<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Add Quotation';
$activePage = 'quotes';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (empty($_SESSION['quotations_csrf_token'])) {
  $_SESSION['quotations_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['quotations_csrf_token'];
$quoteId = isset($_GET['quote_id']) ? (int) $_GET['quote_id'] : 0;
$viewOnly = isset($_GET['view']) && $_GET['view'] === '1';
$preRequestId = isset($_GET['request_id']) ? (int) $_GET['request_id'] : 0;
$preRevisitId = isset($_GET['revisit_id']) ? (int) $_GET['revisit_id'] : 0;
$preClientId = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1" name="viewport" />
  <title>Add Quotation - FieldPlx</title>
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

    /* Client Locations page */
    .fd-loc-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 18px;
    }

    .fd-loc-title {
      margin: 0 0 7px;
      color: var(--fd-text);
      font-size: 21px;
      font-weight: 700;
    }

    .fd-loc-sub {
      margin: 0;
      max-width: 800px;
      color: var(--fd-muted);
      font-size: 11px;
      line-height: 1.55;
    }

    .fd-loc-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .fd-loc-client {
      margin-bottom: 14px;
      padding: 12px 14px;
      display: flex;
      align-items: center;
      gap: 10px;
      border: 1px solid var(--fd-border);
      border-radius: 10px;
      background: #fff;
    }

    .fd-loc-client-icon {
      width: 38px;
      height: 38px;
      display: grid;
      place-items: center;
      flex: 0 0 38px;
      border-radius: 10px;
      color: var(--fd-green-dark);
      background: var(--fd-green-soft);
    }

    .fd-loc-client strong,
    .fd-loc-client small {
      display: block;
    }

    .fd-loc-client strong {
      color: var(--fd-text);
      font-size: 11px;
    }

    .fd-loc-client small {
      margin-top: 3px;
      color: var(--fd-muted);
      font-size: 8.5px;
    }

    .fd-loc-address {
      min-width: 260px;
      white-space: normal !important;
      line-height: 1.4;
    }

    .fd-loc-modal {
      width: min(900px, 100%);
    }

    .fd-loc-map-link {
      color: #123d70 !important;
      font-weight: 700;
      text-decoration: none !important;
    }

    .fd-loc-map-link:hover {
      color: var(--fd-green-dark) !important;
    }

    .fd-loc-primary {
      color: #5d971b;
      background: #f0f8e5;
    }

    .fd-loc-type {
      color: #123d70;
      background: #edf2f7;
    }

    .fd-loc-table .fd-team-actions-cell {
      justify-content: flex-start;
    }

    .fd-loc-table-wrap {
      overflow-x: auto;
      overflow-y: hidden;
      scrollbar-width: thin;
      scrollbar-color: #9aa0a6 transparent;
    }

    .fd-loc-table-wrap::-webkit-scrollbar {
      height: 3px !important
    }

    .fd-loc-table-wrap::-webkit-scrollbar-track {
      background: transparent !important
    }

    .fd-loc-table-wrap::-webkit-scrollbar-thumb {
      min-width: 20px;
      border-radius: 999px !important;
      background: #9aa0a6 !important;
    }

    .fd-loc-table-wrap::-webkit-scrollbar-button {
      width: 0 !important;
      height: 0 !important;
      display: none !important;
    }

    @media(max-width:767.98px) {
      .fd-loc-head {
        flex-direction: column;
      }

      .fd-loc-actions {
        width: 100%;
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
    a:active {
      text-decoration: none !important;
    }

    .fd-rq-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 18px;
    }

    .fd-rq-title {
      margin: 0 0 7px;
      color: var(--fd-text);
      font-size: 21px;
      line-height: 1.2;
      font-weight: 700;
    }

    .fd-rq-sub {
      margin: 0;
      max-width: 860px;
      color: var(--fd-muted);
      font-size: 11px;
      line-height: 1.55;
    }

    .fd-rq-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .fd-rq-btn {
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
      cursor: pointer;
    }

    .fd-rq-btn:hover {
      border-color: #cfe3ae;
      color: var(--fd-green-dark);
      background: #f9fcf4;
    }

    .fd-rq-btn.primary {
      border-color: var(--fd-green);
      color: #fff;
      background: linear-gradient(90deg, #7fc92d, #68aa1d);
      box-shadow: 0 7px 16px rgba(104, 170, 29, .18);
    }

    .fd-rq-btn.primary:hover {
      color: #fff;
      background: linear-gradient(90deg, #74b824, #5d971b);
    }

    .fd-rq-btn.danger {
      border-color: #ffd5d9;
      color: #b9444d;
      background: #fff;
    }

    .fd-rq-btn:disabled {
      opacity: .58;
      cursor: not-allowed;
    }

    .fd-rq-loader {
      width: 13px;
      height: 13px;
      display: none;
      border: 2px dotted currentColor;
      border-radius: 50%;
      animation: fdRqSpin .75s linear infinite;
    }

    .fd-rq-btn.loading .fd-rq-loader {
      display: inline-block
    }

    @keyframes fdRqSpin {
      to {
        transform: rotate(360deg)
      }
    }

    .fd-rq-summary {
      margin-bottom: 16px
    }

    .fd-rq-stat {
      min-height: 112px;
      padding: 18px 20px;
      border: 1px solid #dfe6ef;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 3px 12px rgba(24, 45, 76, .035);
    }

    .fd-rq-stat-row {
      min-height: 72px;
      display: flex;
      align-items: center;
      gap: 18px;
    }

    .fd-rq-stat-icon {
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

    .fd-rq-stat-label {
      display: block;
      margin-bottom: 8px;
      color: #506784;
      font-size: 13px;
    }

    .fd-rq-stat-value {
      display: block;
      color: #020b16;
      font-size: 31px;
      line-height: 1;
      font-weight: 700;
    }

    .fd-rq-card {
      overflow: hidden
    }

    .fd-rq-toolbar {
      padding: 13px 14px;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      border-bottom: 1px solid var(--fd-border);
      background: #fbfcfd;
    }

    .fd-rq-search {
      width: 280px;
      position: relative;
    }

    .fd-rq-search i {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #8a96a7;
      font-size: 13px;
    }

    .fd-rq-search input,
    .fd-rq-filter {
      height: 39px;
      border: 1px solid #dde4ec;
      border-radius: 8px;
      outline: 0;
      color: #33445f;
      background: #fff;
      font-size: 10px;
    }

    .fd-rq-search input {
      width: 100%;
      padding: 8px 11px 8px 34px;
    }

    .fd-rq-filter {
      min-width: 140px;
      padding: 8px 10px;
    }

    .fd-rq-search input:focus,
    .fd-rq-filter:focus {
      border-color: #a9cf75;
      box-shadow: 0 0 0 3px rgba(116, 184, 36, .11);
    }

    .fd-rq-spacer {
      margin-left: auto
    }

    .fd-rq-table-wrap {
      width: 100%;
      overflow-x: auto;
      overflow-y: hidden;
      scrollbar-width: thin;
      scrollbar-color: #9aa0a6 transparent;
    }

    .fd-rq-table-wrap::-webkit-scrollbar {
      height: 3px !important
    }

    .fd-rq-table-wrap::-webkit-scrollbar-track {
      background: transparent !important
    }

    .fd-rq-table-wrap::-webkit-scrollbar-thumb {
      min-width: 20px;
      border-radius: 999px !important;
      background: #9aa0a6 !important;
    }

    .fd-rq-table-wrap::-webkit-scrollbar-button {
      width: 0 !important;
      height: 0 !important;
      display: none !important;
    }

    .fd-rq-table {
      width: 100%;
      min-width: 1320px;
      margin: 0;
      border-collapse: collapse;
      white-space: nowrap;
    }

    .fd-rq-table th {
      padding: 11px 12px;
      border-bottom: 1px solid var(--fd-border);
      color: #65738a;
      background: #f8fafc;
      font-size: 9px;
      line-height: 1.2;
      font-weight: 700;
      text-align: left;
      text-transform: uppercase;
    }

    .fd-rq-table td {
      padding: 12px;
      border-bottom: 1px solid #f1f3f7;
      color: #33445f;
      font-size: 9.5px;
      line-height: 1.45;
      vertical-align: middle;
    }

    .fd-rq-table tbody tr:hover {
      background: #fbfcfa
    }

    .fd-rq-table th:first-child,
    .fd-rq-table td:first-child {
      width: 55px;
      text-align: center;
    }

    .fd-rq-request strong,
    .fd-rq-request small,
    .fd-rq-client strong,
    .fd-rq-client small {
      display: block;
    }

    .fd-rq-request strong {
      color: #123d70;
      font-size: 10.5px;
      font-weight: 700;
    }

    .fd-rq-request small,
    .fd-rq-client small {
      margin-top: 3px;
      color: #8995a6;
      font-size: 8.3px;
    }

    .fd-rq-client strong {
      color: #17233b;
      font-size: 10px;
      font-weight: 700;
    }

    .fd-rq-badge {
      min-height: 22px;
      padding: 4px 7px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 5px;
      font-size: 8.5px;
      line-height: 1;
      font-weight: 700;
      text-transform: capitalize;
    }

    .fd-rq-badge.new,
    .fd-rq-badge.normal {
      color: #123d70;
      background: #edf2f7;
    }

    .fd-rq-badge.contacting,
    .fd-rq-badge.information_required {
      color: #8a5e10;
      background: #fff7df;
    }

    .fd-rq-badge.assessment_required,
    .fd-rq-badge.quote_required,
    .fd-rq-badge.job_required,
    .fd-rq-badge.high {
      color: #b55b00;
      background: #fff1e4;
    }

    .fd-rq-badge.converted,
    .fd-rq-badge.closed,
    .fd-rq-badge.low {
      color: #5d971b;
      background: #f0f8e5;
    }

    .fd-rq-badge.cancelled {
      color: #8b4450;
      background: #fff0f1;
    }

    .fd-rq-badge.urgent {
      color: #bd2f3a;
      background: #fff0f1;
    }

    .fd-rq-actions-cell {
      min-width: 120px;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .fd-rq-icon {
      width: 29px;
      height: 29px;
      min-width: 29px;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 0;
      border-radius: 6px;
      color: #66748b;
      background: transparent;
      cursor: pointer;
      font-size: 12px;
      line-height: 1;
    }

    .fd-rq-icon:hover {
      color: var(--fd-green-dark);
      background: var(--fd-green-soft);
    }

    .fd-rq-icon.danger:hover {
      color: #b9444d;
      background: #fff0f1;
    }

    .fd-rq-empty {
      padding: 28px 18px !important;
      text-align: center;
      color: #9aa4b3 !important;
      font-size: 10px !important;
    }

    .fd-rq-pagination {
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

    .fd-rq-pagination-actions {
      display: flex;
      gap: 5px;
    }

    /* Modal */
    .fd-rq-modal-bg {
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

    .fd-rq-modal-bg.show {
      display: flex
    }

    .fd-rq-modal {
      width: min(940px, 100%);
      max-height: calc(100vh - 34px);
      overflow: auto;
      border: 1px solid #dfe5ec;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 24px 65px rgba(0, 17, 49, .24);
    }

    .fd-rq-modal.small {
      width: min(610px, 100%);
    }

    .fd-rq-modal-header {
      min-height: 58px;
      padding: 11px 14px;
      display: flex;
      align-items: center;
      gap: 10px;
      border-bottom: 1px solid var(--fd-border);
      background: #fbfcfd;
    }

    .fd-rq-modal-icon {
      width: 34px;
      height: 34px;
      display: grid;
      place-items: center;
      border-radius: 9px;
      color: var(--fd-green-dark);
      background: var(--fd-green-soft);
      font-size: 15px;
    }

    .fd-rq-modal-heading {
      min-width: 0;
      flex: 1;
    }

    .fd-rq-modal-heading h3 {
      margin: 0;
      color: var(--fd-text);
      font-size: 12px;
      font-weight: 700;
    }

    .fd-rq-modal-heading p {
      margin: 3px 0 0;
      color: var(--fd-muted);
      font-size: 8.5px;
    }

    .fd-rq-modal-close {
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

    .fd-rq-modal-body {
      padding: 15px
    }

    .fd-rq-form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 13px;
    }

    .fd-rq-field.full {
      grid-column: 1/-1
    }

    .fd-rq-field label {
      margin-bottom: 6px;
      display: block;
      color: #42536c;
      font-size: 9px;
      font-weight: 700;
    }

    .fd-rq-field input,
    .fd-rq-field select,
    .fd-rq-field textarea {
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

    .fd-rq-field textarea {
      min-height: 88px;
      resize: vertical;
    }

    .fd-rq-field input:focus,
    .fd-rq-field select:focus,
    .fd-rq-field textarea:focus {
      border-color: #a9cf75;
      box-shadow: 0 0 0 3px rgba(116, 184, 36, .11);
    }

    .fd-rq-section {
      grid-column: 1/-1;
      margin-top: 4px;
      padding: 8px 0 3px;
      border-bottom: 1px solid #eef2f5;
      color: #31425b;
      font-size: 9px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .fd-rq-modal-footer {
      padding: 12px 15px;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      border-top: 1px solid var(--fd-border);
      background: #fbfcfd;
    }

    /* History */
    .fd-rq-history {
      display: grid;
      gap: 8px;
    }

    .fd-rq-history-item {
      padding: 10px 11px;
      border: 1px solid #e4e9ef;
      border-radius: 8px;
      background: #fbfcfd;
    }

    .fd-rq-history-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
    }

    .fd-rq-history-top strong {
      color: #263750;
      font-size: 9.5px;
    }

    .fd-rq-history-item small {
      display: block;
      margin-top: 4px;
      color: #8793a5;
      font-size: 8px;
    }

    .fd-rq-history-item p {
      margin: 7px 0 0;
      color: #56667c;
      font-size: 8.5px;
      line-height: 1.5;
    }

    /* Toast */
    .fd-rq-toast {
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

    .fd-rq-toast.show {
      opacity: 1;
      transform: translateY(0);
    }

    .fd-rq-toast.success {
      background: #5d971b
    }

    .fd-rq-toast.error {
      background: #e45b66
    }

    .fd-rq-toast.warning {
      background: #96a52f
    }

    .fd-rq-toast.info {
      background: #123d70
    }

    .fd-rq-toast-msg {
      min-width: 0;
      flex: 1;
      font-size: 8.5px;
      font-weight: 600;
    }

    .fd-rq-toast-close {
      width: 19px;
      height: 19px;
      padding: 0;
      border: 0;
      color: #fff;
      background: transparent;
      cursor: pointer;
    }

    @media(max-width:767.98px) {
      .fd-rq-head {
        flex-direction: column
      }

      .fd-rq-actions {
        width: 100%
      }

      .fd-rq-form-grid {
        grid-template-columns: 1fr
      }

      .fd-rq-field.full,
      .fd-rq-section {
        grid-column: auto
      }

      .fd-rq-search {
        width: 100%
      }

      .fd-rq-spacer {
        display: none
      }
    }

    @media(max-width:575.98px) {
      .fd-rq-stat {
        min-height: 102px;
        padding: 15px 17px;
      }

      .fd-rq-stat-icon {
        width: 54px;
        height: 54px;
        flex-basis: 54px;
      }

      .fd-rq-stat-value {
        font-size: 29px
      }

      .fd-rq-filter {
        flex: 1
      }

      .fd-rq-modal-footer {
        flex-direction: column-reverse
      }

      .fd-rq-modal-footer .fd-rq-btn {
        width: 100%
      }

      .fd-rq-toast {
        top: 72px;
        left: 12px;
        right: 12px;
        width: auto;
      }
    }

    /* Jobs page */
    a,
    a:link,
    a:visited,
    a:hover,
    a:focus,
    a:active {
      text-decoration: none !important
    }

    .fd-job-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 18px
    }

    .fd-job-title {
      margin: 0 0 7px;
      color: var(--fd-text);
      font-size: 21px;
      font-weight: 700
    }

    .fd-job-sub {
      margin: 0;
      max-width: 880px;
      color: var(--fd-muted);
      font-size: 10.5px;
      line-height: 1.55
    }

    .fd-job-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap
    }

    .fd-job-btn {
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
      font-size: 10px;
      font-weight: 700;
      cursor: pointer
    }

    .fd-job-btn.primary {
      border-color: var(--fd-green);
      color: #fff;
      background: linear-gradient(90deg, #7fc92d, #68aa1d);
      box-shadow: 0 7px 16px rgba(104, 170, 29, .16)
    }

    .fd-job-btn.danger {
      border-color: #ffd5d9;
      color: #b9444d
    }

    .fd-job-btn:disabled {
      opacity: .55;
      cursor: not-allowed
    }

    .fd-job-loader {
      width: 13px;
      height: 13px;
      display: none;
      border: 2px dotted currentColor;
      border-radius: 50%;
      animation: jobSpin .75s linear infinite
    }

    .fd-job-btn.loading .fd-job-loader {
      display: inline-block
    }

    @keyframes jobSpin {
      to {
        transform: rotate(360deg)
      }
    }

    .fd-job-summary {
      margin-bottom: 16px
    }

    .fd-job-stat {
      min-height: 112px;
      padding: 18px 20px;
      border: 1px solid #dfe6ef;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 3px 12px rgba(24, 45, 76, .035)
    }

    .fd-job-stat-row {
      min-height: 72px;
      display: flex;
      align-items: center;
      gap: 18px
    }

    .fd-job-stat-icon {
      width: 58px;
      height: 58px;
      flex: 0 0 58px;
      display: grid;
      place-items: center;
      border-radius: 16px;
      color: #fff;
      background: #123f73;
      font-size: 25px
    }

    .fd-job-stat-label {
      display: block;
      margin-bottom: 8px;
      color: #506784;
      font-size: 13px
    }

    .fd-job-stat-value {
      display: block;
      color: #020b16;
      font-size: 31px;
      line-height: 1;
      font-weight: 700
    }

    .fd-job-toolbar {
      padding: 13px 14px;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      border-bottom: 1px solid var(--fd-border);
      background: #fbfcfd
    }

    .fd-job-search {
      width: 270px;
      position: relative
    }

    .fd-job-search i {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #8a96a7
    }

    .fd-job-search input,
    .fd-job-filter {
      height: 39px;
      border: 1px solid #dde4ec;
      border-radius: 8px;
      outline: 0;
      color: #33445f;
      background: #fff;
      font-size: 10px
    }

    .fd-job-search input {
      width: 100%;
      padding: 8px 11px 8px 34px
    }

    .fd-job-filter {
      min-width: 135px;
      padding: 8px 10px
    }

    .fd-job-spacer {
      margin-left: auto
    }

    .fd-job-table-wrap {
      overflow-x: auto;
      overflow-y: hidden;
      scrollbar-width: thin;
      scrollbar-color: #9aa0a6 transparent
    }

    .fd-job-table-wrap::-webkit-scrollbar {
      height: 3px !important
    }

    .fd-job-table-wrap::-webkit-scrollbar-track {
      background: transparent !important
    }

    .fd-job-table-wrap::-webkit-scrollbar-thumb {
      min-width: 20px;
      border-radius: 999px !important;
      background: #9aa0a6 !important
    }

    .fd-job-table-wrap::-webkit-scrollbar-button {
      display: none !important;
      width: 0 !important;
      height: 0 !important
    }

    .fd-job-table {
      width: 100%;
      min-width: 1420px;
      border-collapse: collapse;
      white-space: nowrap
    }

    .fd-job-table th {
      padding: 11px 12px;
      border-bottom: 1px solid var(--fd-border);
      color: #65738a;
      background: #f8fafc;
      font-size: 9px;
      font-weight: 700;
      text-align: left;
      text-transform: uppercase
    }

    .fd-job-table td {
      padding: 12px;
      border-bottom: 1px solid #f1f3f7;
      color: #33445f;
      font-size: 9.5px;
      vertical-align: middle
    }

    .fd-job-table th:first-child,
    .fd-job-table td:first-child {
      width: 55px;
      text-align: center
    }

    .fd-job-table tbody tr:hover {
      background: #fbfcfa
    }

    .fd-job-main strong,
    .fd-job-main small,
    .fd-job-client strong,
    .fd-job-client small {
      display: block
    }

    .fd-job-main strong {
      color: #123d70;
      font-size: 10.5px
    }

    .fd-job-main small,
    .fd-job-client small {
      margin-top: 3px;
      color: #8995a6;
      font-size: 8.3px
    }

    .fd-job-client strong {
      color: #17233b;
      font-size: 10px
    }

    .fd-job-badge {
      min-height: 22px;
      padding: 4px 7px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 5px;
      font-size: 8.3px;
      font-weight: 700;
      text-transform: capitalize
    }

    .fd-job-badge.draft,
    .fd-job-badge.normal {
      color: #123d70;
      background: #edf2f7
    }

    .fd-job-badge.active,
    .fd-job-badge.completed,
    .fd-job-badge.closed,
    .fd-job-badge.ready_to_invoice,
    .fd-job-badge.low {
      color: #5d971b;
      background: #f0f8e5
    }

    .fd-job-badge.scheduled,
    .fd-job-badge.upcoming,
    .fd-job-badge.today,
    .fd-job-badge.in_progress,
    .fd-job-badge.high {
      color: #a85a08;
      background: #fff4df
    }

    .fd-job-badge.waiting_customer,
    .fd-job-badge.waiting_material,
    .fd-job-badge.needs_review,
    .fd-job-badge.rescheduled {
      color: #8a5e10;
      background: #fff7df
    }

    .fd-job-badge.cancelled,
    .fd-job-badge.archived,
    .fd-job-badge.urgent {
      color: #bd2f3a;
      background: #fff0f1
    }

    .fd-job-badge.invoiced {
      color: #5b4dad;
      background: #f1efff
    }

    .fd-job-row-actions {
      display: flex;
      align-items: center;
      gap: 4px;
      min-width: 110px
    }

    .fd-job-icon {
      width: 29px;
      height: 29px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 0;
      border-radius: 6px;
      color: #66748b;
      background: transparent;
      cursor: pointer
    }

    .fd-job-icon:hover {
      color: var(--fd-green-dark);
      background: var(--fd-green-soft)
    }

    .fd-job-icon.danger:hover {
      color: #b9444d;
      background: #fff0f1
    }

    .fd-job-empty {
      padding: 28px 18px !important;
      text-align: center;
      color: #9aa4b3 !important;
      font-size: 10px !important
    }

    .fd-job-pagination {
      min-height: 49px;
      padding: 10px 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      border-top: 1px solid var(--fd-border);
      color: #768397;
      background: #fff;
      font-size: 9px
    }

    .fd-job-modal-bg {
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

    .fd-job-modal-bg.show {
      display: flex
    }

    .fd-job-modal {
      width: min(980px, 100%);
      max-height: calc(100vh - 34px);
      overflow: auto;
      border: 1px solid #dfe5ec;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 24px 65px rgba(0, 17, 49, .24)
    }

    .fd-job-modal.small {
      width: min(610px, 100%)
    }

    .fd-job-modal-head {
      min-height: 58px;
      padding: 11px 14px;
      display: flex;
      align-items: center;
      gap: 10px;
      border-bottom: 1px solid var(--fd-border);
      background: #fbfcfd
    }

    .fd-job-modal-head h3 {
      margin: 0;
      color: var(--fd-text);
      font-size: 12px
    }

    .fd-job-modal-head p {
      margin: 3px 0 0;
      color: var(--fd-muted);
      font-size: 8.5px
    }

    .fd-job-modal-copy {
      min-width: 0;
      flex: 1
    }

    .fd-job-modal-icon {
      width: 34px;
      height: 34px;
      display: grid;
      place-items: center;
      border-radius: 9px;
      color: var(--fd-green-dark);
      background: var(--fd-green-soft)
    }

    .fd-job-close {
      width: 30px;
      height: 30px;
      border: 0;
      border-radius: 7px;
      background: transparent;
      color: #8490a0
    }

    .fd-job-modal-body {
      padding: 15px
    }

    .fd-job-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 13px
    }

    .fd-job-field.full {
      grid-column: 1/-1
    }

    .fd-job-section {
      grid-column: 1/-1;
      margin-top: 4px;
      padding: 8px 0 4px;
      border-bottom: 1px solid #eef2f5;
      color: #31425b;
      font-size: 9px;
      font-weight: 700;
      text-transform: uppercase
    }

    .fd-job-field label {
      display: block;
      margin-bottom: 6px;
      color: #42536c;
      font-size: 9px;
      font-weight: 700
    }

    .fd-job-field input,
    .fd-job-field select,
    .fd-job-field textarea {
      width: 100%;
      min-height: 40px;
      padding: 8px 10px;
      border: 1px solid #dfe5ec;
      border-radius: 8px;
      outline: 0;
      color: #263750;
      background: #fff;
      font-size: 10px
    }

    .fd-job-field textarea {
      min-height: 86px;
      resize: vertical
    }

    .fd-job-assignment {
      grid-column: 1/-1;
      padding: 12px;
      border: 1px solid #e4e9ef;
      border-radius: 9px;
      background: #fbfcfd
    }

    .fd-job-hint {
      margin-top: 5px;
      color: #8793a5;
      font-size: 8px
    }

    .fd-job-modal-footer {
      padding: 12px 15px;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      border-top: 1px solid var(--fd-border);
      background: #fbfcfd
    }

    .fd-job-toast {
      width: min(300px, calc(100vw - 24px));
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
      transition: .18s
    }

    .fd-job-toast.show {
      opacity: 1;
      transform: translateY(0)
    }

    .fd-job-toast.success {
      background: #5d971b
    }

    .fd-job-toast.error {
      background: #e45b66
    }

    .fd-job-toast.warning {
      background: #96a52f
    }

    .fd-job-toast.info {
      background: #123d70
    }

    .fd-job-toast-msg {
      flex: 1;
      font-size: 8.5px;
      font-weight: 600
    }

    .fd-job-toast-close {
      border: 0;
      color: #fff;
      background: transparent
    }

    .select2-container {
      width: 100% !important
    }

    .select2-container .select2-selection--single {
      height: 40px !important;
      border: 1px solid #dfe5ec !important;
      border-radius: 8px !important
    }

    .select2-container .select2-selection--single .select2-selection__rendered {
      height: 38px !important;
      padding: 0 31px 0 10px !important;
      display: flex !important;
      align-items: center !important;
      color: #263750 !important;
      font-size: 10px !important
    }

    .select2-container .select2-selection--single .select2-selection__arrow {
      height: 38px !important
    }

    .select2-container .select2-selection--multiple {
      min-height: 40px !important;
      border: 1px solid #dfe5ec !important;
      border-radius: 8px !important
    }

    .select2-dropdown {
      z-index: 20000 !important;
      border: 1px solid #dfe5ec !important
    }

    .select2-results__option {
      font-size: 9px !important
    }

    @media(max-width:767.98px) {
      .fd-job-head {
        flex-direction: column
      }

      .fd-job-grid {
        grid-template-columns: 1fr
      }

      .fd-job-field.full,
      .fd-job-section,
      .fd-job-assignment {
        grid-column: auto
      }

      .fd-job-search {
        width: 100%
      }

      .fd-job-spacer {
        display: none
      }
    }

    /* Add / Edit Quotation - canonical tenant UI */
    a,
    a:link,
    a:visited,
    a:hover,
    a:focus,
    a:active {
      text-decoration: none !important
    }

    .qa-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 18px
    }

    .qa-title {
      margin: 0 0 7px;
      color: var(--fd-text);
      font-size: 21px;
      font-weight: 700
    }

    .qa-sub {
      margin: 0;
      max-width: 850px;
      color: var(--fd-muted);
      font-size: 10.5px;
      line-height: 1.55
    }

    .qa-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap
    }

    .qa-btn {
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
      font-size: 10px;
      font-weight: 700;
      cursor: pointer
    }

    .qa-btn.primary {
      border-color: var(--fd-green);
      color: #fff;
      background: linear-gradient(90deg, #7fc92d, #68aa1d);
      box-shadow: 0 7px 16px rgba(104, 170, 29, .16)
    }

    .qa-btn:hover {
      border-color: #cfe3ae;
      color: var(--fd-green-dark);
      background: #f9fcf4
    }

    .qa-btn.primary:hover {
      color: #fff;
      background: linear-gradient(90deg, #74b824, #5d971b)
    }

    .qa-btn:disabled {
      opacity: .55;
      cursor: not-allowed
    }

    .qa-loader {
      width: 13px;
      height: 13px;
      display: none;
      border: 2px dotted currentColor;
      border-radius: 50%;
      animation: qaSpin .75s linear infinite
    }

    .qa-btn.loading .qa-loader {
      display: inline-block
    }

    @keyframes qaSpin {
      to {
        transform: rotate(360deg)
      }
    }

    .qa-card {
      overflow: hidden
    }

    .qa-section {
      padding: 14px 15px;
      border-bottom: 1px solid var(--fd-border)
    }

    .qa-section-title {
      margin-bottom: 11px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px
    }

    .qa-section-title strong {
      color: #31425b;
      font-size: 11px
    }

    .qa-section-title small {
      color: #8a96a7;
      font-size: 8.5px
    }

    .qa-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 13px
    }

    .qa-field.full {
      grid-column: 1/-1
    }

    .qa-field label {
      display: block;
      margin-bottom: 6px;
      color: #42536c;
      font-size: 9px;
      font-weight: 700
    }

    .qa-field input,
    .qa-field select,
    .qa-field textarea {
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

    .qa-field textarea {
      min-height: 84px;
      resize: vertical
    }

    .qa-field input:focus,
    .qa-field select:focus,
    .qa-field textarea:focus {
      border-color: #a9cf75;
      box-shadow: 0 0 0 3px rgba(116, 184, 36, .11)
    }

    .qa-enquiry-info {
      display: none;
      margin-top: 12px;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 8px
    }

    .qa-enquiry-info.show {
      display: grid
    }

    .qa-info {
      padding: 9px 10px;
      border: 1px solid #e7ecf1;
      border-radius: 8px;
      background: #fbfcfd
    }

    .qa-info small,
    .qa-info strong {
      display: block
    }

    .qa-info small {
      margin-bottom: 4px;
      color: #8a96a7;
      font-size: 7.6px;
      text-transform: uppercase
    }

    .qa-info strong {
      color: #31445e;
      font-size: 9.5px
    }

    .qa-builder {
      display: none
    }

    .qa-builder.show {
      display: block
    }

    .qa-currency {
      padding: 5px 8px;
      border-radius: 999px;
      color: #5d971b;
      background: #f0f8e5;
      font-size: 8.5px;
      font-weight: 700
    }

    .qa-tools {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap
    }

    .qa-tools .select2-container {
      min-width: 320px;
      flex: 1
    }

    .qa-table-wrap {
      width: 100%;
      overflow-x: auto;
      overflow-y: hidden;
      scrollbar-width: thin;
      scrollbar-color: #9aa0a6 transparent
    }

    .qa-table-wrap::-webkit-scrollbar {
      height: 3px !important
    }

    .qa-table-wrap::-webkit-scrollbar-track {
      background: transparent !important
    }

    .qa-table-wrap::-webkit-scrollbar-thumb {
      background: #9aa0a6 !important;
      border-radius: 999px !important
    }

    .qa-table-wrap::-webkit-scrollbar-button {
      display: none !important;
      width: 0 !important;
      height: 0 !important
    }

    .qa-table {
      width: 100%;
      min-width: 1430px;
      border-collapse: collapse;
      white-space: nowrap
    }

    .qa-table th {
      padding: 10px 9px;
      border-bottom: 1px solid var(--fd-border);
      background: #f8fafc;
      color: #65738a;
      font-size: 8.5px;
      font-weight: 700;
      text-transform: uppercase
    }

    .qa-table td {
      padding: 9px;
      border-bottom: 1px solid #f1f3f7;
      color: #33445f;
      font-size: 9px;
      vertical-align: middle
    }

    .qa-table input {
      height: 34px;
      min-width: 82px;
      padding: 6px 7px;
      border: 1px solid #dfe5ec;
      border-radius: 7px;
      color: #34465f;
      background: #fff;
      font-size: 9px
    }

    .qa-table input.wide {
      min-width: 150px
    }

    .qa-table input.desc {
      min-width: 190px
    }

    .qa-table input[type="checkbox"] {
      width: 14px !important;
      height: 14px !important;
      min-width: 14px !important;
      min-height: 14px !important;
      padding: 0 !important;
      margin: 0 !important;
      border-radius: 3px !important;
      accent-color: var(--fd-green);
      cursor: pointer;
      vertical-align: middle
    }

    .qa-remove {
      width: 29px;
      height: 29px;
      display: grid;
      place-items: center;
      border: 0;
      border-radius: 6px;
      color: #b9444d;
      background: transparent
    }

    .qa-remove:hover {
      background: #fff0f1
    }

    .qa-empty {
      padding: 28px 18px !important;
      text-align: center;
      color: #9aa4b3 !important
    }

    .qa-total-wrap {
      padding: 14px 15px;
      display: flex;
      justify-content: flex-end;
      background: #fbfcfd
    }

    .qa-total-box {
      width: min(390px, 100%);
      border: 1px solid var(--fd-border);
      border-radius: 9px;
      background: #fff;
      overflow: hidden
    }

    .qa-total-row {
      padding: 9px 11px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      border-bottom: 1px solid #eef2f5;
      color: #66758a;
      font-size: 9px
    }

    .qa-total-row:last-child {
      border-bottom: 0
    }

    .qa-total-row strong {
      color: #263750;
      font-size: 10px
    }

    .qa-total-row.grand {
      background: #f5faee
    }

    .qa-total-row.grand span,
    .qa-total-row.grand strong {
      color: #4f8515;
      font-size: 11px;
      font-weight: 700
    }

    .qa-footer {
      padding: 12px 15px;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      border-top: 1px solid var(--fd-border);
      background: #fff
    }

    .qa-toast {
      width: min(300px, calc(100vw - 24px));
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
      transition: .18s
    }

    .qa-toast.show {
      opacity: 1;
      transform: translateY(0)
    }

    .qa-toast.success {
      background: #5d971b
    }

    .qa-toast.error {
      background: #e45b66
    }

    .qa-toast.warning {
      background: #96a52f
    }

    .qa-toast.info {
      background: #123d70
    }

    .qa-toast span {
      flex: 1;
      font-size: 8.5px;
      font-weight: 600
    }

    .select2-container--default .select2-selection--single {
      height: 40px !important;
      border: 1px solid #dfe5ec !important;
      border-radius: 8px !important
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered {
      line-height: 38px !important;
      padding-left: 10px !important;
      font-size: 10px !important;
      color: #263750 !important
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
      height: 38px !important
    }

    @media(max-width:767.98px) {
      .qa-head {
        flex-direction: column
      }

      .qa-grid {
        grid-template-columns: 1fr
      }

      .qa-field.full {
        grid-column: auto
      }

      .qa-enquiry-info {
        grid-template-columns: 1fr 1fr
      }

      .qa-actions {
        width: 100%
      }

      .qa-actions .qa-btn {
        flex: 1
      }

      .qa-tools .select2-container {
        min-width: 100%
      }
    }

    @media(max-width:575.98px) {
      .qa-enquiry-info {
        grid-template-columns: 1fr
      }

      .qa-footer {
        flex-direction: column-reverse
      }

      .qa-footer .qa-btn {
        width: 100%
      }

      .qa-toast {
        top: 72px;
        left: 12px;
        right: 12px;
        width: auto
      }
    }

    /* Quotation direct mode + product quick create + bootstrap guard */
    .qa-direct-box {
      margin-top: 12px;
      padding: 12px;
      border: 1px solid #dfe7ef;
      border-radius: 9px;
      background: #fbfcfd
    }

    .qa-direct-box.hide {
      display: none
    }

    .qa-product-tools {
      margin-top: 10px;
      display: grid;
      grid-template-columns: minmax(240px, 1fr) 110px auto auto;
      gap: 8px;
      align-items: end
    }

    .qa-product-field label {
      display: block;
      margin-bottom: 5px;
      color: #42536c;
      font-size: 9px;
      font-weight: 700
    }

    .qa-product-field input,
    .qa-product-field select,
    .qa-product-modal input,
    .qa-product-modal select,
    .qa-product-modal textarea {
      width: 100%;
      min-height: 39px;
      padding: 8px 10px;
      border: 1px solid #dfe5ec;
      border-radius: 8px;
      background: #fff;
      color: #263750;
      font-size: 10px;
      outline: 0
    }

    .qa-product-create {
      display: none
    }

    .qa-product-create.show {
      display: inline-flex
    }

    .qa-modal .modal-content {
      border: 0;
      border-radius: 12px;
      overflow: hidden
    }

    .qa-modal .modal-header {
      background: #fbfcfd;
      border-bottom: 1px solid var(--fd-border)
    }

    .qa-modal .modal-title {
      font-size: 12px;
      color: var(--fd-text)
    }

    .qa-product-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px
    }

    .qa-product-grid .full {
      grid-column: 1/-1
    }

    .qa-product-modal label {
      display: block;
      margin-bottom: 5px;
      color: #42536c;
      font-size: 9px;
      font-weight: 700
    }

    .qa-product-modal textarea {
      min-height: 85px;
      resize: vertical
    }

    .qa-unsaved-copy {
      font-size: 10px;
      color: #56667c;
      line-height: 1.6
    }

    @media(max-width:767.98px) {
      .qa-product-tools {
        grid-template-columns: 1fr 100px
      }

      .qa-product-tools .qa-btn {
        width: 100%
      }

      .qa-product-grid {
        grid-template-columns: 1fr
      }

      .qa-product-grid .full {
        grid-column: auto
      }
    }
  
    /* ==========================================================
       Jobber-inspired quotation builder main-content redesign
       Shared FieldPlx nav/sidebar/footer remain unchanged.
       ========================================================== */
    .qa-jobber-page{max-width:1600px;padding-bottom:90px}
    .jq-top-card,.jq-section-card,.jq-summary-card{border:1px solid #dfe6ee;border-radius:10px;background:#fff;box-shadow:none}
    .jq-top-card{padding:20px 22px 18px;margin-bottom:18px}
    .jq-heading-row{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:18px}
    .jq-title-wrap{display:flex;align-items:center;gap:11px}.jq-title-wrap h1{margin:0;color:#06223f;font-size:20px;font-weight:700}.jq-title-icon{width:28px;height:28px;display:grid;place-items:center;color:#a13f50;font-size:16px}.jq-back{display:inline-flex;align-items:center;gap:7px;color:#5d971b!important;font-size:10px;font-weight:700}
    .jq-top-grid{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(350px,.95fr);gap:28px}.jq-top-left,.jq-top-right{min-width:0}.jq-client-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}.jq-field input,.jq-field select,.jq-input,.jq-textarea,.jq-meta-row input,.jq-meta-row select,.jq-custom-field input,.jq-inline-editor input,.jq-deposit-editor input,.jq-deposit-editor select,.jq-product-tools input,.jq-catalog-tools select{width:100%;border:1px solid #d9e1e9;border-radius:7px;background:#fff;color:#17334f;outline:none;font-size:10px}.jq-field input,.jq-field select,.jq-input,.jq-meta-row input,.jq-meta-row select,.jq-custom-field input,.jq-inline-editor input,.jq-deposit-editor input,.jq-deposit-editor select,.jq-product-tools input,.jq-catalog-tools select{min-height:39px;padding:8px 11px}.jq-textarea{min-height:92px;padding:12px;resize:vertical}.jq-field input:focus,.jq-input:focus,.jq-textarea:focus,.jq-meta-row input:focus,.jq-meta-row select:focus,.jq-custom-field input:focus,.jq-inline-editor input:focus,.jq-deposit-editor input:focus,.jq-deposit-editor select:focus{border-color:#9ac969;box-shadow:0 0 0 3px rgba(116,184,36,.1)}
    .jq-top-right{padding-top:2px}.jq-meta-row{min-height:46px;display:grid;grid-template-columns:125px 1fr;align-items:center;gap:12px;border-bottom:1px solid #e5eaf1;color:#557086;font-size:10px}.jq-meta-row strong{color:#17334f;font-size:11px}.jq-meta-row .select2-container{width:100%!important}.jq-meta-row .select2-selection{min-height:34px!important}.jq-customize-row{border-bottom:0}.jq-small-btn,.jq-link-button,.jq-add-section-bar button,.jq-total-action button,.jq-deposit-link,.jq-icon-btn,.jq-cancel,.jq-save-main{border:1px solid #dbe3eb;border-radius:7px;background:#fff;color:#4d6a7f;font-size:10px;font-weight:700;cursor:pointer}.jq-small-btn{min-height:31px;padding:6px 10px}.jq-small-btn.green,.jq-green-btn{color:#fff;background:#328b24;border-color:#328b24}.jq-small-btn:hover,.jq-link-button:hover,.jq-add-section-bar button:hover,.jq-total-action button:hover,.jq-deposit-link:hover{color:#5d971b;border-color:#bddb98;background:#f8fced}.jq-custom-fields{grid-column:1/-1;display:grid;gap:7px;padding:7px 0}.jq-custom-field{display:grid;grid-template-columns:1fr 1fr 30px;gap:7px}.jq-custom-field button{border:0;background:transparent;color:#7d8a99}
    .jq-linked-enquiry{margin-top:10px}.jq-link-button{padding:6px 9px}.jq-enquiry-box{display:none;margin-top:8px;padding:10px;border:1px solid #e5eaf1;border-radius:8px;background:#fbfcfd}.jq-enquiry-box.show{display:block}.jq-enquiry-summary{display:none;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:9px}.jq-enquiry-summary.show{display:grid}.jq-enquiry-summary span{padding:8px;border:1px solid #edf0f3;border-radius:6px;background:#fff}.jq-enquiry-summary small,.jq-enquiry-summary strong{display:block}.jq-enquiry-summary small{color:#8b98a7;font-size:8px}.jq-enquiry-summary strong{margin-top:3px;color:#2a465e;font-size:9px}
    .jq-add-section-bar{display:inline-flex;align-items:center;gap:5px;margin:0 0 14px;padding:5px 7px;border-radius:8px;background:#efeee9;color:#38556c;font-size:10px}.jq-add-section-bar span{display:inline-flex;align-items:center;gap:5px;padding:0 4px}.jq-add-section-bar button{min-height:27px;padding:4px 9px;background:#fff}.jq-bottom-section-bar{margin:16px 0}
    .jq-section-card{position:relative;margin-bottom:14px;padding:18px 20px}.jq-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:14px}.jq-section-head h2,.jq-notes-section h2{margin:0;color:#0f2e49;font-size:17px;font-weight:700}.jq-section-head p{margin:7px 0 0;color:#6f8191;font-size:9px}.jq-icon-btn{width:31px;height:31px;display:grid;place-items:center;padding:0;border:0;background:transparent;color:#425e71}.jq-icon-btn:hover{background:#f4f8ef;color:#5d971b}.jq-upload-drop{min-height:53px;margin-bottom:12px;display:flex;align-items:center;justify-content:center;gap:10px;border:1px dashed #d5dfe8;border-radius:7px;color:#63788b;font-size:9px}.jq-upload-counter{position:absolute;top:19px;right:52px;color:#6f8191;font-size:9px}.jq-file-list{display:grid;gap:6px}.jq-file-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:7px 9px;border:1px solid #edf0f3;border-radius:6px;background:#fbfcfd;font-size:9px}.jq-file-row a,.jq-file-row span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#36526b}.jq-file-row button{border:0;background:transparent;color:#7b8897}.jq-file-row.pending{border-style:dashed}.jq-intro-upload{margin-bottom:12px}.jq-section-card>.jq-input{margin-bottom:8px}.jq-optional-section{display:none}.jq-optional-section.show{display:block}
    .jq-products-card{padding-bottom:13px}.jq-item-add-tools{display:none;margin-bottom:12px;padding:10px;border:1px solid #e5eaf1;border-radius:8px;background:#fbfcfd}.jq-item-add-tools.show{display:grid;gap:8px}.jq-catalog-tools{display:grid;grid-template-columns:minmax(220px,1fr) auto auto;gap:8px}.jq-product-tools{display:grid;grid-template-columns:minmax(220px,1fr) 90px auto auto;gap:8px}.jq-line-card{margin:10px 0;padding:12px 13px;border:1px solid #dce4eb;border-radius:8px;background:#fff}.jq-line-top{display:grid;grid-template-columns:minmax(240px,1fr) 120px 145px 130px 32px;gap:8px;align-items:end}.jq-line-top input,.jq-line-desc,.jq-line-bottom input{width:100%;border:1px solid #d9e1e9;border-radius:6px;background:#fff;color:#17334f;font-size:10px;outline:none}.jq-line-top input{min-height:39px;padding:8px 10px}.jq-number-field span,.jq-line-total span{display:block;margin-bottom:4px;color:#677b8f;font-size:8px}.jq-line-total{min-height:39px;padding:7px 10px;border:1px solid #d9e1e9;border-radius:6px}.jq-line-total strong{color:#17334f;font-size:11px}.jq-line-desc{min-height:78px;margin-top:7px;padding:10px;resize:vertical}.jq-line-bottom{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:7px;color:#445f75;font-size:9px}.jq-line-bottom>label{display:flex;align-items:center;gap:6px}.jq-line-bottom input[type=checkbox]{width:14px;height:14px;accent-color:#5d971b}.jq-line-advanced{display:flex;align-items:center;gap:8px}.jq-line-advanced label{display:flex;align-items:center;gap:5px}.jq-line-advanced input{width:78px;min-height:29px;padding:5px 7px}.jq-line-actions{display:flex;gap:7px;margin-top:12px}.jq-green-btn{min-height:33px;padding:6px 11px;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer}.jq-empty{padding:24px;text-align:center;color:#8b98a7;font-size:10px}
    .jq-summary-card{min-height:235px;margin-bottom:14px;padding:20px;display:grid;grid-template-columns:1fr minmax(420px,.9fr);gap:40px}.jq-client-view{display:flex;align-items:flex-start;gap:15px;color:#38556c;font-size:10px}.jq-client-view span{display:flex;align-items:center;gap:9px}.jq-client-view a{color:#328b24;font-weight:700}.jq-totals{display:grid}.jq-totals>div:not(.jq-inline-editor):not(.jq-deposit-editor){min-height:41px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid #dfe5eb;color:#476278;font-size:10px}.jq-totals strong{color:#16324b}.jq-totals .grand{font-size:13px;font-weight:700;border-bottom:3px solid #dfe5eb!important}.jq-total-action button,.jq-deposit-link{border:0;padding:0;background:transparent;color:#328b24;text-decoration:underline}.jq-inline-editor,.jq-deposit-editor{display:none;grid-template-columns:1fr 160px;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #e6ebef;color:#667b8e;font-size:9px}.jq-inline-editor.show,.jq-deposit-editor.show{display:grid}.jq-deposit-editor{grid-template-columns:150px 150px 1fr}.jq-deposit-link{justify-self:start;margin-top:12px}.jq-notes-section{margin-bottom:15px}.jq-notes-section h2{margin-bottom:9px}.jq-notes-section .jq-textarea{min-height:110px;background:#fff}.jq-save-spacer{height:20px}
    .jq-savebar{position:fixed;left:var(--fieldplx-sidebar-width);right:0;bottom:0;z-index:1035;border-top:1px solid #dce3e9;background:rgba(255,255,255,.98);box-shadow:0 -4px 18px rgba(0,17,49,.05)}body.fieldplx-sidebar-collapsed .jq-savebar{left:var(--fieldplx-sidebar-collapsed-width)}.jq-savebar-inner{min-height:58px;padding:9px 27px;display:flex;align-items:center;justify-content:flex-end;gap:8px}.jq-cancel,.jq-save-main{min-height:36px;padding:8px 14px}.jq-save-group{display:flex;gap:6px}.jq-save-main.primary{border-color:#328b24;background:#328b24;color:#fff}.jq-save-main:hover{border-color:#bddb98;color:#5d971b}.jq-save-main.primary:hover{background:#28771d;color:#fff}.jq-save-main.loading .qa-loader{display:inline-block}
    .select2-container--default .select2-selection--single{height:39px!important;border:1px solid #d9e1e9!important;border-radius:7px!important}.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:37px!important;padding-left:10px!important;color:#29465e!important;font-size:10px!important}.select2-container--default .select2-selection--single .select2-selection__arrow{height:37px!important}
    .jq-product-tools .select2-container{min-width:0}.jq-product-tools .select2-selection--single{height:39px!important}.select2-results__option{font-size:11px}.select2-results__option .bi-plus-circle{color:#5d971b}
    @media(max-width:1100px){.jq-top-grid{grid-template-columns:1fr}.jq-summary-card{grid-template-columns:1fr}.jq-line-top{grid-template-columns:1fr 110px 130px 120px 32px}}

    /* Invoice-style direct-create + Product / Service composer */
    .jq-select-create{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:7px;align-items:center}
    .jq-create-mini{height:39px;padding:0 11px;border:1px solid #d9e1e9;border-radius:7px;background:#fff;color:#4f8618;font-size:10px;font-weight:700;white-space:nowrap;cursor:pointer}
    .jq-create-mini:hover{background:#f4faec;border-color:#b8d88d}
    .jq-quote-number-input{width:100%;min-height:36px;padding:7px 10px;border:1px solid #d9e1e9;border-radius:7px;background:#fff;color:#17334f;font-size:11px;font-weight:600;outline:none}
    .jq-quote-number-input:focus{border-color:#91bd7e;box-shadow:0 0 0 3px rgba(47,141,37,.08)}
    .jq-line-composer{padding:0;background:#fff}
    .jq-composer-main{display:grid;grid-template-columns:minmax(380px,1fr) 130px 160px 150px;gap:10px;align-items:start}
    .jq-composer-catalog{position:relative;min-width:0;padding-left:18px}.jq-composer-grip{position:absolute;left:-2px;top:17px;color:#416170}
    .jq-composer-main input,.jq-composer-description{width:100%;border:1px solid #d9e1e9;border-radius:8px;background:#fff;color:#183845;font-family:inherit;font-size:11px;outline:0}
    .jq-composer-main input{height:51px;padding:20px 12px 7px}.jq-composer-description{min-height:90px;margin-top:10px;padding:12px;resize:vertical}
    .jq-composer-main input:focus,.jq-composer-description:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.07)}
    .jq-composer-main .select2-container .select2-selection--single{height:51px!important;border-radius:8px!important}.jq-composer-main .select2-container .select2-selection--single .select2-selection__rendered{height:49px!important;line-height:49px!important;padding-left:14px!important}.jq-composer-main .select2-container .select2-selection--single .select2-selection__arrow{height:49px!important}
    .jq-labeled{position:relative}.jq-labeled>span{position:absolute;left:12px;top:7px;z-index:2;color:#6b808b;font-size:8px;pointer-events:none}.jq-composer-total{height:51px;padding:19px 12px 7px;border:1px solid #d9e1e9;border-radius:8px;color:#17334f;font-size:12px;font-weight:700;text-align:right}
    .jq-composer-actions{min-height:40px;display:flex;align-items:center;gap:12px;margin-top:8px}.jq-service-date-link{padding:0;border:0;background:transparent;color:#4f8618;text-decoration:underline;font-size:10px;font-weight:700;cursor:pointer}.jq-draft-date{height:34px;padding:6px 8px;border:1px solid #d9e1e9;border-radius:7px;font-size:10px}
    .jq-add-composer-line{margin-left:auto;min-height:36px;padding:7px 13px;border:1px solid #2f8d25;border-radius:7px;background:#2f8d25;color:#fff;font-size:10px;font-weight:700;cursor:pointer}
    .jq-add-composer-line:hover{background:#24751d}
    .jq-catalog-result{display:flex;align-items:flex-start;gap:9px}.jq-catalog-copy{min-width:0;flex:1}.jq-catalog-name{display:flex;align-items:center;gap:7px}.jq-catalog-desc{margin-top:2px;color:#6b808b;font-size:10px}.jq-catalog-price{margin-left:auto;white-space:nowrap}.jq-type-badge{display:inline-flex;padding:2px 6px;border-radius:999px;font-size:8px;font-weight:700}.jq-type-badge.service{background:#edf7e9;color:#28701f}.jq-type-badge.product{background:#eef6fb;color:#245d83}.jq-create-option{display:flex;align-items:center;gap:8px;color:#4f8618;font-weight:700}
    .jq-line-kind{display:flex;align-items:center;gap:7px;margin-bottom:7px}.jq-line-kind strong{font-size:11px}.jq-line-type{padding:2px 6px;border-radius:999px;background:#f4f7f8;color:#526b78;font-size:8px;font-weight:700}
    .jq-line-extra-grid{display:grid;grid-template-columns:minmax(280px,1fr) 115px 115px 105px;gap:8px;margin-top:8px}.jq-line-extra-grid textarea{min-height:74px;margin:0}.jq-line-service-date{display:flex;align-items:center;gap:8px;margin-top:8px}.jq-line-service-date input{height:32px;padding:5px 8px;border:1px solid #d9e1e9;border-radius:6px;font-size:9px}.jq-line-optional{margin-left:auto;display:flex;align-items:center;gap:5px;color:#526b78;font-size:9px}
    .qa-quick-modal .modal-dialog{max-width:620px}.qa-quick-modal .modal-content{border:0;border-radius:11px;box-shadow:0 22px 60px rgba(0,17,49,.22)}.qa-quick-modal .modal-header{padding:18px 20px}.qa-quick-modal .modal-body{padding:4px 20px 18px}.qa-quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px}.qa-quick-grid .full{grid-column:1/-1}.qa-quick-grid label{display:block;margin-bottom:5px;color:#526b78;font-size:9px;font-weight:700}.qa-quick-grid input,.qa-quick-grid select,.qa-quick-grid textarea{width:100%;min-height:39px;padding:8px 10px;border:1px solid #d9e1e9;border-radius:7px;font:inherit;font-size:10px}.qa-quick-grid textarea{min-height:72px;resize:vertical}
    .qa-catalog-modal .modal-dialog{max-width:700px}.qa-catalog-costs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}.qa-tax-check{display:flex;align-items:center;gap:7px;margin-top:10px;font-size:10px;color:#526b78}
    @media(max-width:991.98px){.jq-savebar,body.fieldplx-sidebar-collapsed .jq-savebar{left:0}.jq-line-top{grid-template-columns:1fr 1fr}.jq-line-top .jq-name{grid-column:1/-1}.jq-line-total{align-self:end}.jq-client-row{grid-template-columns:1fr}.jq-catalog-tools,.jq-product-tools{grid-template-columns:1fr 1fr}.jq-enquiry-summary{grid-template-columns:1fr}}
    @media(max-width:575.98px){.qa-jobber-page{padding-left:12px!important;padding-right:12px!important}.jq-top-card,.jq-section-card,.jq-summary-card{padding:14px}.jq-heading-row{align-items:flex-start}.jq-title-wrap h1{font-size:18px}.jq-back{font-size:0}.jq-back i{font-size:14px}.jq-meta-row{grid-template-columns:95px 1fr}.jq-line-top{grid-template-columns:1fr}.jq-line-top .jq-name{grid-column:auto}.jq-line-bottom{align-items:flex-start;flex-direction:column}.jq-line-advanced{width:100%;flex-wrap:wrap}.jq-summary-card{gap:16px}.jq-inline-editor,.jq-deposit-editor{grid-template-columns:1fr}.jq-add-section-bar{max-width:100%;overflow-x:auto}.jq-catalog-tools,.jq-product-tools{grid-template-columns:1fr}.jq-savebar-inner{padding:8px 12px}.jq-save-main{padding:7px 10px}}
    @media(max-width:991.98px){.jq-composer-main{grid-template-columns:minmax(260px,1fr) 110px 135px 125px}.jq-line-extra-grid{grid-template-columns:1fr 100px 100px 90px}}
    @media(max-width:767.98px){.jq-select-create{grid-template-columns:1fr}.jq-composer-main{grid-template-columns:1fr 1fr}.jq-composer-catalog{grid-column:1/-1}.jq-line-extra-grid{grid-template-columns:1fr 1fr}.jq-line-extra-grid textarea{grid-column:1/-1}.qa-quick-grid,.qa-catalog-costs{grid-template-columns:1fr}.qa-quick-grid .full{grid-column:auto}}
    @media(max-width:575.98px){.jq-composer-main,.jq-line-extra-grid{grid-template-columns:1fr}.jq-composer-catalog{grid-column:auto}.jq-composer-actions{align-items:flex-start;flex-wrap:wrap}.jq-add-composer-line{width:100%;margin-left:0}}


    /* ===== Quote builder: Jobber behavior + FieldPlx Invoice form UI reference ===== */
    :root{--jq-navy:#001131;--jq-green:#2f8d25;--jq-green-dark:#24751d;--jq-green-soft:#f2f8ee;--jq-text:#0b2b37;--jq-muted:#5f7380;--jq-border:#dce4e8;--jq-soft:#f8fafb;--jq-danger:#c74646}
    body{background:#fff!important;font-family:Arial,Helvetica,sans-serif!important;font-size:14px!important}
    .qa-jobber-page{width:100%!important;max-width:none!important;margin:0!important;padding:0 0 88px!important;background:#fff!important}
    .jq-top-card{margin:0!important;padding:24px 28px 30px!important;border:0!important;border-bottom:1px solid var(--jq-border)!important;border-radius:0!important;background:#fff!important}
    .jq-heading-row{margin-bottom:18px!important}.jq-title-wrap{gap:12px!important}.jq-title-icon{width:auto!important;height:auto!important;border:0!important;background:transparent!important;color:#2b75ac!important;font-size:22px!important}.jq-title-wrap h1{margin:0!important;color:var(--jq-text)!important;font-size:24px!important;line-height:1.2!important;font-weight:700!important}.jq-back{color:var(--jq-green-dark)!important;font-size:13px!important;font-weight:700!important}
    .jq-top-grid{gap:22px!important}.jq-field input,.jq-field select,.jq-input,.jq-textarea,.jq-meta-row input,.jq-meta-row select{border:1px solid var(--jq-border)!important;border-radius:7px!important;background:#fff!important;color:#163644!important;font-family:inherit!important;font-size:14px!important;outline:0!important}.jq-field input,.jq-field select{height:46px!important}.jq-input,.jq-meta-row input,.jq-meta-row select{height:43px!important}.jq-textarea{min-height:96px!important;padding:13px!important;line-height:1.45!important}.jq-field input:focus,.jq-field select:focus,.jq-input:focus,.jq-textarea:focus,.jq-meta-row input:focus,.jq-meta-row select:focus{border-color:#91bd7e!important;box-shadow:0 0 0 2px rgba(47,141,37,.08)!important}
    .jq-meta-row{min-height:44px!important;grid-template-columns:150px minmax(0,1fr)!important;border-bottom:1px solid var(--jq-border)!important;font-size:14px!important}.jq-meta-row>span{color:#607582!important;font-size:14px!important}.jq-small-btn,.jq-create-mini{height:34px!important;border:1px solid var(--jq-border)!important;border-radius:7px!important;background:#fff!important;color:var(--jq-green-dark)!important;font:700 13px Arial,Helvetica,sans-serif!important}.jq-small-btn:hover,.jq-create-mini:hover{border-color:#b9d9ab!important;background:var(--jq-green-soft)!important}
    .jq-add-section-bar{margin:12px 28px 0!important;padding:7px 9px!important;border-radius:8px!important;background:#f1f0ed!important;font-size:13px!important}.jq-add-section-bar>span{font-size:13px!important}.jq-add-section-bar button{height:29px!important;padding:0 11px!important;border:1px solid #d5dde1!important;border-radius:6px!important;background:#fff!important;color:#24424f!important;font:600 13px Arial,Helvetica,sans-serif!important}
    .jq-section-card{margin:20px 28px 0!important;padding:21px 20px!important;border:1px solid var(--jq-border)!important;border-radius:8px!important;background:#fff!important;box-shadow:none!important}.jq-section-head h2,.jq-notes-section h2{color:var(--jq-text)!important;font-size:20px!important;font-weight:700!important}.jq-section-head p{font-size:13px!important;color:var(--jq-muted)!important}.jq-products-card{padding:22px 20px!important}.jq-line-composer{padding-bottom:18px!important}.jq-composer-main{grid-template-columns:minmax(360px,1fr) 130px 170px 170px!important;gap:10px!important}.jq-composer-main input{height:52px!important;font-size:14px!important}.jq-composer-description{min-height:96px!important;font-size:14px!important}.jq-add-composer-line{height:38px!important;border-radius:7px!important;background:var(--jq-green)!important;font-size:13px!important}.jq-add-composer-line:hover{background:var(--jq-green-dark)!important}
    .jq-line-card{position:relative!important;margin:0 0 14px!important;padding:16px!important;border:1px solid var(--jq-border)!important;border-radius:8px!important;background:#fff!important;box-shadow:none!important}.jq-line-kind{display:none!important}.jq-line-top{grid-template-columns:minmax(280px,1fr) 130px 170px 170px 38px!important;gap:10px!important}.jq-line-top input,.jq-number-field input{height:52px!important;border:1px solid var(--jq-border)!important;border-radius:7px!important;color:#183845!important;font-size:14px!important}.jq-number-field span,.jq-line-total span{font-size:11px!important}.jq-line-total{height:52px!important;border:1px solid var(--jq-border)!important;border-radius:7px!important;font-size:14px!important}.jq-line-body{display:grid!important;grid-template-columns:minmax(0,1fr) 150px!important;gap:10px!important;margin-top:10px!important}.jq-line-desc{min-height:104px!important;border:1px solid var(--jq-border)!important;border-radius:7px!important;font-size:14px!important}.jq-line-image{min-height:104px!important;position:relative!important;display:flex!important;align-items:center!important;justify-content:center!important;border:1px dashed #cbd7dd!important;border-radius:7px!important;background:#fff!important;overflow:hidden!important}.jq-line-image img{width:100%!important;height:104px!important;object-fit:cover!important}.jq-line-image button{border:0!important;background:transparent!important;color:var(--jq-green-dark)!important;font-size:24px!important}.jq-line-image .jq-line-image-remove{position:absolute!important;right:5px!important;top:5px!important;width:28px!important;height:28px!important;border-radius:6px!important;background:#fff!important;color:var(--jq-danger)!important;font-size:14px!important}.jq-drag-grip{position:absolute;left:-22px;top:30px;color:#416170;cursor:grab;font-size:18px}.jq-line-service-date{margin-top:10px!important;font-size:13px!important}.jq-line-optional{font-size:14px!important}.jq-line-optional input,.jq-check-row input,.jq-view-checks input,.jq-link-related input{width:19px!important;height:19px!important;accent-color:var(--jq-green)!important}
    .jq-price-wrap{position:relative}.jq-cost-popover{display:none;position:absolute;z-index:1350;right:-24px;top:58px;width:225px;padding:16px;border:1px solid var(--jq-border);border-radius:8px;background:#fff;box-shadow:0 10px 28px rgba(0,17,49,.16)}.jq-cost-popover.show{display:grid;gap:12px}.jq-cost-popover .jq-number-field input{height:50px!important}.jq-cost-popover p{margin:0;color:#647a87;font-size:12px;line-height:1.35}.jq-cost-popover:before{content:"";position:absolute;top:-7px;right:78px;width:12px;height:12px;background:#fff;border-left:1px solid var(--jq-border);border-top:1px solid var(--jq-border);transform:rotate(45deg)}
    .jq-summary-card.jq-invoice-summary{margin:20px 28px 0!important;min-height:210px!important;padding:20px!important;grid-template-columns:1fr minmax(420px,52%)!important;gap:24px!important;border:1px solid var(--jq-border)!important;border-radius:8px!important}.jq-client-view{font-size:14px!important;align-items:center!important}.jq-client-view i{font-size:20px!important}.jq-text-action,.jq-client-view-panel-head button{border:0;background:transparent;color:var(--jq-green-dark);font:700 13px Arial,Helvetica,sans-serif;text-decoration:underline;cursor:pointer}.jq-client-view-panel{display:none;max-width:620px;margin-top:20px;padding:16px;border:1px solid var(--jq-border);border-radius:8px;background:#fff}.jq-client-view-panel.show{display:block}.jq-client-view-panel-head{display:flex;align-items:center;justify-content:space-between}.jq-client-view-panel p{color:#607782;font-size:13px;line-height:1.45}.jq-view-checks{display:flex;gap:20px;flex-wrap:wrap}.jq-view-checks label{display:flex;align-items:center;gap:7px;color:#405d6a;font-size:14px}
    .jq-jobber-totals{font-size:14px!important}.jq-total-row{min-height:43px!important;display:flex!important;align-items:center!important;justify-content:space-between!important;border-bottom:1px solid var(--jq-border)!important;color:#445f6d!important;font-size:14px!important}.jq-total-row strong{font-size:14px!important}.jq-total-row.grand{min-height:49px!important;border-bottom:3px solid #e1e6e9!important;font-weight:700!important}.jq-total-row.grand strong{font-size:20px!important}.jq-total-action button,.jq-deposit-link{color:var(--jq-green-dark)!important;font:700 13px Arial,Helvetica,sans-serif!important}.jq-adjustment-editor{display:none;min-height:48px;grid-template-columns:minmax(180px,1fr) 105px 34px;gap:8px;align-items:center;border-bottom:1px solid var(--jq-border)}.jq-adjustment-editor.show{display:grid}.jq-discount-input{display:flex}.jq-adjustment-editor input,.jq-adjustment-editor select{height:38px;border:1px solid var(--jq-border);background:#fff;color:#183845;font:14px Arial,Helvetica,sans-serif}.jq-discount-input input{width:130px;border-radius:7px 0 0 7px}.jq-discount-input select{width:90px;border-left:0;border-radius:0 7px 7px 0}.jq-adjustment-editor>select{border-radius:7px;padding:0 10px}.jq-adjustment-editor>strong{text-align:right}.jq-remove-adjustment{width:32px;height:36px;border:0;border-radius:6px;background:transparent;color:var(--jq-danger)}.jq-payment-summary{display:none;margin-top:10px;padding:10px 12px;border-radius:7px;background:#faf9f7;color:#405d6a;font-size:13px}.jq-payment-summary.show{display:flex;justify-content:space-between;gap:12px}.jq-deposit-link{margin-top:15px!important}
    .jq-upload-drop{min-height:74px!important;border:1px dashed #cbd7dd!important;border-radius:7px!important;font-size:13px!important}.jq-upload-counter{font-size:13px!important}.jq-file-row{font-size:13px!important}
    .jq-check-row{display:flex;align-items:center;gap:8px;margin-top:12px;color:#405d6a;font-size:14px}
    .jq-notes-section.jq-internal-notes{margin:22px 28px 28px!important}.jq-note-editor{position:relative}.jq-internal-notes .jq-textarea{min-height:78px!important;border-color:#79ad64!important}.jq-mention-menu{display:none;position:absolute;left:10px;top:calc(100% - 3px);z-index:1400;width:min(320px,calc(100% - 20px));max-height:220px;overflow:auto;padding:4px 0;border:1px solid #d8e0e4;border-radius:7px;background:#fff;box-shadow:0 8px 22px rgba(0,17,49,.16)}.jq-mention-menu.show{display:block}.jq-mention-option{width:100%;min-height:42px;padding:8px 12px;display:flex;align-items:center;gap:9px;border:0;background:#fff;color:#314f5d;text-align:left}.jq-mention-option.active,.jq-mention-option:hover{background:#f1f0ed}.jq-mention-avatar{width:28px;height:28px;display:grid;place-items:center;border-radius:50%;background:#edf6e8;color:var(--jq-green);font-size:11px;font-weight:700}.jq-mention-copy{min-width:0}.jq-mention-copy strong,.jq-mention-copy small{display:block}.jq-mention-copy small{color:#7b8d96}.jq-note-upload{margin-top:13px;min-height:80px;padding:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;border:1px dashed #d5dde1;border-radius:8px}.jq-note-upload.dragover{border-color:#79ad64;background:#f8fcf6}.jq-note-upload button{height:32px;padding:0 13px;border:1px solid #d7dfe3;border-radius:7px;background:#fff;color:var(--jq-green-dark);font:700 13px Arial,Helvetica,sans-serif}.jq-note-upload small{color:#617783;font-size:11px}.jq-link-related{margin-top:18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;color:#405d6a;font-size:14px}.jq-link-related strong{width:100%;color:var(--jq-text);font-size:15px}.jq-link-related label{display:flex;align-items:center;gap:6px}
    .jq-savebar{left:var(--fieldplx-sidebar-width)!important;height:64px!important;padding:0!important;border-top:1px solid var(--jq-border)!important;background:rgba(255,255,255,.98)!important;box-shadow:0 -4px 12px rgba(0,17,49,.04)!important}.fieldplx-sidebar-collapsed .jq-savebar{left:var(--fieldplx-sidebar-collapsed-width)!important}.jq-savebar-inner{height:64px!important;padding:10px 28px!important}.jq-cancel,.jq-save-main{height:38px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;border-radius:7px!important;font-size:14px!important;font-weight:700!important}.jq-save-main.primary{background:var(--jq-green)!important}.jq-save-main.primary:hover{background:var(--jq-green-dark)!important}
    .jq-payment-modal .modal-dialog{max-width:610px}.jq-payment-modal .modal-content{max-height:calc(100vh - 40px);overflow:hidden;border:0;border-radius:10px;box-shadow:0 22px 60px rgba(0,17,49,.25)}.jq-payment-modal .modal-header{padding:25px 28px 12px;border:0}.jq-payment-modal .modal-title{font-size:24px;color:var(--jq-text);font-weight:700}.jq-payment-modal .modal-body{padding:10px 28px 18px;overflow-y:auto}.jq-payment-modal .modal-footer{padding:12px 28px 24px;border:0}.jq-payment-mode{display:flex;align-items:flex-start;gap:10px;margin:14px 0;color:#284755;font-size:15px;cursor:pointer}.jq-payment-mode input{width:20px;height:20px;accent-color:var(--jq-green)}.jq-payment-mode span{display:grid;gap:2px}.jq-payment-mode strong{font-size:16px}.jq-payment-mode small{color:#6f818b;font-size:12px}.jq-deposit-only,.jq-schedule-panel{display:none;margin:14px 0 22px;padding-left:28px}.jq-deposit-only.show,.jq-schedule-panel.show{display:block}.jq-deposit-only{grid-template-columns:200px 130px 1fr;gap:10px;align-items:center}.jq-deposit-only.show{display:grid}.jq-split-toggle{display:inline-flex;border:1px solid var(--jq-border);border-radius:7px;overflow:hidden}.jq-split-toggle button{min-width:92px;height:38px;border:0;border-right:1px solid var(--jq-border);background:#fff;color:#405d6a}.jq-split-toggle button:last-child{border-right:0}.jq-split-toggle button.active{background:var(--jq-green-soft);color:var(--jq-green-dark);font-weight:700}.jq-deposit-only>input{height:38px;border:1px solid var(--jq-border);border-radius:7px;padding:0 10px}.jq-split-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:8px 0 18px;color:#405d6a}.jq-schedule-head,.jq-schedule-row{display:grid;grid-template-columns:90px minmax(150px,1fr) 105px 30px;gap:8px;align-items:center}.jq-schedule-head{padding:0 6px 8px;color:#405d6a;font-size:11px;font-weight:700}.jq-schedule-row{margin-bottom:9px}.jq-schedule-row input{height:38px;border:1px solid var(--jq-border);border-radius:7px;padding:0 10px;color:#183845;font-size:14px}.jq-schedule-row button{width:28px;height:28px;border:0;background:transparent;color:var(--jq-danger)}.jq-required-deposit{grid-column:1/4;display:flex;align-items:center;gap:7px;font-size:13px;color:#526b78}.jq-required-deposit input{width:18px;height:18px;accent-color:var(--jq-green)}.jq-add-schedule{width:100%;height:41px;margin-top:8px;border:1px solid var(--jq-border);border-radius:7px;background:#fff;color:var(--jq-green-dark);font-weight:700}.jq-schedule-total{margin-top:15px;padding:14px;border-radius:7px;background:#faf9f7}.jq-schedule-total>div{display:flex;justify-content:space-between;margin:5px 0}.danger-link{color:var(--jq-danger)!important}
    @media(max-width:991.98px){.jq-savebar,.fieldplx-sidebar-collapsed .jq-savebar{left:0!important}.jq-summary-card.jq-invoice-summary{grid-template-columns:1fr!important}.jq-line-top{grid-template-columns:minmax(220px,1fr) 110px 140px 140px 38px!important}.jq-composer-main{grid-template-columns:minmax(260px,1fr) 110px 140px 140px!important}}
    @media(max-width:767.98px){.jq-top-card{padding-left:14px!important;padding-right:14px!important}.jq-section-card,.jq-summary-card.jq-invoice-summary,.jq-notes-section.jq-internal-notes{margin-left:14px!important;margin-right:14px!important}.jq-add-section-bar{margin-left:14px!important;margin-right:14px!important}.jq-line-top{grid-template-columns:1fr 1fr!important}.jq-line-top .jq-name{grid-column:1/-1!important}.jq-line-body{grid-template-columns:1fr!important}.jq-drag-grip{display:none}.jq-payment-modal .modal-dialog{margin:10px}.jq-deposit-only.show{grid-template-columns:1fr}.jq-schedule-head,.jq-schedule-row{grid-template-columns:78px minmax(120px,1fr) 95px 28px}}
    @media(max-width:575.98px){.jq-line-top{grid-template-columns:1fr!important}.jq-line-top .jq-name{grid-column:auto!important}.jq-summary-card.jq-invoice-summary{padding:14px!important}.jq-adjustment-editor{grid-template-columns:1fr 90px 32px}.jq-schedule-head{display:none}.jq-schedule-row{grid-template-columns:1fr}.jq-required-deposit{grid-column:1}.jq-schedule-row button{justify-self:end}}

    /* Quote form spacing corrections - keep aligned with Add Invoice form */
    .jq-top-right{padding-top:0!important;display:flex!important;flex-direction:column!important;gap:7px!important}
    .jq-meta-row{min-height:44px!important;margin:0!important;padding:0 0 7px!important;grid-template-columns:164px minmax(0,1fr)!important;column-gap:18px!important;border-bottom:1px solid var(--jq-border)!important}
    .jq-meta-row:last-of-type,.jq-customize-row{border-bottom:0!important}
    .jq-meta-row>span{padding-left:0!important;line-height:1.25!important}
    .jq-meta-row input,.jq-meta-row select{height:43px!important;margin:0!important}
    .jq-meta-row .select2-container{margin:0!important}
    .jq-meta-row .select2-container--default .select2-selection--single{height:43px!important;min-height:43px!important;border-color:var(--jq-border)!important}
    .jq-meta-row .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:41px!important;font-size:14px!important;padding-left:11px!important}
    .jq-meta-row .select2-container--default .select2-selection--single .select2-selection__arrow{height:41px!important}
    .jq-custom-fields{padding-top:0!important}

    .jq-add-section-bar.all-added{display:none!important}
    .jq-add-section-bar[hidden]{display:none!important}

    .jq-summary-card.jq-invoice-summary{padding:24px 22px!important;gap:34px!important}
    .jq-jobber-totals{padding:0 4px 2px 8px!important}
    .jq-total-row{min-height:47px!important;padding:0 2px!important}
    .jq-total-row.grand{min-height:54px!important;margin-top:2px!important}
    .jq-deposit-link{margin-top:17px!important;margin-left:2px!important}
    .jq-adjustment-editor{display:none!important;min-height:50px!important;padding:6px 2px!important}
    .jq-adjustment-editor[hidden]{display:none!important}
    .jq-adjustment-editor.show:not([hidden]){display:grid!important}
    .jq-discount-input{min-width:0!important}
    .jq-discount-input input{width:min(150px,62%)!important;padding:0 10px!important}
    .jq-discount-input select{width:100px!important;padding:0 9px!important}
    .jq-adjustment-editor>select{width:100%!important;min-width:0!important}
    .jq-adjustment-editor>strong{padding-right:2px!important}

    @media(max-width:767.98px){
      .jq-top-right{gap:6px!important}
      .jq-meta-row{grid-template-columns:120px minmax(0,1fr)!important;column-gap:12px!important;padding-bottom:6px!important}
      .jq-summary-card.jq-invoice-summary{padding:18px 16px!important}
      .jq-jobber-totals{padding:0!important}
    }
    @media(max-width:575.98px){
      .jq-meta-row{grid-template-columns:1fr!important;gap:6px!important;padding:0 0 10px!important}
      .jq-meta-row>span{padding-top:2px!important}
      .jq-adjustment-editor{grid-template-columns:minmax(0,1fr) 86px 32px!important}
    }



    /* ===== Add Invoice parity: Customer + Product / Service ===== */
    .jq-customer-shell{display:block!important;max-width:640px;margin-top:12px}
    .ni-customer-picker{max-width:640px}
    .ni-customer-picker .select2-container .select2-selection--single,
    .ni-location-picker .select2-container .select2-selection--single{height:46px!important;border:1px solid var(--jq-border)!important;border-radius:8px!important;background:#fff!important}
    .ni-customer-picker .select2-container .select2-selection__rendered,
    .ni-location-picker .select2-container .select2-selection__rendered{height:44px!important;line-height:44px!important;padding-left:13px!important;padding-right:30px!important;color:#183845!important;font-size:14px!important}
    .ni-customer-picker .select2-selection__arrow,.ni-location-picker .select2-selection__arrow{height:44px!important}
    .ni-customer-card{display:none;position:relative;min-height:226px;padding:20px 22px;border:1px solid #d9e1e5;border-radius:8px;background:#fff;color:#173846}
    .ni-customer-card.show{display:block}
    .ni-customer-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:13px}
    .ni-customer-card-name{display:flex;align-items:center;gap:6px;color:#113448;font-size:16px;font-weight:700;line-height:1.25}
    .ni-customer-active-dot{width:7px;height:7px;border-radius:50%;background:#2f8d25;display:inline-block;flex:0 0 7px}
    .ni-customer-menu-wrap{position:relative}
    .ni-customer-menu-button{width:34px;height:30px;border:0;border-radius:6px;background:transparent;color:#274b5b;display:grid;place-items:center;cursor:pointer;font-size:19px;font-weight:700;line-height:1}
    .ni-customer-menu-button:hover{background:#f3f7f8}
    .ni-customer-menu{display:none;position:absolute;right:0;top:34px;z-index:1210;width:190px;padding:6px;border:1px solid var(--jq-border);border-radius:8px;background:#fff;box-shadow:0 12px 28px rgba(0,17,49,.15)}
    .ni-customer-menu.show{display:block}
    .ni-customer-menu button{width:100%;padding:9px 10px;border:0;border-radius:6px;background:#fff;color:#36535f;text-align:left;font:13px Arial,Helvetica,sans-serif;cursor:pointer}
    .ni-customer-menu button:hover{background:#f5faf2;color:var(--jq-green-dark)}
    .ni-customer-block{margin-top:12px}
    .ni-customer-block-label{display:block;margin-bottom:3px;color:#5e7581;font-size:13px}
    .ni-customer-block-value{display:block;color:#173846;font-size:14px;line-height:1.35;white-space:pre-line}
    .ni-customer-contact{margin-top:12px;display:grid;gap:3px}
    .ni-customer-contact a{width:max-content;max-width:100%;overflow:hidden;text-overflow:ellipsis;color:var(--jq-green-dark)!important;font-size:13px;text-decoration:underline!important}
    .ni-location-picker{display:none;max-width:640px;margin-top:10px}
    .ni-location-picker.show{display:block}
    .ni-create-option{display:flex;align-items:center;gap:10px;color:var(--jq-green-dark);font-size:14px;font-weight:700}
    .ni-create-option i{font-size:1.15em}

    .jq-products-card.jq-products-invoice{padding:22px 20px!important}
    .jq-products-invoice .jq-section-head{margin-bottom:17px!important}
    .jq-products-invoice #lineItemCards{margin:0}
    .jq-products-invoice .jq-line-card{position:relative!important;margin:0 0 28px!important;padding:16px 14px 18px!important;border:1px solid var(--jq-border)!important;border-radius:8px!important;background:#fff!important}
    .jq-products-invoice .jq-line-top{display:grid!important;grid-template-columns:minmax(280px,1fr) 130px 170px 170px 42px!important;gap:12px!important;align-items:end!important}
    .jq-line-catalog{min-width:0}
    .jq-line-catalog .select2-container{width:100%!important}
    .jq-line-catalog .select2-container .select2-selection--single{height:48px!important;border:1px solid var(--jq-border)!important;border-radius:7px!important;background:#fff!important}
    .jq-line-catalog .select2-selection__rendered{height:46px!important;line-height:46px!important;padding-left:12px!important;padding-right:28px!important;color:#183845!important;font-size:14px!important}
    .jq-line-catalog .select2-selection__arrow{height:46px!important}
    .jq-products-invoice .jq-number-field input,.jq-products-invoice .jq-line-total{height:48px!important}
    .jq-products-invoice .jq-line-catalog,.jq-products-invoice .jq-number-field,.jq-products-invoice .jq-price-wrap,.jq-products-invoice .jq-line-total,.jq-products-invoice .jq-icon-btn[data-remove]{align-self:end!important}
    .jq-products-invoice .jq-number-field input{padding:22px 10px 7px!important}
    .jq-products-invoice .jq-number-field span,.jq-products-invoice .jq-line-total span{font-size:11px!important}
    .jq-products-invoice .jq-line-total{padding:0 10px!important;display:flex!important;flex-direction:column!important;align-items:flex-end!important;justify-content:center!important;text-align:right!important;line-height:1!important;overflow:hidden!important}
    .jq-products-invoice .jq-line-total span{display:block!important;margin:0 0 4px!important;line-height:1!important}
    .jq-products-invoice .jq-line-total strong{display:block!important;margin:0!important;color:#17334f!important;font-size:14px!important;line-height:1.1!important;white-space:nowrap!important}
    .jq-products-invoice .jq-line-body{grid-template-columns:minmax(280px,1fr) 180px!important;gap:10px!important;margin-top:10px!important}
    .jq-products-invoice .jq-line-desc{min-height:104px!important;padding:13px 12px!important;font-size:14px!important}
    .jq-products-invoice .jq-line-image{min-height:104px!important}
    .jq-products-invoice .jq-line-footer{min-height:32px!important;margin-top:10px!important;display:flex!important;align-items:center!important;justify-content:flex-end!important}
    .jq-products-invoice .jq-line-optional{margin-left:0!important;font-size:14px!important}
    .jq-products-invoice .jq-icon-btn[data-remove]{width:42px!important;height:48px!important;border:0!important;border-radius:7px!important;color:#6c7f89!important;background:transparent!important}
    .jq-products-invoice .jq-icon-btn[data-remove]:hover{color:var(--jq-danger)!important;background:#fff1f1!important}
    .jq-add-line-wrap{margin-top:18px;display:flex;align-items:center;gap:9px;flex-wrap:wrap}
    .jq-add-line-invoice{height:40px;padding:0 15px;border:1px solid var(--jq-green);border-radius:7px;color:#fff;background:var(--jq-green);font:700 13px Arial,Helvetica,sans-serif;cursor:pointer}
    .jq-add-line-invoice:hover{background:var(--jq-green-dark)}
    .jq-add-text-invoice{height:40px;padding:0 14px;border:1px solid var(--jq-border);border-radius:7px;background:#fff;color:var(--jq-green-dark);font:700 13px Arial,Helvetica,sans-serif;cursor:pointer}
    .jq-add-text-invoice:hover{border-color:#b9d9ab;background:var(--jq-green-soft)}
    .jq-products-invoice .jq-empty{display:none}

    .ni-quick-modal .modal-dialog{max-width:620px}
    .ni-quick-modal .modal-content{border:0;border-radius:10px;box-shadow:0 22px 60px rgba(0,17,49,.22)}
    .ni-quick-modal .modal-header{padding:22px 24px 12px;border-bottom:0}
    .ni-quick-modal .modal-title{margin:0;color:var(--jq-text);font-size:23px;font-weight:700}
    .ni-quick-modal .modal-body{padding:8px 24px 10px}
    .ni-quick-modal .modal-footer{padding:12px 24px 22px;border-top:0}
    .ni-quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .ni-quick-grid .full{grid-column:1/-1}
    .ni-floating{position:relative}
    .ni-floating label{position:absolute;top:7px;left:13px;z-index:2;color:#647a87;font-size:13px;line-height:1;pointer-events:none}
    .ni-floating input,.ni-floating select,.ni-floating textarea{width:100%;border:1px solid var(--jq-border);border-radius:7px;background:#fff;color:#163644;outline:0;font-family:inherit;font-size:16px}
    .ni-floating input,.ni-floating select{height:43px;padding:17px 12px 6px}
    .ni-floating textarea{min-height:96px;padding:21px 12px 10px;resize:vertical}
    .ni-floating input:focus,.ni-floating select:focus,.ni-floating textarea:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.09)}
    .ni-quick-check{display:flex;align-items:center;gap:8px;margin-top:14px;color:#405d6a;font-size:14px}
    .ni-quick-check input{width:18px;height:18px;accent-color:var(--jq-green)}
    .ni-btn{height:38px;padding:0 14px;border:1px solid var(--jq-border);border-radius:7px;background:#fff;color:#31505d;font-size:14px;font-weight:700;cursor:pointer}
    .ni-btn.primary{border-color:var(--jq-green);background:var(--jq-green);color:#fff}
    .ni-btn.primary:hover{background:var(--jq-green-dark)}

    .ni-catalog-modal .modal-dialog{max-width:720px}
    .ni-catalog-modal .modal-content{border:0;border-radius:10px;box-shadow:0 22px 60px rgba(0,17,49,.22)}
    .ni-catalog-modal .modal-header{padding:22px 24px;border-bottom:0}
    .ni-catalog-modal .modal-title{margin:0;color:var(--jq-text);font-size:24px;font-weight:700}
    .ni-catalog-modal .modal-body{padding:6px 24px 12px}
    .ni-catalog-modal .modal-footer{padding:14px 24px 22px;border-top:0}
    .ni-modal-field{margin-bottom:14px}
    .ni-new-item-costs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0;margin-top:14px}
    .ni-new-item-costs .ni-floating input{border-radius:0}
    .ni-new-item-costs .ni-floating:first-child input{border-radius:8px 0 0 8px}
    .ni-new-item-costs .ni-floating:last-child input{border-radius:0 8px 8px 0}
    .ni-tax-exempt{display:flex;align-items:center;gap:8px;margin-top:16px;color:#334f5c;cursor:pointer}
    .ni-tax-exempt input{width:20px;height:20px;accent-color:var(--jq-green)}
    .ni-item-image-upload{margin-top:16px}.ni-item-image-input{display:none!important}
    .ni-item-image-drop{width:100%;min-height:128px;padding:16px;border:1px dashed #c7d4da;border-radius:8px;background:#fff;color:#607986;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;cursor:pointer;text-align:center;font:14px Arial,Helvetica,sans-serif}
    .ni-item-image-drop:hover,.ni-item-image-drop.dragover{border-color:#91bd7e;background:#f8fcf6}
    .ni-item-image-drop i{font-size:28px;color:var(--jq-green)}.ni-item-image-drop strong{font-size:14px;color:var(--jq-green-dark)}.ni-item-image-drop small{font-size:12px;color:#7b8d96}
    .ni-item-image-preview{min-height:128px;padding:10px;border:1px solid var(--jq-border);border-radius:8px;background:#fbfcfc;display:flex;align-items:center;gap:14px}
    .ni-item-image-preview img{width:112px;height:106px;border-radius:7px;object-fit:cover;border:1px solid #dfe6ea;background:#fff}
    .ni-item-image-preview-copy{min-width:0;flex:1}.ni-item-image-preview-name{display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#284755;font-size:14px;font-weight:700}.ni-item-image-preview-size{display:block;margin-top:5px;color:#7b8d96;font-size:12px}.ni-item-image-remove{margin-top:10px;padding:0;border:0;background:transparent;color:#b34141;font:700 13px Arial,Helvetica,sans-serif;text-decoration:underline;cursor:pointer}


    /* ---------- Tax selector / Create Tax Rate: same interaction as Add Invoice ---------- */
    .jq-tax-control{position:relative;min-width:0;width:100%}
    .jq-tax-selector{width:100%;height:38px;padding:7px 34px 7px 11px;border:1px solid var(--jq-border);border-radius:7px;background:#fff;color:#405d6a;text-align:left;font:14px Arial,Helvetica,sans-serif;cursor:pointer;position:relative;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
    .jq-tax-selector:focus{outline:0;border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
    .jq-tax-selector i{position:absolute;right:11px;top:50%;transform:translateY(-50%);font-size:12px}
    .jq-tax-menu{display:none;position:absolute;left:0;right:0;top:calc(100% + 5px);z-index:1200;min-width:220px;padding:6px 0;border:1px solid var(--jq-border);border-radius:7px;background:#fff;box-shadow:0 10px 24px rgba(0,17,49,.14)}
    .jq-tax-menu.show{display:block}
    .jq-tax-menu-empty{padding:9px 11px;color:#6f818b;font-size:13px}
    .jq-tax-menu button{width:100%;padding:9px 11px;border:0;background:#fff;color:#36535f;text-align:left;font:14px Arial,Helvetica,sans-serif;cursor:pointer}
    .jq-tax-menu button:hover{background:#f5faf2}
    .jq-tax-menu button strong{display:block;color:#183845;font-size:13px;font-weight:700}
    .jq-tax-menu button small{display:block;margin-top:2px;color:#7b8d96;font-size:11px}
    .jq-tax-menu .jq-create-tax{border-top:1px solid var(--jq-border);color:var(--jq-green-dark);font-weight:700;text-decoration:underline}
    .ni-tax-modal .modal-dialog{max-width:540px}
    .ni-tax-modal .modal-content{border:0;border-radius:10px;box-shadow:0 22px 60px rgba(0,17,49,.22)}
    .ni-tax-modal .modal-header{padding:22px 24px 12px;border-bottom:0}
    .ni-tax-modal .modal-title{margin:0;color:var(--jq-text);font-size:23px;font-weight:700}
    .ni-tax-modal .modal-body{padding:8px 24px 10px}
    .ni-tax-modal .modal-footer{padding:12px 24px 22px;border-top:0}
    .ni-tax-pair{display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:14px}
    .ni-tax-pair .ni-floating:first-child input{border-radius:8px 0 0 8px}
    .ni-tax-pair .ni-floating:last-child input{border-radius:0 8px 8px 0}
    .ni-tax-default{display:flex;align-items:center;gap:9px;margin-top:13px;color:#405d6a;font-size:14px}
    .ni-tax-default input{width:19px;height:19px;accent-color:var(--jq-green)}
    .ni-tax-help{margin:13px 0 0;color:#607782;font-size:13px}
    .ni-tax-help a{color:var(--jq-green-dark);text-decoration:underline!important}

    @media(max-width:991.98px){.jq-products-invoice .jq-line-top{grid-template-columns:minmax(220px,1fr) 110px 140px 140px 42px!important}}
    @media(max-width:767.98px){.ni-quick-grid{grid-template-columns:1fr}.ni-quick-grid .full{grid-column:auto}.ni-customer-card{padding:17px 16px}.jq-products-invoice .jq-line-top{grid-template-columns:1fr 1fr!important}.jq-products-invoice .jq-line-catalog{grid-column:1/-1}.jq-products-invoice .jq-line-body{grid-template-columns:1fr!important}.ni-new-item-costs{grid-template-columns:1fr}.ni-new-item-costs .ni-floating input,.ni-new-item-costs .ni-floating:first-child input,.ni-new-item-costs .ni-floating:last-child input{border-radius:8px}}
    @media(max-width:575.98px){.jq-products-invoice .jq-line-top{grid-template-columns:1fr!important}.jq-products-invoice .jq-line-catalog{grid-column:auto}.jq-products-invoice .jq-line-optional{margin-left:0!important}}

  </style>
</head>

<body>
  <?php require_once __DIR__ . '/includes/nav.php'; ?>
  <div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
      <div class="fieldplx-content-wrapper">
        <div class="fd-dashboard qa-jobber-page">
          <form id="quoteForm" autocomplete="off">
            <input type="hidden" name="quote_id" value="<?= (int)$quoteId ?>">
            <input type="hidden" name="request_id" id="realRequestId" value="">
            <input type="hidden" name="assessment_reschedule_id" id="assessmentRescheduleId" value="">
            <input type="hidden" name="items_json" id="itemsJson" value="[]">
            <input type="hidden" name="sections_json" id="sectionsJson" value="[]">
            <input type="hidden" name="custom_fields_json" id="customFieldsJson" value="[]">
            <input type="hidden" name="quote_no_custom" id="quoteNoCustom" value="0">
            <input type="hidden" name="client_view_options_json" id="clientViewOptionsJson" value="{}">
            <input type="hidden" name="payment_plan_mode" id="paymentPlanMode" value="none">
            <input type="hidden" name="payment_plan_split_type" id="paymentPlanSplitType" value="percent">
            <input type="hidden" name="payment_schedule_json" id="paymentScheduleJson" value="[]">
            <input type="hidden" name="deposit_required" id="depositRequired" value="0">
            <input type="hidden" name="deposit_type" id="depositType" value="percent">
            <input type="hidden" name="deposit_value" id="depositValue" value="0">
            <input type="hidden" name="note_mentions_json" id="noteMentionsJson" value="[]">
            <input type="hidden" id="globalTax" value="0">

            <section class="jq-top-card">
              <div class="jq-heading-row">
                <div class="jq-title-wrap">
                  <span class="jq-title-icon"><i class="bi bi-file-earmark-text"></i></span>
                  <h1><?= $viewOnly ? 'View Quote' : ($quoteId > 0 ? 'Edit Quote' : 'New Quote') ?></h1>
                </div>
                <a class="jq-back qa-nav-link" href="quotes.php"><i class="bi bi-arrow-left"></i> Back to Quotations</a>
              </div>

              <div class="jq-top-grid">
                <div class="jq-top-left">
                  <div class="jq-field full">
                    <input type="text" name="title" id="quoteTitle" maxlength="190" placeholder="Title" required <?= $viewOnly ? 'disabled' : '' ?>>
                  </div>
                  <div class="jq-customer-shell">
                    <div class="ni-customer-picker" id="clientPickerWrap">
                      <select name="client_id" id="clientId" required <?= $viewOnly ? 'disabled' : '' ?>><option value=""></option></select>
                    </div>
                    <div class="ni-customer-card" id="selectedClientCard">
                      <div class="ni-customer-card-head">
                        <div class="ni-customer-card-name"><span id="clientCardName">Customer</span><span class="ni-customer-active-dot" aria-hidden="true"></span></div>
                        <?php if (!$viewOnly): ?>
                        <div class="ni-customer-menu-wrap">
                          <button type="button" class="ni-customer-menu-button" id="clientMenuButton" aria-label="Customer options">...</button>
                          <div class="ni-customer-menu" id="clientMenu">
                            <button type="button" id="changeClientButton">Change customer</button>
                            <button type="button" id="changeLocationButton">Change location</button>
                          </div>
                        </div>
                        <?php endif; ?>
                      </div>
                      <div class="ni-customer-block"><span class="ni-customer-block-label">Billing Address</span><span class="ni-customer-block-value" id="clientBillingAddress">-</span></div>
                      <div class="ni-customer-block"><span class="ni-customer-block-label">Property Address</span><span class="ni-customer-block-value" id="clientPropertyAddress">-</span></div>
                      <div class="ni-customer-contact"><a href="#" id="clientPhoneLink" style="display:none"></a><a href="#" id="clientEmailLink" style="display:none"></a></div>
                    </div>
                    <div class="ni-location-picker" id="locationPickerWrap">
                      <select name="location_id" id="locationId" <?= $viewOnly ? 'disabled' : '' ?>><option value=""></option></select>
                    </div>
                  </div>
                  <div class="jq-linked-enquiry" hidden aria-hidden="true">
                    <button type="button" class="jq-link-button" id="toggleEnquiry" tabindex="-1"><i class="bi bi-link-45deg"></i> Link enquiry / revisit</button>
                    <div class="jq-enquiry-box" id="enquiryBox">
                      <select id="requestId" name="request_source" <?= $viewOnly ? 'disabled' : '' ?>><option value="">Direct Quote - No Enquiry</option></select>
                      <div class="jq-enquiry-summary" id="enquiryInfo">
                        <span><small>Enquiry</small><strong id="infoSource">—</strong></span>
                        <span><small>Service</small><strong id="infoService">—</strong></span>
                        <span><small>Visit</small><strong id="infoVisit">—</strong></span>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="jq-top-right">
                  <div class="jq-meta-row"><span>Quote #</span><input class="jq-quote-number-input" type="text" name="quote_no" id="quoteNumberInput" maxlength="100" value="" placeholder="Auto" <?= $viewOnly ? 'disabled' : '' ?>></div>
                  <div class="jq-meta-row jq-sales-row"><span>Salesperson</span><select name="salesperson_id" id="salespersonId" <?= $viewOnly ? 'disabled' : '' ?>><option value="">Unassigned</option></select></div>
                  <div class="jq-meta-row"><span>Status</span><select name="status" id="quoteStatus" <?= $viewOnly ? 'disabled' : '' ?>><option value="sent">Send for Approval</option><option value="draft">Draft</option><option value="internal_approval">Internal Approval</option><option value="approved">Approved</option><option value="viewed">Viewed</option><option value="changes_requested">Changes Requested</option><option value="rejected">Rejected</option><option value="expired">Expired</option></select></div>
                  <div class="jq-meta-row"><span>Valid until</span><input type="date" name="valid_until" id="validUntil" <?= $viewOnly ? 'disabled' : '' ?>></div>
                  <div class="jq-meta-row jq-customize-row"><span>Customize</span><button type="button" class="jq-small-btn" id="addCustomField" <?= $viewOnly ? 'disabled' : '' ?>>Add Field</button></div>
                  <div id="customFields" class="jq-custom-fields"></div>
                </div>
              </div>
            </section>

            <div class="jq-add-section-bar" id="topSectionBar">
              <span><i class="bi bi-plus-lg"></i> Add section</span>
              <button type="button" data-show-section="introduction">Introduction</button>
            </div>

            <section class="jq-section-card jq-optional-section" id="introductionSection">
              <div class="jq-section-head"><h2>Introduction</h2><button type="button" class="jq-icon-btn" data-hide-section="introduction" title="Remove section"><i class="bi bi-trash"></i></button></div>
              <div class="jq-upload-drop jq-intro-upload">
                <input type="file" id="introImageInput" accept="image/avif,image/gif,image/jpeg,image/png,image/webp,image/heic" hidden <?= $viewOnly ? 'disabled' : '' ?>>
                <button type="button" class="jq-small-btn" data-pick="introImageInput" <?= $viewOnly ? 'disabled' : '' ?>>Add Image</button>
                <span>AVIF, GIF, JPEG, PNG, WEBP, HEIC up to 25MB</span>
              </div>
              <input type="text" class="jq-input" name="introduction_title" id="introductionTitle" maxlength="190" placeholder="Title" <?= $viewOnly ? 'disabled' : '' ?>>
              <textarea class="jq-textarea" name="introduction" id="introduction" placeholder="Description" <?= $viewOnly ? 'disabled' : '' ?>></textarea>
              <div class="jq-file-list" id="introImageList"></div>
            </section>

            <section class="jq-section-card jq-products-card jq-products-invoice">
              <div class="jq-section-head"><h2>Product / Service</h2></div>
              <div id="lineItemCards"></div>
              <?php if (!$viewOnly): ?>
              <div class="jq-add-line-wrap">
                <button type="button" class="jq-add-line-invoice" id="addLineButton"><i class="bi bi-plus-lg"></i> Add Line Item</button>
                <button type="button" class="jq-add-text-invoice" id="addText"><i class="bi bi-plus-lg"></i> Add Text</button>
              </div>
              <?php endif; ?>
            </section>

            <section id="textSections"></section>

            <section class="jq-summary-card jq-invoice-summary">
              <div class="jq-client-view-wrap">
                <div class="jq-client-view">
                  <span><i class="bi bi-eye"></i> Client view</span>
                  <?php if (!$viewOnly): ?><button type="button" class="jq-text-action" id="customerViewChange">Change</button><?php endif; ?>
                </div>
                <div class="jq-client-view-panel" id="clientViewPanel">
                  <div class="jq-client-view-panel-head"><strong>Client view</strong><button type="button" id="clientViewCancel">Cancel</button></div>
                  <p>Adjust what your customer will see on this quote. Internal cost and markup are never shown.</p>
                  <div class="jq-view-checks">
                    <label><input type="checkbox" data-client-view="quantities" checked> Quantities</label>
                    <label><input type="checkbox" data-client-view="unit_prices" checked> Unit prices</label>
                    <label><input type="checkbox" data-client-view="line_item_totals" checked> Line item totals</label>
                    <label><input type="checkbox" data-client-view="totals" checked> Totals</label>
                  </div>
                </div>
              </div>
              <div class="jq-totals jq-jobber-totals">
                <div class="jq-total-row"><span>Subtotal</span><strong id="subTotal">0.00</strong></div>
                <div class="jq-total-row jq-total-action" id="discountRow"><span>Discount</span><button type="button" id="toggleDiscount">Add Discount</button></div>
                <div id="discountEditor" class="jq-adjustment-editor" hidden>
                  <div class="jq-discount-input"><input type="number" id="globalDiscount" min="0" step="0.01" value="0"><select id="globalDiscountType"><option value="fixed">Amount</option><option value="percentage">%</option></select></div>
                  <strong id="discountTotal">0.00</strong><button type="button" class="jq-remove-adjustment" id="removeDiscount" title="Remove discount"><i class="bi bi-trash"></i></button>
                </div>
                <div class="jq-total-row jq-total-action" id="taxRow"><span>Tax</span><button type="button" id="toggleTax">Add Tax</button></div>
                <div id="taxEditor" class="jq-adjustment-editor" hidden>
                  <div class="jq-tax-control" id="taxControl">
                    <button type="button" class="jq-tax-selector" id="taxSelectorButton"><span id="taxSelectorText">No tax rate created</span><i class="bi bi-chevron-down" id="taxSelectorChevron"></i></button>
                    <div class="jq-tax-menu" id="taxMenu"></div>
                  </div>
                  <strong id="taxTotal">0.00</strong><button type="button" class="jq-remove-adjustment" id="removeTax" title="Remove tax"><i class="bi bi-trash"></i></button>
                </div>
                <div class="jq-total-row grand"><span>Total</span><strong id="grandTotal">0.00</strong></div>
                <button type="button" class="jq-deposit-link" id="toggleDeposit">Add Deposit or Payment Schedule</button>
                <div class="jq-payment-summary" id="paymentPlanSummary"></div>
              </div>
            </section>

            <div class="jq-add-section-bar jq-bottom-section-bar">
              <span><i class="bi bi-plus-lg"></i> Add section</span>
              <button type="button" data-show-section="attachments">Attachments</button>
              <button type="button" data-show-section="images">Images</button>
              <button type="button" data-show-section="clientMessage">Client Message</button>
              <button type="button" data-show-section="disclaimer">Contract / Disclaimer</button>
            </div>

            <section class="jq-section-card jq-optional-section" id="attachmentsSection">
              <div class="jq-section-head"><div><h2>Attachments</h2><p>Include all attachments for your quote in one place</p></div><button type="button" class="jq-icon-btn" data-hide-section="attachments"><i class="bi bi-trash"></i></button></div>
              <div class="jq-upload-counter" id="attachmentCounter">0 of 10 uploaded</div>
              <div class="jq-upload-drop"><input type="file" id="attachmentInput" accept=".jpg,.jpeg,.png,.gif,.webp,.avif,.heic,.pdf,.doc,.docx" multiple hidden <?= $viewOnly ? 'disabled' : '' ?>><button type="button" class="jq-small-btn" data-pick="attachmentInput" <?= $viewOnly ? 'disabled' : '' ?>>Select Files</button><span>JPEG, PNG, HEIC, PDF, DOCX, up to 50MB each</span></div>
              <div class="jq-file-list" id="attachmentList"></div>
            </section>

            <section class="jq-section-card jq-optional-section" id="imagesSection">
              <div class="jq-section-head"><div><h2>Images</h2><p>Add images to showcase your past work</p></div><button type="button" class="jq-icon-btn" data-hide-section="images"><i class="bi bi-trash"></i></button></div>
              <div class="jq-upload-counter" id="imageCounter">0 of 10 uploaded</div>
              <div class="jq-upload-drop"><input type="file" id="imageInput" accept="image/avif,image/gif,image/jpeg,image/png,image/webp,image/heic" multiple hidden <?= $viewOnly ? 'disabled' : '' ?>><button type="button" class="jq-small-btn" data-pick="imageInput" <?= $viewOnly ? 'disabled' : '' ?>>Add Images</button><span>AVIF, GIF, JPEG, PNG, WEBP up to 25MB each</span></div>
              <div class="jq-file-list" id="imageList"></div>
            </section>

            <section class="jq-section-card jq-optional-section" id="clientMessageSection">
              <div class="jq-section-head"><h2>Client Message</h2><button type="button" class="jq-icon-btn" data-hide-section="clientMessage"><i class="bi bi-trash"></i></button></div>
              <textarea class="jq-textarea" name="client_message" id="clientMessage" placeholder="Description" <?= $viewOnly ? 'disabled' : '' ?>></textarea>
            </section>

            <section class="jq-section-card jq-optional-section" id="disclaimerSection">
              <div class="jq-section-head"><h2>Contract / Disclaimer</h2><button type="button" class="jq-icon-btn" data-hide-section="disclaimer"><i class="bi bi-trash"></i></button></div>
              <textarea class="jq-textarea" name="disclaimer" id="disclaimer" placeholder="Add your quote terms, contract language, or disclaimer" <?= $viewOnly ? 'disabled' : '' ?>></textarea>
              <label class="jq-check-row"><input type="checkbox" name="apply_disclaimer_default" id="applyDisclaimerDefault" value="1" <?= $viewOnly ? 'disabled' : '' ?>> Apply to all future quotes</label>
            </section>

            <section class="jq-notes-section jq-internal-notes">
              <h2>Notes</h2>
              <div class="jq-note-editor">
                <textarea class="jq-textarea" name="internal_notes" id="internalNotes" maxlength="10000" autocomplete="off" placeholder="Use @ in notes to mention your team" <?= $viewOnly ? 'disabled' : '' ?>></textarea>
                <div class="jq-mention-menu" id="noteMentionMenu" role="listbox"></div>
              </div>
              <?php if (!$viewOnly): ?>
              <div class="jq-note-upload" id="noteUploadDrop">
                <input type="file" id="noteAttachmentInput" accept="image/avif,image/jpeg,image/png,image/webp,image/heic,.heic,.pdf,.doc,.docx" multiple hidden>
                <button type="button" id="noteAttachButton">Attach files &amp; photos</button>
                <small>Select or drag files here to upload</small>
              </div>
              <?php endif; ?>
              <div class="jq-file-list" id="noteFileList"></div>
              <div class="jq-link-related">
                <strong>Link to related</strong>
                <label><input type="checkbox" name="link_notes_to_jobs" id="linkNotesJobs" value="1" checked <?= $viewOnly ? 'disabled' : '' ?>> Jobs</label>
                <label><input type="checkbox" name="link_notes_to_invoices" id="linkNotesInvoices" value="1" checked <?= $viewOnly ? 'disabled' : '' ?>> Invoices</label>
              </div>
            </section>

            <div class="jq-save-spacer"></div>
          </form>
        </div>
      </div>
    </main>
  </div>

  <div class="jq-savebar">
    <div class="jq-savebar-inner">
      <a class="jq-cancel qa-nav-link" href="quotes.php">Cancel</a>
      <?php if (!$viewOnly): ?>
      <div class="jq-save-group">
        <button type="button" class="jq-save-main" id="saveDraftButton">Save Draft</button>
        <button type="button" class="jq-save-main primary" id="saveButton"><span class="qa-loader"></span><span><?= $quoteId > 0 ? 'Save Quote' : 'Save Quote' ?></span></button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="modal fade ni-quick-modal" id="customerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h2 class="modal-title">Create Customer</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body"><div class="ni-quick-grid">
        <div class="ni-floating full"><label for="newCustomerName">Customer name</label><input id="newCustomerName" type="text" maxlength="190"></div>
        <div class="ni-floating full"><label for="newCustomerCompany">Company name</label><input id="newCustomerCompany" type="text" maxlength="190"></div>
        <div class="ni-floating"><label for="newCustomerPhone">Phone</label><input id="newCustomerPhone" type="text" maxlength="50"></div>
        <div class="ni-floating"><label for="newCustomerEmail">Email</label><input id="newCustomerEmail" type="email" maxlength="190"></div>
      </div></div>
      <div class="modal-footer"><button type="button" class="ni-btn" data-bs-dismiss="modal">Cancel</button><button type="button" class="ni-btn primary" id="createCustomerButton">Create Customer</button></div>
    </div></div>
  </div>

  <div class="modal fade ni-quick-modal" id="locationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h2 class="modal-title">Create Location</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="ni-quick-grid">
          <div class="ni-floating"><label for="newLocationName">Location name</label><input id="newLocationName" type="text" maxlength="190"></div>
          <div class="ni-floating"><label for="newLocationType">Location type</label><select id="newLocationType"><option value="site">Site</option><option value="home">Home</option><option value="office">Office</option><option value="shop">Shop</option><option value="warehouse">Warehouse</option><option value="factory">Factory</option><option value="farm">Farm</option><option value="other">Other</option></select></div>
          <div class="ni-floating full"><label for="newLocationAddress1">Address line 1</label><input id="newLocationAddress1" type="text" maxlength="255"></div>
          <div class="ni-floating full"><label for="newLocationAddress2">Address line 2</label><input id="newLocationAddress2" type="text" maxlength="255"></div>
          <div class="ni-floating"><label for="newLocationCity">City</label><input id="newLocationCity" type="text" maxlength="120"></div>
          <div class="ni-floating"><label for="newLocationState">State</label><input id="newLocationState" type="text" maxlength="120"></div>
          <div class="ni-floating"><label for="newLocationPostal">Postal code</label><input id="newLocationPostal" type="text" maxlength="40"></div>
        </div>
        <label class="ni-quick-check"><input id="newLocationPrimary" type="checkbox"><span>Use as primary / billing location</span></label>
      </div>
      <div class="modal-footer"><button type="button" class="ni-btn" data-bs-dismiss="modal">Cancel</button><button type="button" class="ni-btn primary" id="createLocationButton">Create Location</button></div>
    </div></div>
  </div>

  <div class="modal fade ni-catalog-modal" id="catalogItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h2 class="modal-title">Add Product / Service</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="ni-floating ni-modal-field"><label for="newItemType">Item type</label><select id="newItemType"><option value="service">Service</option><option value="product">Product</option></select></div>
        <div class="ni-floating ni-modal-field"><label for="newItemName">Name</label><input id="newItemName" type="text" maxlength="190"></div>
        <div class="ni-floating ni-modal-field"><label for="newItemDescription">Description</label><textarea id="newItemDescription"></textarea></div>
        <div class="ni-new-item-costs">
          <div class="ni-floating"><label for="newItemUnitCost">Unit cost</label><input id="newItemUnitCost" type="number" min="0" step="0.01" value="0.00"></div>
          <div class="ni-floating"><label for="newItemMarkup">Markup (%)</label><input id="newItemMarkup" type="number" min="0" step="0.01" value="0"></div>
          <div class="ni-floating"><label for="newItemUnitPrice">Unit price</label><input id="newItemUnitPrice" type="number" min="0" step="0.01" value="0.00"></div>
        </div>
        <div class="ni-item-image-upload">
          <input class="ni-item-image-input" id="newItemImage" type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
          <button class="ni-item-image-drop" id="newItemImagePick" type="button"><i class="bi bi-image"></i><strong>Add product / service image</strong><small>JPG, PNG or WEBP up to 4 MB</small></button>
          <div class="ni-item-image-preview" id="newItemImagePreview" style="display:none"><img id="newItemImagePreviewImg" src="" alt="Item image preview"><div class="ni-item-image-preview-copy"><span class="ni-item-image-preview-name" id="newItemImageName"></span><span class="ni-item-image-preview-size" id="newItemImageSize"></span><button class="ni-item-image-remove" id="newItemImageRemove" type="button">Remove image</button></div></div>
        </div>
        <label class="ni-tax-exempt"><input id="newItemTaxExempt" type="checkbox"> <span>Exempt from Tax</span></label>
      </div>
      <div class="modal-footer"><button type="button" class="ni-btn" data-bs-dismiss="modal">Cancel</button><button type="button" class="ni-btn primary" id="createCatalogItemButton">Create</button></div>
    </div></div>
  </div>

  <div class="modal fade ni-tax-modal" id="taxRateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h2 class="modal-title">Create Tax Rate</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="ni-tax-pair">
          <div class="ni-floating"><label for="newTaxName">Name</label><input id="newTaxName" type="text" maxlength="120"></div>
          <div class="ni-floating"><label for="newTaxRate">Tax rate (%)</label><input id="newTaxRate" type="number" min="0" max="100" step="0.0001" value="0"></div>
        </div>
        <div class="ni-floating"><label for="newTaxDescription">Internal tax description</label><input id="newTaxDescription" type="text" maxlength="190"></div>
        <label class="ni-tax-default"><input id="newTaxDefault" type="checkbox"><span>Make default for new quotes and invoices</span></label>
        <p class="ni-tax-help">Tax rates can be edited in <a href="settings.php">Settings &gt; Company Settings</a>.</p>
      </div>
      <div class="modal-footer"><button type="button" class="ni-btn" data-bs-dismiss="modal">Cancel</button><button type="button" class="ni-btn primary" id="createTaxRateButton">Create Tax Rate</button></div>
    </div></div>
  </div>

  <div class="modal fade qa-modal jq-payment-modal" id="paymentScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Deposit or Payment Schedule</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <label class="jq-payment-mode"><input type="radio" name="payment_mode_ui" value="deposit"> <span><strong>Deposit only</strong><small>Collect an upfront payment on quote approval</small></span></label>
        <div class="jq-deposit-only" id="depositOnlyPanel">
          <div class="jq-split-toggle"><button type="button" data-deposit-type="percent" class="active">%</button><button type="button" data-deposit-type="fixed" id="depositCurrencyButton">Amount</button></div>
          <input type="number" id="depositModalValue" min="0" step="0.01" value="0">
          <strong id="depositModalAmount">0.00</strong>
        </div>
        <label class="jq-payment-mode"><input type="radio" name="payment_mode_ui" value="schedule"> <span><strong>Payment Schedule</strong><small>Split the job into multiple invoices</small></span></label>
        <div class="jq-schedule-panel" id="schedulePanel">
          <div class="jq-split-row"><span>Split payments by</span><div class="jq-split-toggle"><button type="button" data-split-type="percent" class="active">%</button><button type="button" data-split-type="fixed" id="scheduleCurrencyButton">Amount</button></div></div>
          <div class="jq-schedule-head"><span>AMOUNT</span><span>DESCRIPTION</span><span>TOTAL</span><span></span></div>
          <div id="paymentScheduleRows"></div>
          <button type="button" class="jq-add-schedule" id="addScheduleRow"><i class="bi bi-plus-lg"></i> Add Invoice to Payment Schedule</button>
          <div class="jq-schedule-total"><div><strong>Job Total</strong><strong id="scheduleJobTotal">0.00</strong></div><div><span>Remaining</span><span id="scheduleRemaining">100%</span></div></div>
        </div>
      </div>
      <div class="modal-footer jq-payment-footer"><button type="button" class="qa-btn danger-link" id="deletePaymentPlan">Delete</button><span class="flex-grow-1"></span><button type="button" class="qa-btn" data-bs-dismiss="modal">Cancel</button><button type="button" class="qa-btn primary" id="savePaymentPlan">Save</button></div>
    </div></div>
  </div>

  <div class="modal fade qa-modal" id="unsavedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Unsaved Quote Changes</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body qa-unsaved-copy">You have unsaved quote changes. Do you want to leave this page and discard them?</div>
      <div class="modal-footer"><button type="button" class="qa-btn" data-bs-dismiss="modal">Stay Here</button><button type="button" class="qa-btn primary" id="leavePageButton">Leave Page</button></div>
    </div></div>
  </div>

  <div class="qa-toast info" id="toast"><span id="toastMsg">Notification</span></div>
  <?php require_once __DIR__ . '/includes/footer.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <script>
  (function(){
    'use strict';
    var csrfToken=<?= json_encode($csrfToken) ?>, quoteId=<?= (int)$quoteId ?>, viewOnly=<?= $viewOnly?'true':'false' ?>, preRequestId=<?= (int)$preRequestId ?>, preRevisitId=<?= (int)$preRevisitId ?>, preClientId=<?= (int)$preClientId ?>;
    var meta={currency:{symbol:'',symbol_position:'before',decimal_places:2},requests:[],catalog:[],products:[],clients:[],locations:[],salespersons:[],team_members:[],tax_rates:[],quote_settings:{}};
    var cart=[], textSections=[], customFields=[], existingFiles=[], pendingFiles={attachment:[],image:[],introduction_image:[],note_attachment:[]}, pendingLineImages={};
    var clientViewOptions={quantities:true,unit_prices:true,line_item_totals:true,totals:true}, paymentPlan={mode:'none',split_type:'percent',deposit_type:'percent',deposit_value:0,rows:[]}, noteMentionIds=[], mentionMatches=[], mentionActiveIndex=0, dragIndex=-1, discountMode='fixed';
    var dirty=false, submitting=false, timer=null, pendingUrl='';
    var form=document.getElementById('quoteForm'), toast=document.getElementById('toast'), toastMsg=document.getElementById('toastMsg');
    var customerModal=new bootstrap.Modal(document.getElementById('customerModal')),locationModal=new bootstrap.Modal(document.getElementById('locationModal')),catalogModal=new bootstrap.Modal(document.getElementById('catalogItemModal')),taxRateModal=new bootstrap.Modal(document.getElementById('taxRateModal')),paymentModal=new bootstrap.Modal(document.getElementById('paymentScheduleModal')),unsavedModal=new bootstrap.Modal(document.getElementById('unsavedModal'));
    var selectedTaxRateId=0;
    var draftItem={product_service_id:null,product_id:null,item_type:'manual',item_name:'',description:'',quantity:1,unit_cost:0,markup_percent:0,unit_price:0,discount_amount:0,tax_percent:0,is_optional:0,service_date:'',image_path:''},catalogPendingName='',catalogPriceTouched=false,catalogTargetIndex=null,catalogImageFile=null,catalogImageObjectUrl=null;
    function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
    function label(v){return String(v||'').replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()})}
    function notify(t,m){if(timer)clearTimeout(timer);toast.className='qa-toast '+(t||'info')+' show';toastMsg.textContent=m||'Notification';timer=setTimeout(function(){toast.classList.remove('show')},3600)}
    function parse(r){return r.text().then(function(raw){var d,t=(raw||'').trim();try{d=t?JSON.parse(t):{}}catch(e){throw new Error(t.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!r.ok||!d.success)throw new Error(d.message||'Request failed.');return d})}
    function req(fd){fd.append('csrf_token',csrfToken);return fetch('api/quotations.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parse)}
    function money(n){n=Number(n||0);var s=n.toFixed(Number(meta.currency.decimal_places||2)),sym=meta.currency.symbol||'';return meta.currency.symbol_position==='after'?s+(sym?' '+sym:''):(sym||'')+s}
    function effectiveTaxPercent(x){var own=Math.max(0,Number(x&&x.tax_percent||0));if(own>0)return own;var r=selectedTaxRate();return r?Math.max(0,Number(r.rate_percent||0)):0}
    function calc(x){var base=Math.max(0,Number(x.quantity||0)*Number(x.unit_price||0)),disc=Math.min(base,Math.max(0,Number(x.discount_amount||0))),taxable=Math.max(0,base-disc),tax=taxable*effectiveTaxPercent(x)/100;return{base:base,discount:disc,tax:tax,total:taxable+tax}}
    function createOptionNode(text){var n=document.createElement('div');n.className='ni-create-option';n.innerHTML='<i class="bi bi-plus-lg"></i><span>'+esc(text)+'</span>';return $(n)}
    function customerOptionsHtml(){var h='<option value=""></option>';meta.clients.forEach(function(c){h+='<option value="'+Number(c.id)+'">'+esc((c.name||c.display_name||'Customer')+(c.company_name?' - '+c.company_name:'')+(c.phone?' - '+c.phone:''))+'</option>'});return h}
    function customerResult(item){if(!item.id)return item.text;return String(item.id).indexOf('newclient:')===0?createOptionNode('Create new customer'):item.text}
    function openCustomerModal(prefill){document.getElementById('newCustomerName').value=String(prefill||'');document.getElementById('newCustomerCompany').value='';document.getElementById('newCustomerPhone').value='';document.getElementById('newCustomerEmail').value='';customerModal.show();setTimeout(function(){document.getElementById('newCustomerName').focus();document.getElementById('newCustomerName').select()},160)}
    function initCustomerSelect(selected){var $x=$('#clientId');if($x.hasClass('select2-hidden-accessible'))$x.select2('destroy');$x.html(customerOptionsHtml()).select2({width:'100%',placeholder:'Select a customer',allowClear:true,tags:!viewOnly,minimumResultsForSearch:0,createTag:function(params){var term=$.trim(params.term||'');if(!term||viewOnly)return null;var exact=meta.clients.some(function(c){return String(c.name||c.display_name||'').toLowerCase()===term.toLowerCase()});return exact?null:{id:'newclient:'+term,text:term,newTag:true}},templateResult:customerResult});if(Number(selected)>0)$x.val(String(selected)).trigger('change.select2')}
    function locationsForClient(clientId){return meta.locations.filter(function(x){return Number(x.client_id)===Number(clientId||0)})}
    function defaultLocationId(clientId){var list=locationsForClient(clientId),p=list.find(function(x){return Number(x.is_primary||0)===1});return p?Number(p.id):(list.length?Number(list[0].id):0)}
    function locationResult(item){if(!item.id)return item.text;return String(item.id).indexOf('newloc:')===0?createOptionNode('Create new location'):item.text}
    function openLocationModal(prefill){var c=currentClient();if(!c){notify('warning','Select or create a customer first.');return}document.getElementById('newLocationName').value=String(prefill||'');['newLocationAddress1','newLocationAddress2','newLocationCity','newLocationState','newLocationPostal'].forEach(function(id){document.getElementById(id).value=''});document.getElementById('newLocationType').value='site';document.getElementById('newLocationPrimary').checked=locationsForClient(c.id).length===0;locationModal.show();setTimeout(function(){document.getElementById('newLocationName').focus();document.getElementById('newLocationName').select()},160)}
    function filterLocations(clientId,selected){var $x=$('#locationId');if($x.hasClass('select2-hidden-accessible'))$x.select2('destroy');var list=locationsForClient(clientId),h='<option value=""></option>';list.forEach(function(x){var a=[x.address_line1,x.city,x.state].filter(Boolean).join(', ');h+='<option value="'+Number(x.id)+'">'+esc((x.name||'Location')+(a?' - '+a:''))+'</option>'});$x.html(h).select2({width:'100%',placeholder:'Select or create location',allowClear:true,tags:!viewOnly&&Number(clientId)>0,minimumResultsForSearch:0,createTag:function(params){var term=$.trim(params.term||'');if(!term||viewOnly||Number(clientId)<=0)return null;var exact=list.some(function(x){return String(x.name||'').toLowerCase()===term.toLowerCase()});return exact?null:{id:'newloc:'+term,text:term,newTag:true}},templateResult:locationResult});if(Number(selected)>0)$x.val(String(selected)).trigger('change.select2')}
    function setLocations(clientId,selected){filterLocations(clientId,selected)}
    function currentClient(){var id=Number($('#clientId').val()||0);return meta.clients.find(function(c){return Number(c.id)===id})||null}
    function currentLocation(){var id=Number($('#locationId').val()||0);return meta.locations.find(function(x){return Number(x.id)===id})||null}
    function primaryLocation(clientId){var list=locationsForClient(clientId);return list.find(function(x){return Number(x.is_primary||0)===1})||(list.length?list[0]:null)}
    function formatAddress(x){if(!x)return '';return [x.address_line1,x.address_line2,x.city,x.state,x.postal_code].filter(function(v){return String(v||'').trim()!==''}).join(', ')}
    function renderCustomerCard(){var c=currentClient(),card=document.getElementById('selectedClientCard'),picker=document.getElementById('clientPickerWrap'),locWrap=document.getElementById('locationPickerWrap');if(!c){card.classList.remove('show');picker.style.display='block';locWrap.classList.remove('show');return}picker.style.display='none';card.classList.add('show');document.getElementById('clientCardName').textContent=c.name||c.display_name||'Customer';var billing=primaryLocation(c.id),property=currentLocation(),billingText=formatAddress(billing),propertyText=formatAddress(property);document.getElementById('clientBillingAddress').textContent=billingText||'No billing address saved';document.getElementById('clientPropertyAddress').textContent=property?(billing&&Number(property.id)===Number(billing.id)?'(Same as billing address)':(propertyText||property.name||'Selected location')):'No service location selected';var ph=document.getElementById('clientPhoneLink'),em=document.getElementById('clientEmailLink');if(c.phone){ph.style.display='inline';ph.textContent=c.phone;ph.href='tel:'+String(c.phone).replace(/[^+0-9]/g,'')}else ph.style.display='none';if(c.email){em.style.display='inline';em.textContent=c.email;em.href='mailto:'+c.email}else em.style.display='none';locWrap.classList.toggle('show',!property)}
    function selectCustomerById(clientId,locationId){initCustomerSelect(clientId);var sel=Number(locationId||0);if(sel<=0)sel=defaultLocationId(clientId);filterLocations(clientId,sel);renderCustomerCard()}
    function showRequest(key){var r=meta.requests.find(function(x){return String(x.source_key||('request:'+x.id))===String(key)});if(!r){document.getElementById('realRequestId').value='';document.getElementById('assessmentRescheduleId').value='';document.getElementById('enquiryInfo').classList.remove('show');return}document.getElementById('realRequestId').value=String(r.request_id||r.id||'');document.getElementById('assessmentRescheduleId').value=r.assessment_reschedule_id?String(r.assessment_reschedule_id):'';selectCustomerById(Number(r.client_id||0),Number(r.location_id||0));document.getElementById('infoSource').textContent=r.request_no+' · '+(r.source_label||'Original Enquiry');document.getElementById('infoService').textContent=r.service_name||r.title||'—';var visit=r.visit_date||'—';if(r.visit_time_from)visit+=' · '+String(r.visit_time_from).slice(0,5);document.getElementById('infoVisit').textContent=visit;document.getElementById('enquiryInfo').classList.add('show');if(!document.getElementById('quoteTitle').value)document.getElementById('quoteTitle').value=r.title||'Quote'}
    function loadRequestQuoteSource(requestId,revisitId){if(Number(requestId||0)<=0||quoteId>0)return Promise.resolve();var fd=new FormData();fd.append('action','request_quote_source');fd.append('request_id',String(Number(requestId)));fd.append('assessment_reschedule_id',String(Number(revisitId||0)));return req(fd).then(function(d){var r=d.request||{},items=Array.isArray(d.line_items)?d.line_items:[];if(Number(r.client_id||0)>0)selectCustomerById(Number(r.client_id),Number(r.location_id||0));if(!document.getElementById('quoteTitle').value&&r.title)document.getElementById('quoteTitle').value=r.title;if(items.length){cart=items.map(function(x){return normalizeItem({product_service_id:x.product_service_id,product_id:x.product_id,item_type:x.item_type,item_name:x.item_name,description:x.description,image_path:x.image_path||'',quantity:x.quantity,unit_cost:x.unit_cost,markup_percent:x.markup_percent,unit_price:x.unit_price,discount_amount:0,tax_percent:x.tax_percent,is_optional:0,service_date:x.service_date||r.preferred_date||''})});renderItems();syncTaxSelectorFromCart();notify('success',(r.request_no||'Request')+' product / service items loaded into this quotation.')}else{renderItems();notify('warning','This request has no product / service items to copy. Add quotation items before saving.')}dirty=false;return d})}
    function quoteTotal(){var g=0;cart.forEach(function(x){g+=calc(x).total});return g}
    function refreshTotals(){var st=0,di=0,tx=0,g=0;cart.forEach(function(x){var c=calc(x);st+=c.base;di+=c.discount;tx+=c.tax;g+=c.total});document.getElementById('subTotal').textContent=money(st);document.getElementById('discountTotal').textContent=di>0?('-'+money(di)):money(0);document.getElementById('taxTotal').textContent=money(tx);document.getElementById('grandTotal').textContent=money(g);document.getElementById('itemsJson').value=JSON.stringify(cart.map(function(x){var y=Object.assign({},x);delete y._localImage;y.tax_percent=effectiveTaxPercent(y);return y}));renderPaymentSummary();}
    function selectedTaxRate(){return (meta.tax_rates||[]).find(function(r){return Number(r.id)===Number(selectedTaxRateId)})||null}
    function formatTaxRatePercent(v){return Number(v||0).toFixed(4).replace(/0+$/,'').replace(/\.$/,'')+'%'}
    function setTaxMenuOpen(show){var menu=document.getElementById('taxMenu'),chev=document.getElementById('taxSelectorChevron');if(menu)menu.classList.toggle('show',!!show);if(chev){chev.classList.toggle('bi-chevron-down',!show);chev.classList.toggle('bi-chevron-up',!!show)}}
    function renderTaxMenu(){var menu=document.getElementById('taxMenu');if(!menu)return;var html='';if(!(meta.tax_rates||[]).length)html='<div class="jq-tax-menu-empty">No options</div>';(meta.tax_rates||[]).forEach(function(r){html+='<button type="button" data-tax-rate-id="'+Number(r.id)+'"><strong>'+esc(r.tax_name||('Tax '+formatTaxRatePercent(r.rate_percent)))+'</strong><small>'+esc(formatTaxRatePercent(r.rate_percent))+'</small></button>'});html+='<button type="button" class="jq-create-tax" id="createTaxRateLink">Create new tax rate</button>';menu.innerHTML=html}
    function updateTaxSelectorText(){var r=selectedTaxRate(),txt=document.getElementById('taxSelectorText');if(!txt)return;if(r)txt.textContent=(r.tax_name||'Tax')+' ('+formatTaxRatePercent(r.rate_percent)+')';else txt.textContent=(meta.tax_rates||[]).length?'Select tax rate':'No tax rate created'}
    function setSelectedTaxRate(id,applyToQuote){selectedTaxRateId=Number(id||0);var r=selectedTaxRate();document.getElementById('globalTax').value=r?String(Number(r.rate_percent||0)):'0';updateTaxSelectorText();setTaxMenuOpen(false);if(applyToQuote!==false)applyOverallTax()}
    function openTaxRateModal(){document.getElementById('newTaxName').value='';document.getElementById('newTaxRate').value='0';document.getElementById('newTaxDescription').value='';document.getElementById('newTaxDefault').checked=false;taxRateModal.show();setTimeout(function(){document.getElementById('newTaxName').focus()},160)}
    function createTaxRate(){var name=String(document.getElementById('newTaxName').value||'').trim(),rate=Number(document.getElementById('newTaxRate').value||0);if(!name){notify('warning','Enter a tax rate name.');document.getElementById('newTaxName').focus();return}if(!isFinite(rate)||rate<0||rate>100){notify('warning','Tax rate must be between 0 and 100.');document.getElementById('newTaxRate').focus();return}var fd=new FormData();fd.append('action','create_tax_rate');fd.append('name',name);fd.append('rate_percent',String(rate));fd.append('description',document.getElementById('newTaxDescription').value||'');fd.append('is_default',document.getElementById('newTaxDefault').checked?'1':'0');var btn=document.getElementById('createTaxRateButton');btn.disabled=true;btn.textContent='Creating...';req(fd).then(function(d){var r=d.tax_rate||{};if(Number(r.id||0)<=0)throw new Error('Tax rate was not returned by the server.');if(Number(r.is_default||0)===1)(meta.tax_rates||[]).forEach(function(x){x.is_default=0});var pos=(meta.tax_rates||[]).findIndex(function(x){return Number(x.id)===Number(r.id)});if(pos>=0)meta.tax_rates[pos]=r;else meta.tax_rates.push(r);if(Number(r.is_default||0)===1)meta.default_tax_rate_id=Number(r.id);renderTaxMenu();setSelectedTaxRate(r.id,true);taxRateModal.hide();notify('success',d.message||'Tax rate created successfully.')}).catch(function(e){notify('error',e.message)}).finally(function(){btn.disabled=false;btn.textContent='Create Tax Rate'})}
    function syncTaxSelectorFromCart(){var rates=[];cart.forEach(function(x){var p=Number(x.tax_percent||0);if(p>0&&!rates.some(function(v){return Math.abs(v-p)<0.00001}))rates.push(p)});if(rates.length===1){var match=(meta.tax_rates||[]).find(function(r){return Math.abs(Number(r.rate_percent||0)-rates[0])<0.00001});selectedTaxRateId=match?Number(match.id):0;document.getElementById('globalTax').value=String(rates[0]);if(match)updateTaxSelectorText();else document.getElementById('taxSelectorText').textContent='Custom tax ('+formatTaxRatePercent(rates[0])+')';if(!viewOnly){var ed=document.getElementById('taxEditor');ed.hidden=false;ed.classList.add('show');document.getElementById('toggleTax').style.display='none'}}else{selectedTaxRateId=Number(meta.default_tax_rate_id||0);document.getElementById('globalTax').value='0';updateTaxSelectorText()}}
    function lineCatalogValue(x,i){if(x&&Number(x.product_service_id||0)>0)return 'service:'+Number(x.product_service_id);if(x&&Number(x.product_id||0)>0)return 'product:'+Number(x.product_id);if(x&&String(x.item_name||'').trim()!=='')return 'manual:'+i;return ''}
    function lineCatalogOptions(x,i){var h=catalogOptions();if(x&&String(x.item_name||'').trim()!==''&&!x.product_service_id&&!x.product_id)h+='<option value="manual:'+i+'">'+esc(x.item_name)+'</option>';return h}
    function catalogSelection(item){return item&&item.id?item.text:'Name'}
    function initLineCatalogs(){document.querySelectorAll('.jq-line-catalog-select').forEach(function(el){var i=Number(el.getAttribute('data-line-catalog')),x=cart[i]||null,$el=$(el);if($el.hasClass('select2-hidden-accessible'))$el.select2('destroy');$el.select2({width:'100%',placeholder:'Name',allowClear:true,tags:!viewOnly,minimumResultsForSearch:0,createTag:function(params){var term=$.trim(params.term||'');if(!term||viewOnly)return null;var lower=term.toLowerCase(),exists=meta.catalog.some(function(v){return String(v.name||'').toLowerCase()===lower})||meta.products.some(function(v){return String(v.name||'').toLowerCase()===lower});return exists?null:{id:'new:'+term,text:term,newTag:true}},templateResult:catalogResult,templateSelection:catalogSelection});var current=lineCatalogValue(x,i);if(current)$el.val(current).trigger('change.select2');if(!viewOnly){$el.on('select2:select',function(e){var raw=e.params&&e.params.data?e.params.data.id:this.value;applyCatalogToLine(i,raw)});$el.on('select2:clear',function(){if(cart[i]){cart[i]=normalizeItem({item_type:'manual',quantity:cart[i].quantity||1,service_date:cart[i].service_date||'',is_optional:cart[i].is_optional||0});renderItems();dirty=true}})}})}
    function renderItems(){var box=document.getElementById('lineItemCards');if(!cart.length){box.innerHTML='';refreshTotals();return}box.innerHTML=cart.map(function(x,i){var c=calc(x),img=x.image_path?'<img src="'+esc(x.image_path)+'" alt="Line item image">':'<button type="button" data-line-image="'+i+'" title="Add image"><i class="bi bi-image"></i></button>';return '<article class="jq-line-card" data-i="'+i+'" draggable="'+(!viewOnly?'true':'false')+'">'+(!viewOnly?'<span class="jq-drag-grip" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></span>':'')+'<div class="jq-line-top"><div class="jq-line-catalog"><select class="jq-line-catalog-select" data-line-catalog="'+i+'" '+(viewOnly?'disabled':'')+'>'+lineCatalogOptions(x,i)+'</select></div><label class="jq-number-field"><span>Quantity</span><input type="number" min="0.001" step="0.001" data-f="quantity" value="'+Number(x.quantity||1)+'" '+(viewOnly?'disabled':'')+'></label><div class="jq-price-wrap"><label class="jq-number-field"><span>Unit price</span><input type="number" min="0" step="0.01" data-f="unit_price" data-show-cost="'+i+'" value="'+Number(x.unit_price||0).toFixed(2)+'" '+(viewOnly?'disabled':'')+'></label><div class="jq-cost-popover" data-cost-popover="'+i+'"><label class="jq-number-field"><span>Unit cost</span><input type="number" min="0" step="0.01" data-f="unit_cost" value="'+Number(x.unit_cost||0).toFixed(2)+'" '+(viewOnly?'disabled':'')+'></label><label class="jq-number-field"><span>Markup (%)</span><input type="number" min="0" step="0.01" data-f="markup_percent" value="'+Number(x.markup_percent||0).toFixed(2)+'" '+(viewOnly?'disabled':'')+'></label><p>These calculations won\'t be visible to your clients</p></div></div><div class="jq-line-total"><span>Total</span><strong>'+money(c.total)+'</strong></div>'+(viewOnly?'':'<button type="button" class="jq-icon-btn" data-remove="'+i+'" title="Remove"><i class="bi bi-trash"></i></button>')+'</div><div class="jq-line-body"><textarea class="jq-line-desc" data-f="description" placeholder="Description" '+(viewOnly?'disabled':'')+'>'+esc(x.description||'')+'</textarea><div class="jq-line-image">'+img+(x.image_path&&!viewOnly?'<button type="button" class="jq-line-image-remove" data-remove-line-image="'+i+'" title="Remove image"><i class="bi bi-trash"></i></button>':'')+'<input type="file" data-line-image-input="'+i+'" accept="image/avif,image/gif,image/jpeg,image/png,image/webp,image/heic" hidden></div></div><div class="jq-line-footer"><label class="jq-line-optional"><input type="checkbox" data-f="is_optional" '+(Number(x.is_optional)===1?'checked':'')+' '+(viewOnly?'disabled':'')+'> Mark as optional</label></div></article>'}).join('');initLineCatalogs();refreshTotals()}
    function renderTextSections(){var box=document.getElementById('textSections');box.innerHTML=textSections.map(function(x,i){return '<section class="jq-section-card"><div class="jq-section-head"><h2>Text</h2>'+(viewOnly?'':'<button type="button" class="jq-icon-btn" data-remove-text="'+i+'"><i class="bi bi-trash"></i></button>')+'</div><input class="jq-input" data-sec="title" data-si="'+i+'" value="'+esc(x.title||'')+'" placeholder="Title" '+(viewOnly?'disabled':'')+'><textarea class="jq-textarea" data-sec="body" data-si="'+i+'" placeholder="Description" '+(viewOnly?'disabled':'')+'>'+esc(x.body||'')+'</textarea></section>'}).join('');syncSections()}
    function syncSections(){document.getElementById('sectionsJson').value=JSON.stringify(textSections)}
    function renderCustomFields(){var box=document.getElementById('customFields');box.innerHTML=customFields.map(function(x,i){return '<div class="jq-custom-field"><input data-cf="label" data-ci="'+i+'" value="'+esc(x.label||'')+'" placeholder="Field name" '+(viewOnly?'disabled':'')+'><input data-cf="value" data-ci="'+i+'" value="'+esc(x.value||'')+'" placeholder="Value" '+(viewOnly?'disabled':'')+'>'+(viewOnly?'':'<button type="button" data-remove-custom="'+i+'"><i class="bi bi-x"></i></button>')+'</div>'}).join('');document.getElementById('customFieldsJson').value=JSON.stringify(customFields)}
    function normalizeItem(x){x=x||{};var cost=Math.max(0,Number(x.unit_cost||0)),price=Math.max(0,Number(x.unit_price||0)),markup=x.markup_percent!=null?Math.max(0,Number(x.markup_percent||0)):(cost>0?Math.max(0,(price-cost)/cost*100):0);return{product_service_id:Number(x.product_service_id||0)||null,product_id:Number(x.product_id||0)||null,item_type:String(x.item_type||((x.product_id)?'product':((x.product_service_id)?'service':'manual'))),item_name:String(x.item_name||''),description:String(x.description||''),image_path:String(x.image_path||''),quantity:Math.max(.001,Number(x.quantity||1)),unit_cost:cost,markup_percent:markup,unit_price:price,discount_amount:Math.max(0,Number(x.discount_amount||0)),tax_percent:Math.max(0,Number(x.tax_percent||0)),is_optional:Number(x.is_optional||0)?1:0,service_date:String(x.service_date||'')};}
    function addManual(){addBlankLine()}
    function addBlankLine(){cart.push(normalizeItem({item_type:'manual',item_name:'',quantity:1}));dirty=true;renderItems();var i=cart.length-1;setTimeout(function(){var el=document.querySelector('[data-line-catalog="'+i+'"]');if(el)try{$(el).select2('open')}catch(e){}},40)}
    function catalogOptions(){var h='<option value=""></option>';meta.catalog.forEach(function(x){h+='<option value="service:'+Number(x.id)+'" data-kind="service" data-price="'+esc(x.unit_price||0)+'" data-description="'+esc(x.description||'')+'">'+esc(x.name)+'</option>'});meta.products.forEach(function(x){h+='<option value="product:'+Number(x.id)+'" data-kind="product" data-price="'+esc(x.selling_price||0)+'" data-description="'+esc(x.description||'')+'">'+esc(x.name)+'</option>'});return h}
    function findCatalogValue(v){var a=String(v||'').split(':'),kind=a[0],id=Number(a[1]||0);if(kind==='service')return{kind:kind,item:meta.catalog.find(function(x){return Number(x.id)===id})};if(kind==='product')return{kind:kind,item:meta.products.find(function(x){return Number(x.id)===id})};return null}
    function catalogResult(item){if(!item.id)return item.text;var id=String(item.id||'');if(id.indexOf('new:')===0){var n=document.createElement('div');n.className='ni-create-option';n.innerHTML='<i class="bi bi-plus-lg"></i><span>Create new product / service</span>';return $(n)}var el=item.element,kind=el?String(el.getAttribute('data-kind')||''):'',price=el?el.getAttribute('data-price'):'',desc=el?el.getAttribute('data-description'):'';var row=document.createElement('div');row.className='jq-catalog-result';var copy=document.createElement('div');copy.className='jq-catalog-copy';var nm=document.createElement('div');nm.className='jq-catalog-name';var tx=document.createElement('span');tx.textContent=item.text||'';nm.appendChild(tx);if(kind){var badge=document.createElement('span');badge.className='jq-type-badge '+kind;badge.textContent=kind==='product'?'Product':'Service';nm.appendChild(badge)}copy.appendChild(nm);if(desc){var ds=document.createElement('div');ds.className='jq-catalog-desc';ds.textContent=desc;copy.appendChild(ds)}row.appendChild(copy);if(price!==''){var pr=document.createElement('div');pr.className='jq-catalog-price';pr.textContent=money(price);row.appendChild(pr)}return $(row)}
    function applyCatalogToLine(index,raw){raw=String(raw||'');if(!cart[index])return;if(raw.indexOf('new:')===0){catalogTargetIndex=index;openCatalogModal(raw.slice(4));return}if(raw.indexOf('manual:')===0)return;var found=findCatalogValue(raw);if(!found||!found.item)return;var x=found.item,old=cart[index],qty=Math.max(.001,Number(old.quantity||1)),date=String(old.service_date||''),optional=Number(old.is_optional||0)?1:0;if(found.kind==='service')cart[index]=normalizeItem({product_service_id:x.id,item_type:'service',item_name:x.name,description:x.description||'',image_path:x.image_path||'',quantity:qty,unit_cost:x.unit_cost,unit_price:x.unit_price,tax_percent:x.tax_percent,service_date:date,is_optional:optional});else cart[index]=normalizeItem({product_id:x.id,item_type:'product',item_name:x.name,description:x.description||'',image_path:x.image_path||'',quantity:qty,unit_cost:x.base_unit_price,unit_price:x.selling_price,tax_percent:x.tax_percent,service_date:date,is_optional:optional});dirty=true;renderItems()}
    function resetCatalogImage(){catalogImageFile=null;var input=document.getElementById('newItemImage');if(input)input.value='';if(catalogImageObjectUrl){try{URL.revokeObjectURL(catalogImageObjectUrl)}catch(e){}catalogImageObjectUrl=null}document.getElementById('newItemImagePreviewImg').src='';document.getElementById('newItemImageName').textContent='';document.getElementById('newItemImageSize').textContent='';document.getElementById('newItemImagePreview').style.display='none';document.getElementById('newItemImagePick').style.display='flex'}
    function setCatalogImage(file){if(!file){resetCatalogImage();return false}var allowed=['image/jpeg','image/png','image/webp'],name=String(file.name||''),ext=(name.split('.').pop()||'').toLowerCase();if(allowed.indexOf(String(file.type||'').toLowerCase())<0&&['jpg','jpeg','png','webp'].indexOf(ext)<0){notify('warning','Item image must be JPG, PNG or WEBP.');resetCatalogImage();return false}if(Number(file.size||0)<=0||Number(file.size)>4*1024*1024){notify('warning','Item image must be 4 MB or smaller.');resetCatalogImage();return false}if(catalogImageObjectUrl){try{URL.revokeObjectURL(catalogImageObjectUrl)}catch(e){}}catalogImageFile=file;catalogImageObjectUrl=URL.createObjectURL(file);document.getElementById('newItemImagePreviewImg').src=catalogImageObjectUrl;document.getElementById('newItemImageName').textContent=name;document.getElementById('newItemImageSize').textContent=(Number(file.size||0)/1024).toFixed(1)+' KB';document.getElementById('newItemImagePick').style.display='none';document.getElementById('newItemImagePreview').style.display='flex';return true}
    function openCatalogModal(name){catalogPendingName=String(name||'').trim();document.getElementById('newItemType').value='service';document.getElementById('newItemName').value=catalogPendingName;document.getElementById('newItemDescription').value='';document.getElementById('newItemUnitCost').value='0.00';document.getElementById('newItemMarkup').value='0';document.getElementById('newItemUnitPrice').value='0.00';document.getElementById('newItemTaxExempt').checked=false;resetCatalogImage();catalogPriceTouched=false;catalogModal.show();setTimeout(function(){document.getElementById('newItemName').focus();document.getElementById('newItemName').select()},150)}
    function recalcNewItemPrice(){if(catalogPriceTouched)return;var cost=Math.max(0,Number(document.getElementById('newItemUnitCost').value||0)),markup=Math.max(0,Number(document.getElementById('newItemMarkup').value||0));document.getElementById('newItemUnitPrice').value=(cost*(1+markup/100)).toFixed(2)}
    function createCatalogItem(){var type=document.getElementById('newItemType').value,name=document.getElementById('newItemName').value.trim();if(!name){notify('warning','Enter a product / service name.');return}var fd=new FormData();fd.append('action','create_catalog_item');fd.append('item_type',type);fd.append('name',name);fd.append('description',document.getElementById('newItemDescription').value||'');fd.append('unit_cost',document.getElementById('newItemUnitCost').value||0);fd.append('markup_percent',document.getElementById('newItemMarkup').value||0);fd.append('unit_price',document.getElementById('newItemUnitPrice').value||0);var selectedRate=selectedTaxRate();fd.append('tax_rate_id',(!document.getElementById('newItemTaxExempt').checked&&selectedRate)?String(selectedRate.id||0):'0');fd.append('tax_percent',(!document.getElementById('newItemTaxExempt').checked&&selectedRate)?String(selectedRate.rate_percent||0):'0');fd.append('exempt_tax',document.getElementById('newItemTaxExempt').checked?'1':'0');if(catalogImageFile)fd.append('item_image',catalogImageFile,catalogImageFile.name||'item-image');var btn=document.getElementById('createCatalogItemButton');btn.disabled=true;btn.textContent='Creating...';req(fd).then(function(d){var item=d.item||{},kind=String(d.item_kind||type);if(kind==='service'){var p=meta.catalog.findIndex(function(x){return Number(x.id)===Number(item.id)});if(p>=0)meta.catalog[p]=item;else meta.catalog.push(item)}else{var pp=meta.products.findIndex(function(x){return Number(x.id)===Number(item.id)});if(pp>=0)meta.products[pp]=item;else meta.products.push(item)}var val=kind+':'+Number(item.id),target=catalogTargetIndex;catalogTargetIndex=null;resetCatalogImage();catalogModal.hide();if(target!==null&&cart[target])applyCatalogToLine(target,val);notify('success',d.message||'Item created successfully.')} ).catch(function(e){notify('error',e.message)}).finally(function(){btn.disabled=false;btn.textContent='Create'})}
    function createCustomer(){var name=document.getElementById('newCustomerName').value.trim(),email=document.getElementById('newCustomerEmail').value.trim();if(!name){notify('warning','Enter a customer name.');document.getElementById('newCustomerName').focus();return}var fd=new FormData();fd.append('action','create_customer');fd.append('display_name',name);fd.append('company_name',document.getElementById('newCustomerCompany').value||'');fd.append('phone',document.getElementById('newCustomerPhone').value||'');fd.append('email',email);var btn=document.getElementById('createCustomerButton');btn.disabled=true;btn.textContent='Creating...';req(fd).then(function(d){var c=d.client||{};if(Number(c.id||0)<=0)throw new Error('Customer was not returned by the server.');c.name=c.name||c.display_name||name;var p=meta.clients.findIndex(function(x){return Number(x.id)===Number(c.id)});if(p>=0)meta.clients[p]=c;else meta.clients.push(c);initCustomerSelect(c.id);filterLocations(c.id,0);renderCustomerCard();customerModal.hide();notify('success',d.message||'Customer created successfully.');dirty=true}).catch(function(e){notify('error',e.message)}).finally(function(){btn.disabled=false;btn.textContent='Create Customer'})}
    function createLocation(){var c=currentClient();if(!c){notify('warning','Select a customer first.');return}var name=document.getElementById('newLocationName').value.trim(),a1=document.getElementById('newLocationAddress1').value.trim();if(!name){notify('warning','Enter a location name.');return}if(!a1){notify('warning','Enter address line 1.');return}var fd=new FormData();fd.append('action','create_location');fd.append('client_id',String(c.id));fd.append('name',name);fd.append('location_type',document.getElementById('newLocationType').value||'site');fd.append('address_line1',a1);fd.append('address_line2',document.getElementById('newLocationAddress2').value||'');fd.append('city',document.getElementById('newLocationCity').value||'');fd.append('state',document.getElementById('newLocationState').value||'');fd.append('postal_code',document.getElementById('newLocationPostal').value||'');fd.append('is_primary',document.getElementById('newLocationPrimary').checked?'1':'0');var btn=document.getElementById('createLocationButton');btn.disabled=true;btn.textContent='Creating...';req(fd).then(function(d){var loc=d.location||{};if(Number(loc.id||0)<=0)throw new Error('Location was not returned by the server.');if(Number(loc.is_primary||0)===1)meta.locations.forEach(function(x){if(Number(x.client_id)===Number(c.id))x.is_primary=0});var p=meta.locations.findIndex(function(x){return Number(x.id)===Number(loc.id)});if(p>=0)meta.locations[p]=loc;else meta.locations.push(loc);filterLocations(c.id,loc.id);renderCustomerCard();document.getElementById('locationPickerWrap').classList.remove('show');locationModal.hide();notify('success',d.message||'Location created successfully.');dirty=true}).catch(function(e){notify('error',e.message)}).finally(function(){btn.disabled=false;btn.textContent='Create Location'})}
    function syncClientView(){document.getElementById('clientViewOptionsJson').value=JSON.stringify(clientViewOptions);document.querySelectorAll('[data-client-view]').forEach(function(x){x.checked=clientViewOptions[x.dataset.clientView]!==false})}
    function toggleClientView(show){document.getElementById('clientViewPanel').classList.toggle('show',!!show);syncClientView()}
    function syncSectionButtons(){document.querySelectorAll('[data-show-section]').forEach(function(b){var k=b.dataset.showSection,id=k==='introduction'?'introductionSection':k+'Section',el=document.getElementById(id);b.hidden=!!(el&&el.classList.contains('show'))});document.querySelectorAll('.jq-add-section-bar').forEach(function(bar){var available=Array.prototype.some.call(bar.querySelectorAll('[data-show-section]'),function(b){return !b.hidden});bar.classList.toggle('all-added',!available);bar.hidden=!available})}
    function paymentAmount(row,total){return paymentPlan.split_type==='percent'?Math.round(total*Math.min(100,Math.max(0,Number(row.split_value||0)))/100*100)/100:Math.min(total,Math.max(0,Number(row.split_value||0)))}
    function renderPaymentSummary(){var box=document.getElementById('paymentPlanSummary'),total=quoteTotal();document.getElementById('paymentPlanMode').value=paymentPlan.mode;document.getElementById('paymentPlanSplitType').value=paymentPlan.split_type;document.getElementById('paymentScheduleJson').value=JSON.stringify(paymentPlan.rows||[]);if(paymentPlan.mode==='deposit'){var a=paymentPlan.deposit_type==='percent'?Math.min(total,total*Math.min(100,Number(paymentPlan.deposit_value||0))/100):Math.min(total,Number(paymentPlan.deposit_value||0));document.getElementById('depositRequired').value='1';document.getElementById('depositType').value=paymentPlan.deposit_type;document.getElementById('depositValue').value=String(paymentPlan.deposit_value||0);box.className='jq-payment-summary show';box.innerHTML='<span>Quote deposit</span><strong>'+money(a)+'</strong>'}else if(paymentPlan.mode==='schedule'){document.getElementById('depositRequired').value='0';var req=(paymentPlan.rows||[]).find(function(r){return !!r.required_quote_deposit});if(req){document.getElementById('depositRequired').value='1';document.getElementById('depositType').value=paymentPlan.split_type==='percent'?'percent':'fixed';document.getElementById('depositValue').value=String(req.split_value||0)}box.className='jq-payment-summary show';box.innerHTML='<span>Payment schedule</span><strong>'+(paymentPlan.rows||[]).length+' payments</strong>'}else{document.getElementById('depositRequired').value='0';document.getElementById('depositValue').value='0';box.className='jq-payment-summary';box.innerHTML=''}}
    function renderPaymentModal(){var total=quoteTotal();document.querySelectorAll('input[name="payment_mode_ui"]').forEach(function(r){r.checked=r.value===paymentPlan.mode});document.getElementById('depositOnlyPanel').classList.toggle('show',paymentPlan.mode==='deposit');document.getElementById('schedulePanel').classList.toggle('show',paymentPlan.mode==='schedule');document.querySelectorAll('[data-deposit-type]').forEach(function(b){b.classList.toggle('active',b.dataset.depositType===paymentPlan.deposit_type)});document.querySelectorAll('[data-split-type]').forEach(function(b){b.classList.toggle('active',b.dataset.splitType===paymentPlan.split_type)});document.getElementById('depositModalValue').value=Number(paymentPlan.deposit_value||0);var da=paymentPlan.deposit_type==='percent'?total*Math.min(100,Number(paymentPlan.deposit_value||0))/100:Math.min(total,Number(paymentPlan.deposit_value||0));document.getElementById('depositModalAmount').textContent=money(da);var rows=paymentPlan.rows||[];if(!rows.length)rows=[{split_value:0,description:'Payment 1',required_quote_deposit:0},{split_value:0,description:'Payment 2',required_quote_deposit:0}],paymentPlan.rows=rows;document.getElementById('paymentScheduleRows').innerHTML=rows.map(function(r,i){return '<div class="jq-schedule-row"><input type="number" min="0" step="0.01" data-schedule-value="'+i+'" value="'+Number(r.split_value||0)+'"><input type="text" maxlength="190" data-schedule-desc="'+i+'" value="'+esc(r.description||('Payment '+(i+1)))+'"><input type="text" value="'+esc(money(paymentAmount(r,total)))+'" readonly>'+(i>0?'<button type="button" data-remove-schedule="'+i+'"><i class="bi bi-trash"></i></button>':'<span></span>')+(i===0?'<label class="jq-required-deposit"><input type="checkbox" data-required-deposit="0" '+(r.required_quote_deposit?'checked':'')+'> Required quote deposit</label>':'')+'</div>'}).join('');var alloc=0;rows.forEach(function(r){alloc+=paymentPlan.split_type==='percent'?Number(r.split_value||0):paymentAmount(r,total)});var rem=paymentPlan.split_type==='percent'?Math.max(0,100-alloc):Math.max(0,total-alloc);document.getElementById('scheduleJobTotal').textContent=money(total);document.getElementById('scheduleRemaining').textContent=paymentPlan.split_type==='percent'?rem.toFixed(2).replace(/\.00$/,'')+'%':money(rem)}
    function noteMentionHandle(m){var raw=[m.first_name,m.last_name].filter(Boolean).join('_')||('user'+Number(m.id||0));return raw.replace(/[^A-Za-z0-9_]/g,'_').replace(/_+/g,'_')}
    function noteMentionContext(){var ta=document.getElementById('internalNotes'),pos=ta.selectionStart==null?ta.value.length:ta.selectionStart,before=ta.value.slice(0,pos),m=before.match(/(^|[\s\n])@([A-Za-z0-9_.-]*)$/);if(!m)return null;return{start:pos-m[2].length-1,end:pos,query:String(m[2]||'').toLowerCase()}}
    function hideMentionMenu(){var m=document.getElementById('noteMentionMenu');m.classList.remove('show');m.innerHTML='';mentionMatches=[];mentionActiveIndex=0}
    function renderMentionMenu(){var ctx=noteMentionContext(),menu=document.getElementById('noteMentionMenu');if(!ctx){hideMentionMenu();return}var q=ctx.query;mentionMatches=(meta.team_members||meta.salespersons||[]).filter(function(m){return [m.first_name,m.last_name,m.email,m.job_title,noteMentionHandle(m)].join(' ').toLowerCase().indexOf(q)>=0}).slice(0,8);if(!mentionMatches.length){menu.innerHTML='<div class="jq-mention-option">No team members found</div>';menu.classList.add('show');return}mentionActiveIndex=Math.min(mentionActiveIndex,mentionMatches.length-1);menu.innerHTML=mentionMatches.map(function(m,i){var n=[m.first_name,m.last_name].filter(Boolean).join(' ')||m.email||'Team member';return '<button type="button" class="jq-mention-option '+(i===mentionActiveIndex?'active':'')+'" data-mention-user="'+Number(m.id)+'"><span class="jq-mention-avatar">'+esc(n.charAt(0).toUpperCase())+'</span><span class="jq-mention-copy"><strong>'+esc(n)+'</strong><small>'+esc(m.job_title||m.email||'Team member')+'</small></span></button>'}).join('');menu.classList.add('show')}
    function insertMention(id){var m=(meta.team_members||meta.salespersons||[]).find(function(x){return Number(x.id)===Number(id)}),ctx=noteMentionContext(),ta=document.getElementById('internalNotes');if(!m||!ctx)return;var token='@'+noteMentionHandle(m),before=ta.value.slice(0,ctx.start),after=ta.value.slice(ctx.end);ta.value=before+token+' '+after;var caret=before.length+token.length+1;ta.focus();ta.setSelectionRange(caret,caret);if(noteMentionIds.indexOf(Number(m.id))<0)noteMentionIds.push(Number(m.id));document.getElementById('noteMentionsJson').value=JSON.stringify(noteMentionIds);hideMentionMenu();dirty=true}
    function syncMentionIds(){var text=String(document.getElementById('internalNotes').value||'').toLowerCase();noteMentionIds=noteMentionIds.filter(function(id){var m=(meta.team_members||meta.salespersons||[]).find(function(x){return Number(x.id)===Number(id)});return m&&text.indexOf(('@'+noteMentionHandle(m)).toLowerCase())>=0});document.getElementById('noteMentionsJson').value=JSON.stringify(noteMentionIds)}
    function setMeta(m){meta=m||meta;meta.clients=Array.isArray(meta.clients)?meta.clients:[];meta.locations=Array.isArray(meta.locations)?meta.locations:[];meta.catalog=Array.isArray(meta.catalog)?meta.catalog:[];meta.products=Array.isArray(meta.products)?meta.products:[];meta.requests=Array.isArray(meta.requests)?meta.requests:[];meta.salespersons=Array.isArray(meta.salespersons)?meta.salespersons:[];meta.team_members=meta.team_members||meta.salespersons||[];meta.tax_rates=Array.isArray(meta.tax_rates)?meta.tax_rates:[];meta.default_tax_rate_id=Number(meta.default_tax_rate_id||0);initCustomerSelect(0);filterLocations(0,0);var rh='<option value="">Direct Quote - No Enquiry</option>';meta.requests.forEach(function(r){var key=String(r.source_key||('request:'+r.id));rh+='<option value="'+esc(key)+'">'+esc(r.request_no+' · '+r.client_name+' · '+(r.source_label||'Original Enquiry'))+'</option>'});$('#requestId').html(rh);var sp='<option value="">Unassigned</option>';meta.salespersons.forEach(function(x){var n=[x.first_name,x.last_name].filter(Boolean).join(' ')||x.email||('User #'+x.id);sp+='<option value="'+Number(x.id)+'">'+esc(n)+'</option>'});$('#salespersonId').html(sp);renderTaxMenu();selectedTaxRateId=meta.default_tax_rate_id;updateTaxSelectorText();document.getElementById('quoteNumberInput').value=meta.next_quote_no||'';document.getElementById('quoteNoCustom').value='0';$('#requestId,#salespersonId').select2({width:'100%'});if(quoteId>0)loadQuote();else{if(meta.current_user_id)$('#salespersonId').val(String(meta.current_user_id)).trigger('change.select2');if(preClientId>0)selectCustomerById(preClientId,0);if(preRequestId>0){var desired=preRevisitId>0?'revisit:'+preRequestId+':'+preRevisitId:'request:'+preRequestId;if(meta.requests.some(function(x){return String(x.source_key)===desired})){$('#requestId').val(desired).trigger('change');document.getElementById('enquiryBox').classList.add('show');loadRequestQuoteSource(preRequestId,preRevisitId).catch(function(e){notify('error',e.message)})}else{notify('warning','This request is unavailable for a new quotation or already has an active quotation.')}}if(!document.getElementById('validUntil').value){var d=new Date();d.setDate(d.getDate()+30);document.getElementById('validUntil').value=d.toISOString().slice(0,10)}if(!document.getElementById('disclaimer').value&&meta.quote_settings&&meta.quote_settings.default_disclaimer)document.getElementById('disclaimer').value=meta.quote_settings.default_disclaimer;renderItems();renderFiles();renderCustomerCard();syncClientView();syncSectionButtons();dirty=false}}
    function loadQuote(){var fd=new FormData();fd.append('action','get');fd.append('quote_id',quoteId);req(fd).then(function(d){var q=d.quotation||{};cart=(d.items||[]).map(normalizeItem);existingFiles=d.files||[];textSections=(d.sections||[]).map(function(x){return{section_key:x.section_key||'text',title:x.title||'',body:x.body||''}});try{customFields=JSON.parse(q.custom_fields_json||'[]')||[]}catch(e){customFields=[]}try{clientViewOptions=Object.assign(clientViewOptions,JSON.parse(q.client_view_options_json||'{}')||{})}catch(e){}try{noteMentionIds=(JSON.parse(q.note_mentions_json||'[]')||[]).map(Number)}catch(e){noteMentionIds=[]}paymentPlan.mode=q.payment_plan_mode||((Number(q.deposit_required)===1)?'deposit':'none');paymentPlan.split_type=q.payment_plan_split_type||'percent';paymentPlan.deposit_type=q.deposit_type||'percent';paymentPlan.deposit_value=Number(q.deposit_value||0);paymentPlan.rows=(d.payment_schedule||[]).map(function(r){return{split_value:Number(r.split_value||0),description:r.description||'',required_quote_deposit:Number(r.required_quote_deposit||0)?1:0}});document.getElementById('quoteNumberInput').value=q.quote_no||meta.next_quote_no||'';document.getElementById('quoteNoCustom').value='1';document.getElementById('quoteTitle').value=q.title||'';selectCustomerById(Number(q.client_id||0),Number(q.location_id||0));$('#salespersonId').val(String(q.salesperson_id||'')).trigger('change.select2');document.getElementById('quoteStatus').value=q.status||'draft';document.getElementById('validUntil').value=q.valid_until||'';document.getElementById('introductionTitle').value=q.introduction_title||'';document.getElementById('introduction').value=q.introduction||'';document.getElementById('clientMessage').value=q.client_message||'';document.getElementById('disclaimer').value=q.disclaimer||'';document.getElementById('internalNotes').value=q.internal_notes||'';document.getElementById('linkNotesJobs').checked=Number(q.link_notes_to_jobs==null?1:q.link_notes_to_jobs)===1;document.getElementById('linkNotesInvoices').checked=Number(q.link_notes_to_invoices==null?1:q.link_notes_to_invoices)===1;document.getElementById('noteMentionsJson').value=JSON.stringify(noteMentionIds);var key=q.source_key||'';$('#requestId').val(key).trigger('change.select2');if(key)document.getElementById('enquiryBox').classList.add('show');showRequest(key);renderItems();syncTaxSelectorFromCart();renderTextSections();renderCustomFields();renderFiles();syncClientView();renderPaymentSummary();showStoredSections(q);syncSectionButtons();dirty=false}).catch(function(e){notify('error',e.message)})}
    function showStoredSections(q){if((q.introduction_title||'')!==''||(q.introduction||'')!==''||existingFiles.some(function(x){return x.file_category==='introduction_image'}))document.getElementById('introductionSection').classList.add('show');if((q.client_message||'')!=='')document.getElementById('clientMessageSection').classList.add('show');if((q.disclaimer||'')!=='')document.getElementById('disclaimerSection').classList.add('show');if(existingFiles.some(function(x){return x.file_category==='attachment'}))document.getElementById('attachmentsSection').classList.add('show');if(existingFiles.some(function(x){return x.file_category==='image'}))document.getElementById('imagesSection').classList.add('show')}
    function renderFiles(){function list(cat,boxId,counterId){var stored=existingFiles.filter(function(x){return x.file_category===cat}),pending=pendingFiles[cat]||[],box=document.getElementById(boxId);if(!box)return;box.innerHTML=stored.map(function(x){var href=cat==='note_attachment'?('quote-note-file.php?file_id='+Number(x.id)):esc(x.file_path);return '<div class="jq-file-row"><a href="'+href+'" target="_blank"><i class="bi bi-paperclip"></i> '+esc(x.original_name)+'</a>'+(viewOnly?'':'<button type="button" data-delete-file="'+Number(x.id)+'"><i class="bi bi-trash"></i></button>')+'</div>'}).join('')+pending.map(function(x,i){return '<div class="jq-file-row pending"><span><i class="bi bi-clock"></i> '+esc(x.name)+'</span><button type="button" data-remove-pending="'+cat+'" data-pending-index="'+i+'"><i class="bi bi-x"></i></button></div>'}).join('');if(counterId&&document.getElementById(counterId))document.getElementById(counterId).textContent=(stored.length+pending.length)+' of 10 uploaded'}list('attachment','attachmentList','attachmentCounter');list('image','imageList','imageCounter');list('introduction_image','introImageList',null);list('note_attachment','noteFileList',null)}
    function queueFiles(input,cat){var files=Array.prototype.slice.call(input.files||[]),current=existingFiles.filter(function(x){return x.file_category===cat}).length+(pendingFiles[cat]||[]).length;if(!pendingFiles[cat])pendingFiles[cat]=[];files.forEach(function(f){if(current>=10){notify('warning','Maximum 10 files are allowed in this section.');return}var max=cat==='attachment'?50*1024*1024:25*1024*1024;if(f.size<=0||f.size>max){notify('warning',f.name+' is too large.');return}pendingFiles[cat].push(f);current++});input.value='';renderFiles();dirty=true}
    function uploadPending(savedQuoteId){var queue=[];Object.keys(pendingFiles).forEach(function(cat){pendingFiles[cat].forEach(function(file){queue.push({cat:cat,file:file})})});var chain=Promise.resolve();queue.forEach(function(item){chain=chain.then(function(){var fd=new FormData();fd.append('action','upload_file');fd.append('quote_id',savedQuoteId);fd.append('file_category',item.cat);fd.append('file',item.file);return req(fd)})});return chain.then(function(){return uploadPendingLineImages(savedQuoteId)})}
    function uploadPendingLineImages(savedQuoteId){var keys=Object.keys(pendingLineImages),chain=Promise.resolve();keys.forEach(function(k){var file=pendingLineImages[k];if(!file)return;chain=chain.then(function(){var fd=new FormData();fd.append('action','upload_line_image');fd.append('quote_id',savedQuoteId);fd.append('line_index',k);fd.append('file',file);return req(fd)})});return chain}
    function applyOverallDiscount(){var raw=Math.max(0,Number(document.getElementById('globalDiscount').value||0)),type=document.getElementById('globalDiscountType').value,base=0;cart.forEach(function(x){base+=Math.max(0,Number(x.quantity||0)*Number(x.unit_price||0))});var amount=type==='percentage'?base*Math.min(100,raw)/100:Math.min(base,raw);cart.forEach(function(x){var line=Math.max(0,Number(x.quantity||0)*Number(x.unit_price||0));x.discount_amount=base>0?Math.min(line,amount*line/base):0});renderItems();dirty=true}
    function applyOverallTax(){refreshTotals();dirty=true}
    function saveWithStatus(status){document.getElementById('quoteStatus').value=status;form.requestSubmit()}
    $('#clientId').on('select2:select',function(e){var raw=e.params&&e.params.data?String(e.params.data.id||''):String(this.value||'');if(raw.indexOf('newclient:')===0){var term=raw.slice(10);initCustomerSelect(0);renderCustomerCard();openCustomerModal(term);return}var cid=Number(raw||0),sel=cid>0?defaultLocationId(cid):0;filterLocations(cid,sel);renderCustomerCard();dirty=true}).on('select2:clear',function(){filterLocations(0,0);renderCustomerCard();dirty=true});
    $('#locationId').on('select2:select',function(e){var raw=e.params&&e.params.data?String(e.params.data.id||''):String(this.value||'');if(raw.indexOf('newloc:')===0){var term=raw.slice(7),c=currentClient();filterLocations(c?c.id:0,0);renderCustomerCard();openLocationModal(term);return}renderCustomerCard();dirty=true}).on('select2:clear',function(){renderCustomerCard();dirty=true});
    $('#requestId').on('change',function(){showRequest(this.value);dirty=true});
    var enquiryToggle=document.getElementById('toggleEnquiry');if(enquiryToggle)enquiryToggle.onclick=function(){document.getElementById('enquiryBox').classList.toggle('show')};
    document.querySelectorAll('[data-show-section]').forEach(function(b){b.onclick=function(){var k=b.dataset.showSection,id=k==='introduction'?'introductionSection':k+'Section';document.getElementById(id).classList.add('show');syncSectionButtons();dirty=true}});
    document.querySelectorAll('[data-hide-section]').forEach(function(b){b.onclick=function(){var k=b.dataset.hideSection,id=k==='introduction'?'introductionSection':k+'Section';document.getElementById(id).classList.remove('show');if(k==='introduction'){document.getElementById('introductionTitle').value='';document.getElementById('introduction').value='';pendingFiles.introduction_image=[];var introStored=existingFiles.filter(function(x){return x.file_category==='introduction_image'});introStored.forEach(function(x){var fd=new FormData();fd.append('action','delete_file');fd.append('file_id',x.id);req(fd).then(function(){existingFiles=existingFiles.filter(function(f){return Number(f.id)!==Number(x.id)});renderFiles()}).catch(function(er){notify('error',er.message)})});renderFiles()}if(k==='clientMessage')document.getElementById('clientMessage').value='';if(k==='disclaimer')document.getElementById('disclaimer').value='';syncSectionButtons();dirty=true}});
    document.querySelectorAll('[data-pick]').forEach(function(b){b.onclick=function(){document.getElementById(b.dataset.pick).click()}});
    document.getElementById('attachmentInput').onchange=function(){queueFiles(this,'attachment')};document.getElementById('imageInput').onchange=function(){queueFiles(this,'image')};document.getElementById('introImageInput').onchange=function(){queueFiles(this,'introduction_image')};var noteInput=document.getElementById('noteAttachmentInput');if(noteInput)noteInput.onchange=function(){queueFiles(this,'note_attachment')};
    if(!viewOnly){document.getElementById('addLineButton').onclick=addBlankLine;document.getElementById('newItemUnitCost').oninput=recalcNewItemPrice;document.getElementById('newItemMarkup').oninput=recalcNewItemPrice;document.getElementById('newItemUnitPrice').oninput=function(){catalogPriceTouched=true};document.getElementById('createCatalogItemButton').onclick=createCatalogItem;document.getElementById('createCustomerButton').onclick=createCustomer;document.getElementById('createLocationButton').onclick=createLocation;document.getElementById('clientMenuButton').onclick=function(e){e.stopPropagation();document.getElementById('clientMenu').classList.toggle('show')};document.getElementById('changeClientButton').onclick=function(){document.getElementById('clientMenu').classList.remove('show');document.getElementById('selectedClientCard').classList.remove('show');document.getElementById('clientPickerWrap').style.display='block';setTimeout(function(){try{$('#clientId').select2('open')}catch(e){}},0)};document.getElementById('changeLocationButton').onclick=function(){document.getElementById('clientMenu').classList.remove('show');document.getElementById('locationPickerWrap').classList.add('show');setTimeout(function(){try{$('#locationId').select2('open')}catch(e){}},0)};document.getElementById('newItemImagePick').onclick=function(){document.getElementById('newItemImage').click()};document.getElementById('newItemImage').onchange=function(){if(this.files&&this.files[0])setCatalogImage(this.files[0]);else resetCatalogImage()};document.getElementById('newItemImageRemove').onclick=resetCatalogImage;['dragenter','dragover'].forEach(function(evt){document.getElementById('newItemImagePick').addEventListener(evt,function(e){e.preventDefault();e.stopPropagation();this.classList.add('dragover')})});['dragleave','drop'].forEach(function(evt){document.getElementById('newItemImagePick').addEventListener(evt,function(e){e.preventDefault();e.stopPropagation();this.classList.remove('dragover')})});document.getElementById('newItemImagePick').addEventListener('drop',function(e){var f=e.dataTransfer&&e.dataTransfer.files?e.dataTransfer.files[0]:null;if(f)setCatalogImage(f)});document.getElementById('quoteNumberInput').addEventListener('input',function(){document.getElementById('quoteNoCustom').value='1';dirty=true})}
    if(document.getElementById('addText'))document.getElementById('addText').onclick=function(){textSections.push({section_key:'text',title:'',body:''});renderTextSections();dirty=true};
    document.addEventListener('click',function(e){var menu=document.getElementById('clientMenu');if(menu&&!e.target.closest('.ni-customer-menu-wrap'))menu.classList.remove('show')});
    document.getElementById('addCustomField').onclick=function(){customFields.push({label:'',value:''});renderCustomFields();dirty=true};
    document.getElementById('toggleDiscount').onclick=function(){var ed=document.getElementById('discountEditor');ed.hidden=false;ed.classList.add('show');this.style.display='none';setTimeout(function(){document.getElementById('globalDiscount').focus()},0)};document.getElementById('globalDiscount').oninput=function(){applyOverallDiscount()};document.getElementById('globalDiscountType').onchange=function(){applyOverallDiscount()};document.getElementById('removeDiscount').onclick=function(){document.getElementById('globalDiscount').value='0';cart.forEach(function(x){x.discount_amount=0});var ed=document.getElementById('discountEditor');ed.classList.remove('show');ed.hidden=true;document.getElementById('toggleDiscount').style.display='';renderItems();dirty=true};document.getElementById('toggleTax').onclick=function(){var ed=document.getElementById('taxEditor');ed.hidden=false;ed.classList.add('show');this.style.display='none';renderTaxMenu();setTaxMenuOpen(true);setTimeout(function(){document.getElementById('taxSelectorButton').focus()},0)};document.getElementById('taxSelectorButton').onclick=function(){renderTaxMenu();setTaxMenuOpen(!document.getElementById('taxMenu').classList.contains('show'))};document.getElementById('taxMenu').onclick=function(e){var rateBtn=e.target.closest('[data-tax-rate-id]');if(rateBtn){setSelectedTaxRate(rateBtn.getAttribute('data-tax-rate-id'),true);dirty=true;return}if(e.target.closest('#createTaxRateLink')){setTaxMenuOpen(false);openTaxRateModal()}};document.getElementById('removeTax').onclick=function(){selectedTaxRateId=0;document.getElementById('globalTax').value='0';updateTaxSelectorText();setTaxMenuOpen(false);var ed=document.getElementById('taxEditor');ed.classList.remove('show');ed.hidden=true;document.getElementById('toggleTax').style.display='';renderItems();dirty=true};document.getElementById('createTaxRateButton').onclick=createTaxRate;document.addEventListener('click',function(e){if(!e.target.closest('#taxControl')&&!e.target.closest('#toggleTax'))setTaxMenuOpen(false)});
    document.getElementById('toggleDeposit').onclick=function(){if(paymentPlan.mode==='none')paymentPlan.mode='deposit';renderPaymentModal();paymentModal.show()};
    document.getElementById('lineItemCards').addEventListener('input',function(e){var card=e.target.closest('[data-i]');if(!card)return;var i=Number(card.dataset.i),f=e.target.dataset.f;if(!f||!cart[i]||f==='is_optional')return;if(['quantity','unit_cost','markup_percent','unit_price','discount_amount','tax_percent'].indexOf(f)>=0)cart[i][f]=Number(e.target.value||0);else cart[i][f]=e.target.value;if(f==='unit_cost'||f==='markup_percent'){var cost=Number(cart[i].unit_cost||0),markup=Number(cart[i].markup_percent||0);cart[i].unit_price=Math.round(cost*(1+markup/100)*100)/100;var price=card.querySelector('[data-f="unit_price"]');if(price)price.value=Number(cart[i].unit_price).toFixed(2)}else if(f==='unit_price'){var cost=Number(cart[i].unit_cost||0);cart[i].markup_percent=cost>0?Math.max(0,(Number(cart[i].unit_price||0)-cost)/cost*100):0;var mk=card.querySelector('[data-f="markup_percent"]');if(mk)mk.value=Number(cart[i].markup_percent).toFixed(2)}dirty=true;var totalEl=card.querySelector('.jq-line-total strong');if(totalEl)totalEl.textContent=money(calc(cart[i]).total);refreshTotals()});
    document.getElementById('lineItemCards').addEventListener('change',function(e){var card=e.target.closest('[data-i]');if(!card)return;var i=Number(card.dataset.i),f=e.target.dataset.f;if(f==='is_optional'){cart[i].is_optional=e.target.checked?1:0;dirty=true;refreshTotals()}});
    document.getElementById('lineItemCards').addEventListener('change',function(e){if(e.target.matches('[data-line-image-input]')){var i=Number(e.target.dataset.lineImageInput),f=e.target.files&&e.target.files[0];if(f){if(f.size>25*1024*1024){notify('warning','Line-item image must be 25MB or smaller.');return}pendingLineImages[i]=f;var reader=new FileReader();reader.onload=function(){if(cart[i])cart[i].image_path=reader.result;renderItems()};reader.readAsDataURL(f);dirty=true}}});
    document.getElementById('lineItemCards').addEventListener('focusin',function(e){if(e.target.matches('[data-show-cost]')){var p=document.querySelector('[data-cost-popover="'+e.target.dataset.showCost+'"]');if(p)p.classList.add('show')}});
    document.getElementById('lineItemCards').addEventListener('dragstart',function(e){var card=e.target.closest('[data-i]');if(!card)return;dragIndex=Number(card.dataset.i);e.dataTransfer.effectAllowed='move'});document.getElementById('lineItemCards').addEventListener('dragover',function(e){if(e.target.closest('[data-i]'))e.preventDefault()});document.getElementById('lineItemCards').addEventListener('drop',function(e){var card=e.target.closest('[data-i]');if(!card||dragIndex<0)return;e.preventDefault();var to=Number(card.dataset.i);if(to===dragIndex)return;var item=cart.splice(dragIndex,1)[0];cart.splice(to,0,item);var files=[];for(var i=0;i<cart.length;i++)files[i]=pendingLineImages[i]||null;var moving=files.splice(dragIndex,1)[0];files.splice(to,0,moving);pendingLineImages={};files.forEach(function(f,i){if(f)pendingLineImages[i]=f});dragIndex=-1;renderItems();dirty=true});

    document.getElementById('lineItemCards').addEventListener('click',function(e){var show=e.target.closest('[data-show-cost]');if(show){document.querySelectorAll('.jq-cost-popover').forEach(function(p){p.classList.remove('show')});var p=document.querySelector('[data-cost-popover="'+show.dataset.showCost+'"]');if(p)p.classList.add('show');return}var img=e.target.closest('[data-line-image]');if(img){var input=document.querySelector('[data-line-image-input="'+img.dataset.lineImage+'"]');if(input)input.click();return}var ri=e.target.closest('[data-remove-line-image]');if(ri){var ix=Number(ri.dataset.removeLineImage);pendingLineImages[ix]=null;if(quoteId>0&&cart[ix]&&cart[ix].image_path){var fd=new FormData();fd.append('action','delete_line_image');fd.append('quote_id',quoteId);fd.append('line_index',ix);req(fd).catch(function(er){notify('error',er.message)})}if(cart[ix])cart[ix].image_path='';renderItems();dirty=true;return}var b=e.target.closest('[data-remove]');if(!b)return;var ix=Number(b.dataset.remove),next={};Object.keys(pendingLineImages).forEach(function(k){var n=Number(k);if(n<ix)next[n]=pendingLineImages[k];else if(n>ix)next[n-1]=pendingLineImages[k]});pendingLineImages=next;cart.splice(ix,1);renderItems();dirty=true});
    document.getElementById('textSections').addEventListener('input',function(e){var i=Number(e.target.dataset.si),f=e.target.dataset.sec;if(!f||!textSections[i])return;textSections[i][f]=e.target.value;syncSections();dirty=true});document.getElementById('textSections').addEventListener('click',function(e){var b=e.target.closest('[data-remove-text]');if(!b)return;textSections.splice(Number(b.dataset.removeText),1);renderTextSections();dirty=true});
    document.getElementById('customFields').addEventListener('input',function(e){var i=Number(e.target.dataset.ci),f=e.target.dataset.cf;if(!f||!customFields[i])return;customFields[i][f]=e.target.value;document.getElementById('customFieldsJson').value=JSON.stringify(customFields);dirty=true});document.getElementById('customFields').addEventListener('click',function(e){var b=e.target.closest('[data-remove-custom]');if(!b)return;customFields.splice(Number(b.dataset.removeCustom),1);renderCustomFields();dirty=true});
    document.addEventListener('click',function(e){var p=e.target.closest('[data-remove-pending]');if(p){pendingFiles[p.dataset.removePending].splice(Number(p.dataset.pendingIndex),1);renderFiles();dirty=true;return}var d=e.target.closest('[data-delete-file]');if(d){var fd=new FormData();fd.append('action','delete_file');fd.append('file_id',d.dataset.deleteFile);req(fd).then(function(){existingFiles=existingFiles.filter(function(x){return Number(x.id)!==Number(d.dataset.deleteFile)});renderFiles();notify('success','File removed.')}).catch(function(er){notify('error',er.message)});return}});
    var cv=document.getElementById('customerViewChange');if(cv)cv.onclick=function(){toggleClientView(true)};document.getElementById('clientViewCancel').onclick=function(){toggleClientView(false)};document.querySelectorAll('[data-client-view]').forEach(function(x){x.onchange=function(){clientViewOptions[this.dataset.clientView]=this.checked;syncClientView();dirty=true}});
    document.querySelectorAll('input[name="payment_mode_ui"]').forEach(function(r){r.onchange=function(){paymentPlan.mode=this.value;renderPaymentModal()}});document.querySelectorAll('[data-deposit-type]').forEach(function(b){b.onclick=function(){paymentPlan.deposit_type=this.dataset.depositType;renderPaymentModal()}});document.querySelectorAll('[data-split-type]').forEach(function(b){b.onclick=function(){paymentPlan.split_type=this.dataset.splitType;renderPaymentModal()}});document.getElementById('depositModalValue').oninput=function(){paymentPlan.deposit_value=Number(this.value||0);renderPaymentModal()};document.getElementById('paymentScheduleRows').addEventListener('input',function(e){var i=Number(e.target.dataset.scheduleValue!=null?e.target.dataset.scheduleValue:e.target.dataset.scheduleDesc);if(e.target.dataset.scheduleValue!=null)paymentPlan.rows[i].split_value=Number(e.target.value||0);if(e.target.dataset.scheduleDesc!=null)paymentPlan.rows[i].description=e.target.value;renderPaymentModal()});document.getElementById('paymentScheduleRows').addEventListener('change',function(e){if(e.target.dataset.requiredDeposit!=null){paymentPlan.rows.forEach(function(r){r.required_quote_deposit=0});paymentPlan.rows[Number(e.target.dataset.requiredDeposit)].required_quote_deposit=e.target.checked?1:0;renderPaymentModal()}});document.getElementById('paymentScheduleRows').addEventListener('click',function(e){var b=e.target.closest('[data-remove-schedule]');if(!b)return;paymentPlan.rows.splice(Number(b.dataset.removeSchedule),1);renderPaymentModal()});document.getElementById('addScheduleRow').onclick=function(){paymentPlan.rows.push({split_value:0,description:'Payment '+(paymentPlan.rows.length+1),required_quote_deposit:0});renderPaymentModal()};document.getElementById('deletePaymentPlan').onclick=function(){paymentPlan={mode:'none',split_type:'percent',deposit_type:'percent',deposit_value:0,rows:[]};renderPaymentSummary();paymentModal.hide();dirty=true};document.getElementById('savePaymentPlan').onclick=function(){if(paymentPlan.mode==='schedule'){var total=quoteTotal(),alloc=0;(paymentPlan.rows||[]).forEach(function(r){alloc+=paymentPlan.split_type==='percent'?Number(r.split_value||0):paymentAmount(r,total)});var rem=paymentPlan.split_type==='percent'?100-alloc:total-alloc;if(rem<-0.01){notify('warning','Payment schedule exceeds the quote total.');return}}renderPaymentSummary();paymentModal.hide();dirty=true};
    var notes=document.getElementById('internalNotes');notes.addEventListener('input',function(){syncMentionIds();renderMentionMenu()});notes.addEventListener('click',renderMentionMenu);notes.addEventListener('keydown',function(e){var menu=document.getElementById('noteMentionMenu');if(!menu.classList.contains('show'))return;if(e.key==='ArrowDown'&&mentionMatches.length){e.preventDefault();mentionActiveIndex=(mentionActiveIndex+1)%mentionMatches.length;renderMentionMenu()}else if(e.key==='ArrowUp'&&mentionMatches.length){e.preventDefault();mentionActiveIndex=(mentionActiveIndex-1+mentionMatches.length)%mentionMatches.length;renderMentionMenu()}else if(e.key==='Enter'&&mentionMatches.length){e.preventDefault();insertMention(mentionMatches[mentionActiveIndex].id)}else if(e.key==='Escape')hideMentionMenu()});document.getElementById('noteMentionMenu').addEventListener('mousedown',function(e){var b=e.target.closest('[data-mention-user]');if(!b)return;e.preventDefault();insertMention(b.dataset.mentionUser)});document.addEventListener('click',function(e){if(!e.target.closest('.jq-note-editor')&&!e.target.closest('.jq-cost-popover')&&!e.target.matches('[data-show-cost]')){hideMentionMenu();document.querySelectorAll('.jq-cost-popover').forEach(function(p){p.classList.remove('show')})}});var noteAttach=document.getElementById('noteAttachButton'),noteDrop=document.getElementById('noteUploadDrop');if(noteAttach)noteAttach.onclick=function(){document.getElementById('noteAttachmentInput').click()};if(noteDrop){['dragenter','dragover'].forEach(function(ev){noteDrop.addEventListener(ev,function(e){e.preventDefault();this.classList.add('dragover')})});['dragleave','drop'].forEach(function(ev){noteDrop.addEventListener(ev,function(e){e.preventDefault();this.classList.remove('dragover')})});noteDrop.addEventListener('drop',function(e){var input=document.getElementById('noteAttachmentInput');if(e.dataTransfer&&e.dataTransfer.files){try{var dt=new DataTransfer();Array.prototype.forEach.call(e.dataTransfer.files,function(f){dt.items.add(f)});input.files=dt.files;queueFiles(input,'note_attachment')}catch(er){Array.prototype.forEach.call(e.dataTransfer.files,function(f){pendingFiles.note_attachment.push(f)});renderFiles()}}})}
    form.addEventListener('input',function(){dirty=true});form.addEventListener('change',function(){dirty=true});
    form.addEventListener('submit',function(e){e.preventDefault();if(viewOnly)return;if(!form.reportValidity()){notify('warning','Complete the required quote fields.');return}if(!document.getElementById('clientId').value){notify('warning','Select a customer.');return}if(!cart.length){notify('warning','Add at least one line item.');return}syncSections();renderCustomFields();syncClientView();syncMentionIds();renderPaymentSummary();refreshTotals();var fd=new FormData(form);fd.append('action','save');var btn=document.getElementById('saveButton');submitting=true;btn.disabled=true;btn.classList.add('loading');req(fd).then(function(d){quoteId=Number(d.quote_id||quoteId);return uploadPending(quoteId).then(function(){pendingFiles={attachment:[],image:[],introduction_image:[],note_attachment:[]};pendingLineImages={};dirty=false;var msg=d.message||'Quote saved successfully.';if(d.email_notice)msg+=' '+d.email_notice;notify(d.email_status==='sent'?'success':'warning',msg);setTimeout(function(){window.location.href='quotes.php'},1100)})}).catch(function(er){notify('error',er.message)}).finally(function(){submitting=false;btn.disabled=false;btn.classList.remove('loading')})});
    document.getElementById('saveDraftButton')&& (document.getElementById('saveDraftButton').onclick=function(){saveWithStatus('draft')});
    document.getElementById('saveButton')&& (document.getElementById('saveButton').onclick=function(){saveWithStatus('sent')});
    document.addEventListener('click',function(e){var a=e.target.closest('a.qa-nav-link');if(!a||!dirty||submitting||viewOnly)return;e.preventDefault();pendingUrl=a.href;unsavedModal.show()});document.getElementById('leavePageButton').onclick=function(){dirty=false;unsavedModal.hide();if(pendingUrl)window.location.href=pendingUrl};
    var fd=new FormData();fd.append('action','form_meta');fd.append('quote_id',quoteId);req(fd).then(function(d){setMeta(d.meta||{})}).catch(function(e){notify('error',e.message)});
  })();
  </script>
</body>
</html>
