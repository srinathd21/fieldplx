<?php
/**
 * FieldPlx Platform - View Tenant
 * UI page only. Tenant details are loaded from api/tenant-view.php.
 * PHP 7.2+
 */

require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Tenant Details';
$activePage = 'tenants';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$tenantId = isset($_GET['id']) && !is_array($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($tenantId <= 0) {
    http_response_code(400);
    exit('Invalid tenant ID.');
}

function tenantViewEscape($value)
{
    return htmlspecialchars(
        (string) ($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= tenantViewEscape($pageTitle); ?> - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>

    <style>

        :root {
            --fp-primary: #12182d;
            --fp-primary-2: #1c2250;
            --fp-primary-3: #201f6b;
            --fp-accent: #8b5cf6;
            --fp-accent-light: #a78bfa;
            --fp-accent-dark: #6d28d9;
            --fp-text: #20213f;
            --fp-muted: #6f6b8f;
            --fp-border: #ded9ef;
            --fp-bg: #ffffff;
            --fp-surface: #ffffff;
            --fp-surface-soft: #f8f6ff;
            --fp-success: #059669;
            --fp-warning: #d97706;
            --fp-danger: #dc2626;
            --fp-info: #6366f1;
            --fp-sidebar-width: 260px;
            --fp-sidebar-collapsed-width: 76px;
            --fp-topbar-height: 66px;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            background: #ffffff;
            color: var(--fp-text);
            font-family: "Inter", sans-serif;
            font-size: 13px;
        }

        a {
            text-decoration: none;
        }

        button,
        input,
        select,
        textarea {
            font-family: inherit;
        }

        .fp-layout {
            min-height: 100vh;
        }

        .fp-main {
            min-height: calc(100vh - 52px);
            margin-left: var(--fp-sidebar-width);
            transition: margin-left .22s ease;
        }

        body.fp-sidebar-collapsed .fp-main {
            margin-left: var(--fp-sidebar-collapsed-width);
        }

        /* =========================================================
           SHARED TOPBAR - SAME DASHBOARD UI
        ========================================================= */

        .fp-topbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            min-height: var(--fp-topbar-height);
            border-bottom: 1px solid #ded8f3;
            background: rgba(248, 246, 255, .96);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .fp-topbar-inner {
            min-height: var(--fp-topbar-height);
            padding: 8px 18px;
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .fp-menu-toggle,
        .fp-icon-button {
            width: 39px;
            height: 39px;
            min-width: 39px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #d9d2ef;
            border-radius: 10px;
            background: #ffffff;
            color: #39345f;
            font-size: 18px;
            line-height: 1;
            transition: .16s ease;
        }

        .fp-menu-toggle:hover,
        .fp-icon-button:hover {
            border-color: #bda9ff;
            background: #f4f0ff;
            color: var(--fp-accent-dark);
        }

        .fp-page-heading {
            min-width: 0;
            margin-right: auto;
        }

        .fp-page-title {
            margin: 0;
            overflow: hidden;
            color: #17172e;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.25;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .fp-page-subtitle {
            margin-top: 2px;
            color: var(--fp-muted);
            font-size: 10px;
        }

        .fp-search {
            width: min(340px, 31vw);
            position: relative;
            flex: 0 1 340px;
        }

        .fp-search i {
            position: absolute;
            top: 50%;
            left: 12px;
            z-index: 2;
            transform: translateY(-50%);
            color: #8f88aa;
            font-size: 14px;
            pointer-events: none;
        }

        .fp-search input {
            width: 100%;
            height: 39px;
            padding: 8px 13px 8px 36px;
            border: 1px solid #dcd5ef;
            border-radius: 10px;
            outline: none;
            background: #f8f6ff;
            box-shadow: none;
            font-size: 12px;
        }

        .fp-search input:focus {
            border-color: #a78bfa;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, .12);
        }

        .fp-notification-wrap {
            position: relative;
            flex: 0 0 auto;
        }

        .fp-notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            z-index: 3;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
            border-radius: 999px;
            background: var(--fp-danger);
            color: #fff;
            font-size: 9px;
            font-weight: 700;
        }

        .fp-profile {
            min-width: 0;
            padding: 4px 9px 4px 5px;
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid var(--fp-border);
            border-radius: 11px;
            background: #fff;
        }

        .fp-avatar {
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: linear-gradient(135deg, #6d4df4, #9a5cff);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
        }

        .fp-profile-text {
            max-width: 145px;
            min-width: 0;
        }

        .fp-profile-name,
        .fp-profile-role {
            overflow: hidden;
            display: block;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .fp-profile-name {
            color: #111827;
            font-size: 11px;
            font-weight: 700;
        }

        .fp-profile-role {
            margin-top: 1px;
            color: var(--fp-muted);
            font-size: 9px;
        }

        .fp-mobile-brand {
            display: none;
        }

        .fp-content {
            padding: 18px;
            background: #ffffff;
        }

        /* =========================================================
           ADD TENANT PAGE
        ========================================================= */

        .tenant-add-page {
            display: grid;
            gap: 16px;
        }

        .tenant-add-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
        }

        .tenant-add-title {
            margin: 0;
            color: #111827;
            font-size: 20px;
            font-weight: 800;
        }

        .tenant-add-description {
            margin-top: 4px;
            color: #77718e;
            font-size: 10px;
            line-height: 1.55;
        }

        .tenant-back-button {
            min-height: 38px;
            padding: 8px 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border: 1px solid #ded7ef;
            border-radius: 10px;
            background: #ffffff;
            color: #50496a;
            font-size: 10px;
            font-weight: 700;
        }

        .tenant-back-button:hover {
            border-color: #bca7ff;
            background: #f7f3ff;
            color: #6d28d9;
        }

        .tenant-form-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 310px;
            gap: 16px;
            align-items: start;
        }

        .tenant-form-column {
            display: grid;
            gap: 16px;
        }

        .tenant-form-card {
            overflow: hidden;
            border: 1px solid #ded7ef;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 8px 24px rgba(37, 29, 80, .05);
        }

        .tenant-form-card-header {
            min-height: 54px;
            padding: 12px 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #ece7f7;
            background: #fbf9ff;
        }

        .tenant-form-card-icon {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: #eee8ff;
            color: #7c3aed;
            font-size: 14px;
        }

        .tenant-form-card-title {
            margin: 0;
            color: #111827;
            font-size: 12px;
            font-weight: 800;
        }

        .tenant-form-card-subtitle {
            margin-top: 2px;
            color: #9a94aa;
            font-size: 8px;
        }

        .tenant-form-card-body {
            padding: 15px;
        }

        .tenant-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 13px;
        }

        .tenant-form-grid.three {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .tenant-field {
            min-width: 0;
        }

        .tenant-field.full {
            grid-column: 1 / -1;
        }

        .tenant-field label {
            margin-bottom: 6px;
            display: block;
            color: #4c465f;
            font-size: 9px;
            font-weight: 700;
        }

        .tenant-field label .required {
            color: #dc2626;
        }

        .tenant-input,
        .tenant-select,
        .tenant-textarea {
            width: 100%;
            border: 1px solid #dcd5ef;
            border-radius: 9px;
            outline: none;
            background: #ffffff;
            color: #312b47;
            box-shadow: none;
            font-size: 10px;
            transition: .15s ease;
        }

        .tenant-input,
        .tenant-select {
            height: 39px;
            padding: 8px 11px;
        }

        .tenant-textarea {
            min-height: 82px;
            padding: 10px 11px;
            resize: vertical;
        }

        .tenant-input:focus,
        .tenant-select:focus,
        .tenant-textarea:focus {
            border-color: #a78bfa;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, .10);
        }

        .tenant-input::placeholder,
        .tenant-textarea::placeholder {
            color: #aaa4b8;
        }

        .tenant-field-note {
            margin-top: 5px;
            color: #9a94aa;
            font-size: 8px;
            line-height: 1.4;
        }

        .tenant-readonly {
            background: #f8f6ff;
        }

        .tenant-code-row {
            position: relative;
        }

        .tenant-code-row .tenant-input {
            padding-right: 92px;
        }

        .tenant-generate-code {
            position: absolute;
            top: 5px;
            right: 5px;
            height: 29px;
            padding: 0 9px;
            border: 0;
            border-radius: 7px;
            background: #eee8ff;
            color: #6d28d9;
            font-size: 8px;
            font-weight: 700;
        }

        .tenant-switch-row {
            min-height: 39px;
            padding: 8px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            border: 1px solid #ded7ef;
            border-radius: 9px;
            background: #fbf9ff;
        }

        .tenant-switch-text strong {
            display: block;
            color: #393248;
            font-size: 9px;
        }

        .tenant-switch-text span {
            margin-top: 2px;
            display: block;
            color: #9a94aa;
            font-size: 8px;
        }

        .tenant-plan-summary {
            display: grid;
            gap: 9px;
        }

        .tenant-summary-line {
            padding-bottom: 9px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px dashed #e3ddef;
            font-size: 9px;
        }

        .tenant-summary-line:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }

        .tenant-summary-line span {
            color: #8c849e;
        }

        .tenant-summary-line strong {
            color: #2f2940;
            text-align: right;
            font-weight: 700;
        }

        .tenant-info-box {
            margin-top: 12px;
            padding: 11px;
            border: 1px solid #e3daf8;
            border-radius: 10px;
            background: #f8f5ff;
            color: #655d78;
            font-size: 8px;
            line-height: 1.55;
        }

        .tenant-info-box i {
            margin-right: 5px;
            color: #7c3aed;
        }

        .tenant-submit-bar {
            padding: 13px 15px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 9px;
            border-top: 1px solid #ece7f7;
            background: #fbf9ff;
        }

        .tenant-cancel-button,
        .tenant-save-button {
            min-height: 38px;
            padding: 8px 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border-radius: 9px;
            font-size: 10px;
            font-weight: 700;
        }

        .tenant-cancel-button {
            border: 1px solid #dcd5ef;
            background: #ffffff;
            color: #5f5870;
        }

        .tenant-save-button {
            border: 0;
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            color: #ffffff;
            box-shadow: 0 8px 20px rgba(109, 40, 217, .18);
        }

        .tenant-save-button:disabled {
            opacity: .65;
            cursor: not-allowed;
        }

        .tenant-alert {
            display: none;
            padding: 11px 13px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 600;
            line-height: 1.5;
        }

        .tenant-alert.show {
            display: block;
        }

        .tenant-alert.success {
            border: 1px solid #a7f3d0;
            background: #ecfdf5;
            color: #047857;
        }

        .tenant-alert.error {
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #b91c1c;
        }

        .tenant-alert.warning {
            border: 1px solid #fde68a;
            background: #fffbeb;
            color: #92400e;
        }

        .tenant-loading {
            width: 13px;
            height: 13px;
            display: none;
            border: 2px solid rgba(255,255,255,.45);
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: tenantSpin .65s linear infinite;
        }

        .tenant-save-button.loading .tenant-loading {
            display: inline-block;
        }

        @keyframes tenantSpin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 1100px) {
            .tenant-form-layout {
                grid-template-columns: 1fr;
            }

            .tenant-side-column {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px;
            }
        }

        @media (max-width: 991.98px) {
            .fp-main,
            body.fp-sidebar-collapsed .fp-main {
                margin-left: 0;
            }

            .fp-search {
                display: none;
            }

            .fp-profile-text {
                display: none;
            }

            .fp-mobile-brand {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: #ffffff;
                font-weight: 700;
            }
        }

        @media (max-width: 700px) {
            .tenant-add-header {
                flex-direction: column;
            }

            .tenant-back-button {
                width: 100%;
            }

            .tenant-form-grid,
            .tenant-form-grid.three,
            .tenant-side-column {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 575.98px) {
            .fp-topbar-inner {
                padding: 8px 11px;
            }

            .fp-page-subtitle {
                display: none;
            }

            .fp-page-title {
                font-size: 13px;
            }

            .fp-content {
                padding: 12px;
            }

            .tenant-submit-bar {
                flex-direction: column-reverse;
            }

            .tenant-cancel-button,
            .tenant-save-button {
                width: 100%;
            }
        }
    
        .tenant-view-page{display:grid;gap:16px}
        .tenant-view-header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}
        .tenant-view-title-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .tenant-view-title{margin:0;color:#111827;font-size:20px;font-weight:800}
        .tenant-view-subtitle{margin-top:4px;color:#77718e;font-size:10px}
        .tenant-view-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .tenant-view-status{padding:4px 8px;border-radius:999px;font-size:8px;font-weight:800;background:#f3f4f6;color:#4b5563;text-transform:capitalize}
        .tenant-view-status.active{background:#d1fae5;color:#047857}
        .tenant-view-status.trial{background:#e0e7ff;color:#4338ca}
        .tenant-view-status.suspended{background:#fee2e2;color:#b91c1c}
        .tenant-view-status.expired{background:#ffedd5;color:#c2410c}
        .tenant-view-status.cancelled,.tenant-view-status.archived{background:#f3f4f6;color:#4b5563}
        .tenant-view-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
        .tenant-view-summary-card{padding:14px 15px;display:flex;align-items:center;gap:11px;border:1px solid #ddd5f1;border-radius:13px;background:linear-gradient(180deg,#fff 0%,#fbf9ff 100%)}
        .tenant-view-summary-icon{width:38px;height:38px;flex:0 0 38px;display:inline-flex;align-items:center;justify-content:center;border-radius:10px;background:#eee8ff;color:#7c3aed;font-size:16px}
        .tenant-view-summary-label{color:#9a94ae;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
        .tenant-view-summary-value{margin-top:2px;display:block;color:#111827;font-size:18px;font-weight:800}
        .tenant-view-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;align-items:start}
        .tenant-view-column{display:grid;gap:16px}
        .tenant-view-card{overflow:hidden;border:1px solid #ded7ef;border-radius:14px;background:#fff;box-shadow:0 8px 24px rgba(37,29,80,.05)}
        .tenant-view-card-header{min-height:54px;padding:12px 15px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #ece7f7;background:#fbf9ff}
        .tenant-view-card-icon{width:34px;height:34px;flex:0 0 34px;display:inline-flex;align-items:center;justify-content:center;border-radius:9px;background:#eee8ff;color:#7c3aed;font-size:14px}
        .tenant-view-card-title{margin:0;color:#111827;font-size:12px;font-weight:800}
        .tenant-view-card-subtitle{margin-top:2px;color:#9a94aa;font-size:8px}
        .tenant-view-card-body{padding:15px}
        .tenant-view-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px 14px}
        .tenant-view-item{min-width:0}
        .tenant-view-item.full{grid-column:1/-1}
        .tenant-view-label{margin-bottom:4px;color:#9a94aa;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
        .tenant-view-value{color:#342e45;font-size:10px;font-weight:600;line-height:1.5;word-break:break-word}
        .tenant-view-plan-lines{display:grid;gap:9px}
        .tenant-view-plan-line{padding-bottom:9px;display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed #e3ddef;font-size:9px}
        .tenant-view-plan-line:last-child{padding-bottom:0;border-bottom:0}
        .tenant-view-plan-line span{color:#8c849e}
        .tenant-view-plan-line strong{color:#2f2940;text-align:right}
        .tenant-view-logo-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .tenant-view-logo{min-height:115px;padding:10px;display:flex;align-items:center;justify-content:center;border:1px solid #e2dbef;border-radius:10px;background:#fbf9ff}
        .tenant-view-logo img{max-width:100%;max-height:90px;object-fit:contain}
        .tenant-view-empty{color:#9a94aa;font-size:9px;text-align:center}
        .tenant-view-table-wrap{overflow-x:auto}
        .tenant-view-table{width:100%;min-width:650px;border-collapse:collapse}
        .tenant-view-table th{padding:10px 12px;background:#f6f2ff;border-bottom:1px solid #ece7f7;color:#847d9e;font-size:8px;text-align:left;text-transform:uppercase}
        .tenant-view-table td{padding:11px 12px;border-bottom:1px solid #f1eff6;color:#4f4a64;font-size:9px}
        .tenant-loading-state{padding:30px 18px;display:flex;align-items:center;justify-content:center;gap:9px;border:1px solid #e5def4;border-radius:12px;color:#776f8d;font-size:10px;background:#fbf9ff}
        .tenant-inline-loader{width:15px;height:15px;border:2px solid #ded7ef;border-top-color:#7c3aed;border-radius:50%;animation:tenantSpin .65s linear infinite}
        @media(max-width:1100px){.tenant-view-layout{grid-template-columns:1fr}.tenant-view-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:700px){.tenant-view-summary,.tenant-view-grid,.tenant-view-logo-grid{grid-template-columns:1fr}.tenant-view-header{flex-direction:column}.tenant-view-actions{width:100%}.tenant-view-actions .tenant-back-button{flex:1}}

    </style>
</head>
<body>
<div class="fp-layout">

    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="fp-main">
        <?php require_once __DIR__ . '/includes/topbar.php'; ?>

        <div class="fp-content">
            <div class="tenant-view-page">

                <div class="tenant-view-header">
                    <div>
                        <div class="tenant-view-title-row">
                            <h2 class="tenant-view-title" id="tenantTitle">Tenant Details</h2>
                            <span class="tenant-view-status" id="tenantStatusBadge">—</span>
                        </div>
                        <div class="tenant-view-subtitle" id="tenantSubtitle">Loading tenant information...</div>
                    </div>

                    <div class="tenant-view-actions">
                        <a href="tenant-edit.php?id=<?= (int) $tenantId; ?>" class="tenant-back-button">
                            <i class="bi bi-pencil"></i> Edit Tenant
                        </a>
                        <a href="tenants.php" class="tenant-back-button">
                            <i class="bi bi-arrow-left"></i> Back to Tenants
                        </a>
                    </div>
                </div>

                <div class="tenant-alert" id="tenantAlert" role="alert"></div>

                <div class="tenant-loading-state" id="tenantLoadingState">
                    <span class="tenant-inline-loader"></span>
                    Loading tenant details...
                </div>

                <div id="tenantContent" style="display:none;">

                    <section class="tenant-view-summary">
                        <article class="tenant-view-summary-card">
                            <span class="tenant-view-summary-icon"><i class="bi bi-people"></i></span>
                            <span><span class="tenant-view-summary-label">Users</span><span class="tenant-view-summary-value" id="summaryUsers">0</span></span>
                        </article>
                        <article class="tenant-view-summary-card">
                            <span class="tenant-view-summary-icon"><i class="bi bi-diagram-3"></i></span>
                            <span><span class="tenant-view-summary-label">Branches</span><span class="tenant-view-summary-value" id="summaryBranches">0</span></span>
                        </article>
                        <article class="tenant-view-summary-card">
                            <span class="tenant-view-summary-icon"><i class="bi bi-card-checklist"></i></span>
                            <span><span class="tenant-view-summary-label">Plan</span><span class="tenant-view-summary-value" id="summaryPlan">No Plan</span></span>
                        </article>
                        <article class="tenant-view-summary-card">
                            <span class="tenant-view-summary-icon"><i class="bi bi-calendar-check"></i></span>
                            <span><span class="tenant-view-summary-label">Joined</span><span class="tenant-view-summary-value" id="summaryJoined">—</span></span>
                        </article>
                    </section>

                    <div class="tenant-view-layout" style="margin-top:16px;">
                        <div class="tenant-view-column">

                            <section class="tenant-view-card">
                                <div class="tenant-view-card-header">
                                    <span class="tenant-view-card-icon"><i class="bi bi-buildings"></i></span>
                                    <span><h3 class="tenant-view-card-title">Business Details</h3><span class="tenant-view-card-subtitle">Tenant identity and registration information</span></span>
                                </div>
                                <div class="tenant-view-card-body">
                                    <div class="tenant-view-grid">
                                        <div class="tenant-view-item"><div class="tenant-view-label">Tenant Code</div><div class="tenant-view-value" id="vTenantCode">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">Business Type</div><div class="tenant-view-value" id="vBusinessType">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">Legal Name</div><div class="tenant-view-value" id="vLegalName">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">Display Name</div><div class="tenant-view-value" id="vDisplayName">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">Registration Number</div><div class="tenant-view-value" id="vRegistration">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">Tax Number</div><div class="tenant-view-value" id="vTaxNumber">—</div></div>
                                    </div>
                                </div>
                            </section>

                            <section class="tenant-view-card">
                                <div class="tenant-view-card-header">
                                    <span class="tenant-view-card-icon"><i class="bi bi-person-lines-fill"></i></span>
                                    <span><h3 class="tenant-view-card-title">Contact Details</h3><span class="tenant-view-card-subtitle">Business communication information</span></span>
                                </div>
                                <div class="tenant-view-card-body">
                                    <div class="tenant-view-grid">
                                        <div class="tenant-view-item"><div class="tenant-view-label">Email</div><div class="tenant-view-value" id="vEmail">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">Website</div><div class="tenant-view-value" id="vWebsite">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">Phone</div><div class="tenant-view-value" id="vPhone">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">Alternate Phone</div><div class="tenant-view-value" id="vAlternatePhone">—</div></div>
                                    </div>
                                </div>
                            </section>

                            <section class="tenant-view-card">
                                <div class="tenant-view-card-header">
                                    <span class="tenant-view-card-icon"><i class="bi bi-geo-alt"></i></span>
                                    <span><h3 class="tenant-view-card-title">Location & Localization</h3><span class="tenant-view-card-subtitle">Country, currency, timezone and address</span></span>
                                </div>
                                <div class="tenant-view-card-body">
                                    <div class="tenant-view-grid">
                                        <div class="tenant-view-item"><div class="tenant-view-label">Country</div><div class="tenant-view-value" id="vCountry">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">Currency</div><div class="tenant-view-value" id="vCurrency">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">Timezone</div><div class="tenant-view-value" id="vTimezone">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">Date Format</div><div class="tenant-view-value" id="vDateFormat">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">City</div><div class="tenant-view-value" id="vCity">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">State / Province</div><div class="tenant-view-value" id="vState">—</div></div>
                                        <div class="tenant-view-item full"><div class="tenant-view-label">Address</div><div class="tenant-view-value" id="vAddress">—</div></div>
                                        <div class="tenant-view-item"><div class="tenant-view-label">Postal Code</div><div class="tenant-view-value" id="vPostalCode">—</div></div>
                                    </div>
                                </div>
                            </section>

                            <section class="tenant-view-card">
                                <div class="tenant-view-card-header">
                                    <span class="tenant-view-card-icon"><i class="bi bi-diagram-3"></i></span>
                                    <span><h3 class="tenant-view-card-title">Branches</h3><span class="tenant-view-card-subtitle">Current non-archived business locations</span></span>
                                </div>
                                <div class="tenant-view-table-wrap">
                                    <table class="tenant-view-table">
                                        <thead>
                                            <tr><th>Branch</th><th>Code</th><th>Location</th><th>Head Office</th><th>Status</th></tr>
                                        </thead>
                                        <tbody id="branchTableBody"></tbody>
                                    </table>
                                </div>
                            </section>
                        </div>

                        <aside class="tenant-view-column">
                            <section class="tenant-view-card">
                                <div class="tenant-view-card-header">
                                    <span class="tenant-view-card-icon"><i class="bi bi-credit-card"></i></span>
                                    <span><h3 class="tenant-view-card-title">Subscription</h3><span class="tenant-view-card-subtitle">Latest subscription information</span></span>
                                </div>
                                <div class="tenant-view-card-body">
                                    <div class="tenant-view-plan-lines">
                                        <div class="tenant-view-plan-line"><span>Plan</span><strong id="vPlan">No Plan</strong></div>
                                        <div class="tenant-view-plan-line"><span>Status</span><strong id="vSubscriptionStatus">—</strong></div>
                                        <div class="tenant-view-plan-line"><span>Amount</span><strong id="vSubscriptionAmount">—</strong></div>
                                        <div class="tenant-view-plan-line"><span>Billing Cycle</span><strong id="vBillingCycle">—</strong></div>
                                        <div class="tenant-view-plan-line"><span>Start Date</span><strong id="vStartDate">—</strong></div>
                                        <div class="tenant-view-plan-line"><span>Expiry Date</span><strong id="vExpiryDate">—</strong></div>
                                        <div class="tenant-view-plan-line"><span>Trial End</span><strong id="vTrialEnd">—</strong></div>
                                        <div class="tenant-view-plan-line"><span>Auto Renew</span><strong id="vAutoRenew">—</strong></div>
                                        <div class="tenant-view-plan-line"><span>Max Users</span><strong id="vMaxUsers">—</strong></div>
                                        <div class="tenant-view-plan-line"><span>Max Branches</span><strong id="vMaxBranches">—</strong></div>
                                    </div>
                                </div>
                            </section>

                            <section class="tenant-view-card">
                                <div class="tenant-view-card-header">
                                    <span class="tenant-view-card-icon"><i class="bi bi-image"></i></span>
                                    <span><h3 class="tenant-view-card-title">Branding</h3><span class="tenant-view-card-subtitle">Business and invoice logos</span></span>
                                </div>
                                <div class="tenant-view-card-body">
                                    <div class="tenant-view-logo-grid">
                                        <div>
                                            <div class="tenant-view-label">Business Logo</div>
                                            <div class="tenant-view-logo" id="businessLogoBox"><span class="tenant-view-empty">No logo</span></div>
                                        </div>
                                        <div>
                                            <div class="tenant-view-label">Invoice Logo</div>
                                            <div class="tenant-view-logo" id="invoiceLogoBox"><span class="tenant-view-empty">No logo</span></div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="tenant-view-card">
                                <div class="tenant-view-card-header">
                                    <span class="tenant-view-card-icon"><i class="bi bi-clock-history"></i></span>
                                    <span><h3 class="tenant-view-card-title">Record Information</h3><span class="tenant-view-card-subtitle">Tenant database timestamps</span></span>
                                </div>
                                <div class="tenant-view-card-body">
                                    <div class="tenant-view-plan-lines">
                                        <div class="tenant-view-plan-line"><span>Created</span><strong id="vCreatedAt">—</strong></div>
                                        <div class="tenant-view-plan-line"><span>Updated</span><strong id="vUpdatedAt">—</strong></div>
                                    </div>
                                </div>
                            </section>
                        </aside>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    var tenantId = <?= (int) $tenantId; ?>;
    var API_URL = 'api/tenant-view.php';

    var body = document.body;
    var toggle = document.getElementById('fpSidebarToggle');
    var close = document.getElementById('fpSidebarClose');
    var overlay = document.getElementById('fpSidebarOverlay');
    var SIDEBAR_STORAGE_KEY = 'fieldplx_sidebar_collapsed';

    function restoreSidebarState() {
        if (window.innerWidth < 992) {
            body.classList.remove('fp-sidebar-collapsed');
            return;
        }
        if (localStorage.getItem(SIDEBAR_STORAGE_KEY) === '1') {
            body.classList.add('fp-sidebar-collapsed');
        } else {
            body.classList.remove('fp-sidebar-collapsed');
        }
    }

    restoreSidebarState();

    if (toggle) {
        toggle.addEventListener('click', function () {
            if (window.innerWidth < 992) {
                body.classList.toggle('fp-sidebar-mobile-open');
                return;
            }
            body.classList.toggle('fp-sidebar-collapsed');
            localStorage.setItem(
                SIDEBAR_STORAGE_KEY,
                body.classList.contains('fp-sidebar-collapsed') ? '1' : '0'
            );
        });
    }

    if (close) close.addEventListener('click', function () { body.classList.remove('fp-sidebar-mobile-open'); });
    if (overlay) overlay.addEventListener('click', function () { body.classList.remove('fp-sidebar-mobile-open'); });

    document.querySelectorAll('.fp-sidebar-menu-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            var menu = button.closest('.fp-sidebar-menu');
            if (menu) menu.classList.toggle('open');
        });
    });

    var alertBox = document.getElementById('tenantAlert');
    var loadingState = document.getElementById('tenantLoadingState');
    var content = document.getElementById('tenantContent');

    function showAlert(message) {
        alertBox.className = 'tenant-alert show error';
        alertBox.textContent = message;
    }

    function text(id, value) {
        var element = document.getElementById(id);
        if (element) {
            var output = value === null || value === undefined || value === '' ? '—' : String(value);
            element.textContent = output;
        }
    }

    function label(value) {
        if (!value) return '—';
        return String(value)
            .replace(/[_-]+/g, ' ')
            .replace(/\b\w/g, function (letter) { return letter.toUpperCase(); });
    }

    function dateText(value, withTime) {
        if (!value) return '—';

        var normalized = String(value).replace(' ', 'T');
        var date = new Date(normalized);

        if (isNaN(date.getTime())) return String(value);

        return date.toLocaleString(
            undefined,
            withTime
                ? { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' }
                : { day:'2-digit', month:'short', year:'numeric' }
        );
    }

    function amountText(amount, currency, symbol) {
        if (amount === null || amount === undefined || amount === '') return '—';

        var prefix = symbol || currency || '';
        var number = Number(amount || 0).toLocaleString(
            undefined,
            { minimumFractionDigits:2, maximumFractionDigits:2 }
        );

        return (prefix ? prefix + ' ' : '') + number;
    }

    function renderLogo(boxId, path) {
        var box = document.getElementById(boxId);
        box.innerHTML = '';

        if (!path) {
            var empty = document.createElement('span');
            empty.className = 'tenant-view-empty';
            empty.textContent = 'No logo';
            box.appendChild(empty);
            return;
        }

        var image = document.createElement('img');
        image.src = path;
        image.alt = '';
        image.onerror = function () {
            box.innerHTML = '';
            var empty = document.createElement('span');
            empty.className = 'tenant-view-empty';
            empty.textContent = 'Logo unavailable';
            box.appendChild(empty);
        };
        box.appendChild(image);
    }

    function renderBranches(branches) {
        var bodyElement = document.getElementById('branchTableBody');
        bodyElement.innerHTML = '';

        if (!branches || branches.length === 0) {
            var row = document.createElement('tr');
            var cell = document.createElement('td');
            cell.colSpan = 5;
            cell.className = 'tenant-view-empty';
            cell.textContent = 'No branches found.';
            row.appendChild(cell);
            bodyElement.appendChild(row);
            return;
        }

        branches.forEach(function (branch) {
            var row = document.createElement('tr');

            [
                branch.name || '—',
                branch.branch_code || '—',
                [branch.city, branch.state].filter(Boolean).join(', ') || '—',
                Number(branch.is_head_office || 0) === 1 ? 'Yes' : 'No',
                label(branch.status)
            ].forEach(function (value) {
                var cell = document.createElement('td');
                cell.textContent = value;
                row.appendChild(cell);
            });

            bodyElement.appendChild(row);
        });
    }

    fetch(
        API_URL + '?id=' + encodeURIComponent(tenantId),
        {
            method:'GET',
            credentials:'same-origin',
            headers:{'X-Requested-With':'XMLHttpRequest'}
        }
    )
    .then(function (response) {
        return response.json().then(function (data) {
            return { ok: response.ok, data: data };
        });
    })
    .then(function (result) {
        if (!result.ok || !result.data.success) {
            throw new Error(result.data.message || 'Unable to load tenant.');
        }

        var data = result.data.data || {};
        var tenant = data.tenant || {};
        var subscription = data.subscription || {};

        document.getElementById('tenantTitle').textContent =
            tenant.display_name || tenant.legal_name || 'Tenant Details';

        document.getElementById('tenantSubtitle').textContent =
            (tenant.tenant_code || '—') +
            (tenant.business_type ? ' · ' + tenant.business_type : '');

        var badge = document.getElementById('tenantStatusBadge');
        badge.textContent = label(tenant.status);
        badge.className = 'tenant-view-status ' + String(tenant.status || '').toLowerCase();

        text('summaryUsers', data.counts ? data.counts.users : 0);
        text('summaryBranches', data.counts ? data.counts.branches : 0);
        text('summaryPlan', subscription.plan_name || 'No Plan');
        text('summaryJoined', dateText(tenant.created_at, false));

        text('vTenantCode', tenant.tenant_code);
        text('vBusinessType', tenant.business_type);
        text('vLegalName', tenant.legal_name);
        text('vDisplayName', tenant.display_name);
        text('vRegistration', tenant.registration_number);
        text('vTaxNumber', tenant.tax_number);
        text('vEmail', tenant.email);
        text('vWebsite', tenant.website_url);
        text('vPhone', tenant.phone);
        text('vAlternatePhone', tenant.alternate_phone);

        text(
            'vCountry',
            tenant.country_name
                ? tenant.country_name + (tenant.country_iso2 ? ' (' + tenant.country_iso2 + ')' : '')
                : '—'
        );

        text(
            'vCurrency',
            tenant.currency_code
                ? tenant.currency_code + (tenant.currency_name ? ' - ' + tenant.currency_name : '')
                : '—'
        );

        text('vTimezone', tenant.timezone);
        text('vDateFormat', tenant.date_format);
        text('vCity', tenant.city);
        text('vState', tenant.state);
        text('vAddress', [tenant.address_line1, tenant.address_line2].filter(Boolean).join(', ') || '—');
        text('vPostalCode', tenant.postal_code);

        text('vPlan', subscription.plan_name || 'No Plan');
        text('vSubscriptionStatus', label(subscription.status));
        text(
            'vSubscriptionAmount',
            subscription.id
                ? amountText(subscription.amount, tenant.currency_code, tenant.currency_symbol)
                : '—'
        );
        text('vBillingCycle', label(subscription.billing_cycle));
        text('vStartDate', dateText(subscription.start_date, false));
        text('vExpiryDate', dateText(subscription.expiry_date, false));
        text('vTrialEnd', dateText(subscription.trial_end_date, false));
        text('vAutoRenew', subscription.id ? (Number(subscription.auto_renew || 0) === 1 ? 'Yes' : 'No') : '—');
        text('vMaxUsers', subscription.id ? ((subscription.max_users === null || subscription.max_users === '') ? 'Unlimited' : subscription.max_users) : '—');
        text('vMaxBranches', subscription.id ? ((subscription.max_branches === null || subscription.max_branches === '') ? 'Unlimited' : subscription.max_branches) : '—');

        renderLogo('businessLogoBox', tenant.logo_path || '');
        renderLogo('invoiceLogoBox', tenant.invoice_logo_path || '');

        text('vCreatedAt', dateText(tenant.created_at, true));
        text('vUpdatedAt', dateText(tenant.updated_at, true));

        renderBranches(data.branches || []);

        loadingState.style.display = 'none';
        content.style.display = '';
    })
    .catch(function (error) {
        loadingState.style.display = 'none';
        showAlert(error.message || 'Unable to load tenant.');
    });
})();
</script>
</body>
</html>
