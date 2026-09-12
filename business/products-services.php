<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Products & Services · FieldPlx';
$pageDescription = 'Manage reusable products and services';
$settingsActivePage = 'products-services';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['products_services_csrf'])) {
    $_SESSION['products_services_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['products_services_csrf'];

$tenantId = isset($currentTenantId) ? (int)$currentTenantId : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);
$currencySymbol = isset($_SESSION['currency_symbol']) && $_SESSION['currency_symbol'] !== '' ? (string)$_SESSION['currency_symbol'] : '₹';

require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/toast.php';
?>
<style>
.ps-page{display:grid;grid-template-columns:250px minmax(0,1fr);gap:28px;max-width:1320px;margin:0 auto;padding:8px 0 44px}.ps-main{min-width:0}.ps-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin:0 0 14px}.ps-head h1{margin:0;color:var(--fieldplx-text,#0b1933);font-size:31px;line-height:1.15}.ps-head p{margin:8px 0 0;color:var(--fieldplx-muted,#6f7b90);font-size:14px}.ps-tools{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}.ps-search{position:relative;width:min(520px,100%);display:block}.ps-search>i,.ps-search>svg{position:absolute;left:14px;top:50%;z-index:2;transform:translateY(-50%);width:19px;height:19px;color:#66808e;pointer-events:none}.ps-search input{display:block;width:100%;height:50px;margin:0;padding:0 44px 0 46px;border:1px solid #dce4eb;border-radius:9px;background:#fff;color:#0b1933;font-size:14px;outline:none;-webkit-appearance:none;appearance:none}.ps-search input::-webkit-search-cancel-button{-webkit-appearance:none;appearance:none;display:none}.ps-search input:focus{border-color:#74b824;box-shadow:0 0 0 2px rgba(116,184,36,.12)}.ps-search .clear{position:absolute;right:8px;top:7px;width:36px;height:36px;border:0;background:transparent;color:#234454;font-size:22px;cursor:pointer}.ps-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;height:41px;padding:0 15px;border:1px solid #dce4eb;border-radius:9px;background:#fff;color:#163746;font:inherit;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none}.ps-btn:hover{border-color:#74b824}.ps-btn.primary{background:#2f8c25;border-color:#2f8c25;color:#fff}.ps-btn.danger{color:#d94242}.ps-btn:disabled{cursor:not-allowed;opacity:.55;background:#edf0f2;border-color:#e2e7eb;color:#7d8c94}.ps-btn.primary:disabled{background:#d9dfe3;border-color:#d9dfe3;color:#8b979d}.ps-btn.small{height:34px;padding:0 11px;font-size:11px}.ps-card{background:#fff;border:1px solid #dce4eb;border-radius:11px;overflow:hidden;margin-bottom:22px}.ps-table{width:100%;border-collapse:collapse}.ps-table th,.ps-table td{padding:17px 16px;border-bottom:1px solid #dce4eb;text-align:left;vertical-align:middle}.ps-table th{font-size:12px;color:#0b1933;font-weight:700;background:#fff}.ps-table td{font-size:13px;color:#284957}.ps-table tr:last-child td{border-bottom:0}.ps-table tbody tr{cursor:pointer}.ps-table tbody tr:hover{background:#fbfdf9}.ps-table .name{font-weight:700;color:#173644}.ps-table .desc{max-width:560px;color:#345462}.ps-table .type{white-space:nowrap}.ps-sort{display:inline-flex;align-items:center;gap:5px;border:0;background:none;padding:0;font:inherit;font-weight:700;color:inherit;cursor:pointer}.ps-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;border-top:1px solid #dce4eb;font-size:12px;color:#345462}.ps-page-controls{display:flex;align-items:center;gap:10px}.ps-page-controls select{height:38px;padding:0 32px 0 11px;border:1px solid #dce4eb;border-radius:8px;background:#fff;color:#173644}.ps-icon-btn{width:40px;height:40px;display:grid;place-items:center;border:0;border-radius:8px;background:#f1f2f3;color:#7b8990;cursor:pointer}.ps-icon-btn:disabled{opacity:.45;cursor:not-allowed}.ps-section{padding:20px 16px}.ps-section h2{margin:0 0 12px;color:#0b1933;font-size:23px}.ps-section p{margin:0;color:#345462;font-size:13px;line-height:1.5}.ps-split{display:flex;align-items:center;justify-content:space-between;gap:18px}.ps-toggle{position:relative;width:49px;height:25px;flex:0 0 auto}.ps-toggle input{position:absolute;opacity:0}.ps-toggle span{position:absolute;inset:0;border-radius:999px;background:#c8d0d5;transition:.18s}.ps-toggle span:after{content:"";position:absolute;width:19px;height:19px;left:3px;top:3px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:.18s}.ps-toggle input:checked+span{background:#2f8c25}.ps-toggle input:checked+span:after{transform:translateX(24px)}.ps-import-export{display:flex;align-items:center;justify-content:space-between;gap:18px}.ps-import-export .copy{max-width:720px}.ps-link{color:#2f7f24;text-decoration:underline;cursor:pointer}.ps-empty{padding:42px 16px;text-align:center;color:#6f7b90}
.ps-modal-backdrop{position:fixed;inset:0;z-index:12000;display:none;align-items:flex-start;justify-content:center;padding:18px;background:rgba(0,17,49,.36);overflow:auto}.ps-modal-backdrop.open{display:flex}.ps-modal{width:min(620px,100%);margin:auto;background:#fff;border-radius:12px;box-shadow:0 24px 70px rgba(0,17,49,.25);overflow:hidden}.ps-modal.wide{width:min(980px,100%)}.ps-modal-head{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid #e5eaf1}.ps-modal-head h3{margin:0;color:#0b1933;font-size:22px}.ps-close{width:34px;height:34px;border:0;background:transparent;color:#234454;font-size:25px;cursor:pointer}.ps-modal-body{padding:20px 22px}.ps-modal-foot{display:flex;align-items:center;justify-content:flex-end;gap:9px;padding:15px 22px;border-top:1px solid #e5eaf1}.ps-field{position:relative;margin-bottom:12px}.ps-field label{display:block;margin:0 0 6px;color:#244554;font-size:11px;font-weight:600}.ps-input,.ps-select,.ps-textarea{width:100%;border:1px solid #dce4eb;border-radius:8px;background:#fff;color:#163746;font:inherit;font-size:13px;outline:none}.ps-input,.ps-select{height:49px;padding:0 14px}.ps-textarea{min-height:96px;padding:14px;resize:vertical}.ps-input:focus,.ps-select:focus,.ps-textarea:focus{border-color:#74b824;box-shadow:0 0 0 2px rgba(116,184,36,.11)}.ps-cost-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0;margin-bottom:14px;border:1px solid #dce4eb;border-radius:8px;overflow:hidden}.ps-cost-grid .ps-field{margin:0}.ps-cost-grid .ps-field label{position:absolute;left:14px;top:7px;z-index:2;font-size:10px;color:#6b7f89}.ps-cost-grid input{height:51px;padding:20px 14px 5px;border:0;border-radius:0;border-right:1px solid #dce4eb}.ps-cost-grid .ps-field:last-child input{border-right:0}.ps-drop{position:relative;min-height:88px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:16px;border:1px dashed #d7e0e6;border-radius:8px;text-align:center;color:#6f7b90;font-size:11px;margin-bottom:14px}.ps-drop input{position:absolute;inset:0;opacity:0;cursor:pointer}.ps-check{display:flex;align-items:flex-start;gap:9px;margin:10px 0;color:#345462;font-size:12px}.ps-check input{width:18px;height:18px;accent-color:#2f8c25}.ps-divider{height:1px;background:#e5eaf1;margin:18px 0}.ps-subtitle{margin:0 0 13px;color:#173644;font-size:14px;font-weight:700}.ps-help{font-size:11px;color:#6f7b90;line-height:1.45}.ps-qty-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #dce4eb;border-radius:8px;overflow:hidden;margin-top:12px}.ps-qty-grid .ps-field{margin:0}.ps-qty-grid input{border:0;border-radius:0}.ps-qty-grid .ps-field:first-child input{border-right:1px solid #dce4eb}.ps-preview-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}.ps-stat{padding:12px;border:1px solid #e0e7ec;border-radius:9px;background:#fbfcfd}.ps-stat span{display:block;color:#6f7b90;font-size:10px}.ps-stat strong{display:block;margin-top:4px;color:#0b1933;font-size:18px}.ps-preview-wrap{max-height:420px;overflow:auto;border:1px solid #dce4eb;border-radius:9px}.ps-preview{width:100%;border-collapse:collapse}.ps-preview th,.ps-preview td{padding:9px 10px;border-bottom:1px solid #e7edf1;font-size:11px;text-align:left;vertical-align:top}.ps-preview th{position:sticky;top:0;background:#f7f9fb;z-index:2;color:#173644}.ps-preview tr.error{background:#fff3f4}.ps-preview tr.warning{background:#fffaf0}.ps-badge{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:9px;font-weight:700}.ps-badge.ok{background:#edf7e7;color:#397820}.ps-badge.error{background:#fdeced;color:#b9333b}.ps-badge.warning{background:#fff4d8;color:#906b10}.ps-file-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:12px}.ps-note{padding:10px 12px;border-radius:8px;background:#f6f8fb;color:#526b77;font-size:11px;line-height:1.5}.ps-hidden{display:none!important}
@media(max-width:980px){.ps-page{grid-template-columns:1fr}.ps-page>aside{display:none}.ps-main{padding:0 4px}}@media(max-width:680px){.ps-head,.ps-tools,.ps-import-export,.ps-split{align-items:stretch;flex-direction:column}.ps-tools .ps-btn{align-self:flex-end}.ps-table th:nth-child(2),.ps-table td:nth-child(2){display:none}.ps-cost-grid,.ps-qty-grid{grid-template-columns:1fr}.ps-cost-grid input{border-right:0;border-bottom:1px solid #dce4eb}.ps-preview-summary{grid-template-columns:1fr 1fr}.ps-modal-body{padding:16px}.ps-modal-foot{padding:13px 16px}}
</style>

<div class="ps-page">
    <aside><?php require __DIR__ . '/includes/settings-nav.php'; ?></aside>
    <main class="ps-main">
        <div class="ps-head">
            <div><h1>Products &amp; services</h1><p>Add and update your products &amp; services to stay organized when creating quotes, jobs, and invoices.</p></div>
        </div>

        <div class="ps-tools">
            <div class="ps-search"><i data-lucide="search"></i><input id="searchInput" type="search" placeholder="Search"><button class="clear" id="clearSearch" type="button">&times;</button></div>
            <button class="ps-btn primary" id="addItemBtn" type="button"><span>+</span> Add Item</button>
        </div>

        <section class="ps-card">
            <div style="overflow:auto">
                <table class="ps-table">
                    <thead><tr><th><button class="ps-sort" data-sort="name">Name <span>↕</span></button></th><th>Description</th><th><button class="ps-sort" data-sort="type">Type <span>↕</span></button></th></tr></thead>
                    <tbody id="itemsBody"><tr><td colspan="3" class="ps-empty">Loading products &amp; services...</td></tr></tbody>
                </table>
            </div>
            <div class="ps-pagination"><div id="pageSummary">Showing 0 items</div><div class="ps-page-controls"><select id="perPage"><option>10</option><option selected>25</option><option>50</option><option>100</option></select><span>per page</span><button class="ps-icon-btn" id="prevPage" type="button">‹</button><button class="ps-icon-btn" id="nextPage" type="button">›</button></div></div>
        </section>

        <section class="ps-card ps-section"><div class="ps-split"><div><h2>Costs</h2><p><strong>Product &amp; Services costs</strong></p><p style="margin-top:7px">Add costs to your products and services on quotes and jobs.</p></div><label class="ps-toggle"><input id="costToggle" type="checkbox" checked><span></span></label></div></section>

        <section class="ps-card ps-section"><div class="ps-import-export"><div class="copy"><h2>Import products &amp; services</h2><p>Bulk import products &amp; services by uploading a CSV. Preview and validation run before anything is saved.</p><p style="margin-top:10px"><button type="button" class="ps-link" id="sampleCsvBtn" style="border:0;background:none;padding:0;font:inherit">Download sample CSV</button> generated by FieldPlx.</p></div><button class="ps-btn" id="importBtn" type="button"><i data-lucide="square-arrow-in-down-left"></i> Import CSV</button></div></section>

        <section class="ps-card ps-section"><div class="ps-import-export"><div class="copy"><h2>Export products &amp; services</h2><p>Export all active products &amp; services from this FieldPlx account.</p></div><button class="ps-btn" id="exportBtn" type="button"><i data-lucide="square-arrow-out-up-right"></i> Export CSV</button></div></section>
    </main>
</div>

<div class="ps-modal-backdrop" id="itemModal" aria-hidden="true">
    <div class="ps-modal">
        <div class="ps-modal-head"><h3 id="itemModalTitle">Add New Product/Service</h3><button class="ps-close" type="button" data-close-modal="itemModal">&times;</button></div>
        <form id="itemForm" enctype="multipart/form-data">
            <div class="ps-modal-body">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_item"><input type="hidden" name="item_id" id="itemId" value="0"><input type="hidden" name="source_table" id="sourceTable" value="">
                <div class="ps-field"><label>Item type</label><select class="ps-select" name="item_type" id="itemType"><option value="service">Service</option><option value="product">Product</option></select></div>
                <div class="ps-field"><label>Name</label><input class="ps-input" name="name" id="itemName" maxlength="190" required></div>
                <div class="ps-field"><label>Description</label><textarea class="ps-textarea" name="description" id="itemDescription"></textarea></div>
                <div class="ps-cost-grid">
                    <div class="ps-field"><label>Cost (<?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?>)</label><input class="ps-input" type="number" min="0" step="0.01" name="unit_cost" id="unitCost" value="0.00"></div>
                    <div class="ps-field"><label>Markup (%)</label><input class="ps-input" type="number" min="0" step="0.01" name="markup_percent" id="markupPercent" value="0"></div>
                    <div class="ps-field"><label>Unit Price (<?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?>)</label><input class="ps-input" type="number" min="0" step="0.01" name="unit_price" id="unitPrice" value="0.00"></div>
                </div>
                <label class="ps-drop"><input type="file" name="item_image" id="itemImage" accept="image/jpeg,image/png,image/webp"><button class="ps-btn small" type="button">Upload Image</button><span id="imageFileName">Select or drag an image here to upload</span></label>
                <label class="ps-check"><input type="checkbox" name="tax_exempt" id="taxExempt" value="1"><span>Exempt from Tax</span></label>
                <div class="ps-divider"></div>
                <div id="onlineBookingFields"><h4 class="ps-subtitle">Online Booking</h4><p class="ps-help">These settings are available for services used in online booking.</p>
                    <div class="ps-field" style="margin-top:13px"><label>Service Duration</label><select class="ps-select" name="duration_minutes" id="durationMinutes"></select></div>
                    <label class="ps-check"><input type="checkbox" name="allow_customer_quantity" id="allowCustomerQuantity" value="1"><span>Allow customers to select quantity<br><small class="ps-help">Duration and unit price can scale based on quantity.</small></span></label>
                    <div class="ps-qty-grid ps-hidden" id="qtyRange"><div class="ps-field"><label>Minimum quantity</label><input class="ps-input" type="number" min="1" step="1" name="min_quantity" id="minQuantity" value="1"></div><div class="ps-field"><label>Maximum quantity</label><input class="ps-input" type="number" min="1" step="1" name="max_quantity" id="maxQuantity" value="10"></div></div>
                </div>
            </div>
            <div class="ps-modal-foot"><button class="ps-btn" type="button" data-close-modal="itemModal">Cancel</button><button class="ps-btn primary" type="submit" id="saveItemBtn">Create</button></div>
        </form>
    </div>
</div>

<div class="ps-modal-backdrop" id="importModal" aria-hidden="true">
    <div class="ps-modal wide">
        <div class="ps-modal-head"><h3>Import products &amp; services</h3><button class="ps-close" type="button" data-close-modal="importModal">&times;</button></div>
        <div class="ps-modal-body">
            <div class="ps-field"><label>Existing items</label><select class="ps-select" id="duplicateMode"><option value="skip">Skip existing items</option><option value="update">Update existing items</option></select></div>
            <label class="ps-drop" style="min-height:115px"><input type="file" id="csvFile" accept=".csv,text/csv"><button class="ps-btn small" type="button">Select CSV</button><span id="csvFileName">Select or drag a CSV file here</span></label>
            <div class="ps-note">Step 1: select a CSV and click <strong>Preview &amp; Validate</strong>. FieldPlx will not save anything until the preview has no blocking errors and you click <strong>Import</strong>.</div>
            <div id="previewArea" class="ps-hidden" style="margin-top:16px">
                <div class="ps-preview-summary"><div class="ps-stat"><span>Total rows</span><strong id="pvTotal">0</strong></div><div class="ps-stat"><span>Valid</span><strong id="pvValid">0</strong></div><div class="ps-stat"><span>Warnings</span><strong id="pvWarnings">0</strong></div><div class="ps-stat"><span>Errors</span><strong id="pvErrors">0</strong></div></div>
                <div class="ps-preview-wrap"><table class="ps-preview"><thead><tr><th>Row</th><th>Type</th><th>Name</th><th>Cost</th><th>Markup</th><th>Price</th><th>Status</th><th>Message</th></tr></thead><tbody id="previewBody"></tbody></table></div>
            </div>
        </div>
        <div class="ps-modal-foot"><button class="ps-btn" type="button" data-close-modal="importModal">Cancel</button><button class="ps-btn" type="button" id="previewCsvBtn">Preview &amp; Validate</button><button class="ps-btn primary" type="button" id="commitImportBtn" disabled>Import</button></div>
    </div>
</div>

<script>
(function(){
'use strict';
var API='api/products-services.php',csrf=<?= json_encode($csrfToken) ?>,tenant=<?= (int)$tenantId ?>;
var state={page:1,per_page:25,search:'',sort:'name',dir:'asc',pages:1,total:0,previewToken:''};
function el(id){return document.getElementById(id)}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]})}
function toast(t,m){if(window.fieldplxToast)window.fieldplxToast(t,m)}
function req(fd){return fetch(API,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json().catch(function(){throw new Error('Invalid server response.');}).then(function(d){if(!r.ok||!d.success)throw new Error(d.message||'Request failed.');return d;});});}
function fd(action){var x=new FormData();x.append('csrf_token',csrf);x.append('action',action);return x}
function openModal(id){el(id).classList.add('open');el(id).setAttribute('aria-hidden','false')}
function closeModal(id){el(id).classList.remove('open');el(id).setAttribute('aria-hidden','true')}
document.querySelectorAll('[data-close-modal]').forEach(function(b){b.addEventListener('click',function(){closeModal(b.getAttribute('data-close-modal'))})});
document.querySelectorAll('.ps-modal-backdrop').forEach(function(m){m.addEventListener('click',function(e){if(e.target===m)closeModal(m.id)})});

function load(){var x=fd('list');Object.keys(state).forEach(function(k){if(k!=='previewToken')x.append(k,state[k])});req(x).then(render).catch(function(e){toast('error',e.message);el('itemsBody').innerHTML='<tr><td colspan="3" class="ps-empty">Unable to load products & services.</td></tr>'})}
function render(d){var rows=d.items||[],pg=d.pagination||{};state.page=Number(pg.page||1);state.pages=Number(pg.pages||1);state.total=Number(pg.total||0);var body=el('itemsBody');body.innerHTML=rows.length?rows.map(function(r){return '<tr data-key="'+esc(r.key)+'"><td><div class="name">'+esc(r.name)+'</div></td><td class="desc">'+esc(r.description||'')+'</td><td class="type">'+esc(r.type_label)+'</td></tr>'}).join(''):'<tr><td colspan="3" class="ps-empty">No products or services found.</td></tr>';body.querySelectorAll('tr[data-key]').forEach(function(tr){tr.addEventListener('click',function(){editItem(tr.getAttribute('data-key'))})});el('pageSummary').textContent=state.total?('Showing '+Number(pg.from||0)+'-'+Number(pg.to||0)+' of '+state.total+' items'):'Showing 0 items';el('prevPage').disabled=state.page<=1;el('nextPage').disabled=state.page>=state.pages;}
var searchTimer=null;el('searchInput').addEventListener('input',function(){clearTimeout(searchTimer);searchTimer=setTimeout(function(){state.search=el('searchInput').value.trim();state.page=1;load()},220)});el('clearSearch').onclick=function(){el('searchInput').value='';state.search='';state.page=1;load()};el('perPage').onchange=function(){state.per_page=Number(this.value||25);state.page=1;load()};el('prevPage').onclick=function(){if(state.page>1){state.page--;load()}};el('nextPage').onclick=function(){if(state.page<state.pages){state.page++;load()}};document.querySelectorAll('.ps-sort').forEach(function(b){b.onclick=function(){var s=b.dataset.sort;if(state.sort===s)state.dir=state.dir==='asc'?'desc':'asc';else{state.sort=s;state.dir='asc'}state.page=1;load()}});

function fillDurations(){var s=el('durationMinutes'),opts=[];for(var m=15;m<=720;m+=15){var h=Math.floor(m/60),rm=m%60,label='';if(h)label+=h+'h';if(rm)label+=(h?' ':'')+rm+'m';opts.push('<option value="'+m+'">'+label+'</option>')}s.innerHTML=opts.join('');s.value='60'}fillDurations();
function resetItem(){el('itemModalTitle').textContent='Add New Product/Service';el('saveItemBtn').textContent='Create';el('itemId').value='0';el('sourceTable').value='';el('itemType').value='service';el('itemType').disabled=false;el('itemName').value='';el('itemDescription').value='';el('unitCost').value='0.00';el('markupPercent').value='0';el('unitPrice').value='0.00';el('taxExempt').checked=false;el('durationMinutes').value='60';el('allowCustomerQuantity').checked=false;el('minQuantity').value='1';el('maxQuantity').value='10';el('qtyRange').classList.add('ps-hidden');el('onlineBookingFields').classList.remove('ps-hidden');el('itemImage').value='';el('imageFileName').textContent='Select or drag an image here to upload'}
el('addItemBtn').onclick=function(){resetItem();openModal('itemModal');setTimeout(function(){el('itemName').focus()},60)};
el('itemType').onchange=function(){el('onlineBookingFields').classList.toggle('ps-hidden',this.value!=='service')};el('allowCustomerQuantity').onchange=function(){el('qtyRange').classList.toggle('ps-hidden',!this.checked)};el('itemImage').onchange=function(){el('imageFileName').textContent=this.files&&this.files[0]?this.files[0].name:'Select or drag an image here to upload'};
['unitCost','markupPercent'].forEach(function(id){el(id).addEventListener('input',function(){var c=Number(el('unitCost').value||0),m=Number(el('markupPercent').value||0);el('unitPrice').value=(c+(c*m/100)).toFixed(2)})});
function editItem(key){var x=fd('get_item');x.append('key',key);req(x).then(function(d){var r=d.item||{};resetItem();el('itemModalTitle').textContent='Edit Product/Service';el('saveItemBtn').textContent='Save';el('itemId').value=r.id||0;el('sourceTable').value=r.source_table||'';el('itemType').value=r.item_type||'service';el('itemType').disabled=true;el('itemName').value=r.name||'';el('itemDescription').value=r.description||'';el('unitCost').value=Number(r.unit_cost||0).toFixed(2);el('markupPercent').value=Number(r.markup_percent||0).toFixed(2).replace(/\.00$/,'');el('unitPrice').value=Number(r.unit_price||0).toFixed(2);el('taxExempt').checked=Number(r.tax_percent||0)===0;el('durationMinutes').value=String(r.duration_minutes||60);el('allowCustomerQuantity').checked=Number(r.allow_customer_quantity||0)===1;el('minQuantity').value=r.min_quantity||1;el('maxQuantity').value=r.max_quantity||10;el('qtyRange').classList.toggle('ps-hidden',!el('allowCustomerQuantity').checked);el('onlineBookingFields').classList.toggle('ps-hidden',r.item_type!=='service');openModal('itemModal')}).catch(function(e){toast('error',e.message)})}
el('itemForm').onsubmit=function(e){e.preventDefault();var data=new FormData(this);if(el('itemType').disabled)data.set('item_type',el('itemType').value);var btn=el('saveItemBtn'),old=btn.textContent;btn.disabled=true;btn.textContent='Saving...';req(data).then(function(d){toast('success',d.message);closeModal('itemModal');load()}).catch(function(e){toast('error',e.message)}).finally(function(){btn.disabled=false;btn.textContent=old})};

el('costToggle').onchange=function(){localStorage.setItem('fieldplx.ps.costs.'+tenant,this.checked?'1':'0');toast('success','Cost display preference updated.')};var costPref=localStorage.getItem('fieldplx.ps.costs.'+tenant);if(costPref!==null)el('costToggle').checked=costPref==='1';

el('sampleCsvBtn').onclick=function(){window.location.href=API+'?action=sample_csv'};el('exportBtn').onclick=function(){window.location.href=API+'?action=export_csv'};
el('importBtn').onclick=function(){el('csvFile').value='';el('csvFileName').textContent='Select or drag a CSV file here';el('previewArea').classList.add('ps-hidden');el('previewBody').innerHTML='';el('commitImportBtn').disabled=true;state.previewToken='';openModal('importModal')};el('csvFile').onchange=function(){el('csvFileName').textContent=this.files&&this.files[0]?this.files[0].name:'Select or drag a CSV file here';el('commitImportBtn').disabled=true;state.previewToken=''};
el('previewCsvBtn').onclick=function(){if(!el('csvFile').files||!el('csvFile').files[0]){toast('warning','Select a CSV file first.');return}var x=new FormData();x.append('csrf_token',csrf);x.append('action','preview_import');x.append('duplicate_mode',el('duplicateMode').value);x.append('csv_file',el('csvFile').files[0]);var b=this,old=b.textContent;b.disabled=true;b.textContent='Validating...';req(x).then(function(d){state.previewToken=d.preview_token||'';renderPreview(d);toast(d.errors>0?'warning':'success',d.message)}).catch(function(e){toast('error',e.message)}).finally(function(){b.disabled=false;b.textContent=old})};
function renderPreview(d){el('previewArea').classList.remove('ps-hidden');el('pvTotal').textContent=d.total||0;el('pvValid').textContent=d.valid||0;el('pvWarnings').textContent=d.warnings||0;el('pvErrors').textContent=d.errors||0;el('previewBody').innerHTML=(d.rows||[]).map(function(r){var cls=r.status==='error'?'error':(r.status==='warning'?'warning':'');return '<tr class="'+cls+'"><td>'+esc(r.row)+'</td><td>'+esc(r.item_type)+'</td><td>'+esc(r.name)+'</td><td>'+esc(r.unit_cost)+'</td><td>'+esc(r.markup_percent)+'</td><td>'+esc(r.unit_price)+'</td><td><span class="ps-badge '+esc(r.status)+'">'+esc(r.status)+'</span></td><td>'+esc(r.message||'Ready')+'</td></tr>'}).join('');el('commitImportBtn').disabled=Number(d.errors||0)>0||Number(d.valid||0)<=0||!state.previewToken;}
el('commitImportBtn').onclick=function(){if(!state.previewToken)return;var x=fd('commit_import');x.append('preview_token',state.previewToken);var b=this,old=b.textContent;b.disabled=true;b.textContent='Importing...';req(x).then(function(d){toast('success',d.message);closeModal('importModal');load()}).catch(function(e){toast('error',e.message)}).finally(function(){b.disabled=false;b.textContent=old})};
if(window.lucide&&typeof window.lucide.createIcons==='function')window.lucide.createIcons();load();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
