<?php
/* FieldPlx Insights - Dynamic Business Analytics - 2026-09-02 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Insights';
$activePage = 'insights';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$insTenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$insCurrencySymbol = isset($_SESSION['tenant_currency_symbol']) && $_SESSION['tenant_currency_symbol'] !== ''
    ? (string)$_SESSION['tenant_currency_symbol']
    : '₹';

$insPdo = null;
if (isset($pdo) && $pdo instanceof PDO) {
    $insPdo = $pdo;
} elseif (isset($db) && $db instanceof PDO) {
    $insPdo = $db;
}

function ins_table_exists(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:n");
    $q->execute(array(':n' => $table));
    $cache[$table] = ((int)$q->fetchColumn() > 0);
    return $cache[$table];
}

function ins_column_exists(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = ((int)$q->fetchColumn() > 0);
    return $cache[$key];
}

function ins_scalar(PDO $pdo, $sql, $params = array(), $default = 0)
{
    try {
        $q = $pdo->prepare($sql);
        $q->execute($params);
        $v = $q->fetchColumn();
        return $v === false || $v === null ? $default : $v;
    } catch (Throwable $e) {
        error_log('FieldPlx insights scalar error: ' . $e->getMessage());
        return $default;
    }
}

function ins_rows(PDO $pdo, $sql, $params = array())
{
    try {
        $q = $pdo->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('FieldPlx insights rows error: ' . $e->getMessage());
        return array();
    }
}

function ins_valid_date($value)
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

function ins_pct_change($current, $previous)
{
    $current = (float)$current;
    $previous = (float)$previous;
    if (abs($previous) < 0.00001) return abs($current) < 0.00001 ? 0.0 : 100.0;
    return (($current - $previous) / abs($previous)) * 100.0;
}

function ins_trend_class($pct)
{
    return $pct > 0 ? '' : ($pct < 0 ? ' down' : ' neutral');
}

function ins_trend_text($pct)
{
    $prefix = $pct > 0 ? '↑ ' : ($pct < 0 ? '↓ ' : '');
    return $prefix . rtrim(rtrim(number_format(abs((float)$pct), 1, '.', ''), '0'), '.') . '%';
}

function ins_date_label($date)
{
    $ts = strtotime($date);
    return $ts ? date('M j, Y', $ts) : $date;
}

/* Default to the last complete calendar month, matching the reference Insights view. */
$defaultFrom = date('Y-m-01', strtotime('first day of last month'));
$defaultTo = date('Y-m-t', strtotime('last day of last month'));
$insFrom = isset($_GET['from']) && ins_valid_date($_GET['from']) ? $_GET['from'] : $defaultFrom;
$insTo = isset($_GET['to']) && ins_valid_date($_GET['to']) ? $_GET['to'] : $defaultTo;
if (strtotime($insFrom) > strtotime($insTo)) {
    $tmp = $insFrom;
    $insFrom = $insTo;
    $insTo = $tmp;
}

$insRangeDays = max(1, (int)floor((strtotime($insTo) - strtotime($insFrom)) / 86400) + 1);
$insPrevTo = date('Y-m-d', strtotime($insFrom . ' -1 day'));
$insPrevFrom = date('Y-m-d', strtotime($insPrevTo . ' -' . ($insRangeDays - 1) . ' days'));
$insFromDT = $insFrom . ' 00:00:00';
$insToExclusive = date('Y-m-d', strtotime($insTo . ' +1 day')) . ' 00:00:00';
$insPrevFromDT = $insPrevFrom . ' 00:00:00';
$insPrevToExclusive = date('Y-m-d', strtotime($insPrevTo . ' +1 day')) . ' 00:00:00';
$insRangeLabel = ins_date_label($insFrom) . ' - ' . ins_date_label($insTo);
$insPrevRangeLabel = ins_date_label($insPrevFrom) . ' - ' . ins_date_label($insPrevTo);

$insDataError = '';
$overview = array(
    'new_leads' => 0,
    'new_requests' => 0,
    'converted_quotes' => 0,
    'one_off_jobs' => 0,
    'recurring_jobs' => 0,
    'invoiced_value' => 0.0
);
$overviewPrev = $overview;

$revenueYears = array();
$leadSourceRevenue = array();
$profitRows = array();
$cashflow = array(
    'receivables' => 0.0,
    'receivable_clients' => 0,
    'due_today' => 0.0,
    'due_7_days' => 0.0,
    'payment_days' => null
);
$paymentMethods = array();
$leadConversion = array(
    'lead_days' => null,
    'quote_approval_days' => null,
    'quote_conversion_rate' => 0.0
);
$leadFunnel = array('new_leads' => 0, 'sent_quote' => 0, 'job_created' => 0);
$quoteTrend = array();
$scheduledJobWeeks = array();
$jobMix = array('one_off' => 0.0, 'recurring' => 0.0);
$avgJobValue = array('one_off' => 0.0, 'recurring' => 0.0);

if ($insPdo instanceof PDO && $insTenantId > 0) {
    try {
        $t = $insTenantId;

        /* ---------------- Overview ---------------- */
        if (ins_table_exists($insPdo, 'clients')) {
            $overview['new_leads'] = (int)ins_scalar(
                $insPdo,
                "SELECT COUNT(*) FROM clients
                 WHERE tenant_id=:t AND deleted_at IS NULL AND client_type='lead'
                   AND created_at>=:f AND created_at<:x",
                array(':t'=>$t, ':f'=>$insFromDT, ':x'=>$insToExclusive)
            );
            $overviewPrev['new_leads'] = (int)ins_scalar(
                $insPdo,
                "SELECT COUNT(*) FROM clients
                 WHERE tenant_id=:t AND deleted_at IS NULL AND client_type='lead'
                   AND created_at>=:f AND created_at<:x",
                array(':t'=>$t, ':f'=>$insPrevFromDT, ':x'=>$insPrevToExclusive)
            );
        }

        if (ins_table_exists($insPdo, 'service_requests')) {
            $overview['new_requests'] = (int)ins_scalar(
                $insPdo,
                "SELECT COUNT(*) FROM service_requests
                 WHERE tenant_id=:t AND created_at>=:f AND created_at<:x AND status<>'cancelled'",
                array(':t'=>$t, ':f'=>$insFromDT, ':x'=>$insToExclusive)
            );
            $overviewPrev['new_requests'] = (int)ins_scalar(
                $insPdo,
                "SELECT COUNT(*) FROM service_requests
                 WHERE tenant_id=:t AND created_at>=:f AND created_at<:x AND status<>'cancelled'",
                array(':t'=>$t, ':f'=>$insPrevFromDT, ':x'=>$insPrevToExclusive)
            );
        }

        if (ins_table_exists($insPdo, 'quotes') && ins_table_exists($insPdo, 'jobs')) {
            /* A quote is counted as converted when a real job was created from that quote. */
            $overview['converted_quotes'] = (int)ins_scalar(
                $insPdo,
                "SELECT COUNT(DISTINCT q.id)
                   FROM quotes q
                   JOIN jobs j ON j.quote_id=q.id AND j.tenant_id=q.tenant_id AND j.deleted_at IS NULL
                  WHERE q.tenant_id=:t
                    AND j.created_at>=:f AND j.created_at<:x
                    AND j.status NOT IN('cancelled','archived')",
                array(':t'=>$t, ':f'=>$insFromDT, ':x'=>$insToExclusive)
            );
            $overviewPrev['converted_quotes'] = (int)ins_scalar(
                $insPdo,
                "SELECT COUNT(DISTINCT q.id)
                   FROM quotes q
                   JOIN jobs j ON j.quote_id=q.id AND j.tenant_id=q.tenant_id AND j.deleted_at IS NULL
                  WHERE q.tenant_id=:t
                    AND j.created_at>=:f AND j.created_at<:x
                    AND j.status NOT IN('cancelled','archived')",
                array(':t'=>$t, ':f'=>$insPrevFromDT, ':x'=>$insPrevToExclusive)
            );
        }

        if (ins_table_exists($insPdo, 'jobs')) {
            foreach (array('one_off','recurring') as $jobType) {
                $key = $jobType === 'one_off' ? 'one_off_jobs' : 'recurring_jobs';
                $overview[$key] = (int)ins_scalar(
                    $insPdo,
                    "SELECT COUNT(*) FROM jobs
                     WHERE tenant_id=:t AND deleted_at IS NULL AND job_type=:jt
                       AND status NOT IN('cancelled','archived')
                       AND created_at>=:f AND created_at<:x",
                    array(':t'=>$t, ':jt'=>$jobType, ':f'=>$insFromDT, ':x'=>$insToExclusive)
                );
                $overviewPrev[$key] = (int)ins_scalar(
                    $insPdo,
                    "SELECT COUNT(*) FROM jobs
                     WHERE tenant_id=:t AND deleted_at IS NULL AND job_type=:jt
                       AND status NOT IN('cancelled','archived')
                       AND created_at>=:f AND created_at<:x",
                    array(':t'=>$t, ':jt'=>$jobType, ':f'=>$insPrevFromDT, ':x'=>$insPrevToExclusive)
                );
            }
        }

        if (ins_table_exists($insPdo, 'invoices')) {
            $invoiceLiveStatuses = "status NOT IN('draft','cancelled','archived','written_off')";
            $overview['invoiced_value'] = (float)ins_scalar(
                $insPdo,
                "SELECT COALESCE(SUM(total),0) FROM invoices
                 WHERE tenant_id=:t AND $invoiceLiveStatuses
                   AND issue_date>=:f AND issue_date<=:x",
                array(':t'=>$t, ':f'=>$insFrom, ':x'=>$insTo)
            );
            $overviewPrev['invoiced_value'] = (float)ins_scalar(
                $insPdo,
                "SELECT COALESCE(SUM(total),0) FROM invoices
                 WHERE tenant_id=:t AND $invoiceLiveStatuses
                   AND issue_date>=:f AND issue_date<=:x",
                array(':t'=>$t, ':f'=>$insPrevFrom, ':x'=>$insPrevTo)
            );

            /* ---------------- Revenue: previous vs current year ---------------- */
            $currentYear = (int)date('Y');
            $previousYear = $currentYear - 1;
            $revenueYears = array(
                (string)$previousYear => array_fill(1, 12, 0.0),
                (string)$currentYear => array_fill(1, 12, 0.0)
            );
            $revRows = ins_rows(
                $insPdo,
                "SELECT YEAR(issue_date) y, MONTH(issue_date) m, COALESCE(SUM(total),0) total
                   FROM invoices
                  WHERE tenant_id=:t AND $invoiceLiveStatuses
                    AND issue_date IS NOT NULL
                    AND YEAR(issue_date) IN(:py,:cy)
                  GROUP BY YEAR(issue_date),MONTH(issue_date)
                  ORDER BY y,m",
                array(':t'=>$t, ':py'=>$previousYear, ':cy'=>$currentYear)
            );
            foreach ($revRows as $r) {
                $y = (string)(int)$r['y'];
                $m = (int)$r['m'];
                if (isset($revenueYears[$y]) && $m >= 1 && $m <= 12) $revenueYears[$y][$m] = (float)$r['total'];
            }

            /* Revenue by customer lead source in selected period. */
            if (ins_table_exists($insPdo, 'clients')) {
                $leadSourceRevenue = ins_rows(
                    $insPdo,
                    "SELECT COALESCE(NULLIF(TRIM(c.source),''),'Unspecified') source_name,
                            COALESCE(SUM(i.total),0) total
                       FROM invoices i
                       JOIN clients c ON c.id=i.client_id AND c.tenant_id=i.tenant_id
                      WHERE i.tenant_id=:t AND $invoiceLiveStatuses
                        AND i.issue_date>=:f AND i.issue_date<=:x
                      GROUP BY COALESCE(NULLIF(TRIM(c.source),''),'Unspecified')
                      ORDER BY total DESC",
                    array(':t'=>$t, ':f'=>$insFrom, ':x'=>$insTo)
                );
            }

            /* ---------------- Cashflow ---------------- */
            $cashflow['receivables'] = (float)ins_scalar(
                $insPdo,
                "SELECT COALESCE(SUM(balance_due),0) FROM invoices
                  WHERE tenant_id=:t AND balance_due>0
                    AND status NOT IN('draft','cancelled','archived','written_off')",
                array(':t'=>$t)
            );
            $cashflow['receivable_clients'] = (int)ins_scalar(
                $insPdo,
                "SELECT COUNT(DISTINCT client_id) FROM invoices
                  WHERE tenant_id=:t AND balance_due>0
                    AND status NOT IN('draft','cancelled','archived','written_off')",
                array(':t'=>$t)
            );
            $cashflow['due_today'] = (float)ins_scalar(
                $insPdo,
                "SELECT COALESCE(SUM(balance_due),0) FROM invoices
                  WHERE tenant_id=:t AND balance_due>0 AND due_date=CURDATE()
                    AND status NOT IN('draft','cancelled','archived','written_off')",
                array(':t'=>$t)
            );
            $cashflow['due_7_days'] = (float)ins_scalar(
                $insPdo,
                "SELECT COALESCE(SUM(balance_due),0) FROM invoices
                  WHERE tenant_id=:t AND balance_due>0
                    AND due_date>CURDATE() AND due_date<=DATE_ADD(CURDATE(),INTERVAL 7 DAY)
                    AND status NOT IN('draft','cancelled','archived','written_off')",
                array(':t'=>$t)
            );
            $paymentDays = ins_scalar(
                $insPdo,
                "SELECT AVG(DATEDIFF(DATE(paid_at),issue_date)) FROM invoices
                  WHERE tenant_id=:t AND paid_at IS NOT NULL AND issue_date IS NOT NULL
                    AND DATE(paid_at)>=:f AND DATE(paid_at)<=:x
                    AND status='paid'",
                array(':t'=>$t, ':f'=>$insFrom, ':x'=>$insTo),
                null
            );
            $cashflow['payment_days'] = $paymentDays === null ? null : max(0.0, (float)$paymentDays);
        }

        if (ins_table_exists($insPdo, 'payments')) {
            $paymentMethods = ins_rows(
                $insPdo,
                "SELECT payment_method, COALESCE(SUM(amount),0) total
                   FROM payments
                  WHERE tenant_id=:t AND status='succeeded'
                    AND DATE(COALESCE(received_at,created_at))>=:f
                    AND DATE(COALESCE(received_at,created_at))<=:x
                  GROUP BY payment_method
                  ORDER BY total DESC",
                array(':t'=>$t, ':f'=>$insFrom, ':x'=>$insTo)
            );
        }

        /* ---------------- Profit and loss ---------------- */
        if (ins_table_exists($insPdo, 'jobs') && ins_table_exists($insPdo, 'clients')) {
            $laborExpr = ins_table_exists($insPdo, 'time_entries')
                ? "(SELECT COALESCE(SUM(COALESCE(te.labor_cost,0)),0)
                     FROM time_entries te
                    WHERE te.tenant_id=j.tenant_id AND te.job_id=j.id AND te.status<>'rejected')"
                : "0";
            $expenseExpr = "0";
            if (ins_table_exists($insPdo, 'expenses')) {
                $expenseDeletedCondition = ins_column_exists($insPdo, 'expenses', 'deleted_at') ? " AND e.deleted_at IS NULL" : "";
                $expenseExpr = "(SELECT COALESCE(SUM(e.amount),0)
                                   FROM expenses e
                                  WHERE e.tenant_id=j.tenant_id AND e.job_id=j.id
                                    AND e.status<>'rejected' $expenseDeletedCondition)";
            }
            $materialExpr = ins_table_exists($insPdo, 'job_material_costs')
                ? "(SELECT COALESCE(SUM(jmc.total_cost),0)
                     FROM job_material_costs jmc
                    WHERE jmc.tenant_id=j.tenant_id AND jmc.job_id=j.id)"
                : "0";

            $profitRows = ins_rows(
                $insPdo,
                "SELECT j.id,j.job_no,j.title,j.status,j.total,
                        c.display_name client_name,
                        $laborExpr labor_cost,
                        $expenseExpr direct_expense,
                        $materialExpr material_cost
                   FROM jobs j
                   JOIN clients c ON c.id=j.client_id AND c.tenant_id=j.tenant_id
                  WHERE j.tenant_id=:t AND j.deleted_at IS NULL
                    AND j.job_type='one_off'
                    AND j.status NOT IN('draft','cancelled','archived')
                    AND DATE(j.created_at)>=:f AND DATE(j.created_at)<=:x
                  ORDER BY COALESCE(j.completed_at,j.updated_at,j.created_at) DESC
                  LIMIT 40",
                array(':t'=>$t, ':f'=>$insFrom, ':x'=>$insTo)
            );
            foreach ($profitRows as &$pr) {
                $price = (float)$pr['total'];
                $labor = (float)$pr['labor_cost'];
                $direct = (float)$pr['direct_expense'];
                $material = (float)$pr['material_cost'];
                $allExpenses = $direct + $material;
                $profit = $price - $labor - $allExpenses;
                $pr['expenses_total'] = $allExpenses;
                $pr['profit'] = $profit;
                $pr['margin'] = $price > 0 ? ($profit / $price) * 100 : null;
                $pr['view_group'] = in_array($pr['status'], array('completed','invoiced','closed'), true) ? 'completed' : 'active';
            }
            unset($pr);
        }

        /* ---------------- Lead conversion ---------------- */
        if (ins_table_exists($insPdo, 'clients') && ins_table_exists($insPdo, 'jobs')) {
            $leadDays = ins_scalar(
                $insPdo,
                "SELECT AVG(DATEDIFF(first_job_at,client_created_at))
                   FROM (
                         SELECT c.id,c.created_at client_created_at,MIN(j.created_at) first_job_at
                           FROM clients c
                           JOIN jobs j ON j.client_id=c.id AND j.tenant_id=c.tenant_id
                                      AND j.deleted_at IS NULL AND j.status NOT IN('cancelled','archived')
                          WHERE c.tenant_id=:t AND c.deleted_at IS NULL
                            AND DATE(c.created_at)>=:f AND DATE(c.created_at)<=:x
                          GROUP BY c.id,c.created_at
                        ) x",
                array(':t'=>$t, ':f'=>$insFrom, ':x'=>$insTo),
                null
            );
            $leadConversion['lead_days'] = $leadDays === null ? null : max(0.0, (float)$leadDays);
        }

        if (ins_table_exists($insPdo, 'quotes')) {
            $approvalDays = ins_scalar(
                $insPdo,
                "SELECT AVG(TIMESTAMPDIFF(MINUTE,sent_at,approved_at))/1440
                   FROM quotes
                  WHERE tenant_id=:t AND sent_at IS NOT NULL AND approved_at IS NOT NULL
                    AND DATE(approved_at)>=:f AND DATE(approved_at)<=:x",
                array(':t'=>$t, ':f'=>$insFrom, ':x'=>$insTo),
                null
            );
            $leadConversion['quote_approval_days'] = $approvalDays === null ? null : max(0.0, (float)$approvalDays);

            $sentCount = (int)ins_scalar(
                $insPdo,
                "SELECT COUNT(*) FROM quotes
                  WHERE tenant_id=:t
                    AND COALESCE(sent_at,created_at)>=:f AND COALESCE(sent_at,created_at)<:x
                    AND status NOT IN('draft','internal_approval','archived')",
                array(':t'=>$t, ':f'=>$insFromDT, ':x'=>$insToExclusive)
            );
            $convertedCount = (int)ins_scalar(
                $insPdo,
                "SELECT COUNT(*) FROM quotes
                  WHERE tenant_id=:t
                    AND COALESCE(sent_at,created_at)>=:f AND COALESCE(sent_at,created_at)<:x
                    AND status='converted'",
                array(':t'=>$t, ':f'=>$insFromDT, ':x'=>$insToExclusive)
            );
            $leadConversion['quote_conversion_rate'] = $sentCount > 0 ? ($convertedCount / $sentCount) * 100 : 0.0;

            $quoteTrend = ins_rows(
                $insPdo,
                "SELECT DATE(COALESCE(sent_at,created_at)) d,
                        COUNT(*) sent_count,
                        SUM(CASE WHEN status='converted' THEN 1 ELSE 0 END) converted_count,
                        COALESCE(SUM(total),0) sent_value,
                        COALESCE(SUM(CASE WHEN status='converted' THEN total ELSE 0 END),0) converted_value
                   FROM quotes
                  WHERE tenant_id=:t
                    AND DATE(COALESCE(sent_at,created_at))>=:f
                    AND DATE(COALESCE(sent_at,created_at))<=:x
                    AND status NOT IN('draft','internal_approval','archived')
                  GROUP BY DATE(COALESCE(sent_at,created_at))
                  ORDER BY d",
                array(':t'=>$t, ':f'=>$insFrom, ':x'=>$insTo)
            );
        }

        /* Fixed 90-day funnel, as in the reference page. */
        if (ins_table_exists($insPdo, 'clients')) {
            $funnelStart = date('Y-m-d', strtotime('-89 days'));
            $leadFunnel['new_leads'] = (int)ins_scalar(
                $insPdo,
                "SELECT COUNT(*) FROM clients
                  WHERE tenant_id=:t AND deleted_at IS NULL
                    AND created_at>=:f AND created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)",
                array(':t'=>$t, ':f'=>$funnelStart . ' 00:00:00')
            );

            if (ins_table_exists($insPdo, 'quotes')) {
                $leadFunnel['sent_quote'] = (int)ins_scalar(
                    $insPdo,
                    "SELECT COUNT(DISTINCT c.id)
                       FROM clients c
                       JOIN quotes q ON q.client_id=c.id AND q.tenant_id=c.tenant_id
                      WHERE c.tenant_id=:t AND c.deleted_at IS NULL
                        AND c.created_at>=:f
                        AND q.status NOT IN('draft','internal_approval','archived')",
                    array(':t'=>$t, ':f'=>$funnelStart . ' 00:00:00')
                );
            }
            if (ins_table_exists($insPdo, 'jobs')) {
                $leadFunnel['job_created'] = (int)ins_scalar(
                    $insPdo,
                    "SELECT COUNT(DISTINCT c.id)
                       FROM clients c
                       JOIN jobs j ON j.client_id=c.id AND j.tenant_id=c.tenant_id
                                  AND j.deleted_at IS NULL AND j.status NOT IN('cancelled','archived')
                      WHERE c.tenant_id=:t AND c.deleted_at IS NULL
                        AND c.created_at>=:f",
                    array(':t'=>$t, ':f'=>$funnelStart . ' 00:00:00')
                );
            }
        }

        /* ---------------- Jobs ---------------- */
        if (ins_table_exists($insPdo, 'jobs')) {
            $today = new DateTime(date('Y-m-d'));
            for ($w = 0; $w < 4; $w++) {
                $start = clone $today;
                $start->modify('+' . ($w * 7) . ' days');
                $end = clone $start;
                $end->modify('+6 days');
                $scheduledJobWeeks[] = array(
                    'label' => $start->format('M d'),
                    'from' => $start->format('Y-m-d'),
                    'to' => $end->format('Y-m-d'),
                    'value' => 0.0
                );
            }
            $scheduledRows = ins_rows(
                $insPdo,
                "SELECT start_date,COALESCE(SUM(total),0) total
                   FROM jobs
                  WHERE tenant_id=:t AND deleted_at IS NULL
                    AND start_date>=CURDATE() AND start_date<=DATE_ADD(CURDATE(),INTERVAL 27 DAY)
                    AND status NOT IN('draft','cancelled','archived','completed','closed','invoiced')
                  GROUP BY start_date
                  ORDER BY start_date",
                array(':t'=>$t)
            );
            foreach ($scheduledRows as $sr) {
                foreach ($scheduledJobWeeks as &$wk) {
                    if ($sr['start_date'] >= $wk['from'] && $sr['start_date'] <= $wk['to']) {
                        $wk['value'] += (float)$sr['total'];
                        break;
                    }
                }
                unset($wk);
            }

            $mixRows = ins_rows(
                $insPdo,
                "SELECT job_type,COALESCE(SUM(total),0) total,AVG(total) avg_value
                   FROM jobs
                  WHERE tenant_id=:t AND deleted_at IS NULL
                    AND status NOT IN('draft','cancelled','archived')
                    AND DATE(created_at)>=:f AND DATE(created_at)<=:x
                  GROUP BY job_type",
                array(':t'=>$t, ':f'=>$insFrom, ':x'=>$insTo)
            );
            foreach ($mixRows as $mr) {
                $jt = $mr['job_type'];
                if (isset($jobMix[$jt])) {
                    $jobMix[$jt] = (float)$mr['total'];
                    $avgJobValue[$jt] = (float)$mr['avg_value'];
                }
            }
        }

    } catch (Throwable $e) {
        $insDataError = $e->getMessage();
        error_log('FieldPlx insights main error: ' . $e->getMessage());
    }
} else {
    $insDataError = 'Database connection or tenant session is unavailable.';
}

$overviewTrend = array();
foreach ($overview as $key => $value) {
    $overviewTrend[$key] = ins_pct_change($value, isset($overviewPrev[$key]) ? $overviewPrev[$key] : 0);
}

$currentYear = (int)date('Y');
$previousYear = $currentYear - 1;
$monthLabels = array('Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec');
$revenuePrevData = array();
$revenueCurrData = array();
for ($m=1; $m<=12; $m++) {
    $revenuePrevData[] = isset($revenueYears[(string)$previousYear][$m]) ? (float)$revenueYears[(string)$previousYear][$m] : 0;
    $revenueCurrData[] = isset($revenueYears[(string)$currentYear][$m]) ? (float)$revenueYears[(string)$currentYear][$m] : 0;
}

$leadSourceLabels = array();
$leadSourceValues = array();
foreach ($leadSourceRevenue as $r) {
    $leadSourceLabels[] = $r['source_name'];
    $leadSourceValues[] = (float)$r['total'];
}

$paymentMethodLabels = array();
$paymentMethodValues = array();
foreach ($paymentMethods as $r) {
    $paymentMethodLabels[] = ucwords(str_replace('_',' ', $r['payment_method']));
    $paymentMethodValues[] = (float)$r['total'];
}

$quoteLabels = array();
$quoteConversionSeries = array();
$quoteSentValueSeries = array();
$quoteConvertedValueSeries = array();
foreach ($quoteTrend as $r) {
    $quoteLabels[] = date('M j', strtotime($r['d']));
    $sent = (int)$r['sent_count'];
    $converted = (int)$r['converted_count'];
    $quoteConversionSeries[] = $sent > 0 ? round(($converted / $sent) * 100, 2) : 0;
    $quoteSentValueSeries[] = (float)$r['sent_value'];
    $quoteConvertedValueSeries[] = (float)$r['converted_value'];
}

$scheduledLabels = array();
$scheduledValues = array();
foreach ($scheduledJobWeeks as $w) {
    $scheduledLabels[] = $w['label'];
    $scheduledValues[] = (float)$w['value'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>Insights - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <style>
    :root {
        --fieldplx-primary: #6d28d9;
        --fieldplx-primary-dark: #5b21b6;
        --fieldplx-text: #1f2937;
        --fieldplx-muted: #6b7280;
        --fieldplx-border: #e5e7eb;
        --fieldplx-surface: #ffffff;
        --fieldplx-background: #f7f7fb;
        --fieldplx-topbar-height: 64px;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        min-height: 100vh;
        overflow-x: hidden;
        background: var(--fieldplx-background);
        color: var(--fieldplx-text);
        font-family: "Inter", sans-serif;
        font-size: 13px;
    }

    .fieldplx-topbar {
        position: sticky;
        top: 0;
        z-index: 1030;
        min-height: var(--fieldplx-topbar-height);
        background: rgba(255, 255, 255, 0.96);
        border-bottom: 1px solid var(--fieldplx-border);
        backdrop-filter: blur(12px);
    }

    .fieldplx-topbar-inner {
        min-height: var(--fieldplx-topbar-height);
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 8px 18px;
    }

    .fieldplx-brand-mobile {
        display: none;
        align-items: center;
        gap: 9px;
        min-width: 0;
        text-decoration: none;
        color: var(--fieldplx-text);
    }

    .fieldplx-brand-logo {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        border-radius: 9px;
        object-fit: contain;
        background: #f3f0ff;
    }

    .fieldplx-brand-placeholder {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        color: #ffffff;
        background: linear-gradient(135deg, #7c3aed, #5b21b6);
        font-size: 15px;
        font-weight: 700;
    }

    .fieldplx-brand-name {
        max-width: 170px;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        font-size: 14px;
        font-weight: 700;
    }

    .fieldplx-menu-toggle {
        width: 36px;
        height: 36px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--fieldplx-border);
        border-radius: 9px;
        background: #ffffff;
        color: #4b5563;
        font-size: 19px;
    }

    .fieldplx-menu-toggle:hover {
        color: var(--fieldplx-primary);
        border-color: #d8ccfb;
        background: #faf8ff;
    }

    .fieldplx-page-heading {
        min-width: 0;
        margin-right: auto;
    }

    .fieldplx-page-title {
        margin: 0;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        color: #111827;
        font-size: 15px;
        font-weight: 700;
    }

    .fieldplx-page-subtitle {
        margin-top: 2px;
        color: var(--fieldplx-muted);
        font-size: 11px;
    }

    .fieldplx-search-wrap {
        width: min(340px, 31vw);
        position: relative;
    }

    .fieldplx-search-icon {
        position: absolute;
        top: 50%;
        left: 12px;
        z-index: 2;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 14px;
        pointer-events: none;
    }

    .fieldplx-search-input {
        height: 38px;
        padding: 8px 13px 8px 35px;
        border: 1px solid var(--fieldplx-border);
        border-radius: 10px;
        background: #f9fafb;
        box-shadow: none;
        font-size: 12px;
    }

    .fieldplx-search-input:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.09);
    }

    .fieldplx-topbar-action {
        width: 38px;
        height: 38px;
        padding: 0;
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--fieldplx-border);
        border-radius: 10px;
        background: #ffffff;
        color: #4b5563;
        font-size: 17px;
    }

    .fieldplx-topbar-action:hover {
        color: var(--fieldplx-primary);
        border-color: #d8ccfb;
        background: #faf8ff;
    }

    .fieldplx-notification-count {
        position: absolute;
        top: -5px;
        right: -5px;
        min-width: 18px;
        height: 18px;
        padding: 0 5px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 2px solid #ffffff;
        border-radius: 999px;
        background: #dc2626;
        color: #ffffff;
        font-size: 9px;
        font-weight: 700;
    }

    .fieldplx-profile-button {
        min-width: 0;
        padding: 4px 8px 4px 5px;
        display: flex;
        align-items: center;
        gap: 9px;
        border: 1px solid var(--fieldplx-border);
        border-radius: 11px;
        background: #ffffff;
        text-align: left;
    }

    .fieldplx-profile-button:hover {
        border-color: #d8ccfb;
        background: #faf8ff;
    }

    .fieldplx-avatar {
        width: 32px;
        height: 32px;
        flex: 0 0 32px;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: linear-gradient(135deg, #7c3aed, #5b21b6);
        color: #ffffff;
        font-size: 11px;
        font-weight: 700;
    }

    .fieldplx-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .fieldplx-profile-details {
        max-width: 145px;
        min-width: 0;
    }

    .fieldplx-profile-name,
    .fieldplx-profile-role {
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .fieldplx-profile-name {
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .fieldplx-profile-role {
        margin-top: 1px;
        color: var(--fieldplx-muted);
        font-size: 9px;
    }

    .fieldplx-dropdown {
        width: 340px;
        max-width: calc(100vw - 24px);
        padding: 0;
        margin-top: 10px !important;
        overflow: hidden;
        border: 1px solid var(--fieldplx-border);
        border-radius: 14px;
        background: #ffffff;
        box-shadow: 0 14px 34px rgba(31, 41, 55, 0.12);
    }

    .fieldplx-dropdown-header {
        min-height: 48px;
        padding: 11px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid var(--fieldplx-border);
        background: #ffffff;
    }

    .fieldplx-dropdown-title {
        margin: 0;
        color: #111827;
        font-size: 14px;
        line-height: 1.2;
        font-weight: 700;
    }

    .fieldplx-notification-item {
        padding: 11px 14px;
        display: flex;
        gap: 10px;
        border-bottom: 1px solid #f1f2f4;
        color: inherit;
        text-decoration: none;
    }

    .fieldplx-notification-item:hover {
        background: #faf8ff;
    }

    .fieldplx-notification-item.is-unread {
        background: #fbf9ff;
    }

    .fieldplx-notification-icon {
        width: 32px;
        height: 32px;
        flex: 0 0 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 14px;
    }

    .fieldplx-notification-content {
        min-width: 0;
    }

    .fieldplx-notification-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .fieldplx-notification-message {
        margin-top: 3px;
        overflow: hidden;
        display: -webkit-box;
        color: var(--fieldplx-muted);
        font-size: 10px;
        line-height: 1.45;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }

    .fieldplx-notification-time {
        margin-top: 4px;
        color: #9ca3af;
        font-size: 9px;
    }

    .fieldplx-empty-notifications {
        min-height: 155px;
        padding: 28px 18px 24px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: #718096;
        background: #ffffff;
        font-size: 13px;
        line-height: 1.45;
    }

    .fieldplx-empty-notifications i {
        display: block;
        margin-bottom: 10px;
        color: #b9a8ff;
        font-size: 30px;
        line-height: 1;
    }

    .fieldplx-dropdown-footer {
        min-height: 44px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-top: 1px solid var(--fieldplx-border);
        text-align: center;
        background: #ffffff;
    }

    .fieldplx-dropdown-footer a {
        color: var(--fieldplx-primary);
        font-size: 11px;
        font-weight: 700;
        text-decoration: none;
    }

    .fieldplx-dropdown-footer a:hover {
        text-decoration: underline;
    }

    .fieldplx-profile-menu {
        width: 230px;
        padding: 7px;
        border: 1px solid var(--fieldplx-border);
        border-radius: 12px;
        box-shadow: 0 18px 50px rgba(31, 41, 55, 0.13);
    }

    .fieldplx-profile-menu-header {
        padding: 9px 10px 11px;
        border-bottom: 1px solid #f0f1f3;
    }

    .fieldplx-profile-menu-name {
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        color: #111827;
        font-size: 12px;
        font-weight: 700;
    }

    .fieldplx-profile-menu-email {
        margin-top: 2px;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        color: var(--fieldplx-muted);
        font-size: 10px;
    }

    .fieldplx-profile-menu .dropdown-item {
        padding: 9px 10px;
        display: flex;
        align-items: center;
        gap: 9px;
        border-radius: 8px;
        color: #374151;
        font-size: 11px;
    }

    .fieldplx-profile-menu .dropdown-item:hover {
        color: var(--fieldplx-primary);
        background: #faf8ff;
    }

    .fieldplx-profile-menu .dropdown-item.text-danger:hover {
        color: #b91c1c !important;
        background: #fff5f5;
    }

    .fieldplx-main-layout {
        display: flex;
        min-height: calc(100vh - var(--fieldplx-topbar-height));
    }

    .fieldplx-main-content {
        min-width: 0;
        flex: 1;
    }

    .fieldplx-content-wrapper {
        padding: 18px;
    }

    @media (max-width: 991.98px) {
        .fieldplx-brand-mobile {
            display: flex;
        }

        .fieldplx-page-heading {
            display: none;
        }

        .fieldplx-search-wrap {
            margin-left: auto;
            width: min(280px, 40vw);
        }

        .fieldplx-profile-details {
            display: none;
        }

        .fieldplx-profile-button {
            padding-right: 5px;
        }
    }

    @media (max-width: 767.98px) {
        .fieldplx-topbar-inner {
            gap: 8px;
            padding: 8px 11px;
        }

        .fieldplx-brand-name {
            display: none;
        }

        .fieldplx-search-wrap {
            display: none;
        }

        .fieldplx-topbar-spacer {
            margin-left: auto;
        }

        .fieldplx-dropdown {
            width: min(330px, calc(100vw - 22px));
        }

        .fieldplx-content-wrapper {
            padding: 12px;
        }
    }

    :root {
        --fieldplx-sidebar-width: 246px;
        --fieldplx-sidebar-collapsed-width: 72px;
    }

    .fieldplx-sidebar {
        width: var(--fieldplx-sidebar-width);
        min-width: var(--fieldplx-sidebar-width);
        height: calc(100vh - var(--fieldplx-topbar-height));
        position: sticky;
        top: var(--fieldplx-topbar-height);
        z-index: 1020;
        display: flex;
        flex-direction: column;
        background: #ffffff;
        border-right: 1px solid var(--fieldplx-border);
        transition:
            width 0.22s ease,
            min-width 0.22s ease,
            transform 0.22s ease;
    }

    .fieldplx-sidebar-header {
        min-height: 64px;
        padding: 10px 13px;
        display: flex;
        align-items: center;
        border-bottom: 1px solid #f0f1f3;
    }

    .fieldplx-sidebar-brand {
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        color: #111827;
        text-decoration: none;
    }

    .fieldplx-sidebar-logo,
    .fieldplx-sidebar-logo-placeholder {
        width: 38px;
        height: 38px;
        flex: 0 0 38px;
        border-radius: 10px;
    }

    .fieldplx-sidebar-logo {
        object-fit: contain;
        background: #f7f4ff;
    }

    .fieldplx-sidebar-logo-placeholder {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #7c3aed, #5b21b6);
        color: #ffffff;
        font-size: 16px;
        font-weight: 700;
    }

    .fieldplx-sidebar-brand-text {
        min-width: 0;
        display: block;
    }

    .fieldplx-sidebar-company-name {
        max-width: 160px;
        display: block;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        font-size: 12px;
        font-weight: 700;
    }

    .fieldplx-sidebar-product-name {
        margin-top: 1px;
        display: block;
        color: #8b5cf6;
        font-size: 9px;
        font-weight: 600;
        letter-spacing: 0.4px;
        text-transform: uppercase;
    }

    .fieldplx-sidebar-close {
        width: 32px;
        height: 32px;
        margin-left: auto;
        padding: 0;
        display: none;
        align-items: center;
        justify-content: center;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: #6b7280;
        font-size: 16px;
    }

    .fieldplx-sidebar-body {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 12px 9px;
        scrollbar-width: thin;
        scrollbar-color: #d8d4e5 transparent;
    }

    .fieldplx-sidebar-section-label {
        margin: 4px 10px 7px;
        color: #9ca3af;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 0.65px;
        text-transform: uppercase;
    }

    .fieldplx-sidebar-nav {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .fieldplx-sidebar-link {
        width: 100%;
        min-height: 39px;
        padding: 8px 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        border: 0;
        border-radius: 9px;
        background: transparent;
        color: #4b5563;
        text-align: left;
        text-decoration: none;
        font-family: inherit;
        font-size: 11px;
        font-weight: 500;
        transition:
            color 0.16s ease,
            background 0.16s ease;
    }

    .fieldplx-sidebar-link:hover {
        background: #f8f6ff;
        color: #6d28d9;
    }

    .fieldplx-sidebar-link.active {
        background: #f0ebff;
        color: #6d28d9;
        font-weight: 700;
    }

    .fieldplx-sidebar-link-icon {
        width: 20px;
        height: 20px;
        flex: 0 0 20px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
    }

    .fieldplx-sidebar-link-text {
        min-width: 0;
        flex: 1;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .fieldplx-sidebar-arrow {
        margin-left: auto;
        color: #9ca3af;
        font-size: 10px;
        transition: transform 0.2s ease;
    }

    .fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-arrow {
        transform: rotate(180deg);
    }

    .fieldplx-sidebar-submenu {
        max-height: 0;
        overflow: hidden;
        padding-left: 39px;
        transition: max-height 0.25s ease;
    }

    .fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-submenu {
        max-height: 520px;
        padding-top: 3px;
        padding-bottom: 3px;
    }

    .fieldplx-sidebar-sublink {
        min-height: 31px;
        padding: 7px 9px;
        position: relative;
        display: flex;
        align-items: center;
        border-radius: 7px;
        color: #6b7280;
        text-decoration: none;
        font-size: 10px;
        font-weight: 500;
    }

    .fieldplx-sidebar-sublink::before {
        width: 5px;
        height: 5px;
        margin-right: 9px;
        flex: 0 0 5px;
        content: "";
        border-radius: 50%;
        background: #d1d5db;
    }

    .fieldplx-sidebar-sublink:hover {
        background: #faf8ff;
        color: #6d28d9;
    }

    .fieldplx-sidebar-sublink.active {
        background: #f7f3ff;
        color: #6d28d9;
        font-weight: 700;
    }

    .fieldplx-sidebar-sublink.active::before {
        background: #7c3aed;
    }

    .fieldplx-sidebar-empty {
        margin: 8px 10px 14px;
        padding: 14px 12px;
        display: grid;
        justify-items: center;
        gap: 7px;
        border: 1px dashed #ddd6fe;
        border-radius: 10px;
        background: #faf8ff;
        color: #7c3aed;
        font-size: 9px;
        line-height: 1.5;
        text-align: center;
    }

    .fieldplx-sidebar-empty i {
        font-size: 17px;
    }

    .fieldplx-sidebar-footer {
        padding: 10px;
        border-top: 1px solid #f0f1f3;
    }

    .fieldplx-sidebar-user {
        padding: 8px;
        display: flex;
        align-items: center;
        gap: 9px;
        border-radius: 10px;
        background: #fafafa;
    }

    .fieldplx-sidebar-user-avatar {
        width: 31px;
        height: 31px;
        flex: 0 0 31px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: linear-gradient(135deg, #7c3aed, #5b21b6);
        color: #ffffff;
        font-size: 11px;
        font-weight: 700;
    }

    .fieldplx-sidebar-user-details {
        min-width: 0;
        flex: 1;
    }

    .fieldplx-sidebar-user-name,
    .fieldplx-sidebar-user-role {
        display: block;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .fieldplx-sidebar-user-name {
        color: #111827;
        font-size: 10px;
        font-weight: 700;
    }

    .fieldplx-sidebar-user-role {
        margin-top: 1px;
        color: #9ca3af;
        font-size: 8px;
    }

    .fieldplx-sidebar-logout {
        width: 29px;
        height: 29px;
        flex: 0 0 29px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        color: #9ca3af;
        text-decoration: none;
        font-size: 14px;
    }

    .fieldplx-sidebar-logout:hover {
        background: #fee2e2;
        color: #dc2626;
    }

    .fieldplx-sidebar-overlay {
        display: none;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
        width: var(--fieldplx-sidebar-collapsed-width);
        min-width: var(--fieldplx-sidebar-collapsed-width);
    }

    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout {
        display: none;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header {
        justify-content: center;
        padding-left: 8px;
        padding-right: 8px;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link {
        justify-content: center;
        padding-left: 8px;
        padding-right: 8px;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user {
        justify-content: center;
        padding-left: 5px;
        padding-right: 5px;
    }

    @media (max-width: 991.98px) {
        .fieldplx-sidebar {
            width: 260px;
            min-width: 260px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1050;
            transform: translateX(-100%);
            box-shadow: none;
        }

        body.fieldplx-sidebar-mobile-open .fieldplx-sidebar {
            transform: translateX(0);
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
            width: 260px;
            min-width: 260px;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout {
            display: block;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu {
            display: block;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user {
            justify-content: initial;
        }

        .fieldplx-sidebar-close {
            display: inline-flex;
        }

        .fieldplx-sidebar-overlay {
            position: fixed;
            inset: 0;
            z-index: 1040;
            display: block;
            visibility: hidden;
            background: rgba(17, 24, 39, 0.42);
            opacity: 0;
            transition:
                opacity 0.2s ease,
                visibility 0.2s ease;
        }

        body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay {
            visibility: visible;
            opacity: 1;
        }
    }

    .fieldplx-fallback-sidebar {
        width: 236px;
        min-width: 236px;
        height: calc(100vh - var(--fieldplx-topbar-height));
        position: sticky;
        top: var(--fieldplx-topbar-height);
        z-index: 1020;
        display: flex;
        flex-direction: column;
        border-right: 1px solid var(--fieldplx-border);
        background: #ffffff;
    }

    .fieldplx-fallback-brand {
        min-height: 62px;
        padding: 11px 13px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #f1f5f9;
    }

    .fieldplx-fallback-logo {
        width: 37px;
        height: 37px;
        flex: 0 0 37px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: linear-gradient(135deg, #7c3aed, #5b21b6);
        color: #ffffff;
        font-size: 14px;
        font-weight: 700;
    }

    .fieldplx-fallback-brand-text {
        min-width: 0;
    }

    .fieldplx-fallback-brand-text strong,
    .fieldplx-fallback-brand-text small {
        display: block;
    }

    .fieldplx-fallback-brand-text strong {
        max-width: 155px;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        color: #111827;
        font-size: 11px;
    }

    .fieldplx-fallback-brand-text small {
        margin-top: 2px;
        color: #8b5cf6;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 0.4px;
        text-transform: uppercase;
    }

    .fieldplx-fallback-nav {
        flex: 1;
        overflow-y: auto;
        padding: 10px 8px;
    }

    .fieldplx-fallback-nav a,
    .fieldplx-fallback-footer a {
        min-height: 38px;
        padding: 8px 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-radius: 9px;
        color: #4b5563;
        font-size: 10px;
        font-weight: 600;
        text-decoration: none;
    }

    .fieldplx-fallback-nav a:hover,
    .fieldplx-fallback-nav a.active {
        background: #f0ebff;
        color: #6d28d9;
    }

    .fieldplx-fallback-nav i,
    .fieldplx-fallback-footer i {
        width: 19px;
        flex: 0 0 19px;
        font-size: 14px;
        text-align: center;
    }

    .fieldplx-fallback-footer {
        padding: 10px;
        border-top: 1px solid #f1f5f9;
    }

    .fieldplx-fallback-footer a:hover {
        background: #fef2f2;
        color: #dc2626;
    }

    @media (max-width: 991.98px) {
        .fieldplx-fallback-sidebar {
            display: none;
        }
    }

    :root {
        --fieldplx-primary: #74b824;
        --fieldplx-primary-dark: #5d971b;
        --fieldplx-text: #0b1933;
        --fieldplx-muted: #6f7b90;
        --fieldplx-border: #e5eaf1;
        --fieldplx-surface: #ffffff;
        --fieldplx-background: #f6f8fb;
        --fieldplx-topbar-height: 70px;
        --fieldplx-sidebar-width: 250px;
        --fieldplx-sidebar-collapsed-width: 78px;
        --fd-navy: #001131;
        --fd-navy-light: #071f49;
        --fd-blue: #123d70;
        --fd-green: #74b824;
        --fd-green-dark: #5d971b;
        --fd-green-soft: #f0f8e5;
        --fd-orange: #96c945;
        --fd-red: #e45b66;
        --fd-bg: #f6f8fb;
        --fd-text: #0b1933;
        --fd-muted: #6f7b90;
        --fd-border: #e5eaf1;
    }

    body {
        background: var(--fd-bg) !important;
        color: var(--fd-text);
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 14px;
    }

    .fieldplx-topbar {
        min-height: 70px !important;
        margin-left: var(--fieldplx-sidebar-width);
        width: calc(100% - var(--fieldplx-sidebar-width));
        background: #fff !important;
        border-bottom: 1px solid var(--fd-border) !important;
        box-shadow: 0 3px 14px rgba(0, 17, 49, 0.035);
        backdrop-filter: none !important;
        transition:
            margin-left 0.25s ease,
            width 0.25s ease;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-topbar {
        margin-left: var(--fieldplx-sidebar-collapsed-width);
        width: calc(100% - var(--fieldplx-sidebar-collapsed-width));
    }

    .fieldplx-topbar-inner {
        min-height: 70px !important;
        padding: 0 27px !important;
        gap: 13px !important;
    }

    .fieldplx-page-heading {
        display: none !important;
    }

    .fieldplx-menu-toggle,
    .fieldplx-topbar-action {
        width: 41px !important;
        height: 41px !important;
        border: 0 !important;
        border-radius: 9px !important;
        color: var(--fd-navy) !important;
        background: transparent !important;
    }

    .fieldplx-menu-toggle:hover,
    .fieldplx-topbar-action:hover {
        color: var(--fd-navy) !important;
        background: var(--fd-green-soft) !important;
    }

    .fieldplx-search-wrap {
        width: 280px !important;
        margin-left: auto;
    }

    .fieldplx-search-input {
        height: 41px !important;
        padding-left: 38px !important;
        border: 0 !important;
        border-radius: 8px !important;
        background: #f5f8fb !important;
        color: var(--fd-text) !important;
        font-size: 12px !important;
    }

    .fieldplx-search-input:focus {
        background: #f5f8fb !important;
        box-shadow: 0 0 0 3px rgba(116, 184, 36, 0.14) !important;
    }

    .fieldplx-profile-button {
        padding: 2px !important;
        border: 0 !important;
        border-radius: 9px !important;
        background: transparent !important;
    }

    .fieldplx-profile-button:hover {
        background: var(--fd-green-soft) !important;
    }

    .fieldplx-avatar {
        width: 38px !important;
        height: 38px !important;
        flex: 0 0 38px !important;
        border-radius: 50% !important;
        border: 0 !important;
        color: var(--fd-navy) !important;
        background: linear-gradient(135deg, #fff, #e8f3d9) !important;
        font-size: 12px !important;
        font-weight: 800 !important;
    }

    .fieldplx-profile-name {
        font-size: 12px !important;
    }

    .fieldplx-profile-role {
        color: var(--fd-muted) !important;
        font-size: 10px !important;
    }

    .fieldplx-notification-count {
        background: var(--fd-red) !important;
    }

    .fieldplx-dropdown,
    .fieldplx-profile-menu {
        border-color: var(--fd-border) !important;
        box-shadow: 0 18px 45px rgba(29, 38, 74, 0.14) !important;
    }

    .fieldplx-dropdown-footer a,
    .fieldplx-profile-menu .dropdown-item:hover {
        color: var(--fd-green-dark) !important;
    }

    .fieldplx-sidebar {
        width: var(--fieldplx-sidebar-width) !important;
        min-width: var(--fieldplx-sidebar-width) !important;
        height: 100vh !important;
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        z-index: 1045 !important;
        color: #fff !important;
        background: linear-gradient(180deg,
                var(--fd-navy-light),
                var(--fd-navy)) !important;

        border-right: 0 !important;
        transition:
            width 0.25s ease,
            min-width 0.25s ease,
            transform 0.25s ease !important;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
        width: var(--fieldplx-sidebar-collapsed-width) !important;
        min-width: var(--fieldplx-sidebar-collapsed-width) !important;
    }

    .fieldplx-sidebar-header {
        min-height: 68px !important;
        padding: 9px 14px 10px !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
    }

    .fieldplx-sidebar-brand {
        color: #fff !important;
    }

    .fieldplx-sidebar-logo,
    .fieldplx-sidebar-logo-placeholder {
        width: 40px !important;
        height: 40px !important;
        flex: 0 0 40px !important;
        border-radius: 10px !important;
    }

    .fieldplx-sidebar-logo-placeholder {
        color: #fff !important;
        background: linear-gradient(135deg, #8fd236, #68aa1d) !important;
        font-size: 18px !important;
    }

    .fieldplx-sidebar-company-name {
        max-width: 155px !important;
        color: #fff !important;
        font-size: 16px !important;
        font-weight: 700 !important;
    }

    .fieldplx-sidebar-product-name {
        color: #9fda55 !important;
        font-size: 9px !important;
    }

    .fieldplx-sidebar-body {
        padding: 12px 14px !important;
        scrollbar-width: none !important;
    }

    .fieldplx-sidebar-body::-webkit-scrollbar {
        display: none;
    }

    .fieldplx-sidebar-section-label {
        margin: 7px 12px 7px !important;
        color: rgba(255, 255, 255, 0.5) !important;
        font-size: 9px !important;
    }

    .fieldplx-sidebar-nav {
        gap: 3px !important;
    }

    .fieldplx-sidebar-link {
        min-height: 46px !important;
        margin-bottom: 3px !important;
        padding: 0 14px !important;
        gap: 15px !important;
        border-radius: 9px !important;
        color: rgba(255, 255, 255, 0.94) !important;
        font-size: 14px !important;
        font-weight: 600 !important;
    }

    .fieldplx-sidebar-link:hover {
        color: #fff !important;
        background: rgba(255, 255, 255, 0.08) !important;
    }

    .fieldplx-sidebar-link.active,
    .fieldplx-sidebar-menu.menu-open>.fieldplx-sidebar-link {
        color: #fff !important;
        background: linear-gradient(90deg, #7fc92d, #68aa1d) !important;
        box-shadow: 0 6px 18px rgba(0, 17, 49, 0.28) !important;
    }

    .fieldplx-sidebar-link-icon {
        width: 21px !important;
        height: 21px !important;
        flex: 0 0 21px !important;
        font-size: 19px !important;
    }

    .fieldplx-sidebar-arrow {
        color: rgba(255, 255, 255, 0.65) !important;
    }

    .fieldplx-sidebar-submenu {
        padding-left: 36px !important;
    }

    .fieldplx-sidebar-sublink {
        min-height: 34px !important;
        color: rgba(255, 255, 255, 0.72) !important;
        font-size: 11px !important;
    }

    .fieldplx-sidebar-sublink::before {
        background: rgba(255, 255, 255, 0.35) !important;
    }

    .fieldplx-sidebar-sublink:hover,
    .fieldplx-sidebar-sublink.active {
        color: #fff !important;
        background: rgba(255, 255, 255, 0.08) !important;
    }

    .fieldplx-sidebar-sublink.active::before {
        background: #9fda55 !important;
    }

    .fieldplx-sidebar-footer {
        padding: 10px 14px 14px !important;
        border-top: 1px solid rgba(255, 255, 255, 0.08) !important;
    }

    .fieldplx-sidebar-user {
        min-height: 62px;
        background: rgba(255, 255, 255, 0.08) !important;
    }

    .fieldplx-sidebar-user-name {
        color: #fff !important;
        font-size: 12px !important;
    }

    .fieldplx-sidebar-user-role {
        color: rgba(255, 255, 255, 0.6) !important;
        font-size: 9px !important;
    }

    .fieldplx-sidebar-user-avatar {
        width: 38px !important;
        height: 38px !important;
        flex: 0 0 38px !important;
        border-radius: 50% !important;
        color: var(--fd-navy) !important;
        background: linear-gradient(135deg, #fff, #e8f3d9) !important;
    }

    .fieldplx-sidebar-logout {
        color: rgba(255, 255, 255, 0.7) !important;
    }

    .fieldplx-sidebar-logout:hover {
        color: #fff !important;
        background: rgba(228, 91, 102, 0.3) !important;
    }

    .fieldplx-main-layout {
        display: block !important;
        min-height: calc(100vh - 70px) !important;
    }

    .fieldplx-main-content {
        margin-left: var(--fieldplx-sidebar-width);
        min-width: 0;
        transition: margin-left 0.25s ease;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-main-content {
        margin-left: var(--fieldplx-sidebar-collapsed-width);
    }

    .fieldplx-content-wrapper {
        padding: 0 !important;
    }

    .fieldplx-footer {
        display: block !important;
    }

    .fd-dashboard {
        width: 100%;
        max-width: 1600px;
        margin: auto;
        padding: 25px 27px 35px;
    }

    .fd-dashboard .row>* {
        min-width: 0;
    }

    .fd-welcome {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 23px;
    }

    .fd-welcome h1 {
        margin: 0 0 8px;
        color: var(--fd-text);
        font-size: 21px;
        font-weight: 700;
    }

    .fd-welcome p {
        margin: 0;
        color: var(--fd-muted);
        font-size: 12px;
    }

    .fd-date-actions {
        display: flex;
        gap: 9px;
    }

    .fd-date-button,
    .fd-filter-button {
        height: 46px;
        border: 1px solid var(--fd-border);
        border-radius: 9px;
        color: var(--fd-navy);
        background: #fff;
        box-shadow: 0 5px 15px rgba(31, 43, 88, 0.05);
        text-decoration: none;
    }

    .fd-date-button {
        min-width: 213px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 11px;
        padding: 0 14px;
        font-size: 11px;
        font-weight: 700;
    }

    .fd-filter-button {
        width: 46px;
        display: grid;
        place-items: center;
    }

    .fd-date-button:hover,
    .fd-filter-button:hover {
        border-color: #cfe3ae;
        color: var(--fd-green-dark);
        background: #f9fcf4;
    }

    .fd-card {
        height: 100%;
        border: 1px solid var(--fd-border);
        border-radius: 9px;
        background: #fff;
        box-shadow: 0 4px 14px rgba(31, 43, 88, 0.05);
    }

    /* Summary cards - clean reference style */
    .fd-stat-card {
        position: relative;
        min-height: 112px;
        padding: 18px 20px;
        overflow: hidden;
        border: 1px solid #dfe6ef;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 3px 12px rgba(24, 45, 76, 0.035);
    }

    .fd-stat-more {
        position: absolute;
        top: 14px;
        right: 15px;
        color: #8b9bb0;
        font-size: 18px;
        line-height: 1;
    }

    .fd-stat-row {
        display: flex;
        align-items: center;
        gap: 18px;
        min-height: 72px;
    }

    .fd-stat-row>div {
        min-width: 0;
    }

    .fd-stat-icon {
        width: 58px;
        height: 58px;
        flex: 0 0 58px;
        display: grid;
        place-items: center;
        border-radius: 16px;
        color: #ffffff;
        background: #123f73 !important;
        font-size: 26px;
    }

    .fd-stat-icon i {
        line-height: 1;
    }

    .fd-stat-icon.blue,
    .fd-stat-icon.green,
    .fd-stat-icon.lime,
    .fd-stat-icon.orange {
        background: #123f73 !important;
    }

    .fd-stat-label {
        display: block;
        margin-bottom: 8px;
        color: #506784;
        font-size: 13px;
        line-height: 1.2;
        font-weight: 400;
    }

    .fd-stat-value {
        display: block;
        color: #020b16;
        font-size: 31px;
        line-height: 1;
        font-weight: 700;
        letter-spacing: -0.5px;
    }

    .fd-stat-card .fd-growth,
    .fd-stat-card .fd-sparkline {
        display: none !important;
    }

    .fd-growth {
        display: block;
        margin-top: 14px;
        color: #8a95a8;
        font-size: 9px;
    }

    .fd-growth strong {
        font-size: 10px;
    }

    .fd-growth.up strong {
        color: var(--fd-green-dark);
    }

    .fd-growth.down strong {
        color: var(--fd-red);
    }

    .fd-growth.flat strong {
        color: #7d899d;
    }

    .fd-sparkline {
        position: absolute;
        right: 18px;
        bottom: 7px;
        left: 18px;
        height: 45px;
    }

    .fd-panel {
        padding: 18px;
    }

    .fd-panel-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 13px;
    }

    .fd-panel-title h2 {
        margin: 0;
        color: var(--fd-text);
        font-size: 14px;
        font-weight: 700;
    }

    .fd-chart-card {
        min-height: 313px;
    }

    .fd-chart-area {
        position: relative;
        height: 245px;
    }

    .fd-chart-area canvas {
        width: 100% !important;
        height: 100% !important;
    }

    .fd-chart-legend {
        color: var(--fd-muted);
        font-size: 10px;
        white-space: nowrap;
    }

    .fd-status-wrapper {
        min-height: 245px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 22px;
    }

    .fd-donut {
        position: relative;
        width: 165px;
        height: 165px;
        flex: 0 0 165px;
        display: grid;
        place-items: center;
        border-radius: 50%;
    }

    .fd-donut::before {
        position: absolute;
        width: 104px;
        height: 104px;
        border-radius: 50%;
        background: #fff;
        content: "";
    }

    .fd-donut-center {
        position: relative;
        z-index: 1;
        text-align: center;
    }

    .fd-donut-center strong {
        display: block;
        color: var(--fd-text);
        font-size: 21px;
    }

    .fd-donut-center small {
        color: var(--fd-muted);
        font-size: 10px;
    }

    .fd-status-legend {
        display: flex;
        flex-direction: column;
        gap: 11px;
    }

    .fd-legend-row {
        display: flex;
        gap: 8px;
        color: var(--fd-muted);
        font-size: 10px;
        line-height: 1.45;
    }

    .fd-legend-dot {
        width: 8px;
        height: 8px;
        flex: 0 0 8px;
        margin-top: 3px;
        border-radius: 50%;
    }

    .fd-legend-row strong {
        color: var(--fd-text);
    }

    .fd-tasks-count {
        padding: 4px 8px;
        border-radius: 999px;
        color: var(--fd-green-dark);
        background: var(--fd-green-soft);
        font-size: 9px;
        font-weight: 700;
    }

    .fd-task-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .fd-task-item {
        min-height: 41px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 10px;
        border: 1px solid var(--fd-border);
        border-radius: 8px;
        color: inherit;
        background: #fbfcfa;
        text-decoration: none;
        transition:
            border-color 0.2s ease,
            background 0.2s ease;
    }

    .fd-task-item:hover {
        border-color: #cfe3ae;
        color: inherit;
        background: #f7fbed;
    }

    .fd-task-check {
        width: 17px;
        height: 17px;
        flex: 0 0 17px;
        display: grid;
        place-items: center;
        border: 1px solid #cdd3df;
        border-radius: 4px;
        color: #fff;
        font-size: 10px;
    }

    .fd-task-item.complete {
        background: #f5faee;
    }

    .fd-task-item.complete .fd-task-check {
        border-color: var(--fd-green);
        background: var(--fd-green);
    }

    .fd-task-content {
        min-width: 0;
        flex: 1;
    }

    .fd-task-content strong,
    .fd-task-content small {
        display: block;
    }

    .fd-task-content strong {
        overflow: hidden;
        color: var(--fd-navy);
        font-size: 10px;
        font-weight: 700;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .fd-task-content small {
        margin-top: 2px;
        color: var(--fd-muted);
        font-size: 9px;
    }

    .fd-task-item.complete .fd-task-content strong {
        color: #8792a4;
        text-decoration: line-through;
    }

    .fd-task-time {
        flex: 0 0 auto;
        padding: 4px 7px;
        border-radius: 999px;
        color: #5c6b81;
        background: #eef2f6;
        font-size: 8.5px;
        font-weight: 700;
        white-space: nowrap;
    }

    .fd-task-footer {
        display: flex;
        justify-content: flex-end;
        padding-top: 7px;
    }

    .fd-link {
        color: var(--fd-green-dark);
        font-size: 10px;
        font-weight: 600;
        text-decoration: none;
    }

    .fd-link:hover {
        color: var(--fd-green);
    }

    .fd-recent-jobs-card {
        min-height: 360px;
        overflow: hidden;
    }

    .fd-view-button {
        padding: 6px 11px;
        border: 1px solid var(--fd-border);
        border-radius: 5px;
        color: #53627a;
        background: #fff;
        font-size: 10px;
        text-decoration: none;
    }

    .fd-view-button:hover {
        border-color: #cfe3ae;
        color: var(--fd-green-dark);
        background: #f9fcf4;
    }

    .fd-jobs-table {
        min-width: 820px;
        margin: 4px 0 0;
        white-space: nowrap;
    }

    .fd-jobs-table th {
        padding: 11px 6px;
        border-bottom-color: var(--fd-border);
        color: #65738a;
        font-size: 9px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .fd-jobs-table td {
        padding: 12px 6px;
        border-bottom-color: #f1f3f7;
        color: #33445f;
        font-size: 9.5px;
        vertical-align: middle;
    }

    .fd-job-name {
        color: var(--fd-text);
        font-weight: 700;
    }

    .fd-status {
        display: inline-flex;
        padding: 5px 7px;
        border-radius: 5px;
        font-size: 9px;
        font-weight: 600;
    }

    .fd-status.progress {
        color: #123d70;
        background: #edf2f7;
    }

    .fd-status.completed {
        color: #5d971b;
        background: #f0f8e5;
    }

    .fd-status.pending {
        color: #678a23;
        background: #f5f9ea;
    }

    .fd-status.cancelled {
        color: #b9444d;
        background: #fff0f1;
    }

    .fd-action-link {
        width: 28px;
        height: 28px;
        display: grid;
        place-items: center;
        border-radius: 6px;
        color: #66748b;
        text-decoration: none;
    }

    .fd-action-link:hover {
        color: var(--fd-green-dark);
        background: var(--fd-green-soft);
    }

    .fd-schedule-event {
        min-height: 45px;
        display: grid;
        grid-template-columns: 10px 58px 1fr;
        align-items: start;
        color: inherit;
        text-decoration: none;
    }

    .fd-schedule-event:hover .fd-schedule-info strong {
        color: var(--fd-green-dark);
    }

    .fd-schedule-dot {
        width: 8px;
        height: 8px;
        margin-top: 3px;
        border-radius: 50%;
        background: var(--fd-green);
    }

    .fd-schedule-time {
        padding-top: 1px;
        color: var(--fd-muted);
        font-size: 9px;
    }

    .fd-schedule-info strong,
    .fd-schedule-info small {
        display: block;
    }

    .fd-schedule-info strong {
        color: var(--fd-text);
        font-size: 10px;
    }

    .fd-schedule-info small {
        margin-top: 2px;
        color: var(--fd-muted);
        font-size: 9px;
    }

    .fd-activity-item {
        display: flex;
        gap: 10px;
        padding: 8px 0;
    }

    .fd-activity-icon {
        width: 30px;
        height: 30px;
        flex: 0 0 30px;
        display: grid;
        place-items: center;
        border-radius: 9px;
    }

    .fd-activity-icon.green,
    .fd-activity-icon.lime {
        color: var(--fd-green-dark);
        background: #f0f8e5;
    }

    .fd-activity-icon.orange {
        color: #789d2c;
        background: #f4f9ea;
    }

    .fd-activity-icon.blue {
        color: #123d70;
        background: #edf2f7;
    }

    .fd-activity-content strong,
    .fd-activity-content small {
        display: block;
    }

    .fd-activity-content strong {
        color: var(--fd-text);
        font-size: 10px;
    }

    .fd-activity-content small {
        margin-top: 2px;
        color: var(--fd-muted);
        font-size: 9px;
        line-height: 1.4;
    }

    .fd-bottom-card {
        position: relative;
        min-height: 132px;
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 22px;
        overflow: hidden;
    }

    .fd-bottom-icon {
        width: 52px;
        height: 52px;
        flex: 0 0 52px;
        display: grid;
        place-items: center;
        border-radius: 14px;
        font-size: 22px;
    }

    .fd-bottom-content small,
    .fd-bottom-content strong,
    .fd-bottom-content span {
        display: block;
    }

    .fd-bottom-content small {
        color: var(--fd-muted);
        font-size: 10px;
        font-weight: 600;
    }

    .fd-bottom-content strong {
        margin-top: 5px;
        color: var(--fd-text);
        font-size: 23px;
        line-height: 1.1;
    }

    .fd-bottom-content span {
        margin-top: 7px;
        color: #33445f;
        font-size: 9px;
        font-weight: 700;
    }

    .fd-bottom-content .growth {
        color: var(--fd-green-dark);
    }

    .fd-empty {
        min-height: 120px;
        display: grid;
        place-items: center;
        padding: 20px;
        color: #9aa4b3;
        font-size: 10px;
        text-align: center;
    }

    @media (max-width: 1199.98px) {
        .fd-status-wrapper {
            gap: 14px;
        }
    }

    @media (max-width: 991.98px) {

        .fieldplx-topbar,
        body.fieldplx-sidebar-collapsed .fieldplx-topbar {
            margin-left: 0 !important;
            width: 100% !important;
        }

        .fieldplx-sidebar,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
            width: 250px !important;
            min-width: 250px !important;
            transform: translateX(-100%);
            box-shadow: none !important;
            filter: none !important;
        }

        body.fieldplx-sidebar-mobile-open .fieldplx-sidebar {
            transform: translateX(0) !important;
        }

        .fieldplx-main-content,
        body.fieldplx-sidebar-collapsed .fieldplx-main-content {
            margin-left: 0 !important;
        }

        .fieldplx-sidebar-brand-text,
        .fieldplx-sidebar-section-label,
        .fieldplx-sidebar-link-text,
        .fieldplx-sidebar-arrow,
        .fieldplx-sidebar-user-details,
        .fieldplx-sidebar-logout,
        .fieldplx-sidebar-submenu {
            display: initial;
        }
    }

    @media (max-width: 767.98px) {
        :root {
            --fieldplx-topbar-height: 64px;
        }

        .fieldplx-topbar,
        .fieldplx-topbar-inner {
            min-height: 64px !important;
        }

        .fieldplx-topbar-inner {
            padding: 0 13px !important;
        }

        .fieldplx-search-wrap {
            display: none !important;
        }

        .fd-dashboard {
            padding: 17px 13px 28px;
        }

        .fd-welcome {
            align-items: flex-start;
        }

        .fd-welcome h1 {
            font-size: 19px;
        }

        .fd-welcome p {
            max-width: 260px;
            font-size: 11px;
            line-height: 1.5;
        }

        .fd-date-button {
            min-width: 46px;
            width: 46px;
            padding: 0;
            justify-content: center;
        }

        .fd-date-button span,
        .fd-date-button .bi-chevron-down {
            display: none;
        }

        .fd-stat-card {
            min-height: 108px;
            padding: 17px 18px;
        }

        .fd-donut {
            width: 145px;
            height: 145px;
            flex-basis: 145px;
        }

        .fd-donut::before {
            width: 91px;
            height: 91px;
        }
    }

    @media (max-width: 420px) {
        .fd-welcome {
            min-height: 65px;
            gap: 10px;
        }

        .fd-date-actions {
            gap: 5px;
        }

        .fd-filter-button {
            width: 42px;
        }

        .fd-status-wrapper {
            transform: scale(0.92);
            margin-inline: -14px;
        }
    }

    @media (max-width: 575.98px) {
        .fd-stat-card {
            min-height: 102px;
            padding: 15px 17px;
        }

        .fd-stat-row {
            gap: 15px;
            min-height: 66px;
        }

        .fd-stat-icon {
            width: 54px;
            height: 54px;
            flex-basis: 54px;
            border-radius: 15px;
            font-size: 24px;
        }

        .fd-stat-label {
            margin-bottom: 7px;
        }

        .fd-stat-value {
            font-size: 28px;
        }

        .fd-stat-row {
            gap: 18px;
            min-height: 72px;
        }

        .fd-stat-value {
            font-size: 29px;
        }
    }

    .fieldplx-footer {
        min-height: 52px;
        margin-left: var(--fieldplx-sidebar-width);
        border-top: 1px solid var(--fieldplx-border);
        background: #ffffff;
        transition:
            margin-left 0.22s ease,
            background-color 0.22s ease;
    }

    .fieldplx-footer-inner {
        min-height: 52px;
        padding: 10px 18px;
        display: flex;
        align-items: center;
        gap: 18px;
        color: #6b7280;
        font-size: 10px;
    }

    .fieldplx-footer-copyright {
        min-width: 0;
    }

    .fieldplx-footer-links {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .fieldplx-footer-links a {
        color: #6b7280;
        text-decoration: none;
        transition: color 0.18s ease;
    }

    .fieldplx-footer-links a:hover {
        color: var(--fieldplx-primary);
    }

    .fieldplx-footer-separator {
        color: #d1d5db;
        font-size: 8px;
    }

    .fieldplx-footer-product {
        margin-left: auto;
        white-space: nowrap;
        color: #9ca3af;
    }

    .fieldplx-footer-product strong {
        color: var(--fieldplx-primary);
        font-weight: 700;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-footer {
        margin-left: var(--fieldplx-sidebar-collapsed-width);
    }

    @media (max-width: 991.98px) {
        .fieldplx-footer {
            margin-left: 0;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-footer {
            margin-left: 0;
        }
    }

    @media (max-width: 767.98px) {
        .fieldplx-footer-inner {
            padding: 12px;
            flex-wrap: wrap;
            justify-content: center;
            gap: 7px 14px;
            text-align: center;
        }

        .fieldplx-footer-product {
            width: 100%;
            margin-left: 0;
        }
    }

    /* Notification dropdown correction */
    .dropdown:has(.fieldplx-topbar-action) .fieldplx-dropdown {
        right: 0 !important;
        left: auto !important;
        width: 340px !important;
        max-width: calc(100vw - 24px) !important;
        margin-top: 10px !important;
        border: 1px solid var(--fd-border) !important;
        border-radius: 14px !important;
        background: #ffffff !important;
        box-shadow: 0 14px 34px rgba(29, 38, 74, 0.12) !important;
    }

    #topbarNotificationList {
        max-height: 300px;
        overflow-y: auto;
        background: #ffffff;
    }

    .fieldplx-empty-notifications {
        min-height: 155px !important;
        padding: 28px 18px 24px !important;
    }

    .fieldplx-dropdown-footer {
        border-top: 1px solid var(--fd-border) !important;
    }

    @media (max-width: 575.98px) {
        .dropdown:has(.fieldplx-topbar-action) .fieldplx-dropdown {
            width: min(320px, calc(100vw - 20px)) !important;
        }

        .fieldplx-empty-notifications {
            min-height: 135px !important;
            padding: 22px 15px !important;
        }
    }

    /* ==========================================================
   FieldPlx mobile sidebar final correction
   Desktop sidebar appearance is intentionally unchanged.
   ========================================================== */
    @media (max-width: 991.98px) {

        html,
        body {
            overflow-x: hidden !important;
        }

        body.fieldplx-sidebar-mobile-open {
            overflow: hidden !important;
        }

        .fieldplx-topbar,
        body.fieldplx-sidebar-collapsed .fieldplx-topbar {
            margin-left: 0 !important;
            width: 100% !important;
        }

        .fieldplx-main-content,
        body.fieldplx-sidebar-collapsed .fieldplx-main-content {
            margin-left: 0 !important;
            width: 100% !important;
        }

        .fieldplx-sidebar,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
            width: min(300px, calc(100vw - 52px)) !important;
            min-width: 0 !important;
            max-width: 300px !important;
            height: 100vh !important;
            height: 100dvh !important;
            position: fixed !important;
            top: 0 !important;
            bottom: 0 !important;
            left: 0 !important;
            z-index: 1060 !important;

            display: flex !important;
            flex-direction: column !important;

            overflow: hidden !important;
            visibility: hidden !important;
            transform: translate3d(-100%, 0, 0) !important;

            border-right: 0 !important;
            box-shadow: none !important;
            filter: none !important;

            transition:
                transform 0.25s ease,
                visibility 0.25s ease !important;

            will-change: transform;
        }

        body.fieldplx-sidebar-mobile-open .fieldplx-sidebar,
        body.fieldplx-sidebar-mobile-open.fieldplx-sidebar-collapsed .fieldplx-sidebar {
            visibility: visible !important;
            transform: translate3d(0, 0, 0) !important;
        }

        .fieldplx-sidebar-header,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header {
            flex: 0 0 auto !important;
            justify-content: flex-start !important;
            padding-left: 14px !important;
            padding-right: 10px !important;
        }

        .fieldplx-sidebar-close {
            width: 34px !important;
            height: 34px !important;
            margin-left: auto !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;

            color: rgba(255, 255, 255, 0.88) !important;
            background: rgba(255, 255, 255, 0.08) !important;
        }

        .fieldplx-sidebar-close:hover {
            color: #ffffff !important;
            background: rgba(255, 255, 255, 0.14) !important;
        }

        .fieldplx-sidebar-body {
            min-height: 0 !important;
            flex: 1 1 auto !important;
            overflow-x: hidden !important;
            overflow-y: auto !important;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
        }

        .fieldplx-sidebar-footer {
            flex: 0 0 auto !important;
        }

        /* Never allow the desktop collapsed state to hide mobile labels. */
        .fieldplx-sidebar-brand-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text {
            display: block !important;
        }

        .fieldplx-sidebar-section-label,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label {
            display: block !important;
        }

        .fieldplx-sidebar-link-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text {
            display: block !important;
        }

        .fieldplx-sidebar-arrow,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow {
            display: inline-flex !important;
        }

        .fieldplx-sidebar-user-details,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details {
            display: block !important;
        }

        .fieldplx-sidebar-logout,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout {
            display: inline-flex !important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user {
            justify-content: flex-start !important;
        }

        /* Restore proper accordion behavior on mobile.
       Do not use display:initial here: it turns the submenu into inline
       content and breaks max-height animation/spacing. */
        .fieldplx-sidebar-submenu,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu {
            display: block !important;
            max-height: 0 !important;
            overflow: hidden !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;

            transition:
                max-height 0.25s ease,
                padding-top 0.25s ease,
                padding-bottom 0.25s ease !important;
        }

        .fieldplx-sidebar-menu.menu-open>.fieldplx-sidebar-submenu,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-menu.menu-open>.fieldplx-sidebar-submenu {
            display: block !important;
            max-height: 680px !important;
            padding-top: 4px !important;
            padding-bottom: 5px !important;
        }

        .fieldplx-sidebar-overlay {
            position: fixed !important;
            inset: 0 !important;
            z-index: 1055 !important;

            display: block !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;

            background: rgba(0, 17, 49, 0.48) !important;
            transition:
                opacity 0.25s ease,
                visibility 0.25s ease !important;
        }

        body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay {
            visibility: visible !important;
            opacity: 1 !important;
            pointer-events: auto !important;
        }
    }

    @media (max-width: 575.98px) {

        .fieldplx-sidebar,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
            width: min(288px, calc(100vw - 44px)) !important;
        }

        .fieldplx-sidebar-body {
            padding-left: 10px !important;
            padding-right: 10px !important;
        }

        .fieldplx-sidebar-link {
            min-height: 43px !important;
            padding-left: 12px !important;
            padding-right: 12px !important;
            gap: 12px !important;
            font-size: 13px !important;
        }

        .fieldplx-sidebar-submenu {
            padding-left: 31px !important;
        }

        .fieldplx-sidebar-sublink {
            min-height: 33px !important;
            font-size: 11px !important;
        }
    }


    /* Employees page */
    .fd-employees-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px
    }

    .fd-employees-title {
        margin: 0 0 7px;
        color: var(--fd-text);
        font-size: 21px;
        font-weight: 700
    }

    .fd-employees-subtitle {
        margin: 0;
        color: var(--fd-muted);
        font-size: 11px
    }

    .fd-employees-actions {
        display: flex;
        gap: 8px
    }

    .fd-employee-btn {
        min-height: 39px;
        padding: 0 13px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border: 1px solid var(--fd-border);
        border-radius: 8px;
        background: #fff;
        color: #43546c;
        font-size: 10px;
        font-weight: 700;
        cursor: pointer
    }

    .fd-employee-btn.primary {
        border-color: var(--fd-green);
        background: linear-gradient(90deg, #7fc92d, #68aa1d);
        color: #fff
    }

    .fd-employee-btn:hover {
        border-color: #cfe3ae;
        background: #f9fcf4;
        color: var(--fd-green-dark)
    }

    .fd-employee-btn.primary:hover {
        background: linear-gradient(90deg, #74b824, #5d971b);
        color: #fff
    }

    .fd-employee-btn.danger {
        border-color: #ffd5d9;
        color: #b9444d
    }

    .fd-employee-loader {
        width: 13px;
        height: 13px;
        display: none;
        border: 2px dotted currentColor;
        border-radius: 50%;
        animation: eSpin .75s linear infinite
    }

    .fd-employee-btn.loading .fd-employee-loader {
        display: inline-block
    }

    @keyframes eSpin {
        to {
            transform: rotate(360deg)
        }
    }

    .fd-employee-stat {
        min-height: 112px;
        padding: 18px 20px;
        border: 1px solid #dfe6ef;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(24, 45, 76, .035)
    }

    .fd-employee-stat-row {
        min-height: 72px;
        display: flex;
        align-items: center;
        gap: 18px
    }

    .fd-employee-stat-icon {
        width: 58px;
        height: 58px;
        flex: 0 0 58px;
        display: grid;
        place-items: center;
        border-radius: 16px;
        background: #123f73;
        color: #fff;
        font-size: 25px
    }

    .fd-employee-stat-label {
        display: block;
        margin-bottom: 8px;
        color: #506784;
        font-size: 13px
    }

    .fd-employee-stat-value {
        display: block;
        color: #020b16;
        font-size: 31px;
        line-height: 1;
        font-weight: 700
    }

    .fd-employees-card {
        overflow: hidden
    }

    .fd-employees-toolbar {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        border-bottom: 1px solid var(--fd-border);
        background: #fbfcfd
    }

    .fd-employee-search {
        width: 270px;
        position: relative
    }

    .fd-employee-search i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #8a96a7
    }

    .fd-employee-search input,
    .fd-employee-filter {
        height: 39px;
        border: 1px solid #dde4ec;
        border-radius: 8px;
        background: #fff;
        color: #33445f;
        font-size: 10px;
        outline: 0
    }

    .fd-employee-search input {
        width: 100%;
        padding: 8px 11px 8px 34px
    }

    .fd-employee-filter {
        min-width: 140px;
        padding: 8px 10px
    }

    .fd-employee-toolbar-spacer {
        margin-left: auto
    }

    .fd-employee-table-wrap {
        overflow-x: auto
    }

    .fd-employee-table {
        width: 100%;
        min-width: 1180px;
        border-collapse: collapse;
        white-space: nowrap
    }

    .fd-employee-table th {
        padding: 11px 12px;
        border-bottom: 1px solid var(--fd-border);
        background: #f8fafc;
        color: #65738a;
        font-size: 9px;
        font-weight: 600;
        text-transform: uppercase
    }

    .fd-employee-table td {
        padding: 12px;
        border-bottom: 1px solid #f1f3f7;
        color: #33445f;
        font-size: 9.5px
    }

    .fd-employee-person {
        display: flex;
        align-items: center;
        gap: 10px
    }

    .fd-employee-avatar {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: grid;
        place-items: center;
        border-radius: 50%;
        background: linear-gradient(135deg, #fff, #e8f3d9);
        border: 1px solid #dce8cf;
        color: var(--fd-navy);
        font-size: 10px;
        font-weight: 700;
        overflow: hidden
    }

    .fd-employee-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover
    }

    .fd-employee-person strong,
    .fd-employee-person small {
        display: block
    }

    .fd-employee-person small {
        margin-top: 2px;
        color: #8d98a8;
        font-size: 8.5px
    }

    .fd-employee-badge {
        display: inline-flex;
        padding: 5px 7px;
        border-radius: 5px;
        font-size: 8.5px;
        font-weight: 600
    }

    .fd-employee-badge.active,
    .fd-employee-badge.field {
        color: #5d971b;
        background: #f0f8e5
    }

    .fd-employee-badge.inactive {
        color: #6f7b90;
        background: #eef2f6
    }

    .fd-employee-badge.invited,
    .fd-employee-badge.admin {
        color: #123d70;
        background: #edf2f7
    }

    .fd-employee-badge.suspended {
        color: #b9444d;
        background: #fff0f1
    }

    .fd-employee-actions-cell {
        display: flex;
        gap: 5px
    }

    .fd-employee-icon {
        width: 29px;
        height: 29px;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 6px;
        background: transparent;
        color: #66748b;
        cursor: pointer
    }

    .fd-employee-icon:hover {
        background: var(--fd-green-soft);
        color: var(--fd-green-dark)
    }

    .fd-employee-icon.danger:hover {
        background: #fff0f1;
        color: #b9444d
    }

    .fd-employee-empty {
        padding: 28px 18px !important;
        text-align: center;
        color: #9aa4b3 !important;
        font-size: 10px !important
    }

    .fd-employee-pagination {
        padding: 10px 14px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top: 1px solid var(--fd-border);
        font-size: 9px;
        color: #768397
    }

    .fd-employee-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 15000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
        background: rgba(0, 17, 49, .46);
        backdrop-filter: blur(3px)
    }

    .fd-employee-modal-backdrop.show {
        display: flex
    }

    .fd-employee-modal {
        width: min(860px, 100%);
        max-height: calc(100vh - 36px);
        overflow: auto;
        border: 1px solid #dfe5ec;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 24px 65px rgba(0, 17, 49, .24)
    }

    .fd-employee-modal-header {
        padding: 11px 14px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid var(--fd-border);
        background: #fbfcfd
    }

    .fd-employee-modal-icon {
        width: 34px;
        height: 34px;
        display: grid;
        place-items: center;
        border-radius: 9px;
        background: var(--fd-green-soft);
        color: var(--fd-green-dark)
    }

    .fd-employee-modal-heading {
        flex: 1
    }

    .fd-employee-modal-heading h3 {
        margin: 0;
        font-size: 12px
    }

    .fd-employee-modal-heading p {
        margin: 3px 0 0;
        color: var(--fd-muted);
        font-size: 8.5px
    }

    .fd-employee-modal-close {
        width: 30px;
        height: 30px;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: #8490a0
    }

    .fd-employee-modal-body {
        padding: 15px
    }

    .fd-employee-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 13px
    }

    .fd-employee-field.full {
        grid-column: 1/-1
    }

    .fd-employee-field label {
        display: block;
        margin-bottom: 6px;
        color: #42536c;
        font-size: 9px;
        font-weight: 700
    }

    .fd-employee-field input,
    .fd-employee-field select {
        width: 100%;
        min-height: 40px;
        padding: 8px 10px;
        border: 1px solid #dfe5ec;
        border-radius: 8px;
        background: #fff;
        color: #263750;
        font-size: 10px;
        outline: 0
    }

    .fd-employee-section {
        grid-column: 1/-1;
        padding: 7px 0 2px;
        border-bottom: 1px solid #eef2f5;
        color: #31425b;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase
    }

    .fd-employee-switches {
        grid-column: 1/-1;
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 9px
    }

    .fd-employee-switch-row {
        padding: 10px;
        border: 1px solid var(--fd-border);
        border-radius: 9px;
        background: #fbfcfd;
        display: flex;
        align-items: center;
        justify-content: space-between
    }

    .fd-employee-switch-row strong,
    .fd-employee-switch-row small {
        display: block
    }

    .fd-employee-switch-row strong {
        font-size: 9.5px
    }

    .fd-employee-switch-row small {
        margin-top: 2px;
        color: #8a96a7;
        font-size: 8px
    }

    .fd-employee-switch input {
        width: 15px;
        height: 15px;
        accent-color: var(--fd-green)
    }

    .fd-employee-modal-footer {
        padding: 12px 15px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        border-top: 1px solid var(--fd-border);
        background: #fbfcfd
    }

    .fd-employee-confirm {
        width: min(440px, 100%)
    }

    .fd-employee-toast {
        width: min(290px, calc(100vw - 24px));
        position: fixed;
        top: 82px;
        right: 16px;
        z-index: 25000;
        padding: 8px 9px;
        display: flex;
        align-items: center;
        gap: 7px;
        border-radius: 7px;
        color: #fff;
        opacity: 0;
        transform: translateY(-8px);
        pointer-events: none;
        transition: .18s ease;
        box-shadow: 0 10px 26px rgba(0, 17, 49, .18)
    }

    .fd-employee-toast.show {
        opacity: 1;
        transform: translateY(0)
    }

    .fd-employee-toast.success {
        background: #5d971b
    }

    .fd-employee-toast.error {
        background: #e45b66
    }

    .fd-employee-toast.warning {
        background: #96a52f
    }

    .fd-employee-toast.info {
        background: #123d70
    }

    .fd-employee-toast span {
        font-size: 8.5px
    }

    .fd-employee-toast button {
        margin-left: auto;
        border: 0;
        background: transparent;
        color: #fff
    }

    @media(max-width:767.98px) {
        .fd-employees-header {
            flex-direction: column
        }

        .fd-employee-grid {
            grid-template-columns: 1fr
        }

        .fd-employee-field.full,
        .fd-employee-section,
        .fd-employee-switches {
            grid-column: auto
        }

        .fd-employee-switches {
            grid-template-columns: 1fr
        }

        .fd-employee-search {
            width: 100%
        }

        .fd-employee-toolbar-spacer {
            display: none
        }
    }

    @media(max-width:575.98px) {
        .fd-employee-toast {
            top: 72px;
            left: 12px;
            right: 12px;
            width: auto
        }

        .fd-employee-modal-footer {
            flex-direction: column-reverse
        }

        .fd-employee-modal-footer .fd-employee-btn {
            width: 100%
        }
    }

    /* ==========================================================
   Teams page - canonical tenant template
   ========================================================== */
    .fd-teams-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
    }

    .fd-teams-title {
        margin: 0 0 7px;
        color: var(--fd-text);
        font-size: 21px;
        line-height: 1.2;
        font-weight: 700;
    }

    .fd-teams-subtitle {
        margin: 0;
        max-width: 780px;
        color: var(--fd-muted);
        font-size: 11px;
        line-height: 1.55;
    }

    .fd-teams-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .fd-team-button {
        min-height: 39px;
        padding: 0 13px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border: 1px solid var(--fd-border);
        border-radius: 8px;
        color: #43546c;
        background: #fff;
        box-shadow: 0 4px 12px rgba(31, 43, 88, .04);
        font-size: 10px;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
    }

    .fd-team-button:hover {
        border-color: #cfe3ae;
        color: var(--fd-green-dark);
        background: #f9fcf4;
    }

    .fd-team-button.primary {
        border-color: var(--fd-green);
        color: #fff;
        background: linear-gradient(90deg, #7fc92d, #68aa1d);
        box-shadow: 0 7px 16px rgba(104, 170, 29, .18);
    }

    .fd-team-button.primary:hover {
        color: #fff;
        background: linear-gradient(90deg, #74b824, #5d971b);
    }

    .fd-team-button.danger {
        border-color: #ffd5d9;
        color: #b9444d;
        background: #fff;
    }

    .fd-team-button:disabled {
        opacity: .58;
        cursor: not-allowed;
    }

    .fd-team-loader {
        width: 13px;
        height: 13px;
        display: none;
        border: 2px dotted currentColor;
        border-radius: 50%;
        animation: fdTeamSpin .75s linear infinite;
    }

    .fd-team-button.loading .fd-team-loader {
        display: inline-block
    }

    @keyframes fdTeamSpin {
        to {
            transform: rotate(360deg)
        }
    }

    .fd-teams-summary {
        margin-bottom: 16px
    }

    .fd-team-stat-card {
        min-height: 112px;
        padding: 18px 20px;
        border: 1px solid #dfe6ef;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(24, 45, 76, .035);
    }

    .fd-team-stat-row {
        min-height: 72px;
        display: flex;
        align-items: center;
        gap: 18px;
    }

    .fd-team-stat-icon {
        width: 58px;
        height: 58px;
        flex: 0 0 58px;
        display: grid;
        place-items: center;
        border-radius: 16px;
        color: #fff;
        background: #123f73;
        font-size: 25px;
    }

    .fd-team-stat-label {
        display: block;
        margin-bottom: 8px;
        color: #506784;
        font-size: 13px;
    }

    .fd-team-stat-value {
        display: block;
        color: #020b16;
        font-size: 31px;
        line-height: 1;
        font-weight: 700;
    }

    .fd-teams-card {
        overflow: hidden
    }

    .fd-teams-toolbar {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        border-bottom: 1px solid var(--fd-border);
        background: #fbfcfd;
    }

    .fd-team-search {
        width: 270px;
        position: relative
    }

    .fd-team-search i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #8a96a7;
        font-size: 13px;
    }

    .fd-team-search input,
    .fd-team-filter {
        height: 39px;
        border: 1px solid #dde4ec;
        border-radius: 8px;
        outline: 0;
        color: #33445f;
        background: #fff;
        font-size: 10px;
    }

    .fd-team-search input {
        width: 100%;
        padding: 8px 11px 8px 34px;
    }

    .fd-team-filter {
        min-width: 140px;
        padding: 8px 10px;
    }

    .fd-team-search input:focus,
    .fd-team-filter:focus {
        border-color: #a9cf75;
        box-shadow: 0 0 0 3px rgba(116, 184, 36, .11);
    }

    .fd-team-toolbar-spacer {
        margin-left: auto
    }

    .fd-team-table-wrap {
        width: 100%;
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: thin;
        scrollbar-color: #9aa0a6 transparent;
    }

    .fd-team-table-wrap::-webkit-scrollbar {
        height: 3px !important
    }

    .fd-team-table-wrap::-webkit-scrollbar-track {
        background: transparent !important
    }

    .fd-team-table-wrap::-webkit-scrollbar-thumb {
        min-width: 20px;
        border-radius: 999px !important;
        background: #9aa0a6 !important;
    }

    .fd-team-table-wrap::-webkit-scrollbar-button {
        width: 0 !important;
        height: 0 !important;
        display: none !important;
    }

    .fd-team-table {
        width: 100%;
        min-width: 1080px;
        margin: 0;
        border-collapse: collapse;
        white-space: nowrap;
    }

    .fd-team-table th {
        padding: 11px 12px;
        border-bottom: 1px solid var(--fd-border);
        color: #65738a;
        background: #f8fafc;
        font-size: 9px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .fd-team-table td {
        padding: 12px;
        border-bottom: 1px solid #f1f3f7;
        color: #33445f;
        font-size: 9.5px;
        vertical-align: middle;
    }

    .fd-team-table tbody tr:hover {
        background: #fbfcfa
    }

    .fd-team-name {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .fd-team-name-icon {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: grid;
        place-items: center;
        border-radius: 10px;
        color: var(--fd-green-dark);
        background: var(--fd-green-soft);
        font-size: 15px;
    }

    .fd-team-name strong,
    .fd-team-name small {
        display: block
    }

    .fd-team-name strong {
        color: var(--fd-text);
        font-size: 10.5px;
    }

    .fd-team-name small {
        margin-top: 2px;
        color: #8d98a8;
        font-size: 8.5px;
    }

    .fd-team-badge {
        display: inline-flex;
        align-items: center;
        padding: 5px 7px;
        border-radius: 5px;
        font-size: 8.5px;
        font-weight: 600;
    }

    .fd-team-badge.active {
        color: #5d971b;
        background: #f0f8e5;
    }

    .fd-team-badge.inactive {
        color: #6f7b90;
        background: #eef2f6;
    }

    .fd-team-badge.primary {
        color: #123d70;
        background: #edf2f7;
    }

    .fd-team-actions-cell {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .fd-team-icon-button {
        width: 29px;
        height: 29px;
        padding: 0;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 6px;
        color: #66748b;
        background: transparent;
        cursor: pointer;
        font-size: 12px;
    }

    .fd-team-icon-button:hover {
        color: var(--fd-green-dark);
        background: var(--fd-green-soft);
    }

    .fd-team-icon-button.danger:hover {
        color: #b9444d;
        background: #fff0f1;
    }

    .fd-team-empty {
        padding: 28px 18px !important;
        text-align: center;
        color: #9aa4b3 !important;
        font-size: 10px !important;
    }

    .fd-team-pagination {
        min-height: 49px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border-top: 1px solid var(--fd-border);
        color: #768397;
        background: #fff;
        font-size: 9px;
    }

    .fd-team-pagination-actions {
        display: flex;
        gap: 5px
    }

    .fd-team-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 15000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
        background: rgba(0, 17, 49, .46);
        backdrop-filter: blur(3px);
    }

    .fd-team-modal-backdrop.show {
        display: flex
    }

    .fd-team-modal {
        width: min(860px, 100%);
        max-height: calc(100vh - 36px);
        overflow: auto;
        border: 1px solid #dfe5ec;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 24px 65px rgba(0, 17, 49, .24);
    }

    .fd-team-modal-header {
        min-height: 58px;
        padding: 11px 14px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid var(--fd-border);
        background: #fbfcfd;
    }

    .fd-team-modal-icon {
        width: 34px;
        height: 34px;
        display: grid;
        place-items: center;
        border-radius: 9px;
        color: var(--fd-green-dark);
        background: var(--fd-green-soft);
        font-size: 15px;
    }

    .fd-team-modal-heading {
        min-width: 0;
        flex: 1
    }

    .fd-team-modal-heading h3 {
        margin: 0;
        color: var(--fd-text);
        font-size: 12px;
        font-weight: 700;
    }

    .fd-team-modal-heading p {
        margin: 3px 0 0;
        color: var(--fd-muted);
        font-size: 8.5px;
    }

    .fd-team-modal-close {
        width: 30px;
        height: 30px;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 7px;
        color: #8490a0;
        background: transparent;
        cursor: pointer;
    }

    .fd-team-modal-body {
        padding: 15px
    }

    .fd-team-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 13px;
    }

    .fd-team-field.full {
        grid-column: 1/-1
    }

    .fd-team-field label {
        margin-bottom: 6px;
        display: block;
        color: #42536c;
        font-size: 9px;
        font-weight: 700;
    }

    .fd-team-field input,
    .fd-team-field select,
    .fd-team-field textarea {
        width: 100%;
        min-height: 40px;
        padding: 8px 10px;
        border: 1px solid #dfe5ec;
        border-radius: 8px;
        outline: 0;
        color: #263750;
        background: #fff;
        font-size: 10px;
    }

    .fd-team-field textarea {
        min-height: 76px;
        resize: vertical;
    }

    .fd-team-field input:focus,
    .fd-team-field select:focus,
    .fd-team-field textarea:focus {
        border-color: #a9cf75;
        box-shadow: 0 0 0 3px rgba(116, 184, 36, .11);
    }

    .fd-team-section-title {
        grid-column: 1/-1;
        margin-top: 3px;
        padding: 8px 0 2px;
        border-bottom: 1px solid #eef2f5;
        color: #31425b;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .fd-team-members-box {
        grid-column: 1/-1;
        border: 1px solid var(--fd-border);
        border-radius: 9px;
        overflow: hidden;
    }

    .fd-team-members-head {
        padding: 10px 11px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        background: #f9fbfc;
        border-bottom: 1px solid var(--fd-border);
    }

    .fd-team-members-head strong {
        color: #31425b;
        font-size: 10px;
    }

    .fd-team-members-list {
        max-height: 260px;
        overflow: auto;
    }

    .fd-team-member-row {
        padding: 9px 11px;
        display: grid;
        grid-template-columns: 24px minmax(0, 1fr) 170px 110px;
        align-items: center;
        gap: 8px;
        border-bottom: 1px solid #f0f3f6;
    }

    .fd-team-member-row:last-child {
        border-bottom: 0
    }

    .fd-team-member-row input[type="checkbox"] {
        width: 14px;
        height: 14px;
        accent-color: var(--fd-green);
    }

    .fd-team-member-copy strong,
    .fd-team-member-copy small {
        display: block
    }

    .fd-team-member-copy strong {
        color: #34465f;
        font-size: 9.5px;
    }

    .fd-team-member-copy small {
        margin-top: 2px;
        color: #8a96a7;
        font-size: 8px;
    }

    .fd-team-member-row input[type="text"] {
        width: 100%;
        height: 34px;
        padding: 6px 8px;
        border: 1px solid #dfe5ec;
        border-radius: 7px;
        font-size: 9px;
    }

    .fd-team-primary-label {
        display: flex;
        align-items: center;
        gap: 6px;
        color: #607086;
        font-size: 8.5px;
    }

    .fd-team-modal-footer {
        padding: 12px 15px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        border-top: 1px solid var(--fd-border);
        background: #fbfcfd;
    }

    .fd-team-confirm {
        width: min(440px, 100%)
    }

    .fd-team-confirm .fd-team-modal-body {
        padding: 18px 16px;
        color: #56667c;
        font-size: 10px;
        line-height: 1.6;
    }

    .fd-team-toast {
        width: min(290px, calc(100vw - 24px));
        position: fixed;
        top: 82px;
        right: 16px;
        z-index: 25000;
        padding: 8px 9px;
        display: flex;
        align-items: center;
        gap: 7px;
        border-radius: 7px;
        color: #fff;
        box-shadow: 0 10px 26px rgba(0, 17, 49, .18);
        opacity: 0;
        transform: translateY(-8px);
        pointer-events: none;
        transition: .18s ease;
    }

    .fd-team-toast.show {
        opacity: 1;
        transform: translateY(0);
    }

    .fd-team-toast.success {
        background: #5d971b
    }

    .fd-team-toast.error {
        background: #e45b66
    }

    .fd-team-toast.warning {
        background: #96a52f
    }

    .fd-team-toast.info {
        background: #123d70
    }

    .fd-team-toast-message {
        min-width: 0;
        flex: 1;
        font-size: 8.5px;
        font-weight: 600;
    }

    .fd-team-toast-close {
        width: 19px;
        height: 19px;
        padding: 0;
        border: 0;
        color: #fff;
        background: transparent;
        cursor: pointer;
    }

    @media(max-width:767.98px) {
        .fd-teams-header {
            flex-direction: column
        }

        .fd-teams-actions {
            justify-content: flex-end
        }

        .fd-team-form-grid {
            grid-template-columns: 1fr
        }

        .fd-team-field.full,
        .fd-team-section-title,
        .fd-team-members-box {
            grid-column: auto
        }

        .fd-team-search {
            width: 100%
        }

        .fd-team-toolbar-spacer {
            display: none
        }

        .fd-team-member-row {
            grid-template-columns: 24px minmax(0, 1fr);
        }

        .fd-team-member-row input[type="text"],
        .fd-team-primary-label {
            grid-column: 2;
        }
    }

    @media(max-width:575.98px) {
        .fd-team-stat-card {
            min-height: 102px;
            padding: 15px 17px;
        }

        .fd-team-stat-icon {
            width: 54px;
            height: 54px;
            flex-basis: 54px;
        }

        .fd-team-stat-value {
            font-size: 29px
        }

        .fd-team-filter {
            flex: 1
        }

        .fd-team-modal-footer {
            flex-direction: column-reverse
        }

        .fd-team-modal-footer .fd-team-button {
            width: 100%
        }

        .fd-team-toast {
            top: 72px;
            left: 12px;
            right: 12px;
            width: auto;
        }
    }



    /* ==========================================================
       Customers page - Version 3.2.1
       Header action overflow / topbar overlap fix
       ========================================================== */

    /*
     * The shared navigation is fixed in the live layout. Keep this page's
     * content below it instead of allowing the Customers header to sit
     * underneath the topbar. This fixes Add Customer / More Actions overlap.
     */
    .fieldplx-topbar {
        position: fixed !important;
        top: 0 !important;
        right: 0 !important;
        z-index: 1030 !important;
    }

    .fieldplx-main-content {
        padding-top: var(--fieldplx-topbar-height);
    }

    .fd-teams-header {
        min-width: 0;
        position: relative;
        z-index: 20;
        overflow: visible;
    }

    .fd-teams-header>div:first-child {
        min-width: 0;
        flex: 1 1 auto;
    }

    .fd-teams-actions {
        min-width: 0;
        flex: 0 0 auto;
        margin-left: auto;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: nowrap;
    }

    .fd-teams-actions .fd-team-button {
        flex: 0 0 auto;
        white-space: nowrap;
    }

    .fd-customer-more {
        position: relative;
        z-index: 30;
    }

    .fd-customer-more-menu {
        z-index: 40;
        max-width: min(215px, calc(100vw - 24px));
    }

    @media (max-width: 991.98px) {
        .fd-teams-header {
            align-items: flex-start;
        }

        .fd-teams-actions {
            flex-wrap: wrap;
        }
    }

    @media (max-width: 767.98px) {
        .fieldplx-main-content {
            padding-top: 64px;
        }

        .fd-teams-actions {
            width: 100%;
            margin-left: 0;
            justify-content: flex-start;
        }
    }

    @media (max-width: 420px) {

        .fd-teams-actions>.fd-team-button,
        .fd-teams-actions>.fd-customer-more {
            flex: 1 1 calc(50% - 4px);
            min-width: 0;
        }

        .fd-teams-actions>.fd-customer-more>.fd-team-button {
            width: 100%;
        }
    }

    /* ==========================================================
       Customers page - Version 3.1.0
       Quotation-style summary cards + customer More menus
       ========================================================== */
    .fd-teams-header {
        position: relative;
        z-index: 20;
        overflow: visible;
    }

    .fd-customer-more {
        position: relative;
        z-index: 30;
    }

    .fd-customer-more-menu {
        position: absolute;
        top: calc(100% + 7px);
        right: 0;
        z-index: 40;
        width: 215px;
        padding: 6px;
        display: none;
        border: 1px solid #e1e7ef;
        border-radius: 10px;
        background: #fff;
        box-shadow: 0 16px 38px rgba(15, 23, 42, .15);
    }

    .fd-customer-more.open .fd-customer-more-menu {
        display: block;
    }

    .fd-customer-more-item {
        width: 100%;
        min-height: 36px;
        padding: 8px 10px;
        display: flex;
        align-items: center;
        gap: 9px;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: #33445f !important;
        text-align: left;
        text-decoration: none !important;
        font: inherit;
        font-size: 10px;
        cursor: pointer;
    }

    .fd-customer-more-item i {
        width: 16px;
        color: #123d70;
        font-size: 13px;
        text-align: center;
    }

    .fd-customer-more-item:hover,
    .fd-customer-more-item:focus {
        color: var(--fd-green-dark) !important;
        background: var(--fd-green-soft);
        outline: 0;
    }

    .fd-customer-more-item:hover i,
    .fd-customer-more-item:focus i {
        color: var(--fd-green-dark);
    }

    /* Same visual structure as Quotations: Overview + 3 metric cards. */
    .fd-customer-summary {
        margin-bottom: 16px;
    }

    .fd-customer-summary>div {
        display: flex;
    }

    .fd-customer-summary-card {
        width: 100%;
        min-height: 134px;
        padding: 15px 18px;
        position: relative;
        overflow: visible;
        border: 1px solid #dfe6ef;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(24, 45, 76, .035);
    }

    .fd-customer-summary-title {
        margin: 0;
        color: #10213c;
        font-size: 15px;
        line-height: 1.2;
        font-weight: 700;
    }

    .fd-customer-summary-period {
        display: block;
        margin-top: 3px;
        color: #7f8da1;
        font-size: 10px;
        line-height: 1.2;
    }

    .fd-customer-summary-arrow {
        position: absolute;
        top: 15px;
        right: 16px;
        color: #8191a6;
        font-size: 15px;
        line-height: 1;
    }

    .fd-customer-summary-number-row {
        min-height: 67px;
        display: flex;
        align-items: flex-end;
        gap: 8px;
        padding-top: 10px;
    }

    .fd-customer-summary-value {
        color: #030d1b;
        font-size: 31px;
        line-height: 1;
        font-weight: 700;
        letter-spacing: -.45px;
    }

    .fd-customer-summary-subvalue {
        display: block;
        margin-top: 7px;
        color: #728197;
        font-size: 10px;
    }

    .fd-customer-trend-change {
        position: relative;
        margin-bottom: 1px;
        padding: 4px 7px;
        border-radius: 999px;
        color: #398523;
        background: #edf7e8;
        font-size: 10px;
        line-height: 1;
        font-weight: 600;
        cursor: help;
    }

    .fd-customer-trend-change.down {
        color: #bf4d54;
        background: #fff0f1;
    }

    .fd-customer-trend-change.neutral {
        color: #64748b;
        background: #f1f5f9;
    }

    .fd-customer-trend-tooltip {
        position: absolute;
        left: 50%;
        bottom: calc(100% + 9px);
        z-index: 1080;
        width: 190px;
        padding: 10px 11px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        background: #fff;
        box-shadow: 0 12px 30px rgba(15, 23, 42, .17);
        color: #334155;
        font-size: 10px;
        font-weight: 500;
        line-height: 1.55;
        opacity: 0;
        visibility: hidden;
        transform: translate(-50%, 5px);
        transition: .15s ease;
        pointer-events: none;
    }

    .fd-customer-trend-tooltip::after {
        position: absolute;
        top: 100%;
        left: 50%;
        margin-left: -5px;
        border: 5px solid transparent;
        border-top-color: #fff;
        content: "";
    }

    .fd-customer-trend-change:hover .fd-customer-trend-tooltip,
    .fd-customer-trend-change:focus .fd-customer-trend-tooltip {
        opacity: 1;
        visibility: visible;
        transform: translate(-50%, 0);
    }

    .fd-customer-tooltip-title {
        display: block;
        margin-bottom: 4px;
        color: #64748b;
        font-weight: 600;
    }

    .fd-customer-tooltip-row {
        display: flex;
        justify-content: space-between;
        gap: 10px;
    }

    .fd-customer-tooltip-row strong {
        color: #0f172a;
    }

    .fd-customer-overview-list {
        margin-top: 7px;
        display: grid;
        gap: 5px;
    }

    .fd-customer-overview-row {
        min-height: 14px;
        display: grid;
        grid-template-columns: 7px minmax(0, 1fr) auto;
        align-items: center;
        gap: 6px;
        color: #5d6c82;
        font-size: 9.5px;
        line-height: 1.15;
    }

    .fd-customer-overview-row strong {
        color: #17243a;
        font-size: 9.5px;
        font-weight: 700;
    }

    .fd-customer-overview-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #91a0b4;
    }

    .fd-customer-overview-dot.lead {
        background: #9aa9bc;
    }

    .fd-customer-overview-dot.customer {
        background: #d6a825;
    }

    .fd-customer-overview-dot.active {
        background: #68aa1d;
    }

    .fd-customer-overview-dot.inactive {
        background: #e45b66;
    }

    /* Version 3.2: the customer row itself opens Customer View. */
    .fd-client-click-row {
        cursor: pointer;
    }

    .fd-client-click-row:active {
        background: #f7fbed;
    }

    /* Row-level More menu is fixed so table scrolling never clips it. */
    .fd-client-row-more-button {
        min-width: 64px;
        height: 29px;
        padding: 0 9px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        border: 1px solid #dfe7ef;
        border-radius: 7px;
        color: #43546c;
        background: #fff;
        font-size: 9px;
        font-weight: 700;
        cursor: pointer;
    }

    .fd-client-row-more-button:hover,
    .fd-client-row-more-button.active {
        border-color: #cfe3ae;
        color: var(--fd-green-dark);
        background: var(--fd-green-soft);
    }

    .fd-client-row-more-menu {
        width: 190px;
        padding: 6px;
        position: fixed;
        z-index: 24000;
        display: none;
        border: 1px solid #dfe6ef;
        border-radius: 10px;
        background: #fff;
        box-shadow: 0 18px 42px rgba(0, 17, 49, .17);
    }

    .fd-client-row-more-menu.show {
        display: block;
    }

    .fd-client-row-more-menu .fd-customer-more-item+.fd-customer-more-item {
        margin-top: 2px;
    }

    @media (max-width: 991.98px) {
        .fd-customer-summary-card {
            min-height: 126px;
        }
    }

    @media (max-width: 575.98px) {
        .fd-customer-summary-card {
            min-height: 122px;
        }

        .fd-customer-summary-value {
            font-size: 28px;
        }
    }

    a,
    a:link,
    a:visited,
    a:hover,
    a:focus,
    a:active {
        text-decoration: none !important
    }

    .fd-client-type {
        display: inline-flex;
        align-items: center;
        padding: 5px 7px;
        border-radius: 5px;
        font-size: 8.5px;
        font-weight: 600
    }

    .fd-client-type.client,
    .fd-client-type.active {
        color: #5d971b;
        background: #f0f8e5
    }

    .fd-client-type.lead,
    .fd-client-type.new {
        color: #123d70;
        background: #edf2f7
    }

    .fd-client-type.inactive {
        color: #6f7b90;
        background: #eef2f6
    }

    .fd-client-type.archived {
        color: #8a5e10;
        background: #fff7df
    }

    .fd-client-checks {
        grid-column: 1/-1;
        display: flex;
        flex-wrap: wrap;
        gap: 8px
    }

    .fd-client-check {
        min-height: 38px;
        padding: 7px 9px;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        border: 1px solid #e3e8ed;
        border-radius: 7px;
        color: #5c6d82;
        background: #fff;
        font-size: 8.5px
    }

    .fd-client-check input {
        width: 14px;
        height: 14px;
        accent-color: var(--fd-green)
    }



    /* Customers table font + alignment correction */
    .fd-client-table {
        table-layout: auto;
    }

    .fd-client-table th {
        padding: 12px 14px !important;
        color: #5f6f86 !important;
        font-size: 9px !important;
        line-height: 1.2 !important;
        font-weight: 700 !important;
        letter-spacing: .01em !important;
        text-align: left !important;
        vertical-align: middle !important;
        white-space: nowrap !important;
    }

    .fd-client-table td {
        padding: 12px 14px !important;
        color: #33445f !important;
        font-size: 9.5px !important;
        line-height: 1.45 !important;
        font-weight: 400 !important;
        text-align: left !important;
        vertical-align: middle !important;
    }

    .fd-client-table th:first-child,
    .fd-client-table td:first-child {
        width: 58px;
        text-align: center !important;
    }

    .fd-client-person {
        min-width: 185px;
        align-items: center !important;
    }

    .fd-client-person strong {
        color: #17233b !important;
        font-size: 10.5px !important;
        line-height: 1.35 !important;
        font-weight: 700 !important;
    }

    .fd-client-person small {
        margin-top: 3px !important;
        color: #8793a5 !important;
        font-size: 8.5px !important;
        line-height: 1.3 !important;
        font-weight: 400 !important;
    }

    .fd-client-table td small {
        color: #66758a !important;
        font-size: 8.5px !important;
        line-height: 1.35 !important;
    }

    .fd-client-badge {
        min-height: 22px;
        padding: 4px 7px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        border-radius: 5px !important;
        font-size: 8.5px !important;
        line-height: 1 !important;
        font-weight: 700 !important;
        text-transform: capitalize !important;
        white-space: nowrap !important;
    }

    .fd-client-actions-cell {
        min-width: 100px;
        justify-content: flex-start !important;
        align-items: center !important;
        gap: 4px !important;
    }

    .fd-client-icon-btn {
        width: 29px !important;
        height: 29px !important;
        min-width: 29px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        line-height: 1 !important;
    }

    .fd-client-icon-btn i {
        line-height: 1 !important;
        font-size: 12px !important;
    }

    .fd-client-table td:nth-child(3),
    .fd-client-table td:nth-child(9) {
        vertical-align: middle !important;
    }

    .fd-client-table td:nth-child(10) {
        color: #52627a !important;
        font-size: 9px !important;
    }

    .fd-client-table th:last-child,
    .fd-client-table td:last-child {
        text-align: left !important;
    }

    .fd-client-table a,
    .fd-client-table a:visited,
    .fd-client-table a:hover,
    .fd-client-table a:focus,
    .fd-client-table a:active {
        color: inherit;
        text-decoration: none !important;
    }

    .fd-client-table a.fd-client-badge,
    .fd-client-table a.fd-client-badge:visited {
        color: #123d70 !important;
    }

    @media(max-width:767.98px) {

        .fd-client-table th,
        .fd-client-table td {
            padding: 10px 11px !important;
        }
    }

    /* Client location/view/delete controls */
    .fd-client-location-link {
        min-width: 42px;
        height: 28px;
        padding: 0 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        border: 1px solid #dfe7ef;
        border-radius: 7px;
        color: #123d70 !important;
        background: #f8fafc;
        font-size: 9px;
        font-weight: 700;
        text-decoration: none !important
    }

    .fd-client-location-link:hover {
        border-color: #cfe3ae;
        color: var(--fd-green-dark) !important;
        background: var(--fd-green-soft)
    }

    .fd-team-actions-cell a.fd-team-icon-button {
        text-decoration: none !important
    }


    /* Customers - Version 3.1.0 action column */
    .fd-client-table th:last-child,
    .fd-client-table td:last-child {
        min-width: 250px;
        white-space: nowrap;
    }

    .fd-client-actions-cell {
        min-width: 238px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        flex-wrap: nowrap;
    }

    .fd-client-actions-cell .fd-team-icon-button {
        flex: 0 0 29px;
    }

    .fd-client-action-divider {
        width: 1px;
        height: 23px;
        flex: 0 0 1px;
        margin: 0 2px;
        background: #e5eaf1;
    }

    @media(max-width:575.98px) {

        .fd-client-table th:last-child,
        .fd-client-table td:last-child {
            min-width: 238px;
        }
    }


    /* ==========================================================
       FieldPlx Insights - 2026-09-02
       ========================================================== */
    .ins-header-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
    }

    .ins-date-form {
        display: flex;
        align-items: center;
        gap: 7px;
        flex-wrap: wrap;
    }

    .ins-date-field {
        height: 39px;
        min-width: 142px;
        padding: 0 10px;
        border: 1px solid var(--fd-border);
        border-radius: 8px;
        color: #43546c;
        background: #fff;
        font-size: 10px;
        outline: 0;
    }

    .ins-date-field:focus {
        border-color: #b9d78e;
        box-shadow: 0 0 0 3px rgba(116, 184, 36, .10);
    }

    .ins-section {
        margin-top: 22px;
    }

    .ins-section:first-of-type {
        margin-top: 0;
    }

    .ins-section-title {
        margin: 0 0 12px;
        color: #10213c;
        font-size: 19px;
        line-height: 1.2;
        font-weight: 700;
    }

    .ins-card {
        border: 1px solid #dfe6ef;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(24, 45, 76, .035);
    }

    .ins-overview-card {
        padding: 16px 18px;
        margin-bottom: 18px;
    }

    .ins-card-head {
        min-height: 34px;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }

    .ins-card-title {
        margin: 0;
        color: #10213c;
        font-size: 14px;
        line-height: 1.2;
        font-weight: 700;
    }

    .ins-card-subtitle {
        display: block;
        margin-top: 3px;
        color: #7f8da1;
        font-size: 9.5px;
        line-height: 1.3;
    }

    .ins-range-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #526279;
        font-size: 10px;
        font-weight: 600;
        white-space: nowrap;
    }

    .ins-overview-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        border-top: 1px solid #eef2f6;
    }

    .ins-overview-item {
        min-width: 0;
        padding: 14px 16px 8px;
        position: relative;
        border-right: 1px solid #eef2f6;
    }

    .ins-overview-item:first-child {
        padding-left: 0;
    }

    .ins-overview-item:last-child {
        border-right: 0;
    }

    .ins-overview-label {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 6px;
        color: #17243a;
        font-size: 10.5px;
        line-height: 1.25;
        font-weight: 700;
    }

    .ins-overview-arrow {
        color: #8492a6;
        font-size: 11px;
        flex: 0 0 auto;
    }

    .ins-overview-number-row {
        min-height: 45px;
        display: flex;
        align-items: flex-end;
        gap: 7px;
        padding-top: 8px;
    }

    .ins-overview-value {
        color: #030d1b;
        font-size: 25px;
        line-height: 1;
        font-weight: 700;
        letter-spacing: -.25px;
        white-space: nowrap;
    }

    .ins-trend {
        margin-bottom: 1px;
        padding: 4px 6px;
        border-radius: 999px;
        color: #398523;
        background: #edf7e8;
        font-size: 9px;
        line-height: 1;
        font-weight: 700;
        white-space: nowrap;
    }

    .ins-trend.down {
        color: #bf4d54;
        background: #fff0f1;
    }

    .ins-trend.neutral {
        color: #64748b;
        background: #f1f5f9;
    }

    .ins-grid-2 {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    .ins-grid-3 {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
    }

    .ins-chart-card {
        min-height: 330px;
        padding: 16px 18px;
        position: relative;
        overflow: hidden;
    }

    .ins-chart-card.large {
        min-height: 360px;
    }

    .ins-chart-card.medium {
        min-height: 300px;
    }

    .ins-chart-card.compact {
        min-height: 250px;
    }

    .ins-chart-wrap {
        height: 250px;
        min-height: 0;
        position: relative;
    }

    .ins-chart-card.large .ins-chart-wrap {
        height: 285px;
    }

    .ins-chart-card.compact .ins-chart-wrap {
        height: 175px;
    }

    .ins-chart-summary {
        display: flex;
        align-items: flex-end;
        gap: 22px;
        margin: -2px 0 10px;
    }

    .ins-chart-summary-item small {
        display: block;
        color: #7f8da1;
        font-size: 9px;
        line-height: 1.2;
    }

    .ins-chart-summary-item strong {
        display: block;
        margin-top: 2px;
        color: #061326;
        font-size: 22px;
        line-height: 1;
        font-weight: 700;
    }

    .ins-empty {
        height: 100%;
        min-height: 150px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 8px;
        color: #8190a5;
        text-align: center;
        font-size: 10px;
    }

    .ins-empty i {
        font-size: 22px;
        color: #a6b2c2;
    }

    .ins-kpi-card {
        min-height: 150px;
        padding: 16px 18px;
        position: relative;
    }

    .ins-kpi-arrow {
        position: absolute;
        top: 16px;
        right: 17px;
        color: #8191a6;
        font-size: 13px;
    }

    .ins-kpi-value {
        display: block;
        margin-top: 16px;
        color: #030d1b;
        font-size: 28px;
        line-height: 1;
        font-weight: 700;
    }

    .ins-kpi-caption {
        display: block;
        margin-top: 6px;
        color: #7f8da1;
        font-size: 9.5px;
        line-height: 1.35;
    }

    .ins-kpi-stack {
        display: grid;
        gap: 12px;
        margin-top: 14px;
    }

    .ins-kpi-stack-row {
        display: flex;
        align-items: end;
        justify-content: space-between;
        gap: 10px;
    }

    .ins-kpi-stack-row span {
        color: #6f7e92;
        font-size: 9.5px;
    }

    .ins-kpi-stack-row strong {
        color: #061326;
        font-size: 18px;
    }

    .ins-table-wrap {
        overflow-x: auto;
    }

    .ins-table {
        width: 100%;
        border-collapse: collapse;
    }

    .ins-table th {
        padding: 10px 12px;
        border-bottom: 1px solid #e6ecf2;
        color: #526279;
        background: #fbfcfd;
        text-align: left;
        white-space: nowrap;
        font-size: 8.5px;
        font-weight: 800;
        letter-spacing: .35px;
        text-transform: uppercase;
    }

    .ins-table td {
        padding: 11px 12px;
        border-bottom: 1px solid #eef2f6;
        color: #43546c;
        font-size: 9.5px;
        vertical-align: middle;
    }

    .ins-table tr:last-child td {
        border-bottom: 0;
    }

    .ins-table strong {
        color: #17243a;
        font-weight: 700;
    }

    .ins-profit-positive {
        color: #398523 !important;
        font-weight: 700;
    }

    .ins-profit-negative {
        color: #bf4d54 !important;
        font-weight: 700;
    }

    .ins-tabs {
        display: inline-flex;
        overflow: hidden;
        border: 1px solid #dfe6ef;
        border-radius: 8px;
        background: #fff;
    }

    .ins-tab {
        min-height: 31px;
        padding: 0 12px;
        display: inline-flex;
        align-items: center;
        border: 0;
        border-right: 1px solid #e6ecf2;
        color: #526279;
        background: #fff;
        font-size: 9.5px;
        font-weight: 700;
        cursor: pointer;
    }

    .ins-tab:last-child {
        border-right: 0;
    }

    .ins-tab.active {
        color: #4f7e18;
        background: #eff8e4;
        box-shadow: inset 0 0 0 1px #8ac244;
    }

    .ins-pl-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 16px;
        border-bottom: 1px solid #edf1f5;
    }

    .ins-pl-left {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .ins-pl-title {
        color: #17243a;
        font-size: 11px;
        font-weight: 700;
    }

    .ins-no-rows {
        padding: 28px 16px !important;
        color: #8190a5 !important;
        text-align: center;
    }

    .ins-donut-layout {
        min-height: 220px;
        display: grid;
        grid-template-columns: minmax(180px, 260px) minmax(0, 1fr);
        align-items: center;
        gap: 20px;
    }

    .ins-donut-chart {
        height: 200px;
        position: relative;
    }

    .ins-legend {
        display: grid;
        gap: 8px;
    }

    .ins-legend-row {
        display: grid;
        grid-template-columns: 9px minmax(0, 1fr) auto;
        align-items: center;
        gap: 8px;
        color: #5d6c82;
        font-size: 9.5px;
    }

    .ins-legend-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #68aa1d;
    }

    .ins-legend-row strong {
        color: #17243a;
        font-size: 9.5px;
    }

    .ins-lead-metrics {
        display: grid;
        grid-template-columns: 1fr 2fr;
        gap: 14px;
        margin-bottom: 14px;
    }

    .ins-lead-left {
        display: grid;
        gap: 12px;
    }

    .ins-small-metric {
        min-height: 118px;
        padding: 15px 16px;
    }

    .ins-small-metric-value {
        display: flex;
        align-items: flex-end;
        gap: 7px;
        margin-top: 18px;
    }

    .ins-small-metric-value strong {
        color: #061326;
        font-size: 22px;
        line-height: 1;
    }

    .ins-funnel-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }

    .ins-jobs-bottom {
        margin-top: 14px;
        padding: 15px 18px;
    }

    .ins-average-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
        margin-top: 10px;
    }

    .ins-average-item {
        padding-right: 18px;
        border-right: 1px solid #edf1f5;
    }

    .ins-average-item:last-child {
        border-right: 0;
    }

    .ins-average-item span {
        display: block;
        color: #7f8da1;
        font-size: 9.5px;
    }

    .ins-average-item strong {
        display: block;
        margin-top: 5px;
        color: #061326;
        font-size: 22px;
    }

    @media(max-width:1199.98px) {
        .ins-overview-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .ins-overview-item:nth-child(3) {
            border-right: 0;
        }

        .ins-overview-item:nth-child(n+4) {
            border-top: 1px solid #eef2f6;
        }

        .ins-lead-metrics {
            grid-template-columns: 1fr;
        }
    }

    @media(max-width:991.98px) {
        .ins-grid-3 {
            grid-template-columns: 1fr 1fr;
        }

        .ins-grid-2,
        .ins-funnel-grid {
            grid-template-columns: 1fr;
        }

        .ins-donut-layout {
            grid-template-columns: 1fr;
        }

        .ins-donut-chart {
            height: 180px;
        }
    }

    @media(max-width:767.98px) {
        .fd-teams-header {
            flex-direction: column;
        }

        .ins-header-actions,
        .ins-date-form {
            width: 100%;
            justify-content: flex-start;
        }

        .ins-date-field {
            flex: 1 1 135px;
            min-width: 0;
        }

        .ins-overview-grid {
            grid-template-columns: 1fr 1fr;
        }

        .ins-overview-item {
            border-right: 1px solid #eef2f6;
            border-top: 1px solid #eef2f6;
            padding-left: 12px;
        }

        .ins-overview-item:nth-child(-n+2) {
            border-top: 0;
        }

        .ins-overview-item:nth-child(2n) {
            border-right: 0;
        }

        .ins-grid-3 {
            grid-template-columns: 1fr;
        }

        .ins-chart-card,
        .ins-chart-card.large {
            min-height: 290px;
            padding: 14px;
        }

        .ins-chart-wrap,
        .ins-chart-card.large .ins-chart-wrap {
            height: 220px;
        }
    }

    @media(max-width:480px) {
        .ins-overview-grid {
            grid-template-columns: 1fr;
        }

        .ins-overview-item {
            border-right: 0 !important;
            border-top: 1px solid #eef2f6 !important;
            padding-left: 0;
        }

        .ins-overview-item:first-child {
            border-top: 0 !important;
        }

        .ins-average-grid {
            grid-template-columns: 1fr;
        }

        .ins-average-item {
            border-right: 0;
            padding-right: 0;
        }
    }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/includes/nav.php'; ?>

    <div class="fieldplx-main-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <main class="fieldplx-main-content">
            <div class="fieldplx-content-wrapper">
                <div class="fd-dashboard">

                    <section class="fd-teams-header">
                        <div>
                            <h1 class="fd-teams-title">Insights</h1>
                            <p class="fd-teams-subtitle">Track CRM conversion, revenue, profitability, cashflow, quote
                                performance and job value using live FieldPlx data.</p>
                        </div>
                        <div class="ins-header-actions">
                            <form class="ins-date-form" method="get">
                                <input class="ins-date-field" type="date" name="from"
                                    value="<?= htmlspecialchars($insFrom, ENT_QUOTES, 'UTF-8') ?>"
                                    aria-label="From date">
                                <input class="ins-date-field" type="date" name="to"
                                    value="<?= htmlspecialchars($insTo, ENT_QUOTES, 'UTF-8') ?>" aria-label="To date">
                                <button type="submit" class="fd-team-button primary"><i class="bi bi-funnel"></i>
                                    Apply</button>
                                <a class="fd-team-button" href="insights.php"><i
                                        class="bi bi-arrow-counterclockwise"></i> Reset</a>
                            </form>
                        </div>
                    </section>

                    <?php if ($insDataError !== ''): ?>
                    <div class="alert alert-warning py-2 px-3" style="font-size:11px;">
                        Some Insights data could not be loaded:
                        <?= htmlspecialchars($insDataError, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endif; ?>

                    <section class="ins-section">
                        <article class="ins-card ins-overview-card">
                            <div class="ins-card-head">
                                <div>
                                    <h2 class="ins-card-title">Overview</h2>
                                    <span class="ins-card-subtitle">Performance for the selected period compared with
                                        the immediately preceding period.</span>
                                </div>
                                <span class="ins-range-label"><i
                                        class="bi bi-calendar3"></i><?= htmlspecialchars($insRangeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>

                            <div class="ins-overview-grid">
                                <?php
                  $overviewItems = array(
                    array('key'=>'new_leads','label'=>'New leads','money'=>false),
                    array('key'=>'new_requests','label'=>'New requests','money'=>false),
                    array('key'=>'converted_quotes','label'=>'Converted quotes','money'=>false),
                    array('key'=>'one_off_jobs','label'=>'New one-off jobs','money'=>false),
                    array('key'=>'recurring_jobs','label'=>'New recurring jobs','money'=>false),
                    array('key'=>'invoiced_value','label'=>'Invoiced value','money'=>true)
                  );
                  foreach ($overviewItems as $item):
                    $key = $item['key'];
                    $pct = $overviewTrend[$key];
                ?>
                                <div class="ins-overview-item">
                                    <div class="ins-overview-label">
                                        <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <i class="bi bi-arrow-up-right ins-overview-arrow"></i>
                                    </div>
                                    <div class="ins-overview-number-row">
                                        <strong class="ins-overview-value">
                                            <?php if ($item['money']): ?>
                                            <?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$overview[$key], 2) ?>
                                            <?php else: ?>
                                            <?= number_format((float)$overview[$key], 0) ?>
                                            <?php endif; ?>
                                        </strong>
                                        <span
                                            class="ins-trend<?= htmlspecialchars(ins_trend_class($pct), ENT_QUOTES, 'UTF-8') ?>"
                                            title="Previous period: <?= htmlspecialchars((string)$overviewPrev[$key], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(ins_trend_text($pct), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    </section>

                    <section class="ins-section">
                        <h2 class="ins-section-title">Revenue</h2>

                        <article class="ins-card ins-chart-card large">
                            <div class="ins-card-head">
                                <div>
                                    <h3 class="ins-card-title">Revenue</h3>
                                    <span class="ins-card-subtitle">Invoiced revenue by month for
                                        <?= (int)$previousYear ?> and <?= (int)$currentYear ?>. Draft, cancelled,
                                        archived and written-off invoices are excluded.</span>
                                </div>
                                <span class="ins-range-label"><i class="bi bi-calendar3"></i>Jan 1,
                                    <?= (int)$previousYear ?> - <?= date('M j, Y') ?></span>
                            </div>
                            <div class="ins-chart-summary">
                                <div class="ins-chart-summary-item">
                                    <small><?= (int)$previousYear ?></small>
                                    <strong><?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format(array_sum($revenuePrevData), 2) ?></strong>
                                </div>
                                <div class="ins-chart-summary-item">
                                    <small><?= (int)$currentYear ?></small>
                                    <strong><?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format(array_sum($revenueCurrData), 2) ?></strong>
                                </div>
                            </div>
                            <div class="ins-chart-wrap"><canvas id="revenueChart"></canvas></div>
                        </article>

                        <div style="margin-top:14px;">
                            <article class="ins-card ins-chart-card medium">
                                <div class="ins-card-head">
                                    <div>
                                        <h3 class="ins-card-title">Revenue by lead source</h3>
                                        <span
                                            class="ins-card-subtitle"><?= htmlspecialchars($insRangeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <i class="bi bi-arrow-up-right ins-overview-arrow"></i>
                                </div>
                                <?php if (array_sum($leadSourceValues) > 0): ?>
                                <div class="ins-donut-layout">
                                    <div class="ins-donut-chart"><canvas id="leadSourceChart"></canvas></div>
                                    <div class="ins-legend" id="leadSourceLegend"></div>
                                </div>
                                <?php else: ?>
                                <div class="ins-empty"><i class="bi bi-pie-chart"></i><span>No invoiced revenue by lead
                                        source for this period.</span></div>
                                <?php endif; ?>
                            </article>
                        </div>
                    </section>

                    <section class="ins-section">
                        <h2 class="ins-section-title">Profit and loss</h2>
                        <article class="ins-card">
                            <div class="ins-pl-header">
                                <div class="ins-pl-left">
                                    <span class="ins-pl-title">Recent one-off jobs</span>
                                    <div class="ins-tabs">
                                        <button type="button" class="ins-tab active"
                                            data-profit-view="completed">Completed</button>
                                        <button type="button" class="ins-tab" data-profit-view="active">Active</button>
                                    </div>
                                </div>
                                <span class="ins-range-label"><i
                                        class="bi bi-calendar3"></i><?= htmlspecialchars($insRangeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="ins-table-wrap">
                                <table class="ins-table">
                                    <thead>
                                        <tr>
                                            <th>Client</th>
                                            <th>Job #</th>
                                            <th>Total price</th>
                                            <th>Labour cost</th>
                                            <th>Expenses</th>
                                            <th>Profit</th>
                                            <th>Profit margin</th>
                                        </tr>
                                    </thead>
                                    <tbody id="profitTableBody">
                                        <?php if (empty($profitRows)): ?>
                                        <tr>
                                            <td colspan="7" class="ins-no-rows">No recent one-off jobs to display.</td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($profitRows as $row): ?>
                                        <tr
                                            data-profit-group="<?= htmlspecialchars($row['view_group'], ENT_QUOTES, 'UTF-8') ?>">
                                            <td><strong><?= htmlspecialchars($row['client_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            </td>
                                            <td><strong><?= htmlspecialchars($row['job_no'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            </td>
                                            <td><?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$row['total'], 2) ?>
                                            </td>
                                            <td><?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$row['labor_cost'], 2) ?>
                                            </td>
                                            <td><?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$row['expenses_total'], 2) ?>
                                            </td>
                                            <td
                                                class="<?= (float)$row['profit'] >= 0 ? 'ins-profit-positive' : 'ins-profit-negative' ?>">
                                                <?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$row['profit'], 2) ?>
                                            </td>
                                            <td
                                                class="<?= $row['margin'] !== null && (float)$row['margin'] >= 0 ? 'ins-profit-positive' : 'ins-profit-negative' ?>">
                                                <?= $row['margin'] === null ? '—' : number_format((float)$row['margin'], 1) . '%' ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr id="profitNoRows" style="display:none;">
                                            <td colspan="7" class="ins-no-rows">No jobs in this view.</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </article>
                    </section>

                    <section class="ins-section">
                        <h2 class="ins-section-title">Cashflow</h2>
                        <div class="ins-grid-3">
                            <article class="ins-card ins-kpi-card">
                                <i class="bi bi-arrow-up-right ins-kpi-arrow"></i>
                                <h3 class="ins-card-title">Receivables</h3>
                                <strong
                                    class="ins-kpi-value"><?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$cashflow['receivables'], 2) ?></strong>
                                <span class="ins-kpi-caption"><?= (int)$cashflow['receivable_clients'] ?>
                                    client<?= (int)$cashflow['receivable_clients'] === 1 ? '' : 's' ?> owe you</span>
                            </article>

                            <article class="ins-card ins-kpi-card">
                                <i class="bi bi-arrow-up-right ins-kpi-arrow"></i>
                                <h3 class="ins-card-title">Projected income</h3>
                                <div class="ins-kpi-stack">
                                    <div class="ins-kpi-stack-row">
                                        <div>
                                            <strong><?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$cashflow['due_today'], 2) ?></strong><span>Due
                                                today</span></div>
                                    </div>
                                    <div class="ins-kpi-stack-row">
                                        <div>
                                            <strong><?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$cashflow['due_7_days'], 2) ?></strong><span>Due
                                                in the next 7 days</span></div>
                                    </div>
                                </div>
                            </article>

                            <article class="ins-card ins-kpi-card">
                                <i class="bi bi-arrow-up-right ins-kpi-arrow"></i>
                                <h3 class="ins-card-title">Invoice payment time</h3>
                                <span class="ins-card-subtitle">Selected period</span>
                                <strong class="ins-kpi-value">
                                    <?= $cashflow['payment_days'] === null ? '—' : number_format((float)$cashflow['payment_days'], 1) . ' days' ?>
                                </strong>
                                <span class="ins-kpi-caption">Average days from invoice issue date to paid date.</span>
                            </article>
                        </div>

                        <div style="margin-top:14px;">
                            <article class="ins-card ins-chart-card compact">
                                <div class="ins-card-head">
                                    <div>
                                        <h3 class="ins-card-title">Payment methods</h3>
                                        <span
                                            class="ins-card-subtitle"><?= htmlspecialchars($insRangeLabel, ENT_QUOTES, 'UTF-8') ?>
                                            · Successful payments only</span>
                                    </div>
                                </div>
                                <?php if (array_sum($paymentMethodValues) > 0): ?>
                                <div class="ins-donut-layout">
                                    <div class="ins-donut-chart"><canvas id="paymentMethodChart"></canvas></div>
                                    <div class="ins-legend" id="paymentMethodLegend"></div>
                                </div>
                                <?php else: ?>
                                <div class="ins-empty"><i class="bi bi-credit-card"></i><span>No successful payments in
                                        this period.</span></div>
                                <?php endif; ?>
                            </article>
                        </div>
                    </section>

                    <section class="ins-section">
                        <h2 class="ins-section-title">Lead conversion</h2>

                        <div class="ins-lead-metrics">
                            <div class="ins-lead-left">
                                <article class="ins-card ins-small-metric">
                                    <h3 class="ins-card-title">Lead conversion time</h3>
                                    <span class="ins-card-subtitle">Selected-period average</span>
                                    <div class="ins-small-metric-value">
                                        <strong><?= $leadConversion['lead_days'] === null ? '—' : number_format((float)$leadConversion['lead_days'], 1) . ' days' ?></strong>
                                    </div>
                                </article>
                                <article class="ins-card ins-small-metric">
                                    <h3 class="ins-card-title">Quote approval time</h3>
                                    <span class="ins-card-subtitle">Selected-period average</span>
                                    <div class="ins-small-metric-value">
                                        <strong><?= $leadConversion['quote_approval_days'] === null ? '—' : number_format((float)$leadConversion['quote_approval_days'], 1) . ' days' ?></strong>
                                    </div>
                                </article>
                            </div>

                            <article class="ins-card ins-chart-card compact">
                                <div class="ins-card-head">
                                    <div>
                                        <h3 class="ins-card-title">Lead funnel</h3>
                                        <span class="ins-card-subtitle">Past 90 days</span>
                                    </div>
                                </div>
                                <div class="ins-chart-wrap"><canvas id="leadFunnelChart"></canvas></div>
                            </article>
                        </div>

                        <div class="ins-funnel-grid">
                            <article class="ins-card ins-chart-card medium">
                                <div class="ins-card-head">
                                    <div>
                                        <h3 class="ins-card-title">Quote conversion rate</h3>
                                        <span
                                            class="ins-card-subtitle"><?= htmlspecialchars($insRangeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <strong
                                        style="font-size:23px;color:#061326;"><?= number_format((float)$leadConversion['quote_conversion_rate'], 1) ?>%</strong>
                                </div>
                                <?php if (!empty($quoteLabels)): ?>
                                <div class="ins-chart-wrap"><canvas id="quoteConversionChart"></canvas></div>
                                <?php else: ?>
                                <div class="ins-empty"><i class="bi bi-graph-up-arrow"></i><span>No sent quotes in this
                                        period.</span></div>
                                <?php endif; ?>
                            </article>

                            <article class="ins-card ins-chart-card medium">
                                <div class="ins-card-head">
                                    <div>
                                        <h3 class="ins-card-title">Quote value</h3>
                                        <span
                                            class="ins-card-subtitle"><?= htmlspecialchars($insRangeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <i class="bi bi-arrow-up-right ins-overview-arrow"></i>
                                </div>
                                <?php if (!empty($quoteLabels)): ?>
                                <div class="ins-chart-summary">
                                    <div class="ins-chart-summary-item">
                                        <small>Sent</small><strong><?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format(array_sum($quoteSentValueSeries), 2) ?></strong>
                                    </div>
                                    <div class="ins-chart-summary-item">
                                        <small>Converted</small><strong><?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format(array_sum($quoteConvertedValueSeries), 2) ?></strong>
                                    </div>
                                </div>
                                <div class="ins-chart-wrap"><canvas id="quoteValueChart"></canvas></div>
                                <?php else: ?>
                                <div class="ins-empty"><i class="bi bi-file-earmark-text"></i><span>No quote value data
                                        in this period.</span></div>
                                <?php endif; ?>
                            </article>
                        </div>
                    </section>

                    <section class="ins-section">
                        <h2 class="ins-section-title">Jobs</h2>
                        <div class="ins-grid-2">
                            <article class="ins-card ins-chart-card medium">
                                <div class="ins-card-head">
                                    <div>
                                        <h3 class="ins-card-title">Scheduled job value</h3>
                                        <span class="ins-card-subtitle">Next 28 days</span>
                                    </div>
                                </div>
                                <div class="ins-chart-summary">
                                    <div class="ins-chart-summary-item"><small>Scheduled
                                            value</small><strong><?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format(array_sum($scheduledValues), 2) ?></strong>
                                    </div>
                                </div>
                                <div class="ins-chart-wrap"><canvas id="scheduledJobsChart"></canvas></div>
                            </article>

                            <article class="ins-card ins-chart-card medium">
                                <div class="ins-card-head">
                                    <div>
                                        <h3 class="ins-card-title">Recurring vs One-off</h3>
                                        <span
                                            class="ins-card-subtitle"><?= htmlspecialchars($insRangeLabel, ENT_QUOTES, 'UTF-8') ?>
                                            · Job value mix</span>
                                    </div>
                                    <i class="bi bi-arrow-up-right ins-overview-arrow"></i>
                                </div>
                                <?php if (($jobMix['one_off'] + $jobMix['recurring']) > 0): ?>
                                <div class="ins-donut-layout">
                                    <div class="ins-donut-chart"><canvas id="jobMixChart"></canvas></div>
                                    <div class="ins-legend" id="jobMixLegend"></div>
                                </div>
                                <?php else: ?>
                                <div class="ins-empty"><i class="bi bi-briefcase"></i><span>No job value data in this
                                        period.</span></div>
                                <?php endif; ?>
                            </article>
                        </div>

                        <article class="ins-card ins-jobs-bottom">
                            <h3 class="ins-card-title">Average job value</h3>
                            <div class="ins-average-grid">
                                <div class="ins-average-item">
                                    <span>One-off jobs</span>
                                    <strong><?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$avgJobValue['one_off'], 2) ?></strong>
                                </div>
                                <div class="ins-average-item">
                                    <span>Recurring jobs</span>
                                    <strong><?= htmlspecialchars($insCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$avgJobValue['recurring'], 2) ?></strong>
                                </div>
                            </div>
                        </article>
                    </section>

                </div>
            </div>
        </main>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>

    <script>
    (function() {
        'use strict';

        var currency = <?= json_encode($insCurrencySymbol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var green = '#68aa1d';
        var greenDark = '#4f7e18';
        var greenSoft = 'rgba(104,170,29,.16)';
        var navy = '#123d70';
        var blueSoft = 'rgba(18,61,112,.13)';
        var gold = '#d6a825';
        var red = '#e45b66';
        var grey = '#9aa9bc';
        var grid = '#edf1f5';
        var palette = [green, navy, gold, '#7c8ca5', '#95bf5e', '#3f7f91', '#c18b4d', red];

        function money(v) {
            return currency + Number(v || 0).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function baseOptions(yMoney) {
            return {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var label = ctx.dataset.label ? ctx.dataset.label + ': ' : '';
                                return label + (yMoney ? money(ctx.parsed.y) : ctx.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#7f8da1',
                            font: {
                                size: 9
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: grid
                        },
                        ticks: {
                            color: '#7f8da1',
                            font: {
                                size: 9
                            },
                            callback: function(v) {
                                return yMoney ? currency + Number(v).toLocaleString() : v;
                            }
                        }
                    }
                }
            };
        }

        function renderLegend(elId, labels, values, colors, formatter) {
            var el = document.getElementById(elId);
            if (!el) return;
            var h = '';
            labels.forEach(function(label, i) {
                h += '<div class="ins-legend-row"><span class="ins-legend-dot" style="background:' + colors[
                        i % colors.length] + '"></span><span>' +
                    String(label).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') +
                    '</span><strong>' + formatter(values[i]) + '</strong></div>';
            });
            el.innerHTML = h;
        }

        if (window.Chart) {
            new Chart(document.getElementById('revenueChart'), {
                type: 'line',
                data: {
                    labels: <?= json_encode($monthLabels) ?>,
                    datasets: [{
                            label: <?= json_encode((string)$previousYear) ?>,
                            data: <?= json_encode($revenuePrevData) ?>,
                            borderColor: grey,
                            backgroundColor: 'rgba(154,169,188,.10)',
                            borderWidth: 2,
                            pointRadius: 2,
                            tension: .28
                        },
                        {
                            label: <?= json_encode((string)$currentYear) ?>,
                            data: <?= json_encode($revenueCurrData) ?>,
                            borderColor: green,
                            backgroundColor: greenSoft,
                            borderWidth: 2,
                            pointRadius: 2,
                            tension: .28
                        }
                    ]
                },
                options: Object.assign(baseOptions(true), {
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            align: 'start',
                            labels: {
                                boxWidth: 8,
                                boxHeight: 8,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                color: '#526279',
                                font: {
                                    size: 9
                                }
                            }
                        },
                        tooltip: baseOptions(true).plugins.tooltip
                    }
                })
            });

            var leadLabels = <?= json_encode($leadSourceLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            var leadValues = <?= json_encode($leadSourceValues) ?>;
            if (document.getElementById('leadSourceChart')) {
                var leadColors = leadLabels.map(function(_, i) {
                    return palette[i % palette.length];
                });
                new Chart(document.getElementById('leadSourceChart'), {
                    type: 'doughnut',
                    data: {
                        labels: leadLabels,
                        datasets: [{
                            data: leadValues,
                            backgroundColor: leadColors,
                            borderWidth: 0,
                            hoverOffset: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '67%',
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(c) {
                                        return c.label + ': ' + money(c.parsed);
                                    }
                                }
                            }
                        }
                    }
                });
                renderLegend('leadSourceLegend', leadLabels, leadValues, leadColors, money);
            }

            var paymentLabels =
                <?= json_encode($paymentMethodLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            var paymentValues = <?= json_encode($paymentMethodValues) ?>;
            if (document.getElementById('paymentMethodChart')) {
                var paymentColors = paymentLabels.map(function(_, i) {
                    return palette[i % palette.length];
                });
                new Chart(document.getElementById('paymentMethodChart'), {
                    type: 'doughnut',
                    data: {
                        labels: paymentLabels,
                        datasets: [{
                            data: paymentValues,
                            backgroundColor: paymentColors,
                            borderWidth: 0,
                            hoverOffset: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '67%',
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(c) {
                                        return c.label + ': ' + money(c.parsed);
                                    }
                                }
                            }
                        }
                    }
                });
                renderLegend('paymentMethodLegend', paymentLabels, paymentValues, paymentColors, money);
            }

            new Chart(document.getElementById('leadFunnelChart'), {
                type: 'bar',
                data: {
                    labels: ['New leads', 'Leads with sent quote', 'Leads with job created'],
                    datasets: [{
                        data: [
                            <?= (int)$leadFunnel['new_leads'] ?>,
                            <?= (int)$leadFunnel['sent_quote'] ?>,
                            <?= (int)$leadFunnel['job_created'] ?>
                        ],
                        backgroundColor: [green, '#8bbd52', '#b2cf8f'],
                        borderRadius: 4,
                        maxBarThickness: 85
                    }]
                },
                options: Object.assign(baseOptions(false), {
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(c) {
                                    return String(c.parsed.y);
                                }
                            }
                        }
                    }
                })
            });

            var quoteLabels = <?= json_encode($quoteLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            if (document.getElementById('quoteConversionChart')) {
                new Chart(document.getElementById('quoteConversionChart'), {
                    type: 'line',
                    data: {
                        labels: quoteLabels,
                        datasets: [{
                            label: 'Conversion rate',
                            data: <?= json_encode($quoteConversionSeries) ?>,
                            borderColor: green,
                            backgroundColor: greenSoft,
                            borderWidth: 2,
                            pointRadius: 2,
                            tension: .25,
                            fill: false
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(c) {
                                        return Number(c.parsed.y).toFixed(1) + '%';
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    color: '#7f8da1',
                                    font: {
                                        size: 9
                                    }
                                }
                            },
                            y: {
                                beginAtZero: true,
                                suggestedMax: 100,
                                grid: {
                                    color: grid
                                },
                                ticks: {
                                    color: '#7f8da1',
                                    font: {
                                        size: 9
                                    },
                                    callback: function(v) {
                                        return v + '%';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            if (document.getElementById('quoteValueChart')) {
                new Chart(document.getElementById('quoteValueChart'), {
                    type: 'line',
                    data: {
                        labels: quoteLabels,
                        datasets: [{
                                label: 'Sent',
                                data: <?= json_encode($quoteSentValueSeries) ?>,
                                borderColor: grey,
                                backgroundColor: 'rgba(154,169,188,.10)',
                                borderWidth: 2,
                                pointRadius: 2,
                                tension: .25
                            },
                            {
                                label: 'Converted',
                                data: <?= json_encode($quoteConvertedValueSeries) ?>,
                                borderColor: green,
                                backgroundColor: greenSoft,
                                borderWidth: 2,
                                pointRadius: 2,
                                tension: .25
                            }
                        ]
                    },
                    options: Object.assign(baseOptions(true), {
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                align: 'start',
                                labels: {
                                    boxWidth: 8,
                                    boxHeight: 8,
                                    usePointStyle: true,
                                    pointStyle: 'circle',
                                    color: '#526279',
                                    font: {
                                        size: 9
                                    }
                                }
                            },
                            tooltip: baseOptions(true).plugins.tooltip
                        }
                    })
                });
            }

            new Chart(document.getElementById('scheduledJobsChart'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($scheduledLabels) ?>,
                    datasets: [{
                        label: 'Scheduled value',
                        data: <?= json_encode($scheduledValues) ?>,
                        backgroundColor: greenSoft,
                        borderColor: green,
                        borderWidth: 1,
                        borderRadius: 4,
                        maxBarThickness: 90
                    }]
                },
                options: baseOptions(true)
            });

            if (document.getElementById('jobMixChart')) {
                var mixLabels = ['One-off', 'Recurring'];
                var mixValues = [<?= json_encode((float)$jobMix['one_off']) ?>,
                    <?= json_encode((float)$jobMix['recurring']) ?>
                ];
                var mixColors = [green, navy];
                new Chart(document.getElementById('jobMixChart'), {
                    type: 'doughnut',
                    data: {
                        labels: mixLabels,
                        datasets: [{
                            data: mixValues,
                            backgroundColor: mixColors,
                            borderWidth: 0,
                            hoverOffset: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '67%',
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(c) {
                                        return c.label + ': ' + money(c.parsed);
                                    }
                                }
                            }
                        }
                    }
                });
                renderLegend('jobMixLegend', mixLabels, mixValues, mixColors, money);
            }
        }

        /* Profit and loss Completed / Active toggle. */
        var tabs = document.querySelectorAll('[data-profit-view]');
        var rows = document.querySelectorAll('#profitTableBody tr[data-profit-group]');
        var noRows = document.getElementById('profitNoRows');

        function applyProfitView(view) {
            var shown = 0;
            rows.forEach(function(row) {
                var show = row.getAttribute('data-profit-group') === view;
                row.style.display = show ? '' : 'none';
                if (show) shown++;
            });
            if (noRows) noRows.style.display = shown ? 'none' : '';
            tabs.forEach(function(tab) {
                tab.classList.toggle('active', tab.getAttribute('data-profit-view') === view);
            });
        }

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                applyProfitView(tab.getAttribute('data-profit-view'));
            });
        });
        if (rows.length) applyProfitView('completed');
    })();
    </script>
</body>

</html>