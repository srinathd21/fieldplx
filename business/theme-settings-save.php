<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/theme-loader.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: theme-settings.php');
    exit;
}

function theme_redirect_error(string $message): void
{
    header('Location: theme-settings.php?error=' . urlencode($message));
    exit;
}

function theme_bind_params(mysqli_stmt $stmt, string $types, array &$values): void
{
    $params = [$types];

    foreach ($values as $index => &$value) {
        $params[] = &$value;
    }

    if (!call_user_func_array([$stmt, 'bind_param'], $params)) {
        throw new RuntimeException('Unable to bind theme settings parameters.');
    }
}

$companyId = max(1, (int)($_POST['company_id'] ?? ($_SESSION['company_id'] ?? 1)));
$branchIdRaw = trim((string)($_POST['branch_id'] ?? ''));
$branchId = $branchIdRaw === '' ? null : (int)$branchIdRaw;

$allowed = array_keys($themeDefaults);
$data = [];

$colorKeys = [
    'body_bg','content_bg','text_color','muted_text_color','border_color',
    'navbar_bg','navbar_text','navbar_icon','navbar_hover_bg','navbar_hover_text','navbar_search_bg',
    'brand_bg','brand_text',
    'sidebar_bg','sidebar_text','sidebar_icon','sidebar_hover_text','sidebar_active_bg',
    'sidebar_active_text','sidebar_active_border','sidebar_separator','sidebar_tooltip_bg','sidebar_tooltip_text',
    'primary_color','primary_hover','primary_gradient_start','primary_gradient_end',
    'success_color','warning_color','danger_color',
    'card_bg','card_header_bg','card_border',
    'button_bg','button_text','button_border','button_hover_bg','button_hover_text',
    'table_header_bg','table_header_text','table_row_bg','table_alt_bg','table_hover_bg','table_border',
    'input_bg','input_text','input_border','input_focus',
    'status_bg','status_text','status_dot',
    'profile_bg','profile_text',
    'gradient_start','gradient_middle','gradient_end','button_gradient_end','button_gradient_middle','button_gradient_start','topbar_gradient_end','topbar_gradient_middle','topbar_gradient_start','sidebar_gradient_end','sidebar_gradient_middle','sidebar_gradient_start'
];

$gradientPrefixes = ['sidebar','topbar','button'];
$allowedGradientTypes = ['linear','radial','conic'];
$allowedGradientPositions = ['center','top','bottom','left','right','top left','top right','bottom left','bottom right'];

foreach ($gradientPrefixes as $prefix) {
    $_POST[$prefix . '_gradient_enabled'] =
        isset($_POST[$prefix . '_gradient_enabled']) && (string)$_POST[$prefix . '_gradient_enabled'] === '1'
            ? '1'
            : '0';

    $type = strtolower(trim((string)($_POST[$prefix . '_gradient_type'] ?? 'linear')));
    if (!in_array($type, $allowedGradientTypes, true)) {
        theme_redirect_error('Invalid gradient type for ' . $prefix . '.');
    }
    $_POST[$prefix . '_gradient_type'] = $type;

    $position = strtolower(trim((string)($_POST[$prefix . '_gradient_position'] ?? 'center')));
    if (!in_array($position, $allowedGradientPositions, true)) {
        theme_redirect_error('Invalid gradient position for ' . $prefix . '.');
    }
    $_POST[$prefix . '_gradient_position'] = $position;

    $angle = trim((string)($_POST[$prefix . '_gradient_angle'] ?? '135deg'));
    if (!preg_match('/^-?\d+(?:\.\d+)?(?:deg|rad|turn)$/i', $angle)) {
        theme_redirect_error('Invalid gradient angle for ' . $prefix . '. Example: 135deg.');
    }
    $_POST[$prefix . '_gradient_angle'] = $angle;
}

$typographyEnabled = isset($_POST['typography_enabled']) && (string)$_POST['typography_enabled'] === '1' ? '1' : '0';

foreach ($allowed as $key) {
    if ($key === 'typography_enabled') {
        $data[$key] = $typographyEnabled;
        continue;
    }

    $value = isset($_POST[$key])
        ? trim((string)$_POST[$key])
        : theme_value($key, $themeDefaults[$key]);

    if (in_array($key, $colorKeys, true)) {
        $upper = strtoupper($value);
        if (!preg_match('/^#[0-9A-F]{6}$/', $upper)) {
            theme_redirect_error('Invalid color value for ' . $key . '. Use HEX format such as #08A5D2.');
        }
        $value = $upper;
    }

    if ($key === 'gradient_angle' && !preg_match('/^-?\d+(?:\.\d+)?(?:deg|rad|turn)$/i', $value)) {
        theme_redirect_error('Invalid gradient angle. Example: 180deg or 135deg.');
    }

    $data[$key] = $value;
}

mysqli_begin_transaction($conn);

try {
    if ($branchId === null) {
        $find = mysqli_prepare(
            $conn,
            'SELECT id FROM theme_settings
             WHERE company_id = ? AND branch_id IS NULL
             ORDER BY id DESC LIMIT 1'
        );

        if (!$find) {
            throw new RuntimeException(mysqli_error($conn));
        }

        mysqli_stmt_bind_param($find, 'i', $companyId);
    } else {
        $find = mysqli_prepare(
            $conn,
            'SELECT id FROM theme_settings
             WHERE company_id = ? AND branch_id = ?
             ORDER BY id DESC LIMIT 1'
        );

        if (!$find) {
            throw new RuntimeException(mysqli_error($conn));
        }

        mysqli_stmt_bind_param($find, 'ii', $companyId, $branchId);
    }

    if (!mysqli_stmt_execute($find)) {
        throw new RuntimeException(mysqli_stmt_error($find));
    }

    $result = mysqli_stmt_get_result($find);
    $row = mysqli_fetch_assoc($result);
    $themeId = (int)($row['id'] ?? 0);
    mysqli_stmt_close($find);

    $columns = array_keys($data);

    if ($themeId > 0) {
        $assignments = array_map(
            static fn(string $column): string => "`{$column}` = ?",
            $columns
        );

        $sql = 'UPDATE theme_settings
                SET ' . implode(', ', $assignments) . ',
                    is_active = 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?';

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            throw new RuntimeException(mysqli_error($conn));
        }

        $values = array_values($data);
        $values[] = $themeId;

        $types = str_repeat('s', count($columns)) . 'i';
        theme_bind_params($stmt, $types, $values);

        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException(mysqli_stmt_error($stmt));
        }

        mysqli_stmt_close($stmt);
    } else {
        $insertColumns = array_merge(
            ['company_id', 'branch_id', 'theme_name'],
            $columns,
            ['is_active']
        );

        $placeholders = implode(',', array_fill(0, count($insertColumns), '?'));

        $sql = 'INSERT INTO theme_settings (`' .
            implode('`,`', $insertColumns) .
            '`) VALUES (' . $placeholders . ')';

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            throw new RuntimeException(mysqli_error($conn));
        }

        $values = [$companyId, $branchId, 'Default Theme'];

        foreach ($columns as $column) {
            $values[] = $data[$column];
        }

        $values[] = 1;

        $types = 'iis' . str_repeat('s', count($columns)) . 'i';
        theme_bind_params($stmt, $types, $values);

        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException(mysqli_stmt_error($stmt));
        }

        mysqli_stmt_close($stmt);
    }

    mysqli_commit($conn);

    header('Location: theme-settings.php?success=1');
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    theme_redirect_error($e->getMessage());
}
