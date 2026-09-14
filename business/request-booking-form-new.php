<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'New Form · FieldPlx';
$pageDescription = 'Create a request or booking form';
$settingsActivePage = 'requests-bookings';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['request_booking_csrf'])) $_SESSION['request_booking_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string)$_SESSION['request_booking_csrf'];

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
<link rel="stylesheet" href="assets/request-booking.css">

<div class="rb-wizard-page">
    <aside><?php require __DIR__ . '/includes/settings-nav.php'; ?></aside>

    <main class="rb-wizard-main">
        <section class="rb-wizard-card" id="typeStep">
            <h1>Choose a form type</h1>
            <p>We'll set up the right fields based on the form type you choose.</p>

            <label class="rb-choice">
                <input type="radio" name="formType" value="request" checked>
                <span>Request form</span>
            </label>
            <label class="rb-choice">
                <input type="radio" name="formType" value="job_booking">
                <span>Job booking form</span>
            </label>
            <label class="rb-choice">
                <input type="radio" name="formType" value="assessment_booking">
                <span>Assessment booking form</span>
            </label>

            <div class="rb-wizard-actions">
                <a class="rb-btn" href="request-booking-settings.php">Back</a>
                <button class="rb-btn primary" type="button" id="nextBtn">Next</button>
            </div>
        </section>

        <section class="rb-wizard-card rb-wizard-card-wide rb-hidden" id="detailsStep">
            <h1>New form</h1>
            <p>Start by entering some basic information about the form and the type of information you're looking to capture.</p>

            <div class="rb-field">
                <input class="rb-input" id="formTitle" maxlength="190" placeholder="Add a form title">
            </div>

            <div class="rb-field">
                <textarea class="rb-textarea" id="formDescription" placeholder="Add a short description of your services or other details to appear above your form"></textarea>
            </div>

            <div id="requestOptions">
                <div class="rb-copy-row rb-wizard-setting-row">
                    <div>
                        <strong>Form Pages</strong>
                        <p>When you add a section, it creates a new page in your form to keep it from looking too long. You can turn this off at any time.</p>
                    </div>
                    <label class="rb-toggle">
                        <input type="checkbox" id="formPages" checked>
                        <span class="rb-toggle-track"></span>
                    </label>
                </div>
            </div>

            <div id="bookingOptions" class="rb-hidden">
                <div class="rb-copy-row rb-wizard-setting-row rb-hidden" id="assessmentApprovalRow">
                    <div>
                        <strong>Require assessment booking approval</strong>
                        <p>Review and confirm assessment bookings before they're scheduled.</p>
                    </div>
                    <label class="rb-toggle">
                        <input type="checkbox" id="requireApproval" checked>
                        <span class="rb-toggle-track"></span>
                    </label>
                </div>

                <div class="rb-copy-row rb-wizard-setting-row">
                    <div>
                        <strong>Service areas</strong>
                        <p>Define your service area for online bookings. <button type="button" class="rb-inline-link" id="editServiceArea">Edit service area.</button></p>
                    </div>
                    <label class="rb-toggle">
                        <input type="checkbox" id="serviceAreaEnabled">
                        <span class="rb-toggle-track"></span>
                    </label>
                </div>

                <div class="rb-copy-row rb-wizard-setting-row">
                    <div>
                        <strong>Efficient scheduling</strong>
                        <p>Set how far you're willing to drive between visits.</p>
                    </div>
                    <label class="rb-toggle">
                        <input type="checkbox" id="efficientEnabled" checked>
                        <span class="rb-toggle-track"></span>
                    </label>
                </div>

                <div class="rb-efficient-box" id="efficientBox">
                    <label class="rb-efficient-option">
                        <input type="radio" name="efficientMode" value="drive_time">
                        <span>
                            <strong>Drive time limit</strong>
                            <small>Only accept visits within a set drive time of your other bookings that day.</small>
                            <span class="rb-drive-config rb-hidden" id="driveConfig">
                                <span>Show appointments that are within this drive time of your other bookings:</span>
                                <span class="rb-number-unit"><input type="number" id="driveMinutes" min="5" max="240" value="30"><span>min</span></span>
                            </span>
                        </span>
                    </label>

                    <label class="rb-efficient-option">
                        <input type="radio" name="efficientMode" value="fixed_buffer" checked>
                        <span>
                            <strong>Fixed buffer time</strong>
                            <small>Add a buffer to avoid back-to-back bookings. Customer location isn't considered.</small>
                            <span class="rb-chip-row rb-wizard-chips" id="bufferChips">
                                <button type="button" class="rb-chip active" data-buffer="30">30 minutes <i data-lucide="check"></i></button>
                                <button type="button" class="rb-chip" data-buffer="60">1 hour</button>
                            </span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="rb-wizard-actions">
                <button class="rb-btn" type="button" id="backBtn">Back</button>
                <button class="rb-btn primary" type="button" id="createBtn">Create form</button>
            </div>
        </section>
    </main>
</div>

<div class="rb-modal-backdrop" id="serviceAreaModal" aria-hidden="true">
    <div class="rb-modal wide">
        <div class="rb-modal-head">
            <h3>Add service area</h3>
            <button class="rb-close" type="button" data-close-area aria-label="Close"><i data-lucide="x"></i></button>
        </div>
        <div class="rb-modal-body">
            <div class="rb-field rb-location-field">
                <i data-lucide="map-pin"></i>
                <input class="rb-input" id="areaAddress" placeholder="Enter address or city">
            </div>

            <div class="rb-map" id="newFormMap"></div>
            <div class="rb-map-note" id="newFormMapNote">Choose an address or move the marker to set the center of your service area.</div>

            <div style="margin-top:12px">
                <button class="rb-btn" type="button" id="resetArea">Reset Area</button>
            </div>

            <div class="rb-range-row">
                <input class="rb-range" id="areaRadius" type="range" min="1" max="250" value="25">
                <div class="rb-km"><span id="areaRadiusValue">25</span><span>km</span></div>
            </div>

            <input type="hidden" id="areaLat">
            <input type="hidden" id="areaLng">
        </div>
        <div class="rb-modal-foot">
            <button class="rb-btn" type="button" data-close-area>Cancel</button>
            <button class="rb-btn primary" type="button" id="saveAreaLocal">Save</button>
        </div>
    </div>
</div>

<script>
(function(window, document){
'use strict';

var API = 'api/request-booking-form-new.php';
var csrf = <?= json_encode($csrfToken) ?>;
var map = null, marker = null, circle = null, geocoder = null;
var areaState = {address:'', latitude:'', longitude:'', radius_km:25};

function E(id){ return document.getElementById(id); }
function icons(){ if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons(); }
function toast(type,message){ if(window.fieldplxToast) window.fieldplxToast(type,message); else if(type==='error') window.alert(message); }
function formType(){ var selected=document.querySelector('input[name="formType"]:checked'); return selected ? selected.value : 'request'; }
function request(data){
    return fetch(API,{
        method:'POST',
        body:data,
        credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
    }).then(function(response){
        return response.text().then(function(raw){
            var result;
            try { result = raw ? JSON.parse(raw) : {}; }
            catch (e) { throw new Error('Requests and Bookings API returned an invalid response.'); }
            if (!response.ok || !result.success) throw new Error(result.message || 'Request failed.');
            return result;
        });
    });
}

function updateDetailOptions(){
    var type = formType();
    E('requestOptions').classList.toggle('rb-hidden', type !== 'request');
    E('bookingOptions').classList.toggle('rb-hidden', type === 'request');
    E('assessmentApprovalRow').classList.toggle('rb-hidden', type !== 'assessment_booking');
    if (type === 'job_booking') E('requireApproval').checked = false;
    if (type === 'assessment_booking' && !E('requireApproval').dataset.touched) E('requireApproval').checked = true;
    refreshEfficientUI();
}

function refreshEfficientUI(){
    var enabled = E('efficientEnabled').checked;
    E('efficientBox').classList.toggle('rb-disabled', !enabled);
    var mode = (document.querySelector('input[name="efficientMode"]:checked') || {value:'fixed_buffer'}).value;
    E('driveConfig').classList.toggle('rb-hidden', mode !== 'drive_time');
}

function openArea(){
    E('areaAddress').value = areaState.address || '';
    E('areaLat').value = areaState.latitude || '';
    E('areaLng').value = areaState.longitude || '';
    E('areaRadius').value = Number(areaState.radius_km || 25);
    E('areaRadiusValue').textContent = E('areaRadius').value;
    E('serviceAreaModal').classList.add('open');
    E('serviceAreaModal').setAttribute('aria-hidden','false');
    setTimeout(initMap,80);
    icons();
}
function closeArea(){
    E('serviceAreaModal').classList.remove('open');
    E('serviceAreaModal').setAttribute('aria-hidden','true');
}

function syncMapFromInputs(){
    if (!map || !marker || !circle) return;
    var lat = parseFloat(E('areaLat').value), lng = parseFloat(E('areaLng').value);
    if (!isNaN(lat) && !isNaN(lng)) {
        var pos = {lat:lat,lng:lng};
        marker.setPosition(pos);
        circle.setCenter(pos);
        map.setCenter(pos);
        map.setZoom(9);
    }
    circle.setRadius(Number(E('areaRadius').value || 25) * 1000);
}

function initMap(){
    if (!(window.google && google.maps)) {
        E('newFormMapNote').textContent = 'Google Maps is not configured. You can still save an address and radius. Set GOOGLE_MAPS_API_KEY to enable map search.';
        return;
    }

    if (map) { syncMapFromInputs(); return; }

    var lat = parseFloat(E('areaLat').value), lng = parseFloat(E('areaLng').value);
    if (isNaN(lat)) lat = 12.9716;
    if (isNaN(lng)) lng = 77.5946;
    var center = {lat:lat,lng:lng};

    map = new google.maps.Map(E('newFormMap'), {
        center:center,
        zoom:E('areaLat').value ? 9 : 6,
        mapTypeControl:false,
        streetViewControl:false,
        fullscreenControl:false
    });
    marker = new google.maps.Marker({map:map,position:center,draggable:true});
    circle = new google.maps.Circle({map:map,center:center,radius:Number(E('areaRadius').value||25)*1000,fillOpacity:.15,strokeOpacity:.55,strokeWeight:2});
    geocoder = new google.maps.Geocoder();

    marker.addListener('dragend', function(){
        var p = marker.getPosition();
        E('areaLat').value = p.lat();
        E('areaLng').value = p.lng();
        circle.setCenter(p);
    });

    E('areaAddress').addEventListener('change', function(){
        var address = E('areaAddress').value.trim();
        if (!address || !geocoder) return;
        geocoder.geocode({address:address}, function(results,status){
            if (status === 'OK' && results && results[0]) {
                var p = results[0].geometry.location;
                E('areaLat').value = p.lat();
                E('areaLng').value = p.lng();
                map.setCenter(p);
                map.setZoom(9);
                marker.setPosition(p);
                circle.setCenter(p);
            } else {
                toast('error','Address could not be located.');
            }
        });
    });
}

E('nextBtn').onclick = function(){
    updateDetailOptions();
    E('typeStep').classList.add('rb-hidden');
    E('detailsStep').classList.remove('rb-hidden');
    setTimeout(function(){ E('formTitle').focus(); },30);
};
E('backBtn').onclick = function(){
    E('detailsStep').classList.add('rb-hidden');
    E('typeStep').classList.remove('rb-hidden');
};

document.querySelectorAll('input[name="formType"]').forEach(function(input){ input.addEventListener('change',updateDetailOptions); });
E('requireApproval').addEventListener('change',function(){ this.dataset.touched='1'; });
E('efficientEnabled').addEventListener('change',refreshEfficientUI);
document.querySelectorAll('input[name="efficientMode"]').forEach(function(input){ input.addEventListener('change',refreshEfficientUI); });

document.querySelectorAll('[data-buffer]').forEach(function(button){
    button.addEventListener('click',function(){
        document.querySelectorAll('[data-buffer]').forEach(function(x){x.classList.remove('active');x.innerHTML=x.getAttribute('data-buffer')==='60'?'1 hour':'30 minutes';});
        button.classList.add('active');
        button.innerHTML = (button.getAttribute('data-buffer')==='60'?'1 hour':'30 minutes') + ' <i data-lucide="check"></i>';
        icons();
    });
});

E('editServiceArea').onclick = openArea;
E('serviceAreaEnabled').addEventListener('change',function(){ if(this.checked && !areaState.address && !areaState.latitude) openArea(); });
document.querySelectorAll('[data-close-area]').forEach(function(button){ button.onclick=closeArea; });
E('serviceAreaModal').onclick=function(e){ if(e.target===E('serviceAreaModal')) closeArea(); };
E('areaRadius').oninput=function(){ E('areaRadiusValue').textContent=this.value; if(circle) circle.setRadius(Number(this.value)*1000); };
E('resetArea').onclick=function(){
    E('areaAddress').value=''; E('areaLat').value=''; E('areaLng').value=''; E('areaRadius').value='25'; E('areaRadiusValue').textContent='25';
    if(map){ map.setCenter({lat:12.9716,lng:77.5946}); map.setZoom(6); }
    if(marker) marker.setPosition({lat:12.9716,lng:77.5946});
    if(circle){ circle.setCenter({lat:12.9716,lng:77.5946}); circle.setRadius(25000); }
};
E('saveAreaLocal').onclick=function(){
    areaState = {
        address:E('areaAddress').value.trim(),
        latitude:E('areaLat').value,
        longitude:E('areaLng').value,
        radius_km:Number(E('areaRadius').value||25)
    };
    E('serviceAreaEnabled').checked=true;
    closeArea();
    toast('success','Service area will be saved when the form is created.');
};

E('createBtn').onclick = function(){
    var title=E('formTitle').value.trim();
    if(!title){ toast('error','Form title is required.'); E('formTitle').focus(); return; }

    var type=formType();
    var mode=(document.querySelector('input[name="efficientMode"]:checked')||{value:'fixed_buffer'}).value;
    var activeBuffer=document.querySelector('[data-buffer].active');
    var efficient={
        enabled:(type!=='request' && E('efficientEnabled').checked)?1:0,
        mode:mode,
        fixed_buffer_minutes:activeBuffer?Number(activeBuffer.getAttribute('data-buffer')):30,
        drive_time_limit_minutes:Math.max(5,Number(E('driveMinutes').value||30))
    };

    var data=new FormData();
    data.append('csrf_token',csrf);
    data.append('form_type',type);
    data.append('name',title);
    data.append('description',E('formDescription').value);
    data.append('form_pages',type==='request' && E('formPages').checked?'1':'0');
    data.append('require_booking_approval',type==='assessment_booking' && E('requireApproval').checked?'1':'0');
    data.append('service_area_enabled',type!=='request' && E('serviceAreaEnabled').checked?'1':'0');
    data.append('efficient_scheduling_json',JSON.stringify(efficient));
    data.append('service_area_address',areaState.address||'');
    data.append('service_area_latitude',areaState.latitude||'');
    data.append('service_area_longitude',areaState.longitude||'');
    data.append('service_area_radius_km',String(areaState.radius_km||25));

    var button=this, old=button.textContent;
    button.disabled=true;
    button.textContent='Creating...';

    request(data).then(function(result){
        toast('success',result.message);
        window.location.href=result.redirect||('request-booking-form-builder.php?id='+result.id);
    }).catch(function(error){
        toast('error',error.message);
        button.disabled=false;
        button.textContent=old;
    });
};

updateDetailOptions();
icons();
})(window,document);
</script>
<?php if($mapsKey!==''): ?>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($mapsKey,ENT_QUOTES,'UTF-8') ?>&libraries=places"></script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
