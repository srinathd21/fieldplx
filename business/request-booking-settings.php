<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Requests and bookings · FieldPlx';
$pageDescription = 'Manage request and booking forms';
$settingsActivePage = 'requests-bookings';
if (session_status() === PHP_SESSION_NONE)
    session_start();
if (empty($_SESSION['request_booking_csrf']))
    $_SESSION['request_booking_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string) $_SESSION['request_booking_csrf'];
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/toast.php';
?>
<link rel="stylesheet" href="assets/request-booking.css?v=20260919-1">
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>

<div class="rb-page">
    <aside><?php require __DIR__ . '/includes/settings-nav.php'; ?></aside>
    <main class="rb-main">
        <header class="rb-head">
            <h1>Requests and bookings</h1>
            <p>Manage all your requests and booking forms in one place. Customize your forms to collect the details you
                need, then share it on your website, social media, or Customer Hub so customers can book with you any
                time.</p>
        </header>

        <div class="rb-schema" id="schemaWarning">Run the included SQL migration before using the protected default
            forms.</div>

        <section class="rb-card">
            <div class="rb-card-head">
                <h2>Forms</h2>
                <a class="rb-btn" href="request-booking-form-new.php"><i data-lucide="plus"></i>Add New Form</a>
            </div>
            <div class="rb-card-body">
                <div id="formsList" class="rb-form-list">
                    <div class="rb-empty">Loading forms...</div>
                </div>
            </div>
        </section>

        <section class="rb-card">
            <div class="rb-card-head">
                <h2>Checklists</h2>
                <a class="rb-btn" href="checklists.php">Manage Checklists</a>
            </div>
            <div class="rb-card-body">
                <div class="rb-copy-row">
                    <div>
                        <p>Attach custom-built checklists to on-site assessments so that nothing gets missed</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="rb-card">
            <div class="rb-card-head">
                <h2>Customization</h2>
            </div>
            <div class="rb-card-body">
                <div class="rb-copy-row">
                    <div><strong>Personalize your forms with your brand</strong>
                        <p>Add your logo and customize colors to match your business style. Branding can be updated in
                            <a class="rb-link" href="business-profile.php">Business Profile</a>.</p>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<div class="rb-modal-backdrop" id="shareModal" aria-hidden="true">
    <div class="rb-modal">
        <div class="rb-modal-head">
            <h3>Share links</h3><button class="rb-close" type="button" data-close-share><i data-lucide="x"></i></button>
        </div>
        <div class="rb-modal-body">
            <p class="rb-help">Copy and paste the hosted link below, or embed this form on your website.</p>
            <div class="rb-section-label">Hosted form</div>
            <div class="rb-input-group"><input class="rb-input" id="shareUrl" readonly><button class="rb-btn"
                    type="button" data-copy="shareUrl"><i data-lucide="copy"></i>Copy</button></div>
            <div class="rb-share-sep"></div>
            <div class="rb-section-label">Embed code</div>
            <div class="rb-input-group"><textarea class="rb-textarea" id="embedCode" readonly></textarea><button
                    class="rb-btn" type="button" data-copy="embedCode"><i data-lucide="copy"></i>Copy</button></div>
        </div>
        <div class="rb-modal-foot"><button class="rb-btn" type="button" data-close-share>Close</button></div>
    </div>
</div>

<div class="rb-modal-backdrop" id="trackingModal" aria-hidden="true">
    <div class="rb-modal">
        <div class="rb-modal-head">
            <h3>Add tracking</h3><button class="rb-close" type="button" data-close-tracking><i
                    data-lucide="x"></i></button>
        </div>
        <div class="rb-modal-body">
            <p class="rb-help">Create a separate link for a website, ad, social post, QR code, or campaign so
                submissions can be attributed to that source.</p>
            <div class="rb-field"><label class="rb-section-label" for="trackingLabel">Tracking source</label><input
                    class="rb-input" id="trackingLabel" placeholder="e.g. Google Ads, Facebook, Website footer"></div>
            <div id="trackingResult"></div>
        </div>
        <div class="rb-modal-foot"><button class="rb-btn" type="button" data-close-tracking>Cancel</button><button
                class="rb-btn primary" id="createTrackingBtn" type="button">Create link</button></div>
    </div>
</div>

<script>
    (function (window, document) {
        'use strict';
        var API = 'api/request-booking-settings.php', csrf = <?= json_encode($csrfToken) ?>, forms = [], trackingFormId = 0;
        function E(id) { return document.getElementById(id) }
        function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c] }) }
        function icons() { if (window.lucide && window.lucide.createIcons) window.lucide.createIcons() }
        function toast(type, msg) { if (window.fieldplxToast) window.fieldplxToast(type, msg); else if (type === 'error') alert(msg) }
        function fd(action, id) { var x = new FormData(); x.append('action', action); x.append('csrf_token', csrf); if (id) x.append('id', String(id)); return x }
        function req(data) { return fetch(API, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).then(function (r) { return r.text().then(function (raw) { var d; try { d = raw ? JSON.parse(raw) : {} } catch (e) { throw new Error('Forms API returned an invalid response.') } if (!r.ok || !d.success) throw new Error(d.message || 'Request failed.'); return d }) }) }
        function findForm(id) { id = Number(id); for (var i = 0; i < forms.length; i++)if (Number(forms[i].id) === id) return forms[i]; return null }
        function closeMenus() { document.querySelectorAll('.rb-menu.open').forEach(function (x) { x.classList.remove('open') }) }
        function typeCanRequest(f) { return f.form_type === 'request' }
        function typeCanBooking(f) { return f.form_type !== 'request' }
        function menuHtml(f) {
            var out = ''; out += '<button type="button" data-edit="' + f.id + '"><i data-lucide="pencil"></i>Edit</button>';
            out += '<button type="button" data-preview="' + f.id + '"><i data-lucide="eye"></i>Preview</button>';
            out += '<button type="button" data-share="' + f.id + '"><i data-lucide="link"></i>Share links</button>';
            out += '<button type="button" data-track="' + f.id + '"><i data-lucide="plus"></i>Add tracking</button>';
            if (typeCanRequest(f) && !Number(f.is_request_default)) out += '<button type="button" data-default="request:' + f.id + '"><i data-lucide="lock-keyhole"></i>Set as request default</button>';
            if (typeCanBooking(f) && !Number(f.is_booking_default)) out += '<button type="button" data-default="booking:' + f.id + '"><i data-lucide="lock-keyhole"></i>Set as booking default</button>';
            if (!Number(f.is_system_form)) out += '<button type="button" class="danger" data-delete="' + f.id + '"><i data-lucide="trash-2"></i>Delete</button>';
            return out
        }
        function render() {
            if (!forms.length) { E('formsList').innerHTML = '<div class="rb-empty">No forms yet. Select Add New Form to create one.</div>'; return }
            E('formsList').innerHTML = forms.map(function (f) { var label = ''; if (Number(f.is_booking_default)) label = 'Booking default'; else if (Number(f.is_request_default)) label = 'Request default'; var used = Number(f.used_count || 0) > 0 ? '<span class="rb-used">Used in ' + Number(f.used_count) + ' place' + (Number(f.used_count) === 1 ? '' : 's') + '</span>' : ''; return '<div class="rb-form-row" data-row="' + f.id + '"><div class="rb-form-name"><span>' + esc(f.name) + '</span>' + used + '</div><div class="rb-form-state">' + (label ? '<span>' + esc(label) + '</span>' : '') + '<label class="rb-toggle" title="Enable or disable form"><input type="checkbox" data-toggle-form="' + f.id + '" ' + (f.status === 'active' ? 'checked' : '') + '><span class="rb-toggle-track"></span></label></div><div class="rb-menu-wrap"><button class="rb-icon-btn" type="button" data-menu="' + f.id + '" aria-label="Form actions"><i data-lucide="more-horizontal"></i></button><div class="rb-menu" id="formMenu' + f.id + '">' + menuHtml(f) + '</div></div></div>' }).join('');
            wire(); icons();
        }
        function wire() {
            document.querySelectorAll('[data-menu]').forEach(function (b) { b.onclick = function (e) { e.stopPropagation(); var m = E('formMenu' + b.getAttribute('data-menu')), open = m.classList.contains('open'); closeMenus(); if (!open) m.classList.add('open') } });
            document.querySelectorAll('[data-toggle-form]').forEach(function (x) { x.onchange = function () { var id = Number(x.getAttribute('data-toggle-form')), d = fd('toggle_status', id); d.append('active', x.checked ? '1' : '0'); x.disabled = true; req(d).then(function (r) { var f = findForm(id); if (f) f.status = x.checked ? 'active' : 'inactive'; toast('success', r.message) }).catch(function (e) { x.checked = !x.checked; toast('error', e.message) }).finally(function () { x.disabled = false }) } });
            document.querySelectorAll('[data-edit]').forEach(function (b) { b.onclick = function () { location.href = 'request-booking-form-builder.php?id=' + encodeURIComponent(b.getAttribute('data-edit')) } });
            document.querySelectorAll('[data-preview]').forEach(function (b) { b.onclick = function () { var f = findForm(b.getAttribute('data-preview')); if (f) window.open(f.public_url, '_blank', 'noopener') } });
            document.querySelectorAll('[data-share]').forEach(function (b) { b.onclick = function () { var f = findForm(b.getAttribute('data-share')); if (!f) return; E('shareUrl').value = f.public_url || ''; E('embedCode').value = f.embed_code || ''; openModal('shareModal') } });
            document.querySelectorAll('[data-track]').forEach(function (b) { b.onclick = function () { trackingFormId = Number(b.getAttribute('data-track')); E('trackingLabel').value = ''; E('trackingResult').innerHTML = ''; openModal('trackingModal'); setTimeout(function () { E('trackingLabel').focus() }, 30) } });
            document.querySelectorAll('[data-default]').forEach(function (b) { b.onclick = function () { var p = b.getAttribute('data-default').split(':'), d = fd('set_default', Number(p[1])); d.append('kind', p[0]); req(d).then(function (r) { toast('success', r.message); load() }).catch(function (e) { toast('error', e.message) }) } });
            document.querySelectorAll('[data-delete]').forEach(function (b) { b.onclick = function () { var f = findForm(b.getAttribute('data-delete')); if (!f) return; if (!confirm('Delete “' + f.name + '”? This cannot be undone.')) return; req(fd('delete', f.id)).then(function (r) { toast('success', r.message); load() }).catch(function (e) { toast('error', e.message) }) } });
        }
        function openModal(id) { E(id).classList.add('open'); E(id).setAttribute('aria-hidden', 'false'); icons() }
        function closeModal(id) { E(id).classList.remove('open'); E(id).setAttribute('aria-hidden', 'true') }
        document.querySelectorAll('[data-close-share]').forEach(function (b) { b.onclick = function () { closeModal('shareModal') } }); document.querySelectorAll('[data-close-tracking]').forEach(function (b) { b.onclick = function () { closeModal('trackingModal') } });
        document.querySelectorAll('[data-copy]').forEach(function (b) { b.onclick = function () { var el = E(b.getAttribute('data-copy')), text = el.value || ''; if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(function () { toast('success', 'Copied to clipboard.') }); else { el.select(); document.execCommand('copy'); toast('success', 'Copied to clipboard.') } } });
        E('createTrackingBtn').onclick = function () { var label = E('trackingLabel').value.trim(); if (!label) { toast('error', 'Enter a tracking source name.'); return } var d = fd('tracking_add', trackingFormId); d.append('source_label', label); this.disabled = true; req(d).then(function (r) { E('trackingResult').innerHTML = '<div class="rb-toast-inline"><strong>Tracking link created</strong><div class="rb-input-group" style="margin-top:8px"><input class="rb-input" id="newTrackingUrl" readonly value="' + esc(r.url) + '"><button class="rb-btn" type="button" id="copyNewTracking">Copy</button></div></div>'; E('copyNewTracking').onclick = function () { var x = E('newTrackingUrl'); x.select(); document.execCommand('copy'); toast('success', 'Copied to clipboard.') }; toast('success', r.message) }).catch(function (e) { toast('error', e.message) }).finally(function () { E('createTrackingBtn').disabled = false }) };
        document.addEventListener('click', closeMenus); document.querySelectorAll('.rb-modal-backdrop').forEach(function (m) { m.onclick = function (e) { if (e.target === m) closeModal(m.id) } });
        function load() { req(fd('list')).then(function (d) { forms = d.forms || []; E('schemaWarning').style.display = d.schema_ready ? 'none' : 'block'; render() }).catch(function (e) { E('formsList').innerHTML = '<div class="rb-empty">Unable to load forms.</div>'; toast('error', e.message) }) }
        load(); icons();
    })(window, document);
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>