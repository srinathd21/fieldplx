<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Customer Import · FieldPlx';
$pageDescription = 'Import customers in bulk from CSV or spreadsheet data';
$activePage = 'clients';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['customer_import_csrf_token'])) {
    $_SESSION['customer_import_csrf_token'] = bin2hex(random_bytes(32));
}

$customerImportCsrfToken = (string) $_SESSION['customer_import_csrf_token'];

require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{
    --ci-green:#2f8d22;
    --ci-green-dark:#26751c;
    --ci-green-soft:#edf7e9;
    --ci-title:#00263a;
    --ci-text:#183445;
    --ci-muted:#647787;
    --ci-border:#dce3e7;
    --ci-border-soft:#edf1f3;
    --ci-surface:#ffffff;
    --ci-surface-soft:#fbfcfd;
    --ci-surface-muted:#f7f9fa;
    --ci-sticky:#f8fafb;
    --ci-focus:#fbfff8;
    --ci-danger:#e24234;
    --ci-warning:#9a7b20;
    --ci-info:#123d70;
    --ci-success-soft:#fbfef9;
    --ci-skip-soft:#fffdf6;
    --ci-error-soft:#fff8f8;
    --ci-shadow:0 3px 12px rgba(0,38,58,.035);
    --ci-modal-overlay:rgba(0,38,58,.42);
}

html.app-dark-mode,
html.app-dark-mode body{
    --ci-green:#54b844;
    --ci-green-dark:#76ca68;
    --ci-green-soft:#17291b;
    --ci-title:#f3f8fb;
    --ci-text:#dce8ef;
    --ci-muted:#9bb0bd;
    --ci-border:#2c3d47;
    --ci-border-soft:#22323b;
    --ci-surface:#111c23;
    --ci-surface-soft:#15222a;
    --ci-surface-muted:#19262e;
    --ci-sticky:#16242c;
    --ci-focus:#16291d;
    --ci-danger:#ff6b61;
    --ci-warning:#d5b75f;
    --ci-info:#67a8ff;
    --ci-success-soft:#14271a;
    --ci-skip-soft:#2a2617;
    --ci-error-soft:#2b191b;
    --ci-shadow:0 4px 18px rgba(0,0,0,.22);
    --ci-modal-overlay:rgba(0,0,0,.64);
}

.ci-page{min-width:0;color:var(--ci-text)}
.ci-header{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    align-items:flex-start;
    gap:16px;
    margin-bottom:16px;
}
.ci-title{margin:0 0 6px;color:var(--ci-title);font-size:30px;line-height:1.1;font-weight:800;letter-spacing:-.6px}
.ci-subtitle{margin:0;max-width:800px;color:var(--ci-muted);font-size:12px;line-height:1.5}
.ci-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:6px;
    flex-wrap:nowrap;
    white-space:nowrap;
}
.ci-btn{
    min-height:30px;
    padding:0 9px;
    display:inline-flex;
    flex:0 0 auto;
    align-items:center;
    justify-content:center;
    gap:5px;
    border:1px solid var(--ci-border);
    border-radius:7px;
    background:var(--ci-surface);
    color:var(--ci-text);
    font:600 11px/1 inherit;
    text-decoration:none!important;
    cursor:pointer;
    transition:border-color .15s ease,background-color .15s ease,color .15s ease,box-shadow .15s ease;
    white-space:nowrap;
    box-shadow:none;
}
.ci-btn i{font-size:12px;line-height:1}
.ci-btn:hover,.ci-btn:focus{border-color:var(--ci-green);color:var(--ci-green-dark);background:var(--ci-green-soft);outline:0}
.ci-btn.primary{border-color:var(--ci-green);background:var(--ci-green);color:#fff}
.ci-btn.primary:hover{border-color:var(--ci-green-dark);background:var(--ci-green-dark);color:#fff}
.ci-btn.danger{color:var(--ci-danger)}
.ci-btn.danger:hover{border-color:var(--ci-danger);background:var(--ci-error-soft);color:var(--ci-danger)}
.ci-btn:disabled{opacity:.55;cursor:not-allowed}
.ci-hidden{display:none!important}

.ci-notice{
    margin-bottom:14px;
    padding:11px 13px;
    display:flex;
    align-items:flex-start;
    gap:9px;
    border:1px solid color-mix(in srgb,var(--ci-green) 24%,var(--ci-border));
    border-radius:9px;
    background:var(--ci-green-soft);
    color:var(--ci-muted);
    font-size:10px;
    line-height:1.5;
}
.ci-notice strong{color:var(--ci-text)}
.ci-notice i{margin-top:1px;color:var(--ci-green-dark);font-size:14px}

.ci-card{overflow:hidden;border:1px solid var(--ci-border);border-radius:10px;background:var(--ci-surface);box-shadow:var(--ci-shadow)}
.ci-toolbar{
    padding:8px 10px;
    display:flex;
    align-items:center;
    gap:6px;
    flex-wrap:nowrap;
    white-space:nowrap;
    overflow-x:auto;
    overflow-y:hidden;
    border-bottom:1px solid var(--ci-border);
    background:var(--ci-surface-soft);
    scrollbar-width:thin;
    scrollbar-color:var(--ci-border) transparent;
}
.ci-toolbar::-webkit-scrollbar{height:3px}
.ci-toolbar::-webkit-scrollbar-track{background:transparent}
.ci-toolbar::-webkit-scrollbar-thumb{border-radius:999px;background:var(--ci-border)}
.ci-help{margin-left:auto;flex:0 0 auto;color:var(--ci-muted);font-size:9.5px;line-height:1.35}

.ci-grid-wrap{width:100%;max-height:560px;overflow:auto;scrollbar-width:thin;scrollbar-color:var(--ci-border) transparent}
.ci-grid-wrap::-webkit-scrollbar{width:5px;height:5px}.ci-grid-wrap::-webkit-scrollbar-track{background:transparent}.ci-grid-wrap::-webkit-scrollbar-thumb{border-radius:999px;background:var(--ci-border)}
.ci-grid{width:max-content;min-width:100%;border-collapse:separate;border-spacing:0;background:var(--ci-surface)}
.ci-grid th{position:sticky;top:0;z-index:5;padding:9px 8px;border-right:1px solid var(--ci-border-soft);border-bottom:1px solid var(--ci-border);background:var(--ci-surface-muted);color:var(--ci-muted);font-size:8.5px;font-weight:700;text-transform:uppercase;white-space:nowrap}
.ci-grid th:first-child{left:0;z-index:7}.ci-grid td:first-child{position:sticky;left:0;z-index:3;background:var(--ci-sticky)}
.ci-grid td{height:39px;padding:0;border-right:1px solid var(--ci-border-soft);border-bottom:1px solid var(--ci-border-soft);background:var(--ci-surface);vertical-align:middle}
.ci-row-no{width:46px;min-width:46px;text-align:center;color:var(--ci-muted);font-size:9px;font-weight:700}
.ci-cell{width:150px;min-width:150px;height:38px;padding:7px 8px;border:0!important;border-radius:0!important;outline:0!important;background:transparent!important;color:var(--ci-text)!important;font:500 9px/1.2 inherit!important;box-shadow:none!important;color-scheme:light}
html.app-dark-mode .ci-cell{color-scheme:dark}
.ci-cell::placeholder{color:var(--ci-muted)}
.ci-cell:focus{position:relative;z-index:2;background:var(--ci-focus)!important;box-shadow:inset 0 0 0 2px var(--ci-green)!important}
.ci-cell.narrow{width:105px;min-width:105px}.ci-cell.medium{width:175px;min-width:175px}.ci-cell.wide{width:220px;min-width:220px}
select.ci-cell{padding-right:22px;cursor:pointer}select.ci-cell option{background:var(--ci-surface);color:var(--ci-text)}
.ci-row-action{width:34px;height:30px;border:0;border-radius:6px;background:transparent;color:var(--ci-muted);cursor:pointer}.ci-row-action:hover{color:var(--ci-danger);background:var(--ci-error-soft)}

.ci-footer{padding:10px 12px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-top:1px solid var(--ci-border);background:var(--ci-surface-soft)}
.ci-summary{display:flex;gap:14px;flex-wrap:nowrap;white-space:nowrap;color:var(--ci-muted);font-size:9.5px}.ci-summary strong{color:var(--ci-title)}
.ci-result{display:none;margin-top:14px}.ci-result.show{display:block}.ci-result-head{padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--ci-border)}
.ci-result-title{margin:0;color:var(--ci-title);font-size:12px;font-weight:700}.ci-result-head span{color:var(--ci-muted);font-size:9px}
.ci-result-list{max-height:360px;overflow:auto}.ci-result-row{padding:10px 14px;display:grid;grid-template-columns:92px minmax(0,1fr);gap:10px;border-bottom:1px solid var(--ci-border-soft);font-size:9px;line-height:1.5;color:var(--ci-text)}
.ci-result-row.ok{background:var(--ci-success-soft)}.ci-result-row.skip{background:var(--ci-skip-soft)}.ci-result-row.error{background:var(--ci-error-soft)}
.ci-result-row.ok>span:first-child{color:#74b84e}.ci-result-row.skip>span:first-child{color:#c5a84c}.ci-result-row.error>span:first-child{color:#e36d75}
.ci-result-main strong{display:block;margin-bottom:2px;color:var(--ci-title);font-size:9.5px}.ci-result-main small{display:block;color:var(--ci-muted);font-size:8.5px}
.ci-existing{margin-top:6px;padding:7px 9px;border:1px solid color-mix(in srgb,var(--ci-warning) 50%,var(--ci-border));border-radius:6px;background:var(--ci-skip-soft);color:var(--ci-muted)}.ci-existing strong{color:var(--ci-text)}
.ci-grid-result{min-width:210px;max-width:260px;padding:5px 8px!important;white-space:normal!important;line-height:1.35}.ci-grid-result small{display:block;margin-top:3px;color:var(--ci-muted);font-size:8px}
.ci-row-state{display:inline-flex;align-items:center;gap:4px;padding:4px 6px;border-radius:5px;font-size:8px;font-weight:700}.ci-row-state.ok{color:#74b84e;background:var(--ci-success-soft)}.ci-row-state.skip{color:#c5a84c;background:var(--ci-skip-soft)}.ci-row-state.error{color:#e36d75;background:var(--ci-error-soft)}.ci-row-state.pending{color:var(--ci-muted);background:var(--ci-surface-muted)}
.ci-grid tr.import-ok td{background:var(--ci-success-soft)}.ci-grid tr.import-skip td{background:var(--ci-skip-soft)}.ci-grid tr.import-error td{background:var(--ci-error-soft)}

.ci-toast{width:min(330px,calc(100vw - 28px));position:fixed;top:82px;right:16px;z-index:25000;padding:10px 11px;display:flex;align-items:center;gap:8px;border-radius:8px;color:#fff;opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s ease;box-shadow:0 12px 30px rgba(0,0,0,.24)}
.ci-toast.show{opacity:1;transform:translateY(0)}.ci-toast.success{background:#2f8d22}.ci-toast.error{background:#d7463a}.ci-toast.warning{background:#9a7b20}.ci-toast.info{background:#123d70}.ci-toast span{font-size:10px}.ci-toast button{margin-left:auto;border:0;background:transparent;color:#fff;cursor:pointer}

.ci-modal-backdrop{position:fixed;inset:0;z-index:20000;display:none;align-items:center;justify-content:center;padding:18px;background:var(--ci-modal-overlay);backdrop-filter:blur(2px)}.ci-modal-backdrop.show{display:flex}
.ci-modal{width:min(470px,100%);overflow:hidden;border:1px solid var(--ci-border);border-radius:12px;background:var(--ci-surface);box-shadow:0 24px 65px rgba(0,0,0,.35)}
.ci-modal-head,.ci-modal-foot{padding:13px 15px;display:flex;align-items:center;gap:10px;background:var(--ci-surface-soft)}.ci-modal-head{border-bottom:1px solid var(--ci-border)}.ci-modal-foot{justify-content:flex-end;border-top:1px solid var(--ci-border)}
.ci-modal-head h3{margin:0;color:var(--ci-title);font-size:13px}.ci-modal-body{padding:16px;color:var(--ci-muted);font-size:10px;line-height:1.6}.ci-modal-body strong{color:var(--ci-title)!important}.ci-modal-head div[style]{color:var(--ci-muted)!important}
.ci-modal-warning{margin-top:10px;padding:9px 10px;border:1px solid color-mix(in srgb,var(--ci-warning) 55%,var(--ci-border));border-radius:8px;background:var(--ci-skip-soft);color:var(--ci-warning)}
.ci-spinner{width:13px;height:13px;display:inline-block;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;animation:ciSpin .7s linear infinite}@keyframes ciSpin{to{transform:rotate(360deg)}}

@media(max-width:1100px){
    .ci-header{grid-template-columns:1fr;gap:10px}
    .ci-actions{justify-content:flex-start;overflow-x:auto;padding-bottom:2px;scrollbar-width:none}
    .ci-actions::-webkit-scrollbar{display:none}
}
@media(max-width:900px){
    .ci-title{font-size:25px}
    .ci-help{margin-left:16px}
    .ci-footer{align-items:center}
}
@media(max-width:575px){
    .ci-title{font-size:23px}
    .ci-header{margin-bottom:12px}
    .ci-btn{min-height:29px;padding:0 8px;font-size:10px}
    .ci-toolbar{padding:7px 8px}
    .ci-footer{overflow-x:auto;white-space:nowrap}
}
</style>

<div class="ci-page">
    <section class="ci-header">
        <div>
            <h1 class="ci-title">Customer Import</h1>
            <p class="ci-subtitle">Enter bulk customer data directly in the spreadsheet-style grid, paste rows from Excel, or load a CSV before importing into FieldPlx.</p>
        </div>
        <div class="ci-actions">
            <a class="ci-btn" href="clients.php"><i class="bi bi-arrow-left"></i> Back to Customers</a>
            <button class="ci-btn" type="button" id="sampleButton"><i class="bi bi-filetype-csv"></i> Sample CSV</button>
            <button class="ci-btn" type="button" id="exportGridButton"><i class="bi bi-download"></i> Export Grid CSV</button>
        </div>
    </section>

    <div class="ci-notice">
        <i class="bi bi-info-circle"></i>
        <div><strong>Bulk entry:</strong> paste rows copied from Excel directly into any grid cell. Required field is <strong>Display Name</strong>. Existing customers with the same email or phone are skipped to prevent duplicates. Location columns create one primary service location for that customer.</div>
    </div>

    <section class="ci-card">
        <div class="ci-toolbar">
            <input class="ci-hidden" type="file" id="csvFile" accept=".csv,text/csv">
            <button class="ci-btn" type="button" id="loadCsvButton"><i class="bi bi-upload"></i> Load CSV</button>
            <button class="ci-btn" type="button" id="addRowButton"><i class="bi bi-plus-lg"></i> Add Row</button>
            <button class="ci-btn" type="button" id="addTenRowsButton"><i class="bi bi-plus-square"></i> Add 10 Rows</button>
            <button class="ci-btn danger" type="button" id="clearGridButton"><i class="bi bi-trash3"></i> Clear Grid</button>
            <span class="ci-help">Tip: copy rows from Excel and paste directly into the first cell.</span>
        </div>

        <div class="ci-grid-wrap">
            <table class="ci-grid">
                <thead>
                <tr>
                    <th>#</th><th>Import Result</th><th>Customer Type</th><th>Display Name *</th><th>Company Name</th><th>First Name</th><th>Last Name</th><th>Email</th><th>Phone</th><th>Alternate Phone</th><th>Source</th><th>Preferred Contact</th><th>Status</th><th>Branch Code / Name</th><th>Tax Number</th><th>Notes</th><th>Location Name</th><th>Location Type</th><th>Address Line 1</th><th>Address Line 2</th><th>City</th><th>State</th><th>Postal Code</th><th>Country / ISO2</th><th>Location Contact</th><th>Location Phone</th><th>Primary Location</th><th>Action</th>
                </tr>
                </thead>
                <tbody id="bulkGridBody"></tbody>
            </table>
        </div>

        <div class="ci-footer">
            <div class="ci-summary">
                <span>Total grid rows: <strong id="rowCount">0</strong></span>
                <span>Ready to import: <strong id="readyCount">0</strong></span>
            </div>
            <button class="ci-btn primary" type="button" id="saveImportButton"><i class="bi bi-database-add"></i> Import Customers</button>
        </div>
    </section>

    <section class="ci-card ci-result" id="importResult">
        <div class="ci-result-head"><h2 class="ci-result-title">Import Result</h2><span id="resultSummary"></span></div>
        <div class="ci-result-list" id="importResultList"></div>
    </section>
</div>

<div class="ci-modal-backdrop" id="leavePageModal" aria-hidden="true">
    <div class="ci-modal" role="dialog" aria-modal="true" aria-labelledby="leavePageModalTitle">
        <div class="ci-modal-head"><div><h3 id="leavePageModalTitle">Unsaved customer import data</h3><div style="margin-top:3px;color:#647787;font-size:9px">Your spreadsheet contains changes that may be lost.</div></div></div>
        <div class="ci-modal-body">
            <strong style="color:#00263a">Are you sure you want to leave or refresh this page?</strong><br>
            Any customer rows that have not been successfully imported will remain only in this browser page and can be lost.
            <div class="ci-modal-warning"><i class="bi bi-exclamation-triangle me-1"></i> Export the grid as CSV first if you want to keep a backup.</div>
        </div>
        <div class="ci-modal-foot">
            <button type="button" class="ci-btn" id="stayOnPageButton">Stay on Page</button>
            <button type="button" class="ci-btn danger" id="confirmLeaveButton"><i class="bi bi-box-arrow-right"></i> Leave Anyway</button>
        </div>
    </div>
</div>

<div class="ci-toast info" id="toast"><span id="toastMessage">Notification</span><button type="button" id="toastClose"><i class="bi bi-x-lg"></i></button></div>

<script src="https://cdn.jsdelivr.net/npm/papaparse@5.4.1/papaparse.min.js"></script>
<script>
(function(){
'use strict';
var csrfToken=<?= json_encode($customerImportCsrfToken) ?>;
var apiUrl='api/customer-import.php';
var columns=[
 ['client_type','Customer Type','select',['lead','client']],['display_name','Display Name *','text'],['company_name','Company Name','text'],['first_name','First Name','text'],['last_name','Last Name','text'],['email','Email','text'],['phone','Phone','text'],['alternate_phone','Alternate Phone','text'],['source','Source','text'],['preferred_contact_method','Preferred Contact','select',['email','sms','phone','whatsapp','none']],['status','Status','select',['new','active','inactive']],['branch','Branch Code / Name','text'],['tax_number','Tax Number','text'],['notes','Notes','text'],['location_name','Location Name','text'],['location_type','Location Type','select',['home','office','warehouse','factory','farm','shop','site','other']],['address_line1','Address Line 1','text'],['address_line2','Address Line 2','text'],['city','City','text'],['state','State','text'],['postal_code','Postal Code','text'],['country','Country / ISO2','text'],['location_contact_name','Location Contact','text'],['location_contact_phone','Location Phone','text'],['is_primary','Primary Location','select',['Yes','No']]
];
var rows=[],meta={branches:[],countries:[]},toastTimer=null,rowResults={},isDirty=false,pendingLeaveAction=null;
function el(id){return document.getElementById(id)}
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/'/g,'&#039;')}
function notify(type,msg){if(toastTimer)clearTimeout(toastTimer);var t=el('toast');t.className='ci-toast '+(type||'info')+' show';el('toastMessage').textContent=msg||'Notification';toastTimer=setTimeout(function(){t.classList.remove('show')},3200)}
function parseResponse(r){return r.text().then(function(raw){var d,text=String(raw||'').trim();if(r.status===404)throw new Error('Customer Import API not found. Please place api/customer-import.php in the business/api folder.');try{d=text?JSON.parse(text):{}}catch(e){throw new Error(text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!r.ok||!d.success)throw new Error(d.message||('Request failed. HTTP '+r.status));return d})}
function request(fd){fd.append('csrf_token',csrfToken);return fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parseResponse)}
function emptyRow(){var r={};columns.forEach(function(c){r[c[0]]=''});r.client_type='lead';r.preferred_contact_method='email';r.status='new';r.location_type='other';r.is_primary='Yes';return r}
function ensureRows(count){while(rows.length<count)rows.push(emptyRow())}
function render(){var body=el('bulkGridBody'),h='';rows.forEach(function(r,ri){var rr=rowResults[ri+1]||null,rowCls=rr?(rr.status==='Imported'?' import-ok':(rr.status==='Existing'||rr.status==='Skipped'?' import-skip':' import-error')):'';h+='<tr class="'+rowCls+'" data-row="'+ri+'"><td class="ci-row-no">'+(ri+1)+'</td>';var badgeCls=rr?(rr.status==='Imported'?'ok':(rr.status==='Existing'||rr.status==='Skipped'?'skip':'error')):'pending',badgeText=rr?(rr.status==='Existing'?'Already Exists':rr.status):'Not Checked';h+='<td class="ci-grid-result"><span class="ci-row-state '+badgeCls+'">'+esc(badgeText)+'</span>'+(rr&&rr.message?'<small>'+esc(rr.message)+'</small>':'')+'</td>';columns.forEach(function(c,ci){var key=c[0],type=c[2],cls=(key==='notes'||key==='address_line1'||key==='address_line2'?' wide':(key==='display_name'||key==='company_name'||key==='email'?' medium':((key==='client_type'||key==='status'||key==='location_type'||key==='is_primary')?' narrow':'')));if(type==='select'){h+='<td><select class="ci-cell'+cls+'" data-row="'+ri+'" data-col="'+ci+'" data-key="'+key+'">';c[3].forEach(function(o){h+='<option value="'+esc(o)+'"'+(String(r[key])===String(o)?' selected':'')+'>'+esc(o)+'</option>'});h+='</select></td>'}else{h+='<td><input class="ci-cell'+cls+'" data-row="'+ri+'" data-col="'+ci+'" data-key="'+key+'" value="'+esc(r[key])+'"></td>'}});h+='<td><button type="button" class="ci-row-action" data-remove="'+ri+'" title="Remove row"><i class="bi bi-trash"></i></button></td></tr>'});body.innerHTML=h;updateCount()}
function updateCount(){var valid=rows.filter(function(r){return String(r.display_name||'').trim()!==''}).length;el('rowCount').textContent=rows.length;el('readyCount').textContent=valid}
function markDirty(){isDirty=true}
function syncCell(target){var ri=Number(target.dataset.row),key=target.dataset.key;if(!rows[ri])return;rows[ri][key]=target.value;delete rowResults[ri+1];markDirty();updateCount()}
function addRows(n,dirty){n=Number(n||1);for(var i=0;i<n;i++)rows.push(emptyRow());if(dirty)markDirty();render()}
function downloadText(name,text){var blob=new Blob(['\ufeff'+text],{type:'text/csv;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=name;document.body.appendChild(a);a.click();a.remove();URL.revokeObjectURL(url)}
function gridData(){return rows.filter(function(r){return Object.keys(r).some(function(k){return String(r[k]||'').trim()!==''})}).map(function(r){var out={};columns.forEach(function(c){out[c[1].replace(' *','')]=r[c[0]]||''});return out})}
function normalizeImported(data){var map={};columns.forEach(function(c){map[c[1].replace(' *','').toLowerCase()]=c[0];map[c[0].toLowerCase()]=c[0]});var out=[];(data||[]).forEach(function(src){var r=emptyRow(),has=false;Object.keys(src||{}).forEach(function(k){var key=map[String(k).trim().toLowerCase()];if(key){r[key]=String(src[k]==null?'':src[k]).trim();if(r[key]!=='')has=true}});if(has)out.push(r)});return out}
function showResult(d){var box=el('importResult'),list=el('importResultList'),h='';rowResults={};(d.results||[]).forEach(function(x){rowResults[Number(x.row||0)]=x;var cls=x.status==='Imported'?'ok':(x.status==='Existing'||x.status==='Skipped'?'skip':'error'),label=x.status==='Existing'?'Already Exists':x.status,entered=x.input||{},existing=x.existing||null;h+='<div class="ci-result-row '+cls+'"><span>'+esc(label)+'</span><div class="ci-result-main"><strong>Row '+Number(x.row||0)+' · '+esc(entered.display_name||'Unnamed customer')+'</strong><small>'+esc(x.message||'')+'</small>';if(existing){h+='<div class="ci-existing"><strong>Existing Customer #'+Number(existing.id||0)+': '+esc(existing.display_name||'-')+'</strong><br>Email: '+esc(existing.email||'-')+' · Phone: '+esc(existing.phone||'-')+' · Type: '+esc(existing.client_type||'-')+' · Status: '+esc(existing.status||'-')+(existing.branch_name?' · Branch: '+esc(existing.branch_name):'')+'</div>'}h+='</div></div>'});list.innerHTML=h||'<div class="ci-result-row"><span>Done</span><div class="ci-result-main"><small>No row details returned.</small></div></div>';el('resultSummary').textContent=Number(d.imported||0)+' imported · '+Number(d.existing||d.skipped||0)+' already exists · '+Number(d.failed||0)+' failed';box.classList.add('show');render();isDirty=!(Number(d.failed||0)===0&&Number(d.existing||d.skipped||0)===0);box.scrollIntoView({behavior:'smooth',block:'start'})}
function loadMeta(){var fd=new FormData();fd.append('action','meta');request(fd).then(function(d){meta=d.meta||meta}).catch(function(e){notify('error',e.message)})}
function openLeaveModal(action){pendingLeaveAction=action;el('leavePageModal').classList.add('show');el('leavePageModal').setAttribute('aria-hidden','false')}
function closeLeaveModal(){el('leavePageModal').classList.remove('show');el('leavePageModal').setAttribute('aria-hidden','true')}
function askBeforeLeave(action){if(!isDirty){action();return}openLeaveModal(action)}
el('loadCsvButton').onclick=function(){el('csvFile').click()};
el('bulkGridBody').addEventListener('input',function(e){if(e.target.matches('.ci-cell'))syncCell(e.target)});
el('bulkGridBody').addEventListener('change',function(e){if(e.target.matches('.ci-cell'))syncCell(e.target)});
el('bulkGridBody').addEventListener('click',function(e){var b=e.target.closest('[data-remove]');if(!b)return;var i=Number(b.dataset.remove);rows.splice(i,1);rowResults={};if(!rows.length)rows.push(emptyRow());markDirty();render()});
el('bulkGridBody').addEventListener('paste',function(e){var target=e.target.closest('.ci-cell');if(!target)return;var text=(e.clipboardData||window.clipboardData).getData('text');if(text.indexOf('\t')<0&&text.indexOf('\n')<0&&text.indexOf('\r')<0)return;e.preventDefault();var matrix=text.replace(/\r/g,'').split('\n').filter(function(x,i,a){return !(i===a.length-1&&x==='')}).map(function(line){return line.split('\t')});var sr=Number(target.dataset.row),sc=Number(target.dataset.col);ensureRows(sr+matrix.length);matrix.forEach(function(line,rOffset){line.forEach(function(v,cOffset){var c=columns[sc+cOffset];if(c)rows[sr+rOffset][c[0]]=String(v||'').trim()})});rowResults={};markDirty();render();var next=el('bulkGridBody').querySelector('[data-row="'+sr+'"][data-col="'+sc+'"]');if(next)next.focus();notify('success','Pasted '+matrix.length+' row(s) from spreadsheet.')});
el('addRowButton').onclick=function(){addRows(1,true)};
el('addTenRowsButton').onclick=function(){addRows(10,true)};
el('clearGridButton').onclick=function(){rows=[];rowResults={};addRows(10,false);isDirty=false;el('importResult').classList.remove('show')};
el('csvFile').onchange=function(e){var file=e.target.files&&e.target.files[0];if(!file)return;Papa.parse(file,{header:true,skipEmptyLines:true,complete:function(res){var imported=normalizeImported(res.data||[]);if(!imported.length){notify('warning','No matching customer columns were found in the CSV.');return}rows=imported;rowResults={};ensureRows(Math.max(10,rows.length));markDirty();render();notify('success',imported.length+' CSV row(s) loaded into the grid.')}})};
el('exportGridButton').onclick=function(){var data=gridData();if(!data.length){notify('warning','Enter customer data before exporting.');return}downloadText('customer-import-grid-'+new Date().toISOString().slice(0,10)+'.csv',Papa.unparse(data))};
el('sampleButton').onclick=function(){var sample=[{'Customer Type':'lead','Display Name':'John Smith','Company Name':'Smith Services','First Name':'John','Last Name':'Smith','Email':'john.smith@example.com','Phone':'5551234567','Alternate Phone':'','Source':'Website','Preferred Contact':'email','Status':'new','Branch Code / Name':'B001','Tax Number':'','Notes':'Sample customer import row','Location Name':'Main Location','Location Type':'office','Address Line 1':'123 Main Street','Address Line 2':'Suite 100','City':'Austin','State':'Texas','Postal Code':'78701','Country / ISO2':'US','Location Contact':'John Smith','Location Phone':'5551234567','Primary Location':'Yes'}];downloadText('customer-import-sample.csv',Papa.unparse(sample));notify('success','Sample CSV generated successfully.')};
el('saveImportButton').onclick=function(){var payload=rows.filter(function(r){return String(r.display_name||'').trim()!==''});if(!payload.length){notify('warning','Enter at least one customer Display Name.');return}var btn=this,old=btn.innerHTML,fd=new FormData();fd.append('action','import');fd.append('rows_json',JSON.stringify(payload));btn.disabled=true;btn.innerHTML='<span class="ci-spinner"></span> Importing...';request(fd).then(function(d){notify('success',d.message);showResult(d)}).catch(function(e){notify('error',e.message)}).finally(function(){btn.disabled=false;btn.innerHTML=old})};
el('toastClose').onclick=function(){el('toast').classList.remove('show')};
el('stayOnPageButton').onclick=function(){pendingLeaveAction=null;closeLeaveModal()};
el('leavePageModal').addEventListener('click',function(e){if(e.target===this){pendingLeaveAction=null;closeLeaveModal()}});
el('confirmLeaveButton').onclick=function(){isDirty=false;var action=pendingLeaveAction;pendingLeaveAction=null;closeLeaveModal();setTimeout(function(){if(action)action()},60)};
document.addEventListener('click',function(e){var a=e.target.closest('a[href]');if(!a||a.dataset.importLeaveSafe==='1'||a.target==='_blank'||a.hasAttribute('download'))return;var href=a.getAttribute('href');if(!href||href.charAt(0)==='#'||href.toLowerCase().indexOf('javascript:')===0)return;if(!isDirty)return;e.preventDefault();askBeforeLeave(function(){window.location.href=a.href})},true);
document.addEventListener('keydown',function(e){if(e.key==='Escape'&&el('leavePageModal').classList.contains('show')){pendingLeaveAction=null;closeLeaveModal();return}var reload=(e.key==='F5'||((e.ctrlKey||e.metaKey)&&String(e.key).toLowerCase()==='r'));if(!reload||!isDirty)return;e.preventDefault();askBeforeLeave(function(){window.location.reload()})});
window.addEventListener('beforeunload',function(e){if(!isDirty)return;e.preventDefault();e.returnValue='' });
rows=[];addRows(10,false);loadMeta();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
