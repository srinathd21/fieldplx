<?php
/* FieldPlx Add Invoice - Version 2.4.0 - 2026-09-08
 * Jobber-style invoice form with Job Card / Direct source, Services / Products / Manual items,
 * custom label/value fields, Images, Attachments and automatic client email after creation.
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Create Invoice';
$activePage = 'invoices';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
/*
 * Keep the Add Invoice token compatible with both the current invoice-form API
 * and older deployed API versions that still validate invoices_csrf_token.
 * This is important when Create Similar Invoice is opened from invoice-view.php.
 */
if (empty($_SESSION['invoice_form_csrf_token'])) {
    if (!empty($_SESSION['invoices_csrf_token'])) {
        $_SESSION['invoice_form_csrf_token'] = (string)$_SESSION['invoices_csrf_token'];
    } else {
        $_SESSION['invoice_form_csrf_token'] = bin2hex(random_bytes(32));
    }
}
if (empty($_SESSION['invoices_csrf_token'])) {
    $_SESSION['invoices_csrf_token'] = (string)$_SESSION['invoice_form_csrf_token'];
}
$invoiceFormCsrfToken = (string)$_SESSION['invoice_form_csrf_token'];
$invoiceLegacyCsrfToken = (string)$_SESSION['invoices_csrf_token'];

$invoiceScriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '/business/add-invoice.php';
$invoiceScriptDir = rtrim(str_replace('\\', '/', dirname($invoiceScriptName)), '/');
if ($invoiceScriptDir === '.' || $invoiceScriptDir === '/') {
    $invoiceScriptDir = '';
}
$invoiceFormApiUrl = $invoiceScriptDir . '/api/invoice-form.php';
$preJobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$preClientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$preLocationId = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;

/*
|--------------------------------------------------------------------------
| Create Similar Invoice
|--------------------------------------------------------------------------
|
| invoice-view.php opens this page with ?similar_invoice_id=ID.
| We intentionally copy editable invoice content only. We DO NOT copy the
| original invoice number, payments, signatures, audit history, internal
| notes or uploaded files. The new invoice always receives a fresh number.
|
*/
$similarInvoiceId = 0;
if (isset($_GET['similar_invoice_id'])) {
    $similarInvoiceId = (int)$_GET['similar_invoice_id'];
} elseif (isset($_GET['source_invoice_id'])) {
    $similarInvoiceId = (int)$_GET['source_invoice_id'];
} elseif (isset($_GET['copy_invoice_id'])) {
    $similarInvoiceId = (int)$_GET['copy_invoice_id'];
}

$similarInvoiceData = null;
$similarInvoiceLoadError = '';

if ($similarInvoiceId > 0) {
    try {
        $similarTenantId = !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;

        if ($similarTenantId <= 0) {
            throw new RuntimeException('Tenant session is not available.');
        }

        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new RuntimeException('Database connection is not available.');
        }

        $similarStmt = $pdo->prepare("SELECT * FROM invoices WHERE id=:id AND tenant_id=:tenant_id LIMIT 1");
        $similarStmt->execute(array(
            ':id' => $similarInvoiceId,
            ':tenant_id' => $similarTenantId
        ));
        $similarInvoice = $similarStmt->fetch(PDO::FETCH_ASSOC);

        if (!$similarInvoice) {
            throw new RuntimeException('The invoice selected for Create Similar could not be found.');
        }

        $similarItems = array();
        $tableCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name");

        $tableCheck->execute(array(':table_name' => 'invoice_line_items'));
        if ((int)$tableCheck->fetchColumn() > 0) {
            $itemStmt = $pdo->prepare("SELECT * FROM invoice_line_items WHERE invoice_id=:invoice_id ORDER BY sort_order,id");
            $itemStmt->execute(array(':invoice_id' => $similarInvoiceId));
            $similarItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $similarCustomFields = array();
        $tableCheck->execute(array(':table_name' => 'invoice_custom_fields'));
        if ((int)$tableCheck->fetchColumn() > 0) {
            $customStmt = $pdo->prepare("SELECT field_label,field_value,sort_order FROM invoice_custom_fields WHERE tenant_id=:tenant_id AND invoice_id=:invoice_id ORDER BY sort_order,id");
            $customStmt->execute(array(
                ':tenant_id' => $similarTenantId,
                ':invoice_id' => $similarInvoiceId
            ));
            foreach ($customStmt->fetchAll(PDO::FETCH_ASSOC) as $field) {
                $similarCustomFields[] = array(
                    'label' => isset($field['field_label']) ? (string)$field['field_label'] : '',
                    'value' => isset($field['field_value']) ? (string)$field['field_value'] : ''
                );
            }
        }

        $similarClientView = array();
        if (!empty($similarInvoice['client_view_options'])) {
            $decodedClientView = json_decode((string)$similarInvoice['client_view_options'], true);
            if (is_array($decodedClientView)) {
                $similarClientView = $decodedClientView;
            }
        }

        /* Preserve the payment-term interval, but calculate dates from today. */
        $similarPaymentDays = 0;
        if (!empty($similarInvoice['issue_date']) && !empty($similarInvoice['due_date'])) {
            try {
                $oldIssue = new DateTime(substr((string)$similarInvoice['issue_date'], 0, 10));
                $oldDue = new DateTime(substr((string)$similarInvoice['due_date'], 0, 10));
                $similarPaymentDays = (int)$oldIssue->diff($oldDue)->format('%r%a');
                if ($similarPaymentDays < 0) {
                    $similarPaymentDays = 0;
                }
            } catch (Throwable $dateError) {
                $similarPaymentDays = 0;
            }
        }

        $similarInvoiceData = array(
            'source_invoice_id' => (int)$similarInvoice['id'],
            'source_invoice_no' => isset($similarInvoice['invoice_no']) ? (string)$similarInvoice['invoice_no'] : '',
            'client_id' => !empty($similarInvoice['client_id']) ? (int)$similarInvoice['client_id'] : 0,
            'location_id' => !empty($similarInvoice['location_id']) ? (int)$similarInvoice['location_id'] : 0,
            'branch_id' => !empty($similarInvoice['branch_id']) ? (int)$similarInvoice['branch_id'] : 0,
            'subject' => isset($similarInvoice['subject']) ? (string)$similarInvoice['subject'] : 'For Services Rendered',
            'payment_terms' => isset($similarInvoice['payment_terms']) ? (string)$similarInvoice['payment_terms'] : 'Due upon receipt',
            'payment_term_days' => $similarPaymentDays,
            'client_message' => isset($similarInvoice['client_message']) ? (string)$similarInvoice['client_message'] : '',
            'contract_disclaimer' => isset($similarInvoice['contract_disclaimer']) ? (string)$similarInvoice['contract_disclaimer'] : '',
            'client_view_options' => $similarClientView,
            'items' => $similarItems,
            'custom_fields' => $similarCustomFields
        );

        /* Fallback preselection if JS cannot apply the richer payload. */
        $preClientId = (int)$similarInvoiceData['client_id'];
        $preLocationId = (int)$similarInvoiceData['location_id'];
        $preJobId = 0;

    } catch (Throwable $similarError) {
        $similarInvoiceLoadError = $similarError->getMessage() !== ''
            ? $similarError->getMessage()
            : 'Unable to load the source invoice.';
        error_log('FieldPlx create similar invoice: ' . $similarInvoiceLoadError);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Create Invoice - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        :root{
            --fieldplx-primary:#74b824;
            --fieldplx-primary-dark:#5d971b;
            --fieldplx-text:#0b1933;
            --fieldplx-muted:#6f7b90;
            --fieldplx-border:#e5eaf1;
            --fieldplx-surface:#ffffff;
            --fieldplx-background:#f6f8fb;
            --fieldplx-topbar-height:70px;
            --fieldplx-sidebar-width:250px;
            --fieldplx-sidebar-collapsed-width:78px;

            --fd-navy:#001131;
            --fd-navy-light:#071f49;
            --fd-blue:#123d70;
            --fd-green:#74b824;
            --fd-green-dark:#5d971b;
            --fd-green-soft:#f0f8e5;
            --fd-red:#e45b66;
            --fd-bg:#f6f8fb;
            --fd-text:#0b1933;
            --fd-muted:#6f7b90;
            --fd-border:#e5eaf1;
        }

        *{
            box-sizing:border-box;
        }

        html,
        body{
            margin:0;
            min-height:100%;
            overflow-x:hidden;
        }

        body{
            min-height:100vh;
            background:var(--fd-bg)!important;
            color:var(--fd-text);
            font-family:Arial,Helvetica,sans-serif!important;
            font-size:14px;
        }

        a,
        a:link,
        a:visited,
        a:hover,
        a:focus,
        a:active{
            text-decoration:none!important;
        }

        /* ---------- Topbar ---------- */
        .fieldplx-topbar{
            min-height:70px!important;
            position:sticky!important;
            top:0!important;
            z-index:1030!important;
            margin-left:var(--fieldplx-sidebar-width);
            width:calc(100% - var(--fieldplx-sidebar-width));
            background:#fff!important;
            border-bottom:1px solid var(--fd-border)!important;
            box-shadow:0 3px 14px rgba(0,17,49,.035)!important;
            backdrop-filter:none!important;
            transition:margin-left .25s ease,width .25s ease;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-topbar{
            margin-left:var(--fieldplx-sidebar-collapsed-width);
            width:calc(100% - var(--fieldplx-sidebar-collapsed-width));
        }

        .fieldplx-topbar-inner{
            min-height:70px!important;
            padding:0 27px!important;
            display:flex!important;
            align-items:center!important;
            gap:13px!important;
        }

        .fieldplx-brand-mobile{
            display:none!important;
            align-items:center!important;
            gap:9px!important;
            min-width:0!important;
            color:var(--fd-text)!important;
        }

        .fieldplx-brand-logo{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            border-radius:10px!important;
            object-fit:contain!important;
        }

        .fieldplx-brand-placeholder{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:10px!important;
            color:#fff!important;
            background:linear-gradient(135deg,#8fd236,#68aa1d)!important;
            font-weight:700!important;
        }

        .fieldplx-brand-name{
            max-width:170px!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:var(--fd-text)!important;
            font-size:14px!important;
            font-weight:700!important;
        }

        .fieldplx-page-heading{
            display:none!important;
        }

        .fieldplx-menu-toggle,
        .fieldplx-topbar-action{
            width:41px!important;
            height:41px!important;
            min-width:41px!important;
            padding:0!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            position:relative!important;
            border:0!important;
            border-radius:9px!important;
            color:var(--fd-navy)!important;
            background:transparent!important;
            font-size:18px!important;
            box-shadow:none!important;
        }

        .fieldplx-menu-toggle:hover,
        .fieldplx-topbar-action:hover{
            color:var(--fd-navy)!important;
            background:var(--fd-green-soft)!important;
        }

        .fieldplx-search-wrap{
            width:280px!important;
            margin-left:auto!important;
            position:relative!important;
        }

        .fieldplx-search-icon{
            position:absolute!important;
            top:50%!important;
            left:13px!important;
            z-index:2!important;
            transform:translateY(-50%)!important;
            color:#98a3b2!important;
            font-size:14px!important;
            pointer-events:none!important;
        }

        .fieldplx-search-input{
            width:100%!important;
            height:41px!important;
            padding:8px 13px 8px 38px!important;
            border:0!important;
            border-radius:8px!important;
            outline:0!important;
            background:#f5f8fb!important;
            color:var(--fd-text)!important;
            font-size:12px!important;
            box-shadow:none!important;
        }

        .fieldplx-search-input:focus{
            background:#f5f8fb!important;
            box-shadow:0 0 0 3px rgba(116,184,36,.14)!important;
        }

        .fieldplx-notification-count{
            position:absolute!important;
            top:-5px!important;
            right:-5px!important;
            min-width:18px!important;
            height:18px!important;
            padding:0 5px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border:2px solid #fff!important;
            border-radius:999px!important;
            color:#fff!important;
            background:var(--fd-red)!important;
            font-size:9px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-button{
            min-width:0!important;
            padding:2px!important;
            display:flex!important;
            align-items:center!important;
            gap:9px!important;
            border:0!important;
            border-radius:9px!important;
            background:transparent!important;
            color:var(--fd-text)!important;
            text-align:left!important;
            box-shadow:none!important;
        }

        .fieldplx-profile-button:hover{
            background:var(--fd-green-soft)!important;
        }

        .fieldplx-avatar{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            overflow:hidden!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border:0!important;
            border-radius:50%!important;
            color:var(--fd-navy)!important;
            background:linear-gradient(135deg,#fff,#e8f3d9)!important;
            font-size:12px!important;
            font-weight:800!important;
        }

        .fieldplx-avatar img{
            width:100%!important;
            height:100%!important;
            object-fit:cover!important;
        }

        .fieldplx-profile-details{
            max-width:145px!important;
            min-width:0!important;
        }

        .fieldplx-profile-name,
        .fieldplx-profile-role{
            display:block!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
        }

        .fieldplx-profile-name{
            color:#111827!important;
            font-size:12px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-role{
            margin-top:1px!important;
            color:var(--fd-muted)!important;
            font-size:10px!important;
        }

        /* ---------- Dropdowns ---------- */
        .fieldplx-dropdown{
            width:340px!important;
            max-width:calc(100vw - 24px)!important;
            padding:0!important;
            margin-top:10px!important;
            overflow:hidden!important;
            border:1px solid var(--fd-border)!important;
            border-radius:14px!important;
            background:#fff!important;
            box-shadow:0 18px 45px rgba(29,38,74,.14)!important;
        }

        .fieldplx-dropdown-header{
            min-height:48px!important;
            padding:11px 16px!important;
            display:flex!important;
            align-items:center!important;
            justify-content:space-between!important;
            border-bottom:1px solid var(--fd-border)!important;
            background:#fff!important;
        }

        .fieldplx-dropdown-title{
            margin:0!important;
            color:#111827!important;
            font-size:14px!important;
            font-weight:700!important;
        }

        #topbarNotificationList{
            max-height:300px!important;
            overflow-y:auto!important;
            background:#fff!important;
        }

        .fieldplx-notification-item{
            padding:11px 14px!important;
            display:flex!important;
            gap:10px!important;
            border-bottom:1px solid #f1f2f4!important;
            color:inherit!important;
            text-decoration:none!important;
        }

        .fieldplx-notification-item:hover,
        .fieldplx-notification-item.is-unread{
            background:#f8fbf3!important;
        }

        .fieldplx-notification-icon{
            width:32px!important;
            height:32px!important;
            flex:0 0 32px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:9px!important;
            color:var(--fd-green-dark)!important;
            background:var(--fd-green-soft)!important;
            font-size:14px!important;
        }

        .fieldplx-notification-content{
            min-width:0!important;
        }

        .fieldplx-notification-title{
            margin:0!important;
            color:#111827!important;
            font-size:11px!important;
            font-weight:700!important;
        }

        .fieldplx-notification-message{
            margin-top:3px!important;
            overflow:hidden!important;
            display:-webkit-box!important;
            color:var(--fd-muted)!important;
            font-size:10px!important;
            line-height:1.45!important;
            -webkit-line-clamp:2!important;
            -webkit-box-orient:vertical!important;
        }

        .fieldplx-notification-time{
            margin-top:4px!important;
            color:#9ca3af!important;
            font-size:9px!important;
        }

        .fieldplx-empty-notifications{
            min-height:155px!important;
            padding:28px 18px 24px!important;
            display:flex!important;
            flex-direction:column!important;
            align-items:center!important;
            justify-content:center!important;
            color:#718096!important;
            background:#fff!important;
            text-align:center!important;
            font-size:13px!important;
        }

        .fieldplx-empty-notifications i{
            margin-bottom:10px!important;
            color:#a9cf75!important;
            font-size:30px!important;
        }

        .fieldplx-dropdown-footer{
            min-height:44px!important;
            padding:10px 14px!important;
            display:flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-top:1px solid var(--fd-border)!important;
            background:#fff!important;
        }

        .fieldplx-dropdown-footer a{
            color:var(--fd-green-dark)!important;
            font-size:11px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-menu{
            width:230px!important;
            padding:7px!important;
            border:1px solid var(--fd-border)!important;
            border-radius:12px!important;
            background:#fff!important;
            box-shadow:0 18px 45px rgba(29,38,74,.14)!important;
        }

        .fieldplx-profile-menu-header{
            padding:9px 10px 11px!important;
            border-bottom:1px solid #f0f1f3!important;
        }

        .fieldplx-profile-menu-name{
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:#111827!important;
            font-size:12px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-menu-email{
            margin-top:2px!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:var(--fd-muted)!important;
            font-size:10px!important;
        }

        .fieldplx-profile-menu .dropdown-item{
            padding:9px 10px!important;
            display:flex!important;
            align-items:center!important;
            gap:9px!important;
            border-radius:8px!important;
            color:#374151!important;
            background:transparent!important;
            font-size:11px!important;
        }

        .fieldplx-profile-menu .dropdown-item:hover{
            color:var(--fd-green-dark)!important;
            background:var(--fd-green-soft)!important;
        }

        /* ---------- Sidebar ---------- */
        .fieldplx-sidebar{
            width:var(--fieldplx-sidebar-width)!important;
            min-width:var(--fieldplx-sidebar-width)!important;
            height:100vh!important;
            position:fixed!important;
            top:0!important;
            left:0!important;
            z-index:1045!important;
            display:flex!important;
            flex-direction:column!important;
            color:#fff!important;
            background:linear-gradient(180deg,var(--fd-navy-light),var(--fd-navy))!important;
            border-right:0!important;
            transition:width .25s ease,min-width .25s ease,transform .25s ease!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
            width:var(--fieldplx-sidebar-collapsed-width)!important;
            min-width:var(--fieldplx-sidebar-collapsed-width)!important;
        }

        .fieldplx-sidebar-header{
            min-height:68px!important;
            padding:9px 14px 10px!important;
            display:flex!important;
            align-items:center!important;
            border-bottom:1px solid rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-brand{
            min-width:0!important;
            display:flex!important;
            align-items:center!important;
            gap:10px!important;
            color:#fff!important;
        }

        .fieldplx-sidebar-logo,
        .fieldplx-sidebar-logo-placeholder{
            width:40px!important;
            height:40px!important;
            flex:0 0 40px!important;
            border-radius:10px!important;
        }

        .fieldplx-sidebar-logo{
            object-fit:contain!important;
        }

        .fieldplx-sidebar-logo-placeholder{
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            color:#fff!important;
            background:linear-gradient(135deg,#8fd236,#68aa1d)!important;
            font-size:18px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-brand-text{
            min-width:0!important;
            display:block!important;
        }

        .fieldplx-sidebar-company-name{
            max-width:155px!important;
            display:block!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:#fff!important;
            font-size:16px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-product-name{
            margin-top:1px!important;
            display:block!important;
            color:#9fda55!important;
            font-size:9px!important;
            font-weight:600!important;
            letter-spacing:.4px!important;
            text-transform:uppercase!important;
        }

        .fieldplx-sidebar-close{
            width:32px!important;
            height:32px!important;
            margin-left:auto!important;
            padding:0!important;
            display:none!important;
            align-items:center!important;
            justify-content:center!important;
            border:0!important;
            border-radius:8px!important;
            color:rgba(255,255,255,.82)!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-body{
            min-height:0!important;
            flex:1 1 auto!important;
            overflow-y:auto!important;
            overflow-x:hidden!important;
            padding:12px 14px!important;
            scrollbar-width:none!important;
        }

        .fieldplx-sidebar-body::-webkit-scrollbar{
            display:none!important;
        }

        .fieldplx-sidebar-section-label{
            margin:7px 12px!important;
            color:rgba(255,255,255,.5)!important;
            font-size:9px!important;
            font-weight:700!important;
            letter-spacing:.65px!important;
            text-transform:uppercase!important;
        }

        .fieldplx-sidebar-nav{
            display:flex!important;
            flex-direction:column!important;
            gap:3px!important;
        }

        .fieldplx-sidebar-link{
            width:100%!important;
            min-height:46px!important;
            margin-bottom:3px!important;
            padding:0 14px!important;
            display:flex!important;
            align-items:center!important;
            gap:15px!important;
            border:0!important;
            border-radius:9px!important;
            color:rgba(255,255,255,.94)!important;
            background:transparent!important;
            text-align:left!important;
            font-family:inherit!important;
            font-size:14px!important;
            font-weight:600!important;
        }

        .fieldplx-sidebar-link:hover{
            color:#fff!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-link.active,
        .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link{
            color:#fff!important;
            background:linear-gradient(90deg,#7fc92d,#68aa1d)!important;
            box-shadow:0 6px 18px rgba(0,17,49,.28)!important;
        }

        .fieldplx-sidebar-link-icon{
            width:21px!important;
            height:21px!important;
            flex:0 0 21px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            font-size:19px!important;
        }

        .fieldplx-sidebar-link-text{
            min-width:0!important;
            flex:1!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
        }

        .fieldplx-sidebar-arrow{
            margin-left:auto!important;
            color:rgba(255,255,255,.65)!important;
            font-size:10px!important;
            transition:transform .2s ease!important;
        }

        .fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-arrow{
            transform:rotate(180deg)!important;
        }

        .fieldplx-sidebar-submenu{
            max-height:0!important;
            overflow:hidden!important;
            padding-left:36px!important;
            transition:max-height .25s ease,padding-top .25s ease,padding-bottom .25s ease!important;
        }

        .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu{
            max-height:680px!important;
            padding-top:4px!important;
            padding-bottom:5px!important;
        }

        .fieldplx-sidebar-sublink{
            min-height:34px!important;
            padding:7px 9px!important;
            display:flex!important;
            align-items:center!important;
            border-radius:7px!important;
            color:rgba(255,255,255,.72)!important;
            background:transparent!important;
            font-size:11px!important;
            font-weight:500!important;
        }

        .fieldplx-sidebar-sublink::before{
            width:5px!important;
            height:5px!important;
            margin-right:9px!important;
            flex:0 0 5px!important;
            content:""!important;
            border-radius:50%!important;
            background:rgba(255,255,255,.35)!important;
        }

        .fieldplx-sidebar-sublink:hover,
        .fieldplx-sidebar-sublink.active{
            color:#fff!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-sublink.active::before{
            background:#9fda55!important;
        }

        .fieldplx-sidebar-footer{
            flex:0 0 auto!important;
            padding:10px 14px 14px!important;
            border-top:1px solid rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-user{
            min-height:62px!important;
            padding:8px!important;
            display:flex!important;
            align-items:center!important;
            gap:9px!important;
            border-radius:10px!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-user-avatar{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:50%!important;
            color:var(--fd-navy)!important;
            background:linear-gradient(135deg,#fff,#e8f3d9)!important;
            font-size:11px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-user-details{
            min-width:0!important;
            flex:1!important;
        }

        .fieldplx-sidebar-user-name,
        .fieldplx-sidebar-user-role{
            display:block!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
        }

        .fieldplx-sidebar-user-name{
            color:#fff!important;
            font-size:12px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-user-role{
            margin-top:1px!important;
            color:rgba(255,255,255,.6)!important;
            font-size:9px!important;
        }

        .fieldplx-sidebar-logout{
            width:29px!important;
            height:29px!important;
            flex:0 0 29px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:8px!important;
            color:rgba(255,255,255,.7)!important;
            font-size:14px!important;
        }

        .fieldplx-sidebar-logout:hover{
            color:#fff!important;
            background:rgba(228,91,102,.3)!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout{
            display:none!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user{
            justify-content:center!important;
        }

        /* ---------- Main content ---------- */
        .fieldplx-main-layout{
            display:block!important;
            min-height:calc(100vh - 70px)!important;
        }

        .fieldplx-main-content{
            margin-left:var(--fieldplx-sidebar-width)!important;
            min-width:0!important;
            transition:margin-left .25s ease!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-main-content{
            margin-left:var(--fieldplx-sidebar-collapsed-width)!important;
        }

        .fieldplx-content-wrapper{
            padding:0!important;
        }

        /* ---------- Footer ---------- */
        .fieldplx-footer{
            min-height:52px!important;
            margin-left:var(--fieldplx-sidebar-width)!important;
            display:block!important;
            border-top:1px solid var(--fd-border)!important;
            background:#fff!important;
            transition:margin-left .22s ease!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-footer{
            margin-left:var(--fieldplx-sidebar-collapsed-width)!important;
        }

        .fieldplx-footer-inner{
            min-height:52px!important;
            padding:10px 18px!important;
            display:flex!important;
            align-items:center!important;
            gap:18px!important;
            color:#6b7280!important;
            font-size:10px!important;
        }

        .fieldplx-footer-links{
            display:flex!important;
            align-items:center!important;
            gap:8px!important;
        }

        .fieldplx-footer-links a{
            color:#6b7280!important;
        }

        .fieldplx-footer-links a:hover,
        .fieldplx-footer-product strong{
            color:var(--fd-green-dark)!important;
        }

        .fieldplx-footer-separator{
            color:#d1d5db!important;
            font-size:8px!important;
        }

        .fieldplx-footer-product{
            margin-left:auto!important;
            white-space:nowrap!important;
            color:#9ca3af!important;
        }

        /* ---------- Mobile sidebar ---------- */
        .fieldplx-sidebar-overlay{
            display:none;
        }

        @media(max-width:991.98px){
            html,
            body{
                overflow-x:hidden!important;
            }

            body.fieldplx-sidebar-mobile-open{
                overflow:hidden!important;
            }

            .fieldplx-topbar,
            body.fieldplx-sidebar-collapsed .fieldplx-topbar{
                margin-left:0!important;
                width:100%!important;
            }

            .fieldplx-brand-mobile{
                display:flex!important;
            }

            .fieldplx-main-content,
            body.fieldplx-sidebar-collapsed .fieldplx-main-content{
                width:100%!important;
                margin-left:0!important;
            }

            .fieldplx-footer,
            body.fieldplx-sidebar-collapsed .fieldplx-footer{
                margin-left:0!important;
            }

            .fieldplx-sidebar,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
                width:min(300px,calc(100vw - 52px))!important;
                min-width:0!important;
                max-width:300px!important;
                height:100vh!important;
                height:100dvh!important;
                position:fixed!important;
                top:0!important;
                bottom:0!important;
                left:0!important;
                z-index:1060!important;
                display:flex!important;
                flex-direction:column!important;
                overflow:hidden!important;
                visibility:hidden!important;
                transform:translate3d(-100%,0,0)!important;
                box-shadow:none!important;
                transition:transform .25s ease,visibility .25s ease!important;
            }

            body.fieldplx-sidebar-mobile-open .fieldplx-sidebar,
            body.fieldplx-sidebar-mobile-open.fieldplx-sidebar-collapsed .fieldplx-sidebar{
                visibility:visible!important;
                transform:translate3d(0,0,0)!important;
            }

            .fieldplx-sidebar-close{
                display:inline-flex!important;
            }

            .fieldplx-sidebar-brand-text,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
            .fieldplx-sidebar-section-label,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
            .fieldplx-sidebar-link-text,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
            .fieldplx-sidebar-user-details,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details{
                display:block!important;
            }

            .fieldplx-sidebar-arrow,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
            .fieldplx-sidebar-logout,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout{
                display:inline-flex!important;
            }

            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user{
                justify-content:flex-start!important;
            }

            .fieldplx-sidebar-submenu,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu{
                display:block!important;
                max-height:0!important;
                overflow:hidden!important;
                padding-top:0!important;
                padding-bottom:0!important;
            }

            .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu{
                max-height:680px!important;
                padding-top:4px!important;
                padding-bottom:5px!important;
            }

            .fieldplx-sidebar-overlay{
                position:fixed!important;
                inset:0!important;
                z-index:1055!important;
                display:block!important;
                visibility:hidden!important;
                opacity:0!important;
                pointer-events:none!important;
                background:rgba(0,17,49,.48)!important;
                transition:opacity .25s ease,visibility .25s ease!important;
            }

            body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay{
                visibility:visible!important;
                opacity:1!important;
                pointer-events:auto!important;
            }
        }

        @media(max-width:767.98px){
            :root{
                --fieldplx-topbar-height:64px;
            }

            .fieldplx-topbar,
            .fieldplx-topbar-inner{
                min-height:64px!important;
            }

            .fieldplx-topbar-inner{
                padding:0 13px!important;
            }

            .fieldplx-search-wrap{
                display:none!important;
            }

            .fieldplx-profile-details{
                display:none!important;
            }

            .fieldplx-footer-inner{
                padding:12px!important;
                flex-wrap:wrap!important;
                justify-content:center!important;
                gap:7px 14px!important;
                text-align:center!important;
            }

            .fieldplx-footer-product{
                width:100%!important;
                margin-left:0!important;
            }
        }

        @media(max-width:575.98px){
            .fieldplx-sidebar,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
                width:min(288px,calc(100vw - 44px))!important;
            }

            .fieldplx-sidebar-body{
                padding-left:10px!important;
                padding-right:10px!important;
            }

            .fieldplx-sidebar-link{
                min-height:43px!important;
                padding-left:12px!important;
                padding-right:12px!important;
                gap:12px!important;
                font-size:13px!important;
            }

            .fieldplx-sidebar-submenu{
                padding-left:31px!important;
            }
        }

        :root{
            --ni-navy:#001131;
            --ni-green:#2f8d25;
            --ni-green-dark:#24751d;
            --ni-green-soft:#f2f8ee;
            --ni-text:#0b2b37;
            --ni-muted:#5f7380;
            --ni-border:#dce4e8;
            --ni-soft:#f8fafb;
            --ni-danger:#c74646;
        }
        body{background:#fff!important}
        .ni-page{width:100%;max-width:none;margin:0;background:#fff;padding:0 0 88px}
        .ni-form{background:#fff}
        .ni-top{padding:24px 28px 30px;border-bottom:1px solid var(--ni-border);background:#fff}
        .ni-heading{display:flex;align-items:center;gap:12px;margin-bottom:18px}
        .ni-heading i{color:#2b75ac;font-size:22px}
        .ni-heading h1{margin:0;color:var(--ni-text);font-size:24px;line-height:1.2;font-weight:700}
        .ni-subject{margin-bottom:14px}
        .ni-floating{position:relative}
        .ni-floating label{position:absolute;top:7px;left:13px;z-index:2;color:#647a87;font-size:13px;line-height:1;pointer-events:none}
        .ni-floating input,.ni-floating select,.ni-floating textarea{width:100%;min-width:0;max-width:100%;border:1px solid var(--ni-border);border-radius:7px;background:#fff;color:#163644;outline:0;font-family:inherit;font-size:16px}
        .ni-floating input,.ni-floating select{height:43px;padding:17px 12px 6px}
        .ni-floating textarea{min-height:96px;padding:21px 12px 10px;resize:vertical}
        .ni-floating input:focus,.ni-floating select:focus,.ni-floating textarea:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.09)}
        .ni-source-line{display:flex;align-items:center;gap:10px;margin:0 0 14px}
        .ni-source-label{color:#526a78;font-size:14px}
        .ni-segment{display:inline-flex;border:1px solid var(--ni-border);border-radius:8px;overflow:hidden;background:#fff}
        .ni-segment button{height:34px;padding:0 13px;border:0;border-right:1px solid var(--ni-border);background:#fff;color:#526a78;font:600 14px Arial,Helvetica,sans-serif;cursor:pointer}
        .ni-segment button:last-child{border-right:0}.ni-segment button.active{background:var(--ni-green-soft);color:var(--ni-green-dark)}
        .ni-top-grid{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(360px,.92fr);gap:22px}
        .ni-left,.ni-right{min-width:0;max-width:100%}.ni-left>*,.ni-right>*{min-width:0;max-width:100%}
        .ni-client-wrap,.ni-job-wrap{display:none}.ni-client-wrap.show,.ni-job-wrap.show{display:block}
        .ni-right-row{min-height:44px;display:grid;grid-template-columns:180px minmax(0,1fr);align-items:center;border-bottom:1px solid var(--ni-border)}
        .ni-right-row:last-child{border-bottom:0}.ni-right-label{min-width:0;color:#607582;font-size:14px}.ni-right-value{min-width:0;max-width:100%;overflow:hidden;color:#153442;font-size:15px}
        .ni-right-value input,.ni-right-value select{width:100%;min-width:0;max-width:100%;height:33px;padding:6px 10px;border:1px solid var(--ni-border);border-radius:7px;background:#fff;color:#153442;font-family:inherit;font-size:14px;outline:0}
        .ni-right-value input:focus,.ni-right-value select:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
        .ni-job-context{display:none;margin-top:10px;padding:10px 12px;border:1px solid #e3e9ec;border-radius:7px;background:#fbfcfc;color:#526b78;font-size:13.5px;line-height:1.55}.ni-job-context.show{display:block}.ni-job-context strong{color:#173846}
        .ni-location-row{margin-top:10px;display:grid;grid-template-columns:minmax(0,1.35fr) minmax(190px,.65fr);gap:10px}.ni-location-row>*{min-width:0;max-width:100%}.ni-location-row .select2-container{min-width:0!important;max-width:100%!important}#customerEmail{min-width:0;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .ni-section{padding:26px 28px;border-bottom:1px solid var(--ni-border);background:#fff}
        .ni-box{border:1px solid var(--ni-border);border-radius:8px;background:#fff;overflow:hidden}
        .ni-box-pad{padding:22px 20px}
        .ni-section-title{margin:0 0 17px;color:var(--ni-text);font-size:20px;font-weight:700}
        .ni-item-add{display:flex;align-items:center;gap:8px;margin-bottom:13px;flex-wrap:wrap}
        .ni-item-mode{display:inline-flex;border:1px solid var(--ni-border);border-radius:7px;overflow:hidden}
        .ni-item-mode button{height:34px;padding:0 12px;border:0;border-right:1px solid var(--ni-border);background:#fff;color:#566e7b;font-size:13px;font-weight:700;cursor:pointer}.ni-item-mode button:last-child{border-right:0}.ni-item-mode button.active{color:var(--ni-green-dark);background:var(--ni-green-soft)}
        .ni-item-select{min-width:300px;flex:1 1 360px}.ni-add-line{height:34px;padding:0 13px;border:1px solid var(--ni-green);border-radius:7px;color:#fff;background:var(--ni-green);font-size:13px;font-weight:700;cursor:pointer}.ni-add-line:hover{background:var(--ni-green-dark)}
        .ni-line{position:relative;padding:16px;border:1px solid var(--ni-border);border-radius:8px;background:#fff;margin-bottom:12px}
        .ni-line-main{display:grid;grid-template-columns:minmax(280px,1fr) 130px 170px 170px 42px;gap:12px;align-items:start}
        .ni-line-extra{display:grid;grid-template-columns:minmax(280px,1fr) 180px;gap:10px;margin-top:10px;align-items:start}
        .ni-line input,.ni-line textarea{width:100%;border:1px solid var(--ni-border);border-radius:7px;background:#fff;color:#183845;font-family:inherit;font-size:14px;outline:0}
        .ni-line input{height:52px;padding:22px 12px 8px;line-height:18px}
        .ni-line .ni-line-name{padding:0 12px!important;line-height:50px}
        .ni-line textarea{min-height:84px;padding:13px 12px;line-height:1.45;resize:vertical}
        .ni-line input:focus,.ni-line textarea:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.07)}
        .ni-labeled{position:relative;min-width:0}
        .ni-labeled span{position:absolute;top:8px;left:12px;z-index:2;color:#718692;font-size:11px;line-height:1;pointer-events:none}
        .ni-labeled input{padding:23px 12px 7px!important}
        .ni-line-total{height:52px;padding:23px 12px 7px;border:1px solid var(--ni-border);border-radius:7px;color:#183845;background:#fff;font-size:14px;font-weight:700;line-height:18px;position:relative}
        .ni-line-total:before{content:'Total';position:absolute;top:8px;left:12px;color:#718692;font-size:11px;line-height:1;font-weight:400}
        .ni-remove{width:42px;height:52px;margin:0;display:grid;place-items:center;border:0;border-radius:7px;color:#6c7f89;background:transparent;cursor:pointer}.ni-remove:hover{color:var(--ni-danger);background:#fff1f1}
        .ni-invoice-number{font-weight:700;letter-spacing:.15px;background:#fff!important}
        .ni-invoice-number.is-manual{border-color:#86b66f!important;box-shadow:0 0 0 2px rgba(47,141,37,.08)!important}
        .ni-empty{padding:26px;border:1px dashed #ccd7dd;border-radius:7px;color:#8797a0;text-align:center;font-size:14px}
        .ni-summary{margin-top:18px;padding-top:18px;border-top:2px solid #e2e7ea;display:grid;grid-template-columns:1fr minmax(420px,52%);gap:20px}
        .ni-client-view-wrap{min-width:0}.ni-client-view{display:flex;align-items:center;gap:9px;color:#516a77;font-size:14px}.ni-client-view i{font-size:20px;color:#3b5966}.ni-client-view a{margin-left:8px;color:var(--ni-green-dark);font-weight:700;text-decoration:underline!important}.ni-client-view-panel{display:none;max-width:620px;margin-top:22px;padding:18px 16px 16px;border:1px solid var(--ni-border);border-radius:8px;background:#fff}.ni-client-view-panel.show{display:block}.ni-client-view-panel h3{margin:0 0 14px;color:#183845;font-size:16px;font-weight:700}.ni-client-view-option{min-height:36px;display:flex;align-items:center;justify-content:space-between;gap:20px;color:#405d6a;font-size:14px}.ni-client-view-panel p{margin:14px 0 0;color:#607782;font-size:13px;line-height:1.45}.ni-switch{position:relative;width:44px;height:24px;padding:0;border:0;border-radius:999px;background:#cbd5db;cursor:pointer;transition:background .16s ease}.ni-switch.on{background:var(--ni-green)}.ni-switch-check{position:absolute;left:8px;top:3px;color:#fff;font-size:13px;line-height:18px;opacity:0}.ni-switch.on .ni-switch-check{opacity:1}.ni-switch-knob{position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.18);transition:left .16s ease}.ni-switch.on .ni-switch-knob{left:23px}
        .ni-totals{display:grid;gap:0}.ni-total-row{min-height:43px;padding:0 0;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--ni-border);color:#445f6d;font-size:14px}.ni-total-row strong{color:#183845;font-size:14px}.ni-total-row.grand{min-height:48px;border-bottom:3px solid #e1e6e9;font-weight:700}.ni-total-row.grand strong{font-size:20px}.ni-total-row.balance{margin-top:14px;padding:0 12px;border:0;border-radius:6px;background:#faf9f7}.ni-total-link{border:0;background:transparent;color:var(--ni-green-dark);font:600 13px Arial,Helvetica,sans-serif;text-decoration:underline;cursor:pointer}
        .ni-adjustment-row{display:grid;grid-template-columns:minmax(105px,1fr) minmax(170px,220px) 110px 34px;gap:8px;align-items:center}
        .ni-adjustment-row>.ni-total-link{grid-column:2/5;justify-self:end}
        .ni-adjustment-row>strong{grid-column:3;text-align:right;white-space:nowrap}
        .ni-discount-control,.ni-tax-control{grid-column:2;min-width:0}
        .ni-discount-control{display:flex;align-items:stretch}
        .ni-discount-control input{width:112px;height:38px;padding:7px 10px;border:1px solid var(--ni-border);border-right:0;border-radius:7px 0 0 7px;outline:0;color:#183845;background:#fff;font:inherit}
        .ni-discount-control select{width:70px;height:38px;padding:6px 8px;border:1px solid var(--ni-border);border-radius:0 7px 7px 0;outline:0;color:#183845;background:#fff;font:inherit}
        .ni-discount-control input:focus,.ni-discount-control select:focus,.ni-tax-selector:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
        .ni-adjust-remove{grid-column:4;width:32px;height:36px;padding:0;border:0;border-radius:6px;background:transparent;color:#506b77;cursor:pointer;display:grid;place-items:center}
        .ni-adjust-remove:hover{color:var(--ni-danger);background:#fff1f1}
        .ni-tax-control{position:relative}
        .ni-tax-selector{width:100%;height:38px;padding:7px 34px 7px 11px;border:1px solid var(--ni-border);border-radius:7px;background:#fff;color:#405d6a;text-align:left;font:inherit;cursor:pointer;position:relative;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
        .ni-tax-selector i{position:absolute;right:11px;top:50%;transform:translateY(-50%);font-size:12px}
        .ni-tax-menu{display:none;position:absolute;left:0;right:0;top:calc(100% + 5px);z-index:1200;min-width:220px;padding:6px 0;border:1px solid var(--ni-border);border-radius:7px;background:#fff;box-shadow:0 10px 24px rgba(0,17,49,.14)}
        .ni-tax-menu.show{display:block}
        .ni-tax-menu-empty{padding:9px 11px;color:#6f818b;font-size:13px}
        .ni-tax-menu button{width:100%;padding:9px 11px;border:0;background:#fff;color:#36535f;text-align:left;font:inherit;cursor:pointer}
        .ni-tax-menu button:hover{background:#f5faf2}
        .ni-tax-menu .ni-create-tax{border-top:1px solid var(--ni-border);color:var(--ni-green-dark);font-weight:700;text-decoration:underline}
        .ni-tax-menu small{display:block;margin-top:2px;color:#7b8d96;font-size:11px}
        .ni-tax-modal .modal-dialog{max-width:540px}
        .ni-tax-modal .modal-content{border:0;border-radius:10px;box-shadow:0 22px 60px rgba(0,17,49,.22)}
        .ni-tax-modal .modal-header{padding:22px 24px 12px;border-bottom:0}.ni-tax-modal .modal-title{font-size:23px;color:var(--ni-text);font-weight:700}
        .ni-tax-modal .modal-body{padding:8px 24px 10px}.ni-tax-modal .modal-footer{padding:12px 24px 22px;border-top:0}
        .ni-tax-pair{display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:14px}.ni-tax-pair .ni-floating:first-child input{border-radius:8px 0 0 8px}.ni-tax-pair .ni-floating:last-child input{border-radius:0 8px 8px 0}
        .ni-tax-default{display:flex;align-items:center;gap:9px;margin-top:13px;color:#405d6a;font-size:14px}.ni-tax-default input{width:19px;height:19px;accent-color:var(--ni-green)}
        .ni-tax-help{margin:13px 0 0;color:#607782;font-size:13px}.ni-tax-help a{color:var(--ni-green-dark);text-decoration:underline!important}
        .ni-add-section{padding:12px 28px 0;background:#fff}.ni-add-section-inner{display:inline-flex;align-items:center;gap:7px;padding:7px 9px;border-radius:8px;background:#f1f0ed}.ni-add-section-inner span{color:#3e5966;font-size:13px}.ni-section-chip{height:29px;padding:0 11px;border:1px solid #d5dde1;border-radius:6px;background:#fff;color:#24424f;font-size:13px;font-weight:600;cursor:pointer}.ni-section-chip:hover{color:var(--ni-green-dark);border-color:#b9d9ab;background:#f7fbf5}.ni-section-chip[hidden]{display:none!important}
        .ni-extra-section{display:none;padding:20px 28px 0;background:#fff}.ni-extra-section.show{display:block}.ni-extra-card{position:relative;padding:21px 20px;border:1px solid var(--ni-border);border-radius:8px;background:#fff}.ni-extra-card h2{margin:0 0 16px;color:var(--ni-text);font-size:19px}.ni-extra-remove{position:absolute;top:17px;right:18px;width:30px;height:30px;border:0;border-radius:6px;background:transparent;color:#46616e;cursor:pointer}.ni-extra-remove:hover{color:var(--ni-danger);background:#fff1f1}
        .ni-customize-row .ni-right-value{display:flex;justify-content:flex-start}.ni-custom-add{height:31px;padding:0 11px;border:1px solid var(--ni-border);border-radius:7px;background:#fff;color:var(--ni-green-dark);font-size:13px;font-weight:700;cursor:pointer}.ni-custom-add:hover{border-color:#b9d9ab;background:var(--ni-green-soft)}
        .ni-custom-fields{grid-column:1/-1;display:grid;gap:7px;padding:8px 0 4px}.ni-custom-field{position:relative;display:grid;grid-template-columns:1fr 1fr 31px;gap:7px;align-items:center}.ni-custom-field input{width:100%;height:36px;padding:7px 9px;border:1px solid var(--ni-border);border-radius:7px;background:#fff;color:#183845;font-family:inherit;font-size:13.5px;outline:0}.ni-custom-field input:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}.ni-custom-field button{width:31px;height:31px;border:0;border-radius:6px;background:transparent;color:#718692;cursor:pointer}.ni-custom-field button:hover{color:var(--ni-danger);background:#fff1f1}
        .ni-upload-copy{margin:0 0 11px;color:var(--ni-muted);font-size:13.5px}.ni-upload-counter{position:absolute;right:52px;top:23px;color:#718692;font-size:13px}.ni-upload-drop{min-height:74px;padding:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;border:1px dashed #cbd7dd;border-radius:7px;background:#fff;color:#708490;font-size:13px;text-align:center}.ni-upload-btn{height:31px;padding:0 12px;border:1px solid #d5dde1;border-radius:6px;background:#fff;color:var(--ni-green-dark);font-size:13px;font-weight:700;cursor:pointer}.ni-upload-btn:hover{border-color:#b8d89e;background:#f7fbf5}.ni-file-list{margin-top:10px;display:grid;gap:7px}.ni-file-row{min-height:38px;padding:7px 10px;display:flex;align-items:center;gap:8px;border:1px solid #e4eaee;border-radius:7px;background:#fbfcfc;color:#425e6b;font-size:13.5px}.ni-file-row i{color:#607d89}.ni-file-row span{min-width:0;flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.ni-file-row small{color:#899aa3}.ni-file-remove{width:28px;height:28px;border:0;border-radius:6px;background:transparent;color:#718692;cursor:pointer}.ni-file-remove:hover{color:var(--ni-danger);background:#fff1f1}
        .ni-notes{padding:22px 28px 28px}.ni-notes h2{margin:0 0 12px;color:var(--ni-text);font-size:20px}
        .ni-note-editor{position:relative}
        .ni-notes textarea{width:100%;min-height:78px;padding:13px;border:1px solid #79ad64;border-radius:8px;background:#fff;color:#183845;font-family:inherit;font-size:14px;line-height:1.5;resize:vertical;outline:0}.ni-notes textarea:focus{border-color:#5f9d45;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
        .ni-mention-menu{display:none;position:absolute;left:10px;top:calc(100% - 3px);z-index:1300;width:min(320px,calc(100% - 20px));max-height:220px;overflow:auto;padding:4px 0;border:1px solid #d8e0e4;border-radius:7px;background:#fff;box-shadow:0 8px 22px rgba(0,17,49,.16)}.ni-mention-menu.show{display:block}.ni-mention-option{width:100%;min-height:42px;padding:8px 12px;display:flex;align-items:center;gap:9px;border:0;background:#fff;color:#314f5d;text-align:left;cursor:pointer}.ni-mention-option:hover,.ni-mention-option.active{background:#f1f0ed}.ni-mention-avatar{width:28px;height:28px;flex:0 0 28px;display:grid;place-items:center;border-radius:50%;background:#edf6e8;color:#2f8d25;font-size:11px;font-weight:700}.ni-mention-copy{min-width:0;flex:1}.ni-mention-name{display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-size:13px}.ni-mention-role{display:block;margin-top:2px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#7b8d96;font-size:11px}
        .ni-note-mention-help{margin:7px 0 0;color:#6d818c;font-size:12px}
        .ni-note-upload{margin-top:13px;min-height:80px;padding:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;border:1px dashed #d5dde1;border-radius:8px;background:#fff;text-align:center}.ni-note-upload.dragover{border-color:#79ad64;background:#f8fcf6}.ni-note-upload button{height:32px;padding:0 13px;border:1px solid #d7dfe3;border-radius:7px;background:#fff;color:var(--ni-green-dark);font-family:inherit;font-size:13px;font-weight:700;cursor:pointer}.ni-note-upload button:hover{border-color:#a9cc98;background:#f8fcf6}.ni-note-upload small{color:#617783;font-size:11px}
        .ni-note-files{margin-top:9px;display:grid;gap:7px}.ni-note-file{min-height:39px;padding:7px 10px;display:flex;align-items:center;gap:8px;border:1px solid #e3e9ec;border-radius:7px;background:#fbfcfc;color:#405c69;font-size:13px}.ni-note-file i{color:#61808d}.ni-note-file span{min-width:0;flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.ni-note-file small{color:#8899a2}.ni-note-file button{width:29px;height:29px;padding:0;display:grid;place-items:center;border:0;border-radius:6px;background:transparent;color:#718692;cursor:pointer}.ni-note-file button:hover{color:var(--ni-danger);background:#fff1f1}
        .ni-savebar{position:fixed;left:var(--fieldplx-sidebar-width);right:0;bottom:0;z-index:1025;height:64px;padding:10px 28px;display:flex;align-items:center;justify-content:flex-end;gap:8px;border-top:1px solid var(--ni-border);background:rgba(255,255,255,.98);box-shadow:0 -4px 12px rgba(0,17,49,.04)}body.fieldplx-sidebar-collapsed .ni-savebar{left:var(--fieldplx-sidebar-collapsed-width)}
        .ni-btn{height:38px;padding:0 14px;border:1px solid var(--ni-border);border-radius:7px;background:#fff;color:#31505d;font-size:14px;font-weight:700;cursor:pointer}.ni-btn.primary{border-color:var(--ni-green);background:var(--ni-green);color:#fff}.ni-btn.primary:hover{background:var(--ni-green-dark)}.ni-btn:disabled{opacity:.55;cursor:not-allowed}
        .select2-container{width:100%!important}.select2-container .select2-selection--single{height:43px!important;border:1px solid var(--ni-border)!important;border-radius:7px!important;background:#fff!important}.select2-container .select2-selection--single .select2-selection__rendered{height:41px!important;line-height:41px!important;padding-left:12px!important;padding-right:28px!important;display:block!important;overflow:hidden!important;white-space:nowrap!important;text-overflow:ellipsis!important;color:#183845!important;font-size:14px!important}.select2-container .select2-selection--single .select2-selection__arrow{height:41px!important}.select2-container--focus .select2-selection--single,.select2-container--open .select2-selection--single{border-color:#91bd7e!important;box-shadow:0 0 0 2px rgba(47,141,37,.08)!important}.select2-dropdown{border:1px solid var(--ni-border)!important;border-radius:7px!important;overflow:hidden!important;box-shadow:0 12px 24px rgba(0,17,49,.12)!important}.select2-search__field{height:34px!important;border:1px solid var(--ni-border)!important;border-radius:6px!important;font-size:14px!important}.select2-results__option{padding:8px 10px!important;font-size:13.5px!important}.select2-results__option--highlighted[aria-selected]{background:#eaf5e5!important;color:#244f1c!important}
        /* Jobber-style Product / Service composer */
        .ni-line-composer{padding:0;background:#fff}
        .ni-composer-main{display:grid;grid-template-columns:minmax(420px,1fr) 160px 180px 180px;gap:10px;align-items:start}
        .ni-catalog-field{position:relative;min-width:0;padding-left:20px}
        .ni-drag-handle{position:absolute;left:-3px;top:12px;color:#416170;cursor:grab}
        .ni-composer-main input,.ni-composer-description{width:100%;border:1px solid var(--ni-border);border-radius:8px;background:#fff;color:#183845;font-family:inherit;font-size:14px;outline:0}
        .ni-composer-main input{height:54px;padding:24px 12px 8px;line-height:18px}
        .ni-composer-main .ni-labeled span{top:8px;left:12px}
        .ni-composer-main .ni-line-total{height:54px;padding-top:24px}
        .ni-composer-description{min-height:104px;margin-top:10px;padding:14px;resize:vertical}
        .ni-composer-main input:focus,.ni-composer-description:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.07)}
        .ni-line-composer .select2-container .select2-selection--single{height:51px!important;border-radius:8px!important}
        .ni-line-composer .select2-container .select2-selection--single .select2-selection__rendered{height:49px!important;line-height:49px!important;padding-left:14px!important}
        .ni-line-composer .select2-container .select2-selection--single .select2-selection__arrow{height:49px!important}
        .ni-catalog-result{display:flex;align-items:flex-start;gap:10px;padding:2px 0}
        .ni-catalog-copy{min-width:0;flex:1}
        .ni-catalog-name{display:flex;align-items:center;gap:8px;color:#284755}
        .ni-catalog-desc{margin-top:3px;color:#6b808b;line-height:1.35}
        .ni-catalog-price{margin-left:auto;white-space:nowrap;color:#284755}
        .ni-type-badge{display:inline-flex;align-items:center;min-height:20px;padding:2px 7px;border-radius:999px;font-weight:700;line-height:1}
        .ni-type-badge.service{color:#28701f;background:#edf7e9;border:1px solid #cae6c2}
        .ni-type-badge.product{color:#245d83;background:#eef6fb;border:1px solid #c9deeb}
        .ni-create-option{display:flex;align-items:center;gap:10px;color:var(--ni-green-dark);font-weight:700}
        .ni-create-option i{font-size:1.15em}
        .ni-service-date-row{min-height:44px;display:flex;align-items:center;gap:12px;padding:8px 12px 4px}
        .ni-service-date-link{padding:0;border:0;background:transparent;color:var(--ni-green-dark);text-decoration:underline;font-weight:700;cursor:pointer}
        .ni-service-date-input{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}
        
        .ni-line-catalog-label{display:flex;align-items:center;gap:7px;margin-bottom:7px}
        .ni-line-type{display:inline-flex;padding:2px 7px;border-radius:999px;background:#f4f7f8;color:#526b78;font-weight:700}
        .ni-line-service-date{position:relative;min-height:36px;margin-top:8px;display:flex;align-items:center;gap:8px}
        .ni-line-service-date button{padding:0;border:0;background:transparent;color:var(--ni-green-dark);text-decoration:underline;font-weight:700;cursor:pointer}
        .ni-line-service-date input{width:170px;max-width:100%;height:36px;padding:7px 9px;position:static;opacity:1;pointer-events:auto}
        .ni-add-line-wrap{margin-top:18px;display:flex;justify-content:flex-start}
        .ni-add-line-wrap .ni-add-line{height:40px;margin:0;padding:0 15px}
        .ni-line{margin-bottom:28px;padding:16px 14px 18px}
        .ni-line-main .ni-line-catalog{min-width:0}
        .ni-line-main .ni-line-catalog .select2-container .select2-selection--single{height:48px!important;border-radius:7px!important}
        .ni-line-main .ni-line-catalog .select2-container .select2-selection__rendered{height:46px!important;line-height:46px!important;padding-left:12px!important;font-size:14px!important}
        .ni-line-main .ni-line-catalog .select2-selection__arrow{height:46px!important}
        .ni-line-main .ni-labeled input,.ni-line-main .ni-line-total{height:48px}
        .ni-line-main .ni-labeled input{padding:22px 10px 7px}.ni-line-main .ni-labeled span{top:7px;left:10px}
        .ni-line-main .ni-line-total{padding:22px 10px 6px}.ni-line-main .ni-line-total:before{top:7px;left:10px}
        .ni-line-extra{margin-top:10px}
        .ni-line-service-date{margin-top:10px}


        /* Customer-first invoice header */
        .ni-client-wrap{display:block!important}
        .ni-customer-picker{max-width:640px}
        .ni-customer-picker .select2-container .select2-selection--single,.ni-location-picker .select2-container .select2-selection--single{height:46px!important;border-radius:8px!important}
        .ni-customer-picker .select2-container .select2-selection__rendered,.ni-location-picker .select2-container .select2-selection__rendered{height:44px!important;line-height:44px!important;padding-left:13px!important;font-size:14px!important}
        .ni-customer-picker .select2-selection__arrow,.ni-location-picker .select2-selection__arrow{height:44px!important}
        .ni-customer-card{display:none;position:relative;min-height:226px;padding:20px 22px;border:1px solid #d9e1e5;border-radius:8px;background:#fff;color:#173846}
        .ni-customer-card.show{display:block}
        .ni-customer-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:13px}
        .ni-customer-card-name{display:flex;align-items:center;gap:6px;color:#113448;font-size:16px;font-weight:700;line-height:1.25}
        .ni-customer-active-dot{width:7px;height:7px;border-radius:50%;background:#2f8d25;display:inline-block;flex:0 0 7px}
        .ni-customer-menu-wrap{position:relative}
        .ni-customer-menu-button{width:34px;height:30px;border:0;border-radius:6px;background:transparent;color:#274b5b;display:grid;place-items:center;cursor:pointer;font-size:19px;font-weight:700;line-height:1}
        .ni-customer-menu-button:hover{background:#f3f7f8}
        .ni-customer-menu{display:none;position:absolute;right:0;top:34px;z-index:1210;width:190px;padding:6px;border:1px solid var(--ni-border);border-radius:8px;background:#fff;box-shadow:0 12px 28px rgba(0,17,49,.15)}
        .ni-customer-menu.show{display:block}
        .ni-customer-menu button{width:100%;padding:9px 10px;border:0;border-radius:6px;background:#fff;color:#36535f;text-align:left;font:inherit;font-size:13px;cursor:pointer}
        .ni-customer-menu button:hover{background:#f5faf2;color:var(--ni-green-dark)}
        .ni-customer-block{margin-top:12px}
        .ni-customer-block-label{display:block;margin-bottom:3px;color:#5e7581;font-size:13px}
        .ni-customer-block-value{display:block;color:#173846;font-size:14px;line-height:1.35;white-space:pre-line}
        .ni-customer-contact{margin-top:12px;display:grid;gap:3px}
        .ni-customer-contact a{width:max-content;max-width:100%;overflow:hidden;text-overflow:ellipsis;color:var(--ni-green-dark)!important;font-size:13px;text-decoration:underline!important}
        .ni-location-picker{display:none;max-width:640px;margin-top:10px}
        .ni-location-picker.show{display:block}
        .ni-linked-job{display:none;max-width:640px;margin-top:10px;padding:10px 12px;border:1px solid #dbe6d4;border-radius:7px;background:#f7fbf5;color:#405d4a;font-size:13px}
        .ni-linked-job.show{display:flex;align-items:center;gap:8px}
        .ni-linked-job i{color:var(--ni-green-dark)}
        .ni-linked-job strong{color:#214a28}
        .ni-linked-job button{margin-left:auto;padding:0;border:0;background:transparent;color:var(--ni-green-dark);font:700 12px Arial,Helvetica,sans-serif;text-decoration:underline;cursor:pointer}
        .ni-create-option{font-size:14px}

        /* Salesperson control - Jobber-style compact pill */
        .ni-salesperson-ui{max-width:260px}
        .ni-salesperson-ui .select2-container{width:100%!important;max-width:260px!important}
        .ni-salesperson-ui .select2-container .select2-selection--single{height:38px!important;border:1px solid var(--ni-border)!important;border-radius:999px!important;background:#fff!important}
        .ni-salesperson-ui .select2-selection__rendered{height:36px!important;line-height:36px!important;padding-left:14px!important;padding-right:32px!important;color:#153442!important;font-size:14px!important}
        .ni-salesperson-ui .select2-selection__arrow{height:36px!important;right:6px!important}
        .ni-salesperson-static{display:inline-flex;align-items:center;min-height:34px;color:#153442;font-size:14px}

        /* Create customer/location modals */
        .ni-quick-modal .modal-dialog{max-width:620px}
        .ni-quick-modal .modal-content{border:0;border-radius:10px;box-shadow:0 22px 60px rgba(0,17,49,.22)}
        .ni-quick-modal .modal-header{padding:22px 24px 12px;border-bottom:0}
        .ni-quick-modal .modal-title{margin:0;color:var(--ni-text);font-size:23px;font-weight:700}
        .ni-quick-modal .modal-body{padding:8px 24px 10px}
        .ni-quick-modal .modal-footer{padding:12px 24px 22px;border-top:0}
        .ni-quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .ni-quick-grid .full{grid-column:1/-1}
        .ni-quick-check{display:flex;align-items:center;gap:8px;margin-top:14px;color:#405d6a;font-size:14px}
        .ni-quick-check input{width:18px;height:18px;accent-color:var(--ni-green)}

        /* Customer jobs modal */
        .ni-jobs-modal .modal-dialog{max-width:850px}
        .ni-jobs-modal .modal-content{border:1px solid #9aa9b0;border-radius:10px;box-shadow:0 22px 65px rgba(0,17,49,.25)}
        .ni-jobs-modal .modal-header{padding:27px 30px 20px;border-bottom:0}
        .ni-jobs-modal .modal-title{margin:0;color:#0f3545;font-size:22px;font-weight:700}
        .ni-jobs-modal .modal-body{padding:18px 30px 20px}
        .ni-jobs-table-wrap{min-height:330px;border:1px solid #d9e1e5;border-radius:7px;overflow:auto;background:#fff}
        .ni-jobs-table{width:100%;border-collapse:collapse;table-layout:fixed}
        .ni-jobs-table th{padding:13px 10px;border-bottom:1px solid #d9e1e5;color:#173846;background:#fff;font-size:12px;font-weight:700;text-align:left}
        .ni-jobs-table td{padding:12px 10px;vertical-align:top;color:#36535f;font-size:13px;line-height:1.35}
        .ni-jobs-table th:nth-child(1),.ni-jobs-table td:nth-child(1){width:52px;text-align:center}
        .ni-jobs-table th:nth-child(2),.ni-jobs-table td:nth-child(2){width:95px}
        .ni-jobs-table th:nth-child(4),.ni-jobs-table td:nth-child(4){width:132px}
        .ni-jobs-table th:nth-child(5),.ni-jobs-table td:nth-child(5){width:105px;text-align:right}
        .ni-jobs-table th:nth-child(6),.ni-jobs-table td:nth-child(6){width:105px;text-align:right}
        .ni-job-check{width:18px;height:18px;accent-color:var(--ni-green);cursor:pointer}
        .ni-job-status{display:inline-flex;align-items:center;gap:5px;padding:4px 7px;border-radius:999px;background:#fff1ee;color:#b34a42;font-size:11px;font-weight:700}
        .ni-job-status:before{content:'';width:6px;height:6px;border-radius:50%;background:#e95f54}
        .ni-job-title{display:block;color:var(--ni-green-dark);font-size:13px;font-weight:700;text-decoration:underline}
        .ni-job-sub{display:block;margin-top:2px;color:#697d87;font-size:12px}
        .ni-job-money{color:#244653;font-weight:700;white-space:nowrap}
        .ni-jobs-empty{padding:60px 20px;color:#7a8d96;text-align:center;font-size:14px}
        .ni-jobs-modal .modal-footer{padding:0 30px 28px;border-top:0;gap:8px}
        .ni-jobs-modal .ni-btn{height:39px;padding:0 16px}

        /* Add Product / Service modal */
        .ni-catalog-modal .modal-dialog{max-width:720px}
        .ni-catalog-modal .modal-content{border:0;border-radius:10px;box-shadow:0 22px 60px rgba(0,17,49,.22)}
        .ni-catalog-modal .modal-header{padding:22px 24px;border-bottom:0}
        .ni-catalog-modal .modal-title{margin:0;color:var(--ni-text);font-size:24px;font-weight:700}
        .ni-catalog-modal .modal-body{padding:6px 24px 12px}
        .ni-catalog-modal .modal-footer{padding:14px 24px 22px;border-top:0}
        .ni-modal-field{margin-bottom:14px}
        .ni-new-item-costs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0;margin-top:14px}
        .ni-new-item-costs .ni-floating input{border-radius:0}
        .ni-new-item-costs .ni-floating:first-child input{border-radius:8px 0 0 8px}
        .ni-new-item-costs .ni-floating:last-child input{border-radius:0 8px 8px 0}
        .ni-tax-exempt{display:flex;align-items:center;gap:8px;margin-top:16px;color:#334f5c;cursor:pointer}
        .ni-tax-exempt input{width:20px;height:20px;accent-color:var(--ni-green)}
        .ni-item-image-upload{margin-top:16px}
        .ni-item-image-input{display:none!important}
        .ni-item-image-drop{width:100%;min-height:128px;padding:16px;border:1px dashed #c7d4da;border-radius:8px;background:#fff;color:#607986;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;cursor:pointer;text-align:center;font-family:inherit;font-size:14px}
        .ni-item-image-drop:hover,.ni-item-image-drop.dragover{border-color:#91bd7e;background:#f8fcf6}
        .ni-item-image-drop i{font-size:28px;color:var(--ni-green)}
        .ni-item-image-drop strong{font-size:14px;color:var(--ni-green-dark)}
        .ni-item-image-drop small{font-size:12px;color:#7b8d96}
        .ni-item-image-preview{min-height:128px;padding:10px;border:1px solid var(--ni-border);border-radius:8px;background:#fbfcfc;display:flex;align-items:center;gap:14px}
        .ni-item-image-preview img{width:112px;height:106px;border-radius:7px;object-fit:cover;border:1px solid #dfe6ea;background:#fff}
        .ni-item-image-preview-copy{min-width:0;flex:1}
        .ni-item-image-preview-name{display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#284755;font-size:14px;font-weight:700}
        .ni-item-image-preview-size{display:block;margin-top:5px;color:#7b8d96;font-size:12px}
        .ni-item-image-remove{margin-top:10px;padding:0;border:0;background:transparent;color:#b34141;font:700 13px Arial,Helvetica,sans-serif;text-decoration:underline;cursor:pointer}

        @media(max-width:991.98px){.ni-savebar,body.fieldplx-sidebar-collapsed .ni-savebar{left:0}.ni-top-grid{grid-template-columns:1fr}.ni-right-row{grid-template-columns:150px 1fr}.ni-summary{grid-template-columns:1fr}.ni-line-main{grid-template-columns:minmax(220px,1fr) 110px 140px 140px 42px}.ni-line-extra{grid-template-columns:1fr 150px}}
        @media(max-width:767.98px){.ni-top,.ni-section,.ni-notes{padding-left:14px;padding-right:14px}.ni-quick-grid{grid-template-columns:1fr}.ni-quick-grid .full{grid-column:auto}.ni-customer-card{padding:17px 16px}.ni-jobs-modal .modal-header,.ni-jobs-modal .modal-body,.ni-jobs-modal .modal-footer{padding-left:16px;padding-right:16px}.ni-add-section,.ni-extra-section{padding-left:14px;padding-right:14px}.ni-location-row{grid-template-columns:1fr}.ni-right-row{grid-template-columns:120px 1fr}.ni-line-main,.ni-line-extra{grid-template-columns:1fr 1fr}.ni-line-main .ni-line-name{grid-column:1/-1}.ni-line-main .ni-remove{position:absolute;top:11px;right:10px}.ni-line-extra textarea{grid-column:1/-1}.ni-summary{grid-template-columns:1fr}.ni-item-select{min-width:0;flex-basis:100%}.ni-savebar{padding-left:14px;padding-right:14px}}
        @media(max-width:575.98px){.ni-line-main,.ni-line-extra{grid-template-columns:1fr}.ni-right-row{grid-template-columns:1fr;gap:4px;padding:8px 0}.ni-custom-field{grid-template-columns:1fr}.ni-custom-field button{position:absolute;right:0}.ni-item-add{display:grid;grid-template-columns:1fr}.ni-item-mode{width:max-content}.ni-summary{gap:14px}.ni-source-line{align-items:flex-start;flex-direction:column}.ni-segment{width:100%}.ni-segment button{flex:1}}

        @media(max-width:991.98px){.ni-composer-main{grid-template-columns:minmax(260px,1fr) 120px 145px 145px}}
        @media(max-width:767.98px){.ni-composer-main{grid-template-columns:1fr 1fr}.ni-catalog-field{grid-column:1/-1}.ni-new-item-costs{grid-template-columns:1fr}.ni-new-item-costs .ni-floating input,.ni-new-item-costs .ni-floating:first-child input,.ni-new-item-costs .ni-floating:last-child input{border-radius:8px}}
        @media(max-width:575.98px){.ni-composer-main{grid-template-columns:1fr}.ni-catalog-field{grid-column:auto}}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="ni-page">
                <form id="invoiceForm" class="ni-form" autocomplete="off">
                    <input type="hidden" name="items_json" id="itemsJson" value="[]">
                    <input type="hidden" name="source_mode" id="sourceMode" value="direct">
                    <input type="hidden" name="job_id" id="jobId" value="">
                    <input type="hidden" name="visit_id" id="visitId" value="">
                    <input type="hidden" name="salesperson_id" id="salespersonId" value="">
                    <input type="hidden" name="branch_id" id="branchId" value="">
                    <input type="hidden" name="custom_fields_json" id="customFieldsJson" value="[]">
                    <input type="hidden" name="client_view_options_json" id="clientViewOptionsJson" value="">
                    <input type="hidden" name="invoice_no_mode" id="invoiceNoMode" value="auto">
                    <input type="hidden" name="invoice_discount_type" id="invoiceDiscountType" value="fixed">
                    <input type="hidden" name="invoice_discount_value" id="invoiceDiscountValue" value="0">
                    <input type="hidden" name="invoice_tax_rate_id" id="invoiceTaxRateId" value="">

                    <section class="ni-top">
                        <div class="ni-heading"><i class="bi bi-file-earmark-text"></i><h1><?php echo $similarInvoiceId > 0 ? 'Create Similar Invoice' : 'New Invoice'; ?></h1></div>
                        <div class="ni-subject ni-floating">
                            <label for="invoiceSubject">Subject</label>
                            <input id="invoiceSubject" name="subject" type="text" maxlength="190" value="For Services Rendered">
                        </div>

                        <div class="ni-top-grid">
                            <div class="ni-left">
                                <div class="ni-client-wrap show" id="directSourcePanel">
                                    <div class="ni-customer-picker" id="clientPickerWrap">
                                        <select id="clientId" name="client_id"><option value=""></option></select>
                                    </div>

                                    <div class="ni-customer-card" id="selectedClientCard">
                                        <div class="ni-customer-card-head">
                                            <div class="ni-customer-card-name"><span id="clientCardName">Customer</span><span class="ni-customer-active-dot" aria-hidden="true"></span></div>
                                            <div class="ni-customer-menu-wrap">
                                                <button type="button" class="ni-customer-menu-button" id="clientMenuButton" aria-label="Customer options">...</button>
                                                <div class="ni-customer-menu" id="clientMenu">
                                                    <button type="button" id="changeClientButton">Change customer</button>
                                                    <button type="button" id="changeLocationButton">Change location</button>
                                                    <button type="button" id="selectCustomerJobButton">Select customer job</button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="ni-customer-block"><span class="ni-customer-block-label">Billing Address</span><span class="ni-customer-block-value" id="clientBillingAddress">-</span></div>
                                        <div class="ni-customer-block"><span class="ni-customer-block-label">Property Address</span><span class="ni-customer-block-value" id="clientPropertyAddress">-</span></div>
                                        <div class="ni-customer-contact">
                                            <a href="#" id="clientPhoneLink" style="display:none"></a>
                                            <a href="#" id="clientEmailLink" style="display:none"></a>
                                        </div>
                                    </div>

                                    <div class="ni-location-picker" id="locationPickerWrap">
                                        <select id="locationId" name="location_id"><option value=""></option></select>
                                    </div>

                                    <div class="ni-linked-job" id="linkedJobBar">
                                        <i class="bi bi-briefcase"></i>
                                        <span id="linkedJobText"></span>
                                        <button type="button" id="removeLinkedJobButton">Remove</button>
                                    </div>
                                </div>
                            </div>
                            <div class="ni-right">
                                <div class="ni-right-row"><div class="ni-right-label">Invoice #</div><div class="ni-right-value"><input type="text" id="invoiceNo" name="invoice_no" class="ni-invoice-number" maxlength="80" value="" placeholder="Loading current invoice number..." autocomplete="off" spellcheck="false"></div></div>
                                <div class="ni-right-row"><div class="ni-right-label">Issued date</div><div class="ni-right-value"><input type="date" id="issueDate" name="issue_date" required></div></div>
                                <div class="ni-right-row"><div class="ni-right-label">Payment terms</div><div class="ni-right-value"><select id="paymentTermsPreset"><option value="0">Due upon receipt</option><option value="7">Net 7</option><option value="15">Net 15</option><option value="30">Net 30</option><option value="45">Net 45</option><option value="custom">Custom</option></select><input type="hidden" id="paymentTerms" name="payment_terms" value="Due upon receipt"></div></div>
                                <div class="ni-right-row" id="dueDateRow" style="display:none"><div class="ni-right-label">Due date</div><div class="ni-right-value"><input type="date" id="dueDate" name="due_date"></div></div>
                                <div class="ni-right-row"><div class="ni-right-label">Salesperson</div><div class="ni-right-value ni-salesperson-ui"><span class="ni-salesperson-static" id="salespersonName">Current user</span><select id="salespersonSelect" style="display:none"><option value=""></option></select></div></div>
                                <div class="ni-right-row ni-customize-row"><div class="ni-right-label">Customize</div><div class="ni-right-value"><button type="button" class="ni-custom-add" id="addCustomField"><i class="bi bi-plus-lg"></i> Add Field</button></div></div>
                                <div id="customFields" class="ni-custom-fields"></div>
                            </div>
                        </div>
                    </section>

                    <section class="ni-section">
                        <div class="ni-box">
                            <div class="ni-box-pad">
                                <h2 class="ni-section-title">Product / Service</h2>

                                <div id="itemRows"></div>

                                <div class="ni-add-line-wrap">
                                    <button type="button" class="ni-add-line" id="addLineButton">Add Line Item</button>
                                </div>

                                <div class="ni-summary">
                                    <div class="ni-client-view-wrap">
                                        <div class="ni-client-view">
                                            <i class="bi bi-eye"></i>
                                            <span>Client view</span>
                                            <a href="#" id="clientViewToggle">Change</a>
                                        </div>
                                        <div class="ni-client-view-panel" id="clientViewPanel" aria-hidden="true">
                                            <h3>Client view</h3>
                                            <div class="ni-client-view-option"><span>Quantities</span><button type="button" class="ni-switch on" data-client-view-key="quantities" aria-pressed="true"><span class="ni-switch-check">✓</span><span class="ni-switch-knob"></span></button></div>
                                            <div class="ni-client-view-option"><span>Unit prices</span><button type="button" class="ni-switch on" data-client-view-key="unit_prices" aria-pressed="true"><span class="ni-switch-check">✓</span><span class="ni-switch-knob"></span></button></div>
                                            <div class="ni-client-view-option"><span>Line item totals</span><button type="button" class="ni-switch on" data-client-view-key="line_item_totals" aria-pressed="true"><span class="ni-switch-check">✓</span><span class="ni-switch-knob"></span></button></div>
                                            <div class="ni-client-view-option"><span>Account balance</span><button type="button" class="ni-switch on" data-client-view-key="account_balance" aria-pressed="true"><span class="ni-switch-check">✓</span><span class="ni-switch-knob"></span></button></div>
                                            <div class="ni-client-view-option"><span>Late stamp (if overdue)</span><button type="button" class="ni-switch on" data-client-view-key="late_stamp" aria-pressed="true"><span class="ni-switch-check">✓</span><span class="ni-switch-knob"></span></button></div>
                                            <p>Adjust what your client will see on this invoice. These choices are saved with this invoice.</p>
                                        </div>
                                    </div>
                                    <div class="ni-totals">
                                        <div class="ni-total-row"><span>Subtotal</span><strong id="sumSubtotal">0.00</strong></div>
                                        <div class="ni-total-row ni-adjustment-row" id="discountRow">
                                            <span>Discount</span>
                                            <button type="button" class="ni-total-link" id="discountLink">Add Discount</button>
                                            <div class="ni-discount-control" id="discountControl" style="display:none">
                                                <input type="number" id="discountInput" min="0" step="0.01" value="0.00" aria-label="Invoice discount">
                                                <select id="discountType" aria-label="Discount type"><option value="fixed" id="discountFixedOption">$</option><option value="percentage">%</option></select>
                                            </div>
                                            <strong id="sumDiscount" style="display:none">-$0.00</strong>
                                            <button type="button" class="ni-adjust-remove" id="discountRemove" style="display:none" title="Remove discount"><i class="bi bi-trash"></i></button>
                                        </div>
                                        <div class="ni-total-row ni-adjustment-row" id="taxRow">
                                            <span id="taxRowLabel">Tax</span>
                                            <button type="button" class="ni-total-link" id="taxLink">Add Tax</button>
                                            <div class="ni-tax-control" id="taxControl" style="display:none">
                                                <button type="button" class="ni-tax-selector" id="taxSelectorButton"><span id="taxSelectorText">Select tax rate</span><i class="bi bi-chevron-down"></i></button>
                                                <div class="ni-tax-menu" id="taxMenu"></div>
                                            </div>
                                            <strong id="sumTax" style="display:none">$0.00</strong>
                                            <button type="button" class="ni-adjust-remove" id="taxRemove" style="display:none" title="Remove selected tax rate"><i class="bi bi-trash"></i></button>
                                        </div>
                                        <div class="ni-total-row grand"><span>Total</span><strong id="sumTotal">0.00</strong></div>
                                        <div class="ni-total-row balance"><span>Invoice balance</span><strong id="sumBalance">0.00</strong></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="ni-add-section">
                        <div class="ni-add-section-inner"><span><i class="bi bi-plus-lg"></i> Add section</span><button type="button" class="ni-section-chip" data-section-toggle="clientMessageSection">Client Message</button><button type="button" class="ni-section-chip" data-section-toggle="imagesSection">Images</button><button type="button" class="ni-section-chip" data-section-toggle="attachmentsSection">Attachments</button><button type="button" class="ni-section-chip" data-section-toggle="contractSection" hidden>Contract / Disclaimer</button></div>
                    </div>

                    <section class="ni-extra-section" id="clientMessageSection">
                        <div class="ni-extra-card"><button type="button" class="ni-extra-remove" data-remove-section="clientMessageSection"><i class="bi bi-trash"></i></button><h2>Client Message</h2><div class="ni-floating"><label>Description</label><textarea name="client_message" id="clientMessage"></textarea></div></div>
                    </section>

                    <section class="ni-extra-section show" id="contractSection">
                        <div class="ni-extra-card"><button type="button" class="ni-extra-remove" data-remove-section="contractSection"><i class="bi bi-trash"></i></button><h2>Contract / Disclaimer</h2><div class="ni-floating"><label>Description</label><textarea name="contract_disclaimer" id="contractDisclaimer">Thank you for your business. Please contact us with any questions regarding this invoice.</textarea></div></div>
                    </section>

                    <section class="ni-extra-section" id="imagesSection">
                        <div class="ni-extra-card">
                            <button type="button" class="ni-extra-remove" data-remove-section="imagesSection"><i class="bi bi-trash"></i></button>
                            <h2>Images</h2>
                            <p class="ni-upload-copy">Add images from before and after the job</p>
                            <div class="ni-upload-counter" id="imageCounter">0 of 10 uploaded</div>
                            <div class="ni-upload-drop">
                                <input type="file" id="imageInput" accept="image/avif,image/jpeg,image/png,image/webp,image/heic,.heic" multiple hidden>
                                <button type="button" class="ni-upload-btn" data-pick-file="imageInput">Add Images</button>
                                <span>AVIF, JPEG, PNG, WEBP, HEIC up to 25MB each</span>
                            </div>
                            <div class="ni-file-list" id="imageList"></div>
                        </div>
                    </section>

                    <section class="ni-extra-section" id="attachmentsSection">
                        <div class="ni-extra-card">
                            <button type="button" class="ni-extra-remove" data-remove-section="attachmentsSection"><i class="bi bi-trash"></i></button>
                            <h2>Attachments</h2>
                            <p class="ni-upload-copy">Include all attachments for your invoice in one place</p>
                            <div class="ni-upload-counter" id="attachmentCounter">0 of 10 uploaded</div>
                            <div class="ni-upload-drop">
                                <input type="file" id="attachmentInput" accept=".avif,.jpg,.jpeg,.png,.webp,.heic,.pdf,.doc,.docx" multiple hidden>
                                <button type="button" class="ni-upload-btn" data-pick-file="attachmentInput">Select Files</button>
                                <span>AVIF, JPEG, PNG, WEBP, HEIC, PDF, DOCX up to 50MB each</span>
                            </div>
                            <div class="ni-file-list" id="attachmentList"></div>
                        </div>
                    </section>

                    <section class="ni-notes">
                        <h2>Notes</h2>
                        <div class="ni-note-editor">
                            <textarea name="notes" id="invoiceNotes" maxlength="10000" autocomplete="off" placeholder="Use @ in notes to mention your team"></textarea>
                            <div class="ni-mention-menu" id="noteMentionMenu" role="listbox"></div>
                        </div>
                        <input type="hidden" name="note_mentions_json" id="noteMentionsJson" value="[]">
                        <p class="ni-note-mention-help">Type @ and select an employee. Internal notes and their files are visible only to administrators, the note author, and mentioned team members.</p>
                        <div class="ni-note-upload" id="noteUploadDrop">
                            <input type="file" id="noteAttachmentInput" accept="image/avif,image/jpeg,image/png,image/webp,image/heic,.heic,.pdf,.doc,.docx" multiple hidden>
                            <button type="button" id="noteAttachButton">Attach files &amp; photos</button>
                            <small>Select or drag files here to upload</small>
                        </div>
                        <div class="ni-note-files" id="noteFileList"></div>
                    </section>

                    <div class="ni-savebar">
                        <button type="button" class="ni-btn" id="cancelButton">Cancel</button>
                        <button type="submit" class="ni-btn primary" id="saveButton">Save Invoice</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<div class="modal fade ni-quick-modal" id="createCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title">Create Customer</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="ni-quick-grid">
                    <div class="ni-floating full"><label for="newCustomerName">Customer name</label><input id="newCustomerName" type="text" maxlength="190"></div>
                    <div class="ni-floating full"><label for="newCustomerCompany">Company name</label><input id="newCustomerCompany" type="text" maxlength="190"></div>
                    <div class="ni-floating"><label for="newCustomerPhone">Phone</label><input id="newCustomerPhone" type="text" maxlength="50"></div>
                    <div class="ni-floating"><label for="newCustomerEmail">Email</label><input id="newCustomerEmail" type="email" maxlength="190"></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="ni-btn" data-bs-dismiss="modal">Cancel</button><button type="button" class="ni-btn primary" id="createCustomerButton">Create Customer</button></div>
        </div>
    </div>
</div>
<div class="modal fade ni-quick-modal" id="createLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title">Create Location</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="ni-quick-grid">
                    <div class="ni-floating"><label for="newLocationName">Location name</label><input id="newLocationName" type="text" maxlength="190"></div>
                    <div class="ni-floating"><label for="newLocationType">Location type</label><select id="newLocationType"><option value="site">Site</option><option value="home">Home</option><option value="office">Office</option><option value="shop">Shop</option><option value="warehouse">Warehouse</option><option value="factory">Factory</option><option value="farm">Farm</option><option value="other">Other</option></select></div>
                    <div class="ni-floating full"><label for="newLocationAddress1">Address line 1</label><input id="newLocationAddress1" type="text" maxlength="255"></div>
                    <div class="ni-floating full"><label for="newLocationAddress2">Address line 2</label><input id="newLocationAddress2" type="text" maxlength="255"></div>
                    <div class="ni-floating"><label for="newLocationCity">City</label><input id="newLocationCity" type="text" maxlength="120"></div>
                    <div class="ni-floating"><label for="newLocationState">State</label><input id="newLocationState" type="text" maxlength="120"></div>
                    <div class="ni-floating"><label for="newLocationPostal">Postal code</label><input id="newLocationPostal" type="text" maxlength="40"></div>
                </div>
                <label class="ni-quick-check"><input id="newLocationPrimary" type="checkbox"><span>Use as primary / billing location</span></label>
            </div>
            <div class="modal-footer"><button type="button" class="ni-btn" data-bs-dismiss="modal">Cancel</button><button type="button" class="ni-btn primary" id="createLocationButton">Create Location</button></div>
        </div>
    </div>
</div>
<div class="modal fade ni-jobs-modal" id="customerJobsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title" id="customerJobsTitle">Select jobs to invoice</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="ni-jobs-table-wrap" id="customerJobsBody"></div>
            </div>
            <div class="modal-footer"><button type="button" class="ni-btn" data-bs-dismiss="modal">Cancel</button><button type="button" class="ni-btn primary" id="customerJobsContinue">Continue</button></div>
        </div>
    </div>
</div>
<div class="modal fade ni-catalog-modal" id="catalogItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Add Product / Service</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="ni-floating ni-modal-field">
                    <label for="newItemType">Item type</label>
                    <select id="newItemType">
                        <option value="service">Service</option>
                        <option value="product">Product</option>
                    </select>
                </div>
                <div class="ni-floating ni-modal-field">
                    <label for="newItemName">Name</label>
                    <input id="newItemName" type="text" maxlength="190">
                </div>
                <div class="ni-floating ni-modal-field">
                    <label for="newItemDescription">Description</label>
                    <textarea id="newItemDescription"></textarea>
                </div>
                <div class="ni-new-item-costs">
                    <div class="ni-floating"><label for="newItemUnitCost">Unit cost</label><input id="newItemUnitCost" type="number" min="0" step="0.01" value="0.00"></div>
                    <div class="ni-floating"><label for="newItemMarkup">Markup (%)</label><input id="newItemMarkup" type="number" min="0" step="0.01" value="0"></div>
                    <div class="ni-floating"><label for="newItemUnitPrice">Unit price</label><input id="newItemUnitPrice" type="number" min="0" step="0.01" value="0.00"></div>
                </div>
                <div class="ni-item-image-upload">
                    <input class="ni-item-image-input" id="newItemImage" type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
                    <button class="ni-item-image-drop" id="newItemImagePick" type="button">
                        <i class="bi bi-image"></i>
                        <strong>Add product / service image</strong>
                        <small>JPG, PNG or WEBP up to 4 MB</small>
                    </button>
                    <div class="ni-item-image-preview" id="newItemImagePreview" style="display:none">
                        <img id="newItemImagePreviewImg" src="" alt="Item image preview">
                        <div class="ni-item-image-preview-copy">
                            <span class="ni-item-image-preview-name" id="newItemImageName"></span>
                            <span class="ni-item-image-preview-size" id="newItemImageSize"></span>
                            <button class="ni-item-image-remove" id="newItemImageRemove" type="button">Remove image</button>
                        </div>
                    </div>
                </div>
                <label class="ni-tax-exempt"><input id="newItemTaxExempt" type="checkbox"> <span>Exempt from Tax</span></label>
            </div>
            <div class="modal-footer">
                <button type="button" class="ni-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="ni-btn primary" id="createCatalogItemButton">Create</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade ni-tax-modal" id="taxRateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Create Tax Rate</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="ni-tax-pair">
                    <div class="ni-floating"><label for="newTaxName">Name</label><input id="newTaxName" type="text" maxlength="120"></div>
                    <div class="ni-floating"><label for="newTaxRate">Tax rate (%)</label><input id="newTaxRate" type="number" min="0" max="100" step="0.0001" value="0"></div>
                </div>
                <div class="ni-floating"><label for="newTaxDescription">Internal tax description</label><input id="newTaxDescription" type="text" maxlength="190"></div>
                <label class="ni-tax-default"><input id="newTaxDefault" type="checkbox"><span>Make default for new quotes and invoices</span></label>
                <p class="ni-tax-help">Tax rates can be edited in <a href="settings.php">Settings &gt; Company Settings</a>.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="ni-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="ni-btn primary" id="createTaxRateButton">Create Tax Rate</button>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/toast.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){
'use strict';
var csrfToken=<?= json_encode($invoiceFormCsrfToken) ?>;
var csrfLegacyToken=<?= json_encode($invoiceLegacyCsrfToken) ?>;
var invoiceApiUrl=<?= json_encode($invoiceFormApiUrl) ?>;
var preJobId=<?= (int)$preJobId ?>,preClientId=<?= (int)$preClientId ?>,preLocationId=<?= (int)$preLocationId ?>;
var similarInvoiceId=<?= (int)$similarInvoiceId ?>;
var similarInvoiceData=<?= json_encode($similarInvoiceData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var similarInvoiceLoadError=<?= json_encode($similarInvoiceLoadError, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var meta={clients:[],locations:[],branches:[],jobs:[],services:[],products:[],tax_rates:[],team_members:[],default_tax_rate_id:0,currency:{},current_user:{}};
var cart=[],source='direct',currentJob=null,customFields=[],pendingFiles={image:[],attachment:[]},noteFiles=[],noteMentionIds=[],mentionMatches=[],mentionActiveIndex=0;
var createCustomerModal=null,createLocationModal=null,customerJobsModal=null,customerJobs=[],selectedCustomerJobId=0,jobsPromptedClientId=0;
var clientViewOptions={quantities:true,unit_prices:true,line_item_totals:true,account_balance:true,late_stamp:true};
var invoiceDiscount={active:false,type:'fixed',value:0};
var selectedTaxRateId=0;
var catalogTargetIndex=null;
var catalogModal=null,catalogPendingName='',catalogPriceTouched=false,catalogImageObjectUrl=null,catalogImageFile=null;
var taxRateModal=null;
function E(id){return document.getElementById(id)}
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function title(v){return String(v||'').replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()})}
function toast(type,msg,duration){if(typeof window.fieldplxToast==='function'){window.fieldplxToast(type,msg,duration);return;}console.log('[FieldPlx toast]',type,msg);}
function parse(r){return r.text().then(function(raw){var d=null,msg='';try{d=raw?JSON.parse(raw):{}}catch(e){msg=String(raw||'').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();var pe=new Error(msg||('Invalid server response (HTTP '+r.status+').'));pe.status=r.status;pe.raw=raw;throw pe}if(!r.ok||!d||d.success!==true){msg=d&&d.message?String(d.message):'';if(!msg)msg='Request failed'+(r.status&&r.status!==200?' (HTTP '+r.status+')':'')+'.';var er=new Error(msg);er.status=r.status;er.payload=d||{};throw er}return d})}
function requestOnce(fd,token){if(typeof fd.set==='function')fd.set('csrf_token',token||'');else fd.append('csrf_token',token||'');return fetch(invoiceApiUrl,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parse)}
function request(fd){return requestOnce(fd,csrfToken).catch(function(err){var retry=csrfLegacyToken&&csrfLegacyToken!==csrfToken&&(Number(err&&err.status||0)===419||/session expired|csrf|request failed/i.test(String(err&&err.message||'')));if(retry)return requestOnce(fd,csrfLegacyToken);throw err})}
function money(v){var c=meta.currency||{},p=parseInt(c.decimal_places,10);if(isNaN(p))p=2;var n=Number(v||0).toFixed(p),s=c.symbol||'';return c.symbol_position==='after'?n+(s?' '+s:''):(s||'')+n}
function negativeMoney(v){v=Math.abs(Number(v||0));return v>0?'-'+money(v):money(0)}
function round2(v){return Math.round((Number(v||0)+Number.EPSILON)*100)/100}
function today(){var d=new Date(),o=d.getTimezoneOffset();d=new Date(d.getTime()-o*60000);return d.toISOString().slice(0,10)}
function initSelect(id,placeholder,opts){opts=opts||{};var cfg={width:'100%',placeholder:placeholder||'',allowClear:true};Object.keys(opts).forEach(function(k){cfg[k]=opts[k]});$('#'+id).select2(cfg)}
function resetSelect(id,html,placeholder,opts){var $n=$('#'+id);if($n.hasClass('select2-hidden-accessible'))$n.select2('destroy');E(id).innerHTML=html;initSelect(id,placeholder,opts)}
function setInvoiceNumberAuto(value){var input=E('invoiceNo');if(!input)return;E('invoiceNoMode').value='auto';input.classList.remove('is-manual');input.value=String(value||'');}
function invoiceBranchId(){if(source==='job'&&currentJob&&Number(currentJob.branch_id||0)>0)return Number(currentJob.branch_id);var c=currentClient();if(c&&Number(c.branch_id||0)>0)return Number(c.branch_id);return Number(E('branchId').value||meta.session_branch_id||0);}
function refreshInvoiceNumber(){if(E('invoiceNoMode').value==='manual')return Promise.resolve();var fd=new FormData();fd.append('action','invoice_number_preview');fd.append('branch_id',String(invoiceBranchId()||0));return request(fd).then(function(d){setInvoiceNumberAuto(d.invoice_no||'');return d}).catch(function(e){if(!E('invoiceNo').value)E('invoiceNo').placeholder='Enter invoice number';return null})}
function setSource(next){source=next==='job'?'job':'direct';E('sourceMode').value=source}
function catalogOptionHtml(){var html='<option value=""></option>';meta.services.forEach(function(s){html+='<option value="service:'+Number(s.id)+'" data-kind="service" data-price="'+esc(s.unit_price)+'" data-description="'+esc(s.description||'')+'">'+esc(s.name)+'</option>'});meta.products.forEach(function(p){html+='<option value="product:'+Number(p.id)+'" data-kind="product" data-price="'+esc(p.selling_price)+'" data-description="'+esc(p.description||'')+'">'+esc(p.name)+'</option>'});return html}
function catalogResult(item){if(!item.id)return item.text;var id=String(item.id||'');if(id.indexOf('new:')===0){var n=document.createElement('div');n.className='ni-create-option';var ic=document.createElement('i');ic.className='bi bi-plus-lg';var tx=document.createElement('span');tx.textContent='Create new item';n.appendChild(ic);n.appendChild(tx);return $(n)}var el=item.element,kind=el?String(el.getAttribute('data-kind')||''):'',price=el?el.getAttribute('data-price'):'',desc=el?el.getAttribute('data-description'):'';var row=document.createElement('div');row.className='ni-catalog-result';var copy=document.createElement('div');copy.className='ni-catalog-copy';var name=document.createElement('div');name.className='ni-catalog-name';var text=document.createElement('span');text.textContent=item.text||'';name.appendChild(text);if(kind==='service'||kind==='product'){var badge=document.createElement('span');badge.className='ni-type-badge '+kind;badge.textContent=kind==='service'?'Service':'Product';name.appendChild(badge)}copy.appendChild(name);if(desc){var d=document.createElement('div');d.className='ni-catalog-desc';d.textContent=desc;copy.appendChild(d)}row.appendChild(copy);if(price!==null&&price!==''){var p=document.createElement('div');p.className='ni-catalog-price';p.textContent=money(price);row.appendChild(p)}return $(row)}
function catalogSelection(item){if(!item.id)return 'Name';return item.text||'Name'}
function createOptionNode(label){var n=document.createElement('div');n.className='ni-create-option';var ic=document.createElement('i');ic.className='bi bi-plus-lg';var tx=document.createElement('span');tx.textContent=label;n.appendChild(ic);n.appendChild(tx);return $(n)}
function customerOptionsHtml(){var html='<option value=""></option>';meta.clients.forEach(function(c){html+='<option value="'+Number(c.id)+'">'+esc((c.name||c.display_name||'Customer')+(c.company_name?' - '+c.company_name:'')+(c.phone?' - '+c.phone:''))+'</option>'});return html}
function customerResult(item){if(!item.id)return item.text;var id=String(item.id||'');if(id.indexOf('newclient:')===0)return createOptionNode('Create new customer');return item.text}
function initCustomerSelect(selected){resetSelect('clientId',customerOptionsHtml(),'Select a customer',{tags:true,minimumResultsForSearch:0,createTag:function(params){var term=$.trim(params.term||'');if(!term)return null;var exact=meta.clients.some(function(c){return String(c.name||c.display_name||'').toLowerCase()===term.toLowerCase()});return exact?null:{id:'newclient:'+term,text:term,newTag:true}},templateResult:customerResult});if(Number(selected)>0)$('#clientId').val(String(selected)).trigger('change.select2')}
function locationResult(item){if(!item.id)return item.text;var id=String(item.id||'');if(id.indexOf('newloc:')===0)return createOptionNode('Create new location');return item.text}
function locationsForClient(clientId){return meta.locations.filter(function(x){return Number(x.client_id)===Number(clientId||0)})}
function defaultLocationId(clientId){var list=locationsForClient(clientId),p=list.find(function(x){return Number(x.is_primary||0)===1});return p?Number(p.id):(list.length?Number(list[0].id):0)}
function filterLocations(clientId,selected){var html='<option value=""></option>',list=locationsForClient(clientId);list.forEach(function(x){var a=[x.address_line1,x.city,x.state].filter(Boolean).join(', ');html+='<option value="'+Number(x.id)+'">'+esc(x.name+(a?' - '+a:''))+'</option>'});resetSelect('locationId',html,'Select or create location',{tags:Number(clientId)>0,minimumResultsForSearch:0,createTag:function(params){var term=$.trim(params.term||'');if(!term||Number(clientId)<=0)return null;var exact=list.some(function(x){return String(x.name||'').toLowerCase()===term.toLowerCase()});return exact?null:{id:'newloc:'+term,text:term,newTag:true}},templateResult:locationResult});if(Number(selected)>0)$('#locationId').val(String(selected)).trigger('change.select2')}
function currentClient(){var id=Number(E('clientId').value||0);return meta.clients.find(function(c){return Number(c.id)===id})||null}
function currentLocation(){var id=Number(E('locationId').value||0);return meta.locations.find(function(x){return Number(x.id)===id})||null}
function primaryLocation(clientId){var list=locationsForClient(clientId);return list.find(function(x){return Number(x.is_primary||0)===1})||(list.length?list[0]:null)}
function formatAddress(x){if(!x)return '';return [x.address_line1,x.address_line2,x.city,x.state,x.postal_code].filter(function(v){return String(v||'').trim()!==''}).join(', ')}
function renderCustomerCard(){var c=currentClient(),card=E('selectedClientCard'),picker=E('clientPickerWrap'),locWrap=E('locationPickerWrap');if(!c){card.classList.remove('show');picker.style.display='block';locWrap.classList.remove('show');return}picker.style.display='none';card.classList.add('show');E('clientCardName').textContent=c.name||c.display_name||'Customer';var billing=primaryLocation(c.id),property=currentLocation(),billingText=formatAddress(billing),propertyText=formatAddress(property);E('clientBillingAddress').textContent=billingText||'No billing address saved';E('clientPropertyAddress').textContent=property?(billing&&Number(property.id)===Number(billing.id)?'(Same as billing address)':(propertyText||property.name||'Selected location')):'No service location selected';var ph=E('clientPhoneLink'),em=E('clientEmailLink');if(c.phone){ph.style.display='inline';ph.textContent=c.phone;ph.href='tel:'+String(c.phone).replace(/[^+0-9]/g,'')}else ph.style.display='none';if(c.email){em.style.display='inline';em.textContent=c.email;em.href='mailto:'+c.email}else em.style.display='none';var hasLoc=locationsForClient(c.id).length>0;locWrap.classList.toggle('show',!property||!hasLoc)}
function initSalesperson(){var cu=meta.current_user||{},id=Number(cu.id||0),name=String(cu.name||'Current user');E('salespersonId').value=String(id||'');if(Number(cu.can_edit_salesperson||0)===1){var html='<option value=""></option>';meta.team_members.forEach(function(m){var nm=String(m.name||[m.first_name,m.last_name].filter(Boolean).join(' ')||'Team member');html+='<option value="'+Number(m.id)+'">'+esc(nm)+'</option>'});E('salespersonName').style.display='none';E('salespersonSelect').style.display='block';resetSelect('salespersonSelect',html,'Salesperson');$('#salespersonSelect').val(String(id)).trigger('change.select2')}else{if($('#salespersonSelect').hasClass('select2-hidden-accessible'))$('#salespersonSelect').select2('destroy');E('salespersonSelect').style.display='none';E('salespersonName').style.display='inline-flex';E('salespersonName').textContent=name}}
function setMeta(m){meta=m||meta;meta.clients=Array.isArray(meta.clients)?meta.clients:[];meta.locations=Array.isArray(meta.locations)?meta.locations:[];meta.jobs=Array.isArray(meta.jobs)?meta.jobs:[];meta.tax_rates=Array.isArray(meta.tax_rates)?meta.tax_rates:[];meta.team_members=Array.isArray(meta.team_members)?meta.team_members:[];initCustomerSelect(0);filterLocations(0,0);initSalesperson();var symbol=(meta.currency&&meta.currency.symbol)?meta.currency.symbol:'$';E('discountFixedOption').textContent=symbol;selectedTaxRateId=Number(meta.default_tax_rate_id||0);E('invoiceTaxRateId').value=selectedTaxRateId>0?String(selectedTaxRateId):'';renderTaxMenu();setInvoiceNumberAuto(meta.invoice_no_preview||'')}
function applyClient(selectedLocation,opts){opts=opts||{};var c=currentClient();if(!c){renderCustomerCard();return Promise.resolve()}var sel=Number(selectedLocation||0);if(sel<=0)sel=defaultLocationId(c.id);filterLocations(c.id,sel);if(c.branch_id)E('branchId').value=String(c.branch_id);renderCustomerCard();refreshInvoiceNumber();if(!opts.skipJobs&&similarInvoiceId<=0&&Number(c.id)!==Number(jobsPromptedClientId)){jobsPromptedClientId=Number(c.id);return loadCustomerJobs(c.id,true)}return Promise.resolve()}
function updateCustomerView(){renderCustomerCard()}
function openCreateCustomerModal(name){E('newCustomerName').value=String(name||'').trim();E('newCustomerCompany').value='';E('newCustomerPhone').value='';E('newCustomerEmail').value='';if(!createCustomerModal)createCustomerModal=bootstrap.Modal.getOrCreateInstance(E('createCustomerModal'));createCustomerModal.show();setTimeout(function(){E('newCustomerName').focus();E('newCustomerName').select()},160)}
function createCustomer(){var name=String(E('newCustomerName').value||'').trim();if(!name){toast('warning','Enter a customer name.');E('newCustomerName').focus();return}var fd=new FormData();fd.append('action','create_customer');fd.append('display_name',name);fd.append('company_name',E('newCustomerCompany').value||'');fd.append('phone',E('newCustomerPhone').value||'');fd.append('email',E('newCustomerEmail').value||'');fd.append('branch_id',String(Number(E('branchId').value||meta.session_branch_id||0)));var btn=E('createCustomerButton');btn.disabled=true;btn.textContent='Creating...';request(fd).then(function(d){var c=d.client||{};if(Number(c.id||0)<=0)throw new Error('Customer was not returned by the server.');c.name=c.name||c.display_name||name;var pos=meta.clients.findIndex(function(x){return Number(x.id)===Number(c.id)});if(pos>=0)meta.clients[pos]=c;else meta.clients.push(c);resetJob(false);setSource('direct');initCustomerSelect(c.id);jobsPromptedClientId=0;return applyClient(0,{skipJobs:false}).then(function(){if(createCustomerModal)createCustomerModal.hide();toast('success',d.message||'Customer created successfully.')})}).catch(function(err){toast('error',err.message)}).finally(function(){btn.disabled=false;btn.textContent='Create Customer'})}
function openCreateLocationModal(name){var c=currentClient();if(!c){toast('warning','Select a customer first.');return}E('newLocationName').value=String(name||'').trim();E('newLocationType').value='site';E('newLocationAddress1').value='';E('newLocationAddress2').value='';E('newLocationCity').value='';E('newLocationState').value='';E('newLocationPostal').value='';E('newLocationPrimary').checked=locationsForClient(c.id).length===0;if(!createLocationModal)createLocationModal=bootstrap.Modal.getOrCreateInstance(E('createLocationModal'));createLocationModal.show();setTimeout(function(){E('newLocationName').focus();E('newLocationName').select()},160)}
function createLocation(){var c=currentClient();if(!c){toast('warning','Select a customer first.');return}var name=String(E('newLocationName').value||'').trim(),a1=String(E('newLocationAddress1').value||'').trim();if(!name){toast('warning','Enter a location name.');return}if(!a1){toast('warning','Enter address line 1.');return}var fd=new FormData();fd.append('action','create_location');fd.append('client_id',String(c.id));fd.append('name',name);fd.append('location_type',E('newLocationType').value||'site');fd.append('address_line1',a1);fd.append('address_line2',E('newLocationAddress2').value||'');fd.append('city',E('newLocationCity').value||'');fd.append('state',E('newLocationState').value||'');fd.append('postal_code',E('newLocationPostal').value||'');fd.append('is_primary',E('newLocationPrimary').checked?'1':'0');var btn=E('createLocationButton');btn.disabled=true;btn.textContent='Creating...';request(fd).then(function(d){var loc=d.location||{};if(Number(loc.id||0)<=0)throw new Error('Location was not returned by the server.');if(Number(loc.is_primary||0)===1)meta.locations.forEach(function(x){if(Number(x.client_id)===Number(c.id))x.is_primary=0});var pos=meta.locations.findIndex(function(x){return Number(x.id)===Number(loc.id)});if(pos>=0)meta.locations[pos]=loc;else meta.locations.push(loc);filterLocations(c.id,loc.id);renderCustomerCard();E('locationPickerWrap').classList.remove('show');if(createLocationModal)createLocationModal.hide();toast('success',d.message||'Location created successfully.')}).catch(function(err){toast('error',err.message)}).finally(function(){btn.disabled=false;btn.textContent='Create Location'})}
function renderCustomerJobs(list){customerJobs=Array.isArray(list)?list:[];selectedCustomerJobId=0;var body=E('customerJobsBody');if(!customerJobs.length){body.innerHTML='<div class="ni-jobs-empty">No uninvoiced jobs are available for this customer.</div>';E('customerJobsContinue').disabled=true;return}E('customerJobsContinue').disabled=false;var h='<table class="ni-jobs-table"><thead><tr><th></th><th>Status</th><th>Title</th><th>Address</th><th>Uninvoiced</th><th>Subtotal</th></tr></thead><tbody>';customerJobs.forEach(function(j){var visit=j.next_scheduled_start?('Visit: '+String(j.next_scheduled_start).slice(0,10)):'';h+='<tr><td><input class="ni-job-check" type="checkbox" data-job-select="'+Number(j.id)+'"></td><td><span class="ni-job-status">'+esc(j.status_label||title(j.status||'Job'))+'</span></td><td><span class="ni-job-title">'+esc((j.job_no?j.job_no+' - ':'')+(j.title||'Job'))+'</span>'+(visit?'<span class="ni-job-sub">'+esc(visit)+'</span>':'')+'</td><td>'+esc(j.address||j.location_name||'-')+'</td><td class="ni-job-money">'+esc(money(j.uninvoiced||0))+'</td><td class="ni-job-money">'+esc(money(j.subtotal||0))+'</td></tr>'});h+='</tbody></table>';body.innerHTML=h}
function loadCustomerJobs(clientId,autoShow){clientId=Number(clientId||0);if(clientId<=0)return Promise.resolve([]);var fd=new FormData();fd.append('action','customer_jobs');fd.append('client_id',String(clientId));return request(fd).then(function(d){var jobs=Array.isArray(d.jobs)?d.jobs:[];renderCustomerJobs(jobs);var c=currentClient();E('customerJobsTitle').textContent='Select jobs to invoice for '+String(c?(c.name||c.display_name):'customer');if(jobs.length&&autoShow!==false){if(!customerJobsModal)customerJobsModal=bootstrap.Modal.getOrCreateInstance(E('customerJobsModal'));customerJobsModal.show()}return jobs}).catch(function(err){toast('error',err.message);return[]})}
function continueCustomerJob(){var j=customerJobs.find(function(x){return Number(x.id)===Number(selectedCustomerJobId)});if(!j){toast('warning','Select a job to invoice.');return}var btn=E('customerJobsContinue');btn.disabled=true;btn.textContent='Loading...';loadJob(j.id,j.preferred_visit_id||0).then(function(){if(customerJobsModal)customerJobsModal.hide();toast('success','Job '+String(j.job_no||'')+' added to this invoice.')}).finally(function(){btn.disabled=false;btn.textContent='Continue'})}
function renderCustomFields(){var box=E('customFields');box.innerHTML=customFields.map(function(x,i){return '<div class="ni-custom-field"><input data-custom-f="label" data-custom-i="'+i+'" value="'+esc(x.label||'')+'" maxlength="190" placeholder="Field name"><input data-custom-f="value" data-custom-i="'+i+'" value="'+esc(x.value||'')+'" maxlength="1000" placeholder="Value"><button type="button" data-remove-custom="'+i+'" title="Remove"><i class="bi bi-x-lg"></i></button></div>'}).join('');E('customFieldsJson').value=JSON.stringify(customFields)}
function fileSize(n){n=Number(n||0);if(n>=1024*1024)return (n/(1024*1024)).toFixed(1)+' MB';if(n>=1024)return Math.round(n/1024)+' KB';return n+' B'}
function renderPendingFiles(){function draw(cat,listId,counterId){var a=pendingFiles[cat]||[],box=E(listId);box.innerHTML=a.map(function(f,i){return '<div class="ni-file-row"><i class="bi bi-paperclip"></i><span>'+esc(f.name)+' <small>('+esc(fileSize(f.size))+')</small></span><button type="button" class="ni-file-remove" data-remove-file="'+cat+'" data-file-index="'+i+'" title="Remove"><i class="bi bi-x-lg"></i></button></div>'}).join('');E(counterId).textContent=a.length+' of 10 uploaded'}draw('image','imageList','imageCounter');draw('attachment','attachmentList','attachmentCounter')}
function noteMentionHandle(m){var raw=String((m&&m.first_name)||'').trim();if(!raw)raw=String((m&&m.name)||'User').trim();var last=String((m&&m.last_name)||'').trim();if(last)raw+='_'+last;raw=raw.replace(/[^A-Za-z0-9_]/g,'_').replace(/_+/g,'_').replace(/^_+|_+$/g,'');return raw||('user'+Number(m&&m.id||0))}
function noteMentionContext(){var ta=E('invoiceNotes'),pos=ta.selectionStart==null?ta.value.length:ta.selectionStart,before=ta.value.slice(0,pos),m=before.match(/(^|[\s\n])@([A-Za-z0-9_.-]*)$/);if(!m)return null;return{start:pos-m[2].length-1,end:pos,query:String(m[2]||'').toLowerCase()}}
function hideMentionMenu(){var menu=E('noteMentionMenu');if(menu){menu.classList.remove('show');menu.innerHTML=''}mentionMatches=[];mentionActiveIndex=0}
function renderMentionMenu(){var ctx=noteMentionContext(),menu=E('noteMentionMenu');if(!ctx||!menu){hideMentionMenu();return}var q=ctx.query;mentionMatches=(meta.team_members||[]).filter(function(m){var hay=[m.first_name,m.last_name,m.name,m.email,m.job_title,m.role_name,noteMentionHandle(m)].join(' ').toLowerCase();return !q||hay.indexOf(q)>=0}).slice(0,8);if(!mentionMatches.length){hideMentionMenu();return}mentionActiveIndex=Math.min(mentionActiveIndex,mentionMatches.length-1);menu.innerHTML=mentionMatches.map(function(m,i){var full=String(m.name||([m.first_name,m.last_name].filter(Boolean).join(' '))||'Team member'),role=String(m.job_title||m.role_name||'Team member'),initial=full.trim().charAt(0).toUpperCase()||'U';return '<button type="button" class="ni-mention-option '+(i===mentionActiveIndex?'active':'')+'" data-note-mention-id="'+Number(m.id)+'"><span class="ni-mention-avatar">'+esc(initial)+'</span><span class="ni-mention-copy"><span class="ni-mention-name">'+esc(full)+'</span><span class="ni-mention-role">'+esc(role)+'</span></span></button>'}).join('');menu.classList.add('show')}
function insertNoteMention(id){var m=(meta.team_members||[]).find(function(x){return Number(x.id)===Number(id)}),ctx=noteMentionContext(),ta=E('invoiceNotes');if(!m||!ctx||!ta)return;var token='@'+noteMentionHandle(m),before=ta.value.slice(0,ctx.start),after=ta.value.slice(ctx.end);ta.value=before+token+' '+after;var caret=before.length+token.length+1;ta.focus();ta.setSelectionRange(caret,caret);if(noteMentionIds.indexOf(Number(m.id))<0)noteMentionIds.push(Number(m.id));syncNoteMentions();hideMentionMenu()}
function syncNoteMentions(){var text=String(E('invoiceNotes').value||'').toLowerCase();noteMentionIds=noteMentionIds.filter(function(id){var m=(meta.team_members||[]).find(function(x){return Number(x.id)===Number(id)});return m&&text.indexOf(('@'+noteMentionHandle(m)).toLowerCase())>=0});E('noteMentionsJson').value=JSON.stringify(noteMentionIds)}
function renderNoteFiles(){var box=E('noteFileList');if(!box)return;box.innerHTML=noteFiles.map(function(f,i){var ext=(String(f.name||'').split('.').pop()||'').toLowerCase(),icon=['jpg','jpeg','png','webp','avif','heic'].indexOf(ext)>=0?'bi-image':'bi-paperclip';return '<div class="ni-note-file"><i class="bi '+icon+'"></i><span>'+esc(f.name)+' <small>('+esc(fileSize(f.size))+')</small></span><button type="button" data-remove-note-file="'+i+'" title="Remove"><i class="bi bi-x-lg"></i></button></div>'}).join('')}
function queueNoteFiles(list){var files=Array.prototype.slice.call(list||[]),allowed=['avif','jpg','jpeg','png','webp','heic','pdf','doc','docx'];files.forEach(function(f){if(noteFiles.length>=10){toast('warning','Maximum 10 note files/photos allowed.');return}var ext=(String(f.name||'').split('.').pop()||'').toLowerCase();if(allowed.indexOf(ext)<0){toast('warning',f.name+' has an unsupported file type.');return}if(Number(f.size||0)<=0||Number(f.size)>25*1024*1024){toast('warning',f.name+' exceeds the 25MB note attachment limit.');return}noteFiles.push(f)});renderNoteFiles()}
function appendNoteFiles(fd){noteFiles.forEach(function(f){fd.append('note_attachments[]',f,f.name)})}
function queueFiles(input,cat){var files=Array.prototype.slice.call(input.files||[]),max=cat==='image'?25*1024*1024:50*1024*1024,allowedImage=['avif','jpg','jpeg','png','webp','heic'],allowedAttachment=['avif','jpg','jpeg','png','webp','heic','pdf','doc','docx'],allowed=cat==='image'?allowedImage:allowedAttachment,current=pendingFiles[cat].length;files.forEach(function(f){if(current>=10){toast('warning','Maximum 10 '+(cat==='image'?'images':'attachments')+' allowed.');return}var ext=(f.name.split('.').pop()||'').toLowerCase();if(allowed.indexOf(ext)<0){toast('warning',f.name+' has an unsupported file type.');return}if(Number(f.size||0)<=0||Number(f.size)>max){toast('warning',f.name+' exceeds the '+(cat==='image'?'25':'50')+'MB limit.');return}pendingFiles[cat].push(f);current++});input.value='';renderPendingFiles()}
function appendPendingFiles(fd){pendingFiles.image.forEach(function(f){fd.append('invoice_images[]',f,f.name)});pendingFiles.attachment.forEach(function(f){fd.append('invoice_attachments[]',f,f.name)})}
function resetJob(clearItems){currentJob=null;setSource('direct');E('jobId').value='';E('visitId').value='';E('linkedJobBar').classList.remove('show');E('linkedJobText').textContent='';if(clearItems){cart=[];renderItems()}updateCustomerView()}
function renderJob(d){currentJob=d.job||null;if(!currentJob){resetJob(false);return}setSource('job');E('jobId').value=String(Number(currentJob.id||0));E('visitId').value=String(Number(d.selected_visit_id||0)||'');E('branchId').value=currentJob.branch_id||'';var clientId=Number(currentJob.client_id||0);if(clientId>0&&Number(E('clientId').value||0)!==clientId){initCustomerSelect(clientId)}var locId=Number(currentJob.location_id||0);filterLocations(clientId,locId);renderCustomerCard();E('locationPickerWrap').classList.remove('show');E('linkedJobText').innerHTML='<strong>'+esc(currentJob.job_no||'Job')+'</strong>'+(currentJob.title?' - '+esc(currentJob.title):'');E('linkedJobBar').classList.add('show');cart=(d.items||[]).map(normalize);renderItems();refreshInvoiceNumber()}
function loadJob(id,visitId){id=Number(id||0);if(id<=0){resetJob(false);return Promise.resolve()}var fd=new FormData();fd.append('action','job_context');fd.append('job_id',id);if(Number(visitId)>0)fd.append('visit_id',visitId);return request(fd).then(function(d){renderJob(d);return d}).catch(function(e){resetJob(false);toast('error',e.message);throw e})}
function normalize(x){var src=String(x&&x.item_source||((x&&x.product_id)?'product':((x&&x.product_service_id)?'service':'manual')));if(src==='product_service')src='service';return{product_service_id:Number(x&&x.product_service_id||0)||null,product_id:Number(x&&x.product_id||0)||null,item_source:src,new_product_name:String(x&&x.new_product_name||''),item_name:String(x&&x.item_name||''),description:String(x&&x.description||''),quantity:Math.max(.001,Number(x&&x.quantity||1)),unit_cost:Math.max(0,Number(x&&x.unit_cost||0)),unit_price:Math.max(0,Number(x&&x.unit_price||0)),discount_amount:Math.max(0,Number(x&&x.discount_amount||0)),tax_percent:Math.max(0,Number(x&&x.tax_percent||0)),service_date:String(x&&x.service_date||'')}}
function selectedTaxRate(){var id=Number(selectedTaxRateId||0);return meta.tax_rates.find(function(r){return Number(r.id)===id})||null}
function effectiveTaxPercent(x){var own=Math.max(0,Number(x&&x.tax_percent||0));if(own>0)return own;var r=selectedTaxRate();return r?Math.max(0,Number(r.rate_percent||0)):0}
function invoiceDiscountAmount(base){base=Math.max(0,round2(base));if(!invoiceDiscount.active||base<=0)return 0;var value=Math.max(0,Number(invoiceDiscount.value||0));if(invoiceDiscount.type==='percentage')return round2(base*Math.min(100,value)/100);return Math.min(base,round2(value))}
function calculateAll(){var lines=[],subtotal=0,lineDiscount=0,discountBase=0;cart.forEach(function(x){var base=round2(Number(x.quantity||0)*Number(x.unit_price||0)),disc=Math.min(base,Math.max(0,round2(x.discount_amount||0))),net=Math.max(0,round2(base-disc));subtotal+=base;lineDiscount+=disc;discountBase+=net;lines.push({base:base,original_discount:disc,net_before_invoice_discount:net,tax_percent:effectiveTaxPercent(x),invoice_discount:0,discount:disc,tax:0,total:0})});subtotal=round2(subtotal);lineDiscount=round2(lineDiscount);discountBase=round2(discountBase);var extraDiscount=invoiceDiscountAmount(discountBase),remaining=extraDiscount,remainingBase=discountBase;lines.forEach(function(line,idx){var share=0;if(extraDiscount>0&&line.net_before_invoice_discount>0&&remainingBase>0){if(idx===lines.length-1||Math.abs(remainingBase-line.net_before_invoice_discount)<0.005)share=remaining;else share=Math.min(line.net_before_invoice_discount,round2(extraDiscount*(line.net_before_invoice_discount/discountBase)));share=Math.max(0,Math.min(line.net_before_invoice_discount,share));remaining=round2(remaining-share);remainingBase=round2(remainingBase-line.net_before_invoice_discount)}line.invoice_discount=share;line.discount=round2(line.original_discount+share);var taxable=Math.max(0,round2(line.base-line.discount));line.tax=round2(taxable*line.tax_percent/100);line.total=round2(taxable+line.tax)});var discount=round2(lineDiscount+extraDiscount),tax=round2(lines.reduce(function(s,l){return s+l.tax},0)),total=round2(subtotal-discount+tax);return{subtotal:subtotal,line_discount:lineDiscount,invoice_discount:extraDiscount,discount:discount,tax:tax,total:total,lines:lines}}
function calc(x,index){var all=calculateAll();return all.lines[Number(index)||0]||{base:0,discount:0,tax:0,total:0,tax_percent:0}}
function totals(){var a=calculateAll();return{subtotal:a.subtotal,discount:a.discount,tax:a.tax,total:a.total,line_discount:a.line_discount,invoice_discount:a.invoice_discount}}
function lineCatalogOptions(x,i){var html='<option value=""></option>';meta.services.forEach(function(s){html+='<option value="service:'+Number(s.id)+'" data-kind="service" data-price="'+esc(s.unit_price)+'" data-description="'+esc(s.description||'')+'">'+esc(s.name)+'</option>'});meta.products.forEach(function(p){html+='<option value="product:'+Number(p.id)+'" data-kind="product" data-price="'+esc(p.selling_price)+'" data-description="'+esc(p.description||'')+'">'+esc(p.name)+'</option>'});if(x&&!x.product_service_id&&!x.product_id&&String(x.item_name||'').trim()!=='')html+='<option value="manual:'+i+'" data-kind="manual">'+esc(x.item_name)+'</option>';return html}
function lineCatalogValue(x,i){if(x&&Number(x.product_service_id||0)>0)return 'service:'+Number(x.product_service_id);if(x&&Number(x.product_id||0)>0)return 'product:'+Number(x.product_id);if(x&&String(x.item_name||'').trim()!=='')return 'manual:'+i;return ''}
function initLineCatalogs(){document.querySelectorAll('.ni-line-catalog-select').forEach(function(el){var i=Number(el.getAttribute('data-line-catalog')),x=cart[i]||null,$el=$(el);$el.select2({width:'100%',placeholder:'Name',allowClear:true,tags:true,minimumResultsForSearch:0,createTag:function(params){var term=$.trim(params.term||'');if(!term)return null;var exact=false;meta.services.some(function(v){if(String(v.name||'').toLowerCase()===term.toLowerCase()){exact=true;return true}});if(!exact)meta.products.some(function(v){if(String(v.name||'').toLowerCase()===term.toLowerCase()){exact=true;return true}});if(exact)return null;return{id:'new:'+term,text:term,newTag:true}},templateResult:catalogResult,templateSelection:catalogSelection});var current=lineCatalogValue(x,i);if(current)$el.val(current).trigger('change.select2');$el.on('select2:select',function(e){var raw=e.params&&e.params.data?e.params.data.id:this.value;applyCatalogToLine(i,raw)});$el.on('select2:clear',function(){if(cart[i]){cart[i]=normalize({item_source:'manual',quantity:cart[i].quantity||1,service_date:cart[i].service_date||''});renderItems()}})})}
function renderItems(){var w=E('itemRows');if(!cart.length){w.innerHTML='';updateTotals();return}var h='',all=calculateAll();cart.forEach(function(x,i){var c=all.lines[i]||{total:0};h+='<div class="ni-line" data-index="'+i+'"><div class="ni-line-main"><div class="ni-line-catalog"><select class="ni-line-catalog-select" data-line-catalog="'+i+'">'+lineCatalogOptions(x,i)+'</select></div><div class="ni-labeled"><span>Quantity</span><input data-f="quantity" type="number" min="0.001" step="0.001" value="'+esc(x.quantity)+'"></div><div class="ni-labeled"><span>Unit price</span><input data-f="unit_price" type="number" min="0" step="0.01" value="'+esc(x.unit_price)+'"></div><div class="ni-line-total">'+esc(money(c.total))+'</div><button type="button" class="ni-remove" data-remove="'+i+'" title="Remove"><i class="bi bi-trash"></i></button></div><div class="ni-line-extra"><textarea data-f="description" placeholder="Description">'+esc(x.description)+'</textarea><div class="ni-labeled"><span>Unit cost</span><input data-f="unit_cost" type="number" min="0" step="0.01" value="'+esc(x.unit_cost)+'"></div></div><div class="ni-line-service-date"><button type="button" data-line-date-button="'+i+'" style="'+(x.service_date?'display:none':'')+'">Add Service Date</button><input type="date" data-f="service_date" value="'+esc(x.service_date)+'" style="'+(x.service_date?'display:block':'display:none')+'"></div></div>'});w.innerHTML=h;initLineCatalogs();updateTotals()}
function renderTaxMenu(){var menu=E('taxMenu');if(!menu)return;var html='';if(!meta.tax_rates.length)html='<div class="ni-tax-menu-empty">No options</div>';meta.tax_rates.forEach(function(r){html+='<button type="button" data-tax-rate-id="'+Number(r.id)+'"><strong>'+esc(r.tax_name||('Tax '+r.rate_percent+'%'))+'</strong><small>'+esc(Number(r.rate_percent||0).toFixed(4).replace(/0+$/,'').replace(/\.$/,'')+'%')+'</small></button>'});html+='<button type="button" class="ni-create-tax" id="createTaxRateLink">Create new tax rate</button>';menu.innerHTML=html}
function setSelectedTaxRate(id){selectedTaxRateId=Number(id||0);E('invoiceTaxRateId').value=selectedTaxRateId>0?String(selectedTaxRateId):'';E('taxMenu').classList.remove('show');updateTotals()}
function updateTotals(){var t=totals(),rate=selectedTaxRate();E('sumSubtotal').textContent=money(t.subtotal);E('sumDiscount').textContent=negativeMoney(t.discount);E('sumTax').textContent=money(t.tax);E('sumTotal').textContent=money(t.total);E('sumBalance').textContent=money(t.total);var discountControl=E('discountControl'),discountRemove=E('discountRemove');if(invoiceDiscount.active){E('discountLink').style.display='none';discountControl.style.display='flex';discountRemove.style.display='grid';E('sumDiscount').style.display='inline'}else{discountControl.style.display='none';discountRemove.style.display='none';E('discountLink').style.display=t.discount>0?'none':'inline';E('sumDiscount').style.display=t.discount>0?'inline':'none'}var hasCatalogTax=cart.some(function(x){return Number(x.tax_percent||0)>0});if(rate){E('taxSelectorText').textContent=(rate.tax_name||'Tax')+' ('+Number(rate.rate_percent||0).toFixed(4).replace(/0+$/,'').replace(/\.$/,'')+'%)';E('taxControl').style.display='block';E('taxRemove').style.display='grid';E('taxLink').style.display='none';E('sumTax').style.display='inline'}else if(hasCatalogTax||t.tax>0){E('taxControl').style.display='none';E('taxRemove').style.display='none';E('taxLink').style.display='none';E('sumTax').style.display='inline'}else{E('taxControl').style.display='none';E('taxRemove').style.display='none';E('taxLink').style.display='inline';E('sumTax').style.display='none'}E('invoiceDiscountType').value=invoiceDiscount.type;E('invoiceDiscountValue').value=invoiceDiscount.active?String(Math.max(0,Number(invoiceDiscount.value||0))):'0';E('invoiceTaxRateId').value=selectedTaxRateId>0?String(selectedTaxRateId):''}
function addBlankLine(){cart.push(normalize({item_source:'manual',quantity:1}));renderItems();var i=cart.length-1;setTimeout(function(){var el=document.querySelector('[data-line-catalog="'+i+'"]');if(el)try{$(el).select2('open')}catch(e){}},30)}
function applyCatalogToLine(index,raw){raw=String(raw||'');if(!cart[index])return;if(raw.indexOf('new:')===0){catalogTargetIndex=index;openCreateCatalogModal(raw.slice(4));return}var parts=raw.split(':'),kind=parts[0],id=Number(parts[1]||0),x=null,old=cart[index],qty=Math.max(.001,Number(old.quantity||1)),date=String(old.service_date||'');if(kind==='service')x=meta.services.find(function(s){return Number(s.id)===id});else if(kind==='product')x=meta.products.find(function(p){return Number(p.id)===id});else if(kind==='manual'){return}if(!x)return;if(kind==='service')cart[index]=normalize({product_service_id:x.id,item_source:'service',item_name:x.name,description:x.description,quantity:qty,unit_cost:x.unit_cost,unit_price:x.unit_price,tax_percent:x.tax_percent,service_date:date});else cart[index]=normalize({product_id:x.id,item_source:'product',item_name:x.name,description:x.description,quantity:qty,unit_cost:x.base_unit_price,unit_price:x.selling_price,tax_percent:x.tax_percent,service_date:date});renderItems()}
function openDatePicker(input){if(!input)return;var row=input.closest('.ni-line-service-date'),button=row?row.querySelector('[data-line-date-button]'):null;if(button)button.style.display='none';input.style.display='block';input.style.position='static';input.style.left='auto';input.style.opacity='1';input.style.pointerEvents='auto';input.style.width='170px';if(typeof input.showPicker==='function'){try{input.showPicker();return}catch(e){}}input.focus();input.click()}
function resetCatalogImage(){catalogImageFile=null;var input=E('newItemImage');if(input)input.value='';if(catalogImageObjectUrl){try{URL.revokeObjectURL(catalogImageObjectUrl)}catch(e){}catalogImageObjectUrl=null}E('newItemImagePreviewImg').src='';E('newItemImageName').textContent='';E('newItemImageSize').textContent='';E('newItemImagePreview').style.display='none';E('newItemImagePick').style.display='flex'}
function setCatalogImage(file){if(!file){resetCatalogImage();return false}var allowed=['image/jpeg','image/png','image/webp'];var name=String(file.name||''),ext=(name.split('.').pop()||'').toLowerCase();if(allowed.indexOf(String(file.type||'').toLowerCase())<0&&['jpg','jpeg','png','webp'].indexOf(ext)<0){toast('warning','Item image must be JPG, PNG or WEBP.');resetCatalogImage();return false}if(Number(file.size||0)<=0||Number(file.size)>4*1024*1024){toast('warning','Item image must be 4 MB or smaller.');resetCatalogImage();return false}if(catalogImageObjectUrl){try{URL.revokeObjectURL(catalogImageObjectUrl)}catch(e){}}catalogImageFile=file;catalogImageObjectUrl=URL.createObjectURL(file);E('newItemImagePreviewImg').src=catalogImageObjectUrl;E('newItemImageName').textContent=name;E('newItemImageSize').textContent=(Number(file.size)/(1024*1024)).toFixed(2)+' MB';E('newItemImagePick').style.display='none';E('newItemImagePreview').style.display='flex';return true}
function openCreateCatalogModal(name){catalogPendingName=String(name||'').trim();E('newItemType').value='service';E('newItemName').value=catalogPendingName;E('newItemDescription').value='';E('newItemUnitCost').value='0.00';E('newItemMarkup').value='0';E('newItemUnitPrice').value='0.00';E('newItemTaxExempt').checked=false;resetCatalogImage();catalogPriceTouched=false;if(!catalogModal)catalogModal=bootstrap.Modal.getOrCreateInstance(E('catalogItemModal'));catalogModal.show();setTimeout(function(){E('newItemName').focus();E('newItemName').select()},180)}
function recalcNewItemPrice(){if(catalogPriceTouched)return;var cost=Math.max(0,Number(E('newItemUnitCost').value||0)),markup=Math.max(0,Number(E('newItemMarkup').value||0));E('newItemUnitPrice').value=(Math.round(cost*(1+markup/100)*100)/100).toFixed(2)}
function createCatalogItem(){var type=String(E('newItemType').value||'service'),name=String(E('newItemName').value||'').trim();if(!name){toast('warning','Enter a product / service name.');E('newItemName').focus();return}var fd=new FormData(),rate=selectedTaxRate();fd.append('action','create_catalog_item');fd.append('item_type',type);fd.append('name',name);fd.append('description',E('newItemDescription').value||'');fd.append('unit_cost',E('newItemUnitCost').value||'0');fd.append('markup_percent',E('newItemMarkup').value||'0');fd.append('unit_price',E('newItemUnitPrice').value||'0');fd.append('exempt_tax',E('newItemTaxExempt').checked?'1':'0');fd.append('tax_rate_id',(!E('newItemTaxExempt').checked&&rate)?String(rate.id||0):'0');fd.append('tax_percent',(!E('newItemTaxExempt').checked&&rate)?String(rate.rate_percent||0):'0');if(catalogImageFile)fd.append('item_image',catalogImageFile,catalogImageFile.name||'item-image');var btn=E('createCatalogItemButton');btn.disabled=true;btn.textContent='Creating...';request(fd).then(function(d){var item=d.item||{},kind=String(d.item_kind||type);if(kind==='service'){var exists=meta.services.some(function(x){return Number(x.id)===Number(item.id)});if(!exists)meta.services.push(item)}else{var existsP=meta.products.some(function(x){return Number(x.id)===Number(item.id)});if(!existsP)meta.products.push(item)}var val=kind+':'+Number(item.id);if(catalogTargetIndex!==null&&cart[catalogTargetIndex])applyCatalogToLine(catalogTargetIndex,val);catalogTargetIndex=null;resetCatalogImage();if(catalogModal)catalogModal.hide();toast('success',d.message||'Item created successfully.')} ).catch(function(err){toast('error',err.message)}).finally(function(){btn.disabled=false;btn.textContent='Create'})}
function openTaxRateModal(){E('newTaxName').value='';E('newTaxRate').value='0';E('newTaxDescription').value='';E('newTaxDefault').checked=false;if(!taxRateModal)taxRateModal=bootstrap.Modal.getOrCreateInstance(E('taxRateModal'));taxRateModal.show();setTimeout(function(){E('newTaxName').focus()},160)}
function createTaxRate(){var name=String(E('newTaxName').value||'').trim(),rate=Math.max(0,Number(E('newTaxRate').value||0));if(!name){toast('warning','Enter a tax rate name.');E('newTaxName').focus();return}if(rate<0||rate>100){toast('warning','Tax rate must be between 0 and 100.');return}var fd=new FormData();fd.append('action','create_tax_rate');fd.append('name',name);fd.append('rate_percent',String(rate));fd.append('description',E('newTaxDescription').value||'');fd.append('is_default',E('newTaxDefault').checked?'1':'0');var btn=E('createTaxRateButton');btn.disabled=true;btn.textContent='Creating...';request(fd).then(function(d){var r=d.tax_rate||{};if(Number(r.id||0)<=0)throw new Error('Tax rate was not returned by the server.');if(Number(r.is_default||0)===1)meta.tax_rates.forEach(function(x){x.is_default=0});var pos=meta.tax_rates.findIndex(function(x){return Number(x.id)===Number(r.id)});if(pos>=0)meta.tax_rates[pos]=r;else meta.tax_rates.push(r);if(Number(r.is_default||0)===1)meta.default_tax_rate_id=Number(r.id);renderTaxMenu();setSelectedTaxRate(r.id);if(taxRateModal)taxRateModal.hide();toast('success',d.message||'Tax rate created successfully.')} ).catch(function(err){toast('error',err.message)}).finally(function(){btn.disabled=false;btn.textContent='Create Tax Rate'})}
function renderClientViewOptions(){var input=E('clientViewOptionsJson');if(input)input.value=JSON.stringify(clientViewOptions);document.querySelectorAll('[data-client-view-key]').forEach(function(btn){var key=btn.getAttribute('data-client-view-key'),on=!!clientViewOptions[key];btn.classList.toggle('on',on);btn.setAttribute('aria-pressed',on?'true':'false')})}
function toggleClientViewPanel(){var panel=E('clientViewPanel'),link=E('clientViewToggle');if(!panel||!link)return;var show=!panel.classList.contains('show');panel.classList.toggle('show',show);panel.setAttribute('aria-hidden',show?'false':'true');link.textContent=show?'Close':'Change'}
function addDaysToLocalDate(dateString,days){var p=String(dateString||'').split('-'),y=Number(p[0]),m=Number(p[1]),day=Number(p[2]);if(p.length!==3||!y||!m||!day)return today();var d=new Date(y,m-1,day);d.setDate(d.getDate()+Number(days||0));return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')}
function syncTerms(){var v=E('paymentTermsPreset').value,issue=E('issueDate').value||today();if(v==='custom'){E('dueDateRow').style.display='grid';E('paymentTerms').value='Custom';return}var days=Number(v||0);E('dueDate').value=days===0?issue:addDaysToLocalDate(issue,days);E('dueDateRow').style.display='none';E('paymentTerms').value=days===0?'Due upon receipt':'Net '+days}
function serialize(){syncNoteMentions();E('itemsJson').value=JSON.stringify(cart.map(function(x){return{product_service_id:x.product_service_id,product_id:x.product_id,item_source:x.item_source,new_product_name:x.new_product_name,item_name:x.item_name,description:x.description,quantity:Number(x.quantity),unit_cost:Number(x.unit_cost),unit_price:Number(x.unit_price),discount_amount:Number(x.discount_amount),tax_percent:Number(x.tax_percent),service_date:x.service_date}}));E('customFieldsJson').value=JSON.stringify(customFields.filter(function(x){return String(x.label||'').trim()!==''||String(x.value||'').trim()!==''}));E('clientViewOptionsJson').value=JSON.stringify(clientViewOptions);E('noteMentionsJson').value=JSON.stringify(noteMentionIds)}
function validate(){if(!String(E('invoiceNo').value||'').trim()){toast('warning','Enter an invoice number.');E('invoiceNo').focus();return false}if(!E('clientId').value){toast('warning','Select a customer.');return false}if(source==='job'&&!E('jobId').value){toast('warning','The selected job could not be linked. Choose the customer job again.');return false}if(!cart.length){toast('warning','Add at least one invoice item.');return false}for(var i=0;i<cart.length;i++){if(!cart[i].item_name.trim()){toast('warning','Line item '+(i+1)+' needs a name.');return false}if(Number(cart[i].quantity)<=0){toast('warning','Line item '+(i+1)+' needs a valid quantity.');return false}}if(totals().total<=0){toast('warning','Invoice total must be greater than zero.');return false}if(!E('dueDate').value){toast('warning','Select a due date.');return false}return true}
function setExtraSectionState(id,show){var section=E(id),toggle=document.querySelector('[data-section-toggle="'+id+'"]');if(section)section.classList.toggle('show',!!show);if(toggle)toggle.hidden=!!show}
function setSimilarPaymentTerms(data){var days=Math.max(0,Number(data&&data.payment_term_days||0)),preset=[0,7,15,30,45].indexOf(days)>=0?String(days):'custom',issue=E('issueDate').value||today();E('paymentTermsPreset').value=preset;E('dueDate').value=days===0?issue:addDaysToLocalDate(issue,days);if(preset==='custom'){E('dueDateRow').style.display='grid';E('paymentTerms').value=String(data&&data.payment_terms||'Custom')||'Custom'}else{E('dueDateRow').style.display='none';E('paymentTerms').value=days===0?'Due upon receipt':'Net '+days}}
function applySimilarInvoice(data){if(!data||Number(data.source_invoice_id||0)<=0)return Promise.resolve(false);resetJob(false);var clientId=Number(data.client_id||0),locationId=Number(data.location_id||0);if(clientId>0){initCustomerSelect(clientId);applyClient(locationId,{skipJobs:true})}if(Number(data.branch_id||0)>0)E('branchId').value=String(Number(data.branch_id));E('invoiceSubject').value=String(data.subject||'For Services Rendered');E('issueDate').value=today();setSimilarPaymentTerms(data);cart=Array.isArray(data.items)?data.items.map(normalize):[];customFields=Array.isArray(data.custom_fields)?data.custom_fields.map(function(x){return{label:String(x&&x.label||''),value:String(x&&x.value||'')}}):[];renderCustomFields();var defaults={quantities:true,unit_prices:true,line_item_totals:true,account_balance:true,late_stamp:true},saved=(data.client_view_options&&typeof data.client_view_options==='object')?data.client_view_options:{};Object.keys(defaults).forEach(function(k){clientViewOptions[k]=Object.prototype.hasOwnProperty.call(saved,k)?!!saved[k]:defaults[k]});renderClientViewOptions();var cm=String(data.client_message||''),cd=String(data.contract_disclaimer||'');E('clientMessage').value=cm;E('contractDisclaimer').value=cd;setExtraSectionState('clientMessageSection',cm.trim()!=='');setExtraSectionState('contractSection',cd.trim()!=='');setExtraSectionState('imagesSection',false);setExtraSectionState('attachmentsSection',false);pendingFiles={image:[],attachment:[]};noteFiles=[];noteMentionIds=[];E('invoiceNotes').value='';renderPendingFiles();renderNoteFiles();syncNoteMentions();invoiceDiscount={active:false,type:'fixed',value:0};E('discountInput').value='0.00';E('discountType').value='fixed';selectedTaxRateId=0;E('invoiceTaxRateId').value='';renderItems();updateTotals();return refreshInvoiceNumber().then(function(){setTimeout(function(){toast('success','Similar invoice loaded from '+String(data.source_invoice_no||('invoice #'+data.source_invoice_id))+'. Review the details and save to create a new invoice.')},120);return true})}
function loadMeta(){var fd=new FormData();fd.append('action','form_meta');return request(fd).then(function(d){setMeta(d.meta||{});if(similarInvoiceId>0){if(similarInvoiceLoadError){toast('error',similarInvoiceLoadError);return Promise.resolve()}if(similarInvoiceData)return applySimilarInvoice(similarInvoiceData)}if(preJobId>0)return loadJob(preJobId,0);if(preClientId>0){initCustomerSelect(preClientId);return applyClient(preLocationId,{skipJobs:false})}return Promise.resolve()})}
E('issueDate').value=today();syncTerms();
$('#clientId').on('select2:select',function(e){var raw=String(e.params&&e.params.data?e.params.data.id:this.value||'');if(raw.indexOf('newclient:')===0){openCreateCustomerModal(raw.slice(10));$('#clientId').val(null).trigger('change.select2');return}resetJob(false);setSource('direct');jobsPromptedClientId=0;applyClient(0,{skipJobs:false})});
$('#clientId').on('select2:clear',function(){resetJob(false);E('locationId').value='';renderCustomerCard()});
$('#locationId').on('select2:select',function(e){var raw=String(e.params&&e.params.data?e.params.data.id:this.value||'');if(raw.indexOf('newloc:')===0){openCreateLocationModal(raw.slice(7));var old=defaultLocationId(Number(E('clientId').value||0));$('#locationId').val(old?String(old):null).trigger('change.select2');return}renderCustomerCard();E('locationPickerWrap').classList.remove('show')});
$('#locationId').on('select2:clear',function(){renderCustomerCard();E('locationPickerWrap').classList.add('show')});
$('#salespersonSelect').on('change',function(){var fallback=Number((meta.current_user||{}).id||0);E('salespersonId').value=this.value?String(this.value):String(fallback||'')});
E('clientMenuButton').addEventListener('click',function(e){e.stopPropagation();E('clientMenu').classList.toggle('show')});
E('changeClientButton').addEventListener('click',function(){E('clientMenu').classList.remove('show');E('selectedClientCard').classList.remove('show');E('clientPickerWrap').style.display='block';setTimeout(function(){try{$('#clientId').select2('open')}catch(e){}},0)});
E('changeLocationButton').addEventListener('click',function(){E('clientMenu').classList.remove('show');E('locationPickerWrap').classList.add('show');setTimeout(function(){try{$('#locationId').select2('open')}catch(e){}},0)});
E('selectCustomerJobButton').addEventListener('click',function(){E('clientMenu').classList.remove('show');var c=currentClient();if(c)loadCustomerJobs(c.id,true)});
E('removeLinkedJobButton').addEventListener('click',function(){resetJob(false);toast('success','Job link removed. Invoice items were kept editable.')});
document.addEventListener('click',function(e){if(!e.target.closest('.ni-customer-menu-wrap'))E('clientMenu').classList.remove('show')});
E('createCustomerButton').addEventListener('click',createCustomer);E('createLocationButton').addEventListener('click',createLocation);
E('customerJobsBody').addEventListener('change',function(e){if(!e.target.matches('[data-job-select]'))return;var id=Number(e.target.getAttribute('data-job-select'));this.querySelectorAll('[data-job-select]').forEach(function(x){if(x!==e.target)x.checked=false});selectedCustomerJobId=e.target.checked?id:0});
E('customerJobsContinue').addEventListener('click',continueCustomerJob);
E('paymentTermsPreset').addEventListener('change',syncTerms);E('issueDate').addEventListener('change',syncTerms);E('addLineButton').addEventListener('click',addBlankLine);
E('invoiceNo').addEventListener('input',function(){E('invoiceNoMode').value='manual';this.classList.add('is-manual')});
E('newItemUnitCost').addEventListener('input',recalcNewItemPrice);E('newItemMarkup').addEventListener('input',recalcNewItemPrice);E('newItemUnitPrice').addEventListener('input',function(){catalogPriceTouched=true});E('createCatalogItemButton').addEventListener('click',createCatalogItem);
E('newItemImagePick').addEventListener('click',function(){E('newItemImage').click()});E('newItemImage').addEventListener('change',function(){if(this.files&&this.files[0])setCatalogImage(this.files[0]);else resetCatalogImage()});E('newItemImageRemove').addEventListener('click',resetCatalogImage);
E('clientViewToggle').addEventListener('click',function(e){e.preventDefault();toggleClientViewPanel()});document.querySelectorAll('[data-client-view-key]').forEach(function(btn){btn.addEventListener('click',function(){var key=this.getAttribute('data-client-view-key');clientViewOptions[key]=!clientViewOptions[key];renderClientViewOptions()})});
['dragenter','dragover'].forEach(function(evt){E('newItemImagePick').addEventListener(evt,function(e){e.preventDefault();e.stopPropagation();this.classList.add('dragover')})});['dragleave','drop'].forEach(function(evt){E('newItemImagePick').addEventListener(evt,function(e){e.preventDefault();e.stopPropagation();this.classList.remove('dragover')})});E('newItemImagePick').addEventListener('drop',function(e){var f=e.dataTransfer&&e.dataTransfer.files?e.dataTransfer.files[0]:null;if(!f)return;try{var dt=new DataTransfer();dt.items.add(f);E('newItemImage').files=dt.files}catch(ex){}setCatalogImage(f)});
E('itemRows').addEventListener('input',function(e){var r=e.target.closest('.ni-line');if(!r||!e.target.matches('[data-f]'))return;var i=Number(r.getAttribute('data-index')),f=e.target.getAttribute('data-f');if(!cart[i])return;if(['quantity','unit_cost','unit_price'].indexOf(f)>=0)cart[i][f]=Math.max(f==='quantity'?.001:0,Number(e.target.value||0));else cart[i][f]=e.target.value;if(f==='service_date'){var db=r.querySelector('[data-line-date-button]');if(db){db.textContent='Add Service Date';db.style.display=e.target.value?'none':'inline-block'}if(!e.target.value)e.target.style.display='none'}var all=calculateAll(),t=r.querySelector('.ni-line-total');if(t&&all.lines[i])t.textContent=money(all.lines[i].total);updateTotals()});
E('itemRows').addEventListener('click',function(e){var dateButton=e.target.closest('[data-line-date-button]');if(dateButton){var row=dateButton.closest('.ni-line'),input=row?row.querySelector('[data-f="service_date"]'):null;if(input)openDatePicker(input);return}var b=e.target.closest('[data-remove]');if(!b)return;cart.splice(Number(b.getAttribute('data-remove')),1);renderItems()});
E('discountLink').addEventListener('click',function(){if(!cart.length){toast('warning','Add an invoice item first.');return}invoiceDiscount.active=true;invoiceDiscount.type='fixed';invoiceDiscount.value=0;E('discountType').value='fixed';E('discountInput').value='0.00';updateTotals();setTimeout(function(){E('discountInput').focus();E('discountInput').select()},0)});
E('discountInput').addEventListener('input',function(){invoiceDiscount.active=true;invoiceDiscount.value=Math.max(0,Number(this.value||0));updateTotals()});
E('discountType').addEventListener('change',function(){invoiceDiscount.type=this.value==='percentage'?'percentage':'fixed';if(invoiceDiscount.type==='percentage'&&Number(invoiceDiscount.value)>100){invoiceDiscount.value=100;E('discountInput').value='100'}updateTotals()});
E('discountRemove').addEventListener('click',function(){invoiceDiscount={active:false,type:'fixed',value:0};E('discountInput').value='0.00';E('discountType').value='fixed';updateTotals()});
E('taxLink').addEventListener('click',function(){if(!cart.length){toast('warning','Add an invoice item first.');return}E('taxLink').style.display='none';E('taxControl').style.display='block';E('taxMenu').classList.add('show');renderTaxMenu()});
E('taxSelectorButton').addEventListener('click',function(){renderTaxMenu();E('taxMenu').classList.toggle('show')});
E('taxMenu').addEventListener('click',function(e){var rateBtn=e.target.closest('[data-tax-rate-id]');if(rateBtn){setSelectedTaxRate(rateBtn.getAttribute('data-tax-rate-id'));return}if(e.target.closest('#createTaxRateLink')){E('taxMenu').classList.remove('show');openTaxRateModal()}});
E('taxRemove').addEventListener('click',function(){setSelectedTaxRate(0)});
E('createTaxRateButton').addEventListener('click',createTaxRate);
document.addEventListener('click',function(e){if(!e.target.closest('#taxControl')&&!e.target.closest('#taxLink'))E('taxMenu').classList.remove('show')});
E('addCustomField').addEventListener('click',function(){customFields.push({label:'',value:''});renderCustomFields();var rows=E('customFields').querySelectorAll('[data-custom-f="label"]');if(rows.length)rows[rows.length-1].focus()});
E('customFields').addEventListener('input',function(e){var i=Number(e.target.getAttribute('data-custom-i')),f=e.target.getAttribute('data-custom-f');if(!f||!customFields[i])return;customFields[i][f]=e.target.value;E('customFieldsJson').value=JSON.stringify(customFields)});
E('customFields').addEventListener('click',function(e){var b=e.target.closest('[data-remove-custom]');if(!b)return;customFields.splice(Number(b.getAttribute('data-remove-custom')),1);renderCustomFields()});
document.querySelectorAll('[data-pick-file]').forEach(function(b){b.addEventListener('click',function(){var el=E(this.getAttribute('data-pick-file'));if(el)el.click()})});
E('imageInput').addEventListener('change',function(){queueFiles(this,'image')});E('attachmentInput').addEventListener('change',function(){queueFiles(this,'attachment')});
document.addEventListener('click',function(e){var b=e.target.closest('[data-remove-file]');if(!b)return;var cat=b.getAttribute('data-remove-file'),i=Number(b.getAttribute('data-file-index'));if(pendingFiles[cat])pendingFiles[cat].splice(i,1);renderPendingFiles()});
document.querySelectorAll('[data-section-toggle]').forEach(function(b){b.addEventListener('click',function(){var id=this.getAttribute('data-section-toggle');setExtraSectionState(id,true)})});document.querySelectorAll('[data-remove-section]').forEach(function(b){b.addEventListener('click',function(){var id=this.getAttribute('data-remove-section');setExtraSectionState(id,false);if(id==='imagesSection'){pendingFiles.image=[];renderPendingFiles()}if(id==='attachmentsSection'){pendingFiles.attachment=[];renderPendingFiles()}if(id==='clientMessageSection')E('clientMessage').value='';if(id==='contractSection')E('contractDisclaimer').value=''})});
E('invoiceNotes').addEventListener('input',function(){syncNoteMentions();mentionActiveIndex=0;renderMentionMenu()});
E('invoiceNotes').addEventListener('click',renderMentionMenu);
E('invoiceNotes').addEventListener('keydown',function(e){var menu=E('noteMentionMenu');if(!menu.classList.contains('show'))return;if(e.key==='ArrowDown'){e.preventDefault();mentionActiveIndex=(mentionActiveIndex+1)%mentionMatches.length;renderMentionMenu()}else if(e.key==='ArrowUp'){e.preventDefault();mentionActiveIndex=(mentionActiveIndex-1+mentionMatches.length)%mentionMatches.length;renderMentionMenu()}else if(e.key==='Enter'&&mentionMatches.length){e.preventDefault();insertNoteMention(mentionMatches[mentionActiveIndex].id)}else if(e.key==='Escape'){e.preventDefault();hideMentionMenu()}});
E('noteMentionMenu').addEventListener('mousedown',function(e){var b=e.target.closest('[data-note-mention-id]');if(!b)return;e.preventDefault();insertNoteMention(b.getAttribute('data-note-mention-id'))});
document.addEventListener('click',function(e){if(!e.target.closest('.ni-note-editor'))hideMentionMenu()});
E('noteAttachButton').addEventListener('click',function(){E('noteAttachmentInput').click()});
E('noteAttachmentInput').addEventListener('change',function(){queueNoteFiles(this.files);this.value=''});
['dragenter','dragover'].forEach(function(evt){E('noteUploadDrop').addEventListener(evt,function(e){e.preventDefault();e.stopPropagation();this.classList.add('dragover')})});
['dragleave','drop'].forEach(function(evt){E('noteUploadDrop').addEventListener(evt,function(e){e.preventDefault();e.stopPropagation();this.classList.remove('dragover')})});
E('noteUploadDrop').addEventListener('drop',function(e){queueNoteFiles(e.dataTransfer&&e.dataTransfer.files?e.dataTransfer.files:[])});
E('noteFileList').addEventListener('click',function(e){var b=e.target.closest('[data-remove-note-file]');if(!b)return;noteFiles.splice(Number(b.getAttribute('data-remove-note-file')),1);renderNoteFiles()});
E('cancelButton').addEventListener('click',function(){window.location.href=similarInvoiceId>0?('invoice-view.php?invoice_id='+similarInvoiceId):'invoices.php'});
E('invoiceForm').addEventListener('submit',function(e){e.preventDefault();serialize();if(!validate())return;serialize();var fd=new FormData(this);appendPendingFiles(fd);appendNoteFiles(fd);fd.append('action','save');var b=E('saveButton');b.disabled=true;b.textContent='Saving...';request(fd).then(function(d){var sent=Number(d.email_sent||0)===1,emailMessage=String(d.email_message||'').trim(),recipient=String(d.email_recipient||'').trim();if(sent){toast('success',emailMessage||('Invoice created and emailed'+(recipient?' to '+recipient:'')+'.'));setTimeout(function(){window.location.href='invoice-view.php?invoice_id='+Number(d.invoice_id)},1300)}else{var warning=emailMessage||'Invoice was created, but the customer email was not sent.';toast('warning',warning);setTimeout(function(){window.location.href='invoice-view.php?invoice_id='+Number(d.invoice_id)},2800)}}).catch(function(err){toast('error',err.message)}).finally(function(){b.disabled=false;b.textContent='Save Invoice'})});
renderCustomFields();renderPendingFiles();renderNoteFiles();renderClientViewOptions();loadMeta().then(function(){if(!cart.length&&source==='direct')cart.push(normalize({item_source:'manual',quantity:1}));renderItems();updateCustomerView();return refreshInvoiceNumber()}).catch(function(e){toast('error',e.message)});
})();
</script>
</body>
</html>
