<?php
/**
 * FieldPlx - Schedule Settings v1.1
 * File: business/schedule-settings.php
 * Compatible with PHP 7.2+ / MariaDB 11.x
 */

require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Schedule · FieldPlx';
$pageDescription = 'Control personal schedule preferences, availability, calendar colors, sync, and day sheet options';
$settingsActivePage = 'schedule';

$tenantId = isset($currentTenantId)
    ? (int)$currentTenantId
    : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);

$userId = isset($currentTenantUserId)
    ? (int)$currentTenantUserId
    : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0));

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['schedule_settings_csrf'])) {
    $_SESSION['schedule_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['schedule_settings_csrf'];

function ss_h($value)
{
    return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
}

function ss_table_exists(PDO $pdo, $table)
{
    $q = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $q->execute(array(':table_name' => $table));
    return ((int)$q->fetchColumn() > 0);
}

function ss_column_exists(PDO $pdo, $table, $column)
{
    $q = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $q->execute(array(':table_name' => $table, ':column_name' => $column));
    return ((int)$q->fetchColumn() > 0);
}

$schemaReady =
    ss_table_exists($pdo, 'user_schedule_settings') &&
    ss_table_exists($pdo, 'calendar_color_assignments') &&
    ss_table_exists($pdo, 'calendar_sync_settings');

$settings = array(
    'appointment_layout' => 'nested',
    'completed_appointment_style' => 'grayed_out',
    'day_view_orientation' => 'horizontal',
    'show_weekends' => 1,
    'confirm_reschedule_notification' => 1,
    'day_sheet_property_map' => 1,
    'day_sheet_notes_area' => 0,
    'day_sheet_custom_information' => 0,
    'day_sheet_custom_template' => "Time In:\nTime Out:"
);

if ($schemaReady && $tenantId > 0 && $userId > 0) {
    $q = $pdo->prepare("
        SELECT *
        FROM user_schedule_settings
        WHERE tenant_id = :tenant_id
          AND user_id = :user_id
        LIMIT 1
    ");
    $q->execute(array(':tenant_id' => $tenantId, ':user_id' => $userId));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $settings = array_merge($settings, $row);
    }
}

$teamMembers = array();
if ($tenantId > 0 && ss_table_exists($pdo, 'users')) {
    $q = $pdo->prepare("
        SELECT id, first_name, last_name, email, avatar_path
        FROM users
        WHERE tenant_id = :tenant_id
          AND deleted_at IS NULL
          AND status IN ('active','invited')
        ORDER BY first_name ASC, last_name ASC, id ASC
    ");
    $q->execute(array(':tenant_id' => $tenantId));
    $teamMembers = $q->fetchAll(PDO::FETCH_ASSOC);
}

$colorAssignments = array();
$calendarColorHasSortOrder = ss_table_exists($pdo, 'calendar_color_assignments') && ss_column_exists($pdo, 'calendar_color_assignments', 'sort_order');
$calendarSync = array(
    'sync_tasks' => 1,
    'sync_reminders' => 1,
    'sync_events' => 1,
    'sync_visits' => 1,
    'sync_requests' => 1,
    'subscription_token' => ''
);

if ($schemaReady && $tenantId > 0 && $userId > 0 && ss_table_exists($pdo, 'calendar_sync_settings')) {
    $q = $pdo->prepare("
        SELECT *
        FROM calendar_sync_settings
        WHERE tenant_id = :tenant_id
          AND user_id = :user_id
        LIMIT 1
    ");
    $q->execute(array(':tenant_id' => $tenantId, ':user_id' => $userId));
    $syncRow = $q->fetch(PDO::FETCH_ASSOC);
    if ($syncRow) {
        $calendarSync = array_merge($calendarSync, $syncRow);
    }
}

if ($schemaReady && $tenantId > 0) {
    $sortSelect = $calendarColorHasSortOrder ? 'cca.sort_order' : 'cca.id';
    $sortOrder = $calendarColorHasSortOrder ? 'cca.sort_order ASC, cca.id ASC' : 'cca.id ASC';
    $q = $pdo->prepare("
        SELECT
            cca.id,
            cca.user_id,
            cca.rule_type,
            cca.rule_value,
            cca.color_hex,
            " . $sortSelect . " AS sort_order,
            u.first_name,
            u.last_name,
            u.email
        FROM calendar_color_assignments cca
        LEFT JOIN users u
            ON u.id = cca.user_id
           AND u.tenant_id = cca.tenant_id
        WHERE cca.tenant_id = :tenant_id
        ORDER BY " . $sortOrder . "
    ");
    $q->execute(array(':tenant_id' => $tenantId));
    $colorAssignments = $q->fetchAll(PDO::FETCH_ASSOC);
}

require __DIR__ . '/includes/header.php';
if (file_exists(__DIR__ . '/includes/toast.php')) {
    require_once __DIR__ . '/includes/toast.php';
}
?>

<style>
.schedule-settings-layout{
    display:grid;
    grid-template-columns:250px minmax(0,1fr);
    gap:28px;
    max-width:1240px;
    margin:0 auto;
    padding:0 0 46px;
    align-items:start;
}
.schedule-settings-main{min-width:0}
.schedule-page-title{margin:0 0 16px}
.schedule-page-title-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    position:relative;
}
.schedule-mobile-settings-btn{
    display:none;
    min-height:42px;
    padding:0 14px;
    border:1px solid var(--ss-border,#d8e0e6);
    border-radius:9px;
    background:var(--ss-surface,#fff);
    color:var(--ss-text,#173644);
    font:inherit;
    font-size:12px;
    font-weight:700;
    align-items:center;
    gap:7px;
    cursor:pointer;
}
.schedule-mobile-settings-btn svg{width:16px;height:16px;transition:transform .16s ease}
.schedule-mobile-settings-btn.open svg{transform:rotate(180deg)}
.schedule-mobile-settings-backdrop{
    position:fixed;
    inset:0;
    z-index:11990;
    display:none;
    background:rgba(9,25,34,.38);
}
.schedule-mobile-settings-backdrop.open{display:block}
body.schedule-settings-menu-open{overflow:hidden}
.schedule-mobile-settings-popover{
    position:absolute;
    top:calc(100% + 12px);
    right:0;
    z-index:12010;
    display:none;
    width:min(330px,calc(100vw - 32px));
    max-height:min(72vh,560px);
    overflow-y:auto;
    overflow-x:hidden;
    padding:18px 13px 16px;
    border:1px solid var(--ss-border,#dfe5eb);
    border-radius:4px;
    background:var(--ss-surface,#fff);
    box-shadow:0 12px 30px rgba(0,17,49,.22);
    scrollbar-width:thin;
    scrollbar-color:#b7c2cc transparent;
}
.schedule-mobile-settings-popover.open{display:block}
.schedule-mobile-settings-popover:before{
    content:"";
    position:absolute;
    top:-8px;
    right:17px;
    width:14px;
    height:14px;
    border-left:1px solid var(--ss-border,#dfe5eb);
    border-top:1px solid var(--ss-border,#dfe5eb);
    background:var(--ss-surface,#fff);
    transform:rotate(45deg);
}
.schedule-mobile-settings-popover::-webkit-scrollbar{width:3px}
.schedule-mobile-settings-popover::-webkit-scrollbar-track{background:transparent}
.schedule-mobile-settings-popover::-webkit-scrollbar-thumb{background:#b7c2cc;border-radius:999px}
.schedule-mobile-settings-popover .fieldplx-settings-nav{
    display:block!important;
    position:static!important;
    width:100%!important;
    height:auto!important;
    max-height:none!important;
    min-height:0!important;
    overflow:visible!important;
    padding:0!important;
}
.schedule-mobile-settings-popover .fieldplx-settings-nav>h2{display:none!important}
.schedule-mobile-settings-popover .fieldplx-settings-nav-group{margin:0 0 20px!important}
.schedule-mobile-settings-popover .fieldplx-settings-nav-group:last-child{margin-bottom:0!important}
.schedule-mobile-settings-popover .fieldplx-settings-nav-label{
    margin:0 0 8px!important;
    color:var(--ss-text,#0b1933)!important;
    font-size:10px!important;
    font-weight:800!important;
    line-height:1.05!important;
    text-transform:uppercase!important;
}
.schedule-mobile-settings-popover .fieldplx-settings-nav a{
    display:block!important;
    padding:6px 0!important;
    color:var(--ss-muted,#405b6b)!important;
    font-size:12px!important;
    line-height:1.25!important;
    text-decoration:none!important;
}
.schedule-mobile-settings-popover .fieldplx-settings-nav a:hover,
.schedule-mobile-settings-popover .fieldplx-settings-nav a.active{
    color:var(--ss-primary,#318d27)!important;
    font-weight:700!important;
}
.schedule-page-title h1{
    margin:0;
    color:var(--fieldplx-text,#0b1933);
    font-size:30px;
    line-height:1.16;
    font-weight:700;
}
.schedule-page-title p{
    margin:10px 0 0;
    color:var(--fieldplx-muted,#6f7b90);
    font-size:13px;
    line-height:1.55;
}
.schedule-schema-warning{
    margin:0 0 16px;
    padding:11px 13px;
    border:1px solid #efd393;
    border-radius:8px;
    background:#fff8e7;
    color:#7b5b08;
    font-size:11px;
    line-height:1.45;
}
.schedule-card{
    margin:0 0 18px;
    border:1px solid #dfe5eb;
    border-radius:10px;
    background:#fff;
    overflow:hidden;
}
.schedule-card-body{padding:16px}
.schedule-card h2{
    margin:0;
    color:#082b3a;
    font-size:20px;
    line-height:1.2;
}
.schedule-card-intro{
    margin:7px 0 10px;
    color:#41596a;
    font-size:11px;
    line-height:1.5;
}
.schedule-setting-row{
    display:grid;
    grid-template-columns:minmax(0,1fr) 370px;
    gap:22px;
    align-items:center;
    padding:18px 0;
    border-top:1px solid #edf1f4;
}
.schedule-setting-row:first-of-type{border-top:0}
.schedule-setting-copy strong{
    display:block;
    color:#0b2e40;
    font-size:12px;
    margin-bottom:4px;
}
.schedule-setting-copy p{
    margin:0;
    max-width:560px;
    color:#3c586a;
    font-size:11px;
    line-height:1.45;
}
.schedule-choice-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
}
.schedule-choice{
    position:relative;
    cursor:pointer;
}
.schedule-choice input{
    position:absolute;
    opacity:0;
    pointer-events:none;
}
.schedule-choice-preview{
    display:block;
    width:100%;
    border:1px solid var(--ss-border,#dce4ea);
    border-radius:8px;
    background:var(--ss-surface,#fff);
    object-fit:contain;
    object-position:center;
    transition:border-color .16s ease,box-shadow .16s ease,background-color .16s ease,filter .16s ease;
}
.schedule-choice input:checked + .schedule-choice-preview{
    border-color:var(--ss-primary,#74b824);
    box-shadow:0 0 0 2px color-mix(in srgb,var(--ss-primary,#74b824) 16%,transparent);
}
.schedule-choice-label{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    margin-top:7px;
    color:#2a4757;
    font-size:10px;
}
.schedule-radio-dot{
    width:15px;
    height:15px;
    border:1px solid #cfd8df;
    border-radius:50%;
    position:relative;
    background:#fff;
}
.schedule-choice input:checked ~ .schedule-choice-label .schedule-radio-dot{
    border-color:#3c922c;
}
.schedule-choice input:checked ~ .schedule-choice-label .schedule-radio-dot:after{
    content:"";
    position:absolute;
    inset:3px;
    border-radius:50%;
    background:#3c922c;
}
.mock-calendar{
    height:100%;
    display:grid;
    gap:5px;
    grid-template-columns:repeat(3,1fr);
}
.mock-calendar span{
    border-radius:4px;
    background:#e8edef;
}
.mock-calendar span:nth-child(2n){background:#dfe6e8}
.mock-stacked{
    height:100%;
    display:grid;
    gap:6px;
    align-content:center;
}
.mock-stacked span{
    display:block;
    height:14px;
    border-radius:5px;
    background:#dce5e7;
}
.mock-stacked span:nth-child(1){width:82%}
.mock-stacked span:nth-child(2){width:92%}
.mock-stacked span:nth-child(3){width:58%}
.mock-strike{
    height:100%;
    position:relative;
    border-radius:5px;
    background:#eff5eb;
}
.mock-strike:before,
.mock-strike:after{
    content:"";
    position:absolute;
    left:12px;
    right:12px;
    height:8px;
    border-radius:6px;
    background:#d3ded5;
}
.mock-strike:before{top:32px}
.mock-strike:after{top:53px}
.mock-strike i{
    position:absolute;
    left:12px;
    right:12px;
    top:49px;
    height:2px;
    background:#173e4b;
}
.schedule-toggle-row{
    display:flex;
    align-items:flex-start;
    gap:12px;
    padding:18px 0;
    border-top:1px solid #edf1f4;
}
.schedule-toggle{
    position:relative;
    width:40px;
    height:22px;
    flex:0 0 auto;
}
.schedule-toggle input{opacity:0;width:0;height:0}
.schedule-toggle-track{
    position:absolute;
    inset:0;
    border-radius:999px;
    background:#d9e1e6;
    transition:.18s ease;
    cursor:pointer;
}
.schedule-toggle-track:after{
    content:"";
    position:absolute;
    width:16px;
    height:16px;
    left:3px;
    top:3px;
    border-radius:50%;
    background:#fff;
    box-shadow:0 1px 3px rgba(0,0,0,.16);
    transition:.18s ease;
}
.schedule-toggle input:checked + .schedule-toggle-track{background:#318d27}
.schedule-toggle input:checked + .schedule-toggle-track:after{transform:translateX(18px)}
.schedule-toggle-copy strong{
    display:block;
    color:#0b2e40;
    font-size:12px;
    margin-bottom:4px;
}
.schedule-toggle-copy p{
    margin:0;
    color:#3c586a;
    font-size:11px;
    line-height:1.45;
}
.schedule-actions{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    padding-top:12px;
}
.schedule-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    min-height:34px;
    padding:0 12px;
    border:1px solid #d8e0e6;
    border-radius:8px;
    background:#fff;
    color:#2f7f24;
    text-decoration:none;
    font:inherit;
    font-size:11px;
    font-weight:700;
    cursor:pointer;
}
.schedule-btn:hover{border-color:#74b824}
.schedule-btn.primary{
    border-color:#2f8c25;
    background:#2f8c25;
    color:#fff;
}
.schedule-btn:disabled{opacity:.5;cursor:not-allowed}
.availability-row{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:16px;
    align-items:center;
    padding:15px 0;
    border-top:1px solid #edf1f4;
}
.availability-row:first-of-type{border-top:0}
.availability-row strong{
    display:block;
    color:#0b2e40;
    font-size:12px;
}
.availability-row p{
    margin:4px 0 0;
    color:#3c586a;
    font-size:11px;
    line-height:1.45;
}
.calendar-color-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}
.color-empty{
    display:flex;
    align-items:center;
    gap:14px;
    padding:22px 12px 8px;
}
.color-empty-icon{
    width:46px;
    height:46px;
    display:grid;
    place-items:center;
    flex:0 0 auto;
    border-radius:50%;
    background:#f1f0ec;
    color:#244657;
}
.color-empty strong{
    display:block;
    color:#0b2e40;
    font-size:12px;
}
.color-empty p{
    margin:3px 0 8px;
    color:#4b6575;
    font-size:11px;
}
.color-list{padding-top:10px}
.color-list-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:11px 0;
    border-top:1px solid #edf1f4;
}
.color-user{
    display:flex;
    align-items:center;
    gap:10px;
    min-width:0;
}
.color-dot{
    width:28px;
    height:28px;
    border-radius:50%;
    border:1px solid rgba(0,0,0,.08);
    flex:0 0 auto;
}
.color-user strong{
    display:block;
    color:#153747;
    font-size:11px;
}
.color-user small{
    display:block;
    margin-top:2px;
    color:#75848d;
    font-size:9px;
}
.day-sheet-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px 34px;
    margin-top:14px;
}
.day-sheet-option{
    display:flex;
    align-items:flex-start;
    gap:8px;
    color:#294958;
    font-size:11px;
}
.day-sheet-option input{
    width:17px;
    height:17px;
    margin:0;
    accent-color:#318d27;
}
.day-sheet-option small{
    display:block;
    margin-top:3px;
    color:#73838c;
    font-size:9px;
}
.custom-info-wrap{
    margin-top:12px;
    max-width:460px;
}
.schedule-textarea{
    width:100%;
    min-height:88px;
    padding:11px 13px;
    border:1px solid #d8e1e7;
    border-radius:8px;
    outline:none;
    resize:vertical;
    background:#fff;
    color:#173644;
    font:inherit;
    font-size:11px;
}
.schedule-textarea:focus{
    border-color:#74b824;
    box-shadow:0 0 0 2px rgba(116,184,36,.12);
}
.schedule-modal-bg{
    position:fixed;
    inset:0;
    z-index:13000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px;
    background:rgba(0,17,49,.38);
}
.schedule-modal-bg.open{display:flex}
.schedule-modal{
    width:min(500px,100%);
    max-height:90vh;
    overflow:auto;
    border-radius:11px;
    background:#fff;
    box-shadow:0 24px 70px rgba(0,17,49,.24);
}
.schedule-modal-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:18px 20px 10px;
}
.schedule-modal-head h3{
    margin:0;
    color:#0b3443;
    font-size:20px;
}
.schedule-modal-x{
    width:32px;
    height:32px;
    border:0;
    border-radius:7px;
    background:transparent;
    color:#234454;
    font-size:23px;
    cursor:pointer;
}
.schedule-modal-x:hover{background:#f4f6f8}
.schedule-modal-body{padding:8px 20px 14px}
.color-palette{
    display:grid;
    grid-template-columns:repeat(12,1fr);
    gap:7px;
    margin:10px 0 18px;
}
.color-palette button{
    aspect-ratio:1;
    border:2px solid transparent;
    border-radius:50%;
    cursor:pointer;
    box-shadow:inset 0 0 0 1px rgba(0,0,0,.08);
}
.color-palette button.selected{
    border-color:#fff;
    outline:2px solid #0b1933;
}
.color-apply-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
}
.schedule-select{
    width:100%;
    height:42px;
    padding:0 12px;
    border:1px solid #d8e1e7;
    border-radius:8px;
    background:#fff;
    color:#173644;
    font:inherit;
    font-size:11px;
    outline:none;
}
.schedule-select:focus{
    border-color:#74b824;
    box-shadow:0 0 0 2px rgba(116,184,36,.12);
}
.schedule-modal-foot{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    padding:14px 20px 18px;
}
.sync-checks{
    display:flex;
    flex-wrap:wrap;
    gap:8px 14px;
}
.sync-checks label{
    display:flex;
    align-items:center;
    gap:5px;
    color:#294958;
    font-size:10px;
}
.sync-checks input{
    width:14px;
    height:14px;
    accent-color:#318d27;
}
.sync-url-row{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:8px;
    align-items:center;
}
@media(max-width:980px){
    .schedule-settings-layout{grid-template-columns:1fr;padding:0 14px 30px}
    .schedule-settings-layout>.fieldplx-settings-nav{display:none}
    .schedule-mobile-settings-btn{display:inline-flex}
    .schedule-page-title{margin-bottom:14px}
}
@media(max-width:520px){
    .schedule-settings-layout{padding-left:12px;padding-right:12px}
    .schedule-mobile-settings-popover{
        position:fixed;
        top:184px;
        left:16px;
        right:16px;
        width:auto;
        max-height:calc(100vh - 205px);
    }
    .schedule-mobile-settings-popover:before{right:18px}
}
@media(max-width:760px){
    .schedule-setting-row{grid-template-columns:1fr}
    .schedule-choice-grid{max-width:420px}
    .day-sheet-grid{grid-template-columns:1fr}
}
@media(max-width:520px){
    .schedule-page-title h1{font-size:25px}
    .schedule-choice-grid,.color-apply-grid{grid-template-columns:1fr}
    .color-palette{grid-template-columns:repeat(8,1fr)}
    .availability-row{grid-template-columns:1fr}
}


/* ==========================================================
   Schedule Settings theme integration + image previews
   Keeps this page aligned with the shared FieldPlx light/dark theme.
   ========================================================== */
:root{
    --ss-primary:var(--primary,#318d27);
    --ss-primary-strong:var(--primary-dark,#26751f);
    --ss-surface:var(--card-bg,var(--fieldplx-surface,#ffffff));
    --ss-surface-soft:var(--table-header-bg,#f6f8fa);
    --ss-input:var(--input-bg,var(--card-bg,#ffffff));
    --ss-text:var(--text,var(--fieldplx-text,#0b2e40));
    --ss-muted:var(--muted,var(--fieldplx-muted,#607481));
    --ss-border:var(--card-border,var(--fieldplx-border,#dfe5eb));
    --ss-border-soft:var(--table-border,#edf1f4);
    --ss-hover:var(--table-hover-bg,#f4f7f8);
    --ss-overlay:rgba(0,17,49,.38);
    --ss-warning-bg:#fff8e7;
    --ss-warning-border:#efd393;
    --ss-warning-text:#7b5b08;
}

html.app-dark-mode,
html[data-theme="dark"],
body.app-dark-mode,
body.dark-mode,
body[data-theme="dark"]{
    --ss-surface:#131f26;
    --ss-surface-soft:#17262e;
    --ss-input:#101b21;
    --ss-text:#e8f1f5;
    --ss-muted:#9aadb7;
    --ss-border:#30424c;
    --ss-border-soft:#25363f;
    --ss-hover:#1b2c34;
    --ss-overlay:rgba(0,0,0,.64);
    --ss-warning-bg:#332a13;
    --ss-warning-border:#66521d;
    --ss-warning-text:#f2d27a;
}

.schedule-page-title h1,
.schedule-card h2,
.schedule-setting-copy strong,
.schedule-toggle-copy strong,
.availability-row strong,
.color-empty strong,
.color-user strong,
.schedule-modal-head h3{
    color:var(--ss-text)!important;
}
.schedule-page-title p,
.schedule-card-intro,
.schedule-setting-copy p,
.schedule-toggle-copy p,
.availability-row p,
.color-empty p,
.color-user small,
.day-sheet-option small{
    color:var(--ss-muted)!important;
}
.schedule-card,
.schedule-modal{
    background:var(--ss-surface)!important;
    border-color:var(--ss-border)!important;
    color:var(--ss-text)!important;
}
.schedule-setting-row,
.schedule-toggle-row,
.availability-row,
.color-list-row{
    border-color:var(--ss-border-soft)!important;
}
.schedule-schema-warning{
    background:var(--ss-warning-bg)!important;
    border-color:var(--ss-warning-border)!important;
    color:var(--ss-warning-text)!important;
}
.schedule-choice-label,
.day-sheet-option,
.sync-checks label{
    color:var(--ss-text)!important;
}
.schedule-radio-dot{
    background:var(--ss-input)!important;
    border-color:var(--ss-border)!important;
}
.schedule-choice input:checked ~ .schedule-choice-label .schedule-radio-dot{
    border-color:var(--ss-primary)!important;
}
.schedule-choice input:checked ~ .schedule-choice-label .schedule-radio-dot:after{
    background:var(--ss-primary)!important;
}
.schedule-toggle-track{background:color-mix(in srgb,var(--ss-muted) 32%,var(--ss-surface))!important}
.schedule-toggle-track:after{background:var(--ss-surface)!important}
.schedule-toggle input:checked + .schedule-toggle-track{background:var(--ss-primary)!important}
.schedule-btn{
    background:var(--ss-surface)!important;
    border-color:var(--ss-border)!important;
    color:var(--ss-text)!important;
}
.schedule-btn:hover{
    border-color:var(--ss-primary)!important;
    color:var(--ss-primary)!important;
    background:var(--ss-hover)!important;
}
.schedule-btn.primary{
    background:var(--ss-primary)!important;
    border-color:var(--ss-primary)!important;
    color:#fff!important;
}
.color-empty-icon{
    background:var(--ss-surface-soft)!important;
    color:var(--ss-text)!important;
}
.color-dot{border-color:var(--ss-border)!important}
.schedule-textarea,
.schedule-select{
    background:var(--ss-input)!important;
    border-color:var(--ss-border)!important;
    color:var(--ss-text)!important;
}
.schedule-textarea::placeholder,
.schedule-select::placeholder{color:var(--ss-muted)!important}
.schedule-textarea:focus,
.schedule-select:focus{
    border-color:var(--ss-primary)!important;
    box-shadow:0 0 0 2px color-mix(in srgb,var(--ss-primary) 18%,transparent)!important;
}
.schedule-select option{
    background:var(--ss-surface)!important;
    color:var(--ss-text)!important;
}
.schedule-modal-bg{background:var(--ss-overlay)!important;backdrop-filter:blur(2px)}
.schedule-modal-x{color:var(--ss-text)!important}
.schedule-modal-x:hover{background:var(--ss-hover)!important}
.color-palette button{box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--ss-text) 16%,transparent)!important}
.color-palette button.selected{border-color:var(--ss-surface)!important;outline-color:var(--ss-text)!important}
#colorModal .schedule-modal-body > div:first-child,
#calendarSyncModal .schedule-modal-body > p,
#calendarSyncModal .schedule-modal-body > strong{
    color:var(--ss-muted)!important;
}
#calendarSyncModal .schedule-modal-body > strong{color:var(--ss-text)!important}

html.app-dark-mode .schedule-choice-preview,
html[data-theme="dark"] .schedule-choice-preview,
body.app-dark-mode .schedule-choice-preview,
body.dark-mode .schedule-choice-preview,
body[data-theme="dark"] .schedule-choice-preview{
    background:#eef3f5!important;
    filter:brightness(.86) saturate(.82);
}

html.app-dark-mode .schedule-choice input:checked + .schedule-choice-preview,
html[data-theme="dark"] .schedule-choice input:checked + .schedule-choice-preview,
body.app-dark-mode .schedule-choice input:checked + .schedule-choice-preview,
body.dark-mode .schedule-choice input:checked + .schedule-choice-preview,
body[data-theme="dark"] .schedule-choice input:checked + .schedule-choice-preview{
    filter:brightness(.94) saturate(.95);
}


/* Jobber-style Calendar colors */
.calendar-color-card .schedule-card-body{padding:16px}
.calendar-color-head{margin-bottom:12px}
.color-rule-list{display:grid;gap:8px}
.color-rule-row{min-height:46px;display:flex;align-items:center;gap:10px;padding:0 12px 0 8px;border:1px solid var(--ss-border,#dfe5eb);border-radius:8px;background:var(--ss-surface,#fff);color:var(--ss-text,#0b2e40);cursor:pointer;user-select:none;transition:border-color .14s ease,background-color .14s ease,box-shadow .14s ease,transform .14s ease}
.color-rule-row:hover{border-color:color-mix(in srgb,var(--ss-primary,#318d27) 55%,var(--ss-border,#dfe5eb));background:var(--ss-hover,#f4f7f8)}
.color-rule-row:focus-visible{outline:2px solid color-mix(in srgb,var(--ss-primary,#318d27) 32%,transparent);outline-offset:2px}
.color-rule-row.dragging{opacity:.55;transform:scale(.995);box-shadow:0 8px 24px rgba(0,0,0,.12)}
.color-rule-row.drag-over{border-color:var(--ss-primary,#318d27);box-shadow:0 0 0 2px color-mix(in srgb,var(--ss-primary,#318d27) 14%,transparent)}
.color-drag-handle{width:22px;height:34px;flex:0 0 22px;display:grid;place-items:center;color:var(--ss-muted,#607481);cursor:grab;touch-action:none}
.color-drag-handle:active{cursor:grabbing}.color-drag-handle svg{width:15px;height:15px}
.color-rule-swatch{width:10px;height:10px;flex:0 0 10px;border-radius:2px;border:1px solid rgba(0,0,0,.10)}
.color-rule-text{min-width:0;flex:1;font-size:11px;line-height:1.3;color:var(--ss-text,#0b2e40)}
.color-rule-prefix{color:var(--ss-muted,#607481)}.color-rule-value{color:var(--ss-primary,#318d27);font-weight:500}
.color-sort-note{margin:8px 1px 0;color:var(--ss-muted,#607481);font-size:9px}
.color-modal-delete{margin-right:auto!important;border-color:rgba(214,69,69,.35)!important;color:#d64545!important}.color-modal-delete:hover{background:rgba(214,69,69,.07)!important;border-color:#d64545!important;color:#d64545!important}
@media(max-width:520px){.color-sort-note{display:none}}
</style>

<div class="schedule-settings-layout">
    <?php require __DIR__ . '/includes/settings-nav.php'; ?>

    <main class="schedule-settings-main">
        <div class="schedule-page-title">
            <div class="schedule-page-title-row">
                <h1>Schedule</h1>
                <button type="button" class="schedule-mobile-settings-btn" id="mobileSettingsBtn" aria-expanded="false" aria-controls="mobileSettingsPopover">
                    <span>Settings</span>
                    <i data-lucide="chevron-down"></i>
                </button>
                <div class="schedule-mobile-settings-popover" id="mobileSettingsPopover" aria-hidden="true"></div>
            </div>
        </div>
        <div class="schedule-mobile-settings-backdrop" id="mobileSettingsBackdrop" aria-hidden="true"></div>

        <?php if (!$schemaReady): ?>
            <div class="schedule-schema-warning">
                Run <strong>database/schedule-settings-migration.sql</strong> before saving Schedule settings.
            </div>
        <?php endif; ?>

        <form id="scheduleSettingsForm">
            <input type="hidden" name="csrf_token" value="<?= ss_h($csrfToken) ?>">
            <input type="hidden" name="action" value="save_settings">

            <section class="schedule-card">
                <div class="schedule-card-body">
                    <h2>Personal settings</h2>
                    <p class="schedule-card-intro">
                        Control how your calendar looks and behaves. These preferences are saved only for your user account.
                    </p>

                    <div class="schedule-setting-row">
                        <div class="schedule-setting-copy">
                            <strong>Appointment layout</strong>
                            <p>Choose how appointments are laid out in the Week view. Nested improves title legibility in busy schedules, while Stacked emphasizes time gaps.</p>
                        </div>
                        <div class="schedule-choice-grid">
                            <label class="schedule-choice">
                                <input type="radio" name="appointment_layout" value="nested" <?= $settings['appointment_layout'] === 'nested' ? 'checked' : '' ?>>
                                <img class="schedule-choice-preview" src="assets/settings-schedule/nested.png" alt="Nested appointment layout preview">
                                <div class="schedule-choice-label"><span class="schedule-radio-dot"></span>Nested</div>
                            </label>
                            <label class="schedule-choice">
                                <input type="radio" name="appointment_layout" value="stacked" <?= $settings['appointment_layout'] === 'stacked' ? 'checked' : '' ?>>
                                <img class="schedule-choice-preview" src="assets/settings-schedule/stacked.png" alt="Stacked appointment layout preview">
                                <div class="schedule-choice-label"><span class="schedule-radio-dot"></span>Stacked</div>
                            </label>
                        </div>
                    </div>

                    <div class="schedule-setting-row">
                        <div class="schedule-setting-copy">
                            <strong>Completed appointment styling</strong>
                            <p>Choose how completed appointments appear. Grayed out keeps completed work visually quiet. Strikethrough preserves the assigned color.</p>
                        </div>
                        <div class="schedule-choice-grid">
                            <label class="schedule-choice">
                                <input type="radio" name="completed_appointment_style" value="grayed_out" <?= $settings['completed_appointment_style'] === 'grayed_out' ? 'checked' : '' ?>>
                                <img class="schedule-choice-preview" src="assets/settings-schedule/Grayed out.png" alt="Grayed out completed appointment preview">
                                <div class="schedule-choice-label"><span class="schedule-radio-dot"></span>Grayed out</div>
                            </label>
                            <label class="schedule-choice">
                                <input type="radio" name="completed_appointment_style" value="strikethrough" <?= $settings['completed_appointment_style'] === 'strikethrough' ? 'checked' : '' ?>>
                                <img class="schedule-choice-preview" src="assets/settings-schedule/Strikethrough.png" alt="Strikethrough completed appointment preview">
                                <div class="schedule-choice-label"><span class="schedule-radio-dot"></span>Strikethrough</div>
                            </label>
                        </div>
                    </div>

                    <div class="schedule-setting-row">
                        <div class="schedule-setting-copy">
                            <strong>Day view orientation</strong>
                            <p>Choose how time is shown in the Day view. Vertical works well for many appointments, while Horizontal gives a timeline-style view.</p>
                        </div>
                        <div class="schedule-choice-grid">
                            <label class="schedule-choice">
                                <input type="radio" name="day_view_orientation" value="vertical" <?= $settings['day_view_orientation'] === 'vertical' ? 'checked' : '' ?>>
                                <img class="schedule-choice-preview" src="assets/settings-schedule/vertical.png" alt="Vertical day view preview">
                                <div class="schedule-choice-label"><span class="schedule-radio-dot"></span>Vertical</div>
                            </label>
                            <label class="schedule-choice">
                                <input type="radio" name="day_view_orientation" value="horizontal" <?= $settings['day_view_orientation'] === 'horizontal' ? 'checked' : '' ?>>
                                <img class="schedule-choice-preview" src="assets/settings-schedule/Horizontal.png" alt="Horizontal day view preview">
                                <div class="schedule-choice-label"><span class="schedule-radio-dot"></span>Horizontal</div>
                            </label>
                        </div>
                    </div>

                    <div class="schedule-toggle-row">
                        <label class="schedule-toggle">
                            <input type="checkbox" name="show_weekends" value="1" <?= !empty($settings['show_weekends']) ? 'checked' : '' ?>>
                            <span class="schedule-toggle-track"></span>
                        </label>
                        <div class="schedule-toggle-copy">
                            <strong>Show weekends</strong>
                            <p>Display Saturday and Sunday alongside your regular weekdays in Month and Week view.</p>
                        </div>
                    </div>

                    <div class="schedule-toggle-row">
                        <label class="schedule-toggle">
                            <input type="checkbox" name="confirm_reschedule_notification" value="1" <?= !empty($settings['confirm_reschedule_notification']) ? 'checked' : '' ?>>
                            <span class="schedule-toggle-track"></span>
                        </label>
                        <div class="schedule-toggle-copy">
                            <strong>Ask to confirm reschedule notification</strong>
                            <p>Show a confirmation when moving a calendar block before sending a reschedule notification.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="schedule-card">
                <div class="schedule-card-body">
                    <h2>Availability</h2>

                    <div class="availability-row">
                        <div>
                            <strong>Business hours</strong>
                            <p>Adjust company operating hours in Company Settings. Schedule availability can use these hours when all team members are displayed.</p>
                        </div>
                        <a class="schedule-btn" href="settings.php">Company Settings</a>
                    </div>

                    <div class="availability-row">
                        <div>
                            <strong>Working hours</strong>
                            <p>Manage individual team member working hours from the team profile. Working hours are used for availability and scheduling.</p>
                        </div>
                        <a class="schedule-btn" href="team.php">Manage Team</a>
                    </div>
                </div>
            </section>

            <section class="schedule-card calendar-color-card">
                <div class="schedule-card-body">
                    <div class="calendar-color-head">
                        <h2>Calendar colors</h2>
                        <button type="button" class="schedule-btn primary" id="assignColorBtn">Assign a Color</button>
                    </div>
                    <?php if (empty($colorAssignments)): ?>
                        <div class="color-empty" id="colorEmpty">
                            <div class="color-empty-icon"><i data-lucide="calendar-days"></i></div>
                            <div><strong>No colors are assigned</strong><p>Quickly identify team members by color on the schedule.</p><button type="button" class="schedule-btn" id="assignColorBtnEmpty">Assign a Color</button></div>
                        </div>
                    <?php else: ?>
                        <div class="color-rule-list" id="colorList">
                            <?php foreach ($colorAssignments as $assignment): ?>
                                <?php
                                $ruleType = isset($assignment['rule_type']) ? (string)$assignment['rule_type'] : 'assigned_to';
                                $ruleValue = isset($assignment['rule_value']) ? (string)$assignment['rule_value'] : '';
                                if ($ruleType === 'title_contains') { $prefix = 'Item title contains'; $name = $ruleValue !== '' ? $ruleValue : 'No text'; }
                                else { $prefix = 'Assigned to'; $name = trim((string)$assignment['first_name'] . ' ' . (string)$assignment['last_name']); if ($name === '') $name = (string)$assignment['email']; }
                                ?>
                                <div class="color-rule-row" draggable="true" tabindex="0" role="button" aria-label="Edit <?= ss_h($prefix . ' ' . $name) ?>" data-assignment-id="<?= (int)$assignment['id'] ?>" data-user-id="<?= (int)$assignment['user_id'] ?>" data-rule-type="<?= ss_h($ruleType) ?>" data-rule-value="<?= ss_h($ruleValue) ?>" data-color="<?= ss_h($assignment['color_hex']) ?>">
                                    <span class="color-drag-handle" aria-hidden="true"><i data-lucide="grip-vertical"></i></span>
                                    <span class="color-rule-swatch" style="background:<?= ss_h($assignment['color_hex']) ?>"></span>
                                    <span class="color-rule-text"><span class="color-rule-prefix"><?= ss_h($prefix) ?></span> <span class="color-rule-value"><?= ss_h($name) ?></span></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="color-sort-note">Drag color rules to change priority. Click a rule to edit it.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="schedule-card">
                <div class="schedule-card-body">
                    <h2>Calendar sync</h2>
                    <p class="schedule-card-intro">Sync your FieldPlx calendar to apps like iCal or Google Calendar.</p>
                    <button class="schedule-btn" type="button" id="openCalendarSyncBtn">Set Up Calendar Sync</button>
                </div>
            </section>

            <section class="schedule-card">
                <div class="schedule-card-body">
                    <h2>Day sheet options</h2>

                    <div class="day-sheet-grid">
                        <label class="day-sheet-option">
                            <input type="checkbox" name="day_sheet_property_map" value="1" <?= !empty($settings['day_sheet_property_map']) ? 'checked' : '' ?>>
                            <span>Property map<small>Show a map of the jobs for the day</small></span>
                        </label>

                        <label class="day-sheet-option">
                            <input type="checkbox" name="day_sheet_notes_area" value="1" <?= !empty($settings['day_sheet_notes_area']) ? 'checked' : '' ?>>
                            <span>Notes area<small>Provide space for notes on each job</small></span>
                        </label>

                        <label class="day-sheet-option">
                            <input type="checkbox" name="day_sheet_custom_information" id="customInfoToggle" value="1" <?= !empty($settings['day_sheet_custom_information']) ? 'checked' : '' ?>>
                            <span>Custom information<small>Appears under each job on the day sheet</small></span>
                        </label>
                    </div>

                    <div class="custom-info-wrap" id="customInfoWrap" <?= empty($settings['day_sheet_custom_information']) ? 'style="display:none"' : '' ?>>
                        <textarea
                            class="schedule-textarea"
                            name="day_sheet_custom_template"
                            maxlength="1000"
                            placeholder="Time In:&#10;Time Out:"
                        ><?= ss_h($settings['day_sheet_custom_template']) ?></textarea>
                    </div>
                </div>
            </section>

            <div class="schedule-actions">
                <button class="schedule-btn primary" type="submit" id="updateSettingsBtn" <?= $schemaReady ? '' : 'disabled' ?>>
                    Update Settings
                </button>
            </div>
        </form>
    </main>
</div>

<div class="schedule-modal-bg" id="colorModal" aria-hidden="true">
    <div class="schedule-modal" role="dialog" aria-modal="true" aria-labelledby="colorModalTitle">
        <div class="schedule-modal-head">
            <h3 id="colorModalTitle">Assign a color</h3>
            <button type="button" class="schedule-modal-x" data-close-color>&times;</button>
        </div>

        <form id="colorForm">
            <div class="schedule-modal-body">
                <input type="hidden" name="csrf_token" value="<?= ss_h($csrfToken) ?>">
                <input type="hidden" name="action" value="save_color">
                <input type="hidden" name="assignment_id" id="colorAssignmentId" value="0">
                <input type="hidden" name="color_hex" id="selectedColor" value="#0B1933">

                <div style="font-size:11px;color:#45606f">Choose a color</div>

                <div class="color-palette" id="colorPalette"></div>

                <div class="color-apply-grid">
                    <select class="schedule-select" name="rule_type" id="colorRuleType">
                        <option value="assigned_to">when assigned to</option>
                        <option value="title_contains">when item title contains</option>
                    </select>

                    <div id="assignedToWrap">
                        <select class="schedule-select" name="user_id" id="colorUserId">
                            <option value="">Select team member</option>
                            <?php foreach ($teamMembers as $member): ?>
                                <?php
                                $memberName = trim((string)$member['first_name'] . ' ' . (string)$member['last_name']);
                                if ($memberName === '') $memberName = (string)$member['email'];
                                ?>
                                <option value="<?= (int)$member['id'] ?>"><?= ss_h($memberName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="titleContainsWrap" style="display:none">
                        <input class="schedule-select" type="text" name="rule_value" id="colorRuleValue" maxlength="190" placeholder="Enter item title text">
                    </div>
                </div>
            </div>

            <div class="schedule-modal-foot">
                <button type="button" class="schedule-btn color-modal-delete" id="deleteColorBtn" style="display:none">Delete</button>
                <button type="button" class="schedule-btn" data-close-color>Cancel</button>
                <button type="submit" class="schedule-btn primary" <?= $schemaReady ? '' : 'disabled' ?>>Save</button>
            </div>
        </form>
    </div>
</div>


<div class="schedule-modal-bg" id="calendarSyncModal" aria-hidden="true">
    <div class="schedule-modal" role="dialog" aria-modal="true" aria-labelledby="calendarSyncTitle">
        <div class="schedule-modal-head">
            <h3 id="calendarSyncTitle">Calendar sync</h3>
            <button type="button" class="schedule-modal-x" data-close-sync>&times;</button>
        </div>

        <form id="calendarSyncForm">
            <div class="schedule-modal-body">
                <input type="hidden" name="csrf_token" value="<?= ss_h($csrfToken) ?>">
                <input type="hidden" name="action" value="save_calendar_sync">

                <p style="margin:0 0 12px;color:#46606f;font-size:11px;line-height:1.5">
                    Sync your FieldPlx calendar to apps like iCal or Google Calendar.
                </p>

                <strong style="display:block;color:#0b2e40;font-size:12px;margin-bottom:10px">
                    Which calendar items would you like to sync?
                </strong>

                <div class="sync-checks">
                    <label><input type="checkbox" name="sync_tasks" value="1" <?= !empty($calendarSync['sync_tasks']) ? 'checked' : '' ?>> Tasks</label>
                    <label><input type="checkbox" name="sync_reminders" value="1" <?= !empty($calendarSync['sync_reminders']) ? 'checked' : '' ?>> Reminders</label>
                    <label><input type="checkbox" name="sync_events" value="1" <?= !empty($calendarSync['sync_events']) ? 'checked' : '' ?>> Events</label>
                    <label><input type="checkbox" name="sync_visits" value="1" <?= !empty($calendarSync['sync_visits']) ? 'checked' : '' ?>> Visits</label>
                    <label><input type="checkbox" name="sync_requests" value="1" <?= !empty($calendarSync['sync_requests']) ? 'checked' : '' ?>> Requests</label>
                </div>

                <strong style="display:block;color:#0b2e40;font-size:12px;margin:13px 0 8px">
                    Copy the URL below into your app's subscription settings
                </strong>

                <div class="sync-url-row">
                    <input
                        type="text"
                        class="schedule-select"
                        id="calendarSyncUrl"
                        value="<?= ss_h($calendarSync['subscription_token'] !== '' ? ('calendar-feed.php?token=' . $calendarSync['subscription_token']) : '') ?>"
                        placeholder="Save to generate your subscription URL"
                        readonly
                    >
                    <button class="schedule-btn" type="button" id="copyCalendarUrl">Copy</button>
                </div>
            </div>

            <div class="schedule-modal-foot">
                <button type="button" class="schedule-btn" data-close-sync>Cancel</button>
                <button type="submit" class="schedule-btn primary" <?= $schemaReady ? '' : 'disabled' ?>>Save</button>
            </div>
        </form>
    </div>
</div>

<script>
(function(window, document){
    'use strict';

    var mobileSettingsBtn = document.getElementById('mobileSettingsBtn');
    var mobileSettingsPopover = document.getElementById('mobileSettingsPopover');
    var mobileSettingsBackdrop = document.getElementById('mobileSettingsBackdrop');
    var desktopSettingsNav = document.querySelector('.schedule-settings-layout > .fieldplx-settings-nav');

    function buildMobileSettingsNav(){
        if(!mobileSettingsPopover || !desktopSettingsNav || mobileSettingsPopover.children.length) return;
        var clone = desktopSettingsNav.cloneNode(true);
        clone.removeAttribute('aria-label');
        clone.setAttribute('aria-label','Mobile settings navigation');
        mobileSettingsPopover.appendChild(clone);
    }
    function setMobileSettings(open){
        if(!mobileSettingsBtn || !mobileSettingsPopover || !mobileSettingsBackdrop) return;
        if(open) buildMobileSettingsNav();
        mobileSettingsBtn.classList.toggle('open',!!open);
        mobileSettingsPopover.classList.toggle('open',!!open);
        mobileSettingsBackdrop.classList.toggle('open',!!open);
        mobileSettingsBtn.setAttribute('aria-expanded',open?'true':'false');
        mobileSettingsPopover.setAttribute('aria-hidden',open?'false':'true');
        mobileSettingsBackdrop.setAttribute('aria-hidden',open?'false':'true');
        document.body.classList.toggle('schedule-settings-menu-open',!!open);
    }
    if(mobileSettingsBtn){
        mobileSettingsBtn.addEventListener('click',function(e){
            e.stopPropagation();
            setMobileSettings(!mobileSettingsPopover.classList.contains('open'));
        });
    }
    if(mobileSettingsBackdrop){mobileSettingsBackdrop.addEventListener('click',function(){setMobileSettings(false);});}
    if(mobileSettingsPopover){mobileSettingsPopover.addEventListener('click',function(e){if(e.target.closest('a')) setMobileSettings(false);});}
    window.addEventListener('resize',function(){if(window.innerWidth>980) setMobileSettings(false);});

    var apiUrl = 'api/schedule-settings.php';
    var colorApiUrl = 'api/schedule-color-settings.php';
    var colorModal = document.getElementById('colorModal');
    var colorForm = document.getElementById('colorForm');
    var selectedColor = document.getElementById('selectedColor');
    var colorUserId = document.getElementById('colorUserId');
    var colorRuleType = document.getElementById('colorRuleType');
    var colorRuleValue = document.getElementById('colorRuleValue');
    var colorAssignmentId = document.getElementById('colorAssignmentId');
    var colorModalTitle = document.getElementById('colorModalTitle');
    var deleteColorBtn = document.getElementById('deleteColorBtn');
    var assignedToWrap = document.getElementById('assignedToWrap');
    var titleContainsWrap = document.getElementById('titleContainsWrap');

    var calendarSyncModal = document.getElementById('calendarSyncModal');
    var calendarSyncForm = document.getElementById('calendarSyncForm');

    var palette = [
        '#000000','#8F1515','#A44E10','#6F3D16','#4D5600','#123D17','#146047','#145B5C','#173F50','#050B99','#44006A','#62003A',
        '#343434','#AD0000','#C94B12','#A88A16','#8D8200','#285D26','#2CCB72','#507187','#247CB7','#1B5595','#6600A8','#A30B58',
        '#626262','#D00000','#FF845A','#C6B65C','#6DAE3C','#49652E','#48BF8D','#07989D','#4898D5','#5046AA','#9623AF','#D800B6',
        '#9B9B9B','#FF4545','#FF8500','#F3DF38','#91EF39','#008E11','#54E6A5','#10B4C9','#1D91F0','#6487E9','#AB46C4','#E17BDD',
        '#B8B8B8','#FF8181','#E9BD81','#FFEEA0','#DBE8C4','#A8B896','#A0F476','#7FDCE6','#70BCEB','#99B6F4','#CDA7DA','#ED5C8A',
        '#D9D9D9','#FFAAAA','#FFD0A1','#FFF0B7','#E8F5A6','#A4E6A5','#D0F7E0','#DFF4F7','#89CDEE','#D6E2FA','#EEDDF1','#ED9DBC'
    ];

    function toast(type, message){
        if (typeof window.fieldplxToast === 'function') {
            window.fieldplxToast(type, message);
        } else if (type === 'error') {
            window.alert(message);
        }
    }

    function request(formData){
        return fetch(apiUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        }).then(function(response){
            return response.json().catch(function(){
                throw new Error('Invalid server response.');
            }).then(function(data){
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to save schedule settings.');
                }
                return data;
            });
        });
    }

    function colorRequest(formData){
        return fetch(colorApiUrl,{method:'POST',body:formData,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(function(response){
            return response.text().then(function(raw){
                var data; try{data=JSON.parse(raw);}catch(e){throw new Error('Invalid calendar color server response.');}
                if(!response.ok||!data.success)throw new Error(data.message||'Unable to update calendar colors.');
                return data;
            });
        });
    }

    function renderPalette(){
        var wrap = document.getElementById('colorPalette');
        wrap.innerHTML = '';
        palette.forEach(function(color){
            var button = document.createElement('button');
            button.type = 'button';
            button.style.background = color;
            button.setAttribute('data-color', color);
            if (color === selectedColor.value.toUpperCase()) {
                button.classList.add('selected');
            }
            button.addEventListener('click', function(){
                wrap.querySelectorAll('button').forEach(function(item){
                    item.classList.remove('selected');
                });
                button.classList.add('selected');
                selectedColor.value = color;
            });
            wrap.appendChild(button);
        });
    }

    function syncColorRuleInputs(){
        var isTitle = colorRuleType.value === 'title_contains';
        assignedToWrap.style.display = isTitle ? 'none' : 'block';
        titleContainsWrap.style.display = isTitle ? 'block' : 'none';
        colorUserId.required = !isTitle;
        colorRuleValue.required = isTitle;
    }

    function openColorModal(assignmentId,userId,color,ruleType,ruleValue){
        var isEdit=Number(assignmentId||0)>0;
        colorAssignmentId.value=isEdit?String(assignmentId):'0';
        colorRuleType.value=ruleType||'assigned_to';
        colorUserId.value=userId?String(userId):'';
        colorRuleValue.value=ruleValue||'';
        selectedColor.value=color||'#0B1933';
        colorModalTitle.textContent=isEdit?'Edit Color':'Assign a color';
        deleteColorBtn.style.display=isEdit?'inline-flex':'none';
        syncColorRuleInputs(); renderPalette();
        colorModal.classList.add('open'); colorModal.setAttribute('aria-hidden','false');
    }
    function closeColorModal(){colorModal.classList.remove('open');colorModal.setAttribute('aria-hidden','true');colorAssignmentId.value='0';}
    var assignBtn=document.getElementById('assignColorBtn');
    if(assignBtn)assignBtn.addEventListener('click',function(){openColorModal(0,'','#0B1933','assigned_to','');});
    var emptyAssignBtn=document.getElementById('assignColorBtnEmpty');
    if(emptyAssignBtn)emptyAssignBtn.addEventListener('click',function(){openColorModal(0,'','#0B1933','assigned_to','');});

    var colorList=document.getElementById('colorList');
    if(colorList){
        colorList.addEventListener('click',function(e){if(e.target.closest('.color-drag-handle'))return;var row=e.target.closest('.color-rule-row');if(!row)return;openColorModal(row.dataset.assignmentId,row.dataset.userId,row.dataset.color,row.dataset.ruleType,row.dataset.ruleValue);});
        colorList.addEventListener('keydown',function(e){var row=e.target.closest('.color-rule-row');if(!row||(e.key!=='Enter'&&e.key!==' '))return;e.preventDefault();openColorModal(row.dataset.assignmentId,row.dataset.userId,row.dataset.color,row.dataset.ruleType,row.dataset.ruleValue);});
        var draggedRow=null;
        colorList.addEventListener('dragstart',function(e){var row=e.target.closest('.color-rule-row');if(!row)return;draggedRow=row;row.classList.add('dragging');if(e.dataTransfer){e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',row.dataset.assignmentId||'');}});
        colorList.addEventListener('dragover',function(e){if(!draggedRow)return;e.preventDefault();var target=e.target.closest('.color-rule-row');colorList.querySelectorAll('.color-rule-row').forEach(function(r){r.classList.remove('drag-over');});if(!target||target===draggedRow)return;target.classList.add('drag-over');var rect=target.getBoundingClientRect();if(e.clientY<rect.top+rect.height/2)colorList.insertBefore(draggedRow,target);else colorList.insertBefore(draggedRow,target.nextSibling);});
        colorList.addEventListener('drop',function(e){if(draggedRow)e.preventDefault();});
        colorList.addEventListener('dragend',function(){if(!draggedRow)return;draggedRow.classList.remove('dragging');colorList.querySelectorAll('.color-rule-row').forEach(function(r){r.classList.remove('drag-over');});draggedRow=null;var ids=Array.prototype.map.call(colorList.querySelectorAll('.color-rule-row'),function(r){return Number(r.dataset.assignmentId||0);}).filter(Boolean);var fd=new FormData();fd.append('csrf_token',<?= json_encode($csrfToken) ?>);fd.append('action','save_color_order');fd.append('order_json',JSON.stringify(ids));colorRequest(fd).then(function(data){toast('success',data.message||'Calendar color order updated.');}).catch(function(error){toast('error',error.message);});});
    }

    colorRuleType.addEventListener('change', syncColorRuleInputs);

    document.querySelectorAll('[data-close-color]').forEach(function(button){
        button.addEventListener('click', closeColorModal);
    });

    colorModal.addEventListener('click', function(e){
        if (e.target === colorModal) closeColorModal();
    });

    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
            setMobileSettings(false);
            closeColorModal();
            if (calendarSyncModal) {
                calendarSyncModal.classList.remove('open');
                calendarSyncModal.setAttribute('aria-hidden', 'true');
            }
        }
    });

    var customToggle = document.getElementById('customInfoToggle');
    var customWrap = document.getElementById('customInfoWrap');
    customToggle.addEventListener('change', function(){
        customWrap.style.display = customToggle.checked ? 'block' : 'none';
    });

    document.getElementById('scheduleSettingsForm').addEventListener('submit', function(e){
        e.preventDefault();

        var form = e.currentTarget;
        var button = document.getElementById('updateSettingsBtn');
        var oldText = button.textContent;

        button.disabled = true;
        button.textContent = 'Saving...';

        request(new FormData(form)).then(function(data){
            toast('success', data.message);
        }).catch(function(error){
            toast('error', error.message);
        }).then(function(){
            button.disabled = false;
            button.textContent = oldText;
        });
    });

    colorForm.addEventListener('submit',function(e){
        e.preventDefault();var button=colorForm.querySelector('[type="submit"]');var oldText=button.textContent;button.disabled=true;button.textContent='Saving...';
        colorRequest(new FormData(colorForm)).then(function(data){toast('success',data.message);closeColorModal();window.location.reload();}).catch(function(error){toast('error',error.message);}).then(function(){button.disabled=false;button.textContent=oldText;});
    });
    if(deleteColorBtn){deleteColorBtn.addEventListener('click',function(){var id=Number(colorAssignmentId.value||0);if(!id)return;if(!window.confirm('Delete this calendar color rule?'))return;var oldText=deleteColorBtn.textContent;deleteColorBtn.disabled=true;deleteColorBtn.textContent='Deleting...';var fd=new FormData();fd.append('csrf_token',<?= json_encode($csrfToken) ?>);fd.append('action','delete_color');fd.append('assignment_id',String(id));colorRequest(fd).then(function(data){toast('success',data.message||'Calendar color deleted.');closeColorModal();window.location.reload();}).catch(function(error){toast('error',error.message);}).then(function(){deleteColorBtn.disabled=false;deleteColorBtn.textContent=oldText;});});}



    var openCalendarSyncBtn = document.getElementById('openCalendarSyncBtn');
    if (openCalendarSyncBtn) {
        openCalendarSyncBtn.addEventListener('click', function(){
            calendarSyncModal.classList.add('open');
            calendarSyncModal.setAttribute('aria-hidden', 'false');
        });
    }

    function closeCalendarSync(){
        calendarSyncModal.classList.remove('open');
        calendarSyncModal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('[data-close-sync]').forEach(function(button){
        button.addEventListener('click', closeCalendarSync);
    });

    calendarSyncModal.addEventListener('click', function(e){
        if (e.target === calendarSyncModal) closeCalendarSync();
    });

    calendarSyncForm.addEventListener('submit', function(e){
        e.preventDefault();

        var button = calendarSyncForm.querySelector('[type="submit"]');
        var oldText = button.textContent;
        button.disabled = true;
        button.textContent = 'Saving...';

        request(new FormData(calendarSyncForm)).then(function(data){
            if (data.subscription_url) {
                document.getElementById('calendarSyncUrl').value = data.subscription_url;
            }
            toast('success', data.message);
        }).catch(function(error){
            toast('error', error.message);
        }).then(function(){
            button.disabled = false;
            button.textContent = oldText;
        });
    });

    document.getElementById('copyCalendarUrl').addEventListener('click', function(){
        var input = document.getElementById('calendarSyncUrl');
        if (!input.value) {
            toast('error', 'Save calendar sync settings first to generate a URL.');
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(function(){
                toast('success', 'Calendar subscription URL copied.');
            }).catch(function(){
                input.select();
                document.execCommand('copy');
                toast('success', 'Calendar subscription URL copied.');
            });
        } else {
            input.select();
            document.execCommand('copy');
            toast('success', 'Calendar subscription URL copied.');
        }
    });

    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
})(window, document);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
