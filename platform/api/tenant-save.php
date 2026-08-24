<?php
/**
 * FieldPlx API - Create Tenant
 * Endpoint: /platform/api/tenant-save.php
 * PHP 7.2+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function tenantApiResponse(
    int $status,
    bool $success,
    string $message,
    array $extra = array()
): void {
    http_response_code($status);

    echo json_encode(
        array_merge(
            array(
                'success' => $success,
                'message' => $message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function tenantApiPost(
    string $key,
    string $default = ''
): string {
    if (
        !isset($_POST[$key]) ||
        is_array($_POST[$key])
    ) {
        return $default;
    }

    return trim((string) $_POST[$key]);
}

function tenantApiNullable(
    string $value
) {
    $value = trim($value);

    return $value === ''
        ? null
        : $value;
}

function tenantApiValidDate(
    string $value
): bool {
    if ($value === '') {
        return true;
    }

    $date = DateTime::createFromFormat(
        'Y-m-d',
        $value
    );

    return $date !== false &&
        $date->format('Y-m-d') === $value;
}

function tenantApiUpload(
    string $field,
    string $tenantCode
) {
    if (
        !isset($_FILES[$field]) ||
        !is_array($_FILES[$field]) ||
        !isset($_FILES[$field]['error'])
    ) {
        return null;
    }

    $file = $_FILES[$field];

    if (
        $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if (
        $file['error'] !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException(
            'One of the uploaded logo files could not be processed.'
        );
    }

    if (
        !isset($file['size']) ||
        (int) $file['size'] <= 0 ||
        (int) $file['size'] > 3 * 1024 * 1024
    ) {
        throw new RuntimeException(
            'Logo files must be 3 MB or smaller.'
        );
    }

    $tmpName = isset($file['tmp_name'])
        ? (string) $file['tmp_name']
        : '';

    if (
        $tmpName === '' ||
        !is_uploaded_file($tmpName)
    ) {
        throw new RuntimeException(
            'Invalid uploaded logo file.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpName);

    $allowed = array(
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    );

    if (
        !is_string($mime) ||
        !isset($allowed[$mime])
    ) {
        throw new RuntimeException(
            'Only JPG, PNG and WEBP logo files are allowed.'
        );
    }

    $safeTenantCode = preg_replace(
        '/[^A-Za-z0-9_-]+/',
        '-',
        $tenantCode
    );

    $safeTenantCode = trim(
        (string) $safeTenantCode,
        '-'
    );

    if ($safeTenantCode === '') {
        $safeTenantCode = 'tenant';
    }

    $uploadRelativeDir =
        'uploads/tenants/' .
        strtolower($safeTenantCode);

    $uploadAbsoluteDir =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $uploadRelativeDir
        );

    if (
        !is_dir($uploadAbsoluteDir) &&
        !mkdir(
            $uploadAbsoluteDir,
            0755,
            true
        ) &&
        !is_dir($uploadAbsoluteDir)
    ) {
        throw new RuntimeException(
            'Unable to create tenant upload directory.'
        );
    }

    $filename =
        $field .
        '-' .
        date('YmdHis') .
        '-' .
        bin2hex(random_bytes(4)) .
        '.' .
        $allowed[$mime];

    $absolutePath =
        $uploadAbsoluteDir .
        DIRECTORY_SEPARATOR .
        $filename;

    if (
        !move_uploaded_file(
            $tmpName,
            $absolutePath
        )
    ) {
        throw new RuntimeException(
            'Unable to save uploaded logo.'
        );
    }

    return $uploadRelativeDir . '/' . $filename;
}

/*
|--------------------------------------------------------------------------
| Request validation
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {
    tenantApiResponse(
        405,
        false,
        'Method not allowed.'
    );
}

$csrfToken = tenantApiPost('csrf_token');

if (
    empty($_SESSION['tenant_add_csrf']) ||
    !is_string($_SESSION['tenant_add_csrf']) ||
    $csrfToken === '' ||
    !hash_equals(
        $_SESSION['tenant_add_csrf'],
        $csrfToken
    )
) {
    tenantApiResponse(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$tenantCode = strtoupper(
    tenantApiPost('tenant_code')
);

$legalName = tenantApiPost('legal_name');
$displayName = tenantApiPost('display_name');
$businessType = tenantApiPost('business_type');
$registrationNumber =
    tenantApiPost('registration_number');
$taxNumber = tenantApiPost('tax_number');

$email = tenantApiPost('email');
$phone = tenantApiPost('phone');
$alternatePhone =
    tenantApiPost('alternate_phone');
$websiteUrl =
    tenantApiPost('website_url');

$countryId = (int) tenantApiPost(
    'country_id',
    '0'
);

$currencyId = (int) tenantApiPost(
    'currency_id',
    '0'
);

$timezone = tenantApiPost(
    'timezone',
    'UTC'
);

$dateFormat = tenantApiPost(
    'date_format',
    'd-m-Y'
);

$addressLine1 =
    tenantApiPost('address_line1');
$addressLine2 =
    tenantApiPost('address_line2');
$city = tenantApiPost('city');
$state = tenantApiPost('state');
$postalCode = tenantApiPost('postal_code');

$status = strtolower(
    tenantApiPost(
        'status',
        'trial'
    )
);

$planId = (int) tenantApiPost(
    'plan_id',
    '0'
);

$subscriptionStart =
    tenantApiPost(
        'subscription_start',
        date('Y-m-d')
    );

$subscriptionExpiry =
    tenantApiPost('subscription_expiry');

$trialEndDate =
    tenantApiPost('trial_end_date');

$autoRenew =
    tenantApiPost('auto_renew') === '1'
        ? 1
        : 0;

$allowedTenantStatuses = array(
    'trial',
    'active',
    'suspended'
);

$allowedDateFormats = array(
    'd-m-Y',
    'd/m/Y',
    'm-d-Y',
    'm/d/Y',
    'Y-m-d'
);

if ($tenantCode === '') {
    tenantApiResponse(
        422,
        false,
        'Tenant code is required.'
    );
}

if (
    strlen($tenantCode) > 80 ||
    !preg_match(
        '/^[A-Z0-9][A-Z0-9_-]*$/',
        $tenantCode
    )
) {
    tenantApiResponse(
        422,
        false,
        'Tenant code may contain only letters, numbers, hyphens and underscores.'
    );
}

if (
    $legalName === '' ||
    strlen($legalName) > 190
) {
    tenantApiResponse(
        422,
        false,
        'Legal name is required and must be 190 characters or less.'
    );
}

if (
    $displayName === '' ||
    strlen($displayName) > 190
) {
    tenantApiResponse(
        422,
        false,
        'Display name is required and must be 190 characters or less.'
    );
}

if ($countryId <= 0) {
    tenantApiResponse(
        422,
        false,
        'Please select a country.'
    );
}

if ($currencyId <= 0) {
    tenantApiResponse(
        422,
        false,
        'Please select a currency.'
    );
}

if (
    $timezone === '' ||
    strlen($timezone) > 100
) {
    tenantApiResponse(
        422,
        false,
        'Timezone is required.'
    );
}

if (
    !in_array(
        $dateFormat,
        $allowedDateFormats,
        true
    )
) {
    tenantApiResponse(
        422,
        false,
        'Invalid date format.'
    );
}

if (
    !in_array(
        $status,
        $allowedTenantStatuses,
        true
    )
) {
    tenantApiResponse(
        422,
        false,
        'Invalid tenant status.'
    );
}

if (
    $email !== '' &&
    !filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    )
) {
    tenantApiResponse(
        422,
        false,
        'Enter a valid email address.'
    );
}

if (
    $websiteUrl !== '' &&
    !filter_var(
        $websiteUrl,
        FILTER_VALIDATE_URL
    )
) {
    tenantApiResponse(
        422,
        false,
        'Enter a valid website URL including https:// or http://.'
    );
}

if (
    !tenantApiValidDate(
        $subscriptionStart
    ) ||
    !tenantApiValidDate(
        $subscriptionExpiry
    ) ||
    !tenantApiValidDate(
        $trialEndDate
    )
) {
    tenantApiResponse(
        422,
        false,
        'One or more subscription dates are invalid.'
    );
}

if (
    $subscriptionExpiry !== '' &&
    $subscriptionExpiry < $subscriptionStart
) {
    tenantApiResponse(
        422,
        false,
        'Subscription expiry date cannot be before the start date.'
    );
}

if (
    $trialEndDate !== '' &&
    $trialEndDate < $subscriptionStart
) {
    tenantApiResponse(
        422,
        false,
        'Trial end date cannot be before the subscription start date.'
    );
}

/*
|--------------------------------------------------------------------------
| Validate foreign keys
|--------------------------------------------------------------------------
*/

$countryStmt = $pdo->prepare("
    SELECT id
    FROM countries
    WHERE id = :id
      AND is_active = 1
    LIMIT 1
");

$countryStmt->execute(
    array(
        ':id' => $countryId
    )
);

if (!$countryStmt->fetchColumn()) {
    tenantApiResponse(
        422,
        false,
        'Selected country is not available.'
    );
}

$currencyStmt = $pdo->prepare("
    SELECT
        id,
        currency_code
    FROM currencies
    WHERE id = :id
      AND is_active = 1
    LIMIT 1
");

$currencyStmt->execute(
    array(
        ':id' => $currencyId
    )
);

$currencyRow = $currencyStmt->fetch();

if (!$currencyRow) {
    tenantApiResponse(
        422,
        false,
        'Selected currency is not available.'
    );
}

$plan = null;

if ($planId > 0) {
    $planStmt = $pdo->prepare("
        SELECT
            id,
            name,
            price,
            currency,
            billing_cycle,
            trial_days,
            max_users,
            max_branches,
            storage_limit_mb
        FROM plans
        WHERE id = :id
          AND status = 'active'
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $planStmt->execute(
        array(
            ':id' => $planId
        )
    );

    $plan = $planStmt->fetch();

    if (!$plan) {
        tenantApiResponse(
            422,
            false,
            'Selected subscription plan is not available.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Duplicate tenant code
|--------------------------------------------------------------------------
*/

$duplicateStmt = $pdo->prepare("
    SELECT id
    FROM tenants
    WHERE tenant_code = :tenant_code
      AND deleted_at IS NULL
    LIMIT 1
");

$duplicateStmt->execute(
    array(
        ':tenant_code' => $tenantCode
    )
);

if ($duplicateStmt->fetchColumn()) {
    tenantApiResponse(
        409,
        false,
        'Tenant code already exists. Please use another code.'
    );
}

/*
|--------------------------------------------------------------------------
| Save tenant + subscription
|--------------------------------------------------------------------------
*/

$logoPath = null;
$invoiceLogoPath = null;

try {
    $logoPath =
        tenantApiUpload(
            'logo',
            $tenantCode
        );

    $invoiceLogoPath =
        tenantApiUpload(
            'invoice_logo',
            $tenantCode
        );

    $pdo->beginTransaction();

    $createdBy = null;

    if (
        isset($_SESSION['platform_user_id']) &&
        (int) $_SESSION['platform_user_id'] > 0
    ) {
        $createdBy =
            (int) $_SESSION['platform_user_id'];
    }

    $tenantStmt = $pdo->prepare("
        INSERT INTO tenants (
            tenant_code,
            legal_name,
            display_name,
            business_type,
            registration_number,
            tax_number,
            email,
            phone,
            alternate_phone,
            website_url,
            country_id,
            currency_id,
            timezone,
            date_format,
            logo_path,
            invoice_logo_path,
            address_line1,
            address_line2,
            city,
            state,
            postal_code,
            status,
            created_by
        ) VALUES (
            :tenant_code,
            :legal_name,
            :display_name,
            :business_type,
            :registration_number,
            :tax_number,
            :email,
            :phone,
            :alternate_phone,
            :website_url,
            :country_id,
            :currency_id,
            :timezone,
            :date_format,
            :logo_path,
            :invoice_logo_path,
            :address_line1,
            :address_line2,
            :city,
            :state,
            :postal_code,
            :status,
            :created_by
        )
    ");

    $tenantStmt->execute(
        array(
            ':tenant_code' =>
                $tenantCode,
            ':legal_name' =>
                $legalName,
            ':display_name' =>
                $displayName,
            ':business_type' =>
                tenantApiNullable(
                    $businessType
                ),
            ':registration_number' =>
                tenantApiNullable(
                    $registrationNumber
                ),
            ':tax_number' =>
                tenantApiNullable(
                    $taxNumber
                ),
            ':email' =>
                tenantApiNullable(
                    $email
                ),
            ':phone' =>
                tenantApiNullable(
                    $phone
                ),
            ':alternate_phone' =>
                tenantApiNullable(
                    $alternatePhone
                ),
            ':website_url' =>
                tenantApiNullable(
                    $websiteUrl
                ),
            ':country_id' =>
                $countryId,
            ':currency_id' =>
                $currencyId,
            ':timezone' =>
                $timezone,
            ':date_format' =>
                $dateFormat,
            ':logo_path' =>
                $logoPath,
            ':invoice_logo_path' =>
                $invoiceLogoPath,
            ':address_line1' =>
                tenantApiNullable(
                    $addressLine1
                ),
            ':address_line2' =>
                tenantApiNullable(
                    $addressLine2
                ),
            ':city' =>
                tenantApiNullable(
                    $city
                ),
            ':state' =>
                tenantApiNullable(
                    $state
                ),
            ':postal_code' =>
                tenantApiNullable(
                    $postalCode
                ),
            ':status' =>
                $status,
            ':created_by' =>
                $createdBy
        )
    );

    $tenantId =
        (int) $pdo->lastInsertId();

    $subscriptionId = null;

    if (
        $planId > 0 &&
        is_array($plan)
    ) {
        $subscriptionStatus =
            $status === 'trial'
                ? 'trial'
                : (
                    $status === 'suspended'
                        ? 'suspended'
                        : 'active'
                );

        if (
            $trialEndDate === '' &&
            (int) $plan['trial_days'] > 0
        ) {
            $trialDate = new DateTime(
                $subscriptionStart
            );

            $trialDate->modify(
                '+' .
                (int) $plan['trial_days'] .
                ' days'
            );

            $trialEndDate =
                $trialDate->format(
                    'Y-m-d'
                );
        }

        $subscriptionStmt =
            $pdo->prepare("
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
                ':tenant_id' =>
                    $tenantId,
                ':plan_id' =>
                    $planId,
                ':currency_id' =>
                    $currencyId,
                ':amount' =>
                    (float) $plan['price'],
                ':start_date' =>
                    $subscriptionStart,
                ':expiry_date' =>
                    tenantApiNullable(
                        $subscriptionExpiry
                    ),
                ':trial_end_date' =>
                    tenantApiNullable(
                        $trialEndDate
                    ),
                ':auto_renew' =>
                    $autoRenew,
                ':status' =>
                    $subscriptionStatus,
                ':created_by' =>
                    $createdBy
            )
        );

        $subscriptionId =
            (int) $pdo->lastInsertId();
    }

    $pdo->commit();

    /*
     * Rotate token after successful creation.
     */
    $_SESSION['tenant_add_csrf'] =
        bin2hex(random_bytes(32));

    tenantApiResponse(
        201,
        true,
        'Tenant created successfully.',
        array(
            'tenant_id' =>
                $tenantId,
            'tenant_code' =>
                $tenantCode,
            'subscription_id' =>
                $subscriptionId,
            'redirect' =>
                '../tenants.php'
        )
    );

} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    /*
     * Remove uploaded files if DB transaction fails.
     */
    foreach (
        array(
            $logoPath,
            $invoiceLogoPath
        ) as $relativePath
    ) {
        if (
            is_string($relativePath) &&
            $relativePath !== ''
        ) {
            $absolutePath =
                dirname(__DIR__) .
                DIRECTORY_SEPARATOR .
                str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relativePath
                );

            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    error_log(
        'FieldPlx tenant creation error: ' .
        $exception->getMessage()
    );

    if (
        $exception instanceof PDOException &&
        $exception->getCode() === '23000'
    ) {
        tenantApiResponse(
            409,
            false,
            'Tenant could not be created because a unique or related record already exists.'
        );
    }

    tenantApiResponse(
        500,
        false,
        'Unable to create tenant. Please try again.'
    );
}
