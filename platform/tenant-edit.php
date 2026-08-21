<?php
/**
 * FieldPlx Platform - Edit Tenant
 *
 * File:
 * platform/tenant-edit.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 * - platform_users authentication
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin'
));

$pageTitle = 'Edit Tenant - FieldPlx';
$activePage = 'tenant-edit';
$basePath = '';

@set_time_limit(30);

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('tenantEditEscape')) {
    function tenantEditEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('tenantEditPost')) {
    function tenantEditPost($key, $default = '')
    {
        if (
            !isset($_POST[$key]) ||
            is_array($_POST[$key])
        ) {
            return $default;
        }

        return trim((string) $_POST[$key]);
    }
}

if (!function_exists('tenantEditTableExists')) {
    function tenantEditTableExists(
        mysqli $conn,
        $tableName
    ) {
        static $cache = array();

        $tableName = trim((string) $tableName);

        if ($tableName === '') {
            return false;
        }

        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        if (!$stmt) {
            $cache[$tableName] = false;
            return false;
        }

        $stmt->bind_param('s', $tableName);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        $cache[$tableName] = !empty($row['total']);

        return $cache[$tableName];
    }
}

if (!function_exists('tenantEditColumns')) {
    function tenantEditColumns(
        mysqli $conn,
        $tableName
    ) {
        static $cache = array();

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $cache[$tableName] = array();

        $safeTable = str_replace('`', '``', $tableName);

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$safeTable}`"
        );

        while ($row = $result->fetch_assoc()) {
            if (!empty($row['Field'])) {
                $cache[$tableName][
                    (string) $row['Field']
                ] = $row;
            }
        }

        $result->free();

        return $cache[$tableName];
    }
}

if (!function_exists('tenantEditFirstColumn')) {
    function tenantEditFirstColumn(
        array $columns,
        array $candidates
    ) {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('tenantEditBind')) {
    function tenantEditBind(
        mysqli_stmt $stmt,
        $types,
        array &$values
    ) {
        if ($types === '') {
            return true;
        }

        $arguments = array($types);

        foreach ($values as $key => $value) {
            $arguments[] = &$values[$key];
        }

        return call_user_func_array(
            array($stmt, 'bind_param'),
            $arguments
        );
    }
}

if (!function_exists('tenantEditCode')) {
    function tenantEditCode($value)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $value
        );

        return trim($value, '-');
    }
}

if (!function_exists('tenantEditEnumValues')) {
    function tenantEditEnumValues($columnType)
    {
        $values = array();

        if (stripos((string) $columnType, 'enum(') !== 0) {
            return $values;
        }

        preg_match_all(
            "/'((?:[^'\\\\]|\\\\.)*)'/",
            (string) $columnType,
            $matches
        );

        if (!empty($matches[1])) {
            foreach ($matches[1] as $value) {
                $values[] = stripcslashes($value);
            }
        }

        return $values;
    }
}

if (!function_exists('tenantEditUploadLogo')) {
    function tenantEditUploadLogo($fieldName)
    {
        if (
            empty($_FILES[$fieldName]) ||
            !isset($_FILES[$fieldName]['error']) ||
            (int) $_FILES[$fieldName]['error'] ===
                UPLOAD_ERR_NO_FILE
        ) {
            return array(
                'success' => true,
                'path' => ''
            );
        }

        $file = $_FILES[$fieldName];

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return array(
                'success' => false,
                'message' => 'Unable to upload the tenant logo.'
            );
        }

        if ((int) $file['size'] > 3 * 1024 * 1024) {
            return array(
                'success' => false,
                'message' => 'Tenant logo must not exceed 3 MB.'
            );
        }

        $allowedTypes = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg'
        );

        $mimeType = '';

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo) {
                $mimeType = finfo_file(
                    $finfo,
                    $file['tmp_name']
                );

                finfo_close($finfo);
            }
        }

        if (
            $mimeType === '' &&
            function_exists('mime_content_type')
        ) {
            $mimeType = mime_content_type(
                $file['tmp_name']
            );
        }

        if (!isset($allowedTypes[$mimeType])) {
            return array(
                'success' => false,
                'message' => 'Upload a JPG, PNG, WEBP, or SVG logo.'
            );
        }

        $uploadDirectory =
            __DIR__ . '/../uploads/tenants/logos';

        if (
            !is_dir($uploadDirectory) &&
            !mkdir($uploadDirectory, 0755, true)
        ) {
            return array(
                'success' => false,
                'message' => 'Unable to create the tenant logo directory.'
            );
        }

        try {
            $randomPart = bin2hex(random_bytes(4));
        } catch (Exception $exception) {
            $randomPart = uniqid();
        }

        $fileName =
            'tenant-' .
            date('YmdHis') .
            '-' .
            $randomPart .
            '.' .
            $allowedTypes[$mimeType];

        $absolutePath =
            $uploadDirectory . '/' . $fileName;

        if (
            !move_uploaded_file(
                $file['tmp_name'],
                $absolutePath
            )
        ) {
            return array(
                'success' => false,
                'message' => 'Unable to save the tenant logo.'
            );
        }

        return array(
            'success' => true,
            'path' => 'uploads/tenants/logos/' . $fileName
        );
    }
}

/*
|--------------------------------------------------------------------------
| Verify tenants table
|--------------------------------------------------------------------------
*/

if (!tenantEditTableExists($conn, 'tenants')) {
    http_response_code(500);
    exit('The tenants table does not exist.');
}

$tenantColumns = tenantEditColumns(
    $conn,
    'tenants'
);

/*
|--------------------------------------------------------------------------
| Detect columns
|--------------------------------------------------------------------------
*/

$tenantIdColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('id', 'tenant_id')
);

$tenantNameColumn = tenantEditFirstColumn(
    $tenantColumns,
    array(
        'company_name',
        'business_name',
        'tenant_name',
        'name'
    )
);

$tenantCodeColumn = tenantEditFirstColumn(
    $tenantColumns,
    array(
        'tenant_code',
        'code',
        'business_code'
    )
);

$tenantSlugColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('slug', 'tenant_slug')
);

$tenantEmailColumn = tenantEditFirstColumn(
    $tenantColumns,
    array(
        'email',
        'business_email',
        'contact_email'
    )
);

$tenantPhoneColumn = tenantEditFirstColumn(
    $tenantColumns,
    array(
        'phone',
        'mobile',
        'contact_phone'
    )
);

$tenantContactNameColumn = tenantEditFirstColumn(
    $tenantColumns,
    array(
        'contact_name',
        'owner_name',
        'primary_contact_name'
    )
);

$tenantAddressColumn = tenantEditFirstColumn(
    $tenantColumns,
    array(
        'address',
        'business_address',
        'registered_address'
    )
);

$tenantCityColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('city')
);

$tenantStateColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('state')
);

$tenantCountryColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('country')
);

$tenantPostalCodeColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('postal_code', 'pincode', 'zip_code')
);

$tenantTaxNumberColumn = tenantEditFirstColumn(
    $tenantColumns,
    array(
        'tax_number',
        'gst_number',
        'gstin',
        'vat_number'
    )
);

$tenantStatusColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('status')
);

$tenantLogoColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('logo_path', 'logo', 'business_logo')
);

$tenantTimezoneColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('timezone', 'time_zone')
);

$tenantCurrencyColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('currency', 'currency_code')
);

$tenantTrialEndsColumn = tenantEditFirstColumn(
    $tenantColumns,
    array(
        'trial_ends_at',
        'trial_end_date',
        'trial_until'
    )
);

$tenantNotesColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('notes', 'remarks', 'description')
);

$tenantCreatedAtColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('created_at')
);

$tenantUpdatedAtColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('updated_at')
);

$tenantDeletedColumn = tenantEditFirstColumn(
    $tenantColumns,
    array('deleted_at')
);

if (
    $tenantIdColumn === '' ||
    $tenantNameColumn === ''
) {
    http_response_code(500);
    exit('The tenants table requires id and name columns.');
}

/*
|--------------------------------------------------------------------------
| Load tenant
|--------------------------------------------------------------------------
*/

$tenantId = isset($_GET['id'])
    ? (int) $_GET['id']
    : (
        isset($_POST['tenant_id'])
            ? (int) $_POST['tenant_id']
            : 0
    );

if ($tenantId <= 0) {
    $_SESSION['platform_error_message'] =
        'Invalid tenant.';

    header('Location: tenants.php');
    exit;
}

$select = array(
    "`{$tenantIdColumn}` AS tenant_id",
    "`{$tenantNameColumn}` AS tenant_name"
);

$select[] = $tenantCodeColumn !== ''
    ? "`{$tenantCodeColumn}` AS tenant_code"
    : "'' AS tenant_code";

$select[] = $tenantSlugColumn !== ''
    ? "`{$tenantSlugColumn}` AS tenant_slug"
    : "'' AS tenant_slug";

$select[] = $tenantEmailColumn !== ''
    ? "`{$tenantEmailColumn}` AS tenant_email"
    : "'' AS tenant_email";

$select[] = $tenantPhoneColumn !== ''
    ? "`{$tenantPhoneColumn}` AS tenant_phone"
    : "'' AS tenant_phone";

$select[] = $tenantContactNameColumn !== ''
    ? "`{$tenantContactNameColumn}` AS contact_name"
    : "'' AS contact_name";

$select[] = $tenantAddressColumn !== ''
    ? "`{$tenantAddressColumn}` AS tenant_address"
    : "'' AS tenant_address";

$select[] = $tenantCityColumn !== ''
    ? "`{$tenantCityColumn}` AS tenant_city"
    : "'' AS tenant_city";

$select[] = $tenantStateColumn !== ''
    ? "`{$tenantStateColumn}` AS tenant_state"
    : "'' AS tenant_state";

$select[] = $tenantCountryColumn !== ''
    ? "`{$tenantCountryColumn}` AS tenant_country"
    : "'' AS tenant_country";

$select[] = $tenantPostalCodeColumn !== ''
    ? "`{$tenantPostalCodeColumn}` AS postal_code"
    : "'' AS postal_code";

$select[] = $tenantTaxNumberColumn !== ''
    ? "`{$tenantTaxNumberColumn}` AS tax_number"
    : "'' AS tax_number";

$select[] = $tenantStatusColumn !== ''
    ? "`{$tenantStatusColumn}` AS tenant_status"
    : "'active' AS tenant_status";

$select[] = $tenantLogoColumn !== ''
    ? "`{$tenantLogoColumn}` AS logo_path"
    : "'' AS logo_path";

$select[] = $tenantTimezoneColumn !== ''
    ? "`{$tenantTimezoneColumn}` AS timezone"
    : "'Asia/Kolkata' AS timezone";

$select[] = $tenantCurrencyColumn !== ''
    ? "`{$tenantCurrencyColumn}` AS currency"
    : "'INR' AS currency";

$select[] = $tenantTrialEndsColumn !== ''
    ? "`{$tenantTrialEndsColumn}` AS trial_ends_at"
    : "NULL AS trial_ends_at";

$select[] = $tenantNotesColumn !== ''
    ? "`{$tenantNotesColumn}` AS notes"
    : "'' AS notes";

$select[] = $tenantCreatedAtColumn !== ''
    ? "`{$tenantCreatedAtColumn}` AS created_at"
    : "NULL AS created_at";

$select[] = $tenantUpdatedAtColumn !== ''
    ? "`{$tenantUpdatedAtColumn}` AS updated_at"
    : "NULL AS updated_at";

$sql = "
    SELECT
        " . implode(",\n        ", $select) . "
    FROM tenants
    WHERE `{$tenantIdColumn}` = ?
";

if ($tenantDeletedColumn !== '') {
    $sql .= "
        AND `{$tenantDeletedColumn}` IS NULL
    ";
}

$sql .= " LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $tenantId);
$stmt->execute();

$result = $stmt->get_result();
$currentTenant = $result->fetch_assoc();

$stmt->close();

if (!$currentTenant) {
    $_SESSION['platform_error_message'] =
        'Tenant not found.';

    header('Location: tenants.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$errorMessage = '';

$tenantName = isset($_POST['tenant_name'])
    ? tenantEditPost('tenant_name')
    : (string) $currentTenant['tenant_name'];

$tenantCode = isset($_POST['tenant_code'])
    ? tenantEditCode(tenantEditPost('tenant_code'))
    : (string) $currentTenant['tenant_code'];

$tenantSlug = isset($_POST['tenant_slug'])
    ? tenantEditCode(tenantEditPost('tenant_slug'))
    : (string) $currentTenant['tenant_slug'];

$tenantEmail = isset($_POST['tenant_email'])
    ? strtolower(tenantEditPost('tenant_email'))
    : strtolower((string) $currentTenant['tenant_email']);

$tenantPhone = isset($_POST['tenant_phone'])
    ? tenantEditPost('tenant_phone')
    : (string) $currentTenant['tenant_phone'];

$contactName = isset($_POST['contact_name'])
    ? tenantEditPost('contact_name')
    : (string) $currentTenant['contact_name'];

$tenantAddress = isset($_POST['tenant_address'])
    ? tenantEditPost('tenant_address')
    : (string) $currentTenant['tenant_address'];

$tenantCity = isset($_POST['tenant_city'])
    ? tenantEditPost('tenant_city')
    : (string) $currentTenant['tenant_city'];

$tenantState = isset($_POST['tenant_state'])
    ? tenantEditPost('tenant_state')
    : (string) $currentTenant['tenant_state'];

$tenantCountry = isset($_POST['tenant_country'])
    ? tenantEditPost('tenant_country', 'India')
    : (
        !empty($currentTenant['tenant_country'])
            ? (string) $currentTenant['tenant_country']
            : 'India'
    );

$postalCode = isset($_POST['postal_code'])
    ? tenantEditPost('postal_code')
    : (string) $currentTenant['postal_code'];

$taxNumber = isset($_POST['tax_number'])
    ? tenantEditPost('tax_number')
    : (string) $currentTenant['tax_number'];

$status = isset($_POST['status'])
    ? strtolower(tenantEditPost('status'))
    : strtolower((string) $currentTenant['tenant_status']);

$timezone = isset($_POST['timezone'])
    ? tenantEditPost('timezone', 'Asia/Kolkata')
    : (
        !empty($currentTenant['timezone'])
            ? (string) $currentTenant['timezone']
            : 'Asia/Kolkata'
    );

$currency = isset($_POST['currency'])
    ? strtoupper(tenantEditPost('currency', 'INR'))
    : (
        !empty($currentTenant['currency'])
            ? strtoupper((string) $currentTenant['currency'])
            : 'INR'
    );

$trialEndsAt = isset($_POST['trial_ends_at'])
    ? tenantEditPost('trial_ends_at')
    : (
        !empty($currentTenant['trial_ends_at'])
            ? date(
                'Y-m-d',
                strtotime(
                    (string)
                    $currentTenant['trial_ends_at']
                )
            )
            : ''
    );

$notes = isset($_POST['notes'])
    ? tenantEditPost('notes')
    : (string) $currentTenant['notes'];

$removeLogo = !empty($_POST['remove_logo'])
    ? 1
    : 0;

$statusOptions = array(
    'active',
    'trial',
    'inactive',
    'suspended'
);

if ($tenantStatusColumn !== '') {
    $columnType = isset(
        $tenantColumns[$tenantStatusColumn]['Type']
    )
        ? $tenantColumns[$tenantStatusColumn]['Type']
        : '';

    $enumValues = tenantEditEnumValues(
        $columnType
    );

    if (!empty($enumValues)) {
        $statusOptions = $enumValues;
    }
}

if (!in_array($status, $statusOptions, true)) {
    $status = in_array('active', $statusOptions, true)
        ? 'active'
        : $statusOptions[0];
}

/*
|--------------------------------------------------------------------------
| Process update
|--------------------------------------------------------------------------
*/

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    if ($tenantName === '') {
        $errorMessage = 'Enter the tenant name.';
    } elseif (strlen($tenantName) > 190) {
        $errorMessage =
            'Tenant name must not exceed 190 characters.';
    } elseif (
        $tenantEmail !== '' &&
        filter_var(
            $tenantEmail,
            FILTER_VALIDATE_EMAIL
        ) === false
    ) {
        $errorMessage = 'Enter a valid tenant email address.';
    } elseif (strlen($tenantPhone) > 50) {
        $errorMessage =
            'Tenant phone must not exceed 50 characters.';
    } elseif (strlen($currency) > 10) {
        $errorMessage =
            'Currency code must not exceed 10 characters.';
    }

    if (
        $errorMessage === '' &&
        $tenantCodeColumn !== '' &&
        $tenantCode !== ''
    ) {
        $duplicateSql = "
            SELECT COUNT(*) AS total
            FROM tenants
            WHERE LOWER(`{$tenantCodeColumn}`) =
                  LOWER(?)
              AND `{$tenantIdColumn}` <> ?
        ";

        $duplicateParams = array(
            $tenantCode,
            $tenantId
        );

        $duplicateStmt = $conn->prepare(
            $duplicateSql
        );

        tenantEditBind(
            $duplicateStmt,
            'si',
            $duplicateParams
        );

        $duplicateStmt->execute();

        $duplicateRow =
            $duplicateStmt
                ->get_result()
                ->fetch_assoc();

        $duplicateStmt->close();

        if (!empty($duplicateRow['total'])) {
            $errorMessage =
                'Another tenant already uses this tenant code.';
        }
    }

    if (
        $errorMessage === '' &&
        $tenantSlugColumn !== '' &&
        $tenantSlug !== ''
    ) {
        $duplicateSql = "
            SELECT COUNT(*) AS total
            FROM tenants
            WHERE LOWER(`{$tenantSlugColumn}`) =
                  LOWER(?)
              AND `{$tenantIdColumn}` <> ?
        ";

        $duplicateParams = array(
            $tenantSlug,
            $tenantId
        );

        $duplicateStmt = $conn->prepare(
            $duplicateSql
        );

        tenantEditBind(
            $duplicateStmt,
            'si',
            $duplicateParams
        );

        $duplicateStmt->execute();

        $duplicateRow =
            $duplicateStmt
                ->get_result()
                ->fetch_assoc();

        $duplicateStmt->close();

        if (!empty($duplicateRow['total'])) {
            $errorMessage =
                'Another tenant already uses this slug.';
        }
    }

    if (
        $errorMessage === '' &&
        $tenantEmailColumn !== '' &&
        $tenantEmail !== ''
    ) {
        $duplicateSql = "
            SELECT COUNT(*) AS total
            FROM tenants
            WHERE LOWER(`{$tenantEmailColumn}`) =
                  LOWER(?)
              AND `{$tenantIdColumn}` <> ?
        ";

        $duplicateParams = array(
            $tenantEmail,
            $tenantId
        );

        $duplicateStmt = $conn->prepare(
            $duplicateSql
        );

        tenantEditBind(
            $duplicateStmt,
            'si',
            $duplicateParams
        );

        $duplicateStmt->execute();

        $duplicateRow =
            $duplicateStmt
                ->get_result()
                ->fetch_assoc();

        $duplicateStmt->close();

        if (!empty($duplicateRow['total'])) {
            $errorMessage =
                'Another tenant already uses this email address.';
        }
    }

    $uploadedLogoPath = '';

    if (
        $errorMessage === '' &&
        $tenantLogoColumn !== ''
    ) {
        $logoUpload =
            tenantEditUploadLogo('logo');

        if (!$logoUpload['success']) {
            $errorMessage =
                $logoUpload['message'];
        } else {
            $uploadedLogoPath =
                $logoUpload['path'];
        }
    }

    if ($errorMessage === '') {
        try {
            $conn->begin_transaction();

            $updateData = array();

            $updateData[$tenantNameColumn] =
                $tenantName;

            if ($tenantCodeColumn !== '') {
                $updateData[$tenantCodeColumn] =
                    $tenantCode;
            }

            if ($tenantSlugColumn !== '') {
                $updateData[$tenantSlugColumn] =
                    $tenantSlug;
            }

            if ($tenantEmailColumn !== '') {
                $updateData[$tenantEmailColumn] =
                    $tenantEmail;
            }

            if ($tenantPhoneColumn !== '') {
                $updateData[$tenantPhoneColumn] =
                    $tenantPhone;
            }

            if ($tenantContactNameColumn !== '') {
                $updateData[$tenantContactNameColumn] =
                    $contactName;
            }

            if ($tenantAddressColumn !== '') {
                $updateData[$tenantAddressColumn] =
                    $tenantAddress;
            }

            if ($tenantCityColumn !== '') {
                $updateData[$tenantCityColumn] =
                    $tenantCity;
            }

            if ($tenantStateColumn !== '') {
                $updateData[$tenantStateColumn] =
                    $tenantState;
            }

            if ($tenantCountryColumn !== '') {
                $updateData[$tenantCountryColumn] =
                    $tenantCountry;
            }

            if ($tenantPostalCodeColumn !== '') {
                $updateData[$tenantPostalCodeColumn] =
                    $postalCode;
            }

            if ($tenantTaxNumberColumn !== '') {
                $updateData[$tenantTaxNumberColumn] =
                    $taxNumber;
            }

            if ($tenantStatusColumn !== '') {
                $updateData[$tenantStatusColumn] =
                    $status;
            }

            if ($tenantTimezoneColumn !== '') {
                $updateData[$tenantTimezoneColumn] =
                    $timezone;
            }

            if ($tenantCurrencyColumn !== '') {
                $updateData[$tenantCurrencyColumn] =
                    $currency;
            }

            if ($tenantTrialEndsColumn !== '') {
                $updateData[$tenantTrialEndsColumn] =
                    $trialEndsAt !== ''
                        ? $trialEndsAt
                        : null;
            }

            if ($tenantNotesColumn !== '') {
                $updateData[$tenantNotesColumn] =
                    $notes;
            }

            $oldLogoPath =
                (string) $currentTenant['logo_path'];

            if ($tenantLogoColumn !== '') {
                if ($uploadedLogoPath !== '') {
                    $updateData[$tenantLogoColumn] =
                        $uploadedLogoPath;
                } elseif ($removeLogo === 1) {
                    $updateData[$tenantLogoColumn] =
                        '';
                }
            }

            $setParts = array();
            $values = array();
            $types = '';

            foreach (
                $updateData as
                $column => $value
            ) {
                if ($value === null) {
                    $setParts[] =
                        "`{$column}` = NULL";
                    continue;
                }

                $setParts[] =
                    "`{$column}` = ?";
                $values[] = $value;

                $columnType = isset(
                    $tenantColumns[$column]['Type']
                )
                    ? strtolower(
                        (string)
                        $tenantColumns[$column]['Type']
                    )
                    : '';

                if (
                    preg_match(
                        '/^(tinyint|smallint|mediumint|int|bigint)/',
                        $columnType
                    )
                ) {
                    $types .= 'i';
                } elseif (
                    preg_match(
                        '/^(decimal|float|double|real)/',
                        $columnType
                    )
                ) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
            }

            if ($tenantUpdatedAtColumn !== '') {
                $setParts[] =
                    "`{$tenantUpdatedAtColumn}` = NOW()";
            }

            $values[] = $tenantId;
            $types .= 'i';

            $updateSql = "
                UPDATE tenants
                SET " . implode(', ', $setParts) . "
                WHERE `{$tenantIdColumn}` = ?
                LIMIT 1
            ";

            $updateStmt = $conn->prepare(
                $updateSql
            );

            tenantEditBind(
                $updateStmt,
                $types,
                $values
            );

            $updateStmt->execute();
            $updateStmt->close();

            $conn->commit();

            if (
                $tenantLogoColumn !== '' &&
                (
                    $uploadedLogoPath !== '' ||
                    $removeLogo === 1
                ) &&
                $oldLogoPath !== '' &&
                file_exists(
                    __DIR__ .
                    '/../' .
                    ltrim($oldLogoPath, '/')
                )
            ) {
                @unlink(
                    __DIR__ .
                    '/../' .
                    ltrim($oldLogoPath, '/')
                );
            }

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Tenant updated successfully.';

            header(
                'Location: tenant-view.php?id=' .
                (int) $tenantId,
                true,
                303
            );

            exit;
        } catch (Exception $exception) {
            $conn->rollback();

            if (
                $uploadedLogoPath !== '' &&
                file_exists(
                    __DIR__ .
                    '/../' .
                    $uploadedLogoPath
                )
            ) {
                @unlink(
                    __DIR__ .
                    '/../' .
                    $uploadedLogoPath
                );
            }

            error_log(
                'Tenant update failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                $exception->getMessage();
        }
    }
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .tenant-edit-page {
        max-width: 1080px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .tenant-edit-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .tenant-edit-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .tenant-edit-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .tenant-edit-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .tenant-edit-button {
        min-height: 36px;
        padding: 7px 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #4b5563;
        font-size: 9px;
        font-weight: 700;
        text-decoration: none;
    }

    .tenant-edit-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .tenant-edit-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid #fecaca;
        border-radius: 10px;
        background: #fef2f2;
        color: #b91c1c;
        font-size: 10px;
        line-height: 1.55;
    }

    .tenant-edit-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(270px, 320px);
        gap: 15px;
        align-items: start;
    }

    .tenant-edit-main,
    .tenant-edit-side {
        display: grid;
        gap: 15px;
    }

    .tenant-edit-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .tenant-edit-card-header {
        min-height: 53px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .tenant-edit-card-icon {
        width: 32px;
        height: 32px;
        flex: 0 0 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 13px;
    }

    .tenant-edit-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .tenant-edit-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-edit-card-body {
        padding: 15px;
    }

    .tenant-edit-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .tenant-edit-required {
        color: #dc2626;
    }

    .tenant-edit-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    textarea.tenant-edit-control {
        min-height: 105px;
        resize: vertical;
    }

    .tenant-edit-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .tenant-edit-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .tenant-edit-logo-preview {
        width: 90px;
        height: 90px;
        margin: 0 auto 12px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 14px;
        background: #111827;
        color: #ffffff;
        font-size: 20px;
        font-weight: 800;
    }

    .tenant-edit-logo-preview img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        background: #ffffff;
    }

    .tenant-edit-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .tenant-edit-submit {
        min-height: 41px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border: 0;
        border-radius: 9px;
        background:
            linear-gradient(
                135deg,
                #7c3aed,
                #6d28d9
            );
        color: #ffffff;
        font-size: 10px;
        font-weight: 700;
    }

    .tenant-edit-cancel {
        min-height: 37px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #6b7280;
        font-size: 9px;
        font-weight: 600;
        text-decoration: none;
    }

    @media (max-width: 900px) {
        .tenant-edit-layout {
            grid-template-columns: 1fr;
        }

        .tenant-edit-side {
            order: -1;
        }
    }

    @media (max-width: 600px) {
        .tenant-edit-header {
            align-items: flex-start;
            flex-direction: column;
        }
    }
</style>

<div class="tenant-edit-page">

    <div class="tenant-edit-header">
        <div>
            <h2 class="tenant-edit-title">
                Edit Tenant
            </h2>

            <div class="tenant-edit-description">
                Update tenant details, status, trial, and branding.
            </div>
        </div>

        <div class="tenant-edit-actions">
            <a
                href="tenant-view.php?id=<?= (int) $tenantId; ?>"
                class="tenant-edit-button"
            >
                <i class="bi bi-eye"></i>
                View Tenant
            </a>

            <a
                href="tenants.php"
                class="tenant-edit-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Tenants
            </a>
        </div>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="tenant-edit-alert">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= tenantEditEscape($errorMessage); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        enctype="multipart/form-data"
        id="tenantEditForm"
    >
        <?php csrfField(); ?>

        <input
            type="hidden"
            name="tenant_id"
            value="<?= (int) $tenantId; ?>"
        >

        <div class="tenant-edit-layout">

            <div class="tenant-edit-main">

                <section class="tenant-edit-card">
                    <div class="tenant-edit-card-header">
                        <span class="tenant-edit-card-icon">
                            <i class="bi bi-building"></i>
                        </span>

                        <div>
                            <h3 class="tenant-edit-card-title">
                                Business Information
                            </h3>

                            <div class="tenant-edit-card-subtitle">
                                Tenant identity and contact details
                            </div>
                        </div>
                    </div>

                    <div class="tenant-edit-card-body">
                        <div class="row g-3">

                            <div class="col-12">
                                <label
                                    class="tenant-edit-label"
                                    for="tenantName"
                                >
                                    Tenant Name
                                    <span class="tenant-edit-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    class="form-control tenant-edit-control"
                                    id="tenantName"
                                    name="tenant_name"
                                    value="<?= tenantEditEscape(
                                        $tenantName
                                    ); ?>"
                                    maxlength="190"
                                    required
                                >
                            </div>

                            <?php if ($tenantCodeColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-edit-label"
                                        for="tenantCode"
                                    >
                                        Tenant Code
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-edit-control"
                                        id="tenantCode"
                                        name="tenant_code"
                                        value="<?= tenantEditEscape(
                                            $tenantCode
                                        ); ?>"
                                        maxlength="120"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($tenantSlugColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-edit-label"
                                        for="tenantSlug"
                                    >
                                        Tenant Slug
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-edit-control"
                                        id="tenantSlug"
                                        name="tenant_slug"
                                        value="<?= tenantEditEscape(
                                            $tenantSlug
                                        ); ?>"
                                        maxlength="150"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($tenantEmailColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-edit-label"
                                        for="tenantEmail"
                                    >
                                        Email Address
                                    </label>

                                    <input
                                        type="email"
                                        class="form-control tenant-edit-control"
                                        id="tenantEmail"
                                        name="tenant_email"
                                        value="<?= tenantEditEscape(
                                            $tenantEmail
                                        ); ?>"
                                        maxlength="190"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($tenantPhoneColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-edit-label"
                                        for="tenantPhone"
                                    >
                                        Phone Number
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-edit-control"
                                        id="tenantPhone"
                                        name="tenant_phone"
                                        value="<?= tenantEditEscape(
                                            $tenantPhone
                                        ); ?>"
                                        maxlength="50"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if (
                                $tenantContactNameColumn !== ''
                            ): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-edit-label"
                                        for="contactName"
                                    >
                                        Contact Person
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-edit-control"
                                        id="contactName"
                                        name="contact_name"
                                        value="<?= tenantEditEscape(
                                            $contactName
                                        ); ?>"
                                        maxlength="150"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if (
                                $tenantTaxNumberColumn !== ''
                            ): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-edit-label"
                                        for="taxNumber"
                                    >
                                        Tax / GST Number
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-edit-control"
                                        id="taxNumber"
                                        name="tax_number"
                                        value="<?= tenantEditEscape(
                                            $taxNumber
                                        ); ?>"
                                        maxlength="100"
                                    >
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </section>

                <section class="tenant-edit-card">
                    <div class="tenant-edit-card-header">
                        <span class="tenant-edit-card-icon">
                            <i class="bi bi-geo-alt"></i>
                        </span>

                        <div>
                            <h3 class="tenant-edit-card-title">
                                Address Information
                            </h3>

                            <div class="tenant-edit-card-subtitle">
                                Registered tenant location
                            </div>
                        </div>
                    </div>

                    <div class="tenant-edit-card-body">
                        <div class="row g-3">

                            <?php if (
                                $tenantAddressColumn !== ''
                            ): ?>
                                <div class="col-12">
                                    <label
                                        class="tenant-edit-label"
                                        for="tenantAddress"
                                    >
                                        Address
                                    </label>

                                    <textarea
                                        class="form-control tenant-edit-control"
                                        id="tenantAddress"
                                        name="tenant_address"
                                    ><?= tenantEditEscape(
                                        $tenantAddress
                                    ); ?></textarea>
                                </div>
                            <?php endif; ?>

                            <?php if ($tenantCityColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-edit-label"
                                        for="tenantCity"
                                    >
                                        City
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-edit-control"
                                        id="tenantCity"
                                        name="tenant_city"
                                        value="<?= tenantEditEscape(
                                            $tenantCity
                                        ); ?>"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($tenantStateColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-edit-label"
                                        for="tenantState"
                                    >
                                        State
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-edit-control"
                                        id="tenantState"
                                        name="tenant_state"
                                        value="<?= tenantEditEscape(
                                            $tenantState
                                        ); ?>"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($tenantCountryColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-edit-label"
                                        for="tenantCountry"
                                    >
                                        Country
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-edit-control"
                                        id="tenantCountry"
                                        name="tenant_country"
                                        value="<?= tenantEditEscape(
                                            $tenantCountry
                                        ); ?>"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if (
                                $tenantPostalCodeColumn !== ''
                            ): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-edit-label"
                                        for="postalCode"
                                    >
                                        Postal Code
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-edit-control"
                                        id="postalCode"
                                        name="postal_code"
                                        value="<?= tenantEditEscape(
                                            $postalCode
                                        ); ?>"
                                    >
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </section>

                <?php if ($tenantNotesColumn !== ''): ?>
                    <section class="tenant-edit-card">
                        <div class="tenant-edit-card-header">
                            <span class="tenant-edit-card-icon">
                                <i class="bi bi-journal-text"></i>
                            </span>

                            <div>
                                <h3 class="tenant-edit-card-title">
                                    Notes
                                </h3>

                                <div class="tenant-edit-card-subtitle">
                                    Internal tenant notes
                                </div>
                            </div>
                        </div>

                        <div class="tenant-edit-card-body">
                            <textarea
                                class="form-control tenant-edit-control"
                                name="notes"
                                placeholder="Add internal notes about this tenant"
                            ><?= tenantEditEscape(
                                $notes
                            ); ?></textarea>
                        </div>
                    </section>
                <?php endif; ?>

            </div>

            <aside class="tenant-edit-side">

                <?php if ($tenantLogoColumn !== ''): ?>
                    <section class="tenant-edit-card">
                        <div class="tenant-edit-card-header">
                            <span class="tenant-edit-card-icon">
                                <i class="bi bi-image"></i>
                            </span>

                            <div>
                                <h3 class="tenant-edit-card-title">
                                    Tenant Logo
                                </h3>

                                <div class="tenant-edit-card-subtitle">
                                    Replace or remove the current logo
                                </div>
                            </div>
                        </div>

                        <div class="tenant-edit-card-body text-center">
                            <div
                                class="tenant-edit-logo-preview"
                                id="logoPreview"
                            >
                                <?php if (
                                    !empty(
                                        $currentTenant['logo_path']
                                    )
                                ): ?>
                                    <img
                                        src="../<?= tenantEditEscape(
                                            ltrim(
                                                $currentTenant[
                                                    'logo_path'
                                                ],
                                                '/'
                                            )
                                        ); ?>"
                                        alt=""
                                    >
                                <?php else: ?>
                                    <?= tenantEditEscape(
                                        strtoupper(
                                            substr(
                                                $tenantName,
                                                0,
                                                2
                                            )
                                        )
                                    ); ?>
                                <?php endif; ?>
                            </div>

                            <input
                                type="file"
                                name="logo"
                                id="logo"
                                class="form-control tenant-edit-control"
                                accept=".jpg,.jpeg,.png,.webp,.svg,image/jpeg,image/png,image/webp,image/svg+xml"
                            >

                            <?php if (
                                !empty(
                                    $currentTenant['logo_path']
                                )
                            ): ?>
                                <label
                                    class="mt-3"
                                    style="font-size:9px;color:#6b7280;"
                                >
                                    <input
                                        type="checkbox"
                                        name="remove_logo"
                                        value="1"
                                    >
                                    Remove current logo
                                </label>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="tenant-edit-card">
                    <div class="tenant-edit-card-header">
                        <span class="tenant-edit-card-icon">
                            <i class="bi bi-sliders"></i>
                        </span>

                        <div>
                            <h3 class="tenant-edit-card-title">
                                Tenant Settings
                            </h3>

                            <div class="tenant-edit-card-subtitle">
                                Status, trial, timezone and currency
                            </div>
                        </div>
                    </div>

                    <div class="tenant-edit-card-body">
                        <div class="row g-3">

                            <?php if (
                                $tenantStatusColumn !== ''
                            ): ?>
                                <div class="col-12">
                                    <label
                                        class="tenant-edit-label"
                                        for="status"
                                    >
                                        Status
                                    </label>

                                    <select
                                        class="form-select tenant-edit-control"
                                        id="status"
                                        name="status"
                                    >
                                        <?php foreach (
                                            $statusOptions as $statusOption
                                        ): ?>
                                            <option
                                                value="<?= tenantEditEscape(
                                                    $statusOption
                                                ); ?>"
                                                <?= $status === $statusOption
                                                    ? 'selected'
                                                    : ''; ?>
                                            >
                                                <?= tenantEditEscape(
                                                    ucwords(
                                                        str_replace(
                                                            array(
                                                                '_',
                                                                '-'
                                                            ),
                                                            ' ',
                                                            $statusOption
                                                        )
                                                    )
                                                ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <?php if (
                                $tenantTrialEndsColumn !== ''
                            ): ?>
                                <div class="col-12">
                                    <label
                                        class="tenant-edit-label"
                                        for="trialEndsAt"
                                    >
                                        Trial Ends On
                                    </label>

                                    <input
                                        type="date"
                                        class="form-control tenant-edit-control"
                                        id="trialEndsAt"
                                        name="trial_ends_at"
                                        value="<?= tenantEditEscape(
                                            $trialEndsAt
                                        ); ?>"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if (
                                $tenantTimezoneColumn !== ''
                            ): ?>
                                <div class="col-12">
                                    <label
                                        class="tenant-edit-label"
                                        for="timezone"
                                    >
                                        Timezone
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-edit-control"
                                        id="timezone"
                                        name="timezone"
                                        value="<?= tenantEditEscape(
                                            $timezone
                                        ); ?>"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if (
                                $tenantCurrencyColumn !== ''
                            ): ?>
                                <div class="col-12">
                                    <label
                                        class="tenant-edit-label"
                                        for="currency"
                                    >
                                        Currency
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-edit-control"
                                        id="currency"
                                        name="currency"
                                        value="<?= tenantEditEscape(
                                            $currency
                                        ); ?>"
                                        maxlength="10"
                                    >
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </section>

                <div class="tenant-edit-submit-card">
                    <button
                        type="submit"
                        class="tenant-edit-submit"
                    >
                        <i class="bi bi-check2-circle"></i>
                        Update Tenant
                    </button>

                    <a
                        href="tenant-view.php?id=<?= (int) $tenantId; ?>"
                        class="tenant-edit-cancel"
                    >
                        Cancel
                    </a>
                </div>

            </aside>

        </div>
    </form>
</div>

<script>
(function () {
    'use strict';

    const tenantName =
        document.getElementById('tenantName');

    const tenantCode =
        document.getElementById('tenantCode');

    const tenantSlug =
        document.getElementById('tenantSlug');

    let codeEdited =
        tenantCode &&
        tenantCode.value.trim() !== '';

    let slugEdited =
        tenantSlug &&
        tenantSlug.value.trim() !== '';

    function makeCode(value) {
        return value
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    if (tenantCode) {
        tenantCode.addEventListener(
            'input',
            function () {
                codeEdited =
                    tenantCode.value.trim() !== '';
            }
        );
    }

    if (tenantSlug) {
        tenantSlug.addEventListener(
            'input',
            function () {
                slugEdited =
                    tenantSlug.value.trim() !== '';
            }
        );
    }

    if (tenantName) {
        tenantName.addEventListener(
            'input',
            function () {
                const generated =
                    makeCode(tenantName.value);

                if (
                    tenantCode &&
                    !codeEdited
                ) {
                    tenantCode.value = generated;
                }

                if (
                    tenantSlug &&
                    !slugEdited
                ) {
                    tenantSlug.value = generated;
                }
            }
        );
    }

    const logoInput =
        document.getElementById('logo');

    const logoPreview =
        document.getElementById(
            'logoPreview'
        );

    if (
        logoInput &&
        logoPreview
    ) {
        logoInput.addEventListener(
            'change',
            function () {
                const file =
                    logoInput.files[0];

                if (!file) {
                    return;
                }

                const reader =
                    new FileReader();

                reader.onload =
                    function (event) {
                        logoPreview.innerHTML = '';

                        const image =
                            document.createElement(
                                'img'
                            );

                        image.src =
                            event.target.result;
                        image.alt = '';

                        logoPreview.appendChild(
                            image
                        );
                    };

                reader.readAsDataURL(file);
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
