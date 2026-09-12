<?php
/* FieldPlx Quotation List API - Version 2.0.0 - 2026-09-09 */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

const FIELDPLX_QUOTATION_LIST_API_VERSION = '2.0.0';

function qlResponse($code, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code((int)$code);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message,
        'api_version' => FIELDPLX_QUOTATION_LIST_API_VERSION
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qlPost($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function qlColumnExists(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name"
    );
    $stmt->execute(array(
        ':table_name' => $table,
        ':column_name' => $column
    ));

    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

function qlCurrency(PDO $pdo, $tenantId, $branchId)
{
    $stmt = $pdo->prepare(
        "SELECT
            c.id,
            c.currency_code,
            c.currency_name,
            c.symbol,
            c.symbol_position,
            c.decimal_places,
            c.decimal_separator,
            c.thousand_separator
         FROM tenants t
         LEFT JOIN branches b
           ON b.id = :branch_id
          AND b.tenant_id = t.id
         LEFT JOIN currencies c
           ON c.id = COALESCE(b.currency_id, t.currency_id)
         WHERE t.id = :tenant_id
         LIMIT 1"
    );
    $stmt->execute(array(
        ':branch_id' => $branchId > 0 ? $branchId : -1,
        ':tenant_id' => $tenantId
    ));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['currency_code'])) {
        return $row;
    }

    return array(
        'id' => null,
        'currency_code' => 'INR',
        'currency_name' => 'Indian Rupee',
        'symbol' => '₹',
        'symbol_position' => 'before',
        'decimal_places' => 2,
        'decimal_separator' => '.',
        'thousand_separator' => ','
    );
}

function qlValidDate($value)
{
    if ($value === '') {
        return true;
    }

    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = !empty($_SESSION['tenant_user_id'])
    ? (int)$_SESSION['tenant_user_id']
    : (!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
$branchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($tenantId <= 0 || $userId <= 0) {
    qlResponse(401, false, 'Authentication required.');
}

$csrfToken = trim((string)qlPost('csrf_token', ''));
$sessionCsrf = isset($_SESSION['quotations_csrf_token'])
    ? (string)$_SESSION['quotations_csrf_token']
    : '';

if ($csrfToken === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrfToken)) {
    qlResponse(419, false, 'Your form session expired. Refresh and try again.');
}

$action = trim((string)qlPost('action', 'list'));
if ($action !== 'list') {
    qlResponse(400, false, 'Unsupported quotation list action.');
}

try {
    $page = max(1, (int)qlPost('page', 1));
    $perPage = (int)qlPost('per_page', 25);
    if (!in_array($perPage, array(10, 25, 50), true)) {
        $perPage = 25;
    }

    $search = trim((string)qlPost('search', ''));
    $status = strtolower(trim((string)qlPost('status', '')));
    $fromDate = trim((string)qlPost('from_date', ''));
    $toDate = trim((string)qlPost('to_date', ''));
    $sort = strtolower(trim((string)qlPost('sort', 'created')));
    $direction = strtolower(trim((string)qlPost('direction', 'desc')));

    $allowedStatuses = array(
        'draft',
        'internal_approval',
        'awaiting_response',
        'sent',
        'viewed',
        'changes_requested',
        'approved',
        'rejected',
        'expired',
        'converted',
        'archived'
    );

    if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
        qlResponse(422, false, 'Select a valid quotation status.');
    }

    if (!qlValidDate($fromDate) || !qlValidDate($toDate)) {
        qlResponse(422, false, 'Select a valid date range.');
    }

    if ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) {
        qlResponse(422, false, 'From date cannot be later than To date.');
    }

    $sortMap = array(
        'customer' => 'c.display_name',
        'quote_number' => 'q.id',
        'created' => 'q.created_at',
        'status' => 'q.status',
        'total' => 'q.total'
    );
    if (!isset($sortMap[$sort])) {
        $sort = 'created';
    }
    if (!in_array($direction, array('asc', 'desc'), true)) {
        $direction = 'desc';
    }

    $where = array('q.tenant_id = :tenant_id');
    $params = array(':tenant_id' => $tenantId);

    if ($search !== '') {
        $searchValue = '%' . $search . '%';
        $where[] = "(
            q.quote_no LIKE :search_quote
            OR COALESCE(r.request_no, '') LIKE :search_request
            OR COALESCE(c.display_name, '') LIKE :search_client
            OR COALESCE(q.title, '') LIKE :search_title
            OR COALESCE(cl.name, '') LIKE :search_location
            OR COALESCE(cl.address_line1, '') LIKE :search_address1
            OR COALESCE(cl.address_line2, '') LIKE :search_address2
            OR COALESCE(cl.city, '') LIKE :search_city
            OR COALESCE(cl.state, '') LIKE :search_state
            OR COALESCE(cl.postal_code, '') LIKE :search_postal
        )";
        $params[':search_quote'] = $searchValue;
        $params[':search_request'] = $searchValue;
        $params[':search_client'] = $searchValue;
        $params[':search_title'] = $searchValue;
        $params[':search_location'] = $searchValue;
        $params[':search_address1'] = $searchValue;
        $params[':search_address2'] = $searchValue;
        $params[':search_city'] = $searchValue;
        $params[':search_state'] = $searchValue;
        $params[':search_postal'] = $searchValue;
    }

    if ($status !== '') {
        if ($status === 'awaiting_response') {
            $where[] = "q.status IN ('sent', 'viewed')";
        } elseif ($status === 'converted') {
            $where[] = "(
                q.status = 'converted'
                OR EXISTS (
                    SELECT 1
                    FROM jobs j_filter
                    WHERE j_filter.tenant_id = q.tenant_id
                      AND j_filter.quote_id = q.id
                      AND j_filter.deleted_at IS NULL
                      AND j_filter.status NOT IN ('cancelled', 'archived')
                )
            )";
        } else {
            $where[] = 'q.status = :status';
            $params[':status'] = $status;
        }
    }

    if ($fromDate !== '') {
        $where[] = 'DATE(q.created_at) >= :from_date';
        $params[':from_date'] = $fromDate;
    }

    if ($toDate !== '') {
        $where[] = 'DATE(q.created_at) <= :to_date';
        $params[':to_date'] = $toDate;
    }

    $whereSql = implode(' AND ', $where);
    $baseJoins = "
        LEFT JOIN service_requests r
          ON r.id = q.request_id
         AND r.tenant_id = q.tenant_id
        INNER JOIN clients c
          ON c.id = q.client_id
         AND c.tenant_id = q.tenant_id
        LEFT JOIN client_locations cl
          ON cl.id = q.location_id
         AND cl.tenant_id = q.tenant_id
         AND cl.client_id = q.client_id
    ";

    $countSql = "SELECT COUNT(*) FROM quotes q " . $baseJoins . " WHERE " . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }

    $offset = ($page - 1) * $perPage;
    $hasRevisitColumn = qlColumnExists($pdo, 'quotes', 'assessment_reschedule_id');

    if ($hasRevisitColumn) {
        $sourceSelect = ",
            q.assessment_reschedule_id,
            CASE
                WHEN q.request_id IS NULL THEN 'Direct Quotation'
                WHEN q.assessment_reschedule_id IS NULL THEN 'Original Enquiry'
                ELSE CONCAT('Revisit #', q.assessment_reschedule_id)
            END AS quotation_source";
    } else {
        $sourceSelect = ",
            CASE
                WHEN q.request_id IS NULL THEN 'Direct Quotation'
                ELSE 'Original Enquiry'
            END AS quotation_source";
    }

    $orderSql = $sortMap[$sort] . ' ' . strtoupper($direction) . ', q.id ' . strtoupper($direction);

    $listSql =
        "SELECT
            q.id,
            q.quote_no,
            q.revision_no,
            q.request_id,
            q.client_id,
            q.location_id,
            q.title,
            q.status,
            q.subtotal,
            q.discount_total,
            q.tax_total,
            q.total,
            q.valid_until,
            q.sent_at,
            q.approved_at,
            q.created_at,
            q.updated_at,
            DATE_FORMAT(q.created_at, '%d-%m-%Y') AS created_date,
            r.request_no,
            c.display_name AS client_name,
            c.phone AS client_phone,
            c.email AS client_email,
            cl.name AS location_name,
            cl.address_line1 AS location_address1,
            cl.address_line2 AS location_address2,
            cl.city AS location_city,
            cl.state AS location_state,
            cl.postal_code AS location_postal_code,
            (
                SELECT j.id
                FROM jobs j
                WHERE j.tenant_id = q.tenant_id
                  AND j.quote_id = q.id
                  AND j.deleted_at IS NULL
                  AND j.status NOT IN ('cancelled', 'archived')
                ORDER BY j.id DESC
                LIMIT 1
            ) AS linked_job_id,
            (
                SELECT j.job_no
                FROM jobs j
                WHERE j.tenant_id = q.tenant_id
                  AND j.quote_id = q.id
                  AND j.deleted_at IS NULL
                  AND j.status NOT IN ('cancelled', 'archived')
                ORDER BY j.id DESC
                LIMIT 1
            ) AS linked_job_no
            " . $sourceSelect . "
         FROM quotes q
         " . $baseJoins . "
         WHERE " . $whereSql . "
         ORDER BY " . $orderSql . "
         LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute($params);
    $quotations = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $summaryStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(q.status = 'draft'), 0) AS draft,
            COALESCE(SUM(q.status = 'internal_approval'), 0) AS internal_approval,
            COALESCE(SUM(q.status IN ('sent','viewed')), 0) AS awaiting_response,
            COALESCE(SUM(q.status = 'sent'), 0) AS sent,
            COALESCE(SUM(q.status = 'viewed'), 0) AS viewed,
            COALESCE(SUM(q.status = 'changes_requested'), 0) AS changes_requested,
            COALESCE(SUM(q.status = 'approved'), 0) AS approved,
            COALESCE(SUM(q.status = 'rejected'), 0) AS rejected,
            COALESCE(SUM(q.status = 'expired'), 0) AS expired,
            COALESCE(SUM(q.status = 'archived'), 0) AS archived,
            COALESCE(SUM(
                q.status = 'converted'
                OR EXISTS (
                    SELECT 1
                    FROM jobs j_status
                    WHERE j_status.tenant_id = q.tenant_id
                      AND j_status.quote_id = q.id
                      AND j_status.deleted_at IS NULL
                      AND j_status.status NOT IN ('cancelled', 'archived')
                )
            ), 0) AS converted
         FROM quotes q
         WHERE q.tenant_id = :tenant_id"
    );
    $summaryStmt->execute(array(':tenant_id' => $tenantId));
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: array();

    $statusCounts = array(
        'total' => (int)(isset($summaryRow['total']) ? $summaryRow['total'] : 0),
        'draft' => (int)(isset($summaryRow['draft']) ? $summaryRow['draft'] : 0),
        'internal_approval' => (int)(isset($summaryRow['internal_approval']) ? $summaryRow['internal_approval'] : 0),
        'awaiting_response' => (int)(isset($summaryRow['awaiting_response']) ? $summaryRow['awaiting_response'] : 0),
        'sent' => (int)(isset($summaryRow['sent']) ? $summaryRow['sent'] : 0),
        'viewed' => (int)(isset($summaryRow['viewed']) ? $summaryRow['viewed'] : 0),
        'changes_requested' => (int)(isset($summaryRow['changes_requested']) ? $summaryRow['changes_requested'] : 0),
        'approved' => (int)(isset($summaryRow['approved']) ? $summaryRow['approved'] : 0),
        'rejected' => (int)(isset($summaryRow['rejected']) ? $summaryRow['rejected'] : 0),
        'expired' => (int)(isset($summaryRow['expired']) ? $summaryRow['expired'] : 0),
        'archived' => (int)(isset($summaryRow['archived']) ? $summaryRow['archived'] : 0),
        'converted' => (int)(isset($summaryRow['converted']) ? $summaryRow['converted'] : 0)
    );

    /* Old response keys are kept for compatibility with older quotation pages. */
    $summary = array_merge($statusCounts, array(
        'sent_viewed' => $statusCounts['awaiting_response']
    ));

    qlResponse(200, true, 'Quotations loaded successfully.', array(
        'quotations' => $quotations,
        'summary' => $summary,
        'status_counts' => $statusCounts,
        'currency' => qlCurrency($pdo, $tenantId, $branchId),
        'pagination' => array(
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'from' => $total > 0 ? ($offset + 1) : 0,
            'to' => $total > 0 ? min($offset + $perPage, $total) : 0
        )
    ));
} catch (PDOException $e) {
    error_log('FieldPlx quotation-list PDO: ' . $e->getMessage());
    qlResponse(500, false, 'Unable to load quotations.');
} catch (Throwable $e) {
    error_log('FieldPlx quotation-list: ' . $e->getMessage());
    qlResponse(500, false, 'Unable to load quotations.');
}
