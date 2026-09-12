<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Clients';
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
    <title>Clients - FieldPlx</title>
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




    /* ==========================================================
       Clients Manage - Jobber style / Add Invoice UI
       Version 4.0.0
       ========================================================== */
    :root {
        --cm-green: #2f8d22;
        --cm-green-dark: #26751c;
        --cm-green-soft: #eaf4e6;
        --cm-navy: #00263a;
        --cm-text: #183445;
        --cm-muted: #647787;
        --cm-border: #dce3e7;
        --cm-pill: #eceae6;
        --cm-bg: #ffffff;
        --cm-danger: #e24234;
    }

    .fieldplx-topbar {
        position: fixed !important;
        top: 0 !important;
        right: 0 !important;
        z-index: 1030 !important;
    }

    .fieldplx-main-content {
        padding-top: var(--fieldplx-topbar-height);
    }

    .fd-dashboard.cm-page {
        max-width: 1600px;
        padding: 18px 20px 34px;
        background: #fff;
    }

    .cm-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 25px;
    }

    .cm-title {
        margin: 0;
        color: var(--cm-navy);
        font-size: 30px;
        line-height: 1.1;
        font-weight: 800;
        letter-spacing: -0.7px;
    }

    .cm-header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .cm-btn {
        min-height: 40px;
        padding: 0 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border: 1px solid var(--cm-border);
        border-radius: 8px;
        background: #fff;
        color: var(--cm-text);
        font-size: 12px;
        font-weight: 700;
        text-decoration: none !important;
        cursor: pointer;
        transition: .15s ease;
        white-space: nowrap;
    }

    .cm-btn:hover,
    .cm-btn:focus {
        border-color: #b8cfac;
        color: var(--cm-green-dark);
        background: #fbfdf9;
        outline: none;
    }

    .cm-btn.primary {
        border-color: var(--cm-green);
        background: var(--cm-green);
        color: #fff;
    }

    .cm-btn.primary:hover {
        border-color: var(--cm-green-dark);
        background: var(--cm-green-dark);
        color: #fff;
    }

    .cm-btn.danger {
        border-color: var(--cm-danger);
        background: var(--cm-danger);
        color: #fff;
    }

    .cm-btn:disabled {
        opacity: .55;
        cursor: not-allowed;
    }

    .cm-more-wrap {
        position: relative;
        z-index: 100;
    }

    .cm-menu {
        width: 210px;
        position: absolute;
        top: calc(100% + 6px);
        right: 0;
        z-index: 150;
        display: none;
        padding: 6px;
        border: 1px solid var(--cm-border);
        border-radius: 9px;
        background: #fff;
        box-shadow: 0 14px 36px rgba(0, 38, 58, .14);
    }

    .cm-more-wrap.open .cm-menu {
        display: block;
    }

    .cm-menu-item {
        width: 100%;
        min-height: 36px;
        padding: 8px 10px;
        display: flex;
        align-items: center;
        gap: 9px;
        border: 0;
        border-radius: 6px;
        background: transparent;
        color: var(--cm-text) !important;
        font-size: 11px;
        text-align: left;
        text-decoration: none !important;
        cursor: pointer;
    }

    .cm-menu-item:hover {
        background: #f5f4f1;
        color: var(--cm-navy) !important;
    }

    .cm-menu-item.danger {
        color: var(--cm-danger) !important;
    }

    .cm-cards {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr)) minmax(330px, 1.32fr);
        gap: 8px;
        margin-bottom: 18px;
    }

    .cm-card {
        min-height: 145px;
        position: relative;
        padding: 16px 17px;
        border: 1px solid var(--cm-border);
        border-radius: 8px;
        background: #fff;
        overflow: visible;
    }

    .cm-card-title {
        margin: 0;
        color: var(--cm-navy);
        font-size: 14px;
        font-weight: 700;
    }

    .cm-card-period {
        margin-top: 4px;
        color: var(--cm-muted);
        font-size: 10.5px;
    }

    .cm-card-arrow {
        position: absolute;
        top: 17px;
        right: 16px;
        color: var(--cm-navy);
        font-size: 13px;
    }

    .cm-stat-row {
        position: absolute;
        left: 17px;
        bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .cm-stat-value {
        color: var(--cm-navy);
        font-size: 26px;
        line-height: 1;
        font-weight: 800;
    }

    .cm-trend {
        position: relative;
        padding: 4px 8px;
        border-radius: 999px;
        color: #26751c;
        background: var(--cm-green-soft);
        font-size: 10px;
        font-weight: 600;
        cursor: help;
    }

    .cm-trend.down {
        color: #b53f36;
        background: #fff0ef;
    }

    .cm-trend.neutral {
        color: #687987;
        background: #eef2f4;
    }

    .cm-trend-tooltip {
        width: 180px;
        position: absolute;
        left: 50%;
        bottom: calc(100% + 9px);
        z-index: 1000;
        padding: 10px 11px;
        border: 1px solid var(--cm-border);
        border-radius: 8px;
        background: #fff;
        color: var(--cm-text);
        box-shadow: 0 10px 28px rgba(0, 38, 58, .16);
        opacity: 0;
        visibility: hidden;
        transform: translate(-50%, 4px);
        transition: .14s ease;
        pointer-events: none;
    }

    .cm-trend-tooltip::after {
        position: absolute;
        top: 100%;
        left: 50%;
        margin-left: -5px;
        border: 5px solid transparent;
        border-top-color: #fff;
        content: "";
    }

    .cm-trend:hover .cm-trend-tooltip,
    .cm-trend:focus .cm-trend-tooltip {
        opacity: 1;
        visibility: visible;
        transform: translate(-50%, 0);
    }

    .cm-tooltip-title {
        display: block;
        margin-bottom: 4px;
        color: var(--cm-muted);
        font-weight: 600;
    }

    .cm-tooltip-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        line-height: 1.6;
    }

    .cm-merge-card {
        min-height: 145px;
        display: flex;
        align-items: stretch;
        padding: 0;
        overflow: hidden;
    }

    .cm-merge-copy {
        min-width: 0;
        flex: 1;
        padding: 16px 14px 14px 16px;
    }

    .cm-merge-copy p {
        max-width: 220px;
        margin: 0 0 16px;
        color: #52697a;
        font-size: 12px;
        line-height: 1.28;
    }

    .cm-merge-art {
        width: 122px;
        min-width: 122px;
        position: relative;
        background: linear-gradient(160deg, #f0f2f1, #fbfbfa);
    }

    .cm-merge-art::before,
    .cm-merge-art::after {
        position: absolute;
        left: 18px;
        right: 12px;
        height: 42px;
        border-radius: 4px;
        border: 1px solid #dde2e1;
        background: #fff;
        box-shadow: 0 3px 8px rgba(0,0,0,.04);
        content: "";
    }

    .cm-merge-art::before { top: 18px; transform: rotate(-3deg); }
    .cm-merge-art::after { bottom: 14px; transform: rotate(2deg); }

    .cm-section-title-row {
        display: flex;
        align-items: baseline;
        gap: 8px;
        margin: 12px 0 17px;
    }

    .cm-section-title-row h2 {
        margin: 0;
        color: var(--cm-navy);
        font-size: 18px;
        font-weight: 800;
    }

    .cm-result-count {
        color: var(--cm-muted);
        font-size: 11px;
    }

    .cm-toolbar {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 13px;
        position: relative;
        z-index: 40;
    }

    .cm-filter-wrap {
        position: relative;
    }

    .cm-filter-pill {
        min-height: 36px;
        padding: 0 13px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: 1px solid var(--cm-border);
        border-radius: 999px;
        background: #fff;
        color: var(--cm-text);
        font-size: 11px;
        cursor: pointer;
    }

    .cm-filter-pill.status {
        border-color: var(--cm-pill);
        background: var(--cm-pill);
    }

    .cm-filter-pill strong {
        font-weight: 700;
    }

    .cm-filter-dropdown {
        width: 250px;
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        z-index: 90;
        display: none;
        padding: 6px;
        border: 1px solid var(--cm-border);
        border-radius: 8px;
        background: #fff;
        box-shadow: 0 12px 28px rgba(0, 38, 58, .14);
    }

    .cm-filter-wrap.open .cm-filter-dropdown {
        display: block;
    }

    .cm-filter-option {
        width: 100%;
        min-height: 34px;
        padding: 7px 9px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        border: 0;
        border-radius: 6px;
        background: transparent;
        color: var(--cm-text);
        font-size: 11px;
        text-align: left;
        cursor: pointer;
    }

    .cm-filter-option:hover,
    .cm-filter-option.active {
        background: #f4f3ef;
    }

    .cm-toolbar-spacer { margin-left: auto; }

    .cm-search {
        width: 255px;
        position: relative;
    }

    .cm-search i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #5b7484;
        font-size: 14px;
    }

    .cm-search input {
        width: 100%;
        height: 40px;
        padding: 8px 12px 8px 40px;
        border: 1px solid var(--cm-border);
        border-radius: 8px;
        background: #fff;
        color: var(--cm-text);
        font-size: 11px;
        outline: none;
    }

    .cm-search input:focus {
        border-color: #7ca96c;
        box-shadow: 0 0 0 2px rgba(47,141,34,.10);
    }

    .cm-table-wrap {
        width: 100%;
        overflow: visible;
    }

    .cm-table {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
    }

    .cm-table th,
    .cm-table td {
        border-bottom: 1px solid var(--cm-border);
        color: var(--cm-text);
        font-size: 11px;
        text-align: left;
        vertical-align: middle;
    }

    .cm-table th {
        height: 42px;
        padding: 8px 8px;
        font-weight: 500;
        color: #4f6675;
    }

    .cm-table td {
        min-height: 50px;
        padding: 10px 8px;
    }

    .cm-col-check { width: 36px; }
    .cm-col-name { width: 26%; }
    .cm-col-address { width: 28%; }
    .cm-col-tags { width: 21%; }
    .cm-col-status { width: 12%; }
    .cm-col-last { width: 13%; }

    .cm-sort-button {
        padding: 0;
        border: 0;
        background: transparent;
        color: inherit;
        font: inherit;
        cursor: pointer;
    }

    .cm-sort-button i { margin-left: 3px; color: #94a4ad; font-size: 10px; }

    .cm-checkbox {
        width: 17px;
        height: 17px;
        border-radius: 4px;
        accent-color: var(--cm-green);
    }

    .cm-row {
        position: relative;
        transition: background .12s ease;
        cursor: pointer;
    }

    .cm-row:hover {
        background: #f4f2ed;
    }

    .cm-name {
        color: var(--cm-navy);
        font-weight: 700;
        text-decoration: none !important;
    }

    .cm-address {
        display: -webkit-box;
        overflow: hidden;
        color: #405c6d;
        line-height: 1.4;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }

    .cm-tags {
        display: flex;
        align-items: center;
        gap: 5px;
        flex-wrap: wrap;
    }

    .cm-tag {
        max-width: 105px;
        padding: 4px 9px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        background: #edf1f2;
        color: #173c50;
        font-size: 10px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .cm-tag-more {
        color: var(--cm-muted);
        font-size: 10px;
    }

    .cm-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 9px;
        border-radius: 999px;
        color: #2c6f22;
        background: #e8f2e5;
        font-size: 10px;
    }

    .cm-status::before {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #36a225;
        content: "";
    }

    .cm-status.new {
        color: #786714;
        background: #f5f0cf;
    }

    .cm-status.new::before { background: #c6a915; }
    .cm-status.inactive { color: #687987; background: #edf1f3; }
    .cm-status.inactive::before { background: #72818c; }
    .cm-status.archived { color: #6c7480; background: #eceeef; }
    .cm-status.archived::before { background: #89939a; }

    .cm-last-cell {
        position: relative;
        min-height: 30px;
        display: flex;
        align-items: center;
    }

    .cm-row-actions {
        position: absolute;
        top: 50%;
        right: 0;
        display: flex;
        align-items: center;
        border: 1px solid var(--cm-border);
        border-radius: 7px;
        background: #fff;
        box-shadow: 0 4px 10px rgba(0,38,58,.08);
        opacity: 0;
        visibility: hidden;
        transform: translateY(-50%);
        transition: .12s ease;
        overflow: hidden;
    }

    .cm-row:hover .cm-row-actions,
    .cm-row:focus-within .cm-row-actions {
        opacity: 1;
        visibility: visible;
    }

    .cm-icon-btn {
        width: 38px;
        height: 34px;
        display: grid;
        place-items: center;
        border: 0;
        border-right: 1px solid #eef1f2;
        background: #fff;
        color: #173c50;
        font-size: 15px;
        text-decoration: none !important;
        cursor: pointer;
    }

    .cm-icon-btn:last-child { border-right: 0; }
    .cm-icon-btn:hover { background: #f4f3ef; color: var(--cm-navy); }
    .cm-icon-btn.disabled { opacity: .35; pointer-events: none; }

    .cm-row-menu {
        width: 162px;
        position: fixed;
        z-index: 25000;
        display: none;
        padding: 5px;
        border: 1px solid var(--cm-border);
        border-radius: 8px;
        background: #fff;
        box-shadow: 0 14px 32px rgba(0, 38, 58, .16);
    }

    .cm-row-menu.show { display: block; }

    .cm-empty {
        padding: 34px 15px !important;
        text-align: center !important;
        color: var(--cm-muted) !important;
    }

    .cm-pagination {
        min-height: 48px;
        display: none;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-top: 12px;
        color: var(--cm-muted);
        font-size: 10px;
    }

    .cm-pagination.show { display: flex; }
    .cm-pagination-actions { display: flex; gap: 6px; }

    .cm-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 26000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
        background: rgba(0, 18, 28, .38);
    }

    .cm-modal-backdrop.show { display: flex; }

    .cm-modal {
        width: min(610px, 100%);
        border: 1px solid var(--cm-border);
        border-radius: 10px;
        background: #fff;
        box-shadow: 0 22px 55px rgba(0, 38, 58, .22);
    }

    .cm-modal.small { width: min(470px, 100%); }

    .cm-modal-head {
        min-height: 66px;
        padding: 16px 22px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
    }

    .cm-modal-head h3 {
        margin: 0;
        color: var(--cm-navy);
        font-size: 20px;
        line-height: 1.2;
        font-weight: 800;
    }

    .cm-close {
        width: 32px;
        height: 32px;
        display: grid;
        place-items: center;
        border: 0;
        background: transparent;
        color: var(--cm-text);
        font-size: 19px;
        cursor: pointer;
    }

    .cm-modal-body { padding: 0 22px 20px; }
    .cm-modal-footer {
        padding: 14px 22px 18px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }

    .cm-tag-editor-top {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 8px;
    }

    .cm-select-tags-btn {
        min-height: 36px;
        padding: 0 13px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: 1px solid var(--cm-border);
        border-radius: 999px;
        background: #fff;
        color: var(--cm-text);
        font-size: 11px;
        cursor: pointer;
    }

    .cm-selected-tag {
        min-height: 36px;
        padding: 0 8px 0 13px;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        border-radius: 999px;
        background: var(--cm-pill);
        color: var(--cm-text);
        font-size: 11px;
    }

    .cm-selected-tag button {
        width: 23px;
        height: 23px;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 50%;
        background: #fff;
        color: #6b7e88;
        cursor: pointer;
    }

    .cm-tag-picker {
        width: 245px;
        position: absolute;
        z-index: 27000;
        display: none;
        border: 1px solid var(--cm-border);
        border-radius: 8px;
        background: #fff;
        box-shadow: 0 12px 28px rgba(0,38,58,.16);
        overflow: hidden;
    }

    .cm-tag-picker.show { display: block; }

    .cm-tag-search {
        width: 100%;
        height: 48px;
        padding: 0 14px;
        border: 0;
        border-bottom: 1px solid var(--cm-border);
        outline: 0;
        font-size: 11px;
    }

    .cm-tag-picker-meta {
        min-height: 44px;
        padding: 0 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border-bottom: 1px solid var(--cm-border);
        color: var(--cm-navy);
        font-size: 10px;
        font-weight: 700;
    }

    .cm-clear-link,
    .cm-create-tag-link {
        padding: 0;
        border: 0;
        background: transparent;
        color: var(--cm-green-dark);
        font-size: 10px;
        font-weight: 700;
        text-decoration: underline;
        cursor: pointer;
    }

    .cm-tag-list { max-height: 185px; overflow-y: auto; }
    .cm-tag-option {
        width: 100%;
        min-height: 40px;
        padding: 8px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border: 0;
        background: #fff;
        color: var(--cm-text);
        font-size: 11px;
        text-align: left;
        cursor: pointer;
    }
    .cm-tag-option:hover { background: #f5f4f1; }
    .cm-tag-option i { visibility: hidden; }
    .cm-tag-option.selected i { visibility: visible; }
    .cm-tag-picker-create { padding: 10px 14px; border-top: 1px solid var(--cm-border); }

    .cm-confirm-copy {
        margin: 0;
        color: #415b6c;
        font-size: 12px;
        line-height: 1.55;
    }

    .cm-toast {
        width: min(360px, calc(100vw - 28px));
        position: fixed;
        top: 82px;
        right: 18px;
        z-index: 30000;
        padding: 11px 13px;
        display: flex;
        align-items: center;
        gap: 9px;
        border-radius: 8px;
        color: #fff;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-8px);
        transition: .16s ease;
        box-shadow: 0 12px 30px rgba(0,38,58,.18);
    }

    .cm-toast.show { opacity: 1; visibility: visible; transform: translateY(0); }
    .cm-toast.success { background: #2f8d22; }
    .cm-toast.error { background: #cf4a43; }
    .cm-toast.warning { background: #9b7b17; }
    .cm-toast.info { background: #173c50; }
    .cm-toast span { flex: 1; font-size: 11px; }
    .cm-toast button { border: 0; background: transparent; color: #fff; }

    @media (max-width: 1199.98px) {
        .cm-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 991.98px) {
        .fieldplx-main-content { padding-top: var(--fieldplx-topbar-height); }
        .cm-col-address { width: 31%; }
        .cm-col-tags { width: 18%; }
    }

    @media (max-width: 767.98px) {
        .fieldplx-main-content { padding-top: 64px; }
        .fd-dashboard.cm-page { padding: 16px 13px 28px; }
        .cm-header { align-items: flex-start; }
        .cm-title { font-size: 26px; }
        .cm-cards { grid-template-columns: 1fr; }
        .cm-toolbar { flex-wrap: wrap; }
        .cm-toolbar-spacer { display: none; }
        .cm-search { width: 100%; order: -1; }
        .cm-table thead { display: none; }
        .cm-table,
        .cm-table tbody,
        .cm-table tr,
        .cm-table td { display: block; width: 100% !important; }
        .cm-table tr { padding: 12px 40px 12px 12px; border-bottom: 1px solid var(--cm-border); }
        .cm-table td { min-height: auto; padding: 4px 0; border: 0; }
        .cm-table td.cm-check-cell { position: absolute; right: 12px; top: 12px; width: auto !important; }
        .cm-address { -webkit-line-clamp: 3; }
        .cm-last-cell { min-height: 34px; }
        .cm-row-actions { right: auto; left: 0; top: 100%; transform: none; }
        .cm-row:hover .cm-row-actions,
        .cm-row:focus-within .cm-row-actions { position: static; margin-top: 6px; display: inline-flex; transform: none; }
    }

    @media (max-width: 520px) {
        .cm-header { flex-direction: column; }
        .cm-header-actions { width: 100%; }
        .cm-header-actions > * { flex: 1; }
        .cm-header-actions .cm-btn { width: 100%; }
        .cm-modal-head h3 { font-size: 17px; }
        .cm-modal-head, .cm-modal-body, .cm-modal-footer { padding-left: 16px; padding-right: 16px; }
    }



    /* ==========================================================
       Clients manage v4.1.0 - bulk selection + email composer
       ========================================================== */
    .cm-selection-bar {
        min-height: 48px;
        display: none;
        align-items: center;
        gap: 14px;
        padding: 5px 8px;
        border-bottom: 1px solid var(--cm-border);
        color: var(--cm-text);
        background: #fff;
    }

    .cm-selection-bar.show { display: flex; }
    .cm-selection-bar .cm-checkbox { flex: 0 0 auto; }
    .cm-selection-count { font-size: 11px; font-weight: 700; white-space: nowrap; }
    .cm-selection-clear {
        padding: 0;
        border: 0;
        background: transparent;
        color: var(--cm-green-dark);
        font-size: 11px;
        font-weight: 700;
        text-decoration: underline;
        cursor: pointer;
    }
    .cm-selection-icon {
        width: 34px;
        height: 34px;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: #173c50;
        font-size: 17px;
        cursor: pointer;
    }
    .cm-selection-icon:hover { background: #f4f3ef; }
    .cm-selection-icon.danger:hover { color: #cf4a43; background: #fff1ef; }
    .cm-table-wrap.selection-active .cm-table thead { display: none; }
    .cm-row.is-selected { background: #fbfcf8; }

    .cm-email-modal {
        width: min(930px, calc(100vw - 34px));
        max-height: calc(100vh - 34px);
        overflow: auto;
        border: 1px solid var(--cm-border);
        border-radius: 10px;
        background: #fff;
        box-shadow: 0 24px 64px rgba(0, 38, 58, .24);
    }
    .cm-email-head {
        min-height: 72px;
        padding: 18px 24px 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
    }
    .cm-email-head h3 {
        margin: 0;
        color: var(--cm-navy);
        font-size: 21px;
        line-height: 1.2;
        font-weight: 800;
    }
    .cm-email-body {
        padding: 8px 24px 18px;
        display: grid;
        grid-template-columns: minmax(0, 1fr) 315px;
        gap: 24px;
    }
    .cm-email-left { min-width: 0; }
    .cm-email-to {
        min-height: 58px;
        display: grid;
        grid-template-columns: 28px minmax(0, 1fr) 28px;
        align-items: center;
        gap: 7px;
        padding: 7px 10px;
        border: 1px solid var(--cm-border);
        border-radius: 8px;
        background: #fff;
    }
    .cm-email-to-label { color: #526c7c; font-size: 11px; }
    .cm-email-chip-wrap { display: flex; flex-wrap: wrap; gap: 6px; min-width: 0; }
    .cm-email-chip {
        max-width: 100%;
        min-height: 35px;
        padding: 0 12px;
        display: inline-flex;
        align-items: center;
        gap: 9px;
        border: 1px solid var(--cm-border);
        border-radius: 999px;
        color: #315367;
        background: #fff;
        font-size: 11px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .cm-email-more { color: #173c50; text-align: center; font-size: 18px; }
    .cm-email-field {
        margin-top: 10px;
        position: relative;
    }
    .cm-email-field label {
        position: absolute;
        top: 7px;
        left: 13px;
        z-index: 2;
        color: #67808f;
        font-size: 10px;
        pointer-events: none;
    }
    .cm-email-field input,
    .cm-email-field textarea {
        width: 100%;
        border: 1px solid var(--cm-border);
        border-radius: 8px;
        color: var(--cm-text);
        background: #fff;
        outline: 0;
        font: inherit;
        font-size: 11px;
    }
    .cm-email-field input {
        height: 50px;
        padding: 20px 13px 7px;
    }
    .cm-email-field textarea {
        min-height: 270px;
        padding: 25px 13px 11px;
        resize: vertical;
        line-height: 1.55;
    }
    .cm-email-field input:focus,
    .cm-email-field textarea:focus,
    .cm-email-drop:focus-within {
        border-color: #7ca96c;
        box-shadow: 0 0 0 2px rgba(47,141,34,.10);
    }
    .cm-email-helper {
        margin-top: 5px;
        color: #687f8e;
        font-size: 9px;
        line-height: 1.4;
    }
    .cm-email-attachments h4 {
        margin: 3px 0 14px;
        color: var(--cm-navy);
        font-size: 13px;
        font-weight: 800;
    }
    .cm-email-drop {
        min-height: 90px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px;
        border: 1px dashed #d8e0e4;
        border-radius: 8px;
        color: #607986;
        background: #fff;
        text-align: center;
        cursor: pointer;
    }
    .cm-email-drop.dragover { border-color: #7ca96c; background: #f8fbf5; }
    .cm-email-select {
        min-height: 32px;
        padding: 0 13px;
        border: 1px solid var(--cm-border);
        border-radius: 7px;
        color: var(--cm-green-dark);
        background: #fff;
        font-size: 10px;
        font-weight: 700;
        cursor: pointer;
    }
    .cm-email-drop small { font-size: 9px; }
    .cm-email-size-text { margin-top: 10px; color: #5f7785; font-size: 9px; }
    .cm-email-progress { height: 7px; margin-top: 5px; border-radius: 999px; overflow: hidden; background: #dedcd4; }
    .cm-email-progress > span { width: 0; height: 100%; display: block; background: var(--cm-green); transition: width .15s ease; }
    .cm-email-file-list { margin-top: 10px; display: grid; gap: 6px; }
    .cm-email-file {
        min-height: 40px;
        padding: 7px 8px;
        display: grid;
        grid-template-columns: 1fr auto;
        align-items: center;
        gap: 8px;
        border: 1px solid var(--cm-border);
        border-radius: 7px;
        color: #405c6d;
        background: #fff;
        font-size: 9px;
    }
    .cm-email-file strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 9.5px; }
    .cm-email-file small { color: #83939d; font-size: 8px; }
    .cm-email-file button { width: 26px; height: 26px; border: 0; border-radius: 6px; background: transparent; color: #687f8e; cursor: pointer; }
    .cm-email-file button:hover { color: #cf4a43; background: #fff1ef; }
    .cm-email-footer {
        padding: 0 24px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
    }
    .cm-email-copy {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #405c6d;
        font-size: 11px;
    }
    .cm-email-copy input { width: 17px; height: 17px; accent-color: var(--cm-green); }
    .cm-email-actions { display: flex; gap: 8px; }

    @media (max-width: 760px) {
        .cm-email-modal { width: min(620px, calc(100vw - 22px)); }
        .cm-email-body { grid-template-columns: 1fr; padding-left: 16px; padding-right: 16px; gap: 16px; }
        .cm-email-head { padding-left: 16px; padding-right: 16px; }
        .cm-email-footer { padding-left: 16px; padding-right: 16px; flex-direction: column; align-items: stretch; }
        .cm-email-actions { justify-content: flex-end; }
        .cm-email-field textarea { min-height: 210px; }
    }

</style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/nav.php'; ?>
    <div class="fieldplx-main-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="fieldplx-main-content">
            <div class="fieldplx-content-wrapper">
                <div class="fd-dashboard cm-page">
                    <section class="cm-header">
                        <h1 class="cm-title">Clients</h1>
                        <div class="cm-header-actions">
                            <a class="cm-btn primary" href="client-form.php">New Client</a>
                            <div class="cm-more-wrap" id="cmMoreWrap">
                                <button type="button" class="cm-btn" id="cmMoreButton" aria-expanded="false"><i class="bi bi-three-dots"></i> More Actions</button>
                                <div class="cm-menu" id="cmMoreMenu" aria-hidden="true">
                                    <a class="cm-menu-item" href="customer-import.php"><i class="bi bi-upload"></i> Import Clients</a>
                                    <button type="button" class="cm-menu-item" id="cmExportButton"><i class="bi bi-download"></i> Export Clients</button>
                                    <a class="cm-menu-item" href="client-merge.php"><i class="bi bi-intersect"></i> Merge Clients</a>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="cm-cards">
                        <article class="cm-card">
                            <span class="cm-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                            <h2 class="cm-card-title">New leads</h2>
                            <div class="cm-card-period">Past 30 days</div>
                            <div class="cm-stat-row">
                                <strong class="cm-stat-value" id="cmNewLeads">0</strong>
                                <span class="cm-trend neutral" id="cmLeadTrend" tabindex="0">- 0%
                                    <span class="cm-trend-tooltip" id="cmLeadTooltip"></span>
                                </span>
                            </div>
                        </article>
                        <article class="cm-card">
                            <span class="cm-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                            <h2 class="cm-card-title">New clients</h2>
                            <div class="cm-card-period">Past 30 days</div>
                            <div class="cm-stat-row">
                                <strong class="cm-stat-value" id="cmNewClients">0</strong>
                                <span class="cm-trend neutral" id="cmClientTrend" tabindex="0">- 0%
                                    <span class="cm-trend-tooltip" id="cmClientTooltip"></span>
                                </span>
                            </div>
                        </article>
                        <article class="cm-card">
                            <span class="cm-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                            <h2 class="cm-card-title">Total new clients</h2>
                            <div class="cm-card-period">Year to date</div>
                            <div class="cm-stat-row">
                                <strong class="cm-stat-value" id="cmClientsYtd">0</strong>
                            </div>
                        </article>
                        <article class="cm-card cm-merge-card">
                            <div class="cm-merge-copy">
                                <p>Merge duplicate clients into a single profile to keep information accurate</p>
                                <a class="cm-btn" href="client-merge.php">Merge Clients</a>
                            </div>
                            <div class="cm-merge-art" aria-hidden="true"></div>
                        </article>
                    </section>

                    <section class="cm-section-title-row">
                        <h2>Filtered clients</h2>
                        <span class="cm-result-count" id="cmResultCount">(0 results)</span>
                    </section>

                    <section class="cm-toolbar">
                        <div class="cm-filter-wrap" id="cmTagFilterWrap">
                            <button type="button" class="cm-filter-pill" id="cmTagFilterButton"><span id="cmTagFilterLabel">Filter by tag</span><i class="bi bi-plus-lg"></i></button>
                            <div class="cm-filter-dropdown" id="cmTagFilterMenu"></div>
                        </div>
                        <div class="cm-filter-wrap" id="cmStatusFilterWrap">
                            <button type="button" class="cm-filter-pill status" id="cmStatusFilterButton"><strong>Status</strong><span>|</span><span id="cmStatusFilterLabel">Leads and Active</span></button>
                            <div class="cm-filter-dropdown" id="cmStatusFilterMenu">
                                <button class="cm-filter-option active" type="button" data-scope="leads_active">Leads and Active <i class="bi bi-check-lg"></i></button>
                                <button class="cm-filter-option" type="button" data-scope="all">All clients <i class="bi bi-check-lg"></i></button>
                                <button class="cm-filter-option" type="button" data-scope="leads">Leads only <i class="bi bi-check-lg"></i></button>
                                <button class="cm-filter-option" type="button" data-scope="active">Active <i class="bi bi-check-lg"></i></button>
                                <button class="cm-filter-option" type="button" data-scope="inactive">Inactive <i class="bi bi-check-lg"></i></button>
                                <button class="cm-filter-option" type="button" data-scope="archived">Archived <i class="bi bi-check-lg"></i></button>
                            </div>
                        </div>
                        <div class="cm-toolbar-spacer"></div>
                        <div class="cm-search"><i class="bi bi-search"></i><input type="search" id="cmSearch" placeholder="Search clients..." autocomplete="off"></div>
                    </section>

                    <section class="cm-selection-bar" id="cmSelectionBar" aria-hidden="true">
                        <input class="cm-checkbox" type="checkbox" id="cmBulkSelectAll" checked aria-label="Select all visible clients">
                        <strong class="cm-selection-count" id="cmSelectionCount">0 selected</strong>
                        <button type="button" class="cm-selection-clear" id="cmDeselectAll">Deselect All</button>
                        <button type="button" class="cm-selection-icon" id="cmBulkTagButton" title="Tag selected clients" aria-label="Tag selected clients"><i class="bi bi-tag"></i></button>
                        <button type="button" class="cm-selection-icon danger" id="cmBulkDeleteButton" title="Delete selected clients" aria-label="Delete selected clients"><i class="bi bi-trash"></i></button>
                    </section>

                    <section class="cm-table-wrap" id="cmTableWrap">
                        <table class="cm-table">
                            <thead>
                                <tr>
                                    <th class="cm-col-check"><input class="cm-checkbox" type="checkbox" id="cmSelectAll" aria-label="Select all clients"></th>
                                    <th class="cm-col-name"><button type="button" class="cm-sort-button" data-sort="name">Name <i class="bi bi-chevron-expand"></i></button></th>
                                    <th class="cm-col-address">Address</th>
                                    <th class="cm-col-tags">Tags</th>
                                    <th class="cm-col-status">Status</th>
                                    <th class="cm-col-last"><button type="button" class="cm-sort-button" data-sort="last_activity">Last Activity <i class="bi bi-chevron-expand"></i></button></th>
                                </tr>
                            </thead>
                            <tbody id="cmTableBody">
                                <tr><td colspan="6" class="cm-empty">Loading clients...</td></tr>
                            </tbody>
                        </table>
                    </section>

                    <div class="cm-pagination" id="cmPagination">
                        <span id="cmPaginationText"></span>
                        <div class="cm-pagination-actions">
                            <button type="button" class="cm-btn" id="cmPrevPage"><i class="bi bi-chevron-left"></i></button>
                            <button type="button" class="cm-btn" id="cmNextPage"><i class="bi bi-chevron-right"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="cm-row-menu" id="cmRowMenu" aria-hidden="true">
        <button type="button" class="cm-menu-item" data-row-action="archive"><i class="bi bi-archive"></i> Archive</button>
        <button type="button" class="cm-menu-item danger" data-row-action="delete"><i class="bi bi-trash"></i> Delete</button>
        <a class="cm-menu-item" id="cmOpenNewTab" href="#" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Open in New Tab</a>
    </div>

    <div class="cm-modal-backdrop" id="cmTagsModal" aria-hidden="true">
        <section class="cm-modal" role="dialog" aria-modal="true" aria-labelledby="cmTagsModalTitle">
            <div class="cm-modal-head">
                <h3 id="cmTagsModalTitle">Edit tags</h3>
                <button type="button" class="cm-close" id="cmTagsClose"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="cm-modal-body">
                <div class="cm-tag-editor-top" id="cmSelectedTags"></div>
                <div class="cm-tag-picker" id="cmTagPicker">
                    <input type="search" class="cm-tag-search" id="cmTagSearch" placeholder="Search tags" autocomplete="off">
                    <div class="cm-tag-picker-meta"><span id="cmTagSelectedCount">0 selected</span><button type="button" class="cm-clear-link" id="cmClearTags">Clear</button></div>
                    <div class="cm-tag-list" id="cmTagList"></div>
                    <div class="cm-tag-picker-create"><button type="button" class="cm-create-tag-link" id="cmCreateTag">Create new tag</button></div>
                </div>
            </div>
            <div class="cm-modal-footer">
                <button type="button" class="cm-btn" id="cmTagsCancel">Cancel</button>
                <button type="button" class="cm-btn primary" id="cmTagsSave">Save</button>
            </div>
        </section>
    </div>

    <div class="cm-modal-backdrop" id="cmConfirmModal" aria-hidden="true">
        <section class="cm-modal small" role="dialog" aria-modal="true">
            <div class="cm-modal-head">
                <h3 id="cmConfirmTitle">Confirm</h3>
                <button type="button" class="cm-close" id="cmConfirmClose"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="cm-modal-body"><p class="cm-confirm-copy" id="cmConfirmCopy"></p></div>
            <div class="cm-modal-footer">
                <button type="button" class="cm-btn" id="cmConfirmCancel">Cancel</button>
                <button type="button" class="cm-btn danger" id="cmConfirmAction">Confirm</button>
            </div>
        </section>
    </div>

    <div class="cm-modal-backdrop" id="cmEmailModal" aria-hidden="true">
        <section class="cm-email-modal" role="dialog" aria-modal="true" aria-labelledby="cmEmailTitle">
            <div class="cm-email-head">
                <h3 id="cmEmailTitle">Send email</h3>
                <button type="button" class="cm-close" id="cmEmailClose" aria-label="Close email"><i class="bi bi-x-lg"></i></button>
            </div>
            <form id="cmEmailForm" enctype="multipart/form-data">
                <div class="cm-email-body">
                    <div class="cm-email-left">
                        <div class="cm-email-to">
                            <span class="cm-email-to-label">To</span>
                            <div class="cm-email-chip-wrap" id="cmEmailTo"></div>
                            <span class="cm-email-more"><i class="bi bi-three-dots"></i></span>
                        </div>
                        <div class="cm-email-field">
                            <label for="cmEmailSubject">Subject</label>
                            <input type="text" id="cmEmailSubject" maxlength="250" autocomplete="off">
                        </div>
                        <div class="cm-email-field">
                            <label for="cmEmailMessage">Message</label>
                            <textarea id="cmEmailMessage" maxlength="20000"></textarea>
                        </div>
                        <div class="cm-email-helper">Your client will receive this message at the email address shown above.</div>
                    </div>
                    <aside class="cm-email-attachments">
                        <h4>Attachments</h4>
                        <div class="cm-email-drop" id="cmEmailDrop" tabindex="0">
                            <button type="button" class="cm-email-select" id="cmEmailSelect">Select</button>
                            <small>Select or drag files here to upload</small>
                            <input type="file" id="cmEmailFiles" multiple hidden>
                        </div>
                        <div class="cm-email-size-text" id="cmEmailSizeText">You've attached 0.00 MB of the 10.00 MB limit.</div>
                        <div class="cm-email-progress"><span id="cmEmailProgress"></span></div>
                        <div class="cm-email-file-list" id="cmEmailFileList"></div>
                    </aside>
                </div>
                <div class="cm-email-footer">
                    <label class="cm-email-copy"><input type="checkbox" id="cmEmailCopy"> Send me a copy</label>
                    <div class="cm-email-actions">
                        <button type="button" class="cm-btn" id="cmEmailCancel">Cancel</button>
                        <button type="submit" class="cm-btn primary" id="cmEmailSend">Send Email</button>
                    </div>
                </div>
            </form>
        </section>
    </div>

    <div class="cm-toast info" id="cmToast"><span id="cmToastMessage">Notification</span><button type="button" id="cmToastClose"><i class="bi bi-x-lg"></i></button></div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function () {
        'use strict';
        var csrfToken = <?= json_encode($clientsCsrfToken) ?>;
        var API_URL = 'api/client-manage.php';
        var state = {
            page: 1,
            perPage: 25,
            search: '',
            statusScope: 'leads_active',
            tagId: 0,
            sort: 'name',
            direction: 'asc',
            tags: [],
            rows: [],
            activeRowId: 0,
            activeRowName: '',
            tagClientId: 0,
            tagClientName: '',
            selectedTags: [],
            confirmAction: '',
            confirmClientId: 0,
            confirmClientName: '',
            confirmClientIds: [],
            selectedClientIds: [],
            tagMode: 'single',
            tagClientIds: [],
            emailClientId: 0,
            emailClientName: '',
            emailClientEmail: '',
            emailFiles: []
        };

        var tableBody = document.getElementById('cmTableBody');
        var rowMenu = document.getElementById('cmRowMenu');
        var tagModal = document.getElementById('cmTagsModal');
        var tagPicker = document.getElementById('cmTagPicker');
        var confirmModal = document.getElementById('cmConfirmModal');
        var emailModal = document.getElementById('cmEmailModal');
        var selectionBar = document.getElementById('cmSelectionBar');
        var tableWrap = document.getElementById('cmTableWrap');
        var toast = document.getElementById('cmToast');
        var toastMessage = document.getElementById('cmToastMessage');
        var toastTimer = null;
        var searchTimer = null;

        function esc(v) {
            return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function parseResponse(r) {
            return r.text().then(function (raw) {
                var text = (raw || '').trim(), data;
                try { data = text ? JSON.parse(text) : {}; }
                catch (e) {
                    text = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                    throw new Error(text || 'Invalid server response.');
                }
                if (!r.ok || !data.success) throw new Error(data.message || 'Request failed.');
                return data;
            });
        }

        function request(payload) {
            payload.append('csrf_token', csrfToken);
            return fetch(API_URL, {
                method: 'POST',
                body: payload,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            }).then(parseResponse);
        }

        function showToast(type, message) {
            if (toastTimer) clearTimeout(toastTimer);
            toast.className = 'cm-toast ' + (type || 'info') + ' show';
            toastMessage.textContent = message || 'Notification';
            toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 3300);
        }

        function titleCase(v) {
            v = String(v || '').replace(/_/g, ' ');
            return v.replace(/\b\w/g, function (m) { return m.toUpperCase(); });
        }

        function addressOf(row) {
            return [row.address_line1, row.address_line2, row.city, row.state, row.postal_code].filter(function (v) {
                return String(v || '').trim() !== '';
            }).join(', ');
        }

        function relativeDate(value) {
            if (!value) return '-';
            var d = new Date(String(value).replace(' ', 'T'));
            if (isNaN(d.getTime())) return esc(value);
            var now = new Date();
            var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            var day = new Date(d.getFullYear(), d.getMonth(), d.getDate());
            var diff = Math.round((today - day) / 86400000);
            if (diff === 0) return 'Today';
            if (diff === 1) return 'Yesterday';
            if (diff >= 0 && diff < 7) return d.toLocaleDateString(undefined, { weekday: 'short' });
            return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        }

        function trendClass(value) {
            value = Number(value || 0);
            return value > 0 ? '' : (value < 0 ? ' down' : ' neutral');
        }

        function trendText(value) {
            value = Number(value || 0);
            var arrow = value > 0 ? '↑ ' : (value < 0 ? '↓ ' : '- ');
            var abs = Math.abs(value);
            var shown = Math.round(abs * 10) / 10;
            return arrow + shown + '%';
        }

        function applyStats(stats) {
            stats = stats || {};
            document.getElementById('cmNewLeads').textContent = Number(stats.new_leads_30 || 0);
            document.getElementById('cmNewClients').textContent = Number(stats.new_clients_30 || 0);
            document.getElementById('cmClientsYtd').textContent = Number(stats.new_clients_ytd || 0);

            var lead = document.getElementById('cmLeadTrend');
            lead.className = 'cm-trend' + trendClass(stats.new_leads_change);
            lead.firstChild.nodeValue = trendText(stats.new_leads_change) + ' ';
            document.getElementById('cmLeadTooltip').innerHTML = '<span class="cm-tooltip-title">New leads</span>' +
                '<span class="cm-tooltip-row"><span>' + esc(stats.prior_period_label || '') + '</span><strong>' + Number(stats.prior_leads_30 || 0) + '</strong></span>' +
                '<span class="cm-tooltip-row"><span>' + esc(stats.current_period_label || '') + '</span><strong>' + Number(stats.new_leads_30 || 0) + '</strong></span>';

            var client = document.getElementById('cmClientTrend');
            client.className = 'cm-trend' + trendClass(stats.new_clients_change);
            client.firstChild.nodeValue = trendText(stats.new_clients_change) + ' ';
            document.getElementById('cmClientTooltip').innerHTML = '<span class="cm-tooltip-title">New clients</span>' +
                '<span class="cm-tooltip-row"><span>' + esc(stats.prior_period_label || '') + '</span><strong>' + Number(stats.prior_clients_30 || 0) + '</strong></span>' +
                '<span class="cm-tooltip-row"><span>' + esc(stats.current_period_label || '') + '</span><strong>' + Number(stats.new_clients_30 || 0) + '</strong></span>';
        }

        function renderTags(tags) {
            if (!tags || !tags.length) return '';
            var visible = tags.slice(0, 2);
            var html = visible.map(function (tag) {
                return '<span class="cm-tag">' + esc(tag.name) + '</span>';
            }).join('');
            if (tags.length > 2) html += '<span class="cm-tag-more">+' + (tags.length - 2) + '</span>';
            return html;
        }

        function renderRows(rows) {
            state.rows = rows || [];
            if (!state.rows.length) {
                tableBody.innerHTML = '<tr><td colspan="6" class="cm-empty">No clients found.</td></tr>';
                return;
            }
            tableBody.innerHTML = state.rows.map(function (row) {
                var id = Number(row.id || 0);
                var address = addressOf(row) || '-';
                var emailClass = row.email ? '' : ' disabled';
                var status = String(row.status || 'new').toLowerCase();
                var isSelected = state.selectedClientIds.indexOf(id) !== -1;
                return '<tr class="cm-row' + (isSelected ? ' is-selected' : '') + '" data-client-id="' + id + '">' +
                    '<td class="cm-check-cell"><input class="cm-checkbox cm-row-check" type="checkbox" value="' + id + '"' + (isSelected ? ' checked' : '') + ' aria-label="Select ' + esc(row.display_name) + '"></td>' +
                    '<td><a class="cm-name" href="client-view.php?client_id=' + id + '">' + esc(row.display_name || 'Client') + '</a></td>' +
                    '<td><span class="cm-address">' + esc(address) + '</span></td>' +
                    '<td><div class="cm-tags">' + renderTags(row.tags || []) + '</div></td>' +
                    '<td><span class="cm-status ' + esc(status) + '">' + esc(titleCase(status)) + '</span></td>' +
                    '<td><div class="cm-last-cell"><span>' + esc(relativeDate(row.last_activity_at || row.updated_at || row.created_at)) + '</span>' +
                        '<div class="cm-row-actions">' +
                            '<button type="button" class="cm-icon-btn" data-action="tags" data-id="' + id + '" title="Edit tags"><i class="bi bi-tag"></i></button>' +
                            '<button type="button" class="cm-icon-btn' + emailClass + '" data-action="email" data-id="' + id + '" title="Email client"' + (row.email ? '' : ' disabled') + '><i class="bi bi-envelope"></i></button>' +
                            '<button type="button" class="cm-icon-btn" data-action="more" data-id="' + id + '" title="More"><i class="bi bi-three-dots"></i></button>' +
                        '</div></div></td>' +
                    '</tr>';
            }).join('');
        }

        function renderTagFilter() {
            var menu = document.getElementById('cmTagFilterMenu');
            var html = '<button type="button" class="cm-filter-option' + (state.tagId === 0 ? ' active' : '') + '" data-tag-filter="0">All tags <i class="bi bi-check-lg"></i></button>';
            state.tags.forEach(function (tag) {
                html += '<button type="button" class="cm-filter-option' + (state.tagId === Number(tag.id) ? ' active' : '') + '" data-tag-filter="' + Number(tag.id) + '">' + esc(tag.name) + ' <i class="bi bi-check-lg"></i></button>';
            });
            menu.innerHTML = html;
            var selected = state.tags.find(function (tag) { return Number(tag.id) === state.tagId; });
            document.getElementById('cmTagFilterLabel').textContent = selected ? selected.name : 'Filter by tag';
        }

        function load() {
            var fd = new FormData();
            fd.append('action', 'list');
            fd.append('page', state.page);
            fd.append('per_page', state.perPage);
            fd.append('search', state.search);
            fd.append('status_scope', state.statusScope);
            fd.append('tag_id', state.tagId);
            fd.append('sort', state.sort);
            fd.append('direction', state.direction);
            tableBody.innerHTML = '<tr><td colspan="6" class="cm-empty">Loading clients...</td></tr>';
            request(fd).then(function (data) {
                state.tags = data.tags || [];
                renderTagFilter();
                applyStats(data.stats || {});
                state.selectedClientIds = [];
                renderRows(data.clients || []);
                updateSelectionUI();
                var p = data.pagination || {};
                document.getElementById('cmResultCount').textContent = '(' + Number(p.total || 0) + ' result' + (Number(p.total || 0) === 1 ? '' : 's') + ')';
                document.getElementById('cmPaginationText').textContent = 'Showing ' + Number(p.from || 0) + '-' + Number(p.to || 0) + ' of ' + Number(p.total || 0);
                document.getElementById('cmPrevPage').disabled = Number(p.page || 1) <= 1;
                document.getElementById('cmNextPage').disabled = Number(p.page || 1) >= Number(p.pages || 1);
                document.getElementById('cmPagination').classList.toggle('show', Number(p.pages || 1) > 1);
                document.getElementById('cmSelectAll').checked = false;
                document.getElementById('cmSelectAll').indeterminate = false;
            }).catch(function (e) {
                tableBody.innerHTML = '<tr><td colspan="6" class="cm-empty">' + esc(e.message) + '</td></tr>';
                showToast('error', e.message);
            });
        }

        function rowData(id) {
            return state.rows.find(function (row) { return Number(row.id) === Number(id); }) || null;
        }


        function currentPageIds() {
            return state.rows.map(function (row) { return Number(row.id || 0); }).filter(function (id) { return id > 0; });
        }

        function isSelected(id) {
            return state.selectedClientIds.indexOf(Number(id)) !== -1;
        }

        function setSelected(id, selected) {
            id = Number(id || 0);
            if (id <= 0) return;
            var index = state.selectedClientIds.indexOf(id);
            if (selected && index === -1) state.selectedClientIds.push(id);
            if (!selected && index !== -1) state.selectedClientIds.splice(index, 1);
        }

        function updateSelectionUI() {
            var count = state.selectedClientIds.length;
            selectionBar.classList.toggle('show', count > 0);
            selectionBar.setAttribute('aria-hidden', count > 0 ? 'false' : 'true');
            tableWrap.classList.toggle('selection-active', count > 0);
            document.getElementById('cmSelectionCount').textContent = count + ' selected';
            document.getElementById('cmBulkSelectAll').checked = count > 0 && currentPageIds().every(isSelected);
            var all = document.getElementById('cmSelectAll');
            var ids = currentPageIds();
            var selectedOnPage = ids.filter(isSelected).length;
            all.checked = ids.length > 0 && selectedOnPage === ids.length;
            all.indeterminate = selectedOnPage > 0 && selectedOnPage < ids.length;
            document.querySelectorAll('.cm-row-check').forEach(function (box) {
                var checked = isSelected(Number(box.value));
                box.checked = checked;
                var row = box.closest('.cm-row');
                if (row) row.classList.toggle('is-selected', checked);
            });
        }

        function clearSelection() {
            state.selectedClientIds = [];
            updateSelectionUI();
        }

        function openBulkTags() {
            if (!state.selectedClientIds.length) return;
            state.tagMode = 'bulk';
            state.tagClientId = 0;
            state.tagClientName = '';
            state.tagClientIds = state.selectedClientIds.slice();
            state.selectedTags = [];
            document.getElementById('cmTagsModalTitle').textContent = 'Add tags to ' + state.tagClientIds.length + ' clients';
            document.getElementById('cmTagSearch').value = '';
            renderSelectedTags();
            renderTagPickerList('');
            tagPicker.classList.remove('show');
            tagModal.classList.add('show');
            tagModal.setAttribute('aria-hidden', 'false');
        }

        function openBulkDelete() {
            if (!state.selectedClientIds.length) return;
            state.confirmAction = 'bulk_delete';
            state.confirmClientId = 0;
            state.confirmClientIds = state.selectedClientIds.slice();
            document.getElementById('cmConfirmTitle').textContent = 'Delete ' + state.confirmClientIds.length + ' clients?';
            document.getElementById('cmConfirmCopy').textContent = 'The selected clients will be removed from the active client list. Existing operational history is preserved through soft delete.';
            document.getElementById('cmConfirmAction').textContent = 'Delete Clients';
            confirmModal.classList.add('show');
            confirmModal.setAttribute('aria-hidden', 'false');
        }

        function closeRowMenu() {
            rowMenu.classList.remove('show');
            rowMenu.setAttribute('aria-hidden', 'true');
            state.activeRowId = 0;
            state.activeRowName = '';
        }

        function openRowMenu(button, id) {
            var row = rowData(id);
            if (!row) return;
            state.activeRowId = id;
            state.activeRowName = row.display_name || 'this client';
            document.getElementById('cmOpenNewTab').href = 'client-view.php?client_id=' + encodeURIComponent(id);
            rowMenu.classList.add('show');
            rowMenu.setAttribute('aria-hidden', 'false');
            var rect = button.getBoundingClientRect();
            var width = 162;
            var height = rowMenu.offsetHeight || 128;
            var left = Math.min(rect.right - width, window.innerWidth - width - 8);
            var top = rect.bottom + 5;
            if (top + height > window.innerHeight - 8) top = Math.max(8, rect.top - height - 5);
            rowMenu.style.left = Math.max(8, left) + 'px';
            rowMenu.style.top = top + 'px';
        }

        function selectedTagObjects() {
            return state.tags.filter(function (tag) { return state.selectedTags.indexOf(Number(tag.id)) !== -1; });
        }

        function renderSelectedTags() {
            var box = document.getElementById('cmSelectedTags');
            var html = '<button type="button" class="cm-select-tags-btn" id="cmSelectTagsButton">Select tags <i class="bi bi-plus-lg"></i></button>';
            selectedTagObjects().forEach(function (tag) {
                html += '<span class="cm-selected-tag">' + esc(tag.name) + '<button type="button" data-remove-tag="' + Number(tag.id) + '"><i class="bi bi-x-lg"></i></button></span>';
            });
            box.innerHTML = html;
            document.getElementById('cmTagSelectedCount').textContent = state.selectedTags.length + ' selected';
        }

        function renderTagPickerList(search) {
            search = String(search || '').toLowerCase();
            var filtered = state.tags.filter(function (tag) { return String(tag.name || '').toLowerCase().indexOf(search) !== -1; });
            document.getElementById('cmTagList').innerHTML = filtered.length ? filtered.map(function (tag) {
                var selected = state.selectedTags.indexOf(Number(tag.id)) !== -1;
                return '<button type="button" class="cm-tag-option' + (selected ? ' selected' : '') + '" data-pick-tag="' + Number(tag.id) + '"><span>' + esc(tag.name) + '</span><i class="bi bi-check-lg"></i></button>';
            }).join('') : '<div class="cm-empty">No tags found.</div>';
        }

        function positionTagPicker() {
            var trigger = document.getElementById('cmSelectTagsButton');
            if (!trigger) return;
            var rect = trigger.getBoundingClientRect();
            var width = 245;
            var left = Math.min(rect.left, window.innerWidth - width - 10);
            tagPicker.style.left = Math.max(10, left) + 'px';
            tagPicker.style.top = (rect.bottom + 6) + 'px';
        }

        function openTags(id) {
            var row = rowData(id);
            if (!row) return;
            state.tagMode = 'single';
            state.tagClientIds = [];
            state.tagClientId = id;
            state.tagClientName = row.display_name || 'Client';
            state.selectedTags = (row.tags || []).map(function (tag) { return Number(tag.id); });
            document.getElementById('cmTagsModalTitle').textContent = 'Edit tags for ' + state.tagClientName;
            document.getElementById('cmTagSearch').value = '';
            renderSelectedTags();
            renderTagPickerList('');
            tagPicker.classList.remove('show');
            tagModal.classList.add('show');
            tagModal.setAttribute('aria-hidden', 'false');
        }

        function closeTags() {
            tagPicker.classList.remove('show');
            tagModal.classList.remove('show');
            tagModal.setAttribute('aria-hidden', 'true');
            state.tagClientId = 0;
            state.tagClientName = '';
            state.tagClientIds = [];
            state.tagMode = 'single';
        }

        function saveTags() {
            if (state.tagMode === 'single' && state.tagClientId <= 0) return;
            if (state.tagMode === 'bulk' && !state.tagClientIds.length) return;
            if (state.tagMode === 'bulk' && !state.selectedTags.length) {
                showToast('warning', 'Select at least one tag to add.');
                return;
            }
            var button = document.getElementById('cmTagsSave');
            button.disabled = true;
            var fd = new FormData();
            if (state.tagMode === 'bulk') {
                fd.append('action', 'bulk_add_tags');
                fd.append('client_ids', JSON.stringify(state.tagClientIds));
            } else {
                fd.append('action', 'save_tags');
                fd.append('client_id', state.tagClientId);
            }
            fd.append('tag_ids', JSON.stringify(state.selectedTags));
            request(fd).then(function (data) {
                closeTags();
                showToast('success', data.message);
                clearSelection();
                load();
            }).catch(function (e) {
                showToast('error', e.message);
            }).finally(function () { button.disabled = false; });
        }

        function createTag() {
            var name = window.prompt('New tag name');
            if (name === null) return;
            name = name.trim();
            if (!name) {
                showToast('warning', 'Enter a tag name.');
                return;
            }
            var fd = new FormData();
            fd.append('action', 'create_tag');
            fd.append('name', name);
            request(fd).then(function (data) {
                var tag = data.tag || {};
                if (!state.tags.some(function (x) { return Number(x.id) === Number(tag.id); })) state.tags.push(tag);
                state.tags.sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
                if (state.selectedTags.indexOf(Number(tag.id)) === -1) state.selectedTags.push(Number(tag.id));
                renderSelectedTags();
                renderTagPickerList('');
                renderTagFilter();
                showToast('success', data.message);
            }).catch(function (e) { showToast('error', e.message); });
        }

        function openConfirm(action, id, name) {
            state.confirmAction = action;
            state.confirmClientId = Number(id);
            state.confirmClientName = name || 'this client';
            state.confirmClientIds = [];
            document.getElementById('cmConfirmTitle').textContent = action === 'delete' ? 'Delete client?' : 'Archive client?';
            document.getElementById('cmConfirmCopy').textContent = action === 'delete'
                ? 'Delete ' + state.confirmClientName + '? The client will be removed from the active client list. Existing operational history is preserved through soft delete.'
                : 'Archive ' + state.confirmClientName + '? You can include archived clients again using the Status filter.';
            document.getElementById('cmConfirmAction').textContent = action === 'delete' ? 'Delete Client' : 'Archive Client';
            confirmModal.classList.add('show');
            confirmModal.setAttribute('aria-hidden', 'false');
        }

        function closeConfirm() {
            confirmModal.classList.remove('show');
            confirmModal.setAttribute('aria-hidden', 'true');
            state.confirmAction = '';
            state.confirmClientId = 0;
            state.confirmClientIds = [];
        }

        function runConfirm() {
            if (!state.confirmAction) return;
            if (state.confirmAction !== 'bulk_delete' && state.confirmClientId <= 0) return;
            if (state.confirmAction === 'bulk_delete' && !state.confirmClientIds.length) return;
            var button = document.getElementById('cmConfirmAction');
            button.disabled = true;
            var fd = new FormData();
            fd.append('action', state.confirmAction);
            if (state.confirmAction === 'bulk_delete') fd.append('client_ids', JSON.stringify(state.confirmClientIds));
            else fd.append('client_id', state.confirmClientId);
            request(fd).then(function (data) {
                closeConfirm();
                clearSelection();
                showToast('success', data.message);
                state.page = 1;
                load();
            }).catch(function (e) {
                showToast('error', e.message);
            }).finally(function () { button.disabled = false; });
        }


        function emailTotalBytes() {
            return state.emailFiles.reduce(function (sum, file) { return sum + Number(file.size || 0); }, 0);
        }

        function formatBytes(bytes) {
            if (!bytes) return '0 Bytes';
            if (bytes < 1024) return bytes + ' Bytes';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
        }

        function renderEmailFiles() {
            var total = emailTotalBytes();
            var mb = total / (1024 * 1024);
            document.getElementById('cmEmailSizeText').textContent = "You've attached " + mb.toFixed(2) + ' MB of the 10.00 MB limit.';
            document.getElementById('cmEmailProgress').style.width = Math.min(100, (total / (10 * 1024 * 1024)) * 100) + '%';
            document.getElementById('cmEmailFileList').innerHTML = state.emailFiles.map(function (file, index) {
                return '<div class="cm-email-file"><span><strong>' + esc(file.name) + '</strong><small>' + esc(formatBytes(file.size)) + '</small></span><button type="button" data-remove-email-file="' + index + '" aria-label="Remove attachment"><i class="bi bi-x-lg"></i></button></div>';
            }).join('');
        }

        function addEmailFiles(fileList) {
            var incoming = Array.prototype.slice.call(fileList || []);
            if (!incoming.length) return;
            var total = emailTotalBytes();
            var accepted = [];
            incoming.forEach(function (file) {
                if (state.emailFiles.length + accepted.length >= 10) return;
                if (total + file.size > 10 * 1024 * 1024) return;
                total += file.size;
                accepted.push(file);
            });
            if (accepted.length !== incoming.length) showToast('warning', 'Attachments are limited to 10 files and 10.00 MB total.');
            state.emailFiles = state.emailFiles.concat(accepted);
            renderEmailFiles();
        }

        function openEmail(id) {
            var row = rowData(id);
            if (!row || !row.email) {
                showToast('warning', 'This client does not have an email address.');
                return;
            }
            state.emailClientId = id;
            state.emailClientName = row.display_name || 'Client';
            state.emailClientEmail = row.email || '';
            state.emailFiles = [];
            document.getElementById('cmEmailTitle').textContent = 'Send email to ' + state.emailClientName;
            document.getElementById('cmEmailTo').innerHTML = '<span class="cm-email-chip">' + esc(state.emailClientEmail) + ' <i class="bi bi-x-lg" aria-hidden="true"></i></span>';
            document.getElementById('cmEmailSubject').value = '';
            document.getElementById('cmEmailMessage').value = '';
            document.getElementById('cmEmailCopy').checked = false;
            document.getElementById('cmEmailFiles').value = '';
            renderEmailFiles();
            emailModal.classList.add('show');
            emailModal.setAttribute('aria-hidden', 'false');
            window.setTimeout(function () { document.getElementById('cmEmailSubject').focus(); }, 30);
        }

        function closeEmail() {
            emailModal.classList.remove('show');
            emailModal.setAttribute('aria-hidden', 'true');
            state.emailClientId = 0;
            state.emailClientName = '';
            state.emailClientEmail = '';
            state.emailFiles = [];
            document.getElementById('cmEmailFiles').value = '';
            renderEmailFiles();
        }

        function sendEmail(e) {
            e.preventDefault();
            if (state.emailClientId <= 0) return;
            if (emailTotalBytes() > 10 * 1024 * 1024) {
                showToast('warning', 'Attachments exceed the 10.00 MB limit.');
                return;
            }
            var button = document.getElementById('cmEmailSend');
            var original = button.textContent;
            button.disabled = true;
            button.textContent = 'Sending...';
            var fd = new FormData();
            fd.append('action', 'send_email');
            fd.append('client_id', state.emailClientId);
            fd.append('subject', document.getElementById('cmEmailSubject').value.trim());
            fd.append('message', document.getElementById('cmEmailMessage').value);
            fd.append('send_copy', document.getElementById('cmEmailCopy').checked ? '1' : '0');
            state.emailFiles.forEach(function (file) { fd.append('attachments[]', file, file.name); });
            request(fd).then(function (data) {
                closeEmail();
                showToast('success', data.message || 'Email sent successfully.');
            }).catch(function (err) {
                showToast('error', err.message);
            }).finally(function () {
                button.disabled = false;
                button.textContent = original;
            });
        }

        function exportClients() {
            var button = document.getElementById('cmExportButton');
            button.disabled = true;
            var fd = new FormData();
            fd.append('action', 'export');
            fd.append('search', state.search);
            fd.append('status_scope', state.statusScope);
            fd.append('tag_id', state.tagId);
            request(fd).then(function (data) {
                var rows = data.clients || [];
                var columns = ['Client ID','Name','Company','Email','Phone','Type','Status','Address','Tags','Last Activity'];
                var quote = function (v) { return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; };
                var csvRows = rows.map(function (row) {
                    return [
                        row.id,
                        row.display_name,
                        row.company_name,
                        row.email,
                        row.phone,
                        row.client_type,
                        row.status,
                        addressOf(row),
                        row.tags_text,
                        row.last_activity
                    ].map(quote).join(',');
                });
                var csv = '\ufeff' + columns.map(quote).join(',') + '\r\n' + csvRows.join('\r\n');
                var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
                var url = URL.createObjectURL(blob);
                var link = document.createElement('a');
                link.href = url;
                link.download = 'clients-' + new Date().toISOString().slice(0,10) + '.csv';
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(url);
                showToast('success', data.message);
                document.getElementById('cmMoreWrap').classList.remove('open');
            }).catch(function (e) { showToast('error', e.message); })
              .finally(function () { button.disabled = false; });
        }

        tableBody.addEventListener('click', function (e) {
            var action = e.target.closest('[data-action]');
            if (action) {
                e.preventDefault();
                e.stopPropagation();
                var id = Number(action.dataset.id || 0);
                if (action.dataset.action === 'tags') openTags(id);
                if (action.dataset.action === 'email') openEmail(id);
                if (action.dataset.action === 'more') {
                    if (state.activeRowId === id && rowMenu.classList.contains('show')) closeRowMenu();
                    else { closeRowMenu(); openRowMenu(action, id); }
                }
                return;
            }
            if (e.target.closest('a,button,input,label')) return;
            var row = e.target.closest('tr[data-client-id]');
            if (row) window.location.href = 'client-view.php?client_id=' + encodeURIComponent(row.dataset.clientId);
        });

        tableBody.addEventListener('change', function (e) {
            var box = e.target.closest('.cm-row-check');
            if (!box) return;
            setSelected(Number(box.value), box.checked);
            updateSelectionUI();
        });

        rowMenu.addEventListener('click', function (e) {
            var action = e.target.closest('[data-row-action]');
            if (!action) return;
            e.preventDefault();
            e.stopPropagation();
            var type = action.dataset.rowAction;
            var id = state.activeRowId;
            var name = state.activeRowName;
            closeRowMenu();
            openConfirm(type, id, name);
        });

        document.getElementById('cmSelectedTags').addEventListener('click', function (e) {
            var remove = e.target.closest('[data-remove-tag]');
            if (remove) {
                state.selectedTags = state.selectedTags.filter(function (id) { return id !== Number(remove.dataset.removeTag); });
                renderSelectedTags();
                renderTagPickerList(document.getElementById('cmTagSearch').value);
                return;
            }
            var select = e.target.closest('#cmSelectTagsButton');
            if (select) {
                tagPicker.classList.toggle('show');
                if (tagPicker.classList.contains('show')) {
                    renderTagPickerList(document.getElementById('cmTagSearch').value);
                    positionTagPicker();
                    document.getElementById('cmTagSearch').focus();
                }
            }
        });

        document.getElementById('cmTagList').addEventListener('click', function (e) {
            var pick = e.target.closest('[data-pick-tag]');
            if (!pick) return;
            var id = Number(pick.dataset.pickTag);
            var index = state.selectedTags.indexOf(id);
            if (index === -1) state.selectedTags.push(id); else state.selectedTags.splice(index, 1);
            renderSelectedTags();
            renderTagPickerList(document.getElementById('cmTagSearch').value);
        });

        document.getElementById('cmTagSearch').addEventListener('input', function () { renderTagPickerList(this.value); });
        document.getElementById('cmClearTags').onclick = function () { state.selectedTags = []; renderSelectedTags(); renderTagPickerList(document.getElementById('cmTagSearch').value); };
        document.getElementById('cmCreateTag').onclick = createTag;
        document.getElementById('cmTagsSave').onclick = saveTags;
        document.getElementById('cmTagsClose').onclick = closeTags;
        document.getElementById('cmTagsCancel').onclick = closeTags;
        tagModal.onclick = function (e) { if (e.target === tagModal) closeTags(); };

        document.getElementById('cmConfirmAction').onclick = runConfirm;
        document.getElementById('cmConfirmClose').onclick = closeConfirm;
        document.getElementById('cmConfirmCancel').onclick = closeConfirm;
        confirmModal.onclick = function (e) { if (e.target === confirmModal) closeConfirm(); };

        document.getElementById('cmEmailForm').addEventListener('submit', sendEmail);
        document.getElementById('cmEmailClose').onclick = closeEmail;
        document.getElementById('cmEmailCancel').onclick = closeEmail;
        document.getElementById('cmEmailSelect').onclick = function () { document.getElementById('cmEmailFiles').click(); };
        document.getElementById('cmEmailFiles').onchange = function () { addEmailFiles(this.files); this.value = ''; };
        document.getElementById('cmEmailFileList').addEventListener('click', function (e) {
            var remove = e.target.closest('[data-remove-email-file]');
            if (!remove) return;
            state.emailFiles.splice(Number(remove.dataset.removeEmailFile), 1);
            renderEmailFiles();
        });
        var emailDrop = document.getElementById('cmEmailDrop');
        ['dragenter','dragover'].forEach(function (name) { emailDrop.addEventListener(name, function (e) { e.preventDefault(); e.stopPropagation(); emailDrop.classList.add('dragover'); }); });
        ['dragleave','drop'].forEach(function (name) { emailDrop.addEventListener(name, function (e) { e.preventDefault(); e.stopPropagation(); emailDrop.classList.remove('dragover'); }); });
        emailDrop.addEventListener('drop', function (e) { addEmailFiles(e.dataTransfer.files); });
        emailDrop.addEventListener('click', function (e) { if (!e.target.closest('#cmEmailSelect')) document.getElementById('cmEmailFiles').click(); });
        emailModal.onclick = function (e) { if (e.target === emailModal) closeEmail(); };

        document.getElementById('cmMoreButton').onclick = function (e) {
            e.stopPropagation();
            var wrap = document.getElementById('cmMoreWrap');
            var open = !wrap.classList.contains('open');
            wrap.classList.toggle('open', open);
            this.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.getElementById('cmMoreMenu').setAttribute('aria-hidden', open ? 'false' : 'true');
        };
        document.getElementById('cmExportButton').onclick = exportClients;

        document.getElementById('cmTagFilterButton').onclick = function (e) {
            e.stopPropagation();
            document.getElementById('cmTagFilterWrap').classList.toggle('open');
            document.getElementById('cmStatusFilterWrap').classList.remove('open');
        };
        document.getElementById('cmStatusFilterButton').onclick = function (e) {
            e.stopPropagation();
            document.getElementById('cmStatusFilterWrap').classList.toggle('open');
            document.getElementById('cmTagFilterWrap').classList.remove('open');
        };
        document.getElementById('cmTagFilterMenu').addEventListener('click', function (e) {
            var option = e.target.closest('[data-tag-filter]');
            if (!option) return;
            state.tagId = Number(option.dataset.tagFilter || 0);
            state.page = 1;
            document.getElementById('cmTagFilterWrap').classList.remove('open');
            load();
        });
        document.getElementById('cmStatusFilterMenu').addEventListener('click', function (e) {
            var option = e.target.closest('[data-scope]');
            if (!option) return;
            state.statusScope = option.dataset.scope;
            state.page = 1;
            Array.prototype.forEach.call(this.querySelectorAll('[data-scope]'), function (x) { x.classList.toggle('active', x === option); });
            document.getElementById('cmStatusFilterLabel').textContent = option.textContent.replace('✓','').trim();
            document.getElementById('cmStatusFilterWrap').classList.remove('open');
            load();
        });

        document.getElementById('cmSearch').oninput = function () {
            var value = this.value.trim();
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { state.search = value; state.page = 1; load(); }, 260);
        };

        document.querySelectorAll('[data-sort]').forEach(function (button) {
            button.addEventListener('click', function () {
                var next = this.dataset.sort;
                if (state.sort === next) state.direction = state.direction === 'asc' ? 'desc' : 'asc';
                else { state.sort = next; state.direction = next === 'name' ? 'asc' : 'desc'; }
                state.page = 1;
                load();
            });
        });

        document.getElementById('cmSelectAll').onchange = function () {
            var checked = this.checked;
            currentPageIds().forEach(function (id) { setSelected(id, checked); });
            updateSelectionUI();
        };
        document.getElementById('cmBulkSelectAll').onchange = function () {
            if (!this.checked) clearSelection();
            else currentPageIds().forEach(function (id) { setSelected(id, true); });
            updateSelectionUI();
        };
        document.getElementById('cmDeselectAll').onclick = clearSelection;
        document.getElementById('cmBulkTagButton').onclick = openBulkTags;
        document.getElementById('cmBulkDeleteButton').onclick = openBulkDelete;

        document.getElementById('cmPrevPage').onclick = function () { if (state.page > 1) { state.page--; load(); } };
        document.getElementById('cmNextPage').onclick = function () { state.page++; load(); };
        document.getElementById('cmToastClose').onclick = function () { toast.classList.remove('show'); };

        document.addEventListener('click', function (e) {
            if (!e.target.closest('#cmMoreWrap')) document.getElementById('cmMoreWrap').classList.remove('open');
            if (!e.target.closest('#cmTagFilterWrap')) document.getElementById('cmTagFilterWrap').classList.remove('open');
            if (!e.target.closest('#cmStatusFilterWrap')) document.getElementById('cmStatusFilterWrap').classList.remove('open');
            if (!e.target.closest('#cmRowMenu') && !e.target.closest('[data-action="more"]')) closeRowMenu();
            if (tagPicker.classList.contains('show') && !e.target.closest('#cmTagPicker') && !e.target.closest('#cmSelectTagsButton')) tagPicker.classList.remove('show');
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeRowMenu();
                tagPicker.classList.remove('show');
                document.getElementById('cmMoreWrap').classList.remove('open');
                document.getElementById('cmTagFilterWrap').classList.remove('open');
                document.getElementById('cmStatusFilterWrap').classList.remove('open');
                if (tagModal.classList.contains('show')) closeTags();
                if (confirmModal.classList.contains('show')) closeConfirm();
                if (emailModal.classList.contains('show')) closeEmail();
            }
        });

        window.addEventListener('resize', closeRowMenu);
        window.addEventListener('scroll', closeRowMenu, true);
        load();
    })();
    </script>
</body>
</html>
