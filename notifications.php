<?php
/**
 * FieldPlx - Notifications
 *
 * Upload as:
 * /public_html/notifications.php
 *
 * PHP 7.2+ / MariaDB / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| Authentication and access
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('notifications.php')
    );
    exit;
}

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$canViewNotifications = true;

if (function_exists('hasPermission')) {
    $canViewNotifications =
        hasPermission('notifications.view') ||
        hasPermission('messages.view');

    if (
        function_exists('isTenantOwner') &&
        isTenantOwner()
    ) {
        $canViewNotifications = true;
    }
}

if (!$canViewNotifications) {
    http_response_code(403);

    exit(
        '403 - Access Denied. ' .
        'You do not have permission to view notifications.'
    );
}

$pageTitle = 'Notifications - FieldPlx';
$activePage = 'notifications';
$searchPlaceholder = 'Search notifications...';
$basePath = '';

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('notificationsFetchAssoc')) {
    function notificationsFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('notificationsFetchAll')) {
    function notificationsFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('notificationsBindParams')) {
    function notificationsBindParams(
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

if (!function_exists('notificationsPost')) {
    function notificationsPost($key, $default = '')
    {
        if (
            isset($_POST[$key]) &&
            !is_array($_POST[$key])
        ) {
            return trim((string) $_POST[$key]);
        }

        return $default;
    }
}

if (!function_exists('notificationsCsrfToken')) {
    function notificationsCsrfToken()
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

if (!function_exists('notificationsVerifyCsrf')) {
    function notificationsVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('notificationsTableExists')) {
    function notificationsTableExists(
        mysqli $conn,
        $tableName
    ) {
        if (
            function_exists('dbTableExists')
        ) {
            return dbTableExists(
                $conn,
                $tableName
            );
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            's',
            $tableName
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $row =
            notificationsFetchAssoc($stmt);

        $stmt->close();

        return $row &&
            (int) $row['total'] > 0;
    }
}

if (!function_exists('notificationsLabel')) {
    function notificationsLabel($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 'Notification';
        }

        return ucwords(
            str_replace(
                array('_', '-', '.'),
                ' ',
                $value
            )
        );
    }
}

if (!function_exists('notificationsDateTime')) {
    function notificationsDateTime($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp =
            strtotime((string) $value);

        return $timestamp
            ? date('d M Y, h:i A', $timestamp)
            : '—';
    }
}

if (!function_exists('notificationsTimeAgo')) {
    function notificationsTimeAgo($value)
    {
        if (
            function_exists('timeAgo')
        ) {
            return timeAgo($value);
        }

        $timestamp =
            strtotime((string) $value);

        if (!$timestamp) {
            return '—';
        }

        $difference =
            time() - $timestamp;

        if ($difference < 60) {
            return 'Just now';
        }

        if ($difference < 3600) {
            $minutes =
                (int) floor(
                    $difference / 60
                );

            return $minutes .
                ' minute' .
                ($minutes === 1 ? '' : 's') .
                ' ago';
        }

        if ($difference < 86400) {
            $hours =
                (int) floor(
                    $difference / 3600
                );

            return $hours .
                ' hour' .
                ($hours === 1 ? '' : 's') .
                ' ago';
        }

        if ($difference < 604800) {
            $days =
                (int) floor(
                    $difference / 86400
                );

            return $days .
                ' day' .
                ($days === 1 ? '' : 's') .
                ' ago';
        }

        return date(
            'd M Y',
            $timestamp
        );
    }
}

if (!function_exists('notificationsQueryString')) {
    function notificationsQueryString(
        array $overrides = array()
    ) {
        $query = $_GET;

        unset($query['open']);

        foreach ($overrides as $key => $value) {
            if (
                $value === null ||
                $value === ''
            ) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return http_build_query($query);
    }
}

if (!function_exists('notificationsDecodePayload')) {
    function notificationsDecodePayload($payloadJson)
    {
        if (
            $payloadJson === null ||
            trim((string) $payloadJson) === ''
        ) {
            return array();
        }

        $payload = json_decode(
            (string) $payloadJson,
            true
        );

        return is_array($payload)
            ? $payload
            : array();
    }
}

if (!function_exists('notificationsSafeUrl')) {
    function notificationsSafeUrl($url)
    {
        $url = trim((string) $url);

        if ($url === '') {
            return 'notifications.php';
        }

        if (
            preg_match(
                '/[\x00-\x1F\x7F]/',
                $url
            )
        ) {
            return 'notifications.php';
        }

        if (
            preg_match(
                '/^(javascript|data|vbscript):/i',
                $url
            )
        ) {
            return 'notifications.php';
        }

        if (strpos($url, '//') === 0) {
            return 'notifications.php';
        }

        if (
            preg_match(
                '/^https?:\/\//i',
                $url
            )
        ) {
            return filter_var(
                $url,
                FILTER_VALIDATE_URL
            )
                ? $url
                : 'notifications.php';
        }

        return $url;
    }
}

if (!function_exists('notificationsIcon')) {
    function notificationsIcon(
        $eventKey,
        $relatedType
    ) {
        $text = strtolower(
            trim(
                (string) $eventKey .
                ' ' .
                (string) $relatedType
            )
        );

        $map = array(
            'payment' => array(
                'bi-cash-coin',
                'green'
            ),
            'invoice' => array(
                'bi-receipt',
                'green'
            ),
            'quote' => array(
                'bi-file-earmark-text',
                'blue'
            ),
            'job' => array(
                'bi-briefcase',
                'purple'
            ),
            'work_order' => array(
                'bi-clipboard-check',
                'orange'
            ),
            'work order' => array(
                'bi-clipboard-check',
                'orange'
            ),
            'visit' => array(
                'bi-geo-alt',
                'blue'
            ),
            'route' => array(
                'bi-sign-turn-right',
                'blue'
            ),
            'task' => array(
                'bi-check2-square',
                'orange'
            ),
            'booking' => array(
                'bi-calendar2-check',
                'purple'
            ),
            'request' => array(
                'bi-inbox',
                'purple'
            ),
            'message' => array(
                'bi-chat-dots',
                'blue'
            ),
            'client' => array(
                'bi-person',
                'purple'
            ),
            'customer' => array(
                'bi-person',
                'purple'
            ),
            'failed' => array(
                'bi-exclamation-triangle',
                'red'
            ),
            'error' => array(
                'bi-exclamation-triangle',
                'red'
            )
        );

        foreach ($map as $keyword => $icon) {
            if (
                strpos(
                    $text,
                    $keyword
                ) !== false
            ) {
                return $icon;
            }
        }

        return array(
            'bi-bell',
            'purple'
        );
    }
}

if (!function_exists('notificationsReturnUrl')) {
    function notificationsReturnUrl()
    {
        $query =
            notificationsPost(
                'return_query'
            );

        if ($query === '') {
            return 'notifications.php';
        }

        parse_str(
            $query,
            $parsed
        );

        if (!is_array($parsed)) {
            return 'notifications.php';
        }

        unset(
            $parsed['open'],
            $parsed['action'],
            $parsed['notification_id']
        );

        return 'notifications.php' .
            (
                !empty($parsed)
                    ? '?' .
                        http_build_query(
                            $parsed
                        )
                    : ''
            );
    }
}

/*
|--------------------------------------------------------------------------
| Notification table availability
|--------------------------------------------------------------------------
*/

$notificationTableAvailable =
    notificationsTableExists(
        $conn,
        'notification_logs'
    );

if (!$notificationTableAvailable) {
    $errors[] =
        'The notification_logs table is not available.';
}

/*
|--------------------------------------------------------------------------
| Notification actions
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $notificationTableAvailable
) {
    $csrfToken =
        notificationsPost('csrf_token');

    if (
        !notificationsVerifyCsrf(
            $csrfToken
        )
    ) {
        $errors[] =
            'Your session token is invalid. Refresh the page and try again.';
    }

    $action =
        notificationsPost('action');

    $notificationId =
        (int) notificationsPost(
            'notification_id',
            '0'
        );

    if (
        empty($errors) &&
        $action === 'mark_all_read'
    ) {
        $stmt = $conn->prepare("
            UPDATE notification_logs
            SET status = 'read'
            WHERE tenant_id = ?
              AND recipient_type = 'user'
              AND recipient_id = ?
              AND channel = 'in_app'
              AND status <> 'read'
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to prepare the notification update.';
        } else {
            $stmt->bind_param(
                'ii',
                $tenantId,
                $currentUserId
            );

            if (!$stmt->execute()) {
                $errors[] =
                    'Unable to mark notifications as read.';
            } else {
                $_SESSION['flash_success'] =
                    'All notifications marked as read.';

                $stmt->close();

                header(
                    'Location: ' .
                    notificationsReturnUrl()
                );
                exit;
            }

            $stmt->close();
        }
    }

    if (
        empty($errors) &&
        in_array(
            $action,
            array(
                'mark_read',
                'mark_unread',
                'open_notification'
            ),
            true
        )
    ) {
        if ($notificationId <= 0) {
            $errors[] =
                'Invalid notification selected.';
        } else {
            $stmt = $conn->prepare("
                SELECT
                    id,
                    status,
                    payload_json
                FROM notification_logs
                WHERE id = ?
                  AND tenant_id = ?
                  AND recipient_type = 'user'
                  AND recipient_id = ?
                  AND channel = 'in_app'
                LIMIT 1
            ");

            if (!$stmt) {
                $errors[] =
                    'Unable to prepare the notification query.';
            } else {
                $stmt->bind_param(
                    'iii',
                    $notificationId,
                    $tenantId,
                    $currentUserId
                );

                if (!$stmt->execute()) {
                    $errors[] =
                        'Unable to load the selected notification.';
                } else {
                    $notification =
                        notificationsFetchAssoc(
                            $stmt
                        );
                }

                $stmt->close();

                if (
                    empty($errors) &&
                    empty($notification)
                ) {
                    $errors[] =
                        'Notification not found or access denied.';
                }
            }
        }

        if (empty($errors)) {
            $newStatus =
                $action === 'mark_unread'
                    ? 'delivered'
                    : 'read';

            $stmt = $conn->prepare("
                UPDATE notification_logs
                SET status = ?
                WHERE id = ?
                  AND tenant_id = ?
                  AND recipient_type = 'user'
                  AND recipient_id = ?
                  AND channel = 'in_app'
                LIMIT 1
            ");

            if (!$stmt) {
                $errors[] =
                    'Unable to prepare the notification status update.';
            } else {
                $stmt->bind_param(
                    'siiii',
                    $newStatus,
                    $notificationId,
                    $tenantId,
                    $currentUserId
                );

                if (!$stmt->execute()) {
                    $errors[] =
                        'Unable to update the notification.';
                }

                $stmt->close();
            }
        }

        if (
            empty($errors) &&
            $action === 'open_notification'
        ) {
            $payload =
                notificationsDecodePayload(
                    $notification['payload_json']
                );

            $url =
                notificationsSafeUrl(
                    isset($payload['url'])
                        ? $payload['url']
                        : 'notifications.php'
                );

            header(
                'Location: ' . $url
            );
            exit;
        }

        if (empty($errors)) {
            $_SESSION['flash_success'] =
                $action === 'mark_unread'
                    ? 'Notification marked as unread.'
                    : 'Notification marked as read.';

            header(
                'Location: ' .
                notificationsReturnUrl()
            );
            exit;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search'])
    ? trim((string) $_GET['search'])
    : '';

$statusFilter = isset($_GET['status'])
    ? trim((string) $_GET['status'])
    : '';

$eventFilter = isset($_GET['event'])
    ? trim((string) $_GET['event'])
    : '';

$dateFrom = isset($_GET['date_from'])
    ? trim((string) $_GET['date_from'])
    : '';

$dateTo = isset($_GET['date_to'])
    ? trim((string) $_GET['date_to'])
    : '';

$sort = isset($_GET['sort'])
    ? trim((string) $_GET['sort'])
    : 'latest';

$allowedStatuses = array(
    '',
    'unread',
    'read',
    'queued',
    'sent',
    'delivered',
    'failed',
    'suppressed'
);

$allowedSorts = array(
    'latest',
    'oldest',
    'unread_first',
    'event_asc',
    'status_asc'
);

if (
    !in_array(
        $statusFilter,
        $allowedStatuses,
        true
    )
) {
    $statusFilter = '';
}

if (
    !in_array(
        $sort,
        $allowedSorts,
        true
    )
) {
    $sort = 'latest';
}

if (
    $dateFrom !== '' &&
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $dateFrom
    )
) {
    $dateFrom = '';
}

if (
    $dateTo !== '' &&
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $dateTo
    )
) {
    $dateTo = '';
}

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 20;
$offset =
    ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Event options
|--------------------------------------------------------------------------
*/

$eventOptions = array();

if ($notificationTableAvailable) {
    $stmt = $conn->prepare("
        SELECT DISTINCT event_key
        FROM notification_logs
        WHERE tenant_id = ?
          AND recipient_type = 'user'
          AND recipient_id = ?
          AND channel = 'in_app'
        ORDER BY event_key ASC
    ");

    if ($stmt) {
        $stmt->bind_param(
            'ii',
            $tenantId,
            $currentUserId
        );

        if ($stmt->execute()) {
            $eventOptions =
                notificationsFetchAll(
                    $stmt
                );
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$stats = array(
    'total' => 0,
    'unread' => 0,
    'read' => 0,
    'today' => 0,
    'week' => 0,
    'failed' => 0
);

if ($notificationTableAvailable) {
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_count,
            SUM(status <> 'read') AS unread_count,
            SUM(status = 'read') AS read_count,
            SUM(
                DATE(created_at) = CURDATE()
            ) AS today_count,
            SUM(
                created_at >=
                DATE_SUB(
                    NOW(),
                    INTERVAL 7 DAY
                )
            ) AS week_count,
            SUM(status = 'failed') AS failed_count
        FROM notification_logs
        WHERE tenant_id = ?
          AND recipient_type = 'user'
          AND recipient_id = ?
          AND channel = 'in_app'
    ");

    if ($stmt) {
        $stmt->bind_param(
            'ii',
            $tenantId,
            $currentUserId
        );

        if ($stmt->execute()) {
            $row =
                notificationsFetchAssoc(
                    $stmt
                );

            if ($row) {
                $stats['total'] =
                    (int) $row[
                        'total_count'
                    ];

                $stats['unread'] =
                    (int) $row[
                        'unread_count'
                    ];

                $stats['read'] =
                    (int) $row[
                        'read_count'
                    ];

                $stats['today'] =
                    (int) $row[
                        'today_count'
                    ];

                $stats['week'] =
                    (int) $row[
                        'week_count'
                    ];

                $stats['failed'] =
                    (int) $row[
                        'failed_count'
                    ];
            }
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Build notification query
|--------------------------------------------------------------------------
*/

$where = array(
    'nl.tenant_id = ?',
    "nl.recipient_type = 'user'",
    'nl.recipient_id = ?',
    "nl.channel = 'in_app'"
);

$params = array(
    $tenantId,
    $currentUserId
);

$types = 'ii';

if ($search !== '') {
    $where[] = "(
        nl.event_key LIKE ?
        OR nl.related_type LIKE ?
        OR nl.payload_json LIKE ?
        OR nl.error_message LIKE ?
    )";

    $searchLike =
        '%' . $search . '%';

    for ($index = 0; $index < 4; $index++) {
        $params[] =
            $searchLike;

        $types .= 's';
    }
}

if ($statusFilter === 'unread') {
    $where[] =
        "nl.status <> 'read'";
} elseif ($statusFilter !== '') {
    $where[] =
        'nl.status = ?';

    $params[] =
        $statusFilter;

    $types .= 's';
}

if ($eventFilter !== '') {
    $where[] =
        'nl.event_key = ?';

    $params[] =
        $eventFilter;

    $types .= 's';
}

if ($dateFrom !== '') {
    $where[] =
        'DATE(nl.created_at) >= ?';

    $params[] =
        $dateFrom;

    $types .= 's';
}

if ($dateTo !== '') {
    $where[] =
        'DATE(nl.created_at) <= ?';

    $params[] =
        $dateTo;

    $types .= 's';
}

$whereSql =
    implode(' AND ', $where);

$orderSql =
    'nl.created_at DESC, nl.id DESC';

if ($sort === 'oldest') {
    $orderSql =
        'nl.created_at ASC, nl.id ASC';
} elseif ($sort === 'unread_first') {
    $orderSql = "
        CASE
            WHEN nl.status = 'read'
            THEN 1
            ELSE 0
        END ASC,
        nl.created_at DESC,
        nl.id DESC
    ";
} elseif ($sort === 'event_asc') {
    $orderSql =
        'nl.event_key ASC, nl.created_at DESC';
} elseif ($sort === 'status_asc') {
    $orderSql =
        'nl.status ASC, nl.created_at DESC';
}

/*
|--------------------------------------------------------------------------
| Count filtered notifications
|--------------------------------------------------------------------------
*/

$totalFiltered = 0;

if ($notificationTableAvailable) {
    $countSql = "
        SELECT COUNT(*) AS total
        FROM notification_logs nl
        WHERE {$whereSql}
    ";

    $stmt = $conn->prepare(
        $countSql
    );

    if (!$stmt) {
        $errors[] =
            'Unable to prepare the notification count query: ' .
            $conn->error;
    } else {
        if (
            !notificationsBindParams(
                $stmt,
                $types,
                $params
            )
        ) {
            $errors[] =
                'Unable to bind notification filters.';
        } elseif (!$stmt->execute()) {
            $errors[] =
                'Unable to count notifications.';
        } else {
            $row =
                notificationsFetchAssoc(
                    $stmt
                );

            if ($row) {
                $totalFiltered =
                    (int) $row['total'];
            }
        }

        $stmt->close();
    }
}

$totalPages = max(
    1,
    (int) ceil(
        $totalFiltered / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
    $offset =
        ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| Load notifications
|--------------------------------------------------------------------------
*/

$notifications = array();

if ($notificationTableAvailable) {
    $listSql = "
        SELECT
            nl.id,
            nl.event_key,
            nl.related_type,
            nl.related_id,
            nl.status,
            nl.payload_json,
            nl.error_message,
            nl.created_at,
            nl.sent_at
        FROM notification_logs nl
        WHERE {$whereSql}
        ORDER BY {$orderSql}
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare(
        $listSql
    );

    if (!$stmt) {
        $errors[] =
            'Unable to prepare the notification list query: ' .
            $conn->error;
    } else {
        $listParams = $params;
        $listTypes =
            $types . 'ii';

        $listParams[] =
            $perPage;

        $listParams[] =
            $offset;

        if (
            !notificationsBindParams(
                $stmt,
                $listTypes,
                $listParams
            )
        ) {
            $errors[] =
                'Unable to bind the notification list filters.';
        } elseif (!$stmt->execute()) {
            $errors[] =
                'Unable to load notifications.';
        } else {
            $notifications =
                notificationsFetchAll(
                    $stmt
                );
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Prepare display data
|--------------------------------------------------------------------------
*/

foreach ($notifications as $index => $notification) {
    $payload =
        notificationsDecodePayload(
            $notification['payload_json']
        );

    $title =
        isset($payload['title']) &&
        trim((string) $payload['title']) !== ''
            ? trim(
                (string) $payload['title']
            )
            : notificationsLabel(
                $notification['event_key']
            );

    $message =
        isset($payload['message'])
            ? trim(
                (string) $payload['message']
            )
            : '';

    if (
        $message === '' &&
        $notification['status'] === 'failed' &&
        trim(
            (string) $notification[
                'error_message'
            ]
        ) !== ''
    ) {
        $message =
            trim(
                (string) $notification[
                    'error_message'
                ]
            );
    }

    $url =
        notificationsSafeUrl(
            isset($payload['url'])
                ? $payload['url']
                : 'notifications.php'
        );

    $icon =
        notificationsIcon(
            $notification['event_key'],
            $notification['related_type']
        );

    $notifications[$index]['display_title'] =
        $title;

    $notifications[$index]['display_message'] =
        $message;

    $notifications[$index]['display_url'] =
        $url;

    $notifications[$index]['display_icon'] =
        $icon[0];

    $notifications[$index]['display_color'] =
        $notification['status'] === 'failed'
            ? 'red'
            : $icon[1];

    $notifications[$index]['is_unread'] =
        $notification['status'] !== 'read';
}

$csrfToken =
    notificationsCsrfToken();

$returnQuery =
    notificationsQueryString(
        array(
            'page' => $page
        )
    );

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.notifications-page {
    --nt-primary: #6d28d9;
    --nt-text: #111827;
    --nt-muted: #6b7280;
    --nt-border: #e5e7eb;
}

.nt-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.nt-header h1 {
    margin: 0;
    color: var(--nt-text);
    font-size: 21px;
    font-weight: 700;
}

.nt-header p {
    margin: 5px 0 0;
    color: var(--nt-muted);
    font-size: 11px;
}

.nt-mark-all {
    min-height: 35px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 0;
    border-radius: 9px;
    background: var(--nt-primary);
    color: #fff;
    font-family: inherit;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
}

.nt-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
    line-height: 1.55;
}

.nt-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.nt-stats {
    margin-bottom: 13px;
    display: grid;
    grid-template-columns: repeat(6,minmax(0,1fr));
    gap: 10px;
}

.nt-stat {
    padding: 13px;
    border: 1px solid var(--nt-border);
    border-radius: 11px;
    background: #fff;
}

.nt-stat-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.nt-stat-value {
    margin-top: 4px;
    color: var(--nt-text);
    font-size: 19px;
    font-weight: 700;
}

.nt-panel {
    overflow: hidden;
    border: 1px solid var(--nt-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.nt-filters {
    padding: 12px;
    display: grid;
    grid-template-columns:
        minmax(220px,1.25fr)
        minmax(130px,.62fr)
        minmax(165px,.76fr)
        minmax(120px,.55fr)
        minmax(120px,.55fr)
        minmax(155px,.7fr)
        auto;
    gap: 8px;
    border-bottom: 1px solid #f1f5f9;
}

.nt-input,
.nt-select {
    width: 100%;
    height: 36px;
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

.nt-input:focus,
.nt-select:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.08);
}

.nt-filter-actions {
    display: flex;
    gap: 6px;
}

.nt-filter-btn,
.nt-reset {
    height: 36px;
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 700;
}

.nt-filter-btn {
    border: 0;
    background: var(--nt-primary);
    color: #fff;
    cursor: pointer;
}

.nt-reset {
    border: 1px solid var(--nt-border);
    background: #fff;
    color: #4b5563;
    text-decoration: none;
}

.nt-list {
    display: grid;
}

.nt-item {
    padding: 13px;
    display: grid;
    grid-template-columns:
        38px
        minmax(0,1fr)
        auto;
    gap: 11px;
    align-items: flex-start;
    border-bottom: 1px solid #f1f5f9;
    background: #fff;
}

.nt-item:last-child {
    border-bottom: 0;
}

.nt-item.unread {
    background: #fbf9ff;
}

.nt-item:hover {
    background: #faf8ff;
}

.nt-icon {
    width: 38px;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    font-size: 15px;
}

.nt-icon.purple {
    background: #f3e8ff;
    color: #7c3aed;
}

.nt-icon.blue {
    background: #eff6ff;
    color: #2563eb;
}

.nt-icon.green {
    background: #ecfdf5;
    color: #059669;
}

.nt-icon.orange {
    background: #fff7ed;
    color: #ea580c;
}

.nt-icon.red {
    background: #fef2f2;
    color: #dc2626;
}

.nt-content {
    min-width: 0;
}

.nt-title-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}

.nt-title {
    color: #111827;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.45;
}

.nt-dot {
    width: 7px;
    height: 7px;
    display: inline-block;
    border-radius: 50%;
    background: var(--nt-primary);
}

.nt-message {
    margin-top: 4px;
    max-width: 830px;
    color: #6b7280;
    font-size: 9px;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.nt-meta {
    margin-top: 6px;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}

.nt-badge {
    padding: 4px 7px;
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 700;
}

.nt-badge.read {
    background: #ecfdf5;
    color: #047857;
}

.nt-badge.failed {
    background: #fef2f2;
    color: #b91c1c;
}

.nt-badge.queued,
.nt-badge.sent,
.nt-badge.delivered {
    background: #eff6ff;
    color: #1d4ed8;
}

.nt-badge.suppressed {
    background: #f3f4f6;
    color: #6b7280;
}

.nt-time,
.nt-related {
    color: #9ca3af;
    font-size: 8px;
}

.nt-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 5px;
}

.nt-action-form {
    margin: 0;
}

.nt-action {
    width: 29px;
    height: 29px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--nt-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-family: inherit;
    text-decoration: none;
    cursor: pointer;
}

.nt-action:hover {
    border-color: #c4b5fd;
    background: #faf8ff;
    color: var(--nt-primary);
}

.nt-action.success {
    border-color: #bbf7d0;
    color: #047857;
}

.nt-action.warning {
    border-color: #fed7aa;
    color: #c2410c;
}

.nt-footer {
    padding: 11px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px solid #f1f5f9;
}

.nt-result {
    color: #6b7280;
    font-size: 9px;
}

.nt-pages {
    display: flex;
    gap: 5px;
}

.nt-page {
    min-width: 30px;
    height: 30px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--nt-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.nt-page.active {
    border-color: var(--nt-primary);
    background: var(--nt-primary);
    color: #fff;
}

.nt-empty {
    padding: 48px 15px;
    color: #9ca3af;
    font-size: 10px;
    text-align: center;
}

.nt-empty i {
    margin-bottom: 9px;
    display: block;
    color: #c4b5fd;
    font-size: 30px;
}

@media (max-width: 1450px) {
    .nt-filters {
        grid-template-columns:
            repeat(4,minmax(0,1fr));
    }
}

@media (max-width: 1050px) {
    .nt-stats {
        grid-template-columns:
            repeat(3,minmax(0,1fr));
    }
}

@media (max-width: 760px) {
    .nt-header {
        flex-direction: column;
    }

    .nt-filters {
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }

    .nt-item {
        grid-template-columns:
            38px
            minmax(0,1fr);
    }

    .nt-actions {
        grid-column: 1 / -1;
        justify-content: flex-end;
    }
}

@media (max-width: 560px) {
    .nt-stats,
    .nt-filters {
        grid-template-columns: 1fr;
    }

    .nt-filter-actions {
        width: 100%;
    }

    .nt-filter-btn,
    .nt-reset {
        flex: 1;
    }

    .nt-footer {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="notifications-page">
    <div class="nt-header">
        <div>
            <h1>Notifications</h1>
            <p>
                Review workspace alerts, updates, assignments, and related activity.
            </p>
        </div>

        <?php if (
            $stats['unread'] > 0 &&
            $notificationTableAvailable
        ): ?>
            <form method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken); ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="mark_all_read"
                >

                <input
                    type="hidden"
                    name="return_query"
                    value="<?= e($returnQuery); ?>"
                >

                <button
                    type="submit"
                    class="nt-mark-all"
                    onclick="return confirm('Mark all notifications as read?');"
                >
                    <i class="bi bi-check2-all"></i>
                    Mark All Read
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="nt-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="nt-stats">
        <article class="nt-stat">
            <div class="nt-stat-label">
                Total Notifications
            </div>
            <div class="nt-stat-value">
                <?= e($stats['total']); ?>
            </div>
        </article>

        <article class="nt-stat">
            <div class="nt-stat-label">
                Unread
            </div>
            <div class="nt-stat-value">
                <?= e($stats['unread']); ?>
            </div>
        </article>

        <article class="nt-stat">
            <div class="nt-stat-label">
                Read
            </div>
            <div class="nt-stat-value">
                <?= e($stats['read']); ?>
            </div>
        </article>

        <article class="nt-stat">
            <div class="nt-stat-label">
                Today
            </div>
            <div class="nt-stat-value">
                <?= e($stats['today']); ?>
            </div>
        </article>

        <article class="nt-stat">
            <div class="nt-stat-label">
                Last 7 Days
            </div>
            <div class="nt-stat-value">
                <?= e($stats['week']); ?>
            </div>
        </article>

        <article class="nt-stat">
            <div class="nt-stat-label">
                Failed
            </div>
            <div class="nt-stat-value">
                <?= e($stats['failed']); ?>
            </div>
        </article>
    </section>

    <section class="nt-panel">
        <form
            method="get"
            action=""
            class="nt-filters"
            id="notificationFilters"
        >
            <input
                type="search"
                name="search"
                id="notificationSearch"
                class="nt-input"
                value="<?= e($search); ?>"
                placeholder="Search title, message, event or related type"
            >

            <select
                name="status"
                class="nt-select"
            >
                <option value="">
                    All Statuses
                </option>

                <?php foreach (
                    array(
                        'unread' => 'Unread',
                        'read' => 'Read',
                        'queued' => 'Queued',
                        'sent' => 'Sent',
                        'delivered' => 'Delivered',
                        'failed' => 'Failed',
                        'suppressed' => 'Suppressed'
                    ) as $value => $label
                ): ?>
                    <option
                        value="<?= e($value); ?>"
                        <?= $statusFilter === $value
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="event"
                class="nt-select"
            >
                <option value="">
                    All Events
                </option>

                <?php foreach (
                    $eventOptions as
                    $eventOption
                ): ?>
                    <option
                        value="<?= e(
                            $eventOption[
                                'event_key'
                            ]
                        ); ?>"
                        <?= $eventFilter ===
                            $eventOption['event_key']
                                ? 'selected'
                                : ''; ?>
                    >
                        <?= e(
                            notificationsLabel(
                                $eventOption[
                                    'event_key'
                                ]
                            )
                        ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input
                type="date"
                name="date_from"
                class="nt-input"
                value="<?= e($dateFrom); ?>"
                title="Created from"
            >

            <input
                type="date"
                name="date_to"
                class="nt-input"
                value="<?= e($dateTo); ?>"
                title="Created to"
            >

            <select
                name="sort"
                class="nt-select"
            >
                <option
                    value="latest"
                    <?= $sort === 'latest'
                        ? 'selected'
                        : ''; ?>
                >
                    Latest First
                </option>

                <option
                    value="oldest"
                    <?= $sort === 'oldest'
                        ? 'selected'
                        : ''; ?>
                >
                    Oldest First
                </option>

                <option
                    value="unread_first"
                    <?= $sort === 'unread_first'
                        ? 'selected'
                        : ''; ?>
                >
                    Unread First
                </option>

                <option
                    value="event_asc"
                    <?= $sort === 'event_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Event A-Z
                </option>

                <option
                    value="status_asc"
                    <?= $sort === 'status_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Status
                </option>
            </select>

            <div class="nt-filter-actions">
                <button
                    type="submit"
                    class="nt-filter-btn"
                >
                    Apply
                </button>

                <a
                    href="notifications.php"
                    class="nt-reset"
                >
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($notifications)): ?>
            <div class="nt-list">
                <?php foreach (
                    $notifications as
                    $notification
                ): ?>
                    <?php
                    $notificationId =
                        (int) $notification['id'];

                    $statusClass =
                        preg_replace(
                            '/[^a-z0-9_-]/',
                            '',
                            strtolower(
                                (string) $notification[
                                    'status'
                                ]
                            )
                        );
                    ?>

                    <article class="nt-item <?= $notification['is_unread']
                        ? 'unread'
                        : ''; ?>">
                        <span class="nt-icon <?= e(
                            $notification[
                                'display_color'
                            ]
                        ); ?>">
                            <i class="bi <?= e(
                                $notification[
                                    'display_icon'
                                ]
                            ); ?>"></i>
                        </span>

                        <div class="nt-content">
                            <div class="nt-title-row">
                                <?php if (
                                    $notification[
                                        'is_unread'
                                    ]
                                ): ?>
                                    <span
                                        class="nt-dot"
                                        title="Unread"
                                    ></span>
                                <?php endif; ?>

                                <span class="nt-title">
                                    <?= e(
                                        $notification[
                                            'display_title'
                                        ]
                                    ); ?>
                                </span>
                            </div>

                            <?php if (
                                $notification[
                                    'display_message'
                                ] !== ''
                            ): ?>
                                <div class="nt-message">
                                    <?= e(
                                        $notification[
                                            'display_message'
                                        ]
                                    ); ?>
                                </div>
                            <?php endif; ?>

                            <div class="nt-meta">
                                <span class="nt-badge <?= e(
                                    $statusClass
                                ); ?>">
                                    <?= e(
                                        notificationsLabel(
                                            $notification[
                                                'status'
                                            ]
                                        )
                                    ); ?>
                                </span>

                                <span class="nt-badge">
                                    <?= e(
                                        notificationsLabel(
                                            $notification[
                                                'event_key'
                                            ]
                                        )
                                    ); ?>
                                </span>

                                <?php if (
                                    trim(
                                        (string) $notification[
                                            'related_type'
                                        ]
                                    ) !== ''
                                ): ?>
                                    <span class="nt-related">
                                        <?= e(
                                            notificationsLabel(
                                                $notification[
                                                    'related_type'
                                                ]
                                            )
                                        ); ?>

                                        <?php if (
                                            !empty(
                                                $notification[
                                                    'related_id'
                                                ]
                                            )
                                        ): ?>
                                            #<?= (int) $notification[
                                                'related_id'
                                            ]; ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>

                                <span
                                    class="nt-time"
                                    title="<?= e(
                                        notificationsDateTime(
                                            $notification[
                                                'created_at'
                                            ]
                                        )
                                    ); ?>"
                                >
                                    <?= e(
                                        notificationsTimeAgo(
                                            $notification[
                                                'created_at'
                                            ]
                                        )
                                    ); ?>
                                </span>
                            </div>
                        </div>

                        <div class="nt-actions">
                            <form
                                method="post"
                                class="nt-action-form"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(
                                        $csrfToken
                                    ); ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="open_notification"
                                >

                                <input
                                    type="hidden"
                                    name="notification_id"
                                    value="<?= $notificationId; ?>"
                                >

                                <input
                                    type="hidden"
                                    name="return_query"
                                    value="<?= e(
                                        $returnQuery
                                    ); ?>"
                                >

                                <button
                                    type="submit"
                                    class="nt-action"
                                    title="Open Notification"
                                >
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </button>
                            </form>

                            <form
                                method="post"
                                class="nt-action-form"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(
                                        $csrfToken
                                    ); ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="<?= $notification[
                                        'is_unread'
                                    ]
                                        ? 'mark_read'
                                        : 'mark_unread'; ?>"
                                >

                                <input
                                    type="hidden"
                                    name="notification_id"
                                    value="<?= $notificationId; ?>"
                                >

                                <input
                                    type="hidden"
                                    name="return_query"
                                    value="<?= e(
                                        $returnQuery
                                    ); ?>"
                                >

                                <button
                                    type="submit"
                                    class="nt-action <?= $notification[
                                        'is_unread'
                                    ]
                                        ? 'success'
                                        : 'warning'; ?>"
                                    title="<?= $notification[
                                        'is_unread'
                                    ]
                                        ? 'Mark as Read'
                                        : 'Mark as Unread'; ?>"
                                >
                                    <i class="bi <?= $notification[
                                        'is_unread'
                                    ]
                                        ? 'bi-check2'
                                        : 'bi-envelope'; ?>"></i>
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="nt-footer">
                <div class="nt-result">
                    Showing
                    <?= e(
                        min(
                            $totalFiltered,
                            $offset + 1
                        )
                    ); ?>
                    -
                    <?= e(
                        min(
                            $totalFiltered,
                            $offset +
                            count($notifications)
                        )
                    ); ?>
                    of
                    <?= e($totalFiltered); ?>
                    notifications
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="nt-pages">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(
                                    notificationsQueryString(
                                        array(
                                            'page' =>
                                                $page - 1
                                        )
                                    )
                                ); ?>"
                                class="nt-page"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage =
                            max(1, $page - 2);

                        $endPage =
                            min(
                                $totalPages,
                                $page + 2
                            );

                        for (
                            $pageNumber =
                                $startPage;
                            $pageNumber <=
                                $endPage;
                            $pageNumber++
                        ):
                        ?>
                            <a
                                href="?<?= e(
                                    notificationsQueryString(
                                        array(
                                            'page' =>
                                                $pageNumber
                                        )
                                    )
                                ); ?>"
                                class="nt-page <?= $pageNumber === $page
                                    ? 'active'
                                    : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if (
                            $page < $totalPages
                        ): ?>
                            <a
                                href="?<?= e(
                                    notificationsQueryString(
                                        array(
                                            'page' =>
                                                $page + 1
                                        )
                                    )
                                ); ?>"
                                class="nt-page"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="nt-empty">
                <i class="bi bi-bell-slash"></i>

                <?php if (
                    $search !== '' ||
                    $statusFilter !== '' ||
                    $eventFilter !== '' ||
                    $dateFrom !== '' ||
                    $dateTo !== ''
                ): ?>
                    No notifications found for the selected filters.
                <?php else: ?>
                    No notifications are available.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {
        'use strict';

        var filterForm =
            document.getElementById(
                'notificationFilters'
            );

        var searchInput =
            document.getElementById(
                'notificationSearch'
            );

        var submitTimer = null;

        document.addEventListener(
            'fieldplx:search',
            function (event) {
                if (
                    !filterForm ||
                    !searchInput ||
                    !event.detail
                ) {
                    return;
                }

                searchInput.value =
                    event.detail.value || '';

                window.clearTimeout(
                    submitTimer
                );

                submitTimer =
                    window.setTimeout(
                        function () {
                            filterForm.submit();
                        },
                        250
                    );
            }
        );
    }
);
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
