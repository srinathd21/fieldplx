<?php
/**
 * FieldPlx reusable toast component.
 *
 * Usage from any page:
 *   require_once __DIR__ . '/includes/toast.php';
 *
 * JavaScript:
 *   fieldplxToast('success', 'Invoice created successfully');
 *   fieldplxToast('error', 'Something went wrong');
 *   fieldplxToast('warning', 'Please check the form');
 *
 * Change colors/layout here once and every page using this component updates.
 */
if (!defined('FIELDPLX_TOAST_COMPONENT_LOADED')) {
    define('FIELDPLX_TOAST_COMPONENT_LOADED', true);
?>
<style>
    .fieldplx-toast{
        position:fixed;
        top:82px;
        right:18px;
        z-index:14000;
        width:min(390px,calc(100vw - 36px));
        min-height:50px;
        padding:14px 18px;
        display:flex;
        align-items:center;
        border-radius:8px;
        color:#fff;
        background:#1f5f7a;
        box-shadow:0 12px 30px rgba(0,17,49,.18);
        opacity:0;
        visibility:hidden;
        transform:translateY(-8px);
        pointer-events:none;
        transition:opacity .18s ease,transform .18s ease,visibility .18s ease;
        font-family:Arial,Helvetica,sans-serif;
        font-size:14px;
        font-weight:700;
        line-height:1.45;
    }
    .fieldplx-toast.show{
        opacity:1;
        visibility:visible;
        transform:translateY(0);
    }
    .fieldplx-toast.success{background:#7ab804;}
    .fieldplx-toast.error{background:#c94f55;}
    .fieldplx-toast.warning{background:#9a741a;}

    @media(max-width:575.98px){
        .fieldplx-toast{
            top:76px;
            right:12px;
            width:calc(100vw - 24px);
        }
    }
</style>

<div class="fieldplx-toast" id="fieldplxToast" role="status" aria-live="polite" aria-atomic="true">
    <span id="fieldplxToastMessage">Notification</span>
</div>

<script>
(function(window, document){
    'use strict';

    var timer = null;

    window.fieldplxToast = function(type, message, duration){
        var toast = document.getElementById('fieldplxToast');
        var text = document.getElementById('fieldplxToastMessage');
        if (!toast || !text) return;

        if (timer) {
            window.clearTimeout(timer);
            timer = null;
        }

        type = String(type || '').toLowerCase();
        if (['success','error','warning'].indexOf(type) === -1) {
            type = '';
        }

        toast.className = 'fieldplx-toast' + (type ? ' ' + type : '') + ' show';
        text.textContent = message || 'Notification';

        var hideAfter = Number(duration || 3200);
        if (hideAfter < 500) hideAfter = 500;

        timer = window.setTimeout(function(){
            toast.classList.remove('show');
        }, hideAfter);
    };
})(window, document);
</script>
<?php
}
?>
