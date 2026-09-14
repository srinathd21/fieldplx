<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Requests and Bookings · FieldPlx';
$pageDescription = 'Manage request and booking forms, sharing, service areas and online booking availability';
$settingsActivePage = 'requests-bookings';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['request_booking_csrf'])) $_SESSION['request_booking_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string)$_SESSION['request_booking_csrf'];
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/toast.php';
?>
<link rel="stylesheet" href="assets/request-booking.css">
<div class="rb-page">
    <aside><?php require __DIR__ . '/includes/settings-nav.php'; ?></aside>
    <main class="rb-main">
        <header class="rb-head">
            <h1>Requests and bookings</h1>
            <p>Manage all your request and booking forms in one place. Customize your forms to collect the details you need, then share them on your website, social media, or Customer Hub so customers can contact or book with you anytime.</p>
        </header>

        <div id="schemaWarning" class="rb-schema">Run <strong>database/request-booking-forms-migration.sql</strong> once before using Requests and Bookings.</div>

        <section class="rb-card">
            <div class="rb-card-head">
                <h2>Forms</h2>
                <a href="request-booking-form-new.php" class="rb-btn"><i data-lucide="plus"></i><span>Add New Form</span></a>
            </div>
            <div id="formsList" class="rb-form-list"><div class="rb-empty">Loading forms...</div></div>
        </section>

        <section class="rb-card">
            <div class="rb-card-head"><h2>Checklists</h2><a class="rb-btn" href="checklists.php">Manage Checklists</a></div>
            <div class="rb-card-body"><p class="rb-help" style="margin:0">Attach custom-built checklists to on-site assessments so that nothing gets missed.</p></div>
        </section>

        <section class="rb-card">
            <div class="rb-card-head"><h2>Customization</h2></div>
            <div class="rb-card-body"><div class="rb-copy-row" style="padding-top:0"><div><strong>Personalize your forms with your brand</strong><p>Add your logo and customize colors to match your business style. Branding can be updated in <a class="rb-link" href="business-profile.php">Business Profile</a>.</p></div></div></div>
        </section>

        <section class="rb-card">
            <div class="rb-card-head"><h2>Availability</h2></div>
            <div class="rb-card-body">
                <div class="rb-copy-row"><div><strong>Business Hours</strong><p>Your booking availability is determined by your company's business hours. You can update them in <a class="rb-link" href="settings.php">Company Settings</a>.</p></div></div>
                <div class="rb-copy-row"><div><strong>Service areas</strong><p>Define your service areas for online bookings.</p></div><button class="rb-btn" type="button" id="editServiceAreaBtn">Edit</button></div>
                <div class="rb-copy-row"><div><strong>Team members</strong><p>You can change when your team members can be booked by setting their availability in <a class="rb-link" href="team.php">Manage Team</a>.</p></div></div>
            </div>
        </section>
    </main>
</div>

<div class="rb-modal-backdrop" id="shareModal" aria-hidden="true">
    <div class="rb-modal" role="dialog" aria-modal="true" aria-labelledby="shareTitle">
        <div class="rb-modal-head"><h3 id="shareTitle">Share Options</h3><button class="rb-close" type="button" data-close-modal><i data-lucide="x"></i></button></div>
        <div class="rb-modal-body">
            <p class="rb-help">Copy the link to share or add your form to any page on your website.</p>
            <div class="rb-section-label">Hosted form link</div>
            <p class="rb-help">Share the hosted FieldPlx form anywhere to get new leads.</p>
            <div class="rb-input-group"><input class="rb-input" id="shareUrl" readonly><button class="rb-btn" type="button" data-copy="shareUrl"><i data-lucide="copy"></i>Copy link</button></div>
            <div class="rb-share-sep"></div>
            <div class="rb-section-label">Embed your form</div>
            <p class="rb-help">Embed this form so customers can submit it without leaving your website. Protected payment fields are intentionally excluded from embeds.</p>
            <div class="rb-input-group"><input class="rb-input" id="embedCode" readonly><button class="rb-btn" type="button" data-copy="embedCode"><i data-lucide="copy"></i>Copy code</button></div>
            <div class="rb-share-sep"></div>
            <div class="rb-section-label">Automated Lead Tracking</div>
            <p class="rb-help">Use a source-specific link where you place your request form so FieldPlx can identify where the lead came from.</p>
            <div class="rb-socials" id="trackingLinks"></div>
        </div>
    </div>
</div>

<div class="rb-modal-backdrop" id="serviceAreaModal" aria-hidden="true">
    <div class="rb-modal wide" role="dialog" aria-modal="true" aria-labelledby="serviceAreaTitle">
        <div class="rb-modal-head"><h3 id="serviceAreaTitle">Add service area</h3><button class="rb-close" type="button" data-close-modal><i data-lucide="x"></i></button></div>
        <div class="rb-modal-body">
            <div class="rb-field"><input class="rb-input" id="areaAddress" placeholder="Enter address or city"></div>
            <div class="rb-map" id="serviceMap"></div>
            <div class="rb-map-note" id="mapNote">Enter an address or city. If Google Maps is configured, the map and radius circle update automatically.</div>
            <div style="margin-top:10px"><button class="rb-btn" type="button" id="resetAreaBtn">Reset Area</button></div>
            <div class="rb-range-row"><input class="rb-range" id="areaRadius" type="range" min="1" max="250" step="1" value="25"><div class="rb-km"><span id="areaRadiusValue">25</span><span>km</span></div></div>
            <input type="hidden" id="areaId" value="0"><input type="hidden" id="areaLat"><input type="hidden" id="areaLng">
        </div>
        <div class="rb-modal-foot"><button class="rb-btn" type="button" data-close-modal>Cancel</button><button class="rb-btn primary" type="button" id="saveAreaBtn">Save</button></div>
    </div>
</div>

<script>
(function(){
'use strict';
var API='api/request-booking-settings.php',csrf=<?= json_encode($csrfToken) ?>;
var state={forms:[],serviceArea:null,map:null,marker:null,circle:null,geocoder:null};
function E(id){return document.getElementById(id)}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]})}
function toast(t,m){if(window.fieldplxToast)window.fieldplxToast(t,m);else if(t==='error')alert(m)}
function fd(action){var x=new FormData();x.append('action',action);x.append('csrf_token',csrf);return x}
function req(data){return fetch(API,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(function(r){return r.text().then(function(raw){var d;try{d=raw?JSON.parse(raw):{}}catch(_){throw new Error('Invalid JSON response from Requests and Bookings API.')}if(!r.ok||!d.success)throw new Error(d.message||'Request failed.');return d})})}
function icons(){if(window.lucide&&window.lucide.createIcons)window.lucide.createIcons()}
function openModal(id){E(id).classList.add('open');E(id).setAttribute('aria-hidden','false');icons()}
function closeModals(){document.querySelectorAll('.rb-modal-backdrop.open').forEach(function(m){m.classList.remove('open');m.setAttribute('aria-hidden','true')})}
document.querySelectorAll('[data-close-modal]').forEach(function(b){b.onclick=closeModals});document.querySelectorAll('.rb-modal-backdrop').forEach(function(m){m.addEventListener('click',function(e){if(e.target===m)closeModals()})});document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModals()});
function closeMenus(){document.querySelectorAll('.rb-menu.open').forEach(function(x){x.classList.remove('open')})}
document.addEventListener('click',function(e){if(!e.target.closest('.rb-menu-wrap'))closeMenus()});
function formMenu(f){var buttons='<button type="button" data-act="edit" data-id="'+f.id+'"><i data-lucide="pencil"></i>Edit</button>'+
'<button type="button" data-act="preview" data-id="'+f.id+'"><i data-lucide="eye"></i>Preview</button>'+
'<button type="button" data-act="share" data-id="'+f.id+'"><i data-lucide="link"></i>Share links</button>'+
'<button type="button" data-act="tracking" data-id="'+f.id+'"><i data-lucide="crosshair"></i>Add tracking</button>';
if(!Number(f.is_request_default))buttons+='<button type="button" data-act="request-default" data-id="'+f.id+'"><i data-lucide="clipboard-check"></i>Set as request default</button>';
if(f.form_type!=='request'&&!Number(f.is_booking_default))buttons+='<button type="button" data-act="booking-default" data-id="'+f.id+'"><i data-lucide="calendar-check"></i>Set as booking default</button>';
buttons+='<button type="button" class="danger" data-act="delete" data-id="'+f.id+'"><i data-lucide="trash-2"></i>Delete</button>';return buttons}
function renderForms(){var box=E('formsList');if(!state.forms.length){box.innerHTML='<div class="rb-empty">No forms yet. Create your first request or booking form.</div>';return}
box.innerHTML=state.forms.map(function(f){var labels=[];if(Number(f.is_booking_default))labels.push('Booking default');if(Number(f.is_request_default))labels.push('Request default');var badge=Number(f.used_count||0)>0?'<span class="rb-used">Used in '+Number(f.used_count)+' place'+(Number(f.used_count)===1?'':'s')+'</span>':'';return '<div class="rb-form-row"><div class="rb-form-name"><span>'+esc(f.name)+'</span>'+badge+'</div><div class="rb-form-state"><span>'+esc(labels.join(' · '))+'</span><label class="rb-toggle" title="Enable or disable form"><input type="checkbox" data-toggle="'+Number(f.id)+'" '+(f.status==='active'?'checked':'')+'><span class="rb-toggle-track"></span></label></div><div class="rb-menu-wrap"><button type="button" class="rb-icon-btn" data-menu aria-label="Form actions"><i data-lucide="more-horizontal"></i></button><div class="rb-menu">'+formMenu(f)+'</div></div></div>'}).join('');wire();icons()}
function wire(){document.querySelectorAll('[data-menu]').forEach(function(b){b.onclick=function(e){e.stopPropagation();var m=b.nextElementSibling,was=m.classList.contains('open');closeMenus();if(!was)m.classList.add('open')}});document.querySelectorAll('[data-toggle]').forEach(function(t){t.onchange=function(){var x=fd('toggle_status');x.append('id',t.getAttribute('data-toggle'));x.append('enabled',t.checked?'1':'0');req(x).then(function(d){toast('success',d.message);load()}).catch(function(e){t.checked=!t.checked;toast('error',e.message)})}});document.querySelectorAll('[data-act]').forEach(function(b){b.onclick=function(){doAction(b.getAttribute('data-act'),Number(b.getAttribute('data-id')))}})}
function byId(id){return state.forms.find(function(x){return Number(x.id)===Number(id)})}
function doAction(act,id){closeMenus();var f=byId(id);if(!f)return;if(act==='edit'){location.href='request-booking-form-builder.php?id='+id;return}if(act==='preview'){window.open(f.public_url,'_blank','noopener');return}if(act==='share'||act==='tracking'){openShare(f);return}if(act==='delete'){if(!confirm('Delete this form? Forms with submissions are safely deactivated instead of permanently deleted.'))return;var d=fd('delete');d.append('id',id);req(d).then(function(r){toast('success',r.message);load()}).catch(function(e){toast('error',e.message)});return}if(act==='request-default'||act==='booking-default'){var x=fd('set_default');x.append('id',id);x.append('kind',act==='request-default'?'request':'booking');req(x).then(function(r){toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})}}
function openShare(f){E('shareUrl').value=f.public_url||'';E('embedCode').value=f.embed_code||'';E('trackingLinks').innerHTML=(f.tracking_links||[]).map(function(x){return '<button class="rb-btn" type="button" data-copy-value="'+esc(x.url)+'"><i data-lucide="share-2"></i>'+esc(x.label)+'</button>'}).join('');openModal('shareModal');E('trackingLinks').querySelectorAll('[data-copy-value]').forEach(function(b){b.onclick=function(){copyText(b.getAttribute('data-copy-value'),'Tracking link copied.')}})}
function copyText(text,msg){if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(text).then(function(){toast('success',msg||'Copied.')})}else{var ta=document.createElement('textarea');ta.value=text;document.body.appendChild(ta);ta.select();document.execCommand('copy');ta.remove();toast('success',msg||'Copied.')}}
document.querySelectorAll('[data-copy]').forEach(function(b){b.onclick=function(){copyText(E(b.getAttribute('data-copy')).value,'Copied to clipboard.')}});
function fillArea(a){state.serviceArea=a||{};E('areaId').value=a&&a.id?a.id:0;E('areaAddress').value=a&&a.address_text?a.address_text:'';E('areaLat').value=a&&a.latitude!=null?a.latitude:'';E('areaLng').value=a&&a.longitude!=null?a.longitude:'';E('areaRadius').value=a&&a.radius_km?Math.round(Number(a.radius_km)):25;E('areaRadiusValue').textContent=E('areaRadius').value;updateMap()}
E('editServiceAreaBtn').onclick=function(){fillArea(state.serviceArea);openModal('serviceAreaModal');setTimeout(initMap,80)};E('areaRadius').oninput=function(){E('areaRadiusValue').textContent=this.value;updateMap()};E('resetAreaBtn').onclick=function(){E('areaAddress').value='';E('areaLat').value='';E('areaLng').value='';E('areaRadius').value='25';E('areaRadiusValue').textContent='25';updateMap()};
E('saveAreaBtn').onclick=function(){var x=fd('save_service_area');x.append('id',E('areaId').value||'0');x.append('address_text',E('areaAddress').value);x.append('latitude',E('areaLat').value);x.append('longitude',E('areaLng').value);x.append('radius_km',E('areaRadius').value);var btn=this,old=btn.textContent;btn.disabled=true;btn.textContent='Saving...';req(x).then(function(d){toast('success',d.message);closeModals();load()}).catch(function(e){toast('error',e.message)}).then(function(){btn.disabled=false;btn.textContent=old})};
function initMap(){if(!(window.google&&google.maps)){E('mapNote').textContent='Google Maps is not configured. You can still save an address and radius; set GOOGLE_MAPS_API_KEY to enable the interactive map.';return}if(state.map)return;var lat=Number(E('areaLat').value)||20.5937,lng=Number(E('areaLng').value)||78.9629;state.map=new google.maps.Map(E('serviceMap'),{center:{lat:lat,lng:lng},zoom:E('areaLat').value?10:4,mapTypeControl:false,streetViewControl:false,fullscreenControl:false});state.geocoder=new google.maps.Geocoder();state.marker=new google.maps.Marker({map:state.map,position:{lat:lat,lng:lng},draggable:true});state.circle=new google.maps.Circle({map:state.map,center:{lat:lat,lng:lng},radius:Number(E('areaRadius').value)*1000,fillOpacity:.15,strokeOpacity:.55,strokeWeight:2});state.marker.addListener('dragend',function(){var p=state.marker.getPosition();E('areaLat').value=p.lat();E('areaLng').value=p.lng();state.circle.setCenter(p)});E('areaAddress').addEventListener('change',geocodeAddress)}
function geocodeAddress(){if(!state.geocoder||!E('areaAddress').value)return;state.geocoder.geocode({address:E('areaAddress').value},function(res,status){if(status==='OK'&&res[0]){var p=res[0].geometry.location;E('areaLat').value=p.lat();E('areaLng').value=p.lng();state.map.setCenter(p);state.map.setZoom(10);state.marker.setPosition(p);state.circle.setCenter(p)}else toast('error','Address could not be located on the map.')})}
function updateMap(){if(state.circle)state.circle.setRadius(Number(E('areaRadius').value)*1000);if(state.map&&E('areaLat').value&&E('areaLng').value){var p={lat:Number(E('areaLat').value),lng:Number(E('areaLng').value)};state.map.setCenter(p);state.marker.setPosition(p);state.circle.setCenter(p)}}
function load(){var x=fd('bootstrap');req(x).then(function(d){state.forms=d.forms||[];state.serviceArea=d.service_area||null;E('schemaWarning').style.display='none';renderForms()}).catch(function(e){if(String(e.message).indexOf('migration')>=0)E('schemaWarning').style.display='block';toast('error',e.message)})}
load();icons();
})();
</script>
<?php
$mapsKey = '';
if (defined('GOOGLE_MAPS_API_KEY') && trim((string)GOOGLE_MAPS_API_KEY) !== '') {
    $mapsKey = trim((string)GOOGLE_MAPS_API_KEY);
} else {
    $envMapsKey = getenv('GOOGLE_MAPS_API_KEY');
    if ($envMapsKey !== false) $mapsKey = trim((string)$envMapsKey);
}
if ($mapsKey !== ''):
?>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($mapsKey,ENT_QUOTES,'UTF-8') ?>&libraries=places"></script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
