<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Custom Fields · FieldPlx';
$pageDescription = 'Create reusable custom fields for customers, properties, quotes, jobs, invoices and team members';
$settingsActivePage = 'custom-fields';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['custom_fields_csrf'])) {
    $_SESSION['custom_fields_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['custom_fields_csrf'];

require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/toast.php';
?>
<style>
.cf-page{display:grid;grid-template-columns:250px minmax(0,1fr);gap:28px;max-width:1320px;margin:0 auto;padding:8px 0 44px}.cf-main{min-width:0}.cf-head{margin:0 0 16px}.cf-head h1{margin:0;color:var(--fieldplx-text,#0b1933);font-size:31px;line-height:1.15}.cf-head p{margin:9px 0 0;color:var(--fieldplx-muted,#6f7b90);font-size:14px}.cf-card{margin-bottom:22px;background:#fff;border:1px solid #dce4eb;border-radius:10px;overflow:visible}.cf-card-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 16px 10px}.cf-card-head h2{margin:0;color:#082b3a;font-size:23px}.cf-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:34px;padding:0 12px;border:1px solid #d8e0e6;border-radius:8px;background:#fff;color:#2f7f24;font:inherit;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none}.cf-btn:hover{border-color:#74b824}.cf-btn.primary{background:#2f8c25;border-color:#2f8c25;color:#fff}.cf-btn.danger{color:#d94242}.cf-btn:disabled{opacity:.55;cursor:not-allowed}.cf-list{padding-bottom:6px}.cf-row{position:relative;display:grid;grid-template-columns:30px minmax(220px,1fr) minmax(210px,1.2fr) 42px;gap:10px;align-items:center;min-height:48px;padding:5px 12px 5px 14px;border-top:1px solid #edf1f4;color:#284957}.cf-row:first-child{border-top:0}.cf-row.dragging{opacity:.42;background:#f8fbf5}.cf-drag{display:grid;place-items:center;width:26px;height:32px;border:0;background:transparent;color:#234454;cursor:grab}.cf-drag:active{cursor:grabbing}.cf-dots{font-size:20px;line-height:1;letter-spacing:-4px}.cf-name{color:#2f7f24;font-size:13px;font-weight:500;cursor:pointer}.cf-desc{font-size:12px;color:#294958}.cf-menu-wrap{position:relative;display:flex;justify-content:flex-end}.cf-menu-btn{width:34px;height:34px;border:0;background:transparent;border-radius:7px;color:#173644;font-size:20px;cursor:pointer}.cf-menu-btn:hover{background:#f3f5f6}.cf-menu{position:absolute;right:0;top:34px;z-index:100;display:none;min-width:150px;padding:5px;background:#fff;border:1px solid #dce4eb;border-radius:8px;box-shadow:0 12px 28px rgba(0,17,49,.15)}.cf-menu.open{display:block}.cf-menu button{width:100%;display:block;padding:9px 10px;border:0;border-radius:6px;background:#fff;color:#173644;text-align:left;font:inherit;font-size:12px;cursor:pointer}.cf-menu button:hover{background:#f5f7f8}.cf-menu button.danger{color:#d94242}.cf-empty{display:flex;align-items:center;gap:14px;min-height:120px;padding:12px 28px 22px}.cf-empty-icon{width:56px;height:56px;display:grid;place-items:center;flex:0 0 auto;border-radius:50%;background:#f0efeb;color:#66808e}.cf-empty-copy strong{display:block;color:#0b1933;font-size:13px}.cf-empty-copy p{margin:2px 0 7px;color:#345462;font-size:12px}.cf-schema{display:none;margin:0 0 16px;padding:11px 13px;border:1px solid #efd393;border-radius:8px;background:#fff8e7;color:#7b5b08;font-size:11px;line-height:1.45}
.cf-modal-backdrop{position:fixed;inset:0;z-index:12000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,17,49,.38);overflow:auto}.cf-modal-backdrop.open{display:flex}.cf-modal{width:min(590px,100%);margin:auto;background:#fff;border-radius:11px;box-shadow:0 24px 70px rgba(0,17,49,.24);overflow:visible}.cf-modal-head{display:flex;align-items:center;justify-content:space-between;padding:19px 24px 15px}.cf-modal-head h3{margin:0;color:#0b3443;font-size:22px}.cf-close{width:34px;height:34px;border:0;background:transparent;color:#234454;font-size:24px;cursor:pointer}.cf-modal-body{padding:0 24px 8px}.cf-modal-foot{display:flex;justify-content:flex-end;gap:9px;padding:14px 24px 20px}.cf-field{position:relative;margin-bottom:14px}.cf-input,.cf-select,.cf-textarea{width:100%;border:1px solid #d8e1e7;border-radius:8px;background:#fff;color:#173644;font:inherit;font-size:13px;outline:none}.cf-input,.cf-select{height:51px;padding:0 16px}.cf-textarea{min-height:86px;padding:13px 16px;resize:vertical}.cf-input:focus,.cf-select:focus,.cf-textarea:focus{border-color:#74b824;box-shadow:0 0 0 2px rgba(116,184,36,.12)}.cf-field.float label{position:absolute;left:16px;top:7px;z-index:2;color:#6d808a;font-size:10px;pointer-events:none}.cf-field.float .cf-input,.cf-field.float .cf-select{padding-top:14px}.cf-field.applies .cf-select{background:#efefef;color:#8b9398}.cf-check{display:flex;align-items:flex-start;gap:9px;margin:0 0 14px;color:#345462;font-size:13px}.cf-check input{width:18px;height:18px;accent-color:#2f8c25}.cf-example{margin:-3px 0 12px;color:#607682;font-size:12px}.cf-example span{display:inline-block;margin-left:4px;padding:1px 4px;border:1px solid #d8dde1;background:#f7f7f7;color:#65737b}.cf-helper{margin:0;color:#536b77;font-size:12px}.cf-helper a{color:#2f7f24;text-decoration:underline}.cf-area-label{margin:-2px 0 7px;color:#345462;font-size:12px}.cf-area-grid{display:grid;grid-template-columns:1fr 18px 1fr 1fr;align-items:center;gap:0}.cf-area-grid .cf-field{margin:0}.cf-area-grid .cf-input{border-radius:0}.cf-area-grid .cf-field:first-child .cf-input{border-radius:8px 0 0 8px}.cf-area-grid .cf-field:last-child .cf-input{border-radius:0 8px 8px 0}.cf-area-x{text-align:center;color:#6f7b90}.cf-option-list{display:flex;flex-direction:column;gap:8px}.cf-option-row{display:flex;align-items:center;gap:8px}.cf-option-row .cf-input{height:44px}.cf-option-remove{width:34px;height:34px;border:0;background:transparent;color:#d94242;font-size:20px;cursor:pointer}.cf-add-option{margin-top:8px}.cf-hidden{display:none!important}
@media(max-width:980px){.cf-page{grid-template-columns:1fr}.cf-page>aside{display:none}.cf-main{padding:0 4px}}@media(max-width:650px){.cf-card-head h2{font-size:19px}.cf-row{grid-template-columns:28px minmax(0,1fr) 36px}.cf-desc{grid-column:2/3}.cf-menu-wrap{grid-column:3;grid-row:1/3}.cf-area-grid{grid-template-columns:1fr}.cf-area-x{display:none}.cf-area-grid .cf-input,.cf-area-grid .cf-field:first-child .cf-input,.cf-area-grid .cf-field:last-child .cf-input{border-radius:8px;margin-bottom:8px}.cf-modal-body{padding-left:16px;padding-right:16px}.cf-modal-head{padding-left:16px;padding-right:16px}.cf-modal-foot{padding-left:16px;padding-right:16px}}
</style>

<div class="cf-page">
    <aside><?php require __DIR__ . '/includes/settings-nav.php'; ?></aside>
    <main class="cf-main">
        <div class="cf-head">
            <h1>Custom fields</h1>
            <p>Track additional information specific to your business with custom fields.</p>
        </div>
        <div id="schemaWarning" class="cf-schema">Run <strong>database/custom-fields-migration.sql</strong> to enable Quote, Job, Invoice and Team custom fields. Customer and Property custom fields continue to work with the existing schema.</div>
        <div id="groups"></div>
    </main>
</div>

<div class="cf-modal-backdrop" id="fieldModal" aria-hidden="true">
    <div class="cf-modal" role="dialog" aria-modal="true" aria-labelledby="fieldModalTitle">
        <form id="fieldForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="source" id="fieldSource" value="">
            <input type="hidden" name="id" id="fieldId" value="0">
            <input type="hidden" name="entity_type" id="entityType" value="client">
            <div class="cf-modal-head"><h3 id="fieldModalTitle">New Custom Field</h3><button class="cf-close" type="button" data-close-field>&times;</button></div>
            <div class="cf-modal-body">
                <div class="cf-field float applies"><label>Applies to</label><select class="cf-select" id="appliesDisplay" disabled><option>All clients</option></select></div>
                <label class="cf-check"><input type="checkbox" id="transferable" name="is_transferable" value="1"><span>Transferable field</span></label>
                <div class="cf-field"><input class="cf-input" id="fieldName" name="field_name" maxlength="190" placeholder="Custom field name" required></div>
                <div class="cf-field float"><label>Field type</label><select class="cf-select" id="fieldType" name="field_type"><option value="text">Text</option><option value="numeric">Numeric</option><option value="boolean">True/False</option><option value="area">Area (length x width)</option><option value="dropdown">Dropdown</option></select></div>
                <div id="exampleText" class="cf-example">Example: Serial Number <span>54A17-HEX</span></div>
                <div id="simpleDefault" class="cf-field"><input class="cf-input" id="defaultValue" name="default_value" placeholder="Default value"></div>
                <div id="booleanDefault" class="cf-field float cf-hidden"><label>Default value</label><select class="cf-select" id="booleanValue"><option value="">No default</option><option value="1">Yes</option><option value="0">No</option></select></div>
                <div id="areaDefault" class="cf-hidden"><div class="cf-area-label">Default values</div><div class="cf-area-grid"><div class="cf-field float"><label>Length</label><input class="cf-input" type="number" step="any" min="0" name="area_default_length" id="areaLength" value="0"></div><div class="cf-area-x">×</div><div class="cf-field float"><label>Width</label><input class="cf-input" type="number" step="any" min="0" name="area_default_width" id="areaWidth" value="0"></div><div class="cf-field float"><label>Unit</label><input class="cf-input" name="area_unit" id="areaUnit" placeholder="ft"></div></div></div>
                <div id="dropdownDefault" class="cf-hidden"><div class="cf-area-label">Options for dropdown</div><div id="optionList" class="cf-option-list"></div><button class="cf-btn cf-add-option" type="button" id="addOptionBtn">+ Add Another Option</button></div>
                <p class="cf-helper" style="margin-top:14px">All custom fields can be edited and reordered in <a href="custom-fields.php">Settings &gt; Custom Fields</a>.</p>
            </div>
            <div class="cf-modal-foot"><button class="cf-btn" type="button" data-close-field>Cancel</button><button class="cf-btn primary" type="submit" id="saveFieldBtn">Create Custom Field</button></div>
        </form>
    </div>
</div>

<script>
(function(){
'use strict';
var API='api/custom-fields.php',csrf=<?= json_encode($csrfToken) ?>;
var order=['client','property','quote','job','invoice','team'];
var labels={client:'Client',property:'Property',quote:'Quote',job:'Job',invoice:'Invoice',team:'Team'};
var plural={client:'clients',property:'properties',quote:'quotes',job:'jobs',invoice:'invoices',team:'team members'};
var icons={client:'users',property:'map-pin',quote:'file-text',job:'hammer',invoice:'receipt',team:'circle-user-round'};
var state={groups:{},dragKey:null,dragEntity:null};
function E(id){return document.getElementById(id)}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]})}
function toast(t,m){if(window.fieldplxToast)window.fieldplxToast(t,m)}
function fd(action){var x=new FormData();x.append('csrf_token',csrf);x.append('action',action);return x}
function req(data){return fetch(API,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json().catch(function(){throw new Error('Invalid server response.');}).then(function(d){if(!r.ok||!d.success)throw new Error(d.message||'Request failed.');return d;});});}
function openModal(){E('fieldModal').classList.add('open');E('fieldModal').setAttribute('aria-hidden','false')}
function closeModal(){E('fieldModal').classList.remove('open');E('fieldModal').setAttribute('aria-hidden','true');closeMenus()}
document.querySelectorAll('[data-close-field]').forEach(function(b){b.onclick=closeModal});E('fieldModal').addEventListener('click',function(e){if(e.target===E('fieldModal'))closeModal()});document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal()});
function closeMenus(){document.querySelectorAll('.cf-menu.open').forEach(function(m){m.classList.remove('open')})}
document.addEventListener('click',function(e){if(!e.target.closest('.cf-menu-wrap'))closeMenus()});
function typeDesc(t){return {text:'Stores a text value',numeric:'Stores a numeric value',boolean:'Stores a true / false value',area:'Stores an area in length × width',dropdown:'Stores one selected option'}[t]||'Stores a value'}
function emptyCopy(entity){return entity==='team'?'Keep track of user details by adding a custom field':'Keep track of '+entity+' details by adding a custom field'}
function rowHtml(f){return '<div class="cf-row" draggable="true" data-key="'+esc(f.key)+'" data-entity="'+esc(f.entity_type)+'"><button type="button" class="cf-drag" title="Drag to reorder" aria-label="Drag to reorder"><span class="cf-dots">⠿</span></button><div class="cf-name" data-edit="'+esc(f.key)+'">'+esc(f.field_name)+'</div><div class="cf-desc">'+esc(f.type_description||typeDesc(f.field_type))+'</div><div class="cf-menu-wrap"><button class="cf-menu-btn" type="button" aria-label="Field actions">•••</button><div class="cf-menu"><button type="button" data-edit="'+esc(f.key)+'">Edit</button><button type="button" class="danger" data-delete="'+esc(f.key)+'">Delete</button></div></div></div>'}
function cardHtml(entity,rows){var title=labels[entity]+' custom fields';var body=rows.length?'<div class="cf-list">'+rows.map(rowHtml).join('')+'</div>':'<div class="cf-empty"><div class="cf-empty-icon"><i data-lucide="'+icons[entity]+'"></i></div><div class="cf-empty-copy"><strong>No custom fields</strong><p>'+esc(emptyCopy(entity))+'</p><button class="cf-btn" type="button" data-add="'+entity+'">Add Field</button></div></div>';return '<section class="cf-card" data-group="'+entity+'"><div class="cf-card-head"><h2>'+esc(title)+'</h2><button class="cf-btn" type="button" data-add="'+entity+'">Add Field</button></div>'+body+'</section>'}
function render(){E('groups').innerHTML=order.map(function(entity){return cardHtml(entity,state.groups[entity]||[])}).join('');wire();if(window.lucide&&window.lucide.createIcons)window.lucide.createIcons()}
function wire(){document.querySelectorAll('[data-add]').forEach(function(b){b.onclick=function(){newField(b.getAttribute('data-add'))}});document.querySelectorAll('[data-edit]').forEach(function(b){b.onclick=function(){editField(b.getAttribute('data-edit'))}});document.querySelectorAll('[data-delete]').forEach(function(b){b.onclick=function(){deleteField(b.getAttribute('data-delete'))}});document.querySelectorAll('.cf-menu-btn').forEach(function(b){b.onclick=function(e){e.stopPropagation();var m=b.nextElementSibling,was=m.classList.contains('open');closeMenus();if(!was)m.classList.add('open')}});document.querySelectorAll('.cf-row').forEach(function(row){row.addEventListener('dragstart',dragStart);row.addEventListener('dragover',dragOver);row.addEventListener('drop',dragDrop);row.addEventListener('dragend',dragEnd)})}
function load(){var x=fd('list');req(x).then(function(d){state.groups=d.groups||{};E('schemaWarning').style.display=d.schema&&d.schema.workflow_ready?'none':'block';render()}).catch(function(e){toast('error',e.message)})}
function parseKey(key){var p=String(key||'').split(':');return {source:p[0]||'',id:Number(p[1]||0)}}
function resetForm(entity){E('fieldForm').reset();E('fieldSource').value='';E('fieldId').value='0';E('entityType').value=entity;E('appliesDisplay').innerHTML='<option>All '+esc(plural[entity])+'</option>';E('fieldModalTitle').textContent='New Custom Field';E('saveFieldBtn').textContent='Create Custom Field';E('fieldType').value='text';E('areaLength').value='0';E('areaWidth').value='0';E('areaUnit').value='';E('optionList').innerHTML='';addOption('Option 1');addOption('Option 2');syncType()}
function newField(entity){resetForm(entity);openModal();setTimeout(function(){E('fieldName').focus()},50)}
function editField(key){closeMenus();var p=parseKey(key),x=fd('get');x.append('source',p.source);x.append('id',p.id);req(x).then(function(d){var f=d.field||{};resetForm(f.entity_type||'client');E('fieldSource').value=f.source||p.source;E('fieldId').value=f.id||p.id;E('fieldModalTitle').textContent='Edit Custom Field';E('saveFieldBtn').textContent='Save Changes';E('fieldName').value=f.field_name||'';E('transferable').checked=Number(f.is_transferable||0)===1;E('fieldType').value=f.field_type||'text';E('defaultValue').value=f.default_value||'';E('booleanValue').value=f.default_value===''?'':String(f.default_value);E('areaLength').value=f.area_default_length==null?'0':f.area_default_length;E('areaWidth').value=f.area_default_width==null?'0':f.area_default_width;E('areaUnit').value=f.area_unit||'';E('optionList').innerHTML='';(f.options&&f.options.length?f.options:['Option 1','Option 2']).forEach(addOption);syncType();openModal();}).catch(function(e){toast('error',e.message)})}
function deleteField(key){closeMenus();if(!window.confirm('Delete this custom field? Existing saved values will remain in historical records, but the field will no longer be available for new use.'))return;var p=parseKey(key),x=fd('delete');x.append('source',p.source);x.append('id',p.id);req(x).then(function(d){toast('success',d.message);load()}).catch(function(e){toast('error',e.message)})}
function addOption(value){var row=document.createElement('div');row.className='cf-option-row';row.innerHTML='<input class="cf-input" name="options[]" maxlength="190" placeholder="Option" value="'+esc(value||'')+'"><button class="cf-option-remove" type="button" title="Remove option">×</button>';row.querySelector('button').onclick=function(){if(E('optionList').children.length<=1){row.querySelector('input').value='';return}row.remove()};E('optionList').appendChild(row)}
E('addOptionBtn').onclick=function(){addOption('');var rows=E('optionList').querySelectorAll('input');if(rows.length)rows[rows.length-1].focus()};
function syncType(){var t=E('fieldType').value;E('simpleDefault').classList.toggle('cf-hidden',!(t==='text'||t==='numeric'));E('booleanDefault').classList.toggle('cf-hidden',t!=='boolean');E('areaDefault').classList.toggle('cf-hidden',t!=='area');E('dropdownDefault').classList.toggle('cf-hidden',t!=='dropdown');var e=E('exampleText');if(t==='text')e.innerHTML='Example: Serial Number <span>54A17-HEX</span>';else if(t==='numeric')e.innerHTML='Example: Number of rooms <span>4</span>';else if(t==='boolean')e.innerHTML='Example: Dog? <span>Yes / No</span>';else if(t==='area')e.innerHTML='Example: Yard Size <span>50</span> × <span>75</span> ft';else e.innerHTML='Example: Priority <span>High</span>';E('defaultValue').type=t==='numeric'?'number':'text';}
E('fieldType').onchange=syncType;E('booleanValue').onchange=function(){E('defaultValue').value=this.value};
E('fieldForm').onsubmit=function(e){e.preventDefault();if(E('fieldType').value==='boolean')E('defaultValue').value=E('booleanValue').value;var data=new FormData(this),btn=E('saveFieldBtn'),old=btn.textContent;btn.disabled=true;btn.textContent='Saving...';req(data).then(function(d){toast('success',d.message);closeModal();load()}).catch(function(err){toast('error',err.message)}).then(function(){btn.disabled=false;btn.textContent=old})};
function dragStart(e){var row=e.currentTarget;state.dragKey=row.dataset.key;state.dragEntity=row.dataset.entity;row.classList.add('dragging');if(e.dataTransfer){e.dataTransfer.effectAllowed='move';try{e.dataTransfer.setData('text/plain',state.dragKey)}catch(_){}}}
function dragOver(e){e.preventDefault();var target=e.currentTarget;if(target.dataset.entity!==state.dragEntity)return;var box=target.getBoundingClientRect(),after=(e.clientY-box.top)>box.height/2,drag=document.querySelector('.cf-row.dragging');if(!drag||drag===target)return;target.parentNode.insertBefore(drag,after?target.nextSibling:target)}
function dragDrop(e){e.preventDefault()}
function dragEnd(e){e.currentTarget.classList.remove('dragging');var group=document.querySelector('[data-group="'+state.dragEntity+'"] .cf-list');if(!group){state.dragKey=null;state.dragEntity=null;return}var keys=Array.prototype.map.call(group.querySelectorAll('.cf-row'),function(r){return r.dataset.key}),x=fd('reorder');x.append('entity_type',state.dragEntity);keys.forEach(function(k){x.append('keys[]',k)});req(x).then(function(d){toast('success',d.message);load()}).catch(function(err){toast('error',err.message);load()});state.dragKey=null;state.dragEntity=null}
load();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
