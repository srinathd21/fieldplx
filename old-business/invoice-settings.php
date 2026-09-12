<?php
/* FieldPlx Invoice Settings - Version 1.1.0 - 2026-08-28 */

require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Invoice Settings';
$activePage = 'invoice_settings';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['invoice_settings_csrf_token'])) {
    $_SESSION['invoice_settings_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['invoice_settings_csrf_token'];

function fisDb()
{
    global $pdo, $db;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    throw new RuntimeException('Database connection is not available.');
}

function fisH($value)
{
    return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
}

function fisJson($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) { @ob_end_clean(); }

    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        array_merge(
            array(
                'success' => (bool)$success,
                'message' => (string)$message,
                'version' => '1.1.0'
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function fisTableExists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name"
    );
    $stmt->execute(array(':table_name' => $table));
    return ((int)$stmt->fetchColumn() > 0);
}

function fisLoadTenant(PDO $pdo, $tenantId)
{
    $stmt = $pdo->prepare(
        "SELECT
            id,
            display_name,
            legal_name,
            registration_number,
            tax_number,
            email,
            phone,
            alternate_phone,
            website_url,
            logo_path,
            invoice_logo_path,
            address_line1,
            address_line2,
            city,
            state,
            postal_code,
            currency_id
         FROM tenants
         WHERE id = :tenant_id
           AND deleted_at IS NULL
         LIMIT 1"
    );

    $stmt->execute(array(':tenant_id' => $tenantId));
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function fisLoadBranches(PDO $pdo, $tenantId)
{
    $stmt = $pdo->prepare(
        "SELECT
            id,
            branch_code,
            name,
            email,
            phone,
            address_line1,
            address_line2,
            city,
            state,
            postal_code,
            logo_path,
            invoice_logo_path
         FROM branches
         WHERE tenant_id = :tenant_id
           AND status <> 'archived'
         ORDER BY is_head_office DESC, name ASC"
    );

    $stmt->execute(array(':tenant_id' => $tenantId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fisBranch(PDO $pdo, $tenantId, $branchId)
{
    if ($branchId <= 0) return null;

    $stmt = $pdo->prepare(
        "SELECT
            id,
            branch_code,
            name,
            email,
            phone,
            address_line1,
            address_line2,
            city,
            state,
            postal_code,
            logo_path,
            invoice_logo_path
         FROM branches
         WHERE id = :branch_id
           AND tenant_id = :tenant_id
           AND status <> 'archived'
         LIMIT 1"
    );

    $stmt->execute(array(
        ':branch_id' => $branchId,
        ':tenant_id' => $tenantId
    ));

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function fisLoadSettings(PDO $pdo, $tenantId, $branchId)
{
    if (!fisTableExists($pdo, 'invoice_settings')) {
        return null;
    }

    if ($branchId > 0) {
        $stmt = $pdo->prepare(
            "SELECT *
             FROM invoice_settings
             WHERE tenant_id = :tenant_id
               AND branch_id = :branch_id
             LIMIT 1"
        );

        $stmt->execute(array(
            ':tenant_id' => $tenantId,
            ':branch_id' => $branchId
        ));
    } else {
        $stmt = $pdo->prepare(
            "SELECT *
             FROM invoice_settings
             WHERE tenant_id = :tenant_id
               AND branch_id IS NULL
             LIMIT 1"
        );

        $stmt->execute(array(':tenant_id' => $tenantId));
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fisUpload($fieldName, $tenantId, $branchId, $type)
{
    if (
        !isset($_FILES[$fieldName]) ||
        !is_array($_FILES[$fieldName]) ||
        (int)$_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ((int)$_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Unable to upload ' . $type . '.');
    }

    if ((int)$_FILES[$fieldName]['size'] > 4 * 1024 * 1024) {
        throw new RuntimeException(ucfirst($type) . ' must be 4 MB or smaller.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES[$fieldName]['tmp_name']);

    $allowed = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    );

    if (!isset($allowed[$mime])) {
        throw new RuntimeException(
            ucfirst($type) . ' must be JPG, PNG or WEBP.'
        );
    }

    $folder = 'uploads/invoice-settings/tenant-' . (int)$tenantId;
    if ($branchId > 0) {
        $folder .= '/branch-' . (int)$branchId;
    } else {
        $folder .= '/business-default';
    }

    $absoluteFolder = __DIR__ . '/' . $folder;

    if (
        !is_dir($absoluteFolder) &&
        !@mkdir($absoluteFolder, 0755, true) &&
        !is_dir($absoluteFolder)
    ) {
        throw new RuntimeException('Unable to create invoice settings upload directory.');
    }

    $filename =
        $type . '-' .
        date('YmdHis') . '-' .
        bin2hex(random_bytes(4)) . '.' .
        $allowed[$mime];

    $relativePath = $folder . '/' . $filename;
    $absolutePath = __DIR__ . '/' . $relativePath;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Unable to save ' . $type . '.');
    }

    return $relativePath;
}

$pdo = fisDb();

$tenantId =
    isset($_SESSION['tenant_id'])
        ? (int)$_SESSION['tenant_id']
        : (
            isset($_SESSION['business_id'])
                ? (int)$_SESSION['business_id']
                : 0
        );

$userId =
    isset($_SESSION['tenant_user_id'])
        ? (int)$_SESSION['tenant_user_id']
        : (
            isset($_SESSION['user_id'])
                ? (int)$_SESSION['user_id']
                : 0
        );

if ($tenantId <= 0 || $userId <= 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fisJson(401, false, 'Authentication required.');
    }

    header('Location: login.php');
    exit;
}

$tenant = fisLoadTenant($pdo, $tenantId);

if (!$tenant) {
    http_response_code(404);
    die('Tenant not found.');
}

$branches = fisLoadBranches($pdo, $tenantId);

$selectedBranchId =
    isset($_GET['branch_id'])
        ? max(0, (int)$_GET['branch_id'])
        : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrf = isset($_POST['csrf_token'])
        ? (string)$_POST['csrf_token']
        : '';

    if ($csrf === '' || !hash_equals($csrfToken, $csrf)) {
        fisJson(419, false, 'Your form session expired. Refresh the page and try again.');
    }

    $action = isset($_POST['action'])
        ? trim((string)$_POST['action'])
        : '';

    if ($action !== 'save') {
        fisJson(400, false, 'Unsupported action.');
    }

    if (!fisTableExists($pdo, 'invoice_settings')) {
        fisJson(
            500,
            false,
            'invoice_settings table is missing. Run the provided invoice-settings.sql migration first.'
        );
    }

    try {
        $branchId =
            isset($_POST['branch_id'])
                ? max(0, (int)$_POST['branch_id'])
                : 0;

        if ($branchId > 0 && !fisBranch($pdo, $tenantId, $branchId)) {
            fisJson(422, false, 'Invalid branch selected.');
        }

        $current = fisLoadSettings($pdo, $tenantId, $branchId);

        $companyName = trim((string)($_POST['company_name'] ?? ''));
        $legalName = trim((string)($_POST['legal_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $website = trim((string)($_POST['website_url'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $alternatePhone = trim((string)($_POST['alternate_phone'] ?? ''));
        $registrationNumber = trim((string)($_POST['registration_number'] ?? ''));
        $taxNumber = trim((string)($_POST['tax_number'] ?? ''));
        $address1 = trim((string)($_POST['address_line1'] ?? ''));
        $address2 = trim((string)($_POST['address_line2'] ?? ''));
        $city = trim((string)($_POST['city'] ?? ''));
        $state = trim((string)($_POST['state'] ?? ''));
        $postalCode = trim((string)($_POST['postal_code'] ?? ''));
        $invoiceTitle = trim((string)($_POST['invoice_title'] ?? 'Invoice'));
        $signatoryName = trim((string)($_POST['authorized_signatory_name'] ?? ''));
        $footerNote = trim((string)($_POST['footer_note'] ?? ''));
        $terms = trim((string)($_POST['terms_and_conditions'] ?? ''));

        if ($companyName === '') {
            fisJson(422, false, 'Company name is required.');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fisJson(422, false, 'Enter a valid company email.');
        }

        if (
            $website !== '' &&
            !filter_var($website, FILTER_VALIDATE_URL)
        ) {
            fisJson(422, false, 'Enter a valid website URL including https://');
        }

        $logoPath = fisUpload('logo', $tenantId, $branchId, 'logo');
        $invoiceLogoPath = fisUpload('invoice_logo', $tenantId, $branchId, 'invoice-logo');
        $signaturePath = fisUpload('signature', $tenantId, $branchId, 'signature');

        if ($logoPath === null && $current) {
            $logoPath = $current['logo_path'];
        }

        if ($invoiceLogoPath === null && $current) {
            $invoiceLogoPath = $current['invoice_logo_path'];
        }

        if ($signaturePath === null && $current) {
            $signaturePath = $current['signature_path'];
        }

        $pdo->beginTransaction();

        if ($current) {
            $stmt = $pdo->prepare(
                "UPDATE invoice_settings
                 SET
                    company_name = :company_name,
                    legal_name = :legal_name,
                    email = :email,
                    website_url = :website_url,
                    phone = :phone,
                    alternate_phone = :alternate_phone,
                    registration_number = :registration_number,
                    tax_number = :tax_number,
                    address_line1 = :address_line1,
                    address_line2 = :address_line2,
                    city = :city,
                    state = :state,
                    postal_code = :postal_code,
                    logo_path = :logo_path,
                    invoice_logo_path = :invoice_logo_path,
                    signature_path = :signature_path,
                    authorized_signatory_name = :authorized_signatory_name,
                    invoice_title = :invoice_title,
                    footer_note = :footer_note,
                    terms_and_conditions = :terms_and_conditions,
                    updated_by = :updated_by,
                    updated_at = NOW()
                 WHERE id = :id
                   AND tenant_id = :tenant_id"
            );

            $stmt->execute(array(
                ':company_name' => $companyName,
                ':legal_name' => $legalName !== '' ? $legalName : null,
                ':email' => $email !== '' ? $email : null,
                ':website_url' => $website !== '' ? $website : null,
                ':phone' => $phone !== '' ? $phone : null,
                ':alternate_phone' => $alternatePhone !== '' ? $alternatePhone : null,
                ':registration_number' => $registrationNumber !== '' ? $registrationNumber : null,
                ':tax_number' => $taxNumber !== '' ? $taxNumber : null,
                ':address_line1' => $address1 !== '' ? $address1 : null,
                ':address_line2' => $address2 !== '' ? $address2 : null,
                ':city' => $city !== '' ? $city : null,
                ':state' => $state !== '' ? $state : null,
                ':postal_code' => $postalCode !== '' ? $postalCode : null,
                ':logo_path' => $logoPath,
                ':invoice_logo_path' => $invoiceLogoPath,
                ':signature_path' => $signaturePath,
                ':authorized_signatory_name' => $signatoryName !== '' ? $signatoryName : null,
                ':invoice_title' => $invoiceTitle !== '' ? $invoiceTitle : 'Invoice',
                ':footer_note' => $footerNote !== '' ? $footerNote : null,
                ':terms_and_conditions' => $terms !== '' ? $terms : null,
                ':updated_by' => $userId,
                ':id' => (int)$current['id'],
                ':tenant_id' => $tenantId
            ));
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO invoice_settings
                (
                    tenant_id,
                    branch_id,
                    company_name,
                    legal_name,
                    email,
                    website_url,
                    phone,
                    alternate_phone,
                    registration_number,
                    tax_number,
                    address_line1,
                    address_line2,
                    city,
                    state,
                    postal_code,
                    logo_path,
                    invoice_logo_path,
                    signature_path,
                    authorized_signatory_name,
                    invoice_title,
                    footer_note,
                    terms_and_conditions,
                    created_by,
                    updated_by,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    :tenant_id,
                    :branch_id,
                    :company_name,
                    :legal_name,
                    :email,
                    :website_url,
                    :phone,
                    :alternate_phone,
                    :registration_number,
                    :tax_number,
                    :address_line1,
                    :address_line2,
                    :city,
                    :state,
                    :postal_code,
                    :logo_path,
                    :invoice_logo_path,
                    :signature_path,
                    :authorized_signatory_name,
                    :invoice_title,
                    :footer_note,
                    :terms_and_conditions,
                    :created_by,
                    :updated_by,
                    NOW(),
                    NOW()
                )"
            );

            $stmt->execute(array(
                ':tenant_id' => $tenantId,
                ':branch_id' => $branchId > 0 ? $branchId : null,
                ':company_name' => $companyName,
                ':legal_name' => $legalName !== '' ? $legalName : null,
                ':email' => $email !== '' ? $email : null,
                ':website_url' => $website !== '' ? $website : null,
                ':phone' => $phone !== '' ? $phone : null,
                ':alternate_phone' => $alternatePhone !== '' ? $alternatePhone : null,
                ':registration_number' => $registrationNumber !== '' ? $registrationNumber : null,
                ':tax_number' => $taxNumber !== '' ? $taxNumber : null,
                ':address_line1' => $address1 !== '' ? $address1 : null,
                ':address_line2' => $address2 !== '' ? $address2 : null,
                ':city' => $city !== '' ? $city : null,
                ':state' => $state !== '' ? $state : null,
                ':postal_code' => $postalCode !== '' ? $postalCode : null,
                ':logo_path' => $logoPath,
                ':invoice_logo_path' => $invoiceLogoPath,
                ':signature_path' => $signaturePath,
                ':authorized_signatory_name' => $signatoryName !== '' ? $signatoryName : null,
                ':invoice_title' => $invoiceTitle !== '' ? $invoiceTitle : 'Invoice',
                ':footer_note' => $footerNote !== '' ? $footerNote : null,
                ':terms_and_conditions' => $terms !== '' ? $terms : null,
                ':created_by' => $userId,
                ':updated_by' => $userId
            ));
        }

        $pdo->commit();

        fisJson(
            200,
            true,
            'Invoice settings saved successfully.',
            array(
                'branch_id' => $branchId
            )
        );

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('FieldPlx Invoice Settings: ' . $e->getMessage());

        fisJson(
            500,
            false,
            'Unable to save invoice settings. ' . $e->getMessage()
        );
    }
}

$settings = fisLoadSettings($pdo, $tenantId, $selectedBranchId);
$selectedBranch = fisBranch($pdo, $tenantId, $selectedBranchId);

function fisValue($settings, $tenant, $branch, $key)
{
    if ($settings && isset($settings[$key]) && $settings[$key] !== null && $settings[$key] !== '') {
        return $settings[$key];
    }

    if ($branch && isset($branch[$key]) && $branch[$key] !== null && $branch[$key] !== '') {
        return $branch[$key];
    }

    if ($tenant && isset($tenant[$key]) && $tenant[$key] !== null) {
        return $tenant[$key];
    }

    return '';
}

$companyName =
    $settings && !empty($settings['company_name'])
        ? $settings['company_name']
        : (
            $selectedBranch
                ? $selectedBranch['name']
                : $tenant['display_name']
        );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice Settings - FieldPlx</title>

    <?php require_once __DIR__ . '/includes/links.php'; ?>

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
    --fd-green:#74b824;
    --fd-green-dark:#5d971b;
    --fd-green-soft:#f0f8e5;
    --fd-red:#e45b66;
    --fd-bg:#f6f8fb;
    --fd-text:#0b1933;
    --fd-muted:#6f7b90;
    --fd-border:#e5eaf1;
}

*{box-sizing:border-box}

body{
    margin:0;
    min-height:100vh;
    overflow-x:hidden;
    background:var(--fd-bg)!important;
    color:var(--fd-text);
    font-family:Arial,Helvetica,sans-serif!important;
    font-size:14px;
}

a{text-decoration:none!important}

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
    transition:margin-left .25s ease,width .25s ease;
}

body.fieldplx-sidebar-collapsed .fieldplx-topbar{
    margin-left:var(--fieldplx-sidebar-collapsed-width);
    width:calc(100% - var(--fieldplx-sidebar-collapsed-width));
}

.fieldplx-topbar-inner{
    min-height:70px!important;
    padding:0 27px!important;
    gap:13px!important;
}

.fieldplx-page-heading{display:none!important}

.fieldplx-menu-toggle,
.fieldplx-topbar-action{
    width:41px!important;
    height:41px!important;
    border:0!important;
    border-radius:9px!important;
    color:var(--fd-navy)!important;
    background:transparent!important;
}

.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover{
    background:var(--fd-green-soft)!important;
}

.fieldplx-search-wrap{
    width:280px!important;
    margin-left:auto!important;
}

.fieldplx-search-input{
    height:41px!important;
    padding-left:38px!important;
    border:0!important;
    border-radius:8px!important;
    background:#f5f8fb!important;
    font-size:12px!important;
}

.fieldplx-profile-button{
    padding:2px!important;
    border:0!important;
    background:transparent!important;
}

.fieldplx-avatar{
    width:38px!important;
    height:38px!important;
    flex:0 0 38px!important;
    border-radius:50%!important;
}

.fieldplx-sidebar{
    width:var(--fieldplx-sidebar-width)!important;
    min-width:var(--fieldplx-sidebar-width)!important;
    height:100vh!important;
    position:fixed!important;
    top:0!important;
    left:0!important;
    z-index:1045!important;
    color:#fff!important;
    background:linear-gradient(180deg,var(--fd-navy-light),var(--fd-navy))!important;
    border-right:0!important;
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
    width:var(--fieldplx-sidebar-collapsed-width)!important;
    min-width:var(--fieldplx-sidebar-collapsed-width)!important;
}

.fieldplx-sidebar-link{
    min-height:46px!important;
    margin-bottom:3px!important;
    padding:0 14px!important;
    gap:15px!important;
    border-radius:9px!important;
    color:rgba(255,255,255,.94)!important;
    font-size:14px!important;
    font-weight:600!important;
}

.fieldplx-sidebar-link.active,
.fieldplx-sidebar-menu.menu-open>.fieldplx-sidebar-link{
    color:#fff!important;
    background:linear-gradient(90deg,#7fc92d,#68aa1d)!important;
}

.fieldplx-sidebar-sublink{
    color:rgba(255,255,255,.72)!important;
}

.fieldplx-sidebar-sublink:hover,
.fieldplx-sidebar-sublink.active{
    color:#fff!important;
    background:rgba(255,255,255,.08)!important;
}

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

.fieldplx-content-wrapper{padding:0!important}

.fieldplx-footer{
    margin-left:var(--fieldplx-sidebar-width)!important;
}

body.fieldplx-sidebar-collapsed .fieldplx-footer{
    margin-left:var(--fieldplx-sidebar-collapsed-width)!important;
}

.fis-page{
    width:100%;
    max-width:1600px;
    margin:auto;
    padding:25px 27px 36px;
}

.fis-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    margin-bottom:17px;
}

.fis-title{
    margin:0;
    color:var(--fd-text);
    font-size:21px;
    line-height:1.2;
    font-weight:700;
}

.fis-sub{
    max-width:760px;
    margin:7px 0 0;
    color:var(--fd-muted);
    font-size:10.5px;
    line-height:1.55;
}

.fis-head-actions{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}

.fis-btn{
    min-height:40px;
    padding:0 13px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border:1px solid var(--fd-border);
    border-radius:8px;
    color:#43546c;
    background:#fff;
    font-size:10px;
    font-weight:700;
    cursor:pointer;
}

.fis-btn.primary{
    border-color:var(--fd-green);
    color:#fff;
    background:linear-gradient(90deg,#7fc92d,#68aa1d);
    box-shadow:0 7px 16px rgba(104,170,29,.16);
}

.fis-card{
    margin-top:16px;
    border:1px solid #dfe6ef;
    border-radius:12px;
    background:#fff;
    box-shadow:0 3px 12px rgba(24,45,76,.035);
    overflow:hidden;
}

.fis-card-head{
    padding:16px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    border-bottom:1px solid var(--fd-border);
}

.fis-card-title{
    margin:0;
    color:var(--fd-navy);
    font-size:13px;
    font-weight:700;
}

.fis-card-sub{
    margin-top:4px;
    color:var(--fd-muted);
    font-size:9px;
}

.fis-card-body{
    padding:18px;
}

.fis-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:15px;
}

.fis-grid.three{
    grid-template-columns:repeat(3,minmax(0,1fr));
}

.fis-field.full{
    grid-column:1/-1;
}

.fis-label{
    display:block;
    margin-bottom:6px;
    color:#43536c;
    font-size:9.5px;
    font-weight:700;
}

.fis-control{
    width:100%;
    min-height:40px;
    padding:9px 11px;
    border:1px solid #dfe5ec;
    border-radius:8px;
    outline:0;
    background:#fff;
    color:#31445e;
    font-size:10px;
}

textarea.fis-control{
    min-height:98px;
    resize:vertical;
}

.fis-control:focus{
    border-color:#b8d88d;
    box-shadow:0 0 0 3px rgba(116,184,36,.1);
}

.fis-upload-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:14px;
}

.fis-upload{
    min-height:205px;
    padding:14px;
    border:1px dashed #cfd8e4;
    border-radius:11px;
    background:#fbfcfd;
}

.fis-upload-title{
    color:var(--fd-navy);
    font-size:10px;
    font-weight:700;
}

.fis-upload-sub{
    margin:4px 0 11px;
    color:var(--fd-muted);
    font-size:8.5px;
}

.fis-preview{
    height:105px;
    margin-bottom:11px;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    border:1px solid #e4e9ef;
    border-radius:9px;
    background:#fff;
}

.fis-preview img{
    max-width:100%;
    max-height:100%;
    object-fit:contain;
}

.fis-preview.empty{
    color:#9ca8b7;
    font-size:9px;
}

.fis-actions{
    display:flex;
    justify-content:flex-end;
    gap:9px;
    margin-top:16px;
}

.fis-note{
    padding:12px 13px;
    display:flex;
    gap:9px;
    border:1px solid #e7edf3;
    border-radius:9px;
    background:#f8fafc;
    color:#65748a;
    font-size:9px;
    line-height:1.55;
}

.fis-note i{
    color:var(--fd-green-dark);
    font-size:15px;
}

.fis-toast{
    position:fixed;
    top:82px;
    right:18px;
    z-index:14000;
    width:min(380px,calc(100vw - 36px));
    padding:12px 14px;
    border-radius:9px;
    color:#fff;
    background:#123d70;
    box-shadow:0 12px 30px rgba(0,17,49,.18);
    opacity:0;
    transform:translateY(-8px);
    pointer-events:none;
    transition:.18s;
    font-size:10px;
    font-weight:700;
}

.fis-toast.show{
    opacity:1;
    transform:translateY(0);
}

.fis-toast.success{background:#5d971b}
.fis-toast.error{background:#e45b66}

@media(max-width:991.98px){
    .fieldplx-topbar,
    body.fieldplx-sidebar-collapsed .fieldplx-topbar{
        margin-left:0!important;
        width:100%!important;
    }

    .fieldplx-main-content,
    body.fieldplx-sidebar-collapsed .fieldplx-main-content{
        margin-left:0!important;
        width:100%!important;
    }

    .fieldplx-footer,
    body.fieldplx-sidebar-collapsed .fieldplx-footer{
        margin-left:0!important;
    }

    .fieldplx-sidebar,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
        width:min(300px,calc(100vw - 52px))!important;
        min-width:0!important;
        transform:translate3d(-100%,0,0)!important;
    }

    body.fieldplx-sidebar-mobile-open .fieldplx-sidebar{
        transform:translate3d(0,0,0)!important;
    }

    .fis-upload-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:767.98px){
    .fieldplx-topbar-inner{
        min-height:64px!important;
        padding:0 13px!important;
    }

    .fieldplx-search-wrap{
        display:none!important;
    }

    .fis-page{
        padding:17px 13px 28px;
    }

    .fis-head{
        flex-direction:column;
    }

    .fis-grid,
    .fis-grid.three{
        grid-template-columns:1fr;
    }

    .fis-field.full{
        grid-column:auto;
    }

    .fis-head-actions{
        width:100%;
    }

    .fis-head-actions .fis-btn{
        flex:1;
    }
}
    
/* ==========================================================
   FieldPlx canonical template correction - Invoice Settings
   Keeps page PHP / data / save logic unchanged.
   ========================================================== */
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

/* Topbar */
.fieldplx-topbar {
  min-height: var(--fieldplx-topbar-height) !important;
  margin-left: var(--fieldplx-sidebar-width) !important;
  width: calc(100% - var(--fieldplx-sidebar-width)) !important;
  position: sticky !important;
  top: 0 !important;
  z-index: 1030 !important;
  background: #fff !important;
  border-bottom: 1px solid var(--fd-border) !important;
  box-shadow: 0 3px 14px rgba(0, 17, 49, .035) !important;
  backdrop-filter: none !important;
  transition: margin-left .25s ease, width .25s ease !important;
}
body.fieldplx-sidebar-collapsed .fieldplx-topbar {
  margin-left: var(--fieldplx-sidebar-collapsed-width) !important;
  width: calc(100% - var(--fieldplx-sidebar-collapsed-width)) !important;
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
  margin-left: auto !important;
  position: relative !important;
}
.fieldplx-search-icon {
  position: absolute !important;
  top: 50% !important;
  left: 13px !important;
  z-index: 2 !important;
  transform: translateY(-50%) !important;
  color: #8795a8 !important;
  pointer-events: none !important;
}
.fieldplx-search-input {
  width: 100% !important;
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
  min-width: 0 !important;
  padding: 2px !important;
  display: flex !important;
  align-items: center !important;
  gap: 9px !important;
  border: 0 !important;
  border-radius: 9px !important;
  background: transparent !important;
  text-align: left !important;
}
.fieldplx-profile-button:hover { background: var(--fd-green-soft) !important; }
.fieldplx-avatar {
  width: 38px !important;
  height: 38px !important;
  flex: 0 0 38px !important;
  overflow: hidden !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  border: 0 !important;
  border-radius: 50% !important;
  color: var(--fd-navy) !important;
  background: linear-gradient(135deg, #fff, #e8f3d9) !important;
  font-size: 12px !important;
  font-weight: 800 !important;
}
.fieldplx-avatar img { width: 100% !important; height: 100% !important; object-fit: cover !important; }
.fieldplx-profile-details { max-width: 145px !important; min-width: 0 !important; }
.fieldplx-profile-name,
.fieldplx-profile-role { overflow: hidden !important; white-space: nowrap !important; text-overflow: ellipsis !important; }
.fieldplx-profile-name { color: var(--fd-text) !important; font-size: 12px !important; font-weight: 700 !important; }
.fieldplx-profile-role { margin-top: 1px !important; color: var(--fd-muted) !important; font-size: 10px !important; }
.fieldplx-notification-count { background: var(--fd-red) !important; }
.fieldplx-dropdown,
.fieldplx-profile-menu {
  border: 1px solid var(--fd-border) !important;
  background: #fff !important;
  box-shadow: 0 18px 45px rgba(29, 38, 74, .14) !important;
}
.fieldplx-dropdown {
  width: 340px !important;
  max-width: calc(100vw - 24px) !important;
  margin-top: 10px !important;
  overflow: hidden !important;
  border-radius: 14px !important;
}
.fieldplx-dropdown-header { border-bottom: 1px solid var(--fd-border) !important; background: #fff !important; }
.fieldplx-dropdown-footer { border-top: 1px solid var(--fd-border) !important; background: #fff !important; }
.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover { color: var(--fd-green-dark) !important; }
#topbarNotificationList { max-height: 300px !important; overflow-y: auto !important; background: #fff !important; }
.fieldplx-notification-item:hover,
.fieldplx-notification-item.is-unread { background: #f8fbf3 !important; }
.fieldplx-notification-icon { color: var(--fd-green-dark) !important; background: var(--fd-green-soft) !important; }
.fieldplx-empty-notifications { background: #fff !important; }
.fieldplx-empty-notifications i { color: #9fca68 !important; }
.fieldplx-profile-menu { width: 230px !important; border-radius: 12px !important; }

/* Sidebar */
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
  border-bottom: 1px solid rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-brand {
  min-width: 0 !important;
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
.fieldplx-sidebar-logo { object-fit: contain !important; background: #fff !important; }
.fieldplx-sidebar-logo-placeholder {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  color: #fff !important;
  background: linear-gradient(135deg, #8fd236, #68aa1d) !important;
  font-size: 18px !important;
}
.fieldplx-sidebar-brand-text { min-width: 0 !important; display: block !important; }
.fieldplx-sidebar-company-name {
  max-width: 155px !important;
  display: block !important;
  overflow: hidden !important;
  white-space: nowrap !important;
  text-overflow: ellipsis !important;
  color: #fff !important;
  font-size: 16px !important;
  font-weight: 700 !important;
}
.fieldplx-sidebar-product-name {
  margin-top: 1px !important;
  display: block !important;
  color: #9fda55 !important;
  font-size: 9px !important;
  font-weight: 600 !important;
  letter-spacing: .4px !important;
  text-transform: uppercase !important;
}
.fieldplx-sidebar-close {
  width: 34px !important;
  height: 34px !important;
  margin-left: auto !important;
  padding: 0 !important;
  display: none !important;
  align-items: center !important;
  justify-content: center !important;
  border: 0 !important;
  border-radius: 8px !important;
  color: rgba(255,255,255,.88) !important;
  background: rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-body {
  min-height: 0 !important;
  flex: 1 1 auto !important;
  overflow-x: hidden !important;
  overflow-y: auto !important;
  padding: 12px 14px !important;
  scrollbar-width: none !important;
}
.fieldplx-sidebar-body::-webkit-scrollbar { display: none !important; }
.fieldplx-sidebar-section-label {
  margin: 7px 12px !important;
  color: rgba(255,255,255,.5) !important;
  font-size: 9px !important;
  font-weight: 700 !important;
  letter-spacing: .65px !important;
  text-transform: uppercase !important;
}
.fieldplx-sidebar-nav { display: flex !important; flex-direction: column !important; gap: 3px !important; }
.fieldplx-sidebar-link {
  width: 100% !important;
  min-height: 46px !important;
  margin-bottom: 3px !important;
  padding: 0 14px !important;
  display: flex !important;
  align-items: center !important;
  gap: 15px !important;
  border: 0 !important;
  border-radius: 9px !important;
  color: rgba(255,255,255,.94) !important;
  background: transparent !important;
  text-align: left !important;
  text-decoration: none !important;
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
.fieldplx-sidebar-link-text { min-width: 0 !important; flex: 1 !important; overflow: hidden !important; white-space: nowrap !important; text-overflow: ellipsis !important; }
.fieldplx-sidebar-arrow { margin-left: auto !important; color: rgba(255,255,255,.65) !important; font-size: 10px !important; transition: transform .2s ease !important; }
.fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-arrow { transform: rotate(180deg) !important; }
.fieldplx-sidebar-submenu {
  display: block !important;
  max-height: 0 !important;
  overflow: hidden !important;
  padding-left: 36px !important;
  padding-top: 0 !important;
  padding-bottom: 0 !important;
  transition: max-height .25s ease, padding-top .25s ease, padding-bottom .25s ease !important;
}
.fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu {
  max-height: 680px !important;
  padding-top: 4px !important;
  padding-bottom: 5px !important;
}
.fieldplx-sidebar-sublink {
  min-height: 34px !important;
  padding: 7px 9px !important;
  display: flex !important;
  align-items: center !important;
  border-radius: 7px !important;
  color: rgba(255,255,255,.72) !important;
  background: transparent !important;
  text-decoration: none !important;
  font-size: 11px !important;
}
.fieldplx-sidebar-sublink::before {
  width: 5px !important;
  height: 5px !important;
  margin-right: 9px !important;
  flex: 0 0 5px !important;
  content: "" !important;
  border-radius: 50% !important;
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
  min-height: 62px !important;
  padding: 8px !important;
  display: flex !important;
  align-items: center !important;
  gap: 9px !important;
  border-radius: 10px !important;
  background: rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-user-avatar {
  width: 38px !important;
  height: 38px !important;
  flex: 0 0 38px !important;
  overflow: hidden !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  border-radius: 50% !important;
  color: var(--fd-navy) !important;
  background: linear-gradient(135deg,#fff,#e8f3d9) !important;
}
.fieldplx-sidebar-user-avatar img { width: 100% !important; height: 100% !important; object-fit: cover !important; }
.fieldplx-sidebar-user-details { min-width: 0 !important; flex: 1 !important; }
.fieldplx-sidebar-user-name,
.fieldplx-sidebar-user-role { display: block !important; overflow: hidden !important; white-space: nowrap !important; text-overflow: ellipsis !important; }
.fieldplx-sidebar-user-name { color: #fff !important; font-size: 12px !important; font-weight: 700 !important; }
.fieldplx-sidebar-user-role { margin-top: 1px !important; color: rgba(255,255,255,.6) !important; font-size: 9px !important; }
.fieldplx-sidebar-logout {
  width: 29px !important;
  height: 29px !important;
  flex: 0 0 29px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  border-radius: 8px !important;
  color: rgba(255,255,255,.7) !important;
  text-decoration: none !important;
}
.fieldplx-sidebar-logout:hover { color: #fff !important; background: rgba(228,91,102,.3) !important; }
.fieldplx-sidebar-overlay { display: none !important; }

body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout { display: none !important; }
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header { justify-content: center !important; padding-left: 8px !important; padding-right: 8px !important; }
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link { justify-content: center !important; padding-left: 8px !important; padding-right: 8px !important; }
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user { justify-content: center !important; padding-left: 5px !important; padding-right: 5px !important; }

/* Main layout + footer */
.fieldplx-main-layout { display: block !important; min-height: calc(100vh - var(--fieldplx-topbar-height)) !important; }
.fieldplx-main-content {
  margin-left: var(--fieldplx-sidebar-width) !important;
  min-width: 0 !important;
  transition: margin-left .25s ease !important;
}
body.fieldplx-sidebar-collapsed .fieldplx-main-content { margin-left: var(--fieldplx-sidebar-collapsed-width) !important; }
.fieldplx-content-wrapper { padding: 0 !important; }
.fieldplx-footer {
  display: block !important;
  min-height: 52px !important;
  margin-left: var(--fieldplx-sidebar-width) !important;
  border-top: 1px solid var(--fieldplx-border) !important;
  background: #fff !important;
  transition: margin-left .22s ease, background-color .22s ease !important;
}
body.fieldplx-sidebar-collapsed .fieldplx-footer { margin-left: var(--fieldplx-sidebar-collapsed-width) !important; }
.fieldplx-footer-inner {
  min-height: 52px !important;
  padding: 10px 18px !important;
  display: flex !important;
  align-items: center !important;
  gap: 18px !important;
  color: #6b7280 !important;
  font-size: 10px !important;
}
.fieldplx-footer-links { display: flex !important; align-items: center !important; gap: 8px !important; }
.fieldplx-footer-links a { color: #6b7280 !important; text-decoration: none !important; }
.fieldplx-footer-links a:hover { color: var(--fieldplx-primary) !important; }
.fieldplx-footer-product { margin-left: auto !important; white-space: nowrap !important; color: #9ca3af !important; }
.fieldplx-footer-product strong { color: var(--fieldplx-primary) !important; font-weight: 700 !important; }

/* Invoice Settings page */
.fis-page {
  width: 100% !important;
  max-width: 1600px !important;
  margin: auto !important;
  padding: 25px 27px 36px !important;
}
.fis-head {
  display: flex !important;
  align-items: flex-start !important;
  justify-content: space-between !important;
  gap: 16px !important;
  margin-bottom: 18px !important;
}
.fis-title {
  margin: 0 0 7px !important;
  color: var(--fd-text) !important;
  font-size: 21px !important;
  line-height: 1.2 !important;
  font-weight: 700 !important;
}
.fis-sub {
  max-width: 820px !important;
  margin: 0 !important;
  color: var(--fd-muted) !important;
  font-size: 10.5px !important;
  line-height: 1.55 !important;
}
.fis-head-actions { display: flex !important; align-items: center !important; gap: 8px !important; flex-wrap: wrap !important; }
.fis-btn {
  min-height: 39px !important;
  padding: 0 13px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 7px !important;
  border: 1px solid var(--fd-border) !important;
  border-radius: 8px !important;
  color: #43546c !important;
  background: #fff !important;
  box-shadow: 0 4px 12px rgba(31,43,88,.04) !important;
  font-size: 10px !important;
  font-weight: 600 !important;
  text-decoration: none !important;
  cursor: pointer !important;
  transition: border-color .16s ease, color .16s ease, background .16s ease, box-shadow .16s ease !important;
}
.fis-btn:hover { border-color: #cfe3ae !important; color: var(--fd-green-dark) !important; background: #f9fcf4 !important; }
.fis-btn.primary {
  border-color: var(--fd-green) !important;
  color: #fff !important;
  background: linear-gradient(90deg,#7fc92d,#68aa1d) !important;
  box-shadow: 0 7px 16px rgba(104,170,29,.16) !important;
}
.fis-btn.primary:hover { color: #fff !important; background: linear-gradient(90deg,#74b824,#5d971b) !important; }
.fis-btn:disabled { opacity: .68 !important; cursor: not-allowed !important; }

.fis-card {
  margin-top: 16px !important;
  overflow: hidden !important;
  border: 1px solid #dfe6ef !important;
  border-radius: 12px !important;
  background: #fff !important;
  box-shadow: 0 3px 12px rgba(24,45,76,.035) !important;
}
.fis-card:first-of-type { margin-top: 0 !important; }
.fis-card-head {
  min-height: 56px !important;
  padding: 14px 16px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  gap: 12px !important;
  border-bottom: 1px solid var(--fd-border) !important;
  background: #fbfcfd !important;
}
.fis-card-title { margin: 0 !important; color: var(--fd-text) !important; font-size: 13px !important; font-weight: 700 !important; }
.fis-card-sub { margin-top: 4px !important; color: var(--fd-muted) !important; font-size: 9px !important; line-height: 1.45 !important; }
.fis-card-head > i {
  width: 34px !important;
  height: 34px !important;
  flex: 0 0 34px !important;
  display: grid !important;
  place-items: center !important;
  border-radius: 9px !important;
  color: var(--fd-green-dark) !important;
  background: var(--fd-green-soft) !important;
  font-size: 16px !important;
}
.fis-card-body { padding: 16px !important; }
.fis-grid { display: grid !important; grid-template-columns: repeat(2,minmax(0,1fr)) !important; gap: 13px 15px !important; }
.fis-grid.three { grid-template-columns: repeat(3,minmax(0,1fr)) !important; }
.fis-field { min-width: 0 !important; }
.fis-field.full { grid-column: 1/-1 !important; }
.fis-label {
  display: block !important;
  margin-bottom: 6px !important;
  color: #42536c !important;
  font-size: 9px !important;
  font-weight: 700 !important;
}
.fis-control {
  width: 100% !important;
  min-height: 40px !important;
  padding: 8px 10px !important;
  border: 1px solid #dfe5ec !important;
  border-radius: 8px !important;
  outline: 0 !important;
  background: #fff !important;
  color: #263750 !important;
  box-shadow: none !important;
  font-size: 10px !important;
  transition: border-color .16s ease, box-shadow .16s ease, background .16s ease !important;
}
select.fis-control { cursor: pointer !important; }
textarea.fis-control { min-height: 105px !important; resize: vertical !important; line-height: 1.55 !important; }
.fis-control:focus { border-color: #b7d88b !important; box-shadow: 0 0 0 3px rgba(116,184,36,.10) !important; }
.fis-control::placeholder { color: #a0aab8 !important; }

/* Scope card */
.fis-page > .fis-card .fis-card-body > .fis-grid { max-width: 620px !important; }
#scopeBranch { min-height: 41px !important; }

/* Upload cards */
.fis-upload-grid { display: grid !important; grid-template-columns: repeat(3,minmax(0,1fr)) !important; gap: 14px !important; }
.fis-upload {
  min-height: 220px !important;
  padding: 14px !important;
  border: 1px solid #dfe6ef !important;
  border-radius: 10px !important;
  background: #fbfcfd !important;
}
.fis-upload-title { color: var(--fd-navy) !important; font-size: 10px !important; font-weight: 700 !important; }
.fis-upload-sub { margin: 4px 0 11px !important; color: var(--fd-muted) !important; font-size: 8.5px !important; line-height: 1.4 !important; }
.fis-preview {
  height: 112px !important;
  margin-bottom: 11px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  overflow: hidden !important;
  border: 1px solid #e4e9ef !important;
  border-radius: 9px !important;
  background: #fff !important;
}
.fis-preview img { max-width: 100% !important; max-height: 100% !important; object-fit: contain !important; }
.fis-preview.empty { color: #9ca8b7 !important; background: #f8fafc !important; font-size: 9px !important; }
.fis-upload input[type="file"].fis-control {
  min-height: 38px !important;
  padding: 5px 7px !important;
  background: #fff !important;
}
.fis-upload input[type="file"]::file-selector-button {
  margin-right: 9px !important;
  padding: 6px 9px !important;
  border: 0 !important;
  border-radius: 6px !important;
  color: var(--fd-green-dark) !important;
  background: var(--fd-green-soft) !important;
  font-size: 9px !important;
  font-weight: 700 !important;
  cursor: pointer !important;
}

.fis-note {
  padding: 11px 12px !important;
  display: flex !important;
  align-items: flex-start !important;
  gap: 9px !important;
  border: 1px solid #e4eadf !important;
  border-radius: 9px !important;
  background: #f8fbf3 !important;
  color: #627087 !important;
  font-size: 9px !important;
  line-height: 1.55 !important;
}
.fis-note i { flex: 0 0 auto !important; margin-top: 1px !important; color: var(--fd-green-dark) !important; font-size: 15px !important; }
.fis-actions {
  display: flex !important;
  justify-content: flex-end !important;
  gap: 9px !important;
  margin-top: 16px !important;
}

/* Toast */
.fis-toast {
  width: min(320px,calc(100vw - 24px)) !important;
  position: fixed !important;
  top: 82px !important;
  right: 16px !important;
  z-index: 25000 !important;
  padding: 9px 11px !important;
  display: flex !important;
  align-items: center !important;
  gap: 7px !important;
  border-radius: 7px !important;
  color: #fff !important;
  background: var(--fd-blue) !important;
  box-shadow: 0 10px 26px rgba(0,17,49,.18) !important;
  opacity: 0 !important;
  transform: translateY(-8px) !important;
  pointer-events: none !important;
  transition: .18s ease !important;
  font-size: 9px !important;
  font-weight: 600 !important;
}
.fis-toast.show { opacity: 1 !important; transform: translateY(0) !important; }
.fis-toast.success { background: #5d971b !important; }
.fis-toast.error { background: #e45b66 !important; }

/* Tablet/mobile canonical sidebar */
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
    will-change: transform !important;
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
  .fieldplx-sidebar-close { display: inline-flex !important; }
  .fieldplx-sidebar-close:hover { color: #fff !important; background: rgba(255,255,255,.14) !important; }
  .fieldplx-sidebar-body {
    min-height: 0 !important;
    flex: 1 1 auto !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    overscroll-behavior: contain !important;
    -webkit-overflow-scrolling: touch !important;
  }
  .fieldplx-sidebar-footer { flex: 0 0 auto !important; }

  .fieldplx-sidebar-brand-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
  .fieldplx-sidebar-section-label,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
  .fieldplx-sidebar-link-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
  .fieldplx-sidebar-arrow,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
  .fieldplx-sidebar-user-details,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details { display: block !important; }
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
  }
  .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu {
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

  .fis-upload-grid { grid-template-columns: 1fr !important; }
  .fis-upload { min-height: auto !important; }
}

@media (max-width: 767.98px) {
  :root { --fieldplx-topbar-height: 64px; }
  .fieldplx-topbar,
  .fieldplx-topbar-inner { min-height: 64px !important; }
  .fieldplx-topbar-inner { padding: 0 13px !important; gap: 8px !important; }
  .fieldplx-search-wrap { display: none !important; }
  .fieldplx-profile-details { display: none !important; }
  .fieldplx-profile-button { padding-right: 2px !important; }

  .fis-page { padding: 17px 13px 28px !important; }
  .fis-head { flex-direction: column !important; gap: 12px !important; }
  .fis-title { font-size: 19px !important; }
  .fis-sub { max-width: 100% !important; font-size: 10px !important; }
  .fis-head-actions { width: 100% !important; }
  .fis-head-actions .fis-btn { flex: 1 !important; }
  .fis-grid,
  .fis-grid.three { grid-template-columns: 1fr !important; }
  .fis-field.full { grid-column: auto !important; }
  .fis-card-head { padding: 13px 14px !important; }
  .fis-card-body { padding: 14px !important; }
  .fis-actions { justify-content: stretch !important; }
  .fis-actions .fis-btn { width: 100% !important; }
  .fieldplx-footer-inner { padding: 12px !important; flex-wrap: wrap !important; justify-content: center !important; gap: 7px 14px !important; text-align: center !important; }
  .fieldplx-footer-product { width: 100% !important; margin-left: 0 !important; }
}

@media (max-width: 575.98px) {
  .fieldplx-sidebar,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar { width: min(288px, calc(100vw - 44px)) !important; }
  .fieldplx-sidebar-body { padding-left: 10px !important; padding-right: 10px !important; }
  .fieldplx-sidebar-link { min-height: 43px !important; padding-left: 12px !important; padding-right: 12px !important; gap: 12px !important; font-size: 13px !important; }
  .fieldplx-sidebar-submenu { padding-left: 31px !important; }
  .fieldplx-sidebar-sublink { min-height: 33px !important; font-size: 11px !important; }
  .fieldplx-dropdown { width: min(320px, calc(100vw - 20px)) !important; }
  .fis-toast { top: 72px !important; left: 12px !important; right: 12px !important; width: auto !important; }
}

    </style>
</head>

<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>

<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="fis-page">

                <section class="fis-head">
                    <div>
                        <h1 class="fis-title">Invoice Settings</h1>
                        <p class="fis-sub">
                            Manage invoice company details, branding, invoice logo,
                            authorized signature and footer information for the business
                            or for a specific branch.
                        </p>
                    </div>

                    <div class="fis-head-actions">
                        <a class="fis-btn" href="invoices.php">
                            <i class="bi bi-receipt"></i>
                            Invoices
                        </a>
                    </div>
                </section>

                <div class="fis-card">
                    <div class="fis-card-head">
                        <div>
                            <h2 class="fis-card-title">Settings Scope</h2>
                            <div class="fis-card-sub">
                                Select Business Default or a branch-specific configuration.
                            </div>
                        </div>
                    </div>

                    <div class="fis-card-body">
                        <div class="fis-grid">
                            <div class="fis-field">
                                <label class="fis-label">Invoice Settings For</label>
                                <select class="fis-control" id="scopeBranch">
                                    <option value="0">Business Default</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option
                                            value="<?= (int)$branch['id'] ?>"
                                            <?= $selectedBranchId === (int)$branch['id'] ? 'selected' : '' ?>
                                        >
                                            <?= fisH($branch['name']) ?>
                                            <?= $branch['branch_code'] ? ' · ' . fisH($branch['branch_code']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <form id="invoiceSettingsForm" enctype="multipart/form-data">

                    <input
                        type="hidden"
                        name="branch_id"
                        value="<?= (int)$selectedBranchId ?>"
                    >

                    <div class="fis-card">
                        <div class="fis-card-head">
                            <div>
                                <h2 class="fis-card-title">Company Details</h2>
                                <div class="fis-card-sub">
                                    These details will be used on printed invoices.
                                </div>
                            </div>
                            <i class="bi bi-building" style="font-size:18px;color:var(--fd-green-dark)"></i>
                        </div>

                        <div class="fis-card-body">
                            <div class="fis-grid">
                                <div class="fis-field">
                                    <label class="fis-label">Company Name *</label>
                                    <input
                                        class="fis-control"
                                        type="text"
                                        name="company_name"
                                        maxlength="190"
                                        required
                                        value="<?= fisH($companyName) ?>"
                                    >
                                </div>

                                <div class="fis-field">
                                    <label class="fis-label">Legal Name</label>
                                    <input
                                        class="fis-control"
                                        type="text"
                                        name="legal_name"
                                        maxlength="190"
                                        value="<?= fisH(fisValue($settings, $tenant, $selectedBranch, 'legal_name')) ?>"
                                    >
                                </div>

                                <div class="fis-field">
                                    <label class="fis-label">Email</label>
                                    <input
                                        class="fis-control"
                                        type="email"
                                        name="email"
                                        maxlength="190"
                                        value="<?= fisH(fisValue($settings, $tenant, $selectedBranch, 'email')) ?>"
                                    >
                                </div>

                                <div class="fis-field">
                                    <label class="fis-label">Website</label>
                                    <input
                                        class="fis-control"
                                        type="url"
                                        name="website_url"
                                        maxlength="255"
                                        placeholder="https://example.com"
                                        value="<?= fisH(fisValue($settings, $tenant, $selectedBranch, 'website_url')) ?>"
                                    >
                                </div>

                                <div class="fis-field">
                                    <label class="fis-label">Phone</label>
                                    <input
                                        class="fis-control"
                                        type="text"
                                        name="phone"
                                        maxlength="50"
                                        value="<?= fisH(fisValue($settings, $tenant, $selectedBranch, 'phone')) ?>"
                                    >
                                </div>

                                <div class="fis-field">
                                    <label class="fis-label">Alternate Phone</label>
                                    <input
                                        class="fis-control"
                                        type="text"
                                        name="alternate_phone"
                                        maxlength="50"
                                        value="<?= fisH(fisValue($settings, $tenant, $selectedBranch, 'alternate_phone')) ?>"
                                    >
                                </div>

                                <div class="fis-field">
                                    <label class="fis-label">Registration Number</label>
                                    <input
                                        class="fis-control"
                                        type="text"
                                        name="registration_number"
                                        maxlength="120"
                                        value="<?= fisH(fisValue($settings, $tenant, $selectedBranch, 'registration_number')) ?>"
                                    >
                                </div>

                                <div class="fis-field">
                                    <label class="fis-label">Tax / GST / VAT Number</label>
                                    <input
                                        class="fis-control"
                                        type="text"
                                        name="tax_number"
                                        maxlength="120"
                                        value="<?= fisH(fisValue($settings, $tenant, $selectedBranch, 'tax_number')) ?>"
                                    >
                                </div>

                                <div class="fis-field full">
                                    <label class="fis-label">Address Line 1</label>
                                    <input
                                        class="fis-control"
                                        type="text"
                                        name="address_line1"
                                        maxlength="255"
                                        value="<?= fisH(fisValue($settings, $tenant, $selectedBranch, 'address_line1')) ?>"
                                    >
                                </div>

                                <div class="fis-field full">
                                    <label class="fis-label">Address Line 2</label>
                                    <input
                                        class="fis-control"
                                        type="text"
                                        name="address_line2"
                                        maxlength="255"
                                        value="<?= fisH(fisValue($settings, $tenant, $selectedBranch, 'address_line2')) ?>"
                                    >
                                </div>
                            </div>

                            <div class="fis-grid three" style="margin-top:15px">
                                <div class="fis-field">
                                    <label class="fis-label">City</label>
                                    <input
                                        class="fis-control"
                                        type="text"
                                        name="city"
                                        maxlength="120"
                                        value="<?= fisH(fisValue($settings, $tenant, $selectedBranch, 'city')) ?>"
                                    >
                                </div>

                                <div class="fis-field">
                                    <label class="fis-label">State</label>
                                    <input
                                        class="fis-control"
                                        type="text"
                                        name="state"
                                        maxlength="120"
                                        value="<?= fisH(fisValue($settings, $tenant, $selectedBranch, 'state')) ?>"
                                    >
                                </div>

                                <div class="fis-field">
                                    <label class="fis-label">Postal Code</label>
                                    <input
                                        class="fis-control"
                                        type="text"
                                        name="postal_code"
                                        maxlength="40"
                                        value="<?= fisH(fisValue($settings, $tenant, $selectedBranch, 'postal_code')) ?>"
                                    >
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="fis-card">
                        <div class="fis-card-head">
                            <div>
                                <h2 class="fis-card-title">Branding & Signature</h2>
                                <div class="fis-card-sub">
                                    Upload business logo, invoice logo and authorized signature.
                                </div>
                            </div>
                            <i class="bi bi-images" style="font-size:18px;color:var(--fd-green-dark)"></i>
                        </div>

                        <div class="fis-card-body">
                            <div class="fis-upload-grid">

                                <div class="fis-upload">
                                    <div class="fis-upload-title">Business Logo</div>
                                    <div class="fis-upload-sub">JPG, PNG or WEBP · Max 4 MB</div>

                                    <?php
                                    $logoPreview = $settings && !empty($settings['logo_path'])
                                        ? $settings['logo_path']
                                        : fisValue($settings, $tenant, $selectedBranch, 'logo_path');
                                    ?>

                                    <div class="fis-preview <?= $logoPreview ? '' : 'empty' ?>">
                                        <?php if ($logoPreview): ?>
                                            <img src="<?= fisH($logoPreview) ?>" alt="Business logo">
                                        <?php else: ?>
                                            No logo uploaded
                                        <?php endif; ?>
                                    </div>

                                    <input
                                        class="fis-control"
                                        type="file"
                                        name="logo"
                                        accept="image/jpeg,image/png,image/webp"
                                    >
                                </div>

                                <div class="fis-upload">
                                    <div class="fis-upload-title">Invoice Logo</div>
                                    <div class="fis-upload-sub">Used specifically on printed invoices</div>

                                    <?php
                                    $invoiceLogoPreview = $settings && !empty($settings['invoice_logo_path'])
                                        ? $settings['invoice_logo_path']
                                        : fisValue($settings, $tenant, $selectedBranch, 'invoice_logo_path');
                                    ?>

                                    <div class="fis-preview <?= $invoiceLogoPreview ? '' : 'empty' ?>">
                                        <?php if ($invoiceLogoPreview): ?>
                                            <img src="<?= fisH($invoiceLogoPreview) ?>" alt="Invoice logo">
                                        <?php else: ?>
                                            No invoice logo uploaded
                                        <?php endif; ?>
                                    </div>

                                    <input
                                        class="fis-control"
                                        type="file"
                                        name="invoice_logo"
                                        accept="image/jpeg,image/png,image/webp"
                                    >
                                </div>

                                <div class="fis-upload">
                                    <div class="fis-upload-title">Authorized Signature</div>
                                    <div class="fis-upload-sub">Signature shown near invoice authorization</div>

                                    <?php
                                    $signaturePreview = $settings && !empty($settings['signature_path'])
                                        ? $settings['signature_path']
                                        : '';
                                    ?>

                                    <div class="fis-preview <?= $signaturePreview ? '' : 'empty' ?>">
                                        <?php if ($signaturePreview): ?>
                                            <img src="<?= fisH($signaturePreview) ?>" alt="Authorized signature">
                                        <?php else: ?>
                                            No signature uploaded
                                        <?php endif; ?>
                                    </div>

                                    <input
                                        class="fis-control"
                                        type="file"
                                        name="signature"
                                        accept="image/jpeg,image/png,image/webp"
                                    >
                                </div>
                            </div>

                            <div class="fis-grid" style="margin-top:15px">
                                <div class="fis-field">
                                    <label class="fis-label">Authorized Signatory Name</label>
                                    <input
                                        class="fis-control"
                                        type="text"
                                        name="authorized_signatory_name"
                                        maxlength="190"
                                        value="<?= fisH($settings['authorized_signatory_name'] ?? '') ?>"
                                    >
                                </div>

                                <div class="fis-field">
                                    <label class="fis-label">Invoice Heading</label>
                                    <input
                                        class="fis-control"
                                        type="text"
                                        name="invoice_title"
                                        maxlength="120"
                                        value="<?= fisH($settings['invoice_title'] ?? 'Invoice') ?>"
                                    >
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="fis-card">
                        <div class="fis-card-head">
                            <div>
                                <h2 class="fis-card-title">Invoice Footer</h2>
                                <div class="fis-card-sub">
                                    Footer note and terms shown on the invoice print page.
                                </div>
                            </div>
                            <i class="bi bi-file-earmark-text" style="font-size:18px;color:var(--fd-green-dark)"></i>
                        </div>

                        <div class="fis-card-body">
                            <div class="fis-grid">
                                <div class="fis-field full">
                                    <label class="fis-label">Footer Note</label>
                                    <textarea
                                        class="fis-control"
                                        name="footer_note"
                                        maxlength="2000"
                                        placeholder="Thank you for your business."
                                    ><?= fisH($settings['footer_note'] ?? '') ?></textarea>
                                </div>

                                <div class="fis-field full">
                                    <label class="fis-label">Terms & Conditions</label>
                                    <textarea
                                        class="fis-control"
                                        name="terms_and_conditions"
                                        maxlength="10000"
                                        placeholder="Payment terms, warranty notes, service conditions..."
                                    ><?= fisH($settings['terms_and_conditions'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <div class="fis-note" style="margin-top:15px">
                                <i class="bi bi-info-circle"></i>
                                <span>
                                    Business Default settings are used when a branch does not have
                                    its own invoice configuration. Branch settings can override the
                                    business default values.
                                </span>
                            </div>

                            <div class="fis-actions">
                                <button
                                    class="fis-btn primary"
                                    type="submit"
                                    id="saveButton"
                                >
                                    <i class="bi bi-check2-circle"></i>
                                    Save Invoice Settings
                                </button>
                            </div>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </main>
</div>

<div class="fis-toast" id="toast">Notification</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
    'use strict';

    var csrfToken = <?= json_encode($csrfToken) ?>;
    var toastTimer = null;

    function el(id){
        return document.getElementById(id);
    }

    function notify(type, message){
        var toast = el('toast');

        if (toastTimer) {
            clearTimeout(toastTimer);
        }

        toast.className =
            'fis-toast ' +
            (type || '') +
            ' show';

        toast.textContent =
            message || 'Notification';

        toastTimer = setTimeout(function(){
            toast.classList.remove('show');
        }, 3200);
    }

    function parseResponse(response){
        return response.text().then(function(raw){
            var data;
            var text = String(raw || '').trim();

            try {
                data = text ? JSON.parse(text) : {};
            } catch (error) {
                throw new Error(
                    text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() ||
                    ('Invalid server response. HTTP ' + response.status)
                );
            }

            if (!response.ok || !data.success) {
                throw new Error(
                    data.message ||
                    ('Request failed. HTTP ' + response.status)
                );
            }

            return data;
        });
    }

    el('scopeBranch').addEventListener('change', function(){
        var branchId = Number(this.value || 0);

        if (branchId > 0) {
            window.location.href =
                'invoice-settings.php?branch_id=' + branchId;
        } else {
            window.location.href =
                'invoice-settings.php';
        }
    });

    el('invoiceSettingsForm').addEventListener('submit', function(event){
        event.preventDefault();

        var button = el('saveButton');
        var original = button.innerHTML;
        var formData = new FormData(this);

        formData.append('action', 'save');
        formData.append('csrf_token', csrfToken);

        button.disabled = true;
        button.innerHTML =
            '<span class="spinner-border spinner-border-sm"></span> Saving...';

        fetch('invoice-settings.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(parseResponse)
        .then(function(data){
            notify('success', data.message);

            setTimeout(function(){
                window.location.reload();
            }, 700);
        })
        .catch(function(error){
            notify('error', error.message);
        })
        .finally(function(){
            button.disabled = false;
            button.innerHTML = original;
        });
    });
})();
</script>
</body>
</html>
