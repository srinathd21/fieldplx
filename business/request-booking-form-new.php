<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'New Form · FieldPlx';
$pageDescription = 'Create a request or booking form';
$settingsActivePage = 'requests-bookings';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['request_booking_csrf'])) $_SESSION['request_booking_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string)$_SESSION['request_booking_csrf'];
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/toast.php';
?>
<link rel="stylesheet" href="assets/request-booking.css?v=20260919-1">
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
<div class="rb-wizard-page">
    <aside><?php require __DIR__ . '/includes/settings-nav.php'; ?></aside>
    <main class="rb-wizard-main">
        <section class="rb-wizard-card" id="typeStep">
            <h1>Choose a form type</h1>
            <p>We'll set up the right fields based on the form type you choose.</p>
            <label class="rb-choice"><input type="radio" name="formType" value="request" checked><span>Request form</span></label>
            <label class="rb-choice"><input type="radio" name="formType" value="job_booking"><span>Job booking form</span></label>
            <label class="rb-choice"><input type="radio" name="formType" value="assessment_booking"><span>Assessment booking form</span></label>
            <div class="rb-wizard-actions"><a class="rb-btn" href="request-booking-settings.php">Back</a><button class="rb-btn primary" type="button" id="nextBtn">Next</button></div>
        </section>
        <section class="rb-wizard-card rb-wizard-card-wide rb-hidden" id="detailsStep">
            <h1>New form</h1>
            <p>Start by entering some basic information about the form and the type of information you're looking to capture.</p>
            <div class="rb-field"><input class="rb-input" id="formTitle" maxlength="190" placeholder="Add a form title"></div>
            <div class="rb-field"><textarea class="rb-textarea" id="formDescription" placeholder="Add a short description of your services or other details to appear above your form"></textarea></div>
            <div class="rb-copy-row rb-wizard-setting-row"><div><strong>Form Pages</strong><p>When you add a section, it creates a new page in your form to keep it from looking too long. You can turn this off at any time.</p></div><label class="rb-toggle"><input type="checkbox" id="formPages" checked><span class="rb-toggle-track"></span></label></div>
            <div class="rb-wizard-actions"><button class="rb-btn" type="button" id="backBtn">Back</button><button class="rb-btn primary" type="button" id="createBtn">Create form</button></div>
        </section>
    </main>
</div>
<script>
(function(window,document){'use strict';
var API='api/request-booking-form-new.php',csrf=<?= json_encode($csrfToken) ?>;
function E(id){return document.getElementById(id)}function toast(t,m){if(window.fieldplxToast)window.fieldplxToast(t,m);else if(t==='error')alert(m)}function formType(){var x=document.querySelector('input[name="formType"]:checked');return x?x.value:'request'}
function req(d){return fetch(API,{method:'POST',body:d,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(function(r){return r.text().then(function(raw){var x;try{x=raw?JSON.parse(raw):{}}catch(e){throw new Error('New form API returned an invalid response.')}if(!r.ok||!x.success)throw new Error(x.message||'Unable to create form.');return x})})}
E('nextBtn').onclick=function(){E('typeStep').classList.add('rb-hidden');E('detailsStep').classList.remove('rb-hidden');setTimeout(function(){E('formTitle').focus()},30)};
E('backBtn').onclick=function(){E('detailsStep').classList.add('rb-hidden');E('typeStep').classList.remove('rb-hidden')};
E('createBtn').onclick=function(){var title=E('formTitle').value.trim();if(!title){toast('error','Form title is required.');E('formTitle').focus();return}var d=new FormData();d.append('csrf_token',csrf);d.append('form_type',formType());d.append('name',title);d.append('description',E('formDescription').value);d.append('form_pages',E('formPages').checked?'1':'0');var b=this,old=b.textContent;b.disabled=true;b.textContent='Creating...';req(d).then(function(r){toast('success',r.message);location.href=r.redirect||('request-booking-form-builder.php?id='+r.id)}).catch(function(e){toast('error',e.message);b.disabled=false;b.textContent=old})};
})(window,document);
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
