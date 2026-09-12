<?php
/* FieldPlx Create Job - Version 4.3.0 - Jobber workflow + Add Quotation UI + Request/Quote conversion + Request checklist inheritance */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Create Job';
$activePage = 'jobs';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['jobs_csrf_token'])) { $_SESSION['jobs_csrf_token'] = bin2hex(random_bytes(32)); }
$jobsCsrfToken = (string)$_SESSION['jobs_csrf_token'];
$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$quoteId = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : 0;
$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$locationId = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
$serviceId = isset($_GET['product_service_id']) ? (int)$_GET['product_service_id'] : (isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0);
$requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

/*
|--------------------------------------------------------------------------
| Checklist bootstrap
|--------------------------------------------------------------------------
| The Request checklist builder saves the form title in checklist_templates
| and links the template to the request through service_request_checklists.
| For Request -> Job (and Quote -> Job when the Quote came from a Request),
| preload the exact linked checklist so the Job form shows the same title,
| sections and field/question types before the Job is saved.
|
| Existing Job edits load the templates already attached to the Job.
| This is intentionally read-only bootstrap data; the existing Jobs API
| remains responsible for copying selected templates into job_checklists.
*/
$jobChecklistBootstrap = array(
    'source_request_id' => 0,
    'source_request_no' => '',
    'selected_template_ids' => array(),
    'templates' => array()
);

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $checklistTenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;

        $jfTableExists = function ($table) use ($pdo) {
            static $cache = array();
            $table = (string)$table;
            if (isset($cache[$table])) return $cache[$table];
            $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
            $s->execute(array(':t' => $table));
            $cache[$table] = ((int)$s->fetchColumn() > 0);
            return $cache[$table];
        };

        $jfColumnExists = function ($table, $column) use ($pdo) {
            static $cache = array();
            $key = (string)$table . '.' . (string)$column;
            if (isset($cache[$key])) return $cache[$key];
            $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
            $s->execute(array(':t' => (string)$table, ':c' => (string)$column));
            $cache[$key] = ((int)$s->fetchColumn() > 0);
            return $cache[$key];
        };

        if ($checklistTenantId > 0) {
            $bootstrapRequestId = $requestId;

            /* Quote -> Job can still inherit the Request checklist. */
            if (
                $bootstrapRequestId <= 0 &&
                $quoteId > 0 &&
                $jfTableExists('quotes') &&
                $jfColumnExists('quotes', 'request_id')
            ) {
                $s = $pdo->prepare("SELECT request_id FROM quotes WHERE id=:id AND tenant_id=:t LIMIT 1");
                $s->execute(array(':id' => $quoteId, ':t' => $checklistTenantId));
                $bootstrapRequestId = (int)$s->fetchColumn();
            }

            /* Edit Job: resolve its original Request for the source label. */
            if (
                $bootstrapRequestId <= 0 &&
                $jobId > 0 &&
                $jfTableExists('jobs') &&
                $jfColumnExists('jobs', 'request_id')
            ) {
                $s = $pdo->prepare("SELECT request_id FROM jobs WHERE id=:id AND tenant_id=:t LIMIT 1");
                $s->execute(array(':id' => $jobId, ':t' => $checklistTenantId));
                $bootstrapRequestId = (int)$s->fetchColumn();
            }

            $jobChecklistBootstrap['source_request_id'] = $bootstrapRequestId > 0 ? $bootstrapRequestId : 0;

            if (
                $bootstrapRequestId > 0 &&
                $jfTableExists('service_requests') &&
                $jfColumnExists('service_requests', 'request_no')
            ) {
                $s = $pdo->prepare("SELECT request_no FROM service_requests WHERE id=:id AND tenant_id=:t LIMIT 1");
                $s->execute(array(':id' => $bootstrapRequestId, ':t' => $checklistTenantId));
                $requestNo = $s->fetchColumn();
                if ($requestNo !== false) $jobChecklistBootstrap['source_request_no'] = (string)$requestNo;
            }

            $templateIds = array();

            /* Existing Job edit: use what is already attached to that Job. */
            if (
                $jobId > 0 &&
                $jfTableExists('job_checklists') &&
                $jfColumnExists('job_checklists', 'checklist_template_id')
            ) {
                $s = $pdo->prepare("SELECT DISTINCT checklist_template_id FROM job_checklists WHERE tenant_id=:t AND job_id=:j AND checklist_template_id IS NOT NULL ORDER BY checklist_template_id");
                $s->execute(array(':t' => $checklistTenantId, ':j' => $jobId));
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                    $tid = (int)$tid;
                    if ($tid > 0) $templateIds[$tid] = $tid;
                }
            }
            /* New Job from Request/Quote: auto-select checklists linked to Request. */
            elseif (
                $bootstrapRequestId > 0 &&
                $jfTableExists('service_request_checklists')
            ) {
                $s = $pdo->prepare("SELECT DISTINCT checklist_template_id FROM service_request_checklists WHERE tenant_id=:t AND request_id=:r ORDER BY id");
                $s->execute(array(':t' => $checklistTenantId, ':r' => $bootstrapRequestId));
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                    $tid = (int)$tid;
                    if ($tid > 0) $templateIds[$tid] = $tid;
                }
            }

            $templateIds = array_values($templateIds);
            $jobChecklistBootstrap['selected_template_ids'] = $templateIds;

            if (
                $templateIds &&
                $jfTableExists('checklist_templates') &&
                $jfTableExists('checklist_template_items')
            ) {
                $ph = implode(',', array_fill(0, count($templateIds), '?'));
                $params = array_merge(array($checklistTenantId), $templateIds);
                $ts = $pdo->prepare("SELECT id,name,description,status FROM checklist_templates WHERE tenant_id=? AND id IN ($ph) ORDER BY id");
                $ts->execute($params);
                $templates = $ts->fetchAll(PDO::FETCH_ASSOC);

                $hasSection = $jfColumnExists('checklist_template_items', 'section_title');
                $hasQuestionType = $jfColumnExists('checklist_template_items', 'question_type');
                $hasOptions = $jfColumnExists('checklist_template_items', 'options_json');

                $itemSelect = 'id,checklist_template_id,title,description,is_required,sort_order';
                $itemSelect .= $hasSection ? ',section_title' : ',NULL AS section_title';
                $itemSelect .= $hasQuestionType ? ',question_type' : ',NULL AS question_type';
                $itemSelect .= $hasOptions ? ',options_json' : ',NULL AS options_json';
                $is = $pdo->prepare("SELECT $itemSelect FROM checklist_template_items WHERE checklist_template_id IN ($ph) ORDER BY checklist_template_id,sort_order,id");
                $is->execute($templateIds);

                $itemsByTemplate = array();
                foreach ($is->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    $tid = (int)$item['checklist_template_id'];
                    if (!isset($itemsByTemplate[$tid])) $itemsByTemplate[$tid] = array();

                    $description = isset($item['description']) ? (string)$item['description'] : '';
                    $legacyMeta = json_decode($description, true);
                    if (!is_array($legacyMeta)) $legacyMeta = array();

                    $sectionTitle = trim((string)(isset($item['section_title']) ? $item['section_title'] : ''));
                    if ($sectionTitle === '' && isset($legacyMeta['section_title'])) {
                        $sectionTitle = trim((string)$legacyMeta['section_title']);
                    }

                    $questionType = trim((string)(isset($item['question_type']) ? $item['question_type'] : ''));
                    if ($questionType === '' && isset($legacyMeta['question_type'])) {
                        $questionType = trim((string)$legacyMeta['question_type']);
                    }
                    if (!in_array($questionType, array('short_answer','long_answer','dropdown','checkbox','number','image','date','signature'), true)) {
                        $questionType = 'checkbox';
                    }

                    $options = array();
                    if (!empty($item['options_json'])) {
                        $decoded = json_decode((string)$item['options_json'], true);
                        if (is_array($decoded)) $options = $decoded;
                    } elseif (isset($legacyMeta['options']) && is_array($legacyMeta['options'])) {
                        $options = $legacyMeta['options'];
                    }

                    /* Request API stores structured metadata in description on older schemas. */
                    $plainDescription = $legacyMeta ? '' : $description;

                    $itemsByTemplate[$tid][] = array(
                        'id' => (int)$item['id'],
                        'title' => (string)$item['title'],
                        'description' => $plainDescription,
                        'is_required' => !empty($item['is_required']) ? 1 : 0,
                        'section_title' => $sectionTitle,
                        'question_type' => $questionType,
                        'options' => array_values($options),
                        'sort_order' => (int)$item['sort_order']
                    );
                }

                foreach ($templates as $template) {
                    $tid = (int)$template['id'];
                    $templateItems = isset($itemsByTemplate[$tid]) ? $itemsByTemplate[$tid] : array();
                    $sections = array();
                    foreach ($templateItems as $item) {
                        $section = trim((string)$item['section_title']);
                        if ($section === '') $section = 'Checklist';
                        $sections[$section] = true;
                    }

                    $jobChecklistBootstrap['templates'][] = array(
                        'id' => $tid,
                        'name' => (string)$template['name'],
                        'description' => isset($template['description']) ? (string)$template['description'] : '',
                        'item_count' => count($templateItems),
                        'section_count' => count($sections),
                        'items' => $templateItems,
                        'from_request' => ($bootstrapRequestId > 0 && $jobId <= 0) ? 1 : 0
                    );
                }
            }
        }
    } catch (Throwable $e) {
        error_log('FieldPlx Job Form checklist bootstrap: ' . $e->getMessage());
        $jobChecklistBootstrap = array(
            'source_request_id' => 0,
            'source_request_no' => '',
            'selected_template_ids' => array(),
            'templates' => array()
        );
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $jobId > 0 ? 'Edit Job' : 'New Job' ?> - FieldPlx</title>
<?php require_once __DIR__ . '/includes/links.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>



        /* ==========================================================
           FieldPlx canonical tenant shell
           Matches the working Customers / Teams template.
           ========================================================== */
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
            align-items:center;
            gap:9px;
            min-width:0;
            color:var(--fd-text)!important;
            text-decoration:none!important;
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
            max-width:170px;
            overflow:hidden;
            white-space:nowrap;
            text-overflow:ellipsis;
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
            max-width:145px;
            min-width:0;
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
            text-decoration:none!important;
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
            text-decoration:none!important;
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
            text-decoration:none!important;
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
            text-decoration:none!important;
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
            text-decoration:none!important;
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

        /* ---------- Main layout ---------- */
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

        .fd-dashboard{
            width:100%!important;
            max-width:1600px!important;
            margin:auto!important;
            padding:25px 27px 35px!important;
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
            text-decoration:none!important;
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

            .fd-dashboard{
                padding:17px 13px 28px!important;
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





    /* ==========================================================
       FieldPlx Job Builder v3.0
       Function reference: Jobber New Job
       Visual reference: FieldPlx Add Quotation
       ========================================================== */
    :root{
      --jqj-navy:#001131;
      --jqj-green:#2f8d25;
      --jqj-green-dark:#24751d;
      --jqj-green-soft:#f2f8ee;
      --jqj-text:#0b2b37;
      --jqj-muted:#5f7380;
      --jqj-border:#dce4e8;
      --jqj-soft:#f8fafb;
      --jqj-danger:#c74646;
      --jqj-warning:#fff7dd;
      --jqj-warning-border:#e9d691;
    }
    body{background:#fff!important;font-family:Arial,Helvetica,sans-serif!important;font-size:14px!important}
    .jqj-page{width:100%;max-width:none;margin:0;padding:0 0 84px;background:#fff;color:var(--jqj-text)}
    .jqj-top{padding:24px 28px 28px;border-bottom:1px solid var(--jqj-border);background:#fff}
    .jqj-heading{display:flex;align-items:center;gap:12px;margin-bottom:18px}
    .jqj-heading-icon{color:var(--jqj-green);font-size:22px}.jqj-heading h1{margin:0;font-size:24px;line-height:1.2;font-weight:700;color:var(--jqj-text)}
    .jqj-source{margin:0 0 18px;padding:12px 14px;display:flex;align-items:center;gap:11px;border:1px solid #cfe2bf;border-radius:8px;background:#f6fbf2}
    .jqj-source[hidden]{display:none}.jqj-source-icon{width:34px;height:34px;flex:0 0 34px;display:grid;place-items:center;border-radius:50%;background:#e5f3dd;color:var(--jqj-green-dark);font-size:15px}
    .jqj-source-copy{min-width:0;flex:1}.jqj-source-copy strong{display:block;color:#173845;font-size:13px}.jqj-source-copy span{display:block;margin-top:3px;color:#617682;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.jqj-source a{color:var(--jqj-green-dark);font-size:12px;font-weight:700;text-decoration:underline!important;white-space:nowrap}
    .jqj-title{width:100%;height:48px;margin-bottom:15px;padding:0 14px;border:1px solid var(--jqj-border);border-radius:8px;background:#fff;color:#173845;font:14px Arial,Helvetica,sans-serif;outline:0}.jqj-title:focus,.jqj-control:focus,.jqj-float input:focus,.jqj-float select:focus,.jqj-float textarea:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
    .jqj-top-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(380px,.9fr);gap:24px}.jqj-customer-col{display:grid;gap:10px}.jqj-control{width:100%;min-height:44px;padding:8px 12px;border:1px solid var(--jqj-border);border-radius:8px;background:#fff;color:#183845;font:13px Arial,Helvetica,sans-serif;outline:0}.jqj-control:disabled{background:#f5f7f8;color:#748590}
    .jqj-meta{display:grid}.jqj-meta-row{min-height:48px;display:grid;grid-template-columns:150px minmax(0,1fr);align-items:center;border-bottom:1px solid var(--jqj-border)}.jqj-meta-row:first-child{border-top:1px solid var(--jqj-border)}.jqj-meta-label{color:#607582;font-size:13px}.jqj-meta-value{min-width:0}.jqj-readonly{color:#163644;font-size:13px;font-weight:700}.jqj-add-field{height:34px;padding:0 12px;border:1px solid var(--jqj-border);border-radius:7px;background:#fff;color:var(--jqj-green-dark);font:700 12px Arial,Helvetica,sans-serif;cursor:pointer}.jqj-add-field:hover{background:var(--jqj-green-soft);border-color:#b9d9ab}
    .jqj-custom-list{margin-top:14px;display:grid;gap:9px}.jqj-custom-row{display:grid;grid-template-columns:minmax(170px,.7fr) minmax(220px,1.3fr) 34px;gap:9px;align-items:center}.jqj-custom-label{color:#526c7a;font-size:12px;font-weight:700}.jqj-custom-area{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:6px}.jqj-remove{width:34px;height:34px;border:0;border-radius:6px;background:transparent;color:var(--jqj-danger);cursor:pointer}.jqj-remove:hover{background:#fff1f1}
    .jqj-job-number-wrap{display:flex;align-items:center;gap:8px}.jqj-job-number-wrap .jqj-job-number-input{flex:1;min-width:0}.jqj-job-number-state{flex:0 0 auto;color:#6f7f88;font-size:10px;white-space:nowrap}.jqj-job-number-state.manual{color:var(--jqj-green-dark);font-weight:700}
    .jqj-section{margin:20px 28px 0;padding:21px 20px;border:1px solid var(--jqj-border);border-radius:8px;background:#fff}.jqj-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:17px}.jqj-section-head h2{margin:0;color:var(--jqj-text);font-size:20px;font-weight:700}.jqj-section-head p{margin:5px 0 0;color:var(--jqj-muted);font-size:12px;line-height:1.45}
    .jqj-schedule-bar{min-height:74px;padding:12px 14px;display:flex;align-items:center;gap:18px;border:1px solid var(--jqj-border);border-radius:8px}.jqj-cap{margin-bottom:6px;color:#1f4656;font-size:10px;font-weight:700;text-transform:uppercase}.jqj-tabs{display:flex}.jqj-tab{height:38px;padding:0 17px;border:1px solid var(--jqj-border);background:#fff;color:#38576a;font:700 12px Arial,Helvetica,sans-serif;cursor:pointer}.jqj-tab:first-child{border-radius:7px 0 0 7px}.jqj-tab:last-child{margin-left:-1px;border-radius:0 7px 7px 0}.jqj-tab.active{z-index:1;border-color:#69a951;background:#f9fff5;color:#3b822d}.jqj-schedule-summary{display:flex;align-items:center;gap:24px;color:#35556a;font-size:12px}.jqj-create-visits{margin-left:auto;height:36px;padding:0 13px;border:0;border-radius:7px;background:var(--jqj-green);color:#fff;font:700 11px Arial,Helvetica,sans-serif;cursor:pointer}
    .jqj-visits{display:grid;gap:14px;margin-top:16px}.jqj-visit{display:grid;grid-template-columns:112px minmax(0,1fr);border:1px solid var(--jqj-border);border-radius:8px;overflow:hidden}.jqj-date-rail{padding:15px 14px;border-right:1px solid var(--jqj-border);color:#4d697b;font-size:12px}.jqj-date-rail strong{display:block;margin-top:4px;color:#12384b;font-size:20px}.jqj-visit-main{padding:14px 16px}.jqj-visit-title-row{display:grid;grid-template-columns:minmax(0,1fr) 32px;gap:7px}.jqj-float{position:relative}.jqj-float label{position:absolute;left:12px;top:6px;z-index:1;color:#61798a;font-size:9px;pointer-events:none}.jqj-float input,.jqj-float select,.jqj-float textarea{width:100%;min-height:48px;padding:18px 12px 6px;border:1px solid var(--jqj-border);border-radius:8px;background:#fff;color:#173845;font:13px Arial,Helvetica,sans-serif;outline:0}.jqj-float textarea{min-height:88px;padding-top:21px;resize:vertical}.jqj-icon{width:32px;height:32px;display:grid;place-items:center;border:0;border-radius:6px;background:transparent;color:#345366;cursor:pointer}.jqj-icon:hover{background:#f2f4f5}.jqj-hint{margin:8px 0 13px;color:#58707e;font-size:11px}.jqj-two{display:grid;grid-template-columns:1fr 1fr;gap:11px}.jqj-check{display:flex;align-items:center;gap:8px;margin:8px 0 11px;color:#35556a;font-size:12px}.jqj-check input{width:17px;height:17px;accent-color:var(--jqj-green)}.jqj-assignee{margin-top:10px}.jqj-add-visit{margin-top:14px;height:36px;padding:0 12px;border:1px solid var(--jqj-border);border-radius:7px;background:#fff;color:var(--jqj-green-dark);font-weight:700;font-size:11px;cursor:pointer}
    .jqj-recurring{display:none;margin-top:16px;padding:15px;border:1px solid var(--jqj-border);border-radius:8px}.jqj-recurring.show{display:block}.jqj-rec-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px}.jqj-rec-grid .full{grid-column:1/-1}.jqj-end-radios{display:grid;grid-template-columns:1fr 1fr;gap:14px}.jqj-radio{display:flex;align-items:center;gap:8px;color:#35556a;font-size:12px}.jqj-radio input{width:17px;height:17px;accent-color:var(--jqj-green)}.jqj-end-grid{display:grid;grid-template-columns:120px minmax(170px,1fr) minmax(220px,1.25fr);gap:9px}.jqj-repeat-summary{margin-top:4px;color:#5f7581;font-size:11px}.jqj-email-team{margin-top:0}
    .jqj-checklists{margin-top:14px;border:1px solid var(--jqj-border);border-radius:8px;overflow:hidden}.jqj-collapse-btn{width:100%;min-height:48px;padding:0 14px;display:flex;align-items:center;justify-content:space-between;border:0;background:#fff;color:#365567;font:600 12px Arial,Helvetica,sans-serif;cursor:pointer}.jqj-checklist-body{display:none;padding:6px 14px 14px;border-top:1px solid #eef1f3}.jqj-checklist-body.show{display:block}.jqj-checklist-actions{display:flex;gap:14px;padding:7px 0}.jqj-text-btn{border:0;background:transparent;color:var(--jqj-green-dark);font:700 11px Arial,Helvetica,sans-serif;text-decoration:underline;cursor:pointer}.jqj-template-list{display:grid;gap:7px}.jqj-template-item{display:flex;align-items:center;gap:8px;color:#405d6a;font-size:12px}.jqj-template-item input{width:16px;height:16px;accent-color:var(--jqj-green)}
    .jqj-billing-meta{display:flex;gap:18px;margin-bottom:18px;color:#4d697b;font-size:12px}.jqj-billing-main{display:grid;grid-template-columns:minmax(280px,.9fr) minmax(320px,1.1fr);gap:22px}.jqj-billing-types h3,.jqj-paybox h3{margin:0 0 10px;color:#173845;font-size:14px}.jqj-billing-option{display:flex;align-items:flex-start;gap:9px;margin:10px 0;color:#294b5d;font-size:12px}.jqj-billing-option input{width:18px;height:18px;margin-top:1px;accent-color:var(--jqj-green)}.jqj-billing-option strong{display:block}.jqj-billing-option small{display:block;margin-top:3px;color:#718493;font-size:10px;line-height:1.4}.jqj-paybox{padding:16px 18px;border-radius:8px;background:#f3f1ec}.jqj-paybox p{margin:0 0 13px;color:#3b596b;font-size:12px;line-height:1.5}.jqj-frequency{margin-top:17px}.jqj-frequency select{margin-top:8px}.jqj-fixed{display:none;margin-top:10px}.jqj-fixed.show{display:block}
    .jqj-products{padding-bottom:12px}.jqj-lines{display:grid;gap:13px}.jqj-line{position:relative;padding:15px;border:1px solid var(--jqj-border);border-radius:8px;background:#fff}.jqj-line.source-line:before{position:absolute;right:14px;top:-10px;padding:3px 8px;border:1px solid #cfe2bf;border-radius:999px;background:#f6fbf2;color:var(--jqj-green-dark);content:attr(data-source-label);font-size:9px;font-weight:700}.jqj-line-top{display:grid;grid-template-columns:minmax(260px,1fr) 105px 135px 135px 135px 34px;gap:9px;align-items:start}.jqj-money-field{position:relative}.jqj-money-field label,.jqj-qty-field label{position:absolute;left:10px;top:5px;color:#61798a;font-size:9px}.jqj-money-field input,.jqj-qty-field input{width:100%;height:50px;padding:17px 10px 5px;border:1px solid var(--jqj-border);border-radius:8px;background:#fff;color:#173845;font-size:13px;outline:0}.jqj-line-total{height:50px;padding:6px 10px;border:1px solid var(--jqj-border);border-radius:8px}.jqj-line-total small{display:block;color:#61798a;font-size:9px}.jqj-line-total strong{display:block;margin-top:5px;color:#173845;font-size:13px;font-weight:500}.jqj-line textarea{width:100%;min-height:90px;margin-top:9px;padding:12px;border:1px solid var(--jqj-border);border-radius:8px;background:#fff;color:#173845;font:13px Arial,Helvetica,sans-serif;resize:vertical;outline:0}.jqj-manual-name{display:none;margin-top:7px}.jqj-line.manual .jqj-manual-name{display:block}.jqj-add-line{margin-top:14px;height:37px;padding:0 13px;border:0;border-radius:7px;background:var(--jqj-green);color:#fff;font:700 11px Arial,Helvetica,sans-serif;cursor:pointer}.jqj-add-line:hover{background:var(--jqj-green-dark)}
    .jqj-totals-wrap{margin-top:20px;padding-top:18px;border-top:2px solid var(--jqj-border);display:grid;grid-template-columns:1fr minmax(380px,48%)}.jqj-totals{grid-column:2}.jqj-total-row{min-height:44px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--jqj-border);color:#445f6d;font-size:13px}.jqj-total-row.grand{min-height:50px;border-bottom:3px solid #e1e6e9;color:#173845;font-size:15px;font-weight:700}.jqj-total-row.grand strong{font-size:19px}.jqj-adjust-row{display:none;grid-template-columns:minmax(0,1fr) 115px auto;gap:7px;padding:8px 0;border-bottom:1px solid var(--jqj-border)}.jqj-adjust-row.show{display:grid}.jqj-adjust-row .jqj-control{min-height:38px;height:38px}.jqj-small-green{height:38px;padding:0 12px;border:0;border-radius:7px;background:var(--jqj-green);color:#fff;font-weight:700;font-size:11px;cursor:pointer}.jqj-total-action{border:0;background:transparent;color:var(--jqj-green-dark);font:700 12px Arial,Helvetica,sans-serif;text-decoration:underline;cursor:pointer}
    .jqj-notes{margin:20px 28px 0}.jqj-notes h2{margin:0 0 12px;color:var(--jqj-text);font-size:20px}.jqj-note-editor{border:1px solid var(--jqj-border);border-radius:8px;overflow:hidden}.jqj-note-editor textarea{width:100%;min-height:100px;padding:14px;border:0;outline:0;resize:vertical;color:#294b5d;font:13px Arial,Helvetica,sans-serif}.jqj-upload{margin-top:12px;min-height:88px;padding:13px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:7px;border:1px dashed #cbd7dd;border-radius:8px;color:#607581;background:#fff;font-size:10px}.jqj-upload.drag{border-color:#8fbd72;background:#f8fcf4}.jqj-upload button{height:34px;padding:0 12px;border:1px solid var(--jqj-border);border-radius:7px;background:#fff;color:var(--jqj-green-dark);font-weight:700;font-size:11px;cursor:pointer}.jqj-file-list{margin-top:8px;display:grid;gap:6px}.jqj-file{padding:8px 10px;display:flex;align-items:center;gap:9px;border:1px solid var(--jqj-border);border-radius:7px;color:#435f6c;font-size:11px}.jqj-file i{color:#567482}.jqj-file span{min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.jqj-related{margin-top:14px;padding-top:12px;border-top:1px solid #edf0f2}.jqj-related strong{display:block;margin-bottom:8px;color:#173845;font-size:12px}.jqj-related label{display:inline-flex;align-items:center;gap:7px;margin-right:18px;color:#405d6a;font-size:12px}.jqj-related input{width:17px;height:17px;accent-color:var(--jqj-green)}
    .jqj-sticky{position:fixed;left:var(--fieldplx-sidebar-width);right:0;bottom:0;z-index:1035;min-height:64px;padding:9px 27px;display:flex;align-items:center;justify-content:flex-end;border-top:1px solid var(--jqj-border);background:rgba(255,255,255,.98);box-shadow:0 -4px 18px rgba(0,17,49,.05)}body.fieldplx-sidebar-collapsed .jqj-sticky{left:var(--fieldplx-sidebar-collapsed-width)}.jqj-cancel,.jqj-save{min-height:38px;padding:15px;border:1px solid var(--jqj-border);border-radius:7px;background:#fff;color:var(--jqj-green-dark);font:700 12px Arial,Helvetica,sans-serif;text-decoration:none!important;cursor:pointer}.jqj-save{margin-left:8px;border-color:var(--jqj-green);background:var(--jqj-green);color:#fff}.jqj-save:hover{background:var(--jqj-green-dark)}.jqj-save:disabled{opacity:.6;cursor:not-allowed}
    .jqj-modal{position:fixed;inset:0;z-index:30000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(7,31,49,.38)}.jqj-modal.show{display:flex}.jqj-dialog{width:min(560px,calc(100vw - 28px));max-height:calc(100vh - 36px);overflow:auto;border:1px solid var(--jqj-border);border-radius:10px;background:#fff;box-shadow:0 22px 60px rgba(0,17,49,.24)}.jqj-modal-head{padding:18px 20px 12px;display:flex;align-items:center;justify-content:space-between;gap:12px}.jqj-modal-head h2{margin:0;color:var(--jqj-text);font-size:20px}.jqj-modal-body{padding:5px 20px 16px}.jqj-modal-foot{padding:12px 20px 18px;display:flex;justify-content:flex-end;gap:8px}.jqj-modal-btn{height:38px;padding:0 14px;border:1px solid var(--jqj-border);border-radius:7px;background:#fff;color:#294b5d;font-weight:700;font-size:11px;cursor:pointer}.jqj-modal-btn.primary{border-color:var(--jqj-green);background:var(--jqj-green);color:#fff}.jqj-modal-btn.clear{margin-right:auto}.jqj-modal-label{display:block;margin:0 0 6px;color:#456172;font-size:10px;font-weight:700}.jqj-field-stack{display:grid;gap:13px}.jqj-error{display:none;margin-top:5px;color:#d94a41;font-size:10px}.jqj-error.show{display:block}.jqj-type-help{margin-top:7px;color:#607581;font-size:11px;line-height:1.4}.jqj-options-box{display:none}.jqj-options-box.show{display:block}.jqj-switchline{display:flex;align-items:flex-start;gap:8px;color:#405d6a;font-size:12px}.jqj-switchline input{width:18px;height:18px;accent-color:var(--jqj-green)}.jqj-switchline small{display:block;margin-top:2px;color:#748794;font-size:10px}
    .jqj-rec-modal-row{display:grid;grid-template-columns:auto 120px 170px auto;align-items:center;gap:8px;color:#405d6a}.jqj-day-grid{margin-top:15px;display:grid;grid-template-columns:repeat(7,1fr);gap:6px}.jqj-day-btn,.jqj-month-day{height:38px;border:1px solid var(--jqj-border);border-radius:7px;background:#fff;color:#365567;font-size:11px;cursor:pointer}.jqj-day-btn.active,.jqj-month-day.active{border-color:var(--jqj-green);background:var(--jqj-green);color:#fff}.jqj-month-mode{margin-top:14px;display:flex;gap:16px}.jqj-month-panel{display:none;margin-top:12px}.jqj-month-panel.show{display:block}.jqj-month-days{display:grid;grid-template-columns:repeat(7,1fr);gap:5px}.jqj-month-day.last{grid-column:5/8}.jqj-nth-grid{display:grid;grid-template-columns:45px repeat(7,1fr);gap:5px;align-items:center}.jqj-nth-label{color:#536b79;font-size:11px}.jqj-recur-summary{margin-top:12px;padding:9px 10px;border-radius:7px;background:#f6f8f9;color:#536b79;font-size:11px}
    .jqj-toast{position:fixed;top:82px;right:18px;z-index:35000;max-width:390px;padding:10px 13px;border-radius:7px;background:#173e4f;color:#fff;opacity:0;visibility:hidden;transform:translateY(-7px);transition:.2s;font-size:11px}.jqj-toast.show{opacity:1;visibility:visible;transform:translateY(0)}.jqj-toast.success{background:#2f8d25}.jqj-toast.error{background:#c74646}.jqj-toast.warning{background:#94751d}
    .select2-container{width:100%!important}.select2-container .select2-selection--single{height:44px!important;border:1px solid var(--jqj-border)!important;border-radius:8px!important}.select2-container .select2-selection--single .select2-selection__rendered{height:42px!important;line-height:42px!important;padding-left:12px!important;color:#294b5d!important;font-size:12px!important}.select2-container .select2-selection--single .select2-selection__arrow{height:42px!important}.select2-container .select2-selection--multiple{min-height:44px!important;border:1px solid var(--jqj-border)!important;border-radius:8px!important}.select2-container--default.select2-container--focus .select2-selection--single,.select2-container--default.select2-container--focus .select2-selection--multiple{border-color:#91bd7e!important;box-shadow:0 0 0 2px rgba(47,141,37,.08)!important}.select2-dropdown{z-index:32000!important}.select2-results__option{font-size:11px!important}
    @media(max-width:1100px){.jqj-top-grid,.jqj-billing-main{grid-template-columns:1fr}.jqj-line-top{grid-template-columns:minmax(240px,1fr) 90px 120px 120px 120px 34px}.jqj-totals-wrap{grid-template-columns:1fr minmax(360px,55%)}}
    @media(max-width:991.98px){.jqj-sticky,body.fieldplx-sidebar-collapsed .jqj-sticky{left:0}.jqj-page{padding-bottom:80px}}
    @media(max-width:767.98px){.jqj-top{padding:18px 14px 22px}.jqj-section,.jqj-notes{margin-left:14px;margin-right:14px}.jqj-heading h1{font-size:21px}.jqj-meta-row{grid-template-columns:120px 1fr}.jqj-schedule-bar{align-items:flex-start;flex-wrap:wrap}.jqj-schedule-summary{width:100%;order:3;gap:12px}.jqj-create-visits{margin-left:auto}.jqj-visit{grid-template-columns:1fr}.jqj-date-rail{border-right:0;border-bottom:1px solid var(--jqj-border)}.jqj-rec-grid,.jqj-two,.jqj-end-radios{grid-template-columns:1fr}.jqj-rec-grid .full{grid-column:auto}.jqj-end-grid{grid-template-columns:1fr}.jqj-line-top{grid-template-columns:1fr 1fr}.jqj-line-top>.jqj-item-picker{grid-column:1/-1}.jqj-line textarea{grid-column:1/-1}.jqj-totals-wrap{grid-template-columns:1fr}.jqj-totals{grid-column:1}.jqj-custom-row{grid-template-columns:1fr 34px}.jqj-custom-label{grid-column:1}.jqj-custom-value{grid-column:1}.jqj-custom-row>.jqj-remove{grid-column:2;grid-row:1/3}.jqj-source{align-items:flex-start;flex-wrap:wrap}.jqj-rec-modal-row{grid-template-columns:auto 1fr 1fr}.jqj-rec-modal-row>span:last-child{display:none}}
    @media(max-width:520px){.jqj-section{padding:17px 13px}.jqj-heading{margin-bottom:14px}.jqj-meta-row{grid-template-columns:1fr;gap:5px;padding:8px 0}.jqj-line-top{grid-template-columns:1fr}.jqj-line-top>.jqj-item-picker{grid-column:auto}.jqj-totals-wrap{margin-top:16px}.jqj-sticky{padding:8px 13px}.jqj-day-grid,.jqj-month-days{grid-template-columns:repeat(4,1fr)}.jqj-nth-grid{grid-template-columns:40px repeat(4,1fr)}}


    /* ==========================================================
       Customer / Property + Product / Service composer
       Interaction reference: Add Quotation + Jobber property picker
       ========================================================== */
    .jqj-customer-col{display:block!important;min-width:0}
    .jqj-customer-shell{max-width:640px;position:relative}
    .jqj-customer-picker,.jqj-property-picker{max-width:640px}
    .jqj-property-picker{display:none;margin-top:10px}.jqj-property-picker.show{display:block}
    .jqj-customer-card{display:none;position:relative;min-height:176px;padding:20px 22px;border:1px solid var(--jqj-border);border-radius:8px;background:#fff;color:#173846}
    .jqj-customer-card.show{display:block}
    .jqj-customer-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
    .jqj-customer-card-name{display:flex;align-items:center;gap:7px;color:#113448;font-size:16px;font-weight:700;line-height:1.25}
    .jqj-customer-dot{width:7px;height:7px;border-radius:50%;background:#3496df;display:inline-block;flex:0 0 7px}
    .jqj-customer-menu-wrap{position:relative}.jqj-customer-card.locked .jqj-customer-menu-wrap{display:none}
    .jqj-customer-menu-btn{width:34px;height:30px;border:0;border-radius:6px;background:transparent;color:#274b5b;display:grid;place-items:center;cursor:pointer;font-size:19px;font-weight:700;line-height:1}
    .jqj-customer-menu-btn:hover{background:#f3f7f8}
    .jqj-customer-menu{display:none;position:absolute;right:0;top:34px;z-index:1210;width:200px;padding:6px;border:1px solid var(--jqj-border);border-radius:8px;background:#fff;box-shadow:0 12px 28px rgba(0,17,49,.15)}
    .jqj-customer-menu.show{display:block}
    .jqj-customer-menu button{width:100%;padding:10px;border:0;border-radius:6px;background:#fff;color:#36535f;text-align:left;font:13px Arial,Helvetica,sans-serif;cursor:pointer}
    .jqj-customer-menu button:hover{background:#f5faf2;color:var(--jqj-green-dark)}
    .jqj-customer-menu button.danger{color:#c74646}.jqj-customer-menu button.danger:hover{background:#fff1f1;color:#b63838}
    .jqj-customer-block{margin-top:10px}.jqj-customer-block-label{display:block;margin-bottom:3px;color:#5e7581;font-size:11px}.jqj-customer-block-value{display:block;color:#173846;font-size:13px;line-height:1.4;white-space:pre-line}
    .jqj-customer-contact{margin-top:11px;display:grid;gap:3px}.jqj-customer-contact a{width:max-content;max-width:100%;overflow:hidden;text-overflow:ellipsis;color:var(--jqj-green-dark)!important;font-size:12px;text-decoration:underline!important}
    .jqj-create-option{display:flex;align-items:center;gap:9px;color:var(--jqj-green-dark);font-size:12px;font-weight:700}.jqj-create-option i{font-size:14px}
    .jqj-property-result{display:grid;gap:2px}.jqj-property-result strong{font-size:12px;color:#193b4c}.jqj-property-result small{font-size:10px;color:#718493}
    .jqj-customer-picker .select2-container .select2-selection--single,.jqj-property-picker .select2-container .select2-selection--single{height:46px!important;border:1px solid var(--jqj-border)!important;border-radius:8px!important;background:#fff!important}
    .jqj-customer-picker .select2-selection__rendered,.jqj-property-picker .select2-selection__rendered{height:44px!important;line-height:44px!important;padding-left:13px!important;padding-right:30px!important;color:#183845!important;font-size:13px!important}.jqj-customer-picker .select2-selection__arrow,.jqj-property-picker .select2-selection__arrow{height:44px!important}

    /* Product / Service lines match Add Quotation interaction. */
    .jqj-products .jqj-lines{gap:0}.jqj-products .jqj-line{margin:0 0 14px;padding:16px 14px 15px}
    .jqj-products .jqj-line-top{display:grid;grid-template-columns:minmax(280px,1fr) 120px 170px 150px 38px;gap:10px;align-items:end}
    .jqj-item-picker{min-width:0}.jqj-item-picker .select2-container .select2-selection--single{height:48px!important;border:1px solid var(--jqj-border)!important;border-radius:7px!important;background:#fff!important}.jqj-item-picker .select2-selection__rendered{height:46px!important;line-height:46px!important;padding-left:12px!important;padding-right:28px!important;color:#183845!important;font-size:13px!important}.jqj-item-picker .select2-selection__arrow{height:46px!important}
    .jqj-price-wrap{position:relative}.jqj-cost-popover{display:none;position:absolute;z-index:1400;right:-20px;top:57px;width:230px;padding:14px;border:1px solid var(--jqj-border);border-radius:8px;background:#fff;box-shadow:0 10px 28px rgba(0,17,49,.16)}.jqj-cost-popover.show{display:grid;gap:10px}.jqj-cost-popover:before{content:"";position:absolute;top:-7px;right:72px;width:12px;height:12px;background:#fff;border-left:1px solid var(--jqj-border);border-top:1px solid var(--jqj-border);transform:rotate(45deg)}.jqj-cost-popover p{margin:0;color:#647a87;font-size:10px;line-height:1.4}
    .jqj-products .jqj-money-field input,.jqj-products .jqj-qty-field input,.jqj-products .jqj-line-total{height:48px}.jqj-products .jqj-line-total{display:flex;flex-direction:column;justify-content:center;align-items:flex-end;text-align:right}.jqj-products .jqj-line-total strong{margin-top:3px}.jqj-products .jqj-line textarea{min-height:82px;margin-top:10px}
    .jqj-catalog-result{display:flex;align-items:flex-start;gap:9px}.jqj-catalog-copy{min-width:0;flex:1}.jqj-catalog-name{display:flex;align-items:center;gap:7px;color:#173845;font-size:12px}.jqj-catalog-desc{margin-top:2px;color:#6b808b;font-size:10px}.jqj-catalog-price{margin-left:auto;white-space:nowrap;color:#173845;font-size:11px}.jqj-type-badge{display:inline-flex;padding:2px 6px;border-radius:999px;font-size:8px;font-weight:700}.jqj-type-badge.service{background:#edf7e9;color:#28701f}.jqj-type-badge.product{background:#eef6fb;color:#245d83}

    /* Quick-create modals */
    .jqj-dialog.quick{width:min(650px,calc(100vw - 28px))}.jqj-dialog.catalog{width:min(720px,calc(100vw - 28px))}.jqj-dialog.property{width:min(760px,calc(100vw - 28px))}
    .jqj-quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.jqj-quick-grid .full{grid-column:1/-1}
    .jqj-modal-field{position:relative}.jqj-modal-field label{display:block;margin:0 0 6px;color:#456172;font-size:10px;font-weight:700}.jqj-modal-field input,.jqj-modal-field select,.jqj-modal-field textarea{width:100%;min-height:43px;padding:9px 11px;border:1px solid var(--jqj-border);border-radius:7px;background:#fff;color:#163644;outline:0;font:13px Arial,Helvetica,sans-serif}.jqj-modal-field textarea{min-height:90px;resize:vertical}.jqj-modal-field input:focus,.jqj-modal-field select:focus,.jqj-modal-field textarea:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.09)}
    .jqj-cost-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}.jqj-modal-check{display:flex;align-items:center;gap:8px;margin-top:13px;color:#405d6a;font-size:12px}.jqj-modal-check input{width:18px;height:18px;accent-color:var(--jqj-green)}
    .jqj-item-image{margin-top:14px}.jqj-item-image input{display:none}.jqj-image-drop{width:100%;min-height:112px;padding:14px;border:1px dashed #c7d4da;border-radius:8px;background:#fff;color:#607986;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;cursor:pointer}.jqj-image-drop:hover,.jqj-image-drop.dragover{border-color:#91bd7e;background:#f8fcf6}.jqj-image-drop i{font-size:25px;color:var(--jqj-green)}.jqj-image-drop strong{font-size:12px;color:var(--jqj-green-dark)}.jqj-image-drop small{font-size:10px;color:#7b8d96}.jqj-image-preview{display:none;align-items:center;gap:12px;padding:9px;border:1px solid var(--jqj-border);border-radius:8px;background:#fbfcfc}.jqj-image-preview.show{display:flex}.jqj-image-preview img{width:96px;height:86px;object-fit:cover;border-radius:6px;border:1px solid #dfe6ea}.jqj-image-preview div{min-width:0;flex:1}.jqj-image-preview strong,.jqj-image-preview small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.jqj-image-preview small{margin-top:4px;color:#7b8d96}.jqj-image-remove{margin-top:8px;padding:0;border:0;background:transparent;color:#b34141;font-weight:700;font-size:11px;text-decoration:underline;cursor:pointer}

    @media(max-width:1100px){.jqj-products .jqj-line-top{grid-template-columns:minmax(230px,1fr) 100px 145px 135px 38px}}
    @media(max-width:767.98px){.jqj-quick-grid,.jqj-cost-grid{grid-template-columns:1fr}.jqj-quick-grid .full{grid-column:auto}.jqj-products .jqj-line-top{grid-template-columns:1fr 1fr}.jqj-products .jqj-item-picker{grid-column:1/-1}.jqj-products .jqj-line-top>.jqj-remove{align-self:end}.jqj-customer-card{padding:17px 16px}}
    @media(max-width:520px){.jqj-products .jqj-line-top{grid-template-columns:1fr}.jqj-products .jqj-item-picker{grid-column:auto}}



    /* ==========================================================
       FieldPlx Job Builder v4.0 - Jobber option parity corrections
       ========================================================== */
    .jqj-float.jqj-assignee{margin-top:10px;position:relative}
    .jqj-float.jqj-assignee>label,
    .jqj-float.jqj-assignee>label{z-index:4}
    .jqj-assignee .select2-container,
    #recAssignees + .select2-container{display:block!important;width:100%!important}
    .jqj-assignee .select2-container .select2-selection--multiple,
    #recAssignees + .select2-container .select2-selection--multiple{
      min-height:50px!important;height:auto!important;padding:17px 34px 5px 8px!important;
      border:1px solid var(--jqj-border)!important;border-radius:8px!important;background:#fff!important;
    }
    .jqj-assignee .select2-selection__rendered,
    #recAssignees + .select2-container .select2-selection__rendered{
      display:flex!important;align-items:center!important;flex-wrap:wrap!important;gap:5px!important;
      min-height:25px!important;margin:0!important;padding:0!important;
    }
    .jqj-assignee .select2-selection__choice,
    #recAssignees + .select2-container .select2-selection__choice{
      margin:0!important;padding:4px 24px 4px 7px!important;position:relative!important;
      border:0!important;border-radius:999px!important;background:#eef1ed!important;color:#244653!important;
      font-size:11px!important;line-height:18px!important;
    }
    .jqj-assignee .select2-selection__choice__remove,
    #recAssignees + .select2-container .select2-selection__choice__remove{
      position:absolute!important;right:5px!important;left:auto!important;top:50%!important;transform:translateY(-50%)!important;
      width:16px!important;height:16px!important;border:0!important;color:#526b75!important;background:transparent!important;
    }
    .jqj-assignee .select2-search--inline .select2-search__field,
    #recAssignees + .select2-container .select2-search--inline .select2-search__field{
      height:24px!important;margin:0!important;padding:1px 4px!important;font-size:11px!important;
    }

    /* Product / Service floating labels are anchored to their own columns. */
    .jqj-products .jqj-line-top{grid-template-columns:minmax(280px,1fr) 110px 130px 140px 150px 36px!important;align-items:end!important}
    .jqj-line-field,.jqj-qty-field,.jqj-money-field{position:relative!important;min-width:0}
    .jqj-line-field>label,.jqj-qty-field>label,.jqj-money-field>label{
      position:absolute!important;left:11px!important;top:5px!important;z-index:5!important;
      color:#61798a!important;font-size:9px!important;line-height:1!important;pointer-events:none!important;
    }
    .jqj-line-field .select2-container .select2-selection--single{
      height:50px!important;border:1px solid var(--jqj-border)!important;border-radius:8px!important;background:#fff!important;
    }
    .jqj-line-field .select2-selection__rendered{
      height:48px!important;line-height:19px!important;padding:19px 30px 7px 11px!important;color:#183845!important;font-size:13px!important;
    }
    .jqj-line-field .select2-selection__arrow{height:48px!important}
    /* Keep the selected Product / Service value vertically aligned below the floating Name label. */
    .jqj-item-picker .select2-container .select2-selection--single{position:relative!important;overflow:visible!important}
    .jqj-item-picker .select2-container .select2-selection--single .select2-selection__rendered{
      position:absolute!important;left:0!important;right:0!important;bottom:0!important;width:100%!important;height:34px!important;
      padding:7px 38px 7px 11px!important;line-height:20px!important;display:block!important;overflow:hidden!important;
      white-space:nowrap!important;text-overflow:ellipsis!important;color:#183845!important;font-size:13px!important;
    }
    .jqj-item-picker .select2-container .select2-selection--single .select2-selection__clear{
      position:absolute!important;right:25px!important;top:24px!important;z-index:8!important;margin:0!important;line-height:16px!important;
    }
    .jqj-item-picker .select2-container .select2-selection--single .select2-selection__arrow{top:1px!important;right:3px!important;height:48px!important}
    .jqj-products .jqj-qty-field input,.jqj-products .jqj-money-field input{height:50px!important;padding:18px 10px 6px!important}
    .jqj-products .jqj-line-total{height:50px!important}
    .jqj-job-number-input{max-width:260px!important;height:44px!important;font-weight:600!important;letter-spacing:.1px!important}
    .jqj-job-number-input::placeholder{color:#7a8b95!important;font-weight:500!important}

    /* Add Quotation style tax selector. */
    .jqj-tax-picker{position:relative;display:none;padding:8px 0;border-bottom:1px solid var(--jqj-border)}
    .jqj-tax-picker.show{display:block}
    .jqj-tax-selector{width:100%;min-height:44px;padding:8px 12px;display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid #9fca88;border-radius:8px;background:#fff;color:#294b5d;font:13px Arial,Helvetica,sans-serif;cursor:pointer}
    .jqj-tax-menu{display:none;position:absolute;left:0;right:0;top:56px;z-index:1900;overflow:hidden;border:1px solid var(--jqj-border);border-radius:8px;background:#fff;box-shadow:0 14px 34px rgba(0,17,49,.16)}
    .jqj-tax-menu.show{display:block}
    .jqj-tax-menu button{width:100%;padding:10px 12px;display:flex;align-items:center;justify-content:space-between;border:0;border-bottom:1px solid #eef1f3;background:#fff;color:#173845;text-align:left;cursor:pointer}
    .jqj-tax-menu button:hover{background:#f8fcf5}.jqj-tax-menu button:last-child{border-bottom:0}
    .jqj-tax-menu button strong{font-size:12px}.jqj-tax-menu button small{color:#718493;font-size:10px}
    .jqj-tax-menu .jqj-create-tax{justify-content:flex-start;color:var(--jqj-green-dark);font-weight:700;text-decoration:underline}
    .jqj-tax-empty{padding:10px 12px;color:#738692;font-size:11px}

    /* Property modal Jobber sections. */
    .jqj-dialog.property{width:min(880px,calc(100vw - 28px))}
    .jqj-property-address-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid var(--jqj-border);border-radius:8px;overflow:hidden}
    .jqj-property-address-grid .jqj-property-cell{min-height:49px;position:relative;border-bottom:1px solid var(--jqj-border);background:#fff}
    .jqj-property-address-grid .jqj-property-cell.full{grid-column:1/-1}
    .jqj-property-address-grid .jqj-property-cell:nth-last-child(-n+2){border-bottom:0}
    .jqj-property-address-grid .jqj-property-cell.left{border-right:1px solid var(--jqj-border)}
    .jqj-property-address-grid input,.jqj-property-address-grid select{width:100%;height:48px;padding:9px 13px;border:0!important;border-radius:0!important;box-shadow:none!important;background:#fff;color:#173845;font:13px Arial,Helvetica,sans-serif;outline:0}
    .jqj-property-tax{margin-top:12px}
    .jqj-property-extra{margin-top:14px;border-radius:8px;background:#f7f6f3;overflow:hidden}
    .jqj-property-extra-head{width:100%;min-height:54px;padding:0 14px;display:flex;align-items:center;justify-content:space-between;border:0;background:transparent;color:#173845;font:700 12px Arial,Helvetica,sans-serif;cursor:pointer}
    .jqj-property-extra-body{display:none;padding:3px 14px 16px}.jqj-property-extra.open .jqj-property-extra-body{display:block}
    .jqj-property-extra-copy{display:flex;align-items:center;justify-content:space-between;gap:14px;color:#677e89;font-size:11px}
    .jqj-outline-green{min-height:38px;padding:0 13px;border:1px solid #8abb73;border-radius:7px;background:#fff;color:var(--jqj-green-dark);font-weight:700;font-size:11px;cursor:pointer;white-space:nowrap}
    .jqj-property-custom-values,.jqj-property-contact-list{display:grid;gap:8px;margin-bottom:10px}
    .jqj-property-custom-row{display:grid;grid-template-columns:180px 1fr;align-items:center;gap:10px}
    .jqj-property-custom-row label{color:#405d6a;font-size:11px}.jqj-property-custom-row input,.jqj-property-custom-row select{min-height:39px;padding:8px 10px;border:1px solid var(--jqj-border);border-radius:7px;background:#fff;color:#173845;font-size:12px}
    .jqj-property-contact-chip{padding:9px 10px;display:flex;align-items:center;gap:9px;border:1px solid #e0e6e9;border-radius:7px;background:#fff;color:#31515f;font-size:11px}
    .jqj-property-contact-chip span{min-width:0;flex:1}.jqj-property-contact-chip button{border:0;background:transparent;color:#b54444;cursor:pointer}
    .jqj-contact-settings{margin-top:12px;padding:12px;border:1px solid var(--jqj-border);border-radius:8px}
    .jqj-contact-setting-row{min-height:38px;display:flex;align-items:center;justify-content:space-between;gap:15px;color:#405d6a;font-size:11px}
    .jqj-contact-setting-row input{width:18px;height:18px;accent-color:var(--jqj-green)}
    .jqj-info-box{margin:12px 0;padding:11px 12px;border-radius:7px;background:#e8f4ff;color:#3f6f90;font-size:11px}

    /* Jobber billing/payment schedule. */
    .jqj-jobber-billing .jqj-section-head{margin-bottom:15px}.jqj-billing-check{display:flex;align-items:center;gap:9px;color:#405d6a;font-size:12px}.jqj-billing-check input{width:18px;height:18px;accent-color:var(--jqj-green)}
    .jqj-billing-divider{height:1px;margin:20px 0;background:var(--jqj-border)}
    .jqj-billing-split-head{display:flex;align-items:center;justify-content:space-between;gap:18px}.jqj-split-toggle{display:none;align-items:center;gap:10px;color:#405d6a;font-size:11px}.jqj-split-toggle.show{display:flex}
    .jqj-segment{display:flex;border:1px solid var(--jqj-border);border-radius:7px;overflow:hidden}.jqj-segment button{min-width:78px;height:34px;border:0;border-right:1px solid var(--jqj-border);background:#fff;color:#365567;font-weight:700;cursor:pointer}.jqj-segment button:last-child{border-right:0}.jqj-segment button.active{background:#f8fff5;color:var(--jqj-green-dark);box-shadow:inset 0 0 0 1px #75ad61}
    .jqj-payment-panel{display:none;margin-top:18px}.jqj-payment-panel.show{display:block}
    .jqj-payment-summary{padding:18px;border-radius:8px;background:#faf9f7}.jqj-payment-summary strong{color:#173845}.jqj-payment-sub{margin-top:5px;color:#607581;font-size:10px}.jqj-payment-track{height:9px;margin:15px 0 8px;overflow:hidden;border-radius:999px;background:#dcdedb}.jqj-payment-track-fill{height:100%;width:0;background:var(--jqj-green);transition:width .2s}.jqj-payment-legend{display:flex;align-items:center;gap:18px;flex-wrap:wrap;color:#4b6674;font-size:10px}
    .jqj-payment-table{width:100%;margin-top:14px;border-collapse:collapse}.jqj-payment-table th,.jqj-payment-table td{padding:10px 8px;border-bottom:1px solid var(--jqj-border);text-align:left;color:#405d6a;font-size:10px}.jqj-payment-table th{color:#173845;font-weight:700}.jqj-payment-table input{width:100%;min-height:38px;padding:7px 9px;border:1px solid var(--jqj-border);border-radius:7px;color:#173845;font-size:11px}.jqj-upcoming{display:inline-flex;padding:4px 8px;border-radius:999px;background:#eef3f4;color:#45636f}.jqj-payment-create{min-height:32px;padding:0 10px;border:0;border-radius:6px;background:#eceeed;color:#9aa4a8;font-weight:700;cursor:not-allowed}.jqj-payment-remove{border:0;background:transparent;color:#244a59;cursor:pointer}.jqj-add-payment{margin-top:12px;border:0;background:transparent;color:var(--jqj-green-dark);font-weight:700;font-size:11px;text-decoration:underline;cursor:pointer}

    /* Notes @ mention menu. */
    .jqj-notes{position:relative!important;overflow:visible!important}
    .jqj-note-editor{position:relative!important;z-index:10!important;overflow:visible!important;background:#fff}
    .jqj-note-editor:focus-within{z-index:5200!important}
    .jqj-note-editor textarea{position:relative!important;z-index:1!important;border-radius:8px!important;background:#fff!important}
    .jqj-mention-menu{display:none;position:absolute;left:10px;top:58px;z-index:6000!important;width:min(360px,calc(100% - 20px));max-height:240px;overflow:auto;border:1px solid var(--jqj-border);border-radius:8px;background:#fff;box-shadow:0 16px 38px rgba(0,17,49,.22)}
    .jqj-mention-menu.show{display:block}.jqj-mention-menu button{width:100%;padding:9px 11px;display:flex;align-items:center;gap:8px;border:0;border-bottom:1px solid #eef1f3;background:#fff;color:#31515f;text-align:left;cursor:pointer}.jqj-mention-menu button:hover{background:#f7fbf4}.jqj-mention-menu button:last-child{border-bottom:0}.jqj-mention-avatar{width:25px;height:25px;flex:0 0 25px;display:grid;place-items:center;border-radius:50%;background:#173e4f;color:#fff;font-size:9px;font-weight:700}.jqj-mention-empty{padding:12px;color:#748793;font-size:11px}.jqj-mention-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:7px}.jqj-mention-tag{display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border-radius:999px;background:#eef6e8;color:#315b28;font-size:10px}

    @media(max-width:1100px){.jqj-products .jqj-line-top{grid-template-columns:minmax(230px,1fr) 95px 115px 125px 135px 34px!important}}
    @media(max-width:767.98px){.jqj-products .jqj-line-top{grid-template-columns:1fr 1fr!important}.jqj-products .jqj-line-field:first-child{grid-column:1/-1}.jqj-property-address-grid{grid-template-columns:1fr}.jqj-property-address-grid .jqj-property-cell.full{grid-column:auto}.jqj-property-address-grid .jqj-property-cell.left{border-right:0}.jqj-property-address-grid .jqj-property-cell{border-bottom:1px solid var(--jqj-border)!important}.jqj-property-address-grid .jqj-property-cell:last-child{border-bottom:0!important}.jqj-property-custom-row{grid-template-columns:1fr}.jqj-billing-split-head{align-items:flex-start;flex-direction:column}.jqj-payment-table{display:block;overflow-x:auto;white-space:nowrap}}



    /* ==========================================================
       Job checklist builder + Request checklist inheritance v4.3
       Mirrors the Add Request checklist workflow while retaining
       the FieldPlx Add Quotation visual language.
       ========================================================== */
    .jqj-checklist-source-note{margin:8px 0 12px;padding:11px 12px;display:flex;align-items:flex-start;gap:9px;border:1px solid #cfe2bf;border-radius:8px;background:#f6fbf2;color:#365561}
    .jqj-checklist-source-note[hidden]{display:none!important}.jqj-checklist-source-note>i{margin-top:1px;color:var(--jqj-green-dark);font-size:16px}.jqj-checklist-source-note div{min-width:0}.jqj-checklist-source-note strong,.jqj-checklist-source-note span{display:block}.jqj-checklist-source-note strong{color:#173845;font-size:12px}.jqj-checklist-source-note span{margin-top:3px;color:#647a87;font-size:10px;line-height:1.4}
    .jqj-checklist-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 0 11px}.jqj-checklist-new-btn{height:34px;padding:0 12px;display:inline-flex;align-items:center;gap:6px;border:1px solid var(--jqj-green);border-radius:7px;background:#fff;color:var(--jqj-green-dark);font:700 11px Arial,Helvetica,sans-serif;cursor:pointer}.jqj-checklist-new-btn:hover{background:var(--jqj-green-soft)}
    .jqj-template-list{display:grid;gap:10px}.jqj-checklist-card{position:relative;border:1px solid var(--jqj-border);border-radius:8px;background:#fff;overflow:hidden}.jqj-checklist-card.from-request{border-color:#b9d7aa;box-shadow:0 0 0 1px rgba(47,141,37,.04)}.jqj-checklist-card-head{min-height:54px;padding:10px 12px;display:flex;align-items:center;gap:10px}.jqj-checklist-card-check{width:17px;height:17px;flex:0 0 17px;accent-color:var(--jqj-green)}.jqj-checklist-card-icon{width:32px;height:32px;flex:0 0 32px;display:grid;place-items:center;border-radius:7px;background:#f1f6ef;color:var(--jqj-green-dark);font-size:15px}.jqj-checklist-card-title{min-width:0;flex:1}.jqj-checklist-card-title strong,.jqj-checklist-card-title small{display:block}.jqj-checklist-card-title strong{overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#173845;font-size:12px}.jqj-checklist-card-title small{margin-top:3px;color:#718493;font-size:10px}.jqj-checklist-source-badge{display:inline-flex;margin-left:7px;padding:2px 6px;border-radius:999px;background:#edf7e9;color:#28701f;font-size:8px;font-weight:700;vertical-align:middle}.jqj-checklist-preview-toggle{width:30px;height:30px;flex:0 0 30px;border:0;border-radius:6px;background:transparent;color:#4f6875;cursor:pointer}.jqj-checklist-preview-toggle:hover{background:#f3f6f7}.jqj-checklist-preview{display:none;padding:0 12px 12px;border-top:1px solid #eef1f3;background:#fbfcfc}.jqj-checklist-preview.show{display:block}.jqj-checklist-section-preview{padding-top:11px}.jqj-checklist-section-preview+.jqj-checklist-section-preview{margin-top:8px;border-top:1px solid #edf0f1}.jqj-checklist-section-preview h4{margin:0 0 7px;color:#173845;font-size:11px}.jqj-checklist-question-preview{min-height:30px;padding:5px 0;display:flex;align-items:center;gap:8px;color:#405d6a;font-size:11px}.jqj-checklist-question-preview i{width:18px;color:#6c828e;text-align:center}.jqj-checklist-question-preview .type{margin-left:auto;padding:2px 6px;border-radius:999px;background:#f0f2f3;color:#627986;font-size:8px}.jqj-checklist-question-preview .required{color:#b64e4e;font-size:9px}
    .jqj-checklist-draft{margin-top:11px;padding:10px 12px;display:flex;align-items:center;gap:10px;border:1px dashed #a9c997;border-radius:8px;background:#f8fcf6}.jqj-checklist-draft[hidden]{display:none!important}.jqj-checklist-draft-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:7px;background:#eaf5e4;color:var(--jqj-green-dark)}.jqj-checklist-draft-copy{min-width:0;flex:1}.jqj-checklist-draft-copy strong,.jqj-checklist-draft-copy span{display:block}.jqj-checklist-draft-copy strong{color:#173845;font-size:12px}.jqj-checklist-draft-copy span{margin-top:2px;color:#718493;font-size:10px}.jqj-checklist-draft-action,.jqj-checklist-draft-remove{border:0;background:transparent;cursor:pointer}.jqj-checklist-draft-action{color:var(--jqj-green-dark);font-size:10px;font-weight:700;text-decoration:underline}.jqj-checklist-draft-remove{width:30px;height:30px;color:#b34c4c}

    .jb-modal{position:fixed;inset:0;z-index:30000;padding:16px;display:none;align-items:center;justify-content:center;background:rgba(7,31,49,.42)}.jb-modal.show{display:flex}.jb-dialog{width:min(1450px,98vw);height:min(860px,96vh);display:flex;flex-direction:column;overflow:hidden;border:1px solid #d8e0e4;border-radius:9px;background:#fff;box-shadow:0 24px 70px rgba(0,17,49,.24)}.jb-modal-head{height:66px;flex:0 0 66px;padding:0 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--jqj-border);background:#fff}.jb-modal-head h2{margin:0;color:#0b3142;font-size:20px}.jb-modal-actions{margin-left:auto;display:flex;gap:8px}.jb-modal-body{min-height:0;flex:1;display:grid;grid-template-columns:minmax(0,1fr) 330px;background:#f1f1ed}.jb-builder-canvas{min-width:0;overflow:auto;padding:16px}.jb-builder-paper{max-width:900px;margin:auto;padding:13px;border-radius:8px;background:#fff}.jb-builder-section{margin-bottom:12px;overflow:hidden;border:1px solid var(--jqj-border);border-radius:7px;background:#fff}.jb-builder-sec-head{padding:10px;display:flex;align-items:center;border-bottom:1px solid var(--jqj-border)}.jb-builder-sec-head input{min-width:0;flex:1;height:38px;padding:7px 9px;border:0;border-radius:6px;outline:0;background:#fff;color:#183548;font:700 12px Arial,Helvetica,sans-serif}.jb-builder-sec-head input:focus{background:#f8fbf6}.jb-builder-question{padding:11px 12px;background:#fff}.jb-builder-question+.jb-builder-question{border-top:1px solid #eef0f1}.jb-q-top{display:grid;grid-template-columns:minmax(0,1fr) 190px 32px;gap:8px}.jb-q-top input,.jb-q-top select,.jb-option-row input,.jb-builder-side input{height:39px;padding:8px 10px;border:1px solid var(--jqj-border);border-radius:7px;background:#fff;color:#294b5d;font:12px Arial,Helvetica,sans-serif;outline:0}.jb-q-top input:focus,.jb-q-top select:focus,.jb-option-row input:focus,.jb-builder-side input:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}.jb-options{padding:8px 0 0 30px;display:grid;gap:7px}.jb-option-row{display:grid;grid-template-columns:22px minmax(0,1fr) 32px;gap:7px;align-items:center;color:#627986;font-size:10px}.jb-q-foot{margin-top:8px;display:flex;align-items:center;gap:10px;color:#4f6875;font-size:11px}.jb-q-foot input{width:16px;height:16px;accent-color:var(--jqj-green)}.jb-builder-side{overflow:auto;padding:18px;border-left:1px solid var(--jqj-border);background:#fff}.jb-builder-side h3{margin:0 0 16px;color:#173845;font-size:16px}.jb-form-title-wrap{margin-bottom:18px}.jb-form-title-wrap label{display:block;margin-bottom:6px;color:#607688;font-size:10px}.jb-contents-title{font-size:13px!important}.jb-palette{display:grid;gap:7px}.jb-palette button{padding:6px;display:flex;align-items:center;gap:10px;border:0;border-radius:6px;background:#fff;color:#294b5d;text-align:left;font:12px Arial,Helvetica,sans-serif;cursor:pointer}.jb-palette button:hover{background:#f0f4ee}.jb-palette i{width:34px;height:34px;display:grid;place-items:center;border-radius:7px;background:#efeee9;color:#31515f;font-size:16px}.jb-icon-btn{width:32px;height:32px;padding:0;border:0;border-radius:6px;background:#fff;color:#657d88;cursor:pointer}.jb-icon-btn:hover{background:#f4f5f5;color:#b34c4c}.jb-modal-foot{height:60px;flex:0 0 60px;padding:0 18px;display:flex;align-items:center;justify-content:flex-end;gap:8px;border-top:1px solid var(--jqj-border);background:#fff}.jb-cancel,.jb-save{min-height:38px;padding:8px 15px;border-radius:7px;font:700 11px Arial,Helvetica,sans-serif;cursor:pointer}.jb-cancel{border:1px solid var(--jqj-border);background:#fff;color:var(--jqj-green-dark)}.jb-save{border:1px solid var(--jqj-green);background:var(--jqj-green);color:#fff}.jb-builder-link{padding:0;border:0;background:transparent;color:var(--jqj-green-dark);font:700 11px Arial,Helvetica,sans-serif;text-decoration:underline;cursor:pointer}
    @media(max-width:991.98px){.jb-modal-body{grid-template-columns:1fr}.jb-builder-side{display:none}.jb-dialog{width:calc(100vw - 24px);height:calc(100vh - 24px)}}
    @media(max-width:575.98px){.jb-modal{padding:6px}.jb-dialog{width:100%;height:100%;border-radius:5px}.jb-q-top{grid-template-columns:1fr}.jb-q-top .jb-icon-btn{justify-self:end}.jqj-checklist-card-head{align-items:flex-start}}

</style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<main class="fieldplx-main-content"><div class="fieldplx-content-wrapper"><div class="jqj-page">
<form id="jobForm" enctype="multipart/form-data">
  <input type="hidden" name="job_id" id="jobId" value="<?= (int)$jobId ?>">
  <input type="hidden" name="job_no_auto" id="jobNumberAuto" value="<?= $jobId > 0 ? '0' : '1' ?>">
  <input type="hidden" name="request_id" id="requestId" value="<?= (int)$requestId ?>">
  <input type="hidden" name="job_source" id="jobSource" value="<?= $quoteId > 0 ? 'quotation' : ($requestId > 0 ? 'request' : 'direct') ?>">
  <input type="hidden" name="quote_id" id="quoteId" value="<?= (int)$quoteId ?>">
  <input type="hidden" name="location_id" id="directLocationId" value="<?= (int)$locationId ?>">
  <input type="hidden" name="direct_branch_id" id="directBranchId" value="">
  <input type="hidden" name="direct_product_service_id" id="directServiceId" value="<?= (int)$serviceId ?>">
  <input type="hidden" name="product_service_id" id="jobServiceId" value="<?= (int)$serviceId ?>">
  <input type="hidden" name="assignment_mode" id="assignmentMode" value="multiple_users">
  <input type="hidden" name="assignment_completion_mode" id="completionMode" value="primary_only">
  <input type="hidden" name="status" id="status" value="scheduled">
  <input type="hidden" name="priority" id="priority" value="normal">
  <input type="hidden" name="schedule_json" id="scheduleJson">
  <input type="hidden" name="billing_schedule_json" id="billingScheduleJson">
  <input type="hidden" name="new_checklist_json" id="newChecklistJson">
  <input type="hidden" name="line_items_json" id="lineItemsJson">
  <input type="hidden" name="custom_fields_json" id="customFieldsJson" value="[]">
  <input type="hidden" name="description" id="description" value="">
  <div id="assigneeHidden"></div>

  <section class="jqj-top">
    <div class="jqj-heading"><span class="jqj-heading-icon"><i class="bi bi-hammer"></i></span><h1 id="pageTitle"><?= $jobId > 0 ? 'Edit Job' : 'New Job' ?></h1></div>
    <div class="jqj-source" id="sourceBanner" hidden><span class="jqj-source-icon"><i class="bi bi-link-45deg"></i></span><div class="jqj-source-copy"><strong id="sourceTitle"></strong><span id="sourceDescription"></span></div><a href="#" id="sourceLink">View source</a></div>
    <input class="jqj-title" type="text" name="title" id="title" maxlength="190" placeholder="Title" required>
    <div class="jqj-top-grid">
      <div class="jqj-customer-col">
        <div class="jqj-customer-shell">
          <div class="jqj-customer-picker" id="clientPickerWrap"><select name="client_id" id="directClientId" required><option value=""></option></select></div>
          <div class="jqj-customer-card" id="selectedClientCard">
            <div class="jqj-customer-card-head">
              <div class="jqj-customer-card-name"><span id="clientCardName">Customer</span><span class="jqj-customer-dot" aria-hidden="true"></span></div>
              <div class="jqj-customer-menu-wrap"><button type="button" class="jqj-customer-menu-btn" id="clientMenuButton" aria-label="Customer options">...</button><div class="jqj-customer-menu" id="clientMenu"><button type="button" id="changePropertyButton"><i class="bi bi-house"></i>&nbsp; Change property</button><button type="button" class="danger" id="changeClientButton"><i class="bi bi-x-lg"></i>&nbsp; Change customer</button></div></div>
            </div>
            <div class="jqj-customer-block"><span class="jqj-customer-block-label">Billing Address</span><span class="jqj-customer-block-value" id="clientBillingAddress">-</span></div>
            <div class="jqj-customer-block"><span class="jqj-customer-block-label">Property Address</span><span class="jqj-customer-block-value" id="clientPropertyAddress">-</span></div>
            <div class="jqj-customer-contact"><a href="#" id="clientPhoneLink" style="display:none"></a><a href="#" id="clientEmailLink" style="display:none"></a></div>
          </div>
          <div class="jqj-property-picker" id="locationPickerWrap"><select id="serviceLocationSelect"><option value=""></option></select></div>
        </div>
      </div>
      <div class="jqj-meta">
        <div class="jqj-meta-row"><span class="jqj-meta-label">Job #</span><span class="jqj-meta-value"><span class="jqj-job-number-wrap"><input class="jqj-control jqj-job-number-input" type="text" name="job_no" id="jobNumberInput" maxlength="80" placeholder="Loading..." autocomplete="off" spellcheck="false"><small class="jqj-job-number-state" id="jobNumberState">Auto</small></span></span></div>
        <div class="jqj-meta-row"><span class="jqj-meta-label">Priority</span><span class="jqj-meta-value"><select class="jqj-control" id="priorityEditor"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></span></div>
        <div class="jqj-meta-row"><span class="jqj-meta-label">Customize</span><span class="jqj-meta-value"><button type="button" class="jqj-add-field" id="addCustomField"><i class="bi bi-plus-lg"></i> Add Field</button></span></div>
      </div>
    </div>
    <div class="jqj-custom-list" id="customFields"></div>
  </section>

  <section class="jqj-section">
    <div class="jqj-section-head"><div><h2>Visits</h2><p>Create one-off visits or a recurring schedule and assign the team.</p></div></div>
    <div class="jqj-schedule-bar">
      <div><div class="jqj-cap">Schedule</div><div class="jqj-tabs"><button type="button" class="jqj-tab active" data-mode="one_off">One-off</button><button type="button" class="jqj-tab" data-mode="recurring">Recurring</button></div></div>
      <div class="jqj-schedule-summary"><span id="scheduleRange"></span><span id="visitCount">1 visit</span><span id="repeatSummary"></span></div>
      <button type="button" class="jqj-create-visits" id="createVisits"><i class="bi bi-pencil"></i> Create Visits</button>
    </div>
    <div id="oneOffPanel"><div class="jqj-visits" id="visits"></div><button type="button" class="jqj-add-visit" id="addVisit"><i class="bi bi-plus-lg"></i> Add a Visit</button></div>
    <div class="jqj-recurring" id="recurringPanel">
      <div class="jqj-rec-grid">
        <div class="jqj-float full"><label>Start date</label><input type="date" id="recStartDate"></div>
        <div class="jqj-float"><label>Start time</label><input type="time" id="recStartTime"></div><div class="jqj-float"><label>End time</label><input type="time" id="recEndTime"></div>
        <label class="jqj-check full"><input type="checkbox" id="recAnytime"> Anytime</label>
        <div class="jqj-float full"><label>Repeats</label><select id="recPreset"><option value="daily">Daily</option><option value="weekly">Weekly on selected day</option><option value="biweekly">Every 2 weeks</option><option value="monthly">Monthly on selected day</option><option value="as_needed">As needed — no reminders</option><option value="custom">Custom schedule...</option></select></div>
        <div class="jqj-repeat-summary full" id="recurrenceSummary"></div>
        <div class="jqj-end-radios full"><label class="jqj-radio"><input type="radio" name="recEndMode" value="after_duration" checked> Ends after</label><label class="jqj-radio"><input type="radio" name="recEndMode" value="on_date"> Ends on</label></div>
        <div class="jqj-end-grid full"><div class="jqj-float"><label>Ends after</label><input type="number" id="recEndValue" min="1" value="6"></div><div class="jqj-float"><label>Unit</label><select id="recEndUnit"><option value="days">Days</option><option value="weeks">Weeks</option><option value="months" selected>Months</option><option value="years">Years</option></select></div><div class="jqj-float"><label>Ends on</label><input type="date" id="recEndDate" disabled></div></div>
        <div class="jqj-float jqj-assignee full"><label>Assigned</label><select multiple id="recAssignees"></select></div>
        <label class="jqj-check jqj-email-team full"><input type="checkbox" id="emailTeamRecurring"> Email team about assignment</label>
        <div class="jqj-float full"><label>Visit instructions</label><textarea id="recInstructions"></textarea></div>
      </div>
    </div>
    <div class="jqj-checklists">
      <button type="button" class="jqj-collapse-btn" id="checklistToggle"><span>Checklists</span><i class="bi bi-chevron-down"></i></button>
      <div class="jqj-checklist-body" id="checklistBody">
        <div class="jqj-checklist-source-note" id="checklistSourceNote" hidden>
          <i class="bi bi-link-45deg"></i>
          <div><strong id="checklistSourceTitle">Request checklist</strong><span>The checklist created on the Request is selected automatically and will be copied to this Job.</span></div>
        </div>
        <div class="jqj-checklist-actions">
          <button type="button" class="jqj-text-btn" id="checklistSelectAll">Select all</button>
          <button type="button" class="jqj-checklist-new-btn" id="newChecklist"><i class="bi bi-plus-lg"></i> New checklist</button>
        </div>
        <div class="jqj-template-list" id="checklistTemplates"></div>
        <div class="jqj-checklist-draft" id="checklistDraftSummary" hidden>
          <div class="jqj-checklist-draft-icon"><i class="bi bi-clipboard2-check"></i></div>
          <div class="jqj-checklist-draft-copy"><strong id="checklistDraftName">New checklist</strong><span id="checklistDraftMeta">0 questions</span></div>
          <button type="button" class="jqj-checklist-draft-action" id="editChecklistDraft">Edit</button>
          <button type="button" class="jqj-checklist-draft-remove" id="removeChecklistDraft" title="Remove checklist"><i class="bi bi-trash"></i></button>
        </div>
      </div>
    </div>
  </section>

  <section class="jqj-section jqj-jobber-billing">
    <div class="jqj-section-head"><div><h2>Billing</h2></div></div>
    <label class="jqj-billing-check"><input type="checkbox" name="remind_to_invoice_on_close" id="remindInvoiceOnClose" value="1" checked> <span>Remind me to invoice when I close the job</span></label>
    <div class="jqj-billing-divider"></div>
    <div class="jqj-billing-split-head">
      <label class="jqj-billing-check"><input type="checkbox" name="split_payment_schedule" id="splitPaymentSchedule" value="1"> <span>Split into multiple invoices with a payment schedule</span></label>
      <div class="jqj-split-toggle" id="splitTypeWrap"><span>Split payments by</span><div class="jqj-segment"><button type="button" class="active" data-split-type="percentage">%</button><button type="button" data-split-type="amount" id="splitCurrencyButton">₹</button></div></div>
    </div>
    <div class="jqj-payment-panel" id="paymentSchedulePanel">
      <div class="jqj-payment-summary"><div><strong>Total: <span id="paymentScheduleTotal">₹0.00</span></strong></div><div class="jqj-payment-sub" id="paymentScheduleSub">₹0.00 subtotal - ₹0.00 discount + ₹0.00 taxes</div><div class="jqj-payment-track"><div class="jqj-payment-track-fill" id="paymentTrackFill"></div></div><div class="jqj-payment-legend"><span>Paid: 0.00%</span><span>Awaiting Payment: 0.00%</span><span>Draft: 0.00%</span><span id="paymentRemainingLabel">Remaining: 100.00%</span></div></div>
      <table class="jqj-payment-table"><thead><tr><th>Invoice</th><th>Due date</th><th>Status</th><th id="paymentValueHeading">%</th><th>Description</th><th>Total</th><th>Balance</th><th></th></tr></thead><tbody id="paymentScheduleRows"></tbody></table>
      <button type="button" class="jqj-add-payment" id="addPaymentRow">Add Invoice to Payment Schedule</button>
    </div>
    <input type="hidden" name="billing_type" id="billingType" value="fixed_price">
    <input type="hidden" name="invoice_frequency" id="invoiceFrequency" value="job_completion">
    <input type="hidden" name="automatic_payments_enabled" id="automaticPayments" value="0">
    <input type="hidden" name="fixed_invoice_amount" id="fixedInvoiceAmount" value="0">
    <input type="hidden" name="payment_split_type" id="paymentSplitType" value="percentage">
    <input type="hidden" name="payment_schedule_json" id="paymentScheduleJson" value="[]">
  </section>

  <section class="jqj-section jqj-products">
    <div class="jqj-section-head"><div><h2>Product / Service</h2><p id="productSourceHint">Add the products and services required for this job.</p></div></div>
    <div class="jqj-lines" id="lineItems"></div>
    <button type="button" class="jqj-add-line" id="addLine"><i class="bi bi-plus-lg"></i> Add Line Item</button>
    <input type="hidden" name="discount_type" id="discountType" value=""><input type="hidden" name="discount_value" id="discountValue" value="0">
    <div class="jqj-totals-wrap"><div></div><div class="jqj-totals">
      <div class="jqj-total-row"><span>Subtotal</span><strong id="subtotal">0.00</strong></div>
      <div class="jqj-total-row"><span>Discount</span><span><strong id="discountTotal">0.00</strong> <button type="button" class="jqj-total-action" id="discountAction">Add Discount</button></span></div>
      <div class="jqj-adjust-row" id="discountEditor"><select class="jqj-control" id="discountTypeEditor"><option value="fixed">Amount</option><option value="percentage">Percentage</option></select><input class="jqj-control" id="discountValueEditor" type="number" min="0" step="0.01" value="0"><button type="button" class="jqj-small-green" id="applyDiscount">Apply</button></div>
      <div class="jqj-total-row"><span>Tax</span><span><strong id="taxTotal">0.00</strong> <button type="button" class="jqj-total-action" id="taxAction">Add Tax</button></span></div>
      <div class="jqj-tax-picker" id="taxEditor"><button type="button" class="jqj-tax-selector" id="taxSelectorButton"><span id="taxSelectorText">Select tax rate</span><i class="bi bi-chevron-down" id="taxSelectorChevron"></i></button><div class="jqj-tax-menu" id="taxMenu"></div></div>
      <div class="jqj-total-row grand"><span>Total price</span><strong id="grandTotal">0.00</strong></div>
      <div class="jqj-total-row"><span>Total cost</span><strong id="costTotal">0.00</strong></div>
    </div></div>
  </section>

  <section class="jqj-notes"><h2>Notes</h2><div class="jqj-note-editor"><textarea name="internal_note" id="internalNote" maxlength="10000" autocomplete="off" placeholder="Use @ in notes to mention your team"></textarea><div class="jqj-mention-menu" id="noteMentionMenu" role="listbox"></div></div><input type="hidden" name="note_mentions_json" id="noteMentionsJson" value="[]"><div class="jqj-mention-tags" id="noteMentionTags"></div><div class="jqj-upload" id="noteDrop"><button type="button" id="pickFiles">Attach files &amp; photos</button><span>Select or drag files here to upload</span><input type="file" id="jobAttachments" name="job_attachments[]" multiple hidden accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"></div><div class="jqj-file-list" id="fileList"></div><div class="jqj-related"><strong>Link to related</strong><label><input type="checkbox" name="link_notes_to_invoices" value="1" checked> Invoices</label></div></section>
</form>
</div></div></main></div>

<div class="jqj-sticky"><a class="jqj-cancel" href="jobs" id="cancelLink">Cancel</a><button type="submit" form="jobForm" class="jqj-save" id="saveJob">Save Job</button></div>

<!-- Quick customer modal -->
<div class="jqj-modal" id="customerModal"><div class="jqj-dialog quick"><div class="jqj-modal-head"><h2>Create Customer</h2><button type="button" class="jqj-icon" data-close="customerModal"><i class="bi bi-x-lg"></i></button></div><div class="jqj-modal-body"><div class="jqj-quick-grid"><div class="jqj-modal-field full"><label>Customer name</label><input id="newCustomerName" type="text" maxlength="190"></div><div class="jqj-modal-field full"><label>Company name</label><input id="newCustomerCompany" type="text" maxlength="190"></div><div class="jqj-modal-field"><label>Phone</label><input id="newCustomerPhone" type="text" maxlength="50"></div><div class="jqj-modal-field"><label>Email</label><input id="newCustomerEmail" type="email" maxlength="190"></div></div></div><div class="jqj-modal-foot"><button type="button" class="jqj-modal-btn" data-close="customerModal">Cancel</button><button type="button" class="jqj-modal-btn primary" id="createCustomerButton">Create Customer</button></div></div></div>

<!-- Jobber-style Add Property modal backed by client_locations -->
<div class="jqj-modal" id="propertyModal"><div class="jqj-dialog property"><div class="jqj-modal-head"><h2>Add property</h2><button type="button" class="jqj-icon" data-close="propertyModal"><i class="bi bi-x-lg"></i></button></div><div class="jqj-modal-body">
  <div class="jqj-property-address-grid">
    <div class="jqj-property-cell full"><input id="newPropertyName" type="text" maxlength="190" placeholder="Property name"></div>
    <div class="jqj-property-cell full"><input id="newPropertyStreet1" type="text" maxlength="255" placeholder="Street 1"></div>
    <div class="jqj-property-cell full"><input id="newPropertyStreet2" type="text" maxlength="255" placeholder="Street 2"></div>
    <div class="jqj-property-cell left"><input id="newPropertyCity" type="text" maxlength="120" placeholder="City"></div>
    <div class="jqj-property-cell"><input id="newPropertyState" type="text" maxlength="120" placeholder="Province / State"></div>
    <div class="jqj-property-cell left"><input id="newPropertyPostal" type="text" maxlength="40" placeholder="Postal code"></div>
    <div class="jqj-property-cell"><select id="newPropertyCountry"><option value="">Select a country</option></select></div>
  </div>
  <div class="jqj-modal-field jqj-property-tax"><select id="newPropertyTaxRate"><option value="">No tax rate created</option></select></div>
  <div class="jqj-property-extra open" id="propertyDetailsSection"><button type="button" class="jqj-property-extra-head" data-property-section="propertyDetailsSection"><span>Property details</span><i class="bi bi-chevron-up"></i></button><div class="jqj-property-extra-body"><div class="jqj-property-custom-values" id="propertyCustomValues"></div><div class="jqj-property-extra-copy"><span>Create custom fields to track additional details</span><button type="button" class="jqj-outline-green" id="openPropertyCustomField">Add Custom Field</button></div></div></div>
  <div class="jqj-property-extra open" id="propertyContactsSection"><button type="button" class="jqj-property-extra-head" data-property-section="propertyContactsSection"><span>Property contacts</span><i class="bi bi-chevron-up"></i></button><div class="jqj-property-extra-body"><div class="jqj-property-contact-list" id="propertyContactList"></div><div class="jqj-property-extra-copy"><span>For contacts with access limited to this property, e.g., tenants.</span><button type="button" class="jqj-outline-green" id="openPropertyContact">Add Contact</button></div></div></div>
  <label class="jqj-modal-check"><input id="newPropertyPrimary" type="checkbox"><span>Use as primary / billing location</span></label>
</div><div class="jqj-modal-foot"><button type="button" class="jqj-modal-btn" data-close="propertyModal">Cancel</button><button type="button" class="jqj-modal-btn primary" id="createPropertyButton">Add Property</button></div></div></div>

<!-- Add Product / Service modal -->
<div class="jqj-modal" id="catalogItemModal"><div class="jqj-dialog catalog"><div class="jqj-modal-head"><h2>Add Product / Service</h2><button type="button" class="jqj-icon" data-close="catalogItemModal"><i class="bi bi-x-lg"></i></button></div><div class="jqj-modal-body"><div class="jqj-field-stack"><div class="jqj-modal-field"><label>Item type</label><select id="newItemType"><option value="service">Service</option><option value="product">Product</option></select></div><div class="jqj-modal-field"><label>Name</label><input id="newItemName" type="text" maxlength="190"></div><div class="jqj-modal-field"><label>Description</label><textarea id="newItemDescription"></textarea></div><div class="jqj-cost-grid"><div class="jqj-modal-field"><label>Unit cost</label><input id="newItemUnitCost" type="number" min="0" step="0.01" value="0.00"></div><div class="jqj-modal-field"><label>Markup (%)</label><input id="newItemMarkup" type="number" min="0" step="0.01" value="0"></div><div class="jqj-modal-field"><label>Unit price</label><input id="newItemUnitPrice" type="number" min="0" step="0.01" value="0.00"></div></div><div class="jqj-modal-field"><label>Tax rate</label><select id="newItemTaxRate"><option value="">No tax</option></select></div></div><div class="jqj-item-image"><input id="newItemImage" type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"><button class="jqj-image-drop" id="newItemImagePick" type="button"><i class="bi bi-image"></i><strong>Add product / service image</strong><small>JPG, PNG or WEBP up to 4 MB</small></button><div class="jqj-image-preview" id="newItemImagePreview"><img id="newItemImagePreviewImg" src="" alt="Item image preview"><div><strong id="newItemImageName"></strong><small id="newItemImageSize"></small><button class="jqj-image-remove" id="newItemImageRemove" type="button">Remove image</button></div></div></div><label class="jqj-modal-check"><input id="newItemTaxExempt" type="checkbox"><span>Exempt from Tax</span></label></div><div class="jqj-modal-foot"><button type="button" class="jqj-modal-btn" data-close="catalogItemModal">Cancel</button><button type="button" class="jqj-modal-btn primary" id="createCatalogItemButton">Create</button></div></div></div>

<!-- Tax rate modal -->
<div class="jqj-modal" id="taxRateModal"><div class="jqj-dialog quick"><div class="jqj-modal-head"><h2>Create tax rate</h2><button type="button" class="jqj-icon" data-close="taxRateModal"><i class="bi bi-x-lg"></i></button></div><div class="jqj-modal-body"><div class="jqj-field-stack"><div class="jqj-modal-field"><label>Tax rate name</label><input id="newTaxName" type="text" maxlength="120" placeholder="Tax name"></div><div class="jqj-modal-field"><label>Rate (%)</label><input id="newTaxRate" type="number" min="0" max="100" step="0.0001" value="0"></div><div class="jqj-modal-field"><label>Description</label><input id="newTaxDescription" type="text" maxlength="190" placeholder="Description / jurisdiction"></div><label class="jqj-modal-check"><input id="newTaxDefault" type="checkbox"><span>Use as default tax rate</span></label></div></div><div class="jqj-modal-foot"><button type="button" class="jqj-modal-btn" data-close="taxRateModal">Cancel</button><button type="button" class="jqj-modal-btn primary" id="createTaxRateButton">Create Tax Rate</button></div></div></div>

<!-- Property custom field modal -->
<div class="jqj-modal" id="propertyCustomFieldModal"><div class="jqj-dialog quick"><div class="jqj-modal-head"><h2>New custom field</h2><button type="button" class="jqj-icon" data-close="propertyCustomFieldModal"><i class="bi bi-x-lg"></i></button></div><div class="jqj-modal-body"><div class="jqj-field-stack"><div><div class="jqj-cap">Applies to</div><strong>All properties</strong></div><label class="jqj-switchline"><input type="checkbox" id="pcfTransferable"><span><strong>Transferable field</strong><small>Transferable fields appear in multiple places and follow your workflow.</small></span></label><div class="jqj-modal-field"><label>Custom field name</label><input id="pcfName" type="text" maxlength="190" placeholder="Custom field name"></div><div class="jqj-modal-field"><label>Field type</label><select id="pcfType"><option value="text">Text</option><option value="numeric">Numeric</option><option value="boolean">True/False</option><option value="area">Area (length × width)</option><option value="dropdown">Dropdown</option></select></div><div class="jqj-modal-field" id="pcfOptionsWrap" style="display:none"><label>Dropdown values</label><textarea id="pcfOptions" placeholder="One option per line"></textarea></div><div class="jqj-type-help" id="pcfExample">Example: Serial Number — 54A17-HEX</div><div class="jqj-modal-field"><label>Default value</label><input id="pcfDefault" type="text" placeholder="Default value"></div></div></div><div class="jqj-modal-foot"><button type="button" class="jqj-modal-btn" data-close="propertyCustomFieldModal">Cancel</button><button type="button" class="jqj-modal-btn primary" id="createPropertyCustomFieldButton">Add Custom Field</button></div></div></div>

<!-- Property contact modal -->
<div class="jqj-modal" id="propertyContactModal"><div class="jqj-dialog quick"><div class="jqj-modal-head"><h2>Add contact</h2><button type="button" class="jqj-icon" data-close="propertyContactModal"><i class="bi bi-x-lg"></i></button></div><div class="jqj-modal-body"><div class="jqj-field-stack"><strong>Details</strong><div class="jqj-quick-grid"><div class="jqj-modal-field"><label>Title</label><select id="pcTitle"><option value="">No title</option><option value="Mr.">Mr.</option><option value="Mrs.">Mrs.</option><option value="Ms.">Ms.</option><option value="Dr.">Dr.</option></select></div><div class="jqj-modal-field"><label>First name</label><input id="pcFirstName" type="text" maxlength="120"></div><div class="jqj-modal-field"><label>Last name</label><input id="pcLastName" type="text" maxlength="120"></div><div class="jqj-modal-field full"><label>Role</label><input id="pcRole" type="text" maxlength="120"></div></div><label class="jqj-modal-check"><input id="pcBilling" type="checkbox"><span>Set as billing contact</span></label><strong>Communication</strong><div class="jqj-modal-field"><label>Phone number</label><input id="pcPhone" type="text" maxlength="50"></div><div class="jqj-modal-field"><label>Email</label><input id="pcEmail" type="email" maxlength="190"></div><strong>Communication settings</strong><div class="jqj-info-box"><i class="bi bi-info-circle"></i> Contacts can access the client hub when portal access is enabled for them.</div><label class="jqj-modal-check"><input id="pcPortalAccess" type="checkbox"><span>Allow client hub access</span></label><div class="jqj-contact-settings"><div class="jqj-contact-setting-row"><span>Outstanding quote follow-ups</span><input id="pcQuoteFollowups" type="checkbox"></div><div class="jqj-contact-setting-row"><span>Overdue invoice follow-ups</span><input id="pcInvoiceFollowups" type="checkbox"></div><div class="jqj-contact-setting-row"><span>Upcoming assessment or visit reminders</span><input id="pcVisitReminders" type="checkbox" checked></div><div class="jqj-contact-setting-row"><span>Job closure follow-ups</span><input id="pcJobCloseFollowups" type="checkbox"></div></div></div></div><div class="jqj-modal-foot"><button type="button" class="jqj-modal-btn" data-close="propertyContactModal">Cancel</button><button type="button" class="jqj-modal-btn primary" id="addPropertyContactButton">Add Contact</button></div></div></div>

<!-- Custom field modal -->
<div class="jqj-modal" id="customFieldModal"><div class="jqj-dialog"><div class="jqj-modal-head"><h2>New custom field</h2><button type="button" class="jqj-icon" data-close="customFieldModal"><i class="bi bi-x-lg"></i></button></div><div class="jqj-modal-body"><div class="jqj-field-stack"><div><div class="jqj-cap">Applies to</div><strong>All jobs</strong></div><label class="jqj-switchline"><input type="checkbox" id="cfTransferable"><span><strong>Transferable field</strong><small>Transferable fields can follow your workflow when supported by related records.</small></span></label><div><label class="jqj-modal-label">Custom field name</label><input class="jqj-control" id="cfName" type="text" maxlength="120" placeholder="Custom field name"><div class="jqj-error" id="cfNameError"><i class="bi bi-exclamation-circle"></i> Custom field name is required</div></div><div><label class="jqj-modal-label">Field type</label><select class="jqj-control" id="cfType"><option value="text">Text</option><option value="numeric">Numeric</option><option value="boolean">True/False</option><option value="area">Area (length × width)</option><option value="dropdown">Dropdown</option></select><div class="jqj-type-help" id="cfExample">Example: Serial Number — 54A17-HEX</div></div><div class="jqj-options-box" id="cfOptionsBox"><label class="jqj-modal-label">Dropdown values</label><textarea class="jqj-control" id="cfOptions" rows="4" placeholder="One option per line"></textarea></div><div><label class="jqj-modal-label">Default value</label><input class="jqj-control" id="cfDefault" type="text" placeholder="Default value"></div></div></div><div class="jqj-modal-foot"><button type="button" class="jqj-modal-btn" data-close="customFieldModal">Cancel</button><button type="button" class="jqj-modal-btn primary" id="saveCustomField">Add Custom Field</button></div></div></div>

<!-- Recurrence modal, shared by Visits and Billing -->
<div class="jqj-modal" id="recurrenceModal"><div class="jqj-dialog"><div class="jqj-modal-head"><h2>Set up recurring schedule</h2><button type="button" class="jqj-icon" data-close="recurrenceModal"><i class="bi bi-x-lg"></i></button></div><div class="jqj-modal-body"><div class="jqj-rec-modal-row"><span>Every</span><input class="jqj-control" id="rcInterval" type="number" min="1" max="365" value="1"><select class="jqj-control" id="rcUnit"><option value="daily">Day</option><option value="weekly" selected>Week</option><option value="monthly">Month</option><option value="yearly">Year</option></select><span>on</span></div><div id="rcWeekly"><div class="jqj-day-grid" id="rcWeekdays"></div></div><div id="rcMonthly" class="jqj-month-panel"><div class="jqj-month-mode"><label class="jqj-radio"><input type="radio" name="rcMonthMode" value="day_of_month" checked> Day of month</label><label class="jqj-radio"><input type="radio" name="rcMonthMode" value="day_of_week"> Day of week</label></div><div class="jqj-month-panel show" id="rcMonthDays"><div class="jqj-month-days" id="rcMonthDayGrid"></div></div><div class="jqj-month-panel" id="rcNthWeek"><div class="jqj-nth-grid" id="rcNthGrid"></div></div></div><div class="jqj-recur-summary" id="rcSummary"></div></div><div class="jqj-modal-foot"><button type="button" class="jqj-modal-btn clear" id="clearRecurrence">Clear</button><button type="button" class="jqj-modal-btn" data-close="recurrenceModal">Cancel</button><button type="button" class="jqj-modal-btn primary" id="saveRecurrence">Save</button></div></div></div>

<!-- New checklist modal -->
<!-- Checklist builder: same structured builder used by Add Service Request -->
<div class="jb-modal" id="checklistModal">
  <div class="jb-dialog">
    <div class="jb-modal-head">
      <h2 id="checklistModalTitle">New checklist</h2>
      <div class="jb-modal-actions">
        <button class="jb-cancel" type="button" id="cancelChecklist">Cancel</button>
        <button class="jb-save" type="button" id="saveChecklist">Save</button>
      </div>
    </div>
    <div class="jb-modal-body">
      <div class="jb-builder-canvas"><div class="jb-builder-paper" id="builderCanvas"></div></div>
      <aside class="jb-builder-side">
        <h3>Manage checklist</h3>
        <div class="jb-form-title-wrap">
          <label>Form title</label>
          <input id="checklistName" maxlength="190" value="New checklist" placeholder="Form title">
        </div>
        <h3 class="jb-contents-title">Checklist contents</h3>
        <div class="jb-palette">
          <button type="button" data-add-type="section"><i class="bi bi-file-earmark-plus"></i> Add section</button>
          <button type="button" data-add-type="short_answer"><i class="bi bi-list"></i> Short answer</button>
          <button type="button" data-add-type="long_answer"><i class="bi bi-text-paragraph"></i> Long answer</button>
          <button type="button" data-add-type="dropdown"><i class="bi bi-chevron-circle-down"></i> Dropdown (single choice)</button>
          <button type="button" data-add-type="checkbox"><i class="bi bi-check-square"></i> Checkbox</button>
          <button type="button" data-add-type="number"><i class="bi bi-hash"></i> Numerical answer</button>
          <button type="button" data-add-type="image"><i class="bi bi-image"></i> Upload images</button>
          <button type="button" data-add-type="date"><i class="bi bi-calendar"></i> Date picker</button>
          <button type="button" data-add-type="signature"><i class="bi bi-pen"></i> Signature</button>
        </div>
      </aside>
    </div>
    <div class="jb-modal-foot">
      <button class="jb-cancel" type="button" id="modalClose">Cancel</button>
      <button class="jb-save" type="button" id="modalApply">Save Checklist</button>
    </div>
  </div>
</div>

<div class="jqj-toast" id="toast"></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){
'use strict';
var csrf=<?= json_encode($jobsCsrfToken) ?>;
var basePath=<?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>;
var api=basePath+'/api/jobs.php';
var jobId=<?= (int)$jobId ?>,requestedQuote=<?= (int)$quoteId ?>,requestedRequest=<?= (int)$requestId ?>,requestedClient=<?= (int)$clientId ?>,requestedLocation=<?= (int)$locationId ?>,requestedService=<?= (int)$serviceId ?>;
var meta={clients:[],locations:[],users:[],catalog_items:[],services:[],products:[],checklist_templates:[],team_members:[],tax_rates:[],countries:[],location_custom_fields:[],default_tax_rate_id:0,currency:{}};
var checklistBootstrap=<?= json_encode($jobChecklistBootstrap, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
var checklistDetailsById={};
var checklistBuilder=[{title:'Section 1',questions:[{title:'Question',type:'short_answer',required:0,options:[]}]}];
var checklistDraft=null;
var mode='one_off',visits=[],lines=[],customFields=[],noteFiles=[],noteMentions=[],propertyContacts=[],propertyCustomValues={},paymentScheduleRows=[],paymentSplitType='percentage',selectedTaxRateId=0,sourceContext=null,recurrenceTarget='visit';
var sourceLocked=false,catalogTargetIndex=null,catalogPriceTouched=false,catalogImageFile=null,catalogImageObjectUrl=null;
var jobNumberAuto=jobId<=0,lastAutoJobNumber='',jobNumberPreviewRequest=0;
var recurrence={repeat_type:'weekly',repeat_interval:1,weekly_days:[],monthly_mode:'day_of_month',monthly_day:null,monthly_week:1,monthly_weekday:0};
var billingRecurrence={repeat_type:'monthly',repeat_interval:1,weekly_days:[],monthly_mode:'day_of_month',monthly_day:1,monthly_week:1,monthly_weekday:0};
var toastTimer=null;
function E(id){return document.getElementById(id)}
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function req(fd){if(!fd.has('csrf_token'))fd.append('csrf_token',csrf);return fetch(api,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(function(r){return r.text().then(function(raw){var j;try{j=JSON.parse(raw)}catch(e){throw new Error(raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!r.ok||!j.success)throw new Error(j.message||'Request failed.');return j})})}
function toast(type,msg){clearTimeout(toastTimer);var t=E('toast');t.className='jqj-toast '+(type||'')+' show';t.textContent=msg||'';toastTimer=setTimeout(function(){t.classList.remove('show')},3800)}
function setJobNumberMode(autoMode){jobNumberAuto=!!autoMode;if(E('jobNumberAuto'))E('jobNumberAuto').value=jobNumberAuto?'1':'0';var st=E('jobNumberState');if(st){st.textContent=jobId>0?'Editable':(jobNumberAuto?'Auto':'Custom');st.classList.toggle('manual',!jobNumberAuto)}}
function applyAutomaticJobNumber(value){if(jobId>0||!jobNumberAuto)return;value=String(value||'').trim();if(!value)return;lastAutoJobNumber=value;E('jobNumberInput').value=value;E('jobNumberInput').placeholder='Auto';setJobNumberMode(true)}
function refreshJobNumberPreview(branchId){if(jobId>0||!jobNumberAuto)return Promise.resolve(lastAutoJobNumber);var token=++jobNumberPreviewRequest,fd=new FormData();fd.append('action','job_no_preview');fd.append('branch_id',String(Number(branchId||0)));return req(fd).then(function(d){if(token!==jobNumberPreviewRequest)return lastAutoJobNumber;var value=String(d.job_no||'').trim();if(value)applyAutomaticJobNumber(value);return value}).catch(function(){return lastAutoJobNumber})}
function today(){var d=new Date(),m=String(d.getMonth()+1).padStart(2,'0'),x=String(d.getDate()).padStart(2,'0');return d.getFullYear()+'-'+m+'-'+x}
function dateText(v){if(!v)return '-';var d=new Date(v+'T00:00:00');return isNaN(d)?v:d.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'})}
function money(v){var c=meta.currency||{},n=Number(v||0),p=parseInt(c.decimal_places,10);if(isNaN(p))p=2;var x=n.toLocaleString(undefined,{minimumFractionDigits:p,maximumFractionDigits:p}),s=c.symbol||'₹';return c.symbol_position==='after'?x+' '+s:s+x}
function showModal(id){E(id).classList.add('show')}function hideModal(id){E(id).classList.remove('show')}
document.querySelectorAll('[data-close]').forEach(function(b){b.addEventListener('click',function(){hideModal(this.dataset.close)})});document.querySelectorAll('.jqj-modal').forEach(function(m){m.addEventListener('click',function(e){if(e.target===m)hideModal(m.id)})});
function clientById(id){return (meta.clients||[]).find(function(x){return Number(x.id)===Number(id)})||null}
function locationById(id){return (meta.locations||[]).find(function(x){return Number(x.id)===Number(id)})||null}
function userOptions(selected){selected=(selected||[]).map(Number);return (meta.users||[]).map(function(u){return '<option value="'+Number(u.id)+'"'+(selected.indexOf(Number(u.id))>=0?' selected':'')+'>'+esc(u.name||'User')+'</option>'}).join('')}
function assigneeSelection(item){if(!item||!item.id)return item?item.text:'';var name=String(item.text||'User'),wrap=document.createElement('span'),av=document.createElement('span');av.className='jqj-mention-avatar';av.style.cssText='width:18px;height:18px;display:inline-grid;margin-right:5px;vertical-align:middle';av.textContent=(name.trim().charAt(0)||'U').toUpperCase();wrap.appendChild(av);wrap.appendChild(document.createTextNode(name));return $(wrap)}
function initAssigneeSelect($x){$x.select2({width:'100%',placeholder:'Assigned',closeOnSelect:false,templateSelection:assigneeSelection})}
function createOptionNode(text){var n=document.createElement('div');n.className='jqj-create-option';n.innerHTML='<i class="bi bi-plus-lg"></i><span>'+esc(text)+'</span>';return $(n)}
function customerOptionsHtml(){var h='<option value=""></option>';(meta.clients||[]).forEach(function(c){var text=(c.name||c.display_name||'Customer')+(c.company_name?' - '+c.company_name:'')+(c.phone?' - '+c.phone:'');h+='<option value="'+Number(c.id)+'">'+esc(text)+'</option>'});return h}
function customerResult(item){if(!item.id)return item.text;return String(item.id).indexOf('newclient:')===0?createOptionNode('Create new customer'):item.text}
function locationsForClient(clientId){return (meta.locations||[]).filter(function(x){return Number(x.client_id)===Number(clientId||0)})}
function defaultLocationId(clientId){var list=locationsForClient(clientId),primary=list.find(function(x){return Number(x.is_primary||0)===1});return primary?Number(primary.id):(list.length?Number(list[0].id):0)}
function primaryLocation(clientId){var list=locationsForClient(clientId);return list.find(function(x){return Number(x.is_primary||0)===1})||(list.length?list[0]:null)}
function formatAddress(x){if(!x)return'';return [x.address_line1,x.address_line2,x.city,x.state,x.postal_code].filter(function(v){return String(v||'').trim()!==''}).join(', ')}
function propertyResult(item){if(!item.id)return item.text;if(String(item.id).indexOf('newproperty:')===0)return createOptionNode('Create new property');var x=locationById(item.id);if(!x)return item.text;var wrap=document.createElement('div');wrap.className='jqj-property-result';var strong=document.createElement('strong');strong.textContent=formatAddress(x)||x.name||'Property';wrap.appendChild(strong);if(x.name&&formatAddress(x)){var small=document.createElement('small');small.textContent=x.name;wrap.appendChild(small)}return $(wrap)}
function initCustomerSelect(selected){var $x=$('#directClientId');if($x.hasClass('select2-hidden-accessible'))$x.select2('destroy');$x.html(customerOptionsHtml()).select2({width:'100%',placeholder:'Select a customer',allowClear:true,tags:!sourceLocked,minimumResultsForSearch:0,createTag:function(params){var term=$.trim(params.term||'');if(!term||sourceLocked)return null;var exact=(meta.clients||[]).some(function(c){return String(c.name||c.display_name||'').toLowerCase()===term.toLowerCase()});return exact?null:{id:'newclient:'+term,text:term,newTag:true}},templateResult:customerResult});if(Number(selected)>0)$x.val(String(selected)).trigger('change.select2')}
function refreshLocations(selected){var cid=Number($('#directClientId').val()||0),arr=locationsForClient(cid),h='<option value=""></option>';arr.forEach(function(x){var text=formatAddress(x)||x.name||('Property '+x.id);h+='<option value="'+Number(x.id)+'">'+esc(text)+'</option>'});var $x=$('#serviceLocationSelect');if($x.hasClass('select2-hidden-accessible'))$x.select2('destroy');$x.html(h).select2({width:'100%',placeholder:'Select a property',allowClear:true,tags:!sourceLocked&&cid>0,minimumResultsForSearch:0,createTag:function(params){var term=$.trim(params.term||'');if(!term||sourceLocked||cid<=0)return null;return{id:'newproperty:'+term,text:term,newTag:true}},templateResult:propertyResult});var sel=Number(selected||0);if(sel<=0)sel=defaultLocationId(cid);if(sel>0)$x.val(String(sel)).trigger('change.select2');E('directLocationId').value=sel>0?String(sel):'';var c=clientById(cid);if(c&&c.branch_id)E('directBranchId').value=String(c.branch_id);return sel}
function renderCustomerCard(){var cid=Number($('#directClientId').val()||0),c=clientById(cid),card=E('selectedClientCard'),picker=E('clientPickerWrap'),locWrap=E('locationPickerWrap');if(!c){card.classList.remove('show');picker.style.display='block';locWrap.classList.remove('show');return}picker.style.display='none';card.classList.add('show');card.classList.toggle('locked',sourceLocked);E('clientCardName').textContent=c.name||c.display_name||'Customer';var billing=primaryLocation(cid),property=locationById(E('directLocationId').value),billingText=formatAddress(billing),propertyText=formatAddress(property);E('clientBillingAddress').textContent=billingText||'No billing address saved';E('clientPropertyAddress').textContent=property?(propertyText||property.name||'Selected property'):'No property selected';var ph=E('clientPhoneLink'),em=E('clientEmailLink');if(c.phone){ph.style.display='inline';ph.textContent=c.phone;ph.href='tel:'+String(c.phone).replace(/[^+0-9]/g,'')}else ph.style.display='none';if(c.email){em.style.display='inline';em.textContent=c.email;em.href='mailto:'+c.email}else em.style.display='none';if(!property&&!sourceLocked)locWrap.classList.add('show')}
function selectCustomerById(clientId,locationId){initCustomerSelect(clientId);var selected=refreshLocations(locationId);E('directLocationId').value=selected?String(selected):'';renderCustomerCard();if(jobId<=0&&jobNumberAuto)refreshJobNumberPreview(Number(E('directBranchId').value||0))}
function initMainSelects(){initCustomerSelect(0);refreshLocations(0);if($('#recAssignees').hasClass('select2-hidden-accessible'))$('#recAssignees').select2('destroy');E('recAssignees').innerHTML=userOptions([]);initAssigneeSelect($('#recAssignees'))}
function lockSourceFields(lock){sourceLocked=!!lock;E('directClientId').disabled=sourceLocked;E('serviceLocationSelect').disabled=sourceLocked;initCustomerSelect(Number(E('directClientId').value||0));refreshLocations(Number(E('directLocationId').value||0));renderCustomerCard()}
function setSource(kind,ctx){sourceContext=ctx||{};var b=E('sourceBanner'),title='',desc='',href='#';if(kind==='quotation'){title=(jobId?'Linked to Quote ':'Creating Job from Quote ')+(ctx.quote_no||('#'+requestedQuote));desc=(ctx.title||ctx.request_title||'')+(ctx.client_name?' · '+ctx.client_name:'');href='quotation-view?quote_id='+encodeURIComponent(ctx.id||requestedQuote);E('productSourceHint').textContent='Product / Service items were copied from the quotation and remain editable on this job.';E('cancelLink').href=href}else if(kind==='request'){title=(jobId?'Linked to Request ':'Creating Job from Request ')+(ctx.request_no||('#'+requestedRequest));desc=(ctx.request_title||ctx.title||'')+(ctx.client_name?' · '+ctx.client_name:'');href='request-view.php?request_id='+encodeURIComponent(ctx.request_id||ctx.id||requestedRequest);E('productSourceHint').textContent='Product / Service items were copied from the request and remain editable on this job.';E('cancelLink').href=href}else{b.hidden=true;lockSourceFields(false);return}E('sourceTitle').textContent=title;E('sourceDescription').textContent=desc;E('sourceLink').href=href;E('sourceLink').textContent=kind==='quotation'?'View Quote':'View Request';b.hidden=false;lockSourceFields(true)}
function openCustomerModal(prefill){E('newCustomerName').value=String(prefill||'');E('newCustomerCompany').value='';E('newCustomerPhone').value='';E('newCustomerEmail').value='';showModal('customerModal');setTimeout(function(){E('newCustomerName').focus();E('newCustomerName').select()},80)}
function createCustomer(){var name=E('newCustomerName').value.trim(),email=E('newCustomerEmail').value.trim();if(!name){toast('warning','Enter a customer name.');E('newCustomerName').focus();return}var fd=new FormData();fd.append('action','create_customer');fd.append('display_name',name);fd.append('company_name',E('newCustomerCompany').value||'');fd.append('phone',E('newCustomerPhone').value||'');fd.append('email',email);var btn=E('createCustomerButton');btn.disabled=true;btn.textContent='Creating...';req(fd).then(function(d){var c=d.client||{};if(Number(c.id||0)<=0)throw new Error('Customer was not returned by the server.');c.name=c.name||c.display_name||name;var pos=(meta.clients||[]).findIndex(function(x){return Number(x.id)===Number(c.id)});if(pos>=0)meta.clients[pos]=c;else meta.clients.push(c);sourceLocked=false;initCustomerSelect(c.id);refreshLocations(0);renderCustomerCard();hideModal('customerModal');toast('success',d.message||'Customer created successfully.');E('locationPickerWrap').classList.add('show');setTimeout(function(){try{$('#serviceLocationSelect').select2('open')}catch(e){}},80)}).catch(function(e){toast('error',e.message)}).then(function(){btn.disabled=false;btn.textContent='Create Customer'})}
function countryOptions(){var h='<option value="">Select a country</option>';(meta.countries||[]).forEach(function(x){h+='<option value="'+Number(x.id)+'">'+esc(x.name||x.iso2||'Country')+'</option>'});return h}
function taxRateOptions(emptyLabel){var h='<option value="">'+esc(emptyLabel||'No tax rate created')+'</option>';(meta.tax_rates||[]).forEach(function(x){h+='<option value="'+Number(x.id)+'">'+esc((x.tax_name||'Tax')+' ('+formatTaxPercent(x.rate_percent)+'%)')+'</option>'});return h}
function renderPropertyCustomValues(){var box=E('propertyCustomValues');if(!box)return;var fields=meta.location_custom_fields||[];box.innerHTML=fields.map(function(f){var id=Number(f.id),value=propertyCustomValues[id]!=null?String(propertyCustomValues[id]):String(f.default_value||''),control='';if(f.field_type==='dropdown'){control='<select data-property-custom="'+id+'"><option value=""></option>'+(f.options||[]).map(function(o){return '<option value="'+esc(o)+'"'+(String(o)===value?' selected':'')+'>'+esc(o)+'</option>'}).join('')+'</select>'}else if(f.field_type==='boolean'){control='<select data-property-custom="'+id+'"><option value=""></option><option value="1"'+(value==='1'?' selected':'')+'>Yes</option><option value="0"'+(value==='0'?' selected':'')+'>No</option></select>'}else{control='<input data-property-custom="'+id+'" type="'+(f.field_type==='numeric'?'number':'text')+'" value="'+esc(value)+'">'}return '<div class="jqj-property-custom-row"><label>'+esc(f.field_name||'Custom field')+'</label>'+control+'</div>'}).join('')}
function collectPropertyCustomValues(){document.querySelectorAll('[data-property-custom]').forEach(function(x){propertyCustomValues[Number(x.dataset.propertyCustom)]=x.value});return propertyCustomValues}
function renderPropertyContacts(){E('propertyContactList').innerHTML=propertyContacts.map(function(c,i){var name=[c.title_prefix,c.first_name,c.last_name].filter(Boolean).join(' '),metaText=[c.role_name,c.phone,c.email].filter(Boolean).join(' · ');return '<div class="jqj-property-contact-chip"><i class="bi bi-person"></i><span><strong>'+esc(name||'Contact')+'</strong>'+(metaText?'<br><small>'+esc(metaText)+'</small>':'')+'</span><button type="button" data-remove-property-contact="'+i+'"><i class="bi bi-trash"></i></button></div>'}).join('')}
function openPropertyModal(prefill){var c=clientById($('#directClientId').val());if(!c){toast('warning','Select or create a customer first.');return}E('newPropertyName').value=String(prefill||'');E('newPropertyStreet1').value='';E('newPropertyStreet2').value='';E('newPropertyCity').value='';E('newPropertyState').value='';E('newPropertyPostal').value='';E('newPropertyCountry').innerHTML=countryOptions();E('newPropertyTaxRate').innerHTML=taxRateOptions('No tax rate created');if(Number(meta.default_tax_rate_id||0)>0)E('newPropertyTaxRate').value=String(meta.default_tax_rate_id);E('newPropertyPrimary').checked=locationsForClient(c.id).length===0;propertyContacts=[];propertyCustomValues={};renderPropertyCustomValues();renderPropertyContacts();showModal('propertyModal');setTimeout(function(){E('newPropertyName').focus();E('newPropertyName').select()},80)}
function createProperty(){var c=clientById($('#directClientId').val());if(!c){toast('warning','Select a customer first.');return}collectPropertyCustomValues();var name=E('newPropertyName').value.trim(),street=E('newPropertyStreet1').value.trim();if(!name){toast('warning','Enter a property name.');E('newPropertyName').focus();return}if(!street){toast('warning','Enter Street 1.');E('newPropertyStreet1').focus();return}var fd=new FormData();fd.append('action','create_location');fd.append('client_id',String(c.id));fd.append('name',name);fd.append('location_type','site');fd.append('address_line1',street);fd.append('address_line2',E('newPropertyStreet2').value||'');fd.append('city',E('newPropertyCity').value||'');fd.append('state',E('newPropertyState').value||'');fd.append('postal_code',E('newPropertyPostal').value||'');fd.append('country_id',E('newPropertyCountry').value||'');fd.append('tax_rate_id',E('newPropertyTaxRate').value||'');fd.append('is_primary',E('newPropertyPrimary').checked?'1':'0');fd.append('custom_values_json',JSON.stringify(propertyCustomValues));fd.append('contacts_json',JSON.stringify(propertyContacts));var btn=E('createPropertyButton');btn.disabled=true;btn.textContent='Adding...';req(fd).then(function(d){var loc=d.location||{};if(Number(loc.id||0)<=0)throw new Error('Property was not returned by the server.');if(Number(loc.is_primary||0)===1)(meta.locations||[]).forEach(function(x){if(Number(x.client_id)===Number(c.id))x.is_primary=0});var pos=(meta.locations||[]).findIndex(function(x){return Number(x.id)===Number(loc.id)});if(pos>=0)meta.locations[pos]=loc;else meta.locations.push(loc);refreshLocations(loc.id);E('directLocationId').value=String(loc.id);E('locationPickerWrap').classList.remove('show');renderCustomerCard();hideModal('propertyModal');toast('success',d.message||'Property created successfully.')}).catch(function(e){toast('error',e.message)}).then(function(){btn.disabled=false;btn.textContent='Add Property'})}
function openPropertyCustomFieldModal(){E('pcfName').value='';E('pcfType').value='text';E('pcfDefault').value='';E('pcfOptions').value='';E('pcfOptionsWrap').style.display='none';E('pcfTransferable').checked=false;E('pcfExample').textContent='Example: Serial Number — 54A17-HEX';showModal('propertyCustomFieldModal');setTimeout(function(){E('pcfName').focus()},60)}
function createPropertyCustomField(){var name=E('pcfName').value.trim(),type=E('pcfType').value;if(!name){toast('warning','Enter a custom field name.');return}var opts=E('pcfOptions').value.split(/\r?\n/).map(function(x){return x.trim()}).filter(Boolean);if(type==='dropdown'&&!opts.length){toast('warning','Add at least one dropdown value.');return}var fd=new FormData();fd.append('action','create_location_custom_field');fd.append('field_name',name);fd.append('field_type',type);fd.append('is_transferable',E('pcfTransferable').checked?'1':'0');fd.append('default_value',E('pcfDefault').value||'');fd.append('options_json',JSON.stringify(opts));var btn=E('createPropertyCustomFieldButton');btn.disabled=true;btn.textContent='Adding...';req(fd).then(function(d){var f=d.custom_field||{};if(Number(f.id||0)<=0)throw new Error('Custom field was not returned by the server.');f.options=Array.isArray(f.options)?f.options:opts;meta.location_custom_fields.push(f);propertyCustomValues[Number(f.id)]=String(f.default_value||'');renderPropertyCustomValues();hideModal('propertyCustomFieldModal');toast('success',d.message||'Property custom field created.')}).catch(function(e){toast('error',e.message)}).then(function(){btn.disabled=false;btn.textContent='Add Custom Field'})}
function openPropertyContactModal(){['pcFirstName','pcLastName','pcRole','pcPhone','pcEmail'].forEach(function(id){E(id).value=''});E('pcTitle').value='';E('pcBilling').checked=false;E('pcPortalAccess').checked=false;E('pcQuoteFollowups').checked=false;E('pcInvoiceFollowups').checked=false;E('pcVisitReminders').checked=true;E('pcJobCloseFollowups').checked=false;showModal('propertyContactModal');setTimeout(function(){E('pcFirstName').focus()},60)}
function addPropertyContact(){var first=E('pcFirstName').value.trim(),email=E('pcEmail').value.trim();if(!first){toast('warning','Enter the contact first name.');return}if(email&&E('pcEmail').validity&&!E('pcEmail').validity.valid){toast('warning','Enter a valid contact email.');return}propertyContacts.push({title_prefix:E('pcTitle').value||'',first_name:first,last_name:E('pcLastName').value.trim(),role_name:E('pcRole').value.trim(),is_billing_contact:E('pcBilling').checked?1:0,phone:E('pcPhone').value.trim(),email:email,portal_access:E('pcPortalAccess').checked?1:0,quote_followups:E('pcQuoteFollowups').checked?1:0,invoice_followups:E('pcInvoiceFollowups').checked?1:0,visit_reminders:E('pcVisitReminders').checked?1:0,job_close_followups:E('pcJobCloseFollowups').checked?1:0});renderPropertyContacts();hideModal('propertyContactModal')}


/* Custom fields */
var cfHelp={text:'Example: Serial Number — 54A17-HEX',numeric:'Example: Equipment age — 5',boolean:'Example: Warranty active — Yes / No',area:'Example: Room size — 12 × 14',dropdown:'Add the choices that should appear in this field.'};
function renderCustomFields(){E('customFields').innerHTML=customFields.map(function(f,i){var type=f.field_type||'text',value=f.field_value!=null?String(f.field_value):String(f.default_value||''),control='';if(type==='numeric')control='<input class="jqj-control jqj-custom-value" data-cf-value="'+i+'" type="number" value="'+esc(value)+'">';else if(type==='boolean')control='<label class="jqj-check jqj-custom-value"><input data-cf-bool="'+i+'" type="checkbox" '+((value==='1'||value==='true'||value==='Yes')?'checked':'')+'> Yes</label>';else if(type==='dropdown')control='<select class="jqj-control jqj-custom-value" data-cf-value="'+i+'">'+(f.options||[]).map(function(o){return '<option value="'+esc(o)+'"'+(String(o)===value?' selected':'')+'>'+esc(o)+'</option>'}).join('')+'</select>';else if(type==='area'){var a=value.split('x');control='<div class="jqj-custom-area jqj-custom-value"><input class="jqj-control" data-cf-area-l="'+i+'" type="number" min="0" step="0.01" value="'+esc((a[0]||'').trim())+'"><span>×</span><input class="jqj-control" data-cf-area-w="'+i+'" type="number" min="0" step="0.01" value="'+esc((a[1]||'').trim())+'"></div>'}else control='<input class="jqj-control jqj-custom-value" data-cf-value="'+i+'" type="text" value="'+esc(value)+'">';return '<div class="jqj-custom-row"><div class="jqj-custom-label">'+esc(f.field_label||'Custom field')+'</div>'+control+'<button type="button" class="jqj-remove" data-remove-cf="'+i+'"><i class="bi bi-trash"></i></button></div>'}).join('')}
E('customFields').addEventListener('click',function(e){var b=e.target.closest('[data-remove-cf]');if(b){customFields.splice(Number(b.dataset.removeCf),1);renderCustomFields()}});
E('addCustomField').onclick=function(){E('cfName').value='';E('cfType').value='text';E('cfDefault').value='';E('cfTransferable').checked=false;E('cfOptions').value='';E('cfNameError').classList.remove('show');updateCfType();showModal('customFieldModal');setTimeout(function(){E('cfName').focus()},40)};
function updateCfType(){var t=E('cfType').value;E('cfExample').textContent=cfHelp[t]||'';E('cfOptionsBox').classList.toggle('show',t==='dropdown')}E('cfType').onchange=updateCfType;
E('saveCustomField').onclick=function(){var name=E('cfName').value.trim(),type=E('cfType').value;if(!name){E('cfNameError').classList.add('show');E('cfName').focus();return}var opts=E('cfOptions').value.split(/\r?\n/).map(function(x){return x.trim()}).filter(Boolean);if(type==='dropdown'&&!opts.length){toast('warning','Add at least one dropdown value.');return}customFields.push({field_label:name,field_type:type,field_value:E('cfDefault').value,default_value:E('cfDefault').value,is_transferable:E('cfTransferable').checked?1:0,options:opts});hideModal('customFieldModal');renderCustomFields()};
function collectCustomFields(){customFields.forEach(function(f,i){if(f.field_type==='boolean'){var b=document.querySelector('[data-cf-bool="'+i+'"]');f.field_value=b&&b.checked?'1':'0'}else if(f.field_type==='area'){var l=document.querySelector('[data-cf-area-l="'+i+'"]'),w=document.querySelector('[data-cf-area-w="'+i+'"]');f.field_value=((l?l.value:'')+' x '+(w?w.value:'')).trim()}else{var x=document.querySelector('[data-cf-value="'+i+'"]');if(x)f.field_value=x.value}});return customFields}

/* Visits */
function newVisit(v){v=v||{};return{title:v.title||'',start_date:v.start_date||today(),start_time:(v.start_time||'09:00').substring(0,5),end_time:(v.end_time||'10:00').substring(0,5),schedule_later:Number(v.schedule_later||0),anytime:Number(v.anytime||0),assignee_ids:(v.assignee_ids||[]).map(Number),instructions:v.instructions||'',email_team_about_assignment:Number(v.email_team_about_assignment||0)}}
function renderVisits(){E('visits').innerHTML=visits.map(function(v,i){var d=new Date((v.start_date||today())+'T00:00:00'),mon=d.toLocaleDateString(undefined,{month:'short'}),day=d.getDate();return '<article class="jqj-visit" data-visit="'+i+'"><div class="jqj-date-rail">'+esc(mon)+'<strong>'+day+'</strong></div><div class="jqj-visit-main"><div class="jqj-visit-title-row"><input class="jqj-control" data-v-title="'+i+'" value="'+esc(v.title)+'" placeholder="Title"><button type="button" class="jqj-icon" data-remove-visit="'+i+'"><i class="bi bi-three-dots"></i></button></div><div class="jqj-hint">Leave title blank to use the job title.</div><div class="jqj-float"><label>Date</label><input type="date" data-v-date="'+i+'" value="'+esc(v.start_date)+'"></div><label class="jqj-check"><input type="checkbox" data-v-later="'+i+'" '+(v.schedule_later?'checked':'')+'> Schedule later</label><div class="jqj-two"><div class="jqj-float"><label>Start time</label><input type="time" data-v-start="'+i+'" value="'+esc(v.start_time)+'" '+((v.anytime||v.schedule_later)?'disabled':'')+'></div><div class="jqj-float"><label>End time</label><input type="time" data-v-end="'+i+'" value="'+esc(v.end_time)+'" '+((v.anytime||v.schedule_later)?'disabled':'')+'></div></div><label class="jqj-check"><input type="checkbox" data-v-any="'+i+'" '+(v.anytime?'checked':'')+'> Anytime</label><div class="jqj-float jqj-assignee"><label>Assigned</label><select multiple data-v-assignees="'+i+'">'+userOptions(v.assignee_ids)+'</select></div><label class="jqj-check"><input type="checkbox" data-v-email="'+i+'" '+(v.email_team_about_assignment?'checked':'')+'> Email team about assignment</label><div class="jqj-float"><label>Visit instructions</label><textarea data-v-instructions="'+i+'">'+esc(v.instructions)+'</textarea></div></div></article>'}).join('');visits.forEach(function(v,i){initAssigneeSelect($('[data-v-assignees="'+i+'"]'))});updateScheduleSummary()}
function syncVisits(){visits.forEach(function(v,i){var q=function(s){return document.querySelector(s)};var x=q('[data-v-title="'+i+'"]');if(x)v.title=x.value;x=q('[data-v-date="'+i+'"]');if(x)v.start_date=x.value;x=q('[data-v-start="'+i+'"]');if(x&&!x.disabled)v.start_time=x.value;x=q('[data-v-end="'+i+'"]');if(x&&!x.disabled)v.end_time=x.value;x=q('[data-v-later="'+i+'"]');v.schedule_later=x&&x.checked?1:0;x=q('[data-v-any="'+i+'"]');v.anytime=x&&x.checked?1:0;v.assignee_ids=($('[data-v-assignees="'+i+'"]').val()||[]).map(Number);x=q('[data-v-email="'+i+'"]');v.email_team_about_assignment=x&&x.checked?1:0;x=q('[data-v-instructions="'+i+'"]');if(x)v.instructions=x.value})}
E('visits').addEventListener('click',function(e){var b=e.target.closest('[data-remove-visit]');if(b){if(visits.length===1){toast('warning','At least one visit is required.');return}syncVisits();visits.splice(Number(b.dataset.removeVisit),1);renderVisits()}});E('visits').addEventListener('change',function(e){var a=e.target.getAttribute('data-v-any'),l=e.target.getAttribute('data-v-later');if(a!==null||l!==null){syncVisits();renderVisits()}else{syncVisits();updateScheduleSummary()}});E('visits').addEventListener('input',function(){syncVisits();updateScheduleSummary()});E('addVisit').onclick=function(){syncVisits();visits.push(newVisit({start_date:today()}));renderVisits()};E('createVisits').onclick=function(){if(mode==='one_off'){syncVisits();visits.push(newVisit({start_date:today()}));renderVisits()}else{E('recPreset').value='custom';openRecurrenceModal('visit')}};
function setMode(m){mode=m;document.querySelectorAll('.jqj-tab').forEach(function(b){b.classList.toggle('active',b.dataset.mode===m)});E('oneOffPanel').style.display=m==='one_off'?'block':'none';E('recurringPanel').classList.toggle('show',m==='recurring');updateScheduleSummary()}document.querySelectorAll('.jqj-tab').forEach(function(b){b.onclick=function(){setMode(this.dataset.mode)}});
function currentDow(){var d=new Date((E('recStartDate').value||today())+'T00:00:00');return d.getDay()}
function setPreset(v){var dow=currentDow(),dom=new Date((E('recStartDate').value||today())+'T00:00:00').getDate();if(v==='daily')recurrence={repeat_type:'daily',repeat_interval:1,weekly_days:[],monthly_mode:'day_of_month',monthly_day:dom,monthly_week:1,monthly_weekday:dow};else if(v==='weekly')recurrence={repeat_type:'weekly',repeat_interval:1,weekly_days:[dow],monthly_mode:'day_of_month',monthly_day:dom,monthly_week:1,monthly_weekday:dow};else if(v==='biweekly')recurrence={repeat_type:'weekly',repeat_interval:2,weekly_days:[dow],monthly_mode:'day_of_month',monthly_day:dom,monthly_week:1,monthly_weekday:dow};else if(v==='monthly')recurrence={repeat_type:'monthly',repeat_interval:1,weekly_days:[],monthly_mode:'day_of_month',monthly_day:dom,monthly_week:Math.ceil(dom/7),monthly_weekday:dow};else if(v==='as_needed')recurrence={repeat_type:'none',repeat_interval:1,weekly_days:[],monthly_mode:'day_of_month',monthly_day:dom,monthly_week:1,monthly_weekday:dow};else{openRecurrenceModal('visit');return}renderRecurrenceSummary();updateScheduleSummary()}
E('recPreset').onchange=function(){setPreset(this.value)};E('recStartDate').onchange=function(){if(E('recPreset').value!=='custom')setPreset(E('recPreset').value);updateScheduleSummary()};['recStartTime','recEndTime','recEndValue','recEndUnit','recEndDate','recAnytime','recInstructions'].forEach(function(id){E(id).addEventListener('change',updateScheduleSummary);E(id).addEventListener('input',updateScheduleSummary)});document.querySelectorAll('input[name="recEndMode"]').forEach(function(r){r.onchange=function(){var on=this.value==='on_date';E('recEndDate').disabled=!on;E('recEndValue').disabled=on;E('recEndUnit').disabled=on;updateScheduleSummary()}});
function recurrenceText(r){if(!r||r.repeat_type==='none')return 'As needed';if(r.repeat_type==='daily')return 'Every '+r.repeat_interval+' day'+(r.repeat_interval===1?'':'s');if(r.repeat_type==='yearly')return 'Every '+r.repeat_interval+' year'+(r.repeat_interval===1?'':'s');if(r.repeat_type==='weekly'){var names=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],days=(r.weekly_days||[]).map(function(x){return names[x]}).join(', ');return 'Every '+r.repeat_interval+' week'+(r.repeat_interval===1?'':'s')+(days?' on '+days:'')}if(r.repeat_type==='monthly'){if(r.monthly_mode==='day_of_week'){var ord=['','1st','2nd','3rd','4th','Last'],names=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];return 'Every '+r.repeat_interval+' month'+(r.repeat_interval===1?'':'s')+' on '+ord[r.monthly_week]+' '+names[r.monthly_weekday]}return 'Every '+r.repeat_interval+' month'+(r.repeat_interval===1?'':'s')+' on '+(r.monthly_day===0?'the last day':'day '+r.monthly_day)}return ''}
function renderRecurrenceSummary(){E('recurrenceSummary').textContent='Repeats '+recurrenceText(recurrence)}
function endDateApprox(start,val,unit){var d=new Date(start+'T00:00:00');val=Number(val||1);if(unit==='days')d.setDate(d.getDate()+val);else if(unit==='weeks')d.setDate(d.getDate()+val*7);else if(unit==='years')d.setFullYear(d.getFullYear()+val);else d.setMonth(d.getMonth()+val);return d}
function approxRecurringCount(){if(recurrence.repeat_type==='none')return 1;var start=E('recStartDate').value||today(),endMode=document.querySelector('input[name="recEndMode"]:checked').value,end=endMode==='on_date'?new Date((E('recEndDate').value||start)+'T00:00:00'):endDateApprox(start,E('recEndValue').value,E('recEndUnit').value),s=new Date(start+'T00:00:00'),days=Math.max(0,Math.floor((end-s)/86400000));if(recurrence.repeat_type==='daily')return Math.min(500,Math.floor(days/recurrence.repeat_interval)+1);if(recurrence.repeat_type==='weekly')return Math.min(500,Math.max(1,Math.round((days/7)/recurrence.repeat_interval*Math.max(1,recurrence.weekly_days.length))+1));if(recurrence.repeat_type==='monthly')return Math.min(500,Math.floor((days/30.44)/recurrence.repeat_interval)+1);if(recurrence.repeat_type==='yearly')return Math.min(500,Math.floor((days/365.25)/recurrence.repeat_interval)+1);return 1}
function updateScheduleSummary(){if(mode==='one_off'){var arr=visits.slice().sort(function(a,b){return String(a.start_date).localeCompare(String(b.start_date))}),first=arr[0],last=arr[arr.length-1];E('scheduleRange').textContent=arr.length?(dateText(first.start_date)+(arr.length>1?' – '+dateText(last.start_date):'')):'';E('visitCount').textContent=arr.length+' visit'+(arr.length===1?'':'s');E('repeatSummary').textContent=''}else{var count=approxRecurringCount(),start=E('recStartDate').value||today(),endMode=document.querySelector('input[name="recEndMode"]:checked').value,end=endMode==='on_date'?(E('recEndDate').value||start):endDateApprox(start,E('recEndValue').value,E('recEndUnit').value).toISOString().slice(0,10);E('scheduleRange').textContent=dateText(start)+' – '+dateText(end);E('visitCount').textContent=count+' visit'+(count===1?'':'s');E('repeatSummary').textContent='Repeats '+recurrenceText(recurrence)}updatePaymentScheduleSummary()}

/* Recurrence modal */
function buildRecurrenceControls(){var names=['S','M','T','W','T','F','S'];E('rcWeekdays').innerHTML=names.map(function(n,i){return '<button type="button" class="jqj-day-btn" data-rc-day="'+i+'">'+n+'</button>'}).join('');var days='';for(var i=1;i<=31;i++)days+='<button type="button" class="jqj-month-day" data-month-day="'+i+'">'+i+'</button>';days+='<button type="button" class="jqj-month-day last" data-month-day="0">Last day</button>';E('rcMonthDayGrid').innerHTML=days;var ord=['1st','2nd','3rd','4th','Last'],h='';ord.forEach(function(o,wi){h+='<span class="jqj-nth-label">'+o+'</span>';names.forEach(function(n,di){h+='<button type="button" class="jqj-day-btn" data-nth-week="'+(wi+1)+'" data-nth-day="'+di+'">'+n+'</button>'})});E('rcNthGrid').innerHTML=h}buildRecurrenceControls();
function openRecurrenceModal(target){recurrenceTarget=target;var r=target==='billing'?billingRecurrence:recurrence;E('rcInterval').value=r.repeat_interval||1;E('rcUnit').value=r.repeat_type==='none'?'weekly':r.repeat_type;renderRcMode();document.querySelectorAll('[data-rc-day]').forEach(function(b){b.classList.toggle('active',(r.weekly_days||[]).indexOf(Number(b.dataset.rcDay))>=0)});document.querySelectorAll('[data-month-day]').forEach(function(b){b.classList.toggle('active',Number(b.dataset.monthDay)===Number(r.monthly_day))});document.querySelectorAll('[data-nth-week]').forEach(function(b){b.classList.toggle('active',Number(b.dataset.nthWeek)===Number(r.monthly_week)&&Number(b.dataset.nthDay)===Number(r.monthly_weekday))});var rm=document.querySelector('input[name="rcMonthMode"][value="'+(r.monthly_mode||'day_of_month')+'"]');if(rm)rm.checked=true;renderRcMonthMode();E('rcSummary').textContent=recurrenceText(r);showModal('recurrenceModal')}
function renderRcMode(){var u=E('rcUnit').value;E('rcWeekly').style.display=u==='weekly'?'block':'none';E('rcMonthly').classList.toggle('show',u==='monthly')}
function renderRcMonthMode(){var m=document.querySelector('input[name="rcMonthMode"]:checked').value;E('rcMonthDays').classList.toggle('show',m==='day_of_month');E('rcNthWeek').classList.toggle('show',m==='day_of_week')}
E('rcUnit').onchange=function(){renderRcMode();E('rcSummary').textContent='Every '+E('rcInterval').value+' '+this.options[this.selectedIndex].text};E('rcInterval').oninput=function(){E('rcSummary').textContent='Every '+this.value+' '+E('rcUnit').options[E('rcUnit').selectedIndex].text};document.querySelectorAll('input[name="rcMonthMode"]').forEach(function(x){x.onchange=renderRcMonthMode});E('rcWeekdays').onclick=function(e){var b=e.target.closest('[data-rc-day]');if(b)b.classList.toggle('active')};E('rcMonthDayGrid').onclick=function(e){var b=e.target.closest('[data-month-day]');if(!b)return;document.querySelectorAll('[data-month-day]').forEach(function(x){x.classList.remove('active')});b.classList.add('active')};E('rcNthGrid').onclick=function(e){var b=e.target.closest('[data-nth-week]');if(!b)return;document.querySelectorAll('[data-nth-week]').forEach(function(x){x.classList.remove('active')});b.classList.add('active')};
E('clearRecurrence').onclick=function(){E('rcInterval').value=1;E('rcUnit').value='weekly';document.querySelectorAll('.jqj-day-btn,.jqj-month-day').forEach(function(x){x.classList.remove('active')});var dow=recurrenceTarget==='visit'?currentDow():new Date().getDay(),b=document.querySelector('[data-rc-day="'+dow+'"]');if(b)b.classList.add('active');renderRcMode()};
E('saveRecurrence').onclick=function(){var type=E('rcUnit').value,interval=Math.max(1,Number(E('rcInterval').value||1)),weekly=Array.prototype.map.call(document.querySelectorAll('[data-rc-day].active'),function(x){return Number(x.dataset.rcDay)}),mm=document.querySelector('input[name="rcMonthMode"]:checked').value,md=document.querySelector('[data-month-day].active'),nth=document.querySelector('[data-nth-week].active');if(type==='weekly'&&!weekly.length){toast('warning','Select at least one weekday.');return}var r={repeat_type:type,repeat_interval:interval,weekly_days:weekly,monthly_mode:mm,monthly_day:md?Number(md.dataset.monthDay):new Date((E('recStartDate').value||today())+'T00:00:00').getDate(),monthly_week:nth?Number(nth.dataset.nthWeek):1,monthly_weekday:nth?Number(nth.dataset.nthDay):new Date().getDay()};if(recurrenceTarget==='billing'){billingRecurrence=r;if(E('invoiceFrequency'))E('invoiceFrequency').value='custom';if(E('billingCustomSummary'))E('billingCustomSummary').textContent=recurrenceText(r)}else{recurrence=r;E('recPreset').value='custom';renderRecurrenceSummary();updateScheduleSummary()}hideModal('recurrenceModal');updatePaymentScheduleSummary()};

/* Checklist: Request builder parity + source inheritance */
function checklistTypeLabel(type){var map={short_answer:'Short answer',long_answer:'Long answer',dropdown:'Dropdown',checkbox:'Checkbox',number:'Numerical answer',image:'Upload images',date:'Date picker',signature:'Signature'};return map[type]||'Checkbox'}
function checklistTypeIcon(type){var map={short_answer:'bi-list',long_answer:'bi-text-paragraph',dropdown:'bi-chevron-circle-down',checkbox:'bi-check-square',number:'bi-hash',image:'bi-image',date:'bi-calendar',signature:'bi-pen'};return map[type]||'bi-check-square'}
function checklistNormalizeType(type){type=String(type||'').toLowerCase();return ['short_answer','long_answer','dropdown','checkbox','number','image','date','signature'].indexOf(type)>=0?type:'checkbox'}
function checklistSelectedIds(){return Array.prototype.map.call(E('checklistTemplates').querySelectorAll('input[name="checklist_template_ids[]"]:checked'),function(x){return Number(x.value)}).filter(function(x){return x>0})}
function mergeChecklistBootstrap(){
  checklistDetailsById={};
  (checklistBootstrap.templates||[]).forEach(function(t){var id=Number(t.id||0);if(id<=0)return;checklistDetailsById[id]=t;var exists=(meta.checklist_templates||[]).some(function(x){return Number(x.id)===id});if(!exists){meta.checklist_templates.push({id:id,name:t.name||'Checklist',description:t.description||'',item_count:Number(t.item_count||0),section_count:Number(t.section_count||0)})}});
}
function checklistSections(detail){var sections=[],map={};(detail&&detail.items||[]).forEach(function(item){var name=String(item.section_title||'').trim()||'Checklist';if(!map[name]){map[name]={name:name,items:[]};sections.push(map[name])}map[name].items.push(item)});return sections}
function checklistPreviewHtml(detail){if(!detail||!(detail.items||[]).length)return '';return checklistSections(detail).map(function(section){return '<div class="jqj-checklist-section-preview"><h4>'+esc(section.name)+'</h4>'+section.items.map(function(item){var type=checklistNormalizeType(item.question_type),reqd=Number(item.is_required||item.required||0)===1;return '<div class="jqj-checklist-question-preview"><i class="bi '+checklistTypeIcon(type)+'"></i><span>'+esc(item.title||'Question')+'</span>'+(reqd?'<span class="required">Required</span>':'')+'<span class="type">'+esc(checklistTypeLabel(type))+'</span></div>'}).join('')+'</div>'}).join('')}
function renderChecklistTemplates(selected){
  selected=(selected||[]).map(Number);
  mergeChecklistBootstrap();
  var sourceIds=(checklistBootstrap.selected_template_ids||[]).map(Number),rows=(meta.checklist_templates||[]).slice();
  rows.sort(function(a,b){var as=sourceIds.indexOf(Number(a.id))>=0?0:1,bs=sourceIds.indexOf(Number(b.id))>=0?0:1;if(as!==bs)return as-bs;return String(a.name||'').localeCompare(String(b.name||''))});
  E('checklistTemplates').innerHTML=rows.map(function(x){
    var id=Number(x.id),checked=selected.indexOf(id)>=0,detail=checklistDetailsById[id]||null,fromRequest=sourceIds.indexOf(id)>=0&&Number(checklistBootstrap.source_request_id||0)>0;
    var itemCount=detail?Number(detail.item_count||0):Number(x.item_count||0),sectionCount=detail?Number(detail.section_count||0):Number(x.section_count||0),metaText=(sectionCount?sectionCount+' section'+(sectionCount===1?'':'s')+' · ':'')+itemCount+' question'+(itemCount===1?'':'s');
    return '<article class="jqj-checklist-card '+(fromRequest?'from-request':'')+'" data-checklist-card="'+id+'"><div class="jqj-checklist-card-head"><input class="jqj-checklist-card-check" type="checkbox" name="checklist_template_ids[]" value="'+id+'" '+(checked?'checked':'')+'><span class="jqj-checklist-card-icon"><i class="bi bi-clipboard2-check"></i></span><div class="jqj-checklist-card-title"><strong>'+esc(x.name||'Checklist')+(fromRequest?'<span class="jqj-checklist-source-badge">From Request</span>':'')+'</strong><small>'+esc(metaText)+'</small></div>'+(detail&&detail.items&&detail.items.length?'<button type="button" class="jqj-checklist-preview-toggle" data-checklist-preview="'+id+'" title="Show checklist fields"><i class="bi bi-chevron-'+(fromRequest?'up':'down')+'"></i></button>':'')+'</div>'+(detail&&detail.items&&detail.items.length?'<div class="jqj-checklist-preview '+(fromRequest?'show':'')+'" data-checklist-preview-body="'+id+'">'+checklistPreviewHtml(detail)+'</div>':'')+'</article>'
  }).join('')||'<div class="jqj-type-help">No saved checklist templates yet. Use New checklist to create one.</div>';
  var sourceNote=E('checklistSourceNote');if(Number(checklistBootstrap.source_request_id||0)>0&&sourceIds.length){sourceNote.hidden=false;E('checklistSourceTitle').textContent='Checklist from '+(checklistBootstrap.source_request_no?checklistBootstrap.source_request_no:'Request #'+Number(checklistBootstrap.source_request_id))}else sourceNote.hidden=true;
}
function setChecklistBody(open){E('checklistBody').classList.toggle('show',!!open);E('checklistToggle').querySelector('i').className='bi '+(open?'bi-chevron-up':'bi-chevron-down')}
E('checklistToggle').onclick=function(){setChecklistBody(!E('checklistBody').classList.contains('show'))};
E('checklistSelectAll').onclick=function(){var all=E('checklistTemplates').querySelectorAll('input[type="checkbox"]'),should=Array.prototype.some.call(all,function(x){return !x.checked});all.forEach(function(x){x.checked=should})};
E('checklistTemplates').addEventListener('click',function(e){var b=e.target.closest('[data-checklist-preview]');if(!b)return;e.preventDefault();e.stopPropagation();var id=Number(b.dataset.checklistPreview),body=E('checklistTemplates').querySelector('[data-checklist-preview-body="'+id+'"]');if(!body)return;var show=!body.classList.contains('show');body.classList.toggle('show',show);var i=b.querySelector('i');if(i)i.className='bi bi-chevron-'+(show?'up':'down')});
function blankChecklistBuilder(){return [{title:'Section 1',questions:[{title:'Question',type:'short_answer',required:0,options:[]}]}]}
function newChecklistQuestion(type){type=checklistNormalizeType(type||'short_answer');return {title:'Question',type:type,required:0,options:(type==='dropdown'||type==='checkbox')?['Option 1','Option 2','Option 3']:[]}}
function openChecklistBuilder(useDraft){if(useDraft&&checklistDraft&&Array.isArray(checklistDraft.builder)){checklistBuilder=JSON.parse(JSON.stringify(checklistDraft.builder));E('checklistName').value=checklistDraft.name||'New checklist';E('checklistModalTitle').textContent='Edit '+(checklistDraft.name||'Checklist')}else{checklistBuilder=blankChecklistBuilder();E('checklistName').value='New checklist';E('checklistModalTitle').textContent='New checklist'}E('checklistModal').classList.add('show');document.body.style.overflow='hidden';renderChecklistBuilder();setTimeout(function(){E('checklistName').focus();E('checklistName').select()},60)}
function closeChecklistBuilder(){E('checklistModal').classList.remove('show');document.body.style.overflow=''}
function renderChecklistBuilder(){E('builderCanvas').innerHTML=checklistBuilder.map(function(s,si){return '<div class="jb-builder-section" data-s="'+si+'"><div class="jb-builder-sec-head"><input data-sec-title value="'+esc(s.title||('Section '+(si+1)))+'"><button type="button" class="jb-icon-btn" data-del-sec title="Remove section"><i class="bi bi-trash"></i></button></div>'+s.questions.map(function(q,qi){q.type=checklistNormalizeType(q.type);var ops=(q.type==='dropdown'||q.type==='checkbox')?'<div class="jb-options">'+(q.options||[]).map(function(o,oi){return '<div class="jb-option-row"><span>'+(oi+1)+'.</span><input data-opt="'+oi+'" value="'+esc(o)+'"><button type="button" class="jb-icon-btn" data-del-opt="'+oi+'"><i class="bi bi-x-lg"></i></button></div>'}).join('')+'<button type="button" class="jb-cancel" data-add-opt style="width:max-content;padding:5px 10px">Add option</button></div>':'';return '<div class="jb-builder-question" data-q="'+qi+'"><div class="jb-q-top"><input data-q-title value="'+esc(q.title||'Question')+'"><select data-q-type><option value="short_answer"'+(q.type==='short_answer'?' selected':'')+'>Short answer</option><option value="long_answer"'+(q.type==='long_answer'?' selected':'')+'>Long answer</option><option value="dropdown"'+(q.type==='dropdown'?' selected':'')+'>Dropdown</option><option value="checkbox"'+(q.type==='checkbox'?' selected':'')+'>Checkbox</option><option value="number"'+(q.type==='number'?' selected':'')+'>Numerical answer</option><option value="image"'+(q.type==='image'?' selected':'')+'>Upload images</option><option value="date"'+(q.type==='date'?' selected':'')+'>Date picker</option><option value="signature"'+(q.type==='signature'?' selected':'')+'>Signature</option></select><button type="button" class="jb-icon-btn" data-del-q title="Remove question"><i class="bi bi-trash"></i></button></div>'+ops+'<div class="jb-q-foot"><label><input type="checkbox" data-required '+(q.required?'checked':'')+'> Required</label></div></div>'}).join('')+'<button type="button" class="jb-builder-link" data-add-q style="margin:10px 14px">+ Add Question</button></div>'}).join('')}
function syncChecklistBuilder(){E('builderCanvas').querySelectorAll('[data-s]').forEach(function(sec){var si=Number(sec.dataset.s),section=checklistBuilder[si];if(!section)return;var secTitle=sec.querySelector('[data-sec-title]');section.title=secTitle?secTitle.value:'Section '+(si+1);sec.querySelectorAll('[data-q]').forEach(function(qel){var qi=Number(qel.dataset.q),q=section.questions[qi];if(!q)return;q.title=(qel.querySelector('[data-q-title]')||{}).value||'';q.type=checklistNormalizeType((qel.querySelector('[data-q-type]')||{}).value||'short_answer');q.required=qel.querySelector('[data-required]')&&qel.querySelector('[data-required]').checked?1:0;q.options=[];qel.querySelectorAll('[data-opt]').forEach(function(o){var v=String(o.value||'').trim();if(v)q.options.push(v)})})})}
E('builderCanvas').addEventListener('click',function(e){var sec=e.target.closest('[data-s]'),qel=e.target.closest('[data-q]');if(e.target.closest('[data-add-q]')&&sec){syncChecklistBuilder();checklistBuilder[Number(sec.dataset.s)].questions.push(newChecklistQuestion('short_answer'));renderChecklistBuilder();return}if(e.target.closest('[data-del-q]')&&sec&&qel){syncChecklistBuilder();var s=checklistBuilder[Number(sec.dataset.s)];s.questions.splice(Number(qel.dataset.q),1);renderChecklistBuilder();return}if(e.target.closest('[data-del-sec]')&&sec){syncChecklistBuilder();if(checklistBuilder.length>1)checklistBuilder.splice(Number(sec.dataset.s),1);else toast('warning','A checklist needs at least one section.');renderChecklistBuilder();return}if(e.target.closest('[data-add-opt]')&&sec&&qel){syncChecklistBuilder();var q=checklistBuilder[Number(sec.dataset.s)].questions[Number(qel.dataset.q)];q.options.push('Option '+(q.options.length+1));renderChecklistBuilder();return}var ro=e.target.closest('[data-del-opt]');if(ro&&sec&&qel){syncChecklistBuilder();var q=checklistBuilder[Number(sec.dataset.s)].questions[Number(qel.dataset.q)];q.options.splice(Number(ro.dataset.delOpt),1);renderChecklistBuilder()}});
E('builderCanvas').addEventListener('change',function(e){if(e.target.matches('[data-q-type]')){syncChecklistBuilder();var sec=e.target.closest('[data-s]'),qel=e.target.closest('[data-q]'),q=checklistBuilder[Number(sec.dataset.s)].questions[Number(qel.dataset.q)];if((q.type==='dropdown'||q.type==='checkbox')&&!q.options.length)q.options=['Option 1'];renderChecklistBuilder()}});
document.querySelectorAll('[data-add-type]').forEach(function(b){b.onclick=function(){syncChecklistBuilder();var t=this.dataset.addType;if(t==='section')checklistBuilder.push({title:'Section '+(checklistBuilder.length+1),questions:[]});else{if(!checklistBuilder.length)checklistBuilder=blankChecklistBuilder();checklistBuilder[checklistBuilder.length-1].questions.push(newChecklistQuestion(t))}renderChecklistBuilder()}});
function saveChecklistBuilder(){syncChecklistBuilder();var name=E('checklistName').value.trim(),items=[],sectionCount=0;checklistBuilder.forEach(function(s){var added=0;s.questions.forEach(function(q){var title=String(q.title||'').trim();if(!title)return;added++;items.push({section_title:String(s.title||'').trim(),title:title,question_type:checklistNormalizeType(q.type),options:Array.isArray(q.options)?q.options:[],required:q.required?1:0,is_required:q.required?1:0})});if(added)sectionCount++});if(!name){toast('warning','Enter the checklist Form title.');E('checklistName').focus();return}if(!items.length){toast('warning','Add at least one checklist question.');return}checklistDraft={name:name,description:'',items:items,builder:JSON.parse(JSON.stringify(checklistBuilder)),section_count:sectionCount};E('newChecklistJson').value=JSON.stringify({name:name,description:'',items:items});renderChecklistDraft();closeChecklistBuilder();setChecklistBody(true);toast('success','Checklist added to this Job.')}
function renderChecklistDraft(){var wrap=E('checklistDraftSummary');if(!checklistDraft){wrap.hidden=true;return}wrap.hidden=false;E('checklistDraftName').textContent=checklistDraft.name||'New checklist';E('checklistDraftMeta').textContent=Number(checklistDraft.section_count||0)+' section'+(Number(checklistDraft.section_count||0)===1?'':'s')+' · '+(checklistDraft.items||[]).length+' question'+((checklistDraft.items||[]).length===1?'':'s')}
E('newChecklist').onclick=function(){openChecklistBuilder(false)};
E('editChecklistDraft').onclick=function(){openChecklistBuilder(true)};
E('removeChecklistDraft').onclick=function(){checklistDraft=null;E('newChecklistJson').value='';renderChecklistDraft();toast('success','New checklist removed.')};
E('cancelChecklist').onclick=closeChecklistBuilder;E('modalClose').onclick=closeChecklistBuilder;E('saveChecklist').onclick=saveChecklistBuilder;E('modalApply').onclick=saveChecklistBuilder;E('checklistModal').onclick=function(e){if(e.target===this)closeChecklistBuilder()};

/* Product / Service */
function normalizeLine(x,source){x=x||{};var cost=Math.max(0,Number(x.unit_cost!=null?x.unit_cost:(x.base_unit_price||0))),price=Math.max(0,Number(x.unit_price!=null?x.unit_price:(x.selling_price||0))),markup=x.markup_percent!=null?Math.max(0,Number(x.markup_percent||0)):(cost>0?Math.max(0,(price-cost)/cost*100):0);return{product_service_id:Number(x.product_service_id||0)||null,product_id:Number(x.product_id||0)||null,item_source:String(x.item_source||(!x.product_service_id&&!x.product_id?'manual':(x.product_id?'product':'product_service'))),item_type:String(x.item_type||(x.product_id?'product':(x.product_service_id?'service':'manual'))),item_name:String(x.item_name||x.name||''),description:String(x.description||''),quantity:Math.max(.001,Number(x.quantity||1)),unit_cost:cost,markup_percent:markup,unit_price:price,tax_percent:Math.max(0,Number(x.tax_percent||0)),image_path:String(x.image_path||''),source_label:source||String(x.source_label||'')}}
function catalogKey(l){return l&&l.product_id?'product:'+l.product_id:(l&&l.product_service_id?'ps:'+l.product_service_id:'')}
function catalogOptions(line,i){var h='<option value=""></option>';(meta.catalog_items||[]).forEach(function(x){var key=x.catalog_key||((x.source_type==='product'?'product:':'ps:')+x.id);h+='<option value="'+esc(key)+'" data-kind="'+esc(x.source_type==='product'?'product':'service')+'" data-price="'+esc(x.unit_price!=null?x.unit_price:x.selling_price||0)+'" data-description="'+esc(x.description||'')+'">'+esc(x.name||'Item')+'</option>'});if(line&&!line.product_id&&!line.product_service_id&&String(line.item_name||'').trim()!=='')h+='<option value="manual:'+i+'">'+esc(line.item_name)+'</option>';return h}
function findCatalog(key){return (meta.catalog_items||[]).find(function(x){return String(x.catalog_key)===String(key)})||null}
function catalogResult(item){if(!item.id)return item.text;var id=String(item.id||'');if(id.indexOf('new:')===0)return createOptionNode('Create new product / service');var el=item.element,kind=el?String(el.getAttribute('data-kind')||''):'',price=el?el.getAttribute('data-price'):'',desc=el?el.getAttribute('data-description'):'';var row=document.createElement('div');row.className='jqj-catalog-result';var copy=document.createElement('div');copy.className='jqj-catalog-copy';var name=document.createElement('div');name.className='jqj-catalog-name';var text=document.createElement('span');text.textContent=item.text||'';name.appendChild(text);if(kind){var badge=document.createElement('span');badge.className='jqj-type-badge '+kind;badge.textContent=kind==='product'?'Product':'Service';name.appendChild(badge)}copy.appendChild(name);if(desc){var ds=document.createElement('div');ds.className='jqj-catalog-desc';ds.textContent=desc;copy.appendChild(ds)}row.appendChild(copy);if(price!==''){var pr=document.createElement('div');pr.className='jqj-catalog-price';pr.textContent=money(price);row.appendChild(pr)}return $(row)}
function catalogSelection(item){return item&&item.id?item.text:'Name'}
function lineTotal(l){return Number(l.quantity||0)*Number(l.unit_price||0)}
function applyCatalogToLine(index,key){key=String(key||'');if(!lines[index])return;if(key.indexOf('new:')===0){catalogTargetIndex=index;openCatalogModal(key.slice(4));return}if(key.indexOf('manual:')===0)return;var c=findCatalog(key);if(!c)return;var old=lines[index],kind=c.source_type==='product'?'product':'service';lines[index]=normalizeLine({product_service_id:kind==='service'?(c.product_service_id||c.id):null,product_id:kind==='product'?(c.product_id||c.id):null,item_source:kind==='product'?'product':'product_service',item_type:kind,item_name:c.name,description:c.description||'',quantity:old.quantity,unit_cost:c.unit_cost!=null?c.unit_cost:c.base_unit_price,unit_price:c.unit_price!=null?c.unit_price:c.selling_price,tax_percent:c.tax_percent,image_path:c.image_path||''},old.source_label);renderLines()}
function renderLines(){E('lineItems').innerHTML=lines.map(function(l,i){var key=catalogKey(l),current=key||((String(l.item_name||'').trim()!=='')?'manual:'+i:'');return '<article class="jqj-line '+(l.source_label?'source-line':'')+'" data-line="'+i+'" data-source-label="'+esc(l.source_label||'')+'"><div class="jqj-line-top"><div class="jqj-line-field jqj-item-picker"><label>Name</label><select class="jqj-line-catalog-select" data-line-catalog="'+i+'">'+catalogOptions(l,i)+'</select></div><div class="jqj-qty-field"><label>Quantity</label><input data-line-qty="'+i+'" type="number" min="0.001" step="0.001" value="'+esc(l.quantity)+'"></div><div class="jqj-money-field"><label>Unit cost</label><input data-line-cost="'+i+'" type="number" min="0" step="0.01" value="'+Number(l.unit_cost||0).toFixed(2)+'"></div><div class="jqj-money-field"><label>Unit price</label><input data-line-price="'+i+'" type="number" min="0" step="0.01" value="'+Number(l.unit_price||0).toFixed(2)+'"></div><div class="jqj-line-total"><small>Total</small><strong data-line-total="'+i+'">'+esc(money(lineTotal(l)))+'</strong></div><button type="button" class="jqj-remove" data-remove-line="'+i+'" title="Remove"><i class="bi bi-trash"></i></button></div><textarea data-line-desc="'+i+'" placeholder="Description">'+esc(l.description)+'</textarea><input type="hidden" data-line-current="'+i+'" value="'+esc(current)+'"></article>'}).join('');lines.forEach(function(l,i){var $sel=$('[data-line-catalog="'+i+'"]');$sel.select2({width:'100%',placeholder:'Name',allowClear:true,tags:true,minimumResultsForSearch:0,createTag:function(params){var term=$.trim(params.term||'');if(!term)return null;var lower=term.toLowerCase(),exists=(meta.catalog_items||[]).some(function(v){return String(v.name||'').toLowerCase()===lower});return exists?null:{id:'new:'+term,text:term,newTag:true}},templateResult:catalogResult,templateSelection:catalogSelection});var current=E('lineItems').querySelector('[data-line-current="'+i+'"]');if(current&&current.value)$sel.val(current.value).trigger('change.select2');$sel.on('select2:select',function(e){var raw=e.params&&e.params.data?e.params.data.id:this.value;applyCatalogToLine(i,raw)});$sel.on('select2:clear',function(){if(lines[i]){var old=lines[i];lines[i]=normalizeLine({quantity:old.quantity,unit_cost:old.unit_cost,unit_price:old.unit_price,tax_percent:old.tax_percent},old.source_label);renderLines()}})});updateTotals()}
function syncLines(){lines.forEach(function(l,i){var x=document.querySelector('[data-line-qty="'+i+'"]');if(x)l.quantity=Math.max(.001,Number(x.value||1));x=document.querySelector('[data-line-cost="'+i+'"]');if(x)l.unit_cost=Math.max(0,Number(x.value||0));x=document.querySelector('[data-line-price="'+i+'"]');if(x)l.unit_price=Math.max(0,Number(x.value||0));l.markup_percent=l.unit_cost>0?Math.max(0,(l.unit_price-l.unit_cost)/l.unit_cost*100):0;x=document.querySelector('[data-line-desc="'+i+'"]');if(x)l.description=x.value})}
E('lineItems').addEventListener('input',function(e){var card=e.target.closest('[data-line]');if(!card)return;var i=Number(card.dataset.line);if(!lines[i])return;syncLines();var t=card.querySelector('[data-line-total="'+i+'"]');if(t)t.textContent=money(lineTotal(lines[i]));updateTotals()});
E('lineItems').addEventListener('click',function(e){var b=e.target.closest('[data-remove-line]');if(b){if(lines.length===1){toast('warning','At least one Product / Service item is required.');return}syncLines();lines.splice(Number(b.dataset.removeLine),1);renderLines()}});
E('addLine').onclick=function(){syncLines();lines.push(normalizeLine({quantity:1,tax_percent:selectedTaxRate()?Number(selectedTaxRate().rate_percent||0):0}));renderLines();setTimeout(function(){try{$('[data-line-catalog="'+(lines.length-1)+'"]').select2('open')}catch(e){}},50)};

function resetCatalogImage(){catalogImageFile=null;var input=E('newItemImage');if(input)input.value='';if(catalogImageObjectUrl){try{URL.revokeObjectURL(catalogImageObjectUrl)}catch(e){}catalogImageObjectUrl=null}E('newItemImagePreviewImg').src='';E('newItemImageName').textContent='';E('newItemImageSize').textContent='';E('newItemImagePreview').classList.remove('show');E('newItemImagePick').style.display='flex'}
function setCatalogImage(file){if(!file){resetCatalogImage();return false}var allowed=['image/jpeg','image/png','image/webp'],name=String(file.name||''),ext=(name.split('.').pop()||'').toLowerCase();if(allowed.indexOf(String(file.type||'').toLowerCase())<0&&['jpg','jpeg','png','webp'].indexOf(ext)<0){toast('warning','Item image must be JPG, PNG or WEBP.');resetCatalogImage();return false}if(Number(file.size||0)<=0||Number(file.size)>4*1024*1024){toast('warning','Item image must be 4 MB or smaller.');resetCatalogImage();return false}if(catalogImageObjectUrl){try{URL.revokeObjectURL(catalogImageObjectUrl)}catch(e){}}catalogImageFile=file;catalogImageObjectUrl=URL.createObjectURL(file);E('newItemImagePreviewImg').src=catalogImageObjectUrl;E('newItemImageName').textContent=name;E('newItemImageSize').textContent=(Number(file.size||0)/1024).toFixed(1)+' KB';E('newItemImagePick').style.display='none';E('newItemImagePreview').classList.add('show');return true}
function openCatalogModal(name){E('newItemType').value='service';E('newItemName').value=String(name||'').trim();E('newItemDescription').value='';E('newItemUnitCost').value='0.00';E('newItemMarkup').value='0';E('newItemUnitPrice').value='0.00';E('newItemTaxExempt').checked=false;E('newItemTaxRate').innerHTML=taxRateOptions('No tax');E('newItemTaxRate').value=selectedTaxRateId>0?String(selectedTaxRateId):'';resetCatalogImage();catalogPriceTouched=false;showModal('catalogItemModal');setTimeout(function(){E('newItemName').focus();E('newItemName').select()},80)}
function recalcNewItemPrice(){if(catalogPriceTouched)return;var cost=Math.max(0,Number(E('newItemUnitCost').value||0)),markup=Math.max(0,Number(E('newItemMarkup').value||0));E('newItemUnitPrice').value=(cost*(1+markup/100)).toFixed(2)}
function createCatalogItem(){var type=E('newItemType').value,name=E('newItemName').value.trim();if(!name){toast('warning','Enter a product / service name.');return}var fd=new FormData();fd.append('action','create_catalog_item');fd.append('item_type',type);fd.append('name',name);fd.append('description',E('newItemDescription').value||'');fd.append('unit_cost',E('newItemUnitCost').value||0);fd.append('markup_percent',E('newItemMarkup').value||0);fd.append('unit_price',E('newItemUnitPrice').value||0);var newItemRate=(meta.tax_rates||[]).find(function(r){return Number(r.id)===Number(E('newItemTaxRate').value||0)})||null;fd.append('tax_rate_id',E('newItemTaxExempt').checked?'0':String(Number(E('newItemTaxRate').value||0)));fd.append('tax_percent',E('newItemTaxExempt').checked?'0':String(newItemRate?Number(newItemRate.rate_percent||0):0));fd.append('exempt_tax',E('newItemTaxExempt').checked?'1':'0');if(catalogImageFile)fd.append('item_image',catalogImageFile,catalogImageFile.name||'item-image');var btn=E('createCatalogItemButton');btn.disabled=true;btn.textContent='Creating...';req(fd).then(function(d){var item=d.item||{},kind=String(d.item_kind||type);if(kind==='service'){item.catalog_key=item.catalog_key||('ps:'+Number(item.id));item.source_type='product_service';item.source_label='Service';item.product_service_id=Number(item.product_service_id||item.id);item.product_id=null;item.unit_price=Number(item.unit_price||0);item.unit_cost=Number(item.unit_cost||0)}else{item.catalog_key=item.catalog_key||('product:'+Number(item.id));item.source_type='product';item.source_label='Product';item.product_id=Number(item.product_id||item.id);item.product_service_id=null;item.unit_cost=Number(item.unit_cost!=null?item.unit_cost:item.base_unit_price||0);item.unit_price=Number(item.unit_price!=null?item.unit_price:item.selling_price||0)}var pos=(meta.catalog_items||[]).findIndex(function(x){return String(x.catalog_key)===String(item.catalog_key)});if(pos>=0)meta.catalog_items[pos]=item;else meta.catalog_items.push(item);var target=catalogTargetIndex;catalogTargetIndex=null;resetCatalogImage();hideModal('catalogItemModal');if(target!==null&&lines[target])applyCatalogToLine(target,item.catalog_key);toast('success',d.message||'Item created successfully.')}).catch(function(e){toast('error',e.message)}).then(function(){btn.disabled=false;btn.textContent='Create'})}
function totalsData(){syncLines();var subtotal=lines.reduce(function(sum,l){return sum+lineTotal(l)},0),cost=lines.reduce(function(sum,l){return sum+Number(l.quantity||0)*Number(l.unit_cost||0)},0),dt=E('discountType').value,dv=Math.max(0,Number(E('discountValue').value||0)),discount=0;if(dt==='percentage')discount=subtotal*Math.min(100,dv)/100;else if(dt==='fixed')discount=Math.min(subtotal,dv);var tax=0,remain=discount;lines.forEach(function(l,i){var base=lineTotal(l),share=discount&&subtotal?(i===lines.length-1?remain:Math.round((discount*base/subtotal)*100)/100):0;remain-=share;tax+=(Math.max(0,base-share)*Number(l.tax_percent||0)/100)});return{subtotal:subtotal,cost:cost,discount:discount,tax:tax,total:Math.max(0,subtotal-discount)+tax}}
function updateTotals(){var d=totalsData();E('subtotal').textContent=money(d.subtotal);E('discountTotal').textContent=money(d.discount);E('taxTotal').textContent=money(d.tax);E('grandTotal').textContent=money(d.total);E('costTotal').textContent=money(d.cost);E('discountAction').textContent=d.discount>0?'Change':'Add Discount';E('taxAction').textContent=d.tax>0?'Change':'Add Tax';updatePaymentScheduleSummary(d)}
function formatTaxPercent(v){var n=Number(v||0);return (Math.round(n*10000)/10000).toString()}
function selectedTaxRate(){return (meta.tax_rates||[]).find(function(r){return Number(r.id)===Number(selectedTaxRateId||0)})||null}
function setTaxMenuOpen(show){E('taxMenu').classList.toggle('show',!!show);E('taxSelectorChevron').className='bi '+(show?'bi-chevron-up':'bi-chevron-down')}
function renderTaxMenu(){var h='';if(!(meta.tax_rates||[]).length)h='<div class="jqj-tax-empty">No tax rates created</div>';(meta.tax_rates||[]).forEach(function(r){h+='<button type="button" data-tax-rate-id="'+Number(r.id)+'"><strong>'+esc(r.tax_name||'Tax')+'</strong><small>'+esc(formatTaxPercent(r.rate_percent))+'%</small></button>'});h+='<button type="button" class="jqj-create-tax" id="createTaxRateLink">Create new tax rate</button>';E('taxMenu').innerHTML=h;var create=E('createTaxRateLink');if(create)create.onclick=openTaxRateModal}
function updateTaxSelectorText(){var r=selectedTaxRate();E('taxSelectorText').textContent=r?(r.tax_name||'Tax')+' ('+formatTaxPercent(r.rate_percent)+'%)':((meta.tax_rates||[]).length?'Select tax rate':'No tax rate created')}
function setSelectedTaxRate(id,apply){selectedTaxRateId=Number(id||0);updateTaxSelectorText();setTaxMenuOpen(false);if(apply!==false){var r=selectedTaxRate(),p=r?Number(r.rate_percent||0):0;syncLines();lines.forEach(function(l){l.tax_percent=p});renderLines()}}
function syncSelectedTaxFromLines(){var vals=[];lines.forEach(function(l){var p=Number(l.tax_percent||0);if(p>0&&!vals.some(function(v){return Math.abs(v-p)<0.00001}))vals.push(p)});if(vals.length===1){var m=(meta.tax_rates||[]).find(function(r){return Math.abs(Number(r.rate_percent||0)-vals[0])<0.00001});selectedTaxRateId=m?Number(m.id):0;if(!m)E('taxSelectorText').textContent='Custom tax ('+formatTaxPercent(vals[0])+'%)';else updateTaxSelectorText()}else{selectedTaxRateId=Number(meta.default_tax_rate_id||0);updateTaxSelectorText()}}
function openTaxRateModal(){E('newTaxName').value='';E('newTaxRate').value='0';E('newTaxDescription').value='';E('newTaxDefault').checked=false;showModal('taxRateModal');setTimeout(function(){E('newTaxName').focus()},60)}
function createTaxRate(){var name=E('newTaxName').value.trim(),rate=Number(E('newTaxRate').value||0);if(!name){toast('warning','Enter a tax rate name.');return}if(!isFinite(rate)||rate<0||rate>100){toast('warning','Tax rate must be between 0 and 100.');return}var fd=new FormData();fd.append('action','create_tax_rate');fd.append('name',name);fd.append('rate_percent',String(rate));fd.append('description',E('newTaxDescription').value||'');fd.append('is_default',E('newTaxDefault').checked?'1':'0');var b=E('createTaxRateButton');b.disabled=true;b.textContent='Creating...';req(fd).then(function(d){var r=d.tax_rate||{};if(Number(r.id||0)<=0)throw new Error('Tax rate was not returned by the server.');if(Number(r.is_default||0)===1)(meta.tax_rates||[]).forEach(function(x){x.is_default=0});var pos=(meta.tax_rates||[]).findIndex(function(x){return Number(x.id)===Number(r.id)});if(pos>=0)meta.tax_rates[pos]=r;else meta.tax_rates.push(r);if(Number(r.is_default||0)===1)meta.default_tax_rate_id=Number(r.id);renderTaxMenu();setSelectedTaxRate(r.id,true);hideModal('taxRateModal');toast('success',d.message||'Tax rate created successfully.')}).catch(function(e){toast('error',e.message)}).then(function(){b.disabled=false;b.textContent='Create Tax Rate'})}
E('discountAction').onclick=function(){E('discountEditor').classList.toggle('show');E('discountTypeEditor').value=E('discountType').value||'fixed';E('discountValueEditor').value=E('discountValue').value||0};E('applyDiscount').onclick=function(){var t=E('discountTypeEditor').value,v=Math.max(0,Number(E('discountValueEditor').value||0));if(t==='percentage')v=Math.min(100,v);E('discountType').value=v>0?t:'';E('discountValue').value=v>0?v:0;E('discountEditor').classList.remove('show');updateTotals()};E('taxAction').onclick=function(){E('taxEditor').classList.toggle('show');setTaxMenuOpen(false);updateTaxSelectorText()};E('taxSelectorButton').onclick=function(){setTaxMenuOpen(!E('taxMenu').classList.contains('show'))};E('taxMenu').onclick=function(e){var b=e.target.closest('[data-tax-rate-id]');if(b)setSelectedTaxRate(Number(b.dataset.taxRateId),true)};E('createTaxRateButton').onclick=createTaxRate;document.addEventListener('click',function(e){if(!e.target.closest('#taxEditor'))setTaxMenuOpen(false)});
E('newItemUnitCost').oninput=recalcNewItemPrice;E('newItemMarkup').oninput=recalcNewItemPrice;E('newItemUnitPrice').oninput=function(){catalogPriceTouched=true};E('createCatalogItemButton').onclick=createCatalogItem;E('newItemImagePick').onclick=function(){E('newItemImage').click()};E('newItemImage').onchange=function(){if(this.files&&this.files[0])setCatalogImage(this.files[0]);else resetCatalogImage()};E('newItemImageRemove').onclick=resetCatalogImage;['dragenter','dragover'].forEach(function(evt){E('newItemImagePick').addEventListener(evt,function(e){e.preventDefault();e.stopPropagation();this.classList.add('dragover')})});['dragleave','drop'].forEach(function(evt){E('newItemImagePick').addEventListener(evt,function(e){e.preventDefault();e.stopPropagation();this.classList.remove('dragover')})});E('newItemImagePick').addEventListener('drop',function(e){var f=e.dataTransfer&&e.dataTransfer.files?e.dataTransfer.files[0]:null;if(f)setCatalogImage(f)});document.addEventListener('click',function(e){if(!e.target.closest('.jqj-price-wrap'))document.querySelectorAll('.jqj-cost-popover').forEach(function(x){x.classList.remove('show')})});

/* Billing - Jobber style payment schedule */
function defaultPaymentRows(){return[{description:'Payment 1',value:50,due_date:''},{description:'Payment 2',value:50,due_date:''}]}
function renderPaymentSchedule(){E('paymentSchedulePanel').classList.toggle('show',E('splitPaymentSchedule').checked);E('splitTypeWrap').classList.toggle('show',E('splitPaymentSchedule').checked);E('paymentValueHeading').textContent=paymentSplitType==='percentage'?'%':(meta.currency&&meta.currency.symbol?meta.currency.symbol:'₹');document.querySelectorAll('[data-split-type]').forEach(function(b){b.classList.toggle('active',b.dataset.splitType===paymentSplitType)});var d=totalsData();E('paymentScheduleRows').innerHTML=paymentScheduleRows.map(function(r,i){var amount=paymentSplitType==='percentage'?d.total*Math.max(0,Number(r.value||0))/100:Math.max(0,Number(r.value||0));return '<tr><td><button type="button" class="jqj-payment-create" disabled>Create</button></td><td><input type="date" data-pay-date="'+i+'" value="'+esc(r.due_date||'')+'"></td><td><span class="jqj-upcoming">● Upcoming</span></td><td><input type="number" min="0" step="0.01" data-pay-value="'+i+'" value="'+esc(Number(r.value||0))+'"></td><td><input type="text" maxlength="190" data-pay-desc="'+i+'" value="'+esc(r.description||('Payment '+(i+1)))+'"></td><td><strong>'+esc(money(amount))+'</strong><br><small>Subtotal '+esc(money(amount))+'</small></td><td>'+esc(money(amount))+'</td><td>'+(i>0?'<button type="button" class="jqj-payment-remove" data-remove-pay="'+i+'"><i class="bi bi-trash"></i></button>':'')+'</td></tr>'}).join('');updatePaymentScheduleSummary(d)}
function syncPaymentRows(){paymentScheduleRows.forEach(function(r,i){var x=document.querySelector('[data-pay-date="'+i+'"]');if(x)r.due_date=x.value;x=document.querySelector('[data-pay-value="'+i+'"]');if(x)r.value=Math.max(0,Number(x.value||0));x=document.querySelector('[data-pay-desc="'+i+'"]');if(x)r.description=x.value.trim()||('Payment '+(i+1))})}
function updatePaymentScheduleSummary(d){if(!E('paymentSchedulePanel'))return;d=d||totalsData();E('paymentScheduleTotal').textContent=money(d.total);E('paymentScheduleSub').textContent=money(d.subtotal)+' subtotal - '+money(d.discount)+' discount + '+money(d.tax)+' taxes';var scheduled=0;paymentScheduleRows.forEach(function(r){scheduled+=paymentSplitType==='percentage'?d.total*Math.max(0,Number(r.value||0))/100:Math.max(0,Number(r.value||0))});var pct=d.total>0?Math.min(100,scheduled/d.total*100):(paymentSplitType==='percentage'?Math.min(100,paymentScheduleRows.reduce(function(a,r){return a+Number(r.value||0)},0)):0);E('paymentTrackFill').style.width=Math.max(0,Math.min(100,pct))+'%';E('paymentRemainingLabel').textContent='Remaining: '+Math.max(0,100-pct).toFixed(2)+'% ('+money(Math.max(0,d.total-scheduled))+')';E('fixedInvoiceAmount').value=d.total>0?String(d.total):'0';E('paymentScheduleJson').value=JSON.stringify(paymentScheduleRows);E('paymentSplitType').value=paymentSplitType;E('invoiceFrequency').value=E('remindInvoiceOnClose').checked?'job_completion':'as_needed'}
E('splitPaymentSchedule').onchange=function(){if(this.checked&&!paymentScheduleRows.length)paymentScheduleRows=defaultPaymentRows();renderPaymentSchedule()};E('remindInvoiceOnClose').onchange=function(){updatePaymentScheduleSummary()};document.querySelectorAll('[data-split-type]').forEach(function(b){b.onclick=function(){syncPaymentRows();paymentSplitType=this.dataset.splitType;renderPaymentSchedule()}});E('addPaymentRow').onclick=function(){syncPaymentRows();paymentScheduleRows.push({description:'Payment '+(paymentScheduleRows.length+1),value:0,due_date:''});renderPaymentSchedule()};E('paymentScheduleRows').addEventListener('input',function(){syncPaymentRows();updatePaymentScheduleSummary()});E('paymentScheduleRows').addEventListener('change',function(){syncPaymentRows();renderPaymentSchedule()});E('paymentScheduleRows').addEventListener('click',function(e){var b=e.target.closest('[data-remove-pay]');if(b){syncPaymentRows();paymentScheduleRows.splice(Number(b.dataset.removePay),1);if(!paymentScheduleRows.length)paymentScheduleRows.push({description:'Payment 1',value:0,due_date:''});renderPaymentSchedule()}});

/* Notes team mentions */
function renderMentionTags(){E('noteMentionTags').innerHTML=noteMentions.map(function(id){var mentionSource=(meta.team_members&&meta.team_members.length?meta.team_members:meta.users)||[],u=mentionSource.find(function(x){return Number(x.id)===Number(id)});return u?'<span class="jqj-mention-tag">@'+esc(u.name||'Team member')+'</span>':''}).join('');E('noteMentionsJson').value=JSON.stringify(noteMentions)}
function mentionQuery(){var ta=E('internalNote'),pos=ta.selectionStart||0,before=ta.value.slice(0,pos),m=before.match(/(?:^|\s)@([^@\s]*)$/);return m?{query:m[1]||'',start:pos-(m[1]||'').length-1,end:pos}:null}
function hideMentionMenu(){E('noteMentionMenu').classList.remove('show')}
function renderMentionMenu(){var q=mentionQuery();if(!q){hideMentionMenu();return}var term=q.query.toLowerCase(),mentionSource=(meta.team_members&&meta.team_members.length?meta.team_members:meta.users)||[],rows=mentionSource.filter(function(u){return String(u.name||'').toLowerCase().indexOf(term)>=0}).slice(0,12),box=E('noteMentionMenu');box.innerHTML=rows.length?rows.map(function(u){return '<button type="button" data-mention-user="'+Number(u.id)+'"><span class="jqj-mention-avatar">'+esc((String(u.name||'U').trim().charAt(0)||'U').toUpperCase())+'</span><span>'+esc(u.name||'Team member')+(u.job_title?'<br><small>'+esc(u.job_title)+'</small>':'')+'</span></button>'}).join(''):'<div class="jqj-mention-empty">No team members found</div>';box.classList.add('show')}
function chooseMention(userId){var mentionSource=(meta.team_members&&meta.team_members.length?meta.team_members:meta.users)||[],u=mentionSource.find(function(x){return Number(x.id)===Number(userId)}),q=mentionQuery();if(!u||!q)return;var ta=E('internalNote'),before=ta.value.slice(0,q.start),after=ta.value.slice(q.end);ta.value=before+'@'+String(u.name||'Team member')+' '+after;var pos=(before+'@'+String(u.name||'Team member')+' ').length;ta.focus();ta.setSelectionRange(pos,pos);if(noteMentions.indexOf(Number(u.id))<0)noteMentions.push(Number(u.id));renderMentionTags();hideMentionMenu()}
E('internalNote').addEventListener('input',renderMentionMenu);E('internalNote').addEventListener('click',renderMentionMenu);E('internalNote').addEventListener('keyup',renderMentionMenu);E('noteMentionMenu').onclick=function(e){var b=e.target.closest('[data-mention-user]');if(b)chooseMention(Number(b.dataset.mentionUser))};document.addEventListener('click',function(e){if(!e.target.closest('.jqj-note-editor'))hideMentionMenu()});

/* Notes files */
function renderFiles(){E('fileList').innerHTML=noteFiles.map(function(f,i){return '<div class="jqj-file"><i class="bi bi-paperclip"></i><span>'+esc(f.name)+' · '+(f.size/1024/1024).toFixed(2)+' MB</span><button type="button" class="jqj-remove" data-remove-file="'+i+'"><i class="bi bi-x-lg"></i></button></div>'}).join('')}
function setFiles(fileList){var incoming=Array.prototype.slice.call(fileList||[]);incoming.forEach(function(f){if(noteFiles.length<12&&f.size<=10*1024*1024)noteFiles.push(f);else toast('warning','Attachments are limited to 12 files, 10 MB each.')});renderFiles()}
E('pickFiles').onclick=function(){E('jobAttachments').click()};E('jobAttachments').onchange=function(){setFiles(this.files)};E('noteDrop').ondragover=function(e){e.preventDefault();this.classList.add('drag')};E('noteDrop').ondragleave=function(){this.classList.remove('drag')};E('noteDrop').ondrop=function(e){e.preventDefault();this.classList.remove('drag');setFiles(e.dataTransfer.files)};E('fileList').onclick=function(e){var b=e.target.closest('[data-remove-file]');if(b){noteFiles.splice(Number(b.dataset.removeFile),1);renderFiles()}};

/* Serialization */
function collectSchedules(){if(mode==='one_off'){syncVisits();return visits.map(function(v){return{title:v.title,start_date:v.start_date,end_date:v.start_date,start_time:v.start_time||'09:00',end_time:v.end_time||'10:00',repeat_type:'none',repeat_interval:1,weekly_days:[],end_mode:'after_occurrences',repeat_occurrences:1,assignee_ids:v.assignee_ids,instructions:v.instructions,schedule_later:v.schedule_later,anytime:v.anytime,email_team_about_assignment:v.email_team_about_assignment}})}var start=E('recStartDate').value||today(),endMode=document.querySelector('input[name="recEndMode"]:checked').value;return[{title:'',start_date:start,end_date:start,start_time:E('recStartTime').value||'09:00',end_time:E('recEndTime').value||'10:00',repeat_type:recurrence.repeat_type,repeat_interval:recurrence.repeat_interval,weekly_days:recurrence.weekly_days,monthly_mode:recurrence.monthly_mode,monthly_day:recurrence.monthly_day,monthly_week:recurrence.monthly_week,monthly_weekday:recurrence.monthly_weekday,end_mode:endMode,repeat_end_date:endMode==='on_date'?E('recEndDate').value:'',repeat_occurrences:1,end_after_value:endMode==='after_duration'?Number(E('recEndValue').value||1):null,end_after_unit:endMode==='after_duration'?E('recEndUnit').value:null,assignee_ids:($('#recAssignees').val()||[]).map(Number),instructions:E('recInstructions').value,anytime:E('recAnytime').checked?1:0,schedule_later:recurrence.repeat_type==='none'?1:0,email_team_about_assignment:E('emailTeamRecurring').checked?1:0}]}
function billingSchedulePayload(){return ''}
function prepareSubmit(){var schedules=collectSchedules();for(var i=0;i<schedules.length;i++){if(!schedules[i].start_date){toast('warning','Select a visit date.');return false}if(!schedules[i].assignee_ids.length){toast('warning','Assign at least one team member to every visit.');return false}}syncLines();if(!lines.length){toast('warning','Add at least one Product / Service item.');return false}for(var j=0;j<lines.length;j++){if(!lines[j].item_name){var c=findCatalog(catalogKey(lines[j]));if(c)lines[j].item_name=c.name}if(!lines[j].item_name){toast('warning','Enter a name for Product / Service line '+(j+1)+'.');return false}}
  var all=[];schedules.forEach(function(s){s.assignee_ids.forEach(function(id){if(all.indexOf(Number(id))<0)all.push(Number(id))})});E('assigneeHidden').innerHTML=all.map(function(id){return '<input type="hidden" name="user_ids[]" value="'+id+'">'}).join('');E('assignmentMode').value='multiple_users';E('scheduleJson').value=JSON.stringify(schedules);E('lineItemsJson').value=JSON.stringify(lines);E('customFieldsJson').value=JSON.stringify(collectCustomFields());E('billingScheduleJson').value=billingSchedulePayload();E('priority').value=E('priorityEditor').value;var serviceLine=lines.find(function(l){return l.product_service_id&&String(l.item_type)==='service'});if(serviceLine){E('directServiceId').value=String(serviceLine.product_service_id);E('jobServiceId').value=String(serviceLine.product_service_id)}if(E('jobSource').value==='direct'&&!serviceLine&&!Number(E('directServiceId').value||0)){toast('warning','Select at least one service for a direct job.');return false}var email=schedules.some(function(s){return Number(s.email_team_about_assignment||0)===1});var old=document.querySelector('input[name="email_team_about_assignment"]');if(old)old.remove();var h=document.createElement('input');h.type='hidden';h.name='email_team_about_assignment';h.value=email?'1':'0';E('jobForm').appendChild(h);syncPaymentRows();updatePaymentScheduleSummary();E('noteMentionsJson').value=JSON.stringify(noteMentions);return true}

/* Source + existing load */
function setClientAndLocation(clientId,locationId){selectCustomerById(Number(clientId||0),Number(locationId||0))}
function importSourceLines(rows,label,fallbackService){lines=[];(rows||[]).forEach(function(x){lines.push(normalizeLine(x,label))});if(!lines.length&&fallbackService){var c=(meta.catalog_items||[]).find(function(x){return Number(x.product_service_id||0)===Number(fallbackService)});if(c)lines.push(normalizeLine(c,label))}if(!lines.length)lines.push(normalizeLine({quantity:1},label));renderLines()}
function loadQuoteSource(id){var fd=new FormData();fd.append('action','quote_details');fd.append('quote_id',id);fd.append('job_id',jobId);return req(fd).then(function(d){var q=d.quotation||{};meta.currency=d.currency||meta.currency;E('jobSource').value='quotation';E('quoteId').value=String(id);E('requestId').value=q.request_id||0;setClientAndLocation(q.client_id,q.location_id);if(jobId<=0&&jobNumberAuto)refreshJobNumberPreview(Number(q.branch_id||E('directBranchId').value||0));if(!E('title').value)E('title').value=q.title||q.request_title||'';if(Number(q.discount_total||0)>0){E('discountType').value=q.discount_type&&Number(q.discount_value||0)>0?q.discount_type:'fixed';E('discountValue').value=q.discount_type&&Number(q.discount_value||0)>0?Number(q.discount_value):Number(q.discount_total)}setSource('quotation',q);importSourceLines(d.line_items||[],'From Quote',q.product_service_id);syncSelectedTaxFromLines();updateTotals();return q})}
function loadRequestSource(id){var fd=new FormData();fd.append('action','request_details');fd.append('request_id',id);fd.append('job_id',jobId);return req(fd).then(function(d){var r=d.request||{};meta.currency=d.currency||meta.currency;E('jobSource').value='request';E('requestId').value=String(id);E('quoteId').value='0';setClientAndLocation(r.client_id,r.location_id);if(jobId<=0&&jobNumberAuto)refreshJobNumberPreview(Number(r.branch_id||E('directBranchId').value||0));if(!E('title').value)E('title').value=r.request_title||r.title||'';setSource('request',r);importSourceLines(d.line_items||[],'From Request',r.product_service_id);syncSelectedTaxFromLines();return r})}
function loadMeta(){var fd=new FormData();fd.append('action','meta');fd.append('job_id',jobId);return req(fd).then(function(d){meta=d.meta||meta;selectedTaxRateId=Number(meta.default_tax_rate_id||0);renderTaxMenu();updateTaxSelectorText();E('newPropertyCountry').innerHTML=countryOptions();E('newPropertyTaxRate').innerHTML=taxRateOptions('No tax rate created');E('newItemTaxRate').innerHTML=taxRateOptions('No tax');paymentScheduleRows=defaultPaymentRows();renderPaymentSchedule();initMainSelects();if(!jobId){setJobNumberMode(true);applyAutomaticJobNumber(meta.next_job_no_preview||'')}else{setJobNumberMode(false);E('jobNumberInput').placeholder='Job number'}mergeChecklistBootstrap();renderChecklistTemplates(jobId>0?[]:(checklistBootstrap.selected_template_ids||[]));if((checklistBootstrap.selected_template_ids||[]).length)setChecklistBody(true);E('recStartDate').value=today();E('recStartTime').value='09:00';E('recEndTime').value='10:00';var dow=new Date().getDay();recurrence.weekly_days=[dow];recurrence.monthly_day=new Date().getDate();recurrence.monthly_week=Math.ceil(new Date().getDate()/7);recurrence.monthly_weekday=dow;renderRecurrenceSummary();if(!jobId){visits=[newVisit({start_date:today()})];renderVisits();if(requestedQuote)return loadQuoteSource(requestedQuote);if(requestedRequest)return loadRequestSource(requestedRequest);setClientAndLocation(requestedClient,requestedLocation);if(requestedService){var c=(meta.catalog_items||[]).find(function(x){return Number(x.product_service_id||0)===requestedService});lines=[normalizeLine(c||{product_service_id:requestedService,item_type:'service',quantity:1})]}else lines=[normalizeLine({quantity:1})];renderLines();return null}})}
function loadExisting(){if(!jobId)return Promise.resolve();var fd=new FormData();fd.append('action','get');fd.append('job_id',jobId);return req(fd).then(function(d){var r=d.job||{},a=d.assignments||[],s=d.schedules||[],b=d.billing||{};meta=d.meta||meta;mergeChecklistBootstrap();initMainSelects();E('pageTitle').textContent='Edit Job';E('title').value=r.title||'';E('jobNumberInput').value=r.job_no||'';E('jobNumberInput').placeholder='Job number';setJobNumberMode(false);E('priorityEditor').value=r.priority||'normal';E('status').value=r.status||'scheduled';E('internalNote').value=d.internal_note||'';noteMentions=(d.note_mention_ids||[]).map(Number);renderMentionTags();E('discountType').value=r.discount_type||'';E('discountValue').value=r.discount_value||0;customFields=(d.custom_fields||[]).map(function(x){return{field_label:x.field_label||x.label||'',field_value:x.field_value||x.value||'',field_type:x.field_type||'text',options:x.options||[],default_value:x.default_value||'',is_transferable:Number(x.is_transferable||0)}});renderCustomFields();setClientAndLocation(r.client_id,r.location_id);if(r.quote_id){requestedQuote=Number(r.quote_id);E('quoteId').value=r.quote_id;E('jobSource').value='quotation';setSource('quotation',{id:r.quote_id,quote_no:r.quote_no,title:r.quote_title,client_name:r.client_name})}else if(r.request_id){requestedRequest=Number(r.request_id);E('requestId').value=r.request_id;E('jobSource').value='request';setSource('request',{id:r.request_id,request_id:r.request_id,request_no:r.request_no,request_title:r.request_title,client_name:r.client_name})}else E('jobSource').value='direct';lines=(d.line_items||[]).map(function(x){return normalizeLine(x,'')});if(!lines.length)lines=[normalizeLine({product_service_id:r.product_service_id,item_type:'service',quantity:1})];renderLines();renderChecklistTemplates(d.checklist_template_ids||[]);if((d.checklist_template_ids||[]).length)setChecklistBody(true);if(s.length&&s.some(function(x){return x.repeat_type&&x.repeat_type!=='none'})){setMode('recurring');var x=s[0];E('recStartDate').value=x.start_date||today();E('recStartTime').value=(x.start_time||'09:00').substring(0,5);E('recEndTime').value=(x.end_time||'10:00').substring(0,5);E('recAnytime').checked=Number(x.anytime||0)===1;E('recInstructions').value=x.instructions||'';E('emailTeamRecurring').checked=Number(x.email_team_about_assignment||0)===1;recurrence={repeat_type:x.repeat_type||'weekly',repeat_interval:Number(x.repeat_interval||1),weekly_days:(x.weekly_days||[]).map(Number),monthly_mode:x.monthly_mode||'day_of_month',monthly_day:x.monthly_day!=null?Number(x.monthly_day):new Date((x.start_date||today())+'T00:00:00').getDate(),monthly_week:Number(x.monthly_week||1),monthly_weekday:Number(x.monthly_weekday||0)};E('recPreset').value='custom';if(x.end_mode==='on_date'){document.querySelector('input[name="recEndMode"][value="on_date"]').checked=true;E('recEndDate').disabled=false;E('recEndDate').value=x.repeat_end_date||'';E('recEndValue').disabled=true;E('recEndUnit').disabled=true}else{document.querySelector('input[name="recEndMode"][value="after_duration"]').checked=true;E('recEndValue').value=x.end_after_value||6;E('recEndUnit').value=x.end_after_unit||'months'}if($('#recAssignees').hasClass('select2-hidden-accessible'))$('#recAssignees').select2('destroy');E('recAssignees').innerHTML=userOptions(x.assignee_ids||[]);initAssigneeSelect($('#recAssignees'));renderRecurrenceSummary()}else{setMode('one_off');visits=(s.length?s:[{start_date:r.start_date,start_time:r.start_time,end_time:r.end_time,assignee_ids:a.map(function(z){return Number(z.user_id)}),instructions:r.description||''}]).map(newVisit);renderVisits()}if(b&&typeof b==='object'){E('remindInvoiceOnClose').checked=Number(b.remind_to_invoice_on_close==null?1:b.remind_to_invoice_on_close)===1;E('splitPaymentSchedule').checked=Number(b.split_payment_schedule||0)===1;paymentSplitType=String(b.payment_split_type||'percentage');try{var ps=JSON.parse(b.payment_schedule_json||'[]');if(Array.isArray(ps)&&ps.length)paymentScheduleRows=ps}catch(e){}renderPaymentSchedule()}updateScheduleSummary();updateTotals()})}

$('#directClientId').on('select2:select',function(e){if(sourceLocked)return;var raw=e.params&&e.params.data?String(e.params.data.id||''):String(this.value||'');if(raw.indexOf('newclient:')===0){var term=raw.slice(10);initCustomerSelect(0);refreshLocations(0);renderCustomerCard();openCustomerModal(term);return}var cid=Number(raw||0),loc=cid>0?defaultLocationId(cid):0;refreshLocations(loc);renderCustomerCard();if(jobId<=0&&jobNumberAuto)refreshJobNumberPreview(Number(E('directBranchId').value||0))}).on('select2:clear',function(){if(sourceLocked)return;refreshLocations(0);renderCustomerCard()});
$('#serviceLocationSelect').on('select2:select',function(e){if(sourceLocked)return;var raw=e.params&&e.params.data?String(e.params.data.id||''):String(this.value||'');if(raw.indexOf('newproperty:')===0){var term=raw.slice(12),cid=Number($('#directClientId').val()||0);refreshLocations(0);E('locationPickerWrap').classList.add('show');openPropertyModal(term);return}E('directLocationId').value=raw||'';E('locationPickerWrap').classList.remove('show');renderCustomerCard()}).on('select2:clear',function(){if(sourceLocked)return;E('directLocationId').value='';renderCustomerCard()});
E('createCustomerButton').onclick=createCustomer;E('createPropertyButton').onclick=createProperty;E('openPropertyCustomField').onclick=openPropertyCustomFieldModal;E('createPropertyCustomFieldButton').onclick=createPropertyCustomField;E('openPropertyContact').onclick=openPropertyContactModal;E('addPropertyContactButton').onclick=addPropertyContact;E('pcfType').onchange=function(){var t=this.value;E('pcfOptionsWrap').style.display=t==='dropdown'?'block':'none';var h={text:'Example: Serial Number — 54A17-HEX',numeric:'Example: Equipment age — 5',boolean:'Example: Warranty active — Yes / No',area:'Example: Room size — 12 × 14',dropdown:'Add the choices that should appear in this field.'};E('pcfExample').textContent=h[t]||''};document.querySelectorAll('[data-property-section]').forEach(function(b){b.onclick=function(){var box=E(this.dataset.propertySection);box.classList.toggle('open');this.querySelector('i').className='bi '+(box.classList.contains('open')?'bi-chevron-up':'bi-chevron-down')}});E('propertyContactList').onclick=function(e){var b=e.target.closest('[data-remove-property-contact]');if(b){propertyContacts.splice(Number(b.dataset.removePropertyContact),1);renderPropertyContacts()}};E('clientMenuButton').onclick=function(e){e.stopPropagation();if(!sourceLocked)E('clientMenu').classList.toggle('show')};E('changeClientButton').onclick=function(){E('clientMenu').classList.remove('show');E('selectedClientCard').classList.remove('show');E('clientPickerWrap').style.display='block';E('locationPickerWrap').classList.remove('show');setTimeout(function(){try{$('#directClientId').select2('open')}catch(e){}},0)};E('changePropertyButton').onclick=function(){E('clientMenu').classList.remove('show');E('locationPickerWrap').classList.add('show');setTimeout(function(){try{$('#serviceLocationSelect').select2('open')}catch(e){}},0)};document.addEventListener('click',function(e){if(!e.target.closest('.jqj-customer-menu-wrap'))E('clientMenu').classList.remove('show')});
E('jobNumberInput').addEventListener('input',function(){if(jobId>0){setJobNumberMode(false);return}if(this.value.trim()===''){setJobNumberMode(true)}else if(this.value!==lastAutoJobNumber){setJobNumberMode(false)}});
E('jobNumberInput').addEventListener('blur',function(){if(jobId<=0&&this.value.trim()===''){setJobNumberMode(true);refreshJobNumberPreview(Number(E('directBranchId').value||0))}});
E('priorityEditor').onchange=function(){E('priority').value=this.value};
E('jobForm').addEventListener('submit',function(e){e.preventDefault();if(!this.reportValidity()){toast('warning','Complete the required fields.');return}if(!prepareSubmit())return;var fd=new FormData(this);fd.append('action','save');fd.delete('job_attachments[]');noteFiles.forEach(function(f){fd.append('job_attachments[]',f,f.name)});var b=E('saveJob');b.disabled=true;b.textContent='Saving...';req(fd).then(function(d){toast('success',d.message||'Job saved successfully.');setTimeout(function(){location.href='jobs'},1100)}).catch(function(err){toast('error',err.message)}).finally(function(){b.disabled=false;b.textContent='Save Job'})});

E('recStartDate').value=today();E('recStartTime').value='09:00';E('recEndTime').value='10:00';
loadMeta().then(loadExisting).catch(function(e){toast('error',e.message)});
})();
</script>
</body>
</html>
