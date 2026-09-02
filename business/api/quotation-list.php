<?php
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

/*
|--------------------------------------------------------------------------
| FieldPlx Quotations API
|--------------------------------------------------------------------------
| Same API pattern used by api/clients.php:
| - JSON-only responses
| - tenant/session validation
| - page-specific CSRF
| - action based routing
| - prepared PDO statements
| - tenant scoped reads
|--------------------------------------------------------------------------
*/

function cr($status, $ok, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code((int)$status);

    echo json_encode(
        array_merge(
            array(
                'success' => (bool)$ok,
                'message' => (string)$message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function cp($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function tableExists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :name"
    );
    $stmt->execute(array(':name' => $table));

    return (int)$stmt->fetchColumn() > 0;
}

function validDate($value)
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        return false;
    }

    return $value;
}

function quotationStatuses()
{
    return array(
        'draft',
        'internal_approval',
        'sent',
        'viewed',
        'changes_requested',
        'approved',
        'rejected',
        'expired',
        'converted',
        'archived'
    );
}

function quotationCurrency(PDO $pdo, $tenantId, $branchId)
{
    $stmt = $pdo->prepare(
        "SELECT
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

    if (!$row || empty($row['currency_code'])) {
        return array(
            'currency_code' => 'INR',
            'currency_name' => 'Indian Rupee',
            'symbol' => '₹',
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'decimal_separator' => '.',
            'thousand_separator' => ','
        );
    }

    $row['decimal_places'] = (int)$row['decimal_places'];

    return $row;
}

function getQuotation(PDO $pdo, $tenantId, $quoteId)
{
    $stmt = $pdo->prepare(
        "SELECT
            q.*,
            DATE(q.created_at) AS created_date,

            c.display_name AS client_name,
            c.company_name AS client_company,
            c.email AS client_email,
            c.phone AS client_phone,
            c.alternate_phone AS client_alternate_phone,

            sr.request_no,
            sr.title AS request_title,
            sr.source AS request_source,
            sr.status AS request_status,

            b.name AS branch_name,
            b.branch_code,

            CONCAT(
                COALESCE(sp.first_name, ''),
                CASE
                    WHEN sp.last_name IS NOT NULL AND sp.last_name <> ''
                    THEN CONCAT(' ', sp.last_name)
                    ELSE ''
                END
            ) AS salesperson_name,

            CASE
                WHEN q.request_id IS NULL THEN 'Direct Quotation'
                ELSE 'Original Enquiry'
            END AS quotation_source

         FROM quotes q

         LEFT JOIN clients c
           ON c.id = q.client_id
          AND c.tenant_id = q.tenant_id

         LEFT JOIN service_requests sr
           ON sr.id = q.request_id
          AND sr.tenant_id = q.tenant_id

         LEFT JOIN branches b
           ON b.id = q.branch_id
          AND b.tenant_id = q.tenant_id

         LEFT JOIN users sp
           ON sp.id = q.salesperson_id
          AND sp.tenant_id = q.tenant_id

         WHERE q.id = :quote_id
           AND q.tenant_id = :tenant_id
         LIMIT 1"
    );

    $stmt->execute(array(
        ':quote_id' => $quoteId,
        ':tenant_id' => $tenantId
    ));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        cr(404, false, 'Quotation not found.');
    }

    return $row;
}

function getQuotationItems(PDO $pdo, $quoteId)
{
    if (!tableExists($pdo, 'quote_line_items')) {
        return array();
    }

    $stmt = $pdo->prepare(
        "SELECT
            id,
            quote_id,
            product_service_id,
            product_id,
            item_name,
            description,
            quantity,
            unit_cost,
            unit_price,
            discount_amount,
            tax_percent,
            tax_amount,
            line_total,
            is_optional,
            sort_order
         FROM quote_line_items
         WHERE quote_id = :quote_id
         ORDER BY sort_order, id"
    );

    $stmt->execute(array(':quote_id' => $quoteId));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getQuotationActions(PDO $pdo, $tenantId, $quoteId)
{
    if (!tableExists($pdo, 'quote_actions')) {
        return array();
    }

    $stmt = $pdo->prepare(
        "SELECT
            qa.id,
            qa.action,
            qa.comment,
            qa.actor_type,
            qa.user_id,
            qa.portal_user_id,
            qa.created_at,
            CONCAT(
                COALESCE(u.first_name, ''),
                CASE
                    WHEN u.last_name IS NOT NULL AND u.last_name <> ''
                    THEN CONCAT(' ', u.last_name)
                    ELSE ''
                END
            ) AS user_name
         FROM quote_actions qa
         LEFT JOIN users u
           ON u.id = qa.user_id
          AND u.tenant_id = qa.tenant_id
         WHERE qa.tenant_id = :tenant_id
           AND qa.quote_id = :quote_id
         ORDER BY qa.created_at DESC, qa.id DESC"
    );

    $stmt->execute(array(
        ':tenant_id' => $tenantId,
        ':quote_id' => $quoteId
    ));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$t = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$u = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : 0;
$b = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($t <= 0 || $u <= 0) {
    cr(401, false, 'Authentication required.');
}

$csrf = (string)cp('csrf_token', '');
$sessionCsrf = isset($_SESSION['quotations_csrf_token'])
    ? (string)$_SESSION['quotations_csrf_token']
    : '';

if (
    $csrf === '' ||
    $sessionCsrf === '' ||
    !hash_equals($sessionCsrf, $csrf)
) {
    cr(
        419,
        false,
        'Your quotation session expired. Refresh the page and try again.'
    );
}

$action = trim((string)cp('action', ''));

try {

    /*
    |--------------------------------------------------------------------------
    | LIST SAVED QUOTATIONS
    |--------------------------------------------------------------------------
    */
    if ($action === 'list') {

        if (!tableExists($pdo, 'quotes')) {
            cr(500, false, 'Quotes table was not found.');
        }

        $page = max(1, (int)cp('page', 1));
        $perPage = (int)cp('per_page', 10);

        if (!in_array($perPage, array(10, 25, 50), true)) {
            $perPage = 10;
        }

        $search = trim((string)cp('search', ''));
        $status = trim((string)cp('status', ''));
        $fromDate = validDate(cp('from_date', ''));
        $toDate = validDate(cp('to_date', ''));

        if ($fromDate === false) {
            cr(422, false, 'Invalid From Date.');
        }

        if ($toDate === false) {
            cr(422, false, 'Invalid To Date.');
        }

        if (
            $fromDate !== null &&
            $toDate !== null &&
            $fromDate > $toDate
        ) {
            cr(422, false, 'From Date cannot be after To Date.');
        }

        if (
            $status !== '' &&
            !in_array($status, quotationStatuses(), true)
        ) {
            cr(422, false, 'Invalid quotation status.');
        }

        $where = array('q.tenant_id = :tenant_id');
        $params = array(':tenant_id' => $t);

        if ($search !== '') {
            $searchLike = '%' . $search . '%';

            $where[] = "(
                q.quote_no LIKE :search_quote_no
                OR q.title LIKE :search_quote_title
                OR c.display_name LIKE :search_client_name
                OR c.company_name LIKE :search_company
                OR c.email LIKE :search_email
                OR c.phone LIKE :search_phone
                OR sr.request_no LIKE :search_request_no
                OR sr.title LIKE :search_request_title
            )";

            $params[':search_quote_no'] = $searchLike;
            $params[':search_quote_title'] = $searchLike;
            $params[':search_client_name'] = $searchLike;
            $params[':search_company'] = $searchLike;
            $params[':search_email'] = $searchLike;
            $params[':search_phone'] = $searchLike;
            $params[':search_request_no'] = $searchLike;
            $params[':search_request_title'] = $searchLike;
        }

        if ($status !== '') {
            $where[] = 'q.status = :quotation_status';
            $params[':quotation_status'] = $status;
        }

        if ($fromDate !== null) {
            $where[] = 'DATE(q.created_at) >= :from_date';
            $params[':from_date'] = $fromDate;
        }

        if ($toDate !== null) {
            $where[] = 'DATE(q.created_at) <= :to_date';
            $params[':to_date'] = $toDate;
        }

        $whereSql = implode(' AND ', $where);

        $joinSql = "
            FROM quotes q

            LEFT JOIN clients c
              ON c.id = q.client_id
             AND c.tenant_id = q.tenant_id

            LEFT JOIN service_requests sr
              ON sr.id = q.request_id
             AND sr.tenant_id = q.tenant_id

            LEFT JOIN branches br
              ON br.id = q.branch_id
             AND br.tenant_id = q.tenant_id

            LEFT JOIN users sp
              ON sp.id = q.salesperson_id
             AND sp.tenant_id = q.tenant_id
        ";

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*)
             {$joinSql}
             WHERE {$whereSql}"
        );
        $countStmt->execute($params);

        $total = (int)$countStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));

        if ($page > $pages) {
            $page = $pages;
        }

        $offset = ($page - 1) * $perPage;

        $listSql = "
            SELECT
                q.id,
                q.quote_no,
                q.revision_no,
                q.parent_quote_id,
                q.branch_id,
                q.client_id,
                q.location_id,
                q.request_id,
                q.assessment_id,
                q.assessment_reschedule_id,
                q.salesperson_id,
                q.title,
                q.introduction,
                q.status,
                q.subtotal,
                q.discount_total,
                q.tax_total,
                q.total,
                q.deposit_required,
                q.deposit_type,
                q.deposit_value,
                q.deposit_amount,
                q.valid_until,
                q.sent_at,
                q.viewed_at,
                q.approved_at,
                q.created_at,
                q.updated_at,

                DATE(q.created_at) AS created_date,

                c.display_name AS client_name,
                c.company_name AS client_company,
                c.email AS client_email,
                c.phone AS client_phone,

                sr.request_no,
                sr.title AS request_title,
                sr.source AS request_source,
                sr.status AS request_status,

                br.name AS branch_name,

                CONCAT(
                    COALESCE(sp.first_name, ''),
                    CASE
                        WHEN sp.last_name IS NOT NULL
                             AND sp.last_name <> ''
                        THEN CONCAT(' ', sp.last_name)
                        ELSE ''
                    END
                ) AS salesperson_name,

                CASE
                    WHEN q.request_id IS NULL
                    THEN 'Direct Quotation'
                    ELSE 'Original Enquiry'
                END AS quotation_source,

                (
                    SELECT COUNT(*)
                    FROM quote_line_items qli
                    WHERE qli.quote_id = q.id
                ) AS line_item_count

            {$joinSql}

            WHERE {$whereSql}

            ORDER BY q.created_at DESC, q.id DESC

            LIMIT " . (int)$perPage . "
            OFFSET " . (int)$offset;

        $listStmt = $pdo->prepare($listSql);
        $listStmt->execute($params);

        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        $summaryStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'internal_approval' THEN 1 ELSE 0 END) AS needs_approval,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft,
                SUM(CASE WHEN status IN ('sent', 'viewed') THEN 1 ELSE 0 END) AS sent_viewed,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved
             FROM quotes
             WHERE tenant_id = :tenant_id"
        );
        $summaryStmt->execute(array(':tenant_id' => $t));
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

        $convertedAll = 0;
        $newQuotes30 = 0;
        $previousNewQuotes30 = 0;
        $converted30 = 0;
        $previousConverted30 = 0;
        $convertedQuotes = array();

        if (tableExists($pdo, 'jobs')) {
            $convertedAllStmt = $pdo->prepare(
                "SELECT COUNT(DISTINCT q.id)
                 FROM quotes q
                 INNER JOIN jobs j ON j.quote_id=q.id AND j.tenant_id=q.tenant_id
                   AND j.deleted_at IS NULL AND j.status NOT IN ('cancelled','archived')
                 WHERE q.tenant_id=:tenant_id"
            );
            $convertedAllStmt->execute(array(':tenant_id'=>$t));
            $convertedAll=(int)$convertedAllStmt->fetchColumn();

            $periodStmt=$pdo->prepare(
                "SELECT
                  SUM(CASE WHEN q.created_at>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) AND q.created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY) THEN 1 ELSE 0 END) current_quotes,
                  SUM(CASE WHEN q.created_at>=DATE_SUB(CURDATE(),INTERVAL 59 DAY) AND q.created_at<DATE_SUB(CURDATE(),INTERVAL 29 DAY) THEN 1 ELSE 0 END) previous_quotes,
                  SUM(CASE WHEN q.created_at>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) AND q.created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY) AND EXISTS(SELECT 1 FROM jobs j WHERE j.tenant_id=q.tenant_id AND j.quote_id=q.id AND j.deleted_at IS NULL AND j.status NOT IN ('cancelled','archived')) THEN 1 ELSE 0 END) current_converted,
                  SUM(CASE WHEN q.created_at>=DATE_SUB(CURDATE(),INTERVAL 59 DAY) AND q.created_at<DATE_SUB(CURDATE(),INTERVAL 29 DAY) AND EXISTS(SELECT 1 FROM jobs j2 WHERE j2.tenant_id=q.tenant_id AND j2.quote_id=q.id AND j2.deleted_at IS NULL AND j2.status NOT IN ('cancelled','archived')) THEN 1 ELSE 0 END) previous_converted
                 FROM quotes q WHERE q.tenant_id=:tenant_id"
            );
            $periodStmt->execute(array(':tenant_id'=>$t));
            $period=$periodStmt->fetch(PDO::FETCH_ASSOC)?:array();
            $newQuotes30=(int)($period['current_quotes']??0);
            $previousNewQuotes30=(int)($period['previous_quotes']??0);
            $converted30=(int)($period['current_converted']??0);
            $previousConverted30=(int)($period['previous_converted']??0);

            $detailStmt=$pdo->prepare(
                "SELECT q.id quote_id,q.quote_no,q.title quote_title,c.display_name client_name,j.id job_id,j.job_no,j.status job_status,j.created_at job_created_at
                 FROM quotes q
                 INNER JOIN jobs j ON j.quote_id=q.id AND j.tenant_id=q.tenant_id AND j.deleted_at IS NULL AND j.status NOT IN ('cancelled','archived')
                 LEFT JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id
                 WHERE q.tenant_id=:tenant_id AND q.created_at>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) AND q.created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)
                 ORDER BY j.created_at DESC,j.id DESC LIMIT 25"
            );
            $detailStmt->execute(array(':tenant_id'=>$t));
            $convertedQuotes=$detailStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $periodStmt=$pdo->prepare(
                "SELECT
                  SUM(CASE WHEN created_at>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) AND created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY) THEN 1 ELSE 0 END) current_quotes,
                  SUM(CASE WHEN created_at>=DATE_SUB(CURDATE(),INTERVAL 59 DAY) AND created_at<DATE_SUB(CURDATE(),INTERVAL 29 DAY) THEN 1 ELSE 0 END) previous_quotes
                 FROM quotes WHERE tenant_id=:tenant_id"
            );
            $periodStmt->execute(array(':tenant_id'=>$t));
            $period=$periodStmt->fetch(PDO::FETCH_ASSOC)?:array();
            $newQuotes30=(int)($period['current_quotes']??0);
            $previousNewQuotes30=(int)($period['previous_quotes']??0);
        }

        $conversionRate30=$newQuotes30>0?round(($converted30/$newQuotes30)*100,1):0.0;
        $previousConversionRate30=$previousNewQuotes30>0?round(($previousConverted30/$previousNewQuotes30)*100,1):0.0;
        $newQuotesChange=$previousNewQuotes30>0?round((($newQuotes30-$previousNewQuotes30)/$previousNewQuotes30)*100,1):($newQuotes30>0?100.0:0.0);
        $conversionRateChange=$previousConversionRate30>0?round((($conversionRate30-$previousConversionRate30)/$previousConversionRate30)*100,1):($conversionRate30>0?100.0:0.0);

        cr(
            200,
            true,
            'Quotations loaded successfully.',
            array(
                'quotations' => $rows,
                'currency' => quotationCurrency($pdo, $t, $b),
                'summary' => array(
                    'total'=>(int)($summary['total']??0),
                    'needs_approval'=>(int)($summary['needs_approval']??0),
                    'draft'=>(int)($summary['draft']??0),
                    'sent_viewed'=>(int)($summary['sent_viewed']??0),
                    'approved'=>(int)($summary['approved']??0),
                    'converted_to_job'=>$convertedAll,
                    'new_quotes_30'=>$newQuotes30,
                    'previous_new_quotes_30'=>$previousNewQuotes30,
                    'new_quotes_change_percent'=>$newQuotesChange,
                    'converted_30'=>$converted30,
                    'previous_converted_30'=>$previousConverted30,
                    'conversion_rate_30'=>$conversionRate30,
                    'previous_conversion_rate_30'=>$previousConversionRate30,
                    'conversion_rate_change_percent'=>$conversionRateChange
                ),
                'converted_quotes'=>$convertedQuotes,
                'pagination' => array(
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'pages' => $pages,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => $total > 0
                        ? min($offset + count($rows), $total)
                        : 0
                )
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD ONE SAVED QUOTATION
    |--------------------------------------------------------------------------
    | This can also be used by quotation-view.php.
    */
    if ($action === 'get') {

        $quoteId = (int)cp('quote_id', 0);

        if ($quoteId <= 0) {
            cr(422, false, 'Invalid quotation.');
        }

        $quote = getQuotation($pdo, $t, $quoteId);

        cr(
            200,
            true,
            'Quotation loaded successfully.',
            array(
                'quotation' => $quote,
                'items' => getQuotationItems($pdo, $quoteId),
                'actions' => getQuotationActions($pdo, $t, $quoteId),
                'currency' => quotationCurrency(
                    $pdo,
                    $t,
                    isset($quote['branch_id'])
                        ? (int)$quote['branch_id']
                        : $b
                )
            )
        );
    }

    cr(400, false, 'Unsupported quotation action.');

} catch (PDOException $e) {

    error_log(
        'FieldPlx quotations PDO error: ' . $e->getMessage()
    );

    cr(
        500,
        false,
        'Unable to process the quotation request.'
    );

} catch (Throwable $e) {

    error_log(
        'FieldPlx quotations API error: ' . $e->getMessage()
    );

    cr(
        500,
        false,
        $e->getMessage()
    );
}
