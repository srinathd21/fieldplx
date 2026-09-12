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
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1" name="viewport" />
  <title>Quotations - FieldPlx</title>
  <?php require_once __DIR__ . '/includes/links.php'; ?>
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
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

    \n

    /* Clients page additions */
    \na,
    a:link,
    a:visited,
    a:hover,
    a:focus,
    a:active {
      text-decoration: none !important
    }

    \n.fd-client-type {
      display: inline-flex;
      align-items: center;
      padding: 5px 7px;
      border-radius: 5px;
      font-size: 8.5px;
      font-weight: 600
    }

    \n.fd-client-type.client,
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

    \n.fd-client-checks {
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

    \n

    /* Clients table font + alignment correction */
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

    /* Client Locations page */
    .fd-loc-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 18px;
    }

    .fd-loc-title {
      margin: 0 0 7px;
      color: var(--fd-text);
      font-size: 21px;
      font-weight: 700;
    }

    .fd-loc-sub {
      margin: 0;
      max-width: 800px;
      color: var(--fd-muted);
      font-size: 11px;
      line-height: 1.55;
    }

    .fd-loc-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .fd-loc-client {
      margin-bottom: 14px;
      padding: 12px 14px;
      display: flex;
      align-items: center;
      gap: 10px;
      border: 1px solid var(--fd-border);
      border-radius: 10px;
      background: #fff;
    }

    .fd-loc-client-icon {
      width: 38px;
      height: 38px;
      display: grid;
      place-items: center;
      flex: 0 0 38px;
      border-radius: 10px;
      color: var(--fd-green-dark);
      background: var(--fd-green-soft);
    }

    .fd-loc-client strong,
    .fd-loc-client small {
      display: block;
    }

    .fd-loc-client strong {
      color: var(--fd-text);
      font-size: 11px;
    }

    .fd-loc-client small {
      margin-top: 3px;
      color: var(--fd-muted);
      font-size: 8.5px;
    }

    .fd-loc-address {
      min-width: 260px;
      white-space: normal !important;
      line-height: 1.4;
    }

    .fd-loc-modal {
      width: min(900px, 100%);
    }

    .fd-loc-map-link {
      color: #123d70 !important;
      font-weight: 700;
      text-decoration: none !important;
    }

    .fd-loc-map-link:hover {
      color: var(--fd-green-dark) !important;
    }

    .fd-loc-primary {
      color: #5d971b;
      background: #f0f8e5;
    }

    .fd-loc-type {
      color: #123d70;
      background: #edf2f7;
    }

    .fd-loc-table .fd-team-actions-cell {
      justify-content: flex-start;
    }

    .fd-loc-table-wrap {
      overflow-x: auto;
      overflow-y: hidden;
      scrollbar-width: thin;
      scrollbar-color: #9aa0a6 transparent;
    }

    .fd-loc-table-wrap::-webkit-scrollbar {
      height: 3px !important
    }

    .fd-loc-table-wrap::-webkit-scrollbar-track {
      background: transparent !important
    }

    .fd-loc-table-wrap::-webkit-scrollbar-thumb {
      min-width: 20px;
      border-radius: 999px !important;
      background: #9aa0a6 !important;
    }

    .fd-loc-table-wrap::-webkit-scrollbar-button {
      width: 0 !important;
      height: 0 !important;
      display: none !important;
    }

    @media(max-width:767.98px) {
      .fd-loc-head {
        flex-direction: column;
      }

      .fd-loc-actions {
        width: 100%;
      }
    }

    /* ==========================================================
   Service Requests - tenant CRM intake
   ========================================================== */
    a,
    a:link,
    a:visited,
    a:hover,
    a:focus,
    a:active {
      text-decoration: none !important;
    }

    .fd-rq-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 18px;
    }

    .fd-rq-title {
      margin: 0 0 7px;
      color: var(--fd-text);
      font-size: 21px;
      line-height: 1.2;
      font-weight: 700;
    }

    .fd-rq-sub {
      margin: 0;
      max-width: 860px;
      color: var(--fd-muted);
      font-size: 11px;
      line-height: 1.55;
    }

    .fd-rq-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .fd-rq-btn {
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
      cursor: pointer;
    }

    .fd-rq-btn:hover {
      border-color: #cfe3ae;
      color: var(--fd-green-dark);
      background: #f9fcf4;
    }

    .fd-rq-btn.primary {
      border-color: var(--fd-green);
      color: #fff;
      background: linear-gradient(90deg, #7fc92d, #68aa1d);
      box-shadow: 0 7px 16px rgba(104, 170, 29, .18);
    }

    .fd-rq-btn.primary:hover {
      color: #fff;
      background: linear-gradient(90deg, #74b824, #5d971b);
    }

    .fd-rq-btn.danger {
      border-color: #ffd5d9;
      color: #b9444d;
      background: #fff;
    }

    .fd-rq-btn:disabled {
      opacity: .58;
      cursor: not-allowed;
    }

    .fd-rq-loader {
      width: 13px;
      height: 13px;
      display: none;
      border: 2px dotted currentColor;
      border-radius: 50%;
      animation: fdRqSpin .75s linear infinite;
    }

    .fd-rq-btn.loading .fd-rq-loader {
      display: inline-block
    }

    @keyframes fdRqSpin {
      to {
        transform: rotate(360deg)
      }
    }

    .fd-rq-summary {
      margin-bottom: 16px
    }

    .fd-rq-stat {
      min-height: 112px;
      padding: 18px 20px;
      border: 1px solid #dfe6ef;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 3px 12px rgba(24, 45, 76, .035);
    }

    .fd-rq-stat-row {
      min-height: 72px;
      display: flex;
      align-items: center;
      gap: 18px;
    }

    .fd-rq-stat-icon {
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

    .fd-rq-stat-label {
      display: block;
      margin-bottom: 8px;
      color: #506784;
      font-size: 13px;
    }

    .fd-rq-stat-value {
      display: block;
      color: #020b16;
      font-size: 31px;
      line-height: 1;
      font-weight: 700;
    }

    .fd-rq-card {
      overflow: hidden
    }

    .fd-rq-toolbar {
      padding: 13px 14px;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      border-bottom: 1px solid var(--fd-border);
      background: #fbfcfd;
    }

    .fd-rq-search {
      width: 280px;
      position: relative;
    }

    .fd-rq-search i {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #8a96a7;
      font-size: 13px;
    }

    .fd-rq-search input,
    .fd-rq-filter {
      height: 39px;
      border: 1px solid #dde4ec;
      border-radius: 8px;
      outline: 0;
      color: #33445f;
      background: #fff;
      font-size: 10px;
    }

    .fd-rq-search input {
      width: 100%;
      padding: 8px 11px 8px 34px;
    }

    .fd-rq-filter {
      min-width: 140px;
      padding: 8px 10px;
    }

    .fd-rq-search input:focus,
    .fd-rq-filter:focus {
      border-color: #a9cf75;
      box-shadow: 0 0 0 3px rgba(116, 184, 36, .11);
    }

    .fd-rq-spacer {
      margin-left: auto
    }

    .fd-rq-table-wrap {
      width: 100%;
      overflow-x: auto;
      overflow-y: hidden;
      scrollbar-width: thin;
      scrollbar-color: #9aa0a6 transparent;
    }

    .fd-rq-table-wrap::-webkit-scrollbar {
      height: 3px !important
    }

    .fd-rq-table-wrap::-webkit-scrollbar-track {
      background: transparent !important
    }

    .fd-rq-table-wrap::-webkit-scrollbar-thumb {
      min-width: 20px;
      border-radius: 999px !important;
      background: #9aa0a6 !important;
    }

    .fd-rq-table-wrap::-webkit-scrollbar-button {
      width: 0 !important;
      height: 0 !important;
      display: none !important;
    }

    .fd-rq-table {
      width: 100%;
      min-width: 1320px;
      margin: 0;
      border-collapse: collapse;
      white-space: nowrap;
    }

    .fd-rq-table th {
      padding: 11px 12px;
      border-bottom: 1px solid var(--fd-border);
      color: #65738a;
      background: #f8fafc;
      font-size: 9px;
      line-height: 1.2;
      font-weight: 700;
      text-align: left;
      text-transform: uppercase;
    }

    .fd-rq-table td {
      padding: 12px;
      border-bottom: 1px solid #f1f3f7;
      color: #33445f;
      font-size: 9.5px;
      line-height: 1.45;
      vertical-align: middle;
    }

    .fd-rq-table tbody tr:hover {
      background: #fbfcfa
    }

    .fd-rq-table th:first-child,
    .fd-rq-table td:first-child {
      width: 55px;
      text-align: center;
    }

    .fd-rq-request strong,
    .fd-rq-request small,
    .fd-rq-client strong,
    .fd-rq-client small {
      display: block;
    }

    .fd-rq-request strong {
      color: #123d70;
      font-size: 10.5px;
      font-weight: 700;
    }

    .fd-rq-request small,
    .fd-rq-client small {
      margin-top: 3px;
      color: #8995a6;
      font-size: 8.3px;
    }

    .fd-rq-client strong {
      color: #17233b;
      font-size: 10px;
      font-weight: 700;
    }

    .fd-rq-badge {
      min-height: 22px;
      padding: 4px 7px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 5px;
      font-size: 8.5px;
      line-height: 1;
      font-weight: 700;
      text-transform: capitalize;
    }

    .fd-rq-badge.new,
    .fd-rq-badge.normal {
      color: #123d70;
      background: #edf2f7;
    }

    .fd-rq-badge.contacting,
    .fd-rq-badge.information_required {
      color: #8a5e10;
      background: #fff7df;
    }

    .fd-rq-badge.assessment_required,
    .fd-rq-badge.quote_required,
    .fd-rq-badge.job_required,
    .fd-rq-badge.high {
      color: #b55b00;
      background: #fff1e4;
    }

    .fd-rq-badge.converted,
    .fd-rq-badge.closed,
    .fd-rq-badge.low {
      color: #5d971b;
      background: #f0f8e5;
    }

    .fd-rq-badge.cancelled {
      color: #8b4450;
      background: #fff0f1;
    }

    .fd-rq-badge.urgent {
      color: #bd2f3a;
      background: #fff0f1;
    }

    .fd-rq-actions-cell {
      min-width: 120px;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .fd-rq-icon {
      width: 29px;
      height: 29px;
      min-width: 29px;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 0;
      border-radius: 6px;
      color: #66748b;
      background: transparent;
      cursor: pointer;
      font-size: 12px;
      line-height: 1;
    }

    .fd-rq-icon:hover {
      color: var(--fd-green-dark);
      background: var(--fd-green-soft);
    }

    .fd-rq-icon.danger:hover {
      color: #b9444d;
      background: #fff0f1;
    }

    .fd-rq-empty {
      padding: 28px 18px !important;
      text-align: center;
      color: #9aa4b3 !important;
      font-size: 10px !important;
    }

    .fd-rq-pagination {
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

    .fd-rq-pagination-actions {
      display: flex;
      gap: 5px;
    }

    /* Modal */
    .fd-rq-modal-bg {
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

    .fd-rq-modal-bg.show {
      display: flex
    }

    .fd-rq-modal {
      width: min(940px, 100%);
      max-height: calc(100vh - 34px);
      overflow: auto;
      border: 1px solid #dfe5ec;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 24px 65px rgba(0, 17, 49, .24);
    }

    .fd-rq-modal.small {
      width: min(610px, 100%);
    }

    .fd-rq-modal-header {
      min-height: 58px;
      padding: 11px 14px;
      display: flex;
      align-items: center;
      gap: 10px;
      border-bottom: 1px solid var(--fd-border);
      background: #fbfcfd;
    }

    .fd-rq-modal-icon {
      width: 34px;
      height: 34px;
      display: grid;
      place-items: center;
      border-radius: 9px;
      color: var(--fd-green-dark);
      background: var(--fd-green-soft);
      font-size: 15px;
    }

    .fd-rq-modal-heading {
      min-width: 0;
      flex: 1;
    }

    .fd-rq-modal-heading h3 {
      margin: 0;
      color: var(--fd-text);
      font-size: 12px;
      font-weight: 700;
    }

    .fd-rq-modal-heading p {
      margin: 3px 0 0;
      color: var(--fd-muted);
      font-size: 8.5px;
    }

    .fd-rq-modal-close {
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

    .fd-rq-modal-body {
      padding: 15px
    }

    .fd-rq-form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 13px;
    }

    .fd-rq-field.full {
      grid-column: 1/-1
    }

    .fd-rq-field label {
      margin-bottom: 6px;
      display: block;
      color: #42536c;
      font-size: 9px;
      font-weight: 700;
    }

    .fd-rq-field input,
    .fd-rq-field select,
    .fd-rq-field textarea {
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

    .fd-rq-field textarea {
      min-height: 88px;
      resize: vertical;
    }

    .fd-rq-field input:focus,
    .fd-rq-field select:focus,
    .fd-rq-field textarea:focus {
      border-color: #a9cf75;
      box-shadow: 0 0 0 3px rgba(116, 184, 36, .11);
    }

    .fd-rq-section {
      grid-column: 1/-1;
      margin-top: 4px;
      padding: 8px 0 3px;
      border-bottom: 1px solid #eef2f5;
      color: #31425b;
      font-size: 9px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .fd-rq-modal-footer {
      padding: 12px 15px;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      border-top: 1px solid var(--fd-border);
      background: #fbfcfd;
    }

    /* History */
    .fd-rq-history {
      display: grid;
      gap: 8px;
    }

    .fd-rq-history-item {
      padding: 10px 11px;
      border: 1px solid #e4e9ef;
      border-radius: 8px;
      background: #fbfcfd;
    }

    .fd-rq-history-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
    }

    .fd-rq-history-top strong {
      color: #263750;
      font-size: 9.5px;
    }

    .fd-rq-history-item small {
      display: block;
      margin-top: 4px;
      color: #8793a5;
      font-size: 8px;
    }

    .fd-rq-history-item p {
      margin: 7px 0 0;
      color: #56667c;
      font-size: 8.5px;
      line-height: 1.5;
    }

    /* Toast */
    .fd-rq-toast {
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

    .fd-rq-toast.show {
      opacity: 1;
      transform: translateY(0);
    }

    .fd-rq-toast.success {
      background: #5d971b
    }

    .fd-rq-toast.error {
      background: #e45b66
    }

    .fd-rq-toast.warning {
      background: #96a52f
    }

    .fd-rq-toast.info {
      background: #123d70
    }

    .fd-rq-toast-msg {
      min-width: 0;
      flex: 1;
      font-size: 8.5px;
      font-weight: 600;
    }

    .fd-rq-toast-close {
      width: 19px;
      height: 19px;
      padding: 0;
      border: 0;
      color: #fff;
      background: transparent;
      cursor: pointer;
    }

    @media(max-width:767.98px) {
      .fd-rq-head {
        flex-direction: column
      }

      .fd-rq-actions {
        width: 100%
      }

      .fd-rq-form-grid {
        grid-template-columns: 1fr
      }

      .fd-rq-field.full,
      .fd-rq-section {
        grid-column: auto
      }

      .fd-rq-search {
        width: 100%
      }

      .fd-rq-spacer {
        display: none
      }
    }

    @media(max-width:575.98px) {
      .fd-rq-stat {
        min-height: 102px;
        padding: 15px 17px;
      }

      .fd-rq-stat-icon {
        width: 54px;
        height: 54px;
        flex-basis: 54px;
      }

      .fd-rq-stat-value {
        font-size: 29px
      }

      .fd-rq-filter {
        flex: 1
      }

      .fd-rq-modal-footer {
        flex-direction: column-reverse
      }

      .fd-rq-modal-footer .fd-rq-btn {
        width: 100%
      }

      .fd-rq-toast {
        top: 72px;
        left: 12px;
        right: 12px;
        width: auto;
      }
    }

    /* Jobs page */
    a,
    a:link,
    a:visited,
    a:hover,
    a:focus,
    a:active {
      text-decoration: none !important
    }

    .fd-job-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 18px
    }

    .fd-job-title {
      margin: 0 0 7px;
      color: var(--fd-text);
      font-size: 21px;
      font-weight: 700
    }

    .fd-job-sub {
      margin: 0;
      max-width: 880px;
      color: var(--fd-muted);
      font-size: 10.5px;
      line-height: 1.55
    }

    .fd-job-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap
    }

    .fd-job-btn {
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
      font-size: 10px;
      font-weight: 700;
      cursor: pointer
    }

    .fd-job-btn.primary {
      border-color: var(--fd-green);
      color: #fff;
      background: linear-gradient(90deg, #7fc92d, #68aa1d);
      box-shadow: 0 7px 16px rgba(104, 170, 29, .16)
    }

    .fd-job-btn.danger {
      border-color: #ffd5d9;
      color: #b9444d
    }

    .fd-job-btn:disabled {
      opacity: .55;
      cursor: not-allowed
    }

    .fd-job-loader {
      width: 13px;
      height: 13px;
      display: none;
      border: 2px dotted currentColor;
      border-radius: 50%;
      animation: jobSpin .75s linear infinite
    }

    .fd-job-btn.loading .fd-job-loader {
      display: inline-block
    }

    @keyframes jobSpin {
      to {
        transform: rotate(360deg)
      }
    }

    .fd-job-summary {
      margin-bottom: 16px
    }

    .fd-job-stat {
      min-height: 112px;
      padding: 18px 20px;
      border: 1px solid #dfe6ef;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 3px 12px rgba(24, 45, 76, .035)
    }

    .fd-job-stat-row {
      min-height: 72px;
      display: flex;
      align-items: center;
      gap: 18px
    }

    .fd-job-stat-icon {
      width: 58px;
      height: 58px;
      flex: 0 0 58px;
      display: grid;
      place-items: center;
      border-radius: 16px;
      color: #fff;
      background: #123f73;
      font-size: 25px
    }

    .fd-job-stat-label {
      display: block;
      margin-bottom: 8px;
      color: #506784;
      font-size: 13px
    }

    .fd-job-stat-value {
      display: block;
      color: #020b16;
      font-size: 31px;
      line-height: 1;
      font-weight: 700
    }

    .fd-job-toolbar {
      padding: 13px 14px;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      border-bottom: 1px solid var(--fd-border);
      background: #fbfcfd
    }

    .fd-job-search {
      width: 270px;
      position: relative
    }

    .fd-job-search i {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #8a96a7
    }

    .fd-job-search input,
    .fd-job-filter {
      height: 39px;
      border: 1px solid #dde4ec;
      border-radius: 8px;
      outline: 0;
      color: #33445f;
      background: #fff;
      font-size: 10px
    }

    .fd-job-search input {
      width: 100%;
      padding: 8px 11px 8px 34px
    }

    .fd-job-filter {
      min-width: 135px;
      padding: 8px 10px
    }

    .fd-job-spacer {
      margin-left: auto
    }

    .fd-job-table-wrap {
      overflow-x: auto;
      overflow-y: hidden;
      scrollbar-width: thin;
      scrollbar-color: #9aa0a6 transparent
    }

    .fd-job-table-wrap::-webkit-scrollbar {
      height: 3px !important
    }

    .fd-job-table-wrap::-webkit-scrollbar-track {
      background: transparent !important
    }

    .fd-job-table-wrap::-webkit-scrollbar-thumb {
      min-width: 20px;
      border-radius: 999px !important;
      background: #9aa0a6 !important
    }

    .fd-job-table-wrap::-webkit-scrollbar-button {
      display: none !important;
      width: 0 !important;
      height: 0 !important
    }

    .fd-job-table {
      width: 100%;
      min-width: 1420px;
      border-collapse: collapse;
      white-space: nowrap
    }

    .fd-job-table th {
      padding: 11px 12px;
      border-bottom: 1px solid var(--fd-border);
      color: #65738a;
      background: #f8fafc;
      font-size: 9px;
      font-weight: 700;
      text-align: left;
      text-transform: uppercase
    }

    .fd-job-table td {
      padding: 12px;
      border-bottom: 1px solid #f1f3f7;
      color: #33445f;
      font-size: 9.5px;
      vertical-align: middle
    }

    .fd-job-table th:first-child,
    .fd-job-table td:first-child {
      width: 55px;
      text-align: center
    }

    .fd-job-table tbody tr:hover {
      background: #fbfcfa
    }

    .fd-job-main strong,
    .fd-job-main small,
    .fd-job-client strong,
    .fd-job-client small {
      display: block
    }

    .fd-job-main strong {
      color: #123d70;
      font-size: 10.5px
    }

    .fd-job-main small,
    .fd-job-client small {
      margin-top: 3px;
      color: #8995a6;
      font-size: 8.3px
    }

    .fd-job-client strong {
      color: #17233b;
      font-size: 10px
    }

    .fd-job-badge {
      min-height: 22px;
      padding: 4px 7px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 5px;
      font-size: 8.3px;
      font-weight: 700;
      text-transform: capitalize
    }

    .fd-job-badge.draft,
    .fd-job-badge.normal {
      color: #123d70;
      background: #edf2f7
    }

    .fd-job-badge.active,
    .fd-job-badge.completed,
    .fd-job-badge.closed,
    .fd-job-badge.ready_to_invoice,
    .fd-job-badge.low {
      color: #5d971b;
      background: #f0f8e5
    }

    .fd-job-badge.scheduled,
    .fd-job-badge.upcoming,
    .fd-job-badge.today,
    .fd-job-badge.in_progress,
    .fd-job-badge.high {
      color: #a85a08;
      background: #fff4df
    }

    .fd-job-badge.waiting_customer,
    .fd-job-badge.waiting_material,
    .fd-job-badge.needs_review,
    .fd-job-badge.rescheduled {
      color: #8a5e10;
      background: #fff7df
    }

    .fd-job-badge.cancelled,
    .fd-job-badge.archived,
    .fd-job-badge.urgent {
      color: #bd2f3a;
      background: #fff0f1
    }

    .fd-job-badge.invoiced {
      color: #5b4dad;
      background: #f1efff
    }

    .fd-job-row-actions {
      display: flex;
      align-items: center;
      gap: 4px;
      min-width: 110px
    }

    .fd-job-icon {
      width: 29px;
      height: 29px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 0;
      border-radius: 6px;
      color: #66748b;
      background: transparent;
      cursor: pointer
    }

    .fd-job-icon:hover {
      color: var(--fd-green-dark);
      background: var(--fd-green-soft)
    }

    .fd-job-icon.danger:hover {
      color: #b9444d;
      background: #fff0f1
    }

    .fd-job-empty {
      padding: 28px 18px !important;
      text-align: center;
      color: #9aa4b3 !important;
      font-size: 10px !important
    }

    .fd-job-pagination {
      min-height: 49px;
      padding: 10px 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      border-top: 1px solid var(--fd-border);
      color: #768397;
      background: #fff;
      font-size: 9px
    }

    .fd-job-modal-bg {
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

    .fd-job-modal-bg.show {
      display: flex
    }

    .fd-job-modal {
      width: min(980px, 100%);
      max-height: calc(100vh - 34px);
      overflow: auto;
      border: 1px solid #dfe5ec;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 24px 65px rgba(0, 17, 49, .24)
    }

    .fd-job-modal.small {
      width: min(610px, 100%)
    }

    .fd-job-modal-head {
      min-height: 58px;
      padding: 11px 14px;
      display: flex;
      align-items: center;
      gap: 10px;
      border-bottom: 1px solid var(--fd-border);
      background: #fbfcfd
    }

    .fd-job-modal-head h3 {
      margin: 0;
      color: var(--fd-text);
      font-size: 12px
    }

    .fd-job-modal-head p {
      margin: 3px 0 0;
      color: var(--fd-muted);
      font-size: 8.5px
    }

    .fd-job-modal-copy {
      min-width: 0;
      flex: 1
    }

    .fd-job-modal-icon {
      width: 34px;
      height: 34px;
      display: grid;
      place-items: center;
      border-radius: 9px;
      color: var(--fd-green-dark);
      background: var(--fd-green-soft)
    }

    .fd-job-close {
      width: 30px;
      height: 30px;
      border: 0;
      border-radius: 7px;
      background: transparent;
      color: #8490a0
    }

    .fd-job-modal-body {
      padding: 15px
    }

    .fd-job-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 13px
    }

    .fd-job-field.full {
      grid-column: 1/-1
    }

    .fd-job-section {
      grid-column: 1/-1;
      margin-top: 4px;
      padding: 8px 0 4px;
      border-bottom: 1px solid #eef2f5;
      color: #31425b;
      font-size: 9px;
      font-weight: 700;
      text-transform: uppercase
    }

    .fd-job-field label {
      display: block;
      margin-bottom: 6px;
      color: #42536c;
      font-size: 9px;
      font-weight: 700
    }

    .fd-job-field input,
    .fd-job-field select,
    .fd-job-field textarea {
      width: 100%;
      min-height: 40px;
      padding: 8px 10px;
      border: 1px solid #dfe5ec;
      border-radius: 8px;
      outline: 0;
      color: #263750;
      background: #fff;
      font-size: 10px
    }

    .fd-job-field textarea {
      min-height: 86px;
      resize: vertical
    }

    .fd-job-assignment {
      grid-column: 1/-1;
      padding: 12px;
      border: 1px solid #e4e9ef;
      border-radius: 9px;
      background: #fbfcfd
    }

    .fd-job-hint {
      margin-top: 5px;
      color: #8793a5;
      font-size: 8px
    }

    .fd-job-modal-footer {
      padding: 12px 15px;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      border-top: 1px solid var(--fd-border);
      background: #fbfcfd
    }

    .fd-job-toast {
      width: min(300px, calc(100vw - 24px));
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
      transition: .18s
    }

    .fd-job-toast.show {
      opacity: 1;
      transform: translateY(0)
    }

    .fd-job-toast.success {
      background: #5d971b
    }

    .fd-job-toast.error {
      background: #e45b66
    }

    .fd-job-toast.warning {
      background: #96a52f
    }

    .fd-job-toast.info {
      background: #123d70
    }

    .fd-job-toast-msg {
      flex: 1;
      font-size: 8.5px;
      font-weight: 600
    }

    .fd-job-toast-close {
      border: 0;
      color: #fff;
      background: transparent
    }

    .select2-container {
      width: 100% !important
    }

    .select2-container .select2-selection--single {
      height: 40px !important;
      border: 1px solid #dfe5ec !important;
      border-radius: 8px !important
    }

    .select2-container .select2-selection--single .select2-selection__rendered {
      height: 38px !important;
      padding: 0 31px 0 10px !important;
      display: flex !important;
      align-items: center !important;
      color: #263750 !important;
      font-size: 10px !important
    }

    .select2-container .select2-selection--single .select2-selection__arrow {
      height: 38px !important
    }

    .select2-container .select2-selection--multiple {
      min-height: 40px !important;
      border: 1px solid #dfe5ec !important;
      border-radius: 8px !important
    }

    .select2-dropdown {
      z-index: 20000 !important;
      border: 1px solid #dfe5ec !important
    }

    .select2-results__option {
      font-size: 9px !important
    }

    @media(max-width:767.98px) {
      .fd-job-head {
        flex-direction: column
      }

      .fd-job-grid {
        grid-template-columns: 1fr
      }

      .fd-job-field.full,
      .fd-job-section,
      .fd-job-assignment {
        grid-column: auto
      }

      .fd-job-search {
        width: 100%
      }

      .fd-job-spacer {
        display: none
      }
    }

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
    .fieldplx-main-content{padding-top:0!important}
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
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/includes/nav.php'; ?>
  <div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
      <div class="fieldplx-content-wrapper">
        <div class="fd-dashboard ql-page">
          <section class="ql-header">
            <h1 class="ql-title">Quotes</h1>
            <div class="ql-header-actions">
              <?php if ($quotationCanCreate): ?><a class="ql-btn primary" href="add-quotation">New Quote</a><?php endif; ?>
              <div class="ql-more-wrap" id="topMoreWrap">
                <button type="button" class="ql-btn" id="topMoreButton" aria-expanded="false"><i class="bi bi-three-dots"></i> More Actions</button>
                <div class="ql-menu" id="topMoreMenu" aria-hidden="true">
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
              <h2 class="ql-card-title">Conversion rate <i class="bi bi-question-circle" style="font-size:11px;color:#6d808a"></i></h2>
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
      </div>
    </main>
  </div>

  <div class="ql-row-menu" id="rowMenu" aria-hidden="true">
    <a class="ql-menu-item" href="#" id="rowConvert"><i class="bi bi-hammer"></i><span id="rowConvertText">Convert to Job</span></a>
    <button type="button" class="ql-menu-item danger" id="rowDelete"><i class="bi bi-trash"></i> Delete</button>
    <div class="ql-menu-sep"></div>
    <a class="ql-menu-item" href="#" id="rowOpenNew" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Open in New Tab</a>
  </div>

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

  <div class="ql-modal-backdrop" id="confirmModal" aria-hidden="true">
    <section class="ql-modal small" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
      <div class="ql-modal-head"><h3 id="confirmTitle">Delete quote?</h3><button type="button" class="ql-modal-close" id="confirmClose"><i class="bi bi-x-lg"></i></button></div>
      <div class="ql-confirm-body" id="confirmCopy">Are you sure you want to delete this quote?</div>
      <div class="ql-confirm-footer"><button type="button" class="ql-btn" id="confirmCancel">Cancel</button><button type="button" class="ql-btn danger" id="confirmDelete">Delete</button></div>
    </section>
  </div>

  <div class="ql-toast info" id="toast"><span id="toastMsg">Notification</span></div>
  <?php require_once __DIR__ . '/includes/footer.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
    function parseResponse(r){return r.text().then(function(raw){var t=String(raw||'').trim(),d;try{d=t?JSON.parse(t):{}}catch(e){throw new Error(t.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!r.ok||!d.success)throw new Error(d.message||'Request failed.');return d})}
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
</body>
</html>
