<?php
/**
 * FieldPlx reusable toast component.
 *
 * Usage:
 * require_once __DIR__ . '/includes/toast.php';
 * fieldplxToast('success', 'Saved successfully');
 */
if (!defined('FIELDPLX_TOAST_COMPONENT_LOADED')) {
    define('FIELDPLX_TOAST_COMPONENT_LOADED', true);
?>
<style>
.fieldplx-toast-stack{
    position:fixed;
    top:82px;
    right:18px;
    z-index:16000;
    width:min(340px,calc(100vw - 28px));
    display:flex;
    flex-direction:column;
    gap:8px;
    pointer-events:none;
}
.fieldplx-toast{
    display:flex;
    align-items:flex-start;
    gap:10px;
    min-height:44px;
    padding:10px 12px;
    border:1px solid #dfe6ee;
    border-radius:9px;
    background:#fff;
    color:#0b1933;
    box-shadow:0 10px 28px rgba(0,17,49,.14);
    opacity:0;
    transform:translateY(-7px) scale(.985);
    transition:opacity .16s ease,transform .16s ease;
    font-family:Arial,Helvetica,sans-serif;
    pointer-events:auto;
}
.fieldplx-toast.show{opacity:1;transform:translateY(0) scale(1)}
.fieldplx-toast-icon{
    width:24px;height:24px;flex:0 0 24px;
    display:grid;place-items:center;
    border-radius:50%;
    background:#edf4f7;color:#1f5f7a;
    font-size:13px;font-weight:700;
}
.fieldplx-toast-copy{min-width:0;flex:1;padding-top:2px}
.fieldplx-toast-title{display:block;margin:0 0 1px;font-size:12px;font-weight:700;line-height:1.25}
.fieldplx-toast-message{display:block;color:#5f6f82;font-size:11px;font-weight:400;line-height:1.4;word-break:break-word}
.fieldplx-toast-close{
    width:24px;height:24px;flex:0 0 24px;
    display:grid;place-items:center;
    border:0;border-radius:6px;background:transparent;
    color:#6f7b90;font-size:17px;line-height:1;cursor:pointer;
}
.fieldplx-toast-close:hover{background:#f3f6f9;color:#0b1933}
.fieldplx-toast.success{border-left-color:#74b824}
.fieldplx-toast.success .fieldplx-toast-icon{background:#f0f8e5;color:#5d971b}
.fieldplx-toast.error{border-left-color:#d95a63}
.fieldplx-toast.error .fieldplx-toast-icon{background:#fff0f1;color:#c94f55}
.fieldplx-toast.warning{border-left-color:#c18b20}
.fieldplx-toast.warning .fieldplx-toast-icon{background:#fff8e6;color:#9a741a}
.fieldplx-toast.info{border-left-color:#1f5f7a}
@media(max-width:575.98px){
    .fieldplx-toast-stack{top:74px;right:10px;left:10px;width:auto}
}
</style>
<div class="fieldplx-toast-stack" id="fieldplxToastStack" aria-live="polite" aria-atomic="true"></div>
<script>
(function(window, document){
    'use strict';
    var counter = 0;
    window.fieldplxToast = function(type, message, duration, title){
        var stack = document.getElementById('fieldplxToastStack');
        if (!stack) return;
        type = String(type || 'info').toLowerCase();
        if (['success','error','warning','info'].indexOf(type) === -1) type = 'info';
        var labels = {success:'Success',error:'Error',warning:'Warning',info:'Notice'};
        var symbols = {success:'✓',error:'!',warning:'!',info:'i'};
        var toast = document.createElement('div');
        toast.className = 'fieldplx-toast ' + type;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.dataset.toastId = String(++counter);

        var icon = document.createElement('div');
        icon.className = 'fieldplx-toast-icon';
        icon.textContent = symbols[type];

        var copy = document.createElement('div');
        copy.className = 'fieldplx-toast-copy';
        var titleEl = document.createElement('span');
        titleEl.className = 'fieldplx-toast-title';
        titleEl.textContent = title || labels[type];
        var messageEl = document.createElement('span');
        messageEl.className = 'fieldplx-toast-message';
        messageEl.textContent = message || 'Notification';
        copy.appendChild(titleEl);
        copy.appendChild(messageEl);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'fieldplx-toast-close';
        close.setAttribute('aria-label','Close notification');
        close.innerHTML = '&times;';

        function remove(){
            toast.classList.remove('show');
            window.setTimeout(function(){
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            },170);
        }
        close.addEventListener('click', remove);
        toast.appendChild(icon);toast.appendChild(copy);toast.appendChild(close);
        stack.appendChild(toast);
        window.requestAnimationFrame(function(){ toast.classList.add('show'); });
        var hideAfter = Number(duration || 3200);
        if (hideAfter < 800) hideAfter = 800;
        window.setTimeout(remove, hideAfter);
    };
})(window, document);
</script>
<?php
}
?>
