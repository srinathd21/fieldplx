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
    $stmt = $pdo->prepare("SELECT * FROM modules WHERE id = :id LIMIT 1");
    $stmt->execute(array(':id' => $id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        sm_json(404, false, 'Module not found.');
    }
    return $row;
}

function sm_ids_from_post(): array
{
    $raw = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : array();
    $ids = array();
    foreach ($raw as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function sm_placeholders(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

function sm_validate_delete_set(PDO $pdo, array $ids): void
{
    if (!$ids) {
        sm_json(422, false, 'Select at least one module.');
    }

    $ph = sm_placeholders($ids);

    $stmt = $pdo->prepare("SELECT id, module_name, is_core FROM modules WHERE id IN ($ph)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) !== count($ids)) {
        sm_json(404, false, 'One or more selected modules were not found. Refresh the page and try again.');
    }

    $coreNames = array();
    foreach ($rows as $row) {
        if ((int)$row['is_core'] === 1) {
            $coreNames[] = (string)$row['module_name'];
        }
    }

    if ($coreNames) {
        sm_json(422, false, 'Core modules cannot be deleted: ' . implode(', ', $coreNames) . '.');
    }

    /*
     * Parent modules use ON DELETE SET NULL in the current schema. To avoid
     * silently turning child modules into top-level modules, block deletion
     * when a child exists outside the selected delete set.
     */
    $params = array_merge($ids, $ids);
    $stmt = $pdo->prepare("
        SELECT c.module_name AS child_name, p.module_name AS parent_name
        FROM modules c
        INNER JOIN modules p ON p.id = c.parent_id
        WHERE c.parent_id IN ($ph)
          AND c.id NOT IN ($ph)
        ORDER BY p.module_name, c.menu_order, c.module_name
        LIMIT 1
    ");
    $stmt->execute($params);
    $child = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($child) {
        sm_json(
            422,
            false,
            'Cannot delete parent module "' . $child['parent_name'] . '" while child module "' . $child['child_name'] . '" remains. Select the child too, move it to another parent, or delete the child first.'
        );
    }
}

function sm_delete_modules(PDO $pdo, array $ids): int
{
    sm_validate_delete_set($pdo, $ids);
    $ph = sm_placeholders($ids);

    $pdo->beginTransaction();
    try {
        /* Delete children first, then parents, so hierarchy stays deterministic. */
        $stmt = $pdo->prepare("DELETE FROM modules WHERE id IN ($ph) AND parent_id IS NOT NULL");
        $stmt->execute($ids);
        $deleted = $stmt->rowCount();

        $stmt = $pdo->prepare("DELETE FROM modules WHERE id IN ($ph)");
        $stmt->execute($ids);
        $deleted += $stmt->rowCount();

        $pdo->commit();
        return $deleted;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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

        $isSidebarItem = isset($_POST['is_sidebar_item']) && $_POST['is_sidebar_item'] === '1' ? 1 : 0;

        if ($moduleName === '') {
            sm_json(422, false, 'Module name is required.');
        }

        if ($moduleCode === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $moduleCode)) {
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

        $duplicate = $pdo->prepare("SELECT id FROM modules WHERE module_code = :module_code AND id <> :id LIMIT 1");
        $duplicate->execute(array(':module_code' => $moduleCode, ':id' => $id));
        if ($duplicate->fetchColumn()) {
            sm_json(409, false, 'Module code already exists.');
        }

        if ($id > 0) {
            sm_find_module($pdo, $id);
            $stmt = $pdo->prepare("
                UPDATE modules
                SET parent_id = :parent_id,
                    module_code = :module_code,
                    module_name = :module_name,
                    description = :description,
                    menu_url = :menu_url,
                    icon_name = :icon_name,
                    menu_order = :menu_order,
                    is_sidebar_item = :is_sidebar_item
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
                ':id' => $id
            ));
            sm_json(200, true, 'Sidebar module updated successfully.');
        }

        $createdBy = isset($_SESSION['platform_user_id']) && (int)$_SESSION['platform_user_id'] > 0
            ? (int)$_SESSION['platform_user_id']
            : null;

        $stmt = $pdo->prepare("
            INSERT INTO modules (
                parent_id, module_code, module_name, description, menu_url,
                icon_library_id, icon_name, menu_order, is_core,
                is_sidebar_item, is_active, created_by
            ) VALUES (
                :parent_id, :module_code, :module_name, :description, :menu_url,
                NULL, :icon_name, :menu_order, 0,
                :is_sidebar_item, 1, :created_by
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
            ':created_by' => $createdBy
        ));

        sm_json(201, true, 'Sidebar module created successfully.', array('module_id' => (int)$pdo->lastInsertId()));
    }

    if ($action === 'toggle_sidebar') {
        $id = (int)sm_post('id','0');
        $isSidebarItem = sm_post('is_sidebar_item','0') === '1' ? 1 : 0;
        if ($id <= 0) {
            sm_json(422, false, 'Invalid module.');
        }
        sm_find_module($pdo, $id);
        $stmt = $pdo->prepare("UPDATE modules SET is_sidebar_item = :is_sidebar_item WHERE id = :id");
        $stmt->execute(array(':is_sidebar_item' => $isSidebarItem, ':id' => $id));
        sm_json(200, true, $isSidebarItem ? 'Module added to sidebar.' : 'Module hidden from sidebar.');
    }

    if ($action === 'delete_module') {
        $id = (int)sm_post('id','0');
        if ($id <= 0) {
            sm_json(422, false, 'Invalid module.');
        }
        $deleted = sm_delete_modules($pdo, array($id));
        sm_json(200, true, $deleted === 1 ? 'Module deleted successfully.' : 'Module deleted successfully.', array('deleted' => $deleted));
    }

    if ($action === 'bulk_action') {
        $bulkAction = sm_post('bulk_action');
        $ids = sm_ids_from_post();
        if (!$ids) {
            sm_json(422, false, 'Select at least one module.');
        }

        if ($bulkAction === 'show' || $bulkAction === 'hide') {
            $ph = sm_placeholders($ids);
            $value = $bulkAction === 'show' ? 1 : 0;
            $params = array_merge(array($value), $ids);
            $stmt = $pdo->prepare("UPDATE modules SET is_sidebar_item = ? WHERE id IN ($ph)");
            $stmt->execute($params);
            sm_json(
                200,
                true,
                count($ids) . ' module(s) ' . ($value ? 'shown in sidebar' : 'hidden from sidebar') . ' successfully.',
                array('updated' => $stmt->rowCount())
            );
        }

        if ($bulkAction === 'delete') {
            $deleted = sm_delete_modules($pdo, $ids);
            sm_json(200, true, $deleted . ' module(s) deleted successfully.', array('deleted' => $deleted));
        }

        sm_json(422, false, 'Invalid bulk action.');
    }

    sm_json(400, false, 'Invalid action.');

} catch (PDOException $e) {
    error_log('FieldPlx Sidebar Modules PDO Error: ' . $e->getMessage());
    $driverCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
    if ($driverCode === 1451) {
        sm_json(409, false, 'This module is still linked to protected records. Disable it instead, or remove those links before deleting.');
    }
    sm_json(500, false, 'Unable to complete the sidebar module action.');
} catch (Throwable $e) {
    error_log('FieldPlx Sidebar Modules API Error: ' . $e->getMessage());
    sm_json(500, false, 'Unable to complete the sidebar module action.');
}
