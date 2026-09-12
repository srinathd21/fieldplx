<?php
/**
 * FieldPlx - Location Services Settings
 * File: business/location-services.php
 * Compatible with PHP 7.2+ / MariaDB 11.x
 */

require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Location Services · FieldPlx';
$pageDescription = 'Manage location-based timer preferences for field teams';
$settingsActivePage = 'location-services';

$tenantId = isset($currentTenantId)
    ? (int)$currentTenantId
    : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);

$userId = isset($currentTenantUserId)
    ? (int)$currentTenantUserId
    : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0));

if (empty($_SESSION['location_services_csrf'])) {
    $_SESSION['location_services_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['location_services_csrf'];

function ls_h($value)
{
    return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
}

function ls_table_exists(PDO $pdo, $table)
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

$schemaReady = ls_table_exists($pdo, 'tenant_location_service_settings');

$settings = array(
    'location_timers_enabled' => 0,
    'timer_mode' => 'automatic'
);

if ($schemaReady && $tenantId > 0) {
    $q = $pdo->prepare("
        SELECT location_timers_enabled, timer_mode
        FROM tenant_location_service_settings
        WHERE tenant_id = :tenant_id
        LIMIT 1
    ");
    $q->execute(array(':tenant_id' => $tenantId));
    $row = $q->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $settings = array_merge($settings, $row);
    }
}

require __DIR__ . '/includes/header.php';

if (file_exists(__DIR__ . '/includes/toast.php')) {
    require_once __DIR__ . '/includes/toast.php';
}
?>

<style>
.location-services-layout{
    display:grid;
    grid-template-columns:250px minmax(0,1fr);
    gap:28px;
    max-width:1240px;
    margin:0 auto;
    padding:0 0 46px;
    align-items:start;
}
.location-services-main{min-width:0}
.location-services-title{
    margin:0 0 20px;
}
.location-services-title h1{
    margin:0;
    color:var(--fieldplx-text,#0b1933);
    font-size:30px;
    line-height:1.16;
    font-weight:700;
    letter-spacing:-.01em;
}
.location-services-card{
    border:1px solid #dfe5eb;
    border-radius:10px;
    background:#fff;
    overflow:hidden;
}
.location-services-card-body{
    padding:16px 18px 14px;
}
.location-timers-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:24px;
}
.location-timers-copy{
    min-width:0;
    flex:1 1 auto;
}
.location-timers-copy h2{
    margin:0;
    color:#0a2d3d;
    font-size:20px;
    line-height:1.2;
}
.location-timers-copy > p{
    margin:8px 0 0;
    color:#405a6a;
    font-size:11px;
    line-height:1.5;
    max-width:820px;
}
.location-master-toggle{
    position:relative;
    width:42px;
    height:22px;
    flex:0 0 auto;
    margin-top:5px;
}
.location-master-toggle input{
    opacity:0;
    width:0;
    height:0;
}
.location-master-toggle-track{
    position:absolute;
    inset:0;
    cursor:pointer;
    border-radius:999px;
    background:#d9e1e5;
    transition:background .18s ease;
}
.location-master-toggle-track:after{
    content:"";
    position:absolute;
    top:3px;
    left:3px;
    width:16px;
    height:16px;
    border-radius:50%;
    background:#fff;
    box-shadow:0 1px 3px rgba(0,0,0,.18);
    transition:transform .18s ease;
}
.location-master-toggle input:checked + .location-master-toggle-track{
    background:#318d27;
}
.location-master-toggle input:checked + .location-master-toggle-track:after{
    transform:translateX(20px);
}
.location-options{
    margin:10px 0 0 14px;
}
.location-option{
    display:flex;
    align-items:flex-start;
    gap:8px;
    margin:7px 0;
    color:#2f4c5b;
    font-size:11px;
    line-height:1.35;
    cursor:pointer;
}
.location-option input{
    position:absolute;
    opacity:0;
    pointer-events:none;
}
.location-radio{
    width:16px;
    height:16px;
    margin-top:0;
    border:1px solid #cbd6dc;
    border-radius:50%;
    background:#fff;
    position:relative;
    flex:0 0 auto;
}
.location-option input:checked + .location-radio{
    border-color:#3b932d;
}
.location-option input:checked + .location-radio:after{
    content:"";
    position:absolute;
    inset:3px;
    border-radius:50%;
    background:#3b932d;
}
.location-options.disabled{
    opacity:.43;
    pointer-events:none;
}
.location-note{
    margin:14px 0 0;
    color:#596d79;
    font-size:9px;
    line-height:1.4;
}
.location-note a{
    color:#3c922c;
    text-decoration:none;
}
.location-note a:hover{text-decoration:underline}
.location-schema-warning{
    margin:0 0 16px;
    padding:11px 13px;
    border:1px solid #efd393;
    border-radius:8px;
    background:#fff8e7;
    color:#7b5b08;
    font-size:11px;
    line-height:1.45;
}
.location-save-status{
    min-height:18px;
    margin-top:8px;
    color:#72818b;
    font-size:9px;
}
.location-save-status.saving{color:#6c7a83}
.location-save-status.saved{color:#2f8c25}
.location-save-status.error{color:#b44343}
@media(max-width:980px){
    .location-services-layout{
        grid-template-columns:1fr;
        padding:0 14px 30px;
    }
    .location-services-layout>.fieldplx-settings-nav{display:none}
}
@media(max-width:620px){
    .location-services-title h1{font-size:25px}
    .location-services-card-body{padding:15px}
    .location-timers-head{gap:14px}
}
</style>

<div class="location-services-layout">
    <?php require __DIR__ . '/includes/settings-nav.php'; ?>

    <main class="location-services-main">
        <div class="location-services-title">
            <h1>Location Services</h1>
        </div>

        <?php if (!$schemaReady): ?>
            <div class="location-schema-warning">
                Run <strong>database/location-services-migration.sql</strong> before enabling Location Services.
            </div>
        <?php endif; ?>

        <section class="location-services-card">
            <div class="location-services-card-body">
                <div class="location-timers-head">
                    <div class="location-timers-copy">
                        <h2>Location timers</h2>
                        <p>
                            Team members can consent to receiving notifications based on their location to help with daily time tracking.
                            Location timers can be turned on in the mobile app through Preferences in Settings.
                        </p>
                    </div>

                    <label class="location-master-toggle" aria-label="Enable location timers">
                        <input
                            type="checkbox"
                            id="locationTimersEnabled"
                            <?= !empty($settings['location_timers_enabled']) ? 'checked' : '' ?>
                            <?= $schemaReady ? '' : 'disabled' ?>
                        >
                        <span class="location-master-toggle-track"></span>
                    </label>
                </div>

                <div
                    class="location-options<?= empty($settings['location_timers_enabled']) ? ' disabled' : '' ?>"
                    id="locationTimerOptions"
                >
                    <label class="location-option">
                        <input
                            type="radio"
                            name="location_timer_mode"
                            value="automatic"
                            <?= $settings['timer_mode'] === 'automatic' ? 'checked' : '' ?>
                        >
                        <span class="location-radio"></span>
                        <span>Track time automatically when near a visit</span>
                    </label>

                    <label class="location-option">
                        <input
                            type="radio"
                            name="location_timer_mode"
                            value="reminder"
                            <?= $settings['timer_mode'] === 'reminder' ? 'checked' : '' ?>
                        >
                        <span class="location-radio"></span>
                        <span>Receive reminders to start or stop timers when near a visit</span>
                    </label>
                </div>

                <p class="location-note">
                    Location timers use geofencing and are optional. By using this feature, you are responsible for ensuring compliance with local laws and regulations.
                    Learn more about location timers in our <a href="#" id="locationHelpLink">Help Center</a>.
                </p>

                <div class="location-save-status" id="locationSaveStatus" aria-live="polite"></div>
            </div>
        </section>
    </main>
</div>

<script>
(function(window, document){
    'use strict';

    var apiUrl = 'api/location-services.php';
    var csrfToken = <?= json_encode($csrfToken) ?>;
    var enabledToggle = document.getElementById('locationTimersEnabled');
    var optionsWrap = document.getElementById('locationTimerOptions');
    var status = document.getElementById('locationSaveStatus');
    var saveTimer = null;
    var inFlight = false;

    function toast(type, message){
        if (typeof window.fieldplxToast === 'function') {
            window.fieldplxToast(type, message);
        } else if (type === 'error') {
            window.alert(message);
        }
    }

    function setStatus(type, message){
        status.className = 'location-save-status' + (type ? ' ' + type : '');
        status.textContent = message || '';
    }

    function selectedMode(){
        var checked = document.querySelector('input[name="location_timer_mode"]:checked');
        return checked ? checked.value : 'automatic';
    }

    function syncEnabledUi(){
        if (enabledToggle.checked) {
            optionsWrap.classList.remove('disabled');
        } else {
            optionsWrap.classList.add('disabled');
        }
    }

    function saveSettings(){
        if (inFlight || enabledToggle.disabled) return;

        inFlight = true;
        setStatus('saving', 'Saving...');

        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'save');
        fd.append('location_timers_enabled', enabledToggle.checked ? '1' : '0');
        fd.append('timer_mode', selectedMode());

        fetch(apiUrl, {
            method:'POST',
            body:fd,
            credentials:'same-origin',
            headers:{
                'X-Requested-With':'XMLHttpRequest',
                'Accept':'application/json'
            }
        }).then(function(response){
            return response.json().catch(function(){
                throw new Error('Invalid server response.');
            }).then(function(data){
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to save Location Services.');
                }
                return data;
            });
        }).then(function(data){
            setStatus('saved', 'Saved');
            window.setTimeout(function(){
                if (status.textContent === 'Saved') setStatus('', '');
            }, 1800);
        }).catch(function(error){
            setStatus('error', 'Unable to save');
            toast('error', error.message);
        }).then(function(){
            inFlight = false;
        });
    }

    function queueSave(){
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(saveSettings, 180);
    }

    if (enabledToggle) {
        enabledToggle.addEventListener('change', function(){
            syncEnabledUi();
            queueSave();
        });
    }

    document.querySelectorAll('input[name="location_timer_mode"]').forEach(function(input){
        input.addEventListener('change', queueSave);
    });

    var help = document.getElementById('locationHelpLink');
    if (help) {
        help.addEventListener('click', function(e){
            e.preventDefault();
            toast('info', 'Add your FieldPlx Help Center URL here when the Location Services help article is available.');
        });
    }

    syncEnabledUi();
})(window, document);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
