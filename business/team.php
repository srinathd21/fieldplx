<?php
/** FieldPlx - Manage Team / Crews */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Manage Team · FieldPlx';
$pageDescription = 'Manage your team members, seats and crews';
$settingsActivePage = 'team';
if (session_status() === PHP_SESSION_NONE)
    session_start();
if (empty($_SESSION['team_settings_csrf']))
    $_SESSION['team_settings_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string) $_SESSION['team_settings_csrf'];
require __DIR__ . '/includes/header.php';
if (file_exists(__DIR__ . '/includes/toast.php'))
    require_once __DIR__ . '/includes/toast.php';
?>
<style>
    .team-settings-page {
        display: grid;
        grid-template-columns: 250px minmax(0, 1fr);
        gap: 28px;
        max-width: 1240px;
        margin: 0 auto;
        padding: 0 0 38px;
        align-items: start
    }

    .team-settings-nav-wrap {
        min-width: 0
    }

    .team-main {
        min-width: 0
    }

    .team-heading-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
        margin: 0 0 18px
    }

    .team-heading-copy {
        flex: 1 1 auto;
        min-width: 0
    }

    .team-head-actions {
        flex: 0 0 auto
    }

    .team-heading-copy h1 {
        margin: 0;
        color: var(--text, var(--fieldplx-text, #0b1933));
        font-size: 30px;
        line-height: 1.15;
        font-weight: 700
    }

    .team-heading-copy p {
        margin: 14px 0 0;
        color: var(--muted, var(--fieldplx-muted, #647985));
        font-size: 13px;
        line-height: 1.5;
        max-width: 760px
    }

    .team-head-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: nowrap;
        min-height: 38px;
        white-space: nowrap
    }

    .team-btn,
    .team-seat-pill {
        height: 38px;
        min-height: 38px;
        padding: 0 13px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        font: inherit;
        font-size: 12px;
        font-weight: 700;
        line-height: 1;
        white-space: nowrap;
        box-sizing: border-box;
        vertical-align: middle
    }

    .team-btn i,
    .team-seat-pill i,
    .crew-menu i,
    .crew-add-btn i,
    .crew-drag i,
    .crew-toggle i,
    .crew-more i,
    .crew-member-more i,
    .team-modal-close i,
    .team-page-btn i,
    .session-link i {
        width: 15px;
        height: 15px;
        stroke-width: 2;
        flex: 0 0 auto
    }

    .team-btn {
        border: 1px solid var(--card-border, #dce3eb);
        background: var(--card-bg, #fff);
        color: var(--text, #0b1933);
        text-decoration: none;
        cursor: pointer
    }

    .team-btn:hover {
        border-color: var(--primary, #2d8d24)
    }

    .team-btn-outline {
        color: var(--primary, #2d8d24)
    }

    .team-btn-primary {
        background: var(--primary, #2d8d24);
        border-color: var(--primary, #2d8d24);
        color: var(--primary-text, #fff)
    }

    .team-btn-danger {
        background: #dd4337;
        border-color: #dd4337;
        color: #fff
    }

    .team-seat-pill {
        background: var(--table-header-bg, #f3f1ed);
        border: 1px solid var(--card-border, #ece9e3);
        color: var(--text, #0b1933)
    }

    .team-seat-pill .muted {
        font-weight: 500
    }

    .team-tabs {
        display: flex;
        align-items: center;
        gap: 22px;
        border-bottom: 1px solid var(--card-border, #dfe5eb);
        margin: -2px 0 14px;
        padding-left: 0
    }

    .team-tab {
        height: 40px;
        padding: 0 7px;
        border: 0;
        border-bottom: 3px solid transparent;
        background: transparent;
        color: var(--muted, #536b79);
        font: inherit;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer
    }

    .team-tab.active {
        color: var(--text, #0b1933);
        border-bottom-color: var(--primary, #2d8d24)
    }

    .team-search-wrap {
        position: relative;
        margin: 0 0 20px
    }

    .team-search-icon {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        width: 16px;
        height: 16px;
        color: #68808d;
        pointer-events: none
    }

    .team-search {
        width: 100%;
        height: 42px;
        padding: 0 42px;
        border: 1px solid var(--input-border, var(--card-border, #dce3eb));
        border-radius: 8px;
        outline: none;
        background: var(--input-bg, var(--card-bg, #fff));
        color: var(--text, #0b1933);
        font: inherit;
        font-size: 12px
    }

    .team-search:focus {
        border-color: var(--primary, #2d8d24);
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--primary, #2d8d24) 12%, transparent)
    }

    .team-search-clear {
        position: absolute;
        right: 7px;
        top: 50%;
        transform: translateY(-50%);
        width: 28px;
        height: 28px;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: var(--muted, #617686);
        cursor: pointer;
        display: none;
        align-items: center;
        justify-content: center
    }

    .team-search-clear.show {
        display: flex
    }

    .team-section-label {
        margin: 0 0 10px;
        color: var(--text, #0b1933);
        font-size: 11px;
        font-weight: 700
    }

    .team-card,
    .crew-card,
    .crew-empty {
        border: 1px solid var(--card-border, #dfe5eb);
        border-radius: 10px;
        background: var(--card-bg, #fff);
        overflow: hidden
    }

    .team-table-wrap {
        overflow-x: auto
    }

    .team-table {
        width: 100%;
        min-width: 760px;
        border-collapse: collapse;
        table-layout: fixed
    }

    .team-table th,
    .team-table td {
        padding: 13px 14px;
        border-bottom: 1px solid var(--table-border, #e3e8ed);
        text-align: left;
        vertical-align: middle
    }

    .team-table th {
        color: var(--text, #0b1933);
        font-size: 10px;
        font-weight: 700
    }

    .team-table td {
        color: var(--text, #294858);
        font-size: 11px
    }

    .team-member-link {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        color: var(--primary, #5d971b);
        text-decoration: none;
        font-weight: 600
    }

    .team-avatar {
        width: 29px;
        height: 29px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        flex: 0 0 auto;
        overflow: hidden;
        background: #153e4c;
        color: #fff;
        font-size: 9px;
        font-weight: 700
    }

    .team-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover
    }

    .team-status {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        min-height: 22px;
        padding: 0 9px;
        border-radius: 999px;
        background: #eaf4e6;
        color: #2d7e27;
        font-size: 9px;
        font-weight: 600;
        text-transform: capitalize
    }

    .team-status:before {
        content: "";
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: currentColor
    }

    .team-status.inactive,
    .team-status.suspended,
    .team-status.invited {
        background: #f1f3f5;
        color: #6d7b85
    }

    .session-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: var(--text, #0b1933);
        font-size: 10px;
        font-weight: 700;
        line-height: 1.25;
        text-decoration: none
    }

    .team-card-footer {
        min-height: 49px;
        padding: 8px 13px;
        border-top: 1px solid var(--table-border, #e3e8ed);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px
    }

    .team-page-info,
    .team-pager-label {
        color: var(--muted, #516877);
        font-size: 10px
    }

    .team-pager {
        display: flex;
        align-items: center;
        gap: 8px
    }

    .team-per-page {
        height: 32px;
        min-width: 68px;
        border: 1px solid var(--input-border, #dce3eb);
        border-radius: 8px;
        padding: 0 28px 0 10px;
        background: var(--input-bg, #fff);
        color: var(--text, #264755);
        font-size: 10px
    }

    .team-page-btn {
        width: 32px;
        height: 32px;
        border: 0;
        border-radius: 8px;
        background: var(--table-header-bg, #eff1f3);
        color: var(--muted, #687a86);
        display: grid;
        place-items: center;
        cursor: pointer
    }

    .team-page-btn:disabled {
        opacity: .45;
        cursor: not-allowed
    }

    .team-loading,
    .team-empty {
        padding: 34px 16px;
        text-align: center;
        color: var(--muted, #6f7b90);
        font-size: 11px
    }

    .team-empty strong {
        display: block;
        margin-bottom: 5px;
        color: var(--text, #0b1933);
        font-size: 12px
    }

    .team-spin {
        width: 18px;
        height: 18px;
        margin: 0 auto 8px;
        border: 2px solid #d8e2e8;
        border-top-color: var(--primary, #2d8d24);
        border-radius: 50%;
        animation: teamSpin .75s linear infinite
    }

    @keyframes teamSpin {
        to {
            transform: rotate(360deg)
        }
    }

    #crewsView {
        display: none
    }

    .crew-empty {
        min-height: 140px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 26px
    }

    .crew-empty strong {
        font-size: 16px;
        color: var(--text, #0b1933);
        margin-bottom: 8px
    }

    .crew-empty span {
        color: var(--muted, #647985);
        font-size: 12px
    }

    .crew-list {
        display: grid;
        gap: 14px
    }

    .crew-card {
        overflow: visible
    }

    .crew-head {
        min-height: 56px;
        padding: 0 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid var(--table-border, #e4e9ed)
    }

    .crew-toggle {
        width: 30px;
        height: 30px;
        border: 0;
        background: transparent;
        color: var(--text, #153746);
        display: grid;
        place-items: center;
        cursor: pointer
    }

    .crew-title {
        font-size: 13px;
        font-weight: 800;
        color: var(--text, #0b1933);
        text-transform: uppercase;
        letter-spacing: .01em
    }

    .crew-menu-wrap {
        margin-left: auto;
        position: relative
    }

    .crew-more,
    .crew-member-more {
        width: 34px;
        height: 34px;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: var(--text, #274b5b);
        cursor: pointer;
        font-size: 19px;
        line-height: 1
    }

    .crew-more:hover,
    .crew-member-more:hover {
        background: var(--table-hover-bg, #f5f7f8)
    }

    .crew-menu {
        display: none;
        position: absolute;
        right: 0;
        top: 38px;
        z-index: 30;
        width: 170px;
        padding: 6px;
        border: 1px solid var(--card-border, #dce3eb);
        border-radius: 8px;
        background: var(--card-bg, #fff);
        box-shadow: 0 14px 34px rgba(0, 17, 49, .16)
    }

    .crew-menu.open {
        display: block
    }

    .crew-menu button {
        width: 100%;
        min-height: 40px;
        border: 0;
        border-radius: 6px;
        background: transparent;
        color: var(--text, #294858);
        text-align: left;
        padding: 0 12px;
        display: flex;
        align-items: center;
        gap: 9px;
        font: inherit;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer
    }

    .crew-menu button:hover {
        background: var(--table-hover-bg, #f5f7f8)
    }

    .crew-menu button.danger {
        color: #dd4337
    }

    .crew-body.collapsed {
        display: none
    }

    .crew-empty-members {
        padding: 18px 16px;
        color: var(--muted, #657986);
        font-size: 12px;
        font-style: italic;
        border-bottom: 1px solid var(--table-border, #e4e9ed)
    }

    .crew-members {
        border-bottom: 1px solid var(--table-border, #e4e9ed)
    }

    .crew-member-row {
        min-height: 58px;
        padding: 8px 14px;
        display: grid;
        grid-template-columns: 32px 1fr 40px;
        align-items: center;
        gap: 9px;
        border-bottom: 1px solid var(--table-border, #e8ecef);
        background: var(--card-bg, #fff)
    }

    .crew-member-row:last-child {
        border-bottom: 0
    }

    .crew-member-row.dragging {
        opacity: .45
    }

    .crew-member-row.drag-over {
        box-shadow: inset 0 2px 0 var(--primary, #2d8d24)
    }

    .crew-drag {
        width: 28px;
        height: 32px;
        border: 0;
        background: transparent;
        color: var(--muted, #71838e);
        cursor: grab;
        display: grid;
        place-items: center
    }

    .crew-drag:active {
        cursor: grabbing
    }

    .crew-member-main {
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--text, #294858);
        font-size: 12px
    }

    .crew-member-menu-wrap {
        position: relative;
        justify-self: end
    }

    .crew-member-menu {
        top: 35px;
        width: 184px
    }

    .crew-foot {
        padding: 12px 16px
    }

    .crew-add-btn {
        height: 32px;
        padding: 0 11px;
        border: 1px solid var(--card-border, #dce3eb);
        border-radius: 8px;
        background: var(--card-bg, #fff);
        color: var(--primary, #2d8d24);
        font: inherit;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer
    }

    .crew-add-btn:hover {
        border-color: var(--primary, #2d8d24)
    }

    .team-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 10020;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
        background: rgba(0, 17, 49, .38)
    }

    .team-modal-backdrop.open {
        display: flex
    }

    .team-modal {
        width: min(420px, 100%);
        border: 1px solid var(--card-border, #dce3eb);
        border-radius: 10px;
        background: var(--card-bg, #fff);
        box-shadow: 0 24px 70px rgba(0, 17, 49, .22);
        overflow: hidden
    }

    .team-modal.wide {
        width: min(760px, 100%)
    }

    .team-modal-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 18px
    }

    .team-modal-head h3 {
        margin: 0;
        color: var(--text, #0b1933);
        font-size: 19px
    }

    .team-modal-close {
        width: 30px;
        height: 30px;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: var(--text, #365263);
        cursor: pointer
    }

    .team-modal-body {
        padding: 4px 18px 18px
    }

    .team-modal-foot {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        padding: 0 18px 18px
    }

    .team-field label {
        display: block;
        margin: 0 0 7px;
        color: var(--text, #294858);
        font-size: 12px;
        font-weight: 700
    }

    .team-input {
        width: 100%;
        height: 44px;
        border: 1px solid var(--input-border, #dce3eb);
        border-radius: 8px;
        padding: 0 13px;
        background: var(--input-bg, #fff);
        color: var(--text, #0b1933);
        font: inherit;
        font-size: 12px;
        outline: 0
    }

    .team-input:focus {
        border-color: var(--primary, #2d8d24);
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--primary, #2d8d24) 12%, transparent)
    }

    .team-modal-copy {
        margin: 0;
        color: var(--muted, #536b79);
        font-size: 12px;
        line-height: 1.5
    }

    .crew-candidate-search {
        position: relative;
        margin-bottom: 12px
    }

    .crew-candidate-list {
        max-height: 310px;
        overflow: auto;
        border: 1px solid var(--card-border, #dfe5eb);
        border-radius: 8px
    }

    .crew-candidate {
        min-height: 54px;
        padding: 8px 12px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid var(--table-border, #e7ebee);
        cursor: pointer
    }

    .crew-candidate:last-child {
        border-bottom: 0
    }

    .crew-candidate input {
        width: 18px;
        height: 18px;
        accent-color: var(--primary, #2d8d24)
    }

    .crew-candidate span.name {
        font-size: 12px;
        font-weight: 600;
        color: var(--text, #294858)
    }

    .crew-candidate .team-avatar {
        width: 30px;
        height: 30px
    }

    .team-info-box {
        display: flex;
        gap: 9px;
        padding: 11px;
        border-radius: 8px;
        background: color-mix(in srgb, var(--primary, #2d8d24) 8%, var(--card-bg, #fff));
        color: var(--text, #3f6b87);
        font-size: 10px;
        line-height: 1.45
    }

    .team-seat-box {
        margin-top: 13px;
        padding: 10px 12px;
        border: 1px solid var(--card-border, #dfe5eb);
        border-radius: 8px
    }

    .team-seat-line {
        min-height: 39px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-bottom: 1px solid var(--table-border, #edf0f2);
        color: var(--text, #294858);
        font-size: 10px
    }

    .team-seat-line:last-child {
        border-bottom: 0
    }

    .team-stepper {
        display: inline-flex;
        height: 29px;
        border: 1px solid var(--card-border, #dce3eb);
        border-radius: 7px;
        overflow: hidden
    }

    .team-stepper button,
    .team-stepper span {
        width: 28px;
        display: grid;
        place-items: center;
        border: 0;
        background: var(--card-bg, #fff);
        color: var(--muted, #607683);
        font-size: 11px
    }

    .team-stepper span {
        border-left: 1px solid var(--card-border, #dce3eb);
        border-right: 1px solid var(--card-border, #dce3eb)
    }

    html.app-dark-mode .team-status {
        background: #203a2d;
        color: #78cf6c
    }

    html.app-dark-mode .team-status.inactive,
    html.app-dark-mode .team-status.suspended,
    html.app-dark-mode .team-status.invited {
        background: #25343c;
        color: #a9b6bd
    }

    @media(max-width:980px) {
        .team-settings-page {
            grid-template-columns: 1fr;
            padding: 0 14px 30px
        }

        .team-settings-nav-wrap {
            display: none
        }

        .team-heading-row {
            flex-wrap: wrap
        }

        .team-head-actions {
            margin-left: auto
        }
    }

    @media(max-width:720px) {
        .team-heading-row {
            flex-direction: column
        }

        .team-head-actions {
            width: 100%;
            justify-content: flex-start;
            overflow-x: auto;
            padding-bottom: 2px;
            scrollbar-width: none
        }

        .team-head-actions::-webkit-scrollbar {
            display: none
        }

        .team-heading-copy h1 {
            font-size: 25px
        }

        .team-card-footer {
            align-items: flex-start;
            flex-direction: column
        }

        .team-pager {
            width: 100%;
            justify-content: flex-end
        }

        .team-btn,
        .team-seat-pill {
            flex: 0 0 auto
        }
    }
</style>
<div class="team-settings-page">
    <div class="team-settings-nav-wrap">
        <?php if (file_exists(__DIR__ . '/includes/settings-nav.php'))
            require __DIR__ . '/includes/settings-nav.php'; ?>
    </div>
    <main class="team-main">
        <div class="team-heading-row">
            <div class="team-heading-copy">
                <h1>Manage team</h1>
                <p>Manage your team members and seats in one place. Assign open seats, update team access, or adjust
                    your team size.</p>
            </div>
            <div class="team-head-actions" id="userHeadActions">
                <div class="team-seat-pill"><strong id="seatAssigned">0/0</strong><span
                        class="muted">seats assigned</span></div>
                <a href="invite-team-member.php" class="team-btn team-btn-outline" id="inviteMemberBtn"><span>Invite New Member</span></a>
                <button type="button" class="team-btn team-btn-primary" id="manageSeatsBtn"><span>Manage Seats</span></button>
            </div>
            <div class="team-head-actions" id="crewHeadActions" style="display:none"><button type="button"
                    class="team-btn team-btn-primary" id="addCrewBtn"><span>Add
                        Crew</span></button></div>
        </div>
        <div class="team-tabs"><button type="button" class="team-tab active" data-tab="users">Users</button><button
                type="button" class="team-tab" data-tab="crews">Crews</button></div>
        <section id="usersView">
            <div class="team-search-wrap"><i data-lucide="search" class="team-search-icon"></i><input type="search"
                    class="team-search" id="teamSearch" placeholder="Search team members" autocomplete="off"><button
                    type="button" class="team-search-clear" id="teamSearchClear" aria-label="Clear search"><i
                        data-lucide="x"></i></button></div>
            <div class="team-section-label" id="assignedLabel">Assigned seats (0)</div>
            <section class="team-card">
                <div class="team-table-wrap" id="teamTableWrap">
                    <div class="team-loading">
                        <div class="team-spin"></div>Loading team members...
                    </div>
                </div>
                <div class="team-card-footer">
                    <div class="team-page-info" id="teamPageInfo">Showing 0-0 of 0 items</div>
                    <div class="team-pager"><select class="team-per-page" id="teamPerPage">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                        </select><span class="team-pager-label">per page</span><button type="button"
                            class="team-page-btn" id="prevPage" disabled aria-label="Previous page"><i
                                data-lucide="chevron-left"></i></button><button type="button" class="team-page-btn"
                            id="nextPage" disabled aria-label="Next page"><i data-lucide="chevron-right"></i></button>
                    </div>
                </div>
            </section>
        </section>
        <section id="crewsView">
            <div id="crewList">
                <div class="team-loading">
                    <div class="team-spin"></div>Loading crews...
                </div>
            </div>
        </section>
    </main>
</div>
<div class="team-modal-backdrop" id="manageSeatsModal">
    <div class="team-modal">
        <div class="team-modal-head">
            <h3>Manage seats</h3><button class="team-modal-close" data-close="manageSeatsModal" aria-label="Close"><i
                    data-lucide="x"></i></button>
        </div>
        <div class="team-modal-body">
            <div class="team-info-box" id="seatNotice">Seat changes aren't available for your account right now. Contact
                support if you need to add or remove seats.</div>
            <div class="team-seat-box">
                <div class="team-seat-line"><strong>Included in plan</strong><span id="seatIncluded">0</span></div>
                <div class="team-seat-line"><strong>Paid seats</strong>
                    <div class="team-stepper"><button id="seatMinus" disabled>−</button><span
                            id="seatPaid">0</span><button id="seatPlus" disabled>+</button></div>
                </div>
                <div class="team-seat-line"><strong>Total</strong><strong id="seatTotal">0</strong></div>
            </div>
        </div>
        <div class="team-modal-foot"><button class="team-btn team-btn-primary"
                data-close="manageSeatsModal">Confirm</button></div>
    </div>
</div>
<div class="team-modal-backdrop" id="crewNameModal">
    <div class="team-modal">
        <div class="team-modal-head">
            <h3 id="crewNameTitle">Create a new crew</h3><button class="team-modal-close" data-close="crewNameModal"
                aria-label="Close"><i data-lucide="x"></i></button>
        </div>
        <div class="team-modal-body">
            <div class="team-field"><label for="crewNameInput">Crew name</label><input class="team-input"
                    id="crewNameInput" maxlength="190" placeholder="e.g. West Team"></div>
        </div>
        <div class="team-modal-foot"><button class="team-btn" data-close="crewNameModal">Cancel</button><button
                class="team-btn team-btn-primary" id="saveCrewBtn">Create crew</button></div>
    </div>
</div>
<div class="team-modal-backdrop" id="deleteCrewModal">
    <div class="team-modal">
        <div class="team-modal-head">
            <h3 id="deleteCrewTitle">Delete crew?</h3><button class="team-modal-close" data-close="deleteCrewModal"
                aria-label="Close"><i data-lucide="x"></i></button>
        </div>
        <div class="team-modal-body">
            <p class="team-modal-copy" id="deleteCrewCopy">This crew has no members.</p>
        </div>
        <div class="team-modal-foot"><button class="team-btn" data-close="deleteCrewModal">Cancel</button><button
                class="team-btn team-btn-danger" id="confirmDeleteCrew">Delete crew</button></div>
    </div>
</div>
<div class="team-modal-backdrop" id="addMembersModal">
    <div class="team-modal wide">
        <div class="team-modal-head">
            <h3 id="addMembersTitle">Add teammates</h3><button class="team-modal-close" data-close="addMembersModal"
                aria-label="Close"><i data-lucide="x"></i></button>
        </div>
        <div class="team-modal-body">
            <div class="crew-candidate-search"><input class="team-input" id="crewMemberSearch"
                    placeholder="Search teammates"></div>
            <div class="crew-candidate-list" id="crewCandidateList"></div>
        </div>
        <div class="team-modal-foot"><button class="team-btn" data-close="addMembersModal">Cancel</button><button
                class="team-btn team-btn-primary" id="confirmAddMembers" disabled>Add</button></div>
    </div>
</div>
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
<script>
    (function (window, document) {
        'use strict';
        var csrf = <?= json_encode($csrfToken) ?>, apiUrl = 'api/team.php', state = { tab: 'users', page: 1, perPage: 25, search: '', pages: 1, seats: {}, crews: [], crewId: 0, crewMode: 'create', drag: null }; var searchTimer = null; function E(id) { return document.getElementById(id) } function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;') } function toast(t, m) { if (typeof window.fieldplxToast === 'function') return window.fieldplxToast(t, m); if (t === 'error') alert(m) } function icons() { if (window.lucide && window.lucide.createIcons) window.lucide.createIcons() } function req(fd) { if (!fd.has('csrf_token')) fd.append('csrf_token', csrf); return fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).then(function (r) { return r.text().then(function (x) { var d; try { d = JSON.parse(x) } catch (e) { throw new Error('Invalid response from team API.') } if (!r.ok || !d.success) throw new Error(d.message || 'Unable to complete team request.'); return d }) }) } function initials(r) { return ((String(r.first_name || '').charAt(0) + String(r.last_name || '').charAt(0)).toUpperCase() || 'U') } function memberName(r) { return (String(r.first_name || '') + ' ' + String(r.last_name || '')).trim() || r.email || 'Team member' } function avatar(r) { return r.avatar_path ? '<span class="team-avatar"><img src="' + esc(r.avatar_path) + '" alt=""></span>' : '<span class="team-avatar">' + esc(initials(r)) + '</span>' } function fmt(v) { if (!v) return 'Never'; var d = new Date(String(v).replace(' ', 'T')); return isNaN(d) ? v : d.toLocaleString([], { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }) }
        function renderUsers(rows) { var w = E('teamTableWrap'); if (!rows || !rows.length) { w.innerHTML = '<div class="team-empty"><strong>No team members found</strong>Try another search or invite a new member.</div>'; return } var h = '<table class="team-table"><colgroup><col style="width:27%"><col style="width:20%"><col style="width:22%"><col style="width:15%"><col style="width:16%"></colgroup><thead><tr><th>Name</th><th>Role</th><th>Last Active</th><th>Status</th><th></th></tr></thead><tbody>'; rows.forEach(function (r) { var role = r.role_name || (Number(r.is_tenant_admin || 0) === 1 ? 'Owner' : 'Team member'), st = String(r.status || 'active').toLowerCase(), sessions = Number(r.active_session_count || 0); h += '<tr><td><a class="team-member-link" href="invite-team-member.php?user_id=' + Number(r.id) + '">' + avatar(r) + '<span>' + esc(memberName(r)) + '</span></a></td><td>' + esc(role) + '</td><td>' + esc(fmt(r.last_login_at || r.created_at)) + '</td><td><span class="team-status ' + esc(st) + '">' + esc(st) + '</span></td><td><a class="session-link" href="active-login-sessions.php?user_id=' + Number(r.id) + '"><i data-lucide="monitor"></i><span>Active login sessions' + (sessions ? ' (' + sessions + ')' : '') + '</span></a></td></tr>' }); h += '</tbody></table>'; w.innerHTML = h; icons() }
        function updateSeats(s) { s = s || {}; state.seats = s; E('seatAssigned').textContent = Number(s.assigned || 0) + '/' + Number(s.total || 0); E('seatIncluded').textContent = Number(s.included || 0); E('seatPaid').textContent = Number(s.paid || 0); E('seatTotal').textContent = Number(s.total || 0); E('seatNotice').style.display = s.changes_available ? 'none' : 'flex' } function loadUsers() { E('teamTableWrap').innerHTML = '<div class="team-loading"><div class="team-spin"></div>Loading team members...</div>'; var f = new FormData(); f.append('action', 'list'); f.append('search', state.search); f.append('page', state.page); f.append('per_page', state.perPage); req(f).then(function (d) { var p = d.pagination || {}; state.page = Number(p.page || 1); state.pages = Math.max(1, Number(p.pages || 1)); renderUsers(d.members || []); updateSeats(d.seats || {}); E('assignedLabel').textContent = 'Assigned seats (' + Number((d.seats || {}).assigned || p.total || 0) + ')'; E('teamPageInfo').textContent = 'Showing ' + Number(p.from || 0) + '-' + Number(p.to || 0) + ' of ' + Number(p.total || 0) + ' items'; E('prevPage').disabled = state.page <= 1; E('nextPage').disabled = state.page >= state.pages; E('inviteMemberBtn').style.display = d.can_invite === false ? 'none' : 'inline-flex' }).catch(function (e) { E('teamTableWrap').innerHTML = '<div class="team-empty"><strong>Unable to load team members</strong>' + esc(e.message) + '</div>'; toast('error', e.message) }) }
        function crewById(id) { return state.crews.find(function (c) { return Number(c.id) === Number(id) }) || null } function memberRows(c) { if (!c.members || !c.members.length) return '<div class="crew-empty-members">No teammates yet. Add at least one to see this crew on the schedule.</div>'; return '<div class="crew-members" data-crew-members="' + Number(c.id) + '">' + c.members.map(function (m) { return '<div class="crew-member-row" draggable="true" data-crew-id="' + Number(c.id) + '" data-user-id="' + Number(m.id) + '"><button class="crew-drag" type="button" aria-label="Drag to reorder"><i data-lucide="grip-vertical"></i></button><div class="crew-member-main">' + avatar(m) + '<span>' + esc(memberName(m)) + '</span></div><div class="crew-member-menu-wrap"><button class="crew-member-more" type="button" aria-label="Member options"><i data-lucide="ellipsis"></i></button><div class="crew-menu crew-member-menu"><button type="button" class="danger" data-remove-member="' + Number(m.id) + '"><i data-lucide="trash-2"></i><span>Remove from crew</span></button></div></div></div>' }).join('') + '</div>' } function renderCrews() { var box = E('crewList'); if (!state.crews.length) { box.innerHTML = '<div class="crew-empty"><strong>No crews yet</strong><span>Group teammates into crews so dispatchers can focus on one group at a time.</span></div>'; return } box.innerHTML = '<div class="crew-list">' + state.crews.map(function (c) { return '<section class="crew-card" data-crew="' + Number(c.id) + '"><div class="crew-head"><button type="button" class="crew-toggle" aria-label="Expand or collapse crew"><i data-lucide="chevron-down"></i></button><div class="crew-title">' + esc(c.name) + '</div><div class="crew-menu-wrap"><button type="button" class="crew-more" aria-label="Crew options"><i data-lucide="ellipsis"></i></button><div class="crew-menu"><button type="button" data-rename-crew><i data-lucide="pencil"></i><span>Rename</span></button><button type="button" class="danger" data-delete-crew><i data-lucide="trash-2"></i><span>Delete</span></button></div></div></div><div class="crew-body">' + memberRows(c) + '<div class="crew-foot"><button type="button" class="crew-add-btn" data-add-member><span>Add teammate</span></button></div></div></section>' }).join('') + '</div>'; bindCrewDnD(); icons() }
        function loadCrews() { E('crewList').innerHTML = '<div class="team-loading"><div class="team-spin"></div>Loading crews...</div>'; var f = new FormData(); f.append('action', 'list_crews'); req(f).then(function (d) { state.crews = d.crews || []; renderCrews() }).catch(function (e) { E('crewList').innerHTML = '<div class="team-empty"><strong>Unable to load crews</strong>' + esc(e.message) + '</div>'; toast('error', e.message) }) }
        function openModal(id) { E(id).classList.add('open') } function closeModal(id) { E(id).classList.remove('open') } function openCrewName(mode, id) { state.crewMode = mode; state.crewId = id || 0; var c = crewById(id); E('crewNameTitle').textContent = mode === 'rename' ? 'Rename crew' : 'Create a new crew'; E('crewNameInput').value = c ? c.name : ''; E('saveCrewBtn').textContent = mode === 'rename' ? 'Save' : 'Create crew'; openModal('crewNameModal'); setTimeout(function () { E('crewNameInput').focus(); E('crewNameInput').select() }, 60) } function saveCrew() { var name = E('crewNameInput').value.trim(); if (!name) return toast('warning', 'Enter a crew name.'); var f = new FormData(); f.append('action', state.crewMode === 'rename' ? 'rename_crew' : 'create_crew'); f.append('name', name); if (state.crewId) f.append('crew_id', state.crewId); E('saveCrewBtn').disabled = true; req(f).then(function (d) { closeModal('crewNameModal'); toast('success', d.message); loadCrews() }).catch(function (e) { toast('error', e.message) }).finally(function () { E('saveCrewBtn').disabled = false }) }
        function deleteCrewPrompt(id) { state.crewId = id; var c = crewById(id); E('deleteCrewTitle').textContent = 'Delete ' + (c ? c.name.toUpperCase() : 'CREW') + '?'; E('deleteCrewCopy').textContent = (c && c.members && c.members.length) ? 'This crew has ' + c.members.length + ' member' + (c.members.length === 1 ? '' : 's') + '. Deleting the crew will not delete the teammates.' : 'This crew has no members.'; openModal('deleteCrewModal') } function deleteCrew() { var f = new FormData(); f.append('action', 'delete_crew'); f.append('crew_id', state.crewId); req(f).then(function (d) { closeModal('deleteCrewModal'); toast('success', d.message); loadCrews() }).catch(function (e) { toast('error', e.message) }) }
        function openMembers(id) { state.crewId = id; var c = crewById(id); E('addMembersTitle').textContent = 'Add teammates to ' + (c ? c.name.toUpperCase() : 'CREW'); E('crewMemberSearch').value = ''; openModal('addMembersModal'); loadCandidates('') } function loadCandidates(q) { var f = new FormData(); f.append('action', 'crew_candidates'); f.append('crew_id', state.crewId); f.append('search', q || ''); req(f).then(function (d) { var rows = d.members || []; E('crewCandidateList').innerHTML = rows.length ? rows.map(function (r) { return '<label class="crew-candidate"><input type="checkbox" value="' + Number(r.id) + '">' + avatar(r) + '<span class="name">' + esc(memberName(r)) + '</span></label>' }).join('') : '<div class="team-empty">No available teammates found.</div>'; E('confirmAddMembers').disabled = true }).catch(function (e) { toast('error', e.message) }) } function selectedCandidates() { return Array.prototype.slice.call(E('crewCandidateList').querySelectorAll('input:checked')).map(function (x) { return x.value }) } function addMembers() { var ids = selectedCandidates(); if (!ids.length) return; var f = new FormData(); f.append('action', 'add_crew_members'); f.append('crew_id', state.crewId); ids.forEach(function (id) { f.append('user_ids[]', id) }); req(f).then(function (d) { closeModal('addMembersModal'); toast('success', d.message); loadCrews() }).catch(function (e) { toast('error', e.message) }) } function removeMember(crewId, userId) { var f = new FormData(); f.append('action', 'remove_crew_member'); f.append('crew_id', crewId); f.append('user_id', userId); req(f).then(function (d) { toast('success', d.message); loadCrews() }).catch(function (e) { toast('error', e.message) }) }
        function saveMemberOrder(crewId, container) { var ids = Array.prototype.slice.call(container.querySelectorAll('.crew-member-row')).map(function (r) { return r.dataset.userId }), f = new FormData(); f.append('action', 'reorder_crew_members'); f.append('crew_id', crewId); ids.forEach(function (id) { f.append('user_ids[]', id) }); req(f).then(function (d) { toast('success', d.message) }).catch(function (e) { toast('error', e.message); loadCrews() }) } function bindCrewDnD() { document.querySelectorAll('.crew-member-row').forEach(function (row) { row.addEventListener('dragstart', function (e) { state.drag = this; this.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move' }); row.addEventListener('dragend', function () { this.classList.remove('dragging'); document.querySelectorAll('.drag-over').forEach(function (x) { x.classList.remove('drag-over') }) }); row.addEventListener('dragover', function (e) { if (!state.drag || state.drag.dataset.crewId !== this.dataset.crewId) return; e.preventDefault(); this.classList.add('drag-over'); var box = this.parentNode, r = this.getBoundingClientRect(); if (e.clientY < r.top + r.height / 2) box.insertBefore(state.drag, this); else box.insertBefore(state.drag, this.nextSibling) }); row.addEventListener('dragleave', function () { this.classList.remove('drag-over') }); row.addEventListener('drop', function (e) { e.preventDefault(); this.classList.remove('drag-over'); var box = this.parentNode; saveMemberOrder(this.dataset.crewId, box) }) }) }
        function setTab(tab) { state.tab = tab; document.querySelectorAll('.team-tab').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === tab) }); E('usersView').style.display = tab === 'users' ? 'block' : 'none'; E('crewsView').style.display = tab === 'crews' ? 'block' : 'none'; E('userHeadActions').style.display = tab === 'users' ? 'flex' : 'none'; E('crewHeadActions').style.display = tab === 'crews' ? 'flex' : 'none'; if (tab === 'crews') loadCrews(); else loadUsers(); icons() }
        document.querySelectorAll('.team-tab').forEach(function (b) { b.onclick = function () { setTab(this.dataset.tab) } }); E('addCrewBtn').onclick = function () { openCrewName('create', 0) }; E('saveCrewBtn').onclick = saveCrew; E('crewNameInput').onkeydown = function (e) { if (e.key === 'Enter') { e.preventDefault(); saveCrew() } }; E('confirmDeleteCrew').onclick = deleteCrew; E('confirmAddMembers').onclick = addMembers; E('crewMemberSearch').oninput = function () { var v = this.value; clearTimeout(searchTimer); searchTimer = setTimeout(function () { loadCandidates(v) }, 250) }; E('crewCandidateList').onchange = function () { E('confirmAddMembers').disabled = selectedCandidates().length === 0 }; E('manageSeatsBtn').onclick = function () { openModal('manageSeatsModal') }; document.querySelectorAll('[data-close]').forEach(function (b) { b.onclick = function () { closeModal(this.dataset.close) } }); document.querySelectorAll('.team-modal-backdrop').forEach(function (m) { m.addEventListener('mousedown', function (e) { if (e.target === m) closeModal(m.id) }) }); document.addEventListener('keydown', function (e) { if (e.key === 'Escape') document.querySelectorAll('.team-modal-backdrop.open').forEach(function (m) { closeModal(m.id) }) }); document.addEventListener('click', function (e) { var crew = e.target.closest('[data-crew]'); if (e.target.closest('.crew-more')) { e.stopPropagation(); document.querySelectorAll('.crew-menu.open').forEach(function (x) { if (x !== e.target.closest('.crew-menu-wrap').querySelector('.crew-menu')) x.classList.remove('open') }); e.target.closest('.crew-menu-wrap').querySelector('.crew-menu').classList.toggle('open'); return } if (e.target.closest('.crew-member-more')) { e.stopPropagation(); e.target.closest('.crew-member-menu-wrap').querySelector('.crew-menu').classList.toggle('open'); return } if (e.target.closest('[data-rename-crew]') && crew) { openCrewName('rename', crew.dataset.crew); return } if (e.target.closest('[data-delete-crew]') && crew) { deleteCrewPrompt(crew.dataset.crew); return } if (e.target.closest('[data-add-member]') && crew) { openMembers(crew.dataset.crew); return } var rem = e.target.closest('[data-remove-member]'); if (rem && crew) { removeMember(crew.dataset.crew, rem.dataset.removeMember); return } if (e.target.closest('.crew-toggle') && crew) { crew.querySelector('.crew-body').classList.toggle('collapsed'); return } if (!e.target.closest('.crew-menu-wrap') && !e.target.closest('.crew-member-menu-wrap')) document.querySelectorAll('.crew-menu.open').forEach(function (x) { x.classList.remove('open') }) }); E('teamSearch').oninput = function () { var v = this.value.trim(); E('teamSearchClear').classList.toggle('show', !!v); clearTimeout(searchTimer); searchTimer = setTimeout(function () { state.search = v; state.page = 1; loadUsers() }, 300) }; E('teamSearchClear').onclick = function () { E('teamSearch').value = ''; this.classList.remove('show'); state.search = ''; state.page = 1; loadUsers() }; E('teamPerPage').onchange = function () { state.perPage = Number(this.value || 25); state.page = 1; loadUsers() }; E('prevPage').onclick = function () { if (state.page > 1) { state.page--; loadUsers() } }; E('nextPage').onclick = function () { if (state.page < state.pages) { state.page++; loadUsers() } }; icons(); loadUsers();
    })(window, document);
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>