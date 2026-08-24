<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sm_post(string $key, string $default = ''): string
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }

    return trim((string)$_POST[$key]);
}

function sm_json(int $status, bool $success, string $message, array $extra = array()): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        array_merge(
            array(
                'success' => $success,
                'message' => $message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function sm_find_module(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM modules
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute(array(':id' => $id));

    $row = $stmt->fetch();

    if (!$row) {
        sm_json(404, false, 'Module not found.');
    }

    return $row;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sm_json(405, false, 'Method not allowed.');
}

$csrf = sm_post('csrf_token');

if (
    empty($_SESSION['sidebar_modules_csrf']) ||
    !is_string($_SESSION['sidebar_modules_csrf']) ||
    $csrf === '' ||
    !hash_equals($_SESSION['sidebar_modules_csrf'], $csrf)
) {
    sm_json(419, false, 'Your form session expired. Refresh the page and try again.');
}

$action = sm_post('action');

try {

    if ($action === 'save_module') {
        $id = (int)sm_post('id','0');

        $moduleName = sm_post('module_name');
        $moduleCode = strtolower(sm_post('module_code'));
        $parentId = (int)sm_post('parent_id','0');
        $menuUrl = sm_post('menu_url');
        $iconName = sm_post('icon_name');
        $menuOrder = (int)sm_post('menu_order','0');
        $description = sm_post('description');

        $isSidebarItem =
            isset($_POST['is_sidebar_item']) &&
            $_POST['is_sidebar_item'] === '1'
                ? 1
                : 0;

        $isActive =
            isset($_POST['is_active']) &&
            $_POST['is_active'] === '1'
                ? 1
                : 0;

        if ($moduleName === '') {
            sm_json(422, false, 'Module name is required.');
        }

        if (
            $moduleCode === '' ||
            !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $moduleCode)
        ) {
            sm_json(422, false, 'Module code may contain lowercase letters, numbers, hyphens and underscores only.');
        }

        if ($menuOrder < 0) {
            sm_json(422, false, 'Menu order cannot be negative.');
        }

        if ($parentId > 0 && $parentId === $id) {
            sm_json(422, false, 'A module cannot be its own parent.');
        }

        if ($parentId > 0) {
            $parent = sm_find_module($pdo, $parentId);

            if ($parent['parent_id'] !== null && $parent['parent_id'] !== '') {
                sm_json(422, false, 'Only a top-level module can be selected as parent.');
            }
        }

        $duplicate = $pdo->prepare("
            SELECT id
            FROM modules
            WHERE module_code = :module_code
              AND id <> :id
            LIMIT 1
        ");

        $duplicate->execute(array(
            ':module_code' => $moduleCode,
            ':id' => $id
        ));

        if ($duplicate->fetchColumn()) {
            sm_json(409, false, 'Module code already exists.');
        }

        if ($id > 0) {
            sm_find_module($pdo, $id);

            $stmt = $pdo->prepare("
                UPDATE modules
                SET
                    parent_id = :parent_id,
                    module_code = :module_code,
                    module_name = :module_name,
                    description = :description,
                    menu_url = :menu_url,
                    icon_name = :icon_name,
                    menu_order = :menu_order,
                    is_sidebar_item = :is_sidebar_item,
                    is_active = :is_active
                WHERE id = :id
            ");

            $stmt->execute(array(
                ':parent_id' => $parentId > 0 ? $parentId : null,
                ':module_code' => $moduleCode,
                ':module_name' => $moduleName,
                ':description' => $description === '' ? null : $description,
                ':menu_url' => $menuUrl === '' ? null : $menuUrl,
                ':icon_name' => $iconName === '' ? null : $iconName,
                ':menu_order' => $menuOrder,
                ':is_sidebar_item' => $isSidebarItem,
                ':is_active' => $isActive,
                ':id' => $id
            ));

            sm_json(200, true, 'Sidebar module updated successfully.');
        }

        $createdBy = null;

        if (
            isset($_SESSION['platform_user_id']) &&
            (int)$_SESSION['platform_user_id'] > 0
        ) {
            $createdBy = (int)$_SESSION['platform_user_id'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO modules (
                parent_id,
                module_code,
                module_name,
                description,
                menu_url,
                icon_library_id,
                icon_name,
                menu_order,
                is_core,
                is_sidebar_item,
                is_active,
                created_by
            ) VALUES (
                :parent_id,
                :module_code,
                :module_name,
                :description,
                :menu_url,
                NULL,
                :icon_name,
                :menu_order,
                0,
                :is_sidebar_item,
                :is_active,
                :created_by
            )
        ");

        $stmt->execute(array(
            ':parent_id' => $parentId > 0 ? $parentId : null,
            ':module_code' => $moduleCode,
            ':module_name' => $moduleName,
            ':description' => $description === '' ? null : $description,
            ':menu_url' => $menuUrl === '' ? null : $menuUrl,
            ':icon_name' => $iconName === '' ? null : $iconName,
            ':menu_order' => $menuOrder,
            ':is_sidebar_item' => $isSidebarItem,
            ':is_active' => $isActive,
            ':created_by' => $createdBy
        ));

        sm_json(
            201,
            true,
            'Sidebar module created successfully.',
            array('module_id' => (int)$pdo->lastInsertId())
        );
    }

    if ($action === 'toggle_status') {
        $id = (int)sm_post('id','0');
        $isActive = sm_post('is_active','0') === '1' ? 1 : 0;

        if ($id <= 0) {
            sm_json(422, false, 'Invalid module.');
        }

        sm_find_module($pdo, $id);

        $stmt = $pdo->prepare("
            UPDATE modules
            SET is_active = :is_active
            WHERE id = :id
        ");

        $stmt->execute(array(
            ':is_active' => $isActive,
            ':id' => $id
        ));

        sm_json(
            200,
            true,
            $isActive
                ? 'Module activated successfully.'
                : 'Module deactivated successfully.'
        );
    }

    if ($action === 'toggle_sidebar') {
        $id = (int)sm_post('id','0');
        $isSidebarItem = sm_post('is_sidebar_item','0') === '1' ? 1 : 0;

        if ($id <= 0) {
            sm_json(422, false, 'Invalid module.');
        }

        sm_find_module($pdo, $id);

        $stmt = $pdo->prepare("
            UPDATE modules
            SET is_sidebar_item = :is_sidebar_item
            WHERE id = :id
        ");

        $stmt->execute(array(
            ':is_sidebar_item' => $isSidebarItem,
            ':id' => $id
        ));

        sm_json(
            200,
            true,
            $isSidebarItem
                ? 'Module added to sidebar.'
                : 'Module hidden from sidebar.'
        );
    }

    sm_json(400, false, 'Invalid action.');

} catch (Throwable $e) {
    error_log(
        'FieldPlx Sidebar Modules API Error: ' .
        $e->getMessage()
    );

    sm_json(
        500,
        false,
        'Unable to complete the sidebar module action.'
    );
}
