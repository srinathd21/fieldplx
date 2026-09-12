<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/theme-loader.php';

$pageTitle = 'Theme Settings · FieldPlx';
$pageDescription = 'Dynamic application theme settings';

$companyId = (int)($_SESSION['company_id'] ?? 1);
$branchId  = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;

$success = isset($_GET['success']);
$error = trim($_GET['error'] ?? '');

require __DIR__ . '/includes/header.php';

$fontFamilies = [
    'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif' => 'Inter / System',
    '"Poppins", Arial, sans-serif' => 'Poppins',
    '"Roboto", Arial, sans-serif' => 'Roboto',
    '"Open Sans", Arial, sans-serif' => 'Open Sans',
    '"Montserrat", Arial, sans-serif' => 'Montserrat',
    '"Lato", Arial, sans-serif' => 'Lato',
    '"Nunito", Arial, sans-serif' => 'Nunito',
    '"Raleway", Arial, sans-serif' => 'Raleway',
    'Arial, Helvetica, sans-serif' => 'Arial',
    'Georgia, "Times New Roman", serif' => 'Georgia',
];

$fontWeights = [
    '300' => '300 Light',
    '400' => '400 Regular',
    '500' => '500 Medium',
    '600' => '600 Semi Bold',
    '700' => '700 Bold',
    '800' => '800 Extra Bold',
    '900' => '900 Black'
];

$groups = [
    'layout' => 'Layout Colors',
    'navbar' => 'Navbar Colors',
    'sidebar' => 'Sidebar Colors',
    'brand' => 'Brand & Button Colors',
    'cards' => 'Card Colors',
    'tables' => 'Table Colors',
    'forms' => 'Form Colors',
    'status' => 'Status Colors',
];

$controls = [
    'layout' => [
        ['body_bg', 'Body Background'],
        ['content_bg', 'Content Background'],
        ['text_color', 'Main Text'],
        ['muted_text_color', 'Muted Text'],
        ['border_color', 'Border Color'],
    ],
    'navbar' => [
        ['navbar_bg', 'Navbar Background'],
        ['navbar_text', 'Navbar Text'],
        ['navbar_icon', 'Navbar Icon'],
        ['navbar_hover_bg', 'Navbar Hover Background'],
        ['navbar_hover_text', 'Navbar Hover Text'],
        ['navbar_search_bg', 'Search Background'],
    ],
    'sidebar' => [
        ['sidebar_bg', 'Sidebar Background'],
        ['sidebar_text', 'Sidebar Text'],
        ['sidebar_icon', 'Sidebar Icon'],
        ['sidebar_hover_bg', 'Sidebar Hover Background'],
        ['sidebar_hover_text', 'Sidebar Hover Text'],
        ['sidebar_active_bg', 'Sidebar Active Background'],
        ['sidebar_active_text', 'Sidebar Active Text'],
        ['sidebar_active_border', 'Sidebar Active Border'],
        ['sidebar_separator', 'Sidebar Separator'],
        ['sidebar_tooltip_bg', 'Tooltip Background'],
        ['sidebar_tooltip_text', 'Tooltip Text'],
    ],
    'brand' => [
        ['primary_color', 'Primary Color'],
        ['primary_hover', 'Primary Hover'],
        ['primary_gradient_start', 'Primary Gradient Start'],
        ['primary_gradient_end', 'Primary Gradient End'],
        ['brand_bg', 'Brand Background'],
        ['brand_text', 'Brand Text'],
        ['button_bg', 'Button Background'],
        ['button_text', 'Button Text'],
        ['button_border', 'Button Border'],
        ['button_hover_bg', 'Button Hover Background'],
        ['button_hover_text', 'Button Hover Text'],
    ],
    'cards' => [
        ['card_bg', 'Card Background'],
        ['card_header_bg', 'Card Header Background'],
        ['card_border', 'Card Border'],
    ],
    'tables' => [
        ['table_header_bg', 'Table Header Background'],
        ['table_header_text', 'Table Header Text'],
        ['table_row_bg', 'Table Row Background'],
        ['table_alt_bg', 'Alternate Row Background'],
        ['table_hover_bg', 'Table Row Hover'],
        ['table_border', 'Table Border'],
    ],
    'forms' => [
        ['input_bg', 'Input Background'],
        ['input_text', 'Input Text'],
        ['input_border', 'Input Border'],
        ['input_focus', 'Input Focus'],
    ],
    'status' => [
        ['success_color', 'Success Color'],
        ['warning_color', 'Warning Color'],
        ['danger_color', 'Danger Color'],
        ['status_bg', 'Status Background'],
        ['status_text', 'Status Text'],
        ['status_dot', 'Status Dot'],
        ['profile_bg', 'Profile Background'],
        ['profile_text', 'Profile Text'],
    ],
];

function theme_color_value($key) {
    $value = strtoupper(trim(theme_value($key, '#FFFFFF')));
    return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : '#FFFFFF';
}
?>

<style>
.theme-settings-page{overflow-x:hidden}
.theme-head{
    padding:24px 28px;
    margin-bottom:18px;
    background:var(--card-bg);
    border:1px solid var(--card-border);
    border-radius:22px;
    box-shadow:var(--card-shadow);
}
.theme-head h1{
    font-size:30px;
    line-height:1.2;
    font-weight:var(--font-weight-bold);
    margin:0 0 6px;
    color:var(--text);
}
.theme-head p{
    font-size:15px;
    color:var(--muted);
    margin:0;
    max-width:780px;
}
.theme-head-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}
.theme-summary-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
    margin-bottom:18px;
}
.summary-card{
    min-height:112px;
    padding:18px;
    display:flex;
    align-items:center;
    gap:14px;
    background:var(--card-bg);
    border:1px solid var(--card-border);
    border-radius:22px;
    box-shadow:var(--card-shadow);
    overflow:hidden;
}
.summary-icon{
    width:52px;
    height:52px;
    border-radius:16px;
    display:grid;
    place-items:center;
    color:#fff;
    flex:0 0 auto;
}
.summary-icon svg{width:24px;height:24px}
.summary-card span,.summary-card small{
    display:block;
    color:var(--muted);
    font-weight:700;
    font-size:12px;
}
.summary-card strong{
    display:block;
    color:var(--text);
    font-size:18px;
    line-height:1.25;
    font-weight:var(--font-weight-bold);
    margin:2px 0;
    word-break:break-word;
}
.theme-layout{
    display:grid;
    grid-template-columns:minmax(0,1fr) 390px;
    gap:16px;
    align-items:start;
}
.theme-main-card,.theme-preview-card{
    background:var(--card-bg);
    border:1px solid var(--card-border);
    border-radius:22px;
    box-shadow:var(--card-shadow);
    padding:26px 28px;
}
.theme-group + .theme-group{margin-top:34px}
.theme-group h2{
    font-size:20px;
    font-weight:var(--font-weight-bold);
    margin:0 0 18px;
    color:var(--text);
}
.group-note{
    margin:0 0 18px;
    padding:13px 15px;
    border-radius:15px;
    background:linear-gradient(
        var(--gradient-angle),
        var(--gradient-start),
        var(--gradient-middle),
        var(--gradient-end)
    );
    color:#fff;
    font-weight:700;
    font-size:13px;
}
.field-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:14px;
}
.setting-field{
    border:1px solid var(--card-border);
    background:color-mix(in srgb,var(--card-bg) 92%,var(--body-bg));
    border-radius:18px;
    padding:16px;
    min-height:100%;
}
.setting-field label{
    display:block;
    color:var(--text);
    font-size:13px;
    font-weight:var(--font-weight-bold);
    margin-bottom:12px;
    line-height:1.3;
}
.color-input-wrap{
    display:grid;
    grid-template-columns:58px minmax(0,1fr);
    gap:12px;
    align-items:center;
}
.color-input-wrap input[type=color]{
    width:58px;
    height:48px;
    border:1px solid var(--input-border);
    border-radius:14px;
    background:var(--input-bg);
    padding:4px;
    cursor:pointer;
}
.color-input-wrap input[type=text],
.setting-field input[type=text],
.setting-field select{
    height:48px;
    border-radius:14px;
    font-weight:700;
    background:var(--input-bg);
    border:1px solid var(--input-border);
    color:var(--input-text);
    padding:0 12px;
    width:100%;
}
.typography-switch{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:16px;
    padding:14px 16px;
    border-radius:16px;
    background:color-mix(in srgb,var(--card-bg) 92%,var(--body-bg));
    border:1px solid var(--card-border);
}
.typography-switch input{width:18px;height:18px}
.gradient-cards{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:14px;
}
.gradient-card{
    border:1px solid var(--card-border);
    background:color-mix(in srgb,var(--card-bg) 92%,var(--body-bg));
    border-radius:18px;
    padding:16px;
}
.gradient-preview{
    height:58px;
    border-radius:14px;
    margin-bottom:14px;
}
.gradient-card h3{
    font-size:15px;
    font-weight:var(--font-weight-bold);
    margin:0 0 12px;
}
.gradient-stack{display:grid;gap:10px}
.gradient-row{
    display:grid;
    grid-template-columns:52px 1fr;
    gap:10px;
}
.gradient-row input[type=color]{
    width:52px;
    height:44px;
    border:1px solid var(--input-border);
    border-radius:12px;
}
.gradient-row input[type=text]{
    height:44px;
    border:1px solid var(--input-border);
    border-radius:12px;
    padding:0 10px;
}
.theme-preview-sticky{position:sticky;top:18px}
.live-pill{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:6px 12px;
    background:color-mix(in srgb,var(--success) 16%,transparent);
    color:var(--success);
    font-size:12px;
    font-weight:800;
}
.mini-browser{
    border:1px solid var(--card-border);
    background:var(--card-bg);
    border-radius:22px;
    overflow:hidden;
}
.browser-dots{
    height:44px;
    display:flex;
    align-items:center;
    gap:7px;
    padding:0 14px;
    border-bottom:1px solid var(--card-border);
    background:var(--card-header-bg);
}
.browser-dots span{
    width:10px;height:10px;border-radius:50%;
    background:var(--muted);opacity:.55;
}
.preview-layout{
    display:grid;
    grid-template-columns:118px minmax(0,1fr);
    min-height:390px;
    background:var(--body-bg);
}
.preview-sidebar{
    background:linear-gradient(
        var(--gradient-angle),
        var(--gradient-start),
        var(--gradient-middle),
        var(--gradient-end)
    );
    color:var(--sidebar-text);
    padding:14px 10px;
}
.preview-logo{
    width:34px;height:34px;border-radius:12px;margin-bottom:14px;
    background:linear-gradient(
        var(--gradient-angle),
        var(--gradient-start),
        var(--gradient-middle),
        var(--gradient-end)
    );
}
.preview-menu{
    border-radius:10px;
    padding:9px 10px;
    font-size:11px;
    font-weight:800;
    color:var(--sidebar-text);
    margin-bottom:7px;
}
.preview-menu.hover{
    background:var(--sidebar-hover-bg);
    color:var(--sidebar-hover-text);
}
.preview-menu.active{
    color:var(--sidebar-active-text);
    background:linear-gradient(
        var(--gradient-angle),
        var(--gradient-start),
        var(--gradient-middle),
        var(--gradient-end)
    );
}
.preview-content{
    padding:14px;
    display:grid;
    align-content:start;
    gap:12px;
    background:var(--body-bg);
}
.preview-card{
    background:var(--card-bg);
    border:1px solid var(--card-border);
    border-radius:16px;
    padding:15px;
    color:var(--text);
}
.preview-card h3{
    font-size:var(--font-size-h3);
    margin:0 0 7px;
    font-weight:var(--font-weight-bold);
    color:var(--text);
}
.preview-card p{
    color:var(--muted);
    font-size:12px;
    line-height:var(--line-height);
    margin-bottom:12px;
}
.preview-card button{
    border:0;
    background:linear-gradient(
        var(--gradient-angle),
        var(--gradient-start),
        var(--gradient-middle),
        var(--gradient-end)
    );
    color:#fff;
    border-radius:12px;
    padding:8px 12px;
    font-size:12px;
    font-weight:800;
}
.preview-card input{
    width:100%;
    border:1px solid var(--input-border);
    background:var(--input-bg);
    color:var(--input-text);
    border-radius:12px;
    padding:9px 10px;
    font-size:12px;
    font-weight:700;
}
.preview-table-head{
    margin-top:10px;
    background:var(--table-header-bg);
    color:var(--table-header-text);
    border-radius:12px;
    padding:9px 10px;
    font-size:12px;
    font-weight:800;
}
.preview-statuses{
    display:flex;
    flex-wrap:wrap;
    gap:7px;
}
.preview-statuses span{
    border-radius:999px;
    padding:6px 9px;
    color:#fff;
    font-size:10px;
    font-weight:800;
}
.preview-statuses .success{background:var(--success)}
.preview-statuses .warning{background:var(--warning)}
.preview-statuses .danger{background:var(--danger)}
.theme-save-bar{
    margin-top:24px;
    padding-top:18px;
    border-top:1px solid var(--card-border);
    display:flex;
    justify-content:flex-end;
    gap:10px;
}
@media(max-width:1200px){
    .theme-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .theme-layout{grid-template-columns:1fr}
    .theme-preview-sticky{position:static}
    .field-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:760px){
    .theme-summary-grid{grid-template-columns:1fr}
    .field-grid,.gradient-cards{grid-template-columns:1fr}
    .theme-main-card,.theme-preview-card{padding:18px}
    .theme-head{padding:18px}
    .theme-head h1{font-size:24px}
    .preview-layout{grid-template-columns:96px minmax(0,1fr)}
}

.component-gradient-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:16px;
    align-items:start;
}
.component-gradient-card{
    border:1px solid var(--card-border);
    background:var(--card-bg);
    border-radius:18px;
    padding:16px;
    min-width:0;
    height:auto;
}
.component-gradient-card h3{
    margin:0;
    font-size:16px;
    font-weight:var(--font-weight-bold);
}
.component-gradient-card-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:12px;
}
.component-gradient-preview{
    height:72px;
    border-radius:14px;
    margin-bottom:14px;
    border:1px solid var(--card-border);
    box-shadow:inset 0 0 0 1px rgba(255,255,255,.16);
}
.gradient-switch-row{
    display:flex;
    align-items:center;
    gap:8px;
    margin:0;
    white-space:nowrap;
}
.gradient-switch-row input{
    width:17px;
    height:17px;
    margin:0;
}
.component-gradient-controls{
    display:grid;
    gap:12px;
}
.component-gradient-card .setting-field{
    min-height:0 !important;
    height:auto !important;
    padding:12px !important;
    margin:0 !important;
    border-radius:14px;
}
.component-gradient-card .setting-field label{
    margin-bottom:8px;
    font-size:12px;
}
.gradient-select,
.component-gradient-card input[type=text]{
    width:100%;
    height:42px;
    border:1px solid var(--input-border);
    border-radius:12px;
    background:var(--input-bg);
    color:var(--input-text);
    padding:0 11px;
    font-weight:700;
}
.gradient-color-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:8px;
}
.gradient-color-control{
    min-width:0;
}
.gradient-color-control label{
    display:block;
    margin-bottom:6px;
    color:var(--text);
    font-size:11px;
    font-weight:800;
}
.gradient-color-box{
    display:grid;
    grid-template-columns:38px minmax(0,1fr);
    gap:6px;
    align-items:center;
}
.gradient-color-box input[type=color]{
    width:38px;
    height:38px;
    padding:3px;
    border:1px solid var(--input-border);
    border-radius:10px;
    background:var(--input-bg);
    cursor:pointer;
}
.gradient-color-box input[type=text]{
    height:38px;
    min-width:0;
    padding:0 7px;
    font-size:11px;
    text-transform:uppercase;
}
.gradient-options-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
}
.gradient-option-hidden{
    display:none !important;
}
.component-gradient-card.is-disabled .component-gradient-controls{
    opacity:.48;
}
.component-gradient-card.is-disabled .component-gradient-preview{
    opacity:.7;
}
.gradient-help{
    margin-top:10px;
    color:var(--muted);
    font-size:11px;
    line-height:1.4;
}
@media(max-width:1350px){
    .component-gradient-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:980px){
    .component-gradient-grid{grid-template-columns:1fr}
}
@media(max-width:520px){
    .gradient-color-grid{grid-template-columns:1fr}
    .gradient-options-row{grid-template-columns:1fr}
}

</style>

<div class="theme-settings-page">
    <div class="theme-head">
        <div style="display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap">
            <div>
                <h1>Theme Settings</h1>
                <p>Update application colors, typography, gradients and component styling. Changes preview live before saving.</p>
            </div>
            <div class="theme-head-actions">
                <button type="button" id="resetThemePreview" class="button">Reset Preview</button>
                <button type="submit" form="themeForm" class="button button-primary">Save Theme</button>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="theme-alert success">Theme settings updated successfully.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="theme-alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="theme-summary-grid">
        <div class="summary-card">
            <div class="summary-icon" id="summarySidebarGradient"><i data-lucide="panel-left"></i></div>
            <div>
                <span>Sidebar Gradient</span>
                <strong id="summaryGradientText"><?= theme_e(theme_value('gradient_start')) ?></strong>
                <small>Start → Middle → End</small>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon" style="background:var(--primary)"><i data-lucide="palette"></i></div>
            <div>
                <span>Primary Color</span>
                <strong id="summaryPrimary"><?= theme_e(theme_value('primary_color')) ?></strong>
                <small>Brand / button primary</small>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon" style="background:var(--body-bg);border:1px solid var(--line);color:var(--text)"><i data-lucide="layout-dashboard"></i></div>
            <div>
                <span>Body Background</span>
                <strong id="summaryBodyBg"><?= theme_e(theme_value('body_bg')) ?></strong>
                <small>Page background</small>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon" style="background:var(--text)"><i data-lucide="type"></i></div>
            <div>
                <span>Font Family</span>
                <strong id="summaryFont"><?= theme_e(theme_value('font_family')) ?></strong>
                <small>Dynamic typography</small>
            </div>
        </div>
    </div>

    <form id="themeForm" action="theme-settings-save.php" method="post">
        <input type="hidden" name="company_id" value="<?= $companyId ?>">
        <input type="hidden" name="branch_id" value="<?= $branchId ?? '' ?>">

        <div class="theme-layout">
            <div class="theme-main-card">
                <section class="theme-group">
                    <h2>Typography</h2>

                    <div class="typography-switch">
                        <input type="hidden" name="typography_enabled" value="0">
                        <input type="checkbox" id="typography_enabled" name="typography_enabled" value="1"
                            <?= theme_value('typography_enabled','1') === '1' ? 'checked' : '' ?>>
                        <label for="typography_enabled"><strong>Enable Dynamic Typography</strong></label>
                    </div>

                    <div class="field-grid" id="typographyFields">
                        <div class="setting-field">
                            <label>Font Family</label>
                            <select id="font_family" name="font_family">
                                <?php foreach ($fontFamilies as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value) ?>"
                                        <?= theme_value('font_family') === $value ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php
                        $typeFields = [
                            'body_font_size' => 'Body Font Size',
                            'heading_font_size' => 'H1 Font Size',
                            'h2_font_size' => 'H2 Font Size',
                            'h3_font_size' => 'H3 Font Size',
                            'small_font_size' => 'Small Font Size',
                            'line_height' => 'Line Height',
                            'letter_spacing' => 'Letter Spacing',
                        ];
                        foreach ($typeFields as $key => $label):
                        ?>
                            <div class="setting-field">
                                <label><?= htmlspecialchars($label) ?></label>
                                <input type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= theme_e(theme_value($key)) ?>">
                            </div>
                        <?php endforeach; ?>

                        <?php
                        $weightFields = [
                            'font_weight_normal' => 'Normal Weight',
                            'font_weight_medium' => 'Medium Weight',
                            'font_weight_semibold' => 'Semi Bold Weight',
                            'font_weight_bold' => 'Bold Weight',
                        ];
                        foreach ($weightFields as $key => $label):
                        ?>
                            <div class="setting-field">
                                <label><?= htmlspecialchars($label) ?></label>
                                <select id="<?= $key ?>" name="<?= $key ?>">
                                    <?php foreach ($fontWeights as $v => $l): ?>
                                        <option value="<?= $v ?>" <?= theme_value($key) === $v ? 'selected' : '' ?>><?= $l ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="theme-group">
                    <h2>Component Gradients</h2>
                    <div class="group-note">
                        Select Solid / Linear / Radial / Conic gradient independently for Sidebar, Topbar and Primary Buttons.
                    </div>

                    <div class="component-gradient-grid">
                        <?php
                        $componentGradients = [
                            'sidebar' => ['title' => 'Sidebar', 'icon' => 'panel-left'],
                            'topbar' => ['title' => 'Topbar', 'icon' => 'panel-top'],
                            'button' => ['title' => 'Primary Button', 'icon' => 'mouse-pointer-click'],
                        ];
                        foreach ($componentGradients as $prefix => $meta):
                        ?>
                            <div class="component-gradient-card" id="<?= $prefix ?>GradientCard">
                                <div class="component-gradient-card-head">
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <i data-lucide="<?= htmlspecialchars($meta['icon']) ?>" style="width:17px;height:17px"></i>
                                        <h3><?= htmlspecialchars($meta['title']) ?></h3>
                                    </div>

                                    <div class="gradient-switch-row">
                                        <input type="hidden" name="<?= $prefix ?>_gradient_enabled" value="0">
                                        <input type="checkbox"
                                               id="<?= $prefix ?>_gradient_enabled"
                                               name="<?= $prefix ?>_gradient_enabled"
                                               value="1"
                                               <?= theme_value($prefix . '_gradient_enabled','0') === '1' ? 'checked' : '' ?>>
                                        <label for="<?= $prefix ?>_gradient_enabled"><strong>Enable</strong></label>
                                    </div>
                                </div>

                                <div class="component-gradient-preview" id="<?= $prefix ?>GradientPreview"></div>

                                <div class="component-gradient-controls">
                                    <div class="setting-field">
                                        <label>Gradient Type</label>
                                        <select class="gradient-select" id="<?= $prefix ?>_gradient_type" name="<?= $prefix ?>_gradient_type">
                                            <?php foreach (['linear'=>'Linear','radial'=>'Radial','conic'=>'Conic'] as $v=>$l): ?>
                                                <option value="<?= $v ?>" <?= theme_value($prefix . '_gradient_type','linear') === $v ? 'selected' : '' ?>>
                                                    <?= $l ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="gradient-color-grid">
                                        <?php foreach ([
                                            'start' => 'Start',
                                            'middle' => 'Middle',
                                            'end' => 'End'
                                        ] as $part => $label): ?>
                                            <div class="gradient-color-control">
                                                <label><?= $label ?></label>
                                                <div class="gradient-color-box">
                                                    <input type="color"
                                                           class="component-gradient-picker"
                                                           data-target="<?= $prefix ?>_gradient_<?= $part ?>"
                                                           value="<?= theme_e(theme_value($prefix . '_gradient_' . $part)) ?>">
                                                    <input type="text"
                                                           id="<?= $prefix ?>_gradient_<?= $part ?>"
                                                           name="<?= $prefix ?>_gradient_<?= $part ?>"
                                                           value="<?= theme_e(theme_value($prefix . '_gradient_' . $part)) ?>"
                                                           maxlength="7">
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="gradient-options-row">
                                        <div class="setting-field" id="<?= $prefix ?>GradientAngleWrap">
                                            <label id="<?= $prefix ?>GradientAngleLabel">Angle</label>
                                            <input type="text"
                                                   id="<?= $prefix ?>_gradient_angle"
                                                   name="<?= $prefix ?>_gradient_angle"
                                                   value="<?= theme_e(theme_value($prefix . '_gradient_angle')) ?>">
                                        </div>

                                        <div class="setting-field" id="<?= $prefix ?>GradientPositionWrap">
                                            <label>Position</label>
                                            <select class="gradient-select"
                                                    id="<?= $prefix ?>_gradient_position"
                                                    name="<?= $prefix ?>_gradient_position">
                                                <?php foreach ([
                                                    'center'=>'Center',
                                                    'top'=>'Top',
                                                    'bottom'=>'Bottom',
                                                    'left'=>'Left',
                                                    'right'=>'Right',
                                                    'top left'=>'Top Left',
                                                    'top right'=>'Top Right',
                                                    'bottom left'=>'Bottom Left',
                                                    'bottom right'=>'Bottom Right'
                                                ] as $v=>$l): ?>
                                                    <option value="<?= $v ?>" <?= theme_value($prefix . '_gradient_position','center') === $v ? 'selected' : '' ?>>
                                                        <?= $l ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="gradient-help" id="<?= $prefix ?>GradientHelp"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php foreach ($controls as $groupKey => $items): ?>
                    <section class="theme-group">
                        <h2><?= htmlspecialchars($groups[$groupKey]) ?></h2>

                        <?php if ($groupKey === 'sidebar'): ?>
                            <div class="group-note">
                                Sidebar supports dynamic background, text, hover, active state and gradient styling.
                            </div>
                        <?php endif; ?>

                        <div class="field-grid">
                            <?php foreach ($items as [$key, $label]): ?>
                                <?php $value = theme_color_value($key); ?>
                                <div class="setting-field">
                                    <label for="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></label>
                                    <div class="color-input-wrap">
                                        <input type="color"
                                            class="js-color-picker"
                                            data-target="<?= htmlspecialchars($key) ?>"
                                            value="<?= htmlspecialchars($value) ?>">
                                        <input type="text"
                                            id="<?= htmlspecialchars($key) ?>"
                                            name="<?= htmlspecialchars($key) ?>"
                                            class="js-color-text"
                                            value="<?= htmlspecialchars($value) ?>"
                                            data-original-value="<?= htmlspecialchars($value) ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <section class="theme-group">
                    <h2>Component Sizing</h2>
                    <div class="field-grid">
                        <?php
                        $sizeFields = [
                            'header_height' => 'Header Height',
                            'brand_font_size' => 'Brand Font Size',
                            'sidebar_width' => 'Sidebar Width',
                            'sidebar_collapsed_width' => 'Collapsed Sidebar Width',
                            'sidebar_mobile_width' => 'Mobile Sidebar Width',
                            'card_radius' => 'Card Radius',
                            'card_shadow' => 'Card Shadow',
                            'button_radius' => 'Button Radius',
                            'input_radius' => 'Input Radius',
                            'workspace_padding' => 'Workspace Padding',
                            'radius_sm' => 'Small Radius',
                            'radius_md' => 'Medium Radius',
                            'radius_lg' => 'Large Radius',
                        ];
                        foreach ($sizeFields as $key=>$label):
                        ?>
                            <div class="setting-field">
                                <label><?= htmlspecialchars($label) ?></label>
                                <input type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= theme_e(theme_value($key)) ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="theme-save-bar">
                    <button type="button" id="resetThemePreviewBottom" class="button">Reset Preview</button>
                    <button type="submit" class="button button-primary">Save Theme</button>
                </div>
            </div>

            <aside class="theme-preview-card theme-preview-sticky">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:16px">
                    <div>
                        <h2 style="font-size:20px;margin:0 0 4px">Live Preview</h2>
                        <p style="color:var(--muted);margin:0">Updates instantly while you edit.</p>
                    </div>
                    <span class="live-pill">Live</span>
                </div>

                <div class="mini-browser">
                    <div class="browser-dots"><span></span><span></span><span></span></div>

                    <div class="preview-layout">
                        <aside class="preview-sidebar">
                            <div class="preview-logo"></div>
                            <div class="preview-menu active">Dashboard</div>
                            <div class="preview-menu hover">Invoices</div>
                            <div class="preview-menu">Clients</div>
                            <div class="preview-menu">Reports</div>
                        </aside>

                        <div class="preview-content">
                            <div class="preview-card">
                                <h3>Dashboard Card</h3>
                                <p>Main text, muted text, typography, card and border styles.</p>
                                <button type="button">Primary Button</button>
                            </div>

                            <div class="preview-card">
                                <h3>Form & Table</h3>
                                <input type="text" value="Input preview" readonly>
                                <div class="preview-table-head">Table Header</div>
                            </div>

                            <div class="preview-statuses">
                                <span class="success">Success</span>
                                <span class="warning">Warning</span>
                                <span class="danger">Danger</span>
                            </div>
                        </div>
                    </div>
                </div>

                <p style="font-size:12px;color:var(--muted);margin:14px 0 0">
                    Change any setting on the left. The entire application and this preview update immediately.
                </p>
            </aside>
        </div>
    </form>
</div>

<script>
(function(){
    const root = document.documentElement;

    const variableMap = {
        font_family:'--font-family',
        body_font_size:'--font-size-body',
        small_font_size:'--font-size-small',
        heading_font_size:'--font-size-heading',
        h2_font_size:'--font-size-h2',
        h3_font_size:'--font-size-h3',
        font_weight_normal:'--font-weight-normal',
        font_weight_medium:'--font-weight-medium',
        font_weight_semibold:'--font-weight-semibold',
        font_weight_bold:'--font-weight-bold',
        line_height:'--line-height',
        letter_spacing:'--letter-spacing',

        body_bg:'--body-bg',
        content_bg:'--content-bg',
        text_color:'--text',
        muted_text_color:'--muted',
        border_color:'--line',

        navbar_bg:'--navbar-bg',
        navbar_text:'--navbar-text',
        navbar_icon:'--navbar-icon',
        navbar_hover_bg:'--navbar-hover-bg',
        navbar_hover_text:'--navbar-hover-text',
        navbar_search_bg:'--navbar-search-bg',

        sidebar_bg:'--sidebar-bg',
        sidebar_text:'--sidebar-text',
        sidebar_icon:'--sidebar-icon',
        sidebar_hover_bg:'--sidebar-hover-bg',
        sidebar_hover_text:'--sidebar-hover-text',
        sidebar_active_bg:'--sidebar-active-bg',
        sidebar_active_text:'--sidebar-active-text',
        sidebar_active_border:'--sidebar-active-border',
        sidebar_separator:'--sidebar-separator',
        sidebar_tooltip_bg:'--sidebar-tooltip-bg',
        sidebar_tooltip_text:'--sidebar-tooltip-text',

        primary_color:'--primary',
        primary_hover:'--primary-hover',
        primary_gradient_start:'--primary-start',
        primary_gradient_end:'--primary-end',
        brand_bg:'--brand-bg',
        brand_text:'--brand-text',
        button_bg:'--button-bg',
        button_text:'--button-text',
        button_border:'--button-border',
        button_hover_bg:'--button-hover-bg',
        button_hover_text:'--button-hover-text',

        card_bg:'--card-bg',
        card_header_bg:'--card-header-bg',
        card_border:'--card-border',

        table_header_bg:'--table-header-bg',
        table_header_text:'--table-header-text',
        table_row_bg:'--table-row-bg',
        table_alt_bg:'--table-alt-bg',
        table_hover_bg:'--table-hover-bg',
        table_border:'--table-border',

        input_bg:'--input-bg',
        input_text:'--input-text',
        input_border:'--input-border',
        input_focus:'--input-focus',

        success_color:'--success',
        warning_color:'--warning',
        danger_color:'--danger',
        status_bg:'--status-bg',
        status_text:'--status-text',
        status_dot:'--status-dot',
        profile_bg:'--profile-bg',
        profile_text:'--profile-text',

        header_height:'--header-height',
        brand_font_size:'--brand-font-size',
        sidebar_width:'--sidebar-width',
        sidebar_collapsed_width:'--sidebar-collapsed-width',
        sidebar_mobile_width:'--sidebar-mobile-width',
        card_radius:'--card-radius',
        button_radius:'--button-radius',
        input_radius:'--input-radius',
        workspace_padding:'--workspace-padding',
        radius_sm:'--radius-sm',
        radius_md:'--radius-md',
        radius_lg:'--radius-lg',

        gradient_start:'--gradient-start',
        gradient_middle:'--gradient-middle',
        gradient_end:'--gradient-end',
        gradient_angle:'--gradient-angle'
    };


    function buildGradientCss(prefix){
        const enabled = document.getElementById(prefix + '_gradient_enabled')?.checked;
        const type = document.getElementById(prefix + '_gradient_type')?.value || 'linear';
        const start = document.getElementById(prefix + '_gradient_start')?.value || '#000000';
        const middle = document.getElementById(prefix + '_gradient_middle')?.value || '#666666';
        const end = document.getElementById(prefix + '_gradient_end')?.value || '#FFFFFF';
        const angle = document.getElementById(prefix + '_gradient_angle')?.value || '135deg';
        const position = document.getElementById(prefix + '_gradient_position')?.value || 'center';

        if(!enabled){
            if(prefix === 'sidebar') return getComputedStyle(root).getPropertyValue('--sidebar-bg').trim() || '#f3f2ef';
            if(prefix === 'topbar') return getComputedStyle(root).getPropertyValue('--navbar-bg').trim() || '#f3f2ef';
            return getComputedStyle(root).getPropertyValue('--primary').trim() || '#08a5d2';
        }

        if(type === 'radial'){
            return `radial-gradient(circle at ${position}, ${start} 0%, ${middle} 50%, ${end} 100%)`;
        }

        if(type === 'conic'){
            return `conic-gradient(from ${angle} at ${position}, ${start} 0deg, ${middle} 180deg, ${end} 360deg)`;
        }

        return `linear-gradient(${angle}, ${start} 0%, ${middle} 50%, ${end} 100%)`;
    }

    function refreshComponentGradients(){
        ['sidebar','topbar','button'].forEach(prefix=>{
            const enabled = document.getElementById(prefix + '_gradient_enabled')?.checked ?? false;
            const type = document.getElementById(prefix + '_gradient_type')?.value || 'linear';
            const gradient = buildGradientCss(prefix);

            const card = document.getElementById(prefix + 'GradientCard');
            const preview = document.getElementById(prefix + 'GradientPreview');
            const angleWrap = document.getElementById(prefix + 'GradientAngleWrap');
            const angleLabel = document.getElementById(prefix + 'GradientAngleLabel');
            const positionWrap = document.getElementById(prefix + 'GradientPositionWrap');
            const help = document.getElementById(prefix + 'GradientHelp');

            if(card) card.classList.toggle('is-disabled', !enabled);
            if(preview) preview.style.background = gradient;

            // Linear: angle only. Radial: position only. Conic: start angle + position.
            if(angleWrap) angleWrap.classList.toggle('gradient-option-hidden', type === 'radial');
            if(positionWrap) positionWrap.classList.toggle('gradient-option-hidden', type === 'linear');
            if(angleLabel) angleLabel.textContent = type === 'conic' ? 'Start Angle' : 'Angle';

            if(help){
                if(!enabled){
                    help.textContent = 'Gradient is disabled. Solid theme color will be used.';
                } else if(type === 'radial'){
                    help.textContent = 'Radial gradient uses Start → Middle → End from the selected position.';
                } else if(type === 'conic'){
                    help.textContent = 'Conic gradient rotates Start → Middle → End around the selected position.';
                } else {
                    help.textContent = 'Linear gradient runs Start → Middle → End using the selected angle.';
                }
            }

            if(prefix === 'sidebar'){
                root.style.setProperty('--sidebar-background', gradient);
                document.querySelectorAll('.preview-sidebar').forEach(el=>el.style.background=gradient);
            } else if(prefix === 'topbar'){
                root.style.setProperty('--topbar-background', gradient);
                document.querySelectorAll('.preview-top').forEach(el=>el.style.background=gradient);
            } else {
                root.style.setProperty('--button-background', gradient);
                document.querySelectorAll('.preview-card button,.button-primary').forEach(el=>el.style.background=gradient);
            }
        });
    }

    document.querySelectorAll(
        '[id$="_gradient_enabled"],[id$="_gradient_type"],[id$="_gradient_start"],[id$="_gradient_middle"],[id$="_gradient_end"],[id$="_gradient_angle"],[id$="_gradient_position"]'
    ).forEach(el=>{
        const eventName = (el.type === 'checkbox' || el.tagName === 'SELECT') ? 'change' : 'input';
        el.addEventListener(eventName, refreshComponentGradients);
    });

    document.querySelectorAll(
        '[id$="_gradient_start"],[id$="_gradient_middle"],[id$="_gradient_end"]'
    ).forEach(input=>{
        input.addEventListener('input',()=>{
            let value = String(input.value || '').trim().toUpperCase();
            if(value && !value.startsWith('#')) value = '#' + value;
            input.value = value;

            const valid = /^#[0-9A-F]{6}$/.test(value);
            input.style.borderColor = valid ? '' : 'var(--danger)';

            if(valid){
                const picker = document.querySelector('.component-gradient-picker[data-target="'+input.id+'"]');
                if(picker) picker.value = value;
                refreshComponentGradients();
            }
        });
    });

    document.querySelectorAll('.component-gradient-picker').forEach(picker=>{
        picker.addEventListener('input',()=>{
            const target = document.getElementById(picker.dataset.target);
            if(target){
                target.value = picker.value.toUpperCase();
                target.dispatchEvent(new Event('input'));
            }
        });
    });

    const originals = {};
    const typographyOriginalEnabled = document.getElementById('typography_enabled')?.checked ?? true;

    Object.keys(variableMap).forEach(id=>{
        const input=document.getElementById(id);
        if(!input) return;
        originals[id]=input.value;
        const eventName=input.tagName==='SELECT'?'change':'input';
        input.addEventListener(eventName,()=>{
            root.style.setProperty(variableMap[id],input.value);
            refreshSummary();
            refreshGradients();
        });
    });

    document.querySelectorAll('.js-color-picker,.gradient-picker').forEach(picker=>{
        picker.addEventListener('input',()=>{
            const target=document.getElementById(picker.dataset.target);
            if(!target) return;
            target.value=picker.value.toUpperCase();
            target.dispatchEvent(new Event('input'));
        });
    });

    document.querySelectorAll('.js-color-text').forEach(input=>{
        input.addEventListener('input',()=>{
            let value=input.value.trim();
            if(value && !value.startsWith('#')) value='#'+value;
            input.value=value.toUpperCase();

            if(/^#[0-9A-F]{6}$/.test(input.value)){
                root.style.setProperty(variableMap[input.id],input.value);
                const picker=document.querySelector('.js-color-picker[data-target="'+input.id+'"]');
                if(picker) picker.value=input.value;
                refreshSummary();
            }
        });
    });

    function refreshGradients(){
        const start=document.getElementById('gradient_start')?.value || '#10192E';
        const middle=document.getElementById('gradient_middle')?.value || '#1E3A5F';
        const end=document.getElementById('gradient_end')?.value || '#315C8A';
        const angle=document.getElementById('gradient_angle')?.value || '180deg';

        const preview=document.getElementById('gradientPreview');
        if(preview){
            preview.style.background=`linear-gradient(${angle},${start},${middle},${end})`;
        }

        const summary=document.getElementById('summarySidebarGradient');
        if(summary){
            summary.style.background=`linear-gradient(${angle},${start},${middle},${end})`;
        }

        const summaryText=document.getElementById('summaryGradientText');
        if(summaryText){
            summaryText.textContent=`${start} → ${middle} → ${end}`;
        }
    }

    function refreshSummary(){
        const primary=document.getElementById('primary_color');
        const body=document.getElementById('body_bg');
        const font=document.getElementById('font_family');

        if(primary) document.getElementById('summaryPrimary').textContent=primary.value;
        if(body) document.getElementById('summaryBodyBg').textContent=body.value;
        if(font) document.getElementById('summaryFont').textContent=font.options[font.selectedIndex]?.text || font.value;

        const s=document.getElementById('gradient_start')?.value || '';
        const m=document.getElementById('gradient_middle')?.value || '';
        const e=document.getElementById('gradient_end')?.value || '';
        document.getElementById('summaryGradientText').textContent=`${s} → ${m} → ${e}`;
    }

    const typographyToggle=document.getElementById('typography_enabled');
    const typographyFields=document.getElementById('typographyFields');

    function syncTypography(){
        const enabled=typographyToggle.checked;
        typographyFields.style.opacity=enabled?'1':'.45';
        typographyFields.querySelectorAll('input,select').forEach(el=>el.disabled=!enabled);
    }

    typographyToggle?.addEventListener('change',()=>{
        syncTypography();

        // Live typography toggle:
        // when enabled, apply selected values; when disabled, preview standard defaults.
        const defaults = {
            font_family:'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            body_font_size:'14px',
            small_font_size:'12px',
            heading_font_size:'2.25rem',
            h2_font_size:'1.50rem',
            h3_font_size:'1.20rem',
            font_weight_normal:'400',
            font_weight_medium:'500',
            font_weight_semibold:'600',
            font_weight_bold:'700',
            line_height:'1.5',
            letter_spacing:'-.012em'
        };

        Object.entries(defaults).forEach(([id, fallback])=>{
            const input=document.getElementById(id);
            if(!input) return;
            root.style.setProperty(variableMap[id], typographyToggle.checked ? input.value : fallback);
        });
        refreshSummary();
    });
    syncTypography();

    function resetPreview(){
        Object.entries(originals).forEach(([id,value])=>{
            const input=document.getElementById(id);
            if(!input) return;
            input.value=value;
            root.style.setProperty(variableMap[id],value);
            const picker=document.querySelector('.js-color-picker[data-target="'+id+'"],.gradient-picker[data-target="'+id+'"]');
            if(picker && /^#[0-9A-Fa-f]{6}$/.test(value)) picker.value=value;
        });

        if (typographyToggle) {
            typographyToggle.checked = typographyOriginalEnabled;
            syncTypography();
        }

        refreshGradients();
        refreshComponentGradients();
        refreshSummary();
    }

    document.getElementById('resetThemePreview')?.addEventListener('click',resetPreview);
    document.getElementById('resetThemePreviewBottom')?.addEventListener('click',resetPreview);

    refreshGradients();
    refreshComponentGradients();
    refreshSummary();

    if(window.lucide && typeof window.lucide.createIcons==='function'){
        window.lucide.createIcons();
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
