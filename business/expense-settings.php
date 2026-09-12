<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Expense Tracking · FieldPlx';
$pageDescription = 'Manage expense accounting codes used when tracking job costs';
$settingsActivePage = 'expense-tracking';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['expense_settings_csrf'])) {
    $_SESSION['expense_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['expense_settings_csrf'];

require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/toast.php';
?>
<style>
.exp-page{display:grid;grid-template-columns:250px minmax(0,1fr);gap:28px;max-width:1320px;margin:0 auto;padding:8px 0 44px}.exp-main{min-width:0}.exp-head{margin:0 0 16px}.exp-plan-badge{display:inline-flex;align-items:center;gap:7px;min-height:26px;padding:0 11px;margin-bottom:9px;border-radius:999px;background:#eaf5ff;color:#286a95;font-size:11px}.exp-plan-badge:before{content:"";width:8px;height:8px;border-radius:50%;background:#35a7e8}.exp-head h1{margin:0;color:var(--fieldplx-text,#0b1933);font-size:31px;line-height:1.15}.exp-head p{margin:11px 0 0;color:var(--fieldplx-muted,#6f7b90);font-size:14px}.exp-card{background:#fff;border:1px solid #dce4eb;border-radius:10px;overflow:hidden}.exp-card-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:15px 16px 12px}.exp-card-head h2{margin:0;color:#082b3a;font-size:23px}.exp-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:34px;padding:0 12px;border:1px solid #d8e0e6;border-radius:8px;background:#fff;color:#2f7f24;font:inherit;font-size:12px;font-weight:700;cursor:pointer}.exp-btn:hover{border-color:#74b824}.exp-btn.primary{background:#2f8c25;border-color:#2f8c25;color:#fff}.exp-btn.danger{color:#d94242}.exp-btn:disabled{opacity:.55;cursor:not-allowed}.exp-list{padding:0 16px 12px}.exp-row{border-top:1px solid #edf1f4}.exp-row:first-child{border-top:0}.exp-row-view{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:center;min-height:54px}.exp-code{color:#294958;font-size:13px}.exp-row-edit{display:none;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;padding:12px 4px 14px}.exp-row.editing .exp-row-view{display:none}.exp-row.editing .exp-row-edit{display:grid}.exp-input{width:100%;height:44px;padding:0 14px;border:1px solid #cfdbe3;border-radius:8px;background:#fff;color:#173644;font:inherit;font-size:13px;outline:none}.exp-input:focus{border-color:#74b824;box-shadow:0 0 0 2px rgba(116,184,36,.13)}.exp-actions{display:flex;align-items:center;gap:8px}.exp-new-list{padding:0 16px 12px}.exp-new{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;border-top:1px solid #edf1f4;padding:12px 4px 14px}.exp-new:first-child{border-top:1px solid #edf1f4}.exp-empty{display:none;padding:24px 0;color:#5d7380;font-size:12px}.exp-schema{display:none;margin:0 0 15px;padding:11px 13px;border:1px solid #efd393;border-radius:8px;background:#fff8e7;color:#7b5b08;font-size:11px;line-height:1.45}.exp-loading{padding:20px 0;color:#6f7b90;font-size:12px}
@media(max-width:980px){.exp-page{grid-template-columns:1fr}.exp-page>aside{display:none}.exp-main{padding:0 4px}}@media(max-width:620px){.exp-row-edit,.exp-new{grid-template-columns:1fr}.exp-actions{justify-content:flex-end}.exp-card-head h2{font-size:20px}}
</style>

<div class="exp-page">
    <aside><?php require __DIR__ . '/includes/settings-nav.php'; ?></aside>
    <main class="exp-main">
        <div class="exp-head">
            <div class="exp-plan-badge">Expense tracking</div>
            <h1>Expense Tracking</h1>
            <p>Record expenses for each job and categorize them by creating accounting codes.</p>
        </div>

        <div id="schemaWarning" class="exp-schema">Run <strong>database/expense-tracking-settings-migration.sql</strong> once before using accounting codes.</div>

        <section class="exp-card">
            <div class="exp-card-head">
                <h2>Accounting codes</h2>
                <button type="button" class="exp-btn" id="newCodeButton">+ New Code</button>
            </div>
            <div class="exp-list" id="codeList">
                <div class="exp-loading">Loading accounting codes...</div>
            </div>
            <div class="exp-new-list" id="newCodeList"></div>
        </section>
    </main>
</div>

<script>
(function(){
    'use strict';

    var csrf = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var apiUrl = 'api/expense-settings.php';
    var codeList = document.getElementById('codeList');
    var newCodeButton = document.getElementById('newCodeButton');
    var newCodeList = document.getElementById('newCodeList');
    var schemaWarning = document.getElementById('schemaWarning');
    var rows = [];
    var newRowSequence = 0;
    var activeEditId = 0;

    function esc(value){
        return String(value == null ? '' : value)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function toast(type, message){
        if (typeof window.fieldplxToast === 'function') {
            window.fieldplxToast(type, message);
        }
    }

    function request(formData){
        formData.append('csrf_token', csrf);
        return fetch(apiUrl, {
            method:'POST',
            body:formData,
            credentials:'same-origin',
            headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
        }).then(function(response){
            return response.text().then(function(text){
                var data;
                try { data = JSON.parse(text); }
                catch (e) { throw new Error('Invalid server response.'); }
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to complete the request.');
                }
                return data;
            });
        });
    }

    function form(action){
        var fd = new FormData();
        fd.append('action', action);
        return fd;
    }

    function render(){
        if (!rows.length) {
            codeList.innerHTML = '<div class="exp-empty" style="display:block">No accounting codes yet. Click <strong>+ New Code</strong> to add one.</div>';
            updateEmptyState();
            return;
        }

        codeList.innerHTML = rows.map(function(row){
            return '<div class="exp-row" data-id="'+Number(row.id)+'">'
                + '<div class="exp-row-view">'
                + '<div class="exp-code">'+esc(row.code)+'</div>'
                + '<button type="button" class="exp-btn" data-edit="'+Number(row.id)+'">Edit</button>'
                + '</div>'
                + '<div class="exp-row-edit">'
                + '<input type="text" class="exp-input" data-input="'+Number(row.id)+'" maxlength="120" value="'+esc(row.code)+'" autocomplete="off">'
                + '<div class="exp-actions">'
                + '<button type="button" class="exp-btn" data-cancel="'+Number(row.id)+'">Cancel</button>'
                + '<button type="button" class="exp-btn primary" data-save="'+Number(row.id)+'">Save</button>'
                + '</div>'
                + '</div>'
                + '</div>';
        }).join('');

        codeList.querySelectorAll('[data-edit]').forEach(function(button){
            button.addEventListener('click', function(){ beginEdit(Number(button.getAttribute('data-edit'))); });
        });
        codeList.querySelectorAll('[data-cancel]').forEach(function(button){
            button.addEventListener('click', function(){ cancelEdit(Number(button.getAttribute('data-cancel'))); });
        });
        codeList.querySelectorAll('[data-save]').forEach(function(button){
            button.addEventListener('click', function(){ saveEdit(Number(button.getAttribute('data-save')), button); });
        });
        codeList.querySelectorAll('[data-input]').forEach(function(input){
            input.addEventListener('keydown', function(e){
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var id = Number(input.getAttribute('data-input'));
                    var save = codeList.querySelector('[data-save="'+id+'"]');
                    if (save) save.click();
                }
                if (e.key === 'Escape') {
                    e.preventDefault();
                    cancelEdit(Number(input.getAttribute('data-input')));
                }
            });
        });
        updateEmptyState();
    }

    function updateEmptyState(){
        var empty = codeList.querySelector('.exp-empty');
        if (!empty) return;
        empty.style.display = newCodeList.children.length > 0 ? 'none' : 'block';
    }

    function load(){
        var fd = form('list');
        request(fd).then(function(data){
            rows = Array.isArray(data.codes) ? data.codes : [];
            schemaWarning.style.display = 'none';
            render();
        }).catch(function(error){
            codeList.innerHTML = '<div class="exp-empty" style="display:block">Unable to load accounting codes.</div>';
            if (/migration|not installed|table/i.test(error.message)) {
                schemaWarning.style.display = 'block';
            }
            toast('error', error.message);
        });
    }

    function addNewRow(){
        newRowSequence += 1;
        var key = 'new-' + newRowSequence;
        var row = document.createElement('div');
        row.className = 'exp-new';
        row.setAttribute('data-new-key', key);
        row.innerHTML = '<input type="text" class="exp-input" data-new-input="'+key+'" maxlength="120" placeholder="Code" autocomplete="off">'
            + '<div class="exp-actions">'
            + '<button type="button" class="exp-btn" data-new-cancel="'+key+'">Cancel</button>'
            + '<button type="button" class="exp-btn primary" data-new-save="'+key+'">Save</button>'
            + '</div>';
        newCodeList.appendChild(row);

        var input = row.querySelector('[data-new-input]');
        var cancel = row.querySelector('[data-new-cancel]');
        var save = row.querySelector('[data-new-save]');

        cancel.addEventListener('click', function(){ removeNewRow(key); });
        save.addEventListener('click', function(){ saveNewRow(key, save); });
        input.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
                e.preventDefault();
                save.click();
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                removeNewRow(key);
            }
        });

        updateEmptyState();
        setTimeout(function(){ input.focus(); }, 20);
    }

    function removeNewRow(key){
        var row = newCodeList.querySelector('[data-new-key="'+key+'"]');
        if (row && row.parentNode) {
            row.parentNode.removeChild(row);
        }
        updateEmptyState();
    }

    function saveNewRow(key, button){
        var row = newCodeList.querySelector('[data-new-key="'+key+'"]');
        if (!row) return;
        var input = row.querySelector('[data-new-input="'+key+'"]');
        if (!input) return;
        saveCode(0, input.value, button, function(){
            removeNewRow(key);
            load();
        });
    }

    function beginEdit(id){
        id = Number(id || 0);
        if (!id) return;

        if (activeEditId && activeEditId !== id) {
            var previous = codeList.querySelector('.exp-row[data-id="'+activeEditId+'"]');
            if (previous) previous.classList.remove('editing');
        }

        activeEditId = id;
        var row = codeList.querySelector('.exp-row[data-id="'+id+'"]');
        if (!row) {
            activeEditId = 0;
            return;
        }
        row.classList.add('editing');
        var input = row.querySelector('[data-input="'+id+'"]');
        if (input) {
            setTimeout(function(){ input.focus(); input.select(); }, 20);
        }
    }

    function cancelEdit(id){
        id = Number(id || 0);
        var row = codeList.querySelector('.exp-row[data-id="'+id+'"]');
        if (row) row.classList.remove('editing');
        if (activeEditId === id) activeEditId = 0;
    }

    function saveCode(id, value, button, onSuccess){
        value = String(value || '').trim();
        if (!value) {
            toast('warning', 'Accounting code is required.');
            return;
        }
        if (button) button.disabled = true;
        var fd = form('save');
        fd.append('id', String(id || 0));
        fd.append('code', value);
        request(fd).then(function(data){
            toast('success', data.message);
            if (typeof onSuccess === 'function') {
                onSuccess(data);
            } else {
                activeEditId = 0;
                load();
            }
        }).catch(function(error){
            toast('error', error.message);
        }).then(function(){
            if (button && document.body.contains(button)) button.disabled = false;
        });
    }

    function saveEdit(id, button){
        var input = codeList.querySelector('[data-input="'+id+'"]');
        if (!input) return;
        saveCode(id, input.value, button);
    }

    newCodeButton.addEventListener('click', addNewRow);
    load();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>