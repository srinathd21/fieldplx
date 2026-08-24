<?php
/**
 * FieldPlx Platform - Add Tenant
 * PHP 7.2+
 * PDO
 */

require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Add Tenant';
$activePage = 'tenants';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['tenant_add_csrf']) ||
    !is_string($_SESSION['tenant_add_csrf'])
) {
    $_SESSION['tenant_add_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['tenant_add_csrf'];

/*
|--------------------------------------------------------------------------
| Dropdown data
|--------------------------------------------------------------------------
*/

$countries = array();
$currencies = array();
$plans = array();

$countryStmt = $pdo->query("
    SELECT
        id,
        name,
        iso2,
        phone_code,
        default_currency_code,
        default_timezone,
        date_format
    FROM countries
    WHERE is_active = 1
    ORDER BY name ASC
");

$countries = $countryStmt->fetchAll();

$currencyStmt = $pdo->query("
    SELECT
        id,
        currency_code,
        currency_name,
        symbol
    FROM currencies
    WHERE is_active = 1
    ORDER BY currency_code ASC
");

$currencies = $currencyStmt->fetchAll();

$planStmt = $pdo->query("
    SELECT
        id,
        name,
        code,
        price,
        currency,
        billing_cycle,
        trial_days,
        max_users,
        max_branches,
        storage_limit_mb
    FROM plans
    WHERE deleted_at IS NULL
      AND status = 'active'
    ORDER BY
        is_featured DESC,
        price ASC,
        name ASC
");

$plans = $planStmt->fetchAll();

function tenantAddEscape($value)
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

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        <?= tenantAddEscape($pageTitle); ?> - FieldPlx
    </title>

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
    </style>
</head>

<body>

<div class="fp-layout">

    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="fp-main">

        <?php require_once __DIR__ . '/includes/topbar.php'; ?>

        <div class="fp-content">

            <div class="tenant-add-page">

                <div class="tenant-add-header">
                    <div>
                        <h2 class="tenant-add-title">
                            Add Tenant
                        </h2>

                        <div class="tenant-add-description">
                            Create a new FieldPlx business workspace
                            and optionally start its subscription.
                        </div>
                    </div>

                    <a
                        href="tenants.php"
                        class="tenant-back-button"
                    >
                        <i class="bi bi-arrow-left"></i>
                        Back to Tenants
                    </a>
                </div>

                <div
                    class="tenant-alert"
                    id="tenantAlert"
                    role="alert"
                ></div>

                <form
                    id="tenantAddForm"
                    enctype="multipart/form-data"
                    novalidate
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= tenantAddEscape($csrfToken); ?>"
                    >

                    <div class="tenant-form-layout">

                        <div class="tenant-form-column">

                            <!-- Business Details -->
                            <section class="tenant-form-card">
                                <div class="tenant-form-card-header">
                                    <span class="tenant-form-card-icon">
                                        <i class="bi bi-buildings"></i>
                                    </span>

                                    <span>
                                        <h3 class="tenant-form-card-title">
                                            Business Details
                                        </h3>

                                        <span class="tenant-form-card-subtitle">
                                            Core tenant identity and business information
                                        </span>
                                    </span>
                                </div>

                                <div class="tenant-form-card-body">
                                    <div class="tenant-form-grid">

                                        <div class="tenant-field">
                                            <label for="tenantCode">
                                                Tenant Code
                                                <span class="required">*</span>
                                            </label>

                                            <div class="tenant-code-row">
                                                <input
                                                    type="text"
                                                    class="tenant-input"
                                                    id="tenantCode"
                                                    name="tenant_code"
                                                    maxlength="80"
                                                    placeholder="TNT-00001"
                                                    required
                                                >

                                                <button
                                                    type="button"
                                                    class="tenant-generate-code"
                                                    id="generateTenantCode"
                                                >
                                                    Generate
                                                </button>
                                            </div>

                                            <div class="tenant-field-note">
                                                Must be unique. You can also enter your own code.
                                            </div>
                                        </div>

                                        <div class="tenant-field">
                                            <label for="businessType">
                                                Business Type
                                            </label>

                                            <input
                                                type="text"
                                                class="tenant-input"
                                                id="businessType"
                                                name="business_type"
                                                maxlength="120"
                                                placeholder="HVAC, Plumbing, Electrical..."
                                            >
                                        </div>

                                        <div class="tenant-field">
                                            <label for="legalName">
                                                Legal Name
                                                <span class="required">*</span>
                                            </label>

                                            <input
                                                type="text"
                                                class="tenant-input"
                                                id="legalName"
                                                name="legal_name"
                                                maxlength="190"
                                                placeholder="Registered business name"
                                                required
                                            >
                                        </div>

                                        <div class="tenant-field">
                                            <label for="displayName">
                                                Display Name
                                                <span class="required">*</span>
                                            </label>

                                            <input
                                                type="text"
                                                class="tenant-input"
                                                id="displayName"
                                                name="display_name"
                                                maxlength="190"
                                                placeholder="Name shown inside FieldPlx"
                                                required
                                            >
                                        </div>

                                        <div class="tenant-field">
                                            <label for="registrationNumber">
                                                Registration Number
                                            </label>

                                            <input
                                                type="text"
                                                class="tenant-input"
                                                id="registrationNumber"
                                                name="registration_number"
                                                maxlength="120"
                                                placeholder="Company registration number"
                                            >
                                        </div>

                                        <div class="tenant-field">
                                            <label for="taxNumber">
                                                Tax Number
                                            </label>

                                            <input
                                                type="text"
                                                class="tenant-input"
                                                id="taxNumber"
                                                name="tax_number"
                                                maxlength="120"
                                                placeholder="GST / VAT / Tax ID"
                                            >
                                        </div>

                                    </div>
                                </div>
                            </section>

                            <!-- Contact Details -->
                            <section class="tenant-form-card">
                                <div class="tenant-form-card-header">
                                    <span class="tenant-form-card-icon">
                                        <i class="bi bi-person-lines-fill"></i>
                                    </span>

                                    <span>
                                        <h3 class="tenant-form-card-title">
                                            Contact Details
                                        </h3>

                                        <span class="tenant-form-card-subtitle">
                                            Business communication information
                                        </span>
                                    </span>
                                </div>

                                <div class="tenant-form-card-body">
                                    <div class="tenant-form-grid">

                                        <div class="tenant-field">
                                            <label for="email">
                                                Email
                                            </label>

                                            <input
                                                type="email"
                                                class="tenant-input"
                                                id="email"
                                                name="email"
                                                maxlength="190"
                                                placeholder="admin@company.com"
                                            >
                                        </div>

                                        <div class="tenant-field">
                                            <label for="websiteUrl">
                                                Website
                                            </label>

                                            <input
                                                type="url"
                                                class="tenant-input"
                                                id="websiteUrl"
                                                name="website_url"
                                                maxlength="255"
                                                placeholder="https://company.com"
                                            >
                                        </div>

                                        <div class="tenant-field">
                                            <label for="phone">
                                                Phone
                                            </label>

                                            <input
                                                type="text"
                                                class="tenant-input"
                                                id="phone"
                                                name="phone"
                                                maxlength="50"
                                                placeholder="+91..."
                                            >
                                        </div>

                                        <div class="tenant-field">
                                            <label for="alternatePhone">
                                                Alternate Phone
                                            </label>

                                            <input
                                                type="text"
                                                class="tenant-input"
                                                id="alternatePhone"
                                                name="alternate_phone"
                                                maxlength="50"
                                                placeholder="Optional alternate number"
                                            >
                                        </div>

                                    </div>
                                </div>
                            </section>

                            <!-- Location & Localization -->
                            <section class="tenant-form-card">
                                <div class="tenant-form-card-header">
                                    <span class="tenant-form-card-icon">
                                        <i class="bi bi-geo-alt"></i>
                                    </span>

                                    <span>
                                        <h3 class="tenant-form-card-title">
                                            Location & Localization
                                        </h3>

                                        <span class="tenant-form-card-subtitle">
                                            Country, currency, timezone and business address
                                        </span>
                                    </span>
                                </div>

                                <div class="tenant-form-card-body">

                                    <div class="tenant-form-grid three">

                                        <div class="tenant-field">
                                            <label for="countryId">
                                                Country
                                                <span class="required">*</span>
                                            </label>

                                            <select
                                                class="tenant-select"
                                                id="countryId"
                                                name="country_id"
                                                required
                                            >
                                                <option value="">
                                                    Select country
                                                </option>

                                                <?php foreach ($countries as $country): ?>
                                                    <option
                                                        value="<?= (int) $country['id']; ?>"
                                                        data-currency="<?= tenantAddEscape($country['default_currency_code']); ?>"
                                                        data-timezone="<?= tenantAddEscape($country['default_timezone']); ?>"
                                                        data-date-format="<?= tenantAddEscape($country['date_format']); ?>"
                                                        data-phone-code="<?= tenantAddEscape($country['phone_code']); ?>"
                                                    >
                                                        <?= tenantAddEscape($country['name']); ?>
                                                        (<?= tenantAddEscape($country['iso2']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="tenant-field">
                                            <label for="currencyId">
                                                Currency
                                                <span class="required">*</span>
                                            </label>

                                            <select
                                                class="tenant-select"
                                                id="currencyId"
                                                name="currency_id"
                                                required
                                            >
                                                <option value="">
                                                    Select currency
                                                </option>

                                                <?php foreach ($currencies as $currency): ?>
                                                    <option
                                                        value="<?= (int) $currency['id']; ?>"
                                                        data-code="<?= tenantAddEscape($currency['currency_code']); ?>"
                                                    >
                                                        <?= tenantAddEscape($currency['currency_code']); ?>
                                                        -
                                                        <?= tenantAddEscape($currency['currency_name']); ?>
                                                        (<?= tenantAddEscape($currency['symbol']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="tenant-field">
                                            <label for="timezone">
                                                Timezone
                                                <span class="required">*</span>
                                            </label>

                                            <input
                                                type="text"
                                                class="tenant-input"
                                                id="timezone"
                                                name="timezone"
                                                maxlength="100"
                                                value="UTC"
                                                required
                                            >
                                        </div>

                                        <div class="tenant-field">
                                            <label for="dateFormat">
                                                Date Format
                                                <span class="required">*</span>
                                            </label>

                                            <select
                                                class="tenant-select"
                                                id="dateFormat"
                                                name="date_format"
                                                required
                                            >
                                                <option value="d-m-Y">
                                                    DD-MM-YYYY
                                                </option>
                                                <option value="d/m/Y">
                                                    DD/MM/YYYY
                                                </option>
                                                <option value="m-d-Y">
                                                    MM-DD-YYYY
                                                </option>
                                                <option value="m/d/Y">
                                                    MM/DD/YYYY
                                                </option>
                                                <option value="Y-m-d">
                                                    YYYY-MM-DD
                                                </option>
                                            </select>
                                        </div>

                                        <div class="tenant-field">
                                            <label for="city">
                                                City
                                            </label>

                                            <input
                                                type="text"
                                                class="tenant-input"
                                                id="city"
                                                name="city"
                                                maxlength="120"
                                                placeholder="City"
                                            >
                                        </div>

                                        <div class="tenant-field">
                                            <label for="state">
                                                State / Province
                                            </label>

                                            <input
                                                type="text"
                                                class="tenant-input"
                                                id="state"
                                                name="state"
                                                maxlength="120"
                                                placeholder="State or province"
                                            >
                                        </div>

                                        <div class="tenant-field full">
                                            <label for="addressLine1">
                                                Address Line 1
                                            </label>

                                            <input
                                                type="text"
                                                class="tenant-input"
                                                id="addressLine1"
                                                name="address_line1"
                                                maxlength="255"
                                                placeholder="Street address"
                                            >
                                        </div>

                                        <div class="tenant-field full">
                                            <label for="addressLine2">
                                                Address Line 2
                                            </label>

                                            <input
                                                type="text"
                                                class="tenant-input"
                                                id="addressLine2"
                                                name="address_line2"
                                                maxlength="255"
                                                placeholder="Suite, unit, area..."
                                            >
                                        </div>

                                        <div class="tenant-field">
                                            <label for="postalCode">
                                                Postal Code
                                            </label>

                                            <input
                                                type="text"
                                                class="tenant-input"
                                                id="postalCode"
                                                name="postal_code"
                                                maxlength="40"
                                                placeholder="Postal code"
                                            >
                                        </div>

                                        <div class="tenant-field">
                                            <label for="tenantStatus">
                                                Tenant Status
                                                <span class="required">*</span>
                                            </label>

                                            <select
                                                class="tenant-select"
                                                id="tenantStatus"
                                                name="status"
                                                required
                                            >
                                                <option value="trial" selected>
                                                    Trial
                                                </option>
                                                <option value="active">
                                                    Active
                                                </option>
                                                <option value="suspended">
                                                    Suspended
                                                </option>
                                            </select>
                                        </div>

                                    </div>
                                </div>
                            </section>

                            <!-- Branding -->
                            <section class="tenant-form-card">
                                <div class="tenant-form-card-header">
                                    <span class="tenant-form-card-icon">
                                        <i class="bi bi-image"></i>
                                    </span>

                                    <span>
                                        <h3 class="tenant-form-card-title">
                                            Branding
                                        </h3>

                                        <span class="tenant-form-card-subtitle">
                                            Optional tenant logo and invoice logo
                                        </span>
                                    </span>
                                </div>

                                <div class="tenant-form-card-body">
                                    <div class="tenant-form-grid">

                                        <div class="tenant-field">
                                            <label for="logo">
                                                Business Logo
                                            </label>

                                            <input
                                                type="file"
                                                class="tenant-input"
                                                id="logo"
                                                name="logo"
                                                accept=".jpg,.jpeg,.png,.webp"
                                            >

                                            <div class="tenant-field-note">
                                                JPG, PNG or WEBP. Maximum 3 MB.
                                            </div>
                                        </div>

                                        <div class="tenant-field">
                                            <label for="invoiceLogo">
                                                Invoice Logo
                                            </label>

                                            <input
                                                type="file"
                                                class="tenant-input"
                                                id="invoiceLogo"
                                                name="invoice_logo"
                                                accept=".jpg,.jpeg,.png,.webp"
                                            >

                                            <div class="tenant-field-note">
                                                If empty, the normal business logo can be used later.
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </section>

                        </div>

                        <aside class="tenant-form-column tenant-side-column">

                            <!-- Subscription -->
                            <section class="tenant-form-card">
                                <div class="tenant-form-card-header">
                                    <span class="tenant-form-card-icon">
                                        <i class="bi bi-credit-card"></i>
                                    </span>

                                    <span>
                                        <h3 class="tenant-form-card-title">
                                            Subscription
                                        </h3>

                                        <span class="tenant-form-card-subtitle">
                                            Optional initial plan assignment
                                        </span>
                                    </span>
                                </div>

                                <div class="tenant-form-card-body">
                                    <div class="tenant-form-grid" style="grid-template-columns:1fr;">

                                        <div class="tenant-field">
                                            <label for="planId">
                                                Plan
                                            </label>

                                            <select
                                                class="tenant-select"
                                                id="planId"
                                                name="plan_id"
                                            >
                                                <option value="">
                                                    Create tenant without plan
                                                </option>

                                                <?php foreach ($plans as $plan): ?>
                                                    <option
                                                        value="<?= (int) $plan['id']; ?>"
                                                        data-name="<?= tenantAddEscape($plan['name']); ?>"
                                                        data-price="<?= tenantAddEscape($plan['price']); ?>"
                                                        data-currency="<?= tenantAddEscape($plan['currency']); ?>"
                                                        data-cycle="<?= tenantAddEscape($plan['billing_cycle']); ?>"
                                                        data-trial-days="<?= (int) $plan['trial_days']; ?>"
                                                        data-users="<?= tenantAddEscape($plan['max_users']); ?>"
                                                        data-branches="<?= tenantAddEscape($plan['max_branches']); ?>"
                                                        data-storage="<?= tenantAddEscape($plan['storage_limit_mb']); ?>"
                                                    >
                                                        <?= tenantAddEscape($plan['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="tenant-field">
                                            <label for="subscriptionStart">
                                                Start Date
                                            </label>

                                            <input
                                                type="date"
                                                class="tenant-input"
                                                id="subscriptionStart"
                                                name="subscription_start"
                                                value="<?= date('Y-m-d'); ?>"
                                            >
                                        </div>

                                        <div class="tenant-field">
                                            <label for="subscriptionExpiry">
                                                Expiry Date
                                            </label>

                                            <input
                                                type="date"
                                                class="tenant-input"
                                                id="subscriptionExpiry"
                                                name="subscription_expiry"
                                            >
                                        </div>

                                        <div class="tenant-field">
                                            <label for="trialEndDate">
                                                Trial End Date
                                            </label>

                                            <input
                                                type="date"
                                                class="tenant-input"
                                                id="trialEndDate"
                                                name="trial_end_date"
                                            >
                                        </div>

                                        <div class="tenant-switch-row">
                                            <div class="tenant-switch-text">
                                                <strong>Auto Renew</strong>
                                                <span>
                                                    Enable recurring renewal flag
                                                </span>
                                            </div>

                                            <div class="form-check form-switch m-0">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    id="autoRenew"
                                                    name="auto_renew"
                                                    value="1"
                                                >
                                            </div>
                                        </div>

                                    </div>

                                    <div class="tenant-info-box">
                                        <i class="bi bi-info-circle"></i>
                                        If no plan is selected, only the tenant workspace is created.
                                        A subscription can be assigned later.
                                    </div>
                                </div>
                            </section>

                            <!-- Plan Preview -->
                            <section class="tenant-form-card">
                                <div class="tenant-form-card-header">
                                    <span class="tenant-form-card-icon">
                                        <i class="bi bi-card-checklist"></i>
                                    </span>

                                    <span>
                                        <h3 class="tenant-form-card-title">
                                            Plan Preview
                                        </h3>

                                        <span class="tenant-form-card-subtitle">
                                            Selected plan details
                                        </span>
                                    </span>
                                </div>

                                <div class="tenant-form-card-body">
                                    <div class="tenant-plan-summary">

                                        <div class="tenant-summary-line">
                                            <span>Plan</span>
                                            <strong id="previewPlan">
                                                No Plan
                                            </strong>
                                        </div>

                                        <div class="tenant-summary-line">
                                            <span>Price</span>
                                            <strong id="previewPrice">
                                                —
                                            </strong>
                                        </div>

                                        <div class="tenant-summary-line">
                                            <span>Billing Cycle</span>
                                            <strong id="previewCycle">
                                                —
                                            </strong>
                                        </div>

                                        <div class="tenant-summary-line">
                                            <span>Trial</span>
                                            <strong id="previewTrial">
                                                —
                                            </strong>
                                        </div>

                                        <div class="tenant-summary-line">
                                            <span>Users</span>
                                            <strong id="previewUsers">
                                                —
                                            </strong>
                                        </div>

                                        <div class="tenant-summary-line">
                                            <span>Branches</span>
                                            <strong id="previewBranches">
                                                —
                                            </strong>
                                        </div>

                                    </div>
                                </div>
                            </section>

                        </aside>

                    </div>

                    <section class="tenant-form-card" style="margin-top:16px;">
                        <div class="tenant-submit-bar">
                            <a
                                href="tenants.php"
                                class="tenant-cancel-button"
                            >
                                Cancel
                            </a>

                            <button
                                type="submit"
                                class="tenant-save-button"
                                id="saveTenantButton"
                            >
                                <span class="tenant-loading"></span>
                                <i class="bi bi-check2-circle"></i>
                                <span id="saveTenantText">
                                    Create Tenant
                                </span>
                            </button>
                        </div>
                    </section>

                </form>

            </div>

        </div>

    </main>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>
(function () {
    'use strict';

    var body = document.body;
    var toggle = document.getElementById('fpSidebarToggle');
    var close = document.getElementById('fpSidebarClose');
    var overlay = document.getElementById('fpSidebarOverlay');

    var SIDEBAR_STORAGE_KEY =
        'fieldplx_sidebar_collapsed';

    function restoreSidebarState() {
        if (window.innerWidth < 992) {
            body.classList.remove('fp-sidebar-collapsed');
            return;
        }

        var savedState =
            localStorage.getItem(SIDEBAR_STORAGE_KEY);

        if (savedState === '1') {
            body.classList.add('fp-sidebar-collapsed');
        } else {
            body.classList.remove('fp-sidebar-collapsed');
        }
    }

    function saveSidebarState() {
        localStorage.setItem(
            SIDEBAR_STORAGE_KEY,
            body.classList.contains('fp-sidebar-collapsed')
                ? '1'
                : '0'
        );
    }

    restoreSidebarState();

    if (toggle) {
        toggle.addEventListener('click', function () {
            if (window.innerWidth < 992) {
                body.classList.toggle('fp-sidebar-mobile-open');
                return;
            }

            body.classList.toggle('fp-sidebar-collapsed');
            saveSidebarState();
        });
    }

    if (close) {
        close.addEventListener('click', function () {
            body.classList.remove('fp-sidebar-mobile-open');
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function () {
            body.classList.remove('fp-sidebar-mobile-open');
        });
    }

    document
        .querySelectorAll('.fp-sidebar-menu-toggle')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                var menu =
                    button.closest('.fp-sidebar-menu');

                if (menu) {
                    menu.classList.toggle('open');
                }
            });
        });

    /*
    |--------------------------------------------------------------------------
    | Tenant form
    |--------------------------------------------------------------------------
    */

    var form = document.getElementById('tenantAddForm');
    var alertBox = document.getElementById('tenantAlert');
    var saveButton = document.getElementById('saveTenantButton');
    var saveText = document.getElementById('saveTenantText');

    var countrySelect = document.getElementById('countryId');
    var currencySelect = document.getElementById('currencyId');
    var timezoneInput = document.getElementById('timezone');
    var dateFormatSelect = document.getElementById('dateFormat');
    var phoneInput = document.getElementById('phone');

    var legalNameInput = document.getElementById('legalName');
    var displayNameInput = document.getElementById('displayName');
    var tenantCodeInput = document.getElementById('tenantCode');
    var generateCodeButton = document.getElementById('generateTenantCode');

    var planSelect = document.getElementById('planId');
    var startInput = document.getElementById('subscriptionStart');
    var trialEndInput = document.getElementById('trialEndDate');

    var previewPlan = document.getElementById('previewPlan');
    var previewPrice = document.getElementById('previewPrice');
    var previewCycle = document.getElementById('previewCycle');
    var previewTrial = document.getElementById('previewTrial');
    var previewUsers = document.getElementById('previewUsers');
    var previewBranches = document.getElementById('previewBranches');

    var displayNameTouched = false;

    function showAlert(type, message) {
        alertBox.className = 'tenant-alert show ' + type;
        alertBox.textContent = message;

        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }

    function hideAlert() {
        alertBox.className = 'tenant-alert';
        alertBox.textContent = '';
    }

    function makeCode() {
        var value = '';

        if (displayNameInput.value.trim() !== '') {
            value = displayNameInput.value.trim();
        } else if (legalNameInput.value.trim() !== '') {
            value = legalNameInput.value.trim();
        }

        var prefix = value
            .replace(/[^A-Za-z0-9]/g, '')
            .substring(0, 3)
            .toUpperCase();

        if (prefix.length < 2) {
            prefix = 'TNT';
        }

        var stamp = String(Date.now()).slice(-6);

        tenantCodeInput.value =
            prefix + '-' + stamp;
    }

    if (generateCodeButton) {
        generateCodeButton.addEventListener(
            'click',
            makeCode
        );
    }

    legalNameInput.addEventListener(
        'input',
        function () {
            if (!displayNameTouched) {
                displayNameInput.value =
                    legalNameInput.value;
            }
        }
    );

    displayNameInput.addEventListener(
        'input',
        function () {
            displayNameTouched = true;
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Country defaults
    |--------------------------------------------------------------------------
    */

    countrySelect.addEventListener(
        'change',
        function () {
            var option =
                countrySelect.options[
                    countrySelect.selectedIndex
                ];

            if (!option || !option.value) {
                return;
            }

            var currencyCode =
                option.getAttribute('data-currency') || '';

            var timezone =
                option.getAttribute('data-timezone') || '';

            var dateFormat =
                option.getAttribute('data-date-format') || '';

            var phoneCode =
                option.getAttribute('data-phone-code') || '';

            if (currencyCode !== '') {
                Array.prototype.forEach.call(
                    currencySelect.options,
                    function (currencyOption) {
                        if (
                            currencyOption.getAttribute(
                                'data-code'
                            ) === currencyCode
                        ) {
                            currencySelect.value =
                                currencyOption.value;
                        }
                    }
                );
            }

            if (timezone !== '') {
                timezoneInput.value = timezone;
            }

            if (dateFormat !== '') {
                var exists = false;

                Array.prototype.forEach.call(
                    dateFormatSelect.options,
                    function (dateOption) {
                        if (
                            dateOption.value ===
                            dateFormat
                        ) {
                            exists = true;
                        }
                    }
                );

                if (exists) {
                    dateFormatSelect.value =
                        dateFormat;
                }
            }

            if (
                phoneCode !== '' &&
                phoneInput.value.trim() === ''
            ) {
                phoneInput.value =
                    phoneCode.charAt(0) === '+'
                        ? phoneCode
                        : '+' + phoneCode;
            }
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Plan preview + trial date
    |--------------------------------------------------------------------------
    */

    function addDays(dateString, days) {
        if (!dateString) {
            return '';
        }

        var date = new Date(
            dateString + 'T00:00:00'
        );

        if (isNaN(date.getTime())) {
            return '';
        }

        date.setDate(
            date.getDate() + Number(days || 0)
        );

        var year = date.getFullYear();
        var month = String(
            date.getMonth() + 1
        ).padStart(2, '0');

        var day = String(
            date.getDate()
        ).padStart(2, '0');

        return year + '-' + month + '-' + day;
    }

    function updatePlanPreview() {
        var option =
            planSelect.options[
                planSelect.selectedIndex
            ];

        if (!option || !option.value) {
            previewPlan.textContent = 'No Plan';
            previewPrice.textContent = '—';
            previewCycle.textContent = '—';
            previewTrial.textContent = '—';
            previewUsers.textContent = '—';
            previewBranches.textContent = '—';
            trialEndInput.value = '';
            return;
        }

        var name =
            option.getAttribute('data-name') || '—';

        var price =
            option.getAttribute('data-price') || '0';

        var currency =
            option.getAttribute('data-currency') || '';

        var cycle =
            option.getAttribute('data-cycle') || '—';

        var trialDays =
            Number(
                option.getAttribute('data-trial-days') ||
                0
            );

        var users =
            option.getAttribute('data-users');

        var branches =
            option.getAttribute('data-branches');

        previewPlan.textContent = name;
        previewPrice.textContent =
            currency + ' ' +
            Number(price).toLocaleString(
                undefined,
                {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }
            );

        previewCycle.textContent =
            cycle.replace(/_/g, ' ');

        previewTrial.textContent =
            trialDays > 0
                ? trialDays + ' days'
                : 'No trial';

        previewUsers.textContent =
            users && users !== ''
                ? users
                : 'Unlimited';

        previewBranches.textContent =
            branches && branches !== ''
                ? branches
                : 'Unlimited';

        if (
            trialDays > 0 &&
            startInput.value !== ''
        ) {
            trialEndInput.value =
                addDays(
                    startInput.value,
                    trialDays
                );
        } else {
            trialEndInput.value = '';
        }
    }

    planSelect.addEventListener(
        'change',
        updatePlanPreview
    );

    startInput.addEventListener(
        'change',
        updatePlanPreview
    );

    updatePlanPreview();

    /*
    |--------------------------------------------------------------------------
    | Submit to separate API
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        function (event) {
            event.preventDefault();

            hideAlert();

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            saveButton.disabled = true;
            saveButton.classList.add('loading');
            saveText.textContent = 'Creating...';

            var formData = new FormData(form);

            fetch(
                'api/tenant-save.php',
                {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            )
            .then(function (response) {
                return response
                    .json()
                    .catch(function () {
                        throw new Error(
                            'Invalid API response.'
                        );
                    })
                    .then(function (data) {
                        return {
                            ok: response.ok,
                            data: data
                        };
                    });
            })
            .then(function (result) {
                if (
                    !result.ok ||
                    !result.data.success
                ) {
                    throw new Error(
                        result.data.message ||
                        'Unable to create tenant.'
                    );
                }

                showAlert(
                    'success',
                    result.data.message ||
                    'Tenant created successfully.'
                );

                window.setTimeout(
                    function () {
                        window.location.href =
                            result.data.redirect ||
                            'tenants.php';
                    },
                    700
                );
            })
            .catch(function (error) {
                showAlert(
                    'error',
                    error.message ||
                    'Unable to create tenant.'
                );

                saveButton.disabled = false;
                saveButton.classList.remove('loading');
                saveText.textContent = 'Create Tenant';
            });
        }
    );

})();
</script>

</body>
</html>
