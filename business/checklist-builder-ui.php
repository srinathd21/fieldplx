<?php
if (!isset($builderMode)) $builderMode = 'create';
if (!isset($checklistId)) $checklistId = 0;

$tenantId = isset($currentTenantId)
    ? (int)$currentTenantId
    : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);

if (empty($_SESSION['checklists_csrf'])) {
    $_SESSION['checklists_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['checklists_csrf'];

require __DIR__ . '/includes/header.php';
if (file_exists(__DIR__ . '/includes/toast.php')) {
    require_once __DIR__ . '/includes/toast.php';
}
?>
<style>
/* Full-screen checklist builder. It deliberately covers the normal app navigation. */
body{overflow:hidden!important}
.ckb-shell{
    position:fixed;
    inset:0;
    z-index:99999;
    width:100vw;
    height:100vh;
    overflow:hidden;
    background:#f2f1ed;
    color:#0b2f40;
}
.ckb-top{
    height:68px;
    display:grid;
    grid-template-columns:minmax(250px,1fr) minmax(360px,440px) minmax(300px,1fr);
    align-items:center;
    gap:18px;
    padding:0 20px 0 24px;
    border-bottom:1px solid #dce3e7;
    background:#fff;
}
.ckb-top-title{
    min-width:0;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    margin:0;
    color:#0b2f40;
    font-size:19px;
    line-height:1.2;
    font-weight:700;
}
.ckb-tabs{
    height:34px;
    display:grid;
    grid-template-columns:1fr 1fr;
    overflow:hidden;
    border:1px solid #d8e0e5;
    border-radius:7px;
    background:#fff;
}
.ckb-tab{
    border:0;
    background:#fff;
    color:#49616e;
    font:inherit;
    font-size:10px;
    font-weight:700;
    cursor:pointer;
}
.ckb-tab.active{
    margin:-1px;
    border:1px solid #4a982f;
    border-radius:7px;
    color:#327d26;
    background:#fff;
}
.ckb-top-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:7px;
}
.ckb-icon-btn,.ckb-btn{
    height:34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    border:1px solid #d8e0e5;
    border-radius:7px;
    background:#fff;
    color:#244657;
    font:inherit;
    font-size:10px;
    font-weight:700;
    text-decoration:none;
    cursor:pointer;
}
.ckb-icon-btn{width:38px;padding:0}
.ckb-icon-btn svg,.ckb-btn svg{width:15px;height:15px}
.ckb-icon-btn.active{
    border-color:#4a982f;
    color:#2f8c25;
    background:#fbfff9;
}
.ckb-btn{padding:0 13px}
.ckb-btn.primary{
    border-color:#2f8c25;
    background:#2f8c25;
    color:#fff;
}
.ckb-btn.primary:hover{background:#287b20}
.ckb-btn:disabled{opacity:.6;cursor:not-allowed}
.ckb-work{
    height:calc(100vh - 68px);
    display:grid;
    grid-template-columns:minmax(0,1fr) 350px;
    min-height:0;
}
.ckb-canvas-wrap{
    min-width:0;
    overflow:auto;
    padding:14px 26px 48px;
}
.ckb-paper{
    width:min(100%,920px);
    min-height:250px;
    margin:0 auto;
    padding:14px;
    border-radius:9px;
    background:#fff;
    box-shadow:0 1px 2px rgba(11,25,51,.04);
}
.ckb-section{
    overflow:hidden;
    margin-bottom:12px;
    border:1px solid #d8e1e6;
    border-radius:7px;
    background:#fff;
}
.ckb-section:last-child{margin-bottom:0}
.ckb-section-head{
    min-height:67px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:12px 14px;
    border-bottom:1px solid #e7ecef;
    background:#fff;
}
.ckb-section-title{
    width:min(420px,75%);
    height:35px;
    padding:0 4px;
    border:1px solid transparent;
    border-radius:6px;
    outline:0;
    background:transparent;
    color:#0b2f40;
    font:inherit;
    font-size:12px;
    font-weight:700;
}
.ckb-section-title:hover,.ckb-section-title:focus{
    padding:0 9px;
    border-color:#d8e1e6;
    background:#fff;
}
.ckb-more{
    width:35px;
    height:33px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid transparent;
    border-radius:6px;
    background:#fff;
    color:#31505f;
    cursor:pointer;
}
.ckb-more:hover{border-color:#d8e1e6;background:#f8fafb}
.ckb-more svg{width:17px;height:17px}
.ckb-question{
    padding:13px 14px 14px;
    border-bottom:1px solid #e4e9ec;
    background:#f4f3ef;
}
.ckb-question.first{background:#fff}
.ckb-label{
    display:block;
    margin-bottom:6px;
    color:#526d7c;
    font-size:9px;
}
.ckb-q-row{
    display:grid;
    grid-template-columns:minmax(0,1fr) 170px;
    gap:8px;
}
.ckb-input,.ckb-select,.ckb-textarea{
    width:100%;
    border:1px solid #d6e0e6;
    border-radius:7px;
    background:#fff;
    color:#173846;
    font:inherit;
    font-size:11px;
    outline:0;
}
.ckb-input,.ckb-select{height:40px;padding:0 11px}
.ckb-textarea{min-height:78px;padding:10px 11px;resize:vertical}
.ckb-input:focus,.ckb-select:focus,.ckb-textarea:focus{
    border-color:#6b8b99;
    box-shadow:0 0 0 1px #6b8b99;
}
.ckb-options{
    display:grid;
    gap:7px;
    margin-top:8px;
}
.ckb-option-row{
    display:grid;
    grid-template-columns:minmax(0,1fr) 34px;
    gap:7px;
}
.ckb-option-row input{
    height:34px;
    padding:0 9px;
    border:1px solid #d6e0e6;
    border-radius:6px;
    color:#173846;
    font:inherit;
    font-size:10px;
    outline:0;
}
.ckb-option-row input:focus{border-color:#6b8b99}
.ckb-option-add{
    height:34px;
    border:1px solid #d6e0e6;
    border-radius:6px;
    background:#fff;
    color:#31505f;
    font:inherit;
    font-size:10px;
    font-weight:600;
    cursor:pointer;
}
.ckb-q-tools{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-top:9px;
}
.ckb-required{
    display:flex;
    align-items:center;
    gap:7px;
    color:#31505f;
    font-size:10px;
}
.ckb-required input{width:14px;height:14px;accent-color:#2f8c25}
.ckb-delete{
    width:34px;
    height:32px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid #eadfe0;
    border-radius:6px;
    background:#fff;
    color:#d84b45;
    cursor:pointer;
}
.ckb-delete:hover{background:#fff7f7}
.ckb-delete svg{width:15px;height:15px}
.ckb-add-question{
    width:100%;
    height:43px;
    border:0;
    background:#fff;
    color:#294b5b;
    font:inherit;
    font-size:10px;
    font-weight:700;
    cursor:pointer;
}
.ckb-add-question:hover{background:#fbfcfc}
.ckb-side{
    height:calc(100vh - 68px);
    overflow:auto;
    padding:20px;
    border-left:1px solid #dce3e7;
    background:#fff;
}
.ckb-side h2{
    margin:0 0 20px;
    color:#0b2f40;
    font-size:17px;
}
.ckb-side h3{
    margin:21px 0 8px;
    color:#123849;
    font-size:12px;
}
.ckb-help{
    margin:0 0 8px;
    color:#687e8a;
    font-size:9px;
    line-height:1.45;
}
.ckb-field-label{
    display:block;
    margin-bottom:5px;
    color:#617986;
    font-size:9px;
}
.ckb-title-input{
    width:100%;
    height:44px;
    padding:0 11px;
    border:1px solid #d6e0e6;
    border-radius:7px;
    color:#173846;
    font:inherit;
    font-size:11px;
    outline:0;
}
.ckb-title-input:focus{border-color:#6b8b99;box-shadow:0 0 0 1px #6b8b99}
.ckb-toggle-row{
    min-height:40px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    color:#294b5b;
    font-size:10px;
}
.ckb-toggle{
    position:relative;
    width:40px;
    height:22px;
    flex:0 0 auto;
}
.ckb-toggle input{width:0;height:0;opacity:0}
.ckb-track{
    position:absolute;
    inset:0;
    border-radius:999px;
    background:#d7e0e5;
    cursor:pointer;
    transition:.18s;
}
.ckb-track:after{
    content:"";
    position:absolute;
    top:3px;
    left:3px;
    width:16px;
    height:16px;
    border-radius:50%;
    background:#fff;
    box-shadow:0 1px 2px rgba(0,0,0,.16);
    transition:.18s;
}
.ckb-toggle input:checked+.ckb-track{background:#2f8c25}
.ckb-toggle input:checked+.ckb-track:after{transform:translateX(18px)}
.ckb-tip{
    margin-top:10px;
    padding:12px;
    border-radius:7px;
    background:#f0efeb;
    color:#3d5865;
    font-size:10px;
    line-height:1.45;
}
.ckb-palette{
    display:grid;
    gap:4px;
}
.ckb-palette button{
    min-height:42px;
    display:flex;
    align-items:center;
    gap:9px;
    padding:4px 6px;
    border:1px solid transparent;
    border-radius:7px;
    background:#fff;
    color:#294b5b;
    font:inherit;
    font-size:10px;
    text-align:left;
    cursor:pointer;
}
.ckb-palette button:hover{
    border-color:#dce3e7;
    background:#fafbfb;
}
.ckb-palette-icon{
    width:32px;
    height:32px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    flex:0 0 32px;
    border-radius:7px;
    background:#f0efeb;
    color:#31505f;
}
.ckb-palette-icon svg{width:15px;height:15px}
.ckb-preview{
    display:none;
    height:calc(100vh - 68px);
    overflow:auto;
    padding:18px 28px 50px;
}
.ckb-preview-card{
    width:min(100%,900px);
    margin:0 auto;
    padding:22px;
    border-radius:9px;
    background:#fff;
    box-shadow:0 1px 2px rgba(11,25,51,.04);
}
.ckb-preview-card h2{margin:0 0 24px;color:#0b2f40;font-size:19px}
.ckb-prev-section{margin-bottom:24px}
.ckb-prev-section h3{margin:0 0 18px;color:#0b2f40;font-size:11px}
.ckb-prev-q{margin:16px 0}
.ckb-prev-q>label{display:block;margin-bottom:6px;color:#173846;font-size:10px;font-weight:700}
.ckb-shell.mobile .ckb-work{grid-template-columns:1fr}
.ckb-shell.mobile .ckb-side{display:none}
.ckb-shell.mobile .ckb-paper,.ckb-shell.mobile .ckb-preview-card{max-width:430px}
.ckb-saving{
    display:inline-flex;
    align-items:center;
    gap:6px;
}
.ckb-spinner{
    width:12px;height:12px;border:2px solid rgba(255,255,255,.55);border-top-color:#fff;border-radius:50%;
    animation:ckbspin .7s linear infinite
}
@keyframes ckbspin{to{transform:rotate(360deg)}}
@media(max-width:900px){
    .ckb-top{
        height:auto;
        min-height:68px;
        grid-template-columns:1fr;
        gap:8px;
        padding:10px 12px;
    }
    .ckb-top-actions{justify-content:flex-start}
    .ckb-work{height:calc(100vh - 150px);grid-template-columns:1fr}
    .ckb-side{height:auto;max-height:none;border-left:0}
    .ckb-canvas-wrap{padding:12px}
    .ckb-q-row{grid-template-columns:1fr}
}
</style>

<div class="ckb-shell" id="builderShell">
    <header class="ckb-top">
        <h1 class="ckb-top-title" id="topTitle"><?= $builderMode === 'edit' ? 'Edit Checklist' : 'New Checklist' ?></h1>

        <div class="ckb-tabs">
            <button class="ckb-tab" type="button" id="previewTab">Preview</button>
            <button class="ckb-tab active" type="button" id="editTab">Edit</button>
        </div>

        <div class="ckb-top-actions">
            <button class="ckb-icon-btn" type="button" id="phoneView" title="Phone preview" aria-label="Phone preview">
                <i data-lucide="smartphone"></i>
            </button>
            <button class="ckb-icon-btn active" type="button" id="desktopView" title="Desktop preview" aria-label="Desktop preview">
                <i data-lucide="monitor"></i>
            </button>
            <a class="ckb-btn" href="checklists.php">Cancel</a>
            <button class="ckb-btn primary" id="saveChecklist" type="button">
                <i data-lucide="save"></i><span>Save</span>
            </button>
        </div>
    </header>

    <div class="ckb-work" id="editView">
        <div class="ckb-canvas-wrap">
            <div class="ckb-paper" id="canvas"></div>
        </div>

        <aside class="ckb-side">
            <h2>Manage checklist</h2>

            <label class="ckb-field-label" for="checklistName">Form title</label>
            <input class="ckb-title-input" id="checklistName" value="New checklist" maxlength="190">

            <div class="ckb-toggle-row">
                <span>Automatically attach to new jobs</span>
                <label class="ckb-toggle">
                    <input type="checkbox" id="autoJobs">
                    <span class="ckb-track"></span>
                </label>
            </div>

            <div class="ckb-toggle-row">
                <span>Automatically attach to new assessments</span>
                <label class="ckb-toggle">
                    <input type="checkbox" id="autoAssessments">
                    <span class="ckb-track"></span>
                </label>
            </div>

            <div class="ckb-tip">Auto-attach commonly used checklists to save time.</div>

            <h3>Checklist contents</h3>
            <p class="ckb-help">Layout options</p>
            <div class="ckb-palette">
                <button type="button" data-add-section>
                    <span class="ckb-palette-icon"><i data-lucide="square-plus"></i></span>
                    Add section
                </button>
            </div>

            <h3>Custom questions</h3>
            <p class="ckb-help">Select the type of question you'd like to ask</p>
            <div class="ckb-palette">
                <button type="button" data-qtype="short_answer"><span class="ckb-palette-icon"><i data-lucide="align-left"></i></span>Short answer</button>
                <button type="button" data-qtype="long_answer"><span class="ckb-palette-icon"><i data-lucide="rows-3"></i></span>Long answer</button>
                <button type="button" data-qtype="dropdown"><span class="ckb-palette-icon"><i data-lucide="circle-chevron-down"></i></span>Dropdown (single choice)</button>
                <button type="button" data-qtype="checkbox"><span class="ckb-palette-icon"><i data-lucide="square-check-big"></i></span>Checkbox</button>
                <button type="button" data-qtype="number"><span class="ckb-palette-icon"><i data-lucide="hash"></i></span>Numerical answer</button>
                <button type="button" data-qtype="image"><span class="ckb-palette-icon"><i data-lucide="image-plus"></i></span>Upload images</button>
                <button type="button" data-qtype="date"><span class="ckb-palette-icon"><i data-lucide="calendar-days"></i></span>Date picker</button>
                <button type="button" data-qtype="signature"><span class="ckb-palette-icon"><i data-lucide="pen-line"></i></span>Signature</button>
            </div>
        </aside>
    </div>

    <div class="ckb-preview" id="previewView">
        <div class="ckb-preview-card" id="previewCard"></div>
    </div>
</div>

<script>
(function(window,document){
'use strict';

var api='checklists.php?ajax=1';
var csrf=<?= json_encode($csrfToken) ?>;
var builderMode=<?= json_encode($builderMode) ?>;
var checklistId=<?= (int)$checklistId ?>;

var state={
    id:checklistId,
    name:'New checklist',
    auto_attach_jobs:0,
    auto_attach_assessments:0,
    sections:[
        {title:'Section 1',questions:[{title:'Question',type:'short_answer',required:0,options:[]}]}
    ]
};

var canvas=document.getElementById('canvas');
var nameInput=document.getElementById('checklistName');
var saveBtn=document.getElementById('saveChecklist');

function esc(v){
    return String(v==null?'':v)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}
function toast(type,message){
    if(typeof window.fieldplxToast==='function') window.fieldplxToast(type,message);
    else if(type==='error') window.alert(message);
}
function refreshIcons(){
    if(window.lucide&&typeof window.lucide.createIcons==='function') window.lucide.createIcons();
}
function typeLabel(type){
    return {
        short_answer:'Short answer',
        long_answer:'Long answer',
        dropdown:'Dropdown',
        checkbox:'Checkbox',
        number:'Numerical answer',
        image:'Upload images',
        date:'Date picker',
        signature:'Signature'
    }[type]||type;
}
function syncStateFromControls(){
    state.name=(nameInput.value||'').trim()||'New checklist';
    state.auto_attach_jobs=document.getElementById('autoJobs').checked?1:0;
    state.auto_attach_assessments=document.getElementById('autoAssessments').checked?1:0;
}
function questionTypeOptions(current){
    return ['short_answer','long_answer','dropdown','checkbox','number','image','date','signature']
        .map(function(type){
            return '<option value="'+type+'"'+(current===type?' selected':'')+'>'+esc(typeLabel(type))+'</option>';
        }).join('');
}
function render(){
    syncStateFromControls();

    canvas.innerHTML=state.sections.map(function(section,sectionIndex){
        var questions=section.questions.map(function(question,questionIndex){
            var optionHtml='';
            if(question.type==='dropdown'||question.type==='checkbox'){
                optionHtml='<div class="ckb-options">'+(question.options||[]).map(function(option,optionIndex){
                    return '<div class="ckb-option-row">'+
                        '<input value="'+esc(option)+'" data-option="'+sectionIndex+':'+questionIndex+':'+optionIndex+'" aria-label="Option '+(optionIndex+1)+'">'+
                        '<button type="button" class="ckb-delete" data-remove-option="'+sectionIndex+':'+questionIndex+':'+optionIndex+'" title="Remove option"><i data-lucide="x"></i></button>'+
                    '</div>';
                }).join('')+
                '<button class="ckb-option-add" type="button" data-add-option="'+sectionIndex+':'+questionIndex+'">Add option</button></div>';
            }

            return '<div class="ckb-question'+(questionIndex===0?' first':'')+'">'+
                '<span class="ckb-label">Question</span>'+
                '<div class="ckb-q-row">'+
                    '<input class="ckb-input" value="'+esc(question.title)+'" data-question="'+sectionIndex+':'+questionIndex+'" maxlength="255">'+
                    '<select class="ckb-select" data-type="'+sectionIndex+':'+questionIndex+'">'+questionTypeOptions(question.type)+'</select>'+
                '</div>'+
                optionHtml+
                '<div class="ckb-q-tools">'+
                    '<label class="ckb-required">Required <input type="checkbox" data-required="'+sectionIndex+':'+questionIndex+'" '+(question.required?'checked':'')+'></label>'+
                    '<button class="ckb-delete" type="button" data-remove-question="'+sectionIndex+':'+questionIndex+'" title="Delete question"><i data-lucide="trash-2"></i></button>'+
                '</div>'+
            '</div>';
        }).join('');

        return '<section class="ckb-section" data-section="'+sectionIndex+'">'+
            '<div class="ckb-section-head">'+
                '<input class="ckb-section-title" value="'+esc(section.title)+'" data-section-title="'+sectionIndex+'" maxlength="190">'+
                '<button class="ckb-more" type="button" data-remove-section="'+sectionIndex+'" title="Remove section"><i data-lucide="more-horizontal"></i></button>'+
            '</div>'+
            questions+
            '<button class="ckb-add-question" type="button" data-add-question="'+sectionIndex+'">+ Add Question</button>'+
        '</section>';
    }).join('');

    refreshIcons();
}
function renderPreview(){
    syncStateFromControls();

    var html='<h2>'+esc(state.name)+'</h2>';
    state.sections.forEach(function(section){
        html+='<div class="ckb-prev-section"><h3>'+esc(section.title)+'</h3>';

        section.questions.forEach(function(question){
            html+='<div class="ckb-prev-q"><label>'+esc(question.title)+(question.required?' *':'')+'</label>';

            if(question.type==='long_answer'){
                html+='<textarea class="ckb-textarea" disabled></textarea>';
            }else if(question.type==='dropdown'){
                html+='<select class="ckb-select" disabled><option>Choose an option</option>'+
                    (question.options||[]).map(function(option){return '<option>'+esc(option)+'</option>';}).join('')+
                '</select>';
            }else if(question.type==='checkbox'){
                html+=(question.options||[]).map(function(option){
                    return '<label style="display:flex;align-items:center;gap:7px;font-size:10px;margin:7px 0;font-weight:400"><input type="checkbox" disabled> '+esc(option)+'</label>';
                }).join('');
            }else if(question.type==='image'){
                html+='<button class="ckb-btn" type="button" disabled><i data-lucide="image-plus"></i>Upload images</button>';
            }else if(question.type==='signature'){
                html+='<div class="ckb-textarea" style="min-height:90px"></div>';
            }else{
                html+='<input class="ckb-input" type="'+(question.type==='number'?'number':question.type==='date'?'date':'text')+'" disabled>';
            }

            html+='</div>';
        });

        html+='</div>';
    });

    document.getElementById('previewCard').innerHTML=html;
    refreshIcons();
}
function addQuestion(type,sectionIndex){
    var si=(sectionIndex===undefined||sectionIndex===null)?state.sections.length-1:sectionIndex;

    if(si<0){
        state.sections.push({title:'Section 1',questions:[]});
        si=0;
    }

    state.sections[si].questions.push({
        title:'Question',
        type:type||'short_answer',
        required:0,
        options:(type==='dropdown'||type==='checkbox')?['Option 1','Option 2']:[]
    });

    render();
}
function setSaving(saving){
    saveBtn.disabled=!!saving;
    if(saving){
        saveBtn.innerHTML='<span class="ckb-saving"><span class="ckb-spinner"></span>Saving...</span>';
    }else{
        saveBtn.innerHTML='<i data-lucide="save"></i><span>Save</span>';
        refreshIcons();
    }
}
function parseResponse(response){
    return response.text().then(function(raw){
        var data;
        try{
            data=raw?JSON.parse(raw):{};
        }catch(error){
            throw new Error('Checklist save endpoint returned an invalid response.');
        }

        if(!response.ok||!data.success){
            throw new Error(data.message||'Unable to save checklist.');
        }

        return data;
    });
}

document.addEventListener('input',function(event){
    var el=event.target;

    if(el===nameInput){
        state.name=el.value;
        return;
    }

    if(el.hasAttribute('data-section-title')){
        state.sections[Number(el.getAttribute('data-section-title'))].title=el.value;
        return;
    }

    var questionRef=el.getAttribute('data-question');
    if(questionRef){
        var parts=questionRef.split(':');
        state.sections[Number(parts[0])].questions[Number(parts[1])].title=el.value;
        return;
    }

    var optionRef=el.getAttribute('data-option');
    if(optionRef){
        var op=optionRef.split(':');
        state.sections[Number(op[0])].questions[Number(op[1])].options[Number(op[2])]=el.value;
    }
});

document.addEventListener('change',function(event){
    var el=event.target;

    var typeRef=el.getAttribute('data-type');
    if(typeRef){
        var p=typeRef.split(':');
        var question=state.sections[Number(p[0])].questions[Number(p[1])];
        question.type=el.value;

        if((question.type==='dropdown'||question.type==='checkbox')&&(!question.options||!question.options.length)){
            question.options=['Option 1','Option 2'];
        }
        render();
        return;
    }

    var requiredRef=el.getAttribute('data-required');
    if(requiredRef){
        var r=requiredRef.split(':');
        state.sections[Number(r[0])].questions[Number(r[1])].required=el.checked?1:0;
    }
});

document.addEventListener('click',function(event){
    var addQuestionBtn=event.target.closest('[data-add-question]');
    if(addQuestionBtn){
        addQuestion('short_answer',Number(addQuestionBtn.getAttribute('data-add-question')));
        return;
    }

    var paletteBtn=event.target.closest('[data-qtype]');
    if(paletteBtn){
        addQuestion(paletteBtn.getAttribute('data-qtype'));
        return;
    }

    if(event.target.closest('[data-add-section]')){
        state.sections.push({
            title:'Section '+(state.sections.length+1),
            questions:[{title:'Question',type:'short_answer',required:0,options:[]}]
        });
        render();
        return;
    }

    var removeQuestion=event.target.closest('[data-remove-question]');
    if(removeQuestion){
        var rq=removeQuestion.getAttribute('data-remove-question').split(':');
        state.sections[Number(rq[0])].questions.splice(Number(rq[1]),1);
        render();
        return;
    }

    var removeSection=event.target.closest('[data-remove-section]');
    if(removeSection){
        if(state.sections.length===1){
            toast('error','At least one section is required.');
            return;
        }
        if(window.confirm('Remove this section and all of its questions?')){
            state.sections.splice(Number(removeSection.getAttribute('data-remove-section')),1);
            render();
        }
        return;
    }

    var addOption=event.target.closest('[data-add-option]');
    if(addOption){
        var ao=addOption.getAttribute('data-add-option').split(':');
        var options=state.sections[Number(ao[0])].questions[Number(ao[1])].options;
        options.push('Option '+(options.length+1));
        render();
        return;
    }

    var removeOption=event.target.closest('[data-remove-option]');
    if(removeOption){
        var ro=removeOption.getAttribute('data-remove-option').split(':');
        state.sections[Number(ro[0])].questions[Number(ro[1])].options.splice(Number(ro[2]),1);
        render();
    }
});

document.getElementById('previewTab').addEventListener('click',function(){
    renderPreview();
    document.getElementById('editView').style.display='none';
    document.getElementById('previewView').style.display='block';
    this.classList.add('active');
    document.getElementById('editTab').classList.remove('active');
});

document.getElementById('editTab').addEventListener('click',function(){
    document.getElementById('editView').style.display='grid';
    document.getElementById('previewView').style.display='none';
    this.classList.add('active');
    document.getElementById('previewTab').classList.remove('active');
});

document.getElementById('phoneView').addEventListener('click',function(){
    document.getElementById('builderShell').classList.add('mobile');
    this.classList.add('active');
    document.getElementById('desktopView').classList.remove('active');
});

document.getElementById('desktopView').addEventListener('click',function(){
    document.getElementById('builderShell').classList.remove('mobile');
    this.classList.add('active');
    document.getElementById('phoneView').classList.remove('active');
});

saveBtn.addEventListener('click',function(){
    if(saveBtn.disabled) return;

    syncStateFromControls();

    var items=[];
    state.sections.forEach(function(section){
        section.questions.forEach(function(question){
            var title=(question.title||'').trim();
            if(!title) return;

            items.push({
                section_title:(section.title||'Section').trim()||'Section',
                title:title,
                question_type:question.type,
                is_required:question.required?1:0,
                options:question.options||[]
            });
        });
    });

    if(!state.name.trim()){
        toast('error','Checklist title is required.');
        nameInput.focus();
        return;
    }

    if(!items.length){
        toast('error','Add at least one question.');
        return;
    }

    var form=new FormData();
    form.append('action','save');
    form.append('csrf_token',csrf);
    form.append('id',state.id||0);
    form.append('name',state.name);
    form.append('auto_attach_jobs',state.auto_attach_jobs);
    form.append('auto_attach_assessments',state.auto_attach_assessments);
    form.append('items_json',JSON.stringify(items));

    setSaving(true);

    fetch(api,{
        method:'POST',
        body:form,
        credentials:'same-origin',
        headers:{
            'X-Requested-With':'XMLHttpRequest',
            'Accept':'application/json'
        }
    })
    .then(parseResponse)
    .then(function(data){
        toast('success',builderMode==='edit'?'Checklist updated successfully.':'Checklist created successfully.');
        /* One click saves once, then returns to Manage Checklists. */
        window.setTimeout(function(){
            window.location.href='checklists.php';
        },350);
    })
    .catch(function(error){
        setSaving(false);
        toast('error',error.message);
    });
});

function loadExistingChecklist(){
    if(builderMode!=='edit'||!checklistId){
        document.getElementById('topTitle').textContent='New Checklist';
        nameInput.value=state.name;
        render();
        return;
    }

    var form=new FormData();
    form.append('action','get');
    form.append('id',checklistId);

    fetch(api,{
        method:'POST',
        body:form,
        credentials:'same-origin',
        headers:{
            'X-Requested-With':'XMLHttpRequest',
            'Accept':'application/json'
        }
    })
    .then(parseResponse)
    .then(function(data){
        var checklist=data.checklist||{};

        state.id=Number(checklist.id||0);
        state.name=checklist.name||'Checklist';
        state.auto_attach_jobs=Number(checklist.auto_attach_jobs||0);
        state.auto_attach_assessments=Number(checklist.auto_attach_assessments||0);

        var grouped={};
        var order=[];

        (checklist.items||[]).forEach(function(item){
            var sectionName=item.section_title||'Section 1';

            if(!grouped[sectionName]){
                grouped[sectionName]=[];
                order.push(sectionName);
            }

            grouped[sectionName].push({
                title:item.title||'Question',
                type:item.question_type||'short_answer',
                required:Number(item.is_required||0),
                options:Array.isArray(item.options)?item.options:[]
            });
        });

        state.sections=order.length
            ? order.map(function(name){return {title:name,questions:grouped[name]};})
            : [{title:'Section 1',questions:[{title:'Question',type:'short_answer',required:0,options:[]}]}];

        nameInput.value=state.name;
        document.getElementById('autoJobs').checked=!!state.auto_attach_jobs;
        document.getElementById('autoAssessments').checked=!!state.auto_attach_assessments;
        document.getElementById('topTitle').textContent='Edit '+state.name;

        render();
    })
    .catch(function(error){
        toast('error',error.message);
        window.setTimeout(function(){
            window.location.href='checklists.php';
        },900);
    });
}

loadExistingChecklist();
refreshIcons();

})(window,document);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
