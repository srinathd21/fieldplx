<?php
/* FieldPlx Payments - Version 1.1.0 - 2026-09-02 - Payment Ledger + Invoice/Customer Actions */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Payments';
$activePage = 'payments';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function fpPayDb()
{
    global $pdo, $db;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    throw new RuntimeException('Database connection is not available.');
}

function fpPayH($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fpPayTableExists(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];

    $q = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name"
    );
    $q->execute(array(':table_name' => $table));
    $cache[$table] = ((int)$q->fetchColumn() > 0);
    return $cache[$table];
}

function fpPayColumnExists(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    $q = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name"
    );
    $q->execute(array(':table_name' => $table, ':column_name' => $column));
    $cache[$key] = ((int)$q->fetchColumn() > 0);
    return $cache[$key];
}

function fpPayRows(PDO $pdo, $sql, array $params = array())
{
    try {
        $q = $pdo->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('FieldPlx payments rows failed: ' . $e->getMessage());
        return array();
    }
}

function fpPayScalar(PDO $pdo, $sql, array $params = array(), $default = 0)
{
    try {
        $q = $pdo->prepare($sql);
        $q->execute($params);
        $value = $q->fetchColumn();
        return ($value === false || $value === null) ? $default : $value;
    } catch (Throwable $e) {
        error_log('FieldPlx payments scalar failed: ' . $e->getMessage());
        return $default;
    }
}

function fpPayDate($value)
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : '';
}

function fpPayMoney($value, $symbol, $position, $decimals)
{
    $amount = number_format((float)$value, (int)$decimals, '.', ',');
    if ($symbol === '') return $amount;
    return $position === 'after' ? $amount . ' ' . $symbol : $symbol . ' ' . $amount;
}

function fpPayDateTime($value)
{
    $value = trim((string)$value);
    if ($value === '') return '-';
    $ts = strtotime($value);
    return $ts ? date('d-m-Y h:i A', $ts) : $value;
}

function fpPayQueryString(array $overrides = array())
{
    $query = $_GET;
    unset($query['page'], $query['export']);
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return http_build_query($query);
}

try {
    $pdo = fpPayDb();
} catch (Throwable $e) {
    error_log('FieldPlx Payments DB: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load payments.');
}

$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : (isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0);
$userId = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
$sessionBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($tenantId <= 0 || $userId <= 0) {
    header('Location: login.php');
    exit;
}

$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');
$from = fpPayDate(isset($_GET['from']) ? $_GET['from'] : '') ?: $defaultFrom;
$to = fpPayDate(isset($_GET['to']) ? $_GET['to'] : '') ?: $today;
if ($from > $to) {
    $tmp = $from;
    $from = $to;
    $to = $tmp;
}

$branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
if ($sessionBranchId > 0) $branchId = $sessionBranchId;

$search = trim((string)(isset($_GET['search']) ? $_GET['search'] : ''));
$status = trim((string)(isset($_GET['status']) ? $_GET['status'] : ''));
$method = trim((string)(isset($_GET['method']) ? $_GET['method'] : ''));
$channel = trim((string)(isset($_GET['channel']) ? $_GET['channel'] : ''));
$paymentId = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($perPage, array(10, 25, 50, 100), true)) $perPage = 25;
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);

$branches = array();
if (fpPayTableExists($pdo, 'branches')) {
    $branches = fpPayRows(
        $pdo,
        "SELECT id, name, branch_code, is_head_office
         FROM branches
         WHERE tenant_id = :tenant_id AND status = 'active'
         ORDER BY is_head_office DESC, name ASC",
        array(':tenant_id' => $tenantId)
    );
}

$currency = array(
    'symbol' => '',
    'symbol_position' => 'before',
    'decimal_places' => 2,
    'currency_code' => ''
);
if (fpPayTableExists($pdo, 'tenants') && fpPayTableExists($pdo, 'currencies')) {
    $currencyRows = fpPayRows(
        $pdo,
        "SELECT c.symbol, c.symbol_position, c.decimal_places, c.currency_code
         FROM tenants t
         LEFT JOIN currencies c ON c.id = t.currency_id
         WHERE t.id = :tenant_id
         LIMIT 1",
        array(':tenant_id' => $tenantId)
    );
    if (!empty($currencyRows[0])) $currency = array_merge($currency, $currencyRows[0]);
}

$symbol = isset($currency['symbol']) ? (string)$currency['symbol'] : '';
$symbolPosition = isset($currency['symbol_position']) ? (string)$currency['symbol_position'] : 'before';
$decimalPlaces = isset($currency['decimal_places']) ? (int)$currency['decimal_places'] : 2;

$paymentsTableExists = fpPayTableExists($pdo, 'payments');
$clientsTableExists = fpPayTableExists($pdo, 'clients');
$invoicesTableExists = fpPayTableExists($pdo, 'invoices');
$branchesTableExists = fpPayTableExists($pdo, 'branches');

$rows = array();
$totalRows = 0;
$totalPages = 1;
$summary = array(
    'count' => 0,
    'amount' => 0,
    'total_count' => 0,
    'total_amount' => 0,
    'succeeded_count' => 0,
    'succeeded_amount' => 0,
    'pending_count' => 0,
    'pending_amount' => 0
);
$statusOptions = array();
$methodOptions = array();
$channelOptions = array();
$creditSummary = array('count' => 0, 'amount' => 0);

if ($invoicesTableExists) {
    $creditWhere = array("tenant_id = :tenant_id", "balance_due > 0", "status NOT IN ('cancelled','archived','written_off')");
    $creditParams = array(':tenant_id' => $tenantId);
    if ($branchId > 0 && fpPayColumnExists($pdo, 'invoices', 'branch_id')) {
        $creditWhere[] = 'branch_id = :branch_id';
        $creditParams[':branch_id'] = $branchId;
    }
    if (fpPayColumnExists($pdo, 'invoices', 'issue_date')) {
        $creditWhere[] = 'issue_date BETWEEN :credit_from AND :credit_to';
        $creditParams[':credit_from'] = $from;
        $creditParams[':credit_to'] = $to;
    }
    $creditRows = fpPayRows(
        $pdo,
        "SELECT COUNT(*) AS credit_count, COALESCE(SUM(balance_due),0) AS credit_amount FROM invoices WHERE " . implode(' AND ', $creditWhere),
        $creditParams
    );
    if (!empty($creditRows[0])) {
        $creditSummary['count'] = (int)$creditRows[0]['credit_count'];
        $creditSummary['amount'] = (float)$creditRows[0]['credit_amount'];
    }
}

if ($paymentsTableExists) {
    $joins = '';
    $selectClient = "'' AS client_name, '' AS client_phone";
    $selectInvoice = "'' AS invoice_no";
    $selectBranch = "'' AS branch_name, '' AS branch_code";

    if ($clientsTableExists) {
        $joins .= " LEFT JOIN clients c ON c.id = p.client_id AND c.tenant_id = p.tenant_id ";
        $selectClient = "COALESCE(c.display_name,'') AS client_name, COALESCE(c.phone,'') AS client_phone";
    }
    if ($invoicesTableExists) {
        $joins .= " LEFT JOIN invoices i ON i.id = p.invoice_id AND i.tenant_id = p.tenant_id ";
        $selectInvoice = "COALESCE(i.invoice_no,'') AS invoice_no";
    }
    if ($branchesTableExists) {
        $joins .= " LEFT JOIN branches b ON b.id = p.branch_id AND b.tenant_id = p.tenant_id ";
        $selectBranch = "COALESCE(b.name,'') AS branch_name, COALESCE(b.branch_code,'') AS branch_code";
    }

    $where = array("p.tenant_id = :tenant_id");
    $params = array(':tenant_id' => $tenantId);

    if ($paymentId > 0) {
        $where[] = 'p.id = :payment_id';
        $params[':payment_id'] = $paymentId;
    }

    $dateExpr = fpPayColumnExists($pdo, 'payments', 'received_at')
        ? "COALESCE(p.received_at, p.created_at)"
        : "p.created_at";

    if ($paymentId <= 0) {
        $where[] = "{$dateExpr} BETWEEN :from_date AND :to_date";
        $params[':from_date'] = $from . ' 00:00:00';
        $params[':to_date'] = $to . ' 23:59:59';
    }

    if ($branchId > 0 && fpPayColumnExists($pdo, 'payments', 'branch_id')) {
        $where[] = 'p.branch_id = :branch_id';
        $params[':branch_id'] = $branchId;
    }

    if ($status !== '' && fpPayColumnExists($pdo, 'payments', 'status')) {
        $where[] = 'p.status = :status';
        $params[':status'] = $status;
    }

    if ($method !== '' && fpPayColumnExists($pdo, 'payments', 'payment_method')) {
        $where[] = 'p.payment_method = :payment_method';
        $params[':payment_method'] = $method;
    }

    if ($channel !== '' && fpPayColumnExists($pdo, 'payments', 'payment_channel')) {
        $where[] = 'p.payment_channel = :payment_channel';
        $params[':payment_channel'] = $channel;
    }

    if ($search !== '') {
        $searchParts = array();
        if (fpPayColumnExists($pdo, 'payments', 'payment_no')) $searchParts[] = 'p.payment_no LIKE :search';
        if ($clientsTableExists) {
            $searchParts[] = 'c.display_name LIKE :search';
            $searchParts[] = 'c.phone LIKE :search';
        }
        if ($invoicesTableExists) $searchParts[] = 'i.invoice_no LIKE :search';
        if (fpPayColumnExists($pdo, 'payments', 'provider')) $searchParts[] = 'p.provider LIKE :search';
        if (fpPayColumnExists($pdo, 'payments', 'provider_payment_id')) $searchParts[] = 'p.provider_payment_id LIKE :search';
        if ($searchParts) {
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
            $params[':search'] = '%' . $search . '%';
        }
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);

    $totalRows = (int)fpPayScalar(
        $pdo,
        "SELECT COUNT(*) FROM payments p {$joins} {$whereSql}",
        $params,
        0
    );

    $summarySql = "SELECT
        COUNT(*) AS total_count,
        COALESCE(SUM(p.amount),0) AS total_amount,
        SUM(CASE WHEN p.status='succeeded' THEN 1 ELSE 0 END) AS succeeded_count,
        COALESCE(SUM(CASE WHEN p.status='succeeded' THEN p.amount ELSE 0 END),0) AS succeeded_amount,
        SUM(CASE WHEN p.status='pending' THEN 1 ELSE 0 END) AS pending_count,
        COALESCE(SUM(CASE WHEN p.status='pending' THEN p.amount ELSE 0 END),0) AS pending_amount
        FROM payments p {$joins} {$whereSql}";
    $summaryRows = fpPayRows($pdo, $summarySql, $params);
    if (!empty($summaryRows[0])) {
        $summary = array_merge($summary, $summaryRows[0]);
    }

    if (fpPayColumnExists($pdo, 'payments', 'status')) {
        $statusRows = fpPayRows(
            $pdo,
            "SELECT DISTINCT status FROM payments WHERE tenant_id=:tenant_id AND status IS NOT NULL AND status<>'' ORDER BY status",
            array(':tenant_id' => $tenantId)
        );
        foreach ($statusRows as $r) $statusOptions[] = (string)$r['status'];
    }

    if (fpPayColumnExists($pdo, 'payments', 'payment_method')) {
        $methodRows = fpPayRows(
            $pdo,
            "SELECT DISTINCT payment_method FROM payments WHERE tenant_id=:tenant_id AND payment_method IS NOT NULL AND payment_method<>'' ORDER BY payment_method",
            array(':tenant_id' => $tenantId)
        );
        foreach ($methodRows as $r) $methodOptions[] = (string)$r['payment_method'];
    }

    if (fpPayColumnExists($pdo, 'payments', 'payment_channel')) {
        $channelRows = fpPayRows(
            $pdo,
            "SELECT DISTINCT payment_channel FROM payments WHERE tenant_id=:tenant_id AND payment_channel IS NOT NULL AND payment_channel<>'' ORDER BY payment_channel",
            array(':tenant_id' => $tenantId)
        );
        foreach ($channelRows as $r) $channelOptions[] = (string)$r['payment_channel'];
    }

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $paymentNoExpr = fpPayColumnExists($pdo, 'payments', 'payment_no') ? "COALESCE(p.payment_no,'')" : "CONCAT('PAY-', p.id)";
    $methodExpr = fpPayColumnExists($pdo, 'payments', 'payment_method') ? "COALESCE(p.payment_method,'')" : "''";
    $channelExpr = fpPayColumnExists($pdo, 'payments', 'payment_channel') ? "COALESCE(p.payment_channel,'')" : "''";
    $statusExpr = fpPayColumnExists($pdo, 'payments', 'status') ? "COALESCE(p.status,'')" : "''";
    $amountExpr = fpPayColumnExists($pdo, 'payments', 'amount') ? "COALESCE(p.amount,0)" : "0";
    $receivedExpr = fpPayColumnExists($pdo, 'payments', 'received_at') ? "p.received_at" : "NULL";
    $createdExpr = fpPayColumnExists($pdo, 'payments', 'created_at') ? "p.created_at" : "NULL";
    $providerExpr = fpPayColumnExists($pdo, 'payments', 'provider') ? "COALESCE(p.provider,'')" : "''";
    $referenceExpr = fpPayColumnExists($pdo, 'payments', 'provider_payment_id') ? "COALESCE(p.provider_payment_id,'')" : "''";
    $notesExpr = fpPayColumnExists($pdo, 'payments', 'notes') ? "COALESCE(p.notes,'')" : "''";

    $rows = fpPayRows(
        $pdo,
        "SELECT p.id, p.client_id, p.invoice_id,
                {$paymentNoExpr} AS payment_no,
                {$amountExpr} AS amount,
                {$methodExpr} AS payment_method,
                {$channelExpr} AS payment_channel,
                {$statusExpr} AS status,
                {$receivedExpr} AS received_at,
                {$createdExpr} AS created_at,
                {$providerExpr} AS provider,
                {$referenceExpr} AS provider_payment_id,
                {$notesExpr} AS notes,
                {$selectClient},
                {$selectInvoice},
                {$selectBranch}
         FROM payments p
         {$joins}
         {$whereSql}
         ORDER BY {$dateExpr} DESC, p.id DESC
         LIMIT " . (int)$perPage . " OFFSET " . (int)$offset,
        $params
    );

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $exportRows = fpPayRows(
            $pdo,
            "SELECT p.id, p.client_id, p.invoice_id,
                    {$paymentNoExpr} AS payment_no,
                    {$amountExpr} AS amount,
                    {$methodExpr} AS payment_method,
                    {$channelExpr} AS payment_channel,
                    {$statusExpr} AS status,
                    {$receivedExpr} AS received_at,
                    {$createdExpr} AS created_at,
                    {$providerExpr} AS provider,
                    {$referenceExpr} AS provider_payment_id,
                    {$notesExpr} AS notes,
                    {$selectClient},
                    {$selectInvoice},
                    {$selectBranch}
             FROM payments p
             {$joins}
             {$whereSql}
             ORDER BY {$dateExpr} DESC, p.id DESC",
            $params
        );

        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="fieldplx-payments-' . $from . '-to-' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('S.No', 'Payment No', 'Date & Time', 'Customer', 'Phone', 'Invoice', 'Method', 'Channel', 'Provider', 'Reference', 'Amount', 'Status', 'Branch'));
        $s = 1;
        foreach ($exportRows as $r) {
            fputcsv($out, array(
                $s++,
                $r['payment_no'],
                $r['received_at'] ?: $r['created_at'],
                $r['client_name'],
                $r['client_phone'],
                $r['invoice_no'],
                $r['payment_method'],
                $r['payment_channel'],
                $r['provider'],
                $r['provider_payment_id'],
                $r['amount'],
                $r['status'],
                $r['branch_name']
            ));
        }
        fclose($out);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payments - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <style>
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
  --fd-red: #e45b66;
  --fd-bg: #f6f8fb;
  --fd-text: #0b1933;
  --fd-muted: #6f7b90;
  --fd-border: #e5eaf1;
}

* { box-sizing: border-box; }
html, body { min-height: 100%; }
body {
  margin: 0;
  min-height: 100vh;
  overflow-x: hidden;
  background: var(--fd-bg) !important;
  color: var(--fd-text);
  font-family: Arial, Helvetica, sans-serif !important;
  font-size: 14px;
}

/* =========================================================
   Shared FieldPlx topbar
   ========================================================= */
.fieldplx-topbar {
  min-height: var(--fieldplx-topbar-height) !important;
  margin-left: var(--fieldplx-sidebar-width);
  width: calc(100% - var(--fieldplx-sidebar-width));
  position: sticky !important;
  top: 0;
  z-index: 1030;
  background: #ffffff !important;
  border-bottom: 1px solid var(--fd-border) !important;
  box-shadow: 0 3px 14px rgba(0, 17, 49, 0.035);
  backdrop-filter: none !important;
  transition: margin-left .25s ease, width .25s ease;
}

body.fieldplx-sidebar-collapsed .fieldplx-topbar {
  margin-left: var(--fieldplx-sidebar-collapsed-width);
  width: calc(100% - var(--fieldplx-sidebar-collapsed-width));
}

.fieldplx-topbar-inner {
  min-height: var(--fieldplx-topbar-height) !important;
  padding: 0 27px !important;
  display: flex !important;
  align-items: center !important;
  gap: 13px !important;
}

.fieldplx-page-heading { display: none !important; }

.fieldplx-menu-toggle,
.fieldplx-topbar-action {
  width: 41px !important;
  height: 41px !important;
  padding: 0 !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
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
  position: relative;
}

.fieldplx-search-icon {
  position: absolute;
  top: 50%;
  left: 13px;
  z-index: 2;
  transform: translateY(-50%);
  color: #8795a8;
  pointer-events: none;
}

.fieldplx-search-input {
  width: 100%;
  height: 41px !important;
  padding: 8px 13px 8px 38px !important;
  border: 0 !important;
  border-radius: 8px !important;
  background: #f5f8fb !important;
  color: var(--fd-text) !important;
  box-shadow: none !important;
  font-size: 12px !important;
}

.fieldplx-search-input:focus {
  background: #f5f8fb !important;
  box-shadow: 0 0 0 3px rgba(116, 184, 36, .14) !important;
}

.fieldplx-profile-button {
  min-width: 0;
  padding: 2px !important;
  display: flex !important;
  align-items: center !important;
  gap: 9px !important;
  border: 0 !important;
  border-radius: 9px !important;
  background: transparent !important;
  text-align: left;
}

.fieldplx-profile-button:hover { background: var(--fd-green-soft) !important; }

.fieldplx-avatar {
  width: 38px !important;
  height: 38px !important;
  flex: 0 0 38px !important;
  overflow: hidden;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  border: 0 !important;
  border-radius: 50% !important;
  color: var(--fd-navy) !important;
  background: linear-gradient(135deg, #ffffff, #e8f3d9) !important;
  font-size: 12px !important;
  font-weight: 800 !important;
}

.fieldplx-avatar img { width: 100%; height: 100%; object-fit: cover; }
.fieldplx-profile-details { max-width: 145px; min-width: 0; }
.fieldplx-profile-name,
.fieldplx-profile-role { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.fieldplx-profile-name { color: var(--fd-text) !important; font-size: 12px !important; font-weight: 700; }
.fieldplx-profile-role { margin-top: 1px; color: var(--fd-muted) !important; font-size: 10px !important; }
.fieldplx-notification-count { background: var(--fd-red) !important; }

.fieldplx-dropdown,
.fieldplx-profile-menu {
  border: 1px solid var(--fd-border) !important;
  background: #ffffff !important;
  box-shadow: 0 18px 45px rgba(29, 38, 74, .14) !important;
}

.fieldplx-dropdown { width: 340px; max-width: calc(100vw - 24px); margin-top: 10px !important; border-radius: 14px !important; overflow: hidden; }
.fieldplx-dropdown-header { border-bottom: 1px solid var(--fd-border) !important; background: #fff !important; }
.fieldplx-dropdown-footer { border-top: 1px solid var(--fd-border) !important; background: #fff !important; }
.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover { color: var(--fd-green-dark) !important; }
#topbarNotificationList { max-height: 300px; overflow-y: auto; background: #fff; }
.fieldplx-notification-item:hover,
.fieldplx-notification-item.is-unread { background: #f8fbf3 !important; }
.fieldplx-notification-icon { color: var(--fd-green-dark) !important; background: var(--fd-green-soft) !important; }
.fieldplx-empty-notifications { background: #fff !important; }
.fieldplx-empty-notifications i { color: #9fca68 !important; }
.fieldplx-profile-menu { width: 230px; border-radius: 12px !important; }

/* =========================================================
   Shared FieldPlx sidebar
   ========================================================= */
.fieldplx-sidebar {
  width: var(--fieldplx-sidebar-width) !important;
  min-width: var(--fieldplx-sidebar-width) !important;
  height: 100vh !important;
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  z-index: 1045 !important;
  display: flex !important;
  flex-direction: column !important;
  color: #fff !important;
  background: linear-gradient(180deg, var(--fd-navy-light), var(--fd-navy)) !important;
  border-right: 0 !important;
  transition: width .25s ease, min-width .25s ease, transform .25s ease !important;
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
  width: var(--fieldplx-sidebar-collapsed-width) !important;
  min-width: var(--fieldplx-sidebar-collapsed-width) !important;
}

.fieldplx-sidebar-header {
  min-height: 68px !important;
  padding: 9px 14px 10px !important;
  display: flex !important;
  align-items: center !important;
  border-bottom: 1px solid rgba(255, 255, 255, .08) !important;
}

.fieldplx-sidebar-brand {
  min-width: 0;
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  color: #fff !important;
  text-decoration: none !important;
}

.fieldplx-sidebar-logo,
.fieldplx-sidebar-logo-placeholder {
  width: 40px !important;
  height: 40px !important;
  flex: 0 0 40px !important;
  border-radius: 10px !important;
}

.fieldplx-sidebar-logo { object-fit: contain; background: #fff !important; }
.fieldplx-sidebar-logo-placeholder {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  color: #fff !important;
  background: linear-gradient(135deg, #8fd236, #68aa1d) !important;
  font-size: 18px !important;
  font-weight: 700 !important;
}

.fieldplx-sidebar-brand-text { min-width: 0; display: block; }
.fieldplx-sidebar-company-name {
  max-width: 155px !important;
  display: block;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  color: #fff !important;
  font-size: 16px !important;
  font-weight: 700 !important;
}
.fieldplx-sidebar-product-name {
  margin-top: 1px;
  display: block;
  color: #9fda55 !important;
  font-size: 9px !important;
  font-weight: 600;
  letter-spacing: .4px;
  text-transform: uppercase;
}

.fieldplx-sidebar-close {
  width: 34px;
  height: 34px;
  margin-left: auto;
  padding: 0;
  display: none;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 8px;
  color: rgba(255,255,255,.88);
  background: rgba(255,255,255,.08);
}

.fieldplx-sidebar-body {
  min-height: 0 !important;
  flex: 1 1 auto !important;
  overflow-x: hidden !important;
  overflow-y: auto !important;
  padding: 12px 14px !important;
  scrollbar-width: none !important;
}
.fieldplx-sidebar-body::-webkit-scrollbar { display: none; }
.fieldplx-sidebar-section-label {
  margin: 7px 12px !important;
  color: rgba(255, 255, 255, .5) !important;
  font-size: 9px !important;
  font-weight: 700;
  letter-spacing: .65px;
  text-transform: uppercase;
}
.fieldplx-sidebar-nav { display: flex; flex-direction: column; gap: 3px !important; }
.fieldplx-sidebar-link {
  width: 100%;
  min-height: 46px !important;
  margin-bottom: 3px !important;
  padding: 0 14px !important;
  display: flex !important;
  align-items: center !important;
  gap: 15px !important;
  border: 0 !important;
  border-radius: 9px !important;
  color: rgba(255, 255, 255, .94) !important;
  background: transparent !important;
  text-align: left;
  text-decoration: none !important;
  font-family: inherit;
  font-size: 14px !important;
  font-weight: 600 !important;
}
.fieldplx-sidebar-link:hover { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-link.active,
.fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link {
  color: #fff !important;
  background: linear-gradient(90deg, #7fc92d, #68aa1d) !important;
  box-shadow: 0 6px 18px rgba(0,17,49,.28) !important;
}
.fieldplx-sidebar-link-icon {
  width: 21px !important;
  height: 21px !important;
  flex: 0 0 21px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  font-size: 19px !important;
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
  color: rgba(255,255,255,.65) !important;
  font-size: 10px;
  transition: transform .2s ease;
}
.fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-arrow { transform: rotate(180deg); }
.fieldplx-sidebar-submenu {
  display: block;
  max-height: 0;
  overflow: hidden;
  padding: 0 0 0 36px !important;
  transition: max-height .25s ease, padding-top .25s ease, padding-bottom .25s ease;
}
.fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu {
  max-height: 680px;
  padding-top: 4px !important;
  padding-bottom: 5px !important;
}
.fieldplx-sidebar-sublink {
  min-height: 34px !important;
  padding: 7px 9px;
  display: flex;
  align-items: center;
  border-radius: 7px;
  color: rgba(255,255,255,.72) !important;
  text-decoration: none;
  font-size: 11px !important;
  font-weight: 500;
}
.fieldplx-sidebar-sublink::before {
  width: 5px;
  height: 5px;
  margin-right: 9px;
  flex: 0 0 5px;
  content: "";
  border-radius: 50%;
  background: rgba(255,255,255,.35) !important;
}
.fieldplx-sidebar-sublink:hover,
.fieldplx-sidebar-sublink.active { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-sublink.active::before { background: #9fda55 !important; }

.fieldplx-sidebar-footer {
  flex: 0 0 auto !important;
  padding: 10px 14px 14px !important;
  border-top: 1px solid rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-user {
  min-height: 62px;
  padding: 8px;
  display: flex !important;
  align-items: center !important;
  gap: 9px;
  border-radius: 10px;
  background: rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-user-avatar {
  width: 38px !important;
  height: 38px !important;
  flex: 0 0 38px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  overflow: hidden;
  border-radius: 50% !important;
  color: var(--fd-navy) !important;
  background: linear-gradient(135deg,#fff,#e8f3d9) !important;
}
.fieldplx-sidebar-user-avatar img { width: 100%; height: 100%; object-fit: cover; }
.fieldplx-sidebar-user-details { min-width: 0; flex: 1; }
.fieldplx-sidebar-user-name,
.fieldplx-sidebar-user-role { display: block; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.fieldplx-sidebar-user-name { color: #fff !important; font-size: 12px !important; font-weight: 700; }
.fieldplx-sidebar-user-role { margin-top: 1px; color: rgba(255,255,255,.6) !important; font-size: 9px !important; }
.fieldplx-sidebar-logout {
  width: 29px;
  height: 29px;
  flex: 0 0 29px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  color: rgba(255,255,255,.7) !important;
  text-decoration: none;
}
.fieldplx-sidebar-logout:hover { color: #fff !important; background: rgba(228,91,102,.3) !important; }
.fieldplx-sidebar-overlay { display: none; }

body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout { display: none; }
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header { justify-content: center !important; padding-left: 8px !important; padding-right: 8px !important; }
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link { justify-content: center !important; padding-left: 8px !important; padding-right: 8px !important; }
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user { justify-content: center !important; padding-left: 5px !important; padding-right: 5px !important; }

/* =========================================================
   Main content and footer
   ========================================================= */
.fieldplx-main-layout { display: block !important; min-height: calc(100vh - var(--fieldplx-topbar-height)) !important; }
.fieldplx-main-content {
  margin-left: var(--fieldplx-sidebar-width);
  min-width: 0;
  transition: margin-left .25s ease;
}
body.fieldplx-sidebar-collapsed .fieldplx-main-content { margin-left: var(--fieldplx-sidebar-collapsed-width); }
.fieldplx-content-wrapper { padding: 0 !important; }
.fieldplx-footer {
  display: block !important;
  min-height: 52px;
  margin-left: var(--fieldplx-sidebar-width) !important;
  border-top: 1px solid var(--fieldplx-border);
  background: #fff;
  transition: margin-left .22s ease, background-color .22s ease !important;
}
body.fieldplx-sidebar-collapsed .fieldplx-footer { margin-left: var(--fieldplx-sidebar-collapsed-width) !important; }
.fieldplx-footer-inner {
  min-height: 52px;
  padding: 10px 18px;
  display: flex;
  align-items: center;
  gap: 18px;
  color: #6b7280;
  font-size: 10px;
}
.fieldplx-footer-links { display: flex; align-items: center; gap: 8px; }
.fieldplx-footer-links a { color: #6b7280; text-decoration: none; }
.fieldplx-footer-links a:hover { color: var(--fieldplx-primary); }
.fieldplx-footer-product { margin-left: auto; white-space: nowrap; color: #9ca3af; }
.fieldplx-footer-product strong { color: var(--fieldplx-primary); font-weight: 700; }

/* =========================================================
   Reports page
   ========================================================= */
.fd-dashboard {
  width: 100%;
  max-width: 1600px;
  margin: auto;
  padding: 25px 27px 35px;
}
.fd-dashboard .row > * { min-width: 0; }

.fr-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
  margin-bottom: 18px;
}
.fr-title { margin: 0 0 7px; color: var(--fd-text); font-size: 21px; line-height: 1.2; font-weight: 700; }
.fr-sub { margin: 0; max-width: 820px; color: var(--fd-muted); font-size: 10.5px; line-height: 1.55; }
.fr-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.fr-btn {
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
  box-shadow: 0 4px 12px rgba(31,43,88,.04);
  font-size: 10px;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
  transition: border-color .16s ease, color .16s ease, background .16s ease, box-shadow .16s ease;
}
.fr-btn:hover { border-color: #cfe3ae; color: var(--fd-green-dark); background: #f9fcf4; }
.fr-btn.primary {
  border-color: var(--fd-green);
  color: #fff;
  background: linear-gradient(90deg,#7fc92d,#68aa1d);
  box-shadow: 0 7px 16px rgba(104,170,29,.18);
}
.fr-btn.primary:hover { color: #fff; background: linear-gradient(90deg,#74b824,#5d971b); }

.fr-filter-card,
.fr-card {
  border: 1px solid var(--fd-border);
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 3px 12px rgba(24,45,76,.035);
}
.fr-filter-card { padding: 13px 14px; margin-bottom: 16px; }
.fr-filter { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; }
.fr-field { min-width: 160px; }
.fr-field label {
  display: block;
  margin-bottom: 6px;
  color: #506784;
  font-size: 9px;
  line-height: 1.2;
  font-weight: 600;
  text-transform: uppercase;
}
.fr-input {
  width: 100%;
  height: 39px;
  padding: 8px 10px;
  border: 1px solid #dde4ec;
  border-radius: 8px;
  color: #33445f;
  background: #fff;
  font-size: 10px;
  outline: 0;
}
.fr-input:focus { border-color: #a9cf75; box-shadow: 0 0 0 3px rgba(116,184,36,.11); }
.fr-input:disabled { color: #8490a0; background: #f6f8fa; cursor: not-allowed; }
.fr-filter-spacer { margin-left: auto; }

.fr-summary { margin-bottom: 16px; }
.fr-stat {
  height: 100%;
  min-height: 112px;
  padding: 18px 20px;
  border: 1px solid #dfe6ef;
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 3px 12px rgba(24,45,76,.035);
}
.fr-stat-row { min-height: 72px; display: flex; align-items: center; gap: 18px; }
.fr-stat-row > div { min-width: 0; }
.fr-stat-icon {
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
.fr-stat-icon i { line-height: 1; }
.fr-stat-label { display: block; margin-bottom: 8px; color: #506784; font-size: 13px; line-height: 1.2; font-weight: 400; }
.fr-stat-value {
  display: block;
  max-width: 100%;
  overflow: hidden;
  color: #020b16;
  font-size: 27px;
  line-height: 1.05;
  font-weight: 700;
  letter-spacing: -.35px;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.fr-stat-note { display: block; margin-top: 7px; color: #8a96a7; font-size: 8.5px; line-height: 1.35; }

.fr-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 16px; margin-bottom: 16px; }
.fr-card { min-width: 0; overflow: hidden; }
.fr-card-head {
  min-height: 54px;
  padding: 13px 15px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border-bottom: 1px solid var(--fd-border);
  background: #fbfcfd;
}
.fr-card-head > div { min-width: 0; }
.fr-card-head h2 { margin: 0; color: var(--fd-text); font-size: 13px; line-height: 1.25; font-weight: 700; }
.fr-card-head p { margin: 3px 0 0; color: var(--fd-muted); font-size: 9px; line-height: 1.35; }
.fr-card-head > i { flex: 0 0 auto; color: var(--fd-green-dark) !important; font-size: 16px; }
.fr-card-body { padding: 14px; }

.fr-status-list { display: flex; flex-direction: column; gap: 8px; }
.fr-status-row {
  min-height: 42px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border: 1px solid #eef1f5;
  border-radius: 8px;
  background: #fff;
}
.fr-status-row:hover { border-color: #e2e8ef; background: #fbfcfd; }
.fr-status-dot { width: 8px; height: 8px; flex: 0 0 8px; border-radius: 50%; background: var(--fd-green); }
.fr-status-name { min-width: 0; flex: 1; overflow: hidden; color: #33445f; font-size: 10px; text-transform: capitalize; text-overflow: ellipsis; white-space: nowrap; }
.fr-status-count { color: var(--fd-navy); font-size: 10px; font-weight: 700; }
.fr-status-amount { min-width: 90px; text-align: right; color: #6f7b90; font-size: 9px; }

.fr-table-wrap {
  width: 100%;
  overflow-x: auto;
  overflow-y: hidden;
  scrollbar-width: thin;
  scrollbar-color: #9aa0a6 transparent;
}
.fr-table-wrap::-webkit-scrollbar { height: 3px; }
.fr-table-wrap::-webkit-scrollbar-track { background: transparent; }
.fr-table-wrap::-webkit-scrollbar-thumb { border-radius: 999px; background: #9aa0a6; }
.fr-table { width: 100%; min-width: 780px; margin: 0; border-collapse: collapse; white-space: nowrap; }
.fr-table th {
  padding: 11px 12px;
  border-bottom: 1px solid var(--fd-border);
  color: #65738a;
  background: #f8fafc;
  font-size: 9px;
  font-weight: 600;
  text-align: left;
  text-transform: uppercase;
}
.fr-table td {
  padding: 12px;
  border-bottom: 1px solid #f1f3f7;
  color: #33445f;
  font-size: 9.5px;
  vertical-align: middle;
}
.fr-table tbody tr:last-child td { border-bottom: 0; }
.fr-table tbody tr:hover { background: #fbfcfa; }
.fr-name { display: block; color: var(--fd-text); font-weight: 700; }
.fr-muted { display: block; margin-top: 2px; color: #8d98a8; font-size: 8.5px; }
.fr-badge {
  display: inline-flex;
  align-items: center;
  padding: 5px 7px;
  border-radius: 5px;
  color: #5d971b;
  background: #f0f8e5;
  font-size: 8.5px;
  font-weight: 600;
  text-transform: capitalize;
}
.fr-badge.blue { color: #123d70; background: #edf2f7; }
.fr-badge.gray { color: #6f7b90; background: #eef2f6; }
.fr-empty { padding: 28px 18px !important; color: #9aa4b3 !important; text-align: center !important; font-size: 10px !important; }
.fr-section-gap { margin-top: 16px; }

/* =========================================================
   Responsive shell and reports
   ========================================================= */
@media (max-width: 991.98px) {
  html, body { overflow-x: hidden !important; }
  body.fieldplx-sidebar-mobile-open { overflow: hidden !important; }

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

  .fieldplx-footer,
  body.fieldplx-sidebar-collapsed .fieldplx-footer { margin-left: 0 !important; }

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
    transform: translate3d(-100%,0,0) !important;
    border-right: 0 !important;
    box-shadow: none !important;
    filter: none !important;
    transition: transform .25s ease, visibility .25s ease !important;
    will-change: transform;
  }

  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar,
  body.fieldplx-sidebar-mobile-open.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    visibility: visible !important;
    transform: translate3d(0,0,0) !important;
  }

  .fieldplx-sidebar-header,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header {
    flex: 0 0 auto !important;
    justify-content: flex-start !important;
    padding-left: 14px !important;
    padding-right: 10px !important;
  }

  .fieldplx-sidebar-close {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
  }

  .fieldplx-sidebar-body {
    min-height: 0 !important;
    flex: 1 1 auto !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
  }
  .fieldplx-sidebar-footer { flex: 0 0 auto !important; }

  .fieldplx-sidebar-brand-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
  .fieldplx-sidebar-section-label,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
  .fieldplx-sidebar-link-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
  .fieldplx-sidebar-user-details,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details { display: block !important; }

  .fieldplx-sidebar-arrow,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
  .fieldplx-sidebar-logout,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout { display: inline-flex !important; }

  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user { justify-content: flex-start !important; }

  .fieldplx-sidebar-submenu,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu {
    display: block !important;
    max-height: 0 !important;
    overflow: hidden !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    transition: max-height .25s ease, padding-top .25s ease, padding-bottom .25s ease !important;
  }
  .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu {
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
    background: rgba(0,17,49,.48) !important;
    transition: opacity .25s ease, visibility .25s ease !important;
  }
  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay {
    visibility: visible !important;
    opacity: 1 !important;
    pointer-events: auto !important;
  }

  .fieldplx-brand-mobile { display: flex !important; }
  .fieldplx-page-heading { display: none !important; }
  .fieldplx-profile-details { display: none; }
  .fr-grid { grid-template-columns: 1fr; }
}

@media (max-width: 767.98px) {
  :root { --fieldplx-topbar-height: 64px; }
  .fieldplx-topbar,
  .fieldplx-topbar-inner { min-height: 64px !important; }
  .fieldplx-topbar-inner { padding: 0 13px !important; gap: 8px !important; }
  .fieldplx-search-wrap { display: none !important; }
  .fieldplx-dropdown { width: min(330px, calc(100vw - 22px)); }

  .fd-dashboard { padding: 17px 13px 28px; }
  .fr-head { flex-direction: column; gap: 13px; }
  .fr-title { font-size: 19px; }
  .fr-sub { max-width: 100%; font-size: 10.5px; }
  .fr-actions { width: 100%; }
  .fr-actions .fr-btn { flex: 1; }

  .fr-filter { align-items: stretch; }
  .fr-field { width: 100%; min-width: 0; }
  .fr-filter-spacer { display: none; }
  .fr-filter .fr-btn { flex: 1; }

  .fr-stat { min-height: 102px; padding: 15px 17px; }
  .fr-stat-row { min-height: 66px; gap: 15px; }
  .fr-stat-icon { width: 54px; height: 54px; flex-basis: 54px; border-radius: 15px; font-size: 23px; }
  .fr-stat-value { font-size: 24px; }
  .fr-card-head { align-items: flex-start; }
  .fr-card-head .fr-btn { min-height: 34px; padding: 0 10px; }

  .fieldplx-footer-inner { padding: 12px; flex-wrap: wrap; justify-content: center; gap: 7px 14px; text-align: center; }
  .fieldplx-footer-product { width: 100%; margin-left: 0; }
}

@media (max-width: 575.98px) {
  .fieldplx-sidebar,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar { width: min(288px, calc(100vw - 44px)) !important; }
  .fieldplx-sidebar-body { padding-left: 10px !important; padding-right: 10px !important; }
  .fieldplx-sidebar-link { min-height: 43px !important; padding-left: 12px !important; padding-right: 12px !important; gap: 12px !important; font-size: 13px !important; }
  .fieldplx-sidebar-submenu { padding-left: 31px !important; }
  .fieldplx-sidebar-sublink { min-height: 33px !important; font-size: 11px !important; }

  .fr-status-row { gap: 8px; }
  .fr-status-amount { min-width: 74px; }
  .fr-card-head { padding: 12px; }
  .fr-card-body { padding: 12px; }
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
                <section class="fr-head">
                    <div>
                        <h1 class="fr-title">Payments</h1>
                        <!-- <p class="fr-sub">View and track customer collections, including split Cash, Card and Online payment rows, with invoice and customer links. Credit remains on the invoice as outstanding balance until collected.</p> -->
                    </div>
                    <div class="fr-actions">
                        <a class="fr-btn" href="invoices.php"><i class="bi bi-receipt"></i> Invoices</a>
                        <a class="fr-btn" href="payments.php"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                        <a class="fr-btn" href="?<?= fpPayH(fpPayQueryString(array('export' => 'csv'))) ?>"><i class="bi bi-download"></i> Export CSV</a>
                        <!-- <a class="fr-btn primary" href="payments.php"><i class="bi bi-cash-stack"></i> Collect Payment</a> -->
                    </div>
                </section>

                <section class="fr-filter-card">
                    <form class="fr-filter" method="get" action="payments.php">
                        <div class="fr-field" style="min-width:220px;flex:1 1 220px;">
                            <label>Search</label>
                            <input class="fr-input" type="search" name="search" value="<?= fpPayH($search) ?>" placeholder="Payment no, customer, invoice, provider or reference">
                        </div>

                        <div class="fr-field">
                            <label>From Date</label>
                            <input class="fr-input" type="date" name="from" value="<?= fpPayH($from) ?>">
                        </div>

                        <div class="fr-field">
                            <label>To Date</label>
                            <input class="fr-input" type="date" name="to" value="<?= fpPayH($to) ?>">
                        </div>

                        <div class="fr-field">
                            <label>Status</label>
                            <select class="fr-input" name="status">
                                <option value="">All Statuses</option>
                                <?php foreach ($statusOptions as $option): ?>
                                    <option value="<?= fpPayH($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= fpPayH(ucwords(str_replace('_', ' ', $option))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fr-field">
                            <label>Payment Method</label>
                            <select class="fr-input" name="method">
                                <option value="">All Methods</option>
                                <?php foreach ($methodOptions as $option): ?>
                                    <option value="<?= fpPayH($option) ?>" <?= $method === $option ? 'selected' : '' ?>><?= fpPayH(ucwords(str_replace('_', ' ', $option))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fr-field">
                            <label>Channel</label>
                            <select class="fr-input" name="channel">
                                <option value="">All Channels</option>
                                <?php foreach ($channelOptions as $option): ?>
                                    <option value="<?= fpPayH($option) ?>" <?= $channel === $option ? 'selected' : '' ?>><?= fpPayH(ucwords(str_replace('_', ' ', $option))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fr-field">
                            <label>Branch</label>
                            <select class="fr-input" name="branch_id" <?= $sessionBranchId > 0 ? 'disabled' : '' ?>>
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= (int)$branch['id'] ?>" <?= $branchId === (int)$branch['id'] ? 'selected' : '' ?>>
                                        <?= fpPayH($branch['name']) ?><?= !empty($branch['branch_code']) ? ' (' . fpPayH($branch['branch_code']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($sessionBranchId > 0): ?>
                                <input type="hidden" name="branch_id" value="<?= (int)$sessionBranchId ?>">
                            <?php endif; ?>
                        </div>

                        <div class="fr-field" style="min-width:105px;">
                            <label>Per Page</label>
                            <select class="fr-input" name="per_page">
                                <?php foreach (array(10,25,50,100) as $size): ?>
                                    <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button class="fr-btn primary" type="submit"><i class="bi bi-funnel"></i> Apply Filter</button>
                    </form>
                </section>

                <section class="row g-3 fr-summary">
                    <div class="col-xl-3 col-md-6">
                        <article class="fr-stat">
                            <div class="fr-stat-row">
                                <span class="fr-stat-icon"><i class="bi bi-receipt"></i></span>
                                <div>
                                    <span class="fr-stat-label">Total Payments</span>
                                    <strong class="fr-stat-value"><?= (int)$summary['count'] ?: (int)$summary['total_count'] ?></strong>
                                    <span class="fr-stat-note">Payments in selected period</span>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <article class="fr-stat">
                            <div class="fr-stat-row">
                                <span class="fr-stat-icon"><i class="bi bi-cash-stack"></i></span>
                                <div>
                                    <span class="fr-stat-label">Total Amount</span>
                                    <strong class="fr-stat-value"><?= fpPayH(fpPayMoney($summary['amount'] ?: $summary['total_amount'], $symbol, $symbolPosition, $decimalPlaces)) ?></strong>
                                    <span class="fr-stat-note">All filtered payment records</span>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <article class="fr-stat">
                            <div class="fr-stat-row">
                                <span class="fr-stat-icon"><i class="bi bi-check2-circle"></i></span>
                                <div>
                                    <span class="fr-stat-label">Successful Collections</span>
                                    <strong class="fr-stat-value"><?= fpPayH(fpPayMoney($summary['succeeded_amount'], $symbol, $symbolPosition, $decimalPlaces)) ?></strong>
                                    <span class="fr-stat-note"><?= (int)$summary['succeeded_count'] ?> successful payments</span>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <article class="fr-stat">
                            <div class="fr-stat-row">
                                <span class="fr-stat-icon"><i class="bi bi-hourglass-split"></i></span>
                                <div>
                                    <span class="fr-stat-label">Credit / Outstanding</span>
                                    <strong class="fr-stat-value"><?= fpPayH(fpPayMoney($creditSummary['amount'], $symbol, $symbolPosition, $decimalPlaces)) ?></strong>
                                    <span class="fr-stat-note"><?= (int)$creditSummary['count'] ?> invoice<?= (int)$creditSummary['count'] === 1 ? '' : 's' ?> with balance due</span>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>

                <section class="fr-card">
                    <div class="fr-card-head">
                        <div>
                            <h2>Payment Ledger</h2>
                            <p>Customer collection transactions for the selected filters</p>
                        </div>
                        <span style="color:var(--fd-muted);font-size:9px;">
                            <?= (int)$totalRows ?> record<?= $totalRows === 1 ? '' : 's' ?>
                        </span>
                    </div>

                    <div class="fr-table-wrap">
                        <table class="fr-table" style="min-width:1370px;">
                            <thead>
                                <tr>
                                    <th>S.No</th>
                                    <th>Payment</th>
                                    <th>Date &amp; Time</th>
                                    <th>Customer</th>
                                    <th>Invoice</th>
                                    <th>Method</th>
                                    <th>Channel</th>
                                    <th>Provider / Reference</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Branch</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$paymentsTableExists): ?>
                                <tr><td colspan="12" class="fr-empty">Payments table is not available.</td></tr>
                            <?php elseif (!$rows): ?>
                                <tr><td colspan="12" class="fr-empty">No payments found for the selected filters.</td></tr>
                            <?php else: ?>
                                <?php $serial = (($page - 1) * $perPage) + 1; ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                        $statusValue = strtolower(trim((string)$row['status']));
                                        $badgeClass = '';
                                        if ($statusValue === 'succeeded' || $statusValue === 'paid' || $statusValue === 'completed') {
                                            $badgeClass = '';
                                        } elseif ($statusValue === 'pending' || $statusValue === 'processing') {
                                            $badgeClass = 'blue';
                                        } else {
                                            $badgeClass = 'gray';
                                        }
                                    ?>
                                    <tr>
                                        <td><?= $serial++ ?></td>
                                        <td>
                                            <span class="fr-name"><?= fpPayH($row['payment_no'] ?: ('PAY-' . $row['id'])) ?></span>
                                            <span class="fr-muted">ID: <?= (int)$row['id'] ?></span>
                                        </td>
                                        <td><?= fpPayH(fpPayDateTime($row['received_at'] ?: $row['created_at'])) ?></td>
                                        <td>
                                            <span class="fr-name"><?= fpPayH($row['client_name'] ?: '-') ?></span>
                                            <?php if (!empty($row['client_phone'])): ?><span class="fr-muted"><?= fpPayH($row['client_phone']) ?></span><?php endif; ?>
                                        </td>
                                        <td><?= fpPayH($row['invoice_no'] ?: '-') ?></td>
                                        <td><?= fpPayH($row['payment_method'] !== '' ? ucwords(str_replace('_', ' ', $row['payment_method'])) : '-') ?></td>
                                        <td><?= fpPayH($row['payment_channel'] !== '' ? ucwords(str_replace('_', ' ', $row['payment_channel'])) : '-') ?></td>
                                        <td>
                                            <span class="fr-name"><?= fpPayH($row['provider'] !== '' ? ucwords(str_replace('_', ' ', $row['provider'])) : '-') ?></span>
                                            <?php if (!empty($row['provider_payment_id'])): ?><span class="fr-muted"><?= fpPayH($row['provider_payment_id']) ?></span><?php endif; ?>
                                        </td>
                                        <td><span class="fr-name"><?= fpPayH(fpPayMoney($row['amount'], $symbol, $symbolPosition, $decimalPlaces)) ?></span></td>
                                        <td><span class="fr-badge <?= fpPayH($badgeClass) ?>"><?= fpPayH($row['status'] !== '' ? ucwords(str_replace('_', ' ', $row['status'])) : '-') ?></span></td>
                                        <td>
                                            <?= fpPayH($row['branch_name'] ?: '-') ?>
                                            <?php if (!empty($row['branch_code'])): ?><span class="fr-muted"><?= fpPayH($row['branch_code']) ?></span><?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display:flex;gap:5px;align-items:center;">
                                                <?php if (!empty($row['invoice_id'])): ?>
                                                    <a class="fr-btn" style="min-height:30px;padding:0 9px;" href="invoice-view.php?invoice_id=<?= (int)$row['invoice_id'] ?>" title="View invoice"><i class="bi bi-receipt"></i></a>
                                                <?php endif; ?>
                                                <?php if (!empty($row['client_id'])): ?>
                                                    <a class="fr-btn" style="min-height:30px;padding:0 9px;" href="client-view.php?client_id=<?= (int)$row['client_id'] ?>" title="View customer"><i class="bi bi-person"></i></a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="min-height:49px;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-top:1px solid var(--fd-border);font-size:9px;color:#768397;background:#fff;">
                        <span>
                            <?php if ($totalRows > 0): ?>
                                Showing <?= (($page - 1) * $perPage) + 1 ?>-<?= min($page * $perPage, $totalRows) ?> of <?= (int)$totalRows ?> payments
                            <?php else: ?>
                                Showing 0 payments
                            <?php endif; ?>
                        </span>
                        <div style="display:flex;gap:5px;">
                            <?php if ($page > 1): ?>
                                <a class="fr-btn" style="min-height:32px;padding:0 10px;" href="?<?= fpPayH(fpPayQueryString(array('page' => $page - 1))) ?>"><i class="bi bi-chevron-left"></i> Previous</a>
                            <?php else: ?>
                                <span class="fr-btn" style="min-height:32px;padding:0 10px;opacity:.45;pointer-events:none;"><i class="bi bi-chevron-left"></i> Previous</span>
                            <?php endif; ?>

                            <span class="fr-btn" style="min-height:32px;padding:0 11px;pointer-events:none;">Page <?= (int)$page ?> / <?= (int)$totalPages ?></span>

                            <?php if ($page < $totalPages): ?>
                                <a class="fr-btn" style="min-height:32px;padding:0 10px;" href="?<?= fpPayH(fpPayQueryString(array('page' => $page + 1))) ?>">Next <i class="bi bi-chevron-right"></i></a>
                            <?php else: ?>
                                <span class="fr-btn" style="min-height:32px;padding:0 10px;opacity:.45;pointer-events:none;">Next <i class="bi bi-chevron-right"></i></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
