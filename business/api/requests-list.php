<?php
/* FieldPlx Requests List API - Version 1.0.0 - 2026-09-08
 * Dedicated read-only Jobber-style request listing endpoint.
 * Keeps api/requests.php unchanged for request CRUD/history/notifications.
 */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function rqlResponse($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$status);
    echo json_encode(
        array_merge(
            array(
                'success' => (bool)$success,
                'message' => (string)$message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function rqlPost($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

$pdoConn = null;
if (isset($pdo) && $pdo instanceof PDO) {
    $pdoConn = $pdo;
} elseif (isset($db) && $db instanceof PDO) {
    $pdoConn = $db;
}

if (!($pdoConn instanceof PDO)) {
    rqlResponse(500, false, 'Database connection is unavailable.');
}

$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : 0;
if ($tenantId <= 0 || $userId <= 0) {
    rqlResponse(401, false, 'Authentication required.');
}

$csrf = trim((string)rqlPost('csrf_token', ''));
$sessionCsrf = isset($_SESSION['requests_csrf_token']) ? (string)$_SESSION['requests_csrf_token'] : '';
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    rqlResponse(419, false, 'Your form session expired. Refresh the page and try again.');
}

$action = trim((string)rqlPost('action', 'list'));
if ($action !== 'list') {
    rqlResponse(400, false, 'Unsupported request-list action.');
}

try {
    $page = max(1, (int)rqlPost('page', 1));
    $perPage = (int)rqlPost('per_page', 10);
    if (!in_array($perPage, array(10, 25, 50), true)) {
        $perPage = 10;
    }

    $search = trim((string)rqlPost('search', ''));
    $status = trim((string)rqlPost('status', ''));
    $dateFilter = trim((string)rqlPost('date_filter', ''));

    $where = array('r.tenant_id = :tenant_id');
    $params = array(':tenant_id' => $tenantId);

    if ($search !== '') {
        $value = '%' . $search . '%';
        $where[] = "(
            r.request_no LIKE :s1
            OR r.title LIKE :s2
            OR r.description LIKE :s3
            OR c.display_name LIKE :s4
            OR c.phone LIKE :s5
            OR c.email LIKE :s6
            OR cl.name LIKE :s7
            OR cl.address_line1 LIKE :s8
            OR cl.address_line2 LIKE :s9
            OR cl.city LIKE :s10
            OR cl.state LIKE :s11
            OR cl.postal_code LIKE :s12
        )";
        for ($i = 1; $i <= 12; $i++) {
            $params[':s' . $i] = $value;
        }
    }

    $allowedStatuses = array(
        'new',
        'contacting',
        'information_required',
        'assessment_required',
        'quote_required',
        'job_required',
        'converted',
        'closed',
        'cancelled'
    );
    if (in_array($status, $allowedStatuses, true)) {
        $where[] = 'r.status = :status';
        $params[':status'] = $status;
    }

    if ($dateFilter === 'today') {
        $where[] = 'DATE(r.created_at) = CURDATE()';
    } elseif ($dateFilter === 'last_7') {
        $where[] = 'r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    } elseif ($dateFilter === 'last_30') {
        $where[] = 'r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    } elseif ($dateFilter === 'this_month') {
        $where[] = "r.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        $where[] = "r.created_at < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)";
    }

    $whereSql = implode(' AND ', $where);
    $joinSql = "
        FROM service_requests r
        INNER JOIN clients c
            ON c.id = r.client_id
           AND c.tenant_id = r.tenant_id
        LEFT JOIN client_locations cl
            ON cl.id = r.location_id
           AND cl.tenant_id = r.tenant_id
           AND cl.deleted_at IS NULL
    ";

    $countStmt = $pdoConn->prepare("SELECT COUNT(*) " . $joinSql . " WHERE " . $whereSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT
                r.id,
                r.request_no,
                r.title,
                r.status,
                r.created_at,
                r.updated_at,
                c.display_name AS client_name,
                c.phone AS client_phone,
                c.email AS client_email,
                cl.name AS location_name,
                cl.address_line1 AS location_address_line1,
                cl.address_line2 AS location_address_line2,
                cl.city AS location_city,
                cl.state AS location_state,
                cl.postal_code AS location_postal_code
            " . $joinSql . "
            WHERE " . $whereSql . "
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

    $stmt = $pdoConn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $from = $total > 0 ? $offset + 1 : 0;
    $to = $total > 0 ? min($offset + $perPage, $total) : 0;

    rqlResponse(200, true, 'Requests loaded.', array(
        'requests' => $rows,
        'pagination' => array(
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'from' => $from,
            'to' => $to
        )
    ));
} catch (Throwable $e) {
    error_log('FieldPlx requests-list API error: ' . $e->getMessage());
    rqlResponse(500, false, 'Unable to load service requests.');
}
