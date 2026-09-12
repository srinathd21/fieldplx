<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Schedule';
$activePage = 'schedule';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['schedule_csrf_token'])) $_SESSION['schedule_csrf_token'] = bin2hex(random_bytes(32));
$scheduleCsrf = $_SESSION['schedule_csrf_token'];
if (empty($_SESSION['clients_csrf_token'])) $_SESSION['clients_csrf_token'] = bin2hex(random_bytes(32));
$clientsCsrf = $_SESSION['clients_csrf_token'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Schedule - FieldPlx</title>
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
.fd-schedule{min-height:calc(100vh - 70px);background:#fff}.fd-schedule-head{position:sticky;top:70px;z-index:20;background:#fff;border-bottom:1px solid var(--fd-border);padding:18px 22px 14px}.fd-head-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.fd-title{font-size:25px;font-weight:700;letter-spacing:-.3px;margin-right:auto}.fd-btn,.fd-chip,.fd-icon-btn{border:1px solid #d7dee8;background:#fff;color:#23364d;border-radius:8px;min-height:38px;padding:8px 12px;font-weight:700;font-size:13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;cursor:pointer}.fd-icon-btn{width:38px;padding:0}.fd-btn:hover,.fd-chip:hover,.fd-icon-btn:hover{border-color:#b8c4d2;background:#f8fafc}.fd-btn.primary{background:#2f8d25;border-color:#2f8d25;color:#fff}.fd-segment{display:inline-flex;border:1px solid #d7dee8;border-radius:8px;overflow:hidden;background:#fff}.fd-segment button{border:0;border-right:1px solid #d7dee8;background:#fff;padding:9px 14px;font-weight:700;color:#526278;cursor:pointer}.fd-segment button:last-child{border-right:0}.fd-segment button.active{background:#edf5e7;color:#2c6f22}.fd-toolbar{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}.fd-chip{font-weight:600}.fd-chip b{font-weight:700}.fd-calendar-wrap{position:relative;display:flex;min-height:700px}.fd-calendar-main{min-width:0;flex:1}.fd-month{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border-left:1px solid var(--fd-border);border-top:1px solid var(--fd-border)}.fd-dayname{height:38px;display:flex;align-items:center;justify-content:center;background:#f8fafb;border-right:1px solid var(--fd-border);border-bottom:1px solid var(--fd-border);font-size:11px;font-weight:700;color:#66758a;text-transform:uppercase}.fd-cell{min-height:132px;border-right:1px solid var(--fd-border);border-bottom:1px solid var(--fd-border);padding:7px;position:relative;background:#fff;overflow:hidden}.fd-cell.other{background:#fafbfc;color:#a3adbb}.fd-cell.today .fd-date{background:#356fd1;color:#fff}.fd-date{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}.fd-cell-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px}.fd-count{font-size:10px;color:#77859a}.fd-card{padding:5px 7px;border-radius:6px;margin:4px 0;font-size:10px;line-height:1.25;cursor:pointer;border-left:3px solid transparent;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.fd-card.visit{background:#eaf5e4;border-color:#56a92d;color:#245e1d}.fd-card.request{background:#fff3e1;border-color:#d9902a;color:#805111}.fd-card.task{background:#eaf0fb;border-color:#477fc9;color:#2a5489}.fd-card.event{background:#fff8d7;border-color:#caa92c;color:#725d0f}.fd-card.reminder{background:#fdebed;border-color:#db6670;color:#89333d}.fd-card.completed{opacity:.65;text-decoration:line-through}.fd-card.selected{outline:2px solid #1a73e8}.fd-more{font-size:10px;color:#3e6b9d;cursor:pointer;font-weight:700;padding:3px}.fd-week,.fd-day-view{display:none}.fd-week-grid{display:grid;grid-template-columns:72px repeat(7,minmax(130px,1fr));overflow:auto;border-top:1px solid var(--fd-border);border-left:1px solid var(--fd-border)}.fd-time-label,.fd-week-head,.fd-slot{border-right:1px solid var(--fd-border);border-bottom:1px solid var(--fd-border)}.fd-week-head{height:48px;padding:7px;text-align:center;font-size:12px;font-weight:700;background:#fafbfc}.fd-time-label{height:56px;padding:6px;text-align:right;color:#7a8798;font-size:10px;background:#fafbfc}.fd-slot{height:56px;position:relative;padding:3px;background:#fff}.fd-day-view{padding:0}.fd-agenda{max-width:900px;margin:auto;padding:12px 18px}.fd-agenda-row{display:grid;grid-template-columns:95px 1fr;gap:12px;padding:8px 0;border-bottom:1px solid var(--fd-border)}.fd-agenda-time{font-size:11px;color:#6f7b90;padding-top:7px}.fd-agenda-card{padding:10px 12px;border-radius:8px;background:#f7f9fb}.fd-empty{padding:60px 20px;text-align:center;color:#8692a3}.fd-drawer{position:fixed;top:70px;right:0;bottom:0;width:370px;max-width:95vw;background:#fff;border-left:1px solid var(--fd-border);box-shadow:-8px 0 30px rgba(16,35,62,.1);z-index:1050;transform:translateX(100%);transition:transform .25s ease;display:flex;flex-direction:column}.fd-drawer.show{transform:translateX(0)}.fd-drawer-head{padding:16px 18px;border-bottom:1px solid var(--fd-border);display:flex;align-items:center;gap:8px}.fd-drawer-head h3{margin:0;font-size:18px;flex:1}.fd-drawer-body{padding:16px;overflow:auto;flex:1}.fd-drawer-foot{padding:14px 16px;border-top:1px solid var(--fd-border);display:flex;gap:8px;justify-content:flex-end}.fd-field{margin-bottom:13px}.fd-field label{display:block;margin-bottom:5px;font-size:11px;font-weight:700;color:#57677a}.fd-control{width:100%;height:39px;border:1px solid #d8e0e9;border-radius:7px;padding:8px 10px;background:#fff;color:#23364d}.fd-control:focus{outline:0;border-color:#83b94b;box-shadow:0 0 0 3px rgba(116,184,36,.13)}textarea.fd-control{height:82px;resize:vertical}.fd-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}.fd-check{display:flex;gap:7px;align-items:center;font-size:12px;margin:7px 0}.fd-list-card{border:1px solid var(--fd-border);border-radius:8px;padding:10px;margin-bottom:8px;background:#fff;cursor:pointer}.fd-list-card strong{display:block;font-size:12px}.fd-list-card small{color:#78869a}.fd-dropzone{border:1px dashed #b9c3cf;border-radius:9px;padding:28px 12px;text-align:center;color:#8792a0;margin-top:12px}.fd-popover{position:absolute;top:100%;right:0;margin-top:6px;min-width:230px;background:#fff;border:1px solid var(--fd-border);border-radius:9px;box-shadow:0 12px 35px rgba(0,0,0,.12);padding:8px;z-index:100;display:none}.fd-popover.show{display:block}.fd-popover button{width:100%;border:0;background:#fff;padding:9px 10px;text-align:left;border-radius:6px;cursor:pointer}.fd-popover button:hover{background:#f3f6f8}.fd-relative{position:relative}.fd-filter-menu{position:absolute;top:calc(100% + 6px);left:0;width:250px;background:#fff;border:1px solid var(--fd-border);border-radius:9px;box-shadow:0 12px 35px rgba(0,0,0,.12);padding:10px;z-index:60;display:none}.fd-filter-menu.show{display:block}.fd-search{width:100%;height:35px;border:1px solid #dce2e8;border-radius:7px;padding:7px 9px;margin-bottom:8px}.fd-filter-option{display:flex;align-items:center;gap:8px;padding:7px 4px;font-size:12px}.fd-filter-actions{display:flex;justify-content:space-between;border-top:1px solid #edf0f3;padding-top:8px;margin-top:6px}.fd-link-btn{border:0;background:none;color:#2f7c26;font-weight:700;cursor:pointer}.fd-modal{position:fixed;inset:0;background:rgba(7,22,43,.42);z-index:1100;display:none;align-items:flex-start;justify-content:center;padding:80px 16px 30px;overflow:auto}.fd-modal.show{display:flex}.fd-dialog{width:500px;max-width:96vw;background:#fff;border-radius:12px;box-shadow:0 18px 60px rgba(0,0,0,.2);overflow:hidden}.fd-dialog.lg{width:720px}.fd-modal-head{padding:15px 18px;border-bottom:1px solid var(--fd-border);display:flex;align-items:center}.fd-modal-head h3{margin:0;flex:1;font-size:18px}.fd-modal-body{padding:16px 18px}.fd-modal-foot{padding:13px 18px;border-top:1px solid var(--fd-border);display:flex;justify-content:flex-end;gap:8px}.fd-tabs{display:flex;border-bottom:1px solid var(--fd-border);margin:-16px -18px 16px}.fd-tabs button{flex:1;padding:12px;border:0;background:#fff;font-weight:700;color:#6b7889;cursor:pointer}.fd-tabs button.active{color:#2f7d25;border-bottom:3px solid #2f8d25}.fd-type-panel{display:none}.fd-type-panel.active{display:block}.fd-toast{position:fixed;top:82px;right:18px;z-index:1200;min-width:260px;max-width:420px;padding:11px 14px;border-radius:9px;color:#fff;background:#26374b;box-shadow:0 10px 30px rgba(0,0,0,.18);display:none}.fd-toast.show{display:block}.fd-toast.success{background:#2e7d32}.fd-toast.error{background:#b93c4a}.fd-toast.warning{background:#9a671d}.fd-status-dot{width:8px;height:8px;border-radius:50%;display:inline-block}.fd-status-dot.visit{background:#56a92d}.fd-status-dot.request{background:#d9902a}.fd-status-dot.task{background:#477fc9}.fd-status-dot.event{background:#caa92c}.fd-status-dot.reminder{background:#db6670}.fd-settings-preview{border:1px solid var(--fd-border);border-radius:8px;padding:10px;background:#fafbfc}.fd-preview-card{height:30px;border-radius:5px;background:#eaf5e4;border-left:3px solid #56a92d;margin:6px 0}.fd-find-slot{display:flex;justify-content:space-between;align-items:center;border:1px solid var(--fd-border);border-radius:8px;padding:9px 10px;margin:7px 0}.fd-bulk-bar{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:80;background:#0e2648;color:#fff;border-radius:10px;padding:10px 12px;display:none;align-items:center;gap:10px;box-shadow:0 10px 35px rgba(0,0,0,.25)}.fd-bulk-bar.show{display:flex}.fd-bulk-bar .fd-btn{min-height:34px}.fd-calendar-wrap.drag-target{outline:3px solid rgba(116,184,36,.3);outline-offset:-3px}
@media(max-width:991.98px){.fieldplx-main-content,body.fieldplx-sidebar-collapsed .fieldplx-main-content{margin-left:0!important;width:100%!important}.fieldplx-footer,body.fieldplx-sidebar-collapsed .fieldplx-footer{margin-left:0!important}.fd-schedule-head{top:64px}.fd-drawer{top:64px}.fd-title{width:100%;font-size:21px}.fd-calendar-main{overflow:auto}.fd-month{min-width:840px}.fd-week-grid{min-width:1050px}.fd-modal{padding-top:72px}}
@media(max-width:767.98px){.fd-schedule-head{padding:12px}.fd-toolbar{gap:6px}.fd-chip{padding:7px 9px;font-size:11px}.fd-month{min-width:760px}.fd-day-view{display:block}.fd-agenda{padding:8px 10px}.fd-row{grid-template-columns:1fr}.fd-dialog{width:96vw}.fd-drawer{width:100vw;max-width:100vw}.fd-title{font-size:19px}.fd-segment button{padding:8px 10px;font-size:11px}}
</style>

<style id="fieldplx-schedule-force-styles">
/* Schedule-only cascade guard. Keeps shared FieldPlx/Bootstrap CSS from reverting calendar controls. */
#fieldplxSchedulePage{min-height:calc(100vh - 70px)!important;background:#fff!important;color:#0b1933!important;font-family:Arial,Helvetica,sans-serif!important}
#fieldplxSchedulePage *,#fieldplxSchedulePage *::before,#fieldplxSchedulePage *::after{box-sizing:border-box!important}
#fieldplxSchedulePage .fd-schedule-head{position:sticky!important;top:70px!important;z-index:20!important;background:#fff!important;border-bottom:1px solid #e5eaf1!important;padding:18px 22px 14px!important}
#fieldplxSchedulePage .fd-head-row{display:flex!important;align-items:center!important;gap:10px!important;flex-wrap:wrap!important}
#fieldplxSchedulePage .fd-title{margin-right:auto!important;font-size:25px!important;line-height:1.2!important;font-weight:700!important;color:#0b1933!important;letter-spacing:-.3px!important}
#fieldplxSchedulePage button.fd-btn,#fieldplxSchedulePage button.fd-chip,#fieldplxSchedulePage button.fd-icon-btn{appearance:none!important;-webkit-appearance:none!important;margin:0!important;border:1px solid #d7dee8!important;background:#fff!important;color:#23364d!important;border-radius:8px!important;min-height:38px!important;padding:8px 12px!important;font:700 13px/1.2 Arial,Helvetica,sans-serif!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;cursor:pointer!important;box-shadow:none!important}
#fieldplxSchedulePage button.fd-icon-btn{width:38px!important;padding:0!important}
#fieldplxSchedulePage button.fd-btn:hover,#fieldplxSchedulePage button.fd-chip:hover,#fieldplxSchedulePage button.fd-icon-btn:hover{border-color:#b8c4d2!important;background:#f8fafc!important}
#fieldplxSchedulePage button.fd-btn.primary{background:#2f8d25!important;border-color:#2f8d25!important;color:#fff!important}
#fieldplxSchedulePage .fd-segment{display:inline-flex!important;border:1px solid #d7dee8!important;border-radius:8px!important;overflow:hidden!important;background:#fff!important}
#fieldplxSchedulePage .fd-segment button{appearance:none!important;-webkit-appearance:none!important;margin:0!important;border:0!important;border-right:1px solid #d7dee8!important;border-radius:0!important;background:#fff!important;padding:9px 14px!important;color:#526278!important;font:700 13px/1.2 Arial,Helvetica,sans-serif!important;cursor:pointer!important}
#fieldplxSchedulePage .fd-segment button:last-child{border-right:0!important}
#fieldplxSchedulePage .fd-segment button.active{background:#edf5e7!important;color:#2c6f22!important}
#fieldplxSchedulePage .fd-toolbar{display:flex!important;align-items:center!important;gap:8px!important;margin-top:12px!important;flex-wrap:wrap!important}
#fieldplxSchedulePage .fd-relative{position:relative!important}
#fieldplxSchedulePage .fd-filter-menu{position:absolute!important;top:calc(100% + 6px)!important;left:0!important;width:250px!important;background:#fff!important;border:1px solid #e5eaf1!important;border-radius:9px!important;box-shadow:0 12px 35px rgba(0,0,0,.12)!important;padding:10px!important;z-index:60!important;display:none!important}
#fieldplxSchedulePage .fd-filter-menu.show{display:block!important}
#fieldplxSchedulePage .fd-search{appearance:none!important;width:100%!important;height:35px!important;border:1px solid #dce2e8!important;border-radius:7px!important;padding:7px 9px!important;margin:0 0 8px!important;background:#fff!important;color:#23364d!important;font-size:12px!important}
#fieldplxSchedulePage .fd-filter-option{display:flex!important;align-items:center!important;gap:8px!important;padding:7px 4px!important;font-size:12px!important}
#fieldplxSchedulePage .fd-filter-option input{width:auto!important;margin:0!important}
#fieldplxSchedulePage .fd-filter-actions{display:flex!important;justify-content:space-between!important;border-top:1px solid #edf0f3!important;padding-top:8px!important;margin-top:6px!important}
#fieldplxSchedulePage .fd-link-btn{appearance:none!important;border:0!important;background:transparent!important;color:#2f7c26!important;font-weight:700!important;cursor:pointer!important;padding:4px!important}
#fieldplxSchedulePage .fd-popover{position:absolute!important;top:100%!important;right:0!important;margin-top:6px!important;min-width:230px!important;background:#fff!important;border:1px solid #e5eaf1!important;border-radius:9px!important;box-shadow:0 12px 35px rgba(0,0,0,.12)!important;padding:8px!important;z-index:100!important;display:none!important}
#fieldplxSchedulePage .fd-popover.show{display:block!important}
#fieldplxSchedulePage .fd-popover button{appearance:none!important;width:100%!important;border:0!important;border-radius:6px!important;background:#fff!important;color:#23364d!important;padding:9px 10px!important;text-align:left!important;font-size:12px!important;cursor:pointer!important}
#fieldplxSchedulePage .fd-popover button:hover{background:#f3f6f8!important}
#fieldplxSchedulePage .fd-calendar-wrap{position:relative!important;display:flex!important;min-height:700px!important;background:#fff!important}
#fieldplxSchedulePage .fd-calendar-main{min-width:0!important;flex:1 1 auto!important}
#fieldplxSchedulePage .fd-month{display:grid!important;grid-template-columns:repeat(7,minmax(0,1fr))!important;border-left:1px solid #e5eaf1!important;border-top:1px solid #e5eaf1!important;background:#fff!important}
#fieldplxSchedulePage .fd-dayname{height:38px!important;display:flex!important;align-items:center!important;justify-content:center!important;background:#f8fafb!important;border-right:1px solid #e5eaf1!important;border-bottom:1px solid #e5eaf1!important;color:#66758a!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important}
#fieldplxSchedulePage .fd-cell{min-height:132px!important;border-right:1px solid #e5eaf1!important;border-bottom:1px solid #e5eaf1!important;padding:7px!important;position:relative!important;background:#fff!important;overflow:hidden!important;color:#0b1933!important}
#fieldplxSchedulePage .fd-cell.other{background:#fafbfc!important;color:#a3adbb!important}
#fieldplxSchedulePage .fd-cell-head{display:flex!important;justify-content:space-between!important;align-items:center!important;margin-bottom:5px!important}
#fieldplxSchedulePage .fd-date{width:28px!important;height:28px!important;border-radius:7px!important;display:flex!important;align-items:center!important;justify-content:center!important;font-size:12px!important;font-weight:700!important}
#fieldplxSchedulePage .fd-cell.today .fd-date{background:#356fd1!important;color:#fff!important}
#fieldplxSchedulePage .fd-card{display:block!important;padding:5px 7px!important;border-radius:6px!important;margin:4px 0!important;font-size:10px!important;line-height:1.25!important;cursor:pointer!important;border:0!important;border-left:3px solid transparent!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;text-decoration:none!important}
#fieldplxSchedulePage .fd-card.visit{background:#eaf5e4!important;border-left-color:#56a92d!important;color:#245e1d!important}
#fieldplxSchedulePage .fd-card.request{background:#fff3e1!important;border-left-color:#d9902a!important;color:#805111!important}
#fieldplxSchedulePage .fd-card.task{background:#eaf0fb!important;border-left-color:#477fc9!important;color:#2a5489!important}
#fieldplxSchedulePage .fd-card.event{background:#fff8d7!important;border-left-color:#caa92c!important;color:#725d0f!important}
#fieldplxSchedulePage .fd-card.reminder{background:#fdebed!important;border-left-color:#db6670!important;color:#89333d!important}
#fieldplxSchedulePage .fd-week{display:none}#fieldplxSchedulePage .fd-day-view{display:none}
#fieldplxSchedulePage .fd-week-grid{display:grid!important;grid-template-columns:72px repeat(7,minmax(130px,1fr))!important;overflow:auto!important;border-top:1px solid #e5eaf1!important;border-left:1px solid #e5eaf1!important}
#fieldplxSchedulePage .fd-week-head,#fieldplxSchedulePage .fd-time-label,#fieldplxSchedulePage .fd-slot{border-right:1px solid #e5eaf1!important;border-bottom:1px solid #e5eaf1!important}
#fieldplxSchedulePage .fd-week-head{height:48px!important;padding:7px!important;text-align:center!important;font-size:12px!important;font-weight:700!important;background:#fafbfc!important}
#fieldplxSchedulePage .fd-time-label{height:56px!important;padding:6px!important;text-align:right!important;color:#7a8798!important;font-size:10px!important;background:#fafbfc!important}
#fieldplxSchedulePage .fd-slot{height:56px!important;position:relative!important;padding:3px!important;background:#fff!important}
#fieldplxSchedulePage .fd-agenda{max-width:900px!important;margin:auto!important;padding:12px 18px!important}
#fieldplxSchedulePage .fd-agenda-row{display:grid!important;grid-template-columns:95px 1fr!important;gap:12px!important;padding:8px 0!important;border-bottom:1px solid #e5eaf1!important}
#fieldplxSchedulePage .fd-drawer{position:fixed!important;top:70px!important;right:0!important;bottom:0!important;width:370px!important;max-width:95vw!important;background:#fff!important;border-left:1px solid #e5eaf1!important;box-shadow:-8px 0 30px rgba(16,35,62,.1)!important;z-index:1050!important;transform:translateX(100%)!important;transition:transform .25s ease!important;display:flex!important;flex-direction:column!important}
#fieldplxSchedulePage .fd-drawer.show{transform:translateX(0)!important}
#fieldplxSchedulePage .fd-control{appearance:none!important;width:100%!important;height:39px!important;border:1px solid #d8e0e9!important;border-radius:7px!important;padding:8px 10px!important;background:#fff!important;color:#23364d!important}
#fieldplxSchedulePage textarea.fd-control{height:82px!important;resize:vertical!important}
#fieldplxSchedulePage .fd-modal{position:fixed!important;inset:0!important;background:rgba(7,22,43,.42)!important;z-index:1100!important;display:none!important;align-items:flex-start!important;justify-content:center!important;padding:80px 16px 30px!important;overflow:auto!important}
#fieldplxSchedulePage .fd-modal.show{display:flex!important}
#fieldplxSchedulePage .fd-dialog{width:500px!important;max-width:96vw!important;background:#fff!important;border-radius:12px!important;box-shadow:0 18px 60px rgba(0,0,0,.2)!important;overflow:hidden!important}
@media(max-width:991.98px){#fieldplxSchedulePage .fd-schedule-head{top:64px!important}#fieldplxSchedulePage .fd-month{min-width:840px!important}#fieldplxSchedulePage .fd-calendar-main{overflow:auto!important}#fieldplxSchedulePage .fd-drawer{top:64px!important}}
@media(max-width:767.98px){#fieldplxSchedulePage .fd-schedule-head{padding:12px!important}#fieldplxSchedulePage .fd-title{width:100%!important;font-size:19px!important}#fieldplxSchedulePage .fd-month{min-width:760px!important}#fieldplxSchedulePage .fd-drawer{width:100vw!important;max-width:100vw!important}}


/* ==========================================================
   Schedule overlays live outside #fieldplxSchedulePage.
   Keep these rules global and strong so tenant/global CSS
   cannot expose drawers/modals as raw document content.
   ========================================================== */
body .fd-drawer{position:fixed!important;top:70px!important;right:0!important;bottom:0!important;width:370px!important;max-width:95vw!important;background:#fff!important;border-left:1px solid #e5eaf1!important;box-shadow:-8px 0 30px rgba(16,35,62,.10)!important;z-index:1060!important;transform:translateX(105%)!important;visibility:hidden!important;pointer-events:none!important;transition:transform .25s ease,visibility .25s ease!important;display:flex!important;flex-direction:column!important;color:#0b1933!important}
body .fd-drawer.show{transform:translateX(0)!important;visibility:visible!important;pointer-events:auto!important}
body .fd-drawer-head{padding:16px 18px!important;border-bottom:1px solid #e5eaf1!important;display:flex!important;align-items:center!important;gap:8px!important;background:#fff!important}
body .fd-drawer-head h3{margin:0!important;font-size:18px!important;line-height:1.25!important;font-weight:700!important;flex:1!important;color:#0b1933!important}
body .fd-drawer-body{padding:16px!important;overflow:auto!important;flex:1 1 auto!important;background:#fff!important}
body .fd-drawer-foot{padding:14px 16px!important;border-top:1px solid #e5eaf1!important;display:flex!important;gap:8px!important;justify-content:flex-end!important;background:#fff!important}
body .fd-field{margin-bottom:13px!important}
body .fd-field label{display:block!important;margin-bottom:5px!important;font-size:11px!important;font-weight:700!important;color:#57677a!important}
body .fd-control{appearance:none!important;width:100%!important;min-width:0!important;height:39px!important;border:1px solid #d8e0e9!important;border-radius:7px!important;padding:8px 10px!important;background:#fff!important;color:#23364d!important;font:inherit!important;font-size:12px!important;box-shadow:none!important}
body select.fd-control[multiple]{height:auto!important;min-height:112px!important;padding:6px!important}
body textarea.fd-control{height:82px!important;resize:vertical!important}
body .fd-control:focus{outline:0!important;border-color:#83b94b!important;box-shadow:0 0 0 3px rgba(116,184,36,.13)!important}
body .fd-row{display:grid!important;grid-template-columns:1fr 1fr!important;gap:10px!important}
body .fd-check{display:flex!important;gap:7px!important;align-items:center!important;font-size:12px!important;margin:7px 0!important;color:#23364d!important}
body .fd-check input{width:auto!important;margin:0!important}
body .fd-list-card{border:1px solid #e5eaf1!important;border-radius:8px!important;padding:10px!important;margin-bottom:8px!important;background:#fff!important;cursor:pointer!important}
body .fd-list-card strong{display:block!important;font-size:12px!important;color:#0b1933!important}
body .fd-list-card small{color:#78869a!important}
body .fd-dropzone{border:1px dashed #b9c3cf!important;border-radius:9px!important;padding:28px 12px!important;text-align:center!important;color:#8792a0!important;margin-top:12px!important;background:#fbfcfd!important}
body .fd-drawer .fd-btn,body .fd-modal .fd-btn,body .fd-bulk-bar .fd-btn{appearance:none!important;border:1px solid #d7dee8!important;background:#fff!important;color:#23364d!important;border-radius:8px!important;min-height:38px!important;padding:8px 12px!important;font-weight:700!important;font-size:13px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;cursor:pointer!important;line-height:1!important;text-decoration:none!important}
body .fd-drawer .fd-btn.primary,body .fd-modal .fd-btn.primary{background:#2f8d25!important;border-color:#2f8d25!important;color:#fff!important}
body .fd-drawer .fd-icon-btn,body .fd-modal .fd-icon-btn{appearance:none!important;width:38px!important;height:38px!important;min-width:38px!important;padding:0!important;border:1px solid #d7dee8!important;border-radius:8px!important;background:#fff!important;color:#23364d!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;cursor:pointer!important}

body .fd-modal{position:fixed!important;inset:0!important;background:rgba(7,22,43,.42)!important;z-index:1100!important;display:none!important;align-items:flex-start!important;justify-content:center!important;padding:80px 16px 30px!important;overflow:auto!important;visibility:hidden!important;pointer-events:none!important}
body .fd-modal.show{display:flex!important;visibility:visible!important;pointer-events:auto!important}
body .fd-dialog{width:500px!important;max-width:96vw!important;background:#fff!important;border:1px solid #e5eaf1!important;border-radius:12px!important;box-shadow:0 18px 60px rgba(0,0,0,.20)!important;overflow:hidden!important;color:#0b1933!important}
body .fd-dialog.lg{width:720px!important}
body .fd-modal-head{padding:15px 18px!important;border-bottom:1px solid #e5eaf1!important;display:flex!important;align-items:center!important;gap:8px!important;background:#fff!important}
body .fd-modal-head h3{margin:0!important;flex:1!important;font-size:18px!important;font-weight:700!important;color:#0b1933!important}
body .fd-modal-body{padding:16px 18px!important;background:#fff!important}
body .fd-modal-foot{padding:13px 18px!important;border-top:1px solid #e5eaf1!important;display:flex!important;justify-content:flex-end!important;gap:8px!important;background:#fff!important}
body .fd-tabs{display:flex!important;border-bottom:1px solid #e5eaf1!important;margin:-16px -18px 16px!important}
body .fd-tabs button{appearance:none!important;flex:1!important;padding:12px!important;border:0!important;background:#fff!important;font-weight:700!important;color:#6b7889!important;cursor:pointer!important}
body .fd-tabs button.active{color:#2f7d25!important;border-bottom:3px solid #2f8d25!important}
body .fd-type-panel{display:none!important}
body .fd-type-panel.active{display:block!important}
body .fd-settings-preview{border:1px solid #e5eaf1!important;border-radius:8px!important;padding:10px!important;background:#fafbfc!important}
body .fd-preview-card{height:30px!important;border-radius:5px!important;background:#eaf5e4!important;border-left:3px solid #56a92d!important;margin:6px 0!important}
body .fd-find-slot{display:flex!important;justify-content:space-between!important;align-items:center!important;border:1px solid #e5eaf1!important;border-radius:8px!important;padding:9px 10px!important;margin:7px 0!important;background:#fff!important}

body .fd-toast{position:fixed!important;top:82px!important;right:18px!important;z-index:1200!important;min-width:260px!important;max-width:420px!important;padding:11px 14px!important;border-radius:9px!important;color:#fff!important;background:#26374b!important;box-shadow:0 10px 30px rgba(0,0,0,.18)!important;display:none!important}
body .fd-toast.show{display:block!important}
body .fd-toast.success{background:#2e7d32!important}
body .fd-toast.error{background:#b93c4a!important}
body .fd-toast.warning{background:#9a671d!important}

body .fd-bulk-bar{position:fixed!important;left:50%!important;bottom:22px!important;transform:translateX(-50%)!important;z-index:1080!important;background:#0e2648!important;color:#fff!important;border-radius:10px!important;padding:10px 12px!important;display:none!important;align-items:center!important;gap:10px!important;box-shadow:0 10px 35px rgba(0,0,0,.25)!important}
body .fd-bulk-bar.show{display:flex!important}

@media(max-width:991.98px){body .fd-drawer{top:64px!important}}
@media(max-width:767.98px){body .fd-drawer{width:100vw!important;max-width:100vw!important}body .fd-modal{padding:72px 10px 20px!important}body .fd-dialog,body .fd-dialog.lg{width:100%!important;max-width:100%!important}body .fd-row{grid-template-columns:1fr!important}}

/* Jobber-style quick create optional fields */
body .fd-quick-actions{display:flex!important;flex-direction:column!important;align-items:flex-start!important;gap:12px!important;margin:2px 0 14px!important}
body .fd-add-action{appearance:none!important;min-height:40px!important;padding:8px 13px!important;display:inline-flex!important;align-items:center!important;gap:9px!important;border:1px solid #d7dee8!important;border-radius:8px!important;background:#fff!important;color:#294052!important;font:700 13px/1.2 Arial,Helvetica,sans-serif!important;cursor:pointer!important}
body .fd-add-action:hover{border-color:#9bc66e!important;background:#f7fbf2!important;color:#2f7d25!important}
body .fd-add-action i{font-size:15px!important}
body .fd-optional-section{width:100%!important;margin:0!important}
body .fd-optional-section[hidden]{display:none!important}
body .fd-remove-optional{appearance:none!important;margin-top:5px!important;padding:2px 0!important;border:0!important;background:transparent!important;color:#748294!important;font-size:10px!important;cursor:pointer!important}
body .fd-remove-optional:hover{color:#b93c4a!important}
body .fd-client-search-wrap{position:relative!important}
body .fd-client-search-input{height:48px!important;font-size:14px!important;padding:10px 14px!important}
body .fd-client-results{position:absolute!important;left:0!important;right:0!important;top:calc(100% + 6px)!important;z-index:120!important;display:none!important;max-height:290px!important;overflow:auto!important;border:1px solid #d8e0e9!important;border-radius:9px!important;background:#fff!important;box-shadow:0 10px 28px rgba(16,35,62,.14)!important}
body .fd-client-results.show{display:block!important}
body .fd-client-result{width:100%!important;min-height:44px!important;padding:9px 12px!important;display:flex!important;align-items:center!important;gap:9px!important;border:0!important;border-bottom:1px solid #eef1f4!important;background:#fff!important;color:#23364d!important;text-align:left!important;font-size:12px!important;cursor:pointer!important}
body .fd-client-result:last-child{border-bottom:0!important}
body .fd-client-result:hover{background:#f8fafc!important}
body .fd-client-result strong,body .fd-client-result small{display:block!important}
body .fd-client-result small{margin-top:2px!important;color:#7b8796!important;font-size:10px!important}
body .fd-client-create{color:#2f8d25!important;font-weight:700!important;font-size:13px!important}
body .fd-client-create i{font-size:16px!important}


/* Jobber-style inline Create client or address */
body #quickClientCreateModal{z-index:1130!important}
body #quickClientCreateModal .fd-dialog{width:620px!important;max-width:calc(100vw - 28px)!important}
body .fd-client-create-title{font-size:20px!important;font-weight:700!important;color:#0b1933!important}
body .fd-client-create-grid{display:grid!important;grid-template-columns:1fr 1fr!important;gap:12px!important}
body .fd-client-create-grid .full{grid-column:1/-1!important}
body .fd-client-create-section{margin:2px 0 14px!important;color:#5c6d80!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:.04em!important}
body .fd-client-create-error{display:none!important;margin:0 0 12px!important;padding:9px 11px!important;border:1px solid #f0c6ca!important;border-radius:8px!important;background:#fff3f4!important;color:#a33b46!important;font-size:11px!important}
body .fd-client-create-error.show{display:block!important}
body #saveInlineClient[disabled]{opacity:.6!important;cursor:not-allowed!important}
@media(max-width:767.98px){body .fd-client-create-grid{grid-template-columns:1fr!important}body .fd-client-create-grid .full{grid-column:auto!important}}

</style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<main class="fieldplx-main-content"><div class="fieldplx-content-wrapper"><section class="fd-schedule" id="fieldplxSchedulePage">
  <header class="fd-schedule-head">
    <div class="fd-head-row">
      <div class="fd-title" id="periodTitle">Schedule</div>
      <button class="fd-icon-btn" id="prevBtn" title="Previous"><i class="bi bi-chevron-left"></i></button>
      <button class="fd-icon-btn" id="nextBtn" title="Next"><i class="bi bi-chevron-right"></i></button>
      <button class="fd-btn" id="todayBtn">Today</button>
      <button class="fd-btn primary" id="findTimeBtn"><i class="bi bi-clock"></i> Find a Time</button>
      <div class="fd-segment" id="viewSegment"><button data-view="month" class="active">Month</button><button data-view="week">Week</button><button data-view="day">Day</button></div>
      <button class="fd-btn" id="unscheduledBtn"><i class="bi bi-inbox"></i> Unscheduled <span id="unscheduledCount">0</span></button>
      <button class="fd-icon-btn" id="mapBtn" title="Map"><i class="bi bi-map"></i></button>
      <div class="fd-relative"><button class="fd-btn" id="moreBtn"><i class="bi bi-three-dots"></i> More</button><div class="fd-popover" id="moreMenu"><button data-more="bulk">Reschedule &amp; reassign</button><button data-more="newvisit">Create new visits</button><button data-more="crews">Manage crews</button><button data-more="settings">Schedule Settings</button></div></div>
    </div>
    <div class="fd-toolbar">
      <div class="fd-relative"><button class="fd-chip" data-filter-btn="type">Type <b id="typeLabel">All</b> <i class="bi bi-chevron-down"></i></button><div class="fd-filter-menu" id="typeMenu"><input class="fd-search" placeholder="Search" data-filter-search="type"><div id="typeOptions"></div><div class="fd-filter-actions"><button class="fd-link-btn" data-select-all="type">Select All</button><button class="fd-link-btn" data-clear="type">Clear</button></div></div></div>
      <div class="fd-relative"><button class="fd-chip" data-filter-btn="team">Team <b id="teamLabel">All</b> <i class="bi bi-chevron-down"></i></button><div class="fd-filter-menu" id="teamMenu"><input class="fd-search" placeholder="Search" data-filter-search="team"><div id="teamOptions"></div><div class="fd-filter-actions"><button class="fd-link-btn" data-select-all="team">Select All</button><button class="fd-link-btn" data-clear="team">Clear</button></div></div></div>
      <div class="fd-relative"><button class="fd-chip" data-filter-btn="status">Status <b id="statusLabel">All</b> <i class="bi bi-chevron-down"></i></button><div class="fd-filter-menu" id="statusMenu"><input class="fd-search" placeholder="Search" data-filter-search="status"><div id="statusOptions"></div><div class="fd-filter-actions"><button class="fd-link-btn" data-select-all="status">Select All</button><button class="fd-link-btn" data-clear="status">Clear</button></div></div></div>
    </div>
  </header>
  <div class="fd-calendar-wrap" id="calendarWrap">
    <div class="fd-calendar-main">
      <div id="monthView" class="fd-month"></div>
      <div id="weekView" class="fd-week"><div id="weekGrid" class="fd-week-grid"></div></div>
      <div id="dayView" class="fd-day-view"><div class="fd-agenda" id="dayAgenda"></div></div>
    </div>
  </div>
</section></div></main></div>

<div class="fd-drawer" id="unscheduledDrawer"><div class="fd-drawer-head"><h3>Unscheduled <span id="drawerUnscheduledCount">0</span></h3><button class="fd-icon-btn" data-close-drawer="unscheduledDrawer"><i class="bi bi-x-lg"></i></button></div><div class="fd-drawer-body"><div class="fd-field"><select id="unscheduledSort" class="fd-control"><option value="oldest">Oldest first</option><option value="newest">Newest first</option><option value="az">Client A-Z</option><option value="za">Client Z-A</option><option value="manual">Manual</option></select></div><div id="unscheduledList"></div><div class="fd-dropzone" id="unscheduleDropzone"><i class="bi bi-box-arrow-in-down" style="font-size:25px"></i><div style="margin-top:8px">Drag items here to unschedule them</div></div></div></div>

<div class="fd-drawer" id="detailDrawer"><div class="fd-drawer-head"><h3 id="detailTitle">Appointment</h3><button class="fd-icon-btn" data-close-drawer="detailDrawer"><i class="bi bi-x-lg"></i></button></div><div class="fd-drawer-body" id="detailBody"></div><div class="fd-drawer-foot"><button class="fd-btn" id="detailFindTime">Find a Time</button><button class="fd-btn" id="detailEdit">Edit</button><button class="fd-btn primary" id="detailOpen">Details</button></div></div>

<div class="fd-drawer" id="bulkDrawer"><div class="fd-drawer-head"><h3>Reschedule and reassign appointments</h3><button class="fd-icon-btn" data-close-drawer="bulkDrawer"><i class="bi bi-x-lg"></i></button></div><div class="fd-drawer-body"><p style="color:#728095;font-size:12px">From the schedule or map, select the appointments you want to reschedule or reassign.</p><div class="fd-field"><label>RESCHEDULE TO</label><select id="bulkMode" class="fd-control"><option value="none">No change</option><option value="specific">Specific date</option><option value="shift">Shift by days</option></select></div><div class="fd-field" id="bulkDateWrap" style="display:none"><label>Choose date</label><input id="bulkDate" type="date" class="fd-control"></div><div class="fd-field" id="bulkShiftWrap" style="display:none"><label>Shift by days</label><div class="fd-row"><button class="fd-btn" id="shiftMinus">-</button><input id="bulkShift" type="number" class="fd-control" value="1"><button class="fd-btn" id="shiftPlus">+</button></div></div><div class="fd-field"><label>REASSIGN TO</label><select id="bulkAssignees" class="fd-control" multiple size="6"></select></div><div style="font-weight:700" id="bulkCount">0 appointments selected</div></div><div class="fd-drawer-foot"><button class="fd-btn primary" id="bulkConfirm" disabled>Confirm</button></div></div>

<div class="fd-drawer" id="crewsDrawer"><div class="fd-drawer-head"><h3>Crews</h3><button class="fd-btn primary" id="addCrewBtn"><i class="bi bi-plus-lg"></i> Add crew</button><button class="fd-icon-btn" data-close-drawer="crewsDrawer"><i class="bi bi-x-lg"></i></button></div><div class="fd-drawer-body" id="crewsList"></div></div>

<div class="fd-drawer" id="findDrawer"><div class="fd-drawer-head"><h3>Find a Time</h3><button class="fd-icon-btn" data-close-drawer="findDrawer"><i class="bi bi-x-lg"></i></button></div><div class="fd-drawer-body"><div class="fd-field"><label>Team member</label><select id="findUser" class="fd-control"></select></div><div class="fd-field"><label>Crew</label><select id="findCrew" class="fd-control"></select></div><div class="fd-row"><div class="fd-field"><label>Starting</label><input id="findDate" type="date" class="fd-control"></div><div class="fd-field"><label>Days</label><input id="findDays" type="number" min="1" max="31" value="7" class="fd-control"></div></div><div class="fd-field"><label>Duration in minutes</label><input id="findDuration" type="number" min="15" step="15" value="60" class="fd-control"></div><button class="fd-btn primary" id="findSearch">Find available times</button><div id="findResults" style="margin-top:14px"></div></div></div>

<div class="fd-modal" id="quickModal"><div class="fd-dialog"><div class="fd-modal-head"><h3 id="quickHeading">New appointment</h3><button class="fd-icon-btn" data-close-modal="quickModal"><i class="bi bi-x-lg"></i></button></div><div class="fd-modal-body"><div class="fd-tabs" id="quickTabs"><button class="active" data-qtype="job">Job</button><button data-qtype="request">Request</button><button data-qtype="task">Task</button><button data-qtype="event">Event</button></div>
<div id="commonClient" class="fd-type-panel active">
  <div class="fd-field fd-client-search-wrap">
    <input id="quickClientSearch" class="fd-control fd-client-search-input" autocomplete="off" placeholder="Search client or address">
    <input type="hidden" id="quickClient" value="">
    <div class="fd-client-results" id="quickClientResults"></div>
  </div>
  <div class="fd-field fd-optional-section" id="quickPropertyWrap" hidden><label>Property</label><select id="quickProperty" class="fd-control"></select></div>
</div>
<div class="fd-field"><input id="quickTitle" class="fd-control" placeholder="Title"></div>
<div class="fd-quick-actions" id="quickOptionalActions">
  <button type="button" class="fd-add-action" id="addInstructionsBtn"><i class="bi bi-plus-lg"></i> Add Instructions</button>
  <div class="fd-optional-section" id="instructionsSection" hidden><textarea id="quickDescription" class="fd-control" placeholder="Add instructions"></textarea><button type="button" class="fd-remove-optional" data-hide-optional="instructionsSection">Remove</button></div>
  <button type="button" class="fd-add-action" id="addLineItemBtn"><i class="bi bi-plus-lg"></i> Add Line Item</button>
  <div class="fd-optional-section" id="lineItemWrap" hidden><select id="quickLineItem" class="fd-control"><option value="">Select line item</option></select><button type="button" class="fd-remove-optional" data-hide-optional="lineItemWrap">Remove</button></div>
  <button type="button" class="fd-add-action" id="addAssignBtn"><i class="bi bi-plus-lg"></i> Assign</button>
  <div class="fd-optional-section" id="assignSection" hidden><select id="quickAssignees" class="fd-control" multiple size="4"></select><button type="button" class="fd-remove-optional" data-hide-optional="assignSection">Remove</button></div>
</div><label class="fd-check"><input type="checkbox" id="quickScheduleLater"> Unscheduled / Schedule later</label><div id="quickScheduleFields"><div class="fd-row"><div class="fd-field"><label>Start Date</label><input id="quickDate" type="date" class="fd-control"></div><div class="fd-field"><label>Duration in hours</label><input id="quickDuration" type="number" min="0.25" step="0.25" value="1" class="fd-control"></div></div><div class="fd-row"><div class="fd-field"><label>Start Time</label><input id="quickTime" type="time" value="09:00" class="fd-control"></div><div class="fd-field" id="quickEndDateWrap" style="display:none"><label>End Date</label><input id="quickEndDate" type="date" class="fd-control"></div></div><label class="fd-check"><input type="checkbox" id="quickAnytime"> Anytime</label><label class="fd-check"><input type="checkbox" id="quickAvailability"> Show availability</label></div><div id="repeatWrap" style="display:none"><div class="fd-field"><label>Repeats</label><select id="quickRepeats" class="fd-control"><option value="never">Never</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></div></div></div><div class="fd-modal-foot"><button class="fd-btn" id="moreOptionsBtn">More Options</button><button class="fd-btn primary" id="quickSave">Save</button></div></div></div>


<div class="fd-modal" id="quickClientCreateModal">
  <div class="fd-dialog">
    <div class="fd-modal-head">
      <h3 class="fd-client-create-title">Create client or address</h3>
      <button type="button" class="fd-icon-btn" data-close-modal="quickClientCreateModal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="fd-modal-body">
      <div class="fd-client-create-error" id="inlineClientCreateError"></div>
      <div class="fd-client-create-section">Client details</div>
      <div class="fd-client-create-grid">
        <div class="fd-field"><label>First Name</label><input id="clientCreateFirstName" class="fd-control" autocomplete="given-name" placeholder="First name"></div>
        <div class="fd-field"><label>Last Name</label><input id="clientCreateLastName" class="fd-control" autocomplete="family-name" placeholder="Last name"></div>
        <div class="fd-field full"><label>Company</label><input id="clientCreateCompany" class="fd-control" autocomplete="organization" placeholder="Company name"></div>
        <div class="fd-field"><label>Phone</label><input id="clientCreatePhone" class="fd-control" autocomplete="tel" placeholder="Phone number"></div>
        <div class="fd-field"><label>Email</label><input id="clientCreateEmail" type="email" class="fd-control" autocomplete="email" placeholder="Email"></div>
        <div class="fd-field full"><label>Lead Source</label><input id="clientCreateSource" class="fd-control" list="clientCreateSourceList" placeholder="Lead source"><datalist id="clientCreateSourceList"></datalist></div>
      </div>
      <div class="fd-client-create-section" style="margin-top:8px!important">Property / address</div>
      <div class="fd-client-create-grid">
        <div class="fd-field full"><label>Street 1</label><input id="clientCreateStreet1" class="fd-control" autocomplete="address-line1" placeholder="Street 1"></div>
        <div class="fd-field full"><label>Street 2</label><input id="clientCreateStreet2" class="fd-control" autocomplete="address-line2" placeholder="Street 2"></div>
        <div class="fd-field"><label>City</label><input id="clientCreateCity" class="fd-control" autocomplete="address-level2" placeholder="City"></div>
        <div class="fd-field"><label>State / Province</label><input id="clientCreateState" class="fd-control" autocomplete="address-level1" placeholder="State / Province"></div>
        <div class="fd-field"><label>Postal Code</label><input id="clientCreatePostal" class="fd-control" autocomplete="postal-code" placeholder="Postal code"></div>
        <div class="fd-field"><label>Country</label><select id="clientCreateCountry" class="fd-control"><option value="">Select country</option></select></div>
      </div>
    </div>
    <div class="fd-modal-foot">
      <button type="button" class="fd-btn" data-close-modal="quickClientCreateModal">Cancel</button>
      <button type="button" class="fd-btn primary" id="saveInlineClient">Create client</button>
    </div>
  </div>
</div>

<div class="fd-modal" id="crewModal"><div class="fd-dialog"><div class="fd-modal-head"><h3>Create a new crew</h3><button class="fd-icon-btn" data-close-modal="crewModal"><i class="bi bi-x-lg"></i></button></div><div class="fd-modal-body"><div class="fd-field"><label>Crew name</label><input id="crewName" class="fd-control" placeholder="e.g. West Team"></div><div class="fd-field"><label>Crew leader</label><select id="crewLeader" class="fd-control"></select></div><div class="fd-field"><label>Members</label><select id="crewMembers" class="fd-control" multiple size="7"></select></div></div><div class="fd-modal-foot"><button class="fd-btn" data-close-modal="crewModal">Cancel</button><button class="fd-btn primary" id="saveCrew">Create crew</button></div></div></div>

<div class="fd-modal" id="settingsModal"><div class="fd-dialog lg"><div class="fd-modal-head"><h3>Customize your schedule</h3><button class="fd-icon-btn" data-close-modal="settingsModal"><i class="bi bi-x-lg"></i></button></div><div class="fd-modal-body"><div class="fd-row"><div><div class="fd-field"><label>Default view</label><select id="settingView" class="fd-control"><option value="month">Month</option><option value="week">Week</option><option value="day">Day</option></select></div><div class="fd-field"><label>Appointment layout</label><label class="fd-check"><input type="radio" name="layout" value="stacked" checked> Stacked — easier to see time gaps</label><label class="fd-check"><input type="radio" name="layout" value="nested"> Nested — easier to read titles in busy schedules</label></div><label class="fd-check"><input type="checkbox" id="settingWeekends" checked> Show weekends</label><div class="fd-row"><div class="fd-field"><label>Workday start</label><input id="settingStart" type="time" class="fd-control"></div><div class="fd-field"><label>Workday end</label><input id="settingEnd" type="time" class="fd-control"></div></div><div class="fd-row"><div class="fd-field"><label>Time interval</label><select id="settingSlot" class="fd-control"><option value="15">15 min</option><option value="30">30 min</option><option value="60">60 min</option></select></div><div class="fd-field"><label>Default duration</label><input id="settingDuration" type="number" min="15" step="15" class="fd-control"></div></div><div class="fd-field"><label>Timezone</label><input id="settingTimezone" class="fd-control" value="Asia/Kolkata"></div></div><div><label style="display:block;font-size:11px;font-weight:700;margin-bottom:6px">Preview</label><div class="fd-settings-preview"><strong>Mon 14</strong><div class="fd-preview-card"></div><div class="fd-preview-card" style="width:72%"></div><div class="fd-preview-card" style="width:58%"></div></div></div></div></div><div class="fd-modal-foot"><button class="fd-btn" data-close-modal="settingsModal">Cancel</button><button class="fd-btn primary" id="saveSettings">Finish</button></div></div></div>

<div class="fd-bulk-bar" id="bulkBar"><strong id="bulkBarCount">0 selected</strong><button class="fd-btn" id="openBulk">Reschedule &amp; reassign</button><button class="fd-btn" id="cancelBulk">Cancel</button></div>
<div class="fd-toast" id="toast"></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script>
(function(){'use strict';
var csrf=<?= json_encode($scheduleCsrf) ?>,api='api/schedule.php',clientCsrf=<?= json_encode($clientsCsrf) ?>,clientApi='api/client-form.php';
var clientMeta={countries:[],lead_sources:[]},clientMetaLoaded=false;
var meta={users:[],clients:[],properties:[],catalog:[],crews:[],settings:{}},items=[],unscheduled=[],view='month',anchor=new Date(),filters={type:[],team:[],status:[]},bulkMode=false,selected={},detail=null,quickType='job';
function E(id){return document.getElementById(id)}
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function toast(type,msg){var t=E('toast');t.className='fd-toast '+type+' show';t.textContent=msg;clearTimeout(t._timer);t._timer=setTimeout(function(){t.classList.remove('show')},3500)}
function req(data){var fd=new FormData();Object.keys(data||{}).forEach(function(k){var v=data[k];if(Array.isArray(v)||typeof v==='object'&&v!==null)fd.append(k,JSON.stringify(v));else fd.append(k,v==null?'':v)});fd.append('csrf_token',csrf);return fetch(api,{method:'POST',body:fd,credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.text().then(function(raw){var j;try{j=JSON.parse(raw)}catch(e){throw new Error(raw.replace(/<[^>]+>/g,' ').trim()||'Invalid server response')}if(!r.ok||!j.success)throw new Error(j.message||'Request failed');return j})})}
function clientReq(data){var fd=new FormData();Object.keys(data||{}).forEach(function(k){var v=data[k];if(Array.isArray(v)||typeof v==='object'&&v!==null)fd.append(k,JSON.stringify(v));else fd.append(k,v==null?'':v)});fd.append('csrf_token',clientCsrf);return fetch(clientApi,{method:'POST',body:fd,credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.text().then(function(raw){var j;try{j=JSON.parse(raw)}catch(e){throw new Error(raw.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim()||'Invalid client server response')}if(!r.ok||!j.success)throw new Error(j.message||(j.error&&j.error.message)||'Client request failed');return j})})}
function ymd(d){return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')}
function parseDate(s){var p=String(s||'').slice(0,10).split('-');return new Date(Number(p[0]),Number(p[1])-1,Number(p[2]))}
function firstOfMonth(d){return new Date(d.getFullYear(),d.getMonth(),1)}function lastOfMonth(d){return new Date(d.getFullYear(),d.getMonth()+1,0)}
function addDays(d,n){var x=new Date(d);x.setDate(x.getDate()+n);return x}
function startOfWeek(d){var x=new Date(d),start=Number(meta.settings.week_starts_on||0),diff=(x.getDay()-start+7)%7;return addDays(x,-diff)}
function userName(id){var u=meta.users.find(function(x){return Number(x.id)===Number(id)});return u?(u.name||((u.first_name||'')+' '+(u.last_name||'')).trim()):''}
function clientName(id){var c=meta.clients.find(function(x){return Number(x.id)===Number(id)});return c?c.name:''}
function visible(i){if(filters.type.length&&filters.type.indexOf(i.type)<0)return false;if(filters.status.length){var st=i.confirmed?'confirmed':i.status;if(filters.status.indexOf(st)<0&&filters.status.indexOf(i.status)<0)return false}if(filters.team.length){var ids=(i.assignee_ids||[]).map(String),ok=false;filters.team.forEach(function(f){if(f==='unassigned'&&!ids.length)ok=true;else if(f.indexOf('user:')===0&&ids.indexOf(f.slice(5))>=0)ok=true;else if(f.indexOf('crew:')===0){var c=meta.crews.find(function(x){return String(x.id)===f.slice(5)});if(c&&(c.member_ids||[]).some(function(id){return ids.indexOf(String(id))>=0}))ok=true}});if(!ok)return false}return true}
function range(){if(view==='month'){var f=firstOfMonth(anchor),s=startOfWeek(f),e=addDays(s,41);return{from:ymd(s),to:ymd(e)}}if(view==='week'){var s=startOfWeek(anchor);return{from:ymd(s),to:ymd(addDays(s,6))}}return{from:ymd(anchor),to:ymd(anchor)}}
function load(){var r=range();return Promise.all([req({action:'range',from:r.from,to:r.to}),req({action:'unscheduled'})]).then(function(a){items=a[0].items||[];unscheduled=a[1].items||[];E('unscheduledCount').textContent=unscheduled.length;E('drawerUnscheduledCount').textContent=unscheduled.length;render();renderUnscheduled()}).catch(function(e){toast('error',e.message)})}
function title(){if(view==='month')return anchor.toLocaleDateString(undefined,{month:'long',year:'numeric'});if(view==='week'){var s=startOfWeek(anchor),e=addDays(s,6);return s.toLocaleDateString(undefined,{month:'short',day:'numeric'})+' – '+e.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'})}return anchor.toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric',year:'numeric'})}
function itemHtml(i){var tm=(i.assignee_ids||[]).map(userName).filter(Boolean).join(', '),time=i.anytime?'Any time':String(i.start||'').slice(11,16);return '<div class="fd-card '+esc(i.type)+' '+(i.status==='completed'?'completed ':'')+(selected[i.key]?'selected':'')+'" draggable="true" data-key="'+esc(i.key)+'" title="'+esc(i.title)+'"><strong>'+esc(time)+'</strong> '+esc(i.title)+(tm?' · '+esc(tm):'')+'</div>'}
function renderMonth(){var box=E('monthView');box.innerHTML='';var names=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],startDay=Number(meta.settings.week_starts_on||0),showW=Number(meta.settings.show_weekends||1)===1;var order=[];for(var n=0;n<7;n++)order.push(names[(startDay+n)%7]);if(!showW)order=order.filter(function(x){return x!=='Sat'&&x!=='Sun'});box.style.gridTemplateColumns='repeat('+order.length+',minmax(0,1fr))';order.forEach(function(n){box.insertAdjacentHTML('beforeend','<div class="fd-dayname">'+n+'</div>')});var f=firstOfMonth(anchor),s=startOfWeek(f),today=ymd(new Date());for(var k=0;k<42;k++){var d=addDays(s,k),dow=d.getDay();if(!showW&&(dow===0||dow===6))continue;var ds=ymd(d),dayItems=items.filter(function(i){return visible(i)&&String(i.start||'').slice(0,10)===ds}),same=d.getMonth()===anchor.getMonth(),cards=dayItems.slice(0,4).map(itemHtml).join(''),more=dayItems.length>4?'<div class="fd-more" data-more-day="'+ds+'">+'+(dayItems.length-4)+' more</div>':'';box.insertAdjacentHTML('beforeend','<div class="fd-cell '+(!same?'other ':'')+(ds===today?'today':'')+'" data-date="'+ds+'"><div class="fd-cell-head"><span class="fd-date">'+d.getDate()+'</span>'+(dayItems.length?'<span class="fd-count">'+dayItems.length+' '+(dayItems.length===1?'item':'items')+'</span>':'')+'</div>'+cards+more+'</div>')}}
function hourList(){var s=parseInt(String(meta.settings.workday_start||'08:00').slice(0,2),10),e=parseInt(String(meta.settings.workday_end||'18:00').slice(0,2),10),a=[];for(var h=s;h<=e;h++)a.push(h);return a}
function renderWeek(){var box=E('weekGrid'),s=startOfWeek(anchor),showW=Number(meta.settings.show_weekends||1)===1,days=[];for(var i=0;i<7;i++){var d=addDays(s,i);if(showW||![0,6].includes(d.getDay()))days.push(d)}box.style.gridTemplateColumns='72px repeat('+days.length+',minmax(130px,1fr))';box.innerHTML='<div class="fd-week-head"></div>'+days.map(function(d){return '<div class="fd-week-head">'+d.toLocaleDateString(undefined,{weekday:'short'})+'<br><strong>'+d.getDate()+'</strong></div>'}).join('');hourList().forEach(function(h){box.insertAdjacentHTML('beforeend','<div class="fd-time-label">'+String(h).padStart(2,'0')+':00</div>');days.forEach(function(d){var ds=ymd(d),its=items.filter(function(x){return visible(x)&&String(x.start||'').slice(0,10)===ds&&parseInt(String(x.start||'').slice(11,13)||0,10)===h});box.insertAdjacentHTML('beforeend','<div class="fd-slot" data-date="'+ds+'" data-time="'+String(h).padStart(2,'0')+':00">'+its.map(itemHtml).join('')+'</div>')})})}
function renderDay(){var box=E('dayAgenda'),ds=ymd(anchor),its=items.filter(function(i){return visible(i)&&String(i.start||'').slice(0,10)===ds}).sort(function(a,b){return String(a.start).localeCompare(String(b.start))});if(!its.length){box.innerHTML='<div class="fd-empty">No appointments scheduled for this day.</div>';return}box.innerHTML=its.map(function(i){var time=i.anytime?'Any time':String(i.start||'').slice(11,16);return '<div class="fd-agenda-row"><div class="fd-agenda-time">'+esc(time)+'</div><div class="fd-agenda-card">'+itemHtml(i)+'</div></div>'}).join('')}
function render(){E('periodTitle').textContent=title();E('monthView').style.display=view==='month'?'grid':'none';E('weekView').style.display=view==='week'?'block':'none';E('dayView').style.display=view==='day'?'block':'none';document.querySelectorAll('#viewSegment button').forEach(function(b){b.classList.toggle('active',b.dataset.view===view)});if(view==='month')renderMonth();else if(view==='week')renderWeek();else renderDay();updateBulk()}
function filterLabels(){['type','team','status'].forEach(function(k){var x=filters[k];E(k+'Label').textContent=x.length?(x.length+' selected'):'All'})}
function renderFilters(){var types=[['visit','Visits'],['request','Requests'],['task','Tasks'],['event','Events'],['reminder','Reminders']],status=[['completed','Completed'],['overdue','Overdue'],['upcoming','Upcoming'],['confirmed','Confirmed by client']];E('typeOptions').innerHTML=types.map(function(x){return '<label class="fd-filter-option"><span class="fd-status-dot '+x[0]+'"></span><input type="checkbox" data-filter="type" value="'+x[0]+'"> '+x[1]+'</label>'}).join('');E('statusOptions').innerHTML=status.map(function(x){return '<label class="fd-filter-option"><input type="checkbox" data-filter="status" value="'+x[0]+'"> '+x[1]+'</label>'}).join('');var team=meta.users.map(function(u){return ['user:'+u.id,u.name]}).concat(meta.crews.map(function(c){return ['crew:'+c.id,'Crew: '+c.name]}),[['unassigned','Unassigned']]);E('teamOptions').innerHTML=team.map(function(x){return '<label class="fd-filter-option"><input type="checkbox" data-filter="team" value="'+x[0]+'"> '+esc(x[1])+'</label>'}).join('');filterLabels()}
function renderUnscheduled(){var a=unscheduled.slice(),sort=E('unscheduledSort').value;if(sort==='newest')a.reverse();else if(sort==='az')a.sort(function(x,y){return String(x.client_name||x.title).localeCompare(String(y.client_name||y.title))});else if(sort==='za')a.sort(function(x,y){return String(y.client_name||y.title).localeCompare(String(x.client_name||x.title))});E('unscheduledList').innerHTML=a.length?a.map(function(i){return '<div class="fd-list-card" draggable="true" data-unscheduled-key="'+esc(i.key)+'"><strong><span class="fd-status-dot '+i.type+'"></span> '+esc(i.title)+'</strong><small>'+esc(i.type.charAt(0).toUpperCase()+i.type.slice(1))+'</small></div>'}).join(''):'<div class="fd-empty" style="padding:35px 10px">No unscheduled appointments.</div>'}
function openDrawer(id){document.querySelectorAll('.fd-drawer').forEach(function(x){x.classList.remove('show')});E(id).classList.add('show')}function closeDrawers(){document.querySelectorAll('.fd-drawer').forEach(function(x){x.classList.remove('show')})}
function showDetail(i){detail=i;E('detailTitle').textContent=i.title;var names=(i.assignee_ids||[]).map(userName).filter(Boolean).join(', ')||'Unassigned';E('detailBody').innerHTML='<label class="fd-check"><input type="checkbox" id="detailComplete" '+(i.status==='completed'?'checked':'')+' '+(i.type!=='visit'?'disabled':'')+'> Completed</label><hr style="border:0;border-top:1px solid #edf0f3"><div class="fd-field"><label>Type</label><div>'+esc(i.type.charAt(0).toUpperCase()+i.type.slice(1))+'</div></div>'+(i.job_no?'<div class="fd-field"><label>Job</label><div>'+esc(i.job_no)+'</div></div>':'')+(i.client_name?'<div class="fd-field"><label>Client</label><div>'+esc(i.client_name)+'</div></div>':'')+'<div class="fd-field"><label>Team</label><div>'+esc(names)+'</div></div>'+(i.address?'<div class="fd-field"><label>Location</label><div>'+esc(i.address)+'</div></div>':'')+'<div class="fd-field"><label>Start</label><div>'+esc(i.anytime?String(i.start).slice(0,10)+' · Anytime':String(i.start).replace(' ',' · '))+'</div></div>'+(i.instructions?'<div class="fd-field"><label>Instructions</label><div>'+esc(i.instructions)+'</div></div>':'');openDrawer('detailDrawer');setTimeout(function(){var c=E('detailComplete');if(c&&!c.disabled)c.onchange=function(){req({action:'complete_visit',id:i.id,completed:this.checked?1:0}).then(function(d){toast('success',d.message);load()}).catch(function(e){toast('error',e.message)})}},0)}
function resetQuickOptional(){['instructionsSection','lineItemWrap','assignSection'].forEach(function(id){var x=E(id);if(x)x.hidden=true});['addInstructionsBtn','addLineItemBtn','addAssignBtn'].forEach(function(id){var x=E(id);if(x)x.hidden=false});if(E('quickDescription'))E('quickDescription').value='';if(E('quickLineItem'))E('quickLineItem').value='';if(E('quickAssignees'))Array.prototype.forEach.call(E('quickAssignees').options,function(o){o.selected=false})}
function toggleQuickOptional(sectionId,buttonId,show){var sec=E(sectionId),btn=E(buttonId);if(!sec||!btn)return;sec.hidden=!show;btn.hidden=!!show}
function renderQuickClientResults(term){var box=E('quickClientResults');if(!box)return;term=String(term||'').trim().toLowerCase();var rows=[];(meta.clients||[]).forEach(function(c){var props=(meta.properties||[]).filter(function(p){return Number(p.client_id)===Number(c.id)});var clientText=[c.name,c.company_name,c.phone,c.email].filter(Boolean).join(' ').toLowerCase();if(!term||clientText.indexOf(term)>=0)rows.push({client_id:c.id,property_id:'',title:c.name||('Client '+c.id),sub:c.company_name||c.phone||'Client'});props.forEach(function(p){var txt=[c.name,p.name,p.address_line1,p.city,p.state,p.postal_code].filter(Boolean).join(' ').toLowerCase();if(!term||txt.indexOf(term)>=0)rows.push({client_id:c.id,property_id:p.id,title:c.name||('Client '+c.id),sub:[p.name,p.address_line1,p.city].filter(Boolean).join(' · ')||'Property'})})});rows=rows.slice(0,12);box.innerHTML=rows.map(function(r){return '<button type="button" class="fd-client-result" data-client-id="'+r.client_id+'" data-property-id="'+r.property_id+'"><span><strong>'+esc(r.title)+'</strong><small>'+esc(r.sub)+'</small></span></button>'}).join('')+'<button type="button" class="fd-client-result fd-client-create" data-create-client="1"><i class="bi bi-plus-lg"></i><span>Create client or address</span></button>';box.classList.add('show')}
function selectQuickClient(clientId,propertyId){var c=(meta.clients||[]).find(function(x){return Number(x.id)===Number(clientId)});E('quickClient').value=clientId||'';updateProperties();if(propertyId)E('quickProperty').value=String(propertyId);E('quickPropertyWrap').hidden=!clientId;var p=(meta.properties||[]).find(function(x){return Number(x.id)===Number(propertyId)});E('quickClientSearch').value=c?((c.name||'')+(p?' · '+(p.name||p.address_line1||'Property'):'')):'';E('quickClientResults').classList.remove('show')}
function inlineClientError(message){var box=E('inlineClientCreateError');if(!box)return;box.textContent=message||'';box.classList.toggle('show',!!message)}
function populateInlineClientMeta(){var countries=clientMeta.countries||[],sources=clientMeta.lead_sources||[];E('clientCreateCountry').innerHTML='<option value="">Select country</option>'+countries.map(function(c){return '<option value="'+Number(c.id)+'">'+esc(c.name||c.country_name||'')+'</option>'}).join('');E('clientCreateSourceList').innerHTML=sources.map(function(x){return '<option value="'+esc(x.source_name||x.name||'')+'"></option>'}).join('');var india=countries.find(function(c){return String(c.name||c.country_name||'').toLowerCase()==='india'});if(india)E('clientCreateCountry').value=String(india.id)}
function loadInlineClientMeta(){if(clientMetaLoaded){populateInlineClientMeta();return Promise.resolve(clientMeta)}return clientReq({action:'meta'}).then(function(d){clientMeta=d.meta||{};clientMetaLoaded=true;populateInlineClientMeta();return clientMeta})}
function resetInlineClientForm(){['clientCreateFirstName','clientCreateLastName','clientCreateCompany','clientCreatePhone','clientCreateEmail','clientCreateSource','clientCreateStreet1','clientCreateStreet2','clientCreateCity','clientCreateState','clientCreatePostal'].forEach(function(id){E(id).value=''});inlineClientError('');if(clientMetaLoaded)populateInlineClientMeta()}
function openInlineClientModal(){E('quickClientResults').classList.remove('show');resetInlineClientForm();E('quickClientCreateModal').classList.add('show');setTimeout(function(){E('clientCreateFirstName').focus()},0);loadInlineClientMeta().catch(function(e){inlineClientError(e.message)})}
function buildInlineLocation(){var street1=E('clientCreateStreet1').value.trim(),street2=E('clientCreateStreet2').value.trim(),city=E('clientCreateCity').value.trim(),state=E('clientCreateState').value.trim(),postal=E('clientCreatePostal').value.trim(),country=E('clientCreateCountry').value;var has=[street1,street2,city,state,postal,country].some(function(v){return String(v||'').trim()!==''});if(!has)return null;if(!street1)throw new Error('Street 1 is required when adding a property address.');return{id:0,location_type:'other',name:'',address_line1:street1,address_line2:street2,city:city,state:state,postal_code:postal,country_id:country,tax_rate_id:'',billing_same_as_property:1,billing_address_line1:'',billing_address_line2:'',billing_city:'',billing_state:'',billing_postal_code:'',billing_country_id:'',latitude:'',longitude:'',contact_name:'',contact_phone:'',gate_code:'',access_notes:'',service_instructions:'',is_primary:1,status:'active',custom_values:{},contacts:[],_delete:0}}
function saveInlineClient(){var first=E('clientCreateFirstName').value.trim(),last=E('clientCreateLastName').value.trim(),company=E('clientCreateCompany').value.trim(),email=E('clientCreateEmail').value.trim(),phone=E('clientCreatePhone').value.trim(),display=[first,last].filter(Boolean).join(' ').trim()||company;if(!display){inlineClientError('Enter a first name, last name, or company name.');return}if(email&&!E('clientCreateEmail').checkValidity()){inlineClientError('Enter a valid email address.');return}var locationRow;try{locationRow=buildInlineLocation()}catch(e){inlineClientError(e.message);return}inlineClientError('');var phoneRows=phone?[{id:0,phone_number:phone,phone_type:'main',receives_messages:1,is_primary:1,sort_order:1,_delete:0}]:[];var payload={action:'save',client_id:0,display_name:display,title_prefix:'',first_name:first,last_name:last,company_name:company,phone:phone,email:email,source:E('clientCreateSource').value.trim(),client_type:'client',status:'active',branch_id:'',account_manager_id:'',alternate_phone:'',preferred_contact_method:'email',tax_number:'',allow_email:1,allow_sms:1,notes:'',communication_json:JSON.stringify({quote_followups:1,invoice_followups:1,visit_reminders:1,job_close_followups:1}),customer_custom_values_json:'{}',client_contacts_json:'[]',phone_numbers_json:JSON.stringify(phoneRows),locations_json:JSON.stringify(locationRow?[locationRow]:[])};var btn=E('saveInlineClient');btn.disabled=true;btn.textContent='Creating...';clientReq(payload).then(function(d){var newId=Number(d.client_id||0);if(!newId)throw new Error('Client was created but no client ID was returned.');return req({action:'meta'}).then(function(fresh){meta.clients=fresh.clients||meta.clients;meta.properties=fresh.properties||meta.properties;var created=(meta.clients||[]).find(function(c){return Number(c.id)===newId});var props=(meta.properties||[]).filter(function(p){return Number(p.client_id)===newId});var preferred=props.find(function(p){return Number(p.is_primary||0)===1})||props[0]||null;selectQuickClient(newId,preferred?preferred.id:'');E('quickClientCreateModal').classList.remove('show');toast('success',d.message||'Client created successfully.');if(created&&!E('quickClientSearch').value)E('quickClientSearch').value=created.name||display})}).catch(function(e){inlineClientError(e.message)}).finally(function(){btn.disabled=false;btn.textContent='Create client'})}

function openQuick(date,time){quickType='job';document.querySelectorAll('#quickTabs button').forEach(function(b){b.classList.toggle('active',b.dataset.qtype==='job')});E('quickDate').value=date||ymd(new Date());E('quickEndDate').value=E('quickDate').value;E('quickTime').value=time||'09:00';E('quickTitle').value='';E('quickClient').value='';E('quickClientSearch').value='';E('quickProperty').innerHTML='<option value="">Select property</option>';E('quickPropertyWrap').hidden=true;resetQuickOptional();E('quickScheduleLater').checked=false;E('quickAnytime').checked=!time;syncQuickType();E('quickModal').classList.add('show')}
function syncQuickType(){var clientNeeded=quickType!=='event';E('commonClient').style.display=clientNeeded?'block':'none';var allowLine=quickType==='job'||quickType==='request';E('addLineItemBtn').style.display=allowLine?'inline-flex':'none';if(!allowLine)E('lineItemWrap').hidden=true;E('repeatWrap').style.display=quickType==='event'?'block':'none';E('quickEndDateWrap').style.display=quickType==='event'?'block':'none';E('quickHeading').textContent='New '+quickType.charAt(0).toUpperCase()+quickType.slice(1)}
function saveQuick(){var title=E('quickTitle').value.trim();if(!title){toast('warning','Enter a title.');return}var ids=Array.prototype.map.call(E('quickAssignees').selectedOptions,function(o){return Number(o.value)}),base={title:title,instructions:E('quickDescription').value,description:E('quickDescription').value,client_id:E('quickClient').value,property_id:E('quickProperty').value,assignee_ids:ids,schedule_later:E('quickScheduleLater').checked?1:0,anytime:E('quickAnytime').checked?1:0,date:E('quickDate').value,time:E('quickTime').value,duration_minutes:Math.round(Number(E('quickDuration').value||1)*60)};if(quickType==='job')base.action='quick_job';else if(quickType==='request')base.action='quick_request';else if(quickType==='task')base.action='save_task';else{base.action='save_event';base.item_type='event';base.end_date=E('quickEndDate').value||base.date;var end=new Date('2000-01-01T'+(base.time||'09:00')+':00');end.setMinutes(end.getMinutes()+base.duration_minutes);base.end_time=String(end.getHours()).padStart(2,'0')+':'+String(end.getMinutes()).padStart(2,'0');base.recurrence_json=JSON.stringify({repeat:E('quickRepeats').value})}E('quickSave').disabled=true;req(base).then(function(d){E('quickModal').classList.remove('show');toast('success',d.message);load()}).catch(function(e){toast('error',e.message)}).finally(function(){E('quickSave').disabled=false})}
function populateMeta(){E('quickLineItem').innerHTML='<option value="">Select line item</option>'+meta.catalog.map(function(x){return '<option value="'+x.id+'">'+esc(x.name)+'</option>'}).join('');var users=meta.users.map(function(u){return '<option value="'+u.id+'">'+esc(u.name)+'</option>'}).join('');E('quickAssignees').innerHTML=users;E('bulkAssignees').innerHTML=users;E('crewLeader').innerHTML='<option value="">No leader</option>'+users;E('crewMembers').innerHTML=users;E('findUser').innerHTML='<option value="">Select team member</option>'+users;E('findCrew').innerHTML='<option value="">Select crew</option>'+meta.crews.map(function(c){return '<option value="'+c.id+'">'+esc(c.name)+'</option>'}).join('');renderFilters();applySettingsToForm();renderCrews()}
function updateProperties(){var cid=Number(E('quickClient').value||0),rows=meta.properties.filter(function(p){return Number(p.client_id)===cid});E('quickProperty').innerHTML='<option value="">Select property</option>'+rows.map(function(p){return '<option value="'+p.id+'">'+esc(p.name||p.address_line1||('Property '+p.id))+'</option>'}).join('')}
function renderCrews(){E('crewsList').innerHTML=meta.crews.length?meta.crews.map(function(c){return '<div class="fd-list-card"><strong>'+esc(c.name)+'</strong><small>'+(c.member_ids||[]).map(userName).filter(Boolean).join(', ')+'</small></div>'}).join(''):'<div class="fd-empty"><i class="bi bi-people" style="font-size:30px"></i><div style="margin-top:8px;font-weight:700">No crews yet</div><div style="margin-top:5px">Group teammates into crews to schedule them together.</div></div>'}
function saveCrew(){var name=E('crewName').value.trim();if(!name){toast('warning','Enter a crew name.');return}var ids=Array.prototype.map.call(E('crewMembers').selectedOptions,function(o){return Number(o.value)});req({action:'save_crew',name:name,leader_user_id:E('crewLeader').value,member_ids:ids}).then(function(d){toast('success',d.message);E('crewModal').classList.remove('show');return req({action:'crew_list'})}).then(function(d){meta.crews=d.crews||[];populateMeta();openDrawer('crewsDrawer')}).catch(function(e){toast('error',e.message)})}
function applySettingsToForm(){var s=meta.settings||{};E('settingView').value=s.default_view||'month';var r=document.querySelector('input[name="layout"][value="'+(s.appointment_layout||'stacked')+'"]');if(r)r.checked=true;E('settingWeekends').checked=Number(s.show_weekends||0)===1;E('settingStart').value=String(s.workday_start||'08:00').slice(0,5);E('settingEnd').value=String(s.workday_end||'18:00').slice(0,5);E('settingSlot').value=String(s.slot_minutes||30);E('settingDuration').value=String(s.default_duration_minutes||60);E('settingTimezone').value=s.timezone||'Asia/Kolkata';E('quickDuration').value=(Number(s.default_duration_minutes||60)/60)}
function saveSettings(){var layout=document.querySelector('input[name="layout"]:checked').value;req({action:'save_settings',default_view:E('settingView').value,appointment_layout:layout,show_weekends:E('settingWeekends').checked?1:0,week_starts_on:0,workday_start:E('settingStart').value,workday_end:E('settingEnd').value,slot_minutes:E('settingSlot').value,default_duration_minutes:E('settingDuration').value,timezone:E('settingTimezone').value}).then(function(d){meta.settings.default_view=E('settingView').value;meta.settings.appointment_layout=layout;meta.settings.show_weekends=E('settingWeekends').checked?1:0;meta.settings.workday_start=E('settingStart').value;meta.settings.workday_end=E('settingEnd').value;meta.settings.slot_minutes=E('settingSlot').value;meta.settings.default_duration_minutes=E('settingDuration').value;meta.settings.timezone=E('settingTimezone').value;E('settingsModal').classList.remove('show');toast('success',d.message);render()}).catch(function(e){toast('error',e.message)})}
function updateBulk(){var count=Object.keys(selected).length;E('bulkBarCount').textContent=count+' selected';E('bulkCount').textContent=count+' appointment'+(count===1?'':'s')+' selected';E('bulkConfirm').disabled=count===0;E('bulkBar').classList.toggle('show',bulkMode);}
function enterBulk(){bulkMode=true;selected={};updateBulk();openDrawer('bulkDrawer');render()}
function bulkApply(){var list=Object.keys(selected).map(function(k){var a=k.split(':');return{type:a[0],id:Number(a[1])}}),mode=E('bulkMode').value;req({action:'bulk_update',items:list,specific_date:mode==='specific'?E('bulkDate').value:'',shift_days:mode==='shift'?E('bulkShift').value:0,assignee_ids:Array.prototype.map.call(E('bulkAssignees').selectedOptions,function(o){return Number(o.value)}),do_reassign:E('bulkAssignees').selectedOptions.length?1:0}).then(function(d){toast('success',d.message);bulkMode=false;selected={};closeDrawers();load()}).catch(function(e){toast('error',e.message)})}
function findTime(){var uid=E('findUser').value,crew=E('findCrew').value;if(!uid&&!crew){toast('warning','Select a team member or crew.');return}E('findResults').innerHTML='Searching...';req({action:'availability',user_id:uid,crew_id:crew,date:E('findDate').value,days:E('findDays').value,duration_minutes:E('findDuration').value}).then(function(d){E('findResults').innerHTML=(d.slots||[]).length?d.slots.map(function(s){return '<div class="fd-find-slot"><span>'+esc(new Date(s.start.replace(' ','T')).toLocaleString([], {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}))+'</span><button class="fd-btn" data-use-slot="'+esc(s.start)+'">Use</button></div>'}).join(''):'<div class="fd-empty" style="padding:30px 10px">No open times found.</div>'}).catch(function(e){toast('error',e.message)})}
function openFind(){E('findDate').value=ymd(anchor);E('findResults').innerHTML='';openDrawer('findDrawer')}
function dragSchedule(key,date,time){var a=key.split(':');if(a[0]!=='visit'){toast('warning','Drag scheduling is currently enabled for Visits. Edit Tasks/Events from their details.');return}req({action:'schedule_visit',id:a[1],date:date,time:time||'09:00',duration_minutes:meta.settings.default_duration_minutes||60,anytime:time?0:1}).then(function(d){toast('success',d.message);load()}).catch(function(e){toast('error',e.message)})}
function unschedule(key){var a=key.split(':');if(a[0]!=='visit'){toast('warning','Only Visits can be moved to Unscheduled from drag/drop.');return}req({action:'unschedule_visit',id:a[1]}).then(function(d){toast('success',d.message);load()}).catch(function(e){toast('error',e.message)})}

E('prevBtn').onclick=function(){if(view==='month')anchor.setMonth(anchor.getMonth()-1);else if(view==='week')anchor=addDays(anchor,-7);else anchor=addDays(anchor,-1);load()};E('nextBtn').onclick=function(){if(view==='month')anchor.setMonth(anchor.getMonth()+1);else if(view==='week')anchor=addDays(anchor,7);else anchor=addDays(anchor,1);load()};E('todayBtn').onclick=function(){anchor=new Date();load()};document.querySelectorAll('#viewSegment button').forEach(function(b){b.onclick=function(){view=this.dataset.view;history.replaceState(null,'','?view='+view+'&date='+ymd(anchor));load()}});
E('unscheduledBtn').onclick=function(){openDrawer('unscheduledDrawer')};E('unscheduledSort').onchange=renderUnscheduled;E('findTimeBtn').onclick=openFind;E('mapBtn').onclick=function(){toast('warning','Map view is the integration point for your existing GPS/location module.')};E('moreBtn').onclick=function(e){e.stopPropagation();E('moreMenu').classList.toggle('show')};document.addEventListener('click',function(e){if(!e.target.closest('.fd-relative'))document.querySelectorAll('.fd-popover,.fd-filter-menu').forEach(function(x){x.classList.remove('show')})});
document.querySelectorAll('[data-more]').forEach(function(b){b.onclick=function(){E('moreMenu').classList.remove('show');var a=this.dataset.more;if(a==='bulk')enterBulk();else if(a==='newvisit')openQuick(ymd(anchor));else if(a==='crews')openDrawer('crewsDrawer');else if(a==='settings')E('settingsModal').classList.add('show')}});
document.querySelectorAll('[data-close-drawer]').forEach(function(b){b.onclick=function(){E(this.dataset.closeDrawer).classList.remove('show')}});document.querySelectorAll('[data-close-modal]').forEach(function(b){b.onclick=function(){E(this.dataset.closeModal).classList.remove('show')}});document.querySelectorAll('.fd-modal').forEach(function(m){m.addEventListener('mousedown',function(e){if(e.target===m)m.classList.remove('show')})});
document.querySelectorAll('[data-filter-btn]').forEach(function(b){b.onclick=function(e){e.stopPropagation();var id=this.dataset.filterBtn+'Menu';document.querySelectorAll('.fd-filter-menu').forEach(function(x){if(x.id!==id)x.classList.remove('show')});E(id).classList.toggle('show')}});document.addEventListener('change',function(e){if(e.target.matches('[data-filter]')){var k=e.target.dataset.filter,v=e.target.value,ix=filters[k].indexOf(v);if(e.target.checked&&ix<0)filters[k].push(v);if(!e.target.checked&&ix>=0)filters[k].splice(ix,1);filterLabels();render()}});document.querySelectorAll('[data-select-all]').forEach(function(b){b.onclick=function(){var k=this.dataset.selectAll,arr=[];E(k+'Options').querySelectorAll('input').forEach(function(x){x.checked=true;arr.push(x.value)});filters[k]=arr;filterLabels();render()}});document.querySelectorAll('[data-clear]').forEach(function(b){b.onclick=function(){var k=this.dataset.clear;E(k+'Options').querySelectorAll('input').forEach(function(x){x.checked=false});filters[k]=[];filterLabels();render()}});document.querySelectorAll('[data-filter-search]').forEach(function(i){i.oninput=function(){var q=this.value.toLowerCase(),box=E(this.dataset.filterSearch+'Options');box.querySelectorAll('.fd-filter-option').forEach(function(r){r.style.display=r.textContent.toLowerCase().indexOf(q)>=0?'flex':'none'})}});
E('calendarWrap').addEventListener('click',function(e){var card=e.target.closest('[data-key]');if(card){var key=card.dataset.key;if(bulkMode){selected[key]=!selected[key];if(!selected[key])delete selected[key];render();return}var i=items.find(function(x){return x.key===key});if(i)showDetail(i);return}var cell=e.target.closest('[data-date]');if(cell&&!e.target.closest('.fd-more'))openQuick(cell.dataset.date,cell.dataset.time||'')});E('calendarWrap').addEventListener('dragstart',function(e){var c=e.target.closest('[data-key]');if(c)e.dataTransfer.setData('text/plain',c.dataset.key)});E('calendarWrap').addEventListener('dragover',function(e){if(e.target.closest('[data-date]'))e.preventDefault()});E('calendarWrap').addEventListener('drop',function(e){var c=e.target.closest('[data-date]');if(!c)return;e.preventDefault();dragSchedule(e.dataTransfer.getData('text/plain'),c.dataset.date,c.dataset.time||'')});E('unscheduledList').addEventListener('dragstart',function(e){var c=e.target.closest('[data-unscheduled-key]');if(c)e.dataTransfer.setData('text/plain',c.dataset.unscheduledKey)});E('unscheduleDropzone').addEventListener('dragover',function(e){e.preventDefault()});E('unscheduleDropzone').addEventListener('drop',function(e){e.preventDefault();unschedule(e.dataTransfer.getData('text/plain'))});
E('quickTabs').onclick=function(e){var b=e.target.closest('[data-qtype]');if(!b)return;quickType=b.dataset.qtype;this.querySelectorAll('button').forEach(function(x){x.classList.toggle('active',x===b)});syncQuickType()};E('addInstructionsBtn').onclick=function(){toggleQuickOptional('instructionsSection','addInstructionsBtn',true);E('quickDescription').focus()};E('addLineItemBtn').onclick=function(){toggleQuickOptional('lineItemWrap','addLineItemBtn',true);E('quickLineItem').focus()};E('addAssignBtn').onclick=function(){toggleQuickOptional('assignSection','addAssignBtn',true);E('quickAssignees').focus()};document.querySelectorAll('[data-hide-optional]').forEach(function(b){b.onclick=function(){var id=this.dataset.hideOptional,map={instructionsSection:'addInstructionsBtn',lineItemWrap:'addLineItemBtn',assignSection:'addAssignBtn'};toggleQuickOptional(id,map[id],false)}});E('quickClientSearch').onfocus=function(){renderQuickClientResults(this.value)};E('quickClientSearch').oninput=function(){E('quickClient').value='';E('quickPropertyWrap').hidden=true;renderQuickClientResults(this.value)};E('quickClientResults').onclick=function(e){var create=e.target.closest('[data-create-client]');if(create){openInlineClientModal();return}var row=e.target.closest('[data-client-id]');if(row)selectQuickClient(row.dataset.clientId,row.dataset.propertyId)};document.addEventListener('click',function(e){if(!e.target.closest('.fd-client-search-wrap')&&E('quickClientResults'))E('quickClientResults').classList.remove('show')});E('quickScheduleLater').onchange=function(){E('quickScheduleFields').style.opacity=this.checked?'.45':'1';E('quickScheduleFields').querySelectorAll('input,select').forEach(function(x){if(x.id!=='quickScheduleLater')x.disabled=E('quickScheduleLater').checked})};E('quickAnytime').onchange=function(){E('quickTime').disabled=this.checked};E('quickSave').onclick=saveQuick;E('moreOptionsBtn').onclick=function(){if(quickType==='job')location.href='job-add.php?client_id='+encodeURIComponent(E('quickClient').value)+'&location_id='+encodeURIComponent(E('quickProperty').value);else if(quickType==='request')location.href='request-add.php?client_id='+encodeURIComponent(E('quickClient').value);else toast('warning','All available options are already shown here.')};
E('saveInlineClient').onclick=saveInlineClient;E('addCrewBtn').onclick=function(){E('crewName').value='';Array.prototype.forEach.call(E('crewMembers').options,function(o){o.selected=false});E('crewModal').classList.add('show')};E('saveCrew').onclick=saveCrew;E('saveSettings').onclick=saveSettings;E('bulkMode').onchange=function(){E('bulkDateWrap').style.display=this.value==='specific'?'block':'none';E('bulkShiftWrap').style.display=this.value==='shift'?'block':'none'};E('shiftMinus').onclick=function(){E('bulkShift').value=Number(E('bulkShift').value||0)-1};E('shiftPlus').onclick=function(){E('bulkShift').value=Number(E('bulkShift').value||0)+1};E('bulkConfirm').onclick=bulkApply;E('openBulk').onclick=function(){openDrawer('bulkDrawer')};E('cancelBulk').onclick=function(){bulkMode=false;selected={};updateBulk();render()};E('findSearch').onclick=findTime;E('findResults').onclick=function(e){var b=e.target.closest('[data-use-slot]');if(!b)return;var s=b.dataset.useSlot;E('quickDate').value=s.slice(0,10);E('quickTime').value=s.slice(11,16);closeDrawers();openQuick(s.slice(0,10),s.slice(11,16))};E('detailFindTime').onclick=openFind;E('detailEdit').onclick=function(){if(!detail)return;if(detail.type==='visit')location.href='visit-edit.php?visit_id='+detail.id;else toast('warning','Use the related module to edit this item.')};E('detailOpen').onclick=function(){if(!detail)return;if(detail.type==='visit'&&detail.job_id)location.href='job-view.php?job_id='+detail.job_id;else if(detail.type==='request')location.href='request-view.php?request_id='+detail.id;else toast('warning','Details page is not configured for this item type.')};
var q=new URLSearchParams(location.search);if(q.get('view')&&['month','week','day'].indexOf(q.get('view'))>=0)view=q.get('view');if(q.get('date')&&/^\d{4}-\d{2}-\d{2}$/.test(q.get('date')))anchor=parseDate(q.get('date'));
req({action:'meta'}).then(function(d){meta=d;meta.users=(meta.users||[]).map(function(u){u.name=u.name||((u.first_name||'')+' '+(u.last_name||'')).trim();return u});if(!q.get('view'))view=(meta.settings||{}).default_view||'month';populateMeta();return load()}).catch(function(e){toast('error',e.message)});
})();
</script>
</body>
</html>
