<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Clients · FieldPlx';
$pageDescription = 'Manage clients, leads, tags and client communication';
$activePage = 'clients';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['clients_csrf_token'])) {
    $_SESSION['clients_csrf_token'] = bin2hex(random_bytes(32));
}
$clientsCsrfToken = (string) $_SESSION['clients_csrf_token'];

$tenantId = isset($currentTenantId) ? (int)$currentTenantId : (int)($_SESSION['tenant_id'] ?? 0);
$hasAnyClients = true;
try {
    $sql = "SELECT COUNT(*) FROM clients WHERE tenant_id = :tenant_id";
    $params = array(':tenant_id' => $tenantId);
    $columnCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'deleted_at'");
    $columnCheck->execute();
    if ((int)$columnCheck->fetchColumn() > 0) {
        $sql .= " AND deleted_at IS NULL";
    }
    $countStmt = $pdo->prepare($sql);
    $countStmt->execute($params);
    $hasAnyClients = ((int)$countStmt->fetchColumn() > 0);
} catch (Throwable $e) {
    // Keep the management screen available if an older schema cannot be counted safely.
    $hasAnyClients = true;
}

require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://unpkg.com/aos@2.3.1/dist/aos.css">
<style>
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



    /* ==========================================================
       FieldPlx current-template alignment
       ========================================================== */
    .cm-page{
        width:100%;
        max-width:none;
        padding:24px 26px 36px;
        background:transparent;
        color:var(--text);
    }
    .cm-page,
    .cm-modal,
    .cm-email-modal,
    .cm-row-menu,
    .cm-menu,
    .cm-filter-dropdown{
        font-family:inherit;
    }
    .cm-title,
    .cm-card-title,
    .cm-section-title-row h2,
    .cm-modal-head h3,
    .cm-email-head h3{
        color:var(--text) !important;
    }
    .cm-title{
        font-size:30px;
        font-weight:var(--font-weight-bold);
        letter-spacing:-.55px;
    }
    .cm-header{margin-bottom:20px;}
    .cm-btn{
        border-color:var(--button-border);
        border-radius:12px;
        background:var(--button-bg);
        color:var(--text);
        box-shadow:none;
        font-weight:var(--font-weight-semibold);
    }
    .cm-btn:hover,.cm-btn:focus{
        border-color:var(--primary);
        color:var(--primary);
        background:var(--button-hover-bg);
    }
    .cm-btn.primary{
        border-color:var(--primary);
        background:var(--primary);
        color:var(--primary-text,#fff);
    }
    .cm-btn.primary:hover{filter:brightness(.95);border-color:var(--primary);background:var(--primary);}
    .cm-card,
    .cm-table-wrap,
    .cm-toolbar,
    .cm-selection-bar{
        border-color:var(--card-border) !important;
        background:var(--card-bg) !important;
        box-shadow:var(--card-shadow);
    }
    .cm-card{border-radius:18px;}
    .cm-menu,
    .cm-filter-dropdown,
    .cm-row-menu,
    .cm-modal,
    .cm-email-modal{
        border-color:var(--card-border) !important;
        background:var(--card-bg) !important;
        box-shadow:0 18px 50px rgba(15,23,42,.14) !important;
    }
    .cm-card-period,
    .cm-result-count,
    .cm-address,
    .cm-email-helper,
    .cm-confirm-copy{
        color:var(--muted) !important;
    }
    .cm-search input,
    .cm-email-field input,
    .cm-email-field textarea,
    .cm-tag-search{
        border-color:var(--input-border) !important;
        background:var(--input-bg) !important;
        color:var(--text) !important;
    }
    .cm-search input:focus,
    .cm-email-field input:focus,
    .cm-email-field textarea:focus,
    .cm-tag-search:focus{
        border-color:var(--primary) !important;
        box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 13%,transparent) !important;
    }
    .cm-table thead th{
        background:var(--table-header-bg) !important;
        color:var(--table-header-text) !important;
        border-color:var(--table-border) !important;
    }
    .cm-table tbody td{border-color:var(--table-border) !important;color:var(--text);}
    .cm-table tbody tr:hover{background:var(--table-hover-bg) !important;}
    .cm-name{color:var(--text) !important;font-weight:var(--font-weight-semibold);}
    .cm-filter-pill{border-color:var(--button-border) !important;background:var(--button-bg) !important;color:var(--text) !important;}
    .cm-filter-pill:hover{border-color:var(--primary) !important;color:var(--primary) !important;}
    .cm-menu-item:hover,.cm-filter-option:hover{background:var(--table-hover-bg) !important;}
    .cm-trend{color:var(--status-text);background:var(--status-bg);}
    .cm-pagination{color:var(--muted);}

    /* Empty clients onboarding */
    .cm-empty-onboarding{
        position:relative;
        min-height:calc(100vh - 145px);
        overflow:hidden;
        border:1px solid var(--card-border);
        border-radius:22px;
        background:var(--card-bg);
        box-shadow:var(--card-shadow);
    }
    .cm-empty-title{
        position:relative;
        z-index:2;
        margin:0;
        padding:28px 32px 0;
        color:var(--text);
        font-size:30px;
        line-height:1.15;
        font-weight:var(--font-weight-bold);
        letter-spacing:-.6px;
    }
    .cm-empty-center{
        position:relative;
        z-index:3;
        width:min(620px,calc(100% - 40px));
        margin:18px auto 0;
        padding:6px 0 50px;
        text-align:center;
    }
    .cm-empty-center h2{
        margin:0;
        color:var(--text);
        font-size:27px;
        line-height:1.2;
        font-weight:var(--font-weight-bold);
    }
    .cm-empty-center>p{
        max-width:560px;
        margin:13px auto 0;
        color:var(--muted);
        font-size:14px;
        line-height:1.55;
    }
    .cm-empty-actions{
        display:grid;
        grid-template-columns:repeat(2,minmax(0,230px));
        justify-content:center;
        gap:20px;
        margin-top:30px;
    }
    .cm-empty-action{
        min-height:224px;
        padding:20px;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:flex-start;
        border:1px solid var(--card-border);
        border-radius:18px;
        background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));
        color:var(--text);
        text-decoration:none;
        transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease;
    }
    .cm-empty-action:hover{
        transform:translateY(-3px);
        border-color:var(--primary);
        box-shadow:0 16px 34px color-mix(in srgb,var(--primary) 10%,transparent);
        color:var(--text);
    }
    .cm-empty-action strong{
        display:block;
        margin:0 0 22px;
        font-size:16px;
        font-weight:var(--font-weight-semibold);
    }
    .cm-empty-action-icon{
        width:70px;
        height:70px;
        margin:auto 0;
        display:grid;
        place-items:center;
        border-radius:50%;
        background:color-mix(in srgb,var(--primary) 12%,var(--card-bg));
        color:var(--primary);
    }
    .cm-empty-action-icon svg{width:30px;height:30px;}
    .cm-empty-action small{
        display:block;
        margin-top:20px;
        color:var(--muted);
        font-size:11px;
        line-height:1.45;
    }
    .cm-empty-ghost{
        position:absolute;
        z-index:1;
        width:260px;
        height:160px;
        border:1px solid var(--card-border);
        border-radius:16px;
        opacity:.24;
        background:linear-gradient(180deg,color-mix(in srgb,var(--body-bg) 68%,transparent),transparent);
    }
    .cm-empty-ghost::before,.cm-empty-ghost::after{
        content:"";
        position:absolute;
        left:22px;
        height:10px;
        border-radius:999px;
        background:var(--table-border);
    }
    .cm-empty-ghost::before{top:28px;width:95px;box-shadow:0 25px 0 var(--table-border),0 50px 0 var(--table-border);}
    .cm-empty-ghost::after{top:103px;width:145px;}
    .cm-empty-ghost.left{left:8%;top:128px;transform:rotate(-1.5deg);}
    .cm-empty-ghost.right{right:7%;top:128px;transform:rotate(1.5deg);}

    @keyframes cmFallbackFadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
    .cm-aos-fallback{animation:cmFallbackFadeUp .62s ease both;}
    .cm-aos-delay-1{animation-delay:.08s}.cm-aos-delay-2{animation-delay:.16s}.cm-aos-delay-3{animation-delay:.24s}

    @media(max-width:900px){
        .cm-page{padding:18px 16px 28px;}
        .cm-empty-ghost{display:none;}
    }
    @media(max-width:640px){
        .cm-page{padding:14px 12px 24px;}
        .cm-header{align-items:flex-start;flex-direction:column;}
        .cm-title,.cm-empty-title{font-size:25px;}
        .cm-empty-title{padding:22px 20px 0;}
        .cm-empty-actions{grid-template-columns:1fr;}
        .cm-empty-action{min-height:180px;}
    }


    /* ==========================================================
       FieldPlx Clients - dark theme correction
       Keeps all existing client functions unchanged.
       Dark mode is enabled by header.php on <html class="app-dark-mode">.
       ========================================================== */
    html.app-dark-mode {
        --cm-green:#57b84d;
        --cm-green-dark:#74cc69;
        --cm-green-soft:#183529;
        --cm-navy:#edf7fb;
        --cm-text:#dce8ee;
        --cm-muted:#94a8b4;
        --cm-border:#2b3d47;
        --cm-pill:#22323b;
        --cm-bg:#101a21;
        --cm-danger:#ff6b61;
    }

    html.app-dark-mode .cm-page {
        color:#dce8ee;
    }

    html.app-dark-mode .cm-title,
    html.app-dark-mode .cm-card-title,
    html.app-dark-mode .cm-stat-value,
    html.app-dark-mode .cm-section-title-row h2,
    html.app-dark-mode .cm-modal-head h3,
    html.app-dark-mode .cm-email-head h3,
    html.app-dark-mode .cm-email-attachments h4,
    html.app-dark-mode .cm-tag-picker-meta,
    html.app-dark-mode .cm-empty-title,
    html.app-dark-mode .cm-empty-center h2 {
        color:#f2f7fa !important;
    }

    html.app-dark-mode .cm-card,
    html.app-dark-mode .cm-toolbar,
    html.app-dark-mode .cm-table-wrap,
    html.app-dark-mode .cm-selection-bar,
    html.app-dark-mode .cm-empty-onboarding {
        border-color:#2b3d47 !important;
        background:#15232b !important;
        box-shadow:none !important;
    }

    html.app-dark-mode .cm-card {
        background:#17262f !important;
    }

    html.app-dark-mode .cm-card-period,
    html.app-dark-mode .cm-result-count,
    html.app-dark-mode .cm-address,
    html.app-dark-mode .cm-pagination,
    html.app-dark-mode .cm-email-helper,
    html.app-dark-mode .cm-confirm-copy,
    html.app-dark-mode .cm-email-size-text,
    html.app-dark-mode .cm-email-to-label,
    html.app-dark-mode .cm-empty-center > p,
    html.app-dark-mode .cm-empty-action small,
    html.app-dark-mode .cm-merge-copy p,
    html.app-dark-mode .cm-tooltip-title {
        color:#94a8b4 !important;
    }

    html.app-dark-mode .cm-card-arrow,
    html.app-dark-mode .cm-name,
    html.app-dark-mode .cm-sort-button,
    html.app-dark-mode .cm-last-cell,
    html.app-dark-mode .cm-selection-count {
        color:#e6f0f5 !important;
    }

    html.app-dark-mode .cm-btn,
    html.app-dark-mode .cm-filter-pill,
    html.app-dark-mode .cm-select-tags-btn,
    html.app-dark-mode .cm-email-select {
        border-color:#344852 !important;
        background:#1b2a33 !important;
        color:#e4edf2 !important;
        box-shadow:none !important;
    }

    html.app-dark-mode .cm-btn:hover,
    html.app-dark-mode .cm-btn:focus,
    html.app-dark-mode .cm-filter-pill:hover,
    html.app-dark-mode .cm-select-tags-btn:hover,
    html.app-dark-mode .cm-email-select:hover {
        border-color:var(--primary) !important;
        background:#223640 !important;
        color:var(--primary) !important;
    }

    html.app-dark-mode .cm-btn.primary {
        border-color:var(--primary) !important;
        background:var(--primary) !important;
        color:var(--primary-text,#fff) !important;
    }

    html.app-dark-mode .cm-btn.primary:hover,
    html.app-dark-mode .cm-btn.primary:focus {
        color:var(--primary-text,#fff) !important;
        filter:brightness(.95);
    }

    html.app-dark-mode .cm-btn.danger {
        border-color:#70403f !important;
        background:#3a2325 !important;
        color:#ff9b94 !important;
    }

    html.app-dark-mode .cm-btn.danger:hover {
        border-color:#96514e !important;
        background:#48282a !important;
        color:#ffb0aa !important;
    }

    html.app-dark-mode .cm-menu,
    html.app-dark-mode .cm-filter-dropdown,
    html.app-dark-mode .cm-row-menu,
    html.app-dark-mode .cm-modal,
    html.app-dark-mode .cm-email-modal,
    html.app-dark-mode .cm-tag-picker {
        border-color:#324650 !important;
        background:#18262e !important;
        box-shadow:0 18px 50px rgba(0,0,0,.36) !important;
    }

    html.app-dark-mode .cm-menu-item,
    html.app-dark-mode .cm-filter-option,
    html.app-dark-mode .cm-tag-option {
        color:#dce8ee !important;
        background:transparent !important;
    }

    html.app-dark-mode .cm-menu-item:hover,
    html.app-dark-mode .cm-filter-option:hover,
    html.app-dark-mode .cm-filter-option.active,
    html.app-dark-mode .cm-tag-option:hover,
    html.app-dark-mode .cm-selection-icon:hover,
    html.app-dark-mode .cm-icon-btn:hover {
        background:#22343e !important;
        color:#f4f8fa !important;
    }

    html.app-dark-mode .cm-table {
        background:#15232b;
    }

    html.app-dark-mode .cm-table thead th {
        background:#1c2c35 !important;
        color:#a9bac4 !important;
        border-color:#2d3f49 !important;
    }

    html.app-dark-mode .cm-table tbody td {
        background:#15232b;
        color:#dce8ee !important;
        border-color:#293b45 !important;
    }

    html.app-dark-mode .cm-table tbody tr:hover td {
        background:#1d3039 !important;
    }

    html.app-dark-mode .cm-row.is-selected td {
        background:#203740 !important;
    }

    html.app-dark-mode .cm-row.is-selected:hover td {
        background:#25404a !important;
    }

    html.app-dark-mode .cm-row-actions {
        border-color:#354a55;
        background:#1c2c35;
        box-shadow:0 8px 20px rgba(0,0,0,.28);
    }

    html.app-dark-mode .cm-icon-btn,
    html.app-dark-mode .cm-selection-icon {
        background:#1c2c35;
        color:#cfe0e8;
        border-color:#344751;
    }

    html.app-dark-mode .cm-selection-icon {
        background:transparent;
    }

    html.app-dark-mode .cm-selection-icon.danger:hover {
        color:#ff8b84 !important;
        background:#3b2527 !important;
    }

    html.app-dark-mode .cm-search input,
    html.app-dark-mode .cm-email-field input,
    html.app-dark-mode .cm-email-field textarea,
    html.app-dark-mode .cm-tag-search {
        border-color:#344852 !important;
        background:#132129 !important;
        color:#e6f0f5 !important;
        caret-color:#e6f0f5;
    }

    html.app-dark-mode .cm-search input::placeholder,
    html.app-dark-mode .cm-email-field input::placeholder,
    html.app-dark-mode .cm-email-field textarea::placeholder,
    html.app-dark-mode .cm-tag-search::placeholder {
        color:#6f8794 !important;
        opacity:1;
    }

    html.app-dark-mode .cm-search i {
        color:#71909f;
    }

    html.app-dark-mode .cm-search input:focus,
    html.app-dark-mode .cm-email-field input:focus,
    html.app-dark-mode .cm-email-field textarea:focus,
    html.app-dark-mode .cm-tag-search:focus {
        border-color:var(--primary) !important;
        box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 18%,transparent) !important;
    }

    html.app-dark-mode .cm-filter-pill.status {
        border-color:#344852 !important;
        background:#20313a !important;
    }

    html.app-dark-mode .cm-tag {
        border:1px solid #3a4c55;
        background:#24343d;
        color:#dce8ee;
    }

    html.app-dark-mode .cm-tag-more {
        color:#9fb0ba;
    }

    html.app-dark-mode .cm-status {
        border:1px solid #315f43;
        background:#183628;
        color:#8ddd93;
    }

    html.app-dark-mode .cm-status::before {
        background:#57bf62;
    }

    html.app-dark-mode .cm-status.new {
        border-color:#62592d;
        background:#39331e;
        color:#f0d46f;
    }

    html.app-dark-mode .cm-status.new::before {
        background:#d6b932;
    }

    html.app-dark-mode .cm-status.inactive,
    html.app-dark-mode .cm-status.archived {
        border-color:#43535b;
        background:#28353c;
        color:#b0bdc4;
    }

    html.app-dark-mode .cm-status.inactive::before,
    html.app-dark-mode .cm-status.archived::before {
        background:#82929b;
    }

    html.app-dark-mode .cm-trend {
        border:1px solid #315f43;
        background:#183628;
        color:#8ddd93;
    }

    html.app-dark-mode .cm-trend.down {
        border-color:#6f3c3c;
        background:#3b2426;
        color:#ff9e98;
    }

    html.app-dark-mode .cm-trend.neutral {
        border-color:#3b4b54;
        background:#24333b;
        color:#c4d0d6;
    }

    html.app-dark-mode .cm-trend-tooltip {
        border-color:#344852;
        background:#1c2c35;
        color:#dce8ee;
        box-shadow:0 12px 28px rgba(0,0,0,.32);
    }

    html.app-dark-mode .cm-trend-tooltip::after {
        border-top-color:#1c2c35;
    }

    html.app-dark-mode .cm-merge-art {
        background:linear-gradient(160deg,#1b2a32,#122028);
    }

    html.app-dark-mode .cm-merge-art::before,
    html.app-dark-mode .cm-merge-art::after {
        border-color:#354750;
        background:#22323b;
        box-shadow:0 4px 12px rgba(0,0,0,.2);
    }

    html.app-dark-mode .cm-modal-backdrop {
        background:rgba(0,8,13,.72);
        backdrop-filter:blur(2px);
    }

    html.app-dark-mode .cm-modal-body,
    html.app-dark-mode .cm-email-body,
    html.app-dark-mode .cm-email-footer,
    html.app-dark-mode .cm-modal-footer,
    html.app-dark-mode .cm-modal-head,
    html.app-dark-mode .cm-email-head {
        color:#dce8ee;
    }

    html.app-dark-mode .cm-close {
        color:#c8d6dd;
    }

    html.app-dark-mode .cm-close:hover {
        color:#fff;
        background:#22343e;
        border-radius:7px;
    }

    html.app-dark-mode .cm-selected-tag,
    html.app-dark-mode .cm-email-chip,
    html.app-dark-mode .cm-email-file,
    html.app-dark-mode .cm-email-to,
    html.app-dark-mode .cm-email-drop {
        border-color:#344852 !important;
        background:#132129 !important;
        color:#dce8ee !important;
    }

    html.app-dark-mode .cm-selected-tag button,
    html.app-dark-mode .cm-email-file button {
        background:#20313a;
        color:#aebdc5;
    }

    html.app-dark-mode .cm-email-drop.dragover {
        border-color:var(--primary) !important;
        background:#19313a !important;
    }

    html.app-dark-mode .cm-email-progress {
        background:#2a3941;
    }

    html.app-dark-mode .cm-tag-picker-meta,
    html.app-dark-mode .cm-tag-picker-create,
    html.app-dark-mode .cm-tag-search {
        border-color:#344852 !important;
    }

    html.app-dark-mode .cm-clear-link,
    html.app-dark-mode .cm-create-tag-link,
    html.app-dark-mode .cm-selection-clear {
        color:var(--primary) !important;
    }

    html.app-dark-mode .cm-checkbox {
        accent-color:var(--primary);
    }

    html.app-dark-mode .cm-empty {
        color:#93a7b2 !important;
    }

    html.app-dark-mode .cm-empty-action {
        border-color:#2e414b;
        background:#17262f;
        color:#e6f0f5;
    }

    html.app-dark-mode .cm-empty-action:hover {
        border-color:var(--primary);
        background:#1b2d36;
        box-shadow:0 16px 34px rgba(0,0,0,.24);
    }

    html.app-dark-mode .cm-empty-action-icon {
        background:color-mix(in srgb,var(--primary) 18%,#17262f);
        color:var(--primary);
    }

    html.app-dark-mode .cm-empty-ghost {
        border-color:#2f424b;
        background:linear-gradient(180deg,#1c2b33,rgba(28,43,51,.12));
    }

    html.app-dark-mode .cm-empty-ghost::before,
    html.app-dark-mode .cm-empty-ghost::after {
        background:#31424a;
    }

    html.app-dark-mode .cm-empty-ghost::before {
        box-shadow:0 25px 0 #31424a,0 50px 0 #31424a;
    }

    html.app-dark-mode .cm-toast.info {
        background:#174a63;
    }

    html.app-dark-mode .cm-toast.success {
        background:#2f7d35;
    }

    html.app-dark-mode .cm-toast.warning {
        background:#7b651f;
    }

    html.app-dark-mode .cm-toast.error {
        background:#a83d39;
    }

</style>

<?php if (!$hasAnyClients): ?>

<div class="cm-page">
    <section class="cm-empty-onboarding">
        <h1 class="cm-empty-title cm-aos-fallback" data-aos="fade-up">Clients</h1>
        <div class="cm-empty-ghost left" aria-hidden="true"></div>
        <div class="cm-empty-ghost right" aria-hidden="true"></div>
        <div class="cm-empty-center">
            <h2 class="cm-aos-fallback cm-aos-delay-1" data-aos="fade-up" data-aos-delay="80">Add clients</h2>
            <p class="cm-aos-fallback cm-aos-delay-1" data-aos="fade-up" data-aos-delay="120">Adding client information helps you create jobs, quotes, and invoices faster. Import your client details, or start by adding a client manually.</p>
            <div class="cm-empty-actions">
                <a class="cm-empty-action cm-aos-fallback cm-aos-delay-2" data-aos="fade-up" data-aos-delay="180" href="customer-import.php">
                    <strong>Import Clients</strong>
                    <span class="cm-empty-action-icon"><i data-lucide="file-up"></i></span>
                    <small>Bring existing client records into FieldPlx.</small>
                </a>
                <a class="cm-empty-action cm-aos-fallback cm-aos-delay-3" data-aos="fade-up" data-aos-delay="260" href="client-form.php">
                    <strong>Add a Client</strong>
                    <span class="cm-empty-action-icon"><i data-lucide="plus"></i></span>
                    <small>Create your first client manually.</small>
                </a>
            </div>
        </div>
    </section>
</div>

<?php else: ?>
<div class="cm-page">
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
<?php endif; ?>

<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
(function(){
    if (window.AOS) {
        AOS.init({duration:560, easing:'ease-out-cubic', once:true, offset:10});
    }
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>