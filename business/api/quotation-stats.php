<?php
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function qsResponse($status, $success, $message, $data = array())
{
    http_response_code((int)$status);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message
    ), $data));
    exit;
}

function qsPercentChange($current, $previous)
{
    $current = (float)$current;
    $previous = (float)$previous;

    if (abs($previous) < 0.0000001) {
        return $current > 0 ? 100.0 : 0.0;
    }

    return (($current - $previous) / abs($previous)) * 100.0;
}

function qsDateLabel($date)
{
    return date('M j', strtotime($date));
}

try {
    $tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
    if ($tenantId <= 0) {
        qsResponse(401, false, 'Tenant session is not available.');
    }

    $expectedToken = isset($_SESSION['quotations_csrf_token'])
        ? (string)$_SESSION['quotations_csrf_token']
        : '';
    $receivedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';

    if ($expectedToken === '' || $receivedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
        qsResponse(419, false, 'Your form session expired. Refresh and try again.');
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        if (isset($db) && $db instanceof PDO) {
            $pdo = $db;
        } else {
            qsResponse(500, false, 'Database connection is not available.');
        }
    }

    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'summary';
    if ($action !== 'summary') {
        qsResponse(400, false, 'Unsupported quotation stats action.');
    }

    $currentStart = date('Y-m-d', strtotime('-29 days'));
    $currentEnd = date('Y-m-d');
    $previousStart = date('Y-m-d', strtotime('-59 days'));
    $previousEnd = date('Y-m-d', strtotime('-30 days'));

    $overviewStmt = $pdo->prepare(
        "SELECT
            SUM(status = 'draft') AS draft,
            SUM(status IN ('sent','viewed')) AS awaiting_response,
            SUM(status = 'changes_requested') AS changes_requested,
            SUM(status = 'approved') AS approved
         FROM quotes
         WHERE tenant_id = :tenant_id"
    );
    $overviewStmt->execute(array(':tenant_id' => $tenantId));
    $overview = $overviewStmt->fetch(PDO::FETCH_ASSOC);
    if (!$overview) {
        $overview = array();
    }

    $quotePeriodStmt = $pdo->prepare(
        "SELECT
            SUM(DATE(created_at) BETWEEN :current_start AND :current_end) AS new_quotes_30,
            SUM(DATE(created_at) BETWEEN :previous_start AND :previous_end) AS previous_new_quotes_30
         FROM quotes
         WHERE tenant_id = :tenant_id"
    );
    $quotePeriodStmt->execute(array(
        ':current_start' => $currentStart,
        ':current_end' => $currentEnd,
        ':previous_start' => $previousStart,
        ':previous_end' => $previousEnd,
        ':tenant_id' => $tenantId
    ));
    $quotePeriods = $quotePeriodStmt->fetch(PDO::FETCH_ASSOC);
    if (!$quotePeriods) {
        $quotePeriods = array();
    }

    $sentStmt = $pdo->prepare(
        "SELECT
            SUM(sent_at IS NOT NULL AND DATE(sent_at) BETWEEN :current_start AND :current_end) AS sent_30,
            SUM(CASE WHEN sent_at IS NOT NULL AND DATE(sent_at) BETWEEN :current_start2 AND :current_end2 THEN total ELSE 0 END) AS sent_amount_30,
            SUM(sent_at IS NOT NULL AND DATE(sent_at) BETWEEN :previous_start AND :previous_end) AS previous_sent_30,
            SUM(CASE WHEN sent_at IS NOT NULL AND DATE(sent_at) BETWEEN :previous_start2 AND :previous_end2 THEN total ELSE 0 END) AS previous_sent_amount_30
         FROM quotes
         WHERE tenant_id = :tenant_id"
    );
    $sentStmt->execute(array(
        ':current_start' => $currentStart,
        ':current_end' => $currentEnd,
        ':current_start2' => $currentStart,
        ':current_end2' => $currentEnd,
        ':previous_start' => $previousStart,
        ':previous_end' => $previousEnd,
        ':previous_start2' => $previousStart,
        ':previous_end2' => $previousEnd,
        ':tenant_id' => $tenantId
    ));
    $sent = $sentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sent) {
        $sent = array();
    }

    $convertedCurrentStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS converted_30,
            COALESCE(SUM(q.total),0) AS converted_amount_30
         FROM quotes q
         WHERE q.tenant_id = :tenant_id
           AND EXISTS (
               SELECT 1
               FROM jobs j
               WHERE j.tenant_id = q.tenant_id
                 AND j.quote_id = q.id
                 AND j.deleted_at IS NULL
                 AND j.status NOT IN ('cancelled','archived')
                 AND DATE(j.created_at) BETWEEN :start_date AND :end_date
           )"
    );
    $convertedCurrentStmt->execute(array(
        ':tenant_id' => $tenantId,
        ':start_date' => $currentStart,
        ':end_date' => $currentEnd
    ));
    $convertedCurrent = $convertedCurrentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$convertedCurrent) {
        $convertedCurrent = array();
    }

    $convertedPreviousStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS converted_30,
            COALESCE(SUM(q.total),0) AS converted_amount_30
         FROM quotes q
         WHERE q.tenant_id = :tenant_id
           AND EXISTS (
               SELECT 1
               FROM jobs j
               WHERE j.tenant_id = q.tenant_id
                 AND j.quote_id = q.id
                 AND j.deleted_at IS NULL
                 AND j.status NOT IN ('cancelled','archived')
                 AND DATE(j.created_at) BETWEEN :start_date AND :end_date
           )"
    );
    $convertedPreviousStmt->execute(array(
        ':tenant_id' => $tenantId,
        ':start_date' => $previousStart,
        ':end_date' => $previousEnd
    ));
    $convertedPrevious = $convertedPreviousStmt->fetch(PDO::FETCH_ASSOC);
    if (!$convertedPrevious) {
        $convertedPrevious = array();
    }

    $cohortCurrentStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM quotes q
         WHERE q.tenant_id = :tenant_id
           AND DATE(q.created_at) BETWEEN :start_date AND :end_date
           AND EXISTS (
               SELECT 1
               FROM jobs j
               WHERE j.tenant_id = q.tenant_id
                 AND j.quote_id = q.id
                 AND j.deleted_at IS NULL
                 AND j.status NOT IN ('cancelled','archived')
           )"
    );
    $cohortCurrentStmt->execute(array(
        ':tenant_id' => $tenantId,
        ':start_date' => $currentStart,
        ':end_date' => $currentEnd
    ));
    $convertedQuoteCohort30 = (int)$cohortCurrentStmt->fetchColumn();

    $cohortPreviousStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM quotes q
         WHERE q.tenant_id = :tenant_id
           AND DATE(q.created_at) BETWEEN :start_date AND :end_date
           AND EXISTS (
               SELECT 1
               FROM jobs j
               WHERE j.tenant_id = q.tenant_id
                 AND j.quote_id = q.id
                 AND j.deleted_at IS NULL
                 AND j.status NOT IN ('cancelled','archived')
           )"
    );
    $cohortPreviousStmt->execute(array(
        ':tenant_id' => $tenantId,
        ':start_date' => $previousStart,
        ':end_date' => $previousEnd
    ));
    $convertedQuoteCohortPrevious = (int)$cohortPreviousStmt->fetchColumn();

    $newQuotes30 = isset($quotePeriods['new_quotes_30']) ? (int)$quotePeriods['new_quotes_30'] : 0;
    $previousNewQuotes30 = isset($quotePeriods['previous_new_quotes_30']) ? (int)$quotePeriods['previous_new_quotes_30'] : 0;

    $conversionRate30 = $newQuotes30 > 0
        ? ($convertedQuoteCohort30 / $newQuotes30) * 100.0
        : 0.0;
    $previousConversionRate30 = $previousNewQuotes30 > 0
        ? ($convertedQuoteCohortPrevious / $previousNewQuotes30) * 100.0
        : 0.0;

    $sent30 = isset($sent['sent_30']) ? (int)$sent['sent_30'] : 0;
    $previousSent30 = isset($sent['previous_sent_30']) ? (int)$sent['previous_sent_30'] : 0;
    $converted30 = isset($convertedCurrent['converted_30']) ? (int)$convertedCurrent['converted_30'] : 0;
    $previousConverted30 = isset($convertedPrevious['converted_30']) ? (int)$convertedPrevious['converted_30'] : 0;

    $convertedListStmt = $pdo->prepare(
        "SELECT
            q.id AS quote_id,
            q.quote_no,
            q.title AS quote_title,
            c.display_name AS client_name,
            MIN(j.id) AS job_id,
            SUBSTRING_INDEX(GROUP_CONCAT(j.job_no ORDER BY j.created_at ASC, j.id ASC SEPARATOR '||'), '||', 1) AS job_no
         FROM quotes q
         INNER JOIN clients c
           ON c.id = q.client_id
          AND c.tenant_id = q.tenant_id
         INNER JOIN jobs j
           ON j.quote_id = q.id
          AND j.tenant_id = q.tenant_id
          AND j.deleted_at IS NULL
          AND j.status NOT IN ('cancelled','archived')
         WHERE q.tenant_id = :tenant_id
           AND DATE(q.created_at) BETWEEN :start_date AND :end_date
         GROUP BY q.id, q.quote_no, q.title, c.display_name
         ORDER BY q.created_at DESC, q.id DESC
         LIMIT 20"
    );
    $convertedListStmt->execute(array(
        ':tenant_id' => $tenantId,
        ':start_date' => $currentStart,
        ':end_date' => $currentEnd
    ));
    $convertedQuotes = $convertedListStmt->fetchAll(PDO::FETCH_ASSOC);

    qsResponse(200, true, 'Quotation statistics loaded.', array(
        'summary' => array(
            'draft' => isset($overview['draft']) ? (int)$overview['draft'] : 0,
            'awaiting_response' => isset($overview['awaiting_response']) ? (int)$overview['awaiting_response'] : 0,
            'changes_requested' => isset($overview['changes_requested']) ? (int)$overview['changes_requested'] : 0,
            'approved' => isset($overview['approved']) ? (int)$overview['approved'] : 0,
            'new_quotes_30' => $newQuotes30,
            'previous_new_quotes_30' => $previousNewQuotes30,
            'converted_quote_cohort_30' => $convertedQuoteCohort30,
            'conversion_rate_30' => round($conversionRate30, 2),
            'previous_conversion_rate_30' => round($previousConversionRate30, 2),
            'conversion_rate_change_percent' => round(qsPercentChange($conversionRate30, $previousConversionRate30), 2),
            'sent_30' => $sent30,
            'previous_sent_30' => $previousSent30,
            'sent_amount_30' => isset($sent['sent_amount_30']) ? (float)$sent['sent_amount_30'] : 0.0,
            'previous_sent_amount_30' => isset($sent['previous_sent_amount_30']) ? (float)$sent['previous_sent_amount_30'] : 0.0,
            'sent_change_percent' => round(qsPercentChange($sent30, $previousSent30), 2),
            'converted_30' => $converted30,
            'previous_converted_30' => $previousConverted30,
            'converted_amount_30' => isset($convertedCurrent['converted_amount_30']) ? (float)$convertedCurrent['converted_amount_30'] : 0.0,
            'previous_converted_amount_30' => isset($convertedPrevious['converted_amount_30']) ? (float)$convertedPrevious['converted_amount_30'] : 0.0,
            'converted_change_percent' => round(qsPercentChange($converted30, $previousConverted30), 2)
        ),
        'periods' => array(
            'previous_label' => qsDateLabel($previousStart) . ' - ' . qsDateLabel($previousEnd),
            'current_label' => qsDateLabel($currentStart) . ' - ' . qsDateLabel($currentEnd)
        ),
        'converted_quotes' => $convertedQuotes
    ));
} catch (PDOException $e) {
    error_log('FieldPlx quotation stats PDO error: ' . $e->getMessage());
    qsResponse(500, false, 'Unable to load quotation statistics.');
} catch (Throwable $e) {
    error_log('FieldPlx quotation stats error: ' . $e->getMessage());
    qsResponse(500, false, 'Unable to load quotation statistics.');
}
