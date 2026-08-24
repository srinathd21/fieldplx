<?php
/**
 * FieldPlx Platform - Add Tenant
 *
 * File:
 * platform/tenant-add.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 * - platform_users authentication
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

@set_time_limit(30);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

requirePlatformRole(array(
    'super_admin',
    'platform_admin'
));

$pageTitle = 'Add Tenant - FieldPlx';
$activePage = 'tenant-add';
$basePath = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('tenantAddEscape')) {
    function tenantAddEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('tenantAddPost')) {
    function tenantAddPost($key, $default = '')
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

if (!function_exists('tenantAddTableExists')) {
    function tenantAddTableExists(mysqli $conn, $tableName)
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $tableName);

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        return !empty($row['total']);
    }
}

if (!function_exists('tenantAddColumnExists')) {
    function tenantAddColumnExists(
        mysqli $conn,
        $tableName,
        $columnName
    ) {
        static $columnCache = array();

        $tableName = trim((string) $tableName);
        $columnName = trim((string) $columnName);

        if ($tableName === '' || $columnName === '') {
            return false;
        }

        if (!isset($columnCache[$tableName])) {
            $columnCache[$tableName] = array();

            /*
             * Load the complete table structure only once.
             * The previous code queried information_schema separately
             * for every possible field, which made the page very slow.
             */
            $safeTableName = str_replace('`', '``', $tableName);
            $result = $conn->query(
                "SHOW COLUMNS FROM `{$safeTableName}`"
            );

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    if (!empty($row['Field'])) {
                        $columnCache[$tableName][
                            (string) $row['Field']
                        ] = true;
                    }
                }

                $result->free();
            }
        }

        return isset(
            $columnCache[$tableName][$columnName]
        );
    }
}

if (!function_exists('tenantAddFirstColumn')) {
    function tenantAddFirstColumn(
        mysqli $conn,
        $tableName,
        array $candidates
    ) {
        foreach ($candidates as $candidate) {
            if (
                tenantAddColumnExists(
                    $conn,
                    $tableName,
                    $candidate
                )
            ) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('tenantAddColumnDetails')) {
    function tenantAddColumnDetails(
        mysqli $conn,
        $tableName
    ) {
        $columns = array();

        $stmt = $conn->prepare("
            SELECT
                column_name,
                data_type,
                column_type,
                is_nullable,
                column_default,
                extra
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
            ORDER BY ordinal_position
        ");

        if (!$stmt) {
            return $columns;
        }

        $stmt->bind_param('s', $tableName);

        if (!$stmt->execute()) {
            $stmt->close();
            return $columns;
        }

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $columns[$row['column_name']] = $row;
        }

        $stmt->close();

        return $columns;
    }
}

if (!function_exists('tenantAddSlug')) {
    function tenantAddSlug($value)
    {
        $value = strtolower(trim((string) $value));

        $value = preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $value
        );

        $value = trim($value, '-');

        return $value;
    }
}

if (!function_exists('tenantAddCodeBase')) {
    function tenantAddCodeBase($name)
    {
        $name = strtoupper(
            preg_replace(
                '/[^A-Za-z0-9]/',
                '',
                (string) $name
            )
        );

        if ($name === '') {
            $name = 'TENANT';
        }

        return substr($name, 0, 8);
    }
}

if (!function_exists('tenantAddGenerateCode')) {
    function tenantAddGenerateCode(
        mysqli $conn,
        $codeColumn,
        $tenantName
    ) {
        $base = tenantAddCodeBase($tenantName);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $suffix = date('ymd') .
                strtoupper(
                    substr(
                        bin2hex(random_bytes(3)),
                        0,
                        5
                    )
                );

            $code = $base . '-' . $suffix;

            $sql = "
                SELECT COUNT(*) AS total
                FROM tenants
                WHERE `{$codeColumn}` = ?
            ";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                return $code;
            }

            $stmt->bind_param('s', $code);
            $stmt->execute();

            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            $stmt->close();

            if (empty($row['total'])) {
                return $code;
            }
        }

        return $base . '-' . time();
    }
}

if (!function_exists('tenantAddBindParams')) {
    function tenantAddBindParams(
        mysqli_stmt $stmt,
        $types,
        array &$values
    ) {
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

if (!function_exists('tenantAddNormaliseDate')) {
    function tenantAddNormaliseDate($date)
    {
        $date = trim((string) $date);

        if ($date === '') {
            return null;
        }

        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }
}

if (!function_exists('tenantAddNormaliseDateTime')) {
    function tenantAddNormaliseDateTime($date)
    {
        $date = trim((string) $date);

        if ($date === '') {
            return null;
        }

        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d 23:59:59', $timestamp);
    }
}

if (!function_exists('tenantAddUploadLogo')) {
    function tenantAddUploadLogo($fieldName)
    {
        if (
            empty($_FILES[$fieldName]) ||
            !isset($_FILES[$fieldName]['error']) ||
            (int) $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE
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

        if ((int) $file['size'] > 2 * 1024 * 1024) {
            return array(
                'success' => false,
                'message' => 'Tenant logo must not exceed 2 MB.'
            );
        }

        $allowedMimeTypes = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        );

        $mimeType = '';

        if (function_exists('finfo_open')) {
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($fileInfo) {
                $mimeType = finfo_file(
                    $fileInfo,
                    $file['tmp_name']
                );

                finfo_close($fileInfo);
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

        if (!isset($allowedMimeTypes[$mimeType])) {
            return array(
                'success' => false,
                'message' => 'Upload a JPG, PNG, or WEBP logo.'
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
                'message' => 'Unable to create the logo upload directory.'
            );
        }

        $extension = $allowedMimeTypes[$mimeType];

        $fileName =
            'tenant-' .
            date('YmdHis') .
            '-' .
            bin2hex(random_bytes(4)) .
            '.' .
            $extension;

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

if (!tenantAddTableExists($conn, 'tenants')) {
    http_response_code(500);

    exit('The tenants table does not exist.');
}

$tenantColumns = tenantAddColumnDetails(
    $conn,
    'tenants'
);

/*
|--------------------------------------------------------------------------
| Detect tenant columns
|--------------------------------------------------------------------------
*/

$idColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array('id', 'tenant_id')
);

$nameColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'company_name',
        'business_name',
        'tenant_name',
        'name'
    )
);

$codeColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'tenant_code',
        'code',
        'business_code'
    )
);

$slugColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'slug',
        'tenant_slug',
        'subdomain'
    )
);

$emailColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'email',
        'contact_email',
        'billing_email'
    )
);

$phoneColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'phone',
        'mobile',
        'contact_phone',
        'contact_mobile'
    )
);

$alternatePhoneColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'alternate_phone',
        'alternate_mobile',
        'phone2'
    )
);

$contactNameColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'contact_name',
        'owner_name',
        'primary_contact_name'
    )
);

$addressColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'address',
        'address_line1',
        'street_address'
    )
);

$addressLine2Column = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'address_line2',
        'address2'
    )
);

$cityColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array('city')
);

$stateColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array('state', 'state_name')
);

$countryColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array('country', 'country_name')
);

$postalCodeColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'postal_code',
        'pincode',
        'zip_code'
    )
);

$taxNumberColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'tax_number',
        'gst_number',
        'gstin',
        'vat_number'
    )
);

$statusColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array('status')
);

$trialStartColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'trial_starts_at',
        'trial_start_date',
        'trial_start'
    )
);

$trialEndColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'trial_ends_at',
        'trial_end_date',
        'trial_end'
    )
);

$logoColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'logo_path',
        'logo',
        'company_logo'
    )
);

$timezoneColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array('timezone')
);

$currencyColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'currency',
        'currency_code'
    )
);

$notesColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array(
        'notes',
        'description',
        'remarks'
    )
);

$createdByColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array('created_by')
);

$updatedByColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array('updated_by')
);

$createdAtColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array('created_at')
);

$updatedAtColumn = tenantAddFirstColumn(
    $conn,
    'tenants',
    array('updated_at')
);

if ($nameColumn === '') {
    http_response_code(500);

    exit(
        'A tenant name column was not found in the tenants table.'
    );
}

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$errorMessage = '';

$companyName = tenantAddPost('company_name');
$tenantCode = tenantAddPost('tenant_code');
$tenantSlug = tenantAddPost('tenant_slug');

$contactName = tenantAddPost('contact_name');
$email = strtolower(tenantAddPost('email'));
$phone = tenantAddPost('phone');
$alternatePhone = tenantAddPost('alternate_phone');

$address = tenantAddPost('address');
$addressLine2 = tenantAddPost('address_line2');
$city = tenantAddPost('city');
$state = tenantAddPost('state');
$country = tenantAddPost('country', 'India');
$postalCode = tenantAddPost('postal_code');
$taxNumber = strtoupper(tenantAddPost('tax_number'));

$status = strtolower(
    tenantAddPost('status', 'trial')
);

$trialStartDate = tenantAddPost(
    'trial_start_date',
    date('Y-m-d')
);

$trialEndDate = tenantAddPost(
    'trial_end_date',
    date(
        'Y-m-d',
        strtotime('+14 days')
    )
);

$timezone = tenantAddPost(
    'timezone',
    'Asia/Kolkata'
);

$currency = strtoupper(
    tenantAddPost('currency', 'INR')
);

$notes = tenantAddPost('notes');

$allowedStatuses = array(
    'active',
    'trial',
    'inactive',
    'suspended'
);

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'trial';
}

/*
|--------------------------------------------------------------------------
| Create tenant
|--------------------------------------------------------------------------
*/

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    if ($companyName === '') {
        $errorMessage =
            'Enter the tenant or company name.';
    } elseif (strlen($companyName) > 190) {
        $errorMessage =
            'Tenant name must not exceed 190 characters.';
    } elseif (
        $email !== '' &&
        filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        ) === false
    ) {
        $errorMessage =
            'Enter a valid email address.';
    } elseif (strlen($phone) > 50) {
        $errorMessage =
            'Phone number must not exceed 50 characters.';
    } elseif (strlen($postalCode) > 20) {
        $errorMessage =
            'Postal code must not exceed 20 characters.';
    } elseif (
        $status === 'trial' &&
        $trialEndDate === ''
    ) {
        $errorMessage =
            'Select the trial end date.';
    } elseif (
        $status === 'trial' &&
        tenantAddNormaliseDate($trialStartDate) === null
    ) {
        $errorMessage =
            'Select a valid trial start date.';
    } elseif (
        $status === 'trial' &&
        tenantAddNormaliseDate($trialEndDate) === null
    ) {
        $errorMessage =
            'Select a valid trial end date.';
    } elseif (
        $status === 'trial' &&
        strtotime($trialEndDate) <
        strtotime($trialStartDate)
    ) {
        $errorMessage =
            'Trial end date cannot be before the start date.';
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate checks
    |--------------------------------------------------------------------------
    */

    if (
        $errorMessage === '' &&
        $emailColumn !== '' &&
        $email !== ''
    ) {
        $duplicateEmailSql = "
            SELECT COUNT(*) AS total
            FROM tenants
            WHERE LOWER(`{$emailColumn}`) = ?
        ";

        $duplicateEmailStmt = $conn->prepare(
            $duplicateEmailSql
        );

        if ($duplicateEmailStmt) {
            $duplicateEmailStmt->bind_param(
                's',
                $email
            );

            $duplicateEmailStmt->execute();

            $duplicateResult =
                $duplicateEmailStmt->get_result();

            $duplicateRow =
                $duplicateResult->fetch_assoc();

            $duplicateEmailStmt->close();

            if (!empty($duplicateRow['total'])) {
                $errorMessage =
                    'A tenant already exists with this email address.';
            }
        }
    }

    if (
        $errorMessage === '' &&
        $codeColumn !== ''
    ) {
        if ($tenantCode === '') {
            $tenantCode = tenantAddGenerateCode(
                $conn,
                $codeColumn,
                $companyName
            );
        } else {
            $tenantCode = strtoupper(
                preg_replace(
                    '/[^A-Za-z0-9_-]/',
                    '',
                    $tenantCode
                )
            );

            $duplicateCodeSql = "
                SELECT COUNT(*) AS total
                FROM tenants
                WHERE `{$codeColumn}` = ?
            ";

            $duplicateCodeStmt = $conn->prepare(
                $duplicateCodeSql
            );

            if ($duplicateCodeStmt) {
                $duplicateCodeStmt->bind_param(
                    's',
                    $tenantCode
                );

                $duplicateCodeStmt->execute();

                $duplicateResult =
                    $duplicateCodeStmt->get_result();

                $duplicateRow =
                    $duplicateResult->fetch_assoc();

                $duplicateCodeStmt->close();

                if (!empty($duplicateRow['total'])) {
                    $errorMessage =
                        'This tenant code is already in use.';
                }
            }
        }
    }

    if (
        $errorMessage === '' &&
        $slugColumn !== ''
    ) {
        if ($tenantSlug === '') {
            $tenantSlug = tenantAddSlug($companyName);
        } else {
            $tenantSlug = tenantAddSlug($tenantSlug);
        }

        if ($tenantSlug === '') {
            $tenantSlug = 'tenant-' . time();
        }

        $originalSlug = $tenantSlug;
        $slugNumber = 1;

        while (true) {
            $slugSql = "
                SELECT COUNT(*) AS total
                FROM tenants
                WHERE `{$slugColumn}` = ?
            ";

            $slugStmt = $conn->prepare($slugSql);

            if (!$slugStmt) {
                break;
            }

            $slugStmt->bind_param(
                's',
                $tenantSlug
            );

            $slugStmt->execute();

            $slugResult = $slugStmt->get_result();
            $slugRow = $slugResult->fetch_assoc();

            $slugStmt->close();

            if (empty($slugRow['total'])) {
                break;
            }

            $slugNumber++;
            $tenantSlug =
                $originalSlug . '-' . $slugNumber;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Upload logo
    |--------------------------------------------------------------------------
    */

    $uploadedLogoPath = '';

    if (
        $errorMessage === '' &&
        $logoColumn !== ''
    ) {
        $logoUpload = tenantAddUploadLogo(
            'tenant_logo'
        );

        if (!$logoUpload['success']) {
            $errorMessage = $logoUpload['message'];
        } else {
            $uploadedLogoPath =
                $logoUpload['path'];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Insert tenant
    |--------------------------------------------------------------------------
    */

    if ($errorMessage === '') {
        $insertData = array();

        $insertData[$nameColumn] =
            $companyName;

        if ($codeColumn !== '') {
            $insertData[$codeColumn] =
                $tenantCode;
        }

        if ($slugColumn !== '') {
            $insertData[$slugColumn] =
                $tenantSlug;
        }

        if ($contactNameColumn !== '') {
            $insertData[$contactNameColumn] =
                $contactName;
        }

        if ($emailColumn !== '') {
            $insertData[$emailColumn] =
                $email;
        }

        if ($phoneColumn !== '') {
            $insertData[$phoneColumn] =
                $phone;
        }

        if ($alternatePhoneColumn !== '') {
            $insertData[$alternatePhoneColumn] =
                $alternatePhone;
        }

        if ($addressColumn !== '') {
            $insertData[$addressColumn] =
                $address;
        }

        if ($addressLine2Column !== '') {
            $insertData[$addressLine2Column] =
                $addressLine2;
        }

        if ($cityColumn !== '') {
            $insertData[$cityColumn] =
                $city;
        }

        if ($stateColumn !== '') {
            $insertData[$stateColumn] =
                $state;
        }

        if ($countryColumn !== '') {
            $insertData[$countryColumn] =
                $country;
        }

        if ($postalCodeColumn !== '') {
            $insertData[$postalCodeColumn] =
                $postalCode;
        }

        if ($taxNumberColumn !== '') {
            $insertData[$taxNumberColumn] =
                $taxNumber;
        }

        if ($statusColumn !== '') {
            $insertData[$statusColumn] =
                $status;
        }

        if ($trialStartColumn !== '') {
            if ($status === 'trial') {
                $columnType = isset(
                    $tenantColumns[$trialStartColumn]['data_type']
                )
                    ? $tenantColumns[$trialStartColumn]['data_type']
                    : '';

                $insertData[$trialStartColumn] =
                    in_array(
                        $columnType,
                        array('datetime', 'timestamp'),
                        true
                    )
                        ? date(
                            'Y-m-d 00:00:00',
                            strtotime($trialStartDate)
                        )
                        : tenantAddNormaliseDate(
                            $trialStartDate
                        );
            } else {
                $insertData[$trialStartColumn] = null;
            }
        }

        if ($trialEndColumn !== '') {
            if ($status === 'trial') {
                $columnType = isset(
                    $tenantColumns[$trialEndColumn]['data_type']
                )
                    ? $tenantColumns[$trialEndColumn]['data_type']
                    : '';

                $insertData[$trialEndColumn] =
                    in_array(
                        $columnType,
                        array('datetime', 'timestamp'),
                        true
                    )
                        ? tenantAddNormaliseDateTime(
                            $trialEndDate
                        )
                        : tenantAddNormaliseDate(
                            $trialEndDate
                        );
            } else {
                $insertData[$trialEndColumn] = null;
            }
        }

        if ($logoColumn !== '') {
            $insertData[$logoColumn] =
                $uploadedLogoPath;
        }

        if ($timezoneColumn !== '') {
            $insertData[$timezoneColumn] =
                $timezone;
        }

        if ($currencyColumn !== '') {
            $insertData[$currencyColumn] =
                $currency;
        }

        if ($notesColumn !== '') {
            $insertData[$notesColumn] =
                $notes;
        }
        /*
         * Do not write the platform user ID into tenant-owned
         * created_by/updated_by columns. Those columns commonly
         * reference the tenant users table and can cause an FK failure.
         */

        /*
         * NOW() is inserted separately for timestamp fields.
         */
        $columns = array();
        $placeholders = array();
        $values = array();
        $types = '';

        foreach ($insertData as $column => $value) {
            $columns[] = "`{$column}`";

            if ($value === null) {
                $placeholders[] = 'NULL';
                continue;
            }

            $placeholders[] = '?';
            $values[] = $value;

            $columnDataType = isset(
                $tenantColumns[$column]['data_type']
            )
                ? strtolower(
                    $tenantColumns[$column]['data_type']
                )
                : 'varchar';

            if (
                in_array(
                    $columnDataType,
                    array(
                        'tinyint',
                        'smallint',
                        'mediumint',
                        'int',
                        'bigint',
                        'bit',
                        'boolean'
                    ),
                    true
                )
            ) {
                $types .= 'i';
            } elseif (
                in_array(
                    $columnDataType,
                    array(
                        'decimal',
                        'float',
                        'double',
                        'real'
                    ),
                    true
                )
            ) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        if ($createdAtColumn !== '') {
            $columns[] =
                "`{$createdAtColumn}`";

            $placeholders[] = 'NOW()';
        }

        if ($updatedAtColumn !== '') {
            $columns[] =
                "`{$updatedAtColumn}`";

            $placeholders[] = 'NOW()';
        }

        $insertSql = "
            INSERT INTO tenants (
                " . implode(', ', $columns) . "
            ) VALUES (
                " . implode(', ', $placeholders) . "
            )
        ";

        try {
            $conn->begin_transaction();

            $insertStmt = $conn->prepare(
                $insertSql
            );

            if (!$insertStmt) {
                throw new Exception(
                    'Unable to prepare tenant creation: ' .
                    $conn->error
                );
            }

            if ($types !== '') {
                tenantAddBindParams(
                    $insertStmt,
                    $types,
                    $values
                );
            }

            if (!$insertStmt->execute()) {
                throw new Exception(
                    'Unable to create tenant: ' .
                    $insertStmt->error
                );
            }

            $newTenantId =
                (int) $insertStmt->insert_id;

            $insertStmt->close();

            $conn->commit();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Tenant created successfully.';

            header(
                'Location: tenant-view.php?id=' .
                $newTenantId
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
                unlink(
                    __DIR__ .
                    '/../' .
                    $uploadedLogoPath
                );
            }

            error_log(
                'Tenant creation failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                $exception->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load layout
|--------------------------------------------------------------------------
*/

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .tenant-add-page {
        max-width: 1100px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .tenant-add-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .tenant-add-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .tenant-add-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .tenant-back-button {
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
        font-weight: 600;
        text-decoration: none;
    }

    .tenant-back-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .tenant-add-alert {
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

    .tenant-form-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(260px, 320px);
        gap: 15px;
        align-items: start;
    }

    .tenant-form-main,
    .tenant-form-side {
        display: grid;
        gap: 15px;
    }

    .tenant-form-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .tenant-form-card-header {
        min-height: 54px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .tenant-form-card-icon {
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

    .tenant-form-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .tenant-form-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-form-card-body {
        padding: 15px;
    }

    .tenant-form-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .tenant-required {
        color: #dc2626;
    }

    .tenant-form-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    textarea.tenant-form-control {
        min-height: 83px;
        resize: vertical;
    }

    .tenant-form-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .tenant-form-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .tenant-logo-upload {
        min-height: 142px;
        padding: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px dashed #d1d5db;
        border-radius: 10px;
        background: #fafafa;
        text-align: center;
        cursor: pointer;
    }

    .tenant-logo-upload:hover {
        border-color: #a78bfa;
        background: #faf8ff;
    }

    .tenant-logo-preview {
        width: 68px;
        height: 68px;
        margin: 0 auto 9px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 14px;
        background:
            linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
        color: #ffffff;
        font-size: 19px;
        font-weight: 800;
    }

    .tenant-logo-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .tenant-logo-title {
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .tenant-logo-text {
        margin-top: 3px;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .tenant-submit-button {
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

    .tenant-submit-button:hover {
        background:
            linear-gradient(
                135deg,
                #6d28d9,
                #5b21b6
            );
    }

    .tenant-submit-button:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }

    .tenant-cancel-button {
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

    .tenant-cancel-button:hover {
        border-color: #d1d5db;
        color: #111827;
    }

    .tenant-trial-fields.hidden {
        display: none;
    }

    @media (max-width: 900px) {
        .tenant-form-layout {
            grid-template-columns: 1fr;
        }

        .tenant-form-side {
            order: -1;
        }
    }

    @media (max-width: 600px) {
        .tenant-add-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .tenant-back-button {
            width: 100%;
        }
    }
</style>

<div class="tenant-add-page">

    <div class="tenant-add-heading">
        <div>
            <h2 class="tenant-add-title">
                Add Tenant
            </h2>

            <div class="tenant-add-description">
                Create a new FieldPlx tenant workspace.
            </div>
        </div>

        <a
            href="tenants.php"
            class="tenant-back-button"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Tenants
        </a>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="tenant-add-alert">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= tenantAddEscape($errorMessage); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        enctype="multipart/form-data"
        id="tenantAddForm"
    >
        <?php csrfField(); ?>

        <div class="tenant-form-layout">

            <div class="tenant-form-main">

                <section class="tenant-form-card">
                    <div class="tenant-form-card-header">
                        <span class="tenant-form-card-icon">
                            <i class="bi bi-building"></i>
                        </span>

                        <div>
                            <h3 class="tenant-form-card-title">
                                Business Information
                            </h3>

                            <div class="tenant-form-card-subtitle">
                                Main tenant and workspace details
                            </div>
                        </div>
                    </div>

                    <div class="tenant-form-card-body">
                        <div class="row g-3">

                            <div class="col-md-8">
                                <label
                                    for="companyName"
                                    class="tenant-form-label"
                                >
                                    Tenant / Company Name
                                    <span class="tenant-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    class="form-control tenant-form-control"
                                    id="companyName"
                                    name="company_name"
                                    value="<?= tenantAddEscape(
                                        $companyName
                                    ); ?>"
                                    maxlength="190"
                                    required
                                >
                            </div>

                            <?php if ($codeColumn !== ''): ?>
                                <div class="col-md-4">
                                    <label
                                        for="tenantCode"
                                        class="tenant-form-label"
                                    >
                                        Tenant Code
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-form-control text-uppercase"
                                        id="tenantCode"
                                        name="tenant_code"
                                        value="<?= tenantAddEscape(
                                            $tenantCode
                                        ); ?>"
                                        maxlength="50"
                                        placeholder="Auto generated"
                                    >

                                    <div class="tenant-form-help">
                                        Leave empty to generate automatically.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($slugColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        for="tenantSlug"
                                        class="tenant-form-label"
                                    >
                                        Workspace Slug
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-form-control"
                                        id="tenantSlug"
                                        name="tenant_slug"
                                        value="<?= tenantAddEscape(
                                            $tenantSlug
                                        ); ?>"
                                        maxlength="150"
                                        placeholder="Auto generated"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($taxNumberColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        for="taxNumber"
                                        class="tenant-form-label"
                                    >
                                        Tax / GST Number
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-form-control text-uppercase"
                                        id="taxNumber"
                                        name="tax_number"
                                        value="<?= tenantAddEscape(
                                            $taxNumber
                                        ); ?>"
                                        maxlength="50"
                                    >
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </section>

                <section class="tenant-form-card">
                    <div class="tenant-form-card-header">
                        <span class="tenant-form-card-icon">
                            <i class="bi bi-person-lines-fill"></i>
                        </span>

                        <div>
                            <h3 class="tenant-form-card-title">
                                Contact Information
                            </h3>

                            <div class="tenant-form-card-subtitle">
                                Primary tenant contact details
                            </div>
                        </div>
                    </div>

                    <div class="tenant-form-card-body">
                        <div class="row g-3">

                            <?php if ($contactNameColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        for="contactName"
                                        class="tenant-form-label"
                                    >
                                        Contact Person
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-form-control"
                                        id="contactName"
                                        name="contact_name"
                                        value="<?= tenantAddEscape(
                                            $contactName
                                        ); ?>"
                                        maxlength="150"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($emailColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        for="email"
                                        class="tenant-form-label"
                                    >
                                        Email Address
                                    </label>

                                    <input
                                        type="email"
                                        class="form-control tenant-form-control"
                                        id="email"
                                        name="email"
                                        value="<?= tenantAddEscape(
                                            $email
                                        ); ?>"
                                        maxlength="190"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($phoneColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        for="phone"
                                        class="tenant-form-label"
                                    >
                                        Phone Number
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-form-control"
                                        id="phone"
                                        name="phone"
                                        value="<?= tenantAddEscape(
                                            $phone
                                        ); ?>"
                                        maxlength="50"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($alternatePhoneColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        for="alternatePhone"
                                        class="tenant-form-label"
                                    >
                                        Alternate Phone
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-form-control"
                                        id="alternatePhone"
                                        name="alternate_phone"
                                        value="<?= tenantAddEscape(
                                            $alternatePhone
                                        ); ?>"
                                        maxlength="50"
                                    >
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </section>

                <?php if (
                    $addressColumn !== '' ||
                    $cityColumn !== '' ||
                    $stateColumn !== '' ||
                    $countryColumn !== ''
                ): ?>
                    <section class="tenant-form-card">
                        <div class="tenant-form-card-header">
                            <span class="tenant-form-card-icon">
                                <i class="bi bi-geo-alt"></i>
                            </span>

                            <div>
                                <h3 class="tenant-form-card-title">
                                    Address
                                </h3>

                                <div class="tenant-form-card-subtitle">
                                    Registered business location
                                </div>
                            </div>
                        </div>

                        <div class="tenant-form-card-body">
                            <div class="row g-3">

                                <?php if ($addressColumn !== ''): ?>
                                    <div class="col-12">
                                        <label
                                            for="address"
                                            class="tenant-form-label"
                                        >
                                            Address
                                        </label>

                                        <textarea
                                            class="form-control tenant-form-control"
                                            id="address"
                                            name="address"
                                        ><?= tenantAddEscape(
                                            $address
                                        ); ?></textarea>
                                    </div>
                                <?php endif; ?>

                                <?php if ($addressLine2Column !== ''): ?>
                                    <div class="col-12">
                                        <label
                                            for="addressLine2"
                                            class="tenant-form-label"
                                        >
                                            Address Line 2
                                        </label>

                                        <input
                                            type="text"
                                            class="form-control tenant-form-control"
                                            id="addressLine2"
                                            name="address_line2"
                                            value="<?= tenantAddEscape(
                                                $addressLine2
                                            ); ?>"
                                        >
                                    </div>
                                <?php endif; ?>

                                <?php if ($cityColumn !== ''): ?>
                                    <div class="col-md-6">
                                        <label
                                            for="city"
                                            class="tenant-form-label"
                                        >
                                            City
                                        </label>

                                        <input
                                            type="text"
                                            class="form-control tenant-form-control"
                                            id="city"
                                            name="city"
                                            value="<?= tenantAddEscape(
                                                $city
                                            ); ?>"
                                        >
                                    </div>
                                <?php endif; ?>

                                <?php if ($stateColumn !== ''): ?>
                                    <div class="col-md-6">
                                        <label
                                            for="state"
                                            class="tenant-form-label"
                                        >
                                            State
                                        </label>

                                        <input
                                            type="text"
                                            class="form-control tenant-form-control"
                                            id="state"
                                            name="state"
                                            value="<?= tenantAddEscape(
                                                $state
                                            ); ?>"
                                        >
                                    </div>
                                <?php endif; ?>

                                <?php if ($countryColumn !== ''): ?>
                                    <div class="col-md-6">
                                        <label
                                            for="country"
                                            class="tenant-form-label"
                                        >
                                            Country
                                        </label>

                                        <input
                                            type="text"
                                            class="form-control tenant-form-control"
                                            id="country"
                                            name="country"
                                            value="<?= tenantAddEscape(
                                                $country
                                            ); ?>"
                                        >
                                    </div>
                                <?php endif; ?>

                                <?php if ($postalCodeColumn !== ''): ?>
                                    <div class="col-md-6">
                                        <label
                                            for="postalCode"
                                            class="tenant-form-label"
                                        >
                                            Postal Code
                                        </label>

                                        <input
                                            type="text"
                                            class="form-control tenant-form-control"
                                            id="postalCode"
                                            name="postal_code"
                                            value="<?= tenantAddEscape(
                                                $postalCode
                                            ); ?>"
                                            maxlength="20"
                                        >
                                    </div>
                                <?php endif; ?>

                            </div>
                        </div>
                    </section>
                <?php endif; ?>

            </div>

            <aside class="tenant-form-side">

                <?php if ($logoColumn !== ''): ?>
                    <section class="tenant-form-card">
                        <div class="tenant-form-card-header">
                            <span class="tenant-form-card-icon">
                                <i class="bi bi-image"></i>
                            </span>

                            <div>
                                <h3 class="tenant-form-card-title">
                                    Tenant Logo
                                </h3>

                                <div class="tenant-form-card-subtitle">
                                    JPG, PNG, or WEBP up to 2 MB
                                </div>
                            </div>
                        </div>

                        <div class="tenant-form-card-body">
                            <label
                                for="tenantLogo"
                                class="tenant-logo-upload"
                            >
                                <div>
                                    <div
                                        class="tenant-logo-preview"
                                        id="tenantLogoPreview"
                                    >
                                        <span id="tenantLogoInitials">
                                            TN
                                        </span>
                                    </div>

                                    <div class="tenant-logo-title">
                                        Choose Tenant Logo
                                    </div>

                                    <div class="tenant-logo-text">
                                        Click to select an image
                                    </div>
                                </div>
                            </label>

                            <input
                                type="file"
                                id="tenantLogo"
                                name="tenant_logo"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                class="d-none"
                            >
                        </div>
                    </section>
                <?php endif; ?>

                <section class="tenant-form-card">
                    <div class="tenant-form-card-header">
                        <span class="tenant-form-card-icon">
                            <i class="bi bi-sliders"></i>
                        </span>

                        <div>
                            <h3 class="tenant-form-card-title">
                                Workspace Settings
                            </h3>

                            <div class="tenant-form-card-subtitle">
                                Status and regional preferences
                            </div>
                        </div>
                    </div>

                    <div class="tenant-form-card-body">
                        <div class="row g-3">

                            <?php if ($statusColumn !== ''): ?>
                                <div class="col-12">
                                    <label
                                        for="tenantStatus"
                                        class="tenant-form-label"
                                    >
                                        Status
                                    </label>

                                    <select
                                        class="form-select tenant-form-control"
                                        id="tenantStatus"
                                        name="status"
                                    >
                                        <option
                                            value="trial"
                                            <?= $status === 'trial'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            Trial
                                        </option>

                                        <option
                                            value="active"
                                            <?= $status === 'active'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            Active
                                        </option>

                                        <option
                                            value="inactive"
                                            <?= $status === 'inactive'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            Inactive
                                        </option>

                                        <option
                                            value="suspended"
                                            <?= $status === 'suspended'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            Suspended
                                        </option>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <?php if (
                                $trialStartColumn !== '' ||
                                $trialEndColumn !== ''
                            ): ?>
                                <div
                                    class="col-12 tenant-trial-fields <?= $status !== 'trial'
                                        ? 'hidden'
                                        : ''; ?>"
                                    id="tenantTrialFields"
                                >
                                    <div class="row g-3">

                                        <?php if (
                                            $trialStartColumn !== ''
                                        ): ?>
                                            <div class="col-12">
                                                <label
                                                    for="trialStartDate"
                                                    class="tenant-form-label"
                                                >
                                                    Trial Start
                                                </label>

                                                <input
                                                    type="date"
                                                    class="form-control tenant-form-control"
                                                    id="trialStartDate"
                                                    name="trial_start_date"
                                                    value="<?= tenantAddEscape(
                                                        $trialStartDate
                                                    ); ?>"
                                                >
                                            </div>
                                        <?php endif; ?>

                                        <?php if (
                                            $trialEndColumn !== ''
                                        ): ?>
                                            <div class="col-12">
                                                <label
                                                    for="trialEndDate"
                                                    class="tenant-form-label"
                                                >
                                                    Trial End
                                                </label>

                                                <input
                                                    type="date"
                                                    class="form-control tenant-form-control"
                                                    id="trialEndDate"
                                                    name="trial_end_date"
                                                    value="<?= tenantAddEscape(
                                                        $trialEndDate
                                                    ); ?>"
                                                >
                                            </div>
                                        <?php endif; ?>

                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($timezoneColumn !== ''): ?>
                                <div class="col-12">
                                    <label
                                        for="timezone"
                                        class="tenant-form-label"
                                    >
                                        Timezone
                                    </label>

                                    <select
                                        class="form-select tenant-form-control"
                                        id="timezone"
                                        name="timezone"
                                    >
                                        <option
                                            value="Asia/Kolkata"
                                            <?= $timezone === 'Asia/Kolkata'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            Asia/Kolkata
                                        </option>

                                        <option
                                            value="UTC"
                                            <?= $timezone === 'UTC'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            UTC
                                        </option>

                                        <option
                                            value="Asia/Dubai"
                                            <?= $timezone === 'Asia/Dubai'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            Asia/Dubai
                                        </option>

                                        <option
                                            value="Europe/London"
                                            <?= $timezone === 'Europe/London'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            Europe/London
                                        </option>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <?php if ($currencyColumn !== ''): ?>
                                <div class="col-12">
                                    <label
                                        for="currency"
                                        class="tenant-form-label"
                                    >
                                        Currency
                                    </label>

                                    <select
                                        class="form-select tenant-form-control"
                                        id="currency"
                                        name="currency"
                                    >
                                        <option
                                            value="INR"
                                            <?= $currency === 'INR'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            INR - Indian Rupee
                                        </option>

                                        <option
                                            value="GBP"
                                            <?= $currency === 'GBP'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            GBP - Pound Sterling
                                        </option>

                                        <option
                                            value="USD"
                                            <?= $currency === 'USD'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            USD - US Dollar
                                        </option>

                                        <option
                                            value="AED"
                                            <?= $currency === 'AED'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            AED - UAE Dirham
                                        </option>
                                    </select>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </section>

                <?php if ($notesColumn !== ''): ?>
                    <section class="tenant-form-card">
                        <div class="tenant-form-card-header">
                            <span class="tenant-form-card-icon">
                                <i class="bi bi-journal-text"></i>
                            </span>

                            <div>
                                <h3 class="tenant-form-card-title">
                                    Internal Notes
                                </h3>

                                <div class="tenant-form-card-subtitle">
                                    Visible to platform administrators
                                </div>
                            </div>
                        </div>

                        <div class="tenant-form-card-body">
                            <textarea
                                class="form-control tenant-form-control"
                                name="notes"
                                rows="4"
                                placeholder="Optional notes about this tenant"
                            ><?= tenantAddEscape($notes); ?></textarea>
                        </div>
                    </section>
                <?php endif; ?>

                <div class="tenant-submit-card">
                    <button
                        type="submit"
                        class="tenant-submit-button"
                        id="tenantSubmitButton"
                    >
                        <i class="bi bi-building-add"></i>
                        Create Tenant
                    </button>

                    <a
                        href="tenants.php"
                        class="tenant-cancel-button"
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

    const form = document.getElementById(
        'tenantAddForm'
    );

    const companyName = document.getElementById(
        'companyName'
    );

    const slugInput = document.getElementById(
        'tenantSlug'
    );

    const statusInput = document.getElementById(
        'tenantStatus'
    );

    const trialFields = document.getElementById(
        'tenantTrialFields'
    );

    const logoInput = document.getElementById(
        'tenantLogo'
    );

    const logoPreview = document.getElementById(
        'tenantLogoPreview'
    );

    const logoInitials = document.getElementById(
        'tenantLogoInitials'
    );

    const submitButton = document.getElementById(
        'tenantSubmitButton'
    );

    let slugEdited = false;

    function makeSlug(value) {
        return value
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function makeInitials(value) {
        const parts = value
            .trim()
            .split(/\s+/)
            .filter(Boolean);

        if (!parts.length) {
            return 'TN';
        }

        let result = parts[0].charAt(0);

        if (parts.length > 1) {
            result += parts[
                parts.length - 1
            ].charAt(0);
        }

        return result.toUpperCase();
    }

    if (slugInput) {
        slugInput.addEventListener(
            'input',
            function () {
                slugEdited =
                    slugInput.value.trim() !== '';
            }
        );
    }

    if (companyName) {
        companyName.addEventListener(
            'input',
            function () {
                if (slugInput && !slugEdited) {
                    slugInput.value = makeSlug(
                        companyName.value
                    );
                }

                if (logoInitials) {
                    logoInitials.textContent =
                        makeInitials(
                            companyName.value
                        );
                }
            }
        );
    }

    function updateTrialFields() {
        if (!statusInput || !trialFields) {
            return;
        }

        trialFields.classList.toggle(
            'hidden',
            statusInput.value !== 'trial'
        );
    }

    if (statusInput) {
        statusInput.addEventListener(
            'change',
            updateTrialFields
        );

        updateTrialFields();
    }

    if (
        logoInput &&
        logoPreview
    ) {
        logoInput.addEventListener(
            'change',
            function () {
                const file = logoInput.files[0];

                if (!file) {
                    return;
                }

                if (!file.type.match(/^image\//)) {
                    alert(
                        'Select a valid image file.'
                    );

                    logoInput.value = '';
                    return;
                }

                if (file.size > 2 * 1024 * 1024) {
                    alert(
                        'Tenant logo must not exceed 2 MB.'
                    );

                    logoInput.value = '';
                    return;
                }

                const reader = new FileReader();

                reader.onload = function (event) {
                    logoPreview.innerHTML = '';

                    const image =
                        document.createElement('img');

                    image.src = event.target.result;
                    image.alt = 'Tenant logo preview';

                    logoPreview.appendChild(image);
                };

                reader.readAsDataURL(file);
            }
        );
    }

    if (form && submitButton) {
        form.addEventListener(
            'submit',
            function (event) {
                if (!form.checkValidity()) {
                    return;
                }

                if (
                    form.dataset.submitting === '1'
                ) {
                    event.preventDefault();
                    return;
                }

                form.dataset.submitting = '1';
                submitButton.disabled = true;

                submitButton.innerHTML =
                    '<span class="spinner-border ' +
                    'spinner-border-sm" ' +
                    'aria-hidden="true"></span>' +
                    '<span>Creating tenant...</span>';
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>