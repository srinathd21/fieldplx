<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Active Login Sessions · FieldPlx';
$pageDescription = 'Review and revoke active device sessions';
$settingsActivePage = 'team';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['team_settings_csrf'])) {
    $_SESSION['team_settings_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['team_settings_csrf'];
$targetUserId = isset($_GET['user_id']) ? max(0, (int)$_GET['user_id']) : 0;
$currentUserId = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
$currentDeviceSessionId = isset($_SESSION['tenant_device_session_id']) ? (int)$_SESSION['tenant_device_session_id'] : 0;

/* When opened without user_id, show the current user's sessions. */
if ($targetUserId <= 0 && $currentUserId > 0) {
    $targetUserId = $currentUserId;
}

require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/toast.php';
?>

<!-- Lucide icons -->
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>

<style>
.als-page{
    max-width:1040px;
    margin:0 auto;
    padding:24px 24px 48px;
}
.als-back{
    display:inline-flex;
    align-items:center;
    gap:6px;
    margin-bottom:14px;
    color:var(--primary,#2f8c25);
    text-decoration:none;
    font-size:12px;
    font-weight:600;
}
.als-back:hover{text-decoration:underline}
.als-page h1{
    margin:0 0 28px;
    color:var(--text,#0b1933);
    font-size:32px;
    line-height:1.15;
    font-weight:700;
}
.als-page h2{
    margin:0 0 5px;
    color:var(--text,#0b3142);
    font-size:20px;
    line-height:1.2;
    font-weight:700;
}
.als-sub{
    margin:0 0 20px;
    color:var(--muted,#66788a);
    font-size:13px;
    line-height:1.55;
}
.als-card{
    overflow:hidden;
    border:1px solid var(--border,#dce4eb);
    border-radius:10px;
    background:var(--surface,#fff);
    box-shadow:0 1px 2px rgba(15,23,42,.03);
}
.als-card-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    padding:16px 18px;
    border-bottom:1px solid var(--border,#dfe6eb);
}
.als-card-head strong{
    display:flex;
    align-items:center;
    gap:8px;
    color:var(--text,#0b3142);
    font-size:15px;
    line-height:1.2;
}
.als-count{
    display:inline-grid;
    place-items:center;
    min-width:22px;
    height:20px;
    padding:0 7px;
    border-radius:999px;
    background:var(--soft-bg,#edf0f1);
    color:var(--muted,#60727e);
    font-size:10px;
    font-weight:700;
}
.als-email{
    margin-top:4px;
    color:var(--muted,#6f7b90);
    font-size:11px;
}
.als-btn{
    min-height:36px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    padding:0 14px;
    border:1px solid var(--border,#d9e2e9);
    border-radius:8px;
    background:var(--surface,#fff);
    color:#df4f3f;
    font:inherit;
    font-size:12px;
    font-weight:700;
    cursor:pointer;
    transition:background .15s ease,border-color .15s ease,opacity .15s ease;
}
.als-btn:hover:not(:disabled){background:rgba(223,79,63,.06);border-color:#e7b4ad}
.als-btn:disabled{opacity:.45;cursor:not-allowed}
.als-row{
    display:grid;
    grid-template-columns:42px minmax(0,1fr) auto;
    gap:12px;
    align-items:center;
    padding:15px 18px;
    border-bottom:1px solid var(--border,#e7ecf0);
}
.als-row:last-child{border-bottom:0}
.als-icon{
    width:38px;
    height:38px;
    display:grid;
    place-items:center;
    border-radius:9px;
    background:var(--soft-bg,#f1f0ed);
    color:var(--text,#1d4655);
}
.als-icon svg{width:19px;height:19px;stroke-width:1.9}
.als-name-line{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:8px;
}
.als-name{
    color:var(--text,#183848);
    font-size:13px;
    font-weight:700;
}
.als-current{
    display:inline-flex;
    align-items:center;
    gap:5px;
    min-height:20px;
    padding:0 7px;
    border-radius:999px;
    background:rgba(47,140,37,.1);
    color:#2f8c25;
    font-size:9px;
    font-weight:700;
}
.als-current:before{
    content:"";
    width:6px;
    height:6px;
    border-radius:50%;
    background:#2f8c25;
}
.als-meta{
    margin-top:4px;
    color:var(--muted,#71808d);
    font-size:11px;
    line-height:1.45;
    overflow-wrap:anywhere;
}
.als-online{color:#2f8c25}
.als-empty,.als-loading{
    padding:36px 24px;
    text-align:center;
    color:var(--muted,#6f7b90);
    font-size:12px;
}
.als-loading-inner{
    display:inline-flex;
    align-items:center;
    gap:9px;
}
.als-spinner{
    width:15px;
    height:15px;
    border:2px solid rgba(100,116,139,.25);
    border-top-color:var(--primary,#2f8c25);
    border-radius:50%;
    animation:alsSpin .7s linear infinite;
}
.als-btn.loading{opacity:.65;pointer-events:none}
.als-btn.loading:before{
    content:"";
    width:12px;
    height:12px;
    border:2px dotted currentColor;
    border-radius:50%;
    animation:alsSpin .75s linear infinite;
}
@keyframes alsSpin{to{transform:rotate(360deg)}}

/* Dark-mode compatibility with FieldPlx theme attributes/classes. */
html[data-theme="dark"] .als-card,
body.dark-mode .als-card,
body.theme-dark .als-card{
    background:var(--surface,#12212a);
}
html[data-theme="dark"] .als-icon,
body.dark-mode .als-icon,
body.theme-dark .als-icon{
    background:rgba(255,255,255,.06);
}

@media(max-width:680px){
    .als-page{padding:16px 14px 36px}
    .als-page h1{font-size:26px;margin-bottom:22px}
    .als-card-head{align-items:flex-start;flex-direction:column;padding:14px}
    .als-card-head .als-btn{width:100%}
    .als-row{grid-template-columns:38px minmax(0,1fr);padding:14px}
    .als-row .als-btn{grid-column:2;width:auto;justify-self:start}
}
</style>

<div class="als-page">
    <a class="als-back" href="team.php">
        <i data-lucide="arrow-left"></i>
        <span>Manage team</span>
    </a>

    <h1>Active Login Sessions</h1>

    <div id="sessionContent" class="als-loading">
        <span class="als-loading-inner">
            <span class="als-spinner"></span>
            <span>Loading active sessions...</span>
        </span>
    </div>
</div>

<script>
(function(){
'use strict';

var csrf = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var api = 'api/team.php';
var userId = <?= (int)$targetUserId ?>;
var currentUserId = <?= (int)$currentUserId ?>;
var currentDeviceId = <?= (int)$currentDeviceSessionId ?>;

function E(id){ return document.getElementById(id); }

function esc(v){
    return String(v == null ? '' : v)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function renderIcons(){
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons({attrs:{'stroke-width':1.9}});
    }
}

function toast(type,message){
    if (window.fieldplxToast) {
        window.fieldplxToast(type,message);
    }
}

function req(fd){
    fd.append('csrf_token',csrf);

    return fetch(api,{
        method:'POST',
        body:fd,
        credentials:'same-origin',
        headers:{
            'X-Requested-With':'XMLHttpRequest',
            'Accept':'application/json'
        }
    }).then(function(r){
        return r.text().then(function(x){
            var d;
            try {
                d = JSON.parse(x);
            } catch(e) {
                throw new Error('Invalid server response.');
            }
            if (!r.ok || !d.success) {
                throw new Error(d.message || 'Request failed.');
            }
            return d;
        });
    });
}

function ago(v){
    if (!v) return 'Unknown';

    var raw = String(v).trim();

    /*
     * MariaDB stores timestamps in UTC.
     *
     * Example:
     * 2026-09-18 13:15:00
     *
     * Convert it to ISO UTC:
     * 2026-09-18T13:15:00Z
     *
     * Browser then converts UTC to the user's
     * local timezone automatically.
     */
    if (
        /^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/.test(raw)
    ) {
        raw = raw.replace(' ', 'T') + 'Z';
    } else if (
        /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(raw)
    ) {
        raw = raw + 'Z';
    }

    var d = new Date(raw);

    if (isNaN(d.getTime())) {
        return String(v);
    }

    var seconds =
        Math.floor(
            (Date.now() - d.getTime()) / 1000
        );

    /*
     * Small server/browser clock differences.
     */
    if (seconds < 0) {
        seconds = 0;
    }

    if (seconds < 60) {
        return 'just now';
    }

    if (seconds < 3600) {
        var minutes =
            Math.floor(seconds / 60);

        return minutes +
            (minutes === 1
                ? ' min. ago'
                : ' mins. ago');
    }

    if (seconds < 86400) {
        var hours =
            Math.floor(seconds / 3600);

        return hours +
            (hours === 1
                ? ' hr. ago'
                : ' hrs. ago');
    }

    if (seconds < 604800) {
        var days =
            Math.floor(seconds / 86400);

        return days +
            (days === 1
                ? ' day ago'
                : ' days ago');
    }

    return d.toLocaleString();
}

function titleCase(v){
    v = String(v || '').replace(/[_-]+/g,' ').trim();
    return v.replace(/\b\w/g,function(c){return c.toUpperCase();});
}

function deviceName(r){
    var n = String(r.device_name || '').trim();
    if (n) return n;

    var p = String(r.platform || 'other').toLowerCase();
    if (p === 'web') return 'Web browser';
    if (p === 'android') return 'Android';
    if (p === 'ios') return 'iPhone / iPad';
    return 'Other';
}

function deviceIcon(r){
    var p = String(r.platform || '').toLowerCase();
    var n = String(r.device_name || '').toLowerCase();

    if (p === 'android' || /android|samsung|pixel|redmi|realme|vivo|oppo|oneplus|rmx/.test(n)) {
        return 'smartphone';
    }
    if (p === 'ios' || /iphone|ipad/.test(n)) {
        return 'smartphone';
    }
    if (p === 'web') {
        return 'monitor';
    }
    return 'monitor';
}

function metaText(r){
    var parts = [];

    if (r.browser_name) parts.push(String(r.browser_name));
    if (r.os_name) parts.push(String(r.os_name));

    /* Existing schema/API always has platform and IP. */
    if (!r.browser_name && !r.os_name && r.platform) {
        parts.push(titleCase(r.platform));
    }

    if (r.last_ip_address) {
        parts.push('IP ' + String(r.last_ip_address));
    }

    return parts.join(' · ');
}

function currentBadge(r){
    return currentDeviceId > 0 && Number(r.id) === Number(currentDeviceId)
        ? '<span class="als-current">This device</span>'
        : '';
}

function load(){
    var f = new FormData();
    f.append('action','sessions');
    f.append('user_id',String(userId));

    req(f).then(function(d){
        var m = d.member || {};
        var rows = d.sessions || [];
        var name = ((m.first_name || '') + ' ' + (m.last_name || '')).trim();

        var h = '';
        h += '<h2>' + esc(name || 'Team member') + '</h2>';
        h += '<p class="als-sub">These devices are currently signed in to FieldPlx as ' + esc(name || 'this team member') + '. Sign out anything that looks unfamiliar.</p>';
        h += '<section class="als-card">';
        h += '<div class="als-card-head">';
        h += '<div>';
        h += '<strong>Active sessions <span class="als-count">' + rows.length + '</span></strong>';
        h += '<div class="als-email">' + esc(m.email || '') + '</div>';
        h += '</div>';
        h += '<button class="als-btn" id="signAll" ' + (!rows.length ? 'disabled' : '') + '>';
        h += '<i data-lucide="log-out"></i><span>Sign out all sessions</span>';
        h += '</button>';
        h += '</div>';

        if (!rows.length) {
            h += '<div class="als-empty">No active device sessions found.</div>';
        } else {
            rows.forEach(function(r){
                var isCurrent = currentDeviceId > 0 && Number(r.id) === Number(currentDeviceId);
                var lastSeen = r.last_seen_at || r.created_at;
                var meta = metaText(r);

                h += '<div class="als-row">';
                h += '<div class="als-icon"><i data-lucide="' + deviceIcon(r) + '"></i></div>';
                h += '<div>';
                h += '<div class="als-name-line">';
                h += '<div class="als-name">' + esc(deviceName(r)) + '</div>';
                h += currentBadge(r);
                h += '</div>';
                h += '<div class="als-meta">';
                if (meta) h += esc(meta) + ' · ';
                h += '<span class="als-online">●</span> ' + esc(ago(lastSeen));
                h += '</div>';
                h += '</div>';
                h += '<button class="als-btn" data-sign="' + Number(r.id) + '" data-current="' + (isCurrent ? '1' : '0') + '">';
                h += '<i data-lucide="log-out"></i><span>Sign out</span>';
                h += '</button>';
                h += '</div>';
            });
        }

        h += '</section>';

        E('sessionContent').className = '';
        E('sessionContent').innerHTML = h;
        renderIcons();

        var all = E('signAll');
        if (all) {
            all.onclick = function(){
                sign('signout_all',0,this, userId === currentUserId);
            };
        }

        document.querySelectorAll('[data-sign]').forEach(function(b){
            b.onclick = function(){
                sign(
                    'signout_session',
                    Number(b.getAttribute('data-sign')),
                    b,
                    b.getAttribute('data-current') === '1'
                );
            };
        });
    }).catch(function(e){
        E('sessionContent').className = '';
        E('sessionContent').innerHTML = '<div class="als-empty">Unable to load active sessions.</div>';
        toast('error',e.message);
    });
}

function sign(action,id,button,endsCurrentSession){
    var f = new FormData();
    f.append('action',action);
    f.append('user_id',String(userId));
    if (id) f.append('device_id',String(id));

    button.disabled = true;
    button.classList.add('loading');

    req(f).then(function(d){
        toast('success',d.message);

        /* If the current browser session was revoked, end the PHP session too. */
        if (endsCurrentSession) {
            window.setTimeout(function(){
                window.location.href = 'logout.php?reason=logout';
            },350);
            return;
        }

        load();
    }).catch(function(e){
        toast('error',e.message);
        button.disabled = false;
        button.classList.remove('loading');
    });
}

if (userId <= 0) {
    E('sessionContent').className = '';
    E('sessionContent').innerHTML = '<div class="als-empty">No team member selected.</div>';
} else {
    load();
}

renderIcons();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
