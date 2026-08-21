<?php
/**
 * FieldPlx - Tasks
 * Upload as /public_html/tasks.php
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    header('Location: login.php?redirect=' . rawurlencode('tasks.php'));
    exit;
}

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$canViewTasks = true;
$canManageTasks = true;

if (function_exists('hasPermission')) {
    $canViewTasks = hasPermission('tasks.view') || hasPermission('jobs.view');
    $canManageTasks = hasPermission('tasks.manage') || hasPermission('jobs.manage');
}

if (!$canViewTasks) {
    http_response_code(403);
    exit('403 - Access Denied. You do not have permission to view tasks.');
}

$pageTitle = 'Tasks - FieldPlx';
$activePage = 'tasks';
$searchPlaceholder = 'Search tasks...';
$basePath = '';
$errors = array();

if (!function_exists('tasksFetchAssoc')) {
    function tasksFetchAssoc(mysqli_stmt $stmt)
    {
        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if ($result) {
                return $result->fetch_assoc();
            }
        }

        $metadata = $stmt->result_metadata();
        if (!$metadata) {
            return null;
        }

        $row = array();
        $bind = array();
        while ($field = $metadata->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(array($stmt, 'bind_result'), $bind);
        if (!$stmt->fetch()) {
            return null;
        }

        $copy = array();
        foreach ($row as $key => $value) {
            $copy[$key] = $value;
        }
        return $copy;
    }
}

if (!function_exists('tasksFetchAll')) {
    function tasksFetchAll(mysqli_stmt $stmt)
    {
        $rows = array();

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
                return $rows;
            }
        }

        $metadata = $stmt->result_metadata();
        if (!$metadata) {
            return $rows;
        }

        $row = array();
        $bind = array();
        while ($field = $metadata->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(array($stmt, 'bind_result'), $bind);
        while ($stmt->fetch()) {
            $copy = array();
            foreach ($row as $key => $value) {
                $copy[$key] = $value;
            }
            $rows[] = $copy;
        }

        return $rows;
    }
}

if (!function_exists('tasksBindParams')) {
    function tasksBindParams(mysqli_stmt $stmt, $types, array &$params)
    {
        if ($types === '' || empty($params)) {
            return true;
        }

        $arguments = array($types);
        foreach ($params as $key => $value) {
            $arguments[] = &$params[$key];
        }

        return call_user_func_array(array($stmt, 'bind_param'), $arguments);
    }
}

if (!function_exists('tasksPost')) {
    function tasksPost($key, $default = '')
    {
        return isset($_POST[$key]) && !is_array($_POST[$key])
            ? trim((string) $_POST[$key])
            : $default;
    }
}

if (!function_exists('tasksNullable')) {
    function tasksNullable($value)
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}

if (!function_exists('tasksNormalizeDateTime')) {
    function tasksNormalizeDateTime($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}

if (!function_exists('tasksDateTime')) {
    function tasksDateTime($value)
    {
        if (empty($value)) {
            return 'No due date';
        }

        $timestamp = strtotime((string) $value);
        return $timestamp ? date('d M Y, h:i A', $timestamp) : 'No due date';
    }
}

if (!function_exists('tasksDate')) {
    function tasksDate($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);
        return $timestamp ? date('d M Y', $timestamp) : '—';
    }
}

if (!function_exists('tasksLabel')) {
    function tasksLabel($value)
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }
}

if (!function_exists('tasksCss')) {
    function tasksCss($value)
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value));
    }
}

if (!function_exists('tasksCsrfToken')) {
    function tasksCsrfToken()
    {
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Throwable $error) {
                $_SESSION['csrf_token'] = sha1(uniqid((string) mt_rand(), true));
            }
        }
        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('tasksVerifyCsrf')) {
    function tasksVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token'])
            && is_string($token)
            && hash_equals((string) $_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('tasksQueryString')) {
    function tasksQueryString(array $overrides = array())
    {
        $query = $_GET;
        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }
        return http_build_query($query);
    }
}

if (!function_exists('tasksPriorityColor')) {
    function tasksPriorityColor($priority)
    {
        $colors = array(
            'low' => '#64748b',
            'normal' => '#2563eb',
            'high' => '#ea580c',
            'urgent' => '#dc2626'
        );
        return isset($colors[$priority]) ? $colors[$priority] : '#2563eb';
    }
}

if (!function_exists('tasksRelatedUrl')) {
    function tasksRelatedUrl($type, $id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return '';
        }

        $urls = array(
            'job' => 'job-view.php?id=',
            'visit' => 'visit-view.php?id=',
            'work_order' => 'work-order-view.php?id=',
            'request' => 'request-view.php?id=',
            'quote' => 'quote-view.php?id=',
            'invoice' => 'invoice-view.php?id=',
            'property' => 'property-view.php?id=',
            'route_plan' => 'route-view.php?id=',
            'booking' => 'booking-view.php?id='
        );

        return isset($urls[$type]) ? $urls[$type] . $id : '';
    }
}

if (!function_exists('tasksLoadTask')) {
    function tasksLoadTask(mysqli $conn, $tenantId, $taskId, $lock = false)
    {
        $sql = 'SELECT * FROM tasks WHERE id = ? AND tenant_id = ? LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('ii', $taskId, $tenantId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $task = tasksFetchAssoc($stmt);
        $stmt->close();
        return $task;
    }
}

if (!function_exists('tasksValidateUser')) {
    function tasksValidateUser(mysqli $conn, $tenantId, $userId)
    {
        if ($userId === null) {
            return true;
        }

        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND tenant_id = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ii', $userId, $tenantId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('tasksValidateClient')) {
    function tasksValidateClient(mysqli $conn, $tenantId, $clientId)
    {
        if ($clientId === null) {
            return true;
        }

        $stmt = $conn->prepare('SELECT id FROM clients WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1');
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ii', $clientId, $tenantId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('tasksValidateRelated')) {
    function tasksValidateRelated(mysqli $conn, $tenantId, $type, $id)
    {
        if ($type === '' && $id === null) {
            return true;
        }

        $map = array(
            'job' => array('jobs', 'deleted_at'),
            'visit' => array('visits', ''),
            'work_order' => array('work_orders', 'deleted_at'),
            'request' => array('requests', 'archived_at'),
            'quote' => array('quotes', 'archived_at'),
            'invoice' => array('invoices', 'archived_at'),
            'property' => array('properties', 'deleted_at'),
            'route_plan' => array('route_plans', ''),
            'booking' => array('bookings', '')
        );

        if (!isset($map[$type]) || $id === null || $id <= 0) {
            return false;
        }

        $table = $map[$type][0];
        $deletedColumn = $map[$type][1];
        $sql = "SELECT id FROM `{$table}` WHERE id = ? AND tenant_id = ?";
        if ($deletedColumn !== '') {
            $sql .= " AND `{$deletedColumn}` IS NULL";
        }
        $sql .= ' LIMIT 1';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ii', $id, $tenantId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('tasksSyncCalendar')) {
    function tasksSyncCalendar(mysqli $conn, array $task)
    {
        $tenantId = (int) $task['tenant_id'];
        $taskId = (int) $task['id'];
        $dueAt = !empty($task['due_at']) ? (string) $task['due_at'] : null;

        if ($dueAt === null) {
            $stmt = $conn->prepare("DELETE FROM schedule_events WHERE tenant_id = ? AND event_type = 'task' AND related_type = 'task' AND related_id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $tenantId, $taskId);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        $startAt = date('Y-m-d H:i:s', strtotime($dueAt . ' -30 minutes'));
        $endAt = $dueAt;
        $calendarStatus = 'scheduled';
        if ($task['status'] === 'completed') {
            $calendarStatus = 'completed';
        } elseif ($task['status'] === 'cancelled') {
            $calendarStatus = 'cancelled';
        } elseif ($task['status'] === 'overdue') {
            $calendarStatus = 'missed';
        }

        $description = tasksNullable($task['description']);
        $assignedUserId = !empty($task['assigned_user_id']) ? (int) $task['assigned_user_id'] : null;
        $clientId = !empty($task['client_id']) ? (int) $task['client_id'] : null;
        $createdBy = !empty($task['created_by']) ? (int) $task['created_by'] : null;
        $color = tasksPriorityColor($task['priority']);

        $stmt = $conn->prepare("SELECT id FROM schedule_events WHERE tenant_id = ? AND event_type = 'task' AND related_type = 'task' AND related_id = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Unable to check the linked calendar event.');
        }
        $stmt->bind_param('ii', $tenantId, $taskId);
        $stmt->execute();
        $existing = tasksFetchAssoc($stmt);
        $stmt->close();

        if ($existing) {
            $eventId = (int) $existing['id'];
            $stmt = $conn->prepare("UPDATE schedule_events SET title = ?, description = ?, assigned_user_id = ?, client_id = ?, start_at = ?, end_at = ?, all_day = 0, status = ?, color = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ? LIMIT 1");
            if (!$stmt) {
                throw new Exception('Unable to prepare calendar synchronization.');
            }
            $stmt->bind_param('ssiissssii', $task['title'], $description, $assignedUserId, $clientId, $startAt, $endAt, $calendarStatus, $color, $eventId, $tenantId);
            if (!$stmt->execute()) {
                throw new Exception('Unable to update the task calendar event: ' . $stmt->error);
            }
            $stmt->close();
            return;
        }

        $stmt = $conn->prepare("INSERT INTO schedule_events (tenant_id, event_type, related_type, related_id, title, description, assigned_user_id, client_id, property_id, start_at, end_at, all_day, status, color, created_by, created_at, updated_at) VALUES (?, 'task', 'task', ?, ?, ?, ?, ?, NULL, ?, ?, 0, ?, ?, ?, NOW(), NOW())");
        if (!$stmt) {
            throw new Exception('Unable to prepare calendar event creation.');
        }
        $stmt->bind_param('iissiissssi', $tenantId, $taskId, $task['title'], $description, $assignedUserId, $clientId, $startAt, $endAt, $calendarStatus, $color, $createdBy);
        if (!$stmt->execute()) {
            throw new Exception('Unable to create the task calendar event: ' . $stmt->error);
        }
        $stmt->close();
    }
}

if (!function_exists('tasksLogActivity')) {
    function tasksLogActivity(mysqli $conn, $tenantId, $userId, $taskId, $clientId, $eventType, $title, array $details)
    {
        $stmt = $conn->prepare("INSERT INTO activity_events (tenant_id, actor_user_id, actor_type, event_type, related_type, related_id, client_id, title, details_json, visible_to_client, created_at) VALUES (?, ?, 'user', ?, 'task', ?, ?, ?, ?, 0, NOW())");
        if (!$stmt) {
            return;
        }

        $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt->bind_param('iisiiss', $tenantId, $userId, $eventType, $taskId, $clientId, $title, $detailsJson);
        $stmt->execute();
        $stmt->close();
    }
}

/* Automatically keep open/in-progress past-due tasks marked overdue. */
$stmt = $conn->prepare("UPDATE tasks SET status = 'overdue', updated_at = NOW() WHERE tenant_id = ? AND due_at IS NOT NULL AND due_at < NOW() AND status IN ('open', 'in_progress')");
if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageTasks) {
        $errors[] = 'You do not have permission to manage tasks.';
    }

    if (!tasksVerifyCsrf(tasksPost('csrf_token'))) {
        $errors[] = 'Your session token is invalid. Please refresh and try again.';
    }

    $action = tasksPost('action');

    if (empty($errors) && in_array($action, array('create_task', 'update_task'), true)) {
        $taskId = $action === 'update_task' ? (int) tasksPost('task_id') : 0;
        $title = tasksPost('title');
        $description = tasksPost('description');
        $assignedUserId = (int) tasksPost('assigned_user_id') > 0 ? (int) tasksPost('assigned_user_id') : null;
        $clientId = (int) tasksPost('client_id') > 0 ? (int) tasksPost('client_id') : null;
        $relatedType = tasksPost('related_type');
        $relatedId = (int) tasksPost('related_id') > 0 ? (int) tasksPost('related_id') : null;
        $dueInput = tasksPost('due_at');
        $dueAt = tasksNormalizeDateTime($dueInput);
        $status = tasksPost('status', 'open');
        $priority = tasksPost('priority', 'normal');

        $allowedStatuses = array('open', 'in_progress', 'completed', 'cancelled', 'overdue');
        $allowedPriorities = array('low', 'normal', 'high', 'urgent');
        $allowedRelatedTypes = array('', 'job', 'visit', 'work_order', 'request', 'quote', 'invoice', 'property', 'route_plan', 'booking');

        if ($title === '') {
            $errors[] = 'Task title is required.';
        } elseif (strlen($title) > 190) {
            $errors[] = 'Task title cannot exceed 190 characters.';
        }

        if ($dueInput !== '' && $dueAt === null) {
            $errors[] = 'Please enter a valid due date and time.';
        }

        if (!in_array($status, $allowedStatuses, true)) {
            $errors[] = 'Please select a valid task status.';
        }

        if (!in_array($priority, $allowedPriorities, true)) {
            $errors[] = 'Please select a valid task priority.';
        }

        if (!in_array($relatedType, $allowedRelatedTypes, true)) {
            $errors[] = 'Please select a valid related record type.';
        }

        if (($relatedType === '' && $relatedId !== null) || ($relatedType !== '' && $relatedId === null)) {
            $errors[] = 'Select both the related record type and related record.';
        }

        if (!tasksValidateUser($conn, $tenantId, $assignedUserId)) {
            $errors[] = 'The selected assigned user is not available.';
        }

        if (!tasksValidateClient($conn, $tenantId, $clientId)) {
            $errors[] = 'The selected client is not available.';
        }

        if (!tasksValidateRelated($conn, $tenantId, $relatedType, $relatedId)) {
            $errors[] = 'The selected related record is not available.';
        }

        if ($action === 'update_task' && $taskId <= 0) {
            $errors[] = 'Invalid task selected.';
        }

        if ($dueAt !== null && strtotime($dueAt) < time() && in_array($status, array('open', 'in_progress'), true)) {
            $status = 'overdue';
        }

        $completedAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;

        if (empty($errors)) {
            try {
                $conn->begin_transaction();

                $descriptionValue = tasksNullable($description);
                $relatedTypeValue = tasksNullable($relatedType);

                if ($action === 'create_task') {
                    $stmt = $conn->prepare("INSERT INTO tasks (tenant_id, title, description, assigned_user_id, client_id, related_type, related_id, due_at, status, priority, created_by, completed_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    if (!$stmt) {
                        throw new Exception('Unable to prepare task creation: ' . $conn->error);
                    }
                    $stmt->bind_param('issiisisssis', $tenantId, $title, $descriptionValue, $assignedUserId, $clientId, $relatedTypeValue, $relatedId, $dueAt, $status, $priority, $currentUserId, $completedAt);
                    if (!$stmt->execute()) {
                        throw new Exception('Task could not be created: ' . $stmt->error);
                    }
                    $taskId = (int) $stmt->insert_id;
                    $stmt->close();
                    $eventType = 'task_created';
                    $activityTitle = 'Task created: ' . $title;
                } else {
                    $existingTask = tasksLoadTask($conn, $tenantId, $taskId, true);
                    if (!$existingTask) {
                        throw new Exception('Task not found or access denied.');
                    }

                    $stmt = $conn->prepare("UPDATE tasks SET title = ?, description = ?, assigned_user_id = ?, client_id = ?, related_type = ?, related_id = ?, due_at = ?, status = ?, priority = ?, completed_at = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ? LIMIT 1");
                    if (!$stmt) {
                        throw new Exception('Unable to prepare task update: ' . $conn->error);
                    }
                    $stmt->bind_param('ssiisissssii', $title, $descriptionValue, $assignedUserId, $clientId, $relatedTypeValue, $relatedId, $dueAt, $status, $priority, $completedAt, $taskId, $tenantId);
                    if (!$stmt->execute()) {
                        throw new Exception('Task could not be updated: ' . $stmt->error);
                    }
                    $stmt->close();
                    $eventType = 'task_updated';
                    $activityTitle = 'Task updated: ' . $title;
                }

                $savedTask = tasksLoadTask($conn, $tenantId, $taskId);
                if (!$savedTask) {
                    throw new Exception('Unable to reload the saved task.');
                }

                tasksSyncCalendar($conn, $savedTask);
                $conn->commit();

                tasksLogActivity($conn, $tenantId, $currentUserId, $taskId, $clientId, $eventType, $activityTitle, array(
                    'task_id' => $taskId,
                    'title' => $title,
                    'status' => $status,
                    'priority' => $priority,
                    'assigned_user_id' => $assignedUserId,
                    'client_id' => $clientId,
                    'related_type' => $relatedTypeValue,
                    'related_id' => $relatedId,
                    'due_at' => $dueAt
                ));

                $_SESSION['flash_success'] = $action === 'create_task'
                    ? 'Task created successfully.'
                    : 'Task updated successfully.';

                header('Location: tasks.php');
                exit;
            } catch (Throwable $error) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                }
                $errors[] = $error->getMessage();
            }
        }
    }

    if (empty($errors) && $action === 'update_status') {
        $taskId = (int) tasksPost('task_id');
        $newStatus = tasksPost('new_status');

        if ($taskId <= 0 || !in_array($newStatus, array('open', 'in_progress', 'completed', 'cancelled'), true)) {
            $errors[] = 'Invalid task status action.';
        }

        if (empty($errors)) {
            try {
                $conn->begin_transaction();
                $task = tasksLoadTask($conn, $tenantId, $taskId, true);
                if (!$task) {
                    throw new Exception('Task not found or access denied.');
                }

                $oldStatus = (string) $task['status'];
                if ($newStatus !== 'completed' && !empty($task['due_at']) && strtotime($task['due_at']) < time() && in_array($newStatus, array('open', 'in_progress'), true)) {
                    $newStatus = 'overdue';
                }

                $completedAt = $newStatus === 'completed' ? date('Y-m-d H:i:s') : null;
                $stmt = $conn->prepare("UPDATE tasks SET status = ?, completed_at = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ? LIMIT 1");
                if (!$stmt) {
                    throw new Exception('Unable to prepare task status update.');
                }
                $stmt->bind_param('ssii', $newStatus, $completedAt, $taskId, $tenantId);
                if (!$stmt->execute()) {
                    throw new Exception('Task status could not be updated: ' . $stmt->error);
                }
                $stmt->close();

                $updatedTask = tasksLoadTask($conn, $tenantId, $taskId);
                tasksSyncCalendar($conn, $updatedTask);
                $conn->commit();

                tasksLogActivity($conn, $tenantId, $currentUserId, $taskId, !empty($task['client_id']) ? (int) $task['client_id'] : null, 'task_status_updated', 'Task status changed: ' . $task['title'], array(
                    'task_id' => $taskId,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus
                ));

                $_SESSION['flash_success'] = 'Task status updated successfully.';
                $returnQuery = tasksPost('return_query');
                header('Location: tasks.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
                exit;
            } catch (Throwable $error) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                }
                $errors[] = $error->getMessage();
            }
        }
    }

    if (empty($errors) && $action === 'delete_task') {
        $taskId = (int) tasksPost('task_id');
        if ($taskId <= 0) {
            $errors[] = 'Invalid task selected.';
        }

        if (empty($errors)) {
            try {
                $conn->begin_transaction();
                $task = tasksLoadTask($conn, $tenantId, $taskId, true);
                if (!$task) {
                    throw new Exception('Task not found or access denied.');
                }

                $stmt = $conn->prepare("DELETE FROM schedule_events WHERE tenant_id = ? AND event_type = 'task' AND related_type = 'task' AND related_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $tenantId, $taskId);
                    $stmt->execute();
                    $stmt->close();
                }

                $stmt = $conn->prepare('DELETE FROM tasks WHERE id = ? AND tenant_id = ? LIMIT 1');
                if (!$stmt) {
                    throw new Exception('Unable to prepare task deletion.');
                }
                $stmt->bind_param('ii', $taskId, $tenantId);
                if (!$stmt->execute()) {
                    throw new Exception('Task could not be deleted: ' . $stmt->error);
                }
                $stmt->close();
                $conn->commit();

                tasksLogActivity($conn, $tenantId, $currentUserId, $taskId, !empty($task['client_id']) ? (int) $task['client_id'] : null, 'task_deleted', 'Task deleted: ' . $task['title'], array(
                    'task_id' => $taskId,
                    'title' => $task['title']
                ));

                $_SESSION['flash_success'] = 'Task deleted successfully.';
                header('Location: tasks.php');
                exit;
            } catch (Throwable $error) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                }
                $errors[] = $error->getMessage();
            }
        }
    }
}

$workers = array();
$clients = array();

$stmt = $conn->prepare("SELECT id, first_name, last_name, email, phone, job_title, color_code, is_field_worker FROM users WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL ORDER BY first_name ASC, last_name ASC");
if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $workers = tasksFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT id, display_name, phone, email FROM clients WHERE tenant_id = ? AND deleted_at IS NULL AND status <> 'archived' ORDER BY display_name ASC");
if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $clients = tasksFetchAll($stmt);
    $stmt->close();
}

$relatedOptions = array(
    'job' => array(),
    'visit' => array(),
    'work_order' => array(),
    'request' => array(),
    'quote' => array(),
    'invoice' => array(),
    'property' => array(),
    'route_plan' => array(),
    'booking' => array()
);

$relatedQueries = array(
    'job' => "SELECT id, client_id, CONCAT(job_no, ' · ', title) AS label FROM jobs WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 500",
    'visit' => "SELECT v.id, j.client_id, CONCAT(COALESCE(NULLIF(v.visit_no, ''), CONCAT('Visit #', v.id)), ' · ', j.job_no, ' · ', j.title) AS label FROM visits v INNER JOIN jobs j ON j.id = v.job_id AND j.tenant_id = v.tenant_id AND j.deleted_at IS NULL WHERE v.tenant_id = ? ORDER BY v.created_at DESC LIMIT 500",
    'work_order' => "SELECT id, client_id, CONCAT(work_order_no, ' · ', title) AS label FROM work_orders WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 500",
    'request' => "SELECT id, client_id, CONCAT(request_no, ' · ', title) AS label FROM requests WHERE tenant_id = ? AND archived_at IS NULL ORDER BY created_at DESC LIMIT 500",
    'quote' => "SELECT id, client_id, CONCAT(quote_no, CASE WHEN title IS NOT NULL AND title <> '' THEN CONCAT(' · ', title) ELSE '' END) AS label FROM quotes WHERE tenant_id = ? AND archived_at IS NULL ORDER BY created_at DESC LIMIT 500",
    'invoice' => "SELECT id, client_id, CONCAT(invoice_no, ' · ', status) AS label FROM invoices WHERE tenant_id = ? AND archived_at IS NULL ORDER BY created_at DESC LIMIT 500",
    'property' => "SELECT id, client_id, CONCAT(COALESCE(NULLIF(name, ''), address_line1), ' · ', address_line1, CASE WHEN city IS NOT NULL AND city <> '' THEN CONCAT(', ', city) ELSE '' END) AS label FROM properties WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 500",
    'route_plan' => "SELECT id, NULL AS client_id, CONCAT(name, ' · ', route_date) AS label FROM route_plans WHERE tenant_id = ? ORDER BY route_date DESC, id DESC LIMIT 500",
    'booking' => "SELECT id, client_id, CONCAT(booking_no, ' · ', customer_name) AS label FROM bookings WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 500"
);

foreach ($relatedQueries as $type => $sql) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        continue;
    }

    $stmt->bind_param('i', $tenantId);
    if ($stmt->execute()) {
        foreach (tasksFetchAll($stmt) as $row) {
            $relatedOptions[$type][] = array(
                'id' => (int) $row['id'],
                'client_id' => !empty($row['client_id']) ? (int) $row['client_id'] : null,
                'label' => (string) $row['label']
            );
        }
    }
    $stmt->close();
}

$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$priorityFilter = isset($_GET['priority']) ? trim((string) $_GET['priority']) : '';
$workerFilter = isset($_GET['assigned_user_id']) ? (int) $_GET['assigned_user_id'] : -1;
$clientFilter = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
$relatedTypeFilter = isset($_GET['related_type']) ? trim((string) $_GET['related_type']) : '';
$datePreset = isset($_GET['date_preset']) ? trim((string) $_GET['date_preset']) : '';
$sort = isset($_GET['sort']) ? trim((string) $_GET['sort']) : 'due_asc';

if (!in_array($statusFilter, array('', 'open', 'in_progress', 'completed', 'cancelled', 'overdue'), true)) {
    $statusFilter = '';
}
if (!in_array($priorityFilter, array('', 'low', 'normal', 'high', 'urgent'), true)) {
    $priorityFilter = '';
}
if (!in_array($relatedTypeFilter, array_merge(array(''), array_keys($relatedOptions)), true)) {
    $relatedTypeFilter = '';
}
if (!in_array($datePreset, array('', 'today', 'tomorrow', 'this_week', 'upcoming', 'overdue', 'no_due_date'), true)) {
    $datePreset = '';
}
if (!in_array($sort, array('due_asc', 'due_desc', 'latest', 'oldest', 'priority_desc', 'title_asc', 'worker_asc', 'status_asc'), true)) {
    $sort = 'due_asc';
}

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$stats = array('total' => 0, 'open' => 0, 'in_progress' => 0, 'due_today' => 0, 'overdue' => 0, 'completed_month' => 0);
$stmt = $conn->prepare("SELECT COUNT(*) AS total_count, SUM(status = 'open') AS open_count, SUM(status = 'in_progress') AS in_progress_count, SUM(due_at IS NOT NULL AND DATE(due_at) = CURDATE() AND status NOT IN ('completed', 'cancelled')) AS due_today_count, SUM(status = 'overdue' OR (due_at IS NOT NULL AND due_at < NOW() AND status NOT IN ('completed', 'cancelled'))) AS overdue_count, SUM(status = 'completed' AND YEAR(completed_at) = YEAR(CURDATE()) AND MONTH(completed_at) = MONTH(CURDATE())) AS completed_month_count FROM tasks WHERE tenant_id = ?");
if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = tasksFetchAssoc($stmt);
    $stmt->close();
    if ($row) {
        $stats['total'] = (int) $row['total_count'];
        $stats['open'] = (int) $row['open_count'];
        $stats['in_progress'] = (int) $row['in_progress_count'];
        $stats['due_today'] = (int) $row['due_today_count'];
        $stats['overdue'] = (int) $row['overdue_count'];
        $stats['completed_month'] = (int) $row['completed_month_count'];
    }
}

$where = array('t.tenant_id = ?');
$params = array($tenantId);
$types = 'i';

if ($search !== '') {
    $where[] = "(t.title LIKE ? OR t.description LIKE ? OR c.display_name LIKE ? OR CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) LIKE ? OR j.job_no LIKE ? OR j.title LIKE ? OR v.visit_no LIKE ? OR wo.work_order_no LIKE ? OR wo.title LIKE ? OR r.request_no LIKE ? OR r.title LIKE ? OR q.quote_no LIKE ? OR q.title LIKE ? OR i.invoice_no LIKE ? OR p.name LIKE ? OR p.address_line1 LIKE ? OR rp.name LIKE ? OR b.booking_no LIKE ? OR b.customer_name LIKE ?)";
    $searchValue = '%' . $search . '%';
    for ($index = 0; $index < 19; $index++) {
        $params[] = $searchValue;
        $types .= 's';
    }
}

if ($statusFilter !== '') {
    $where[] = 't.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
if ($priorityFilter !== '') {
    $where[] = 't.priority = ?';
    $params[] = $priorityFilter;
    $types .= 's';
}
if ($workerFilter > 0) {
    $where[] = 't.assigned_user_id = ?';
    $params[] = $workerFilter;
    $types .= 'i';
} elseif (isset($_GET['assigned_user_id']) && (string) $_GET['assigned_user_id'] === '0') {
    $where[] = 't.assigned_user_id IS NULL';
}
if ($clientFilter > 0) {
    $where[] = 't.client_id = ?';
    $params[] = $clientFilter;
    $types .= 'i';
}
if ($relatedTypeFilter !== '') {
    $where[] = 't.related_type = ?';
    $params[] = $relatedTypeFilter;
    $types .= 's';
}

if ($datePreset === 'today') {
    $where[] = 't.due_at IS NOT NULL AND DATE(t.due_at) = CURDATE()';
} elseif ($datePreset === 'tomorrow') {
    $where[] = 't.due_at IS NOT NULL AND DATE(t.due_at) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
} elseif ($datePreset === 'this_week') {
    $where[] = 't.due_at IS NOT NULL AND YEARWEEK(DATE(t.due_at), 1) = YEARWEEK(CURDATE(), 1)';
} elseif ($datePreset === 'upcoming') {
    $where[] = "t.due_at IS NOT NULL AND t.due_at >= NOW() AND t.status NOT IN ('completed', 'cancelled')";
} elseif ($datePreset === 'overdue') {
    $where[] = "(t.status = 'overdue' OR (t.due_at IS NOT NULL AND t.due_at < NOW() AND t.status NOT IN ('completed', 'cancelled')))";
} elseif ($datePreset === 'no_due_date') {
    $where[] = 't.due_at IS NULL';
}

$whereSql = implode(' AND ', $where);
$orderSql = "CASE WHEN t.status = 'overdue' THEN 0 WHEN t.due_at IS NULL THEN 2 ELSE 1 END ASC, t.due_at ASC, t.id DESC";

if ($sort === 'due_desc') {
    $orderSql = 'CASE WHEN t.due_at IS NULL THEN 1 ELSE 0 END, t.due_at DESC, t.id DESC';
} elseif ($sort === 'latest') {
    $orderSql = 't.created_at DESC, t.id DESC';
} elseif ($sort === 'oldest') {
    $orderSql = 't.created_at ASC, t.id ASC';
} elseif ($sort === 'priority_desc') {
    $orderSql = "FIELD(t.priority, 'urgent', 'high', 'normal', 'low'), t.due_at ASC";
} elseif ($sort === 'title_asc') {
    $orderSql = 't.title ASC, t.id ASC';
} elseif ($sort === 'worker_asc') {
    $orderSql = 'u.first_name ASC, u.last_name ASC, t.due_at ASC';
} elseif ($sort === 'status_asc') {
    $orderSql = 't.status ASC, t.due_at ASC';
}

$joinSql = "
    FROM tasks t
    LEFT JOIN users u ON u.id = t.assigned_user_id AND u.tenant_id = t.tenant_id AND u.deleted_at IS NULL
    LEFT JOIN users cu ON cu.id = t.created_by AND cu.tenant_id = t.tenant_id AND cu.deleted_at IS NULL
    LEFT JOIN clients c ON c.id = t.client_id AND c.tenant_id = t.tenant_id AND c.deleted_at IS NULL
    LEFT JOIN jobs j ON t.related_type = 'job' AND j.id = t.related_id AND j.tenant_id = t.tenant_id AND j.deleted_at IS NULL
    LEFT JOIN visits v ON t.related_type = 'visit' AND v.id = t.related_id AND v.tenant_id = t.tenant_id
    LEFT JOIN work_orders wo ON t.related_type = 'work_order' AND wo.id = t.related_id AND wo.tenant_id = t.tenant_id AND wo.deleted_at IS NULL
    LEFT JOIN requests r ON t.related_type = 'request' AND r.id = t.related_id AND r.tenant_id = t.tenant_id AND r.archived_at IS NULL
    LEFT JOIN quotes q ON t.related_type = 'quote' AND q.id = t.related_id AND q.tenant_id = t.tenant_id AND q.archived_at IS NULL
    LEFT JOIN invoices i ON t.related_type = 'invoice' AND i.id = t.related_id AND i.tenant_id = t.tenant_id AND i.archived_at IS NULL
    LEFT JOIN properties p ON t.related_type = 'property' AND p.id = t.related_id AND p.tenant_id = t.tenant_id AND p.deleted_at IS NULL
    LEFT JOIN route_plans rp ON t.related_type = 'route_plan' AND rp.id = t.related_id AND rp.tenant_id = t.tenant_id
    LEFT JOIN bookings b ON t.related_type = 'booking' AND b.id = t.related_id AND b.tenant_id = t.tenant_id
";

$totalFiltered = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS total {$joinSql} WHERE {$whereSql}");
if (!$stmt) {
    $errors[] = 'Unable to prepare task count query: ' . $conn->error;
} else {
    if (!tasksBindParams($stmt, $types, $params)) {
        $errors[] = 'Unable to bind task filters.';
    } elseif (!$stmt->execute()) {
        $errors[] = 'Unable to count tasks: ' . $stmt->error;
    } else {
        $row = tasksFetchAssoc($stmt);
        $totalFiltered = $row ? (int) $row['total'] : 0;
    }
    $stmt->close();
}

$totalPages = max(1, (int) ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listSql = "
    SELECT
        t.id, t.title, t.description, t.assigned_user_id, t.client_id,
        t.related_type, t.related_id, t.due_at, t.status, t.priority,
        t.created_by, t.completed_at, t.created_at, t.updated_at,
        CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS assigned_user_name,
        u.job_title AS assigned_user_job_title,
        u.color_code AS assigned_user_color,
        CONCAT(COALESCE(cu.first_name, ''), CASE WHEN cu.last_name IS NOT NULL AND cu.last_name <> '' THEN CONCAT(' ', cu.last_name) ELSE '' END) AS created_by_name,
        c.display_name AS client_name,
        CASE t.related_type
            WHEN 'job' THEN CONCAT(j.job_no, ' · ', j.title)
            WHEN 'visit' THEN COALESCE(NULLIF(v.visit_no, ''), CONCAT('Visit #', v.id))
            WHEN 'work_order' THEN CONCAT(wo.work_order_no, ' · ', wo.title)
            WHEN 'request' THEN CONCAT(r.request_no, ' · ', r.title)
            WHEN 'quote' THEN CONCAT(q.quote_no, CASE WHEN q.title IS NOT NULL AND q.title <> '' THEN CONCAT(' · ', q.title) ELSE '' END)
            WHEN 'invoice' THEN CONCAT(i.invoice_no, ' · ', i.status)
            WHEN 'property' THEN CONCAT(COALESCE(NULLIF(p.name, ''), p.address_line1), ' · ', p.address_line1)
            WHEN 'route_plan' THEN CONCAT(rp.name, ' · ', rp.route_date)
            WHEN 'booking' THEN CONCAT(b.booking_no, ' · ', b.customer_name)
            ELSE NULL
        END AS related_label
    {$joinSql}
    WHERE {$whereSql}
    ORDER BY {$orderSql}
    LIMIT ? OFFSET ?
";

$tasks = array();
$stmt = $conn->prepare($listSql);
if (!$stmt) {
    $errors[] = 'Unable to prepare task list query: ' . $conn->error;
} else {
    $listParams = $params;
    $listTypes = $types . 'ii';
    $listParams[] = $perPage;
    $listParams[] = $offset;

    if (!tasksBindParams($stmt, $listTypes, $listParams)) {
        $errors[] = 'Unable to bind task list filters.';
    } elseif (!$stmt->execute()) {
        $errors[] = 'Unable to load tasks: ' . $stmt->error;
    } else {
        $tasks = tasksFetchAll($stmt);
    }
    $stmt->close();
}

$csrfToken = tasksCsrfToken();
$returnQuery = tasksQueryString(array('page' => $page));

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.tasks-page {
    --task-primary: #6d28d9;
    --task-text: #111827;
    --task-muted: #6b7280;
    --task-border: #e5e7eb;
}

.task-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.task-header h1 {
    margin: 0;
    color: var(--task-text);
    font-size: 21px;
    font-weight: 700;
}

.task-header p {
    margin: 5px 0 0;
    color: var(--task-muted);
    font-size: 11px;
}

.task-add {
    min-height: 35px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 9px;
    background: var(--task-primary);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.task-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
}

.task-alert.success {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.task-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.task-stats {
    margin-bottom: 13px;
    display: grid;
    grid-template-columns: repeat(6,minmax(0,1fr));
    gap: 10px;
}

.task-stat {
    padding: 13px;
    border: 1px solid var(--task-border);
    border-radius: 11px;
    background: #fff;
}

.task-stat-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.task-stat-value {
    margin-top: 4px;
    color: var(--task-text);
    font-size: 19px;
    font-weight: 700;
}

.task-panel {
    overflow: hidden;
    border: 1px solid var(--task-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.task-filters {
    padding: 12px;
    display: grid;
    grid-template-columns:
        minmax(220px,1.3fr)
        minmax(135px,.62fr)
        minmax(135px,.62fr)
        minmax(170px,.78fr)
        minmax(170px,.78fr)
        minmax(145px,.66fr)
        minmax(145px,.66fr)
        minmax(155px,.7fr)
        auto;
    gap: 8px;
    border-bottom: 1px solid #f1f5f9;
}

.task-input,
.task-select {
    width: 100%;
    min-height: 36px;
    padding: 8px 10px;
    border: 1px solid #dfe3e8;
    border-radius: 8px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 9px;
    outline: none;
}

.task-input:focus,
.task-select:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.08);
}

.task-filter-actions {
    display: flex;
    gap: 6px;
}

.task-filter-btn,
.task-reset {
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 700;
}

.task-filter-btn {
    border: 0;
    background: var(--task-primary);
    color: #fff;
    cursor: pointer;
}

.task-reset {
    border: 1px solid var(--task-border);
    background: #fff;
    color: #4b5563;
    text-decoration: none;
}

.task-table-wrap {
    overflow-x: auto;
}

.task-table {
    width: 100%;
    border-collapse: collapse;
}

.task-table th,
.task-table td {
    padding: 11px 12px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    white-space: nowrap;
    vertical-align: middle;
}

.task-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.task-table td {
    color: #374151;
    font-size: 9px;
}

.task-main {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.task-sub {
    margin-top: 2px;
    display: block;
    max-width: 260px;
    overflow: hidden;
    color: #9ca3af;
    font-size: 8px;
    text-overflow: ellipsis;
}

.task-badge {
    padding: 4px 7px;
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 700;
}

.task-badge.open,
.task-badge.normal {
    background: #eff6ff;
    color: #1d4ed8;
}

.task-badge.in_progress,
.task-badge.high {
    background: #fff7ed;
    color: #c2410c;
}

.task-badge.completed {
    background: #ecfdf5;
    color: #047857;
}

.task-badge.cancelled,
.task-badge.low {
    background: #f3f4f6;
    color: #4b5563;
}

.task-badge.overdue,
.task-badge.urgent {
    background: #fef2f2;
    color: #b91c1c;
}

.task-related-type {
    margin-right: 5px;
    background: #f5f3ff;
    color: #6d28d9;
}

.task-overdue {
    margin-left: 5px;
    padding: 3px 6px;
    display: inline-flex;
    border-radius: 999px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 7px;
    font-weight: 700;
}

.task-worker {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.task-worker-dot {
    width: 8px;
    height: 8px;
    flex: 0 0 auto;
    border-radius: 50%;
    background: #d1d5db;
}

.task-actions {
    display: flex;
    justify-content: flex-end;
    gap: 5px;
}

.task-action-form {
    margin: 0;
    display: inline-flex;
}

.task-action {
    width: 29px;
    height: 29px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--task-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-family: inherit;
    text-decoration: none;
    cursor: pointer;
}

.task-action:hover {
    border-color: #c4b5fd;
    background: #faf8ff;
    color: var(--task-primary);
}

.task-action.success {
    border-color: #bbf7d0;
    color: #047857;
}

.task-action.danger {
    border-color: #fecaca;
    color: #b91c1c;
}

.task-footer {
    padding: 11px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px solid #f1f5f9;
}

.task-result {
    color: #6b7280;
    font-size: 9px;
}

.task-pages {
    display: flex;
    gap: 5px;
}

.task-page {
    min-width: 30px;
    height: 30px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--task-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.task-page.active {
    border-color: var(--task-primary);
    background: var(--task-primary);
    color: #fff;
}

.task-empty {
    padding: 42px 15px;
    color: #9ca3af;
    font-size: 10px;
    text-align: center;
}

@media (max-width: 1450px) {
    .task-filters {
        grid-template-columns: repeat(4,minmax(0,1fr));
    }
}

@media (max-width: 1050px) {
    .task-stats {
        grid-template-columns: repeat(3,minmax(0,1fr));
    }
}

@media (max-width: 760px) {
    .task-header {
        flex-direction: column;
    }

    .task-filters {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 560px) {
    .task-stats,
    .task-filters {
        grid-template-columns: 1fr;
    }

    .task-filter-actions {
        width: 100%;
    }

    .task-filter-btn,
    .task-reset {
        flex: 1;
    }

    .task-footer {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="tasks-page">
    <div class="task-header">
        <div>
            <h1>Tasks</h1>
            <p>
                Manage operational tasks, assignments, due dates, priorities, and completion.
            </p>
        </div>

        <?php if ($canManageTasks): ?>
            <a href="task-add.php" class="task-add">
                <i class="bi bi-plus-lg"></i>
                Add Task
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="task-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="task-alert error">
            <?= e($_SESSION['flash_error']); ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="task-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="task-stats">
        <article class="task-stat">
            <div class="task-stat-label">Total Tasks</div>
            <div class="task-stat-value"><?= e($stats['total']); ?></div>
        </article>

        <article class="task-stat">
            <div class="task-stat-label">Open</div>
            <div class="task-stat-value"><?= e($stats['open']); ?></div>
        </article>

        <article class="task-stat">
            <div class="task-stat-label">In Progress</div>
            <div class="task-stat-value"><?= e($stats['in_progress']); ?></div>
        </article>

        <article class="task-stat">
            <div class="task-stat-label">Due Today</div>
            <div class="task-stat-value"><?= e($stats['due_today']); ?></div>
        </article>

        <article class="task-stat">
            <div class="task-stat-label">Overdue</div>
            <div class="task-stat-value"><?= e($stats['overdue']); ?></div>
        </article>

        <article class="task-stat">
            <div class="task-stat-label">Completed This Month</div>
            <div class="task-stat-value"><?= e($stats['completed_month']); ?></div>
        </article>
    </section>

    <section class="task-panel">
        <form method="get" action="" class="task-filters">
            <input
                type="search"
                name="search"
                class="task-input"
                value="<?= e($search); ?>"
                placeholder="Search task, client, worker or related record"
            >

            <select name="status" class="task-select">
                <option value="">All Statuses</option>
                <option value="open" <?= $statusFilter === 'open' ? 'selected' : ''; ?>>Open</option>
                <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>

            <select name="priority" class="task-select">
                <option value="">All Priorities</option>
                <option value="urgent" <?= $priorityFilter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                <option value="high" <?= $priorityFilter === 'high' ? 'selected' : ''; ?>>High</option>
                <option value="normal" <?= $priorityFilter === 'normal' ? 'selected' : ''; ?>>Normal</option>
                <option value="low" <?= $priorityFilter === 'low' ? 'selected' : ''; ?>>Low</option>
            </select>

            <select name="assigned_user_id" class="task-select">
                <option value="">All Workers</option>
                <option
                    value="0"
                    <?= isset($_GET['assigned_user_id']) && (string) $_GET['assigned_user_id'] === '0' ? 'selected' : ''; ?>
                >
                    Unassigned
                </option>

                <?php foreach ($workers as $worker): ?>
                    <?php
                    $workerName = trim(
                        (string) $worker['first_name'] .
                        ' ' .
                        (string) $worker['last_name']
                    );
                    ?>
                    <option
                        value="<?= (int) $worker['id']; ?>"
                        <?= $workerFilter === (int) $worker['id'] ? 'selected' : ''; ?>
                    >
                        <?= e($workerName); ?>
                        <?php if (trim((string) $worker['job_title']) !== ''): ?>
                            · <?= e($worker['job_title']); ?>
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="client_id" class="task-select">
                <option value="">All Clients</option>

                <?php foreach ($clients as $client): ?>
                    <option
                        value="<?= (int) $client['id']; ?>"
                        <?= $clientFilter === (int) $client['id'] ? 'selected' : ''; ?>
                    >
                        <?= e($client['display_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="related_type" class="task-select">
                <option value="">All Related Types</option>
                <option value="job" <?= $relatedTypeFilter === 'job' ? 'selected' : ''; ?>>Job</option>
                <option value="visit" <?= $relatedTypeFilter === 'visit' ? 'selected' : ''; ?>>Visit</option>
                <option value="work_order" <?= $relatedTypeFilter === 'work_order' ? 'selected' : ''; ?>>Work Order</option>
                <option value="request" <?= $relatedTypeFilter === 'request' ? 'selected' : ''; ?>>Request</option>
                <option value="quote" <?= $relatedTypeFilter === 'quote' ? 'selected' : ''; ?>>Quote</option>
                <option value="invoice" <?= $relatedTypeFilter === 'invoice' ? 'selected' : ''; ?>>Invoice</option>
                <option value="property" <?= $relatedTypeFilter === 'property' ? 'selected' : ''; ?>>Property</option>
                <option value="route_plan" <?= $relatedTypeFilter === 'route_plan' ? 'selected' : ''; ?>>Route</option>
                <option value="booking" <?= $relatedTypeFilter === 'booking' ? 'selected' : ''; ?>>Booking</option>
            </select>

            <select name="date_preset" class="task-select">
                <option value="">All Due Dates</option>
                <option value="today" <?= $datePreset === 'today' ? 'selected' : ''; ?>>Due Today</option>
                <option value="tomorrow" <?= $datePreset === 'tomorrow' ? 'selected' : ''; ?>>Due Tomorrow</option>
                <option value="this_week" <?= $datePreset === 'this_week' ? 'selected' : ''; ?>>Due This Week</option>
                <option value="upcoming" <?= $datePreset === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                <option value="overdue" <?= $datePreset === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                <option value="no_due_date" <?= $datePreset === 'no_due_date' ? 'selected' : ''; ?>>No Due Date</option>
            </select>

            <select name="sort" class="task-select">
                <option value="due_asc" <?= $sort === 'due_asc' ? 'selected' : ''; ?>>Due Date Ascending</option>
                <option value="due_desc" <?= $sort === 'due_desc' ? 'selected' : ''; ?>>Due Date Descending</option>
                <option value="priority_desc" <?= $sort === 'priority_desc' ? 'selected' : ''; ?>>Highest Priority</option>
                <option value="latest" <?= $sort === 'latest' ? 'selected' : ''; ?>>Latest Created</option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : ''; ?>>Oldest Created</option>
                <option value="title_asc" <?= $sort === 'title_asc' ? 'selected' : ''; ?>>Task Title</option>
                <option value="worker_asc" <?= $sort === 'worker_asc' ? 'selected' : ''; ?>>Worker A-Z</option>
                <option value="status_asc" <?= $sort === 'status_asc' ? 'selected' : ''; ?>>Status</option>
            </select>

            <div class="task-filter-actions">
                <button type="submit" class="task-filter-btn">
                    Apply
                </button>

                <a href="tasks.php" class="task-reset">
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($tasks)): ?>
            <div class="task-table-wrap">
                <table class="task-table">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Assigned To</th>
                            <th>Client</th>
                            <th>Related Record</th>
                            <th>Due</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($tasks as $task): ?>
                        <?php
                        $assignedName = trim(
                            (string) $task['assigned_user_name']
                        );

                        $workerColor = trim(
                            (string) $task['assigned_user_color']
                        );

                        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $workerColor)) {
                            $workerColor = '#d1d5db';
                        }

                        $isOverdue =
                            !empty($task['due_at']) &&
                            strtotime((string) $task['due_at']) < time() &&
                            !in_array(
                                $task['status'],
                                array('completed', 'cancelled'),
                                true
                            );

                        $relatedUrl = tasksRelatedUrl(
                            $task['related_type'],
                            $task['related_id']
                        );
                        ?>
                        <tr>
                            <td>
                                <a
                                    href="task-view.php?id=<?= (int) $task['id']; ?>"
                                    class="task-main"
                                >
                                    <?= e($task['title']); ?>
                                </a>

                                <?php if (trim((string) $task['description']) !== ''): ?>
                                    <span class="task-sub">
                                        <?= e($task['description']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="task-worker">
                                    <span
                                        class="task-worker-dot"
                                        style="background:<?= e($workerColor); ?>;"
                                    ></span>

                                    <span class="task-main">
                                        <?= e($assignedName !== '' ? $assignedName : 'Unassigned'); ?>
                                    </span>
                                </span>

                                <?php if (trim((string) $task['assigned_user_job_title']) !== ''): ?>
                                    <span class="task-sub">
                                        <?= e($task['assigned_user_job_title']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($task['client_id'])): ?>
                                    <a
                                        href="client-view.php?id=<?= (int) $task['client_id']; ?>"
                                        class="task-main"
                                    >
                                        <?= e($task['client_name']); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (trim((string) $task['related_label']) !== ''): ?>
                                    <span class="task-badge task-related-type">
                                        <?= e(tasksLabel($task['related_type'])); ?>
                                    </span>

                                    <?php if ($relatedUrl !== ''): ?>
                                        <a href="<?= e($relatedUrl); ?>" class="task-main">
                                            <?= e($task['related_label']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="task-main">
                                            <?= e($task['related_label']); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="task-main">
                                    <?= e(tasksDateTime($task['due_at'])); ?>
                                </span>

                                <?php if ($isOverdue): ?>
                                    <span class="task-overdue">
                                        Overdue
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="task-badge <?= e(tasksCssClass($task['priority'])); ?>">
                                    <?= e(tasksLabel($task['priority'])); ?>
                                </span>
                            </td>

                            <td>
                                <span class="task-badge <?= e(tasksCssClass($task['status'])); ?>">
                                    <?= e(tasksLabel($task['status'])); ?>
                                </span>

                                <?php if (!empty($task['completed_at'])): ?>
                                    <span class="task-sub">
                                        <?= e(tasksDateTime($task['completed_at'])); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= e(tasksDate($task['created_at'])); ?>

                                <?php if (trim((string) $task['created_by_name']) !== ''): ?>
                                    <span class="task-sub">
                                        <?= e($task['created_by_name']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="task-actions">
                                    <a
                                        href="task-view.php?id=<?= (int) $task['id']; ?>"
                                        class="task-action"
                                        title="View Task"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <?php if ($canManageTasks): ?>
                                        <a
                                            href="task-edit.php?id=<?= (int) $task['id']; ?>"
                                            class="task-action"
                                            title="Edit Task"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>

                                        <?php if (in_array($task['status'], array('open', 'overdue'), true)): ?>
                                            <form method="post" class="task-action-form">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="task_id" value="<?= (int) $task['id']; ?>">
                                                <input type="hidden" name="new_status" value="in_progress">
                                                <input type="hidden" name="return_query" value="<?= e($returnQuery); ?>">

                                                <button
                                                    type="submit"
                                                    class="task-action"
                                                    title="Start Task"
                                                >
                                                    <i class="bi bi-play-fill"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if (!in_array($task['status'], array('completed', 'cancelled'), true)): ?>
                                            <form method="post" class="task-action-form">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="task_id" value="<?= (int) $task['id']; ?>">
                                                <input type="hidden" name="new_status" value="completed">
                                                <input type="hidden" name="return_query" value="<?= e($returnQuery); ?>">

                                                <button
                                                    type="submit"
                                                    class="task-action success"
                                                    title="Complete Task"
                                                >
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" class="task-action-form">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="task_id" value="<?= (int) $task['id']; ?>">
                                                <input type="hidden" name="new_status" value="open">
                                                <input type="hidden" name="return_query" value="<?= e($returnQuery); ?>">

                                                <button
                                                    type="submit"
                                                    class="task-action"
                                                    title="Reopen Task"
                                                >
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <form
                                            method="post"
                                            class="task-action-form"
                                            onsubmit="return confirm('Delete this task permanently?');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                            <input type="hidden" name="action" value="delete_task">
                                            <input type="hidden" name="task_id" value="<?= (int) $task['id']; ?>">

                                            <button
                                                type="submit"
                                                class="task-action danger"
                                                title="Delete Task"
                                            >
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="task-footer">
                <div class="task-result">
                    Showing
                    <?= e(min($totalFiltered, $offset + 1)); ?>
                    -
                    <?= e(min($totalFiltered, $offset + count($tasks))); ?>
                    of
                    <?= e($totalFiltered); ?>
                    tasks
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="task-pages">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(tasksQueryString(array('page' => $page - 1))); ?>"
                                class="task-page"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);

                        for (
                            $pageNumber = $startPage;
                            $pageNumber <= $endPage;
                            $pageNumber++
                        ):
                        ?>
                            <a
                                href="?<?= e(tasksQueryString(array('page' => $pageNumber))); ?>"
                                class="task-page <?= $pageNumber === $page ? 'active' : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                href="?<?= e(tasksQueryString(array('page' => $page + 1))); ?>"
                                class="task-page"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="task-empty">
                <?php if (
                    $search !== '' ||
                    $statusFilter !== '' ||
                    $priorityFilter !== '' ||
                    isset($_GET['assigned_user_id']) ||
                    $clientFilter > 0 ||
                    $relatedTypeFilter !== '' ||
                    $datePreset !== ''
                ): ?>
                    No tasks found for the selected filters.
                <?php else: ?>
                    No tasks are available.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
