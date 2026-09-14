<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Add / Edit Client';
$pageDescription = 'Create or edit client details, contacts, communication preferences and service locations';
$activePage = 'clients';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (empty($_SESSION['clients_csrf_token'])) {
  $_SESSION['clients_csrf_token'] = bin2hex(random_bytes(32));
}

$clientsCsrfToken = (string) $_SESSION['clients_csrf_token'];
$clientFormBasePath = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])) : '';
$clientFormBasePath = $clientFormBasePath === '/' ? '' : rtrim($clientFormBasePath, '/');
$clientFormApiUrl = $clientFormBasePath . '/api/client-form.php';

require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
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
  

    /* ==========================================================
       FieldPlx current template + dynamic light/dark theme
       ========================================================== */
    :root {
      --cf-green: var(--primary, #08a5d2);
      --cf-green-dark: var(--primary, #08a5d2);
      --cf-green-soft: color-mix(in srgb, var(--primary, #08a5d2) 12%, var(--card-bg, #ffffff));
      --cf-text: var(--text, #0b2b37);
      --cf-muted: var(--muted, #647787);
      --cf-border: var(--card-border, #dce3e7);
      --cf-soft: var(--body-bg, #f6f8fb);
      --cf-card-soft: color-mix(in srgb, var(--card-bg, #ffffff) 94%, var(--body-bg, #f6f8fb));
    }

    body {
      background: var(--body-bg, #f6f8fb) !important;
      color: var(--text, #0b2b37) !important;
      font-family: inherit !important;
    }

    .fdj-page {
      width: 100%;
      max-width: 1220px;
      margin: 0 auto;
      padding: 24px 24px 104px;
      background: transparent !important;
      color: var(--text, #0b2b37);
    }

    .fdj-title,
    .fdj-section-side h2,
    .fdj-subheading,
    .fdj-accordion-head,
    .fdj-modal-head h3,
    .fdj-setting-title,
    .fdj-contact-copy strong {
      color: var(--text, #0b2b37) !important;
    }

    .fdj-section-side p,
    .fdj-muted,
    .fdj-empty-note,
    .fdj-contact-copy small,
    .fdj-tax-caption,
    .fdj-floating-label,
    .fdj-phone-label,
    .fdj-detail-label,
    .fdj-modal-copy,
    .fdj-setting-row,
    .fdj-phone-sms,
    .fdj-check {
      color: var(--muted, #647787) !important;
    }

    .fdj-section,
    .fdj-property-block + .fdj-property-block {
      border-color: var(--card-border, #dce3e7) !important;
    }

    .fdj-group,
    .fdj-address-group,
    .fdj-contact-card,
    .fdj-setting-box,
    .fdj-modal,
    .fdj-pop-menu,
    .fdj-billing-box,
    .fdj-accordion {
      border-color: var(--card-border, #dce3e7) !important;
      background: var(--card-bg, #ffffff) !important;
      color: var(--text, #0b2b37) !important;
      box-shadow: none;
    }

    .fdj-accordion,
    .fdj-billing-box {
      background: color-mix(in srgb, var(--card-bg, #ffffff) 96%, var(--body-bg, #f6f8fb)) !important;
    }

    .fdj-name-grid .fdj-group-field,
    .fdj-company-row,
    .fdj-address-row,
    .fdj-address-row > * + * {
      border-color: var(--input-border, var(--card-border, #dce3e7)) !important;
    }

    .fdj-input,
    .fdj-select,
    .fdj-textarea,
    .fdj-phone-type,
    .fdj-group .fdj-input,
    .fdj-group .fdj-select,
    .fdj-address-row .fdj-input,
    .fdj-address-row .fdj-select {
      border-color: var(--input-border, #dce3e7) !important;
      background: var(--input-bg, var(--card-bg, #ffffff)) !important;
      color: var(--text, #0b2b37) !important;
      box-shadow: none !important;
    }

    .fdj-input::placeholder,
    .fdj-textarea::placeholder {
      color: color-mix(in srgb, var(--muted, #647787) 82%, transparent) !important;
      opacity: 1;
    }

    .fdj-input:focus,
    .fdj-select:focus,
    .fdj-textarea:focus,
    .fdj-phone-type:focus,
    .fdj-group-field:focus-within {
      border-color: var(--primary, #08a5d2) !important;
      box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary, #08a5d2) 14%, transparent) !important;
    }

    .fdj-btn,
    .fdj-phone-action,
    .fdj-select-tags-btn {
      border-color: var(--button-border, var(--card-border, #dce3e7)) !important;
      background: var(--button-bg, var(--card-bg, #ffffff)) !important;
      color: var(--text, #0b2b37) !important;
      box-shadow: none !important;
    }

    .fdj-btn:hover,
    .fdj-phone-action:hover {
      border-color: var(--primary, #08a5d2) !important;
      background: var(--button-hover-bg, color-mix(in srgb, var(--primary, #08a5d2) 8%, var(--card-bg, #ffffff))) !important;
      color: var(--primary, #08a5d2) !important;
    }

    .fdj-btn.primary {
      border-color: var(--primary, #08a5d2) !important;
      background: var(--primary, #08a5d2) !important;
      color: var(--primary-text, #ffffff) !important;
    }

    .fdj-btn.primary:hover {
      border-color: var(--primary, #08a5d2) !important;
      background: var(--primary, #08a5d2) !important;
      color: var(--primary-text, #ffffff) !important;
      filter: brightness(.94);
    }

    .fdj-btn.danger {
      border-color: color-mix(in srgb, var(--danger, #e1554f) 45%, var(--card-border, #dce3e7)) !important;
      background: var(--button-bg, var(--card-bg, #ffffff)) !important;
      color: var(--danger, #e1554f) !important;
    }

    .fdj-link-button,
    .fdj-accordion-head i,
    .fdj-setting-title button,
    .fdj-icon-btn:hover,
    .fdj-pop-create {
      color: var(--primary, #08a5d2) !important;
    }

    .fdj-contact-avatar {
      background: color-mix(in srgb, var(--primary, #08a5d2) 13%, var(--card-bg, #ffffff)) !important;
      color: var(--primary, #08a5d2) !important;
    }

    .fdj-phone-sms-toggle,
    .fdj-switch {
      background: color-mix(in srgb, var(--muted, #647787) 32%, var(--card-bg, #ffffff)) !important;
    }

    .fdj-phone-sms-toggle.on,
    .fdj-switch.on {
      background: var(--primary, #08a5d2) !important;
    }

    .fdj-pop-option,
    .fdj-pop-create {
      border-color: var(--card-border, #dce3e7) !important;
      background: var(--card-bg, #ffffff) !important;
      color: var(--text, #0b2b37) !important;
    }

    .fdj-pop-option:hover,
    .fdj-icon-btn:hover {
      background: var(--table-hover-bg, color-mix(in srgb, var(--primary, #08a5d2) 8%, var(--card-bg, #ffffff))) !important;
    }

    .fdj-modal-backdrop {
      background: rgba(2, 12, 18, .58) !important;
      backdrop-filter: blur(2px);
    }

    .fdj-savebar {
      position: fixed !important;
      left: var(--fieldplx-sidebar-width, 0px) !important;
      right: 0 !important;
      bottom: 0 !important;
      z-index: 1200;
      width: auto !important;
      min-height: 70px;
      margin: 0 !important;
      padding: 10px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      border-top: 1px solid var(--card-border, #dce3e7) !important;
      background: color-mix(in srgb, var(--card-bg, #ffffff) 96%, transparent) !important;
      box-shadow: 0 -8px 24px rgba(0,0,0,.08);
      backdrop-filter: blur(12px);
    }

    body.fieldplx-sidebar-collapsed .fdj-savebar {
      left: var(--fieldplx-sidebar-collapsed-width, 0px) !important;
    }

    @media (max-width: 991.98px) {
      .fdj-savebar,
      body.fieldplx-sidebar-collapsed .fdj-savebar {
        left: 0 !important;
      }
    }

    .fdj-info {
      border: 1px solid color-mix(in srgb, var(--primary, #08a5d2) 28%, var(--card-border, #dce3e7));
      background: color-mix(in srgb, var(--primary, #08a5d2) 9%, var(--card-bg, #ffffff)) !important;
      color: var(--text, #0b2b37) !important;
    }

    select option {
      background: var(--card-bg, #ffffff);
      color: var(--text, #0b2b37);
    }

    html.app-dark-mode .fdj-page {
      color: #dce8ee;
    }

    html.app-dark-mode .fdj-group,
    html.app-dark-mode .fdj-address-group,
    html.app-dark-mode .fdj-contact-card,
    html.app-dark-mode .fdj-setting-box,
    html.app-dark-mode .fdj-modal,
    html.app-dark-mode .fdj-pop-menu {
      box-shadow: 0 12px 34px rgba(0,0,0,.16);
    }

    html.app-dark-mode .fdj-phone-star {
      color: #e3c74b !important;
    }

    html.app-dark-mode .fdj-btn.danger,
    html.app-dark-mode .fdj-phone-action.delete {
      color: #ff8c85 !important;
    }

    html.app-dark-mode .fdj-btn.danger:hover,
    html.app-dark-mode .fdj-icon-btn.danger:hover,
    html.app-dark-mode .fdj-phone-action.delete:hover {
      background: #3b2426 !important;
      border-color: #6c4041 !important;
      color: #ffaaa5 !important;
    }

    @media (max-width: 767.98px) {
      .fdj-page { padding: 18px 12px 118px; }
      .fdj-savebar { padding: 10px 12px; }
    }

</style>
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
      var clientFormApiUrl = <?= json_encode($clientFormApiUrl) ?>;
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
          var text = String(raw || '').trim();
          var data = null;

          if (text !== '') {
            try {
              data = JSON.parse(text);
            } catch (e) {
              var clean = text.replace(/<br\s*\/?>/gi, ' ').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
              if (response.redirected || /<html|<!doctype/i.test(text)) {
                throw new Error('The Client API returned a web page instead of JSON. Refresh the page and sign in again if your session expired.');
              }
              throw new Error(clean ? clean.substring(0, 260) : 'Invalid server response.');
            }
          }

          if (!data) {
            throw new Error('Client form API returned an empty response (HTTP ' + response.status + '). Check api/client-form.php and the PHP error log.');
          }
          if (!response.ok || data.success !== true) {
            throw new Error(data.message || ('Client request failed (HTTP ' + response.status + ').'));
          }
          return data;
        });
      }

      function request(formData) {
        if (!formData.has('csrf_token')) formData.append('csrf_token', csrfToken);
        return fetch(clientFormApiUrl, {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
          cache: 'no-store',
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
        if (!editMode) {
          clientContacts = [];
          phoneNumbers = [blankPhoneNumber()];
          locations = [blankLocation()];
          renderClientContacts();
          renderPhoneNumbers();
          renderLocations();
        }

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

      function syncSavebarOffset() {
        var bar = document.querySelector('.fdj-savebar');
        if (!bar) return;
        if (window.innerWidth <= 991) {
          bar.style.setProperty('left', '0px', 'important');
          return;
        }
        var sidebar = document.querySelector('.fieldplx-sidebar, .app-sidebar, #sidebar, aside.sidebar');
        if (sidebar) {
          var rect = sidebar.getBoundingClientRect();
          var left = Math.max(0, Math.round(rect.right));
          bar.style.setProperty('left', left + 'px', 'important');
        }
      }

      window.addEventListener('resize', syncSavebarOffset);
      var shellObserver = new MutationObserver(syncSavebarOffset);
      shellObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
      syncSavebarOffset();

      updateMode();
      setCommunicationUI();
      load();
    })();
  </script>

<?php require __DIR__ . '/includes/footer.php'; ?>
