<?php
/* FieldPlx Client Merge Page - Version 1.1.0 - Invoice/Add Invoice UI */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Merge Clients';
$pageDescription = 'Merge duplicate client records while preserving linked work and history';
$activePage = 'clients';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['client_merge_csrf_token'])) {
    $_SESSION['client_merge_csrf_token'] = bin2hex(random_bytes(32));
}
$clientMergeCsrfToken = (string)$_SESSION['client_merge_csrf_token'];
$tenantId = isset($currentTenantId) ? (int)$currentTenantId : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);
$userId = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);

function cmpTableExists(PDO $pdo, $table)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n");
    $q->execute(array(':n' => $table));
    return (int)$q->fetchColumn() > 0;
}

function cmpColumnExists(PDO $pdo, $table, $column)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $q->execute(array(':t' => $table, ':c' => $column));
    return (int)$q->fetchColumn() > 0;
}

function cmpCanView(PDO $pdo, $tenantId, $userId)
{
    if ($tenantId <= 0 || $userId <= 0 || !cmpTableExists($pdo, 'users')) {
        return false;
    }

    $hasRoles = cmpTableExists($pdo, 'roles');
    $hasUserRole = cmpColumnExists($pdo, 'users', 'role_id');
    $hasTenantAdmin = cmpColumnExists($pdo, 'users', 'is_tenant_admin');
    $hasDeletedAt = cmpColumnExists($pdo, 'users', 'deleted_at');
    $hasRoleAdmin = $hasRoles && cmpColumnExists($pdo, 'roles', 'is_admin');

    $roleJoin = ($hasRoles && $hasUserRole)
        ? "LEFT JOIN roles r ON r.id = u.role_id AND r.tenant_id = u.tenant_id"
        : '';
    $roleSelect = $hasUserRole ? 'u.role_id' : '0 AS role_id';
    $tenantAdminSelect = $hasTenantAdmin ? 'u.is_tenant_admin' : '0 AS is_tenant_admin';
    $roleAdminSelect = $hasRoleAdmin ? 'COALESCE(r.is_admin,0)' : '0';
    $deletedWhere = $hasDeletedAt ? ' AND u.deleted_at IS NULL' : '';

    $q = $pdo->prepare("SELECT $roleSelect, $tenantAdminSelect, $roleAdminSelect AS role_admin
                        FROM users u
                        $roleJoin
                        WHERE u.id=:u AND u.tenant_id=:t$deletedWhere
                        LIMIT 1");
    $q->execute(array(':u' => $userId, ':t' => $tenantId));
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return false;
    }

    if ((int)$u['is_tenant_admin'] === 1 || (int)$u['role_admin'] === 1) {
        return true;
    }

    if (!cmpTableExists($pdo, 'permissions')) {
        return false;
    }

    $q = $pdo->prepare("SELECT id FROM permissions WHERE permission_code='clients.view' LIMIT 1");
    $q->execute();
    $permissionId = (int)$q->fetchColumn();
    if ($permissionId <= 0) {
        return false;
    }

    if (cmpTableExists($pdo, 'user_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u AND permission_id=:p LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':u' => $userId, ':p' => $permissionId));
        $access = $q->fetchColumn();
        if ($access !== false) {
            return $access === 'allow';
        }
    }

    $roleId = (int)($u['role_id'] ?? 0);
    if ($roleId > 0 && cmpTableExists($pdo, 'role_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=:t AND role_id=:r AND permission_id=:p LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':r' => $roleId, ':p' => $permissionId));
        return $q->fetchColumn() === 'allow';
    }

    return false;
}

if (!cmpCanView($pdo, $tenantId, $userId)) {
    http_response_code(403);
    exit('You do not have permission to view client merge.');
}

$initialIds = array();
if (!empty($_GET['client_ids'])) {
    foreach (explode(',', (string)$_GET['client_ids']) as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $initialIds[$id] = $id;
        }
    }
}
$initialIds = array_values($initialIds);
$initialPrimaryId = isset($_GET['primary_id']) ? (int)$_GET['primary_id'] : 0;


require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* FieldPlx Client Merge - current shared template */
:root{
    --cm-primary:var(--primary,#08a5d2);
    --cm-text:var(--text,#183445);
    --cm-muted:var(--muted,#647787);
    --cm-border:var(--card-border,#dce3e7);
    --cm-card:var(--card-bg,#fff);
    --cm-input:var(--input-bg,var(--card-bg,#fff));
    --cm-input-border:var(--input-border,var(--card-border,#dce3e7));
    --cm-danger:#e1554f;
    --cm-warning:#b38613;
}
.cm-page{
    width:100%;
    max-width:none;
    min-height:calc(100vh - 120px);
    padding:24px 26px 112px;
    color:var(--cm-text);
    background:transparent;
    font-family:inherit;
}
.cm-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin-bottom:20px;
}
.cm-head-copy{min-width:0}
.cm-title{
    margin:0;
    color:var(--cm-text);
    font-size:30px;
    line-height:1.1;
    font-weight:var(--font-weight-bold,700);
    letter-spacing:-.55px;
}
.cm-subtitle{
    max-width:850px;
    margin:7px 0 0;
    color:var(--cm-muted);
    font-size:13px;
    line-height:1.55;
}
.cm-head-actions,.cm-actions{display:flex;align-items:center;gap:8px;flex:0 0 auto}
.cm-btn{
    min-height:40px;
    padding:0 16px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border:1px solid var(--button-border,var(--cm-border));
    border-radius:12px;
    background:var(--button-bg,var(--cm-card));
    color:var(--cm-text);
    font-family:inherit;
    font-size:12px;
    font-weight:var(--font-weight-semibold,600);
    line-height:1;
    text-decoration:none!important;
    cursor:pointer;
    white-space:nowrap;
    box-shadow:none;
    transition:.15s ease;
}
.cm-btn:hover,.cm-btn:focus{
    border-color:var(--cm-primary);
    background:var(--button-hover-bg,color-mix(in srgb,var(--cm-primary) 8%,var(--cm-card)));
    color:var(--cm-primary);
    outline:0;
}
.cm-btn.primary{border-color:var(--cm-primary);background:var(--cm-primary);color:var(--primary-text,#fff)}
.cm-btn.primary:hover,.cm-btn.primary:focus{filter:brightness(.95);color:var(--primary-text,#fff)}
.cm-btn:disabled{opacity:.55;cursor:not-allowed;filter:none}
.cm-help{
    padding:13px 15px;
    display:flex;
    align-items:flex-start;
    gap:10px;
    border:1px solid color-mix(in srgb,var(--cm-primary) 24%,var(--cm-border));
    border-radius:14px;
    background:color-mix(in srgb,var(--cm-primary) 7%,var(--cm-card));
    color:var(--cm-text);
    font-size:12px;
    line-height:1.55;
}
.cm-help i{margin-top:1px;color:var(--cm-primary);font-size:17px}.cm-help strong{color:var(--cm-text)}
.cm-grid{
    display:grid;
    grid-template-columns:minmax(0,1.65fr) minmax(320px,.85fr);
    gap:14px;
    margin-top:14px;
    align-items:start;
}
.cm-card,.cm-preview-card{
    min-width:0;
    border:1px solid var(--cm-border);
    border-radius:18px;
    background:var(--cm-card);
    box-shadow:var(--card-shadow,none);
}
.cm-selected-card{position:sticky;top:86px}
.cm-card-head{padding:16px 17px 13px;border-bottom:1px solid var(--table-border,var(--cm-border))}
.cm-card-head h2,.cm-preview-card h3{margin:0;color:var(--cm-text);font-weight:var(--font-weight-semibold,600)}
.cm-card-head h2{font-size:16px}.cm-card-head p{margin:5px 0 0;color:var(--cm-muted);font-size:12px;line-height:1.45}
.cm-search-wrap{position:relative;margin:14px 15px 11px}
.cm-search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--cm-muted);font-size:16px;pointer-events:none}
.cm-search{
    width:100%;height:42px;padding:0 13px 0 42px;
    border:1px solid var(--cm-input-border);border-radius:12px;
    background:var(--cm-input);color:var(--cm-text);outline:0;font:13px inherit;
}
.cm-search::placeholder{color:color-mix(in srgb,var(--cm-muted) 82%,transparent);opacity:1}
.cm-search:focus{border-color:var(--cm-primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--cm-primary) 13%,transparent)}
.cm-results-meta{padding:0 16px 10px;color:var(--cm-muted);font-size:11px}
.cm-results{max-height:520px;overflow:auto;border-top:1px solid var(--table-border,var(--cm-border));scrollbar-width:thin}
.cm-results::-webkit-scrollbar{width:5px}.cm-results::-webkit-scrollbar-thumb{border-radius:999px;background:color-mix(in srgb,var(--cm-muted) 42%,transparent)}
.cm-result{
    min-height:74px;padding:11px 14px;display:grid;grid-template-columns:22px minmax(0,1fr) auto;
    gap:10px;align-items:flex-start;border-bottom:1px solid var(--table-border,var(--cm-border));
    background:var(--cm-card);cursor:pointer;transition:.12s ease;
}
.cm-result:last-child{border-bottom:0}.cm-result:hover{background:var(--table-hover-bg,color-mix(in srgb,var(--cm-primary) 6%,var(--cm-card)))}
.cm-result.selected{background:color-mix(in srgb,var(--cm-primary) 8%,var(--cm-card))}
.cm-result input[type=checkbox],.cm-selected-radio{width:17px;height:17px;margin:2px 0 0;accent-color:var(--cm-primary)}
.cm-result-name{overflow:hidden;color:var(--cm-text);font-size:13px;font-weight:var(--font-weight-semibold,600);text-overflow:ellipsis;white-space:nowrap}
.cm-result-company{margin-top:2px;overflow:hidden;color:var(--cm-muted);font-size:11px;text-overflow:ellipsis;white-space:nowrap}
.cm-result-contact,.cm-result-address{margin-top:5px;display:flex;flex-wrap:wrap;gap:5px 12px;color:var(--cm-muted);font-size:11px;line-height:1.3}
.cm-result-contact span{display:inline-flex;align-items:center;gap:5px;min-width:0}
.cm-status{
    min-height:23px;padding:3px 9px;display:inline-flex;align-items:center;gap:6px;border-radius:999px;
    background:var(--status-bg,color-mix(in srgb,var(--cm-primary) 11%,var(--cm-card)));
    color:var(--status-text,var(--cm-primary));font-size:11px;font-weight:500;text-transform:capitalize;white-space:nowrap;
}
.cm-status:before{width:7px;height:7px;border-radius:50%;background:currentColor;content:''}
.cm-status.new,.cm-status.lead{color:#9a741a;background:color-mix(in srgb,#d5a51e 15%,var(--cm-card))}
.cm-status.inactive,.cm-status.archived{color:var(--cm-muted);background:color-mix(in srgb,var(--cm-muted) 10%,var(--cm-card))}
.cm-empty{min-height:110px;padding:26px 18px;display:flex;align-items:center;justify-content:center;color:var(--cm-muted);font-size:12px;text-align:center}
.cm-selected-body{padding:14px 15px 16px}.cm-selected-count{margin-bottom:10px;color:var(--cm-muted);font-size:11px}
.cm-selected-item{
    min-height:58px;padding:9px 10px;display:grid;grid-template-columns:19px minmax(0,1fr) 28px;gap:8px;align-items:center;
    border:1px solid var(--cm-border);border-radius:12px;background:var(--cm-card)
}
.cm-selected-item + .cm-selected-item{margin-top:8px}.cm-selected-item.primary{border-color:color-mix(in srgb,var(--cm-primary) 45%,var(--cm-border));background:color-mix(in srgb,var(--cm-primary) 8%,var(--cm-card))}
.cm-selected-radio{margin:0}.cm-selected-name{overflow:hidden;color:var(--cm-text);font-size:12px;font-weight:var(--font-weight-semibold,600);text-overflow:ellipsis;white-space:nowrap}
.cm-selected-small{margin-top:3px;overflow:hidden;color:var(--cm-muted);font-size:10px;text-overflow:ellipsis;white-space:nowrap}
.cm-remove{width:28px;height:28px;padding:0;display:grid;place-items:center;border:0;border-radius:8px;background:transparent;color:var(--cm-muted);cursor:pointer}
.cm-remove:hover{background:color-mix(in srgb,var(--cm-danger) 12%,var(--cm-card));color:var(--cm-danger)}
.cm-primary-note{margin-top:12px;padding:10px 11px;border-radius:11px;background:color-mix(in srgb,var(--cm-card) 90%,var(--body-bg,#f6f8fb));color:var(--cm-muted);font-size:10.5px;line-height:1.5}
.cm-primary-note strong{color:var(--cm-text)}
.cm-preview{display:none;margin-top:14px}.cm-preview.show{display:block}.cm-preview-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.cm-preview-card{padding:16px 17px}.cm-preview-card h3{margin-bottom:11px;font-size:15px}
.cm-counts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.cm-count,.cm-fill{border:1px solid var(--cm-border);border-radius:12px;background:color-mix(in srgb,var(--cm-card) 94%,var(--body-bg,#f6f8fb))}
.cm-count{min-height:58px;padding:10px 11px}.cm-count strong{display:block;color:var(--cm-text);font-size:18px;line-height:1.1}.cm-count span{display:block;margin-top:4px;color:var(--cm-muted);font-size:10px}
.cm-fill-list{display:grid;gap:8px}.cm-fill{padding:9px 10px}.cm-fill strong{display:block;color:var(--cm-muted);font-size:10px}.cm-fill span{display:block;margin-top:2px;color:var(--cm-text);font-size:12px}.cm-fill small{display:block;margin-top:2px;color:var(--cm-muted);font-size:9px}
.cm-sticky{
    position:fixed;left:var(--fieldplx-sidebar-width,var(--sidebar-width,206px));right:0;bottom:0;z-index:1250;
    min-height:66px;padding:10px 26px;display:flex;align-items:center;justify-content:space-between;gap:12px;
    border-top:1px solid var(--cm-border);background:color-mix(in srgb,var(--cm-card) 96%,transparent);
    box-shadow:0 -8px 24px rgba(0,0,0,.07);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);
    transition:left .2s ease;
}
body.fieldplx-sidebar-collapsed .cm-sticky{left:var(--fieldplx-sidebar-collapsed-width,var(--sidebar-collapsed-width,80px))}
.cm-sticky-note{min-width:0;color:var(--cm-muted);font-size:11px}.cm-loader{width:13px;height:13px;display:none;border:2px dotted currentColor;border-radius:50%;animation:cmSpin .75s linear infinite}.cm-btn.loading .cm-loader{display:inline-block}@keyframes cmSpin{to{transform:rotate(360deg)}}
.cm-modal-backdrop{display:none;position:fixed;inset:0;z-index:16000;align-items:center;justify-content:center;padding:18px;background:rgba(2,12,18,.58);backdrop-filter:blur(2px)}
.cm-modal-backdrop.show{display:flex}.cm-modal{width:min(540px,calc(100vw - 30px));max-height:calc(100vh - 36px);overflow:auto;border:1px solid var(--cm-border);border-radius:18px;background:var(--cm-card);box-shadow:0 22px 60px rgba(0,0,0,.23)}
.cm-modal-head{padding:20px 22px 12px;display:flex;align-items:center;justify-content:space-between;gap:15px}.cm-modal-head h3{margin:0;color:var(--cm-text);font-size:21px;font-weight:var(--font-weight-semibold,600)}
.cm-modal-close{width:34px;height:34px;padding:0;border:0;border-radius:9px;background:transparent;color:var(--cm-text);font-size:20px;cursor:pointer}.cm-modal-close:hover{background:var(--table-hover-bg,color-mix(in srgb,var(--cm-primary) 6%,var(--cm-card)))}
.cm-modal-body{padding:10px 22px 12px;color:var(--cm-muted);font-size:13px;line-height:1.55}.cm-warning{margin-top:13px;padding:11px 12px;border-radius:12px;background:color-mix(in srgb,#d5a51e 12%,var(--cm-card));color:var(--cm-text)}
.cm-modal-footer{padding:12px 22px 20px;display:flex;align-items:center;justify-content:flex-end;gap:8px}
.cm-toast{position:fixed;top:82px;right:18px;z-index:17000;width:min(390px,calc(100vw - 36px));padding:12px 14px;display:flex;align-items:center;gap:9px;border-radius:12px;background:#1f5f7a;color:#fff;box-shadow:0 12px 30px rgba(0,0,0,.18);opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s;font-size:13px;font-weight:600}
.cm-toast.show{opacity:1;transform:translateY(0);pointer-events:auto}.cm-toast.success{background:#2f8d25}.cm-toast.error{background:#c94f55}.cm-toast.warning{background:#9a741a}.cm-toast.info{background:#1f5f7a}.cm-toast span{min-width:0;flex:1}.cm-toast button{border:0;background:transparent;color:#fff;cursor:pointer}
html.app-dark-mode .cm-card,html.app-dark-mode .cm-preview-card,html.app-dark-mode .cm-selected-item,html.app-dark-mode .cm-modal,html.app-dark-mode .cm-result{box-shadow:none}
@media(max-width:1199.98px){.cm-grid{grid-template-columns:minmax(0,1.35fr) minmax(300px,.85fr)}}
@media(max-width:991.98px){.cm-page{padding:20px 16px 110px}.cm-grid{grid-template-columns:1fr}.cm-selected-card{position:static}.cm-sticky,body.fieldplx-sidebar-collapsed .cm-sticky{left:0}}
@media(max-width:767.98px){.cm-page{padding:18px 12px 126px}.cm-head{align-items:flex-start;flex-direction:column}.cm-head-actions{width:100%}.cm-head-actions .cm-btn{width:auto}.cm-title{font-size:25px}.cm-preview-grid{grid-template-columns:1fr}.cm-sticky{min-height:72px;padding:10px 12px}.cm-sticky-note{flex:1}.cm-actions{width:auto}}
@media(max-width:575.98px){.cm-page{padding-bottom:158px}.cm-result{grid-template-columns:21px minmax(0,1fr)}.cm-result>.cm-status{grid-column:2;justify-self:start}.cm-counts{grid-template-columns:1fr}.cm-sticky{min-height:128px;flex-direction:column;align-items:stretch}.cm-sticky-note{text-align:center}.cm-actions{width:100%}.cm-actions .cm-btn{flex:1}.cm-toast{top:72px;left:12px;right:12px;width:auto}.cm-modal-footer{flex-direction:column-reverse}.cm-modal-footer .cm-btn{width:100%}}
</style>
<div class="cm-page">
                <header class="cm-head">
                    <div class="cm-head-copy">
                        <h1 class="cm-title">Merge Clients</h1>
                        <p class="cm-subtitle">Combine duplicate client profiles into one accurate record. Select at least two clients, choose which profile to keep, then review the work and billing records that will move.</p>
                    </div>
                    <div class="cm-head-actions">
                        <a href="clients.php" class="cm-btn"><i class="bi bi-arrow-left"></i> Back to Clients</a>
                    </div>
                </header>

                <div class="cm-help">
                    <i class="bi bi-info-circle"></i>
                    <div><strong>The profile you keep is never deleted.</strong> Existing values on that profile stay in place. Blank profile fields can be filled from the duplicate records, linked work moves to the kept client, and the duplicate client profiles are archived after the merge.</div>
                </div>

                <div class="cm-grid">
                    <section class="cm-card">
                        <div class="cm-card-head">
                            <h2>Select duplicate clients</h2>
                            <p>Search by client name, company, phone, email, or property address. You can merge up to 10 profiles at once.</p>
                        </div>
                        <div class="cm-search-wrap">
                            <i class="bi bi-search"></i>
                            <input class="cm-search" type="search" id="clientSearch" placeholder="Search clients..." autocomplete="off" />
                        </div>
                        <div class="cm-results-meta" id="resultsMeta">Loading clients...</div>
                        <div class="cm-results" id="clientResults">
                            <div class="cm-empty">Loading clients...</div>
                        </div>
                    </section>

                    <section class="cm-card cm-selected-card">
                        <div class="cm-card-head">
                            <h2>Clients to merge</h2>
                            <p>Choose the one profile that should remain after the merge.</p>
                        </div>
                        <div class="cm-selected-body">
                            <div class="cm-selected-count" id="selectedCount">0 clients selected</div>
                            <div id="selectedList"><div class="cm-empty">Select at least two client profiles.</div></div>
                            <div class="cm-primary-note">The selected <strong>Keep this profile</strong> client controls the final name and existing profile values. Linked locations, jobs, quotes, invoices, payments, contacts, and other related records are reassigned to it.</div>
                        </div>
                    </section>
                </div>

                <section class="cm-preview" id="mergePreview">
                    <div class="cm-preview-grid">
                        <article class="cm-preview-card">
                            <h3>Records that will move</h3>
                            <div class="cm-counts" id="linkedCounts"></div>
                        </article>
                        <article class="cm-preview-card">
                            <h3>Blank profile details that will be filled</h3>
                            <div class="cm-fill-list" id="profileFill"></div>
                        </article>
                    </div>
                </section>
            </div>

<div class="cm-sticky">
    <div class="cm-sticky-note" id="stickyNote">Select at least two clients to continue.</div>
    <div class="cm-actions">
        <a class="cm-btn" href="clients.php">Cancel</a>
        <button type="button" class="cm-btn primary" id="mergeButton" disabled><span class="cm-loader"></span><i class="bi bi-intersect"></i> Merge Clients</button>
    </div>
</div>

<div class="cm-modal-backdrop" id="confirmBackdrop" aria-hidden="true">
    <section class="cm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
        <div class="cm-modal-head">
            <h3 id="confirmTitle">Merge selected clients?</h3>
            <button type="button" class="cm-modal-close" id="confirmClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="cm-modal-body">
            <div id="confirmText">The duplicate profiles will be merged into the selected primary client.</div>
            <div class="cm-warning"><strong>This action changes linked records.</strong> Jobs, requests, quotes, invoices, payments, locations, contacts, and other client-linked records will point to the profile you keep. Duplicate client profiles are soft-archived for history.</div>
        </div>
        <div class="cm-modal-footer">
            <button type="button" class="cm-btn" id="confirmCancel">Cancel</button>
            <button type="button" class="cm-btn primary" id="confirmMerge"><span class="cm-loader"></span>Merge Clients</button>
        </div>
    </section>
</div>

<div class="cm-toast info" id="mergeToast"><i class="bi bi-info-circle"></i><span id="mergeToastMessage">Notification</span><button type="button" id="mergeToastClose"><i class="bi bi-x-lg"></i></button></div>


<script>
(function () {
    'use strict';

    var csrfToken = <?= json_encode($clientMergeCsrfToken) ?>;
    var initialIds = <?= json_encode($initialIds) ?>;
    var initialPrimaryId = <?= (int)$initialPrimaryId ?>;
    var state = {
        rows: [],
        rowMap: {},
        selected: {},
        primaryId: initialPrimaryId || 0,
        canMerge: false,
        previewTimer: null,
        searchTimer: null,
        toastTimer: null,
        previewSeq: 0
    };

    var results = document.getElementById('clientResults');
    var resultsMeta = document.getElementById('resultsMeta');
    var searchInput = document.getElementById('clientSearch');
    var selectedList = document.getElementById('selectedList');
    var selectedCount = document.getElementById('selectedCount');
    var mergePreview = document.getElementById('mergePreview');
    var linkedCounts = document.getElementById('linkedCounts');
    var profileFill = document.getElementById('profileFill');
    var stickyNote = document.getElementById('stickyNote');
    var mergeButton = document.getElementById('mergeButton');
    var confirmBackdrop = document.getElementById('confirmBackdrop');
    var confirmMerge = document.getElementById('confirmMerge');
    var toast = document.getElementById('mergeToast');
    var toastMessage = document.getElementById('mergeToastMessage');

    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function parseResponse(response) {
        return response.text().then(function (raw) {
            var text = (raw || '').trim();
            var data;
            try {
                data = text ? JSON.parse(text) : {};
            } catch (e) {
                var clean = text.replace(/<br\s*\/?>/gi, ' ').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                throw new Error(clean ? 'Server error: ' + clean : 'Server returned an invalid response.');
            }
            if (!response.ok || !data.success) {
                var fallback = response.status ? ('Request failed (HTTP ' + response.status + ').') : 'Request failed.';
                if (response.status === 500 && !data.message) {
                    fallback = 'Client merge failed on the server. Check the PHP error log for the exact database/PHP error.';
                }
                throw new Error(data.message || fallback);
            }
            return data;
        });
    }

    function request(data) {
        var fd = new FormData();
        Object.keys(data || {}).forEach(function (key) {
            fd.append(key, data[key]);
        });
        fd.append('csrf_token', csrfToken);
        var endpoint = new URL('api/client-merge.php', window.location.href).href;
        return fetch(endpoint, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        }).then(parseResponse);
    }

    function showToast(type, message) {
        if (state.toastTimer) clearTimeout(state.toastTimer);
        toast.className = 'cm-toast ' + (type || 'info') + ' show';
        toastMessage.textContent = message || 'Notification';
        state.toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 3500);
    }

    function setLoading(button, on) {
        if (!button) return;
        button.classList.toggle('loading', !!on);
        button.disabled = !!on;
    }

    function selectedIds() {
        return Object.keys(state.selected).map(function (id) { return Number(id); }).filter(function (id) { return id > 0; });
    }

    function clientSecondary(row) {
        return row.company_name || [row.first_name || '', row.last_name || ''].join(' ').trim() || row.email || row.phone || '';
    }

    function renderResults() {
        var rows = state.rows || [];
        resultsMeta.textContent = rows.length + (rows.length === 1 ? ' client' : ' clients') + ' found';
        if (!rows.length) {
            results.innerHTML = '<div class="cm-empty">No matching clients found.</div>';
            return;
        }
        var html = '';
        rows.forEach(function (row) {
            var id = Number(row.id || 0);
            var checked = !!state.selected[id];
            html += '<label class="cm-result' + (checked ? ' selected' : '') + '" data-id="' + id + '">' +
                '<input type="checkbox" data-select-id="' + id + '" ' + (checked ? 'checked' : '') + '>' +
                '<div>' +
                    '<div class="cm-result-name">' + esc(row.display_name || 'Unnamed client') + '</div>' +
                    '<div class="cm-result-company">' + esc(clientSecondary(row)) + '</div>' +
                    '<div class="cm-result-contact">' +
                        (row.email ? '<span><i class="bi bi-envelope"></i>' + esc(row.email) + '</span>' : '') +
                        (row.phone ? '<span><i class="bi bi-telephone"></i>' + esc(row.phone) + '</span>' : '') +
                    '</div>' +
                    (row.address ? '<div class="cm-result-address"><i class="bi bi-geo-alt"></i> ' + esc(row.address) + '</div>' : '') +
                '</div>' +
                '<span class="cm-status ' + esc(row.status || '') + '">' + esc(row.status || row.client_type || 'active') + '</span>' +
            '</label>';
        });
        results.innerHTML = html;
    }

    function ensurePrimary() {
        var ids = selectedIds();
        if (!ids.length) {
            state.primaryId = 0;
            return;
        }
        if (ids.indexOf(Number(state.primaryId)) === -1) {
            state.primaryId = ids[0];
        }
    }

    function renderSelected() {
        ensurePrimary();
        var ids = selectedIds();
        selectedCount.textContent = ids.length + (ids.length === 1 ? ' client selected' : ' clients selected');
        if (!ids.length) {
            selectedList.innerHTML = '<div class="cm-empty">Select at least two client profiles.</div>';
            updateActionState();
            return;
        }
        var html = '';
        ids.forEach(function (id) {
            var row = state.selected[id];
            var primary = Number(state.primaryId) === Number(id);
            html += '<div class="cm-selected-item' + (primary ? ' primary' : '') + '">' +
                '<input class="cm-selected-radio" type="radio" name="primaryClient" data-primary-id="' + id + '" ' + (primary ? 'checked' : '') + ' title="Keep this profile">' +
                '<div><div class="cm-selected-name">' + esc(row.display_name || 'Unnamed client') + '</div><div class="cm-selected-small">' + esc(row.email || row.phone || clientSecondary(row) || 'No contact information') + '</div></div>' +
                '<button class="cm-remove" type="button" data-remove-id="' + id + '" title="Remove"><i class="bi bi-x-lg"></i></button>' +
            '</div>';
        });
        selectedList.innerHTML = html;
        updateActionState();
        schedulePreview();
    }

    function updateActionState() {
        var ids = selectedIds();
        var ready = state.canMerge && ids.length >= 2 && state.primaryId > 0;
        mergeButton.disabled = !ready;
        if (!state.canMerge) {
            stickyNote.textContent = 'Your role does not have both Update and Delete customer permissions required to merge.';
        } else if (ids.length < 2) {
            stickyNote.textContent = 'Select at least two clients to continue.';
        } else {
            var primary = state.selected[state.primaryId];
            stickyNote.textContent = (ids.length - 1) + ' duplicate ' + ((ids.length - 1) === 1 ? 'profile' : 'profiles') + ' will be merged into ' + (primary ? primary.display_name : 'the kept client') + '.';
        }
    }

    function renderPreview(data) {
        var counts = data.linked_counts || [];
        var fills = data.profile_fill || [];
        if (!counts.length) {
            linkedCounts.innerHTML = '<div class="cm-empty" style="grid-column:1/-1">No linked work records found on the duplicate profiles.</div>';
        } else {
            linkedCounts.innerHTML = counts.map(function (row) {
                return '<div class="cm-count"><strong>' + Number(row.count || 0) + '</strong><span>' + esc(row.label) + '</span></div>';
            }).join('');
        }
        if (!fills.length) {
            profileFill.innerHTML = '<div class="cm-empty">The kept client already has values for the mergeable profile fields. Existing values will be preserved.</div>';
        } else {
            profileFill.innerHTML = fills.map(function (row) {
                return '<div class="cm-fill"><strong>' + esc(row.label) + '</strong><span>' + esc(row.value) + '</span><small>From ' + esc(row.source_name) + '</small></div>';
            }).join('');
        }
        mergePreview.classList.add('show');
    }

    function schedulePreview() {
        if (state.previewTimer) clearTimeout(state.previewTimer);
        var ids = selectedIds();
        state.previewSeq += 1;
        var seq = state.previewSeq;
        if (ids.length < 2 || !state.primaryId || !state.canMerge) {
            mergePreview.classList.remove('show');
            return;
        }
        state.previewTimer = setTimeout(function () {
            request({
                action: 'preview',
                client_ids: JSON.stringify(ids),
                primary_id: state.primaryId
            }).then(function (data) {
                if (seq !== state.previewSeq) return;
                renderPreview(data);
            }).catch(function (e) {
                if (seq !== state.previewSeq) return;
                mergePreview.classList.remove('show');
                showToast('error', e.message);
            });
        }, 180);
    }

    function loadClients(search) {
        results.innerHTML = '<div class="cm-empty">Loading clients...</div>';
        request({ action: 'list', search: search || '' }).then(function (data) {
            state.rows = data.clients || [];
            state.rowMap = {};
            state.rows.forEach(function (row) {
                state.rowMap[Number(row.id)] = row;
            });
            state.canMerge = !!(data.permissions && data.permissions.can_merge);
            initialIds.forEach(function (id) {
                if (state.rowMap[id]) state.selected[id] = state.rowMap[id];
            });
            if (initialPrimaryId && state.selected[initialPrimaryId]) state.primaryId = initialPrimaryId;
            renderResults();
            renderSelected();
        }).catch(function (e) {
            results.innerHTML = '<div class="cm-empty">' + esc(e.message) + '</div>';
            showToast('error', e.message);
        });
    }

    results.addEventListener('change', function (e) {
        var box = e.target.closest('[data-select-id]');
        if (!box) return;
        var id = Number(box.getAttribute('data-select-id'));
        if (box.checked) {
            if (selectedIds().length >= 10 && !state.selected[id]) {
                box.checked = false;
                showToast('warning', 'You can merge up to 10 clients at one time.');
                return;
            }
            if (state.rowMap[id]) state.selected[id] = state.rowMap[id];
        } else {
            delete state.selected[id];
        }
        ensurePrimary();
        renderResults();
        renderSelected();
    });

    selectedList.addEventListener('change', function (e) {
        var radio = e.target.closest('[data-primary-id]');
        if (!radio) return;
        state.primaryId = Number(radio.getAttribute('data-primary-id'));
        renderSelected();
    });

    selectedList.addEventListener('click', function (e) {
        var remove = e.target.closest('[data-remove-id]');
        if (!remove) return;
        var id = Number(remove.getAttribute('data-remove-id'));
        delete state.selected[id];
        ensurePrimary();
        renderResults();
        renderSelected();
    });

    searchInput.addEventListener('input', function () {
        if (state.searchTimer) clearTimeout(state.searchTimer);
        var value = this.value.trim();
        state.searchTimer = setTimeout(function () { loadClients(value); }, 250);
    });

    function openConfirm() {
        var ids = selectedIds();
        if (!state.canMerge || ids.length < 2 || !state.primaryId) return;
        var primary = state.selected[state.primaryId];
        document.getElementById('confirmText').innerHTML = '<strong>' + esc(primary ? primary.display_name : 'Selected client') + '</strong> will be kept. ' + (ids.length - 1) + ' duplicate ' + ((ids.length - 1) === 1 ? 'client' : 'clients') + ' will be merged into this profile.';
        confirmBackdrop.classList.add('show');
        confirmBackdrop.setAttribute('aria-hidden', 'false');
    }

    function closeConfirm() {
        confirmBackdrop.classList.remove('show');
        confirmBackdrop.setAttribute('aria-hidden', 'true');
    }

    function performMerge() {
        var ids = selectedIds();
        if (!state.canMerge || ids.length < 2 || !state.primaryId) return;
        setLoading(confirmMerge, true);
        request({
            action: 'merge',
            client_ids: JSON.stringify(ids),
            primary_id: state.primaryId
        }).then(function (data) {
            showToast('success', data.message || 'Clients merged successfully.');
            closeConfirm();
            window.setTimeout(function () {
                window.location.href = data.redirect || ('client-view.php?client_id=' + encodeURIComponent(state.primaryId));
            }, 650);
        }).catch(function (e) {
            showToast('error', e.message);
        }).finally(function () {
            setLoading(confirmMerge, false);
        });
    }

    mergeButton.addEventListener('click', openConfirm);
    confirmMerge.addEventListener('click', performMerge);
    document.getElementById('confirmClose').addEventListener('click', closeConfirm);
    document.getElementById('confirmCancel').addEventListener('click', closeConfirm);
    confirmBackdrop.addEventListener('click', function (e) { if (e.target === confirmBackdrop) closeConfirm(); });
    document.getElementById('mergeToastClose').addEventListener('click', function () { toast.classList.remove('show'); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeConfirm(); });

    loadClients('');
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
