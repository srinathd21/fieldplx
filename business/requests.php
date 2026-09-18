<?php
/* FieldPlx Requests - Current Template UI - Version 4.1.0 - 2026-09-15 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Requests · FieldPlx';
$pageDescription = 'Manage incoming customer requests';
$activePage = 'requests';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['requests_csrf_token'])) {
    $_SESSION['requests_csrf_token'] = bin2hex(random_bytes(32));
}
$requestsCsrfToken = (string)$_SESSION['requests_csrf_token'];

require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
/* ==========================================================
   FieldPlx Requests - aligned with Clients current UI/template
   ========================================================== */
.rq-page{
    width:100%;
    max-width:none;
    padding:24px 26px 36px;
    background:transparent;
    color:var(--text);
}
.rq-page *{box-sizing:border-box}
.rq-page button,.rq-page input,.rq-page select{font:inherit}

.rq-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin-bottom:20px;
}
.rq-title{
    margin:0;
    color:var(--text);
    font-size:30px;
    line-height:1.1;
    font-weight:var(--font-weight-bold);
    letter-spacing:-.55px;
}
.rq-header-actions{
    display:flex;
    align-items:center;
    gap:8px;
}
.rq-btn{
    min-height:40px;
    padding:0 16px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border:1px solid var(--button-border);
    border-radius:12px;
    background:var(--button-bg);
    color:var(--text);
    box-shadow:none;
    font-size:12px;
    font-weight:var(--font-weight-semibold);
    text-decoration:none!important;
    cursor:pointer;
    transition:.15s ease;
    white-space:nowrap;
}
.rq-btn:hover,.rq-btn:focus{
    border-color:var(--primary);
    color:var(--primary);
    background:var(--button-hover-bg);
    outline:none;
}
.rq-btn.primary{
    border-color:var(--primary);
    background:var(--primary);
    color:var(--primary-text,#fff);
}
.rq-btn.primary:hover,.rq-btn.primary:focus{
    border-color:var(--primary);
    background:var(--primary);
    color:var(--primary-text,#fff);
    filter:brightness(.95);
}
.rq-btn:disabled{opacity:.55;cursor:not-allowed}

.rq-more-wrap{position:relative;z-index:100}
.rq-more-menu{
    width:220px;
    position:absolute;
    top:calc(100% + 6px);
    right:0;
    z-index:150;
    display:none;
    padding:6px;
    border:1px solid var(--card-border);
    border-radius:9px;
    background:var(--card-bg);
    box-shadow:0 18px 50px rgba(15,23,42,.14);
}
.rq-more-wrap.open .rq-more-menu{display:block}
.rq-menu-item{
    width:100%;
    min-height:36px;
    padding:8px 10px;
    display:flex;
    align-items:center;
    gap:9px;
    border:0;
    border-radius:6px;
    background:transparent;
    color:var(--text)!important;
    font-size:11px;
    text-align:left;
    text-decoration:none!important;
    cursor:pointer;
}
.rq-menu-item:hover{background:var(--table-hover-bg)!important;color:var(--text)!important}

/* Loading */
.rq-loading{
    min-height:420px;
    display:grid;
    place-items:center;
    color:var(--muted);
}
.rq-spinner{
    width:34px;
    height:34px;
    border-radius:50%;
    border:3px solid var(--card-border);
    border-top-color:var(--primary);
    animation:rqSpin .8s linear infinite;
}
@keyframes rqSpin{to{transform:rotate(360deg)}}

/* First-use / zero-data screen */
.rq-empty-onboarding{
    position:relative;
    min-height:calc(100vh - 145px);
    overflow:hidden;
    display:none;
    border:1px solid var(--card-border);
    border-radius:22px;
    background:var(--card-bg);
    box-shadow:var(--card-shadow);
}
.rq-empty-onboarding.show{display:block}
.rq-empty-title{
    position:relative;
    z-index:2;
    margin:0;
    padding:28px 32px 0;
    color:var(--text);
    font-size:30px;
    line-height:1.15;
    font-weight:var(--font-weight-bold);
    letter-spacing:-.6px;
}
.rq-empty-center{
    position:relative;
    z-index:3;
    width:min(620px,calc(100% - 40px));
    margin:16px auto 0;
    padding:6px 0 54px;
    text-align:center;
}
.rq-empty-center h2{
    margin:0;
    color:var(--text);
    font-size:28px;
    line-height:1.2;
    font-weight:var(--font-weight-bold);
}
.rq-empty-center>p{
    max-width:575px;
    margin:13px auto 0;
    color:var(--muted);
    font-size:14px;
    line-height:1.55;
}
.rq-empty-action{
    width:230px;
    min-height:224px;
    margin:30px auto 0;
    padding:20px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:flex-start;
    border:1px solid var(--card-border);
    border-radius:18px;
    background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));
    color:var(--text);
    text-decoration:none!important;
    transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease;
}
.rq-empty-action:hover{
    transform:translateY(-3px);
    border-color:var(--primary);
    box-shadow:0 16px 34px color-mix(in srgb,var(--primary) 10%,transparent);
    color:var(--text);
}
.rq-empty-action strong{
    display:block;
    margin:0 0 22px;
    font-size:16px;
    font-weight:var(--font-weight-semibold);
}
.rq-empty-action-icon{
    width:70px;
    height:70px;
    margin:auto 0;
    display:grid;
    place-items:center;
    border-radius:50%;
    background:color-mix(in srgb,var(--primary) 12%,var(--card-bg));
    color:var(--primary);
    font-size:30px;
}
.rq-empty-action small{
    display:block;
    margin-top:20px;
    color:var(--muted);
    font-size:11px;
    line-height:1.45;
}
.rq-empty-ghost{
    position:absolute;
    z-index:1;
    width:260px;
    height:160px;
    border:1px solid var(--card-border);
    border-radius:16px;
    opacity:.24;
    background:linear-gradient(180deg,color-mix(in srgb,var(--body-bg) 68%,transparent),transparent);
}
.rq-empty-ghost::before,.rq-empty-ghost::after{
    content:"";
    position:absolute;
    left:22px;
    height:10px;
    border-radius:999px;
    background:var(--table-border);
}
.rq-empty-ghost::before{top:28px;width:95px;box-shadow:0 25px 0 var(--table-border),0 50px 0 var(--table-border)}
.rq-empty-ghost::after{top:103px;width:145px}
.rq-empty-ghost.left{left:8%;top:128px;transform:rotate(-1.5deg)}
.rq-empty-ghost.right{right:7%;top:128px;transform:rotate(1.5deg)}

/* Data state */
.rq-data{display:none}
.rq-data.show{display:block}
.rq-cards{
    display:grid;
    grid-template-columns:minmax(310px,1.22fr) repeat(2,minmax(230px,.9fr));
    gap:8px;
    margin-bottom:18px;
}
.rq-card{
    min-height:145px;
    position:relative;
    padding:16px 17px;
    border:1px solid var(--card-border);
    border-radius:18px;
    background:var(--card-bg);
    box-shadow:var(--card-shadow);
    overflow:visible;
}
.rq-card-title{
    margin:0;
    color:var(--text);
    font-size:14px;
    font-weight:var(--font-weight-semibold);
}
.rq-card-period{
    margin-top:4px;
    color:var(--muted);
    font-size:10.5px;
}
.rq-card-arrow{
    position:absolute;
    top:17px;
    right:16px;
    color:var(--text);
    font-size:13px;
}
.rq-overview{
    margin-top:12px;
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    column-gap:18px;
    row-gap:6px;
}
.rq-overview-row{
    display:flex;
    align-items:center;
    gap:8px;
    color:var(--text);
    font-size:11px;
}
.rq-overview-row .count{margin-left:auto;color:var(--muted)}
.rq-dot{width:7px;height:7px;flex:0 0 7px;border-radius:50%;background:#607d8b}
.rq-dot.new{background:#36a4c7}
.rq-dot.approval{background:#d8a727}
.rq-dot.overdue{background:#dd5a57}
.rq-dot.assessment{background:#55a849}
.rq-dot.unscheduled{background:#88949c}
.rq-stat-row{
    position:absolute;
    left:17px;
    bottom:15px;
    display:flex;
    align-items:center;
    gap:8px;
}
.rq-stat-value{
    color:var(--text);
    font-size:26px;
    line-height:1;
    font-weight:var(--font-weight-bold);
}
.rq-trend{
    padding:4px 8px;
    border-radius:999px;
    color:var(--status-text);
    background:var(--status-bg);
    font-size:10px;
    font-weight:600;
}
.rq-trend.down{color:#b53f36;background:#fff0ef}
.rq-trend.neutral{color:#687987;background:#eef2f4}

.rq-section-title-row{
    display:flex;
    align-items:baseline;
    gap:8px;
    margin:12px 0 17px;
}
.rq-section-title-row h2{
    margin:0;
    color:var(--text);
    font-size:18px;
    font-weight:var(--font-weight-bold);
}
.rq-result-count{color:var(--muted);font-size:11px}

.rq-toolbar{
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:13px;
    position:relative;
    z-index:40;
}
.rq-filter-pill{
    min-height:36px;
    padding:0 13px;
    display:inline-flex;
    align-items:center;
    gap:8px;
    border:1px solid var(--button-border);
    border-radius:999px;
    background:var(--button-bg);
    color:var(--text);
    font-size:11px;
}
.rq-filter-pill.status{background:var(--table-header-bg)}
.rq-filter-pill strong{font-weight:var(--font-weight-semibold)}
.rq-filter-pill select{
    max-width:180px;
    border:0;
    outline:0;
    background:transparent;
    color:inherit;
    font:inherit;
    cursor:pointer;
}
.rq-toolbar-spacer{margin-left:auto}
.rq-search{width:255px;position:relative}
.rq-search i{
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    color:var(--muted);
    font-size:14px;
    pointer-events:none;
}
.rq-search input{
    width:100%;
    height:40px;
    padding:8px 12px 8px 40px;
    border:1px solid var(--input-border);
    border-radius:8px;
    background:var(--input-bg);
    color:var(--text);
    font-size:11px;
    outline:none;
}
.rq-search input:focus{
    border-color:var(--primary);
    box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 13%,transparent);
}

.rq-table-wrap{
    width:100%;
    overflow-x:auto;
    overflow-y:hidden;
    border:1px solid var(--card-border);
    border-radius:18px;
    background:var(--card-bg);
    box-shadow:var(--card-shadow);
    scrollbar-width:thin;
}
.rq-table{
    width:100%;
    min-width:980px;
    table-layout:fixed;
    border-collapse:collapse;
}
.rq-table th,.rq-table td{
    border-bottom:1px solid var(--table-border);
    color:var(--text);
    font-size:11px;
    text-align:left;
    vertical-align:middle;
}
.rq-table th{
    height:42px;
    padding:8px 12px;
    font-weight:500;
    color:var(--table-header-text);
    background:var(--table-header-bg);
}
.rq-table td{min-height:50px;padding:11px 12px}
.rq-table tbody tr{cursor:pointer;transition:background .12s ease}
.rq-table tbody tr:hover{background:var(--table-hover-bg)}
.rq-table tbody tr:last-child td{border-bottom:0}
.rq-col-customer{width:22%}
.rq-col-request{width:26%}
.rq-col-property{width:28%}
.rq-col-requested{width:14%}
.rq-col-status{width:10%}
.rq-cell-title{color:var(--text);font-weight:var(--font-weight-semibold)}
.rq-subline{margin-top:3px;color:var(--muted);font-size:10px;line-height:1.4}
.rq-property{display:-webkit-box;overflow:hidden;line-height:1.4;-webkit-box-orient:vertical;-webkit-line-clamp:2}
.rq-status{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:4px 9px;
    border-radius:999px;
    color:var(--status-text);
    background:var(--status-bg);
    font-size:10px;
    white-space:nowrap;
}
.rq-status::before{width:7px;height:7px;border-radius:50%;background:#72818c;content:""}
.rq-status.new::before{background:#13a6ce}
.rq-status.converted::before,.rq-status.closed::before{background:#49a04a}
.rq-status.cancelled::before{background:#d95b5b}
.rq-status.quote_required::before,.rq-status.assessment_required::before,.rq-status.job_required::before{background:#d19d20}
.rq-no-match{padding:42px 15px!important;text-align:center!important;color:var(--muted)!important}

.rq-pagination{
    min-height:48px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding-top:12px;
    color:var(--muted);
    font-size:10px;
}
.rq-pagination-actions{display:flex;gap:6px}
.rq-pagination-actions .rq-btn{width:38px;padding:0}

.rq-toast{
    width:min(360px,calc(100vw - 28px));
    position:fixed;
    top:82px;
    right:18px;
    z-index:30000;
    padding:11px 40px 11px 13px;
    display:flex;
    align-items:center;
    gap:9px;
    border-radius:8px;
    color:#fff;
    opacity:0;
    visibility:hidden;
    transform:translateY(-8px);
    transition:.16s ease;
    box-shadow:0 12px 30px rgba(0,38,58,.18);
}
.rq-toast.show{opacity:1;visibility:visible;transform:translateY(0)}
.rq-toast.success{background:#2f8d22}
.rq-toast.error{background:#cf4a43}
.rq-toast.warning{background:#9b7b17}
.rq-toast.info{background:#173c50}
.rq-toast span{flex:1;font-size:11px}
.rq-toast button{position:absolute;right:8px;top:7px;border:0;background:transparent;color:#fff;font-size:17px;cursor:pointer}

/* Dark mode */
html.app-dark-mode .rq-trend.down{border:1px solid #6f3c3c;background:#3b2426;color:#ff9e98}
html.app-dark-mode .rq-trend.neutral{border:1px solid #3b4b54;background:#24333b;color:#c4d0d6}
html.app-dark-mode .rq-empty-action{border-color:#2e414b;background:#17262f;color:#e6f0f5}
html.app-dark-mode .rq-empty-action:hover{border-color:var(--primary);background:#1b2d36;box-shadow:0 16px 34px rgba(0,0,0,.24)}
html.app-dark-mode .rq-empty-ghost{border-color:#2f424b;background:linear-gradient(180deg,#1c2b33,rgba(28,43,51,.12))}
html.app-dark-mode .rq-empty-ghost::before,html.app-dark-mode .rq-empty-ghost::after{background:#31424a}
html.app-dark-mode .rq-empty-ghost::before{box-shadow:0 25px 0 #31424a,0 50px 0 #31424a}
html.app-dark-mode .rq-more-menu{box-shadow:0 18px 50px rgba(0,0,0,.36)}
html.app-dark-mode .rq-toast.info{background:#174a63}
html.app-dark-mode .rq-toast.success{background:#2f7d35}
html.app-dark-mode .rq-toast.warning{background:#7b651f}
html.app-dark-mode .rq-toast.error{background:#a83d39}

@media(max-width:1100px){
    .rq-cards{grid-template-columns:1fr 1fr}
    .rq-cards .rq-card:first-child{grid-column:1/-1}
}
@media(max-width:900px){
    .rq-page{padding:18px 16px 28px}
    .rq-empty-ghost{display:none}
    .rq-cards{grid-template-columns:1fr}
    .rq-cards .rq-card:first-child{grid-column:auto}
}
@media(max-width:767.98px){
    .rq-header{align-items:flex-start}
    .rq-title{font-size:26px}
    .rq-toolbar{flex-wrap:wrap}
    .rq-toolbar-spacer{display:none}
    .rq-search{width:100%;order:-1}
    .rq-table{min-width:820px}
}
@media(max-width:520px){
    .rq-header{flex-direction:column}
    .rq-header-actions{width:100%}
    .rq-header-actions>*{flex:1}
    .rq-header-actions .rq-btn{width:100%}
    .rq-title,.rq-empty-title{font-size:25px}
    .rq-empty-title{padding:22px 20px 0}
    .rq-empty-action{min-height:180px;width:min(230px,100%)}
}
</style>

<div class="rq-page">
    <section class="rq-header">
        <h1 class="rq-title">Requests</h1>
        <div class="rq-header-actions" id="requestActions" style="display:none">
            <a class="rq-btn primary" href="add-request.php"><i class="bi bi-plus-lg"></i> New Request</a>
            <div class="rq-more-wrap" id="rqMoreWrap">
                <button type="button" class="rq-btn" id="moreActionsBtn" aria-expanded="false"><i class="bi bi-three-dots"></i> More Actions</button>
                <div class="rq-more-menu" id="moreActionsMenu" aria-hidden="true">
                    <a class="rq-menu-item" href="request-booking-settings.php"><i class="bi bi-ui-checks-grid"></i> Customize Form</a>
                    <a class="rq-menu-item" href="request-booking-settings.php"><i class="bi bi-code-square"></i> Share or Embed</a>
                </div>
            </div>
        </div>
    </section>

    <section class="rq-loading" id="requestLoading" aria-live="polite">
        <div class="rq-spinner" aria-hidden="true"></div>
    </section>

    <section class="rq-empty-onboarding" id="requestEmptyState">
        <h1 class="rq-empty-title">Requests</h1>
        <div class="rq-empty-ghost left" aria-hidden="true"></div>
        <div class="rq-empty-ghost right" aria-hidden="true"></div>
        <div class="rq-empty-center">
            <h2>Create a request</h2>
            <p>Requests capture the details you need when a customer reaches out about new work. Get started by creating a new one.</p>
            <a class="rq-empty-action" href="add-request.php">
                <strong>Create a Request</strong>
                <span class="rq-empty-action-icon"><i class="bi bi-plus-lg"></i></span>
                <small>Create your first customer service request.</small>
            </a>
        </div>
    </section>

    <section class="rq-data" id="requestDataState">
        <section class="rq-cards">
            <article class="rq-card">
                <h2 class="rq-card-title">Overview</h2>
                <div class="rq-overview">
                    <div class="rq-overview-row"><span class="rq-dot approval"></span><span>Needs approval</span><span class="count" id="statNeedsApproval">0</span></div>
                    <div class="rq-overview-row"><span class="rq-dot new"></span><span>New</span><span class="count" id="statNew">0</span></div>
                    <div class="rq-overview-row"><span class="rq-dot assessment"></span><span>Assessment complete</span><span class="count" id="statAssessment">0</span></div>
                    <div class="rq-overview-row"><span class="rq-dot overdue"></span><span>Overdue</span><span class="count" id="statOverdue">0</span></div>
                    <div class="rq-overview-row"><span class="rq-dot unscheduled"></span><span>Unscheduled</span><span class="count" id="statUnscheduled">0</span></div>
                </div>
            </article>

            <article class="rq-card">
                <span class="rq-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                <h2 class="rq-card-title">New requests</h2>
                <div class="rq-card-period">Past 30 days</div>
                <div class="rq-stat-row">
                    <strong class="rq-stat-value" id="statNew30">0</strong>
                    <span class="rq-trend neutral" id="statNewTrend">0%</span>
                </div>
            </article>

            <article class="rq-card">
                <span class="rq-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                <h2 class="rq-card-title">Conversion rate</h2>
                <div class="rq-card-period">Past 30 days</div>
                <div class="rq-stat-row">
                    <strong class="rq-stat-value" id="statConversion">0%</strong>
                    <span class="rq-trend neutral" id="statConversionTrend">0%</span>
                </div>
            </article>
        </section>

        <section class="rq-section-title-row">
            <h2>All requests</h2>
            <span class="rq-result-count" id="resultCount">(0 results)</span>
        </section>

        <section class="rq-toolbar">
            <label class="rq-filter-pill status" for="statusFilter">
                <strong>Status</strong><span>|</span>
                <select id="statusFilter" aria-label="Filter requests by status">
                    <option value="">All</option>
                    <option value="new">New</option>
                    <option value="contacting">Contacting</option>
                    <option value="information_required">Information Required</option>
                    <option value="assessment_required">Assessment Required</option>
                    <option value="quote_required">Quote Required</option>
                    <option value="job_required">Job Required</option>
                    <option value="converted">Converted</option>
                    <option value="closed">Closed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </label>

            <label class="rq-filter-pill" for="dateFilter">
                <i class="bi bi-calendar3"></i><strong>Date</strong><span>|</span>
                <select id="dateFilter" aria-label="Filter requests by date">
                    <option value="">All</option>
                    <option value="today">Today</option>
                    <option value="last_7">Last 7 days</option>
                    <option value="last_30">Last 30 days</option>
                    <option value="this_month">This month</option>
                </select>
            </label>

            <div class="rq-toolbar-spacer"></div>
            <div class="rq-search">
                <i class="bi bi-search"></i>
                <input type="search" id="requestSearch" placeholder="Search requests..." autocomplete="off">
            </div>
        </section>

        <section class="rq-table-wrap">
            <table class="rq-table">
                <thead>
                    <tr>
                        <th class="rq-col-customer">Customer</th>
                        <th class="rq-col-request">Request</th>
                        <th class="rq-col-property">Property</th>
                        <th class="rq-col-requested">Requested</th>
                        <th class="rq-col-status">Status</th>
                    </tr>
                </thead>
                <tbody id="requestsBody">
                    <tr><td colspan="5" class="rq-no-match">Loading requests...</td></tr>
                </tbody>
            </table>
        </section>

        <div class="rq-pagination" id="rqPagination">
            <span id="countText">Showing 0 requests</span>
            <div class="rq-pagination-actions">
                <button type="button" class="rq-btn" id="prevPage" aria-label="Previous page"><i class="bi bi-chevron-left"></i></button>
                <button type="button" class="rq-btn" id="nextPage" aria-label="Next page"><i class="bi bi-chevron-right"></i></button>
            </div>
        </div>
    </section>
</div>

<div class="rq-toast info" id="requestToast">
    <span id="requestToastText">Notification</span>
    <button type="button" id="requestToastClose" aria-label="Close notification"><i class="bi bi-x-lg"></i></button>
</div>

<script>
(function(){
'use strict';

var csrfToken = <?= json_encode($requestsCsrfToken) ?>;
var apiUrl = 'api/requests-list.php';
var state = {
    page:1,
    perPage:10,
    search:'',
    status:'',
    dateFilter:'',
    pages:1,
    totalAll:0
};

var searchTimer = null;
var loading = document.getElementById('requestLoading');
var emptyState = document.getElementById('requestEmptyState');
var dataState = document.getElementById('requestDataState');
var actions = document.getElementById('requestActions');
var tableBody = document.getElementById('requestsBody');
var toast = document.getElementById('requestToast');
var toastText = document.getElementById('requestToastText');
var moreWrap = document.getElementById('rqMoreWrap');
var moreBtn = document.getElementById('moreActionsBtn');
var moreMenu = document.getElementById('moreActionsMenu');

function esc(value){
    return String(value == null ? '' : value)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function readable(value){
    return String(value || '-')
        .replace(/_/g,' ')
        .replace(/\b\w/g,function(x){return x.toUpperCase();});
}

function notify(type,message){
    toast.className='rq-toast '+(type || 'info')+' show';
    toastText.textContent=message || 'Notification';
    window.clearTimeout(notify.timer);
    notify.timer=window.setTimeout(function(){toast.classList.remove('show');},3300);
}

function parseResponse(response){
    return response.text().then(function(raw){
        var text=(raw || '').trim();
        var data;
        try{
            data=text ? JSON.parse(text) : {};
        }catch(e){
            text=text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();
            throw new Error(text || 'The Requests API returned an invalid response.');
        }
        if(!response.ok || !data || data.success !== true){
            throw new Error(data && data.message ? data.message : 'Unable to load requests.');
        }
        return data;
    });
}

function requestList(){
    var fd=new FormData();
    fd.append('csrf_token',csrfToken);
    fd.append('action','list');
    fd.append('page',state.page);
    fd.append('per_page',state.perPage);
    fd.append('search',state.search);
    fd.append('status',state.status);
    fd.append('date_filter',state.dateFilter);

    return fetch(apiUrl,{
        method:'POST',
        body:fd,
        credentials:'same-origin',
        headers:{
            'X-Requested-With':'XMLHttpRequest',
            'Accept':'application/json'
        }
    }).then(parseResponse);
}

function formatDate(value){
    if(!value) return '-';
    var d=new Date(String(value).replace(' ','T'));
    if(isNaN(d.getTime())) return String(value);
    return d.toLocaleDateString(undefined,{month:'short',day:'2-digit',year:'numeric'});
}

function timeAgo(value){
    if(!value) return '-';
    var d=new Date(String(value).replace(' ','T'));
    if(isNaN(d.getTime())) return formatDate(value);
    var sec=Math.max(0,Math.floor((Date.now()-d.getTime())/1000));
    if(sec<60) return 'Just now';
    var min=Math.floor(sec/60);
    if(min<60) return min+' minute'+(min===1?'':'s')+' ago';
    var hrs=Math.floor(min/60);
    if(hrs<24) return hrs+' hour'+(hrs===1?'':'s')+' ago';
    var days=Math.floor(hrs/24);
    if(days<30) return days+' day'+(days===1?'':'s')+' ago';
    return formatDate(value);
}

function requestProperty(row){
    var parts=[];
    if(row.location_address_line1) parts.push(row.location_address_line1);
    else if(row.location_name) parts.push(row.location_name);
    if(row.location_address_line2) parts.push(row.location_address_line2);
    var city=[row.location_city || '',row.location_state || ''].filter(Boolean).join(', ');
    if(row.location_postal_code) city+=(city ? ' ' : '')+row.location_postal_code;
    if(city) parts.push(city);
    return parts.length ? parts : ['Property not confirmed'];
}

function trendClass(value){
    value=Number(value || 0);
    return value>0 ? '' : (value<0 ? 'down' : 'neutral');
}

function trendText(value){
    value=Number(value || 0);
    return (value>0 ? '↑ ' : value<0 ? '↓ ' : '')+Math.abs(Math.round(value))+'%';
}

function applySummary(summary){
    summary=summary || {};
    document.getElementById('statNeedsApproval').textContent=Number(summary.needs_approval || 0);
    document.getElementById('statNew').textContent=Number(summary.new_count || 0);
    document.getElementById('statAssessment').textContent=Number(summary.assessment_completed || 0);
    document.getElementById('statOverdue').textContent=Number(summary.overdue || 0);
    document.getElementById('statUnscheduled').textContent=Number(summary.unscheduled || 0);
    document.getElementById('statNew30').textContent=Number(summary.requests_30 || 0);
    document.getElementById('statConversion').textContent=Math.round(Number(summary.conversion_rate || 0))+'%';

    var newTrend=document.getElementById('statNewTrend');
    var conversionTrend=document.getElementById('statConversionTrend');
    newTrend.textContent=trendText(summary.requests_change);
    newTrend.className='rq-trend '+trendClass(summary.requests_change);
    conversionTrend.textContent=trendText(summary.conversion_change);
    conversionTrend.className='rq-trend '+trendClass(summary.conversion_change);
}

function showMode(totalAll){
    loading.style.display='none';
    state.totalAll=Number(totalAll || 0);

    if(state.totalAll===0){
        actions.style.display='none';
        dataState.classList.remove('show');
        emptyState.classList.add('show');
        return;
    }

    actions.style.display='flex';
    emptyState.classList.remove('show');
    dataState.classList.add('show');
}

function render(rows){
    if(!rows || !rows.length){
        tableBody.innerHTML='<tr><td colspan="5" class="rq-no-match">No requests match the selected filters.</td></tr>';
        return;
    }

    var html='';
    rows.forEach(function(row){
        var propertyHtml=requestProperty(row).map(esc).join('<br>');
        html+='<tr data-id="'+Number(row.id)+'" tabindex="0" role="link" aria-label="View '+esc(row.title || row.request_no || 'request')+'">'+
            '<td><div class="rq-cell-title">'+esc(row.client_name || '-')+'</div>'+
                (row.client_phone ? '<div class="rq-subline">'+esc(row.client_phone)+'</div>' : '')+
            '</td>'+
            '<td><div class="rq-cell-title">'+esc(row.title || row.request_no || '-')+'</div><div class="rq-subline">'+esc(row.request_no || '')+'</div></td>'+
            '<td><div class="rq-property">'+propertyHtml+'</div></td>'+
            '<td><div>'+esc(timeAgo(row.created_at))+'</div><div class="rq-subline">'+esc(formatDate(row.created_at))+'</div></td>'+
            '<td><span class="rq-status '+esc(row.status || '')+'">'+esc(readable(row.status))+'</span></td>'+
        '</tr>';
    });
    tableBody.innerHTML=html;
}

function load(){
    requestList().then(function(data){
        var pagination=data.pagination || {};
        var summary=data.summary || {};
        var totalAll=Number(summary.total_all != null ? summary.total_all : (data.tenant_total || 0));

        showMode(totalAll);
        applySummary(summary);
        if(totalAll===0) return;

        render(data.requests || []);

        var filtered=Number(pagination.total || 0);
        state.pages=Number(pagination.pages || 1);

        document.getElementById('resultCount').textContent='('+filtered+' result'+(filtered===1 ? '' : 's')+')';
        document.getElementById('countText').textContent=filtered
            ? 'Showing '+Number(pagination.from || 0)+'-'+Number(pagination.to || 0)+' of '+filtered+' requests'
            : 'Showing 0 requests';

        document.getElementById('prevPage').disabled=state.page<=1;
        document.getElementById('nextPage').disabled=state.page>=state.pages;
    }).catch(function(error){
        loading.style.display='none';
        emptyState.classList.remove('show');
        dataState.classList.add('show');
        actions.style.display='flex';
        tableBody.innerHTML='<tr><td colspan="5" class="rq-no-match">'+esc(error.message)+'</td></tr>';
        notify('error',error.message);
    });
}

tableBody.addEventListener('click',function(event){
    var row=event.target.closest('tr[data-id]');
    if(row){
        window.location.href='request-view.php?request_id='+encodeURIComponent(row.dataset.id);
    }
});

tableBody.addEventListener('keydown',function(event){
    if(event.key!=='Enter' && event.key!==' ') return;
    var row=event.target.closest('tr[data-id]');
    if(row){
        event.preventDefault();
        window.location.href='request-view.php?request_id='+encodeURIComponent(row.dataset.id);
    }
});

document.getElementById('requestSearch').addEventListener('input',function(event){
    window.clearTimeout(searchTimer);
    searchTimer=window.setTimeout(function(){
        state.search=event.target.value.trim();
        state.page=1;
        load();
    },250);
});

document.getElementById('statusFilter').addEventListener('change',function(event){
    state.status=event.target.value;
    state.page=1;
    load();
});

document.getElementById('dateFilter').addEventListener('change',function(event){
    state.dateFilter=event.target.value;
    state.page=1;
    load();
});

document.getElementById('prevPage').addEventListener('click',function(){
    if(state.page>1){state.page--;load();}
});

document.getElementById('nextPage').addEventListener('click',function(){
    if(state.page<state.pages){state.page++;load();}
});

moreBtn.addEventListener('click',function(event){
    event.stopPropagation();
    var open=!moreWrap.classList.contains('open');
    moreWrap.classList.toggle('open',open);
    moreBtn.setAttribute('aria-expanded',open ? 'true' : 'false');
    moreMenu.setAttribute('aria-hidden',open ? 'false' : 'true');
});

document.addEventListener('click',function(event){
    if(!event.target.closest('.rq-more-wrap')){
        moreWrap.classList.remove('open');
        moreBtn.setAttribute('aria-expanded','false');
        moreMenu.setAttribute('aria-hidden','true');
    }
});

document.getElementById('requestToastClose').addEventListener('click',function(){
    toast.classList.remove('show');
});

load();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
