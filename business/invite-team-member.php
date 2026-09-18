<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Invite Team Member · FieldPlx';
$pageDescription = 'Invite or update a FieldPlx team member';
$settingsActivePage = 'team';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['team_settings_csrf'])) {
    $_SESSION['team_settings_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['team_settings_csrf'];
$editUserId = isset($_GET['user_id']) ? max(0, (int)$_GET['user_id']) : 0;

require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/toast.php';
?>
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
<style>
:root{
    --tm-green:var(--primary,#2f8c25);
    --tm-green-dark:#26751f;
    --tm-text:var(--text,#0b3142);
    --tm-muted:var(--muted,#637783);
    --tm-border:var(--card-border,#d8e0e5);
    --tm-card:var(--card-bg,#fff);
    --tm-page:var(--body-bg,#fff);
    --tm-soft:#f7f8f8;
    --tm-danger:#ef4f3f;
}
*{box-sizing:border-box}
.tmi-page{display:grid;grid-template-columns:190px minmax(0,1fr);gap:32px;width:100%;max-width:1390px;margin:0 auto;padding:4px 18px 96px;color:var(--tm-text)}
.tmi-main{min-width:0;max-width:1080px}.tmi-title{margin:18px 0 28px;font-size:34px;line-height:1.08;letter-spacing:-.7px;color:var(--tm-text);font-weight:800}.tmi-back{display:inline-flex;align-items:center;gap:6px;margin:2px 0 0;color:var(--tm-green);text-decoration:none;font-size:12px;font-weight:600}.tmi-back:hover{text-decoration:underline}.tmi-back svg{width:15px;height:15px}
.tmi-card{margin-bottom:16px;padding:16px 16px 18px;border:1px solid var(--tm-border);border-radius:9px;background:var(--tm-card)}
.tmi-card-title{margin:0 0 15px;font-size:22px;line-height:1.15;font-weight:800;color:var(--tm-text)}
.tmi-profile{display:flex;align-items:center;gap:12px;margin-bottom:15px}.tmi-avatar{width:72px;height:72px;display:grid;place-items:center;flex:0 0 72px;border-radius:50%;overflow:hidden;background:#173f4c;color:#fff}.tmi-avatar svg{width:30px;height:30px;stroke-width:1.8}.tmi-avatar img{width:100%;height:100%;object-fit:cover}
.tmi-btn{height:40px;display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:0 16px;border:1px solid var(--tm-border);border-radius:8px;background:var(--tm-card);color:var(--tm-green);font:inherit;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none}.tmi-btn:hover{border-color:#b8c7cf;background:#fbfcfc}.tmi-btn.primary{background:var(--tm-green);border-color:var(--tm-green);color:#fff}.tmi-btn.primary:hover{background:var(--tm-green-dark);border-color:var(--tm-green-dark)}.tmi-btn.danger{color:var(--tm-danger)}.tmi-btn.danger:hover{border-color:#efb8b2;background:#fff7f6}.tmi-btn svg{width:16px;height:16px}.tmi-btn:disabled{opacity:.55;cursor:not-allowed}.tmi-profile-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.tmi-person-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px}.tmi-stack{display:grid;gap:12px}.tmi-input,.tmi-select{width:100%;height:50px;padding:0 16px;border:1px solid var(--tm-border);border-radius:8px;background:var(--tm-card);color:var(--tm-text);font:inherit;font-size:13px;outline:none}.tmi-input:focus,.tmi-select:focus{border-color:var(--tm-green);box-shadow:0 0 0 2px rgba(47,140,37,.10)}.tmi-input::placeholder{color:#738694}.tmi-address-group{border:1px solid var(--tm-border);border-radius:8px;overflow:hidden}.tmi-address-group .tmi-input,.tmi-address-group .tmi-select{border:0;border-radius:0;border-bottom:1px solid var(--tm-border);box-shadow:none}.tmi-address-row{display:grid;grid-template-columns:1fr 1fr}.tmi-address-row>*:first-child{border-right:1px solid var(--tm-border)}.tmi-address-group>*:last-child,.tmi-address-row:last-child>*{border-bottom:0}.tmi-labour{margin-top:18px;max-width:302px}.tmi-field-label{display:flex;align-items:center;gap:7px;margin:0 0 8px;font-size:14px;font-weight:800}.tmi-help{width:18px;height:18px;color:#335563}.tmi-money{height:51px;display:flex;align-items:center;justify-content:space-between;padding:0 14px;border:1px solid var(--tm-border);border-radius:8px}.tmi-money-left{min-width:0}.tmi-money-label{display:block;font-size:10px;color:var(--tm-muted)}.tmi-money-input{width:155px;border:0;outline:0;padding:0;background:transparent;color:var(--tm-text);font:inherit;font-size:14px}.tmi-money-unit{font-size:12px;color:var(--tm-text)}
.tmi-admin{display:flex;align-items:flex-start;gap:10px}.tmi-admin input,.tmi-check input{width:18px;height:18px;accent-color:var(--tm-green);margin:1px 0 0}.tmi-admin-title{font-size:13px}.tmi-helptext{display:block;margin-top:2px;color:var(--tm-muted);font-size:11px;line-height:1.45}.tmi-divider{height:1px;margin:18px 0;background:var(--tm-border)}.tmi-section-title{margin:0 0 8px;font-size:17px;font-weight:800}.tmi-section-copy{margin:0 0 18px;color:var(--tm-muted);font-size:12px;line-height:1.45}
.tmi-preset-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.tmi-preset{position:relative;min-height:220px;padding:16px;border:1px solid var(--tm-border);border-radius:8px;background:var(--tm-card);cursor:pointer;transition:border-color .15s,box-shadow .15s}.tmi-preset:hover{border-color:#b7c5cc}.tmi-preset.active{border-color:var(--tm-green);box-shadow:0 0 0 1px var(--tm-green)}.tmi-preset.custom{min-height:auto;grid-column:1/2}.tmi-preset-head{display:flex;align-items:center;gap:10px;padding-bottom:12px;border-bottom:1px solid var(--tm-border);font-size:15px;font-weight:800}.tmi-radio-dot{width:20px;height:20px;border:2px solid #d7e0e4;border-radius:50%;display:grid;place-items:center;flex:0 0 20px}.tmi-preset.active .tmi-radio-dot{border-color:var(--tm-green)}.tmi-preset.active .tmi-radio-dot:after{content:"";width:9px;height:9px;border-radius:50%;background:var(--tm-green)}.tmi-preset-cols{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:15px}.tmi-preset-col h4{margin:0 0 8px;font-size:10px;font-weight:800;letter-spacing:.25px}.tmi-preset-col.can h4{color:var(--tm-green)}.tmi-preset-col.cant h4{color:var(--tm-danger)}.tmi-preset-item{display:flex;gap:8px;margin:7px 0;font-size:11px;line-height:1.35;color:#405968}.tmi-preset-item svg{width:13px;height:13px;flex:0 0 13px;margin-top:1px}.tmi-preset-col.can svg{color:var(--tm-green)}.tmi-preset-col.cant svg{color:var(--tm-danger)}
.tmi-permission-list{margin-top:18px}.tmi-permission{padding:18px 0;border-top:1px solid var(--tm-border)}.tmi-permission:first-child{border-top:0}.tmi-perm-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:10px}.tmi-perm-name{margin:0;font-size:14px;font-weight:800}.tmi-perm-copy{margin:0 0 10px;color:var(--tm-muted);font-size:11px;line-height:1.5}.tmi-options{display:grid;gap:7px}.tmi-permission.options-hidden .tmi-options,.tmi-permission.options-hidden .tmi-check{display:none}.tmi-schedule-note{display:none;margin:8px 0 0;color:#8b969c;font-size:11px;line-height:1.45}.tmi-schedule-note.show{display:block}.tmi-option{display:flex;align-items:flex-start;gap:9px;color:#344f5d;font-size:12px;line-height:1.35}.tmi-option input[type=radio]{appearance:none;width:19px;height:19px;border:2px solid #d7e0e4;border-radius:50%;margin:0;display:grid;place-items:center;flex:0 0 19px}.tmi-option input[type=radio]:checked{border-color:var(--tm-green)}.tmi-option input[type=radio]:checked:after{content:"";width:9px;height:9px;border-radius:50%;background:var(--tm-green)}.tmi-check{display:flex;align-items:center;gap:9px;margin-top:10px;color:#344f5d;font-size:12px}.tmi-check input{width:18px;height:18px}.tmi-required{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:8px 0 10px;color:var(--tm-muted);font-size:11px}.tmi-required strong{font-weight:500}.tmi-required .ok{display:inline-flex;align-items:center;gap:4px}.tmi-required svg{width:15px;height:15px;color:var(--tm-green)}.tmi-disabled-note{color:#a6b0b6;font-size:11px;margin-top:8px}
.tmi-switch{position:relative;width:48px;height:25px;display:inline-block;flex:0 0 48px}.tmi-switch input{position:absolute;opacity:0;pointer-events:none}.tmi-switch span{position:absolute;inset:0;border:1px solid #b9c4ca;border-radius:999px;background:#eef1f2;cursor:pointer;transition:.18s}.tmi-switch span:before{content:"";position:absolute;width:17px;height:17px;left:3px;top:3px;border-radius:50%;background:#899397;transition:.18s}.tmi-switch input:checked+span{background:var(--tm-green);border-color:var(--tm-green)}.tmi-switch input:checked+span:before{left:26px;background:#fff}.tmi-switch input:checked+span:after{content:"✓";position:absolute;left:9px;top:2px;color:#fff;font-size:13px;font-weight:700}.tmi-switch input:disabled+span{opacity:.55;cursor:not-allowed}
.tmi-comms{display:grid;gap:16px}.tmi-subheading{margin:0 0 10px;font-size:14px;font-weight:800}.tmi-checkbox-line{display:flex;align-items:flex-start;gap:10px;margin:12px 0}.tmi-checkbox-line input{width:18px;height:18px;margin:0;accent-color:var(--tm-green)}.tmi-checkbox-title{font-size:12px}.tmi-checkbox-desc{display:block;color:var(--tm-muted);font-size:10px;margin-top:2px}.tmi-language-note{margin:0 0 12px;color:#405968;font-size:12px}.tmi-language-note strong{font-weight:800}.tmi-actions{position:sticky;bottom:0;z-index:80;display:flex;justify-content:flex-end;gap:12px;margin:20px -18px 0;padding:10px 18px;border-top:1px solid var(--tm-border);background:color-mix(in srgb,var(--tm-card) 96%,transparent);backdrop-filter:blur(8px)}.tmi-actions .tmi-btn{height:42px;min-width:126px}.tmi-actions .primary{min-width:150px}.tmi-privacy{display:none;margin:28px 0 0;padding:26px 0 4px;border-top:1px solid var(--tm-border);text-align:center}.tmi-privacy a{color:var(--tm-green);font-size:12px;font-weight:700;text-decoration:underline;text-underline-offset:2px}.tmi-loading{padding:40px 0;color:var(--tm-muted);font-size:12px}.tmi-error{padding:12px;border:1px solid #f1c9c4;border-radius:8px;background:#fff6f5;color:#b33a2f;font-size:12px}.tmi-lock-note{display:none}.tmi-admin-mode>.tmi-divider,.tmi-admin-mode>.tmi-section-title,.tmi-admin-mode>.tmi-section-copy,.tmi-admin-mode>.tmi-preset-grid,.tmi-admin-mode>.tmi-permission-list{display:none}.tmi-admin-selected .tmi-invitation-language{display:none}

.tmi-account-link{display:inline-flex;align-items:center;gap:6px;margin-top:18px;color:var(--tm-green);font-size:12px;font-weight:700;text-decoration:underline;text-underline-offset:3px;cursor:pointer;background:none;border:0;padding:0}.tmi-account-link:hover{color:var(--tm-green-dark)}
.tmi-edit-only{display:none}.tmi-edit-only.show{display:block}
.tmi-schedule-card{display:none}.tmi-schedule-card.show{display:block}.tmi-schedule-top{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}.tmi-schedule-heading{margin:0 0 12px;font-size:17px;font-weight:800}.tmi-schedule-copy{margin:0 0 10px;max-width:850px;color:var(--tm-muted);font-size:11px;line-height:1.55}.tmi-schedule-table{width:300px;max-width:100%;border-collapse:collapse;font-size:12px;color:#36515f}.tmi-schedule-table td{padding:8px 0;border-bottom:1px solid var(--tm-border)}.tmi-schedule-table tr:last-child td{border-bottom:0}.tmi-schedule-table td:first-child{width:42%}.tmi-edit-link{border:0;background:none;color:var(--tm-green);font:inherit;font-size:12px;font-weight:700;text-decoration:underline;text-underline-offset:2px;cursor:pointer;padding:2px 0}
.tmi-danger-row{display:none;align-items:center;justify-content:space-between;gap:16px;margin:24px 0 0;padding:4px 0 14px}.tmi-danger-row.show{display:flex}.tmi-bottom-save{margin-left:auto}
.tmi-modal-backdrop{position:fixed;inset:0;z-index:11000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(10,25,34,.32)}.tmi-modal-backdrop.open{display:flex}.tmi-modal{width:min(520px,100%);max-height:calc(100vh - 36px);overflow:auto;border:1px solid var(--tm-border);border-radius:10px;background:var(--tm-card);box-shadow:0 24px 70px rgba(0,17,49,.22)}.tmi-modal.schedule{width:min(610px,100%)}.tmi-modal-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 18px 14px}.tmi-modal-head h3{margin:0;font-size:20px;color:var(--tm-text)}.tmi-modal-close{width:32px;height:32px;border:0;border-radius:7px;background:transparent;color:var(--tm-text);display:grid;place-items:center;cursor:pointer}.tmi-modal-close svg{width:20px;height:20px}.tmi-modal-body{padding:0 18px 18px}.tmi-modal-foot{display:flex;justify-content:flex-end;gap:8px;padding:0 18px 18px}.tmi-password-fields{display:grid;gap:14px}.tmi-password-note{margin:0 0 14px;color:var(--tm-muted);font-size:11px;line-height:1.5}.tmi-day-row{display:grid;grid-template-columns:115px 54px minmax(0,1fr) minmax(0,1fr);gap:10px;align-items:center;margin:8px 0}.tmi-day-name{font-size:12px;font-weight:700}.tmi-time-field{position:relative}.tmi-time-field label{position:absolute;left:12px;top:6px;color:#7a8c96;font-size:9px;pointer-events:none}.tmi-time-input{width:100%;height:52px;padding:18px 11px 4px;border:1px solid var(--tm-border);border-radius:8px;background:var(--tm-card);color:var(--tm-text);font:inherit;font-size:13px;outline:none}.tmi-time-input:disabled{background:#eef0f1;color:#9aa4aa}.tmi-mini-switch{position:relative;width:48px;height:25px}.tmi-mini-switch input{position:absolute;opacity:0}.tmi-mini-switch span{position:absolute;inset:0;border:1px solid #bac5ca;border-radius:999px;background:#eef1f2}.tmi-mini-switch span:before{content:"";position:absolute;width:17px;height:17px;left:3px;top:3px;border-radius:50%;background:#173f4c;transition:.18s}.tmi-mini-switch input:checked+span{background:var(--tm-green);border-color:var(--tm-green)}.tmi-mini-switch input:checked+span:before{left:26px;background:#fff}.tmi-mini-switch input:checked+span:after{content:"✓";position:absolute;left:9px;top:2px;color:#fff;font-size:13px;font-weight:700}.tmi-edit-actions{display:none}.tmi-edit-actions.show{display:flex}.tmi-new-actions.hidden{display:none}
@media(max-width:1050px){.tmi-page{grid-template-columns:1fr;max-width:1040px;padding:16px 18px 96px}.tmi-page>aside{display:none}.tmi-back{display:inline-flex;align-items:center;gap:6px;margin-bottom:12px;color:var(--tm-green);text-decoration:none;font-size:12px}.tmi-main{max-width:none}}
@media(max-width:760px){.tmi-day-row{grid-template-columns:92px 50px 1fr 1fr}.tmi-schedule-top{gap:12px}.tmi-schedule-table{width:100%}.tmi-title{font-size:28px}.tmi-card{padding:14px}.tmi-person-grid,.tmi-preset-grid{grid-template-columns:1fr}.tmi-preset.custom{grid-column:auto}.tmi-preset-cols{grid-template-columns:1fr}.tmi-actions{margin-left:-14px;margin-right:-14px;padding:10px 14px}.tmi-actions .tmi-btn{min-width:0;padding:0 12px}.tmi-address-row{grid-template-columns:1fr}.tmi-address-row>*:first-child{border-right:0;border-bottom:1px solid var(--tm-border)}}
</style>

<div class="tmi-page">
    <aside><?php require __DIR__ . '/includes/settings-nav.php'; ?></aside>

    <main class="tmi-main">
        <a class="tmi-back" href="team.php"><i data-lucide="arrow-left"></i><span>Manage team</span></a>
        <h1 class="tmi-title" id="pageHeading"><?= $editUserId > 0 ? 'Edit team member' : 'Invite a new member' ?></h1>

        <div id="loading" class="tmi-loading">Loading team settings...</div>

        <form id="memberForm" enctype="multipart/form-data" style="display:none" novalidate>
            <input type="hidden" name="action" value="save_member">
            <input type="hidden" name="user_id" value="<?= (int)$editUserId ?>">
            <input type="hidden" name="preset_key" id="presetKey" value="field_crew">
            <input type="hidden" name="save_and_assign" id="saveAndAssign" value="0">
            <input type="hidden" name="remove_avatar" id="removeAvatar" value="0">

            <section class="tmi-card">
                <h2 class="tmi-card-title">Personal info</h2>

                <div class="tmi-profile">
                    <div class="tmi-avatar" id="avatarPreview"><i data-lucide="user-round"></i></div>
                    <div class="tmi-profile-actions">
                        <label class="tmi-btn">
                            <i data-lucide="image-up"></i>
                            <span id="avatarButtonText">Upload Image</span>
                            <input type="file" name="avatar" id="avatarInput" accept="image/png,image/jpeg,image/webp" hidden>
                        </label>
                        <button type="button" class="tmi-btn danger" id="removeAvatarButton" style="display:none">
                            <i data-lucide="trash-2"></i>
                            <span>Remove</span>
                        </button>
                    </div>
                </div>

                <div class="tmi-person-grid">
                    <div class="tmi-stack">
                        <input class="tmi-input" name="full_name" id="fullName" maxlength="240" placeholder="Full name" required>
                        <input class="tmi-input" type="email" name="email" id="email" maxlength="190" placeholder="Email address" required>
                        <input class="tmi-input" name="phone" id="phone" maxlength="50" placeholder="Mobile phone number (if applicable)">
                    </div>

                    <div class="tmi-address-group">
                        <input class="tmi-input" name="address_line1" id="addressLine1" maxlength="255" placeholder="Street address">
                        <input class="tmi-input" name="city" id="city" maxlength="120" placeholder="City">
                        <input class="tmi-input" name="state" id="state" maxlength="120" placeholder="Province / State">
                        <div class="tmi-address-row">
                            <input class="tmi-input" name="postal_code" id="postalCode" maxlength="40" placeholder="Postal code">
                            <select class="tmi-select" name="country_id" id="countryId"><option value="0">Country</option></select>
                        </div>
                    </div>
                </div>

                <div class="tmi-labour">
                    <div class="tmi-field-label">Labour cost <i data-lucide="circle-help" class="tmi-help" title="Internal employee labour cost used for job costing."></i></div>
                    <div class="tmi-money">
                        <div class="tmi-money-left">
                            <span class="tmi-money-label">Employee cost</span>
                            <input class="tmi-money-input" type="number" min="0" step="0.01" name="labor_rate" id="laborRate" value="0.00">
                        </div>
                        <span class="tmi-money-unit">per hour</span>
                    </div>
                </div>

                <div class="tmi-edit-only" id="passwordActionWrap">
                    <button type="button" class="tmi-account-link" id="passwordAction"><i data-lucide="key-round" id="passwordActionIcon"></i><span id="passwordActionText">Change password</span></button>
                </div>
            </section>



            <section class="tmi-card tmi-schedule-card" id="workingHoursCard">
                <h2 class="tmi-card-title">Schedule</h2>
                <div class="tmi-schedule-top">
                    <div>
                        <h3 class="tmi-schedule-heading">Working hours</h3>
                        <p class="tmi-schedule-copy">Individual working hours are represented in the schedule, are used for finding time and indicate whether the user is available or unavailable. Working hours are also used as the team member's availability for online booking.</p>
                        <table class="tmi-schedule-table" id="workingHoursSummary"><tbody></tbody></table>
                    </div>
                    <button type="button" class="tmi-edit-link" id="editWorkingHours">Edit</button>
                </div>
            </section>

            <section class="tmi-card" id="permissionCard">
                <h2 class="tmi-card-title">Permissions</h2>

                <label class="tmi-admin">
                    <input type="checkbox" name="is_tenant_admin" id="isAdmin" value="1">
                    <span>
                        <span class="tmi-admin-title">Make administrator</span>
                        <span class="tmi-helptext">This allows them access to everything within the account — including billing, reports, customers, account settings, and editing user permissions.</span>
                    </span>
                </label>
                <div class="tmi-lock-note">Administrator access overrides the preset and detailed permissions below.</div>

                <div class="tmi-divider"></div>

                <h3 class="tmi-section-title">Preset permission levels</h3>
                <p class="tmi-section-copy">Start with a preset permission level, and customize further as needed.</p>

                <div class="tmi-preset-grid">
                    <article class="tmi-preset" data-preset="field_crew">
                        <div class="tmi-preset-head"><span class="tmi-radio-dot"></span><span>Field crew</span></div>
                        <div class="tmi-preset-cols">
                            <div class="tmi-preset-col can"><h4>CAN DO</h4>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>See their own schedule and mark work complete</span></div>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>Start and stop timers</span></div>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>Add notes and photos on their jobs</span></div>
                            </div>
                            <div class="tmi-preset-col cant"><h4>CAN'T DO</h4>
                                <div class="tmi-preset-item"><i data-lucide="x"></i><span>See anyone else's schedule</span></div>
                                <div class="tmi-preset-item"><i data-lucide="x"></i><span>Change their schedule</span></div>
                                <div class="tmi-preset-item"><i data-lucide="x"></i><span>Create or edit timesheets manually</span></div>
                                <div class="tmi-preset-item"><i data-lucide="x"></i><span>See prices or costs</span></div>
                            </div>
                        </div>
                    </article>

                    <article class="tmi-preset" data-preset="senior_field_crew">
                        <div class="tmi-preset-head"><span class="tmi-radio-dot"></span><span>Senior field crew</span></div>
                        <div class="tmi-preset-cols">
                            <div class="tmi-preset-col can"><h4>CAN DO</h4>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>Everything Field crew can do, plus:</span></div>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>View customer files and attachments</span></div>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>See prices</span></div>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>Create and edit customers, quotes, jobs and invoices</span></div>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>Collect payments</span></div>
                            </div>
                            <div class="tmi-preset-col cant"><h4>CAN'T DO</h4>
                                <div class="tmi-preset-item"><i data-lucide="x"></i><span>Change anyone else's schedule</span></div>
                                <div class="tmi-preset-item"><i data-lucide="x"></i><span>Add or edit everyone's timesheets</span></div>
                                <div class="tmi-preset-item"><i data-lucide="x"></i><span>View reports</span></div>
                            </div>
                        </div>
                    </article>

                    <article class="tmi-preset" data-preset="crew_lead">
                        <div class="tmi-preset-head"><span class="tmi-radio-dot"></span><span>Crew lead</span></div>
                        <div class="tmi-preset-cols">
                            <div class="tmi-preset-col can"><h4>CAN DO</h4>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>Everything Senior field crew can do, plus:</span></div>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>View and change everyone's schedule</span></div>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>Add and edit everyone's timesheets manually</span></div>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>Create and edit jobs</span></div>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>View customer communications</span></div>
                            </div>
                            <div class="tmi-preset-col cant"><h4>CAN'T DO</h4>
                                <div class="tmi-preset-item"><i data-lucide="x"></i><span>Use marketing tools</span></div>
                                <div class="tmi-preset-item"><i data-lucide="x"></i><span>View reports</span></div>
                            </div>
                        </div>
                    </article>

                    <article class="tmi-preset" data-preset="manager">
                        <div class="tmi-preset-head"><span class="tmi-radio-dot"></span><span>Manager</span></div>
                        <div class="tmi-preset-cols">
                            <div class="tmi-preset-col can"><h4>CAN DO</h4>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>Everything Crew lead can do, plus:</span></div>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>View reports</span></div>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>Use marketing tools (if available)</span></div>
                                <div class="tmi-preset-item"><i data-lucide="check"></i><span>Use sales pipeline (if available)</span></div>
                            </div>
                            <div class="tmi-preset-col cant"><h4>CAN'T DO</h4>
                                <div class="tmi-preset-item"><i data-lucide="x"></i><span>Add or remove team members</span></div>
                                <div class="tmi-preset-item"><i data-lucide="x"></i><span>Change account settings</span></div>
                            </div>
                        </div>
                    </article>

                    <article class="tmi-preset custom" data-preset="custom">
                        <div class="tmi-preset-head"><span class="tmi-radio-dot"></span><span>Custom</span></div>
                    </article>
                </div>

                <div class="tmi-permission-list" id="permissionList">
                    <div class="tmi-divider"></div>

                    <section class="tmi-permission" data-section="schedule">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Schedule</h3><label class="tmi-switch"><input type="checkbox" name="schedule_enabled" id="scheduleEnabled" value="1"><span></span></label></div>
                        <div class="tmi-options" data-options-for="scheduleEnabled">
                            <label class="tmi-option"><input type="radio" name="schedule_level" value="view_own"><span>View their own schedule</span></label>
                            <label class="tmi-option"><input type="radio" name="schedule_level" value="complete_own"><span>View and complete their own schedule</span></label>
                            <label class="tmi-option"><input type="radio" name="schedule_level" value="edit_own"><span>Edit their own schedule</span></label>
                            <label class="tmi-option"><input type="radio" name="schedule_level" value="edit_all"><span>Edit everyone's schedule</span></label>
                            <label class="tmi-option"><input type="radio" name="schedule_level" value="delete_all"><span>Edit and delete everyone's schedule</span></label>
                        </div>
                    </section>

                    <section class="tmi-permission" data-section="time_tracking">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Time tracking and timesheets</h3><label class="tmi-switch"><input type="checkbox" name="time_tracking_enabled" id="timeTrackingEnabled" value="1"><span></span></label></div>
                        <div class="tmi-options" data-options-for="timeTrackingEnabled">
                            <label class="tmi-option"><input type="radio" name="time_tracking_level" value="timer_own"><span>Start and stop their own timers</span></label>
                            <label class="tmi-option"><input type="radio" name="time_tracking_level" value="manage_own"><span>Track, manually enter, and edit their own time</span></label>
                            <label class="tmi-option"><input type="radio" name="time_tracking_level" value="manage_all"><span>Track, manually enter, and edit everyone's time</span></label>
                        </div>
                    </section>

                    <section class="tmi-permission" data-section="notes">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Notes</h3><label class="tmi-switch"><input type="checkbox" name="notes_enabled" id="notesEnabled" value="1"><span></span></label></div>
                        <p class="tmi-perm-copy">Includes notes across FieldPlx. Notes for a feature remain hidden when that feature is not available to the user.</p>
                        <div class="tmi-options" data-options-for="notesEnabled">
                            <label class="tmi-option"><input type="radio" name="notes_level" value="job_visit"><span>View notes on jobs and visits only</span></label>
                            <label class="tmi-option"><input type="radio" name="notes_level" value="view_all"><span>View all notes</span></label>
                            <label class="tmi-option"><input type="radio" name="notes_level" value="edit_all"><span>View and edit all</span></label>
                            <label class="tmi-option"><input type="radio" name="notes_level" value="delete_all"><span>View, edit, and delete all</span></label>
                        </div>
                    </section>

                    <section class="tmi-permission" data-section="files_media">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Files and media</h3><label class="tmi-switch"><input type="checkbox" name="files_media_enabled" id="filesMediaEnabled" value="1"><span></span></label></div>
                        <p class="tmi-perm-copy">Allows viewing of all customer files and attachments.</p>
                    </section>

                    <section class="tmi-permission" data-section="expenses">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Expenses</h3><label class="tmi-switch"><input type="checkbox" name="expenses_enabled" id="expensesEnabled" value="1"><span></span></label></div>
                        <div class="tmi-options" data-options-for="expensesEnabled">
                            <label class="tmi-option"><input type="radio" name="expenses_level" value="own"><span>View, record, and edit their own</span></label>
                            <label class="tmi-option"><input type="radio" name="expenses_level" value="all"><span>View, record, and edit everyone's</span></label>
                        </div>
                    </section>

                    <section class="tmi-permission" data-section="pricing">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Show pricing</h3><label class="tmi-switch"><input type="checkbox" name="show_pricing" id="showPricing" value="1"><span></span></label></div>
                        <p class="tmi-perm-copy">Allows viewing and editing of pricing on quotes, invoices, and line items on jobs.</p>
                    </section>

                    <section class="tmi-permission" data-section="job_costing">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Job costing</h3><label class="tmi-switch"><input type="checkbox" name="job_costing" id="jobCosting" value="1"><span></span></label></div>
                        <p class="tmi-perm-copy">Show job profit by tracking revenue and costs from line items, labor, and expenses.</p>
                        <div class="tmi-disabled-note" id="jobCostingNote">Turn on show pricing, timesheets, expenses, and jobs to give access to job costing.</div>
                    </section>

                    <section class="tmi-permission" data-section="clients">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Customers and properties</h3><label class="tmi-switch"><input type="checkbox" name="clients_enabled" id="clientsEnabled" value="1"><span></span></label></div>
                        <p class="tmi-perm-copy">Includes access to customer custom fields.</p>
                        <div class="tmi-options" data-options-for="clientsEnabled">
                            <label class="tmi-option"><input type="radio" name="clients_level" value="basic"><span>View customer name and address only</span></label>
                            <label class="tmi-option"><input type="radio" name="clients_level" value="full"><span>View full customer and property info</span></label>
                            <label class="tmi-option"><input type="radio" name="clients_level" value="edit"><span>View and edit full customer and property info</span></label>
                            <label class="tmi-option"><input type="radio" name="clients_level" value="delete"><span>View, edit, and delete full customer and property info</span></label>
                        </div>
                        <label class="tmi-check"><input type="checkbox" name="show_clients_menu" id="showClientsMenu" value="1"><span>Show Customers on their FieldPlx menu</span></label>
                    </section>

                    <section class="tmi-permission" data-section="requests">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Requests</h3><label class="tmi-switch"><input type="checkbox" name="requests_enabled" id="requestsEnabled" value="1"><span></span></label></div>
                        <div class="tmi-options" data-options-for="requestsEnabled">
                            <label class="tmi-option"><input type="radio" name="requests_level" value="view"><span>View only</span></label>
                            <label class="tmi-option"><input type="radio" name="requests_level" value="edit"><span>View, create, and edit</span></label>
                            <label class="tmi-option"><input type="radio" name="requests_level" value="delete"><span>View, create, edit, and delete</span></label>
                        </div>
                        <label class="tmi-check"><input type="checkbox" name="show_requests_menu" id="showRequestsMenu" value="1"><span>Show Requests on their FieldPlx menu</span></label>
                    </section>

                    <section class="tmi-permission" data-section="quotes">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Quotes</h3><label class="tmi-switch"><input type="checkbox" name="quotes_enabled" id="quotesEnabled" value="1"><span></span></label></div>
                        <div class="tmi-options" data-options-for="quotesEnabled">
                            <label class="tmi-option"><input type="radio" name="quotes_level" value="view"><span>View only</span></label>
                            <label class="tmi-option"><input type="radio" name="quotes_level" value="edit"><span>View, create, and edit quotes; view and apply quote templates</span></label>
                            <label class="tmi-option"><input type="radio" name="quotes_level" value="delete"><span>View, create, edit, and delete quotes; view and apply quote templates</span></label>
                        </div>
                        <label class="tmi-check"><input type="checkbox" name="show_quotes_menu" id="showQuotesMenu" value="1"><span>Show Quotes on their FieldPlx menu</span></label>
                    </section>

                    <section class="tmi-permission" data-section="jobs">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Jobs</h3><label class="tmi-switch"><input type="checkbox" name="jobs_enabled" id="jobsEnabled" value="1"><span></span></label></div>
                        <div class="tmi-options" data-options-for="jobsEnabled">
                            <label class="tmi-option"><input type="radio" name="jobs_level" value="view"><span>View only</span></label>
                            <label class="tmi-option"><input type="radio" name="jobs_level" value="edit"><span>View, create, and edit</span></label>
                            <label class="tmi-option"><input type="radio" name="jobs_level" value="delete"><span>View, create, edit, and delete</span></label>
                        </div>
                        <div class="tmi-schedule-note" id="jobsScheduleNote">Select <strong>edit their own schedule</strong> to create and edit jobs.</div>
                        <label class="tmi-check"><input type="checkbox" name="show_jobs_menu" id="showJobsMenu" value="1"><span>Show Jobs on their FieldPlx menu</span></label>
                    </section>

                    <section class="tmi-permission" data-section="invoices">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Invoices</h3><label class="tmi-switch"><input type="checkbox" name="invoices_enabled" id="invoicesEnabled" value="1"><span></span></label></div>
                        <div class="tmi-options" data-options-for="invoicesEnabled">
                            <label class="tmi-option"><input type="radio" name="invoices_level" value="view"><span>View only</span></label>
                            <label class="tmi-option"><input type="radio" name="invoices_level" value="edit"><span>View, create, and edit</span></label>
                            <label class="tmi-option"><input type="radio" name="invoices_level" value="delete"><span>View, create, edit, and delete</span></label>
                        </div>
                        <label class="tmi-check"><input type="checkbox" name="show_invoices_menu" id="showInvoicesMenu" value="1"><span>Show Invoices on their FieldPlx menu</span></label>
                    </section>

                    <section class="tmi-permission" data-section="payments">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Payments</h3><label class="tmi-switch"><input type="checkbox" name="payments_enabled" id="paymentsEnabled" value="1"><span></span></label></div>
                        <p class="tmi-perm-copy">Allow payment collection on quotes and invoices. Turning this on applies the required permissions below. If a required permission is removed, payments will also be removed automatically.</p>
                        <div class="tmi-required"><strong>Required permissions:</strong><span class="ok"><i data-lucide="check"></i>Show pricing</span><span class="ok"><i data-lucide="check"></i>Customers and Properties: Edit access</span><span class="ok"><i data-lucide="check"></i>Quotes and/or Invoices: Edit access</span></div>
                        <div class="tmi-options" data-options-for="paymentsEnabled">
                            <label class="tmi-option"><input type="radio" name="payments_level" value="quotes"><span>Collect on quotes only</span></label>
                            <label class="tmi-option"><input type="radio" name="payments_level" value="invoices"><span>Collect on invoices only</span></label>
                            <label class="tmi-option"><input type="radio" name="payments_level" value="both"><span>Collect on both</span></label>
                        </div>
                    </section>

                    <section class="tmi-permission" data-section="client_communications">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Customer communications</h3><label class="tmi-switch"><input type="checkbox" name="client_communications_enabled" id="clientCommunicationsEnabled" value="1"><span></span></label></div>
                        <p class="tmi-perm-copy">Information this user does not have permission to access will be hidden. Turning this on applies the required customer view permission. If it is removed, customer communications will also be removed automatically.</p>
                        <div class="tmi-required"><strong>Required permissions:</strong><span class="ok"><i data-lucide="check"></i>Customers and Properties: View access</span></div>
                        <div class="tmi-options" data-options-for="clientCommunicationsEnabled">
                            <label class="tmi-option"><input type="radio" name="client_communications_level" value="view"><span>View only</span></label>
                            <label class="tmi-option"><input type="radio" name="client_communications_level" value="send"><span>View and send</span></label>
                        </div>
                    </section>

                    <section class="tmi-permission" data-section="reports">
                        <div class="tmi-perm-head"><h3 class="tmi-perm-name">Reports</h3><label class="tmi-switch"><input type="checkbox" name="reports_enabled" id="reportsEnabled" value="1"><span></span></label></div>
                        <p class="tmi-perm-copy">Users will only be able to see reports available to them based on their other permissions.</p>
                    </section>
                </div>
            </section>

            <section class="tmi-card">
                <h2 class="tmi-card-title">Communications</h2>

                <div class="tmi-comms">
                    <div>
                        <h3 class="tmi-subheading">Email subscriptions</h3>
                        <label class="tmi-checkbox-line"><input type="checkbox" name="surveys_enabled" id="surveysEnabled" value="1"><span><span class="tmi-checkbox-title">Surveys</span><span class="tmi-checkbox-desc">Receive occasional surveys to tell us how we're doing</span></span></label>
                        <label class="tmi-checkbox-line"><input type="checkbox" name="error_messages_enabled" id="errorMessagesEnabled" value="1"><span><span class="tmi-checkbox-title">Error messages</span><span class="tmi-checkbox-desc">Get notified of important warnings and errors in FieldPlx, such as undeliverable customer emails or integration errors</span></span></label>
                        <label class="tmi-checkbox-line"><input type="checkbox" name="marketing_reminder_emails" id="marketingReminderEmails" value="1"><span><span class="tmi-checkbox-title">Marketing reminder emails</span><span class="tmi-checkbox-desc">Receive periodic email reminders about upcoming marketing opportunities</span></span></label>
                    </div>

                    <div class="tmi-invitation-language" id="invitationLanguageBlock">
                        <h3 class="tmi-subheading">Invitation language</h3>
                        <p class="tmi-language-note">The chosen language <strong>only applies to the invitation and cannot be changed once sent.</strong></p>
                        <div class="tmi-options">
                            <label class="tmi-option"><input type="radio" name="invitation_language" value="en"><span>English</span></label>
                            <label class="tmi-option"><input type="radio" name="invitation_language" value="es"><span>Spanish<br><small class="tmi-helptext">The mobile experience can use Spanish for supported non-admin users when their device language is Spanish.</small></span></label>
                        </div>
                    </div>
                </div>
            </section>

            <div class="tmi-danger-row" id="deactivateRow">
                <button type="button" class="tmi-btn danger" id="deactivateUser"><i data-lucide="user-x"></i><span>Deactivate User</span></button>
            </div>

            <div class="tmi-actions tmi-new-actions" id="newMemberActions">
                <button type="button" class="tmi-btn" id="saveMember">Save Member</button>
                <button type="button" class="tmi-btn primary" id="saveAssign">Save and Assign</button>
            </div>
            <div class="tmi-actions tmi-edit-actions" id="editMemberActions">
                <button type="button" class="tmi-btn primary" id="saveChanges">Save Changes</button>
            </div>
        </form>

        <div class="tmi-privacy" id="privacyPolicyLink">
            <a href="/privacy-policy" target="_blank" rel="noopener">Privacy Policy</a>
        </div>
    </main>
</div>


<div class="tmi-modal-backdrop" id="passwordModal">
    <div class="tmi-modal">
        <div class="tmi-modal-head"><h3>Change your password</h3><button type="button" class="tmi-modal-close" data-modal-close="passwordModal" aria-label="Close"><i data-lucide="x"></i></button></div>
        <div class="tmi-modal-body">
            <p class="tmi-password-note" id="passwordModalNote"></p>
            <div class="tmi-password-fields">
                <input class="tmi-input" type="password" id="currentPassword" placeholder="Current password" autocomplete="current-password">
                <input class="tmi-input" type="password" id="newPassword" placeholder="New password" autocomplete="new-password">
                <input class="tmi-input" type="password" id="confirmNewPassword" placeholder="Confirm new password" autocomplete="new-password">
            </div>
        </div>
        <div class="tmi-modal-foot"><button type="button" class="tmi-btn" data-modal-close="passwordModal">Cancel</button><button type="button" class="tmi-btn primary" id="confirmPasswordChange">Change password</button></div>
    </div>
</div>

<div class="tmi-modal-backdrop" id="resetPasswordModal">
    <div class="tmi-modal">
        <div class="tmi-modal-head"><h3>Reset Password</h3><button type="button" class="tmi-modal-close" data-modal-close="resetPasswordModal" aria-label="Close"><i data-lucide="x"></i></button></div>
        <div class="tmi-modal-body"><p class="tmi-password-note" id="resetPasswordNote">Send a password reset email to this team member.</p></div>
        <div class="tmi-modal-foot"><button type="button" class="tmi-btn" data-modal-close="resetPasswordModal">Cancel</button><button type="button" class="tmi-btn primary" id="confirmPasswordReset">Send</button></div>
    </div>
</div>

<div class="tmi-modal-backdrop" id="scheduleModal">
    <div class="tmi-modal schedule">
        <div class="tmi-modal-head"><h3>Edit schedule</h3><button type="button" class="tmi-modal-close" data-modal-close="scheduleModal" aria-label="Close"><i data-lucide="x"></i></button></div>
        <div class="tmi-modal-body"><div id="scheduleRows"></div></div>
        <div class="tmi-modal-foot"><button type="button" class="tmi-btn" data-modal-close="scheduleModal">Cancel</button><button type="button" class="tmi-btn primary" id="saveWorkingHours">Save</button></div>
    </div>
</div>

<div class="tmi-modal-backdrop" id="deactivateModal">
    <div class="tmi-modal">
        <div class="tmi-modal-head"><h3>Deactivate user?</h3><button type="button" class="tmi-modal-close" data-modal-close="deactivateModal" aria-label="Close"><i data-lucide="x"></i></button></div>
        <div class="tmi-modal-body"><p class="tmi-password-note">This removes the user's seat, signs out active sessions and removes them from crews. Their member record is kept and can be assigned again later.</p></div>
        <div class="tmi-modal-foot"><button type="button" class="tmi-btn" data-modal-close="deactivateModal">Cancel</button><button type="button" class="tmi-btn danger" id="confirmDeactivate">Deactivate User</button></div>
    </div>
</div>

<script>
(function(){
'use strict';

var csrf = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var api = 'api/team.php';
var userId = <?= (int)$editUserId ?>;
var member = null;
var capabilities = {};
var availability = [];
var changingProgrammatically = false;

function E(id){ return document.getElementById(id); }
function esc(v){ return String(v == null ? '' : v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function toast(type,message){ if(window.fieldplxToast) window.fieldplxToast(type,message); }
function icons(){ if(window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons({attrs:{'stroke-width':1.9}}); }
function post(action){ var f = new FormData(); f.append('action',action); return f; }
function req(fd){ fd.append('csrf_token',csrf); return fetch(api,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(function(r){ return r.text().then(function(x){ var d; try{ d=JSON.parse(x); }catch(e){ throw new Error('Invalid server response.'); } if(!r.ok || !d.success) throw new Error(d.message || 'Request failed.'); return d; }); }); }
function openModal(id){var x=E(id);if(x)x.classList.add('open');icons();}
function closeModal(id){var x=E(id);if(x)x.classList.remove('open');}
function pad2(n){return String(n).padStart(2,'0');}
function normalizeTime(v){v=String(v||'').slice(0,5);return /^\d{2}:\d{2}$/.test(v)?v:'09:00';}
function defaultAvailability(){return [0,1,2,3,4,5,6].map(function(day){return {weekday:day,is_available:(day>0&&day<6)?1:0,start_time:'09:00',end_time:'17:00'};});}
function availabilityByDay(day){var r=(availability||[]).find(function(x){return Number(x.weekday)===Number(day)});return r||defaultAvailability()[day];}
function dayName(day){return ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][Number(day)]||'';}
function formatTimeRange(r){return Number(r.is_available)===1?normalizeTime(r.start_time)+' – '+normalizeTime(r.end_time):'Unavailable';}
function renderWorkingHours(){var body=E('workingHoursSummary').querySelector('tbody');body.innerHTML='';for(var d=0;d<7;d++){var r=availabilityByDay(d),tr=document.createElement('tr');tr.innerHTML='<td>'+esc(dayName(d))+'</td><td>'+esc(formatTimeRange(r))+'</td>';body.appendChild(tr);}}
function setRadio(name,value){ var x=document.querySelector('input[name="'+name+'"][value="'+value+'"]'); if(x)x.checked=true; }
function checked(id,value){ var x=E(id); if(x)x.checked=!!Number(value); }
function value(id,v){ var x=E(id); if(x)x.value=(v==null?'':v); }
function isOn(id){ return !!(E(id)&&E(id).checked); }
function radioValue(name){ var x=document.querySelector('input[name="'+name+'"]:checked'); return x?x.value:''; }

var presets = {
    field_crew:{
        schedule_enabled:1,schedule_level:'complete_own',time_tracking_enabled:1,time_tracking_level:'timer_own',notes_enabled:1,notes_level:'job_visit',files_media_enabled:0,expenses_enabled:0,expenses_level:'own',show_pricing:0,job_costing:0,clients_enabled:0,clients_level:'basic',show_clients_menu:0,requests_enabled:0,requests_level:'view',show_requests_menu:0,quotes_enabled:0,quotes_level:'view',show_quotes_menu:0,jobs_enabled:1,jobs_level:'view',show_jobs_menu:1,invoices_enabled:0,invoices_level:'view',show_invoices_menu:0,payments_enabled:0,payments_level:'both',client_communications_enabled:0,client_communications_level:'view',reports_enabled:0
    },
    senior_field_crew:{
        schedule_enabled:1,schedule_level:'edit_own',time_tracking_enabled:1,time_tracking_level:'manage_own',notes_enabled:1,notes_level:'edit_all',files_media_enabled:1,expenses_enabled:1,expenses_level:'own',show_pricing:1,job_costing:0,clients_enabled:1,clients_level:'edit',show_clients_menu:1,requests_enabled:1,requests_level:'edit',show_requests_menu:1,quotes_enabled:1,quotes_level:'edit',show_quotes_menu:1,jobs_enabled:1,jobs_level:'edit',show_jobs_menu:1,invoices_enabled:1,invoices_level:'edit',show_invoices_menu:1,payments_enabled:1,payments_level:'both',client_communications_enabled:0,client_communications_level:'view',reports_enabled:0
    },
    crew_lead:{
        schedule_enabled:1,schedule_level:'edit_all',time_tracking_enabled:1,time_tracking_level:'manage_all',notes_enabled:1,notes_level:'edit_all',files_media_enabled:1,expenses_enabled:1,expenses_level:'all',show_pricing:1,job_costing:1,clients_enabled:1,clients_level:'edit',show_clients_menu:1,requests_enabled:1,requests_level:'edit',show_requests_menu:1,quotes_enabled:1,quotes_level:'edit',show_quotes_menu:1,jobs_enabled:1,jobs_level:'edit',show_jobs_menu:1,invoices_enabled:1,invoices_level:'edit',show_invoices_menu:1,payments_enabled:1,payments_level:'both',client_communications_enabled:1,client_communications_level:'view',reports_enabled:0
    },
    manager:{
        schedule_enabled:1,schedule_level:'edit_all',time_tracking_enabled:1,time_tracking_level:'manage_all',notes_enabled:1,notes_level:'edit_all',files_media_enabled:1,expenses_enabled:1,expenses_level:'all',show_pricing:1,job_costing:1,clients_enabled:1,clients_level:'edit',show_clients_menu:1,requests_enabled:1,requests_level:'edit',show_requests_menu:1,quotes_enabled:1,quotes_level:'edit',show_quotes_menu:1,jobs_enabled:1,jobs_level:'edit',show_jobs_menu:1,invoices_enabled:1,invoices_level:'edit',show_invoices_menu:1,payments_enabled:1,payments_level:'both',client_communications_enabled:1,client_communications_level:'send',reports_enabled:1
    }
};

var boolMap={
    schedule_enabled:'scheduleEnabled',time_tracking_enabled:'timeTrackingEnabled',notes_enabled:'notesEnabled',files_media_enabled:'filesMediaEnabled',expenses_enabled:'expensesEnabled',show_pricing:'showPricing',job_costing:'jobCosting',clients_enabled:'clientsEnabled',show_clients_menu:'showClientsMenu',requests_enabled:'requestsEnabled',show_requests_menu:'showRequestsMenu',quotes_enabled:'quotesEnabled',show_quotes_menu:'showQuotesMenu',jobs_enabled:'jobsEnabled',show_jobs_menu:'showJobsMenu',invoices_enabled:'invoicesEnabled',show_invoices_menu:'showInvoicesMenu',payments_enabled:'paymentsEnabled',client_communications_enabled:'clientCommunicationsEnabled',reports_enabled:'reportsEnabled',surveys_enabled:'surveysEnabled',error_messages_enabled:'errorMessagesEnabled',marketing_reminder_emails:'marketingReminderEmails'
};
var radioMap={schedule_level:'schedule_level',time_tracking_level:'time_tracking_level',notes_level:'notes_level',expenses_level:'expenses_level',clients_level:'clients_level',requests_level:'requests_level',quotes_level:'quotes_level',jobs_level:'jobs_level',invoices_level:'invoices_level',payments_level:'payments_level',client_communications_level:'client_communications_level',invitation_language:'invitation_language'};

function applyData(s){
    changingProgrammatically=true;
    Object.keys(boolMap).forEach(function(k){ if(Object.prototype.hasOwnProperty.call(s,k)) checked(boolMap[k],s[k]); });
    Object.keys(radioMap).forEach(function(k){ if(s[k]) setRadio(radioMap[k],String(s[k])); });
    changingProgrammatically=false;
    refreshDependencies(false);
}

function selectPreset(name,apply){
    document.querySelectorAll('.tmi-preset').forEach(function(x){ x.classList.toggle('active',x.getAttribute('data-preset')===name); });
    E('presetKey').value=name;
    if(apply && presets[name]) applyData(presets[name]);
    icons();
}

function markCustom(){
    if(changingProgrammatically || isOn('isAdmin')) return;
    if(E('presetKey').value!=='custom') selectPreset('custom',false);
}

function disableOptionGroup(toggleId){
    var on=isOn(toggleId);
    document.querySelectorAll('[data-options-for="'+toggleId+'"] input').forEach(function(x){ x.disabled=!on; });
    var section=E(toggleId).closest('.tmi-permission');
    if(section){
        section.classList.toggle('options-hidden',!on);
        section.querySelectorAll('.tmi-check input').forEach(function(x){ x.disabled=!on; });
    }
}

function clientLevelRank(v){ return {basic:1,full:2,edit:3,delete:4}[v]||0; }
function moduleLevelRank(v){ return {view:1,edit:2,delete:3}[v]||0; }
function scheduleLevelRank(v){ return {view_own:1,complete_own:2,edit_own:3,edit_all:4,delete_all:5}[v]||0; }
function scheduleAllowsJobEditing(){ return isOn('scheduleEnabled') && scheduleLevelRank(radioValue('schedule_level'))>=3; }

function refreshJobScheduleDependency(){
    var ready=scheduleAllowsJobEditing();
    document.querySelectorAll('input[name="jobs_level"]').forEach(function(x){
        if(x.value==='edit' || x.value==='delete') x.disabled=!isOn('jobsEnabled') || !ready;
    });
    var note=E('jobsScheduleNote');
    if(note) note.classList.toggle('show',isOn('jobsEnabled') && !ready);
    if(isOn('jobsEnabled') && !ready && moduleLevelRank(radioValue('jobs_level'))>=2){
        setRadio('jobs_level','view');
    }
}

function ensurePaymentDependencies(){
    var level=radioValue('payments_level')||'both';
    checked('showPricing',1);
    checked('clientsEnabled',1);
    if(clientLevelRank(radioValue('clients_level'))<3) setRadio('clients_level','edit');
    if(level==='quotes' || level==='both'){
        checked('quotesEnabled',1);
        if(moduleLevelRank(radioValue('quotes_level'))<2) setRadio('quotes_level','edit');
    }
    if(level==='invoices' || level==='both'){
        checked('invoicesEnabled',1);
        if(moduleLevelRank(radioValue('invoices_level'))<2) setRadio('invoices_level','edit');
    }
}

function paymentsDependenciesValid(){
    if(!isOn('showPricing') || !isOn('clientsEnabled') || clientLevelRank(radioValue('clients_level'))<3) return false;
    var level=radioValue('payments_level')||'both';
    var q=isOn('quotesEnabled') && moduleLevelRank(radioValue('quotes_level'))>=2;
    var i=isOn('invoicesEnabled') && moduleLevelRank(radioValue('invoices_level'))>=2;
    if(level==='quotes') return q;
    if(level==='invoices') return i;
    return q&&i;
}

function communicationDependencyValid(){ return isOn('clientsEnabled') && clientLevelRank(radioValue('clients_level'))>=1; }
function jobCostingDependenciesValid(){ return isOn('showPricing') && isOn('timeTrackingEnabled') && isOn('expensesEnabled') && isOn('jobsEnabled'); }

function refreshDependencies(autoApply){
    ['scheduleEnabled','timeTrackingEnabled','notesEnabled','expensesEnabled','clientsEnabled','requestsEnabled','quotesEnabled','jobsEnabled','invoicesEnabled','paymentsEnabled','clientCommunicationsEnabled'].forEach(disableOptionGroup);
    refreshJobScheduleDependency();

    var jc=E('jobCosting');
    var jcOk=jobCostingDependenciesValid();
    jc.disabled=!jcOk;
    E('jobCostingNote').style.color=jcOk?'var(--tm-muted)':'#a6b0b6';
    if(!jcOk && jc.checked){ changingProgrammatically=true; jc.checked=false; changingProgrammatically=false; }

    if(autoApply && isOn('paymentsEnabled') && !paymentsDependenciesValid()){
        changingProgrammatically=true; E('paymentsEnabled').checked=false; changingProgrammatically=false;
    }
    if(autoApply && isOn('clientCommunicationsEnabled') && !communicationDependencyValid()){
        changingProgrammatically=true; E('clientCommunicationsEnabled').checked=false; changingProgrammatically=false;
    }
}

function toggleAdminMode(){
    var admin=isOn('isAdmin');
    E('permissionCard').classList.toggle('tmi-admin-mode',admin);
    E('memberForm').classList.toggle('tmi-admin-selected',admin);
}

function populateCountries(rows){
    var h='<option value="0">Country</option>',usId=0;
    (rows||[]).forEach(function(c){
        var id=Number(c.id);
        if(String(c.iso2||'').toUpperCase()==='US' || /^(united states|united states of america|usa)$/i.test(String(c.name||c.country_name||'').trim())) usId=id;
        h+='<option value="'+id+'">'+esc(c.name||c.country_name||c.iso2||('Country '+c.id))+'</option>';
    });
    E('countryId').innerHTML=h;
    if(userId<=0 && usId>0) E('countryId').value=String(usId);
}

var originalAvatarPath='';
function renderDefaultAvatar(){ E('avatarPreview').innerHTML='<i data-lucide="user-round"></i>'; icons(); }
function updateAvatarButtons(hasAvatar){
    E('avatarButtonText').textContent=hasAvatar?'Change Image':'Upload Image';
    E('removeAvatarButton').style.display=hasAvatar?'inline-flex':'none';
}

function fillMember(m,s,caps,av){
    member=m||{};
    capabilities=caps||{};
    availability=Array.isArray(av)&&av.length?av:defaultAvailability();
    originalAvatarPath=String(member.avatar_path||'');
    E('removeAvatar').value='0';
    value('fullName',((member.first_name||'')+' '+(member.last_name||'')).trim());
    value('email',member.email||'');
    value('phone',member.phone||'');
    value('addressLine1',member.address_line1||'');
    value('city',member.city||'');
    value('state',member.state||'');
    value('postalCode',member.postal_code||'');
    value('countryId',member.country_id||0);
    value('laborRate',member.labor_rate==null?'0.00':member.labor_rate);
    checked('isAdmin',member.is_tenant_admin||0);
    if(member.avatar_path){ E('avatarPreview').innerHTML='<img src="'+esc(member.avatar_path)+'" alt="">'; }
    else { renderDefaultAvatar(); }
    updateAvatarButtons(!!member.avatar_path);
    applyData(s||{});
    selectPreset((s&&s.preset_key)||'custom',false);
    if(s&&Number(s.invitation_language_locked||0)===1){ document.querySelectorAll('input[name="invitation_language"]').forEach(function(x){x.disabled=true;}); }
    toggleAdminMode();
    if(userId>0){
        E('pageHeading').textContent=((member.first_name||'')+' '+(member.last_name||'')).trim()||'Team member';
        E('workingHoursCard').classList.add('show');
        E('newMemberActions').classList.add('hidden');
        E('editMemberActions').classList.add('show');
        E('invitationLanguageBlock').style.display='none';
        var canChangeSelf=Number(capabilities.can_change_password||0)===1;
        var canSendReset=Number(capabilities.can_send_password_reset||0)===1;
        if(canChangeSelf || canSendReset){
            E('passwordActionWrap').classList.add('show');
            E('passwordAction').innerHTML='<i data-lucide="'+(canChangeSelf?'key-round':'mail')+'"></i><span id="passwordActionText">'+(canChangeSelf?'Change password':'Send Password Reset Email')+'</span>';
        }
        if(Number(capabilities.can_deactivate||0)===1){E('deactivateRow').classList.add('show');}
        renderWorkingHours();
    }
    icons();
}

function load(){
    var f=post('meta');
    req(f).then(function(d){
        if(d.migration_required){ throw new Error('Run the supplied Team Member SQL migration before using this page.'); }
        populateCountries(d.countries||[]);
        if(userId>0){
            var x=post('get_member');x.append('user_id',String(userId));
            return req(x).then(function(r){ fillMember(r.member||{},r.team_settings||{},r.capabilities||{},r.availability||[]); });
        }
        applyData(Object.assign({},presets.field_crew,{surveys_enabled:1,error_messages_enabled:1,marketing_reminder_emails:1,invitation_language:'en'}));
        selectPreset('field_crew',false);
        toggleAdminMode();
        icons();
    }).then(function(){
        E('loading').style.display='none';
        E('memberForm').style.display='block';
        E('privacyPolicyLink').style.display='block';
    }).catch(function(e){
        E('loading').className='tmi-error';
        E('loading').textContent=e.message;
        toast('error',e.message);
    });
}

E('avatarInput').addEventListener('change',function(){
    var f=this.files&&this.files[0];if(!f)return;
    E('removeAvatar').value='0';
    var r=new FileReader();r.onload=function(){E('avatarPreview').innerHTML='<img src="'+r.result+'" alt="">';updateAvatarButtons(true);};r.readAsDataURL(f);
});

E('removeAvatarButton').addEventListener('click',function(){
    E('avatarInput').value='';
    E('removeAvatar').value=originalAvatarPath!==''?'1':'0';
    renderDefaultAvatar();
    updateAvatarButtons(false);
});

document.querySelectorAll('.tmi-preset').forEach(function(x){
    x.addEventListener('click',function(){
        if(isOn('isAdmin')) return;
        var p=x.getAttribute('data-preset');
        if(p==='custom'){ selectPreset('custom',false); return; }
        selectPreset(p,true);
    });
});

E('isAdmin').addEventListener('change',function(){ toggleAdminMode(); });

E('paymentsEnabled').addEventListener('change',function(){
    if(this.checked){ changingProgrammatically=true; ensurePaymentDependencies(); changingProgrammatically=false; }
    refreshDependencies(false);markCustom();
});
E('clientCommunicationsEnabled').addEventListener('change',function(){
    if(this.checked && !communicationDependencyValid()){
        changingProgrammatically=true;checked('clientsEnabled',1);if(clientLevelRank(radioValue('clients_level'))<1)setRadio('clients_level','full');changingProgrammatically=false;
    }
    refreshDependencies(false);markCustom();
});

E('memberForm').addEventListener('change',function(e){
    if(e.target===E('isAdmin') || e.target===E('avatarInput')) return;
    if(e.target.name==='payments_enabled' || e.target.name==='client_communications_enabled') return;
    refreshDependencies(true);
    markCustom();
});

function save(assign,button){
    if(!E('memberForm').checkValidity()){E('memberForm').reportValidity();return;}
    if(isOn('paymentsEnabled')&&!paymentsDependenciesValid()){toast('error','Payments requires pricing, customer edit access, and quote/invoice edit access.');return;}
    E('saveAndAssign').value=assign?'1':'0';
    var fd=new FormData(E('memberForm'));
    fd.append('csrf_token',csrf);
    button.disabled=true;button.classList.add('loading');
    req(fd).then(function(d){
        toast('success',d.message);
        window.setTimeout(function(){
            if(userId>0){ window.location.href='team.php'; return; }
            if(assign){ window.location.href=d.assign_url||('team.php?tab=crews&assign_user='+encodeURIComponent(d.user_id||'')); }
            else { window.location.href='team.php'; }
        },450);
    }).catch(function(e){toast('error',e.message);button.disabled=false;button.classList.remove('loading');});
}

E('saveMember').addEventListener('click',function(){save(false,this);});
E('saveAssign').addEventListener('click',function(){save(true,this);});
E('saveChanges').addEventListener('click',function(){save(false,this);});

document.querySelectorAll('[data-modal-close]').forEach(function(b){b.addEventListener('click',function(){closeModal(this.getAttribute('data-modal-close'));});});
document.querySelectorAll('.tmi-modal-backdrop').forEach(function(m){m.addEventListener('mousedown',function(e){if(e.target===m)closeModal(m.id);});});
document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.tmi-modal-backdrop.open').forEach(function(m){closeModal(m.id);});});

E('passwordAction').addEventListener('click',function(){
    if(Number(capabilities.can_change_password||0)===1){
        E('currentPassword').style.display='block';
        E('currentPassword').value='';E('newPassword').value='';E('confirmNewPassword').value='';
        E('passwordModalNote').textContent='Enter your current password, then choose a new password.';
        openModal('passwordModal');
        return;
    }
    if(Number(capabilities.can_send_password_reset||0)===1){
        var name=((member&&member.first_name)||'')+' '+((member&&member.last_name)||'');
        name=name.trim()||'this team member';
        E('resetPasswordNote').textContent='Send a password reset email to '+name;
        openModal('resetPasswordModal');
    }
});
E('confirmPasswordChange').addEventListener('click',function(){
    var b=this,n=E('newPassword').value,c=E('confirmNewPassword').value;
    if(n.length<8){toast('error','New password must be at least 8 characters.');return;}
    if(n!==c){toast('error','New password and confirmation do not match.');return;}
    var f=post('change_password');f.append('user_id',String(userId));f.append('current_password',E('currentPassword').value);f.append('new_password',n);f.append('confirm_password',c);
    b.disabled=true;req(f).then(function(d){toast('success',d.message);closeModal('passwordModal');}).catch(function(e){toast('error',e.message);}).finally(function(){b.disabled=false;});
});

E('confirmPasswordReset').addEventListener('click',function(){
    var b=this,f=post('send_password_reset');
    f.append('user_id',String(userId));
    b.disabled=true;b.classList.add('loading');
    req(f).then(function(d){toast('success',d.message);closeModal('resetPasswordModal');}).catch(function(e){toast('error',e.message);}).finally(function(){b.disabled=false;b.classList.remove('loading');});
});

function buildScheduleEditor(){
    var box=E('scheduleRows'),h='';
    for(var d=0;d<7;d++){
        var r=availabilityByDay(d),on=Number(r.is_available)===1;
        h+='<div class="tmi-day-row" data-day="'+d+'"><div class="tmi-day-name">'+esc(dayName(d))+'</div><label class="tmi-mini-switch"><input type="checkbox" class="day-enabled" '+(on?'checked':'')+'><span></span></label><div class="tmi-time-field"><label>Start time</label><input type="time" class="tmi-time-input day-start" value="'+esc(normalizeTime(r.start_time))+'" '+(on?'':'disabled')+'></div><div class="tmi-time-field"><label>End time</label><input type="time" class="tmi-time-input day-end" value="'+esc(normalizeTime(r.end_time||'17:00'))+'" '+(on?'':'disabled')+'></div></div>';
    }
    box.innerHTML=h;
    box.querySelectorAll('.day-enabled').forEach(function(x){x.addEventListener('change',function(){var row=this.closest('.tmi-day-row');row.querySelector('.day-start').disabled=!this.checked;row.querySelector('.day-end').disabled=!this.checked;});});
}
E('editWorkingHours').addEventListener('click',function(){buildScheduleEditor();openModal('scheduleModal');});
E('saveWorkingHours').addEventListener('click',function(){
    var rows=[],valid=true;
    E('scheduleRows').querySelectorAll('.tmi-day-row').forEach(function(row){var on=row.querySelector('.day-enabled').checked,start=row.querySelector('.day-start').value||'09:00',end=row.querySelector('.day-end').value||'17:00';if(on&&start>=end)valid=false;rows.push({weekday:Number(row.dataset.day),is_available:on?1:0,start_time:start,end_time:end});});
    if(!valid){toast('error','End time must be later than start time for every available day.');return;}
    var b=this,f=post('save_availability');f.append('user_id',String(userId));f.append('availability_json',JSON.stringify(rows));b.disabled=true;req(f).then(function(d){availability=d.availability||rows;renderWorkingHours();closeModal('scheduleModal');toast('success',d.message);}).catch(function(e){toast('error',e.message);}).finally(function(){b.disabled=false;});
});

E('deactivateUser').addEventListener('click',function(){openModal('deactivateModal');});
E('confirmDeactivate').addEventListener('click',function(){var b=this,f=post('deactivate_member');f.append('user_id',String(userId));b.disabled=true;req(f).then(function(d){toast('success',d.message);window.setTimeout(function(){window.location.href='team.php';},350);}).catch(function(e){toast('error',e.message);b.disabled=false;});});

icons();
load();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
