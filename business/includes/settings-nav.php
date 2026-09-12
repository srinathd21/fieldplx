<?php
if (!isset($settingsActivePage)) {
    $settingsActivePage = '';
}
function fsn_active($key, $current)
{
    return $key === $current ? ' active' : '';
}
?>
<style>
.fieldplx-settings-nav{
    position:sticky;
    top:0;
    height:calc(100vh - 64px);
    max-height:calc(100vh - 64px);
    min-height:360px;
    overflow-y:auto;
    overflow-x:hidden;
    padding:0 8px 10px 0;
    scrollbar-width:thin;
    scrollbar-color:#b7c2cc transparent;
}
.fieldplx-settings-nav::-webkit-scrollbar{width:3px}
.fieldplx-settings-nav::-webkit-scrollbar-track{background:transparent}
.fieldplx-settings-nav::-webkit-scrollbar-thumb{background:#b7c2cc;border-radius:999px}
.fieldplx-settings-nav::-webkit-scrollbar-thumb:hover{background:#93a2b2}
.fieldplx-settings-nav h2{margin:0 0 18px;color:var(--text,#0b1933);font-size:22px;line-height:1.15}
.fieldplx-settings-nav-group{margin:0 0 18px}
.fieldplx-settings-nav-label{margin:0 0 7px;color:var(--text,#0b1933);font-size:10px;font-weight:700;line-height:1.15;text-transform:uppercase;letter-spacing:.02em}
.fieldplx-settings-nav a{display:block;padding:4px 0;color:var(--text,#0b1933);font-size:12px;line-height:1.25;text-decoration:none}
.fieldplx-settings-nav a:hover,.fieldplx-settings-nav a.active{color:var(--primary,#74b824);font-weight:700}
@media(max-width:980px){.fieldplx-settings-nav{display:none}}
</style>
<aside class="fieldplx-settings-nav" aria-label="Settings navigation">
    <h2>Settings</h2>
    <div class="fieldplx-settings-nav-group">
        <div class="fieldplx-settings-nav-label">Business<br>Management</div>
        <a href="settings.php" class="<?= fsn_active('company', $settingsActivePage) ?>">Company Settings</a>
        <a href="business-profile.php" class="<?= fsn_active('business-profile', $settingsActivePage) ?>">Business Profile</a>
        <a href="products-services.php" class="<?= fsn_active('products-services', $settingsActivePage) ?>">Products &amp; Services</a>
        <a href="custom-fields.php" class="<?= fsn_active('custom-fields', $settingsActivePage) ?>">Custom Fields</a>
        <a href="expense-settings.php" class="<?= fsn_active('expense-tracking', $settingsActivePage) ?>">Expense Tracking</a>
        <a href="automations.php" class="<?= fsn_active('automations', $settingsActivePage) ?>">Automations</a>
    </div>
    <div class="fieldplx-settings-nav-group">
        <div class="fieldplx-settings-nav-label">Team<br>Organization</div>
        <a href="team.php" class="<?= fsn_active('team', $settingsActivePage) ?>">Manage Team</a>
        <a href="account-activity.php" class="<?= fsn_active('account-activity', $settingsActivePage) ?>">Account Activity</a>
        <a href="work-settings.php" class="<?= fsn_active('work-settings', $settingsActivePage) ?>">Work Settings</a>
        <a href="schedule-settings.php" class="<?= fsn_active('schedule', $settingsActivePage) ?>">Schedule</a>
        <a href="location-services.php" class="<?= fsn_active('location-services', $settingsActivePage) ?>">Location Services</a>
        <a href="checklists.php" class="<?= fsn_active('checklists', $settingsActivePage) ?>">Checklists</a>
    </div>
    <div class="fieldplx-settings-nav-group">
        <div class="fieldplx-settings-nav-label">Customer<br>Communication</div>
        <a href="client-hub-settings.php" class="<?= fsn_active('client-hub', $settingsActivePage) ?>">Customer Hub</a>
        <a href="email-settings.php" class="<?= fsn_active('emails', $settingsActivePage) ?>">Emails</a>
        <a href="request-booking-settings.php" class="<?= fsn_active('requests-bookings', $settingsActivePage) ?>">Requests and Bookings</a>
    </div>
    <div class="fieldplx-settings-nav-group">
        <div class="fieldplx-settings-nav-label">Connected Apps</div>
        <a href="payment-integrations.php" class="<?= fsn_active('payment-integrations', $settingsActivePage) ?>">Payment Integrations</a>
        <a href="account-connections.php" class="<?= fsn_active('account-connections', $settingsActivePage) ?>">Account Connections</a>
    </div>
</aside>
