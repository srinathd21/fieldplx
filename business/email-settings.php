<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email-settings-config.php';

$pageTitle = 'Emails · FieldPlx';
$pageDescription = 'Customize emails sent to your customers';
$settingsActivePage = 'emails';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['email_settings_csrf'])) {
    $_SESSION['email_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['email_settings_csrf'];

require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/toast.php';
?>
<style>
.em-page{display:grid;grid-template-columns:250px minmax(0,1fr);gap:28px;max-width:1320px;margin:0 auto;padding:8px 0 44px;align-items:start}.em-main{min-width:0}.em-head{margin:0 0 16px}.em-head h1{margin:0;color:var(--fieldplx-text,#0b1933);font-size:31px;line-height:1.15}.em-head p{margin:9px 0 0;color:var(--fieldplx-muted,#6f7b90);font-size:14px}.em-schema{display:none;margin:0 0 16px;padding:11px 13px;border:1px solid #efd393;border-radius:8px;background:#fff8e7;color:#7b5b08;font-size:11px;line-height:1.45}.em-info{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:0 0 16px;padding:12px 14px;border:1px solid #cfe8f8;border-radius:9px;background:#eaf6ff;color:#376f95;font-size:12px}.em-info-copy{display:flex;align-items:center;gap:9px;min-width:0}.em-info-copy svg{width:18px;height:18px;flex:0 0 auto}.em-info-actions{display:flex;align-items:center;gap:6px;flex:0 0 auto}.em-card{margin-bottom:22px;background:#fff;border:1px solid #dce4eb;border-radius:10px;overflow:visible}.em-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:16px 16px 10px}.em-card-head h2{margin:0;color:#082b3a;font-size:23px}.em-card-head p{margin:5px 0 0;color:#536b77;font-size:12px;line-height:1.45}.em-card-body{padding:0 16px 8px}.em-row{position:relative;min-height:67px;padding:13px 104px 13px 0;border-top:1px solid #edf1f4}.em-row:first-child{border-top:0}.em-row h3{margin:0 0 7px;color:#173644;font-size:13px;font-weight:700}.em-row p{margin:0;color:#607682;font-size:12px;line-height:1.45}.em-row-actions{position:absolute;right:0;top:50%;display:flex;align-items:center;gap:10px;transform:translateY(-50%)}.em-summary{margin:9px 0 0;padding-left:17px;color:#49616e;font-size:12px;line-height:1.5}.em-summary li{margin:3px 0}.em-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:34px;padding:0 12px;border:1px solid #d8e0e6;border-radius:8px;background:#fff;color:#2f7f24;font:inherit;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none}.em-btn:hover{border-color:#74b824}.em-btn.primary{background:#2f8c25;border-color:#2f8c25;color:#fff}.em-btn.primary:hover{background:#287a20}.em-btn.danger{color:#d94242}.em-btn:disabled{opacity:.55;cursor:not-allowed}.em-btn svg{width:15px;height:15px}.em-icon-btn{width:34px;height:34px;display:grid;place-items:center;border:0;border-radius:7px;background:transparent;color:#173644;cursor:pointer}.em-icon-btn:hover{background:#f3f5f6}.em-icon-btn svg{width:17px;height:17px}.em-edit{border:0;background:transparent;padding:4px;color:#2f7f24;font:inherit;font-size:12px;font-weight:700;text-decoration:underline;cursor:pointer}.em-edit:hover{color:#24691c}.em-toggle{position:relative;width:42px;height:23px;display:inline-block;flex:0 0 auto}.em-toggle input{width:0;height:0;opacity:0}.em-toggle span{position:absolute;inset:0;border-radius:999px;background:#d6e0e5;cursor:pointer;transition:.18s}.em-toggle span:after{content:"";position:absolute;left:3px;top:3px;width:17px;height:17px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.16);transition:.18s}.em-toggle input:checked+span{background:#2f8c25}.em-toggle input:checked+span:after{transform:translateX(19px)}.em-loading-card{display:flex;align-items:center;gap:10px;min-height:100px;padding:20px 16px;color:#607682;font-size:12px}.em-loading-card svg{width:18px;height:18px;animation:emspin .8s linear infinite}
.em-modal-backdrop{position:fixed;inset:0;z-index:12000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,17,49,.38);overflow:auto}.em-modal-backdrop.open{display:flex}.em-modal{width:min(820px,100%);max-height:92vh;margin:auto;background:#fff;border-radius:11px;box-shadow:0 24px 70px rgba(0,17,49,.24);overflow:visible}.em-modal.narrow{width:min(590px,100%)}.em-modal-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:19px 24px 15px}.em-modal-head h3{margin:0;color:#0b3443;font-size:22px}.em-close{width:34px;height:34px;display:grid;place-items:center;border:0;background:transparent;color:#234454;cursor:pointer;border-radius:7px}.em-close:hover{background:#f3f5f6}.em-close svg{width:20px;height:20px}.em-modal-body{padding:0 24px 8px;max-height:calc(92vh - 132px);overflow:auto}.em-modal-foot{display:flex;justify-content:flex-end;gap:9px;padding:14px 24px 20px}.em-tab{display:inline-flex;align-items:center;margin:0 0 12px;padding:0 6px 8px;border-bottom:3px solid #2f8c25;color:#173644;font-size:13px;font-weight:700}.em-editor{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:28px;padding-top:12px;border-top:1px solid #edf1f4}.em-field{position:relative;margin-bottom:12px}.em-input,.em-select,.em-textarea,.em-number,.em-time{width:100%;border:1px solid #d8e1e7;border-radius:8px;background:#fff;color:#173644;font:inherit;font-size:13px;outline:none}.em-input,.em-select{height:51px;padding:0 16px}.em-textarea{min-height:230px;padding:22px 16px 13px;resize:vertical}.em-number,.em-time{height:42px;padding:0 12px}.em-field.float label{position:absolute;left:16px;top:7px;z-index:2;color:#6d808a;font-size:10px;pointer-events:none}.em-field.float .em-input{padding-top:14px}.em-input:focus,.em-select:focus,.em-textarea:focus,.em-number:focus,.em-time:focus{border-color:#74b824;box-shadow:0 0 0 2px rgba(116,184,36,.12)}.em-preview{padding:2px 2px 8px;color:#294958;font-size:12px;line-height:1.5;white-space:pre-wrap}.em-preview-subject{margin-bottom:12px;padding:10px 0 12px;border-bottom:1px solid #e1e7ea;color:#173644;font-weight:600;white-space:normal}.em-tools{position:relative;display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:8px}.em-variable-wrap{position:relative}.em-variable-menu{position:absolute;right:0;bottom:40px;z-index:40;display:none;width:205px;max-height:440px;overflow:auto;padding:7px;background:#fff;border:1px solid #dce4eb;border-radius:8px;box-shadow:0 12px 28px rgba(0,17,49,.15)}.em-variable-menu.open{display:block}.em-variable-group{padding:7px 7px 9px;border-bottom:1px solid #edf1f4}.em-variable-group:last-child{border-bottom:0}.em-variable-title{margin-bottom:5px;color:#6d808a;font-size:10px}.em-variable-item{width:100%;padding:7px 2px;border:0;background:#fff;color:#173644;text-align:left;font:inherit;font-size:12px;font-weight:600;cursor:pointer}.em-variable-item:hover{color:#2f7f24}.em-reply-intro{margin:0 0 15px;color:#536b77;font-size:12px;line-height:1.5}.em-routing{margin-bottom:14px}.em-routing h4{margin:0 0 3px;color:#173644;font-size:13px}.em-routing p{margin:0 0 7px;color:#607682;font-size:11px}.em-routing .em-select{height:49px}.em-reminder-heading{margin:0 0 10px;color:#173644;font-size:14px}.em-schedules{margin-bottom:14px}.em-schedule{display:grid;grid-template-columns:auto 66px 150px minmax(130px,1fr) 120px 72px;gap:8px;align-items:center;padding:9px 0;border-bottom:1px solid #edf1f4;color:#345462;font-size:12px}.em-schedule .em-select{height:42px;padding:0 11px}.em-reminder-template-title{margin:15px 0 9px;color:#173644;font-size:14px}.em-spinner{width:13px;height:13px;border:2px solid rgba(255,255,255,.55);border-top-color:#fff;border-radius:50%;animation:emspin .7s linear infinite}@keyframes emspin{to{transform:rotate(360deg)}}
@media(max-width:980px){.em-page{grid-template-columns:1fr}.em-page>aside{display:none}.em-main{padding:0 4px}}@media(max-width:760px){.em-head h1{font-size:27px}.em-info{align-items:flex-start;flex-direction:column}.em-info-actions{width:100%;justify-content:flex-end}.em-card-head h2{font-size:19px}.em-row{padding-right:88px}.em-editor{grid-template-columns:1fr}.em-schedule{grid-template-columns:1fr 70px minmax(130px,1fr)}.em-schedule .em-schedule-copy{grid-column:1/-1}.em-schedule .em-time,.em-schedule .em-btn{grid-column:auto}.em-modal-body{padding-left:16px;padding-right:16px}.em-modal-head{padding-left:16px;padding-right:16px}.em-modal-foot{padding-left:16px;padding-right:16px}}
</style>

<div class="em-page">
    <aside><?php require __DIR__ . '/includes/settings-nav.php'; ?></aside>
    <main class="em-main">
        <div class="em-head">
            <h1>Emails</h1>
            <p>Customize emails sent to your customers.</p>
        </div>

        <div id="schemaWarning" class="em-schema">Run <strong>database/email-settings-migration.sql</strong> once before saving Email Settings.</div>

        <div class="em-info" id="automationNotice">
            <div class="em-info-copy"><i data-lucide="info"></i><span>Automated follow-ups are managed in Automations. Email templates and reminders continue to be managed here.</span></div>
            <div class="em-info-actions"><a class="em-btn" href="automations.php"><i data-lucide="workflow"></i>Automations Settings</a><button class="em-icon-btn" type="button" id="dismissNotice" aria-label="Dismiss"><i data-lucide="x"></i></button></div>
        </div>

        <section class="em-card">
            <div class="em-card-head"><div><h2>Email replies and notifications</h2><p>Direct customer replies and workflow notifications to the original sender, or assign a team member to be responsible.</p></div></div>
            <div class="em-card-body">
                <div class="em-row">
                    <h3>Reply routing</h3>
                    <p id="routingSummary">All email replies and notifications are sent to the sender of the email.</p>
                    <div class="em-row-actions"><button class="em-edit" type="button" id="editRouting">Edit</button></div>
                </div>
            </div>
        </section>

        <div id="emailGroups"><div class="em-card"><div class="em-loading-card"><i data-lucide="loader-circle"></i>Loading Email Settings...</div></div></div>
    </main>
</div>

<div class="em-modal-backdrop" id="routingModal" aria-hidden="true">
    <div class="em-modal narrow" role="dialog" aria-modal="true" aria-labelledby="routingTitle">
        <form id="routingForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_reply_routing">
            <div class="em-modal-head"><h3 id="routingTitle">Email replies and notifications</h3><button class="em-close" type="button" data-close="routingModal" aria-label="Close"><i data-lucide="x"></i></button></div>
            <div class="em-modal-body">
                <p class="em-reply-intro">Direct all customer replies and notifications for a workflow to the original sender, or assign a specific team member.</p>
                <div id="routingFields"></div>
            </div>
            <div class="em-modal-foot"><button class="em-btn" type="button" data-close="routingModal">Cancel</button><button class="em-btn primary" type="submit" id="routingSave">Save</button></div>
        </form>
    </div>
</div>

<div class="em-modal-backdrop" id="templateModal" aria-hidden="true">
    <div class="em-modal" role="dialog" aria-modal="true" aria-labelledby="templateTitle">
        <form id="templateForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_template">
            <input type="hidden" name="template_key" id="templateKey">
            <div class="em-modal-head"><h3 id="templateTitle">Edit Email Template</h3><button class="em-close" type="button" data-close="templateModal" aria-label="Close"><i data-lucide="x"></i></button></div>
            <div class="em-modal-body">
                <span class="em-tab">Email</span>
                <div class="em-editor">
                    <div>
                        <div class="em-field float"><label>Subject</label><input class="em-input" id="templateSubject" name="subject" maxlength="255" required></div>
                        <div class="em-field float"><label>Message</label><textarea class="em-textarea" id="templateBody" name="body" required></textarea></div>
                        <div class="em-tools">
                            <button class="em-btn danger" type="button" id="resetTemplate"><i data-lucide="rotate-ccw"></i>Reset</button>
                            <div class="em-variable-wrap"><button class="em-btn" type="button" id="insertVariable"><i data-lucide="braces"></i>Insert Variable</button><div class="em-variable-menu" id="variableMenu"></div></div>
                        </div>
                    </div>
                    <div class="em-preview"><div class="em-preview-subject" id="previewSubject"></div><div id="previewBody"></div></div>
                </div>
            </div>
            <div class="em-modal-foot"><button class="em-btn" type="button" data-close="templateModal">Cancel</button><button class="em-btn primary" type="submit" id="templateSave">Save</button></div>
        </form>
    </div>
</div>

<div class="em-modal-backdrop" id="reminderModal" aria-hidden="true">
    <div class="em-modal" role="dialog" aria-modal="true" aria-labelledby="reminderTitle">
        <form id="reminderForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_reminder">
            <input type="hidden" name="template_key" id="reminderKey">
            <input type="hidden" name="enabled" id="reminderEnabled" value="1">
            <input type="hidden" name="schedules_json" id="schedulesJson">
            <div class="em-modal-head"><h3 id="reminderTitle">Edit reminder</h3><button class="em-close" type="button" data-close="reminderModal" aria-label="Close"><i data-lucide="x"></i></button></div>
            <div class="em-modal-body">
                <h4 class="em-reminder-heading">Schedules</h4>
                <div class="em-schedules" id="scheduleRows"></div>
                <button class="em-btn" type="button" id="addReminder"><i data-lucide="plus"></i>Add reminder</button>
                <h4 class="em-reminder-template-title">Templates</h4>
                <span class="em-tab" id="reminderTab">Email</span>
                <div class="em-editor">
                    <div>
                        <div class="em-field float"><label>Subject</label><input class="em-input" id="reminderSubject" name="subject" maxlength="255" required></div>
                        <div class="em-field float"><label>Message</label><textarea class="em-textarea" id="reminderBody" name="body" required></textarea></div>
                        <div class="em-tools">
                            <button class="em-btn danger" type="button" id="resetReminder"><i data-lucide="rotate-ccw"></i>Reset</button>
                            <div class="em-variable-wrap"><button class="em-btn" type="button" id="insertReminderVariable"><i data-lucide="braces"></i>Insert Variable</button><div class="em-variable-menu" id="reminderVariableMenu"></div></div>
                        </div>
                    </div>
                    <div class="em-preview"><div class="em-preview-subject" id="reminderPreviewSubject"></div><div id="reminderPreviewBody"></div></div>
                </div>
            </div>
            <div class="em-modal-foot"><button class="em-btn" type="button" data-close="reminderModal">Cancel</button><button class="em-btn primary" type="submit" id="reminderSave">Save</button></div>
        </form>
    </div>
</div>

<script>
(function(){
'use strict';
var API='api/email-settings.php',csrf=<?= json_encode($csrfToken) ?>;
var order=['Requests','Quotes','Jobs','Invoices','General'];
var state={catalog:{},tenant:{display_name:'Your Company',email:'',phone:''},teamUsers:[],routing:{},schemaReady:false,currentKey:'',activeField:null,reminderSchedules:[]};
function E(id){return document.getElementById(id)}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]})}
function toast(t,m){if(window.fieldplxToast)window.fieldplxToast(t,m);else if(t==='error')window.alert(m)}
function refreshIcons(){if(window.lucide&&window.lucide.createIcons)window.lucide.createIcons()}
function fd(action){var x=new FormData();x.append('csrf_token',csrf);x.append('action',action);return x}
function req(data){return fetch(API,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(function(r){return r.text().then(function(raw){var d;try{d=raw?JSON.parse(raw):{}}catch(_){throw new Error('Email Settings returned an invalid server response.')}if(!r.ok||!d.success)throw new Error(d.message||'Request failed.');return d})})}
function openModal(id){var el=E(id);el.classList.add('open');el.setAttribute('aria-hidden','false');document.body.style.overflow='hidden'}
function closeModal(id){var el=E(id);el.classList.remove('open');el.setAttribute('aria-hidden','true');document.querySelectorAll('.em-variable-menu.open').forEach(function(m){m.classList.remove('open')});if(!document.querySelector('.em-modal-backdrop.open'))document.body.style.overflow=''}
function setSaving(btn,on){if(!btn)return;if(on){btn.disabled=true;btn.dataset.old=btn.innerHTML;btn.innerHTML='<span class="em-spinner"></span>Saving...'}else{btn.disabled=false;if(btn.dataset.old){btn.innerHTML=btn.dataset.old;delete btn.dataset.old}refreshIcons()}}
function sampleValues(){return {
'{{CURRENT_DATE}}':'Sep 14, 2026','{{ACCOUNT_BALANCE}}':'₹12,500.00','{{CLIENT_COMPANY_NAME}}':'Sample Customer Company','{{CLIENT_NAME}}':'Mrs. Natasha Wheeler','{{CLIENT_FIRST_NAME}}':'Natasha','{{CLIENT_LAST_NAME}}':'Wheeler','{{CLIENT_TITLE}}':'Mrs.','{{COMPANY_NAME}}':state.tenant.display_name||'FieldPlx Company','{{DEFAULT_EMAIL}}':state.tenant.email||'hello@example.com','{{DEFAULT_PHONE}}':state.tenant.phone||'+91 98765 43210','{{REQUEST_ADDRESS}}':'123 Service Road, Chennai','{{REQUEST_NUMBER}}':'REQ-000123','{{REQUEST_PREFERRED_DATE}}':'Sep 14, 2026','{{REQUEST_SERVICE}}':'General service','{{ASSIGNED_USERS}}':'Alex Johnson','{{VISIT_CONFIRMATION_LINK}}':'[View Appointment]','{{VISIT_DETAILS}}':'Date: Sep 14, 2026\nWhere: 123 Service Road, Chennai\nTime: 08:00','{{ASSESSMENT_SUMMARY}}':'On-site assessment','{{SERVICE_ADDRESS}}':'123 Service Road, Chennai','{{APPOINTMENT_TIME}}':'08:00','{{APPOINTMENT_DATE}}':'Sep 14, 2026','{{ARRIVAL_WINDOW}}':'08:00 AM–09:00 AM','{{VISIT_SUMMARY}}':'Scheduled service visit','{{VISIT_RESCHEDULE_DETAILS}}':'New date: Sep 14, 2026 at 08:00','{{WORK_DETAILS}}':'Date: Sep 14, 2026\nWhere: 123 Service Road, Chennai\nEstimated Arrival Time: 8:00 AM–9:00 AM','{{JOB_TITLE}}':'Seasonal service','{{JOB_NUMBER}}':'JOB-000123','{{QUOTE_DEPOSIT_AMOUNT}}':'₹4,000.00','{{QUOTE_DISCOUNT_AMOUNT}}':'₹500.00','{{QUOTE_ADDRESS}}':'123 Service Road, Chennai','{{QUOTE_NUMBER}}':'QT-0002345','{{QUOTE_SENT_DATE}}':'Sep 14, 2026','{{QUOTE_CONSUMER_FINANCING}}':'','{{QUOTE_TOTAL}}':'₹40,000.00','{{QUOTE_LABEL}}':'Quote','{{INVOICE_NUMBER}}':'INV-000047','{{INVOICE_SUBJECT}}':'Sample Invoice','{{INVOICE_DATE}}':'Sep 14, 2026','{{INVOICE_DUE_DATE}}':'Sep 28, 2026','{{INVOICE_TOTAL}}':'₹28,111.80','{{INVOICE_BALANCE}}':'₹28,111.80','{{PAYMENT_AMOUNT}}':'₹2,500.00','{{PAYMENT_NUMBER}}':'PAY-000006','{{JOB_FORM_NAME}}':'Checklist','{{JOB_REVIEW_LINK}}':'[Leave Feedback]','{{PAYMENT_METHOD_LINK}}':'[Add Payment Method]'} }
function renderText(text){var value=String(text||''),samples=sampleValues();Object.keys(samples).forEach(function(token){value=value.split(token).join(samples[token])});return value}
function formatTime(v){if(!v)return '';var p=String(v).split(':'),h=Number(p[0]),m=p[1]||'00',amp=h>=12?'PM':'AM',hh=h%12||12;return (hh<10?'0':'')+hh+':'+m+' '+amp}
function scheduleSentence(s){var n=Number(s.amount||0),type=s.offset_type||'hour_before';if(type==='hour_before')return 'Send a reminder <strong>'+n+' hour'+(n===1?'':'s')+' before</strong> the appointment by email';var t=s.time_of_day?formatTime(s.time_of_day):'';if(type==='same_day_as')return 'Send a reminder <strong>the same day as</strong> the appointment by email'+(t?' at '+esc(t):'');return 'Send a reminder <strong>'+n+' day'+(n===1?'':'s')+' before</strong> the appointment by email'+(t?' at '+esc(t):'')}
function groupRows(group){var html='';Object.keys(state.catalog).forEach(function(key){var item=state.catalog[key];if(item.group!==group)return;var summary='';if(item.type==='reminder')summary='<ul class="em-summary">'+(item.schedules||[]).map(function(s){return '<li>'+scheduleSentence(s)+'</li>'}).join('')+'</ul>';else if(key==='job_follow_up')summary='<ul class="em-summary"><li>Send a follow-up after closing a job</li></ul>';var toggle=item.toggle?'<label class="em-toggle" title="Enable '+esc(item.title)+'"><input type="checkbox" data-toggle="'+esc(key)+'" '+(item.enabled?'checked':'')+' '+(state.schemaReady?'':'disabled')+'><span></span></label>':'';html+='<div class="em-row"><h3>'+esc(item.title)+'</h3><p>'+esc(item.description)+'</p>'+summary+'<div class="em-row-actions">'+toggle+'<button class="em-edit" type="button" data-edit="'+esc(key)+'">Edit</button></div></div>'});return html}
function renderGroups(){E('emailGroups').innerHTML=order.map(function(group){return '<section class="em-card"><div class="em-card-head"><div><h2>'+esc(group)+'</h2></div></div><div class="em-card-body">'+groupRows(group)+'</div></section>'}).join('');wireRows();refreshIcons()}
function routingLabel(workflow){var r=state.routing[workflow]||{route_type:'sender',user_id:null};if(r.route_type!=='specific_user'||!r.user_id)return 'Sender of email';for(var i=0;i<state.teamUsers.length;i++){if(Number(state.teamUsers[i].id)===Number(r.user_id)){var u=state.teamUsers[i],name=((u.first_name||'')+' '+(u.last_name||'')).trim()||u.email;return name}}return 'Sender of email'}
function renderRoutingSummary(){var workflows=['requests','quotes','jobs','invoices'],assigned=workflows.filter(function(k){return (state.routing[k]||{}).route_type==='specific_user'});E('routingSummary').innerHTML=assigned.length?'Custom routing is configured for <strong>'+assigned.map(function(k){return k.charAt(0).toUpperCase()+k.slice(1)}).join(', ')+'</strong>.':'All email replies and notifications are sent to <strong>the sender of the email</strong>.'}
function routingFields(){var meta={requests:['Requests','Includes reminders'],quotes:['Quotes','Includes reminders, change requests, and approvals'],jobs:['Jobs','Includes reminders, booking confirmations, checklists, and job follow-ups'],invoices:['Invoices','Includes reminders and receipts']},html='';Object.keys(meta).forEach(function(key){var opts='<option value="sender">Sender of email</option>';state.teamUsers.forEach(function(u){var name=((u.first_name||'')+' '+(u.last_name||'')).trim()||u.email;opts+='<option value="user:'+Number(u.id)+'">'+esc(name+' ('+(u.email||'No email')+')')+'</option>'});html+='<div class="em-routing"><h4>'+esc(meta[key][0])+'</h4><p>'+esc(meta[key][1])+'</p><select class="em-select" name="route_'+key+'" data-route="'+key+'">'+opts+'</select></div>'});E('routingFields').innerHTML=html;Object.keys(meta).forEach(function(key){var el=document.querySelector('[data-route="'+key+'"]'),r=state.routing[key]||{};el.value=r.route_type==='specific_user'&&r.user_id?'user:'+Number(r.user_id):'sender'})}
function variableMenu(el,item){var html='';Object.keys(item.variables||{}).forEach(function(group){html+='<div class="em-variable-group"><div class="em-variable-title">'+esc(group)+'</div>';item.variables[group].forEach(function(v){html+='<button class="em-variable-item" type="button" data-token="'+esc(v.token)+'">'+esc(v.label)+'</button>'});html+='</div>'});el.innerHTML=html}
function insertAtCursor(field,text){if(!field)return;var s=typeof field.selectionStart==='number'?field.selectionStart:field.value.length,e=typeof field.selectionEnd==='number'?field.selectionEnd:s;field.value=field.value.slice(0,s)+text+field.value.slice(e);field.focus();var p=s+text.length;if(field.setSelectionRange)field.setSelectionRange(p,p);field.dispatchEvent(new Event('input',{bubbles:true}))}
function updateTemplatePreview(){E('previewSubject').textContent=renderText(E('templateSubject').value);E('previewBody').textContent=renderText(E('templateBody').value)}
function updateReminderPreview(){E('reminderPreviewSubject').textContent=renderText(E('reminderSubject').value);E('reminderPreviewBody').textContent=renderText(E('reminderBody').value)}
function openTemplate(key){var item=state.catalog[key];if(!item)return;state.currentKey=key;E('templateKey').value=key;E('templateTitle').textContent='Edit '+item.title+' Template';E('templateSubject').value=item.subject||'';E('templateBody').value=item.body||'';state.activeField=E('templateBody');variableMenu(E('variableMenu'),item);updateTemplatePreview();openModal('templateModal')}
function renderSchedules(){E('scheduleRows').innerHTML=state.reminderSchedules.map(function(s,i){var show=s.offset_type!=='hour_before';return '<div class="em-schedule" data-index="'+i+'"><span>Send a reminder</span><input class="em-number" type="number" min="0" max="365" value="'+Number(s.amount||0)+'" data-amount="'+i+'"><select class="em-select" data-offset="'+i+'"><option value="hour_before"'+(s.offset_type==='hour_before'?' selected':'')+'>hour before</option><option value="day_before"'+(s.offset_type==='day_before'?' selected':'')+'>day before</option><option value="same_day_as"'+(s.offset_type==='same_day_as'?' selected':'')+'>the same day as</option></select><span class="em-schedule-copy">the appointment by email'+(show?' at':'')+'</span><input class="em-time" type="time" value="'+esc((s.time_of_day||'15:30').slice(0,5))+'" data-time="'+i+'" style="'+(show?'':'visibility:hidden')+'"><button class="em-btn danger" type="button" data-delete-schedule="'+i+'"><i data-lucide="trash-2"></i>Delete</button></div>'}).join('');refreshIcons()}
function openReminder(key){var item=state.catalog[key];if(!item)return;state.currentKey=key;state.reminderSchedules=JSON.parse(JSON.stringify(item.schedules||[]));E('reminderKey').value=key;E('reminderEnabled').value=item.enabled===0?'0':'1';E('reminderTitle').textContent='Edit '+item.title;E('reminderTab').textContent=item.title.replace(' reminder','')+' Email';E('reminderSubject').value=item.subject||'';E('reminderBody').value=item.body||'';state.activeField=E('reminderBody');variableMenu(E('reminderVariableMenu'),item);renderSchedules();updateReminderPreview();openModal('reminderModal')}
function wireRows(){document.querySelectorAll('[data-edit]').forEach(function(b){b.onclick=function(){var key=b.getAttribute('data-edit'),item=state.catalog[key];if(item.type==='reminder')openReminder(key);else openTemplate(key)}});document.querySelectorAll('[data-toggle]').forEach(function(input){input.onchange=function(){var key=input.getAttribute('data-toggle'),old=!input.checked,x=fd('toggle_feature');x.append('setting_key',key);x.append('enabled',input.checked?'1':'0');input.disabled=true;req(x).then(function(d){state.catalog[key].enabled=input.checked?1:0;toast('success',d.message)}).catch(function(err){input.checked=old;toast('error',err.message)}).then(function(){input.disabled=!state.schemaReady})}})}
function load(){var x=fd('list');req(x).then(function(d){state.catalog=d.catalog||{};state.tenant=d.tenant||state.tenant;state.teamUsers=d.team_users||[];state.routing=d.reply_routing||{};state.schemaReady=!!(d.schema&&d.schema.ready);E('schemaWarning').style.display=state.schemaReady?'none':'block';E('routingSave').disabled=!state.schemaReady;E('templateSave').disabled=!state.schemaReady;E('reminderSave').disabled=!state.schemaReady;renderGroups();renderRoutingSummary()}).catch(function(err){E('emailGroups').innerHTML='<div class="em-card"><div class="em-loading-card">Unable to load Email Settings.</div></div>';toast('error',err.message)})}
E('dismissNotice').onclick=function(){E('automationNotice').style.display='none';try{localStorage.setItem('fieldplx_email_automation_notice','dismissed')}catch(_){}};try{if(localStorage.getItem('fieldplx_email_automation_notice')==='dismissed')E('automationNotice').style.display='none'}catch(_){}
E('editRouting').onclick=function(){routingFields();openModal('routingModal')};document.querySelectorAll('[data-close]').forEach(function(b){b.onclick=function(){closeModal(b.getAttribute('data-close'))}});document.querySelectorAll('.em-modal-backdrop').forEach(function(m){m.addEventListener('click',function(e){if(e.target===m)closeModal(m.id)})});document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.em-modal-backdrop.open').forEach(function(m){closeModal(m.id)})});
E('routingForm').onsubmit=function(e){e.preventDefault();var btn=E('routingSave');setSaving(btn,true);var data=new FormData(this);req(data).then(function(d){['requests','quotes','jobs','invoices'].forEach(function(k){var v=document.querySelector('[data-route="'+k+'"]').value;if(v.indexOf('user:')===0)state.routing[k]={route_type:'specific_user',user_id:Number(v.slice(5))};else state.routing[k]={route_type:'sender',user_id:null}});renderRoutingSummary();toast('success',d.message);closeModal('routingModal')}).catch(function(err){toast('error',err.message)}).then(function(){setSaving(btn,false)})};
E('templateSubject').onfocus=function(){state.activeField=this};E('templateBody').onfocus=function(){state.activeField=this};E('templateSubject').oninput=updateTemplatePreview;E('templateBody').oninput=updateTemplatePreview;E('insertVariable').onclick=function(){E('variableMenu').classList.toggle('open')};E('variableMenu').onclick=function(e){var b=e.target.closest('[data-token]');if(!b)return;insertAtCursor(state.activeField,b.getAttribute('data-token'));E('variableMenu').classList.remove('open')};E('resetTemplate').onclick=function(){var item=state.catalog[state.currentKey];if(!item)return;E('templateSubject').value=item.default_subject||item.subject||'';E('templateBody').value=item.default_body||item.body||'';updateTemplatePreview()};E('templateForm').onsubmit=function(e){e.preventDefault();var btn=E('templateSave');setSaving(btn,true);req(new FormData(this)).then(function(d){var item=state.catalog[state.currentKey];item.subject=E('templateSubject').value;item.body=E('templateBody').value;toast('success',d.message);closeModal('templateModal')}).catch(function(err){toast('error',err.message)}).then(function(){setSaving(btn,false)})};
E('reminderSubject').onfocus=function(){state.activeField=this};E('reminderBody').onfocus=function(){state.activeField=this};E('reminderSubject').oninput=updateReminderPreview;E('reminderBody').oninput=updateReminderPreview;E('insertReminderVariable').onclick=function(){E('reminderVariableMenu').classList.toggle('open')};E('reminderVariableMenu').onclick=function(e){var b=e.target.closest('[data-token]');if(!b)return;insertAtCursor(state.activeField,b.getAttribute('data-token'));E('reminderVariableMenu').classList.remove('open')};E('resetReminder').onclick=function(){var item=state.catalog[state.currentKey];if(!item)return;E('reminderSubject').value=item.default_subject||item.subject||'';E('reminderBody').value=item.default_body||item.body||'';state.reminderSchedules=JSON.parse(JSON.stringify(item.schedules||[]));renderSchedules();updateReminderPreview()};E('addReminder').onclick=function(){state.reminderSchedules.push({amount:1,offset_type:'day_before',time_of_day:'15:30:00'});renderSchedules()};E('scheduleRows').addEventListener('input',function(e){var i=e.target.getAttribute('data-amount');if(i!==null)state.reminderSchedules[Number(i)].amount=Math.max(0,Math.min(365,Number(e.target.value||0)))});E('scheduleRows').addEventListener('change',function(e){var i=e.target.getAttribute('data-offset');if(i!==null){state.reminderSchedules[Number(i)].offset_type=e.target.value;if(e.target.value==='hour_before')state.reminderSchedules[Number(i)].time_of_day=null;else if(!state.reminderSchedules[Number(i)].time_of_day)state.reminderSchedules[Number(i)].time_of_day='15:30:00';renderSchedules();return}i=e.target.getAttribute('data-time');if(i!==null)state.reminderSchedules[Number(i)].time_of_day=e.target.value?e.target.value+':00':null});E('scheduleRows').addEventListener('click',function(e){var b=e.target.closest('[data-delete-schedule]');if(!b)return;if(state.reminderSchedules.length<=1){toast('error','At least one reminder schedule is required.');return}state.reminderSchedules.splice(Number(b.getAttribute('data-delete-schedule')),1);renderSchedules()});E('reminderForm').onsubmit=function(e){e.preventDefault();E('schedulesJson').value=JSON.stringify(state.reminderSchedules);var btn=E('reminderSave');setSaving(btn,true);req(new FormData(this)).then(function(d){var item=state.catalog[state.currentKey];item.subject=E('reminderSubject').value;item.body=E('reminderBody').value;item.schedules=JSON.parse(JSON.stringify(state.reminderSchedules));toast('success',d.message);closeModal('reminderModal');renderGroups()}).catch(function(err){toast('error',err.message)}).then(function(){setSaving(btn,false)})};
document.addEventListener('click',function(e){if(!e.target.closest('.em-variable-wrap'))document.querySelectorAll('.em-variable-menu.open').forEach(function(m){m.classList.remove('open')})});
load();refreshIcons();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
