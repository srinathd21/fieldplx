<?php
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Email & SMTP';
$activePage = 'platform-smtp';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['email_smtp_csrf'])) {
    $_SESSION['email_smtp_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['email_smtp_csrf'];

function es_h($value)
{
    return htmlspecialchars(
        (string)($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

$configs = $pdo->query("
    SELECT
        id,
        scope_type,
        tenant_id,
        branch_id,
        config_name,
        host,
        port,
        encryption,
        username,
        from_name,
        from_email,
        reply_to_email,
        is_default,
        is_active,
        last_test_status,
        last_test_message,
        last_tested_at,
        created_at,
        updated_at
    FROM smtp_configurations
    WHERE scope_type = 'platform'
    ORDER BY is_default DESC, is_active DESC, id DESC
")->fetchAll();

$defaultConfig = null;
foreach ($configs as $config) {
    if ((int)$config['is_default'] === 1) {
        $defaultConfig = $config;
        break;
    }
}
if (!$defaultConfig && !empty($configs)) {
    $defaultConfig = $configs[0];
}

$summary = array(
    'total' => count($configs),
    'active' => 0,
    'passed' => 0,
    'failed' => 0
);

foreach ($configs as $config) {
    if ((int)$config['is_active'] === 1) {
        $summary['active']++;
    }
    if ($config['last_test_status'] === 'success') {
        $summary['passed']++;
    }
    if ($config['last_test_status'] === 'failed') {
        $summary['failed']++;
    }
}

$emailStatsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status IN ('sent','delivered','read')) AS sent_count,
        SUM(status = 'failed') AS failed_count,
        SUM(status IN ('queued','processing')) AS pending_count
    FROM notification_queue
    WHERE channel = 'email'
");
$emailStats = $emailStatsStmt->fetch();

$recentEmailsStmt = $pdo->query("
    SELECT
        q.id,
        q.recipient_address,
        q.subject,
        q.status,
        q.attempts,
        q.scheduled_at,
        q.sent_at,
        q.error_message,
        q.created_at,
        q.smtp_config_id,
        s.config_name
    FROM notification_queue q
    LEFT JOIN smtp_configurations s
        ON s.id = q.smtp_config_id
    WHERE q.channel = 'email'
    ORDER BY q.id DESC
    LIMIT 10
");
$recentEmails = $recentEmailsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= es_h($pageTitle) ?> - FieldPlx</title>
<?php require_once __DIR__ . '/includes/links.php'; ?>

<style>
:root{
    --fp-primary:#12182d;
    --fp-primary-2:#1c2250;
    --fp-primary-3:#201f6b;
    --fp-accent:#8b5cf6;
    --fp-accent-light:#a78bfa;
    --fp-accent-dark:#6d28d9;
    --fp-text:#20213f;
    --fp-muted:#6f6b8f;
    --fp-border:#ded9ef;
    --fp-danger:#dc2626;
    --fp-sidebar-width:260px;
    --fp-sidebar-collapsed-width:76px;
    --fp-topbar-height:66px;
}
*{box-sizing:border-box}
body{
    margin:0;min-height:100vh;overflow-x:hidden;background:#fff;color:var(--fp-text);
    font-family:"Inter",sans-serif;font-size:13px
}
a{text-decoration:none}
button,input,select,textarea{font-family:inherit}
.fp-layout{min-height:100vh}
.fp-main{min-height:calc(100vh - 52px);margin-left:var(--fp-sidebar-width);transition:margin-left .22s ease}
body.fp-sidebar-collapsed .fp-main{margin-left:var(--fp-sidebar-collapsed-width)}
.fp-topbar{
    position:sticky;top:0;z-index:1030;min-height:var(--fp-topbar-height);
    border-bottom:1px solid #ded8f3;background:rgba(248,246,255,.96);backdrop-filter:blur(14px)
}
.fp-topbar-inner{min-height:var(--fp-topbar-height);padding:8px 18px;display:flex;align-items:center;gap:13px}
.fp-menu-toggle,.fp-icon-button{
    width:39px;height:39px;min-width:39px;padding:0;display:inline-flex;align-items:center;justify-content:center;
    border:1px solid #d9d2ef;border-radius:10px;background:#fff;color:#39345f;font-size:18px
}
.fp-menu-toggle:hover,.fp-icon-button:hover{border-color:#bda9ff;background:#f4f0ff;color:var(--fp-accent-dark)}
.fp-page-heading{min-width:0;margin-right:auto}
.fp-page-title{margin:0;color:#17172e;font-size:15px;font-weight:700;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fp-page-subtitle{margin-top:2px;color:var(--fp-muted);font-size:10px}
.fp-search{width:min(340px,31vw);position:relative;flex:0 1 340px}
.fp-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8f88aa;font-size:14px;pointer-events:none}
.fp-search input{width:100%;height:39px;padding:8px 13px 8px 36px;border:1px solid #dcd5ef;border-radius:10px;outline:0;background:#f8f6ff;font-size:12px}
.fp-search input:focus{border-color:#a78bfa;background:#fff;box-shadow:0 0 0 3px rgba(139,92,246,.12)}
.fp-notification-wrap{position:relative}
.fp-notification-count{
    position:absolute;top:-5px;right:-5px;z-index:3;min-width:18px;height:18px;padding:0 5px;
    display:inline-flex;align-items:center;justify-content:center;border:2px solid #fff;border-radius:999px;
    background:var(--fp-danger);color:#fff;font-size:9px;font-weight:700
}
.fp-profile{min-width:0;padding:4px 9px 4px 5px;display:flex;align-items:center;gap:9px;border:1px solid var(--fp-border);border-radius:11px;background:#fff}
.fp-avatar{width:32px;height:32px;flex:0 0 32px;display:inline-flex;align-items:center;justify-content:center;border-radius:9px;background:linear-gradient(135deg,#6d4df4,#9a5cff);color:#fff;font-size:10px;font-weight:700}
.fp-profile-text{max-width:145px;min-width:0}
.fp-profile-name,.fp-profile-role{display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.fp-profile-name{color:#111827;font-size:11px;font-weight:700}
.fp-profile-role{margin-top:1px;color:var(--fp-muted);font-size:9px}
.fp-mobile-brand{display:none}
.fp-content{padding:18px;background:#fff}

.mail-page{display:grid;gap:16px}
.mail-header{display:flex;align-items:flex-start;justify-content:space-between;gap:15px}
.mail-title{margin:0;color:#111827;font-size:20px;font-weight:800}
.mail-description{margin-top:4px;max-width:780px;color:#77718e;font-size:10px;line-height:1.55}
.mail-header-actions{display:flex;align-items:center;gap:8px}
.mail-primary,.mail-secondary{
    min-height:38px;padding:8px 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;
    border-radius:9px;font-size:10px;font-weight:700;cursor:pointer
}
.mail-primary{border:0;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;box-shadow:0 8px 20px rgba(109,40,217,.18)}
.mail-secondary{border:1px solid #dcd5ef;background:#fff;color:#5f5870}
.mail-primary:disabled,.mail-secondary:disabled{opacity:.65;cursor:not-allowed}

.mail-stats{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px
}

.mail-stat{
    min-height:90px;
    padding:14px 15px;
    display:flex;
    align-items:center;
    gap:11px;
    border:1px solid #ddd5f1;
    border-radius:13px;
    background:
        linear-gradient(
            180deg,
            #ffffff 0%,
            #fbf9ff 100%
        );
    box-shadow:none
}

.mail-stat:hover{
    border-color:#cfc3ef;
    background:
        linear-gradient(
            180deg,
            #ffffff 0%,
            #f8f4ff 100%
        )
}

.mail-stat-icon{
    width:38px;
    height:38px;
    flex:0 0 38px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:10px;
    background:#eee8ff;
    color:#7c3aed;
    font-size:16px
}

.mail-stat-content{
    min-width:0;
    display:block
}

.mail-stat-label{
    display:block;
    color:#9a94ae;
    font-size:8px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.04em;
    line-height:1.3
}

.mail-stat-value{
    margin-top:2px;
    display:block;
    color:#111827;
    font-size:20px;
    font-weight:800;
    line-height:1.2
}

.mail-stat-note{
    margin-top:2px;
    display:block;
    color:#9d96ac;
    font-size:7.5px;
    line-height:1.35
}
.mail-layout{display:grid;grid-template-columns:minmax(0,1fr) 310px;gap:16px;align-items:start}
.mail-column{display:grid;gap:16px}
.mail-card{overflow:hidden;border:1px solid #ded7ef;border-radius:14px;background:#fff;box-shadow:0 8px 24px rgba(37,29,80,.05)}
.mail-card-header{min-height:54px;padding:12px 15px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid #ece7f7;background:#fbf9ff}
.mail-card-title-wrap{display:flex;align-items:center;gap:10px}
.mail-card-icon{width:34px;height:34px;flex:0 0 34px;display:inline-flex;align-items:center;justify-content:center;border-radius:9px;background:#eee8ff;color:#7c3aed;font-size:14px}
.mail-card-title{margin:0;color:#111827;font-size:12px;font-weight:800}
.mail-card-subtitle{margin-top:2px;color:#9a94aa;font-size:8px}
.mail-card-body{padding:15px}

.mail-config-list{display:grid;gap:9px}
.mail-config{
    padding:11px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;
    border:1px solid #e2dcf2;border-radius:11px;background:#fff
}
.mail-config:hover{border-color:#c8b9f4;background:#fdfcff}
.mail-config-name{display:flex;align-items:center;gap:7px;color:#302a40;font-size:10px;font-weight:800}
.mail-config-meta{margin-top:5px;display:flex;flex-wrap:wrap;gap:6px 12px;color:#91899f;font-size:8px}
.mail-config-actions{display:flex;align-items:center;gap:5px}
.mail-icon-btn{
    width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;
    border:1px solid #ddd6ec;border-radius:8px;background:#fff;color:#655d78;font-size:12px;cursor:pointer
}
.mail-icon-btn:hover{border-color:#bda9ff;background:#f7f3ff;color:#6d28d9}
.mail-icon-btn.danger:hover{border-color:#fecaca;background:#fef2f2;color:#dc2626}

.mail-badge{display:inline-flex;align-items:center;padding:4px 7px;border-radius:999px;font-size:8px;font-weight:700}
.mail-badge.active,.mail-badge.success,.mail-badge.sent,.mail-badge.delivered,.mail-badge.read{background:#ecfdf5;color:#047857}
.mail-badge.inactive,.mail-badge.not_tested{background:#f3f4f6;color:#6b7280}
.mail-badge.failed{background:#fef2f2;color:#b91c1c}
.mail-badge.queued,.mail-badge.processing{background:#fff7ed;color:#c2410c}
.mail-badge.default{background:#f1ecff;color:#6d28d9}

.mail-default-box{padding:13px;border:1px solid #e3daf8;border-radius:11px;background:#f8f5ff}
.mail-default-row{padding:8px 0;display:flex;justify-content:space-between;gap:12px;border-bottom:1px dashed #ddd6ef;font-size:9px}
.mail-default-row:first-child{padding-top:0}
.mail-default-row:last-child{padding-bottom:0;border-bottom:0}
.mail-default-row span{color:#8d859e}
.mail-default-row strong{max-width:175px;color:#302a40;text-align:right;overflow-wrap:anywhere}

.mail-table-wrap{overflow:auto}
.mail-table{width:100%;min-width:850px;border-collapse:collapse}
.mail-table th{padding:10px 12px;border-bottom:1px solid #e8e2f2;background:#f8f6ff;color:#726a86;text-align:left;font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.mail-table td{padding:10px 12px;border-bottom:1px solid #f0ecf7;color:#433d54;font-size:9px;vertical-align:middle}
.mail-table tbody tr:hover{background:#fcfbff}
.mail-subject{max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mail-empty{padding:36px 15px;text-align:center;color:#928aa5;font-size:10px}

.mail-modal-backdrop{
    position:fixed;inset:0;z-index:15000;display:none;align-items:center;justify-content:center;padding:18px;
    background:rgba(18,24,45,.42);backdrop-filter:blur(3px)
}
.mail-modal-backdrop.show{display:flex}
.mail-modal{width:min(700px,100%);max-height:calc(100vh - 36px);overflow:auto;border:1px solid #ded7ef;border-radius:15px;background:#fff;box-shadow:0 24px 60px rgba(28,20,70,.22)}
.mail-modal.small{width:min(500px,100%)}
.mail-modal-header{padding:13px 15px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-bottom:1px solid #ece7f7;background:#fbf9ff}
.mail-modal-title-wrap{display:flex;align-items:center;gap:10px}
.mail-modal-title{margin:0;color:#111827;font-size:12px;font-weight:800}
.mail-modal-subtitle{margin-top:2px;color:#9a94aa;font-size:8px}
.mail-modal-close{width:30px;height:30px;border:1px solid #ddd6ec;border-radius:8px;background:#fff;color:#6d657d;cursor:pointer}
.mail-modal-body{padding:15px}
.mail-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
.mail-field.full{grid-column:1/-1}
.mail-field label{margin-bottom:6px;display:block;color:#4c465f;font-size:9px;font-weight:700}
.mail-required{color:#dc2626}
.mail-input,.mail-select{
    width:100%;height:39px;padding:8px 11px;border:1px solid #dcd5ef;border-radius:9px;outline:0;background:#fff;color:#312b47;font-size:10px
}
.mail-input:focus,.mail-select:focus{border-color:#a78bfa;box-shadow:0 0 0 3px rgba(139,92,246,.10)}
.mail-note{margin-top:5px;color:#9a94aa;font-size:8px;line-height:1.45}
.mail-toggle{min-height:48px;padding:9px 10px;display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid #e2dcf2;border-radius:10px;background:#fbf9ff}
.mail-toggle strong{display:block;color:#393248;font-size:9px}
.mail-toggle span span{margin-top:2px;display:block;color:#9a94aa;font-size:8px}
.mail-modal-footer{padding:12px 15px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #ece7f7;background:#fbf9ff}

.mail-loader{width:14px;height:14px;display:none;border:2px dotted rgba(255,255,255,.95);border-radius:50%;animation:mailSpin .75s linear infinite}
.mail-primary.loading .mail-loader{display:inline-block}
@keyframes mailSpin{to{transform:rotate(360deg)}}

.mail-toast{
    position:fixed;top:82px;right:20px;z-index:20000;width:min(380px,calc(100vw - 24px));padding:12px 14px;
    display:flex;align-items:center;gap:10px;border:0;border-radius:11px;color:#fff;box-shadow:0 16px 34px rgba(16,24,40,.18);
    opacity:0;visibility:hidden;transform:translateY(-10px);transition:opacity .2s ease,transform .2s ease,visibility .2s ease;
    font-size:10px;line-height:1.45
}
.mail-toast.show{opacity:1;visibility:visible;transform:translateY(0)}
.mail-toast.success{background:#059669}
.mail-toast.error{background:#dc2626}
.mail-toast.warning{background:#d97706}
.mail-toast.info{background:#4f46e5}
.mail-toast-icon{width:24px;height:24px;flex:0 0 24px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:rgba(255,255,255,.18);font-size:12px}
.mail-toast-message{flex:1;min-width:0;font-weight:600}
.mail-toast-close{width:24px;height:24px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:7px;background:transparent;color:#fff;font-size:15px;cursor:pointer;opacity:.82}
.mail-toast-close:hover{background:rgba(255,255,255,.12);opacity:1}

@media(max-width:1100px){
    .mail-layout{grid-template-columns:1fr}
    .mail-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:991.98px){
    .fp-main,body.fp-sidebar-collapsed .fp-main{margin-left:0}
    .fp-search,.fp-profile-text{display:none}
    .fp-mobile-brand{display:inline-flex}
}
@media(max-width:700px){
    .mail-header{flex-direction:column}
    .mail-header-actions{width:100%}
    .mail-header-actions .mail-primary,.mail-header-actions .mail-secondary{flex:1}
    .mail-form-grid{grid-template-columns:1fr}
    .mail-field.full{grid-column:auto}
}
@media(max-width:575.98px){
    .fp-topbar-inner{padding:8px 11px}
    .fp-page-subtitle{display:none}
    .fp-page-title{font-size:13px}
    .fp-content{padding:12px}
    .mail-stats{grid-template-columns:1fr}
    .mail-stat{min-height:82px}
    .mail-config{grid-template-columns:1fr}
    .mail-config-actions{justify-content:flex-end}
    .mail-modal-footer{flex-direction:column-reverse}
    .mail-modal-footer .mail-primary,.mail-modal-footer .mail-secondary{width:100%}
    .mail-toast{top:74px;right:12px;left:12px;width:auto}
}
</style>
</head>

<body>
<div class="fp-layout">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="fp-main">
<?php require_once __DIR__ . '/includes/topbar.php'; ?>

<div class="fp-content">
<div class="mail-page">

<div class="mail-header">
    <div>
        <h2 class="mail-title">Email & SMTP</h2>
        <div class="mail-description">
            Configure FieldPlx platform email delivery, sender identity and SMTP providers,
            then review recent email queue activity.
        </div>
    </div>

    <div class="mail-header-actions">
        <button type="button" class="mail-secondary" id="testEmailBtn">
            <i class="bi bi-send-check"></i>
            Test Email
        </button>

        <button type="button" class="mail-primary" id="addSmtpBtn">
            <i class="bi bi-plus-lg"></i>
            Add SMTP
        </button>
    </div>
</div>

<div class="mail-stats">

<div class="mail-stat">
    <span class="mail-stat-icon">
        <i class="bi bi-hdd-network"></i>
    </span>

    <span class="mail-stat-content">
        <span class="mail-stat-label">
            SMTP Configurations
        </span>

        <span class="mail-stat-value">
            <?= number_format((int)$summary['total']) ?>
        </span>

        <span class="mail-stat-note">
            <?= number_format((int)$summary['active']) ?> active configuration(s)
        </span>
    </span>
</div>

<div class="mail-stat">
    <span class="mail-stat-icon">
        <i class="bi bi-envelope-check"></i>
    </span>

    <span class="mail-stat-content">
        <span class="mail-stat-label">
            Emails Sent
        </span>

        <span class="mail-stat-value">
            <?= number_format((int)($emailStats['sent_count'] ?? 0)) ?>
        </span>

        <span class="mail-stat-note">
            Sent, delivered or read
        </span>
    </span>
</div>

<div class="mail-stat">
    <span class="mail-stat-icon">
        <i class="bi bi-hourglass-split"></i>
    </span>

    <span class="mail-stat-content">
        <span class="mail-stat-label">
            Pending Queue
        </span>

        <span class="mail-stat-value">
            <?= number_format((int)($emailStats['pending_count'] ?? 0)) ?>
        </span>

        <span class="mail-stat-note">
            Queued or processing
        </span>
    </span>
</div>

<div class="mail-stat">
    <span class="mail-stat-icon">
        <i class="bi bi-envelope-x"></i>
    </span>

    <span class="mail-stat-content">
        <span class="mail-stat-label">
            Failed Emails
        </span>

        <span class="mail-stat-value">
            <?= number_format((int)($emailStats['failed_count'] ?? 0)) ?>
        </span>

        <span class="mail-stat-note">
            Needs review or retry
        </span>
    </span>
</div>

</div>

<div class="mail-layout">

<div class="mail-column">

<section class="mail-card">
    <div class="mail-card-header">
        <div class="mail-card-title-wrap">
            <span class="mail-card-icon"><i class="bi bi-hdd-network"></i></span>
            <span>
                <h3 class="mail-card-title">SMTP Configurations</h3>
                <span class="mail-card-subtitle">Platform-level email delivery providers</span>
            </span>
        </div>
    </div>

    <div class="mail-card-body">
        <div class="mail-config-list">

        <?php if (!$configs): ?>
            <div class="mail-empty">
                No platform SMTP configuration found. Click Add SMTP to create one.
            </div>
        <?php else: ?>

            <?php foreach ($configs as $config): ?>
            <div class="mail-config">
                <div>
                    <div class="mail-config-name">
                        <?= es_h($config['config_name']) ?>

                        <?php if ((int)$config['is_default'] === 1): ?>
                            <span class="mail-badge default">Default</span>
                        <?php endif; ?>

                        <span class="mail-badge <?= (int)$config['is_active'] === 1 ? 'active' : 'inactive' ?>">
                            <?= (int)$config['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                        </span>

                        <span class="mail-badge <?= es_h($config['last_test_status']) ?>">
                            <?php
                            if ($config['last_test_status'] === 'success') {
                                echo 'Test Passed';
                            } elseif ($config['last_test_status'] === 'failed') {
                                echo 'Test Failed';
                            } else {
                                echo 'Not Tested';
                            }
                            ?>
                        </span>
                    </div>

                    <div class="mail-config-meta">
                        <span><i class="bi bi-server"></i> <?= es_h($config['host']) ?>:<?= (int)$config['port'] ?></span>
                        <span><i class="bi bi-shield-lock"></i> <?= strtoupper(es_h($config['encryption'])) ?></span>
                        <span><i class="bi bi-envelope"></i> <?= es_h($config['from_email']) ?: '-' ?></span>
                        <span><i class="bi bi-clock"></i>
                            <?= $config['last_tested_at'] ? es_h(date('d M Y, h:i A', strtotime($config['last_tested_at']))) : 'Never tested' ?>
                        </span>
                    </div>
                </div>

                <div class="mail-config-actions">
                    <button
                        type="button"
                        class="mail-icon-btn smtp-test-one"
                        data-id="<?= (int)$config['id'] ?>"
                        title="Test connection"
                    >
                        <i class="bi bi-lightning-charge"></i>
                    </button>

                    <button
                        type="button"
                        class="mail-icon-btn smtp-edit"
                        data-row='<?= es_h(json_encode($config, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'
                        title="Edit"
                    >
                        <i class="bi bi-pencil"></i>
                    </button>

                    <button
                        type="button"
                        class="mail-icon-btn smtp-status"
                        data-id="<?= (int)$config['id'] ?>"
                        data-status="<?= (int)$config['is_active'] ?>"
                        title="<?= (int)$config['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>"
                    >
                        <i class="bi <?= (int)$config['is_active'] === 1 ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                    </button>

                    <button
                        type="button"
                        class="mail-icon-btn danger smtp-delete"
                        data-id="<?= (int)$config['id'] ?>"
                        data-name="<?= es_h($config['config_name']) ?>"
                        title="Delete"
                    >
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>

        <?php endif; ?>

        </div>
    </div>
</section>

<section class="mail-card">
    <div class="mail-card-header">
        <div class="mail-card-title-wrap">
            <span class="mail-card-icon"><i class="bi bi-envelope-paper"></i></span>
            <span>
                <h3 class="mail-card-title">Recent Email Delivery</h3>
                <span class="mail-card-subtitle">Latest email records from the notification queue</span>
            </span>
        </div>
    </div>

    <div class="mail-table-wrap">
        <table class="mail-table">
            <thead>
            <tr>
                <th>S/No</th>
                <th>Recipient</th>
                <th>Subject</th>
                <th>SMTP</th>
                <th>Attempts</th>
                <th>Status</th>
                <th>Sent At</th>
            </tr>
            </thead>

            <tbody>
            <?php if (!$recentEmails): ?>
                <tr>
                    <td colspan="7">
                        <div class="mail-empty">No email delivery records found.</div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($recentEmails as $index => $email): ?>
                <tr title="<?= es_h($email['error_message']) ?>">
                    <td><?= $index + 1 ?></td>
                    <td><?= es_h($email['recipient_address']) ?: '-' ?></td>
                    <td>
                        <div class="mail-subject">
                            <?= es_h($email['subject']) ?: '(No subject)' ?>
                        </div>
                    </td>
                    <td><?= es_h($email['config_name']) ?: '-' ?></td>
                    <td><?= (int)$email['attempts'] ?></td>
                    <td>
                        <span class="mail-badge <?= es_h($email['status']) ?>">
                            <?= es_h(ucwords(str_replace('_', ' ', $email['status']))) ?>
                        </span>
                    </td>
                    <td>
                        <?= $email['sent_at']
                            ? es_h(date('d M Y, h:i A', strtotime($email['sent_at'])))
                            : '-' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

</div>

<aside class="mail-column">

<section class="mail-card">
    <div class="mail-card-header">
        <div class="mail-card-title-wrap">
            <span class="mail-card-icon"><i class="bi bi-star"></i></span>
            <span>
                <h3 class="mail-card-title">Default Email Provider</h3>
                <span class="mail-card-subtitle">Current platform sender configuration</span>
            </span>
        </div>
    </div>

    <div class="mail-card-body">
        <div class="mail-default-box">
            <div class="mail-default-row">
                <span>Configuration</span>
                <strong><?= $defaultConfig ? es_h($defaultConfig['config_name']) : '-' ?></strong>
            </div>

            <div class="mail-default-row">
                <span>SMTP Host</span>
                <strong><?= $defaultConfig ? es_h($defaultConfig['host']) . ':' . (int)$defaultConfig['port'] : '-' ?></strong>
            </div>

            <div class="mail-default-row">
                <span>From Name</span>
                <strong><?= $defaultConfig ? es_h($defaultConfig['from_name']) : '-' ?></strong>
            </div>

            <div class="mail-default-row">
                <span>From Email</span>
                <strong><?= $defaultConfig ? es_h($defaultConfig['from_email']) : '-' ?></strong>
            </div>

            <div class="mail-default-row">
                <span>Encryption</span>
                <strong><?= $defaultConfig ? strtoupper(es_h($defaultConfig['encryption'])) : '-' ?></strong>
            </div>

            <div class="mail-default-row">
                <span>Status</span>
                <strong>
                    <?php if ($defaultConfig): ?>
                    <span class="mail-badge <?= (int)$defaultConfig['is_active'] === 1 ? 'active' : 'inactive' ?>">
                        <?= (int)$defaultConfig['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                    </span>
                    <?php else: ?>
                    -
                    <?php endif; ?>
                </strong>
            </div>
        </div>
    </div>
</section>

<section class="mail-card">
    <div class="mail-card-header">
        <div class="mail-card-title-wrap">
            <span class="mail-card-icon"><i class="bi bi-info-circle"></i></span>
            <span>
                <h3 class="mail-card-title">Email Delivery Notes</h3>
                <span class="mail-card-subtitle">Platform-wide configuration scope</span>
            </span>
        </div>
    </div>

    <div class="mail-card-body">
        <div class="mail-note" style="font-size:9px;line-height:1.75;margin:0">
            Platform SMTP configurations are global providers. Tenant or branch SMTP configurations
            can override them where your notification engine selects a more specific configuration.
            Passwords are never displayed back in the form.
        </div>
    </div>
</section>

</aside>
</div>

</div>
</div>
</main>
</div>

<div id="mailToast" class="mail-toast" role="status" aria-live="polite" aria-atomic="true">
    <span class="mail-toast-icon"><i id="mailToastIcon" class="bi bi-check-lg"></i></span>
    <span id="mailToastMessage" class="mail-toast-message">Saved successfully.</span>
    <button type="button" id="mailToastClose" class="mail-toast-close" aria-label="Close">
        <i class="bi bi-x-lg"></i>
    </button>
</div>

<!-- SMTP ADD/EDIT MODAL -->
<div class="mail-modal-backdrop" id="smtpModal">
<div class="mail-modal">

<form id="smtpForm" novalidate>
<input type="hidden" name="csrf_token" value="<?= es_h($csrfToken) ?>">
<input type="hidden" name="action" value="save_smtp">
<input type="hidden" name="id" id="smtpId">

<div class="mail-modal-header">
    <div class="mail-modal-title-wrap">
        <span class="mail-card-icon"><i class="bi bi-hdd-network"></i></span>
        <span>
            <h3 class="mail-modal-title" id="smtpModalTitle">Add SMTP Configuration</h3>
            <span class="mail-modal-subtitle">Platform email server and sender identity</span>
        </span>
    </div>

    <button type="button" class="mail-modal-close" id="smtpModalClose">
        <i class="bi bi-x-lg"></i>
    </button>
</div>

<div class="mail-modal-body">
<div class="mail-form-grid">

<div class="mail-field full">
    <label>Configuration Name <span class="mail-required">*</span></label>
    <input class="mail-input" name="config_name" id="smtpConfigName" maxlength="190" required placeholder="Primary Platform SMTP">
</div>

<div class="mail-field">
    <label>SMTP Host <span class="mail-required">*</span></label>
    <input class="mail-input" name="host" id="smtpHost" maxlength="190" required placeholder="smtp.example.com">
</div>

<div class="mail-field">
    <label>Port <span class="mail-required">*</span></label>
    <input class="mail-input" type="number" name="port" id="smtpPort" min="1" max="65535" value="587" required>
</div>

<div class="mail-field">
    <label>Encryption <span class="mail-required">*</span></label>
    <select class="mail-select" name="encryption" id="smtpEncryption" required>
        <option value="tls">TLS</option>
        <option value="starttls">STARTTLS</option>
        <option value="ssl">SSL</option>
        <option value="none">None</option>
    </select>
</div>

<div class="mail-field">
    <label>SMTP Username</label>
    <input class="mail-input" name="username" id="smtpUsername" maxlength="190" autocomplete="off">
</div>

<div class="mail-field">
    <label>SMTP Password</label>
    <input class="mail-input" type="password" name="password" id="smtpPassword" maxlength="500" autocomplete="new-password" placeholder="Leave blank to keep existing password">
    <div class="mail-note">Password is encrypted before database storage.</div>
</div>

<div class="mail-field">
    <label>From Name</label>
    <input class="mail-input" name="from_name" id="smtpFromName" maxlength="190" placeholder="FieldPlx">
</div>

<div class="mail-field">
    <label>From Email <span class="mail-required">*</span></label>
    <input class="mail-input" type="email" name="from_email" id="smtpFromEmail" maxlength="190" required placeholder="notifications@example.com">
</div>

<div class="mail-field">
    <label>Reply-To Email</label>
    <input class="mail-input" type="email" name="reply_to_email" id="smtpReplyTo" maxlength="190">
</div>

<div class="mail-field">
    <label>Status</label>
    <select class="mail-select" name="is_active" id="smtpStatus">
        <option value="1">Active</option>
        <option value="0">Inactive</option>
    </select>
</div>

<div class="mail-field full">
    <label class="mail-toggle">
        <span>
            <strong>Default Platform SMTP</strong>
            <span>Use this as the default platform-level email provider.</span>
        </span>
        <span class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" name="is_default" id="smtpDefault" value="1">
        </span>
    </label>
</div>

</div>
</div>

<div class="mail-modal-footer">
    <button type="button" class="mail-secondary" id="smtpCancel">Cancel</button>

    <button type="submit" class="mail-primary" id="smtpSaveBtn">
        <span class="mail-loader"></span>
        <i class="bi bi-check2-circle"></i>
        <span id="smtpSaveText">Save SMTP</span>
    </button>
</div>

</form>
</div>
</div>

<!-- TEST EMAIL MODAL -->
<div class="mail-modal-backdrop" id="testModal">
<div class="mail-modal small">

<form id="testForm" novalidate>
<input type="hidden" name="csrf_token" value="<?= es_h($csrfToken) ?>">
<input type="hidden" name="action" value="send_test_email">

<div class="mail-modal-header">
    <div class="mail-modal-title-wrap">
        <span class="mail-card-icon"><i class="bi bi-send-check"></i></span>
        <span>
            <h3 class="mail-modal-title">Send Test Email</h3>
            <span class="mail-modal-subtitle">Verify SMTP authentication and delivery</span>
        </span>
    </div>

    <button type="button" class="mail-modal-close" id="testModalClose">
        <i class="bi bi-x-lg"></i>
    </button>
</div>

<div class="mail-modal-body">
<div class="mail-form-grid">

<div class="mail-field full">
    <label>SMTP Configuration <span class="mail-required">*</span></label>
    <select class="mail-select" name="smtp_config_id" id="testSmtpConfig" required>
        <option value="">Select SMTP configuration</option>
        <?php foreach ($configs as $config): ?>
            <option value="<?= (int)$config['id'] ?>" <?= $defaultConfig && (int)$defaultConfig['id'] === (int)$config['id'] ? 'selected' : '' ?>>
                <?= es_h($config['config_name']) ?> - <?= es_h($config['host']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="mail-field full">
    <label>Recipient Email <span class="mail-required">*</span></label>
    <input class="mail-input" type="email" name="recipient_email" id="testRecipient" required placeholder="you@example.com">
</div>

</div>
</div>

<div class="mail-modal-footer">
    <button type="button" class="mail-secondary" id="testCancel">Cancel</button>

    <button type="submit" class="mail-primary" id="testSendBtn">
        <span class="mail-loader"></span>
        <i class="bi bi-send"></i>
        <span id="testSendText">Send Test</span>
    </button>
</div>

</form>
</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
'use strict';

var body=document.body;
var toggle=document.getElementById('fpSidebarToggle');
var closeBtn=document.getElementById('fpSidebarClose');
var overlay=document.getElementById('fpSidebarOverlay');
var storageKey='fieldplx_sidebar_collapsed';

function restoreSidebar(){
    if(window.innerWidth<992){
        body.classList.remove('fp-sidebar-collapsed');
        return;
    }
    if(localStorage.getItem(storageKey)==='1'){
        body.classList.add('fp-sidebar-collapsed');
    }else{
        body.classList.remove('fp-sidebar-collapsed');
    }
}
restoreSidebar();

if(toggle){
    toggle.addEventListener('click',function(){
        if(window.innerWidth<992){
            body.classList.toggle('fp-sidebar-mobile-open');
            return;
        }
        body.classList.toggle('fp-sidebar-collapsed');
        localStorage.setItem(storageKey,body.classList.contains('fp-sidebar-collapsed')?'1':'0');
    });
}
if(closeBtn){closeBtn.addEventListener('click',function(){body.classList.remove('fp-sidebar-mobile-open')});}
if(overlay){overlay.addEventListener('click',function(){body.classList.remove('fp-sidebar-mobile-open')});}
document.querySelectorAll('.fp-sidebar-menu-toggle').forEach(function(btn){
    btn.addEventListener('click',function(){
        var menu=btn.closest('.fp-sidebar-menu');
        if(menu){menu.classList.toggle('open');}
    });
});

var toast=document.getElementById('mailToast');
var toastMessage=document.getElementById('mailToastMessage');
var toastIcon=document.getElementById('mailToastIcon');
var toastClose=document.getElementById('mailToastClose');
var toastTimer=null;

function showToast(type,message,duration){
    if(!toast){return;}
    if(toastTimer){clearTimeout(toastTimer);}
    var icons={success:'bi-check-lg',error:'bi-x-lg',warning:'bi-exclamation-lg',info:'bi-info-lg'};
    var t=type||'info';
    toast.className='mail-toast '+t;
    toastMessage.textContent=message||'Notification';
    toastIcon.className='bi '+(icons[t]||icons.info);
    toast.classList.add('show');
    toastTimer=setTimeout(function(){
        toast.classList.remove('show');
        toastTimer=null;
    },typeof duration==='number'?duration:3000);
}

if(toastClose){
    toastClose.addEventListener('click',function(){
        if(toastTimer){clearTimeout(toastTimer);}
        toast.classList.remove('show');
    });
}

function apiRequest(formData){
    return fetch('api/email-smtp.php',{
        method:'POST',
        body:formData,
        credentials:'same-origin',
        headers:{
            'X-Requested-With':'XMLHttpRequest',
            'Accept':'application/json'
        }
    }).then(function(response){
        return response.json().then(function(data){
            return {ok:response.ok,data:data};
        });
    });
}

var smtpModal=document.getElementById('smtpModal');
var smtpForm=document.getElementById('smtpForm');
var smtpSaveBtn=document.getElementById('smtpSaveBtn');
var smtpSaveText=document.getElementById('smtpSaveText');

function openSmtpModal(row){
    smtpForm.reset();
    document.getElementById('smtpId').value='';
    document.getElementById('smtpModalTitle').textContent='Add SMTP Configuration';
    smtpSaveText.textContent='Save SMTP';

    if(row){
        document.getElementById('smtpId').value=row.id||'';
        document.getElementById('smtpConfigName').value=row.config_name||'';
        document.getElementById('smtpHost').value=row.host||'';
        document.getElementById('smtpPort').value=row.port||587;
        document.getElementById('smtpEncryption').value=row.encryption||'tls';
        document.getElementById('smtpUsername').value=row.username||'';
        document.getElementById('smtpPassword').value='';
        document.getElementById('smtpFromName').value=row.from_name||'';
        document.getElementById('smtpFromEmail').value=row.from_email||'';
        document.getElementById('smtpReplyTo').value=row.reply_to_email||'';
        document.getElementById('smtpStatus').value=String(row.is_active);
        document.getElementById('smtpDefault').checked=String(row.is_default)==='1';
        document.getElementById('smtpModalTitle').textContent='Edit SMTP Configuration';
        smtpSaveText.textContent='Update SMTP';
    }

    smtpModal.classList.add('show');
}

function closeSmtpModal(){smtpModal.classList.remove('show');}

document.getElementById('addSmtpBtn').addEventListener('click',function(){openSmtpModal(null);});
document.getElementById('smtpModalClose').addEventListener('click',closeSmtpModal);
document.getElementById('smtpCancel').addEventListener('click',closeSmtpModal);
smtpModal.addEventListener('click',function(e){if(e.target===smtpModal){closeSmtpModal();}});

document.querySelectorAll('.smtp-edit').forEach(function(btn){
    btn.addEventListener('click',function(){
        try{
            openSmtpModal(JSON.parse(btn.getAttribute('data-row')));
        }catch(e){
            showToast('error','Unable to load SMTP configuration.',3000);
        }
    });
});

smtpForm.addEventListener('submit',function(e){
    e.preventDefault();

    if(!smtpForm.checkValidity()){
        showToast('warning','Please complete the required SMTP fields correctly.',3000);
        smtpForm.reportValidity();
        return;
    }

    smtpSaveBtn.disabled=true;
    smtpSaveBtn.classList.add('loading');
    smtpSaveText.textContent='Saving...';

    apiRequest(new FormData(smtpForm))
    .then(function(result){
        if(!result.ok||!result.data.success){
            throw new Error(result.data.message||'Unable to save SMTP configuration.');
        }

        showToast('success',result.data.message,3000);
        closeSmtpModal();

        setTimeout(function(){window.location.reload();},500);
    })
    .catch(function(error){
        showToast('error',error.message||'Unable to save SMTP configuration.',3000);
        smtpSaveBtn.disabled=false;
        smtpSaveBtn.classList.remove('loading');
        smtpSaveText.textContent='Save SMTP';
    });
});

document.querySelectorAll('.smtp-status').forEach(function(btn){
    btn.addEventListener('click',function(){
        var fd=new FormData();
        fd.append('csrf_token','<?= es_h($csrfToken) ?>');
        fd.append('action','toggle_status');
        fd.append('id',btn.getAttribute('data-id'));
        fd.append('is_active',btn.getAttribute('data-status')==='1'?'0':'1');

        apiRequest(fd)
        .then(function(result){
            if(!result.ok||!result.data.success){
                throw new Error(result.data.message||'Unable to update SMTP status.');
            }
            showToast('success',result.data.message,3000);
            setTimeout(function(){window.location.reload();},500);
        })
        .catch(function(error){
            showToast('error',error.message||'Unable to update SMTP status.',3000);
        });
    });
});

document.querySelectorAll('.smtp-delete').forEach(function(btn){
    btn.addEventListener('click',function(){
        if(!window.confirm('Delete '+btn.getAttribute('data-name')+'?')){return;}

        var fd=new FormData();
        fd.append('csrf_token','<?= es_h($csrfToken) ?>');
        fd.append('action','delete_smtp');
        fd.append('id',btn.getAttribute('data-id'));

        apiRequest(fd)
        .then(function(result){
            if(!result.ok||!result.data.success){
                throw new Error(result.data.message||'Unable to delete SMTP configuration.');
            }
            showToast('success',result.data.message,3000);
            setTimeout(function(){window.location.reload();},500);
        })
        .catch(function(error){
            showToast('error',error.message||'Unable to delete SMTP configuration.',3000);
        });
    });
});

document.querySelectorAll('.smtp-test-one').forEach(function(btn){
    btn.addEventListener('click',function(){
        var fd=new FormData();
        fd.append('csrf_token','<?= es_h($csrfToken) ?>');
        fd.append('action','test_connection');
        fd.append('id',btn.getAttribute('data-id'));

        btn.disabled=true;

        apiRequest(fd)
        .then(function(result){
            if(!result.ok||!result.data.success){
                throw new Error(result.data.message||'SMTP connection test failed.');
            }
            showToast('success',result.data.message,3000);
            setTimeout(function(){window.location.reload();},700);
        })
        .catch(function(error){
            showToast('error',error.message||'SMTP connection test failed.',3000);
            btn.disabled=false;
        });
    });
});

var testModal=document.getElementById('testModal');
var testForm=document.getElementById('testForm');
var testSendBtn=document.getElementById('testSendBtn');
var testSendText=document.getElementById('testSendText');

function openTestModal(){
    testForm.reset();
    <?php if ($defaultConfig): ?>
    document.getElementById('testSmtpConfig').value='<?= (int)$defaultConfig['id'] ?>';
    <?php endif; ?>
    testModal.classList.add('show');
}
function closeTestModal(){testModal.classList.remove('show');}

document.getElementById('testEmailBtn').addEventListener('click',openTestModal);
document.getElementById('testModalClose').addEventListener('click',closeTestModal);
document.getElementById('testCancel').addEventListener('click',closeTestModal);
testModal.addEventListener('click',function(e){if(e.target===testModal){closeTestModal();}});

testForm.addEventListener('submit',function(e){
    e.preventDefault();

    if(!testForm.checkValidity()){
        showToast('warning','Select SMTP and enter a valid recipient email.',3000);
        testForm.reportValidity();
        return;
    }

    testSendBtn.disabled=true;
    testSendBtn.classList.add('loading');
    testSendText.textContent='Sending...';

    apiRequest(new FormData(testForm))
    .then(function(result){
        if(!result.ok||!result.data.success){
            throw new Error(result.data.message||'Unable to send test email.');
        }

        showToast('success',result.data.message,3000);
        closeTestModal();
        setTimeout(function(){window.location.reload();},700);
    })
    .catch(function(error){
        showToast('error',error.message||'Unable to send test email.',3000);
        testSendBtn.disabled=false;
        testSendBtn.classList.remove('loading');
        testSendText.textContent='Send Test';
    });
});
})();
</script>
</body>
</html>