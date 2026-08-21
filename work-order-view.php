<?php
/**
 * FieldPlx - Work Order View
 *
 * Upload as:
 * /public_html/work-order-view.php
 *
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| Authentication and permission
|--------------------------------------------------------------------------
|
| The current permissions table does not have separate work-order
| permissions. Work Orders belong to the Jobs module, so this page uses
| jobs.view and jobs.manage.
|
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    $redirectId = isset($_GET['id'])
        ? (int) $_GET['id']
        : 0;

    header(
        'Location: login.php?redirect=' .
        rawurlencode(
            'work-order-view.php?id=' . $redirectId
        )
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'jobs.view',
        'You do not have permission to view work orders.'
    );
}

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

$pageTitle = 'Work Order Details - FieldPlx';
$activePage = 'work-orders';
$searchPlaceholder = 'Search work orders...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];
$workOrderId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

$canManage = function_exists('hasPermission')
    ? hasPermission('jobs.manage')
    : true;

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('workOrderViewFetchAssoc')) {
    function workOrderViewFetchAssoc(mysqli_stmt $stmt)
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

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

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

if (!function_exists('workOrderViewFetchAll')) {
    function workOrderViewFetchAll(mysqli_stmt $stmt)
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

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

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

if (!function_exists('workOrderViewBindParams')) {
    function workOrderViewBindParams(
        mysqli_stmt $stmt,
        $types,
        array &$params
    ) {
        if ($types === '' || empty($params)) {
            return true;
        }

        $arguments = array($types);

        foreach ($params as $key => $value) {
            $arguments[] = &$params[$key];
        }

        return call_user_func_array(
            array($stmt, 'bind_param'),
            $arguments
        );
    }
}

if (!function_exists('workOrderViewCsrfToken')) {
    function workOrderViewCsrfToken()
    {
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));
            } catch (Throwable $error) {
                $_SESSION['csrf_token'] =
                    sha1(
                        uniqid(
                            (string) mt_rand(),
                            true
                        )
                    );
            }
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('workOrderViewVerifyCsrf')) {
    function workOrderViewVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('workOrderViewDate')) {
    function workOrderViewDate($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y', $timestamp)
            : '—';
    }
}

if (!function_exists('workOrderViewDateTime')) {
    function workOrderViewDateTime($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y, h:i A', $timestamp)
            : '—';
    }
}

if (!function_exists('workOrderViewDuration')) {
    function workOrderViewDuration($start, $end)
    {
        if (empty($start) || empty($end)) {
            return '—';
        }

        $startTime = strtotime((string) $start);
        $endTime = strtotime((string) $end);

        if (
            $startTime === false ||
            $endTime === false ||
            $endTime <= $startTime
        ) {
            return '—';
        }

        $seconds = $endTime - $startTime;
        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(($seconds % 86400) / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $parts = array();

        if ($days > 0) {
            $parts[] = $days . 'd';
        }

        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }

        if ($minutes > 0 || empty($parts)) {
            $parts[] = $minutes . 'm';
        }

        return implode(' ', $parts);
    }
}

if (!function_exists('workOrderViewLabel')) {
    function workOrderViewLabel($value)
    {
        return ucwords(
            str_replace(
                '_',
                ' ',
                (string) $value
            )
        );
    }
}

if (!function_exists('workOrderViewClass')) {
    function workOrderViewClass($value)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $value))
        );
    }
}

if (!function_exists('workOrderViewMoney')) {
    function workOrderViewMoney($value)
    {
        return '₹' . number_format((float) $value, 2);
    }
}

if (!function_exists('workOrderViewLogStatus')) {
    function workOrderViewLogStatus(
        mysqli $conn,
        $tenantId,
        $userId,
        $workOrderId,
        $clientId,
        $workOrderNo,
        $oldStatus,
        $newStatus
    ) {
        $stmt = $conn->prepare("
            INSERT INTO activity_events (
                tenant_id,
                actor_user_id,
                actor_type,
                event_type,
                related_type,
                related_id,
                client_id,
                title,
                details_json,
                visible_to_client,
                created_at
            ) VALUES (
                ?,
                ?,
                'user',
                'work_order_status_updated',
                'work_order',
                ?,
                ?,
                ?,
                ?,
                0,
                NOW()
            )
        ");

        if (!$stmt) {
            return;
        }

        $title =
            'Work order status updated: ' .
            $workOrderNo .
            ' - ' .
            workOrderViewLabel($newStatus);

        $details = json_encode(
            array(
                'work_order_id' =>
                    (int) $workOrderId,
                'work_order_no' =>
                    (string) $workOrderNo,
                'old_status' =>
                    (string) $oldStatus,
                'new_status' =>
                    (string) $newStatus
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiiss',
            $tenantId,
            $userId,
            $workOrderId,
            $clientId,
            $title,
            $details
        );

        $stmt->execute();
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Validate work-order ID
|--------------------------------------------------------------------------
*/

if ($workOrderId <= 0) {
    $_SESSION['flash_error'] =
        'Invalid work order selected.';

    header('Location: work-orders.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load a minimal record for status actions
|--------------------------------------------------------------------------
*/

$actionRecord = null;

$stmt = $conn->prepare("
    SELECT
        id,
        work_order_no,
        client_id,
        status
    FROM work_orders
    WHERE id = ?
      AND tenant_id = ?
      AND deleted_at IS NULL
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $workOrderId,
        $tenantId
    );

    $stmt->execute();
    $actionRecord =
        workOrderViewFetchAssoc($stmt);

    $stmt->close();
}

if (!$actionRecord) {
    $_SESSION['flash_error'] =
        'Work order not found or unavailable.';

    header('Location: work-orders.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Status action
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'update_status'
) {
    if (!$canManage) {
        $errors[] =
            'You do not have permission to update this work order.';
    }

    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!workOrderViewVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $newStatus = isset($_POST['new_status'])
        ? trim((string) $_POST['new_status'])
        : '';

    $oldStatus =
        (string) $actionRecord['status'];

    $transitions = array(
        'draft' => array(
            'issued',
            'cancelled'
        ),
        'issued' => array(
            'accepted',
            'rejected',
            'cancelled'
        ),
        'accepted' => array(
            'in_progress',
            'cancelled'
        ),
        'in_progress' => array(
            'completed',
            'cancelled'
        ),
        'completed' => array(),
        'rejected' => array(),
        'cancelled' => array()
    );

    if (
        !isset($transitions[$oldStatus]) ||
        !in_array(
            $newStatus,
            $transitions[$oldStatus],
            true
        )
    ) {
        $errors[] =
            'The selected status change is not allowed.';
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            $setParts = array(
                'status = ?',
                'updated_by = ?',
                'updated_at = NOW()'
            );

            $types = 'si';
            $params = array(
                $newStatus,
                $currentUserId
            );

            if (
                in_array(
                    $newStatus,
                    array(
                        'issued',
                        'accepted',
                        'in_progress',
                        'completed'
                    ),
                    true
                )
            ) {
                $setParts[] =
                    'issued_by = COALESCE(issued_by, ?)';
                $setParts[] =
                    'issued_at = COALESCE(issued_at, NOW())';
                $types .= 'i';
                $params[] = $currentUserId;
            }

            if (
                in_array(
                    $newStatus,
                    array(
                        'accepted',
                        'in_progress',
                        'completed'
                    ),
                    true
                )
            ) {
                $setParts[] =
                    'accepted_at = COALESCE(accepted_at, NOW())';
            }

            if (
                in_array(
                    $newStatus,
                    array(
                        'in_progress',
                        'completed'
                    ),
                    true
                )
            ) {
                $setParts[] =
                    'actual_start = COALESCE(actual_start, NOW())';
            }

            if ($newStatus === 'completed') {
                $setParts[] =
                    'actual_end = COALESCE(actual_end, NOW())';
                $setParts[] =
                    'completed_at = COALESCE(completed_at, NOW())';
            }

            $types .= 'ii';
            $params[] = $workOrderId;
            $params[] = $tenantId;

            $updateSql = "
                UPDATE work_orders
                SET " . implode(', ', $setParts) . "
                WHERE id = ?
                  AND tenant_id = ?
                  AND deleted_at IS NULL
            ";

            $stmt = $conn->prepare($updateSql);

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the work order status update: ' .
                    $conn->error
                );
            }

            if (
                !workOrderViewBindParams(
                    $stmt,
                    $types,
                    $params
                )
            ) {
                throw new Exception(
                    'Unable to bind the work order status update: ' .
                    $stmt->error
                );
            }

            if (!$stmt->execute()) {
                throw new Exception(
                    'Unable to update the work order status: ' .
                    $stmt->error
                );
            }

            $stmt->close();

            $scheduleStatus = 'scheduled';

            if ($newStatus === 'completed') {
                $scheduleStatus = 'completed';
            } elseif (
                in_array(
                    $newStatus,
                    array('rejected', 'cancelled'),
                    true
                )
            ) {
                $scheduleStatus = 'cancelled';
            }

            $stmt = $conn->prepare("
                UPDATE schedule_events
                SET
                    status = ?,
                    updated_at = NOW()
                WHERE tenant_id = ?
                  AND related_type = 'work_order'
                  AND related_id = ?
            ");

            if ($stmt) {
                $stmt->bind_param(
                    'sii',
                    $scheduleStatus,
                    $tenantId,
                    $workOrderId
                );

                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();

            workOrderViewLogStatus(
                $conn,
                $tenantId,
                $currentUserId,
                $workOrderId,
                (int) $actionRecord['client_id'],
                (string) $actionRecord['work_order_no'],
                $oldStatus,
                $newStatus
            );

            $_SESSION['flash_success'] =
                'Work order status updated to ' .
                workOrderViewLabel($newStatus) .
                '.';

            header(
                'Location: work-order-view.php?id=' .
                $workOrderId
            );
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

/*
|--------------------------------------------------------------------------
| Load full work-order record
|--------------------------------------------------------------------------
*/

$workOrder = null;

$stmt = $conn->prepare("
    SELECT
        wo.id,
        wo.tenant_id,
        wo.work_order_no,
        wo.job_id,
        wo.client_id,
        wo.property_id,
        wo.title,
        wo.work_description,
        wo.safety_instructions,
        wo.scheduled_start,
        wo.scheduled_end,
        wo.actual_start,
        wo.actual_end,
        wo.status,
        wo.completion_notes,
        wo.signature_attachment_id,
        wo.issued_by,
        wo.issued_at,
        wo.accepted_at,
        wo.completed_at,
        wo.created_by,
        wo.updated_by,
        wo.created_at,
        wo.updated_at,

        j.job_no,
        j.title AS job_title,
        j.description AS job_description,
        j.status AS job_status,
        j.job_type,
        j.assigned_user_id AS job_assigned_user_id,
        j.start_date AS job_start_date,
        j.end_date AS job_end_date,
        j.invoicing_preference,
        j.subtotal AS job_subtotal,
        j.tax_total AS job_tax_total,
        j.total AS job_total,

        c.display_name AS client_name,
        c.company_name AS client_company,
        c.email AS client_email,
        c.phone AS client_phone,
        c.alternate_phone AS client_alternate_phone,
        c.preferred_contact_method,

        p.name AS property_name,
        p.address_line1 AS property_address_line1,
        p.address_line2 AS property_address_line2,
        p.city AS property_city,
        p.state AS property_state,
        p.postal_code AS property_postal_code,
        p.country AS property_country,
        p.gate_code,
        p.access_notes,
        p.service_instructions,

        CONCAT(
            COALESCE(assigned_user.first_name, ''),
            CASE
                WHEN assigned_user.last_name IS NOT NULL
                 AND assigned_user.last_name <> ''
                THEN CONCAT(' ', assigned_user.last_name)
                ELSE ''
            END
        ) AS assigned_user_name,
        assigned_user.email AS assigned_user_email,
        assigned_user.phone AS assigned_user_phone,

        CONCAT(
            COALESCE(issued_user.first_name, ''),
            CASE
                WHEN issued_user.last_name IS NOT NULL
                 AND issued_user.last_name <> ''
                THEN CONCAT(' ', issued_user.last_name)
                ELSE ''
            END
        ) AS issued_by_name,

        CONCAT(
            COALESCE(created_user.first_name, ''),
            CASE
                WHEN created_user.last_name IS NOT NULL
                 AND created_user.last_name <> ''
                THEN CONCAT(' ', created_user.last_name)
                ELSE ''
            END
        ) AS created_by_name,

        CONCAT(
            COALESCE(updated_user.first_name, ''),
            CASE
                WHEN updated_user.last_name IS NOT NULL
                 AND updated_user.last_name <> ''
                THEN CONCAT(' ', updated_user.last_name)
                ELSE ''
            END
        ) AS updated_by_name,

        signature.file_name AS signature_file_name,
        signature.file_path AS signature_file_path,
        signature.file_mime AS signature_file_mime,
        signature.file_size AS signature_file_size,
        signature.description AS signature_description,
        signature.created_at AS signature_created_at

    FROM work_orders wo

    INNER JOIN jobs j
        ON j.id = wo.job_id
       AND j.tenant_id = wo.tenant_id

    INNER JOIN clients c
        ON c.id = wo.client_id
       AND c.tenant_id = wo.tenant_id

    LEFT JOIN properties p
        ON p.id = wo.property_id
       AND p.tenant_id = wo.tenant_id

    LEFT JOIN users assigned_user
        ON assigned_user.id = j.assigned_user_id
       AND assigned_user.tenant_id = wo.tenant_id

    LEFT JOIN users issued_user
        ON issued_user.id = wo.issued_by
       AND issued_user.tenant_id = wo.tenant_id

    LEFT JOIN users created_user
        ON created_user.id = wo.created_by
       AND created_user.tenant_id = wo.tenant_id

    LEFT JOIN users updated_user
        ON updated_user.id = wo.updated_by
       AND updated_user.tenant_id = wo.tenant_id

    LEFT JOIN attachments signature
        ON signature.id = wo.signature_attachment_id
       AND signature.tenant_id = wo.tenant_id

    WHERE wo.id = ?
      AND wo.tenant_id = ?
      AND wo.deleted_at IS NULL

    LIMIT 1
");

if (!$stmt) {
    $errors[] =
        'Unable to prepare the work order query: ' .
        $conn->error;
} else {
    $stmt->bind_param(
        'ii',
        $workOrderId,
        $tenantId
    );

    if (!$stmt->execute()) {
        $errors[] =
            'Unable to load the work order: ' .
            $stmt->error;
    } else {
        $workOrder =
            workOrderViewFetchAssoc($stmt);
    }

    $stmt->close();
}

if (!$workOrder) {
    $_SESSION['flash_error'] =
        'Work order not found or unavailable.';

    header('Location: work-orders.php');
    exit;
}

$pageTitle =
    $workOrder['work_order_no'] .
    ' - Work Order - FieldPlx';

/*
|--------------------------------------------------------------------------
| Load job line items
|--------------------------------------------------------------------------
*/

$lineItems = array();

$stmt = $conn->prepare("
    SELECT
        id,
        product_service_id,
        item_name,
        description,
        quantity,
        unit_cost,
        unit_price,
        tax_amount,
        line_total,
        sort_order,
        created_at
    FROM job_line_items
    WHERE tenant_id = ?
      AND job_id = ?
    ORDER BY sort_order ASC, id ASC
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $tenantId,
        $workOrder['job_id']
    );

    $stmt->execute();
    $lineItems =
        workOrderViewFetchAll($stmt);

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Load job assignments
|--------------------------------------------------------------------------
*/

$assignments = array();

$stmt = $conn->prepare("
    SELECT
        ja.id,
        ja.user_id,
        ja.team_id,
        ja.assignment_role,
        ja.assigned_at,
        ja.accepted_at,
        ja.status,

        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN u.last_name IS NOT NULL
                 AND u.last_name <> ''
                THEN CONCAT(' ', u.last_name)
                ELSE ''
            END
        ) AS user_name,
        u.email,
        u.phone

    FROM job_assignments ja

    LEFT JOIN users u
        ON u.id = ja.user_id
       AND u.tenant_id = ja.tenant_id

    WHERE ja.tenant_id = ?
      AND ja.job_id = ?
      AND ja.status <> 'removed'

    ORDER BY
        CASE ja.assignment_role
            WHEN 'primary' THEN 1
            WHEN 'supervisor' THEN 2
            WHEN 'technician' THEN 3
            WHEN 'assistant' THEN 4
            WHEN 'salesperson' THEN 5
            ELSE 6
        END,
        ja.assigned_at ASC,
        ja.id ASC
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $tenantId,
        $workOrder['job_id']
    );

    $stmt->execute();
    $assignments =
        workOrderViewFetchAll($stmt);

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Load related attachments
|--------------------------------------------------------------------------
*/

$attachments = array();

$stmt = $conn->prepare("
    SELECT
        id,
        file_name,
        file_path,
        file_mime,
        file_size,
        attachment_type,
        description,
        created_at
    FROM attachments
    WHERE tenant_id = ?
      AND related_type = 'work_order'
      AND related_id = ?
    ORDER BY created_at DESC, id DESC
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $tenantId,
        $workOrderId
    );

    $stmt->execute();
    $attachments =
        workOrderViewFetchAll($stmt);

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Load activity timeline
|--------------------------------------------------------------------------
*/

$activities = array();

$stmt = $conn->prepare("
    SELECT
        ae.id,
        ae.actor_type,
        ae.event_type,
        ae.related_type,
        ae.related_id,
        ae.title,
        ae.details_json,
        ae.created_at,

        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN u.last_name IS NOT NULL
                 AND u.last_name <> ''
                THEN CONCAT(' ', u.last_name)
                ELSE ''
            END
        ) AS actor_name

    FROM activity_events ae

    LEFT JOIN users u
        ON u.id = ae.actor_user_id
       AND u.tenant_id = ae.tenant_id

    WHERE ae.tenant_id = ?
      AND (
            (
                ae.related_type = 'work_order'
                AND ae.related_id = ?
            )
            OR
            (
                ae.related_type = 'job'
                AND ae.related_id = ?
            )
          )

    ORDER BY ae.created_at DESC, ae.id DESC
    LIMIT 25
");

if ($stmt) {
    $stmt->bind_param(
        'iii',
        $tenantId,
        $workOrderId,
        $workOrder['job_id']
    );

    $stmt->execute();
    $activities =
        workOrderViewFetchAll($stmt);

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Derived display values
|--------------------------------------------------------------------------
*/

$propertyAddress = implode(
    ', ',
    array_filter(
        array(
            $workOrder['property_address_line1'],
            $workOrder['property_address_line2'],
            $workOrder['property_city'],
            $workOrder['property_state'],
            $workOrder['property_postal_code'],
            $workOrder['property_country']
        ),
        function ($value) {
            return trim((string) $value) !== '';
        }
    )
);

$isOverdue =
    !empty($workOrder['scheduled_end']) &&
    strtotime((string) $workOrder['scheduled_end']) < time() &&
    !in_array(
        $workOrder['status'],
        array(
            'completed',
            'rejected',
            'cancelled'
        ),
        true
    );

$plannedDuration =
    workOrderViewDuration(
        $workOrder['scheduled_start'],
        $workOrder['scheduled_end']
    );

$actualDuration =
    workOrderViewDuration(
        $workOrder['actual_start'],
        $workOrder['actual_end']
    );

$statusActions = array(
    'draft' => array(
        'issued' => array(
            'label' => 'Issue Work Order',
            'icon' => 'bi-send',
            'class' => 'primary'
        ),
        'cancelled' => array(
            'label' => 'Cancel',
            'icon' => 'bi-x-circle',
            'class' => 'danger'
        )
    ),
    'issued' => array(
        'accepted' => array(
            'label' => 'Mark Accepted',
            'icon' => 'bi-check2-circle',
            'class' => 'primary'
        ),
        'rejected' => array(
            'label' => 'Mark Rejected',
            'icon' => 'bi-slash-circle',
            'class' => 'danger'
        ),
        'cancelled' => array(
            'label' => 'Cancel',
            'icon' => 'bi-x-circle',
            'class' => 'danger'
        )
    ),
    'accepted' => array(
        'in_progress' => array(
            'label' => 'Start Work',
            'icon' => 'bi-play-circle',
            'class' => 'primary'
        ),
        'cancelled' => array(
            'label' => 'Cancel',
            'icon' => 'bi-x-circle',
            'class' => 'danger'
        )
    ),
    'in_progress' => array(
        'completed' => array(
            'label' => 'Complete Work',
            'icon' => 'bi-check2-all',
            'class' => 'success'
        ),
        'cancelled' => array(
            'label' => 'Cancel',
            'icon' => 'bi-x-circle',
            'class' => 'danger'
        )
    ),
    'completed' => array(),
    'rejected' => array(),
    'cancelled' => array()
);

$currentActions = isset(
    $statusActions[$workOrder['status']]
)
    ? $statusActions[$workOrder['status']]
    : array();

$csrfToken = workOrderViewCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.work-order-view-page {
    --wv-primary: #6d28d9;
    --wv-text: #111827;
    --wv-muted: #6b7280;
    --wv-border: #e5e7eb;
}

.wv-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 15px;
}

.wv-heading {
    min-width: 0;
}

.wv-kicker {
    margin-bottom: 4px;
    color: #8b5cf6;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.wv-title-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.wv-title-row h1 {
    margin: 0;
    color: var(--wv-text);
    font-size: 21px;
    font-weight: 700;
}

.wv-heading p {
    margin: 5px 0 0;
    color: var(--wv-muted);
    font-size: 11px;
}

.wv-header-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 7px;
}

.wv-btn {
    min-height: 35px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border: 1px solid var(--wv-border);
    border-radius: 9px;
    background: #fff;
    color: #374151;
    font-family: inherit;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}

.wv-btn.primary {
    border-color: var(--wv-primary);
    background: var(--wv-primary);
    color: #fff;
}

.wv-btn.success {
    border-color: #047857;
    background: #047857;
    color: #fff;
}

.wv-btn.danger {
    border-color: #fecaca;
    background: #fff;
    color: #b91c1c;
}

.wv-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
    line-height: 1.55;
}

.wv-alert.success {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.wv-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.wv-badge {
    padding: 5px 8px;
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 800;
}

.wv-badge.draft {
    background: #f3f4f6;
    color: #4b5563;
}

.wv-badge.issued {
    background: #eff6ff;
    color: #1d4ed8;
}

.wv-badge.accepted {
    background: #f5f3ff;
    color: #6d28d9;
}

.wv-badge.in_progress {
    background: #fff7ed;
    color: #c2410c;
}

.wv-badge.completed {
    background: #ecfdf5;
    color: #047857;
}

.wv-badge.rejected,
.wv-badge.cancelled {
    background: #fef2f2;
    color: #b91c1c;
}

.wv-badge.overdue {
    background: #fef2f2;
    color: #b91c1c;
}

.wv-stats {
    margin-bottom: 13px;
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 10px;
}

.wv-stat {
    padding: 13px;
    border: 1px solid var(--wv-border);
    border-radius: 11px;
    background: #fff;
}

.wv-stat-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
}

.wv-stat-value {
    margin-top: 5px;
    color: var(--wv-text);
    font-size: 16px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.wv-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.45fr)
        minmax(300px,.7fr);
    gap: 13px;
    align-items: start;
}

.wv-card {
    overflow: hidden;
    border: 1px solid var(--wv-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.wv-card + .wv-card {
    margin-top: 13px;
}

.wv-card-head {
    min-height: 46px;
    padding: 11px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #f1f5f9;
}

.wv-card-head h2 {
    margin: 0;
    color: var(--wv-text);
    font-size: 11px;
    font-weight: 700;
}

.wv-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 8px;
}

.wv-card-body {
    padding: 14px;
}

.wv-text {
    margin: 0;
    color: #374151;
    font-size: 10px;
    line-height: 1.75;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.wv-empty-text {
    color: #9ca3af;
    font-size: 9px;
}

.wv-details {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 10px;
}

.wv-detail {
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.wv-detail.full {
    grid-column: 1 / -1;
}

.wv-detail-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
}

.wv-detail-value {
    margin-top: 4px;
    display: block;
    color: #111827;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.wv-detail-sub {
    margin-top: 3px;
    display: block;
    color: #6b7280;
    font-size: 8px;
    line-height: 1.45;
    overflow-wrap: anywhere;
}

.wv-link {
    color: #5b21b6;
    text-decoration: none;
}

.wv-action-stack {
    display: grid;
    gap: 7px;
}

.wv-action-stack form {
    margin: 0;
}

.wv-action-stack .wv-btn {
    width: 100%;
}

.wv-table-wrap {
    overflow-x: auto;
}

.wv-table {
    width: 100%;
    border-collapse: collapse;
}

.wv-table th,
.wv-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    vertical-align: middle;
}

.wv-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
    white-space: nowrap;
}

.wv-table td {
    color: #374151;
    font-size: 9px;
}

.wv-table .number {
    text-align: right;
    white-space: nowrap;
}

.wv-item-name {
    color: #111827;
    font-weight: 700;
}

.wv-item-desc {
    margin-top: 2px;
    display: block;
    max-width: 330px;
    color: #9ca3af;
    font-size: 8px;
}

.wv-team-list,
.wv-file-list,
.wv-timeline {
    display: grid;
    gap: 9px;
}

.wv-team-item,
.wv-file-item {
    padding: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.wv-team-main,
.wv-file-main {
    min-width: 0;
}

.wv-team-name,
.wv-file-name {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.wv-team-meta,
.wv-file-meta {
    margin-top: 3px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.45;
    overflow-wrap: anywhere;
}

.wv-file-open {
    flex: 0 0 auto;
    width: 29px;
    height: 29px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--wv-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    text-decoration: none;
}

.wv-timeline-item {
    position: relative;
    padding: 0 0 13px 20px;
}

.wv-timeline-item:last-child {
    padding-bottom: 0;
}

.wv-timeline-item::before {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #8b5cf6;
}

.wv-timeline-item:not(:last-child)::after {
    content: '';
    position: absolute;
    top: 13px;
    bottom: 0;
    left: 6px;
    width: 1px;
    background: #e5e7eb;
}

.wv-timeline-title {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
    line-height: 1.45;
}

.wv-timeline-meta {
    margin-top: 3px;
    color: #9ca3af;
    font-size: 8px;
}

.wv-signature {
    padding: 12px;
    border: 1px dashed #c4b5fd;
    border-radius: 10px;
    background: #faf8ff;
}

.wv-signature-title {
    color: #5b21b6;
    font-size: 9px;
    font-weight: 800;
}

.wv-signature-meta {
    margin-top: 5px;
    color: #6b7280;
    font-size: 8px;
    line-height: 1.55;
}

.wv-signature-preview {
    margin-top: 10px;
    max-width: 100%;
    max-height: 180px;
    display: block;
    border: 1px solid #ede9fe;
    border-radius: 8px;
    background: #fff;
}

@media (max-width: 1050px) {
    .wv-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .wv-header {
        flex-direction: column;
    }

    .wv-header-actions {
        width: 100%;
        justify-content: flex-start;
    }

    .wv-stats {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 560px) {
    .wv-stats,
    .wv-details {
        grid-template-columns: 1fr;
    }

    .wv-detail.full {
        grid-column: auto;
    }

    .wv-btn {
        flex: 1;
    }
}

@media print {
    .topbar,
    .sidebar,
    .wv-header-actions,
    .wv-action-card,
    .no-print {
        display: none !important;
    }

    .main-content,
    .content-wrapper {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }

    .work-order-view-page {
        padding: 0 !important;
    }

    .wv-card,
    .wv-stat {
        box-shadow: none;
        break-inside: avoid;
    }

    .wv-layout {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="work-order-view-page">
    <div class="wv-header">
        <div class="wv-heading">
            <div class="wv-kicker">
                <?= e($workOrder['work_order_no']); ?>
            </div>

            <div class="wv-title-row">
                <h1><?= e($workOrder['title']); ?></h1>

                <span class="wv-badge <?= e(
                    workOrderViewClass(
                        $workOrder['status']
                    )
                ); ?>">
                    <?= e(
                        workOrderViewLabel(
                            $workOrder['status']
                        )
                    ); ?>
                </span>

                <?php if ($isOverdue): ?>
                    <span class="wv-badge overdue">
                        Overdue
                    </span>
                <?php endif; ?>
            </div>

            <p>
                Job <?= e($workOrder['job_no']); ?>
                · <?= e($workOrder['client_name']); ?>
            </p>
        </div>

        <div class="wv-header-actions no-print">
            <a href="work-orders.php" class="wv-btn">
                <i class="bi bi-arrow-left"></i>
                Back
            </a>

            <button
                type="button"
                class="wv-btn"
                onclick="window.print();"
            >
                <i class="bi bi-printer"></i>
                Print
            </button>

            <a
                href="job-view.php?id=<?= (int) $workOrder['job_id']; ?>"
                class="wv-btn"
            >
                <i class="bi bi-briefcase"></i>
                View Job
            </a>

            <?php if ($canManage): ?>
                <a
                    href="work-order-edit.php?id=<?= (int) $workOrder['id']; ?>"
                    class="wv-btn primary"
                >
                    <i class="bi bi-pencil"></i>
                    Edit
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="wv-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="wv-alert error">
            <?= e($_SESSION['flash_error']); ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="wv-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="wv-stats">
        <article class="wv-stat">
            <div class="wv-stat-label">Current Status</div>
            <div class="wv-stat-value">
                <?= e(
                    workOrderViewLabel(
                        $workOrder['status']
                    )
                ); ?>
            </div>
        </article>

        <article class="wv-stat">
            <div class="wv-stat-label">Planned Duration</div>
            <div class="wv-stat-value">
                <?= e($plannedDuration); ?>
            </div>
        </article>

        <article class="wv-stat">
            <div class="wv-stat-label">Actual Duration</div>
            <div class="wv-stat-value">
                <?= e($actualDuration); ?>
            </div>
        </article>

        <article class="wv-stat">
            <div class="wv-stat-label">Job Total</div>
            <div class="wv-stat-value">
                <?= e(
                    workOrderViewMoney(
                        $workOrder['job_total']
                    )
                ); ?>
            </div>
        </article>
    </section>

    <div class="wv-layout">
        <main>
            <section class="wv-card">
                <div class="wv-card-head">
                    <div>
                        <h2>Work Instructions</h2>
                        <p>Work description and safety requirements.</p>
                    </div>
                </div>

                <div class="wv-card-body">
                    <div class="wv-details">
                        <div class="wv-detail full">
                            <div class="wv-detail-label">
                                Work Description
                            </div>
                            <div class="wv-detail-value">
                                <?php if (
                                    trim((string) $workOrder['work_description']) !== ''
                                ): ?>
                                    <p class="wv-text"><?= e(
                                        $workOrder['work_description']
                                    ); ?></p>
                                <?php else: ?>
                                    <span class="wv-empty-text">
                                        No work description added.
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="wv-detail full">
                            <div class="wv-detail-label">
                                Safety Instructions
                            </div>
                            <div class="wv-detail-value">
                                <?php if (
                                    trim((string) $workOrder['safety_instructions']) !== ''
                                ): ?>
                                    <p class="wv-text"><?= e(
                                        $workOrder['safety_instructions']
                                    ); ?></p>
                                <?php else: ?>
                                    <span class="wv-empty-text">
                                        No safety instructions added.
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="wv-detail full">
                            <div class="wv-detail-label">
                                Completion Notes
                            </div>
                            <div class="wv-detail-value">
                                <?php if (
                                    trim((string) $workOrder['completion_notes']) !== ''
                                ): ?>
                                    <p class="wv-text"><?= e(
                                        $workOrder['completion_notes']
                                    ); ?></p>
                                <?php else: ?>
                                    <span class="wv-empty-text">
                                        No completion notes added.
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="wv-card">
                <div class="wv-card-head">
                    <div>
                        <h2>Schedule and Progress</h2>
                        <p>Planned, actual, and milestone timestamps.</p>
                    </div>
                </div>

                <div class="wv-card-body">
                    <div class="wv-details">
                        <div class="wv-detail">
                            <div class="wv-detail-label">
                                Scheduled Start
                            </div>
                            <span class="wv-detail-value">
                                <?= e(
                                    workOrderViewDateTime(
                                        $workOrder['scheduled_start']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="wv-detail">
                            <div class="wv-detail-label">
                                Scheduled End
                            </div>
                            <span class="wv-detail-value">
                                <?= e(
                                    workOrderViewDateTime(
                                        $workOrder['scheduled_end']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="wv-detail">
                            <div class="wv-detail-label">
                                Actual Start
                            </div>
                            <span class="wv-detail-value">
                                <?= e(
                                    workOrderViewDateTime(
                                        $workOrder['actual_start']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="wv-detail">
                            <div class="wv-detail-label">
                                Actual End
                            </div>
                            <span class="wv-detail-value">
                                <?= e(
                                    workOrderViewDateTime(
                                        $workOrder['actual_end']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="wv-detail">
                            <div class="wv-detail-label">Issued At</div>
                            <span class="wv-detail-value">
                                <?= e(
                                    workOrderViewDateTime(
                                        $workOrder['issued_at']
                                    )
                                ); ?>
                            </span>
                            <span class="wv-detail-sub">
                                <?= e(
                                    trim((string) $workOrder['issued_by_name']) !== ''
                                        ? $workOrder['issued_by_name']
                                        : 'Not issued'
                                ); ?>
                            </span>
                        </div>

                        <div class="wv-detail">
                            <div class="wv-detail-label">Accepted At</div>
                            <span class="wv-detail-value">
                                <?= e(
                                    workOrderViewDateTime(
                                        $workOrder['accepted_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="wv-detail">
                            <div class="wv-detail-label">Completed At</div>
                            <span class="wv-detail-value">
                                <?= e(
                                    workOrderViewDateTime(
                                        $workOrder['completed_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="wv-detail">
                            <div class="wv-detail-label">Created At</div>
                            <span class="wv-detail-value">
                                <?= e(
                                    workOrderViewDateTime(
                                        $workOrder['created_at']
                                    )
                                ); ?>
                            </span>
                            <span class="wv-detail-sub">
                                <?= e(
                                    trim((string) $workOrder['created_by_name']) !== ''
                                        ? $workOrder['created_by_name']
                                        : 'System'
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="wv-card">
                <div class="wv-card-head">
                    <div>
                        <h2>Job Items</h2>
                        <p>Products, services, quantities, and job values.</p>
                    </div>
                </div>

                <?php if (!empty($lineItems)): ?>
                    <div class="wv-table-wrap">
                        <table class="wv-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="number">Qty</th>
                                    <th class="number">Unit Price</th>
                                    <th class="number">Tax</th>
                                    <th class="number">Line Total</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($lineItems as $item): ?>
                                    <tr>
                                        <td>
                                            <span class="wv-item-name">
                                                <?= e($item['item_name']); ?>
                                            </span>

                                            <?php if (
                                                trim((string) $item['description']) !== ''
                                            ): ?>
                                                <span class="wv-item-desc">
                                                    <?= e($item['description']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="number">
                                            <?= e(
                                                number_format(
                                                    (float) $item['quantity'],
                                                    3
                                                )
                                            ); ?>
                                        </td>

                                        <td class="number">
                                            <?= e(
                                                workOrderViewMoney(
                                                    $item['unit_price']
                                                )
                                            ); ?>
                                        </td>

                                        <td class="number">
                                            <?= e(
                                                workOrderViewMoney(
                                                    $item['tax_amount']
                                                )
                                            ); ?>
                                        </td>

                                        <td class="number">
                                            <strong><?= e(
                                                workOrderViewMoney(
                                                    $item['line_total']
                                                )
                                            ); ?></strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                            <tfoot>
                                <tr>
                                    <th colspan="4" class="number">
                                        Job Subtotal
                                    </th>
                                    <th class="number">
                                        <?= e(
                                            workOrderViewMoney(
                                                $workOrder['job_subtotal']
                                            )
                                        ); ?>
                                    </th>
                                </tr>
                                <tr>
                                    <th colspan="4" class="number">
                                        Job Tax
                                    </th>
                                    <th class="number">
                                        <?= e(
                                            workOrderViewMoney(
                                                $workOrder['job_tax_total']
                                            )
                                        ); ?>
                                    </th>
                                </tr>
                                <tr>
                                    <th colspan="4" class="number">
                                        Job Total
                                    </th>
                                    <th class="number">
                                        <?= e(
                                            workOrderViewMoney(
                                                $workOrder['job_total']
                                            )
                                        ); ?>
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="wv-card-body">
                        <span class="wv-empty-text">
                            No line items are attached to this job.
                        </span>
                    </div>
                <?php endif; ?>
            </section>

            <?php if (
                !empty($attachments) ||
                !empty($workOrder['signature_attachment_id'])
            ): ?>
                <section class="wv-card">
                    <div class="wv-card-head">
                        <div>
                            <h2>Files and Signature</h2>
                            <p>Documents and customer signature attached to the work order.</p>
                        </div>
                    </div>

                    <div class="wv-card-body">
                        <?php if (
                            !empty($workOrder['signature_attachment_id'])
                        ): ?>
                            <div class="wv-signature">
                                <div class="wv-signature-title">
                                    <i class="bi bi-pen"></i>
                                    Customer Signature
                                </div>

                                <div class="wv-signature-meta">
                                    <?= e(
                                        $workOrder['signature_file_name']
                                    ); ?>
                                    · Uploaded
                                    <?= e(
                                        workOrderViewDateTime(
                                            $workOrder['signature_created_at']
                                        )
                                    ); ?>
                                </div>

                                <?php if (
                                    strpos(
                                        (string) $workOrder['signature_file_mime'],
                                        'image/'
                                    ) === 0
                                ): ?>
                                    <a
                                        href="<?= e($workOrder['signature_file_path']); ?>"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        <img
                                            src="<?= e($workOrder['signature_file_path']); ?>"
                                            alt="Customer signature"
                                            class="wv-signature-preview"
                                        >
                                    </a>
                                <?php else: ?>
                                    <div style="margin-top:10px;">
                                        <a
                                            href="<?= e($workOrder['signature_file_path']); ?>"
                                            target="_blank"
                                            rel="noopener"
                                            class="wv-btn"
                                        >
                                            <i class="bi bi-box-arrow-up-right"></i>
                                            Open Signature File
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($attachments)): ?>
                            <div
                                class="wv-file-list"
                                style="margin-top:<?= !empty($workOrder['signature_attachment_id']) ? '12px' : '0'; ?>;"
                            >
                                <?php foreach ($attachments as $attachment): ?>
                                    <div class="wv-file-item">
                                        <div class="wv-file-main">
                                            <div class="wv-file-name">
                                                <?= e($attachment['file_name']); ?>
                                            </div>
                                            <div class="wv-file-meta">
                                                <?= e(
                                                    workOrderViewLabel(
                                                        $attachment['attachment_type']
                                                    )
                                                ); ?>
                                                · <?= e(
                                                    workOrderViewDateTime(
                                                        $attachment['created_at']
                                                    )
                                                ); ?>

                                                <?php if (
                                                    trim((string) $attachment['description']) !== ''
                                                ): ?>
                                                    · <?= e($attachment['description']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <a
                                            href="<?= e($attachment['file_path']); ?>"
                                            target="_blank"
                                            rel="noopener"
                                            class="wv-file-open"
                                            title="Open File"
                                        >
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="wv-card">
                <div class="wv-card-head">
                    <div>
                        <h2>Activity History</h2>
                        <p>Recent work-order and job activity.</p>
                    </div>
                </div>

                <div class="wv-card-body">
                    <?php if (!empty($activities)): ?>
                        <div class="wv-timeline">
                            <?php foreach ($activities as $activity): ?>
                                <div class="wv-timeline-item">
                                    <div class="wv-timeline-title">
                                        <?= e($activity['title']); ?>
                                    </div>
                                    <div class="wv-timeline-meta">
                                        <?= e(
                                            trim((string) $activity['actor_name']) !== ''
                                                ? $activity['actor_name']
                                                : workOrderViewLabel(
                                                    $activity['actor_type']
                                                )
                                        ); ?>
                                        · <?= e(
                                            workOrderViewDateTime(
                                                $activity['created_at']
                                            )
                                        ); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span class="wv-empty-text">
                            No activity has been recorded yet.
                        </span>
                    <?php endif; ?>
                </div>
            </section>
        </main>

        <aside>
            <?php if (
                $canManage &&
                !empty($currentActions)
            ): ?>
                <section class="wv-card wv-action-card">
                    <div class="wv-card-head">
                        <div>
                            <h2>Work Order Actions</h2>
                            <p>Move the work order to its next status.</p>
                        </div>
                    </div>

                    <div class="wv-card-body">
                        <div class="wv-action-stack">
                            <?php foreach (
                                $currentActions as
                                $nextStatus => $action
                            ): ?>
                                <form
                                    method="post"
                                    action="work-order-view.php?id=<?= (int) $workOrderId; ?>"
                                    onsubmit="return confirm('Change this work order to <?= e(workOrderViewLabel($nextStatus)); ?>?');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($csrfToken); ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="update_status"
                                    >
                                    <input
                                        type="hidden"
                                        name="new_status"
                                        value="<?= e($nextStatus); ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="wv-btn <?= e($action['class']); ?>"
                                    >
                                        <i class="bi <?= e($action['icon']); ?>"></i>
                                        <?= e($action['label']); ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="wv-card">
                <div class="wv-card-head">
                    <div>
                        <h2>Job Details</h2>
                    </div>
                </div>

                <div class="wv-card-body">
                    <div class="wv-details">
                        <div class="wv-detail full">
                            <div class="wv-detail-label">Job</div>
                            <span class="wv-detail-value">
                                <a
                                    href="job-view.php?id=<?= (int) $workOrder['job_id']; ?>"
                                    class="wv-link"
                                >
                                    <?= e($workOrder['job_no']); ?>
                                    · <?= e($workOrder['job_title']); ?>
                                </a>
                            </span>
                            <span class="wv-detail-sub">
                                <?= e(
                                    workOrderViewLabel(
                                        $workOrder['job_status']
                                    )
                                ); ?>
                                · <?= e(
                                    workOrderViewLabel(
                                        $workOrder['job_type']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="wv-detail full">
                            <div class="wv-detail-label">
                                Assigned Worker
                            </div>
                            <span class="wv-detail-value">
                                <?= e(
                                    trim((string) $workOrder['assigned_user_name']) !== ''
                                        ? $workOrder['assigned_user_name']
                                        : 'Unassigned'
                                ); ?>
                            </span>

                            <?php if (
                                trim((string) $workOrder['assigned_user_phone']) !== ''
                            ): ?>
                                <span class="wv-detail-sub">
                                    <?= e($workOrder['assigned_user_phone']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="wv-detail">
                            <div class="wv-detail-label">Job Start</div>
                            <span class="wv-detail-value">
                                <?= e(
                                    workOrderViewDate(
                                        $workOrder['job_start_date']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="wv-detail">
                            <div class="wv-detail-label">Job End</div>
                            <span class="wv-detail-value">
                                <?= e(
                                    workOrderViewDate(
                                        $workOrder['job_end_date']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="wv-detail full">
                            <div class="wv-detail-label">
                                Invoicing Preference
                            </div>
                            <span class="wv-detail-value">
                                <?= e(
                                    workOrderViewLabel(
                                        $workOrder['invoicing_preference']
                                    )
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="wv-card">
                <div class="wv-card-head">
                    <div>
                        <h2>Customer</h2>
                    </div>
                </div>

                <div class="wv-card-body">
                    <div class="wv-details">
                        <div class="wv-detail full">
                            <div class="wv-detail-label">Client</div>
                            <span class="wv-detail-value">
                                <a
                                    href="client-view.php?id=<?= (int) $workOrder['client_id']; ?>"
                                    class="wv-link"
                                >
                                    <?= e($workOrder['client_name']); ?>
                                </a>
                            </span>

                            <?php if (
                                trim((string) $workOrder['client_company']) !== '' &&
                                trim((string) $workOrder['client_company']) !==
                                    trim((string) $workOrder['client_name'])
                            ): ?>
                                <span class="wv-detail-sub">
                                    <?= e($workOrder['client_company']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="wv-detail full">
                            <div class="wv-detail-label">Phone</div>
                            <span class="wv-detail-value">
                                <?= e(
                                    trim((string) $workOrder['client_phone']) !== ''
                                        ? $workOrder['client_phone']
                                        : '—'
                                ); ?>
                            </span>

                            <?php if (
                                trim((string) $workOrder['client_alternate_phone']) !== ''
                            ): ?>
                                <span class="wv-detail-sub">
                                    Alternate:
                                    <?= e($workOrder['client_alternate_phone']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="wv-detail full">
                            <div class="wv-detail-label">Email</div>
                            <span class="wv-detail-value">
                                <?= e(
                                    trim((string) $workOrder['client_email']) !== ''
                                        ? $workOrder['client_email']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="wv-detail full">
                            <div class="wv-detail-label">
                                Preferred Contact
                            </div>
                            <span class="wv-detail-value">
                                <?= e(
                                    workOrderViewLabel(
                                        $workOrder['preferred_contact_method']
                                    )
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="wv-card">
                <div class="wv-card-head">
                    <div>
                        <h2>Service Location</h2>
                    </div>
                </div>

                <div class="wv-card-body">
                    <?php if (!empty($workOrder['property_id'])): ?>
                        <div class="wv-details">
                            <div class="wv-detail full">
                                <div class="wv-detail-label">Property</div>
                                <span class="wv-detail-value">
                                    <a
                                        href="property-view.php?id=<?= (int) $workOrder['property_id']; ?>"
                                        class="wv-link"
                                    >
                                        <?= e(
                                            trim((string) $workOrder['property_name']) !== ''
                                                ? $workOrder['property_name']
                                                : 'Service Property'
                                        ); ?>
                                    </a>
                                </span>
                                <span class="wv-detail-sub">
                                    <?= e(
                                        $propertyAddress !== ''
                                            ? $propertyAddress
                                            : 'No address available'
                                    ); ?>
                                </span>
                            </div>

                            <?php if (
                                trim((string) $workOrder['gate_code']) !== ''
                            ): ?>
                                <div class="wv-detail full">
                                    <div class="wv-detail-label">Gate Code</div>
                                    <span class="wv-detail-value">
                                        <?= e($workOrder['gate_code']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (
                                trim((string) $workOrder['access_notes']) !== ''
                            ): ?>
                                <div class="wv-detail full">
                                    <div class="wv-detail-label">Access Notes</div>
                                    <span class="wv-detail-value">
                                        <?= e($workOrder['access_notes']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (
                                trim((string) $workOrder['service_instructions']) !== ''
                            ): ?>
                                <div class="wv-detail full">
                                    <div class="wv-detail-label">
                                        Service Instructions
                                    </div>
                                    <span class="wv-detail-value">
                                        <?= e($workOrder['service_instructions']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span class="wv-empty-text">
                            No property is linked to this work order.
                        </span>
                    <?php endif; ?>
                </div>
            </section>

            <?php if (!empty($assignments)): ?>
                <section class="wv-card">
                    <div class="wv-card-head">
                        <div>
                            <h2>Assigned Team</h2>
                        </div>
                    </div>

                    <div class="wv-card-body">
                        <div class="wv-team-list">
                            <?php foreach ($assignments as $assignment): ?>
                                <div class="wv-team-item">
                                    <div class="wv-team-main">
                                        <div class="wv-team-name">
                                            <?= e(
                                                trim((string) $assignment['user_name']) !== ''
                                                    ? $assignment['user_name']
                                                    : 'Team Assignment'
                                            ); ?>
                                        </div>
                                        <div class="wv-team-meta">
                                            <?= e(
                                                workOrderViewLabel(
                                                    $assignment['assignment_role']
                                                )
                                            ); ?>
                                            · <?= e(
                                                workOrderViewLabel(
                                                    $assignment['status']
                                                )
                                            ); ?>
                                            · Assigned
                                            <?= e(
                                                workOrderViewDateTime(
                                                    $assignment['assigned_at']
                                                )
                                            ); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="wv-card">
                <div class="wv-card-head">
                    <div>
                        <h2>Record Information</h2>
                    </div>
                </div>

                <div class="wv-card-body">
                    <div class="wv-details">
                        <div class="wv-detail full">
                            <div class="wv-detail-label">Created By</div>
                            <span class="wv-detail-value">
                                <?= e(
                                    trim((string) $workOrder['created_by_name']) !== ''
                                        ? $workOrder['created_by_name']
                                        : 'System'
                                ); ?>
                            </span>
                            <span class="wv-detail-sub">
                                <?= e(
                                    workOrderViewDateTime(
                                        $workOrder['created_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="wv-detail full">
                            <div class="wv-detail-label">Last Updated</div>
                            <span class="wv-detail-value">
                                <?= e(
                                    workOrderViewDateTime(
                                        $workOrder['updated_at']
                                    )
                                ); ?>
                            </span>
                            <span class="wv-detail-sub">
                                <?= e(
                                    trim((string) $workOrder['updated_by_name']) !== ''
                                        ? $workOrder['updated_by_name']
                                        : 'No update user recorded'
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
