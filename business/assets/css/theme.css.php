<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/theme-loader.php';

header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$typographyEnabled = theme_value('typography_enabled', '1') === '1';

function css_value(string $key, string $default = ''): string
{
    return str_replace(["\r", "\n", ";", "{", "}"], '', theme_value($key, $default));
}
function typography_css_value(string $key, string $default): string
{
    global $typographyEnabled;
    if (!$typographyEnabled) {
        return str_replace(["\r", "\n", ";", "{", "}"], '', $default);
    }
    return css_value($key, $default);
}

function build_component_gradient(string $prefix, string $solidFallback): string
{
    $enabled = theme_value($prefix . '_gradient_enabled', '0') === '1';
    if (!$enabled) {
        return $solidFallback;
    }

    $type = strtolower(theme_value($prefix . '_gradient_type', 'linear'));
    if (!in_array($type, ['linear', 'radial', 'conic'], true)) {
        $type = 'linear';
    }

    $start = css_value($prefix . '_gradient_start', '#000000');
    $middle = css_value($prefix . '_gradient_middle', '#666666');
    $end = css_value($prefix . '_gradient_end', '#FFFFFF');
    $angle = css_value($prefix . '_gradient_angle', '135deg');
    $position = css_value($prefix . '_gradient_position', 'center');

    if ($type === 'radial') {
        return "radial-gradient(circle at {$position}, {$start} 0%, {$middle} 50%, {$end} 100%)";
    }

    if ($type === 'conic') {
        return "conic-gradient(from {$angle} at {$position}, {$start} 0deg, {$middle} 180deg, {$end} 360deg)";
    }

    return "linear-gradient({$angle}, {$start} 0%, {$middle} 50%, {$end} 100%)";
}

?>
:root{
  --font-family:<?= typography_css_value('font_family','Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif') ?>;
  --font-size-body:<?= typography_css_value('body_font_size','14px') ?>;
  --font-size-small:<?= typography_css_value('small_font_size','12px') ?>;
  --font-size-heading:<?= typography_css_value('heading_font_size','2.25rem') ?>;
  --font-size-h2:<?= typography_css_value('h2_font_size','1.50rem') ?>;
  --font-size-h3:<?= typography_css_value('h3_font_size','1.20rem') ?>;
  --font-weight-normal:<?= typography_css_value('font_weight_normal','400') ?>;
  --font-weight-medium:<?= typography_css_value('font_weight_medium','500') ?>;
  --font-weight-semibold:<?= typography_css_value('font_weight_semibold','600') ?>;
  --font-weight-bold:<?= typography_css_value('font_weight_bold','700') ?>;
  --line-height:<?= typography_css_value('line_height','1.5') ?>;
  --letter-spacing:<?= typography_css_value('letter_spacing','-.012em') ?>;

  --body-bg:<?= css_value('body_bg','#f3f2ef') ?>;
  --content-bg:<?= css_value('content_bg','#fff') ?>;
  --text:<?= css_value('text_color','#0a2e42') ?>;
  --muted:<?= css_value('muted_text_color','#5c7482') ?>;
  --line:<?= css_value('border_color','#d9e2e7') ?>;

  --header-height:<?= css_value('header_height','62px') ?>;
  --navbar-bg:<?= css_value('navbar_bg','#f3f2ef') ?>;
  --navbar-text:<?= css_value('navbar_text','#244554') ?>;
  --navbar-icon:<?= css_value('navbar_icon','#0a2e42') ?>;
  --navbar-hover-bg:<?= css_value('navbar_hover_bg','#e6eaec') ?>;
  --navbar-hover-text:<?= css_value('navbar_hover_text','#008bbc') ?>;
  --navbar-search-bg:<?= css_value('navbar_search_bg','#e8e7e4') ?>;

  --brand-bg:<?= css_value('brand_bg','#f3f2ef') ?>;
  --brand-text:<?= css_value('brand_text','#0a2e42') ?>;
  --brand-font-size:<?= css_value('brand_font_size','20px') ?>;

  --sidebar-width:<?= css_value('sidebar_width','184px') ?>;
  --sidebar-collapsed-width:<?= css_value('sidebar_collapsed_width','72px') ?>;
  --sidebar-mobile-width:<?= css_value('sidebar_mobile_width','250px') ?>;
  --sidebar-bg:<?= css_value('sidebar_bg','#f3f2ef') ?>;
  --sidebar-text:<?= css_value('sidebar_text','#0d3447') ?>;
  --sidebar-icon:<?= css_value('sidebar_icon','#0d3447') ?>;
  --sidebar-hover-bg:<?= css_value('sidebar_hover_bg','rgba(255,255,255,.6)') ?>;
  --sidebar-hover-text:<?= css_value('sidebar_hover_text','#008bbc') ?>;
  --sidebar-active-bg:<?= css_value('sidebar_active_bg','#ecf9fc') ?>;
  --sidebar-active-text:<?= css_value('sidebar_active_text','#008bbc') ?>;
  --sidebar-active-border:<?= css_value('sidebar_active_border','#08a5d2') ?>;
  --sidebar-separator:<?= css_value('sidebar_separator','#dadfdf') ?>;
  --sidebar-tooltip-bg:<?= css_value('sidebar_tooltip_bg','#153847') ?>;
  --sidebar-tooltip-text:<?= css_value('sidebar_tooltip_text','#fff') ?>;

  --primary:<?= css_value('primary_color','#08a5d2') ?>;
  --primary-hover:<?= css_value('primary_hover','#008bbc') ?>;
  --primary-start:<?= css_value('primary_gradient_start','#0db1d9') ?>;
  --primary-end:<?= css_value('primary_gradient_end','#0296c6') ?>;
  --gradient-start:<?= css_value('gradient_start','#10192E') ?>;
  --gradient-middle:<?= css_value('gradient_middle','#1E3A5F') ?>;
  --gradient-end:<?= css_value('gradient_end','#315C8A') ?>;
  --gradient-angle:<?= css_value('gradient_angle','180deg') ?>;

  --sidebar-background:<?= build_component_gradient('sidebar', css_value('sidebar_bg','#f3f2ef')) ?>;
  --topbar-background:<?= build_component_gradient('topbar', css_value('navbar_bg','#f3f2ef')) ?>;
  --button-background:<?= build_component_gradient('button', css_value('primary_color','#08a5d2')) ?>;

    --success:<?= css_value('success_color','#168447') ?>;
  --warning:<?= css_value('warning_color','#b16a00') ?>;
  --danger:<?= css_value('danger_color','#d9344b') ?>;

  --card-bg:<?= css_value('card_bg','#fff') ?>;
  --card-header-bg:<?= css_value('card_header_bg','#fbfcfc') ?>;
  --card-border:<?= css_value('card_border','#d9e2e7') ?>;
  --card-radius:<?= css_value('card_radius','10px') ?>;
  --card-shadow:<?= css_value('card_shadow','none') ?>;

  --button-bg:<?= css_value('button_bg','#fff') ?>;
  --button-text:<?= css_value('button_text','#0a2e42') ?>;
  --button-border:<?= css_value('button_border','#ccd9df') ?>;
  --button-hover-bg:<?= css_value('button_hover_bg','#f7fafb') ?>;
  --button-hover-text:<?= css_value('button_hover_text','#0a2e42') ?>;
  --button-radius:<?= css_value('button_radius','8px') ?>;

  --table-header-bg:<?= css_value('table_header_bg','#fbfcfc') ?>;
  --table-header-text:<?= css_value('table_header_text','#4d6877') ?>;
  --table-row-bg:<?= css_value('table_row_bg','#fff') ?>;
  --table-alt-bg:<?= css_value('table_alt_bg','#fcfdfd') ?>;
  --table-hover-bg:<?= css_value('table_hover_bg','#f7fafb') ?>;
  --table-border:<?= css_value('table_border','#e6ebee') ?>;

  --input-bg:<?= css_value('input_bg','#fff') ?>;
  --input-text:<?= css_value('input_text','#0a2e42') ?>;
  --input-border:<?= css_value('input_border','#d9e2e7') ?>;
  --input-focus:<?= css_value('input_focus','#08a5d2') ?>;
  --input-radius:<?= css_value('input_radius','8px') ?>;

  --status-bg:<?= css_value('status_bg','#ddf4fa') ?>;
  --status-text:<?= css_value('status_text','#0082b5') ?>;
  --status-dot:<?= css_value('status_dot','#0ca7d3') ?>;

  --profile-bg:<?= css_value('profile_bg','#d8f0f8') ?>;
  --profile-text:<?= css_value('profile_text','#0878a4') ?>;

  --workspace-padding:<?= css_value('workspace_padding','18px 20px 30px') ?>;
  --radius-sm:<?= css_value('radius_sm','6px') ?>;
  --radius-md:<?= css_value('radius_md','8px') ?>;
  --radius-lg:<?= css_value('radius_lg','10px') ?>;
}
