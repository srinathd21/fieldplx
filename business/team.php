<?php
/**
 * FieldPlx - Manage Team
 * File: business/team.php
 * Compatible with PHP 7.2+ / MariaDB 11.x
 *
 * Backend API expected at: business/api/team.php
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Manage Team · FieldPlx';
$pageDescription = 'Manage your team members, seats, roles and active sessions';
$settingsActivePage = 'team';

if (empty($_SESSION['team_settings_csrf'])) {
    $_SESSION['team_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['team_settings_csrf'];

require __DIR__ . '/includes/header.php';
if (file_exists(__DIR__ . '/includes/toast.php')) {
    require_once __DIR__ . '/includes/toast.php';
}
?>

<style>
.team-settings-page{
    display:grid;
    grid-template-columns:250px minmax(0,1fr);
    gap:28px;
    max-width:1240px;
    margin:0 auto;
    padding:0 0 38px;
    align-items:start;
}
.team-settings-nav-wrap{
    min-width:0;
}
.team-main{
    min-width:0;
    padding-top:0;
}
.team-heading-row{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:18px;
    margin:0 0 14px;
}
.team-heading-copy h1{
    margin:0;
    color:var(--fieldplx-text,#0b1933);
    font-size:30px;
    line-height:1.16;
    font-weight:700;
}
.team-heading-copy p{
    margin:12px 0 0;
    color:var(--fieldplx-muted,#6f7b90);
    font-size:13px;
    line-height:1.55;
    max-width:690px;
}
.team-head-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:8px;
    flex-wrap:wrap;
}
.team-seat-pill,
.team-btn{
    min-height:34px;
    height:34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    padding:0 11px;
    border-radius:8px;
    white-space:nowrap;
    font:inherit;
    font-size:11px;
    font-weight:700;
    line-height:1;
}
.team-seat-pill{
    background:#f3f1ed;
    border:1px solid #ece9e3;
    color:#0b1933;
}
.team-seat-pill .muted{
    font-weight:500;
}
.team-btn{
    border:1px solid #dce3eb;
    background:#fff;
    color:#0b1933;
    text-decoration:none;
    cursor:pointer;
    transition:background .16s ease,border-color .16s ease,color .16s ease,transform .16s ease;
}
.team-btn:hover{
    border-color:#74b824;
    transform:translateY(-1px);
}
.team-btn-outline{
    color:#5d971b;
}
.team-btn-outline:hover{
    background:#f7fbed;
    color:#5d971b;
}
.team-btn-primary{
    background:#2d8d24;
    border-color:#2d8d24;
    color:#fff;
}
.team-btn-primary:hover{
    background:#25791e;
    border-color:#25791e;
    color:#fff;
}
.team-btn svg,
.team-seat-pill svg{
    width:14px;
    height:14px;
    stroke-width:2;
    flex:0 0 auto;
}
.team-search-wrap{
    position:relative;
    margin:0 0 20px;
}
.team-search-icon{
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    width:16px;
    height:16px;
    color:#68808d;
    pointer-events:none;
}
.team-search{
    width:100%;
    height:42px;
    padding:0 42px 0 42px;
    border:1px solid #dce3eb;
    border-radius:8px;
    outline:none;
    background:#fff;
    color:#0b1933;
    font:inherit;
    font-size:12px;
}
.team-search:focus{
    border-color:#74b824;
    box-shadow:0 0 0 2px rgba(116,184,36,.11);
}
.team-search-clear{
    position:absolute;
    right:8px;
    top:50%;
    transform:translateY(-50%);
    width:28px;
    height:28px;
    border:0;
    border-radius:7px;
    background:transparent;
    color:#617686;
    cursor:pointer;
    display:none;
    align-items:center;
    justify-content:center;
}
.team-search-clear.show{display:flex}
.team-search-clear:hover{background:#f4f6f8}
.team-search-clear svg{width:14px;height:14px}
.team-section-label{
    margin:0 0 10px;
    color:#0b1933;
    font-size:11px;
    font-weight:700;
}
.team-card{
    border:1px solid #dfe5eb;
    border-radius:9px;
    background:#fff;
    overflow:hidden;
}
.team-table-wrap{
    overflow-x:auto;
}
.team-table{
    width:100%;
    min-width:760px;
    border-collapse:collapse;
    table-layout:fixed;
}
.team-table th{
    padding:13px 14px;
    border-bottom:1px solid #dfe5eb;
    color:#0b1933;
    background:#fff;
    text-align:left;
    font-size:10px;
    font-weight:700;
    vertical-align:middle;
}
.team-table td{
    padding:13px 14px;
    border-bottom:1px solid #e6ebef;
    color:#294858;
    font-size:11px;
    vertical-align:middle;
}
.team-table tbody tr:last-child td{border-bottom:0}
.team-table tbody tr:hover{background:#fbfcfd}
.team-sort{
    display:inline-flex;
    align-items:center;
    gap:4px;
}
.team-sort svg{width:12px;height:12px;color:#a1adb6}
.team-member-link{
    display:inline-flex;
    align-items:center;
    gap:10px;
    color:#5d971b;
    text-decoration:none;
    font-weight:600;
    max-width:100%;
}
.team-avatar{
    width:29px;
    height:29px;
    border-radius:50%;
    display:grid;
    place-items:center;
    flex:0 0 auto;
    overflow:hidden;
    background:#153e4c;
    color:#fff;
    font-size:9px;
    font-weight:700;
}
.team-avatar img{width:100%;height:100%;object-fit:cover}
.team-status{
    display:inline-flex;
    align-items:center;
    gap:5px;
    min-height:22px;
    padding:0 9px;
    border-radius:999px;
    background:#eaf4e6;
    color:#2d7e27;
    font-size:9px;
    font-weight:600;
    text-transform:capitalize;
}
.team-status:before{
    content:"";
    width:5px;
    height:5px;
    border-radius:50%;
    background:currentColor;
}
.team-status.inactive,
.team-status.suspended{
    background:#f1f3f5;
    color:#6d7b85;
}
.session-link{
    display:inline-flex;
    align-items:center;
    gap:8px;
    color:#0b1933;
    font-size:10px;
    font-weight:700;
    line-height:1.25;
    text-decoration:none;
}
.session-link:hover{color:#5d971b}
.session-link svg{width:16px;height:16px;stroke-width:2}
.team-card-footer{
    min-height:49px;
    padding:8px 13px;
    border-top:1px solid #e3e8ed;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}
.team-page-info{
    color:#516877;
    font-size:10px;
}
.team-pager{
    display:flex;
    align-items:center;
    gap:8px;
}
.team-per-page{
    height:32px;
    min-width:68px;
    border:1px solid #dce3eb;
    border-radius:8px;
    padding:0 28px 0 10px;
    background:#fff;
    color:#264755;
    font-size:10px;
}
.team-pager-label{font-size:10px;color:#516877}
.team-page-btn{
    width:32px;
    height:32px;
    border:0;
    border-radius:8px;
    background:#eff1f3;
    color:#687a86;
    display:grid;
    place-items:center;
    cursor:pointer;
}
.team-page-btn:disabled{
    opacity:.5;
    cursor:not-allowed;
}
.team-page-btn svg{width:14px;height:14px}
.team-loading,
.team-empty{
    padding:34px 16px;
    text-align:center;
    color:#6f7b90;
    font-size:11px;
}
.team-empty strong{
    display:block;
    margin-bottom:5px;
    color:#0b1933;
    font-size:12px;
}
.team-spin{
    width:18px;
    height:18px;
    margin:0 auto 8px;
    border:2px solid #d8e2e8;
    border-top-color:#74b824;
    border-radius:50%;
    animation:teamSpin .75s linear infinite;
}
@keyframes teamSpin{to{transform:rotate(360deg)}}

.team-modal-backdrop{
    position:fixed;
    inset:0;
    z-index:10020;
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px;
    background:rgba(0,17,49,.38);
}
.team-modal-backdrop.open{display:flex}
.team-modal{
    width:min(390px,100%);
    border-radius:10px;
    background:#fff;
    box-shadow:0 24px 70px rgba(0,17,49,.22);
    overflow:hidden;
}
.team-modal-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:17px 18px 10px;
}
.team-modal-head h3{
    margin:0;
    color:#0b1933;
    font-size:18px;
}
.team-modal-close{
    width:30px;
    height:30px;
    display:grid;
    place-items:center;
    border:0;
    border-radius:7px;
    background:transparent;
    color:#365263;
    cursor:pointer;
}
.team-modal-close:hover{background:#f4f6f8}
.team-modal-close svg{width:18px;height:18px}
.team-modal-body{padding:8px 18px 18px}
.team-info-box{
    display:flex;
    gap:9px;
    padding:11px;
    border-radius:8px;
    background:#eaf5ff;
    color:#3f6b87;
    font-size:10px;
    line-height:1.45;
}
.team-info-box svg{width:17px;height:17px;flex:0 0 auto}
.team-seat-box{
    margin-top:13px;
    padding:10px 12px;
    border:1px solid #dfe5eb;
    border-radius:8px;
}
.team-seat-line{
    min-height:39px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    border-bottom:1px solid #edf0f2;
    color:#294858;
    font-size:10px;
}
.team-seat-line:last-child{border-bottom:0}
.team-seat-line strong{color:#0b1933}
.team-stepper{
    display:inline-flex;
    height:29px;
    border:1px solid #dce3eb;
    border-radius:7px;
    overflow:hidden;
}
.team-stepper button,
.team-stepper span{
    width:28px;
    display:grid;
    place-items:center;
    border:0;
    background:#fff;
    color:#607683;
    font-size:11px;
}
.team-stepper button{cursor:pointer}
.team-stepper button:disabled{opacity:.4;cursor:not-allowed}
.team-stepper span{border-left:1px solid #dce3eb;border-right:1px solid #dce3eb}
.team-modal-foot{
    display:flex;
    justify-content:flex-end;
    padding:0 18px 18px;
}
.team-modal-foot .team-btn{min-width:78px}

@media(max-width:980px){
    .team-settings-page{grid-template-columns:1fr;padding:0 14px 30px}
    .team-settings-nav-wrap{display:none}
}
@media(max-width:720px){
    .team-heading-row{flex-direction:column}
    .team-head-actions{width:100%;justify-content:flex-start}
    .team-heading-copy h1{font-size:25px}
    .team-card-footer{align-items:flex-start;flex-direction:column}
    .team-pager{width:100%;justify-content:flex-end}
}
</style>

<div class="team-settings-page">
    <div class="team-settings-nav-wrap">
        <?php
        if (file_exists(__DIR__ . '/includes/settings-nav.php')) {
            require __DIR__ . '/includes/settings-nav.php';
        }
        ?>
    </div>

    <main class="team-main">
        <div class="team-heading-row">
            <div class="team-heading-copy">
                <h1>Manage team</h1>
                <p>Manage your team members and seats in one place. Assign open seats, update team access, or adjust your team size.</p>
            </div>

            <div class="team-head-actions">
                <div class="team-seat-pill" id="seatPill">
                    <i data-lucide="users"></i>
                    <strong id="seatAssigned">0/0</strong>
                    <span class="muted">seats assigned</span>
                </div>

                <a href="invite-team-member.php" class="team-btn team-btn-outline" id="inviteMemberBtn">
                    <i data-lucide="user-plus"></i>
                    <span>Invite Member</span>
                </a>

                <button type="button" class="team-btn team-btn-primary" id="manageSeatsBtn">
                    <i data-lucide="armchair"></i>
                    <span>Manage Seats</span>
                </button>
            </div>
        </div>

        <div class="team-search-wrap">
            <i data-lucide="search" class="team-search-icon"></i>
            <input type="search" class="team-search" id="teamSearch" placeholder="Search team members" autocomplete="off">
            <button type="button" class="team-search-clear" id="teamSearchClear" aria-label="Clear search">
                <i data-lucide="x"></i>
            </button>
        </div>

        <div class="team-section-label" id="assignedLabel">Assigned seats (0)</div>

        <section class="team-card">
            <div class="team-table-wrap" id="teamTableWrap">
                <div class="team-loading">
                    <div class="team-spin"></div>
                    Loading team members...
                </div>
            </div>

            <div class="team-card-footer">
                <div class="team-page-info" id="teamPageInfo">Showing 0-0 of 0 items</div>

                <div class="team-pager">
                    <select class="team-per-page" id="teamPerPage" aria-label="Rows per page">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                    </select>
                    <span class="team-pager-label">per page</span>
                    <button type="button" class="team-page-btn" id="prevPage" aria-label="Previous page" disabled>
                        <i data-lucide="chevron-left"></i>
                    </button>
                    <button type="button" class="team-page-btn" id="nextPage" aria-label="Next page" disabled>
                        <i data-lucide="chevron-right"></i>
                    </button>
                </div>
            </div>
        </section>
    </main>
</div>

<div class="team-modal-backdrop" id="manageSeatsModal" aria-hidden="true">
    <div class="team-modal" role="dialog" aria-modal="true" aria-labelledby="manageSeatsTitle">
        <div class="team-modal-head">
            <h3 id="manageSeatsTitle">Manage seats</h3>
            <button type="button" class="team-modal-close" id="closeSeatsModal" aria-label="Close">
                <i data-lucide="x"></i>
            </button>
        </div>

        <div class="team-modal-body">
            <div class="team-info-box" id="seatNotice">
                <i data-lucide="info"></i>
                <span>Seat changes aren't available for your account right now. Contact support if you need to add or remove seats.</span>
            </div>

            <div class="team-seat-box">
                <div class="team-seat-line">
                    <strong>Included in plan</strong>
                    <span id="seatIncluded">0</span>
                </div>
                <div class="team-seat-line">
                    <div>
                        <strong>Paid seats</strong><br>
                        <span style="color:#7b8993">Billed monthly on top of your plan</span>
                    </div>
                    <div class="team-stepper">
                        <button type="button" id="seatMinus" disabled aria-label="Decrease paid seats">−</button>
                        <span id="seatPaid">0</span>
                        <button type="button" id="seatPlus" disabled aria-label="Increase paid seats">+</button>
                    </div>
                </div>
                <div class="team-seat-line">
                    <strong>Total</strong>
                    <strong id="seatTotal">0</strong>
                </div>
            </div>
        </div>

        <div class="team-modal-foot">
            <button type="button" class="team-btn team-btn-primary" id="confirmSeatsBtn">Confirm</button>
        </div>
    </div>
</div>

<script>
(function(window, document){
    'use strict';

    var csrf = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var apiUrl = 'api/team.php';
    var state = {
        page: 1,
        perPage: 25,
        search: '',
        pages: 1,
        seats: { assigned:0, included:0, paid:0, total:0, changes_available:false }
    };
    var searchTimer = null;

    function byId(id){ return document.getElementById(id); }

    function esc(value){
        return String(value == null ? '' : value)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }

    function toast(type, message){
        if (typeof window.fieldplxToast === 'function') {
            window.fieldplxToast(type, message);
            return;
        }
        if (type === 'error') {
            window.alert(message);
        }
    }

    function refreshIcons(){
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    function apiRequest(formData){
        if (!formData.has('csrf_token')) {
            formData.append('csrf_token', csrf);
        }
        return fetch(apiUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        }).then(function(response){
            return response.text().then(function(text){
                var data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error('Invalid response from team API.');
                }
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to complete the team request.');
                }
                return data;
            });
        });
    }

    function initials(row){
        var a = String(row.first_name || '').trim().charAt(0);
        var b = String(row.last_name || '').trim().charAt(0);
        var value = (a + b).toUpperCase();
        return value || 'U';
    }

    function memberName(row){
        var full = (String(row.first_name || '') + ' ' + String(row.last_name || '')).trim();
        return full || row.email || 'Team member';
    }

    function formatDate(value){
        if (!value) return 'Never';
        var parsed = new Date(String(value).replace(' ', 'T'));
        if (isNaN(parsed.getTime())) return value;
        try {
            return parsed.toLocaleString([], {
                year:'numeric', month:'2-digit', day:'2-digit',
                hour:'2-digit', minute:'2-digit'
            });
        } catch (e) {
            return value;
        }
    }

    function renderTable(rows){
        var wrap = byId('teamTableWrap');
        if (!rows || !rows.length) {
            wrap.innerHTML = '<div class="team-empty"><strong>No team members found</strong>Try another search or invite a new member.</div>';
            return;
        }

        var html = '';
        html += '<table class="team-table">';
        html += '<colgroup><col style="width:27%"><col style="width:20%"><col style="width:22%"><col style="width:15%"><col style="width:16%"></colgroup>';
        html += '<thead><tr>';
        html += '<th><span class="team-sort">Name <i data-lucide="chevrons-up-down"></i></span></th>';
        html += '<th><span class="team-sort">Role <i data-lucide="chevrons-up-down"></i></span></th>';
        html += '<th><span class="team-sort">Last Active <i data-lucide="chevrons-up-down"></i></span></th>';
        html += '<th><span class="team-sort">Status <i data-lucide="chevrons-up-down"></i></span></th>';
        html += '<th></th>';
        html += '</tr></thead><tbody>';

        rows.forEach(function(row){
            var name = memberName(row);
            var role = row.role_name || (Number(row.is_tenant_admin || 0) === 1 ? 'Owner' : 'Team member');
            var status = String(row.status || 'active').toLowerCase();
            var sessions = Number(row.active_session_count || 0);
            var avatar = '';

            if (row.avatar_path) {
                avatar = '<span class="team-avatar"><img src="'+esc(row.avatar_path)+'" alt=""></span>';
            } else {
                avatar = '<span class="team-avatar">'+esc(initials(row))+'</span>';
            }

            html += '<tr>';
            html += '<td><a class="team-member-link" href="invite-team-member.php?user_id='+Number(row.id)+'">'+avatar+'<span>'+esc(name)+'</span></a></td>';
            html += '<td>'+esc(role)+'</td>';
            html += '<td>'+esc(formatDate(row.last_login_at || row.created_at))+'</td>';
            html += '<td><span class="team-status '+esc(status)+'">'+esc(status)+'</span></td>';
            html += '<td><a class="session-link" href="active-login-sessions.php?user_id='+Number(row.id)+'"><i data-lucide="monitor"></i><span>Active login<br>sessions'+(sessions > 0 ? ' ('+sessions+')' : '')+'</span></a></td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
        refreshIcons();
    }

    function updateSeatUi(seats){
        seats = seats || {};
        state.seats = {
            assigned: Number(seats.assigned || 0),
            included: Number(seats.included || 0),
            paid: Number(seats.paid || 0),
            total: Number(seats.total || 0),
            changes_available: !!seats.changes_available
        };

        byId('seatAssigned').textContent = state.seats.assigned + '/' + state.seats.total;
        byId('seatIncluded').textContent = state.seats.included;
        byId('seatPaid').textContent = state.seats.paid;
        byId('seatTotal').textContent = state.seats.total;

        byId('seatMinus').disabled = !state.seats.changes_available;
        byId('seatPlus').disabled = !state.seats.changes_available;
        byId('seatNotice').style.display = state.seats.changes_available ? 'none' : 'flex';
    }

    function loadTeam(){
        byId('teamTableWrap').innerHTML = '<div class="team-loading"><div class="team-spin"></div>Loading team members...</div>';

        var form = new FormData();
        form.append('action', 'list');
        form.append('search', state.search);
        form.append('page', String(state.page));
        form.append('per_page', String(state.perPage));

        apiRequest(form).then(function(data){
            var p = data.pagination || {};
            var rows = data.members || [];
            state.page = Number(p.page || 1);
            state.pages = Math.max(1, Number(p.pages || 1));

            renderTable(rows);
            updateSeatUi(data.seats || {});

            byId('assignedLabel').textContent = 'Assigned seats (' + Number((data.seats || {}).assigned || p.total || 0) + ')';
            byId('teamPageInfo').textContent = 'Showing ' + Number(p.from || 0) + '-' + Number(p.to || 0) + ' of ' + Number(p.total || 0) + ' items';
            byId('prevPage').disabled = state.page <= 1;
            byId('nextPage').disabled = state.page >= state.pages;

            if (data.can_invite === false) {
                byId('inviteMemberBtn').style.display = 'none';
            } else {
                byId('inviteMemberBtn').style.display = 'inline-flex';
            }

            refreshIcons();
        }).catch(function(error){
            byId('teamTableWrap').innerHTML = '<div class="team-empty"><strong>Unable to load team members</strong>'+esc(error.message)+'</div>';
            toast('error', error.message);
        });
    }

    function openSeats(){
        updateSeatUi(state.seats);
        byId('manageSeatsModal').classList.add('open');
        byId('manageSeatsModal').setAttribute('aria-hidden','false');
    }

    function closeSeats(){
        byId('manageSeatsModal').classList.remove('open');
        byId('manageSeatsModal').setAttribute('aria-hidden','true');
    }

    byId('manageSeatsBtn').addEventListener('click', openSeats);
    byId('closeSeatsModal').addEventListener('click', closeSeats);
    byId('confirmSeatsBtn').addEventListener('click', closeSeats);
    byId('manageSeatsModal').addEventListener('click', function(e){
        if (e.target === byId('manageSeatsModal')) closeSeats();
    });

    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') closeSeats();
    });

    byId('teamSearch').addEventListener('input', function(){
        var value = this.value.trim();
        byId('teamSearchClear').classList.toggle('show', value !== '');
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function(){
            state.search = value;
            state.page = 1;
            loadTeam();
        }, 300);
    });

    byId('teamSearchClear').addEventListener('click', function(){
        byId('teamSearch').value = '';
        this.classList.remove('show');
        state.search = '';
        state.page = 1;
        loadTeam();
        byId('teamSearch').focus();
    });

    byId('teamPerPage').addEventListener('change', function(){
        state.perPage = Number(this.value || 25);
        state.page = 1;
        loadTeam();
    });

    byId('prevPage').addEventListener('click', function(){
        if (state.page > 1) {
            state.page -= 1;
            loadTeam();
        }
    });

    byId('nextPage').addEventListener('click', function(){
        if (state.page < state.pages) {
            state.page += 1;
            loadTeam();
        }
    });

    refreshIcons();
    loadTeam();
})(window, document);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
