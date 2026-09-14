<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Form Builder · FieldPlx';
$pageDescription = 'Build request and booking forms';
$settingsActivePage = 'requests-bookings';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['request_booking_csrf'])) $_SESSION['request_booking_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string)$_SESSION['request_booking_csrf'];
$formId = isset($_GET['id']) ? max(0,(int)$_GET['id']) : 0;
if ($formId <= 0) { header('Location: request-booking-settings.php'); exit; }
$mapsKey = '';
if (defined('GOOGLE_MAPS_API_KEY') && trim((string)GOOGLE_MAPS_API_KEY) !== '') {
    $mapsKey = trim((string)GOOGLE_MAPS_API_KEY);
} else {
    $envMapsKey = getenv('GOOGLE_MAPS_API_KEY');
    if ($envMapsKey !== false) $mapsKey = trim((string)$envMapsKey);
}
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/toast.php';
?>
<link rel="stylesheet" href="assets/request-booking-builder.css?v=20260914-6">
<div class="rb-builder" id="builderRoot">
    <header class="rbb-top">
        <div class="rbb-title"><h1 id="builderTitle">Edit Form</h1><button type="button" id="copyPublicLink" title="Copy public link"><i data-lucide="link"></i></button></div>
        <div class="rbb-tabs"><button class="rbb-tab" id="previewTab" type="button">Preview</button><button class="rbb-tab active" id="editTab" type="button">Edit</button></div>
        <div class="rbb-actions"><button class="rbb-icon" id="phoneView" type="button" title="Phone preview"><i data-lucide="smartphone"></i></button><button class="rbb-icon active" id="desktopView" type="button" title="Desktop preview"><i data-lucide="monitor"></i></button><a class="rbb-btn" href="request-booking-settings.php">Cancel</a><button class="rbb-btn primary" id="saveBtn" type="button"><i data-lucide="save"></i>Save</button></div>
    </header>

    <div class="rbb-work" id="editView">
        <div class="rbb-canvas-wrap"><div class="rbb-paper" id="formCanvas"></div></div>
        <aside class="rbb-side">
            <h2>Manage form</h2>
            <div class="rbb-side-tabs"><button class="rbb-side-tab active" data-side-tab="questions" type="button">Add Questions</button><button class="rbb-side-tab" data-side-tab="settings" type="button">Settings</button></div>
            <div id="questionsPanel">
                <div class="rbb-side-copy">Layout options</div><div class="rbb-palette"><button type="button" data-add-section><span class="rbb-pal-icon"><i data-lucide="square-plus"></i></span>Add section</button></div>
                <div class="rbb-side-copy">Custom questions<br><span style="font-weight:400">Select the type of question you'd like to ask</span></div>
                <div class="rbb-palette" id="customQuestionPalette"></div>
                <div class="rbb-side-copy">Standardized questions</div><div class="rbb-palette" id="standardPalette"></div>
                <div class="rbb-side-copy">Actions</div><div class="rbb-palette" id="actionPalette"></div>
            </div>
            <div id="settingsPanel" class="rbb-hidden">
                <div class="rbb-field"><label>Form title</label><input class="rbb-input" id="settingsTitle" maxlength="190"></div>
                <div class="rbb-field"><label>Form description</label><textarea class="rbb-textarea" id="settingsDescription"></textarea></div>
                <div class="rbb-setting-row"><div><strong>Form Pages</strong><p>When you add a section, it creates a new page in your form. You can turn this off at any time.</p></div><label class="rbb-toggle"><input type="checkbox" id="settingsPages"><span class="rbb-track"></span></label></div>
                <div class="rbb-setting-row"><div><strong>Request default</strong><p>Set this form as the default request form. Only one default request form can be active at a time.</p></div><label class="rbb-toggle"><input type="checkbox" id="settingsRequestDefault"><span class="rbb-track"></span></label></div>
                <div class="rbb-setting-row" id="bookingDefaultRow"><div><strong>Booking default</strong><p>Set this form as the default online booking form.</p></div><label class="rbb-toggle"><input type="checkbox" id="settingsBookingDefault"><span class="rbb-track"></span></label></div>
                <div class="rbb-setting-row"><div><strong>Require assessment booking approval</strong><p>Review and confirm assessment bookings before they're scheduled.</p></div><label class="rbb-toggle"><input type="checkbox" id="settingsApproval"><span class="rbb-track"></span></label></div>
                <div class="rbb-setting-row"><div><strong>Service areas</strong><p>Requires collecting the customer's address. <button type="button" class="rbb-btn" style="min-height:27px;margin-top:5px" id="editAreaBtn">Edit service area</button></p></div><label class="rbb-toggle"><input type="checkbox" id="settingsServiceArea"><span class="rbb-track"></span></label></div>

                <div class="rbb-accordion open"><button type="button" data-accordion><span>Booking Availability</span><i data-lucide="chevron-up"></i></button><div class="rbb-accordion-body"><p class="rbb-help" id="bookingAvailabilityNotice"></p><div class="rbb-field"><label>Earliest Availability</label><div style="display:grid;grid-template-columns:70px 1fr;gap:6px"><input class="rbb-input" type="number" min="0" max="365" id="earliestDays"><div class="rbb-input" style="display:flex;align-items:center">business day(s) away</div></div></div><div class="rbb-field"><label>Maximum booking window</label><div style="display:grid;grid-template-columns:70px 1fr;gap:6px"><input class="rbb-input" type="number" min="1" max="730" id="maxDays"><div class="rbb-input" style="display:flex;align-items:center">day(s) ahead</div></div></div><div class="rbb-side-copy">Booking Interval</div><div class="rbb-chip-row" id="intervalChips"></div></div></div>
                <div class="rbb-accordion"><button type="button" data-accordion><span>Efficient Scheduling</span><i data-lucide="chevron-down"></i></button><div class="rbb-accordion-body"><p class="rbb-help" id="efficientNotice"></p><label class="rbb-radio"><input type="radio" name="efficientMode" value="drive_time"><span><strong>Drive time limit</strong><br>Only accept visits within a set drive time of your other bookings that day.</span></label><div class="rbb-chip-row" id="driveChips"></div><label class="rbb-radio"><input type="radio" name="efficientMode" value="fixed_buffer"><span><strong>Fixed buffer time</strong><br>Add a buffer to avoid back-to-back bookings.</span></label><div class="rbb-chip-row" id="bufferChips"></div><label class="rbb-radio"><input type="radio" name="efficientMode" value="none"><span><strong>No time restrictions</strong></span></label></div></div>
                <div class="rbb-accordion"><button type="button" data-accordion><span>Google Business Profile</span><i data-lucide="chevron-down"></i></button><div class="rbb-accordion-body"><p class="rbb-help">Connect this form to an existing Google integration from Account Connections.</p><div id="googleWarning" class="rbb-warning rbb-hidden"></div><div class="rbb-info-card" id="googleBusinessInfo"></div><div style="margin-top:8px"><button class="rbb-btn" id="googleConnectBtn" type="button">Connect form</button></div></div></div>
                <div class="rbb-accordion"><button type="button" data-accordion><span>Google Analytics</span><i data-lucide="chevron-down"></i></button><div class="rbb-accordion-body"><div class="rbb-field"><label>Tracking code</label><input class="rbb-input" id="gaCode" placeholder="G-XXXXXXXXXX or GTM-XXXXXXX"></div><p class="rbb-help">Only use analytics after obtaining any consent required in your region. Analytics platforms can take time to show new data.</p></div></div>
                <div class="rbb-accordion"><button type="button" data-accordion><span>Share Links</span><i data-lucide="chevron-down"></i></button><div class="rbb-accordion-body"><p class="rbb-help">Copy and paste the hosted link below or embed the form on your website.</p><div class="rbb-field"><input class="rbb-input" id="publicUrl" readonly></div><button class="rbb-btn" type="button" data-copy-input="publicUrl"><i data-lucide="copy"></i>Copy link</button><div class="rbb-field" style="margin-top:10px"><textarea class="rbb-textarea" id="embedCode" readonly></textarea></div><button class="rbb-btn" type="button" data-copy-input="embedCode"><i data-lucide="copy"></i>Copy code</button></div></div>
            </div>
        </aside>
    </div>
    <div class="rbb-preview" id="previewView"><div class="rbb-preview-frame" id="previewFrame"></div></div>
</div>

<div class="rbb-modal-backdrop" id="areaModal"><div class="rbb-modal"><div class="rbb-modal-head"><h3>Add service area</h3><button type="button" class="rbb-mini" data-close-area><i data-lucide="x"></i></button></div><div class="rbb-modal-body"><div class="rbb-field"><input class="rbb-input" id="areaAddress" placeholder="Enter address or city"></div><div class="rbb-map" id="builderMap"></div><div class="rbb-help" id="builderMapNote">Set a center location and radius for online bookings.</div><button class="rbb-btn" type="button" id="resetArea">Reset Area</button><div class="rbb-range-row"><input class="rbb-range" id="areaRadius" type="range" min="1" max="250" value="25"><div class="rbb-km"><span id="areaRadiusValue">25</span><span>km</span></div></div><input type="hidden" id="areaId"><input type="hidden" id="areaLat"><input type="hidden" id="areaLng"></div><div class="rbb-modal-foot"><button class="rbb-btn" type="button" data-close-area>Cancel</button><button class="rbb-btn primary" id="saveArea" type="button">Save</button></div></div></div>

<script>window.FIELDPLX_RB_BUILDER={id:<?= (int)$formId ?>,csrf:<?= json_encode($csrfToken) ?>};</script>
<script src="assets/request-booking-builder.js?v=20260914-6"></script>
<?php if($mapsKey!==''): ?><script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($mapsKey,ENT_QUOTES,'UTF-8') ?>&libraries=places"></script><?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
