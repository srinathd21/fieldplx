<?php
/**
 * FieldPlx - Quote View
 *
 * Upload as:
 * /public_html/quote-view.php
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
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode(
            'quote-view.php?id=' .
            (
                isset($_GET['id'])
                    ? (int) $_GET['id']
                    : 0
            )
        )
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'quotes.view',
        'You do not have permission to view quotes.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Quote Details - FieldPlx';
$activePage = 'quotes';
$searchPlaceholder = 'Search quotes...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];

$quoteId = 0;

if (
    isset($_GET['id']) &&
    (int) $_GET['id'] > 0
) {
    $quoteId = (int) $_GET['id'];
} elseif (
    isset($_GET['quote_id']) &&
    (int) $_GET['quote_id'] > 0
) {
    $quoteId = (int) $_GET['quote_id'];
}

if ($quoteId <= 0) {
    $_SESSION['flash_error'] =
        'Please select a quote to view.';

    header('Location: quotes.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/

$canManage = function_exists('hasPermission')
    ? hasPermission('quotes.manage')
    : true;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('quoteViewFetchAssoc')) {
    function quoteViewFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('quoteViewFetchAll')) {
    function quoteViewFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('quoteViewMoney')) {
    function quoteViewMoney($amount)
    {
        return number_format(
            (float) $amount,
            2,
            '.',
            ','
        );
    }
}

if (!function_exists('quoteViewQty')) {
    function quoteViewQty($quantity)
    {
        $formatted = number_format(
            (float) $quantity,
            3,
            '.',
            ''
        );

        return rtrim(
            rtrim($formatted, '0'),
            '.'
        );
    }
}

if (!function_exists('quoteViewDate')) {
    function quoteViewDate($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp =
            strtotime((string) $value);

        return $timestamp
            ? date('d M Y', $timestamp)
            : '—';
    }
}

if (!function_exists('quoteViewDateTime')) {
    function quoteViewDateTime($value)
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

if (!function_exists('quoteViewStatusClass')) {
    function quoteViewStatusClass($status)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $status))
        );
    }
}

if (!function_exists('quoteViewStatusLabel')) {
    function quoteViewStatusLabel($status)
    {
        return ucwords(
            str_replace(
                '_',
                ' ',
                (string) $status
            )
        );
    }
}

/*
|--------------------------------------------------------------------------
| Load quote
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        q.id,
        q.quote_no,
        q.client_id,
        q.property_id,
        q.request_id,
        q.assessment_id,
        q.template_id,
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
        q.converted_job_id,
        q.financing_required,
        q.financing_status,
        q.created_by,
        q.created_at,
        q.updated_at,
        q.archived_at,

        c.display_name AS client_name,
        c.company_name AS client_company,
        c.email AS client_email,
        c.phone AS client_phone,

        p.name AS property_name,
        p.address_line1 AS property_address_line1,
        p.address_line2 AS property_address_line2,
        p.city AS property_city,
        p.state AS property_state,
        p.postal_code AS property_postal_code,
        p.country AS property_country,

        r.request_no,
        r.title AS request_title,
        r.status AS request_status,

        j.job_no AS converted_job_no,
        j.title AS converted_job_title,

        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN u.last_name IS NOT NULL
                 AND u.last_name <> ''
                THEN CONCAT(' ', u.last_name)
                ELSE ''
            END
        ) AS salesperson_name,

        CONCAT(
            COALESCE(cu.first_name, ''),
            CASE
                WHEN cu.last_name IS NOT NULL
                 AND cu.last_name <> ''
                THEN CONCAT(' ', cu.last_name)
                ELSE ''
            END
        ) AS created_by_name

    FROM quotes q

    INNER JOIN clients c
        ON c.id = q.client_id
       AND c.tenant_id = q.tenant_id

    LEFT JOIN properties p
        ON p.id = q.property_id
       AND p.tenant_id = q.tenant_id

    LEFT JOIN requests r
        ON r.id = q.request_id
       AND r.tenant_id = q.tenant_id

    LEFT JOIN jobs j
        ON j.id = q.converted_job_id
       AND j.tenant_id = q.tenant_id
       AND j.deleted_at IS NULL

    LEFT JOIN users u
        ON u.id = q.salesperson_id
       AND u.tenant_id = q.tenant_id
       AND u.deleted_at IS NULL

    LEFT JOIN users cu
        ON cu.id = q.created_by
       AND cu.tenant_id = q.tenant_id
       AND cu.deleted_at IS NULL

    WHERE q.id = ?
      AND q.tenant_id = ?
      AND q.archived_at IS NULL
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit(
        'Unable to prepare quote details: ' .
        e($conn->error)
    );
}

$stmt->bind_param(
    'ii',
    $quoteId,
    $tenantId
);

$stmt->execute();
$quote = quoteViewFetchAssoc($stmt);
$stmt->close();

if (!$quote) {
    http_response_code(404);
    exit('Quote not found.');
}

/*
|--------------------------------------------------------------------------
| Load line items
|--------------------------------------------------------------------------
*/

$lineItems = array();

$stmt = $conn->prepare("
    SELECT
        qli.id,
        qli.product_service_id,
        qli.item_name,
        qli.description,
        qli.quantity,
        qli.unit_cost,
        qli.unit_price,
        qli.markup_percent,
        qli.margin_percent,
        qli.discount_amount,
        qli.tax_rate_id,
        qli.tax_amount,
        qli.line_total,
        qli.sort_order,
        tr.name AS tax_name,
        tr.rate AS tax_rate,
        ps.item_type,
        ps.unit_name
    FROM quote_line_items qli

    LEFT JOIN tax_rates tr
        ON tr.id = qli.tax_rate_id
       AND tr.tenant_id = qli.tenant_id

    LEFT JOIN product_services ps
        ON ps.id = qli.product_service_id
       AND ps.tenant_id = qli.tenant_id

    WHERE qli.quote_id = ?
      AND qli.tenant_id = ?

    ORDER BY
        qli.sort_order ASC,
        qli.id ASC
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $quoteId,
        $tenantId
    );

    $stmt->execute();
    $lineItems =
        quoteViewFetchAll($stmt);

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Derived values
|--------------------------------------------------------------------------
*/

$propertyTitle = '—';
$propertyAddress = '—';

if (!empty($quote['property_id'])) {
    $propertyTitle =
        trim((string) $quote['property_name']) !== ''
            ? (string) $quote['property_name']
            : (string) $quote['property_address_line1'];

    $propertyAddressParts = array_filter(
        array(
            $quote['property_address_line1'],
            $quote['property_address_line2'],
            $quote['property_city'],
            $quote['property_state'],
            $quote['property_postal_code'],
            $quote['property_country']
        ),
        function ($value) {
            return trim((string) $value) !== '';
        }
    );

    $propertyAddress =
        !empty($propertyAddressParts)
            ? implode(', ', $propertyAddressParts)
            : '—';
}

$depositDescription = 'Not required';

if (!empty($quote['deposit_required'])) {
    if ($quote['deposit_type'] === 'percent') {
        $depositDescription =
            quoteViewQty(
                $quote['deposit_value']
            ) .
            '% · ' .
            quoteViewMoney(
                $quote['deposit_amount']
            );
    } else {
        $depositDescription =
            quoteViewMoney(
                $quote['deposit_amount']
            );
    }
}

require_once __DIR__ . '/includes/topbar.php';
?>

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
    --qv-navy: #001131;
    --qv-navy-light: #071f49;
    --qv-blue: #123d70;
    --qv-primary: #74b824;
    --qv-primary-dark: #5d971b;
    --qv-primary-soft: #f0f8e5;
    --qv-red: #e45b66;
    --qv-bg: #f6f8fb;
    --qv-text: #0b1933;
    --qv-muted: #6f7b90;
    --qv-border: #e5eaf1;
}

body {
    background: var(--qv-bg) !important;
    color: var(--qv-text);
    font-family: Arial, Helvetica, sans-serif !important;
    font-size: 14px;
}

/* Exact new FieldPlx dashboard shell */
.fieldplx-topbar {
    min-height: 70px !important;
    margin-left: var(--fieldplx-sidebar-width);
    width: calc(100% - var(--fieldplx-sidebar-width));
    background: #fff !important;
    border-bottom: 1px solid var(--qv-border) !important;
    box-shadow: 0 3px 14px rgba(0,17,49,.035);
    backdrop-filter: none !important;
    transition: margin-left .25s ease, width .25s ease;
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
.fieldplx-page-heading { display: none !important; }
.fieldplx-menu-toggle,
.fieldplx-topbar-action {
    width: 41px !important;
    height: 41px !important;
    border: 0 !important;
    border-radius: 9px !important;
    color: var(--qv-navy) !important;
    background: transparent !important;
}
.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover {
    color: var(--qv-navy) !important;
    background: var(--qv-primary-soft) !important;
}
.fieldplx-search-wrap { width: 280px !important; margin-left: auto; }
.fieldplx-search-input {
    height: 41px !important;
    padding-left: 38px !important;
    border: 0 !important;
    border-radius: 8px !important;
    background: #f5f8fb !important;
    color: var(--qv-text) !important;
    font-size: 14px !important;
}
.fieldplx-search-input:focus {
    background: #f5f8fb !important;
    box-shadow: 0 0 0 3px rgba(116,184,36,.14) !important;
}
.fieldplx-profile-button {
    padding: 2px !important;
    border: 0 !important;
    border-radius: 9px !important;
    background: transparent !important;
}
.fieldplx-profile-button:hover { background: var(--qv-primary-soft) !important; }
.fieldplx-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    border: 0 !important;
    color: var(--qv-navy) !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
    font-size: 14px !important;
    font-weight: 800 !important;
}
.fieldplx-profile-name { font-size: 14px !important; }
.fieldplx-profile-role { color: var(--qv-muted) !important; font-size: 12px !important; }
.fieldplx-notification-count { background: var(--qv-red) !important; }
.fieldplx-dropdown,
.fieldplx-profile-menu {
    border-color: var(--qv-border) !important;
    box-shadow: 0 18px 45px rgba(29,38,74,.14) !important;
}
.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover { color: var(--qv-primary-dark) !important; }

.fieldplx-sidebar {
    width: var(--fieldplx-sidebar-width) !important;
    min-width: var(--fieldplx-sidebar-width) !important;
    height: 100vh !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    z-index: 1045 !important;
    color: #fff !important;
    background: linear-gradient(180deg,var(--qv-navy-light),var(--qv-navy)) !important;
    border-top: 4px solid var(--qv-primary) !important;
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
    border-bottom: 1px solid rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-brand { color: #fff !important; }
.fieldplx-sidebar-logo,
.fieldplx-sidebar-logo-placeholder {
    width: 40px !important;
    height: 40px !important;
    flex: 0 0 40px !important;
    border-radius: 10px !important;
}
.fieldplx-sidebar-logo-placeholder {
    color: #fff !important;
    background: linear-gradient(135deg,#8fd236,#68aa1d) !important;
    font-size: 18px !important;
}
.fieldplx-sidebar-company-name {
    max-width: 155px !important;
    color: #fff !important;
    font-size: 16px !important;
    font-weight: 700 !important;
}
.fieldplx-sidebar-product-name { color: #9fda55 !important; font-size: 11px !important; }
.fieldplx-sidebar-body { padding: 12px 14px !important; scrollbar-width: none !important; }
.fieldplx-sidebar-body::-webkit-scrollbar { display: none; }
.fieldplx-sidebar-section-label {
    margin: 7px 12px 7px !important;
    color: rgba(255,255,255,.50) !important;
    font-size: 11px !important;
}
.fieldplx-sidebar-nav { gap: 3px !important; }
.fieldplx-sidebar-link {
    min-height: 46px !important;
    margin-bottom: 3px !important;
    padding: 0 14px !important;
    gap: 15px !important;
    border-radius: 9px !important;
    color: rgba(255,255,255,.94) !important;
    font-size: 15px !important;
    font-weight: 600 !important;
}
.fieldplx-sidebar-link:hover { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-link.active,
.fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link {
    color: #fff !important;
    background: linear-gradient(90deg,#7fc92d,#68aa1d) !important;
    box-shadow: 0 6px 18px rgba(0,17,49,.28) !important;
}
.fieldplx-sidebar-link-icon {
    width: 21px !important;
    height: 21px !important;
    flex: 0 0 21px !important;
    font-size: 19px !important;
}
.fieldplx-sidebar-arrow { color: rgba(255,255,255,.65) !important; }
.fieldplx-sidebar-submenu { padding-left: 36px !important; }
.fieldplx-sidebar-sublink {
    min-height: 34px !important;
    color: rgba(255,255,255,.72) !important;
    font-size: 13px !important;
}
.fieldplx-sidebar-sublink::before { background: rgba(255,255,255,.35) !important; }
.fieldplx-sidebar-sublink:hover,
.fieldplx-sidebar-sublink.active { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-sublink.active::before { background: #9fda55 !important; }
.fieldplx-sidebar-footer {
    padding: 10px 14px 14px !important;
    border-top: 1px solid rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-user { min-height: 62px; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-user-name { color: #fff !important; font-size: 14px !important; }
.fieldplx-sidebar-user-role { color: rgba(255,255,255,.60) !important; font-size: 11px !important; }
.fieldplx-sidebar-user-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    color: var(--qv-navy) !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
}
.fieldplx-sidebar-logout { color: rgba(255,255,255,.70) !important; }
.fieldplx-sidebar-logout:hover { color: #fff !important; background: rgba(228,91,102,.30) !important; }
.fieldplx-main-layout { display: block !important; min-height: calc(100vh - 70px) !important; }
.fieldplx-main-content {
    margin-left: var(--fieldplx-sidebar-width);
    min-width: 0;
    transition: margin-left .25s ease;
}
body.fieldplx-sidebar-collapsed .fieldplx-main-content { margin-left: var(--fieldplx-sidebar-collapsed-width); }
.fieldplx-content-wrapper { padding: 0 !important; }
.fieldplx-footer { display: none !important; }

/* Quote View - approved new FieldPlx component system */
.quote-view-page {
    width: 100%;
    max-width: 1600px;
    margin: auto;
    padding: 25px 27px 35px;
}

.qv-header {
    min-height: 108px;
    margin-bottom: 18px;
    padding: 20px 22px;
    display: flex;
    align-items: center;
    gap: 16px;
    border: 1px solid var(--qv-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.qv-heading-icon {
    width: 58px;
    height: 58px;
    flex: 0 0 58px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    background: linear-gradient(135deg,var(--qv-blue),var(--qv-navy));
    box-shadow: 0 8px 22px rgba(0,17,49,.16);
    font-size: 24px;
}
.qv-heading { min-width: 0; flex: 1; }
.qv-heading-row { display: flex; align-items: center; flex-wrap: wrap; gap: 9px; }
.qv-heading h1 {
    margin: 0;
    color: var(--qv-text);
    font-size: 28px;
    line-height: 1.1;
    font-weight: 700;
}
.qv-heading p {
    margin: 7px 0 0;
    max-width: 760px;
    color: var(--qv-muted);
    font-size: 14px;
    line-height: 1.5;
}
.qv-actions { display: flex; align-items: center; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
.qv-btn {
    height: 46px;
    padding: 0 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid var(--qv-border);
    border-radius: 9px;
    background: #fff;
    color: #53627a;
    box-shadow: 0 4px 14px rgba(31,43,88,.04);
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}
.qv-btn i { font-size: 14px; }
.qv-btn:hover { border-color: #cfe3ae; color: var(--qv-primary-dark); background: #f9fcf4; }
.qv-btn.primary { border-color: var(--qv-primary); color: #fff; background: var(--qv-primary); }
.qv-btn.primary:hover { border-color: var(--qv-primary-dark); color: #fff; background: var(--qv-primary-dark); }

.qv-alert {
    margin-bottom: 18px;
    padding: 13px 15px;
    border: 1px solid #cfe8b2;
    border-radius: 9px;
    background: #f4faec;
    color: #4f8118;
    font-size: 13px;
    line-height: 1.5;
}
.qv-status {
    display: inline-flex;
    align-items: center;
    padding: 6px 9px;
    border-radius: 5px;
    background: #f1f4f7;
    color: #5e6b80;
    font-size: 11px;
    line-height: 1.2;
    font-weight: 700;
    text-transform: capitalize;
}
.qv-status.sent,
.qv-status.viewed,
.qv-status.awaiting_response { color: #123d70; background: #edf2f7; }
.qv-status.approved,
.qv-status.deposit_paid,
.qv-status.converted { color: #5d971b; background: #f0f8e5; }
.qv-status.rejected,
.qv-status.expired { color: #b24b53; background: #fff0f1; }
.qv-status.changes_requested { color: #8b6d16; background: #fff8e7; }

.qv-stats {
    margin-bottom: 18px;
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 14px;
}
.qv-stat {
    position: relative;
    min-height: 170px;
    padding: 25px 20px 8px;
    overflow: hidden;
    border: 1px solid var(--qv-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.qv-stat-more { position: absolute; top: 13px; right: 11px; color: #8995a8; font-size: 18px; }
.qv-stat-row { display: flex; align-items: flex-start; gap: 18px; }
.qv-stat-icon {
    width: 52px;
    height: 52px;
    flex: 0 0 52px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    font-size: 25px;
}
.qv-stat-icon.navy { background: var(--qv-blue); }
.qv-stat-icon.green { background: var(--qv-primary); }
.qv-stat-icon.dark-green { background: var(--qv-primary-dark); }
.qv-stat-icon.soft-green { background: #96c945; }
.qv-stat-copy { min-width: 0; }
.qv-stat-label { display: block; margin-bottom: 10px; color: #66748b; font-size: 13px; font-weight: 500; }
.qv-stat-value {
    display: block;
    max-width: 245px;
    overflow: hidden;
    color: var(--qv-text);
    font-size: clamp(27px,2vw,34px);
    line-height: 1;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.qv-stat-note { display: block; margin-top: 14px; color: #8a95a8; font-size: 11px; line-height: 1.5; }
.qv-stat-note strong { color: var(--qv-primary-dark); font-size: 11px; }
.qv-stat-wave { position: absolute; right: 18px; bottom: 7px; left: 18px; height: 38px; opacity: .72; pointer-events: none; }
.qv-stat-wave svg { width: 100%; height: 100%; display: block; }
.qv-stat-wave path { fill: none; stroke: #d5e9ba; stroke-width: 2; vector-effect: non-scaling-stroke; }
.qv-stat-wave path.accent { stroke: var(--qv-primary); }

.qv-layout {
    display: grid;
    grid-template-columns: minmax(0,1.68fr) minmax(350px,.72fr);
    gap: 18px;
    align-items: start;
}
.qv-card {
    overflow: hidden;
    border: 1px solid var(--qv-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.qv-card + .qv-card { margin-top: 18px; }
.qv-card-head {
    min-height: 62px;
    padding: 17px 18px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid var(--qv-border);
    background: #fff;
}
.qv-card-head h2 { margin: 0; color: var(--qv-text); font-size: 18px; line-height: 1.2; font-weight: 700; }
.qv-card-head p { margin: 5px 0 0; color: var(--qv-muted); font-size: 12px; line-height: 1.45; }
.qv-card-body { padding: 20px 18px; }

.qv-detail-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 0;
    overflow: hidden;
    border: 1px solid #edf0f4;
    border-radius: 8px;
}
.qv-detail {
    min-width: 0;
    min-height: 92px;
    padding: 17px 18px;
    border-right: 1px solid #edf0f4;
    border-bottom: 1px solid #edf0f4;
    background: #fff;
}
.qv-detail:nth-child(2n) { border-right: 0; }
.qv-detail.full { grid-column: 1 / -1; border-right: 0; }
.qv-label {
    display: block;
    margin-bottom: 8px;
    color: #78859a;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .02em;
    text-transform: uppercase;
}
.qv-value { display: block; color: #263956; font-size: 14px; line-height: 1.65; font-weight: 600; overflow-wrap: anywhere; }
.qv-value.normal { font-weight: 500; white-space: pre-wrap; }
.qv-link { color: var(--qv-blue); font-weight: 600; text-decoration: none; }
.qv-link:hover { color: var(--qv-primary-dark); }

.qv-table-wrap { overflow-x: auto; }
.qv-table { width: 100%; min-width: 980px; border-collapse: collapse; }
.qv-table th,
.qv-table td { padding: 13px 12px; border-bottom: 1px solid #f0f2f6; text-align: left; vertical-align: middle; white-space: nowrap; }
.qv-table th { background: #fff; color: #65738a; font-size: 11px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; }
.qv-table td { color: #33445f; font-size: 13px; }
.qv-table tbody tr:hover { background: #fbfdf8; }
.qv-item-name { color: var(--qv-text); font-size: 13px; font-weight: 700; }
.qv-item-description { margin-top: 4px; display: block; max-width: 330px; color: #8793a6; font-size: 11px; white-space: normal; line-height: 1.5; }
.qv-table strong { color: var(--qv-text); font-weight: 700; }

.qv-summary { display: grid; gap: 8px; }
.qv-summary-row {
    min-height: 48px;
    padding: 11px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border: 1px solid #edf0f4;
    border-radius: 8px;
    background: #fff;
    color: #526179;
    font-size: 13px;
}
.qv-summary-row strong { color: var(--qv-text); font-size: 14px; }
.qv-summary-row.total {
    min-height: 60px;
    border-color: #dce8cc;
    background: linear-gradient(135deg,#f8fced,#f0f8e5);
    color: var(--qv-primary-dark);
    font-size: 15px;
    font-weight: 700;
}
.qv-summary-row.total strong { color: var(--qv-primary-dark); font-size: 20px; }
.qv-empty { padding: 42px 18px; color: #8995a8; font-size: 13px; text-align: center; }

@media (max-width: 1250px) {
    .qv-stats { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .qv-layout { grid-template-columns: minmax(0,1.45fr) minmax(320px,.75fr); }
}
@media (max-width: 1080px) {
    .qv-layout { grid-template-columns: 1fr; }
}
@media (max-width: 991.98px) {
    .fieldplx-topbar { margin-left: 0 !important; width: 100% !important; }
    .fieldplx-main-content { margin-left: 0 !important; }
    .quote-view-page { padding: 20px 18px 30px; }
}
@media (max-width: 680px) {
    .quote-view-page { padding: 16px 12px 26px; }
    .qv-header { align-items: flex-start; flex-wrap: wrap; padding: 18px; }
    .qv-heading-icon { width: 50px; height: 50px; flex-basis: 50px; border-radius: 13px; font-size: 21px; }
    .qv-heading { width: calc(100% - 66px); }
    .qv-heading h1 { font-size: 23px; }
    .qv-heading p { font-size: 13px; }
    .qv-actions { width: 100%; justify-content: stretch; }
    .qv-btn { flex: 1 1 130px; }
    .qv-stats { grid-template-columns: 1fr; }
    .qv-stat { min-height: 158px; }
    .qv-detail-grid { grid-template-columns: 1fr; }
    .qv-detail,
    .qv-detail:nth-child(2n),
    .qv-detail.full { grid-column: auto; border-right: 0; }
    .qv-card-head { align-items: flex-start; }
}

@media print {
    body { background: #fff !important; }
    .fieldplx-sidebar,
    .fieldplx-topbar,
    .fieldplx-footer,
    .qv-actions,
    .qv-stats { display: none !important; }
    .fieldplx-main-content { margin-left: 0 !important; }
    .quote-view-page { max-width: none; padding: 0; }
    .qv-header { min-height: 0; padding: 0 0 16px; border: 0; box-shadow: none; }
    .qv-heading-icon { display: none; }
    .qv-layout { grid-template-columns: 1fr; }
    .qv-card { box-shadow: none; break-inside: avoid; }
    .qv-card + .qv-card { margin-top: 12px; }
}
</style>

<div class="quote-view-page">
    <div class="qv-header">
        <div class="qv-heading-icon">
            <i class="bi bi-file-earmark-text"></i>
        </div>

        <div class="qv-heading">
            <div class="qv-heading-row">
                <h1><?= e($quote['quote_no']); ?></h1>

                <span class="qv-status <?= e(
                    quoteViewStatusClass(
                        $quote['status']
                    )
                ); ?>">
                    <?= e(
                        quoteViewStatusLabel(
                            $quote['status']
                        )
                    ); ?>
                </span>
            </div>

            <p>
                <?= e($quote['title']); ?>
            </p>
        </div>

        <div class="qv-actions">
            <a href="quotes.php" class="qv-btn">
                <i class="bi bi-arrow-left"></i>
                Back
            </a>

            <button
                type="button"
                class="qv-btn"
                onclick="window.print();"
            >
                <i class="bi bi-printer"></i>
                Print
            </button>

            <?php if ($canManage): ?>
                <a
                    href="quote-edit.php?id=<?= (int) $quoteId; ?>"
                    class="qv-btn primary"
                >
                    <i class="bi bi-pencil"></i>
                    Edit Quote
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="qv-alert">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>



    <section class="qv-stats">
        <article class="qv-stat">
            <span class="qv-stat-more"><i class="bi bi-three-dots"></i></span>
            <div class="qv-stat-row">
                <span class="qv-stat-icon navy"><i class="bi bi-receipt"></i></span>
                <div class="qv-stat-copy">
                    <span class="qv-stat-label">Quote Total</span>
                    <span class="qv-stat-value"><?= e(quoteViewMoney($quote['total'])); ?></span>
                    <span class="qv-stat-note"><strong><?= e(quoteViewStatusLabel($quote['status'])); ?></strong> current status</span>
                </div>
            </div>
            <div class="qv-stat-wave" aria-hidden="true"><svg viewBox="0 0 240 38" preserveAspectRatio="none"><path d="M0 25 C30 11, 55 34, 86 19 S145 10, 172 21 S213 31, 240 12"></path><path class="accent" d="M0 29 C35 17, 58 30, 88 23 S143 15, 174 24 S214 26, 240 17"></path></svg></div>
        </article>

        <article class="qv-stat">
            <span class="qv-stat-more"><i class="bi bi-three-dots"></i></span>
            <div class="qv-stat-row">
                <span class="qv-stat-icon green"><i class="bi bi-calculator"></i></span>
                <div class="qv-stat-copy">
                    <span class="qv-stat-label">Subtotal</span>
                    <span class="qv-stat-value"><?= e(quoteViewMoney($quote['subtotal'])); ?></span>
                    <span class="qv-stat-note">Before tax and final adjustments</span>
                </div>
            </div>
            <div class="qv-stat-wave" aria-hidden="true"><svg viewBox="0 0 240 38" preserveAspectRatio="none"><path d="M0 24 C28 18, 51 31, 81 18 S140 12, 171 20 S210 31, 240 15"></path><path class="accent" d="M0 28 C31 21, 56 27, 83 22 S141 18, 171 25 S211 24, 240 18"></path></svg></div>
        </article>

        <article class="qv-stat">
            <span class="qv-stat-more"><i class="bi bi-three-dots"></i></span>
            <div class="qv-stat-row">
                <span class="qv-stat-icon soft-green"><i class="bi bi-percent"></i></span>
                <div class="qv-stat-copy">
                    <span class="qv-stat-label">Tax</span>
                    <span class="qv-stat-value"><?= e(quoteViewMoney($quote['tax_total'])); ?></span>
                    <span class="qv-stat-note">Discount: <strong><?= e(quoteViewMoney($quote['discount_total'])); ?></strong></span>
                </div>
            </div>
            <div class="qv-stat-wave" aria-hidden="true"><svg viewBox="0 0 240 38" preserveAspectRatio="none"><path d="M0 27 C33 13, 61 35, 92 20 S148 14, 181 23 S216 28, 240 13"></path><path class="accent" d="M0 30 C36 19, 63 30, 93 24 S150 20, 181 26 S216 24, 240 18"></path></svg></div>
        </article>

        <article class="qv-stat">
            <span class="qv-stat-more"><i class="bi bi-three-dots"></i></span>
            <div class="qv-stat-row">
                <span class="qv-stat-icon dark-green"><i class="bi bi-wallet2"></i></span>
                <div class="qv-stat-copy">
                    <span class="qv-stat-label">Deposit</span>
                    <span class="qv-stat-value"><?= e(!empty($quote['deposit_required']) ? quoteViewMoney($quote['deposit_amount']) : '0.00'); ?></span>
                    <span class="qv-stat-note"><?= !empty($quote['deposit_required']) ? 'Required for this quote' : 'No deposit required'; ?></span>
                </div>
            </div>
            <div class="qv-stat-wave" aria-hidden="true"><svg viewBox="0 0 240 38" preserveAspectRatio="none"><path d="M0 22 C27 12, 56 33, 84 21 S139 12, 169 20 S211 29, 240 14"></path><path class="accent" d="M0 28 C29 18, 57 29, 87 24 S140 18, 171 25 S213 25, 240 18"></path></svg></div>
        </article>
    </section>

    <div class="qv-layout">
        <main>
            <section class="qv-card">
                <div class="qv-card-head">
                    <div>
                        <h2>Quote Information</h2>
                        <p>
                            Client, property, request, and quote details.
                        </p>
                    </div>
                </div>

                <div class="qv-card-body">
                    <div class="qv-detail-grid">
                        <div class="qv-detail">
                            <span class="qv-label">
                                Client
                            </span>

                            <span class="qv-value">
                                <a
                                    href="client-view.php?id=<?= (int) $quote['client_id']; ?>"
                                    class="qv-link"
                                >
                                    <?= e($quote['client_name']); ?>
                                </a>
                            </span>
                        </div>

                        <div class="qv-detail">
                            <span class="qv-label">
                                Contact
                            </span>

                            <span class="qv-value normal">
                                <?= e(
                                    trim(
                                        implode(
                                            ' · ',
                                            array_filter(
                                                array(
                                                    $quote['client_phone'],
                                                    $quote['client_email']
                                                ),
                                                function ($value) {
                                                    return trim((string) $value) !== '';
                                                }
                                            )
                                        )
                                    ) !== ''
                                        ? implode(
                                            ' · ',
                                            array_filter(
                                                array(
                                                    $quote['client_phone'],
                                                    $quote['client_email']
                                                ),
                                                function ($value) {
                                                    return trim((string) $value) !== '';
                                                }
                                            )
                                        )
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="qv-detail">
                            <span class="qv-label">
                                Property
                            </span>

                            <span class="qv-value">
                                <?php if (!empty($quote['property_id'])): ?>
                                    <a
                                        href="property-view.php?id=<?= (int) $quote['property_id']; ?>"
                                        class="qv-link"
                                    >
                                        <?= e($propertyTitle); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="qv-detail">
                            <span class="qv-label">
                                Property Address
                            </span>

                            <span class="qv-value normal">
                                <?= e($propertyAddress); ?>
                            </span>
                        </div>

                        <div class="qv-detail">
                            <span class="qv-label">
                                Request
                            </span>

                            <span class="qv-value">
                                <?php if (!empty($quote['request_id'])): ?>
                                    <a
                                        href="request-view.php?id=<?= (int) $quote['request_id']; ?>"
                                        class="qv-link"
                                    >
                                        <?= e($quote['request_no']); ?>
                                        ·
                                        <?= e($quote['request_title']); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="qv-detail">
                            <span class="qv-label">
                                Salesperson
                            </span>

                            <span class="qv-value">
                                <?= e(
                                    trim(
                                        (string) $quote['salesperson_name']
                                    ) !== ''
                                        ? $quote['salesperson_name']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="qv-detail">
                            <span class="qv-label">
                                Valid Until
                            </span>

                            <span class="qv-value">
                                <?= e(
                                    quoteViewDate(
                                        $quote['valid_until']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="qv-detail">
                            <span class="qv-label">
                                Deposit
                            </span>

                            <span class="qv-value">
                                <?= e($depositDescription); ?>
                            </span>
                        </div>

                        <div class="qv-detail">
                            <span class="qv-label">
                                Created By
                            </span>

                            <span class="qv-value">
                                <?= e(
                                    trim(
                                        (string) $quote['created_by_name']
                                    ) !== ''
                                        ? $quote['created_by_name']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="qv-detail">
                            <span class="qv-label">
                                Created
                            </span>

                            <span class="qv-value">
                                <?= e(
                                    quoteViewDateTime(
                                        $quote['created_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <?php if (
                            trim(
                                (string) $quote['introduction']
                            ) !== ''
                        ): ?>
                            <div class="qv-detail full">
                                <span class="qv-label">
                                    Introduction
                                </span>

                                <span class="qv-value normal"><?= e(
                                    $quote['introduction']
                                ); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="qv-card">
                <div class="qv-card-head">
                    <div>
                        <h2>Quote Items</h2>
                        <p>
                            Products, services, quantities, tax, and totals.
                        </p>
                    </div>
                </div>

                <?php if (!empty($lineItems)): ?>
                    <div class="qv-table-wrap">
                        <table class="qv-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Type / Unit</th>
                                    <th>Qty</th>
                                    <th>Unit Cost</th>
                                    <th>Unit Price</th>
                                    <th>Tax Rate</th>
                                    <th>Tax</th>
                                    <th>Total</th>
                                </tr>
                            </thead>

                            <tbody>
                            <?php foreach ($lineItems as $item): ?>
                                <tr>
                                    <td>
                                        <span class="qv-item-name">
                                            <?= e($item['item_name']); ?>
                                        </span>

                                        <?php if (
                                            trim(
                                                (string) $item['description']
                                            ) !== ''
                                        ): ?>
                                            <span class="qv-item-description">
                                                <?= e($item['description']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            trim(
                                                implode(
                                                    ' · ',
                                                    array_filter(
                                                        array(
                                                            quoteViewStatusLabel(
                                                                $item['item_type']
                                                            ),
                                                            $item['unit_name']
                                                        ),
                                                        function ($value) {
                                                            return trim((string) $value) !== '';
                                                        }
                                                    )
                                                )
                                            ) !== ''
                                                ? implode(
                                                    ' · ',
                                                    array_filter(
                                                        array(
                                                            quoteViewStatusLabel(
                                                                $item['item_type']
                                                            ),
                                                            $item['unit_name']
                                                        ),
                                                        function ($value) {
                                                            return trim((string) $value) !== '';
                                                        }
                                                    )
                                                )
                                                : '—'
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            quoteViewQty(
                                                $item['quantity']
                                            )
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            quoteViewMoney(
                                                $item['unit_cost']
                                            )
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            quoteViewMoney(
                                                $item['unit_price']
                                            )
                                        ); ?>
                                    </td>

                                    <td>
                                        <?php if (
                                            !empty($item['tax_rate_id'])
                                        ): ?>
                                            <?= e(
                                                $item['tax_name']
                                            ); ?>
                                            ·
                                            <?= e(
                                                quoteViewQty(
                                                    $item['tax_rate']
                                                )
                                            ); ?>%
                                        <?php else: ?>
                                            No Tax
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            quoteViewMoney(
                                                $item['tax_amount']
                                            )
                                        ); ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= e(
                                                quoteViewMoney(
                                                    $item['line_total']
                                                )
                                            ); ?>
                                        </strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="qv-empty">
                        No quote items found.
                    </div>
                <?php endif; ?>
            </section>
        </main>

        <aside>
            <section class="qv-card">
                <div class="qv-card-head">
                    <div>
                        <h2>Quote Summary</h2>
                        <p>
                            Pricing, tax, deposit, and final total.
                        </p>
                    </div>
                </div>

                <div class="qv-card-body">
                    <div class="qv-summary">
                        <div class="qv-summary-row">
                            <span>Subtotal</span>
                            <strong>
                                <?= e(
                                    quoteViewMoney(
                                        $quote['subtotal']
                                    )
                                ); ?>
                            </strong>
                        </div>

                        <div class="qv-summary-row">
                            <span>Discount</span>
                            <strong>
                                <?= e(
                                    quoteViewMoney(
                                        $quote['discount_total']
                                    )
                                ); ?>
                            </strong>
                        </div>

                        <div class="qv-summary-row">
                            <span>Tax</span>
                            <strong>
                                <?= e(
                                    quoteViewMoney(
                                        $quote['tax_total']
                                    )
                                ); ?>
                            </strong>
                        </div>

                        <?php if (
                            !empty($quote['deposit_required'])
                        ): ?>
                            <div class="qv-summary-row">
                                <span>Deposit Required</span>
                                <strong>
                                    <?= e(
                                        quoteViewMoney(
                                            $quote['deposit_amount']
                                        )
                                    ); ?>
                                </strong>
                            </div>
                        <?php endif; ?>

                        <div class="qv-summary-row total">
                            <span>Total</span>
                            <strong>
                                <?= e(
                                    quoteViewMoney(
                                        $quote['total']
                                    )
                                ); ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </section>

            <section class="qv-card">
                <div class="qv-card-head">
                    <div>
                        <h2>Status Timeline</h2>
                        <p>
                            Important quote activity dates.
                        </p>
                    </div>
                </div>

                <div class="qv-card-body">
                    <div class="qv-detail-grid">
                        <div class="qv-detail full">
                            <span class="qv-label">
                                Sent At
                            </span>

                            <span class="qv-value">
                                <?= e(
                                    quoteViewDateTime(
                                        $quote['sent_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="qv-detail full">
                            <span class="qv-label">
                                Viewed At
                            </span>

                            <span class="qv-value">
                                <?= e(
                                    quoteViewDateTime(
                                        $quote['viewed_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="qv-detail full">
                            <span class="qv-label">
                                Approved At
                            </span>

                            <span class="qv-value">
                                <?= e(
                                    quoteViewDateTime(
                                        $quote['approved_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="qv-detail full">
                            <span class="qv-label">
                                Last Updated
                            </span>

                            <span class="qv-value">
                                <?= e(
                                    quoteViewDateTime(
                                        $quote['updated_at']
                                    )
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <?php if (
                !empty($quote['converted_job_id'])
            ): ?>
                <section class="qv-card">
                    <div class="qv-card-head">
                        <div>
                            <h2>Converted Job</h2>
                            <p>
                                This quote has been converted.
                            </p>
                        </div>
                    </div>

                    <div class="qv-card-body">
                        <a
                            href="job-view.php?id=<?= (int) $quote['converted_job_id']; ?>"
                            class="qv-btn primary"
                            style="width:100%;"
                        >
                            <i class="bi bi-briefcase"></i>
                            <?= e(
                                $quote['converted_job_no']
                            ); ?>
                            ·
                            <?= e(
                                $quote['converted_job_title']
                            ); ?>
                        </a>
                    </div>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
