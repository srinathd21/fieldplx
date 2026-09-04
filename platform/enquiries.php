<?php
/**
 * FieldPlx Platform - Website Enquiries
 * PHP 7.2+
 * PDO
 */

require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Website Enquiries';
$activePage = 'enquiries';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['fieldplx_enquiry_csrf']) || !is_string($_SESSION['fieldplx_enquiry_csrf'])) {
    $_SESSION['fieldplx_enquiry_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['fieldplx_enquiry_csrf'];

function enquiryEscape($value)
{
    return htmlspecialchars((string) ($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
}

function enquiryGet($key, $default = '')
{
    if (!isset($_GET[$key]) || is_array($_GET[$key])) {
        return $default;
    }
    return trim((string) $_GET[$key]);
}

function enquiryPost($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }
    return trim((string) $_POST[$key]);
}

function enquiryLabel($value)
{
    $value = trim((string) $value);
    return $value === '' ? '—' : ucwords(str_replace(array('_', '-'), ' ', $value));
}

function enquiryDateTime($value)
{
    if (empty($value)) {
        return '—';
    }
    $ts = strtotime((string) $value);
    return $ts === false ? '—' : date('d M Y, h:i A', $ts);
}

function enquiryUrl($changes = array())
{
    $query = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return '?' . http_build_query($query);
}

$platformToast = null;
if (isset($_SESSION['platform_toast']) && is_array($_SESSION['platform_toast'])) {
    $platformToast = $_SESSION['platform_toast'];
    unset($_SESSION['platform_toast']);
}

/* Update enquiry status + remark */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && enquiryPost('action') === 'update_enquiry') {
    $postedToken = enquiryPost('csrf_token');
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $_SESSION['platform_toast'] = array('type' => 'error', 'message' => 'Your form session expired. Please try again.');
    } else {
        $enquiryId = (int) enquiryPost('enquiry_id', '0');
        $newStatus = strtolower(enquiryPost('status'));
        $remark = enquiryPost('remark');
        $allowedStatuses = array('new', 'contacted', 'closed');

        if ($enquiryId <= 0) {
            $_SESSION['platform_toast'] = array('type' => 'error', 'message' => 'Invalid enquiry.');
        } elseif (!in_array($newStatus, $allowedStatuses, true)) {
            $_SESSION['platform_toast'] = array('type' => 'error', 'message' => 'Invalid enquiry status.');
        } elseif (strlen($remark) > 2000) {
            $_SESSION['platform_toast'] = array('type' => 'error', 'message' => 'Remark must be 2000 characters or less.');
        } else {
            try {
                $stmt = $pdo->prepare("\n                    UPDATE fieldplx_enquiry\n                    SET\n                        status = :status,\n                        remark = :remark,\n                        updated_at = NOW()\n                    WHERE id = :id\n                    LIMIT 1\n                ");
                $stmt->execute(array(
                    ':status' => $newStatus,
                    ':remark' => $remark !== '' ? $remark : null,
                    ':id' => $enquiryId
                ));

                $_SESSION['platform_toast'] = array(
                    'type' => 'success',
                    'message' => 'Enquiry updated successfully.'
                );
                $_SESSION['fieldplx_enquiry_csrf'] = bin2hex(random_bytes(32));
            } catch (PDOException $e) {
                error_log('FieldPlx enquiry update error: ' . $e->getMessage());
                $_SESSION['platform_toast'] = array(
                    'type' => 'error',
                    'message' => strpos($e->getMessage(), 'Unknown column') !== false
                        ? 'Enquiry remark column is missing. Run the supplied ALTER TABLE query first.'
                        : 'Unable to update enquiry. Please try again.'
                );
            }
        }
    }

    $redirect = 'enquiries.php';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $redirect .= '?' . $_SERVER['QUERY_STRING'];
    }
    header('Location: ' . $redirect);
    exit;
}

$search = enquiryGet('search');
$status = strtolower(enquiryGet('status'));
$reason = strtolower(enquiryGet('reason'));
$page = max(1, (int) enquiryGet('page', '1'));
$perPage = (int) enquiryGet('per_page', '10');
$allowedPerPage = array(10, 15, 25, 50, 100);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}

$allowedStatuses = array('', 'new', 'contacted', 'closed');
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$allowedReasons = array('', 'book_demo', 'start_trial', 'product_pricing', 'technical_support', 'partnership', 'media', 'general');
if (!in_array($reason, $allowedReasons, true)) {
    $reason = '';
}

$summary = $pdo->query("\n    SELECT\n        COUNT(*) AS total,\n        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS new_count,\n        SUM(CASE WHEN status = 'contacted' THEN 1 ELSE 0 END) AS contacted_count,\n        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed_count\n    FROM fieldplx_enquiry\n")->fetch();

$totalEnquiries = (int) ($summary['total'] ?? 0);
$newEnquiries = (int) ($summary['new_count'] ?? 0);
$contactedEnquiries = (int) ($summary['contacted_count'] ?? 0);
$closedEnquiries = (int) ($summary['closed_count'] ?? 0);

$where = array('1=1');
$params = array();
if ($search !== '') {
    $where[] = "(first_name LIKE :search OR last_name LIKE :search OR business_name LIKE :search OR email LIKE :search OR phone LIKE :search OR industry LIKE :search OR message LIKE :search OR COALESCE(remark,'') LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}
if ($status !== '') {
    $where[] = 'status = :status';
    $params[':status'] = $status;
}
if ($reason !== '') {
    $where[] = 'reason = :reason';
    $params[':reason'] = $reason;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM fieldplx_enquiry WHERE {$whereSql}");
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$countStmt->execute();
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare("\n    SELECT\n        id, first_name, last_name, business_name, email, phone, industry, employees, reason, message, status, remark, created_at, updated_at\n    FROM fieldplx_enquiry\n    WHERE {$whereSql}\n    ORDER BY created_at DESC, id DESC\n    LIMIT :limit OFFSET :offset\n");
foreach ($params as $key => $value) {
    $listStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$enquiries = $listStmt->fetchAll();

$startRecord = $totalRecords > 0 ? $offset + 1 : 0;
$endRecord = min($offset + $perPage, $totalRecords);
$paginationStart = max(1, $page - 2);
$paginationEnd = min($totalPages, $page + 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= enquiryEscape($pageTitle); ?> - FieldPlx</title>
<?php require_once __DIR__ . '/includes/links.php'; ?>
<style>
        :root {
            --fp-primary: #12182d;
            --fp-primary-2: #1c2250;
            --fp-primary-3: #201f6b;
            --fp-accent: #8b5cf6;
            --fp-accent-light: #a78bfa;
            --fp-accent-dark: #6d28d9;
            --fp-text: #20213f;
            --fp-muted: #6f6b8f;
            --fp-border: #ded9ef;
            --fp-bg: #ffffff;
            --fp-surface: #ffffff;
            --fp-surface-soft: #f8f6ff;
            --fp-success: #059669;
            --fp-warning: #d97706;
            --fp-danger: #dc2626;
            --fp-info: #6366f1;
            --fp-sidebar-width: 260px;
            --fp-sidebar-collapsed-width: 76px;
            --fp-topbar-height: 66px;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            background: #ffffff;
            color: var(--fp-text);
            font-family: "Inter", sans-serif;
            font-size: 13px;
        }

        a {
            text-decoration: none;
        }

        .fp-layout {
            min-height: 100vh;
        }

        .fp-main {
            min-height: calc(100vh - 52px);
            margin-left: var(--fp-sidebar-width);
            transition: margin-left .22s ease;
        }

        body.fp-sidebar-collapsed .fp-main {
            margin-left: var(--fp-sidebar-collapsed-width);
        }

        /* =========================================================
           SHARED FIELDPLX TOPBAR UI
           Matches Platform Dashboard
        ========================================================= */

        .fp-topbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            min-height: var(--fp-topbar-height);
            border-bottom: 1px solid #ded8f3;
            background: rgba(248, 246, 255, .96);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .fp-topbar-inner {
            min-height: var(--fp-topbar-height);
            padding: 8px 18px;
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .fp-menu-toggle,
        .fp-icon-button {
            width: 39px;
            height: 39px;
            min-width: 39px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #d9d2ef;
            border-radius: 10px;
            background: #ffffff;
            color: #39345f;
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
            transition: .16s ease;
        }

        .fp-menu-toggle:hover,
        .fp-icon-button:hover {
            border-color: #bda9ff;
            background: #f4f0ff;
            color: var(--fp-accent-dark);
        }

        .fp-page-heading {
            min-width: 0;
            margin-right: auto;
        }

        .fp-page-title {
            margin: 0;
            overflow: hidden;
            color: #17172e;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.25;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .fp-page-subtitle {
            margin-top: 2px;
            color: var(--fp-muted);
            font-size: 10px;
            line-height: 1.3;
        }

        .fp-search {
            width: min(340px, 31vw);
            position: relative;
            flex: 0 1 340px;
        }

        .fp-search i {
            position: absolute;
            top: 50%;
            left: 12px;
            z-index: 2;
            transform: translateY(-50%);
            color: #8f88aa;
            font-size: 14px;
            pointer-events: none;
        }

        .fp-search input {
            width: 100%;
            height: 39px;
            padding: 8px 13px 8px 36px;
            border: 1px solid #dcd5ef;
            border-radius: 10px;
            outline: none;
            background: #f8f6ff;
            color: #292640;
            box-shadow: none;
            font-family: inherit;
            font-size: 12px;
        }

        .fp-search input::placeholder {
            color: #77718e;
        }

        .fp-search input:focus {
            border-color: #a78bfa;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, .12);
        }

        .fp-notification-wrap {
            position: relative;
            flex: 0 0 auto;
        }

        .fp-notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            z-index: 3;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
            border-radius: 999px;
            background: var(--fp-danger);
            color: #ffffff;
            font-size: 9px;
            font-weight: 700;
            line-height: 1;
        }

        .fp-profile {
            min-width: 0;
            padding: 4px 9px 4px 5px;
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid var(--fp-border);
            border-radius: 11px;
            background: #ffffff;
            color: inherit;
            cursor: pointer;
        }

        .fp-avatar {
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: linear-gradient(135deg, #6d4df4, #9a5cff);
            color: #ffffff;
            font-size: 10px;
            font-weight: 700;
        }

        .fp-profile-text {
            max-width: 145px;
            min-width: 0;
        }

        .fp-profile-name,
        .fp-profile-role {
            overflow: hidden;
            display: block;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .fp-profile-name {
            color: #111827;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.25;
        }

        .fp-profile-role {
            margin-top: 1px;
            color: var(--fp-muted);
            font-size: 9px;
            line-height: 1.25;
        }

        .fp-mobile-brand {
            display: none;
        }

        .fp-mobile-brand-logo {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: linear-gradient(135deg, #6d4df4, #9a5cff);
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
        }

        .fp-content {
            padding: 18px;
            background: #ffffff;
        }


.enquiry-page{display:grid;gap:16px}.enquiry-header{display:flex;align-items:flex-start;justify-content:space-between;gap:15px}.enquiry-title{margin:0;color:#111827;font-size:20px;font-weight:800}.enquiry-description{margin-top:4px;color:#77718e;font-size:10px}.enquiry-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.enquiry-summary-card{padding:14px 15px;display:flex;align-items:center;gap:11px;border:1px solid #ddd5f1;border-radius:13px;background:linear-gradient(180deg,#fff 0%,#fbf9ff 100%)}.enquiry-summary-icon{width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;border-radius:10px;background:#eee8ff;color:#7c3aed;font-size:16px}.enquiry-summary-label{color:#9a94ae;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}.enquiry-summary-value{margin-top:2px;display:block;color:#111827;font-size:20px;font-weight:800}.enquiry-card{overflow:hidden;border:1px solid #ded7ef;border-radius:14px;background:#fff;box-shadow:0 8px 24px rgba(37,29,80,.05)}.enquiry-toolbar{padding:13px;display:grid;grid-template-columns:minmax(260px,1.5fr) minmax(150px,.5fr) minmax(180px,.65fr) 110px;gap:10px;align-items:start;border-bottom:1px solid #ece7f7;background:#fbf9ff}.enquiry-search{position:relative}.enquiry-search i{position:absolute;top:19px;left:12px;transform:translateY(-50%);color:#8f88aa;font-size:13px}.enquiry-control{width:100%;height:38px;border:1px solid #dcd5ef;border-radius:9px;background:#fff;color:#39345f;font-size:10px;box-shadow:none}.enquiry-search .enquiry-control{padding-left:35px}.enquiry-control:focus{border-color:#a78bfa;box-shadow:0 0 0 3px rgba(139,92,246,.10)}.enquiry-table-wrap{width:100%;overflow-x:auto}.enquiry-table{width:100%;min-width:1350px;border-collapse:collapse;white-space:nowrap}.enquiry-table th{padding:11px 13px;border-bottom:1px solid #ece7f7;background:#f6f2ff;color:#847d9e;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}.enquiry-table td{padding:12px 13px;border-bottom:1px solid #f1eff6;color:#4f4a64;font-size:10px;vertical-align:middle}.enquiry-table tbody tr:hover{background:#fcfbff}.enquiry-person strong{display:block;color:#2f2940;font-size:10px}.enquiry-person span,.enquiry-muted{display:block;margin-top:2px;color:#918b9d;font-size:8px}.enquiry-reason{padding:5px 8px;display:inline-flex;border-radius:7px;background:#f0ebff;color:#6d28d9;font-size:8px;font-weight:700}.enquiry-status{padding:4px 8px;display:inline-flex;border-radius:999px;font-size:8px;font-weight:700}.enquiry-status.new{background:#fef3c7;color:#b45309}.enquiry-status.contacted{background:#dbeafe;color:#1d4ed8}.enquiry-status.closed{background:#d1fae5;color:#047857}.enquiry-message{max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.enquiry-action{width:29px;height:29px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid #e1dcef;border-radius:8px;background:#fff;color:#615a75;font-size:12px;cursor:pointer}.enquiry-action:hover{border-color:#bca7ff;background:#f4f0ff;color:#6d28d9}.enquiry-empty{padding:50px 20px;text-align:center;color:#9992a8}.enquiry-pagination-bar{min-height:56px;padding:10px 13px;display:flex;align-items:center;gap:12px;border-top:1px solid #ece7f7}.enquiry-pagination-info{color:#817a91;font-size:9px}.enquiry-pagination{margin:0 0 0 auto;padding:0;display:flex;align-items:center;gap:5px;list-style:none}.enquiry-pagination a,.enquiry-pagination span{min-width:30px;height:30px;padding:0 8px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #e0daee;border-radius:8px;background:#fff;color:#5e586d;font-size:9px;font-weight:600}.enquiry-pagination .active{border-color:#7c3aed;background:#7c3aed;color:#fff}.enquiry-pagination .disabled{color:#c1bbc9}.enquiry-toast{position:fixed;top:82px;right:20px;z-index:20000;width:min(380px,calc(100vw - 24px));padding:12px 14px;display:flex;align-items:center;gap:10px;border:0;border-radius:11px;color:#fff;box-shadow:0 16px 34px rgba(16,24,40,.18);opacity:0;visibility:hidden;transform:translateY(-10px);transition:.2s;font-size:10px}.enquiry-toast.show{opacity:1;visibility:visible;transform:translateY(0)}.enquiry-toast.success{background:#059669}.enquiry-toast.error{background:#dc2626}.enquiry-toast.warning{background:#d97706}.enquiry-toast.info{background:#4f46e5}.enquiry-toast-close{margin-left:auto;border:0;background:transparent;color:#fff;font-size:15px}.modal-content{border:0;border-radius:14px;box-shadow:0 20px 50px rgba(37,29,80,.18)}.modal-header{border-bottom:1px solid #ece7f7;background:#fbf9ff}.modal-title{font-size:13px;font-weight:800;color:#251f39}.modal-label{display:block;margin-bottom:5px;color:#554d68;font-size:9px;font-weight:700}.modal-control{width:100%;border:1px solid #dcd5ef;border-radius:9px;font-size:10px;box-shadow:none}.modal-control.form-select{height:39px}.modal-control.form-control{min-height:90px;resize:vertical}.modal-enquiry-info{padding:10px;border:1px solid #e5def4;border-radius:9px;background:#faf8ff;font-size:9px;line-height:1.55;color:#625a74}.modal-footer{border-top:1px solid #ece7f7}.btn-enquiry-save{border:0;border-radius:9px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;font-size:10px;font-weight:700;padding:9px 14px}
@media(max-width:1100px){.enquiry-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.enquiry-toolbar{grid-template-columns:repeat(2,minmax(0,1fr))}.enquiry-search{grid-column:span 2}}@media(max-width:991.98px){.fp-main,body.fp-sidebar-collapsed .fp-main{margin-left:0}}@media(max-width:700px){.enquiry-summary,.enquiry-toolbar{grid-template-columns:1fr}.enquiry-search{grid-column:span 1}.enquiry-pagination-bar{align-items:flex-start;flex-direction:column}.enquiry-pagination{margin-left:0;flex-wrap:wrap}}@media(max-width:575.98px){.fp-content{padding:12px}.enquiry-toast{top:74px;right:12px;left:12px;width:auto}}


        @media (max-width: 991.98px) {
            .fp-main,
            body.fp-sidebar-collapsed .fp-main { margin-left: 0; }
            .fp-search { display: none; }
            .fp-profile-text { display: none; }
            .fp-mobile-brand {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: #ffffff;
                font-weight: 700;
            }
        }
        @media (max-width: 575.98px) {
            .fp-topbar-inner { padding: 8px 11px; }
            .fp-page-subtitle { display: none; }
            .fp-page-title { font-size: 13px; }
        }
</style>
</head>
<body>
<div class="fp-layout">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<main class="fp-main">
<?php require_once __DIR__ . '/includes/topbar.php'; ?>
<div class="fp-content">
<div class="enquiry-page">
    <div class="enquiry-header">
        <div>
            <h2 class="enquiry-title">Website Enquiries</h2>
            <div class="enquiry-description">Review enquiries submitted from the FieldPlx website, update follow-up status and save internal remarks.</div>
        </div>
    </div>

    <section class="enquiry-summary">
        <article class="enquiry-summary-card"><span class="enquiry-summary-icon"><i class="bi bi-inbox"></i></span><span><span class="enquiry-summary-label">Total Enquiries</span><span class="enquiry-summary-value"><?= number_format($totalEnquiries); ?></span></span></article>
        <article class="enquiry-summary-card"><span class="enquiry-summary-icon"><i class="bi bi-envelope"></i></span><span><span class="enquiry-summary-label">New</span><span class="enquiry-summary-value"><?= number_format($newEnquiries); ?></span></span></article>
        <article class="enquiry-summary-card"><span class="enquiry-summary-icon"><i class="bi bi-telephone-check"></i></span><span><span class="enquiry-summary-label">Contacted</span><span class="enquiry-summary-value"><?= number_format($contactedEnquiries); ?></span></span></article>
        <article class="enquiry-summary-card"><span class="enquiry-summary-icon"><i class="bi bi-check-circle"></i></span><span><span class="enquiry-summary-label">Closed</span><span class="enquiry-summary-value"><?= number_format($closedEnquiries); ?></span></span></article>
    </section>

    <section class="enquiry-card">
        <form method="get" action="enquiries.php" class="enquiry-toolbar" id="enquiryFilterForm">
            <div class="enquiry-search"><i class="bi bi-search"></i><input type="search" name="search" id="enquirySearchInput" class="form-control enquiry-control" value="<?= enquiryEscape($search); ?>" placeholder="Search name, business, email, phone, industry, message or remark..."></div>
            <select name="status" class="form-select enquiry-control" id="enquiryStatusFilter">
                <option value="">All Status</option>
                <?php foreach (array('new'=>'New','contacted'=>'Contacted','closed'=>'Closed') as $value=>$label): ?><option value="<?= $value; ?>" <?= $status === $value ? 'selected' : ''; ?>><?= $label; ?></option><?php endforeach; ?>
            </select>
            <select name="reason" class="form-select enquiry-control" id="enquiryReasonFilter">
                <option value="">All Reasons</option>
                <?php foreach (array('book_demo'=>'Book a demonstration','start_trial'=>'Start a free trial','product_pricing'=>'Product or pricing question','technical_support'=>'Technical support','partnership'=>'Partnership opportunity','media'=>'Media inquiry','general'=>'General question') as $value=>$label): ?><option value="<?= $value; ?>" <?= $reason === $value ? 'selected' : ''; ?>><?= enquiryEscape($label); ?></option><?php endforeach; ?>
            </select>
            <select name="per_page" class="form-select enquiry-control" id="enquiryPerPage"><?php foreach ($allowedPerPage as $size): ?><option value="<?= (int)$size; ?>" <?= $perPage === $size ? 'selected' : ''; ?>><?= (int)$size; ?> / page</option><?php endforeach; ?></select>
            <input type="hidden" name="page" id="enquiryPageInput" value="1">
        </form>

        <?php if (empty($enquiries)): ?>
            <div class="enquiry-empty"><i class="bi bi-inbox" style="font-size:32px"></i><h3 style="margin:10px 0 4px;font-size:13px;color:#312c40">No enquiries found</h3><p style="margin:0;font-size:9px">Change the search or filters and try again.</p></div>
        <?php else: ?>
            <div class="enquiry-table-wrap">
                <table class="enquiry-table">
                    <thead><tr><th>S.No</th><th>Customer</th><th>Business / Industry</th><th>Reason</th><th>Message</th><th>Status</th><th>Remark</th><th>Received</th><th style="text-align:right">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($enquiries as $index => $row): ?>
                        <?php $fullName = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']); ?>
                        <tr>
                            <td><?= (int)($offset + $index + 1); ?></td>
                            <td><div class="enquiry-person"><strong><?= enquiryEscape($fullName); ?></strong><span><?= enquiryEscape($row['email']); ?></span><span><?= enquiryEscape(!empty($row['phone']) ? $row['phone'] : '—'); ?></span></div></td>
                            <td><div class="enquiry-person"><strong><?= enquiryEscape(!empty($row['business_name']) ? $row['business_name'] : '—'); ?></strong><span><?= enquiryEscape(!empty($row['industry']) ? $row['industry'] : '—'); ?><?= !empty($row['employees']) ? ' · ' . enquiryEscape($row['employees']) . ' employees' : ''; ?></span></div></td>
                            <td><span class="enquiry-reason"><?= enquiryEscape(enquiryLabel($row['reason'])); ?></span></td>
                            <td><div class="enquiry-message" title="<?= enquiryEscape($row['message']); ?>"><?= enquiryEscape($row['message']); ?></div></td>
                            <td><span class="enquiry-status <?= enquiryEscape($row['status']); ?>"><?= enquiryEscape(enquiryLabel($row['status'])); ?></span></td>
                            <td><div class="enquiry-message" title="<?= enquiryEscape($row['remark']); ?>"><?= enquiryEscape(!empty($row['remark']) ? $row['remark'] : '—'); ?></div></td>
                            <td><div class="enquiry-person"><strong><?= enquiryEscape(enquiryDateTime($row['created_at'])); ?></strong><?php if (!empty($row['updated_at'])): ?><span>Updated <?= enquiryEscape(enquiryDateTime($row['updated_at'])); ?></span><?php endif; ?></div></td>
                            <td style="text-align:right"><button type="button" class="enquiry-action js-enquiry-update" title="Update / Remark" data-bs-toggle="modal" data-bs-target="#enquiryUpdateModal" data-id="<?= (int)$row['id']; ?>" data-name="<?= enquiryEscape($fullName); ?>" data-email="<?= enquiryEscape($row['email']); ?>" data-status="<?= enquiryEscape($row['status']); ?>" data-remark="<?= enquiryEscape($row['remark']); ?>" data-message="<?= enquiryEscape($row['message']); ?>"><i class="bi bi-pencil-square"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="enquiry-pagination-bar">
            <div class="enquiry-pagination-info">Showing <?= number_format($startRecord); ?> to <?= number_format($endRecord); ?> of <?= number_format($totalRecords); ?> enquiries</div>
            <?php if ($totalPages > 1): ?><ul class="enquiry-pagination">
                <li><?php if ($page > 1): ?><a href="<?= enquiryEscape(enquiryUrl(array('page'=>$page-1))); ?>"><i class="bi bi-chevron-left"></i></a><?php else: ?><span class="disabled"><i class="bi bi-chevron-left"></i></span><?php endif; ?></li>
                <?php for ($p=$paginationStart;$p<=$paginationEnd;$p++): ?><li><?php if ($p===$page): ?><span class="active"><?= $p; ?></span><?php else: ?><a href="<?= enquiryEscape(enquiryUrl(array('page'=>$p))); ?>"><?= $p; ?></a><?php endif; ?></li><?php endfor; ?>
                <li><?php if ($page < $totalPages): ?><a href="<?= enquiryEscape(enquiryUrl(array('page'=>$page+1))); ?>"><i class="bi bi-chevron-right"></i></a><?php else: ?><span class="disabled"><i class="bi bi-chevron-right"></i></span><?php endif; ?></li>
            </ul><?php endif; ?>
        </div>
    </section>
</div>
</div>
</main>
</div>

<div class="modal fade" id="enquiryUpdateModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form method="post" action="<?= enquiryEscape('enquiries.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')); ?>">
<input type="hidden" name="action" value="update_enquiry"><input type="hidden" name="csrf_token" value="<?= enquiryEscape($csrfToken); ?>"><input type="hidden" name="enquiry_id" id="modalEnquiryId" value="">
<div class="modal-header"><h5 class="modal-title">Update Enquiry</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body">
    <div class="modal-enquiry-info" id="modalEnquiryInfo">Select an enquiry.</div>
    <div style="margin-top:13px"><label class="modal-label" for="modalEnquiryStatus">Status</label><select class="form-select modal-control" id="modalEnquiryStatus" name="status" required><option value="new">New</option><option value="contacted">Contacted</option><option value="closed">Closed</option></select></div>
    <div style="margin-top:13px"><label class="modal-label" for="modalEnquiryRemark">Remark</label><textarea class="form-control modal-control" id="modalEnquiryRemark" name="remark" maxlength="2000" placeholder="Enter follow-up remark, call note, next action, etc."></textarea><div style="margin-top:5px;color:#918b9d;font-size:8px">Remark is saved internally and is not shown to the website customer.</div></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal" style="font-size:10px">Cancel</button><button type="submit" class="btn-enquiry-save"><i class="bi bi-check2-circle"></i> Save Update</button></div>
</form>
</div></div></div>

<div id="enquiryToast" class="enquiry-toast" role="status"><span id="enquiryToastMessage"></span><button type="button" class="enquiry-toast-close" id="enquiryToastClose"><i class="bi bi-x-lg"></i></button></div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
'use strict';
var form=document.getElementById('enquiryFilterForm');
var pageInput=document.getElementById('enquiryPageInput');
var search=document.getElementById('enquirySearchInput');
var timer=null;
function submitFilters(){if(pageInput){pageInput.value='1';}if(form){form.submit();}}
if(search){search.addEventListener('input',function(){window.clearTimeout(timer);timer=window.setTimeout(submitFilters,700);});search.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();window.clearTimeout(timer);submitFilters();}});}
['enquiryStatusFilter','enquiryReasonFilter','enquiryPerPage'].forEach(function(id){var el=document.getElementById(id);if(el){el.addEventListener('change',submitFilters);}});

document.querySelectorAll('.js-enquiry-update').forEach(function(btn){btn.addEventListener('click',function(){document.getElementById('modalEnquiryId').value=btn.getAttribute('data-id')||'';document.getElementById('modalEnquiryStatus').value=btn.getAttribute('data-status')||'new';document.getElementById('modalEnquiryRemark').value=btn.getAttribute('data-remark')||'';var name=btn.getAttribute('data-name')||'';var email=btn.getAttribute('data-email')||'';var message=btn.getAttribute('data-message')||'';document.getElementById('modalEnquiryInfo').textContent=name+' · '+email+' — '+message;});});

var toast=document.getElementById('enquiryToast');var toastMessage=document.getElementById('enquiryToastMessage');var toastClose=document.getElementById('enquiryToastClose');var toastTimer=null;
function showToast(type,message){if(!toast)return;toast.className='enquiry-toast '+(type||'info')+' show';toastMessage.textContent=message||'Notification';if(toastTimer)window.clearTimeout(toastTimer);toastTimer=window.setTimeout(function(){toast.classList.remove('show');},3000);}window.showEnquiryToast=showToast;if(toastClose){toastClose.addEventListener('click',function(){toast.classList.remove('show');});}
<?php if (is_array($platformToast) && !empty($platformToast['message'])): ?>showToast(<?= json_encode(isset($platformToast['type'])?$platformToast['type']:'info'); ?>,<?= json_encode($platformToast['message']); ?>);<?php endif; ?>

var body=document.body;var toggle=document.getElementById('fpSidebarToggle');var close=document.getElementById('fpSidebarClose');var overlay=document.getElementById('fpSidebarOverlay');var key='fieldplx_sidebar_collapsed';function restore(){if(window.innerWidth<992){body.classList.remove('fp-sidebar-collapsed');return;}if(localStorage.getItem(key)==='1'){body.classList.add('fp-sidebar-collapsed');}else{body.classList.remove('fp-sidebar-collapsed');}}restore();if(toggle){toggle.addEventListener('click',function(){if(window.innerWidth<992){body.classList.toggle('fp-sidebar-mobile-open');return;}body.classList.toggle('fp-sidebar-collapsed');localStorage.setItem(key,body.classList.contains('fp-sidebar-collapsed')?'1':'0');});}if(close){close.addEventListener('click',function(){body.classList.remove('fp-sidebar-mobile-open');});}if(overlay){overlay.addEventListener('click',function(){body.classList.remove('fp-sidebar-mobile-open');});}
})();
</script>
</body>
</html>
