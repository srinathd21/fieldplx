<?php
/**
 * FieldPlx API - Edit / Update Tenant
 * Endpoint:
 *   GET  /platform/api/tenant-update.php?id=TENANT_ID
 *   POST /platform/api/tenant-update.php
 * PHP 7.2+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function tenantUpdateResponse(int $status, bool $success, string $message, array $extra = array()): void
{
    http_response_code($status);

    echo json_encode(
        array_merge(
            array(
                'success' => $success,
                'message' => $message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function tenantUpdatePost(string $key, string $default = ''): string
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }

    return trim((string) $_POST[$key]);
}

function tenantUpdateNullable(string $value)
{
    $value = trim($value);
    return $value === '' ? null : $value;
}

function tenantUpdateValidDate(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);

    return $date !== false &&
        $date->format('Y-m-d') === $value;
}

function tenantUpdateUpload(string $field, string $tenantCode)
{
    if (
        !isset($_FILES[$field]) ||
        !is_array($_FILES[$field]) ||
        !isset($_FILES[$field]['error'])
    ) {
        return null;
    }

    $file = $_FILES[$field];

    if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('One of the uploaded logo files could not be processed.');
    }

    if (
        !isset($file['size']) ||
        (int) $file['size'] <= 0 ||
        (int) $file['size'] > 3 * 1024 * 1024
    ) {
        throw new RuntimeException('Logo files must be 3 MB or smaller.');
    }

    $tmpName = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid uploaded logo file.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpName);

    $allowed = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    );

    if (!is_string($mime) || !isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG and WEBP logo files are allowed.');
    }

    $safeTenantCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', $tenantCode);
    $safeTenantCode = trim((string) $safeTenantCode, '-');

    if ($safeTenantCode === '') {
        $safeTenantCode = 'tenant';
    }

    $uploadRelativeDir = 'uploads/tenants/' . strtolower($safeTenantCode);

    $uploadAbsoluteDir =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        str_replace('/', DIRECTORY_SEPARATOR, $uploadRelativeDir);

    if (
        !is_dir($uploadAbsoluteDir) &&
        !mkdir($uploadAbsoluteDir, 0755, true) &&
        !is_dir($uploadAbsoluteDir)
    ) {
        throw new RuntimeException('Unable to create tenant upload directory.');
    }

    $filename =
        $field . '-' .
        date('YmdHis') . '-' .
        bin2hex(random_bytes(4)) . '.' .
        $allowed[$mime];

    $absolutePath = $uploadAbsoluteDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpName, $absolutePath)) {
        throw new RuntimeException('Unable to save uploaded logo.');
    }

    return array(
        'relative_path' => $uploadRelativeDir . '/' . $filename,
        'absolute_path' => $absolutePath
    );
}

function tenantUpdateDeleteOldFile(?string $relativePath): void
{
    $relativePath = trim((string) $relativePath);

    if ($relativePath === '') {
        return;
    }

    $normalized = str_replace('\\', '/', $relativePath);

    if (strpos($normalized, 'uploads/tenants/') !== 0) {
        return;
    }

    $absolute =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        str_replace('/', DIRECTORY_SEPARATOR, $normalized);

    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

function tenantUpdateLatestSubscription(PDO $pdo, int $tenantId)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM subscriptions
        WHERE tenant_id = :tenant_id
          AND deleted_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute(array(':tenant_id' => $tenantId));

    return $stmt->fetch();
}

function tenantUpdateLoadData(PDO $pdo, int $tenantId): void
{
    if (
        empty($_SESSION['tenant_edit_csrf']) ||
        !is_string($_SESSION['tenant_edit_csrf'])
    ) {
        $_SESSION['tenant_edit_csrf'] = bin2hex(random_bytes(32));
    }

    $tenantStmt = $pdo->prepare("
        SELECT *
        FROM tenants
        WHERE id = :tenant_id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $tenantStmt->execute(array(':tenant_id' => $tenantId));
    $tenant = $tenantStmt->fetch();

    if (!$tenant) {
        tenantUpdateResponse(404, false, 'Tenant not found.');
    }

    $subscription = tenantUpdateLatestSubscription($pdo, $tenantId);

    $countryStmt = $pdo->prepare("
        SELECT
            id,
            name,
            iso2,
            phone_code,
            default_currency_code,
            default_timezone,
            date_format
        FROM countries
        WHERE is_active = 1
           OR id = :current_id
        ORDER BY name ASC
    ");
    $countryStmt->execute(array(':current_id' => (int) $tenant['country_id']));
    $countries = $countryStmt->fetchAll();

    $currencyStmt = $pdo->prepare("
        SELECT
            id,
            currency_code,
            currency_name,
            symbol
        FROM currencies
        WHERE is_active = 1
           OR id = :current_id
        ORDER BY currency_code ASC
    ");
    $currencyStmt->execute(array(':current_id' => (int) $tenant['currency_id']));
    $currencies = $currencyStmt->fetchAll();

    $currentPlanId =
        $subscription && !empty($subscription['plan_id'])
            ? (int) $subscription['plan_id']
            : 0;

    $planStmt = $pdo->prepare("
        SELECT
            id,
            name,
            code,
            price,
            currency,
            billing_cycle,
            trial_days,
            max_users,
            max_branches,
            storage_limit_mb
        FROM plans
        WHERE deleted_at IS NULL
          AND (status = 'active' OR id = :current_plan_id)
        ORDER BY is_featured DESC, price ASC, name ASC
    ");
    $planStmt->execute(array(':current_plan_id' => $currentPlanId));
    $plans = $planStmt->fetchAll();

    if (!$subscription) {
        $subscription = array(
            'id' => null,
            'plan_id' => null,
            'currency_id' => null,
            'amount' => null,
            'start_date' => null,
            'expiry_date' => null,
            'trial_end_date' => null,
            'auto_renew' => 0,
            'status' => null
        );
    }

    tenantUpdateResponse(
        200,
        true,
        'Tenant loaded successfully.',
        array(
            'csrf_token' => $_SESSION['tenant_edit_csrf'],
            'data' => array(
                'tenant' => $tenant,
                'subscription' => $subscription,
                'countries' => $countries,
                'currencies' => $currencies,
                'plans' => $plans
            )
        )
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tenantId =
        isset($_GET['id']) && !is_array($_GET['id'])
            ? (int) $_GET['id']
            : 0;

    if ($tenantId <= 0) {
        tenantUpdateResponse(422, false, 'Invalid tenant ID.');
    }

    tenantUpdateLoadData($pdo, $tenantId);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tenantUpdateResponse(405, false, 'Method not allowed.');
}

$csrfToken = tenantUpdatePost('csrf_token');

if (
    empty($_SESSION['tenant_edit_csrf']) ||
    !is_string($_SESSION['tenant_edit_csrf']) ||
    $csrfToken === '' ||
    !hash_equals($_SESSION['tenant_edit_csrf'], $csrfToken)
) {
    tenantUpdateResponse(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$tenantId = (int) tenantUpdatePost('tenant_id', '0');

if ($tenantId <= 0) {
    tenantUpdateResponse(422, false, 'Invalid tenant ID.');
}

$currentStmt = $pdo->prepare("
    SELECT *
    FROM tenants
    WHERE id = :tenant_id
      AND deleted_at IS NULL
    LIMIT 1
");
$currentStmt->execute(array(':tenant_id' => $tenantId));
$currentTenant = $currentStmt->fetch();

if (!$currentTenant) {
    tenantUpdateResponse(404, false, 'Tenant not found.');
}

$tenantCode = strtoupper(tenantUpdatePost('tenant_code'));
$legalName = tenantUpdatePost('legal_name');
$displayName = tenantUpdatePost('display_name');
$businessType = tenantUpdatePost('business_type');
$registrationNumber = tenantUpdatePost('registration_number');
$taxNumber = tenantUpdatePost('tax_number');
$email = tenantUpdatePost('email');
$phone = tenantUpdatePost('phone');
$alternatePhone = tenantUpdatePost('alternate_phone');
$websiteUrl = tenantUpdatePost('website_url');
$countryId = (int) tenantUpdatePost('country_id', '0');
$currencyId = (int) tenantUpdatePost('currency_id', '0');
$timezone = tenantUpdatePost('timezone', 'UTC');
$dateFormat = tenantUpdatePost('date_format', 'd-m-Y');
$addressLine1 = tenantUpdatePost('address_line1');
$addressLine2 = tenantUpdatePost('address_line2');
$city = tenantUpdatePost('city');
$state = tenantUpdatePost('state');
$postalCode = tenantUpdatePost('postal_code');
$status = strtolower(tenantUpdatePost('status', 'trial'));
$planId = (int) tenantUpdatePost('plan_id', '0');
$subscriptionStart = tenantUpdatePost('subscription_start');
$subscriptionExpiry = tenantUpdatePost('subscription_expiry');
$trialEndDate = tenantUpdatePost('trial_end_date');
$autoRenew = tenantUpdatePost('auto_renew') === '1' ? 1 : 0;

$allowedTenantStatuses = array(
    'trial','active','expired','suspended','cancelled','archived'
);

$allowedDateFormats = array(
    'd-m-Y','d/m/Y','m-d-Y','m/d/Y','Y-m-d'
);

if (
    $tenantCode === '' ||
    strlen($tenantCode) > 80 ||
    !preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $tenantCode)
) {
    tenantUpdateResponse(
        422,
        false,
        'Tenant code is required and may contain only letters, numbers, hyphens and underscores.'
    );
}

if ($legalName === '' || strlen($legalName) > 190) {
    tenantUpdateResponse(422, false, 'Legal name is required and must be 190 characters or less.');
}

if ($displayName === '' || strlen($displayName) > 190) {
    tenantUpdateResponse(422, false, 'Display name is required and must be 190 characters or less.');
}

if ($countryId <= 0) {
    tenantUpdateResponse(422, false, 'Please select a country.');
}

if ($currencyId <= 0) {
    tenantUpdateResponse(422, false, 'Please select a currency.');
}

if ($timezone === '' || strlen($timezone) > 100) {
    tenantUpdateResponse(422, false, 'Timezone is required.');
}

if (!in_array($dateFormat, $allowedDateFormats, true)) {
    tenantUpdateResponse(422, false, 'Invalid date format.');
}

if (!in_array($status, $allowedTenantStatuses, true)) {
    tenantUpdateResponse(422, false, 'Invalid tenant status.');
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    tenantUpdateResponse(422, false, 'Enter a valid email address.');
}

if ($websiteUrl !== '' && !filter_var($websiteUrl, FILTER_VALIDATE_URL)) {
    tenantUpdateResponse(422, false, 'Enter a valid website URL including https:// or http://.');
}

if (
    !tenantUpdateValidDate($subscriptionStart) ||
    !tenantUpdateValidDate($subscriptionExpiry) ||
    !tenantUpdateValidDate($trialEndDate)
) {
    tenantUpdateResponse(422, false, 'One or more subscription dates are invalid.');
}

if ($planId > 0 && $subscriptionStart === '') {
    tenantUpdateResponse(422, false, 'Subscription start date is required when a plan is selected.');
}

if (
    $subscriptionExpiry !== '' &&
    $subscriptionStart !== '' &&
    $subscriptionExpiry < $subscriptionStart
) {
    tenantUpdateResponse(422, false, 'Subscription expiry date cannot be before the start date.');
}

if (
    $trialEndDate !== '' &&
    $subscriptionStart !== '' &&
    $trialEndDate < $subscriptionStart
) {
    tenantUpdateResponse(422, false, 'Trial end date cannot be before the subscription start date.');
}

$countryStmt = $pdo->prepare("SELECT id FROM countries WHERE id = :id LIMIT 1");
$countryStmt->execute(array(':id' => $countryId));

if (!$countryStmt->fetchColumn()) {
    tenantUpdateResponse(422, false, 'Selected country is not available.');
}

$currencyStmt = $pdo->prepare("
    SELECT id, currency_code
    FROM currencies
    WHERE id = :id
    LIMIT 1
");
$currencyStmt->execute(array(':id' => $currencyId));
$currencyRow = $currencyStmt->fetch();

if (!$currencyRow) {
    tenantUpdateResponse(422, false, 'Selected currency is not available.');
}

$plan = null;

if ($planId > 0) {
    $planStmt = $pdo->prepare("
        SELECT
            id,
            name,
            code,
            price,
            currency,
            billing_cycle,
            trial_days,
            max_users,
            max_branches,
            storage_limit_mb
        FROM plans
        WHERE id = :id
          AND deleted_at IS NULL
        LIMIT 1
    ");
    $planStmt->execute(array(':id' => $planId));
    $plan = $planStmt->fetch();

    if (!$plan) {
        tenantUpdateResponse(422, false, 'Selected subscription plan is not available.');
    }
}

$duplicateStmt = $pdo->prepare("
    SELECT id
    FROM tenants
    WHERE tenant_code = :tenant_code
      AND id <> :tenant_id
      AND deleted_at IS NULL
    LIMIT 1
");
$duplicateStmt->execute(
    array(
        ':tenant_code' => $tenantCode,
        ':tenant_id' => $tenantId
    )
);

if ($duplicateStmt->fetchColumn()) {
    tenantUpdateResponse(409, false, 'Tenant code already exists. Please use another code.');
}

$currentSubscription = tenantUpdateLatestSubscription($pdo, $tenantId);

$newLogo = null;
$newInvoiceLogo = null;

try {
    $newLogo = tenantUpdateUpload('logo', $tenantCode);
    $newInvoiceLogo = tenantUpdateUpload('invoice_logo', $tenantCode);

    $logoPath =
        $newLogo !== null
            ? $newLogo['relative_path']
            : $currentTenant['logo_path'];

    $invoiceLogoPath =
        $newInvoiceLogo !== null
            ? $newInvoiceLogo['relative_path']
            : $currentTenant['invoice_logo_path'];

    $pdo->beginTransaction();

    $updateTenantStmt = $pdo->prepare("
        UPDATE tenants
        SET
            tenant_code = :tenant_code,
            legal_name = :legal_name,
            display_name = :display_name,
            business_type = :business_type,
            registration_number = :registration_number,
            tax_number = :tax_number,
            email = :email,
            phone = :phone,
            alternate_phone = :alternate_phone,
            website_url = :website_url,
            country_id = :country_id,
            currency_id = :currency_id,
            timezone = :timezone,
            date_format = :date_format,
            logo_path = :logo_path,
            invoice_logo_path = :invoice_logo_path,
            address_line1 = :address_line1,
            address_line2 = :address_line2,
            city = :city,
            state = :state,
            postal_code = :postal_code,
            status = :status,
            updated_at = NOW()
        WHERE id = :tenant_id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $updateTenantStmt->execute(
        array(
            ':tenant_code' => $tenantCode,
            ':legal_name' => $legalName,
            ':display_name' => $displayName,
            ':business_type' => tenantUpdateNullable($businessType),
            ':registration_number' => tenantUpdateNullable($registrationNumber),
            ':tax_number' => tenantUpdateNullable($taxNumber),
            ':email' => tenantUpdateNullable($email),
            ':phone' => tenantUpdateNullable($phone),
            ':alternate_phone' => tenantUpdateNullable($alternatePhone),
            ':website_url' => tenantUpdateNullable($websiteUrl),
            ':country_id' => $countryId,
            ':currency_id' => $currencyId,
            ':timezone' => $timezone,
            ':date_format' => $dateFormat,
            ':logo_path' => tenantUpdateNullable((string) $logoPath),
            ':invoice_logo_path' => tenantUpdateNullable((string) $invoiceLogoPath),
            ':address_line1' => tenantUpdateNullable($addressLine1),
            ':address_line2' => tenantUpdateNullable($addressLine2),
            ':city' => tenantUpdateNullable($city),
            ':state' => tenantUpdateNullable($state),
            ':postal_code' => tenantUpdateNullable($postalCode),
            ':status' => $status,
            ':tenant_id' => $tenantId
        )
    );

    $createdBy = null;

    if (
        isset($_SESSION['platform_user_id']) &&
        (int) $_SESSION['platform_user_id'] > 0
    ) {
        $createdBy = (int) $_SESSION['platform_user_id'];
    }

    if ($planId > 0 && is_array($plan)) {
        $subscriptionStatus =
            $status === 'trial'
                ? 'trial'
                : (
                    $status === 'suspended'
                        ? 'suspended'
                        : (
                            $status === 'expired'
                                ? 'expired'
                                : (
                                    $status === 'cancelled' || $status === 'archived'
                                        ? 'cancelled'
                                        : 'active'
                                )
                        )
                );

        if ($trialEndDate === '' && (int) $plan['trial_days'] > 0) {
            $trialDate = new DateTime($subscriptionStart);
            $trialDate->modify('+' . (int) $plan['trial_days'] . ' days');
            $trialEndDate = $trialDate->format('Y-m-d');
        }

        $amount = (float) $plan['price'];

        $priceStmt = $pdo->prepare("
            SELECT amount
            FROM plan_prices
            WHERE plan_id = :plan_id
              AND currency_id = :currency_id
              AND is_active = 1
            ORDER BY is_default DESC, id DESC
            LIMIT 1
        ");
        $priceStmt->execute(
            array(
                ':plan_id' => $planId,
                ':currency_id' => $currencyId
            )
        );

        $priceValue = $priceStmt->fetchColumn();

        if ($priceValue !== false) {
            $amount = (float) $priceValue;
        }

        if ($currentSubscription) {
            $subscriptionStmt = $pdo->prepare("
                UPDATE subscriptions
                SET
                    plan_id = :plan_id,
                    currency_id = :currency_id,
                    amount = :amount,
                    start_date = :start_date,
                    expiry_date = :expiry_date,
                    trial_end_date = :trial_end_date,
                    auto_renew = :auto_renew,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :subscription_id
                  AND tenant_id = :tenant_id
                  AND deleted_at IS NULL
                LIMIT 1
            ");

            $subscriptionStmt->execute(
                array(
                    ':plan_id' => $planId,
                    ':currency_id' => $currencyId,
                    ':amount' => $amount,
                    ':start_date' => $subscriptionStart,
                    ':expiry_date' => tenantUpdateNullable($subscriptionExpiry),
                    ':trial_end_date' => tenantUpdateNullable($trialEndDate),
                    ':auto_renew' => $autoRenew,
                    ':status' => $subscriptionStatus,
                    ':subscription_id' => (int) $currentSubscription['id'],
                    ':tenant_id' => $tenantId
                )
            );
        } else {
            $subscriptionStmt = $pdo->prepare("
                INSERT INTO subscriptions (
                    tenant_id,
                    plan_id,
                    currency_id,
                    amount,
                    start_date,
                    expiry_date,
                    trial_end_date,
                    auto_renew,
                    status,
                    created_by
                ) VALUES (
                    :tenant_id,
                    :plan_id,
                    :currency_id,
                    :amount,
                    :start_date,
                    :expiry_date,
                    :trial_end_date,
                    :auto_renew,
                    :status,
                    :created_by
                )
            ");

            $subscriptionStmt->execute(
                array(
                    ':tenant_id' => $tenantId,
                    ':plan_id' => $planId,
                    ':currency_id' => $currencyId,
                    ':amount' => $amount,
                    ':start_date' => $subscriptionStart,
                    ':expiry_date' => tenantUpdateNullable($subscriptionExpiry),
                    ':trial_end_date' => tenantUpdateNullable($trialEndDate),
                    ':auto_renew' => $autoRenew,
                    ':status' => $subscriptionStatus,
                    ':created_by' => $createdBy
                )
            );
        }

        $legacyPlanValue =
            !empty($plan['code'])
                ? (string) $plan['code']
                : (string) $plan['name'];

        $syncLegacyStmt = $pdo->prepare("
            UPDATE tenants
            SET subscription_plan = :subscription_plan
            WHERE id = :tenant_id
            LIMIT 1
        ");
        $syncLegacyStmt->execute(
            array(
                ':subscription_plan' => $legacyPlanValue,
                ':tenant_id' => $tenantId
            )
        );
    } else {
        if ($currentSubscription) {
            $removeStmt = $pdo->prepare("
                UPDATE subscriptions
                SET
                    status = 'cancelled',
                    deleted_at = NOW(),
                    updated_at = NOW()
                WHERE id = :subscription_id
                  AND tenant_id = :tenant_id
                LIMIT 1
            ");
            $removeStmt->execute(
                array(
                    ':subscription_id' => (int) $currentSubscription['id'],
                    ':tenant_id' => $tenantId
                )
            );
        }

        $syncLegacyStmt = $pdo->prepare("
            UPDATE tenants
            SET subscription_plan = NULL
            WHERE id = :tenant_id
            LIMIT 1
        ");
        $syncLegacyStmt->execute(array(':tenant_id' => $tenantId));
    }

    $pdo->commit();

    if (
        $newLogo !== null &&
        !empty($currentTenant['logo_path']) &&
        $currentTenant['logo_path'] !== $newLogo['relative_path']
    ) {
        tenantUpdateDeleteOldFile((string) $currentTenant['logo_path']);
    }

    if (
        $newInvoiceLogo !== null &&
        !empty($currentTenant['invoice_logo_path']) &&
        $currentTenant['invoice_logo_path'] !== $newInvoiceLogo['relative_path']
    ) {
        tenantUpdateDeleteOldFile((string) $currentTenant['invoice_logo_path']);
    }

    $_SESSION['tenant_edit_csrf'] = bin2hex(random_bytes(32));

    tenantUpdateResponse(
        200,
        true,
        'Tenant updated successfully.',
        array(
            'tenant_id' => $tenantId,
            'redirect' => 'tenant-view.php?id=' . $tenantId
        )
    );

} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    foreach (array($newLogo, $newInvoiceLogo) as $uploaded) {
        if (
            is_array($uploaded) &&
            !empty($uploaded['absolute_path']) &&
            is_file($uploaded['absolute_path'])
        ) {
            @unlink($uploaded['absolute_path']);
        }
    }

    error_log('FieldPlx tenant update error: ' . $exception->getMessage());

    if (
        $exception instanceof PDOException &&
        $exception->getCode() === '23000'
    ) {
        tenantUpdateResponse(
            409,
            false,
            'Tenant could not be updated because a unique or related record already exists.'
        );
    }

    tenantUpdateResponse(
        500,
        false,
        'Unable to update tenant. Please try again.'
    );
}
