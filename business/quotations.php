<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Quotations';
$activePage = 'quotes';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (empty($_SESSION['quotations_csrf_token'])) {
  $_SESSION['quotations_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['quotations_csrf_token'];

/*
|--------------------------------------------------------------------------
| Quotations permissions
|--------------------------------------------------------------------------
| - Page requires quotation/quotes VIEW permission.
| - Add/Create controls require CREATE permission.
| - Direct user permission overrides role permission.
| - A direct/role DENY takes precedence over the matching ALLOW at that level.
| - Supports both module codes used by existing FieldPlx quotation installs:
|   "quotations" (current), plus legacy "quotation" / "quotes".
|--------------------------------------------------------------------------
*/
$quotationCanView = false;
$quotationCanCreate = false;
$quotationCanUpdate = false;
$quotationCanDelete = false;

function quotationUserCan(PDO $pdo, $tenantId, $userId, $actionCode)
{
  $tenantId = (int) $tenantId;
  $userId = (int) $userId;
  $actionCode = strtolower(trim((string) $actionCode));

  if ($tenantId <= 0 || $userId <= 0 || $actionCode === '') {
    return false;
  }

  $userStmt = $pdo->prepare(
    "SELECT id, role_id
     FROM users
     WHERE id = :user_id
       AND tenant_id = :tenant_id
       AND deleted_at IS NULL
     LIMIT 1"
  );
  $userStmt->execute(array(
    ':user_id' => $userId,
    ':tenant_id' => $tenantId
  ));
  $user = $userStmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    return false;
  }

  $roleId = !empty($user['role_id']) ? (int) $user['role_id'] : 0;

  $permissionStmt = $pdo->prepare(
    "SELECT p.id
     FROM permissions p
     INNER JOIN modules m ON m.id = p.module_id
     WHERE m.module_code IN ('quotations', 'quotation', 'quotes')
       AND m.is_active = 1
       AND LOWER(p.action_code) = :action_code"
  );
  $permissionStmt->execute(array(':action_code' => $actionCode));
  $permissionIds = $permissionStmt->fetchAll(PDO::FETCH_COLUMN);

  if (!$permissionIds) {
    return false;
  }

  $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));
  $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));

  /* Direct user permission has the highest priority. */
  $userPermissionStmt = $pdo->prepare(
    "SELECT access_type
     FROM user_permissions
     WHERE tenant_id = ?
       AND user_id = ?
       AND permission_id IN ($placeholders)
     ORDER BY CASE access_type WHEN 'deny' THEN 0 ELSE 1 END, permission_id ASC"
  );
  $params = array_merge(array($tenantId, $userId), $permissionIds);
  $userPermissionStmt->execute($params);
  $directPermissions = $userPermissionStmt->fetchAll(PDO::FETCH_COLUMN);

  if ($directPermissions) {
    foreach ($directPermissions as $accessType) {
      if ($accessType === 'deny') {
        return false;
      }
    }
    foreach ($directPermissions as $accessType) {
      if ($accessType === 'allow') {
        return true;
      }
    }
  }

  if ($roleId <= 0) {
    return false;
  }

  $rolePermissionStmt = $pdo->prepare(
    "SELECT access_type
     FROM role_permissions
     WHERE tenant_id = ?
       AND role_id = ?
       AND permission_id IN ($placeholders)
     ORDER BY CASE access_type WHEN 'deny' THEN 0 ELSE 1 END, permission_id ASC"
  );
  $params = array_merge(array($tenantId, $roleId), $permissionIds);
  $rolePermissionStmt->execute($params);
  $rolePermissions = $rolePermissionStmt->fetchAll(PDO::FETCH_COLUMN);

  if (!$rolePermissions) {
    return false;
  }

  foreach ($rolePermissions as $accessType) {
    if ($accessType === 'deny') {
      return false;
    }
  }

  foreach ($rolePermissions as $accessType) {
    if ($accessType === 'allow') {
      return true;
    }
  }

  return false;
}

try {
  $permissionTenantId = isset($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : 0;
  $permissionUserId = !empty($_SESSION['tenant_user_id'])
    ? (int) $_SESSION['tenant_user_id']
    : (!empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0);

  if ((!isset($pdo) || !($pdo instanceof PDO)) && isset($db) && $db instanceof PDO) {
    $pdo = $db;
  }

  if ($permissionTenantId > 0 && $permissionUserId > 0 && isset($pdo) && $pdo instanceof PDO) {
    $quotationCanView = quotationUserCan($pdo, $permissionTenantId, $permissionUserId, 'view');
    $quotationCanCreate = quotationUserCan($pdo, $permissionTenantId, $permissionUserId, 'create');
    $quotationCanUpdate = quotationUserCan($pdo, $permissionTenantId, $permissionUserId, 'update');
    $quotationCanDelete = quotationUserCan($pdo, $permissionTenantId, $permissionUserId, 'delete');
  }
} catch (Throwable $permissionException) {
  error_log('FieldPlx quotation permission check failed: ' . $permissionException->getMessage());
  $quotationCanView = false;
  $quotationCanCreate = false;
}

if (!$quotationCanView) {
  http_response_code(403);
  exit('You do not have permission to view quotations.');
}

/*
|--------------------------------------------------------------------------
| Quotations page statistics
|--------------------------------------------------------------------------
| Statistics are loaded directly on this page from the database.
| No separate statistics API request is used.
|--------------------------------------------------------------------------
*/
$quotationStats = array(
  'draft' => 0,
  'awaiting_response' => 0,
  'changes_requested' => 0,
  'approved' => 0,
  'new_quotes_30' => 0,
  'previous_new_quotes_30' => 0,
  'converted_quote_cohort_30' => 0,
  'conversion_rate_30' => 0.0,
  'previous_conversion_rate_30' => 0.0,
  'conversion_rate_change_percent' => 0.0,
  'sent_30' => 0,
  'previous_sent_30' => 0,
  'sent_amount_30' => 0.0,
  'previous_sent_amount_30' => 0.0,
  'sent_change_percent' => 0.0,
  'converted_30' => 0,
  'previous_converted_30' => 0,
  'converted_amount_30' => 0.0,
  'previous_converted_amount_30' => 0.0,
  'converted_change_percent' => 0.0,
  'total_quotes' => 0
);

$quotationPeriods = array(
  'previous_label' => '',
  'current_label' => ''
);

$quotationConvertedQuotes = array();
$quotationCurrency = array(
  'currency_code' => 'INR',
  'currency_name' => 'Indian Rupee',
  'symbol' => '₹',
  'symbol_position' => 'before',
  'decimal_places' => 2,
  'decimal_separator' => '.',
  'thousand_separator' => ','
);

function quotationPercentChange($current, $previous)
{
  $current = (float) $current;
  $previous = (float) $previous;

  if (abs($previous) < 0.0000001) {
    return $current > 0 ? 100.0 : 0.0;
  }

  return (($current - $previous) / abs($previous)) * 100.0;
}

function quotationMoney($value, $currency)
{
  $amount = (float) $value;
  $places = isset($currency['decimal_places']) ? (int) $currency['decimal_places'] : 2;
  $decimalSeparator = isset($currency['decimal_separator']) && $currency['decimal_separator'] !== ''
    ? (string) $currency['decimal_separator']
    : '.';
  $thousandSeparator = isset($currency['thousand_separator'])
    ? (string) $currency['thousand_separator']
    : ',';
  $symbol = isset($currency['symbol']) ? (string) $currency['symbol'] : '';
  $formatted = number_format($amount, $places, $decimalSeparator, $thousandSeparator);

  if (isset($currency['symbol_position']) && $currency['symbol_position'] === 'after') {
    return $formatted . ($symbol !== '' ? ' ' . $symbol : '');
  }

  return $symbol . $formatted;
}

function quotationTrendClass($value)
{
  $value = (float) $value;
  if ($value > 0) {
    return ' up';
  }
  if ($value < 0) {
    return ' down';
  }
  return '';
}

function quotationTrendText($value)
{
  $value = (float) $value;
  $prefix = $value > 0 ? '↑ ' : ($value < 0 ? '↓ ' : '');
  return $prefix . number_format(abs($value), 0) . '%';
}

try {
  $tenantId = isset($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : 0;
  $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : 0;

  if ((!isset($pdo) || !($pdo instanceof PDO)) && isset($db) && $db instanceof PDO) {
    $pdo = $db;
  }

  if ($tenantId > 0 && isset($pdo) && $pdo instanceof PDO) {
    $currencyStmt = $pdo->prepare(
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
    $currencyStmt->execute(array(
      ':branch_id' => $branchId > 0 ? $branchId : -1,
      ':tenant_id' => $tenantId
    ));
    $currencyRow = $currencyStmt->fetch(PDO::FETCH_ASSOC);
    if ($currencyRow && !empty($currencyRow['currency_code'])) {
      $quotationCurrency = $currencyRow;
      $quotationCurrency['decimal_places'] = (int) $quotationCurrency['decimal_places'];
    }

    $currentStart = date('Y-m-d', strtotime('-29 days'));
    $currentEnd = date('Y-m-d');
    $previousStart = date('Y-m-d', strtotime('-59 days'));
    $previousEnd = date('Y-m-d', strtotime('-30 days'));

    $quotationPeriods['previous_label'] = date('M j', strtotime($previousStart)) . ' - ' . date('M j', strtotime($previousEnd));
    $quotationPeriods['current_label'] = date('M j', strtotime($currentStart)) . ' - ' . date('M j', strtotime($currentEnd));

    $overviewStmt = $pdo->prepare(
      "SELECT
          COUNT(*) AS total_quotes,
          SUM(status = 'draft') AS draft,
          SUM(status IN ('sent','viewed')) AS awaiting_response,
          SUM(status = 'changes_requested') AS changes_requested,
          SUM(status = 'approved') AS approved
       FROM quotes
       WHERE tenant_id = :tenant_id"
    );
    $overviewStmt->execute(array(':tenant_id' => $tenantId));
    $overview = $overviewStmt->fetch(PDO::FETCH_ASSOC) ?: array();

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
    $quotePeriods = $quotePeriodStmt->fetch(PDO::FETCH_ASSOC) ?: array();

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
    $sent = $sentStmt->fetch(PDO::FETCH_ASSOC) ?: array();

    $convertedCurrentStmt = $pdo->prepare(
      "SELECT
          COUNT(*) AS converted_30,
          COALESCE(SUM(q.total), 0) AS converted_amount_30
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
    $convertedCurrent = $convertedCurrentStmt->fetch(PDO::FETCH_ASSOC) ?: array();

    $convertedPreviousStmt = $pdo->prepare(
      "SELECT
          COUNT(*) AS converted_30,
          COALESCE(SUM(q.total), 0) AS converted_amount_30
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
    $convertedPrevious = $convertedPreviousStmt->fetch(PDO::FETCH_ASSOC) ?: array();

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
    $convertedQuoteCohort30 = (int) $cohortCurrentStmt->fetchColumn();

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
    $convertedQuoteCohortPrevious = (int) $cohortPreviousStmt->fetchColumn();

    $newQuotes30 = isset($quotePeriods['new_quotes_30']) ? (int) $quotePeriods['new_quotes_30'] : 0;
    $previousNewQuotes30 = isset($quotePeriods['previous_new_quotes_30']) ? (int) $quotePeriods['previous_new_quotes_30'] : 0;
    $conversionRate30 = $newQuotes30 > 0 ? ($convertedQuoteCohort30 / $newQuotes30) * 100.0 : 0.0;
    $previousConversionRate30 = $previousNewQuotes30 > 0 ? ($convertedQuoteCohortPrevious / $previousNewQuotes30) * 100.0 : 0.0;
    $sent30 = isset($sent['sent_30']) ? (int) $sent['sent_30'] : 0;
    $previousSent30 = isset($sent['previous_sent_30']) ? (int) $sent['previous_sent_30'] : 0;
    $converted30 = isset($convertedCurrent['converted_30']) ? (int) $convertedCurrent['converted_30'] : 0;
    $previousConverted30 = isset($convertedPrevious['converted_30']) ? (int) $convertedPrevious['converted_30'] : 0;

    $quotationStats = array(
      'draft' => isset($overview['draft']) ? (int) $overview['draft'] : 0,
      'awaiting_response' => isset($overview['awaiting_response']) ? (int) $overview['awaiting_response'] : 0,
      'changes_requested' => isset($overview['changes_requested']) ? (int) $overview['changes_requested'] : 0,
      'approved' => isset($overview['approved']) ? (int) $overview['approved'] : 0,
      'new_quotes_30' => $newQuotes30,
      'previous_new_quotes_30' => $previousNewQuotes30,
      'converted_quote_cohort_30' => $convertedQuoteCohort30,
      'conversion_rate_30' => round($conversionRate30, 2),
      'previous_conversion_rate_30' => round($previousConversionRate30, 2),
      'conversion_rate_change_percent' => round(quotationPercentChange($conversionRate30, $previousConversionRate30), 2),
      'sent_30' => $sent30,
      'previous_sent_30' => $previousSent30,
      'sent_amount_30' => isset($sent['sent_amount_30']) ? (float) $sent['sent_amount_30'] : 0.0,
      'previous_sent_amount_30' => isset($sent['previous_sent_amount_30']) ? (float) $sent['previous_sent_amount_30'] : 0.0,
      'sent_change_percent' => round(quotationPercentChange($sent30, $previousSent30), 2),
      'converted_30' => $converted30,
      'previous_converted_30' => $previousConverted30,
      'converted_amount_30' => isset($convertedCurrent['converted_amount_30']) ? (float) $convertedCurrent['converted_amount_30'] : 0.0,
      'previous_converted_amount_30' => isset($convertedPrevious['converted_amount_30']) ? (float) $convertedPrevious['converted_amount_30'] : 0.0,
      'converted_change_percent' => round(quotationPercentChange($converted30, $previousConverted30), 2),
      'total_quotes' => isset($overview['total_quotes']) ? (int) $overview['total_quotes'] : 0
    );

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
    $quotationConvertedQuotes = $convertedListStmt->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {
  error_log('FieldPlx quotations page stats error: ' . $e->getMessage());
}
$quoteImportAvailable = is_file(__DIR__ . '/quotation-import.php');

require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ==========================================================
       Quotes Manage - Clients page UI + Jobber quote behavior
       Version 5.0.0 - 2026-09-09
       ========================================================== */
    :root{
      --ql-green:#2f8d22;
      --ql-green-dark:#26751c;
      --ql-green-soft:#eaf4e6;
      --ql-navy:#00263a;
      --ql-text:#183445;
      --ql-muted:#647787;
      --ql-border:#dce3e7;
      --ql-pill:#eceae6;
      --ql-bg:#ffffff;
      --ql-danger:#e24234;
      --ql-blue:#2f83c6;
      --ql-yellow:#d9b520;
      --ql-gray:#557382;
    }
        .fd-dashboard.ql-page{max-width:1600px;padding:18px 20px 34px;background:#fff}
    .ql-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:26px}
    .ql-title{margin:0;color:var(--ql-navy);font-size:30px;line-height:1.1;font-weight:800;letter-spacing:-.7px}
    .ql-header-actions{display:flex;align-items:center;gap:8px}
    .ql-btn{min-height:40px;padding:0 16px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--ql-border);border-radius:8px;background:#fff;color:var(--ql-text);font:700 12px Arial,Helvetica,sans-serif;cursor:pointer;text-decoration:none!important}
    .ql-btn:hover{border-color:#cbd9c0;background:#f9fcf4;color:var(--ql-green-dark)}
    .ql-btn.primary{border-color:var(--ql-green);background:var(--ql-green);color:#fff}
    .ql-btn.primary:hover{border-color:var(--ql-green-dark);background:var(--ql-green-dark);color:#fff}
    .ql-btn.danger{border-color:#f2c8c4;color:#c83b32;background:#fff}
    .ql-btn:disabled{opacity:.55;cursor:not-allowed}
    .ql-more-wrap{position:relative}
    .ql-menu{width:190px;position:absolute;top:calc(100% + 7px);right:0;z-index:300;display:none;padding:6px;border:1px solid var(--ql-border);border-radius:9px;background:#fff;box-shadow:0 12px 28px rgba(0,38,58,.14)}
    .ql-more-wrap.open .ql-menu{display:block}
    .ql-menu-item{width:100%;min-height:38px;padding:8px 10px;display:flex;align-items:center;gap:9px;border:0;border-radius:7px;background:transparent;color:var(--ql-text);font:600 11px Arial,Helvetica,sans-serif;text-align:left;text-decoration:none!important;cursor:pointer}
    .ql-menu-item:hover{background:#f4f3ef;color:var(--ql-text)}
    .ql-menu-item.danger{color:#cf4a43}
    .ql-menu-sep{height:1px;margin:5px 2px;background:#edf0f2}

    .ql-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:28px}
    .ql-card{min-height:142px;position:relative;padding:15px 16px;border:1px solid var(--ql-border);border-radius:8px;background:#fff;overflow:visible}
    .ql-card-title{margin:0;color:var(--ql-navy);font-size:15px;line-height:1.2;font-weight:700}
    .ql-card-sub{margin-top:5px;color:var(--ql-muted);font-size:11px}
    .ql-card-arrow{position:absolute;top:16px;right:15px;color:#365969;font-size:13px}
    .ql-overview{margin:8px 0 0;padding:0;display:grid;gap:4px;list-style:none}
    .ql-overview li{display:flex;align-items:center;gap:7px;color:#405c6d;font-size:11px;line-height:1.25}
    .ql-overview strong{margin-left:auto;font-weight:400;color:#405c6d}
    .ql-dot{width:7px;height:7px;flex:0 0 7px;border-radius:50%;background:var(--ql-gray)}
    .ql-dot.draft{background:#557382}.ql-dot.awaiting{background:#d9b520}.ql-dot.changes{background:#e24a3f}.ql-dot.approved{background:#3b8d2e}.ql-dot.converted{background:#5aa5e8}.ql-dot.internal{background:#7f72c7}.ql-dot.rejected{background:#e24a3f}.ql-dot.expired,.ql-dot.archived{background:#8c98a1}
    .ql-metric-main{margin-top:24px}
    .ql-metric-row{display:flex;align-items:center;gap:9px}
    .ql-metric-value{color:#082c3c;font-size:32px;line-height:1;font-weight:700;letter-spacing:-1px}
    .ql-metric-amount{display:block;margin-top:7px;color:#526d7a;font-size:11px}
    .ql-trend{position:relative;min-height:25px;padding:4px 8px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#eef2f3;color:#5c7280;font-size:11px;font-weight:700;cursor:default;outline:0}
    .ql-trend.up{background:#eff6e8;color:#4e7a2d}.ql-trend.down{background:#fff0f1;color:#bd4b52}
    .ql-tooltip{min-width:190px;max-width:260px;position:absolute;left:50%;bottom:calc(100% + 9px);z-index:400;padding:11px 13px;border:1px solid #d7e0e4;border-radius:8px;background:#fff;box-shadow:0 7px 22px rgba(0,17,49,.16);opacity:0;visibility:hidden;transform:translate(-50%,5px);transition:.15s ease;pointer-events:none}
    .ql-tooltip:after{width:10px;height:10px;position:absolute;left:50%;bottom:-6px;border-right:1px solid #d7e0e4;border-bottom:1px solid #d7e0e4;background:#fff;content:"";transform:translateX(-50%) rotate(45deg)}
    .ql-trend:hover .ql-tooltip,.ql-trend:focus .ql-tooltip{opacity:1;visibility:visible;transform:translate(-50%,0)}
    .ql-tooltip-title{display:block;margin-bottom:7px;color:#627786;font-size:10px;font-weight:600}
    .ql-tooltip-row{display:flex;justify-content:space-between;gap:14px;min-height:20px;color:#4a6472;font-size:10px;white-space:nowrap}
    .ql-tooltip-row strong{color:#153a49;font-weight:700}

    .ql-section-title-row{display:flex;align-items:baseline;gap:8px;margin:0 0 17px}
    .ql-section-title-row h2{margin:0;color:var(--ql-navy);font-size:19px;font-weight:700}
    .ql-result-count{color:var(--ql-muted);font-size:12px}
    .ql-toolbar{display:flex;align-items:center;gap:8px;margin-bottom:11px;position:relative;z-index:80}
    .ql-filter-wrap{position:relative}
    .ql-filter-pill{min-height:38px;padding:0 14px;display:inline-flex;align-items:center;gap:8px;border:0;border-radius:999px;background:var(--ql-pill);color:#173846;font:700 12px Arial,Helvetica,sans-serif;cursor:pointer}
    .ql-filter-pill .ql-filter-value{font-weight:400}
    .ql-filter-popup{width:260px;position:absolute;top:calc(100% + 7px);left:0;z-index:500;display:none;padding:8px;border:1px solid var(--ql-border);border-radius:9px;background:#fff;box-shadow:0 12px 28px rgba(0,38,58,.15)}
    .ql-filter-wrap.open .ql-filter-popup{display:block}
    .ql-filter-search{width:100%;height:39px;margin-bottom:6px;padding:0 11px;border:1px solid var(--ql-border);border-radius:7px;outline:0;color:var(--ql-text);font:12px Arial,Helvetica,sans-serif}
    .ql-filter-search:focus{border-color:#9bc778;box-shadow:0 0 0 2px rgba(47,141,34,.08)}
    .ql-status-options{max-height:285px;overflow:auto}
    .ql-filter-option{width:100%;min-height:39px;padding:7px 9px;display:flex;align-items:center;gap:9px;border:0;border-radius:7px;background:transparent;color:var(--ql-text);font:500 12px Arial,Helvetica,sans-serif;text-align:left;cursor:pointer}
    .ql-filter-option:hover,.ql-filter-option.active{background:#f1efeb}
    .ql-filter-option .ql-check{margin-left:auto;font-size:14px}
    .ql-filter-option .ql-status-count{margin-left:auto;color:#6e808b;font-size:10px}
    .ql-filter-option.active .ql-status-count{margin-left:auto}
    .ql-date-popup{width:300px}
    .ql-date-custom{display:none;padding:8px 7px 4px;border-top:1px solid #edf0f2;margin-top:5px}
    .ql-date-custom.show{display:block}
    .ql-date-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px}
    .ql-date-grid label{display:grid;gap:4px;color:#607581;font-size:9px;font-weight:700}
    .ql-date-grid input{width:100%;height:37px;padding:0 8px;border:1px solid var(--ql-border);border-radius:7px;color:var(--ql-text);font:11px Arial,Helvetica,sans-serif}
    .ql-date-apply{margin-top:8px;width:100%}
    .ql-toolbar-spacer{margin-left:auto}
    .ql-search{width:255px;position:relative}
    .ql-search i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#6f8590;font-size:15px}
    .ql-search input{width:100%;height:45px;padding:0 13px 0 39px;border:1px solid var(--ql-border);border-radius:8px;background:#fff;color:var(--ql-text);font:12px Arial,Helvetica,sans-serif;outline:0}
    .ql-search input:focus{border-color:#9bc778;box-shadow:0 0 0 2px rgba(47,141,34,.08)}

    .ql-selection-bar{min-height:48px;display:none;align-items:center;gap:14px;padding:5px 8px;border-bottom:1px solid var(--ql-border);color:var(--ql-text);background:#fff}
    .ql-selection-bar.show{display:flex}
    .ql-selection-count{font-size:11px;font-weight:700;white-space:nowrap}
    .ql-selection-clear{padding:0;border:0;background:transparent;color:var(--ql-green-dark);font-size:11px;font-weight:700;text-decoration:underline;cursor:pointer}
    .ql-selection-more-wrap{position:relative}
    .ql-selection-icon{width:34px;height:34px;display:grid;place-items:center;border:0;border-radius:7px;background:transparent;color:#173c50;font-size:17px;cursor:pointer}
    .ql-selection-icon:hover{background:#f4f3ef}
    .ql-selection-menu{width:150px;position:absolute;top:calc(100% + 5px);left:0;z-index:300;display:none;padding:5px;border:1px solid var(--ql-border);border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(0,38,58,.14)}
    .ql-selection-more-wrap.open .ql-selection-menu{display:block}

    .ql-table-wrap{position:relative;overflow:visible;border-top:1px solid transparent}
    .ql-table{width:100%;border-collapse:collapse;table-layout:fixed}
    .ql-table th{height:42px;padding:0 8px;border-bottom:1px solid #d8e1e5;background:#fff;color:#46616e;font-size:11.5px;font-weight:400;text-align:left;vertical-align:middle}
    .ql-table td{height:58px;padding:0 8px;border-bottom:1px solid #dde5e9;background:#fff;color:#314f5d;font-size:12px;vertical-align:middle}
    .ql-table tbody tr{transition:background .12s ease}
    .ql-table tbody tr:hover td,.ql-table tbody tr:focus-within td,.ql-table tbody tr.is-selected td{background:#f4f3ef}
    .ql-col-check{width:34px}.ql-col-customer{width:22%}.ql-col-number{width:16%}.ql-col-property{width:25%}.ql-col-created{width:12%}.ql-col-status{width:15%}.ql-col-total{width:10%}
    .ql-checkbox{width:18px;height:18px;accent-color:var(--ql-green);cursor:pointer}
    .ql-sort{padding:0;border:0;background:transparent;color:inherit;font:inherit;cursor:pointer}
    .ql-sort i{margin-left:3px;color:#9aacb5;font-size:11px}
    .ql-sort.active i{color:#365969}
    .ql-customer{color:#123845;font-weight:700;text-decoration:none!important}
    .ql-quote-number{display:block;color:#244452;font-weight:400}.ql-quote-title{display:block;margin-top:3px;color:#244452;font-weight:400;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ql-property{display:-webkit-box;overflow:hidden;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.3}
    .ql-created{line-height:1.25}.ql-money{font-weight:700;text-align:right;white-space:nowrap}
    .ql-badge{min-height:20px;max-width:100%;padding:2px 7px;display:inline-flex;align-items:center;justify-content:flex-start;gap:5px;border-radius:999px;background:#edf1f2;color:#355463;font-size:9.5px;line-height:1;font-weight:400;white-space:nowrap}
    .ql-badge:before{width:6px;height:6px;min-width:6px;flex:0 0 6px;border-radius:50%;background:#557382;content:""}
    .ql-table td:nth-child(6),.ql-table th:nth-child(6){white-space:nowrap;overflow:visible}
    .ql-badge.awaiting_response,.ql-badge.sent,.ql-badge.viewed{background:#fbf2ce;color:#6f5b13}.ql-badge.awaiting_response:before,.ql-badge.sent:before,.ql-badge.viewed:before{background:#d0ae19}
    .ql-badge.changes_requested,.ql-badge.rejected,.ql-badge.expired{background:#fff0f1;color:#a44349}.ql-badge.changes_requested:before,.ql-badge.rejected:before,.ql-badge.expired:before{background:#df525c}
    .ql-badge.approved{background:#edf6e8;color:#457433}.ql-badge.approved:before{background:#4d962f}
    .ql-badge.converted{background:#edf5fb;color:#3f7192}.ql-badge.converted:before{background:#5aa5e8}
    .ql-badge.archived{background:#f0f2f3;color:#667985}.ql-badge.archived:before{background:#8f9da5}
    .ql-badge.internal_approval{background:#f2efff;color:#5c4e9a}.ql-badge.internal_approval:before{background:#7f72c7}
    .ql-total-cell{position:relative;padding-right:78px!important;text-align:right}
    .ql-row-actions{position:absolute;right:5px;top:50%;display:inline-flex;gap:4px;opacity:0;visibility:hidden;transform:translateY(-50%);transition:.12s ease}
    .ql-table tr:hover .ql-row-actions,.ql-table tr:focus-within .ql-row-actions{opacity:1;visibility:visible}
    .ql-icon-btn{width:31px;height:31px;display:grid;place-items:center;border:1px solid #d7e0e4;border-radius:7px;background:#fff;color:#31505d;font-size:14px;cursor:pointer}
    .ql-icon-btn:hover{border-color:#c7d8bc;background:#f7fbf2;color:var(--ql-green-dark)}
    .ql-icon-btn:disabled{opacity:.4;cursor:not-allowed}
    .ql-row-menu{width:165px;position:fixed;z-index:15000;display:none;padding:5px;border:1px solid var(--ql-border);border-radius:8px;background:#fff;box-shadow:0 12px 28px rgba(0,38,58,.16)}
    .ql-row-menu.show{display:block}
    .ql-row-menu .ql-menu-item{font-size:11px;min-height:38px}
    .ql-empty{padding:30px 18px!important;text-align:center;color:#8b9aa4!important;font-size:11px!important}
    .ql-pagination{min-height:48px;padding:10px 2px;display:flex;align-items:center;justify-content:space-between;gap:10px;color:#768994;font-size:10px}
    .ql-pagination-actions{display:flex;gap:5px}

    .ql-modal-backdrop{position:fixed;inset:0;z-index:20000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,17,49,.42)}
    .ql-modal-backdrop.show{display:flex}
    .ql-modal{width:min(930px,calc(100vw - 34px));max-height:calc(100vh - 34px);overflow:auto;border:1px solid var(--ql-border);border-radius:10px;background:#fff;box-shadow:0 24px 65px rgba(0,17,49,.24)}
    .ql-modal.small{width:min(500px,calc(100vw - 34px))}
    .ql-modal-head{padding:20px 24px 12px;display:flex;align-items:center;justify-content:space-between;gap:14px}
    .ql-modal-head h3{margin:0;color:var(--ql-navy);font-size:22px;font-weight:700}
    .ql-modal-close{width:34px;height:34px;border:0;border-radius:7px;background:transparent;color:#31505d;font-size:20px;cursor:pointer}
    .ql-modal-close:hover{background:#f4f3ef}
    .ql-email-body{padding:0 24px 14px;display:grid;grid-template-columns:minmax(0,1.7fr) minmax(250px,.8fr);gap:24px}
    .ql-email-left{display:grid;gap:12px}
    .ql-email-to{min-height:58px;padding:7px 10px;display:flex;align-items:center;gap:7px;flex-wrap:wrap;border:1px solid var(--ql-border);border-radius:8px}
    .ql-email-label{color:#526d7a;font-size:10px;font-weight:700}
    .ql-email-chips{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
    .ql-email-chip{min-height:34px;padding:0 10px;display:inline-flex;align-items:center;gap:8px;border:1px solid var(--ql-border);border-radius:999px;color:#355463;background:#fff;font-size:11px}
    .ql-email-chip button{border:0;background:transparent;color:#6d818c;cursor:pointer}
    .ql-email-recipient-input{min-width:150px;flex:1;height:34px;border:0;outline:0;color:#355463;font:11px Arial,Helvetica,sans-serif}
    .ql-email-field{position:relative}
    .ql-email-field label{position:absolute;left:12px;top:7px;z-index:1;color:#647b87;font-size:9px}
    .ql-email-field input,.ql-email-field textarea{width:100%;border:1px solid var(--ql-border);border-radius:8px;color:#183f50;background:#fff;font:11.5px Arial,Helvetica,sans-serif;outline:0}
    .ql-email-field input{height:50px;padding:20px 12px 7px}.ql-email-field textarea{min-height:270px;padding:22px 12px 10px;resize:vertical;line-height:1.55}
    .ql-email-field input:focus,.ql-email-field textarea:focus{border-color:#9bc778;box-shadow:0 0 0 2px rgba(47,141,34,.08)}
    .ql-email-help{margin-top:-7px;color:#748792;font-size:9px;line-height:1.35}
    .ql-email-side h4{margin:3px 0 12px;color:var(--ql-navy);font-size:13px;font-weight:700}
    .ql-email-drop{min-height:92px;padding:12px;display:grid;place-items:center;border:1px dashed #d5dee2;border-radius:8px;text-align:center;color:#607581;background:#fff;font-size:10px}
    .ql-email-drop.drag{border-color:#8cbc68;background:#f8fcf4}
    .ql-email-select{min-height:34px;padding:0 12px;border:1px solid var(--ql-border);border-radius:7px;background:#fff;color:var(--ql-green-dark);font:700 10px Arial,Helvetica,sans-serif;cursor:pointer}
    .ql-email-size{margin-top:12px;color:#607581;font-size:9px}.ql-email-progress{height:7px;margin-top:5px;border-radius:999px;overflow:hidden;background:#dedcd4}.ql-email-progress span{width:0;height:100%;display:block;background:var(--ql-green)}
    .ql-email-file-list{margin-top:10px;display:grid;gap:7px}
    .ql-email-file{min-height:48px;padding:6px 8px;display:grid;grid-template-columns:40px minmax(0,1fr) auto;align-items:center;gap:8px;border:1px solid var(--ql-border);border-radius:7px;background:#fff;color:#405c6d;font-size:9.5px}
    .ql-email-file-icon{width:40px;height:38px;display:grid;place-items:center;border-radius:6px;background:#eeeae5;color:#d84432;font-size:18px}.ql-email-file strong,.ql-email-file small{display:block}.ql-email-file strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:10px}.ql-email-file small{margin-top:2px;color:#83939d;font-size:8.5px}.ql-email-file button{width:28px;height:28px;border:0;border-radius:6px;background:transparent;color:#687f8e;cursor:pointer}.ql-email-file button:hover{background:#f4f3ef;color:#173c50}
    .ql-email-footer{padding:0 24px 20px;display:flex;align-items:center;justify-content:space-between;gap:14px}.ql-email-copy{display:inline-flex;align-items:center;gap:8px;color:#405c6d;font-size:11px}.ql-email-copy input{width:17px;height:17px;accent-color:var(--ql-green)}.ql-email-actions{display:flex;gap:8px}
    .ql-confirm-body{padding:8px 24px 22px;color:#405c6d;font-size:12px;line-height:1.55}.ql-confirm-footer{padding:0 24px 20px;display:flex;justify-content:flex-end;gap:8px}

    .ql-toast{width:min(360px,calc(100vw - 24px));position:fixed;top:82px;right:16px;z-index:25000;padding:10px 11px;display:flex;align-items:center;gap:8px;border-radius:8px;color:#fff;box-shadow:0 10px 26px rgba(0,17,49,.18);opacity:0;visibility:hidden;transform:translateY(-8px);transition:.18s}.ql-toast.show{opacity:1;visibility:visible;transform:translateY(0)}.ql-toast.success{background:#2f8d22}.ql-toast.error{background:#cf4a43}.ql-toast.warning{background:#9b7b17}.ql-toast.info{background:#173c50}.ql-toast span{flex:1;font-size:11px}

    @media(max-width:1240px){.ql-cards{grid-template-columns:repeat(4,minmax(0,1fr))}}
    @media(max-width:991.98px){.fieldplx-main-content{padding-top:0!important}.ql-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.ql-property{-webkit-line-clamp:3}.ql-row-actions{opacity:1;visibility:visible}}
    @media(max-width:767.98px){.fieldplx-main-content{padding-top:0!important}.fd-dashboard.ql-page{padding:16px 13px 28px}.ql-header{align-items:flex-start}.ql-title{font-size:26px}.ql-cards{grid-template-columns:1fr}.ql-toolbar{flex-wrap:wrap}.ql-toolbar-spacer{display:none}.ql-search{width:100%;order:-1}.ql-filter-pill{min-height:36px}.ql-table thead{display:none}.ql-table,.ql-table tbody,.ql-table tr,.ql-table td{display:block;width:100%!important}.ql-table tr{position:relative;padding:12px 44px 12px 38px;border-bottom:1px solid var(--ql-border)}.ql-table td{height:auto;min-height:26px;padding:3px 0;border:0;background:transparent!important}.ql-table td.ql-check-cell{position:absolute;left:10px;top:12px}.ql-total-cell{padding-right:0!important;text-align:left}.ql-row-actions{right:7px;top:14px;transform:none}.ql-created{line-height:1.35}.ql-modal{width:min(620px,calc(100vw - 22px))}.ql-email-body{grid-template-columns:1fr;padding-left:16px;padding-right:16px;gap:16px}.ql-modal-head{padding-left:16px;padding-right:16px}.ql-email-footer{padding-left:16px;padding-right:16px;flex-direction:column;align-items:stretch}.ql-email-actions{justify-content:flex-end}.ql-email-field textarea{min-height:210px}.ql-filter-popup{position:fixed;left:12px;right:12px;top:135px;width:auto}.ql-date-popup{width:auto}}
    @media(max-width:520px){.ql-header{flex-direction:column}.ql-header-actions{width:100%}.ql-header-actions>*{flex:1}.ql-header-actions .ql-btn{width:100%}.ql-modal-head h3{font-size:18px}.ql-toast{top:72px;left:12px;right:12px;width:auto}}
  

    /* ==========================================================
       FieldPlx new application shell + reference-aligned Quotes UI
       ========================================================== */
    :root{
      --ql-green:var(--primary,#08a4d4);
      --ql-green-dark:var(--primary,#087fa6);
      --ql-green-soft:color-mix(in srgb,var(--primary,#08a4d4) 12%,white);
      --ql-navy:var(--text,#073248);
      --ql-text:var(--text,#173946);
      --ql-muted:var(--muted,#6c7f8a);
      --ql-border:var(--card-border,#dce5e9);
      --ql-pill:var(--button-bg,#eeece8);
      --ql-bg:var(--card-bg,#fff);
    }
    .ql-page{
      width:100%;
      max-width:none;
      padding:24px 26px 34px;
      background:transparent;
      color:var(--ql-text);
      font-family:inherit;
    }
    .ql-header{margin-bottom:24px;align-items:center}
    .ql-title{color:var(--text,#073248)!important;font-family:inherit;font-size:30px;font-weight:var(--font-weight-bold,700)}
    .ql-btn{border-color:var(--button-border,var(--ql-border));border-radius:11px;background:var(--button-bg,#fff);color:var(--text,#173946);font-family:inherit;font-weight:var(--font-weight-semibold,600);box-shadow:none}
    .ql-btn:hover{border-color:var(--primary,#08a4d4);background:var(--button-hover-bg,#f5fbfd);color:var(--primary,#08a4d4)}
    .ql-btn.primary{border-color:var(--primary,#08a4d4);background:var(--primary,#08a4d4);color:var(--primary-text,#fff)}
    .ql-btn.primary:hover{filter:brightness(.95);border-color:var(--primary,#08a4d4);background:var(--primary,#08a4d4)}
    .ql-menu,.ql-filter-popup,.ql-row-menu,.ql-modal{border-color:var(--card-border,var(--ql-border));background:var(--card-bg,#fff);box-shadow:0 18px 50px rgba(15,23,42,.14)}
    .ql-menu-item{color:var(--text,#173946);font-family:inherit}
    .ql-menu-item:hover{background:var(--table-hover-bg,#f5f7f8)}
    .ql-cards{
      grid-template-columns:minmax(235px,1.25fr) repeat(3,minmax(165px,.82fr)) minmax(300px,1.35fr);
      gap:8px;
      margin-bottom:28px;
    }
    .ql-card{min-height:136px;border-color:var(--card-border,var(--ql-border));border-radius:10px;background:var(--card-bg,#fff);box-shadow:var(--card-shadow,none)}
    .ql-card-title,.ql-section-title-row h2,.ql-modal-head h3{color:var(--text,#073248)!important;font-family:inherit}
    .ql-card-sub,.ql-result-count,.ql-metric-amount{color:var(--muted,#6c7f8a)!important}
    .ql-help-card{display:flex;flex-direction:column;justify-content:space-between;overflow:hidden}
    .ql-help-card p{max-width:440px;margin:5px 0 0;color:var(--muted,#6c7f8a);font-size:11px;line-height:1.45}
    .ql-help-link{width:max-content;padding:0;border:0;background:transparent;color:var(--primary,#08a4d4);font-size:11px;font-weight:700;cursor:pointer;text-decoration:none}
    .ql-help-link:hover{text-decoration:underline}
    .ql-toolbar{z-index:90}
    .ql-filter-pill{background:var(--button-bg,#eeece8);color:var(--text,#173946);font-family:inherit}
    .ql-search input,.ql-filter-search,.ql-date-grid input,.ql-email-field input,.ql-email-field textarea,.ql-email-recipient-input{border-color:var(--input-border,var(--ql-border));background:var(--input-bg,#fff);color:var(--text,#173946);font-family:inherit}
    .ql-search input:focus,.ql-filter-search:focus,.ql-date-grid input:focus,.ql-email-field input:focus,.ql-email-field textarea:focus{border-color:var(--primary,#08a4d4);box-shadow:0 0 0 3px color-mix(in srgb,var(--primary,#08a4d4) 12%,transparent)}
    .ql-table th{border-color:var(--table-border,var(--ql-border));background:var(--table-header-bg,var(--card-bg,#fff));color:var(--table-header-text,#4d6573)}
    .ql-table td{border-color:var(--table-border,var(--ql-border));background:var(--card-bg,#fff);color:var(--text,#173946)}
    .ql-table tbody tr:hover td,.ql-table tbody tr:focus-within td,.ql-table tbody tr.is-selected td{background:var(--table-hover-bg,#f5f7f8)}
    .ql-customer,.ql-quote-number,.ql-money{color:var(--text,#173946)!important}
    .ql-icon-btn{border-color:var(--button-border,var(--ql-border));background:var(--button-bg,#fff);color:var(--text,#173946)}
    .ql-icon-btn:hover{border-color:var(--primary,#08a4d4);background:var(--button-hover-bg,#f5fbfd);color:var(--primary,#08a4d4)}
    .ql-toast.success{background:#2f8d22}.ql-toast.info{background:#16485d}

    /* New quote modal */
    .ql-newquote-modal{width:min(560px,calc(100vw - 34px));overflow:hidden}
    .ql-newquote-body{padding:8px 24px 20px}
    .ql-template-panel{overflow:hidden;border:1px solid var(--ql-border);border-radius:9px;background:var(--card-bg,#fff)}
    .ql-template-panel-title{padding:13px 15px;border-bottom:1px solid var(--ql-border);color:var(--text,#173946);font-size:11px;font-weight:700}
    .ql-template-option{width:100%;min-height:52px;padding:11px 15px;display:flex;align-items:center;justify-content:space-between;gap:12px;border:0;border-bottom:1px solid var(--ql-border);background:transparent;color:var(--text,#173946);text-align:left;font:inherit;font-size:11.5px;cursor:pointer}
    .ql-template-option:last-child{border-bottom:0}.ql-template-option:hover{background:var(--table-hover-bg,#f5f7f8)}
    .ql-template-option small{display:block;margin-top:3px;color:var(--muted,#6c7f8a);font-size:9px}
    .ql-template-empty{padding:24px 16px;color:var(--muted,#6c7f8a);font-size:11px;text-align:center}
    .ql-or{margin:16px 0;display:flex;align-items:center;gap:12px;color:var(--muted,#6c7f8a);font-size:11px}.ql-or:before,.ql-or:after{height:1px;flex:1;background:var(--ql-border);content:""}
    .ql-create-blank{width:100%;min-height:42px}

    /* Templates drawer */
    .ql-drawer-backdrop{position:fixed;inset:0;z-index:28000;display:none;background:rgba(6,24,34,.18)}
    .ql-drawer-backdrop.show{display:block}
    .ql-template-drawer{width:min(390px,92vw);position:fixed;top:0;right:0;bottom:0;z-index:28100;display:flex;flex-direction:column;transform:translateX(103%);transition:transform .2s ease;border-left:1px solid var(--ql-border);background:var(--card-bg,#fff);box-shadow:-12px 0 36px rgba(15,23,42,.12)}
    .ql-template-drawer.show{transform:translateX(0)}
    .ql-drawer-head{min-height:72px;padding:18px 18px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--ql-border)}
    .ql-drawer-head h3{margin:0;color:var(--text,#073248);font-size:20px;font-weight:700}
    .ql-drawer-close{width:34px;height:34px;display:grid;place-items:center;border:0;border-radius:8px;background:transparent;color:var(--text,#173946);font-size:18px;cursor:pointer}.ql-drawer-close:hover{background:var(--table-hover-bg,#f5f7f8)}
    .ql-drawer-copy{padding:14px 18px 9px;color:var(--muted,#6c7f8a);font-size:11px;line-height:1.5}.ql-drawer-count{margin-top:3px}
    .ql-drawer-list{min-height:0;flex:1;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#b8c7cf transparent}
    .ql-drawer-list::-webkit-scrollbar{width:5px}.ql-drawer-list::-webkit-scrollbar-thumb{border-radius:999px;background:#b8c7cf}
    .ql-drawer-item{min-height:62px;padding:12px 18px;display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:10px;border-top:1px solid var(--ql-border);color:var(--text,#173946);background:transparent}
    .ql-drawer-item-name{font-size:11px;font-weight:700}.ql-drawer-item-meta{margin-top:3px;color:var(--muted,#6c7f8a);font-size:9.5px}
    .ql-drawer-item-actions{display:flex;gap:4px}.ql-drawer-mini{width:30px;height:30px;display:grid;place-items:center;border:1px solid var(--button-border,var(--ql-border));border-radius:7px;background:var(--button-bg,#fff);color:var(--text,#173946);cursor:pointer}.ql-drawer-mini:hover{border-color:var(--primary,#08a4d4);color:var(--primary,#08a4d4)}
    .ql-drawer-foot{padding:14px 14px 16px;border-top:1px solid var(--ql-border)}.ql-drawer-foot .ql-btn{width:100%}

    /* Create template modal */
    .ql-template-form{padding:6px 24px 20px;display:grid;gap:12px}.ql-form-group{display:grid;gap:6px}.ql-form-group label{color:var(--text,#173946);font-size:10px;font-weight:700}.ql-form-group input,.ql-form-group select,.ql-form-group textarea{width:100%;min-height:42px;padding:9px 11px;border:1px solid var(--input-border,var(--ql-border));border-radius:8px;background:var(--input-bg,#fff);color:var(--text,#173946);font:inherit;font-size:11px;outline:0}.ql-form-group textarea{min-height:82px;resize:vertical}.ql-form-group input:focus,.ql-form-group select:focus,.ql-form-group textarea:focus{border-color:var(--primary,#08a4d4);box-shadow:0 0 0 3px color-mix(in srgb,var(--primary,#08a4d4) 12%,transparent)}
    .ql-form-help{color:var(--muted,#6c7f8a);font-size:9px;line-height:1.45}

    html.app-dark-mode .ql-card,
    html.app-dark-mode .ql-menu,
    html.app-dark-mode .ql-filter-popup,
    html.app-dark-mode .ql-row-menu,
    html.app-dark-mode .ql-modal,
    html.app-dark-mode .ql-template-drawer,
    html.app-dark-mode .ql-template-panel{background:var(--card-bg,#17262f)!important;color:var(--text,#dce8ee)!important}
    html.app-dark-mode .ql-table th,html.app-dark-mode .ql-table td{background:var(--card-bg,#17262f)!important;color:var(--text,#dce8ee)!important}
    html.app-dark-mode .ql-table tbody tr:hover td,html.app-dark-mode .ql-table tbody tr.is-selected td{background:var(--table-hover-bg,#20343e)!important}
    html.app-dark-mode .ql-template-option:hover,html.app-dark-mode .ql-menu-item:hover{background:var(--table-hover-bg,#20343e)!important}

    @media(max-width:1360px){.ql-cards{grid-template-columns:repeat(4,minmax(0,1fr))}.ql-help-card{grid-column:span 2}}
    @media(max-width:1060px){.ql-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.ql-help-card{grid-column:span 2}}
    @media(max-width:767.98px){.ql-page{padding:16px 13px 28px}.ql-help-card{grid-column:auto}.ql-template-drawer{width:min(390px,100vw)}}
    @media(max-width:520px){.ql-header{gap:12px}.ql-header-actions{display:grid;grid-template-columns:1fr 1fr}.ql-header-actions .ql-btn{min-width:0}.ql-more-wrap{width:100%}.ql-more-wrap>.ql-btn{width:100%}}

</style>

<div class="ql-page">
  <section class="ql-header">
    <h1 class="ql-title">Quotes</h1>
    <div class="ql-header-actions">
      <?php if ($quotationCanCreate): ?>
        <button type="button" class="ql-btn primary" id="newQuoteButton">New Quote</button>
      <?php endif; ?>
      <div class="ql-more-wrap" id="topMoreWrap">
        <button type="button" class="ql-btn" id="topMoreButton" aria-expanded="false"><i class="bi bi-three-dots"></i> More Actions</button>
        <div class="ql-menu" id="topMoreMenu" aria-hidden="true">
          <button type="button" class="ql-menu-item" id="openTemplates"><i class="bi bi-clipboard2-check"></i> Templates</button>
          <?php if ($quoteImportAvailable): ?>
            <a class="ql-menu-item" href="quotation-import.php"><i class="bi bi-file-earmark-arrow-up"></i> Import Quote Data</a>
          <?php else: ?>
            <button type="button" class="ql-menu-item" id="importUnavailable"><i class="bi bi-file-earmark-arrow-up"></i> Import Quote Data</button>
          <?php endif; ?>
          <div class="ql-menu-sep"></div>
          <button type="button" class="ql-menu-item" id="exportQuotes"><i class="bi bi-download"></i> Export Quotes</button>
        </div>
      </div>
    </div>
  </section>

  <section class="ql-cards">
    <article class="ql-card">
      <h2 class="ql-card-title">Overview</h2>
      <ul class="ql-overview">
        <li><span class="ql-dot draft"></span><span>Draft</span><strong id="statDraft">(<?= (int)$quotationStats['draft'] ?>)</strong></li>
        <li><span class="ql-dot awaiting"></span><span>Awaiting response</span><strong id="statAwaitingResponse">(<?= (int)$quotationStats['awaiting_response'] ?>)</strong></li>
        <li><span class="ql-dot changes"></span><span>Changes requested</span><strong id="statChangesRequested">(<?= (int)$quotationStats['changes_requested'] ?>)</strong></li>
        <li><span class="ql-dot approved"></span><span>Approved</span><strong id="statApproved">(<?= (int)$quotationStats['approved'] ?>)</strong></li>
      </ul>
    </article>

    <article class="ql-card">
      <span class="ql-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
      <h2 class="ql-card-title">Conversion rate <i class="bi bi-question-circle" style="font-size:11px;color:var(--ql-muted)"></i></h2>
      <div class="ql-card-sub">Past 30 days</div>
      <div class="ql-metric-main"><div class="ql-metric-row">
        <strong class="ql-metric-value"><?= number_format((float)$quotationStats['conversion_rate_30'],0) ?>%</strong>
        <span class="ql-trend<?= quotationTrendClass($quotationStats['conversion_rate_change_percent']) ?>" tabindex="0"><?= htmlspecialchars(quotationTrendText($quotationStats['conversion_rate_change_percent']),ENT_QUOTES,'UTF-8') ?>
          <span class="ql-tooltip"><span class="ql-tooltip-title">Conversion rate</span><span class="ql-tooltip-row"><span><?= htmlspecialchars($quotationPeriods['previous_label'],ENT_QUOTES,'UTF-8') ?></span><strong><?= number_format((float)$quotationStats['previous_conversion_rate_30'],0) ?>%</strong></span><span class="ql-tooltip-row"><span><?= htmlspecialchars($quotationPeriods['current_label'],ENT_QUOTES,'UTF-8') ?></span><strong><?= number_format((float)$quotationStats['conversion_rate_30'],0) ?>%</strong></span></span>
        </span>
      </div></div>
    </article>

    <article class="ql-card">
      <span class="ql-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
      <h2 class="ql-card-title">Sent</h2><div class="ql-card-sub">Past 30 days</div>
      <div class="ql-metric-main"><div class="ql-metric-row">
        <strong class="ql-metric-value"><?= (int)$quotationStats['sent_30'] ?></strong>
        <span class="ql-trend<?= quotationTrendClass($quotationStats['sent_change_percent']) ?>" tabindex="0"><?= htmlspecialchars(quotationTrendText($quotationStats['sent_change_percent']),ENT_QUOTES,'UTF-8') ?>
          <span class="ql-tooltip"><span class="ql-tooltip-title">Sent</span><span class="ql-tooltip-row"><span><?= htmlspecialchars($quotationPeriods['previous_label'],ENT_QUOTES,'UTF-8') ?></span><strong><?= (int)$quotationStats['previous_sent_30'] ?></strong></span><span class="ql-tooltip-row"><span><?= htmlspecialchars($quotationPeriods['current_label'],ENT_QUOTES,'UTF-8') ?></span><strong><?= (int)$quotationStats['sent_30'] ?></strong></span></span>
        </span>
      </div><span class="ql-metric-amount"><?= htmlspecialchars(quotationMoney($quotationStats['sent_amount_30'],$quotationCurrency),ENT_QUOTES,'UTF-8') ?></span></div>
    </article>

    <article class="ql-card">
      <span class="ql-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
      <h2 class="ql-card-title">Converted</h2><div class="ql-card-sub">Past 30 days</div>
      <div class="ql-metric-main"><div class="ql-metric-row">
        <strong class="ql-metric-value"><?= (int)$quotationStats['converted_30'] ?></strong>
        <span class="ql-trend<?= quotationTrendClass($quotationStats['converted_change_percent']) ?>" tabindex="0"><?= htmlspecialchars(quotationTrendText($quotationStats['converted_change_percent']),ENT_QUOTES,'UTF-8') ?>
          <span class="ql-tooltip"><span class="ql-tooltip-title">Converted</span><span class="ql-tooltip-row"><span><?= htmlspecialchars($quotationPeriods['previous_label'],ENT_QUOTES,'UTF-8') ?></span><strong><?= (int)$quotationStats['previous_converted_30'] ?></strong></span><span class="ql-tooltip-row"><span><?= htmlspecialchars($quotationPeriods['current_label'],ENT_QUOTES,'UTF-8') ?></span><strong><?= (int)$quotationStats['converted_30'] ?></strong></span></span>
        </span>
      </div><span class="ql-metric-amount"><?= htmlspecialchars(quotationMoney($quotationStats['converted_amount_30'],$quotationCurrency),ENT_QUOTES,'UTF-8') ?></span></div>
    </article>

    <article class="ql-card ql-help-card">
      <div>
        <h2 class="ql-card-title">How can I get paid faster?</h2>
        <p>Use clear payment terms, deposits, and online payment options so customers can act on approved quotes without delay.</p>
      </div>
      <a class="ql-help-link" href="settings.php">Review payment settings</a>
    </article>
  </section>

  <section>
    <div class="ql-section-title-row"><h2>All quotes</h2><span class="ql-result-count" id="resultCount">(0 results)</span></div>
    <div class="ql-toolbar">
      <div class="ql-filter-wrap" id="statusFilterWrap">
        <button type="button" class="ql-filter-pill" id="statusFilterButton"><strong>Status</strong><span>|</span><span class="ql-filter-value" id="statusFilterLabel">All</span></button>
        <div class="ql-filter-popup" id="statusFilterPopup">
          <input class="ql-filter-search" type="search" id="statusSearch" placeholder="Search statuses">
          <div class="ql-status-options" id="statusOptions"></div>
        </div>
      </div>
      <div class="ql-filter-wrap" id="dateFilterWrap">
        <button type="button" class="ql-filter-pill" id="dateFilterButton"><i class="bi bi-calendar3"></i><strong>Date</strong><span>|</span><span class="ql-filter-value" id="dateFilterLabel">All</span></button>
        <div class="ql-filter-popup ql-date-popup" id="dateFilterPopup">
          <div id="datePresetOptions"></div>
          <div class="ql-date-custom" id="customDateBox">
            <div class="ql-date-grid"><label>From<input type="date" id="customFrom"></label><label>To<input type="date" id="customTo"></label></div>
            <button type="button" class="ql-btn primary ql-date-apply" id="applyCustomDate">Apply</button>
          </div>
        </div>
      </div>
      <span class="ql-toolbar-spacer"></span>
      <div class="ql-search"><i class="bi bi-search"></i><input type="search" id="search" placeholder="Search quotes..."></div>
    </div>

    <div class="ql-selection-bar" id="selectionBar">
      <input class="ql-checkbox" type="checkbox" id="selectionAllIndicator" checked aria-label="Selected quotes">
      <span class="ql-selection-count" id="selectionCount">0 selected</span>
      <button type="button" class="ql-selection-clear" id="deselectAll">Deselect All</button>
      <div class="ql-selection-more-wrap" id="selectionMoreWrap">
        <button type="button" class="ql-selection-icon" id="selectionMoreButton" aria-label="Bulk actions"><i class="bi bi-three-dots"></i></button>
        <div class="ql-selection-menu"><button type="button" class="ql-menu-item danger" id="bulkDelete"><i class="bi bi-trash"></i> Bulk Delete</button></div>
      </div>
    </div>

    <div class="ql-table-wrap">
      <table class="ql-table">
        <thead><tr>
          <th class="ql-col-check"><input class="ql-checkbox" type="checkbox" id="selectAll" aria-label="Select all quotes"></th>
          <th class="ql-col-customer"><button type="button" class="ql-sort" data-sort="customer">Customer <i class="bi bi-chevron-expand"></i></button></th>
          <th class="ql-col-number"><button type="button" class="ql-sort" data-sort="quote_number">Quote number <i class="bi bi-chevron-expand"></i></button></th>
          <th class="ql-col-property">Property</th>
          <th class="ql-col-created"><button type="button" class="ql-sort active" data-sort="created">Created <i class="bi bi-chevron-down"></i></button></th>
          <th class="ql-col-status"><button type="button" class="ql-sort" data-sort="status">Status <i class="bi bi-chevron-expand"></i></button></th>
          <th class="ql-col-total" style="text-align:right">Total</th>
        </tr></thead>
        <tbody id="quoteRows"><tr><td colspan="7" class="ql-empty">Loading quotes...</td></tr></tbody>
      </table>
    </div>
    <div class="ql-pagination" id="pagination"><span id="paginationText"></span><div class="ql-pagination-actions"><button type="button" class="ql-btn" id="prevPage"><i class="bi bi-chevron-left"></i></button><button type="button" class="ql-btn" id="nextPage"><i class="bi bi-chevron-right"></i></button></div></div>
  </section>
</div>

<div class="ql-row-menu" id="rowMenu" aria-hidden="true">
  <a class="ql-menu-item" href="#" id="rowConvert"><i class="bi bi-hammer"></i><span id="rowConvertText">Convert to Job</span></a>
  <button type="button" class="ql-menu-item danger" id="rowDelete"><i class="bi bi-trash"></i> Delete</button>
  <div class="ql-menu-sep"></div>
  <a class="ql-menu-item" href="#" id="rowOpenNew" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Open in New Tab</a>
</div>

<!-- New quote chooser -->
<div class="ql-modal-backdrop" id="newQuoteModal" aria-hidden="true">
  <section class="ql-modal ql-newquote-modal" role="dialog" aria-modal="true" aria-labelledby="newQuoteTitle">
    <div class="ql-modal-head"><h3 id="newQuoteTitle">New quote</h3><button type="button" class="ql-modal-close" id="newQuoteClose"><i class="bi bi-x-lg"></i></button></div>
    <div class="ql-newquote-body">
      <div class="ql-template-panel">
        <div class="ql-template-panel-title">Use template</div>
        <div id="newQuoteTemplates"><div class="ql-template-empty">Loading templates...</div></div>
      </div>
      <div class="ql-or">or</div>
      <a class="ql-btn primary ql-create-blank" href="add-quotation">Create New Quote</a>
    </div>
  </section>
</div>

<!-- Templates drawer -->
<div class="ql-drawer-backdrop" id="templatesBackdrop"></div>
<aside class="ql-template-drawer" id="templatesDrawer" aria-hidden="true">
  <div class="ql-drawer-head"><h3>Templates</h3><button type="button" class="ql-drawer-close" id="templatesClose"><i class="bi bi-x-lg"></i></button></div>
  <div class="ql-drawer-copy">Save time by setting up reusable quote templates.<div class="ql-drawer-count" id="templatesCount">0/30 created</div></div>
  <div class="ql-drawer-list" id="templatesList"><div class="ql-template-empty">Loading templates...</div></div>
  <?php if ($quotationCanCreate): ?><div class="ql-drawer-foot"><button type="button" class="ql-btn primary" id="createTemplateButton">Create Template</button></div><?php endif; ?>
</aside>

<!-- Create template -->
<div class="ql-modal-backdrop" id="templateCreateModal" aria-hidden="true">
  <section class="ql-modal small" role="dialog" aria-modal="true" aria-labelledby="templateCreateTitle">
    <div class="ql-modal-head"><h3 id="templateCreateTitle">Create quote template</h3><button type="button" class="ql-modal-close" id="templateCreateClose"><i class="bi bi-x-lg"></i></button></div>
    <form id="templateCreateForm">
      <div class="ql-template-form">
        <div class="ql-form-group"><label for="templateName">Template name</label><input type="text" id="templateName" maxlength="150" required placeholder="Example: Standard service quote"></div>
        <div class="ql-form-group"><label for="templateSourceQuote">Use an existing quote as the template source</label><select id="templateSourceQuote" required><option value="">Loading quotes...</option></select><div class="ql-form-help">The selected source quote is not changed. This template stores a reusable reference to it.</div></div>
        <div class="ql-form-group"><label for="templateDescription">Description <span style="font-weight:400;color:var(--ql-muted)">(optional)</span></label><textarea id="templateDescription" maxlength="500" placeholder="Short internal description"></textarea></div>
      </div>
      <div class="ql-confirm-footer"><button type="button" class="ql-btn" id="templateCreateCancel">Cancel</button><button type="submit" class="ql-btn primary" id="templateCreateSave">Save Template</button></div>
    </form>
  </section>
</div>

<!-- Email quote -->
<div class="ql-modal-backdrop" id="emailModal" aria-hidden="true">
  <section class="ql-modal" role="dialog" aria-modal="true" aria-labelledby="emailModalTitle">
    <div class="ql-modal-head"><h3 id="emailModalTitle">Email quote</h3><button type="button" class="ql-modal-close" id="emailClose"><i class="bi bi-x-lg"></i></button></div>
    <form id="emailForm">
      <div class="ql-email-body">
        <div class="ql-email-left">
          <div class="ql-email-to"><span class="ql-email-label">To</span><div class="ql-email-chips" id="emailRecipients"></div><input class="ql-email-recipient-input" id="emailRecipientInput" type="email" placeholder="Add email"></div>
          <div class="ql-email-field"><label>Subject</label><input type="text" id="emailSubject" maxlength="255" required></div>
          <div class="ql-email-field"><label>Message</label><textarea id="emailMessage" required></textarea></div>
          <div class="ql-email-help">Your customer will receive the quote PDF plus a secure button to review and approve or reject the quote.</div>
        </div>
        <aside class="ql-email-side">
          <h4>Attachments</h4>
          <div class="ql-email-drop" id="emailDropZone"><div><button type="button" class="ql-email-select" id="emailAttachmentPick">Select</button><div style="margin-top:8px">Select or drag files here to upload</div></div><input type="file" id="emailAttachmentInput" multiple hidden></div>
          <div class="ql-email-size" id="emailSizeText">You've attached 0.00 MB of the 10.00 MB limit.</div><div class="ql-email-progress"><span id="emailProgress"></span></div>
          <div class="ql-email-file-list" id="emailAttachmentList"></div>
        </aside>
      </div>
      <div class="ql-email-footer"><label class="ql-email-copy"><input type="checkbox" id="emailSendCopy"> Send me a copy</label><div class="ql-email-actions"><button type="button" class="ql-btn" id="emailCancel">Cancel</button><button type="submit" class="ql-btn primary" id="emailSend">Send Email</button></div></div>
    </form>
  </section>
</div>

<!-- Delete confirmation -->
<div class="ql-modal-backdrop" id="confirmModal" aria-hidden="true">
  <section class="ql-modal small" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <div class="ql-modal-head"><h3 id="confirmTitle">Delete quote?</h3><button type="button" class="ql-modal-close" id="confirmClose"><i class="bi bi-x-lg"></i></button></div>
    <div class="ql-confirm-body" id="confirmCopy">Are you sure you want to delete this quote?</div>
    <div class="ql-confirm-footer"><button type="button" class="ql-btn" id="confirmCancel">Cancel</button><button type="button" class="ql-btn danger" id="confirmDelete">Delete</button></div>
  </section>
</div>

<div class="ql-toast info" id="toast"><span id="toastMsg">Notification</span></div>

<script>
  (function(){
    'use strict';
    var csrfToken=<?= json_encode($csrfToken) ?>;
    var basePath=<?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>;
    var listApi=basePath+'/api/quotation-list.php';
    var actionApi=basePath+'/api/quotation-view-actions.php';
    var canDelete=<?= $quotationCanDelete ? 'true' : 'false' ?>;
    var state={page:1,perPage:25,search:'',status:'',from:'',to:'',datePreset:'all',sort:'created',direction:'desc',rows:[],currency:{},statusCounts:{},selected:[],activeRowId:0,emailQuoteId:0,emailQuote:null,emailRecipients:[],emailFiles:[],confirmIds:[]};
    var searchTimer=null,toastTimer=null;
    var statusDefs=[
      {value:'draft',label:'Draft',dot:'draft'},
      {value:'awaiting_response',label:'Awaiting response',dot:'awaiting'},
      {value:'changes_requested',label:'Changes requested',dot:'changes'},
      {value:'approved',label:'Approved',dot:'approved'},
      {value:'converted',label:'Converted',dot:'converted'},
      {value:'internal_approval',label:'Internal approval',dot:'internal'},
      {value:'rejected',label:'Rejected',dot:'rejected'},
      {value:'expired',label:'Expired',dot:'expired'},
      {value:'archived',label:'Archived',dot:'archived'}
    ];
    var dateDefs=[['all','All'],['last_week','Last week'],['last_30','Last 30 days'],['last_month','Last month'],['this_month','This month'],['this_year','This year'],['last_12','Last 12 months'],['custom','Custom range']];
    function E(id){return document.getElementById(id)}
    function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
    function title(v){return String(v||'').replace(/_/g,' ').replace(/\b\w/g,function(m){return m.toUpperCase()})}
    function parseResponse(r){return r.text().then(function(raw){var t=String(raw||'').trim(),d;if(r.status===404||/This Page Does Not Exist/i.test(t)){throw new Error('Quotation API endpoint was not found. Upload the required files inside business.v1/api/.');}try{d=t?JSON.parse(t):{}}catch(e){if(/<!doctype|<html|@charset|<body|<style/i.test(t)){throw new Error('Quotation API returned an HTML page instead of JSON. Check that the API files are uploaded to business.v1/api/.');}throw new Error('Invalid quotation API response.');}if(!r.ok||!d.success)throw new Error(d.message||('Quotation request failed (HTTP '+r.status+').'));return d})}
    function post(url,fd){if(!fd.has('csrf_token'))fd.append('csrf_token',csrfToken);return fetch(url,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parseResponse)}
    function notify(type,msg){if(toastTimer)clearTimeout(toastTimer);E('toast').className='ql-toast '+(type||'info')+' show';E('toastMsg').textContent=msg||'Notification';toastTimer=setTimeout(function(){E('toast').classList.remove('show')},3600)}
    function money(v){var n=Number(v||0),c=state.currency||{},p=parseInt(c.decimal_places,10);if(isNaN(p))p=2;var x=n.toLocaleString(undefined,{minimumFractionDigits:p,maximumFractionDigits:p}),s=c.symbol||'';return c.symbol_position==='after'?x+(s?' '+s:''):(s||'')+x}
    function formatDate(v){if(!v)return '-';var d=new Date(String(v).replace(' ','T'));if(isNaN(d.getTime()))return String(v).substring(0,10);return d.toLocaleDateString(undefined,{month:'short',day:'2-digit',year:'numeric'})}
    function property(row){return [row.location_address1,row.location_address2,row.location_city,row.location_state,row.location_postal_code].filter(function(x){return String(x||'').trim()}).join(', ')||(row.location_name||'Property not confirmed')}
    function displayStatus(row){if(Number(row.linked_job_id||0)>0||String(row.status)==='converted')return 'converted';if(['sent','viewed'].indexOf(String(row.status))>=0)return 'awaiting_response';return String(row.status||'draft')}
    function currentRow(id){return state.rows.find(function(r){return Number(r.id)===Number(id)})||null}
    function setSelected(id,on){id=Number(id);var i=state.selected.indexOf(id);if(on&&i<0)state.selected.push(id);if(!on&&i>=0)state.selected.splice(i,1)}
    function updateSelection(){var bar=E('selectionBar'),count=state.selected.length;bar.classList.toggle('show',count>0);E('selectionCount').textContent=count+' selected';var all=E('selectAll'),rowBoxes=Array.prototype.slice.call(document.querySelectorAll('.ql-row-check')),checked=rowBoxes.filter(function(x){return x.checked}).length;all.checked=rowBoxes.length>0&&checked===rowBoxes.length;all.indeterminate=checked>0&&checked<rowBoxes.length;document.querySelectorAll('tr[data-quote-id]').forEach(function(tr){tr.classList.toggle('is-selected',state.selected.indexOf(Number(tr.dataset.quoteId))>=0)})}
    function clearSelection(){state.selected=[];document.querySelectorAll('.ql-row-check').forEach(function(x){x.checked=false});updateSelection()}

    function renderStatusOptions(query){query=String(query||'').toLowerCase();var html='<button type="button" class="ql-filter-option'+(state.status===''?' active':'')+'" data-status=""><span>All</span>'+(state.status===''?'<i class="bi bi-check-lg ql-check"></i>':'<span class="ql-status-count">'+Number(state.statusCounts.total||0)+'</span>')+'</button>';statusDefs.forEach(function(s){if(query&&s.label.toLowerCase().indexOf(query)<0)return;var c=Number(state.statusCounts[s.value]||0);html+='<button type="button" class="ql-filter-option'+(state.status===s.value?' active':'')+'" data-status="'+esc(s.value)+'"><span class="ql-dot '+esc(s.dot)+'"></span><span>'+esc(s.label)+'</span>'+(state.status===s.value?'<i class="bi bi-check-lg ql-check"></i>':'<span class="ql-status-count">('+c+')</span>')+'</button>'});E('statusOptions').innerHTML=html}
    function renderDateOptions(){E('datePresetOptions').innerHTML=dateDefs.map(function(d){return '<button type="button" class="ql-filter-option'+(state.datePreset===d[0]?' active':'')+'" data-date-preset="'+d[0]+'"><span>'+d[1]+'</span>'+(state.datePreset===d[0]?'<i class="bi bi-check-lg ql-check"></i>':'')+'</button>'}).join('')}
    function dateYmd(d){var y=d.getFullYear(),m=String(d.getMonth()+1).padStart(2,'0'),day=String(d.getDate()).padStart(2,'0');return y+'-'+m+'-'+day}
    function applyDatePreset(key){var now=new Date(),from='',to='';now.setHours(0,0,0,0);if(key==='last_week'){var day=now.getDay()||7,end=new Date(now);end.setDate(now.getDate()-day);var start=new Date(end);start.setDate(end.getDate()-6);from=dateYmd(start);to=dateYmd(end)}else if(key==='last_30'){var s=new Date(now);s.setDate(now.getDate()-29);from=dateYmd(s);to=dateYmd(now)}else if(key==='last_month'){var s1=new Date(now.getFullYear(),now.getMonth()-1,1),e1=new Date(now.getFullYear(),now.getMonth(),0);from=dateYmd(s1);to=dateYmd(e1)}else if(key==='this_month'){from=dateYmd(new Date(now.getFullYear(),now.getMonth(),1));to=dateYmd(now)}else if(key==='this_year'){from=dateYmd(new Date(now.getFullYear(),0,1));to=dateYmd(now)}else if(key==='last_12'){var s2=new Date(now);s2.setMonth(s2.getMonth()-12);s2.setDate(s2.getDate()+1);from=dateYmd(s2);to=dateYmd(now)}else if(key==='all'){from='';to=''}state.datePreset=key;state.from=from;state.to=to;E('dateFilterLabel').textContent=(dateDefs.find(function(d){return d[0]===key})||['','All'])[1];E('customDateBox').classList.toggle('show',key==='custom');renderDateOptions();if(key!=='custom'){E('dateFilterWrap').classList.remove('open');state.page=1;load()}}

    function renderRows(rows,p){state.rows=rows||[];state.selected=[];if(!state.rows.length){E('quoteRows').innerHTML='<tr><td colspan="7" class="ql-empty">No quotes found.</td></tr>';updateSelection();return}E('quoteRows').innerHTML=state.rows.map(function(row){var id=Number(row.id||0),st=displayStatus(row),mailDisabled=!row.client_email;return '<tr data-quote-id="'+id+'"><td class="ql-check-cell"><input class="ql-checkbox ql-row-check" type="checkbox" value="'+id+'" aria-label="Select '+esc(row.client_name||'quote')+'"></td><td><a class="ql-customer" href="quotation-view?quote_id='+id+'">'+esc(row.client_name||'Customer')+'</a></td><td><span class="ql-quote-number">'+esc(row.quote_no||('#'+id))+'</span>'+(row.title?'<span class="ql-quote-title">'+esc(row.title)+'</span>':'')+'</td><td><span class="ql-property">'+esc(property(row))+'</span></td><td><span class="ql-created">'+esc(formatDate(row.created_at))+'</span></td><td><span class="ql-badge '+esc(st)+'">'+esc(title(st))+'</span></td><td class="ql-total-cell"><strong class="ql-money">'+esc(money(row.total))+'</strong><span class="ql-row-actions"><button type="button" class="ql-icon-btn" data-action="email" data-id="'+id+'" title="Email quote"'+(mailDisabled?' disabled':'')+'><i class="bi bi-envelope"></i></button><button type="button" class="ql-icon-btn" data-action="more" data-id="'+id+'" title="More"><i class="bi bi-three-dots"></i></button></span></td></tr>'}).join('');updateSelection()}
    function updateSortHeaders(){document.querySelectorAll('.ql-sort').forEach(function(b){var active=b.dataset.sort===state.sort;b.classList.toggle('active',active);var i=b.querySelector('i');i.className='bi '+(active?(state.direction==='asc'?'bi-chevron-up':'bi-chevron-down'):'bi-chevron-expand')})}
    function load(){var fd=new FormData();fd.append('action','list');fd.append('page',state.page);fd.append('per_page',state.perPage);fd.append('search',state.search);fd.append('status',state.status);fd.append('from_date',state.from);fd.append('to_date',state.to);fd.append('sort',state.sort);fd.append('direction',state.direction);E('quoteRows').innerHTML='<tr><td colspan="7" class="ql-empty">Loading quotes...</td></tr>';return post(listApi,fd).then(function(d){state.currency=d.currency||state.currency;state.statusCounts=d.status_counts||d.summary||{};var p=d.pagination||{};renderRows(Array.isArray(d.quotations)?d.quotations:[],p);E('resultCount').textContent='('+Number(p.total||0)+' result'+(Number(p.total||0)===1?'':'s')+')';E('paginationText').textContent='Showing '+Number(p.from||0)+'-'+Number(p.to||0)+' of '+Number(p.total||0);E('prevPage').disabled=Number(p.page||1)<=1;E('nextPage').disabled=Number(p.page||1)>=Number(p.pages||1);renderStatusOptions(E('statusSearch').value);updateSortHeaders();return d}).catch(function(e){E('quoteRows').innerHTML='<tr><td colspan="7" class="ql-empty">'+esc(e.message)+'</td></tr>';notify('error',e.message)})}

    function closeRowMenu(){E('rowMenu').classList.remove('show');E('rowMenu').setAttribute('aria-hidden','true');state.activeRowId=0}
    function openRowMenu(button,id){var r=currentRow(id);if(!r)return;state.activeRowId=id;var linked=Number(r.linked_job_id||0)>0;E('rowConvert').href=linked?'job-view.php?job_id='+Number(r.linked_job_id):'job-form.php?quote_id='+id;E('rowConvertText').textContent=linked?'View Job':'Convert to Job';E('rowOpenNew').href='quotation-view?quote_id='+id;E('rowDelete').style.display=canDelete?'flex':'none';var m=E('rowMenu'),rect=button.getBoundingClientRect();m.classList.add('show');m.setAttribute('aria-hidden','false');var w=165,h=m.offsetHeight||135,left=Math.min(rect.right-w,window.innerWidth-w-8),top=rect.bottom+5;if(top+h>window.innerHeight-8)top=Math.max(8,rect.top-h-5);m.style.left=Math.max(8,left)+'px';m.style.top=top+'px'}

    function emailBytes(){return state.emailFiles.reduce(function(s,f){return s+Number(f.size||0)},0)}
    function fmtBytes(n){if(!n)return '0 Bytes';if(n<1024)return n+' Bytes';if(n<1048576)return(n/1024).toFixed(1)+' KB';return(n/1048576).toFixed(2)+' MB'}
    function renderRecipients(){E('emailRecipients').innerHTML=state.emailRecipients.map(function(x,i){return '<span class="ql-email-chip">'+esc(x)+'<button type="button" data-remove-recipient="'+i+'"><i class="bi bi-x-lg"></i></button></span>'}).join('')}
    function addRecipient(v){v=String(v||'').trim().toLowerCase();if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v))return false;if(state.emailRecipients.indexOf(v)<0)state.emailRecipients.push(v);renderRecipients();return true}
    function renderEmailFiles(){var total=emailBytes(),mb=total/1048576;E('emailSizeText').textContent="You've attached "+mb.toFixed(2)+' MB of the 10.00 MB limit.';E('emailProgress').style.width=Math.min(100,total/(10*1048576)*100)+'%';var auto=state.emailQuote?'<div class="ql-email-file"><span class="ql-email-file-icon"><i class="bi bi-file-earmark-pdf"></i></span><span><strong>'+esc('quote_'+String(state.emailQuote.quote_no||state.emailQuoteId).replace(/[^A-Za-z0-9_-]/g,'_')+'.pdf')+'</strong><small>Quote PDF attached automatically</small></span><button type="button" data-open-pdf title="Open PDF"><i class="bi bi-box-arrow-up-right"></i></button></div>':'';var extra=state.emailFiles.map(function(f,i){return '<div class="ql-email-file"><span class="ql-email-file-icon"><i class="bi bi-paperclip"></i></span><span><strong>'+esc(f.name)+'</strong><small>'+esc(fmtBytes(f.size))+'</small></span><button type="button" data-remove-file="'+i+'"><i class="bi bi-x-lg"></i></button></div>'}).join('');E('emailAttachmentList').innerHTML=auto+extra}
    function addEmailFiles(list){var inc=Array.prototype.slice.call(list||[]),total=emailBytes(),ok=[];inc.forEach(function(f){if(state.emailFiles.length+ok.length>=10)return;if(total+f.size>10*1048576)return;total+=f.size;ok.push(f)});if(ok.length!==inc.length)notify('warning','Attachments are limited to 10 files and 10.00 MB total.');state.emailFiles=state.emailFiles.concat(ok);renderEmailFiles()}
    function getQuote(id){var fd=new FormData();fd.append('action','get');fd.append('quote_id',id);return post(actionApi,fd)}
    function openEmail(id){closeRowMenu();var row=currentRow(id);if(!row||!row.client_email){notify('warning','This customer does not have an email address.');return}state.emailQuoteId=id;state.emailRecipients=[];state.emailFiles=[];E('emailAttachmentInput').value='';getQuote(id).then(function(d){var q=d.quotation||row;state.emailQuote=q;addRecipient(q.client_email||row.client_email);var company=q.branch_name||q.tenant_name||'FieldPlx';E('emailModalTitle').textContent='Email quote '+(q.quote_no||'')+' to '+(q.client_name||'Customer');E('emailSubject').value='Quote from '+company+' - '+formatDate(q.created_at);E('emailMessage').value='Hi '+(q.client_name||'')+',\n\nThank you for asking us to quote on your project.\n\nThe quote total is '+money(q.total)+' as of '+formatDate(q.created_at)+'.\n\nPlease review the attached quotation PDF and use the secure approval button in this email to Approve or Reject the quote.\n\nIf you have any questions or concerns regarding this quote, please don\'t hesitate to get in touch with us.\n\nSincerely,\n\n'+company;E('emailSendCopy').checked=false;renderEmailFiles();E('emailModal').classList.add('show');E('emailModal').setAttribute('aria-hidden','false');setTimeout(function(){E('emailRecipientInput').focus()},50)}).catch(function(e){notify('error',e.message)})}
    function closeEmail(){E('emailModal').classList.remove('show');E('emailModal').setAttribute('aria-hidden','true');state.emailQuoteId=0;state.emailQuote=null;state.emailRecipients=[];state.emailFiles=[]}
    function sendEmail(e){e.preventDefault();var pending=E('emailRecipientInput').value.trim();if(pending){if(!addRecipient(pending)){notify('error','Enter a valid recipient email address.');return}E('emailRecipientInput').value=''}if(!state.emailRecipients.length){notify('error','Add at least one valid recipient email address.');return}if(emailBytes()>10*1048576){notify('error','Email attachments cannot exceed 10 MB in total.');return}var subject=E('emailSubject').value.trim(),message=E('emailMessage').value.trim();if(!subject){notify('error','Email subject is required.');return}if(!message){notify('error','Email message is required.');return}var fd=new FormData();fd.append('action','send_email');fd.append('quote_id',state.emailQuoteId);fd.append('to_emails',state.emailRecipients.join(','));fd.append('email_subject',subject);fd.append('email_message',message);fd.append('attach_quote_pdf','1');fd.append('include_approval_link','1');if(E('emailSendCopy').checked)fd.append('send_me_copy','1');state.emailFiles.forEach(function(f){fd.append('email_attachments[]',f,f.name)});var b=E('emailSend');b.disabled=true;b.textContent='Sending...';post(actionApi,fd).then(function(d){closeEmail();notify('success',d.message||'Quotation email sent successfully.');setTimeout(function(){window.location.reload()},700)}).catch(function(err){notify('error',err.message)}).finally(function(){b.disabled=false;b.textContent='Send Email'})}

    function openConfirm(ids){state.confirmIds=(ids||[]).map(Number).filter(Boolean);if(!state.confirmIds.length)return;var bulk=state.confirmIds.length>1;E('confirmTitle').textContent=bulk?'Delete '+state.confirmIds.length+' quotes?':'Delete quote?';E('confirmCopy').textContent=bulk?'The selected quotes will be permanently deleted. Quotes already linked to jobs will be skipped by the server.':'Are you sure you want to delete this quote? If it is linked to a job, deletion will be blocked.';E('confirmDelete').textContent=bulk?'Delete Quotes':'Delete';E('confirmModal').classList.add('show');E('confirmModal').setAttribute('aria-hidden','false')}
    function closeConfirm(){E('confirmModal').classList.remove('show');E('confirmModal').setAttribute('aria-hidden','true');state.confirmIds=[]}
    function deleteQuote(id){var fd=new FormData();fd.append('action','delete');fd.append('quote_id',id);return post(actionApi,fd)}
    async function runDelete(){var ids=state.confirmIds.slice();if(!ids.length)return;var b=E('confirmDelete'),failed=[];b.disabled=true;b.textContent='Deleting...';for(var i=0;i<ids.length;i++){try{await deleteQuote(ids[i])}catch(e){failed.push({id:ids[i],message:e.message})}}closeConfirm();b.disabled=false;b.textContent='Delete';clearSelection();if(failed.length)notify('warning',(ids.length-failed.length)+' deleted. '+failed.length+' could not be deleted: '+failed[0].message);else notify('success',ids.length>1?'Quotes deleted successfully.':'Quote deleted successfully.');setTimeout(function(){window.location.reload()},700)}

    async function exportQuotes(){var btn=E('exportQuotes');btn.disabled=true;var all=[],page=1,pages=1;try{do{var fd=new FormData();fd.append('action','list');fd.append('page',page);fd.append('per_page',50);fd.append('search',state.search);fd.append('status',state.status);fd.append('from_date',state.from);fd.append('to_date',state.to);fd.append('sort',state.sort);fd.append('direction',state.direction);var d=await post(listApi,fd);all=all.concat(d.quotations||[]);pages=Number((d.pagination||{}).pages||1);page++}while(page<=pages);var cols=['Quote Number','Title','Customer','Property','Created','Status','Total'];var q=function(v){return '"'+String(v==null?'':v).replace(/"/g,'""')+'"'};var rows=all.map(function(r){return [r.quote_no,r.title,r.client_name,property(r),formatDate(r.created_at),title(displayStatus(r)),money(r.total)].map(q).join(',')});var csv='\ufeff'+cols.map(q).join(',')+'\r\n'+rows.join('\r\n'),blob=new Blob([csv],{type:'text/csv;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='quotes-'+new Date().toISOString().slice(0,10)+'.csv';document.body.appendChild(a);a.click();a.remove();URL.revokeObjectURL(url);notify('success','Quotes exported successfully.');E('topMoreWrap').classList.remove('open')}catch(e){notify('error',e.message)}finally{btn.disabled=false}}

    E('quoteRows').addEventListener('click',function(e){var a=e.target.closest('[data-action]');if(a){e.preventDefault();e.stopPropagation();var id=Number(a.dataset.id||0);if(a.dataset.action==='email')openEmail(id);if(a.dataset.action==='more'){if(state.activeRowId===id&&E('rowMenu').classList.contains('show'))closeRowMenu();else{closeRowMenu();openRowMenu(a,id)}}return}if(e.target.closest('a,button,input,label'))return;var tr=e.target.closest('tr[data-quote-id]');if(tr)window.location.href='quotation-view?quote_id='+encodeURIComponent(tr.dataset.quoteId)});
    E('quoteRows').addEventListener('change',function(e){var box=e.target.closest('.ql-row-check');if(!box)return;setSelected(Number(box.value),box.checked);updateSelection()});
    E('selectAll').addEventListener('change',function(){var on=this.checked;document.querySelectorAll('.ql-row-check').forEach(function(x){x.checked=on;setSelected(Number(x.value),on)});updateSelection()});
    E('deselectAll').addEventListener('click',clearSelection);
    E('selectionMoreButton').addEventListener('click',function(e){e.stopPropagation();E('selectionMoreWrap').classList.toggle('open')});
    E('bulkDelete').addEventListener('click',function(){E('selectionMoreWrap').classList.remove('open');if(!canDelete){notify('error','You do not have permission to delete quotations.');return}openConfirm(state.selected)});
    E('rowDelete').addEventListener('click',function(){var id=state.activeRowId;closeRowMenu();if(!canDelete){notify('error','You do not have permission to delete quotations.');return}openConfirm([id])});
    E('confirmDelete').addEventListener('click',runDelete);E('confirmClose').addEventListener('click',closeConfirm);E('confirmCancel').addEventListener('click',closeConfirm);

    E('statusFilterButton').addEventListener('click',function(e){e.stopPropagation();E('dateFilterWrap').classList.remove('open');E('statusFilterWrap').classList.toggle('open');if(E('statusFilterWrap').classList.contains('open'))setTimeout(function(){E('statusSearch').focus()},20)});
    E('statusSearch').addEventListener('input',function(){renderStatusOptions(this.value)});
    E('statusOptions').addEventListener('click',function(e){var b=e.target.closest('[data-status]');if(!b)return;state.status=b.dataset.status||'';state.page=1;E('statusFilterLabel').textContent=state.status?(statusDefs.find(function(s){return s.value===state.status})||{label:title(state.status)}).label:'All';E('statusFilterWrap').classList.remove('open');E('statusSearch').value='';load()});
    E('dateFilterButton').addEventListener('click',function(e){e.stopPropagation();E('statusFilterWrap').classList.remove('open');E('dateFilterWrap').classList.toggle('open')});
    E('datePresetOptions').addEventListener('click',function(e){var b=e.target.closest('[data-date-preset]');if(!b)return;applyDatePreset(b.dataset.datePreset)});
    E('applyCustomDate').addEventListener('click',function(){var f=E('customFrom').value,t=E('customTo').value;if(f&&t&&f>t){notify('error','From date cannot be later than To date.');return}state.from=f;state.to=t;state.datePreset='custom';E('dateFilterLabel').textContent='Custom range';E('dateFilterWrap').classList.remove('open');state.page=1;load()});
    E('search').addEventListener('input',function(){var v=this.value;if(searchTimer)clearTimeout(searchTimer);searchTimer=setTimeout(function(){state.search=v.trim();state.page=1;load()},250)});
    document.querySelectorAll('.ql-sort').forEach(function(b){b.addEventListener('click',function(){var s=b.dataset.sort;if(state.sort===s)state.direction=state.direction==='asc'?'desc':'asc';else{state.sort=s;state.direction=s==='customer'?'asc':'desc'}state.page=1;load()})});
    E('prevPage').addEventListener('click',function(){if(state.page>1){state.page--;load()}});E('nextPage').addEventListener('click',function(){if(!this.disabled){state.page++;load()}});
    E('topMoreButton').addEventListener('click',function(e){e.stopPropagation();E('topMoreWrap').classList.toggle('open')});E('exportQuotes').addEventListener('click',exportQuotes);

    E('emailRecipientInput').addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===','||e.key===';'){e.preventDefault();if(this.value.trim()){if(!addRecipient(this.value))notify('error','Enter a valid email address.');this.value=''}}});
    E('emailRecipientInput').addEventListener('blur',function(){if(this.value.trim()){if(addRecipient(this.value))this.value=''}});
    E('emailRecipients').addEventListener('click',function(e){var b=e.target.closest('[data-remove-recipient]');if(!b)return;state.emailRecipients.splice(Number(b.dataset.removeRecipient),1);renderRecipients()});
    E('emailAttachmentPick').addEventListener('click',function(){E('emailAttachmentInput').click()});E('emailAttachmentInput').addEventListener('change',function(){addEmailFiles(this.files);this.value=''});
    E('emailDropZone').addEventListener('dragover',function(e){e.preventDefault();this.classList.add('drag')});E('emailDropZone').addEventListener('dragleave',function(){this.classList.remove('drag')});E('emailDropZone').addEventListener('drop',function(e){e.preventDefault();this.classList.remove('drag');addEmailFiles(e.dataTransfer.files)});
    E('emailAttachmentList').addEventListener('click',function(e){var rm=e.target.closest('[data-remove-file]');if(rm){state.emailFiles.splice(Number(rm.dataset.removeFile),1);renderEmailFiles();return}if(e.target.closest('[data-open-pdf]')&&state.emailQuoteId){window.open('quotation-print.php?quote_id='+encodeURIComponent(state.emailQuoteId),'_blank','noopener')}});
    E('emailForm').addEventListener('submit',sendEmail);E('emailClose').addEventListener('click',closeEmail);E('emailCancel').addEventListener('click',closeEmail);

    document.addEventListener('click',function(e){if(!E('statusFilterWrap').contains(e.target))E('statusFilterWrap').classList.remove('open');if(!E('dateFilterWrap').contains(e.target))E('dateFilterWrap').classList.remove('open');if(!E('topMoreWrap').contains(e.target))E('topMoreWrap').classList.remove('open');if(!E('selectionMoreWrap').contains(e.target))E('selectionMoreWrap').classList.remove('open');if(!E('rowMenu').contains(e.target)&&!e.target.closest('[data-action="more"]'))closeRowMenu()});
    E('emailModal').addEventListener('click',function(e){if(e.target===this)closeEmail()});E('confirmModal').addEventListener('click',function(e){if(e.target===this)closeConfirm()});

    renderDateOptions();renderStatusOptions('');load();
  })();
  
</script>

<script>
(function(){
  'use strict';
  var csrfToken=<?= json_encode($csrfToken) ?>;
  var basePath=<?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>;
  var templateApi=basePath+'/api/quotation-templates.php';
  var actionApi=basePath+'/api/quotation-view-actions.php';
  var canCreate=<?= $quotationCanCreate ? 'true' : 'false' ?>;
  var templates=[];
  var maxTemplates=30;
  function E(id){return document.getElementById(id)}
  function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
  function parseResponse(r){return r.text().then(function(raw){var t=String(raw||'').trim(),d;if(r.status===404||/This Page Does Not Exist/i.test(t)){throw new Error('Quotation API endpoint was not found. Upload the required files inside business.v1/api/.');}try{d=t?JSON.parse(t):{}}catch(e){if(/<!doctype|<html|@charset|<body|<style/i.test(t)){throw new Error('Quotation API returned an HTML page instead of JSON. Check that the API files are uploaded to business.v1/api/.');}throw new Error('Invalid quotation API response.');}if(!r.ok||!d.success)throw new Error(d.message||('Quotation request failed (HTTP '+r.status+').'));return d})}
  function post(url,fd){if(!fd.has('csrf_token'))fd.append('csrf_token',csrfToken);return fetch(url,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parseResponse)}
  function toast(type,msg){var box=E('toast'),text=E('toastMsg');if(!box||!text)return;box.className='ql-toast '+(type||'info')+' show';text.textContent=msg||'Notification';window.setTimeout(function(){box.classList.remove('show')},3600)}
  function relative(v){if(!v)return '';var d=new Date(String(v).replace(' ','T'));if(isNaN(d.getTime()))return String(v);return d.toLocaleString(undefined,{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'})}

  function openNewQuote(){E('newQuoteModal').classList.add('show');E('newQuoteModal').setAttribute('aria-hidden','false');loadTemplates()}
  function closeNewQuote(){E('newQuoteModal').classList.remove('show');E('newQuoteModal').setAttribute('aria-hidden','true')}
  function openDrawer(){E('topMoreWrap').classList.remove('open');E('templatesBackdrop').classList.add('show');E('templatesDrawer').classList.add('show');E('templatesDrawer').setAttribute('aria-hidden','false');loadTemplates()}
  function closeDrawer(){E('templatesBackdrop').classList.remove('show');E('templatesDrawer').classList.remove('show');E('templatesDrawer').setAttribute('aria-hidden','true')}
  function openCreateTemplate(){if(!canCreate){toast('error','You do not have permission to create quote templates.');return}E('templateCreateModal').classList.add('show');E('templateCreateModal').setAttribute('aria-hidden','false');E('templateName').value='';E('templateDescription').value='';loadSourceQuotes()}
  function closeCreateTemplate(){E('templateCreateModal').classList.remove('show');E('templateCreateModal').setAttribute('aria-hidden','true')}

  function renderTemplates(){
    maxTemplates=30;
    E('templatesCount').textContent=templates.length+'/'+maxTemplates+' created';
    if(!templates.length){
      E('templatesList').innerHTML='<div class="ql-template-empty">No quote templates have been created yet.</div>';
      E('newQuoteTemplates').innerHTML='<div class="ql-template-empty">No templates yet. Create a new quote or add a reusable template from More Actions.</div>';
      return;
    }
    E('templatesList').innerHTML=templates.map(function(t){return '<div class="ql-drawer-item"><div><div class="ql-drawer-item-name">'+esc(t.name)+'</div><div class="ql-drawer-item-meta">Last changed '+esc(relative(t.updated_at))+'</div></div><div class="ql-drawer-item-actions"><button type="button" class="ql-drawer-mini" data-use-template="'+Number(t.id)+'" title="Use template"><i class="bi bi-plus-lg"></i></button><button type="button" class="ql-drawer-mini" data-delete-template="'+Number(t.id)+'" title="Delete template"><i class="bi bi-trash"></i></button></div></div>'}).join('');
    E('newQuoteTemplates').innerHTML=templates.slice(0,8).map(function(t){return '<button type="button" class="ql-template-option" data-use-template="'+Number(t.id)+'"><span><strong>'+esc(t.name)+'</strong><small>'+esc(t.description||('Based on '+(t.source_quote_no||'an existing quote')))+'</small></span><i class="bi bi-chevron-right"></i></button>'}).join('');
  }

  function loadTemplates(){
    E('templatesList').innerHTML='<div class="ql-template-empty">Loading templates...</div>';
    E('newQuoteTemplates').innerHTML='<div class="ql-template-empty">Loading templates...</div>';
    var fd=new FormData();fd.append('action','list');
    post(templateApi,fd).then(function(d){templates=Array.isArray(d.templates)?d.templates:[];maxTemplates=Number(d.max_templates||30);renderTemplates();E('templatesCount').textContent=templates.length+'/'+maxTemplates+' created'}).catch(function(err){templates=[];E('templatesCount').textContent='Templates unavailable';var html='<div class="ql-template-empty">'+esc(err.message)+'</div>';E('templatesList').innerHTML=html;E('newQuoteTemplates').innerHTML=html})
  }

  function loadSourceQuotes(){
    var select=E('templateSourceQuote');select.innerHTML='<option value="">Loading quotes...</option>';
    var fd=new FormData();fd.append('action','source_quotes');
    post(templateApi,fd).then(function(d){var rows=Array.isArray(d.quotes)?d.quotes:[];select.innerHTML='<option value="">Select a quote</option>'+rows.map(function(q){var label=(q.quote_no||('#'+q.id))+(q.title?' - '+q.title:'')+(q.client_name?' - '+q.client_name:'');return '<option value="'+Number(q.id)+'">'+esc(label)+'</option>'}).join('');if(!rows.length)select.innerHTML='<option value="">No quotes available</option>'}).catch(function(err){select.innerHTML='<option value="">Unable to load quotes</option>';toast('error',err.message)})
  }

  function useTemplate(id){
    var t=templates.find(function(x){return Number(x.id)===Number(id)});if(!t)return;
    /* Existing quotation API already knows how to create a safe draft copy with line items/sections. */
    var fd=new FormData();fd.append('action','create_similar');fd.append('quote_id',Number(t.source_quote_id));
    document.querySelectorAll('[data-use-template]').forEach(function(b){b.disabled=true});
    post(actionApi,fd).then(function(d){toast('success','Draft quote created from '+t.name+'.');window.location.href=d.edit_url||('add-quotation.php?quote_id='+encodeURIComponent(d.quote_id))}).catch(function(err){toast('error',err.message);document.querySelectorAll('[data-use-template]').forEach(function(b){b.disabled=false})})
  }

  function deleteTemplate(id){
    var t=templates.find(function(x){return Number(x.id)===Number(id)});if(!t)return;
    if(!window.confirm('Delete template "'+t.name+'"?'))return;
    var fd=new FormData();fd.append('action','delete');fd.append('template_id',Number(id));
    post(templateApi,fd).then(function(d){toast('success',d.message||'Template deleted.');loadTemplates()}).catch(function(err){toast('error',err.message)})
  }

  E('newQuoteButton')&&E('newQuoteButton').addEventListener('click',openNewQuote);
  E('newQuoteClose').addEventListener('click',closeNewQuote);
  E('newQuoteModal').addEventListener('click',function(e){if(e.target===this)closeNewQuote()});
  E('openTemplates').addEventListener('click',openDrawer);
  E('templatesClose').addEventListener('click',closeDrawer);
  E('templatesBackdrop').addEventListener('click',closeDrawer);
  E('createTemplateButton')&&E('createTemplateButton').addEventListener('click',function(){closeDrawer();openCreateTemplate()});
  E('templateCreateClose').addEventListener('click',closeCreateTemplate);
  E('templateCreateCancel').addEventListener('click',closeCreateTemplate);
  E('templateCreateModal').addEventListener('click',function(e){if(e.target===this)closeCreateTemplate()});
  E('importUnavailable')&&E('importUnavailable').addEventListener('click',function(){E('topMoreWrap').classList.remove('open');toast('info','The quote import page is not installed in this build yet.')});

  document.addEventListener('click',function(e){var use=e.target.closest('[data-use-template]');if(use){e.preventDefault();useTemplate(use.dataset.useTemplate);return}var del=e.target.closest('[data-delete-template]');if(del){e.preventDefault();deleteTemplate(del.dataset.deleteTemplate)}});

  E('templateCreateForm').addEventListener('submit',function(e){
    e.preventDefault();var name=E('templateName').value.trim(),source=Number(E('templateSourceQuote').value||0),desc=E('templateDescription').value.trim();if(!name||source<=0){toast('error','Enter a template name and select a source quote.');return}
    var b=E('templateCreateSave');b.disabled=true;b.textContent='Saving...';var fd=new FormData();fd.append('action','create');fd.append('name',name);fd.append('source_quote_id',source);fd.append('description',desc);
    post(templateApi,fd).then(function(d){closeCreateTemplate();toast('success',d.message||'Template created.');openDrawer()}).catch(function(err){toast('error',err.message)}).finally(function(){b.disabled=false;b.textContent='Save Template'})
  });

  document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeNewQuote();closeCreateTemplate();closeDrawer()}});
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
