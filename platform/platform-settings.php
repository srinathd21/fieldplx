<?php
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Platform Settings';
$activePage = 'platform-settings';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['platform_settings_csrf'])) {
    $_SESSION['platform_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['platform_settings_csrf'];

function fp_h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$countries = $pdo->query("
    SELECT id, name, iso2, default_currency_code, default_timezone, date_format
    FROM countries
    WHERE is_active = 1
    ORDER BY name
")->fetchAll();

$currencies = $pdo->query("
    SELECT id, currency_code, currency_name, symbol
    FROM currencies
    WHERE is_active = 1
    ORDER BY currency_code
")->fetchAll();

$settings = [
    'platform_name' => 'FieldPlx',
    'platform_tagline' => '',
    'default_country_id' => '',
    'default_currency_id' => '',
    'default_timezone' => 'UTC',
    'default_date_format' => 'd-m-Y',
    'default_trial_days' => 14,
    'allow_public_signup' => 0,
    'require_email_verification' => 1,
    'allow_support_access' => 1,
    'maintenance_mode' => 0,
    'maintenance_message' => '',
    'session_timeout_minutes' => 120,
    'password_min_length' => 8
];

$stmt = $pdo->query("SELECT * FROM platform_settings ORDER BY id ASC LIMIT 1");
$row = $stmt->fetch();
if ($row) {
    $settings = array_merge($settings, $row);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= fp_h($pageTitle) ?> - FieldPlx</title>
<?php require_once __DIR__ . '/includes/links.php'; ?>

<style>
:root{
    --fp-primary:#12182d;
    --fp-primary-2:#1c2250;
    --fp-primary-3:#201f6b;
    --fp-accent:#8b5cf6;
    --fp-accent-light:#a78bfa;
    --fp-accent-dark:#6d28d9;
    --fp-text:#20213f;
    --fp-muted:#6f6b8f;
    --fp-border:#ded9ef;
    --fp-danger:#dc2626;
    --fp-sidebar-width:260px;
    --fp-sidebar-collapsed-width:76px;
    --fp-topbar-height:66px;
}
*{box-sizing:border-box}
body{
    margin:0;
    min-height:100vh;
    overflow-x:hidden;
    background:#fff;
    color:var(--fp-text);
    font-family:"Inter",sans-serif;
    font-size:13px;
}
a{text-decoration:none}
button,input,select,textarea{font-family:inherit}
.fp-layout{min-height:100vh}
.fp-main{
    min-height:calc(100vh - 52px);
    margin-left:var(--fp-sidebar-width);
    transition:margin-left .22s ease;
}
body.fp-sidebar-collapsed .fp-main{margin-left:var(--fp-sidebar-collapsed-width)}

.fp-topbar{
    position:sticky;top:0;z-index:1030;
    min-height:var(--fp-topbar-height);
    border-bottom:1px solid #ded8f3;
    background:rgba(248,246,255,.96);
    backdrop-filter:blur(14px);
}
.fp-topbar-inner{
    min-height:var(--fp-topbar-height);
    padding:8px 18px;
    display:flex;
    align-items:center;
    gap:13px;
}
.fp-menu-toggle,.fp-icon-button{
    width:39px;height:39px;min-width:39px;padding:0;
    display:inline-flex;align-items:center;justify-content:center;
    border:1px solid #d9d2ef;border-radius:10px;
    background:#fff;color:#39345f;font-size:18px;
}
.fp-menu-toggle:hover,.fp-icon-button:hover{
    border-color:#bda9ff;background:#f4f0ff;color:var(--fp-accent-dark)
}
.fp-page-heading{min-width:0;margin-right:auto}
.fp-page-title{
    margin:0;color:#17172e;font-size:15px;font-weight:700;
    line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis
}
.fp-page-subtitle{margin-top:2px;color:var(--fp-muted);font-size:10px}
.fp-search{width:min(340px,31vw);position:relative;flex:0 1 340px}
.fp-search i{
    position:absolute;left:12px;top:50%;transform:translateY(-50%);
    color:#8f88aa;font-size:14px;pointer-events:none
}
.fp-search input{
    width:100%;height:39px;padding:8px 13px 8px 36px;
    border:1px solid #dcd5ef;border-radius:10px;outline:0;
    background:#f8f6ff;font-size:12px
}
.fp-search input:focus{
    border-color:#a78bfa;background:#fff;
    box-shadow:0 0 0 3px rgba(139,92,246,.12)
}
.fp-notification-wrap{position:relative}
.fp-notification-count{
    position:absolute;top:-5px;right:-5px;z-index:3;
    min-width:18px;height:18px;padding:0 5px;
    display:inline-flex;align-items:center;justify-content:center;
    border:2px solid #fff;border-radius:999px;
    background:var(--fp-danger);color:#fff;font-size:9px;font-weight:700
}
.fp-profile{
    min-width:0;padding:4px 9px 4px 5px;
    display:flex;align-items:center;gap:9px;
    border:1px solid var(--fp-border);border-radius:11px;background:#fff
}
.fp-avatar{
    width:32px;height:32px;flex:0 0 32px;
    display:inline-flex;align-items:center;justify-content:center;
    border-radius:9px;background:linear-gradient(135deg,#6d4df4,#9a5cff);
    color:#fff;font-size:10px;font-weight:700
}
.fp-profile-text{max-width:145px;min-width:0}
.fp-profile-name,.fp-profile-role{
    display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis
}
.fp-profile-name{color:#111827;font-size:11px;font-weight:700}
.fp-profile-role{margin-top:1px;color:var(--fp-muted);font-size:9px}
.fp-mobile-brand{display:none}
.fp-content{padding:18px;background:#fff}

.settings-page{display:grid;gap:16px}
.settings-header{
    display:flex;align-items:flex-start;justify-content:space-between;gap:15px
}
.settings-title{margin:0;color:#111827;font-size:20px;font-weight:800}
.settings-description{
    margin-top:4px;max-width:720px;color:#77718e;font-size:10px;line-height:1.55
}
.settings-layout{
    display:grid;grid-template-columns:minmax(0,1fr) 310px;
    gap:16px;align-items:start
}
.settings-column{display:grid;gap:16px}
.settings-card{
    overflow:hidden;border:1px solid #ded7ef;border-radius:14px;
    background:#fff;box-shadow:0 8px 24px rgba(37,29,80,.05)
}
.settings-card-header{
    min-height:54px;padding:12px 15px;
    display:flex;align-items:center;gap:10px;
    border-bottom:1px solid #ece7f7;background:#fbf9ff
}
.settings-card-icon{
    width:34px;height:34px;flex:0 0 34px;
    display:inline-flex;align-items:center;justify-content:center;
    border-radius:9px;background:#eee8ff;color:#7c3aed;font-size:14px
}
.settings-card-title{margin:0;color:#111827;font-size:12px;font-weight:800}
.settings-card-subtitle{margin-top:2px;color:#9a94aa;font-size:8px}
.settings-card-body{padding:15px}
.settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
.settings-field.full{grid-column:1/-1}
.settings-field label{
    margin-bottom:6px;display:block;color:#4c465f;font-size:9px;font-weight:700
}
.required{color:#dc2626}
.settings-input,.settings-select,.settings-textarea{
    width:100%;border:1px solid #dcd5ef;border-radius:9px;outline:0;
    background:#fff;color:#312b47;font-size:10px
}
.settings-input,.settings-select{height:39px;padding:8px 11px}
.settings-textarea{min-height:94px;padding:10px 11px;resize:vertical}
.settings-input:focus,.settings-select:focus,.settings-textarea:focus{
    border-color:#a78bfa;box-shadow:0 0 0 3px rgba(139,92,246,.10)
}
.settings-note{margin-top:5px;color:#9a94aa;font-size:8px;line-height:1.45}
.settings-toggle-list{display:grid;gap:10px}
.settings-toggle{
    min-height:54px;padding:10px 11px;
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    border:1px solid #ded7ef;border-radius:10px;background:#fbf9ff
}
.settings-toggle strong{display:block;color:#393248;font-size:9px}
.settings-toggle span span{
    margin-top:3px;display:block;color:#9a94aa;font-size:8px;line-height:1.4
}
.settings-status{
    padding:13px;border:1px solid #e3daf8;border-radius:11px;background:#f8f5ff
}
.settings-status-row{
    padding:9px 0;display:flex;justify-content:space-between;gap:12px;
    border-bottom:1px dashed #ddd6ef;font-size:9px
}
.settings-status-row:first-child{padding-top:0}
.settings-status-row:last-child{padding-bottom:0;border-bottom:0}
.settings-status-row span{color:#8d859e}
.settings-status-row strong{color:#302a40;text-align:right}
.settings-badge{
    padding:4px 7px;border-radius:999px;font-size:8px;font-weight:700
}
.settings-badge.normal{background:#ecfdf5;color:#047857}
.settings-badge.maintenance{background:#fff7ed;color:#c2410c}
.settings-submit{
    padding:13px 15px;display:flex;justify-content:flex-end;gap:9px;
    border-top:1px solid #ece7f7;background:#fbf9ff
}
.settings-reset,.settings-save{
    min-height:38px;padding:8px 14px;
    display:inline-flex;align-items:center;justify-content:center;gap:7px;
    border-radius:9px;font-size:10px;font-weight:700
}
.settings-reset{border:1px solid #dcd5ef;background:#fff;color:#5f5870}
.settings-save{
    border:0;background:linear-gradient(135deg,#7c3aed,#6d28d9);
    color:#fff;box-shadow:0 8px 20px rgba(109,40,217,.18)
}
.settings-save:disabled{opacity:.65}
.settings-spinner{
    width:14px;
    height:14px;
    display:none;
    border:2px dotted rgba(255,255,255,.95);
    border-radius:50%;
    animation:spin .75s linear infinite
}
.settings-save.loading .settings-spinner{display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}


/* =========================================================
   TOP-RIGHT TOAST SYSTEM
   success / error / warning / info
   no left border
========================================================= */
.settings-toast{
    position:fixed;
    top:82px;
    right:20px;
    z-index:20000;
    width:min(380px,calc(100vw - 24px));
    padding:12px 14px;
    display:flex;
    align-items:center;
    gap:10px;
    border:0;
    border-radius:11px;
    color:#ffffff;
    box-shadow:0 16px 34px rgba(16,24,40,.18);
    opacity:0;
    visibility:hidden;
    transform:translateY(-10px);
    transition:opacity .2s ease,transform .2s ease,visibility .2s ease;
    font-size:10px;
    line-height:1.45;
}
.settings-toast.show{
    opacity:1;
    visibility:visible;
    transform:translateY(0);
}
.settings-toast.success{background:#059669;}
.settings-toast.error{background:#dc2626;}
.settings-toast.warning{background:#d97706;}
.settings-toast.info{background:#4f46e5;}

.settings-toast-icon{
    width:24px;
    height:24px;
    flex:0 0 24px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:999px;
    background:rgba(255,255,255,.18);
    color:#ffffff;
    font-size:12px;
}
.settings-toast-message{
    flex:1;
    min-width:0;
    color:#ffffff;
    font-weight:600;
}
.settings-toast-close{
    width:24px;
    height:24px;
    padding:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:0;
    border-radius:7px;
    background:transparent;
    color:#ffffff;
    font-size:15px;
    cursor:pointer;
    opacity:.82;
}
.settings-toast-close:hover{
    background:rgba(255,255,255,.12);
    opacity:1;
}
@media(max-width:575.98px){
    .settings-toast{
        top:74px;
        right:12px;
        left:12px;
        width:auto;
    }
}

@media(max-width:1100px){
    .settings-layout{grid-template-columns:1fr}
    .settings-side{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:991.98px){
    .fp-main,body.fp-sidebar-collapsed .fp-main{margin-left:0}
    .fp-search,.fp-profile-text{display:none}
    .fp-mobile-brand{display:inline-flex}
}
@media(max-width:700px){
    .settings-grid,.settings-side{grid-template-columns:1fr}
}
@media(max-width:575.98px){
    .fp-topbar-inner{padding:8px 11px}
    .fp-page-subtitle{display:none}
    .fp-page-title{font-size:13px}
    .fp-content{padding:12px}
    .settings-submit{flex-direction:column-reverse}
    .settings-reset,.settings-save{width:100%}
}
</style>
</head>
<body>

<div class="fp-layout">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="fp-main">
<?php require_once __DIR__ . '/includes/topbar.php'; ?>

<div class="fp-content">
<div class="settings-page">

<div class="settings-header">
    <div>
        <h2 class="settings-title">Platform Settings</h2>
        <div class="settings-description">
            Configure FieldPlx defaults, signup behaviour, security rules,
            support access and maintenance mode.
        </div>
    </div>
</div>

<div
    id="settingsToast"
    class="settings-toast"
    role="status"
    aria-live="polite"
    aria-atomic="true"
>
    <span class="settings-toast-icon">
        <i id="settingsToastIcon" class="bi bi-check-lg"></i>
    </span>

    <span
        id="settingsToastMessage"
        class="settings-toast-message"
    >
        Platform settings saved successfully.
    </span>

    <button
        type="button"
        id="settingsToastClose"
        class="settings-toast-close"
        aria-label="Close"
    >
        <i class="bi bi-x-lg"></i>
    </button>
</div>


<form id="platformSettingsForm" novalidate>
<input type="hidden" name="csrf_token" value="<?= fp_h($csrfToken) ?>">

<div class="settings-layout">

<div class="settings-column">

<section class="settings-card">
<div class="settings-card-header">
    <span class="settings-card-icon"><i class="bi bi-sliders"></i></span>
    <span>
        <h3 class="settings-card-title">General Settings</h3>
        <span class="settings-card-subtitle">Platform identity and regional defaults</span>
    </span>
</div>
<div class="settings-card-body">
<div class="settings-grid">

<div class="settings-field">
<label>Platform Name <span class="required">*</span></label>
<input class="settings-input" id="platformName" name="platform_name"
       maxlength="190" required value="<?= fp_h($settings['platform_name']) ?>">
</div>

<div class="settings-field">
<label>Platform Tagline</label>
<input class="settings-input" name="platform_tagline" maxlength="255"
       value="<?= fp_h($settings['platform_tagline']) ?>"
       placeholder="Field service management platform">
</div>

<div class="settings-field">
<label>Default Country</label>
<select class="settings-select" id="defaultCountryId" name="default_country_id">
<option value="">No default country</option>
<?php foreach ($countries as $country): ?>
<option
    value="<?= (int)$country['id'] ?>"
    data-currency="<?= fp_h($country['default_currency_code']) ?>"
    data-timezone="<?= fp_h($country['default_timezone']) ?>"
    data-date-format="<?= fp_h($country['date_format']) ?>"
    <?= (string)$settings['default_country_id'] === (string)$country['id'] ? 'selected' : '' ?>
>
<?= fp_h($country['name']) ?> (<?= fp_h($country['iso2']) ?>)
</option>
<?php endforeach; ?>
</select>
</div>

<div class="settings-field">
<label>Default Currency</label>
<select class="settings-select" id="defaultCurrencyId" name="default_currency_id">
<option value="">No default currency</option>
<?php foreach ($currencies as $currency): ?>
<option
    value="<?= (int)$currency['id'] ?>"
    data-code="<?= fp_h($currency['currency_code']) ?>"
    <?= (string)$settings['default_currency_id'] === (string)$currency['id'] ? 'selected' : '' ?>
>
<?= fp_h($currency['currency_code']) ?> -
<?= fp_h($currency['currency_name']) ?>
(<?= fp_h($currency['symbol']) ?>)
</option>
<?php endforeach; ?>
</select>
</div>

<div class="settings-field">
<label>Default Timezone <span class="required">*</span></label>
<input class="settings-input" id="defaultTimezone" name="default_timezone"
       maxlength="100" required value="<?= fp_h($settings['default_timezone']) ?>">
</div>

<div class="settings-field">
<label>Default Date Format <span class="required">*</span></label>
<select class="settings-select" id="defaultDateFormat" name="default_date_format" required>
<?php
$formats = [
    'd-m-Y' => 'DD-MM-YYYY',
    'd/m/Y' => 'DD/MM/YYYY',
    'm-d-Y' => 'MM-DD-YYYY',
    'm/d/Y' => 'MM/DD/YYYY',
    'Y-m-d' => 'YYYY-MM-DD'
];
foreach ($formats as $value => $label):
?>
<option value="<?= fp_h($value) ?>" <?= $settings['default_date_format'] === $value ? 'selected' : '' ?>>
<?= fp_h($label) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="settings-field">
<label>Default Trial Days</label>
<input class="settings-input" id="defaultTrialDays" type="number"
       name="default_trial_days" min="0" max="3650"
       value="<?= (int)$settings['default_trial_days'] ?>">
<div class="settings-note">Used when a plan or signup flow does not specify its own trial period.</div>
</div>

</div>
</div>
</section>

<section class="settings-card">
<div class="settings-card-header">
    <span class="settings-card-icon"><i class="bi bi-person-check"></i></span>
    <span>
        <h3 class="settings-card-title">Access & Signup</h3>
        <span class="settings-card-subtitle">Public registration and support controls</span>
    </span>
</div>
<div class="settings-card-body">
<div class="settings-toggle-list">

<label class="settings-toggle">
<span>
    <strong>Allow Public Signup</strong>
    <span>Allow businesses to register without platform administrator creation.</span>
</span>
<span class="form-check form-switch m-0">
<input class="form-check-input" type="checkbox" name="allow_public_signup" value="1"
       <?= (int)$settings['allow_public_signup'] === 1 ? 'checked' : '' ?>>
</span>
</label>

<label class="settings-toggle">
<span>
    <strong>Require Email Verification</strong>
    <span>Require newly registered accounts to verify their email address.</span>
</span>
<span class="form-check form-switch m-0">
<input class="form-check-input" type="checkbox" name="require_email_verification" value="1"
       <?= (int)$settings['require_email_verification'] === 1 ? 'checked' : '' ?>>
</span>
</label>

<label class="settings-toggle">
<span>
    <strong>Allow Support Access</strong>
    <span>Allow authorised FieldPlx staff to use tenant support access.</span>
</span>
<span class="form-check form-switch m-0">
<input class="form-check-input" type="checkbox" name="allow_support_access" value="1"
       <?= (int)$settings['allow_support_access'] === 1 ? 'checked' : '' ?>>
</span>
</label>

</div>
</div>
</section>

<section class="settings-card">
<div class="settings-card-header">
    <span class="settings-card-icon"><i class="bi bi-shield-lock"></i></span>
    <span>
        <h3 class="settings-card-title">Security</h3>
        <span class="settings-card-subtitle">Session and password defaults</span>
    </span>
</div>
<div class="settings-card-body">
<div class="settings-grid">

<div class="settings-field">
<label>Session Timeout (minutes) <span class="required">*</span></label>
<input class="settings-input" id="sessionTimeout" type="number"
       name="session_timeout_minutes" min="5" max="10080" required
       value="<?= (int)$settings['session_timeout_minutes'] ?>">
<div class="settings-note">120 minutes = 2 hours.</div>
</div>

<div class="settings-field">
<label>Minimum Password Length <span class="required">*</span></label>
<input class="settings-input" type="number" name="password_min_length"
       min="6" max="128" required value="<?= (int)$settings['password_min_length'] ?>">
</div>

</div>
</div>
</section>

<section class="settings-card">
<div class="settings-card-header">
    <span class="settings-card-icon"><i class="bi bi-tools"></i></span>
    <span>
        <h3 class="settings-card-title">Maintenance Mode</h3>
        <span class="settings-card-subtitle">Temporarily restrict normal platform usage</span>
    </span>
</div>
<div class="settings-card-body">

<label class="settings-toggle">
<span>
    <strong>Maintenance Mode</strong>
    <span>Enable platform-wide maintenance state.</span>
</span>
<span class="form-check form-switch m-0">
<input class="form-check-input" id="maintenanceMode" type="checkbox"
       name="maintenance_mode" value="1"
       <?= (int)$settings['maintenance_mode'] === 1 ? 'checked' : '' ?>>
</span>
</label>

<div class="settings-field" style="margin-top:13px">
<label>Maintenance Message</label>
<textarea class="settings-textarea" name="maintenance_message"
          placeholder="We are currently performing scheduled maintenance..."><?= fp_h($settings['maintenance_message']) ?></textarea>
</div>

</div>
</section>

</div>

<aside class="settings-column settings-side">

<section class="settings-card">
<div class="settings-card-header">
    <span class="settings-card-icon"><i class="bi bi-activity"></i></span>
    <span>
        <h3 class="settings-card-title">Current Platform State</h3>
        <span class="settings-card-subtitle">Live preview of important defaults</span>
    </span>
</div>
<div class="settings-card-body">
<div class="settings-status">
    <div class="settings-status-row">
        <span>Platform</span>
        <strong id="previewName"><?= fp_h($settings['platform_name']) ?></strong>
    </div>
    <div class="settings-status-row">
        <span>Timezone</span>
        <strong id="previewTimezone"><?= fp_h($settings['default_timezone']) ?></strong>
    </div>
    <div class="settings-status-row">
        <span>Trial</span>
        <strong id="previewTrial"><?= (int)$settings['default_trial_days'] ?> days</strong>
    </div>
    <div class="settings-status-row">
        <span>Session</span>
        <strong id="previewSession"><?= (int)$settings['session_timeout_minutes'] ?> min</strong>
    </div>
    <div class="settings-status-row">
        <span>Maintenance</span>
        <strong>
            <span id="previewMaintenance"
                  class="settings-badge <?= (int)$settings['maintenance_mode'] === 1 ? 'maintenance' : 'normal' ?>">
                <?= (int)$settings['maintenance_mode'] === 1 ? 'Enabled' : 'Normal' ?>
            </span>
        </strong>
    </div>
</div>
</div>
</section>

<section class="settings-card">
<div class="settings-card-header">
    <span class="settings-card-icon"><i class="bi bi-info-circle"></i></span>
    <span>
        <h3 class="settings-card-title">Settings Scope</h3>
        <span class="settings-card-subtitle">Platform-wide defaults</span>
    </span>
</div>
<div class="settings-card-body">
<div class="settings-note" style="font-size:9px;line-height:1.7">
These values are global defaults. Tenant, branch and plan-specific configuration can override applicable values.
</div>
</div>
</section>

</aside>

</div>

<section class="settings-card" style="margin-top:16px">
<div class="settings-submit">
<button type="reset" class="settings-reset">
    <i class="bi bi-arrow-counterclockwise"></i> Reset
</button>
<button type="submit" class="settings-save" id="saveSettingsButton">
    <span class="settings-spinner"></span>
    <i class="bi bi-check2-circle"></i>
    <span id="saveSettingsText">Save Settings</span>
</button>
</div>
</section>

</form>

</div>
</div>
</main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
'use strict';

var body=document.body;
var toggle=document.getElementById('fpSidebarToggle');
var closeBtn=document.getElementById('fpSidebarClose');
var overlay=document.getElementById('fpSidebarOverlay');
var storageKey='fieldplx_sidebar_collapsed';

function restoreSidebar(){
    if(window.innerWidth<992){
        body.classList.remove('fp-sidebar-collapsed');
        return;
    }
    if(localStorage.getItem(storageKey)==='1'){
        body.classList.add('fp-sidebar-collapsed');
    }else{
        body.classList.remove('fp-sidebar-collapsed');
    }
}
restoreSidebar();

if(toggle){
    toggle.addEventListener('click',function(){
        if(window.innerWidth<992){
            body.classList.toggle('fp-sidebar-mobile-open');
            return;
        }
        body.classList.toggle('fp-sidebar-collapsed');
        localStorage.setItem(storageKey,body.classList.contains('fp-sidebar-collapsed')?'1':'0');
    });
}
if(closeBtn){
    closeBtn.addEventListener('click',function(){body.classList.remove('fp-sidebar-mobile-open')});
}
if(overlay){
    overlay.addEventListener('click',function(){body.classList.remove('fp-sidebar-mobile-open')});
}

document.querySelectorAll('.fp-sidebar-menu-toggle').forEach(function(btn){
    btn.addEventListener('click',function(){
        var menu=btn.closest('.fp-sidebar-menu');
        if(menu){menu.classList.toggle('open')}
    });
});

var form=document.getElementById('platformSettingsForm');
var toastBox=document.getElementById('settingsToast');
var toastMessage=document.getElementById('settingsToastMessage');
var toastIcon=document.getElementById('settingsToastIcon');
var toastClose=document.getElementById('settingsToastClose');
var toastTimer=null;
var saveBtn=document.getElementById('saveSettingsButton');
var saveText=document.getElementById('saveSettingsText');

var nameInput=document.getElementById('platformName');
var countrySelect=document.getElementById('defaultCountryId');
var currencySelect=document.getElementById('defaultCurrencyId');
var timezoneInput=document.getElementById('defaultTimezone');
var dateFormatSelect=document.getElementById('defaultDateFormat');
var trialInput=document.getElementById('defaultTrialDays');
var sessionInput=document.getElementById('sessionTimeout');
var maintenanceInput=document.getElementById('maintenanceMode');


function hideToast(){
    if(toastTimer){
        clearTimeout(toastTimer);
        toastTimer=null;
    }

    if(toastBox){
        toastBox.classList.remove('show');
    }
}

function showToast(type,message,duration){
    if(!toastBox){
        return;
    }

    hideToast();

    var toastType=type||'info';
    var hideAfter=typeof duration==='number' ? duration : 3000;

    var icons={
        success:'bi-check-lg',
        error:'bi-x-lg',
        warning:'bi-exclamation-lg',
        info:'bi-info-lg'
    };

    toastBox.className='settings-toast '+toastType;
    toastMessage.textContent=message||'Notification';

    if(toastIcon){
        toastIcon.className='bi '+(icons[toastType]||icons.info);
    }

    toastBox.classList.add('show');

    toastTimer=setTimeout(function(){
        toastBox.classList.remove('show');
        toastTimer=null;
    },hideAfter);
}

if(toastClose){
    toastClose.addEventListener('click',hideToast);
}

function updatePreview(){
    document.getElementById('previewName').textContent=nameInput.value.trim()||'FieldPlx';
    document.getElementById('previewTimezone').textContent=timezoneInput.value.trim()||'UTC';
    document.getElementById('previewTrial').textContent=(trialInput.value||0)+' days';
    document.getElementById('previewSession').textContent=(sessionInput.value||0)+' min';

    var badge=document.getElementById('previewMaintenance');
    if(maintenanceInput.checked){
        badge.textContent='Enabled';
        badge.className='settings-badge maintenance';
    }else{
        badge.textContent='Normal';
        badge.className='settings-badge normal';
    }
}

countrySelect.addEventListener('change',function(){
    var option=countrySelect.options[countrySelect.selectedIndex];
    if(!option||!option.value){return}

    var currency=option.getAttribute('data-currency')||'';
    var timezone=option.getAttribute('data-timezone')||'';
    var dateFormat=option.getAttribute('data-date-format')||'';

    if(currency){
        Array.prototype.forEach.call(currencySelect.options,function(opt){
            if(opt.getAttribute('data-code')===currency){
                currencySelect.value=opt.value;
            }
        });
    }
    if(timezone){timezoneInput.value=timezone}
    if(dateFormat){
        Array.prototype.forEach.call(dateFormatSelect.options,function(opt){
            if(opt.value===dateFormat){dateFormatSelect.value=dateFormat}
        });
    }
    updatePreview();
});

[nameInput,timezoneInput,trialInput,sessionInput,maintenanceInput].forEach(function(el){
    el.addEventListener('input',updatePreview);
    el.addEventListener('change',updatePreview);
});

form.addEventListener('reset',function(){
    setTimeout(updatePreview,0);
});

form.addEventListener('submit',function(e){
    e.preventDefault();
    if(!form.checkValidity()){
        showToast(
            'warning',
            'Please complete the required fields correctly.',
            3000
        );
        form.reportValidity();
        return;
    }

    saveBtn.disabled=true;
    saveBtn.classList.add('loading');
    saveText.textContent='Saving...';
fetch('api/platform-settings-save.php',{
        method:'POST',
        body:new FormData(form),
        credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest'}
    })
    .then(function(response){
        return response.json().then(function(data){
            return {ok:response.ok,data:data};
        });
    })
    .then(function(result){
        if(!result.ok||!result.data.success){
            throw new Error(result.data.message||'Unable to save platform settings.');
        }
        showToast('success',result.data.message||'Platform settings saved successfully.',3000);
        saveBtn.disabled=false;
        saveBtn.classList.remove('loading');
        saveText.textContent='Save Settings';
        updatePreview();
    })
    .catch(function(error){
        showToast('error',error.message||'Unable to save platform settings.',3000);
        saveBtn.disabled=false;
        saveBtn.classList.remove('loading');
        saveText.textContent='Save Settings';
    });
});

updatePreview();
})();
</script>

</body>
</html>
