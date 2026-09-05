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
    @media(max-width:991.98px){.jq-savebar,body.fieldplx-sidebar-collapsed .jq-savebar{left:0}.jq-line-top{grid-template-columns:1fr 1fr}.jq-line-top .jq-name{grid-column:1/-1}.jq-line-total{align-self:end}.jq-client-row{grid-template-columns:1fr}.jq-catalog-tools,.jq-product-tools{grid-template-columns:1fr 1fr}.jq-enquiry-summary{grid-template-columns:1fr}}
    @media(max-width:575.98px){.qa-jobber-page{padding-left:12px!important;padding-right:12px!important}.jq-top-card,.jq-section-card,.jq-summary-card{padding:14px}.jq-heading-row{align-items:flex-start}.jq-title-wrap h1{font-size:18px}.jq-back{font-size:0}.jq-back i{font-size:14px}.jq-meta-row{grid-template-columns:95px 1fr}.jq-line-top{grid-template-columns:1fr}.jq-line-top .jq-name{grid-column:auto}.jq-line-bottom{align-items:flex-start;flex-direction:column}.jq-line-advanced{width:100%;flex-wrap:wrap}.jq-summary-card{gap:16px}.jq-inline-editor,.jq-deposit-editor{grid-template-columns:1fr}.jq-add-section-bar{max-width:100%;overflow-x:auto}.jq-catalog-tools,.jq-product-tools{grid-template-columns:1fr}.jq-savebar-inner{padding:8px 12px}.jq-save-main{padding:7px 10px}}

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
                  <div class="jq-client-row">
                    <div class="jq-field">
                      <select name="client_id" id="clientId" required <?= $viewOnly ? 'disabled' : '' ?>>
                        <option value="">Select a customer</option>
                      </select>
                    </div>
                    <div class="jq-field">
                      <select name="location_id" id="locationId" <?= $viewOnly ? 'disabled' : '' ?>>
                        <option value="">No location</option>
                      </select>
                    </div>
                  </div>
                  <div class="jq-linked-enquiry">
                    <button type="button" class="jq-link-button" id="toggleEnquiry"><i class="bi bi-link-45deg"></i> Link enquiry / revisit</button>
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
                  <div class="jq-meta-row"><span>Quote #</span><strong id="quoteNumberPreview">Auto</strong></div>
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

            <section class="jq-section-card jq-products-card">
              <div class="jq-section-head"><h2>Product / Service</h2></div>
              <?php if (!$viewOnly): ?>
              <div class="jq-item-add-tools" id="itemAddTools">
                <div class="jq-catalog-tools">
                  <select id="catalogItem"><option value="">Search service / material / fee / discount</option></select>
                  <button type="button" class="jq-small-btn" id="addCatalog"><i class="bi bi-plus-lg"></i> Add Selected</button>
                  <button type="button" class="jq-small-btn" id="addManual"><i class="bi bi-plus-lg"></i> Manual Item</button>
                </div>
                <div class="jq-product-tools">
                  <select id="productSearch" style="width:100%"><option value="">Search product or type a new product name</option></select>
                  <input type="number" id="productQty" min="0.001" step="0.001" value="1" title="Quantity">
                  <button type="button" class="jq-small-btn green" id="addProduct">Add Product</button>
                  <button type="button" class="jq-small-btn" id="openProductModal">Create Product</button>
                </div>
              </div>
              <?php endif; ?>
              <div id="lineItemCards"></div>
              <div class="jq-line-actions">
                <?php if (!$viewOnly): ?><button type="button" class="jq-green-btn" id="addLineItem"><i class="bi bi-plus-lg"></i> Add Line Item</button><button type="button" class="jq-small-btn" id="addText"><i class="bi bi-plus-lg"></i> Add Text</button><?php endif; ?>
              </div>
            </section>

            <section id="textSections"></section>

            <section class="jq-summary-card">
              <div class="jq-client-view">
                <span><i class="bi bi-eye"></i> Customer view</span>
                <a href="#" id="customerViewChange">Change</a>
              </div>
              <div class="jq-totals">
                <div><span>Subtotal</span><strong id="subTotal">0.00</strong></div>
                <div class="jq-total-action"><span>Discount</span><button type="button" id="toggleDiscount">Add Discount</button></div>
                <div id="discountEditor" class="jq-inline-editor"><label>Overall discount</label><input type="number" id="globalDiscount" min="0" step="0.01" value="0"></div>
                <div><span>Discount total</span><strong id="discountTotal">0.00</strong></div>
                <div class="jq-total-action"><span>Tax</span><button type="button" id="toggleTax">Add Tax</button></div>
                <div id="taxEditor" class="jq-inline-editor"><label>Tax % for all items</label><input type="number" id="globalTax" min="0" step="0.01" value="0"></div>
                <div><span>Tax total</span><strong id="taxTotal">0.00</strong></div>
                <div class="grand"><span>Total</span><strong id="grandTotal">0.00</strong></div>
                <button type="button" class="jq-deposit-link" id="toggleDeposit">Add Deposit or Payment Schedule</button>
                <div id="depositEditor" class="jq-deposit-editor">
                  <input type="hidden" name="deposit_required" id="depositRequired" value="0">
                  <select name="deposit_type" id="depositType"><option value="fixed">Fixed amount</option><option value="percent">Percentage</option></select>
                  <input type="number" name="deposit_value" id="depositValue" min="0" step="0.01" value="0">
                  <span id="depositPreview">Deposit: 0.00</span>
                </div>
              </div>
            </section>

            <div class="jq-add-section-bar jq-bottom-section-bar">
              <span><i class="bi bi-plus-lg"></i> Add section</span>
              <button type="button" data-show-section="attachments">Attachments</button>
              <button type="button" data-show-section="images">Images</button>
              <button type="button" data-show-section="clientMessage">Customer Message</button>
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
              <div class="jq-section-head"><h2>Customer Message</h2><button type="button" class="jq-icon-btn" data-hide-section="clientMessage"><i class="bi bi-trash"></i></button></div>
              <textarea class="jq-textarea" name="client_message" id="clientMessage" placeholder="Description" <?= $viewOnly ? 'disabled' : '' ?>></textarea>
            </section>

            <section class="jq-section-card jq-optional-section" id="disclaimerSection">
              <div class="jq-section-head"><h2>Contract / Disclaimer</h2><button type="button" class="jq-icon-btn" data-hide-section="disclaimer"><i class="bi bi-trash"></i></button></div>
              <textarea class="jq-textarea" name="disclaimer" id="disclaimer" placeholder="Add your quote terms, contract language, or disclaimer" <?= $viewOnly ? 'disabled' : '' ?>></textarea>
            </section>

            <section class="jq-notes-section">
              <h2>Notes</h2>
              <textarea class="jq-textarea" name="internal_notes" id="internalNotes" placeholder="Internal notes are only visible to your team" <?= $viewOnly ? 'disabled' : '' ?>></textarea>
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

  <div class="modal fade qa-modal" id="productModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Create New Product</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body qa-product-modal"><div class="qa-product-grid">
        <div><label>Product Name *</label><input type="text" id="newProductName" maxlength="190"></div>
        <div><label>Unit / Base Price *</label><input type="number" id="newProductBasePrice" min="0" step="0.01" value="0.00"></div>
        <div><label>Markup Type *</label><select id="newProductMarkupType"><option value="percentage">Percentage %</option><option value="fixed">Fixed Amount</option></select></div>
        <div><label>Markup Value</label><input type="number" id="newProductMarkupValue" min="0" step="0.01" value="0.00"></div>
        <div><label>Tax %</label><input type="number" id="newProductTax" min="0" step="0.01" value="0"></div>
        <div class="full"><label>Description</label><textarea id="newProductDescription"></textarea></div>
      </div></div>
      <div class="modal-footer"><button type="button" class="qa-btn" data-bs-dismiss="modal">Cancel</button><button type="button" class="qa-btn primary" id="createProductButton"><span class="qa-loader"></span><i class="bi bi-check-lg"></i> Create Product</button></div>
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
    var meta={currency:{symbol:'',symbol_position:'before',decimal_places:2},requests:[],catalog:[],products:[],clients:[],locations:[],salespersons:[]};
    var cart=[], textSections=[], customFields=[], existingFiles=[], pendingFiles={attachment:[],image:[],introduction_image:[]};
    var dirty=false, submitting=false, timer=null, pendingUrl='';
    var form=document.getElementById('quoteForm'), toast=document.getElementById('toast'), toastMsg=document.getElementById('toastMsg');
    var productModal=new bootstrap.Modal(document.getElementById('productModal')), unsavedModal=new bootstrap.Modal(document.getElementById('unsavedModal'));
    function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
    function label(v){return String(v||'').replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()})}
    function notify(t,m){if(timer)clearTimeout(timer);toast.className='qa-toast '+(t||'info')+' show';toastMsg.textContent=m||'Notification';timer=setTimeout(function(){toast.classList.remove('show')},3600)}
    function parse(r){return r.text().then(function(raw){var d,t=(raw||'').trim();try{d=t?JSON.parse(t):{}}catch(e){throw new Error(t.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!r.ok||!d.success)throw new Error(d.message||'Request failed.');return d})}
    function req(fd){fd.append('csrf_token',csrfToken);return fetch('api/quotations.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parse)}
    function money(n){n=Number(n||0);var s=n.toFixed(Number(meta.currency.decimal_places||2)),sym=meta.currency.symbol||'';return meta.currency.symbol_position==='after'?s+(sym?' '+sym:''):(sym||'')+s}
    function calc(x){var base=Math.max(0,Number(x.quantity||0)*Number(x.unit_price||0)),disc=Math.min(base,Math.max(0,Number(x.discount_amount||0))),taxable=Math.max(0,base-disc),tax=taxable*Math.max(0,Number(x.tax_percent||0))/100;return{base:base,discount:disc,tax:tax,total:taxable+tax}}
    function setLocations(clientId,selected){var h='<option value="">No location</option>';meta.locations.filter(function(x){return Number(x.client_id)===Number(clientId)}).forEach(function(x){h+='<option value="'+Number(x.id)+'">'+esc(x.name)+' · '+esc(x.address_line1||'')+'</option>'});$('#locationId').html(h).val(selected?String(selected):'').trigger('change.select2')}
    function showRequest(key){var r=meta.requests.find(function(x){return String(x.source_key||('request:'+x.id))===String(key)});if(!r){document.getElementById('realRequestId').value='';document.getElementById('assessmentRescheduleId').value='';document.getElementById('enquiryInfo').classList.remove('show');return}document.getElementById('realRequestId').value=String(r.request_id||r.id||'');document.getElementById('assessmentRescheduleId').value=r.assessment_reschedule_id?String(r.assessment_reschedule_id):'';$('#clientId').val(String(r.client_id||'')).trigger('change.select2');setLocations(r.client_id,r.location_id);document.getElementById('infoSource').textContent=r.request_no+' · '+(r.source_label||'Original Enquiry');document.getElementById('infoService').textContent=r.service_name||r.title||'—';var visit=r.visit_date||'—';if(r.visit_time_from)visit+=' · '+String(r.visit_time_from).slice(0,5);document.getElementById('infoVisit').textContent=visit;document.getElementById('enquiryInfo').classList.add('show');if(!document.getElementById('quoteTitle').value)document.getElementById('quoteTitle').value=r.title||'Quote'}
    function refreshTotals(){var s=0,d=0,t=0,g=0;cart.forEach(function(x){var c=calc(x);s+=c.base;d+=c.discount;t+=c.tax;g+=c.total});document.getElementById('subTotal').textContent=money(s);document.getElementById('discountTotal').textContent=money(d);document.getElementById('taxTotal').textContent=money(t);document.getElementById('grandTotal').textContent=money(g);document.getElementById('itemsJson').value=JSON.stringify(cart);var dr=Number(document.getElementById('depositRequired').value||0),dv=Number(document.getElementById('depositValue').value||0),dt=document.getElementById('depositType').value,da=dr?(dt==='percent'?Math.min(g,g*Math.min(100,dv)/100):Math.min(g,dv)):0;document.getElementById('depositPreview').textContent='Deposit: '+money(da)}
    function renderItems(){var box=document.getElementById('lineItemCards');if(!cart.length){box.innerHTML='<div class="jq-empty">No line items yet. Click Add Line Item.</div>';refreshTotals();return}box.innerHTML=cart.map(function(x,i){var c=calc(x);return '<article class="jq-line-card" data-i="'+i+'"><div class="jq-line-top"><input class="jq-name" data-f="item_name" value="'+esc(x.item_name)+'" placeholder="Name" '+(viewOnly?'disabled':'')+'><label class="jq-number-field"><span>Quantity</span><input type="number" min="0.001" step="0.001" data-f="quantity" value="'+Number(x.quantity||1)+'" '+(viewOnly?'disabled':'')+'></label><label class="jq-number-field"><span>Unit price</span><input type="number" min="0" step="0.01" data-f="unit_price" value="'+Number(x.unit_price||0).toFixed(2)+'" '+(viewOnly?'disabled':'')+'></label><div class="jq-line-total"><span>Total</span><strong>'+money(c.total)+'</strong></div>'+(viewOnly?'':'<button type="button" class="jq-icon-btn" data-remove="'+i+'"><i class="bi bi-trash"></i></button>')+'</div><textarea class="jq-line-desc" data-f="description" placeholder="Description" '+(viewOnly?'disabled':'')+'>'+esc(x.description||'')+'</textarea><div class="jq-line-bottom"><label><input type="checkbox" data-f="is_optional" '+(Number(x.is_optional)===1?'checked':'')+' '+(viewOnly?'disabled':'')+'> Mark as optional</label><div class="jq-line-advanced"><label>Discount <input type="number" min="0" step="0.01" data-f="discount_amount" value="'+Number(x.discount_amount||0).toFixed(2)+'" '+(viewOnly?'disabled':'')+'></label><label>Tax % <input type="number" min="0" step="0.01" data-f="tax_percent" value="'+Number(x.tax_percent||0)+'" '+(viewOnly?'disabled':'')+'></label></div></div></article>'}).join('');refreshTotals()}
    function renderTextSections(){var box=document.getElementById('textSections');box.innerHTML=textSections.map(function(x,i){return '<section class="jq-section-card"><div class="jq-section-head"><h2>Text</h2>'+(viewOnly?'':'<button type="button" class="jq-icon-btn" data-remove-text="'+i+'"><i class="bi bi-trash"></i></button>')+'</div><input class="jq-input" data-sec="title" data-si="'+i+'" value="'+esc(x.title||'')+'" placeholder="Title" '+(viewOnly?'disabled':'')+'><textarea class="jq-textarea" data-sec="body" data-si="'+i+'" placeholder="Description" '+(viewOnly?'disabled':'')+'>'+esc(x.body||'')+'</textarea></section>'}).join('');syncSections()}
    function syncSections(){document.getElementById('sectionsJson').value=JSON.stringify(textSections)}
    function renderCustomFields(){var box=document.getElementById('customFields');box.innerHTML=customFields.map(function(x,i){return '<div class="jq-custom-field"><input data-cf="label" data-ci="'+i+'" value="'+esc(x.label||'')+'" placeholder="Field name" '+(viewOnly?'disabled':'')+'><input data-cf="value" data-ci="'+i+'" value="'+esc(x.value||'')+'" placeholder="Value" '+(viewOnly?'disabled':'')+'>'+(viewOnly?'':'<button type="button" data-remove-custom="'+i+'"><i class="bi bi-x"></i></button>')+'</div>'}).join('');document.getElementById('customFieldsJson').value=JSON.stringify(customFields)}
    function addCatalog(){var id=Number($('#catalogItem').val()||0);if(!id){notify('warning','Select a catalog item first.');return}var x=meta.catalog.find(function(a){return Number(a.id)===id});if(!x)return;cart.push({product_service_id:id,product_id:null,item_type:x.item_type,item_name:x.name,description:x.description||'',quantity:1,unit_cost:Number(x.unit_cost||0),unit_price:Number(x.unit_price||0),discount_amount:0,tax_percent:Number(x.tax_percent||0),is_optional:0});dirty=true;renderItems();$('#catalogItem').val('').trigger('change')}
    function addManual(){cart.push({product_service_id:null,product_id:null,item_type:'manual',item_name:'',description:'',quantity:1,unit_cost:0,unit_price:0,discount_amount:0,tax_percent:0,is_optional:0});dirty=true;renderItems()}
    function findProduct(name){var n=String(name||'').trim().toLowerCase();return meta.products.find(function(x){return String(x.name||'').trim().toLowerCase()===n})}
    function findProductById(id){return meta.products.find(function(x){return Number(x.id)===Number(id)})}
    function productToCart(x,qty){if(!x)return;cart.push({product_service_id:null,product_id:Number(x.id),item_type:'product',item_name:x.name,description:x.description||'',quantity:qty,unit_cost:Number(x.base_unit_price||0),unit_price:Number(x.selling_price||0),discount_amount:0,tax_percent:Number(x.tax_percent||0),is_optional:0});dirty=true;renderItems();$('#productSearch').val(null).trigger('change');document.getElementById('productQty').value='1'}
    function addProduct(){var raw=$('#productSearch').val(),qty=Math.max(.001,Number(document.getElementById('productQty').value||1));if(!raw){notify('warning','Select or enter a product name.');return}var existing=findProductById(raw);if(existing){productToCart(existing,qty);return}var typed=String(raw).replace(/^new:/,'').trim();if(!typed){notify('warning','Enter a product name.');return}var byName=findProduct(typed);if(byName){productToCart(byName,qty);return}document.getElementById('newProductName').value=typed;document.getElementById('newProductBasePrice').value='0.00';document.getElementById('newProductMarkupType').value='percentage';document.getElementById('newProductMarkupValue').value='0.00';document.getElementById('newProductTax').value='0';document.getElementById('newProductDescription').value='';productModal.show()}
    function createProduct(){var name=document.getElementById('newProductName').value.trim();if(!name){notify('warning','Product name is required.');return}var qty=Math.max(.001,Number(document.getElementById('productQty').value||1));var fd=new FormData();fd.append('action','create_product');fd.append('name',name);fd.append('base_unit_price',document.getElementById('newProductBasePrice').value||0);fd.append('markup_type',document.getElementById('newProductMarkupType').value);fd.append('markup_value',document.getElementById('newProductMarkupValue').value||0);fd.append('tax_percent',document.getElementById('newProductTax').value||0);fd.append('description',document.getElementById('newProductDescription').value||'');var btn=document.getElementById('createProductButton');btn.disabled=true;btn.classList.add('loading');req(fd).then(function(d){if(!d.product)throw new Error('Product was created but no product data was returned.');meta.products.push(d.product);refreshProductSelect();productModal.hide();productToCart(d.product,qty);notify('success','New product created and added to this quote.')}).catch(function(e){notify('error',e.message)}).finally(function(){btn.disabled=false;btn.classList.remove('loading')})}
    function refreshProductSelect(){var $p=$('#productSearch');if($p.hasClass('select2-hidden-accessible'))$p.select2('destroy');var options='<option value="">Search product or type a new product name</option>';meta.products.forEach(function(x){options+='<option value="'+Number(x.id)+'">'+esc(x.name)+'</option>'});$p.html(options);$p.select2({width:'100%',placeholder:'Search product or type a new product name',allowClear:true,tags:true,createTag:function(params){var term=$.trim(params.term||'');if(!term)return null;var exact=findProduct(term);if(exact)return null;return{id:'new:'+term,text:'Create "'+term+'" as a new product',newTag:true}},templateResult:function(data){if(data.newTag)return $('<span><i class="bi bi-plus-circle me-1"></i></span>').append(document.createTextNode(data.text));var x=findProductById(data.id);if(!x)return data.text;var price=money(x.selling_price||0);var sku=x.sku?' · '+x.sku:'';return $('<span></span>').text(x.name+sku+' · '+price)},templateSelection:function(data){if(String(data.id||'').indexOf('new:')===0)return String(data.id).substring(4);var x=findProductById(data.id);return x?x.name:(data.text||'')}})}
    function setMeta(m){meta=m||meta;var clients='<option value="">Select a customer</option>';meta.clients.forEach(function(x){clients+='<option value="'+Number(x.id)+'">'+esc(x.display_name)+(x.email?' · '+esc(x.email):'')+'</option>'});$('#clientId').html(clients);var rh='<option value="">Direct Quote - No Enquiry</option>';meta.requests.forEach(function(r){var key=String(r.source_key||('request:'+r.id));rh+='<option value="'+esc(key)+'">'+esc(r.request_no+' · '+r.client_name+' · '+(r.source_label||'Original Enquiry'))+'</option>'});$('#requestId').html(rh);var ch='<option value="">Search service / material / fee / discount</option>';meta.catalog.filter(function(x){return x.item_type!=='product'}).forEach(function(x){ch+='<option value="'+Number(x.id)+'">'+esc(x.name)+' · '+money(x.unit_price)+'</option>'});$('#catalogItem').html(ch);var sp='<option value="">Unassigned</option>';meta.salespersons.forEach(function(x){var n=[x.first_name,x.last_name].filter(Boolean).join(' ')||x.email||('User #'+x.id);sp+='<option value="'+Number(x.id)+'">'+esc(n)+'</option>'});$('#salespersonId').html(sp);document.getElementById('quoteNumberPreview').textContent=meta.next_quote_no||'Auto';refreshProductSelect();$('#clientId,#locationId,#requestId,#catalogItem,#salespersonId').select2({width:'100%'});if(quoteId>0)loadQuote();else{if(meta.current_user_id)$('#salespersonId').val(String(meta.current_user_id)).trigger('change.select2');if(preClientId>0){$('#clientId').val(String(preClientId)).trigger('change');setLocations(preClientId,'')}if(preRequestId>0){var desired=preRevisitId>0?'revisit:'+preRequestId+':'+preRevisitId:'request:'+preRequestId;if(meta.requests.some(function(x){return String(x.source_key)===desired})){$('#requestId').val(desired).trigger('change');document.getElementById('enquiryBox').classList.add('show')}}if(!document.getElementById('validUntil').value){var d=new Date();d.setDate(d.getDate()+30);document.getElementById('validUntil').value=d.toISOString().slice(0,10)}if(!cart.length)addManual();dirty=false}}
    function loadQuote(){var fd=new FormData();fd.append('action','get');fd.append('quote_id',quoteId);req(fd).then(function(d){var q=d.quotation||{};cart=d.items||[];existingFiles=d.files||[];textSections=(d.sections||[]).map(function(x){return{section_key:x.section_key||'text',title:x.title||'',body:x.body||''}});try{customFields=JSON.parse(q.custom_fields_json||'[]')||[]}catch(e){customFields=[]}document.getElementById('quoteNumberPreview').textContent=q.quote_no||meta.next_quote_no||'Auto';document.getElementById('quoteTitle').value=q.title||'';$('#clientId').val(String(q.client_id||'')).trigger('change.select2');setLocations(q.client_id,q.location_id);$('#salespersonId').val(String(q.salesperson_id||'')).trigger('change.select2');document.getElementById('quoteStatus').value=q.status||'draft';document.getElementById('validUntil').value=q.valid_until||'';document.getElementById('introductionTitle').value=q.introduction_title||'';document.getElementById('introduction').value=q.introduction||'';document.getElementById('clientMessage').value=q.client_message||'';document.getElementById('disclaimer').value=q.disclaimer||'';document.getElementById('internalNotes').value=q.internal_notes||'';if(Number(q.deposit_required)===1){document.getElementById('depositRequired').value='1';document.getElementById('depositEditor').classList.add('show');document.getElementById('depositType').value=q.deposit_type||'fixed';document.getElementById('depositValue').value=Number(q.deposit_value||0)}var key=q.source_key||'';$('#requestId').val(key).trigger('change.select2');if(key)document.getElementById('enquiryBox').classList.add('show');showRequest(key);renderItems();renderTextSections();renderCustomFields();renderFiles();showStoredSections(q);dirty=false}).catch(function(e){notify('error',e.message)})}
    function showStoredSections(q){if((q.introduction_title||'')!==''||(q.introduction||'')!==''||existingFiles.some(function(x){return x.file_category==='introduction_image'}))document.getElementById('introductionSection').classList.add('show');if((q.client_message||'')!=='')document.getElementById('clientMessageSection').classList.add('show');if((q.disclaimer||'')!=='')document.getElementById('disclaimerSection').classList.add('show');if(existingFiles.some(function(x){return x.file_category==='attachment'}))document.getElementById('attachmentsSection').classList.add('show');if(existingFiles.some(function(x){return x.file_category==='image'}))document.getElementById('imagesSection').classList.add('show')}
    function renderFiles(){function list(cat,boxId,counterId){var stored=existingFiles.filter(function(x){return x.file_category===cat}),pending=pendingFiles[cat]||[],box=document.getElementById(boxId);box.innerHTML=stored.map(function(x){return '<div class="jq-file-row"><a href="'+esc(x.file_path)+'" target="_blank"><i class="bi bi-paperclip"></i> '+esc(x.original_name)+'</a>'+(viewOnly?'':'<button type="button" data-delete-file="'+Number(x.id)+'"><i class="bi bi-trash"></i></button>')+'</div>'}).join('')+pending.map(function(x,i){return '<div class="jq-file-row pending"><span><i class="bi bi-clock"></i> '+esc(x.name)+'</span><button type="button" data-remove-pending="'+cat+'" data-pending-index="'+i+'"><i class="bi bi-x"></i></button></div>'}).join('');if(counterId)document.getElementById(counterId).textContent=(stored.length+pending.length)+' of 10 uploaded'}list('attachment','attachmentList','attachmentCounter');list('image','imageList','imageCounter');list('introduction_image','introImageList',null)}
    function queueFiles(input,cat){var files=Array.prototype.slice.call(input.files||[]),current=existingFiles.filter(function(x){return x.file_category===cat}).length+pendingFiles[cat].length;files.forEach(function(f){if(current>=10)return;var max=cat==='attachment'?50*1024*1024:25*1024*1024;if(f.size>max){notify('warning',f.name+' is too large.');return}pendingFiles[cat].push(f);current++});input.value='';renderFiles();dirty=true}
    function uploadPending(savedQuoteId){var queue=[];Object.keys(pendingFiles).forEach(function(cat){pendingFiles[cat].forEach(function(file){queue.push({cat:cat,file:file})})});var chain=Promise.resolve();queue.forEach(function(item){chain=chain.then(function(){var fd=new FormData();fd.append('action','upload_file');fd.append('quote_id',savedQuoteId);fd.append('file_category',item.cat);fd.append('file',item.file);return req(fd)})});return chain}
    function applyOverallDiscount(){var total=cart.reduce(function(a,x){return a+calc(x).base},0),discount=Math.max(0,Math.min(total,Number(document.getElementById('globalDiscount').value||0)));cart.forEach(function(x){var base=calc(x).base;x.discount_amount=total>0?discount*(base/total):0});renderItems();dirty=true}
    function applyOverallTax(){var pct=Math.max(0,Number(document.getElementById('globalTax').value||0));cart.forEach(function(x){x.tax_percent=pct});renderItems();dirty=true}
    function saveWithStatus(status){document.getElementById('quoteStatus').value=status;form.requestSubmit()}
    $('#clientId').on('change',function(){setLocations(this.value,'');var cv=document.getElementById('customerViewChange');cv.href=this.value?'client-view.php?client_id='+encodeURIComponent(this.value):'#';dirty=true});$('#requestId').on('change',function(){showRequest(this.value);dirty=true});
    document.getElementById('toggleEnquiry').onclick=function(){document.getElementById('enquiryBox').classList.toggle('show')};
    document.querySelectorAll('[data-show-section]').forEach(function(b){b.onclick=function(){var k=b.dataset.showSection,id=k==='introduction'?'introductionSection':k+'Section';document.getElementById(id).classList.add('show');dirty=true}});
    document.querySelectorAll('[data-hide-section]').forEach(function(b){b.onclick=function(){var k=b.dataset.hideSection,id=k==='introduction'?'introductionSection':k+'Section';document.getElementById(id).classList.remove('show');if(k==='introduction'){document.getElementById('introductionTitle').value='';document.getElementById('introduction').value='';pendingFiles.introduction_image=[];var introStored=existingFiles.filter(function(x){return x.file_category==='introduction_image'});introStored.forEach(function(x){var fd=new FormData();fd.append('action','delete_file');fd.append('file_id',x.id);req(fd).then(function(){existingFiles=existingFiles.filter(function(f){return Number(f.id)!==Number(x.id)});renderFiles()}).catch(function(er){notify('error',er.message)})});renderFiles()}if(k==='clientMessage')document.getElementById('clientMessage').value='';if(k==='disclaimer')document.getElementById('disclaimer').value='';dirty=true}});
    document.querySelectorAll('[data-pick]').forEach(function(b){b.onclick=function(){document.getElementById(b.dataset.pick).click()}});
    document.getElementById('attachmentInput').onchange=function(){queueFiles(this,'attachment')};document.getElementById('imageInput').onchange=function(){queueFiles(this,'image')};document.getElementById('introImageInput').onchange=function(){queueFiles(this,'introduction_image')};
    document.getElementById('addCatalog').onclick=addCatalog;document.getElementById('addManual').onclick=addManual;document.getElementById('addLineItem').onclick=function(){document.getElementById('itemAddTools').classList.toggle('show');if(!cart.length)addManual()};document.getElementById('addProduct').onclick=addProduct;document.getElementById('openProductModal').onclick=function(){var raw=$('#productSearch').val()||'',existing=findProductById(raw),name=existing?existing.name:String(raw).replace(/^new:/,'').trim();document.getElementById('newProductName').value=name;productModal.show()};document.getElementById('createProductButton').onclick=createProduct;
    document.getElementById('addText').onclick=function(){textSections.push({section_key:'text',title:'',body:''});renderTextSections();dirty=true};
    document.getElementById('addCustomField').onclick=function(){customFields.push({label:'',value:''});renderCustomFields();dirty=true};
    document.getElementById('toggleDiscount').onclick=function(){document.getElementById('discountEditor').classList.toggle('show')};document.getElementById('globalDiscount').oninput=applyOverallDiscount;document.getElementById('toggleTax').onclick=function(){document.getElementById('taxEditor').classList.toggle('show')};document.getElementById('globalTax').oninput=applyOverallTax;
    document.getElementById('toggleDeposit').onclick=function(){var ed=document.getElementById('depositEditor'),show=!ed.classList.contains('show');ed.classList.toggle('show',show);document.getElementById('depositRequired').value=show?'1':'0';refreshTotals();dirty=true};document.getElementById('depositType').onchange=refreshTotals;document.getElementById('depositValue').oninput=refreshTotals;
    document.getElementById('lineItemCards').addEventListener('input',function(e){var card=e.target.closest('[data-i]');if(!card)return;var i=Number(card.dataset.i),f=e.target.dataset.f;if(!f||!cart[i]||f==='is_optional')return;if(['quantity','unit_price','discount_amount','tax_percent'].indexOf(f)>=0)cart[i][f]=Number(e.target.value||0);else cart[i][f]=e.target.value;dirty=true;var totalEl=card.querySelector('.jq-line-total strong');if(totalEl)totalEl.textContent=money(calc(cart[i]).total);refreshTotals()});
    document.getElementById('lineItemCards').addEventListener('change',function(e){var card=e.target.closest('[data-i]');if(!card)return;var i=Number(card.dataset.i),f=e.target.dataset.f;if(f==='is_optional'){cart[i].is_optional=e.target.checked?1:0;dirty=true;refreshTotals()}});
    document.getElementById('lineItemCards').addEventListener('click',function(e){var b=e.target.closest('[data-remove]');if(!b)return;cart.splice(Number(b.dataset.remove),1);renderItems();dirty=true});
    document.getElementById('textSections').addEventListener('input',function(e){var i=Number(e.target.dataset.si),f=e.target.dataset.sec;if(!f||!textSections[i])return;textSections[i][f]=e.target.value;syncSections();dirty=true});document.getElementById('textSections').addEventListener('click',function(e){var b=e.target.closest('[data-remove-text]');if(!b)return;textSections.splice(Number(b.dataset.removeText),1);renderTextSections();dirty=true});
    document.getElementById('customFields').addEventListener('input',function(e){var i=Number(e.target.dataset.ci),f=e.target.dataset.cf;if(!f||!customFields[i])return;customFields[i][f]=e.target.value;document.getElementById('customFieldsJson').value=JSON.stringify(customFields);dirty=true});document.getElementById('customFields').addEventListener('click',function(e){var b=e.target.closest('[data-remove-custom]');if(!b)return;customFields.splice(Number(b.dataset.removeCustom),1);renderCustomFields();dirty=true});
    document.addEventListener('click',function(e){var p=e.target.closest('[data-remove-pending]');if(p){pendingFiles[p.dataset.removePending].splice(Number(p.dataset.pendingIndex),1);renderFiles();dirty=true;return}var d=e.target.closest('[data-delete-file]');if(d){var fd=new FormData();fd.append('action','delete_file');fd.append('file_id',d.dataset.deleteFile);req(fd).then(function(){existingFiles=existingFiles.filter(function(x){return Number(x.id)!==Number(d.dataset.deleteFile)});renderFiles();notify('success','File removed.')}).catch(function(er){notify('error',er.message)});return}});
    form.addEventListener('input',function(){dirty=true});form.addEventListener('change',function(){dirty=true});
    form.addEventListener('submit',function(e){e.preventDefault();if(viewOnly)return;if(!form.reportValidity()){notify('warning','Complete the required quote fields.');return}if(!document.getElementById('clientId').value){notify('warning','Select a customer.');return}if(!cart.length){notify('warning','Add at least one line item.');return}syncSections();renderCustomFields();refreshTotals();var fd=new FormData(form);fd.append('action','save');var btn=document.getElementById('saveButton');submitting=true;btn.disabled=true;btn.classList.add('loading');req(fd).then(function(d){quoteId=Number(d.quote_id||quoteId);return uploadPending(quoteId).then(function(){pendingFiles={attachment:[],image:[],introduction_image:[]};dirty=false;var msg=d.message||'Quote saved successfully.';if(d.email_notice)msg+=' '+d.email_notice;notify(d.email_status==='failed'?'warning':'success',msg);setTimeout(function(){window.location.href='quotes.php'},1100)})}).catch(function(er){notify('error',er.message)}).finally(function(){submitting=false;btn.disabled=false;btn.classList.remove('loading')})});
    document.getElementById('saveDraftButton')&& (document.getElementById('saveDraftButton').onclick=function(){saveWithStatus('draft')});
    document.getElementById('saveButton')&& (document.getElementById('saveButton').onclick=function(){saveWithStatus('sent')});
    document.addEventListener('click',function(e){var a=e.target.closest('a.qa-nav-link');if(!a||!dirty||submitting||viewOnly)return;e.preventDefault();pendingUrl=a.href;unsavedModal.show()});document.getElementById('leavePageButton').onclick=function(){dirty=false;unsavedModal.hide();if(pendingUrl)window.location.href=pendingUrl};
    var fd=new FormData();fd.append('action','form_meta');fd.append('quote_id',quoteId);req(fd).then(function(d){setMeta(d.meta||{})}).catch(function(e){notify('error',e.message)});
  })();
  </script>
</body>
</html>
