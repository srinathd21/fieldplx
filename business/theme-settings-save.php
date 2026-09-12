<?php
/*
|--------------------------------------------------------------------------
| FieldPlx Theme Settings Save
|--------------------------------------------------------------------------
|
| File:
| business/theme-settings-save.php
|
| PDO version.
| Compatible with the current FieldPlx business authentication/database flow.
|
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Authentication + Database
|--------------------------------------------------------------------------
|
| auth.php loads database.php and exposes the current tenant/session context.
|
*/

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme-loader.php';

/*
|--------------------------------------------------------------------------
| Only Accept POST
|--------------------------------------------------------------------------
*/

if (
    !isset($_SERVER['REQUEST_METHOD']) ||
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {
    header('Location: theme-settings.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function theme_redirect_error($message)
{
    header(
        'Location: theme-settings.php?error=' .
        rawurlencode((string)$message)
    );
    exit;
}

function theme_valid_gradient_angle($value)
{
    return preg_match(
        '/^-?\d+(?:\.\d+)?(?:deg|rad|turn)$/i',
        (string)$value
    ) === 1;
}

/*
|--------------------------------------------------------------------------
| Resolve Tenant / Company Context
|--------------------------------------------------------------------------
|
| Do not trust a posted company_id for tenant isolation.
| The authenticated session is authoritative.
|
| Existing theme_settings currently uses company_id, so tenant_id is mapped
| to company_id when an older company_id session key is not present.
|
*/

$companyId = 0;

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

if ($companyId <= 0) {
    theme_redirect_error(
        'Unable to determine the current business.'
    );
}

/*
|--------------------------------------------------------------------------
| Branch Context
|--------------------------------------------------------------------------
|
| Use the current authenticated branch.
| Branch ID 0 means business-wide/default theme.
|
*/

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
| Allowed Theme Fields
|--------------------------------------------------------------------------
*/

$allowed =
    array_keys($themeDefaults);

$data =
    array();

/*
|--------------------------------------------------------------------------
| HEX Color Fields
|--------------------------------------------------------------------------
*/

$colorKeys = array(

    'body_bg',
    'content_bg',
    'text_color',
    'muted_text_color',
    'border_color',

    'navbar_bg',
    'navbar_text',
    'navbar_icon',
    'navbar_hover_bg',
    'navbar_hover_text',
    'navbar_search_bg',

    'brand_bg',
    'brand_text',

    'sidebar_bg',
    'sidebar_text',
    'sidebar_icon',

    /*
     * sidebar_hover_bg is intentionally not here because your default value
     * is rgba(255,255,255,.60), not HEX.
     */
    'sidebar_hover_text',
    'sidebar_active_bg',
    'sidebar_active_text',
    'sidebar_active_border',
    'sidebar_separator',
    'sidebar_tooltip_bg',
    'sidebar_tooltip_text',

    'primary_color',
    'primary_hover',
    'primary_gradient_start',
    'primary_gradient_end',

    'success_color',
    'warning_color',
    'danger_color',

    'card_bg',
    'card_header_bg',
    'card_border',

    'button_bg',
    'button_text',
    'button_border',
    'button_hover_bg',
    'button_hover_text',

    'table_header_bg',
    'table_header_text',
    'table_row_bg',
    'table_alt_bg',
    'table_hover_bg',
    'table_border',

    'input_bg',
    'input_text',
    'input_border',
    'input_focus',

    'status_bg',
    'status_text',
    'status_dot',

    'profile_bg',
    'profile_text',

    'gradient_start',
    'gradient_middle',
    'gradient_end',

    'sidebar_gradient_start',
    'sidebar_gradient_middle',
    'sidebar_gradient_end',

    'topbar_gradient_start',
    'topbar_gradient_middle',
    'topbar_gradient_end',

    'button_gradient_start',
    'button_gradient_middle',
    'button_gradient_end'
);

/*
|--------------------------------------------------------------------------
| Gradient Validation
|--------------------------------------------------------------------------
*/

$gradientPrefixes =
    array(
        'sidebar',
        'topbar',
        'button'
    );

$allowedGradientTypes =
    array(
        'linear',
        'radial',
        'conic'
    );

$allowedGradientPositions =
    array(
        'center',
        'top',
        'bottom',
        'left',
        'right',
        'top left',
        'top right',
        'bottom left',
        'bottom right'
    );

foreach (
    $gradientPrefixes as $prefix
) {

    $_POST[
        $prefix .
        '_gradient_enabled'
    ] =
        isset(
            $_POST[
                $prefix .
                '_gradient_enabled'
            ]
        ) &&
        (string)$_POST[
            $prefix .
            '_gradient_enabled'
        ] === '1'
            ? '1'
            : '0';

    $type =
        strtolower(
            trim(
                (string)(
                    $_POST[
                        $prefix .
                        '_gradient_type'
                    ]
                    ?? 'linear'
                )
            )
        );

    if (
        !in_array(
            $type,
            $allowedGradientTypes,
            true
        )
    ) {
        theme_redirect_error(
            'Invalid gradient type for ' .
            $prefix .
            '.'
        );
    }

    $_POST[
        $prefix .
        '_gradient_type'
    ] = $type;

    $position =
        strtolower(
            trim(
                (string)(
                    $_POST[
                        $prefix .
                        '_gradient_position'
                    ]
                    ?? 'center'
                )
            )
        );

    if (
        !in_array(
            $position,
            $allowedGradientPositions,
            true
        )
    ) {
        theme_redirect_error(
            'Invalid gradient position for ' .
            $prefix .
            '.'
        );
    }

    $_POST[
        $prefix .
        '_gradient_position'
    ] = $position;

    $angle =
        trim(
            (string)(
                $_POST[
                    $prefix .
                    '_gradient_angle'
                ]
                ?? '135deg'
            )
        );

    if (
        !theme_valid_gradient_angle(
            $angle
        )
    ) {
        theme_redirect_error(
            'Invalid gradient angle for ' .
            $prefix .
            '. Example: 135deg.'
        );
    }

    $_POST[
        $prefix .
        '_gradient_angle'
    ] = $angle;
}

/*
|--------------------------------------------------------------------------
| Typography Toggle
|--------------------------------------------------------------------------
*/

$typographyEnabled =
    isset(
        $_POST['typography_enabled']
    ) &&
    (string)$_POST[
        'typography_enabled'
    ] === '1'
        ? '1'
        : '0';

/*
|--------------------------------------------------------------------------
| Validate / Normalize All Theme Values
|--------------------------------------------------------------------------
*/

foreach ($allowed as $key) {

    if (
        $key ===
        'typography_enabled'
    ) {
        $data[$key] =
            $typographyEnabled;

        continue;
    }

    $value =
        isset($_POST[$key])
            ? trim(
                (string)$_POST[$key]
            )
            : theme_value(
                $key,
                isset($themeDefaults[$key])
                    ? $themeDefaults[$key]
                    : ''
            );

    /*
     * Strict HEX validation for fields that must contain HEX.
     */
    if (
        in_array(
            $key,
            $colorKeys,
            true
        )
    ) {
        $upper =
            strtoupper($value);

        if (
            !preg_match(
                '/^#[0-9A-F]{6}$/',
                $upper
            )
        ) {
            theme_redirect_error(
                'Invalid color value for ' .
                $key .
                '. Use HEX format such as #08A5D2.'
            );
        }

        $value = $upper;
    }

    /*
     * Allow HEX or rgb()/rgba() for sidebar hover background.
     */
    if (
        $key ===
        'sidebar_hover_bg'
    ) {
        $validHex =
            preg_match(
                '/^#[0-9A-Fa-f]{6}$/',
                $value
            ) === 1;

        $validRgba =
            preg_match(
                '/^rgba?\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+(?:\s*,\s*[\d.]+)?\s*\)$/i',
                $value
            ) === 1;

        if (
            !$validHex &&
            !$validRgba
        ) {
            theme_redirect_error(
                'Invalid sidebar hover background color.'
            );
        }
    }

    if (
        $key ===
        'gradient_angle' &&
        !theme_valid_gradient_angle(
            $value
        )
    ) {
        theme_redirect_error(
            'Invalid gradient angle. Example: 180deg or 135deg.'
        );
    }

    $data[$key] = $value;
}

/*
|--------------------------------------------------------------------------
| Save Theme
|--------------------------------------------------------------------------
*/

try {

    if (
        !isset($pdo) ||
        !($pdo instanceof PDO)
    ) {
        throw new RuntimeException(
            'Database connection is unavailable.'
        );
    }

    /*
     * Verify theme_settings exists.
     */
    $tableCheck =
        $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'theme_settings'
        ");

    $tableCheck->execute();

    if (
        (int)$tableCheck->fetchColumn() <= 0
    ) {
        throw new RuntimeException(
            'Theme settings table does not exist.'
        );
    }

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | Find Existing Theme
    |--------------------------------------------------------------------------
    */

    if ($branchId === null) {

        $find =
            $pdo->prepare("
                SELECT id
                FROM theme_settings
                WHERE company_id = :company_id
                  AND branch_id IS NULL
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");

        $find->bindValue(
            ':company_id',
            $companyId,
            PDO::PARAM_INT
        );

    } else {

        $find =
            $pdo->prepare("
                SELECT id
                FROM theme_settings
                WHERE company_id = :company_id
                  AND branch_id = :branch_id
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");

        $find->bindValue(
            ':company_id',
            $companyId,
            PDO::PARAM_INT
        );

        $find->bindValue(
            ':branch_id',
            $branchId,
            PDO::PARAM_INT
        );
    }

    $find->execute();

    $themeId =
        (int)$find->fetchColumn();

    $columns =
        array_keys($data);

    /*
    |--------------------------------------------------------------------------
    | Update Existing Theme
    |--------------------------------------------------------------------------
    */

    if ($themeId > 0) {

        $assignments =
            array();

        foreach (
            $columns as $column
        ) {
            /*
             * Column names come only from $themeDefaults whitelist.
             */
            $assignments[] =
                '`' .
                str_replace(
                    '`',
                    '',
                    $column
                ) .
                '` = :' .
                $column;
        }

        $sql =
            'UPDATE theme_settings
             SET ' .
            implode(
                ', ',
                $assignments
            ) .
            ',
                 is_active = 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :theme_id';

        $stmt =
            $pdo->prepare($sql);

        foreach (
            $columns as $column
        ) {
            $stmt->bindValue(
                ':' . $column,
                (string)$data[$column],
                PDO::PARAM_STR
            );
        }

        $stmt->bindValue(
            ':theme_id',
            $themeId,
            PDO::PARAM_INT
        );

        $stmt->execute();

    /*
    |--------------------------------------------------------------------------
    | Insert New Theme
    |--------------------------------------------------------------------------
    */

    } else {

        $insertColumns =
            array_merge(
                array(
                    'company_id',
                    'branch_id',
                    'theme_name'
                ),
                $columns,
                array(
                    'is_active'
                )
            );

        $safeColumnNames =
            array();

        foreach (
            $insertColumns as $column
        ) {
            $safeColumnNames[] =
                '`' .
                str_replace(
                    '`',
                    '',
                    $column
                ) .
                '`';
        }

        $placeholders =
            array(
                ':company_id',
                ':branch_id',
                ':theme_name'
            );

        foreach (
            $columns as $column
        ) {
            $placeholders[] =
                ':' . $column;
        }

        $placeholders[] =
            ':is_active';

        $sql =
            'INSERT INTO theme_settings (' .
            implode(
                ', ',
                $safeColumnNames
            ) .
            ') VALUES (' .
            implode(
                ', ',
                $placeholders
            ) .
            ')';

        $stmt =
            $pdo->prepare($sql);

        $stmt->bindValue(
            ':company_id',
            $companyId,
            PDO::PARAM_INT
        );

        if ($branchId === null) {

            $stmt->bindValue(
                ':branch_id',
                null,
                PDO::PARAM_NULL
            );

        } else {

            $stmt->bindValue(
                ':branch_id',
                $branchId,
                PDO::PARAM_INT
            );
        }

        $stmt->bindValue(
            ':theme_name',
            'Default Theme',
            PDO::PARAM_STR
        );

        foreach (
            $columns as $column
        ) {
            $stmt->bindValue(
                ':' . $column,
                (string)$data[$column],
                PDO::PARAM_STR
            );
        }

        $stmt->bindValue(
            ':is_active',
            1,
            PDO::PARAM_INT
        );

        $stmt->execute();
    }

    $pdo->commit();

    header(
        'Location: theme-settings.php?success=1'
    );

    exit;

} catch (Throwable $e) {

    if (
        isset($pdo) &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    error_log(
        'FieldPlx theme settings save error: ' .
        $e->getMessage()
    );

    /*
     * Do not expose SQL/database details to the browser.
     */
    theme_redirect_error(
        'Unable to save theme settings. Please try again.'
    );
}
