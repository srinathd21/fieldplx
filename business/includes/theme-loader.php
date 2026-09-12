<?php
/*
|--------------------------------------------------------------------------
| FieldPlx Theme Loader
|--------------------------------------------------------------------------
|
| File:
| business/includes/theme-loader.php
|
| IMPORTANT:
| FieldPlx now uses PDO through includes/database.php.
| Do not use $conn / mysqli_* functions in this file.
|
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

if (
    !isset($pdo) ||
    !($pdo instanceof PDO)
) {
    require_once __DIR__ . '/database.php';
}

/*
|--------------------------------------------------------------------------
| Default Theme
|--------------------------------------------------------------------------
*/

$themeDefaults = array(

    'font_family' =>
        'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',

    'body_font_size' => '14px',
    'small_font_size' => '12px',
    'heading_font_size' => '2.25rem',
    'h2_font_size' => '1.50rem',
    'h3_font_size' => '1.20rem',

    'font_weight_normal' => '400',
    'font_weight_medium' => '500',
    'font_weight_semibold' => '600',
    'font_weight_bold' => '700',

    'line_height' => '1.5',
    'letter_spacing' => '-.012em',
    'typography_enabled' => '1',

    'body_bg' => '#f3f2ef',
    'content_bg' => '#ffffff',
    'text_color' => '#0a2e42',
    'muted_text_color' => '#5c7482',
    'border_color' => '#d9e2e7',

    'header_height' => '62px',

    'navbar_bg' => '#f3f2ef',
    'navbar_text' => '#244554',
    'navbar_icon' => '#0a2e42',
    'navbar_hover_bg' => '#e6eaec',
    'navbar_hover_text' => '#008bbc',
    'navbar_search_bg' => '#e8e7e4',

    'brand_bg' => '#f3f2ef',
    'brand_text' => '#0a2e42',
    'brand_font_size' => '20px',

    'sidebar_width' => '184px',
    'sidebar_collapsed_width' => '72px',
    'sidebar_mobile_width' => '250px',

    'sidebar_bg' => '#f3f2ef',
    'sidebar_text' => '#0d3447',
    'sidebar_icon' => '#0d3447',
    'sidebar_hover_bg' => 'rgba(255,255,255,.60)',
    'sidebar_hover_text' => '#008bbc',
    'sidebar_active_bg' => '#ecf9fc',
    'sidebar_active_text' => '#008bbc',
    'sidebar_active_border' => '#08a5d2',
    'sidebar_separator' => '#dadfdf',
    'sidebar_tooltip_bg' => '#153847',
    'sidebar_tooltip_text' => '#ffffff',

    'primary_color' => '#08a5d2',
    'primary_hover' => '#008bbc',
    'primary_gradient_start' => '#0db1d9',
    'primary_gradient_end' => '#0296c6',

    /*
    |--------------------------------------------------------------------------
    | Legacy / Global Gradient
    |--------------------------------------------------------------------------
    */

    'gradient_start' => '#10192E',
    'gradient_middle' => '#1E3A5F',
    'gradient_end' => '#315C8A',
    'gradient_angle' => '180deg',

    /*
    |--------------------------------------------------------------------------
    | Sidebar Gradient
    |--------------------------------------------------------------------------
    */

    'sidebar_gradient_enabled' => '1',
    'sidebar_gradient_type' => 'linear',
    'sidebar_gradient_start' => '#10192E',
    'sidebar_gradient_middle' => '#1E3A5F',
    'sidebar_gradient_end' => '#315C8A',
    'sidebar_gradient_angle' => '180deg',
    'sidebar_gradient_position' => 'center',

    /*
    |--------------------------------------------------------------------------
    | Topbar Gradient
    |--------------------------------------------------------------------------
    */

    'topbar_gradient_enabled' => '0',
    'topbar_gradient_type' => 'linear',
    'topbar_gradient_start' => '#F8FAFC',
    'topbar_gradient_middle' => '#FFFFFF',
    'topbar_gradient_end' => '#E2E8F0',
    'topbar_gradient_angle' => '90deg',
    'topbar_gradient_position' => 'center',

    /*
    |--------------------------------------------------------------------------
    | Primary Button Gradient
    |--------------------------------------------------------------------------
    */

    'button_gradient_enabled' => '1',
    'button_gradient_type' => 'linear',
    'button_gradient_start' => '#0DB1D9',
    'button_gradient_middle' => '#08A5D2',
    'button_gradient_end' => '#0296C6',
    'button_gradient_angle' => '135deg',
    'button_gradient_position' => 'center',

    /*
    |--------------------------------------------------------------------------
    | Status Colors
    |--------------------------------------------------------------------------
    */

    'success_color' => '#168447',
    'warning_color' => '#b16a00',
    'danger_color' => '#d9344b',

    /*
    |--------------------------------------------------------------------------
    | Cards
    |--------------------------------------------------------------------------
    */

    'card_bg' => '#ffffff',
    'card_header_bg' => '#fbfcfc',
    'card_border' => '#d9e2e7',
    'card_radius' => '10px',
    'card_shadow' => 'none',

    /*
    |--------------------------------------------------------------------------
    | Buttons
    |--------------------------------------------------------------------------
    */

    'button_bg' => '#ffffff',
    'button_text' => '#0a2e42',
    'button_border' => '#ccd9df',
    'button_hover_bg' => '#f7fafb',
    'button_hover_text' => '#0a2e42',
    'button_radius' => '8px',

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    */

    'table_header_bg' => '#fbfcfc',
    'table_header_text' => '#4d6877',
    'table_row_bg' => '#ffffff',
    'table_alt_bg' => '#fcfdfd',
    'table_hover_bg' => '#f7fafb',
    'table_border' => '#e6ebee',

    /*
    |--------------------------------------------------------------------------
    | Form Inputs
    |--------------------------------------------------------------------------
    */

    'input_bg' => '#ffffff',
    'input_text' => '#0a2e42',
    'input_border' => '#d9e2e7',
    'input_focus' => '#08a5d2',
    'input_radius' => '8px',

    /*
    |--------------------------------------------------------------------------
    | Status / Profile
    |--------------------------------------------------------------------------
    */

    'status_bg' => '#ddf4fa',
    'status_text' => '#0082b5',
    'status_dot' => '#0ca7d3',

    'profile_bg' => '#d8f0f8',
    'profile_text' => '#0878a4',

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    */

    'workspace_padding' => '18px 20px 30px',
    'radius_sm' => '6px',
    'radius_md' => '8px',
    'radius_lg' => '10px'
);

/*
|--------------------------------------------------------------------------
| Start With Defaults
|--------------------------------------------------------------------------
*/

$themeSettings = $themeDefaults;

/*
|--------------------------------------------------------------------------
| Resolve Business / Tenant Context
|--------------------------------------------------------------------------
|
| Older theme code used company_id.
| Current FieldPlx authentication uses tenant_id.
|
| This fallback keeps both structures compatible:
|   1. company_id if explicitly present
|   2. otherwise tenant_id
|   3. otherwise 1
|
*/

$companyId = 1;

if (
    isset($_SESSION['company_id']) &&
    (int)$_SESSION['company_id'] > 0
) {
    $companyId =
        (int)$_SESSION['company_id'];

} elseif (
    isset($_SESSION['tenant_id']) &&
    (int)$_SESSION['tenant_id'] > 0
) {
    $companyId =
        (int)$_SESSION['tenant_id'];
}

$branchId = null;

if (
    isset($_SESSION['branch_id']) &&
    (int)$_SESSION['branch_id'] > 0
) {
    $branchId =
        (int)$_SESSION['branch_id'];
}

/*
|--------------------------------------------------------------------------
| Load Saved Theme
|--------------------------------------------------------------------------
*/

try {

    /*
     * First confirm the table exists.
     *
     * This prevents the whole application from crashing if theme_settings
     * has not yet been created in a development environment.
     */
    $tableCheck =
        $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'theme_settings'
        ");

    $tableCheck->execute();

    $themeTableExists =
        ((int)$tableCheck->fetchColumn() > 0);

    if ($themeTableExists) {

        $sql = "
            SELECT *
            FROM theme_settings
            WHERE company_id = :company_id
              AND is_active = 1
              AND (
                    branch_id = :branch_id
                    OR branch_id IS NULL
                  )
            ORDER BY
                CASE
                    WHEN branch_id IS NOT NULL THEN 0
                    ELSE 1
                END,
                id DESC
            LIMIT 1
        ";

        $stmt =
            $pdo->prepare($sql);

        /*
         * Binding NULL for branch_id is correct.
         * In that case only the branch_id IS NULL fallback row matches.
         */
        $stmt->bindValue(
            ':company_id',
            $companyId,
            PDO::PARAM_INT
        );

        if ($branchId !== null) {

            $stmt->bindValue(
                ':branch_id',
                $branchId,
                PDO::PARAM_INT
            );

        } else {

            $stmt->bindValue(
                ':branch_id',
                null,
                PDO::PARAM_NULL
            );
        }

        $stmt->execute();

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if ($row) {

            foreach (
                $themeDefaults as $key => $default
            ) {

                if (
                    array_key_exists($key, $row) &&
                    $row[$key] !== null &&
                    $row[$key] !== ''
                ) {
                    $themeSettings[$key] =
                        (string)$row[$key];
                }
            }
        }
    }

} catch (Throwable $e) {

    /*
     * Theme loading must never prevent the business application from loading.
     * Keep defaults and log the technical problem.
     */
    error_log(
        'FieldPlx theme loader error: ' .
        $e->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| Theme Value Helper
|--------------------------------------------------------------------------
*/

if (!function_exists('theme_value')) {

    function theme_value(
        $key,
        $default = ''
    ) {
        global $themeSettings;

        return (string)(
            isset($themeSettings[$key])
                ? $themeSettings[$key]
                : $default
        );
    }
}

/*
|--------------------------------------------------------------------------
| Theme Escape Helper
|--------------------------------------------------------------------------
*/

if (!function_exists('theme_e')) {

    function theme_e($value)
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
