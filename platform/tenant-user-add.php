<?php
/**
 * FieldPlx Platform - Add Tenant User
 *
 * File:
 * platform/tenant-user-add.php
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

$pageTitle = 'Add Tenant User - FieldPlx';
$activePage = 'tenant-users';
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

if (!function_exists('tenantUserAddEscape')) {
    function tenantUserAddEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('tenantUserAddPost')) {
    function tenantUserAddPost($key, $default = '')
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

if (!function_exists('tenantUserAddTableExists')) {
    function tenantUserAddTableExists(
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

        if (!$stmt->execute()) {
            $stmt->close();
            $cache[$tableName] = false;
            return false;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        $cache[$tableName] = !empty($row['total']);

        return $cache[$tableName];
    }
}

if (!function_exists('tenantUserAddColumns')) {
    function tenantUserAddColumns(
        mysqli $conn,
        $tableName
    ) {
        static $cache = array();

        $tableName = trim((string) $tableName);

        if ($tableName === '') {
            return array();
        }

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $cache[$tableName] = array();

        $safeTableName = str_replace('`', '``', $tableName);

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$safeTableName}`"
        );

        if (!$result) {
            return $cache[$tableName];
        }

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

if (!function_exists('tenantUserAddFirstColumn')) {
    function tenantUserAddFirstColumn(
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

if (!function_exists('tenantUserAddBind')) {
    function tenantUserAddBind(
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

if (!function_exists('tenantUserAddPasswordError')) {
    function tenantUserAddPasswordError($password)
    {
        if (strlen($password) < 8) {
            return 'Password must contain at least 8 characters.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return 'Password must contain at least one uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            return 'Password must contain at least one lowercase letter.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one number.';
        }

        return '';
    }
}

if (!function_exists('tenantUserAddRoleCode')) {
    function tenantUserAddRoleCode($value)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace(
            '/[^a-z0-9]+/',
            '_',
            $value
        );

        return trim($value, '_');
    }
}

if (!function_exists('tenantUserAddUniqueValueExists')) {
    function tenantUserAddUniqueValueExists(
        mysqli $conn,
        $column,
        $value
    ) {
        if ($column === '' || $value === '') {
            return false;
        }

        $safeColumn = str_replace('`', '``', $column);

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM users
            WHERE LOWER(`{$safeColumn}`) = LOWER(?)
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $value);

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

if (!function_exists('tenantUserAddEnumValues')) {
    function tenantUserAddEnumValues($columnType)
    {
        $values = array();
        $columnType = trim((string) $columnType);

        if (
            stripos($columnType, 'enum(') !== 0
        ) {
            return $values;
        }

        preg_match_all(
            "/'((?:[^'\\\\]|\\\\.)*)'/",
            $columnType,
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

if (!function_exists('tenantUserAddUploadAvatar')) {
    function tenantUserAddUploadAvatar($fieldName)
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
                'message' => 'Unable to upload the profile image.'
            );
        }

        if ((int) $file['size'] > 2 * 1024 * 1024) {
            return array(
                'success' => false,
                'message' => 'Profile image must not exceed 2 MB.'
            );
        }

        $allowedTypes = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
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
                'message' => 'Upload a JPG, PNG, or WEBP profile image.'
            );
        }

        $uploadDirectory =
            __DIR__ . '/../uploads/users/avatars';

        if (
            !is_dir($uploadDirectory) &&
            !mkdir($uploadDirectory, 0755, true)
        ) {
            return array(
                'success' => false,
                'message' => 'Unable to create the profile upload directory.'
            );
        }

        try {
            $randomPart = bin2hex(random_bytes(4));
        } catch (Exception $exception) {
            $randomPart = uniqid();
        }

        $fileName =
            'user-' .
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
                'message' => 'Unable to save the profile image.'
            );
        }

        return array(
            'success' => true,
            'path' => 'uploads/users/avatars/' . $fileName
        );
    }
}

/*
|--------------------------------------------------------------------------
| Verify tables
|--------------------------------------------------------------------------
*/

if (!tenantUserAddTableExists($conn, 'users')) {
    http_response_code(500);
    exit('The users table does not exist.');
}

if (!tenantUserAddTableExists($conn, 'tenants')) {
    http_response_code(500);
    exit('The tenants table does not exist.');
}

$userColumns = tenantUserAddColumns(
    $conn,
    'users'
);

$tenantColumns = tenantUserAddColumns(
    $conn,
    'tenants'
);

/*
|--------------------------------------------------------------------------
| Detect users columns
|--------------------------------------------------------------------------
*/

$userIdColumn = tenantUserAddFirstColumn(
    $userColumns,
    array('id', 'user_id')
);

$userTenantColumn = tenantUserAddFirstColumn(
    $userColumns,
    array('tenant_id')
);

$userFirstNameColumn = tenantUserAddFirstColumn(
    $userColumns,
    array('first_name', 'firstname', 'given_name')
);

$userLastNameColumn = tenantUserAddFirstColumn(
    $userColumns,
    array('last_name', 'lastname', 'surname')
);

$userNameColumn = tenantUserAddFirstColumn(
    $userColumns,
    array('name', 'full_name', 'display_name')
);

$userEmailColumn = tenantUserAddFirstColumn(
    $userColumns,
    array('email', 'email_address')
);

$userPhoneColumn = tenantUserAddFirstColumn(
    $userColumns,
    array('phone', 'mobile', 'phone_number')
);

$userUsernameColumn = tenantUserAddFirstColumn(
    $userColumns,
    array('username', 'user_name')
);

$userPasswordColumn = tenantUserAddFirstColumn(
    $userColumns,
    array(
        'password_hash',
        'password',
        'passwd'
    )
);

$userStatusColumn = tenantUserAddFirstColumn(
    $userColumns,
    array('status', 'account_status')
);

$userRoleIdColumn = tenantUserAddFirstColumn(
    $userColumns,
    array('role_id')
);

$userRoleCodeColumn = tenantUserAddFirstColumn(
    $userColumns,
    array('role_code', 'user_role', 'role')
);

$userAvatarColumn = tenantUserAddFirstColumn(
    $userColumns,
    array(
        'avatar_path',
        'profile_photo',
        'photo_path',
        'image'
    )
);

$userJobTitleColumn = tenantUserAddFirstColumn(
    $userColumns,
    array(
        'job_title',
        'designation',
        'position'
    )
);

$userCreatedAtColumn = tenantUserAddFirstColumn(
    $userColumns,
    array('created_at')
);

$userUpdatedAtColumn = tenantUserAddFirstColumn(
    $userColumns,
    array('updated_at')
);


if (
    $userIdColumn === '' ||
    $userTenantColumn === '' ||
    $userPasswordColumn === ''
) {
    http_response_code(500);

    exit(
        'The users table requires id, tenant_id and password columns.'
    );
}

/*
|--------------------------------------------------------------------------
| Detect tenant columns
|--------------------------------------------------------------------------
*/

$tenantIdColumn = tenantUserAddFirstColumn(
    $tenantColumns,
    array('id', 'tenant_id')
);

$tenantNameColumn = tenantUserAddFirstColumn(
    $tenantColumns,
    array(
        'company_name',
        'business_name',
        'tenant_name',
        'name'
    )
);

$tenantCodeColumn = tenantUserAddFirstColumn(
    $tenantColumns,
    array(
        'tenant_code',
        'code',
        'business_code'
    )
);

$tenantDeletedColumn = tenantUserAddFirstColumn(
    $tenantColumns,
    array('deleted_at')
);

if (
    $tenantIdColumn === '' ||
    $tenantNameColumn === ''
) {
    http_response_code(500);

    exit(
        'The tenants table requires id and name columns.'
    );
}

/*
|--------------------------------------------------------------------------
| Detect roles table
|--------------------------------------------------------------------------
*/

$hasRolesTable = tenantUserAddTableExists(
    $conn,
    'roles'
);

$roleIdColumn = '';
$roleNameColumn = '';
$roleCodeColumn = '';
$roleTenantColumn = '';
$roleStatusColumn = '';
$roleDeletedColumn = '';

if ($hasRolesTable) {
    $roleColumns = tenantUserAddColumns(
        $conn,
        'roles'
    );

    $roleIdColumn = tenantUserAddFirstColumn(
        $roleColumns,
        array('id', 'role_id')
    );

    $roleNameColumn = tenantUserAddFirstColumn(
        $roleColumns,
        array('name', 'role_name')
    );

    $roleCodeColumn = tenantUserAddFirstColumn(
        $roleColumns,
        array('code', 'role_code')
    );

    $roleTenantColumn = tenantUserAddFirstColumn(
        $roleColumns,
        array('tenant_id')
    );

    $roleStatusColumn = tenantUserAddFirstColumn(
        $roleColumns,
        array('status')
    );

    $roleDeletedColumn = tenantUserAddFirstColumn(
        $roleColumns,
        array('deleted_at')
    );
}

/*
|--------------------------------------------------------------------------
| Load tenants
|--------------------------------------------------------------------------
*/

$tenantList = array();

$tenantSql = "
    SELECT
        `{$tenantIdColumn}` AS tenant_id,
        `{$tenantNameColumn}` AS tenant_name
";

if ($tenantCodeColumn !== '') {
    $tenantSql .= ",
        `{$tenantCodeColumn}` AS tenant_code
    ";
} else {
    $tenantSql .= ",
        '' AS tenant_code
    ";
}

$tenantSql .= "
    FROM tenants
";

if ($tenantDeletedColumn !== '') {
    $tenantSql .= "
        WHERE `{$tenantDeletedColumn}` IS NULL
    ";
}

$tenantSql .= "
    ORDER BY `{$tenantNameColumn}` ASC
";

$tenantResult = $conn->query($tenantSql);

if ($tenantResult) {
    while ($row = $tenantResult->fetch_assoc()) {
        $tenantList[] = $row;
    }

    $tenantResult->free();
}

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$errorMessage = '';

$tenantId = isset($_POST['tenant_id'])
    ? (int) $_POST['tenant_id']
    : (
        isset($_GET['tenant_id'])
            ? (int) $_GET['tenant_id']
            : 0
    );

$firstName = tenantUserAddPost('first_name');
$lastName = tenantUserAddPost('last_name');
$email = strtolower(
    tenantUserAddPost('email')
);
$phone = tenantUserAddPost('phone');
$username = tenantUserAddPost('username');
$jobTitle = tenantUserAddPost('job_title');
$status = strtolower(
    tenantUserAddPost('status', 'active')
);

$roleId = isset($_POST['role_id'])
    ? (int) $_POST['role_id']
    : 0;

$roleCodeValue = tenantUserAddPost(
    'role_code',
    'user'
);

$roleMode = tenantUserAddPost(
    'role_mode',
    'existing'
);

$newRoleName = tenantUserAddPost(
    'new_role_name'
);

$newRoleCode = tenantUserAddPost(
    'new_role_code'
);

if (!in_array(
    $roleMode,
    array('existing', 'new'),
    true
)) {
    $roleMode = 'existing';
}

$password = isset($_POST['password']) &&
    !is_array($_POST['password'])
        ? (string) $_POST['password']
        : '';

$confirmPassword = isset($_POST['confirm_password']) &&
    !is_array($_POST['confirm_password'])
        ? (string) $_POST['confirm_password']
        : '';

/*
|--------------------------------------------------------------------------
| Determine supported user statuses
|--------------------------------------------------------------------------
*/

$statusOptions = array(
    'active',
    'inactive',
    'suspended'
);

if ($userStatusColumn !== '') {
    $columnType = isset(
        $userColumns[$userStatusColumn]['Type']
    )
        ? $userColumns[$userStatusColumn]['Type']
        : '';

    $enumStatuses = tenantUserAddEnumValues(
        $columnType
    );

    if (!empty($enumStatuses)) {
        $statusOptions = $enumStatuses;
    }
}

if (!in_array($status, $statusOptions, true)) {
    $status = in_array(
        'active',
        $statusOptions,
        true
    )
        ? 'active'
        : (
            !empty($statusOptions)
                ? $statusOptions[0]
                : 'active'
        );
}

/*
|--------------------------------------------------------------------------
| Load roles for selected tenant
|--------------------------------------------------------------------------
*/

$roles = array();

if (
    $hasRolesTable &&
    $roleIdColumn !== '' &&
    (
        $roleNameColumn !== '' ||
        $roleCodeColumn !== ''
    )
) {
    $roleSql = "
        SELECT
            `{$roleIdColumn}` AS role_id
    ";

    if ($roleNameColumn !== '') {
        $roleSql .= ",
            `{$roleNameColumn}` AS role_name
        ";
    } else {
        $roleSql .= ",
            `{$roleCodeColumn}` AS role_name
        ";
    }

    if ($roleCodeColumn !== '') {
        $roleSql .= ",
            `{$roleCodeColumn}` AS role_code
        ";
    } else {
        $roleSql .= ",
            '' AS role_code
        ";
    }

    $roleSql .= "
        FROM roles
    ";

    $roleWhere = array();
    $roleParams = array();
    $roleTypes = '';

    if ($roleTenantColumn !== '' && $tenantId > 0) {
        $roleWhere[] =
            "(
                `{$roleTenantColumn}` = ?
                OR `{$roleTenantColumn}` IS NULL
            )";

        $roleTypes .= 'i';
        $roleParams[] = $tenantId;
    }

    if ($roleStatusColumn !== '') {
        $roleWhere[] =
            "`{$roleStatusColumn}` = 'active'";
    }

    if ($roleDeletedColumn !== '') {
        $roleWhere[] =
            "`{$roleDeletedColumn}` IS NULL";
    }

    if (!empty($roleWhere)) {
        $roleSql .= "
            WHERE " .
            implode(' AND ', $roleWhere);
    }

    if ($roleTenantColumn !== '' && $tenantId > 0) {
        $roleSql .= "
            ORDER BY
                CASE
                    WHEN `{$roleTenantColumn}` = {$tenantId}
                    THEN 0
                    ELSE 1
                END,
                role_name ASC
        ";
    } else {
        $roleSql .= "
            ORDER BY role_name ASC
        ";
    }

    $roleStmt = $conn->prepare($roleSql);

    if ($roleStmt) {
        tenantUserAddBind(
            $roleStmt,
            $roleTypes,
            $roleParams
        );

        if ($roleStmt->execute()) {
            $roleResult = $roleStmt->get_result();

            while ($row = $roleResult->fetch_assoc()) {
                $roles[] = $row;
            }
        }

        $roleStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Process form
|--------------------------------------------------------------------------
*/

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    $tenantExists = false;

    foreach ($tenantList as $tenantRow) {
        if ((int) $tenantRow['tenant_id'] === $tenantId) {
            $tenantExists = true;
            break;
        }
    }

    if (!$tenantExists) {
        $errorMessage = 'Select a valid tenant.';
    } elseif (
        $firstName === '' &&
        $userNameColumn === ''
    ) {
        $errorMessage = 'Enter the first name.';
    } elseif (
        $firstName === '' &&
        $lastName === '' &&
        $userNameColumn !== ''
    ) {
        $errorMessage = 'Enter the user name.';
    } elseif (
        strlen($firstName) > 120 ||
        strlen($lastName) > 120
    ) {
        $errorMessage =
            'First and last names must not exceed 120 characters.';
    } elseif (
        $email !== '' &&
        filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        ) === false
    ) {
        $errorMessage = 'Enter a valid email address.';
    } elseif (
        $userEmailColumn !== '' &&
        $email === ''
    ) {
        $errorMessage = 'Enter the email address.';
    } elseif (strlen($email) > 190) {
        $errorMessage =
            'Email address must not exceed 190 characters.';
    } elseif (strlen($phone) > 50) {
        $errorMessage =
            'Phone number must not exceed 50 characters.';
    } elseif (strlen($username) > 120) {
        $errorMessage =
            'Username must not exceed 120 characters.';
    } elseif (
        $userRoleIdColumn !== '' &&
        $roleMode === 'existing' &&
        $roleId <= 0
    ) {
        $errorMessage = 'Select a user role.';
    } elseif (
        $userRoleIdColumn !== '' &&
        $roleMode === 'new' &&
        $newRoleName === ''
    ) {
        $errorMessage = 'Enter the new role name.';
    } elseif (
        strlen($newRoleName) > 150
    ) {
        $errorMessage =
            'New role name must not exceed 150 characters.';
    } else {
        $passwordError =
            tenantUserAddPasswordError($password);

        if ($passwordError !== '') {
            $errorMessage = $passwordError;
        } elseif ($password !== $confirmPassword) {
            $errorMessage =
                'Password and confirmation password do not match.';
        }
    }

    if (
        $errorMessage === '' &&
        $userEmailColumn !== '' &&
        tenantUserAddUniqueValueExists(
            $conn,
            $userEmailColumn,
            $email
        )
    ) {
        $errorMessage =
            'A user already exists with this email address.';
    }

    if (
        $errorMessage === '' &&
        $userUsernameColumn !== '' &&
        tenantUserAddUniqueValueExists(
            $conn,
            $userUsernameColumn,
            $username
        )
    ) {
        $errorMessage =
            'A user already exists with this username.';
    }

    if (
        $errorMessage === '' &&
        $userRoleIdColumn !== ''
    ) {
        if ($roleMode === 'existing') {
            $roleCheckSql = "
                SELECT
                    `{$roleIdColumn}` AS role_id
            ";

            if ($roleCodeColumn !== '') {
                $roleCheckSql .= ",
                    `{$roleCodeColumn}` AS role_code
                ";
            } else {
                $roleCheckSql .= ",
                    '' AS role_code
                ";
            }

            $roleCheckSql .= "
                FROM roles
                WHERE `{$roleIdColumn}` = ?
            ";

            $roleCheckTypes = 'i';
            $roleCheckParams = array($roleId);

            if ($roleTenantColumn !== '') {
                $roleCheckSql .= "
                    AND (
                        `{$roleTenantColumn}` = ?
                        OR `{$roleTenantColumn}` IS NULL
                    )
                ";

                $roleCheckTypes .= 'i';
                $roleCheckParams[] = $tenantId;
            }

            if ($roleStatusColumn !== '') {
                $roleCheckSql .= "
                    AND `{$roleStatusColumn}` = 'active'
                ";
            }

            if ($roleDeletedColumn !== '') {
                $roleCheckSql .= "
                    AND `{$roleDeletedColumn}` IS NULL
                ";
            }

            $roleCheckSql .= " LIMIT 1";

            $roleCheckStmt = $conn->prepare(
                $roleCheckSql
            );

            tenantUserAddBind(
                $roleCheckStmt,
                $roleCheckTypes,
                $roleCheckParams
            );

            $roleCheckStmt->execute();

            $roleCheckResult =
                $roleCheckStmt->get_result();

            $validRole =
                $roleCheckResult->fetch_assoc();

            $roleCheckStmt->close();

            if (!$validRole) {
                $errorMessage =
                    'The selected role is invalid for this tenant.';
            } elseif (
                !empty($validRole['role_code'])
            ) {
                $roleCodeValue =
                    $validRole['role_code'];
            }
        } else {
            $newRoleCode = tenantUserAddRoleCode(
                $newRoleCode !== ''
                    ? $newRoleCode
                    : $newRoleName
            );

            if ($newRoleCode === '') {
                $errorMessage =
                    'Enter a valid new role code.';
            } else {
                $duplicateRoleSql = "
                    SELECT COUNT(*) AS total
                    FROM roles
                    WHERE LOWER(`{$roleCodeColumn}`) =
                          LOWER(?)
                ";

                $duplicateRoleTypes = 's';
                $duplicateRoleParams = array(
                    $newRoleCode
                );

                if ($roleTenantColumn !== '') {
                    $duplicateRoleSql .= "
                        AND `{$roleTenantColumn}` = ?
                    ";

                    $duplicateRoleTypes .= 'i';
                    $duplicateRoleParams[] =
                        $tenantId;
                }

                $duplicateRoleStmt =
                    $conn->prepare(
                        $duplicateRoleSql
                    );

                tenantUserAddBind(
                    $duplicateRoleStmt,
                    $duplicateRoleTypes,
                    $duplicateRoleParams
                );

                $duplicateRoleStmt->execute();

                $duplicateRoleResult =
                    $duplicateRoleStmt->get_result();

                $duplicateRoleRow =
                    $duplicateRoleResult->fetch_assoc();

                $duplicateRoleStmt->close();

                if (!empty(
                    $duplicateRoleRow['total']
                )) {
                    $errorMessage =
                        'This role code already exists for the selected tenant.';
                }
            }
        }
    }

    $uploadedAvatarPath = '';

    if (
        $errorMessage === '' &&
        $userAvatarColumn !== ''
    ) {
        $avatarUpload =
            tenantUserAddUploadAvatar(
                'avatar'
            );

        if (!$avatarUpload['success']) {
            $errorMessage =
                $avatarUpload['message'];
        } else {
            $uploadedAvatarPath =
                $avatarUpload['path'];
        }
    }

    if ($errorMessage === '') {
        $passwordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        if ($passwordHash === false) {
            $errorMessage =
                'Unable to secure the password.';
        }
    }

    if ($errorMessage === '') {
        $insertData = array();

        $insertData[$userTenantColumn] =
            $tenantId;

        if ($userFirstNameColumn !== '') {
            $insertData[$userFirstNameColumn] =
                $firstName;
        }

        if ($userLastNameColumn !== '') {
            $insertData[$userLastNameColumn] =
                $lastName;
        }

        if ($userNameColumn !== '') {
            $insertData[$userNameColumn] =
                trim($firstName . ' ' . $lastName);
        }

        if ($userEmailColumn !== '') {
            $insertData[$userEmailColumn] =
                $email;
        }

        if ($userPhoneColumn !== '') {
            $insertData[$userPhoneColumn] =
                $phone;
        }

        if (
            $userUsernameColumn !== '' &&
            $username !== ''
        ) {
            $insertData[$userUsernameColumn] =
                $username;
        }

        $insertData[$userPasswordColumn] =
            $passwordHash;

        if ($userStatusColumn !== '') {
            $insertData[$userStatusColumn] =
                $status;
        }

        if (
            $userRoleIdColumn !== '' &&
            $roleId > 0
        ) {
            $insertData[$userRoleIdColumn] =
                $roleId;
        } elseif (
            $userRoleIdColumn === '' &&
            $userRoleCodeColumn !== ''
        ) {
            $insertData[$userRoleCodeColumn] =
                $roleCodeValue !== ''
                    ? $roleCodeValue
                    : 'user';
        }

        if ($userAvatarColumn !== '') {
            $insertData[$userAvatarColumn] =
                $uploadedAvatarPath;
        }

        if ($userJobTitleColumn !== '') {
            $insertData[$userJobTitleColumn] =
                $jobTitle;
        }

        /*
         * Platform user IDs should not be inserted into
         * tenant-owned created_by/updated_by foreign keys.
         */
        $columns = array();
        $placeholders = array();
        $values = array();
        $types = '';

        foreach ($insertData as $column => $value) {
            $columns[] = "`{$column}`";
            $placeholders[] = '?';
            $values[] = $value;

            $columnType = isset(
                $userColumns[$column]['Type']
            )
                ? strtolower(
                    (string) $userColumns[$column]['Type']
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

        if ($userCreatedAtColumn !== '') {
            $columns[] =
                "`{$userCreatedAtColumn}`";
            $placeholders[] = 'NOW()';
        }

        if ($userUpdatedAtColumn !== '') {
            $columns[] =
                "`{$userUpdatedAtColumn}`";
            $placeholders[] = 'NOW()';
        }

        $insertSql = "
            INSERT INTO users (
                " . implode(', ', $columns) . "
            ) VALUES (
                " . implode(', ', $placeholders) . "
            )
        ";

        try {
            $conn->begin_transaction();

            if (
                $userRoleIdColumn !== '' &&
                $roleMode === 'new'
            ) {
                $newRoleData = array();

                if ($roleTenantColumn !== '') {
                    $newRoleData[$roleTenantColumn] =
                        $tenantId;
                }

                $newRoleData[$roleNameColumn] =
                    $newRoleName;

                if ($roleCodeColumn !== '') {
                    $newRoleData[$roleCodeColumn] =
                        $newRoleCode;
                }

                if ($roleStatusColumn !== '') {
                    $newRoleData[$roleStatusColumn] =
                        'active';
                }

                $newRoleColumns = array();
                $newRolePlaceholders = array();
                $newRoleValues = array();
                $newRoleTypes = '';

                foreach (
                    $newRoleData as
                    $newRoleColumn => $newRoleValue
                ) {
                    $newRoleColumns[] =
                        "`{$newRoleColumn}`";

                    $newRolePlaceholders[] = '?';
                    $newRoleValues[] = $newRoleValue;

                    $newRoleColumnType = isset(
                        $roleColumns[
                            $newRoleColumn
                        ]['Type']
                    )
                        ? strtolower(
                            (string)
                            $roleColumns[
                                $newRoleColumn
                            ]['Type']
                        )
                        : '';

                    if (
                        preg_match(
                            '/^(tinyint|smallint|mediumint|int|bigint)/',
                            $newRoleColumnType
                        )
                    ) {
                        $newRoleTypes .= 'i';
                    } else {
                        $newRoleTypes .= 's';
                    }
                }

                if (
                    isset($roleColumns['created_at'])
                ) {
                    $newRoleColumns[] =
                        "`created_at`";

                    $newRolePlaceholders[] =
                        'NOW()';
                }

                if (
                    isset($roleColumns['updated_at'])
                ) {
                    $newRoleColumns[] =
                        "`updated_at`";

                    $newRolePlaceholders[] =
                        'NOW()';
                }

                $newRoleInsertSql = "
                    INSERT INTO roles (
                        " .
                        implode(
                            ', ',
                            $newRoleColumns
                        ) .
                        "
                    ) VALUES (
                        " .
                        implode(
                            ', ',
                            $newRolePlaceholders
                        ) .
                        "
                    )
                ";

                $newRoleInsertStmt =
                    $conn->prepare(
                        $newRoleInsertSql
                    );

                tenantUserAddBind(
                    $newRoleInsertStmt,
                    $newRoleTypes,
                    $newRoleValues
                );

                $newRoleInsertStmt->execute();

                $roleId = (int)
                    $newRoleInsertStmt->insert_id;

                $newRoleInsertStmt->close();

                if ($roleId <= 0) {
                    throw new Exception(
                        'The new role could not be created.'
                    );
                }

                $insertData[$userRoleIdColumn] =
                    $roleId;

                $columns = array();
                $placeholders = array();
                $values = array();
                $types = '';

                foreach (
                    $insertData as
                    $column => $value
                ) {
                    $columns[] = "`{$column}`";
                    $placeholders[] = '?';
                    $values[] = $value;

                    $columnType = isset(
                        $userColumns[$column]['Type']
                    )
                        ? strtolower(
                            (string)
                            $userColumns[
                                $column
                            ]['Type']
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

                if (
                    $userCreatedAtColumn !== ''
                ) {
                    $columns[] =
                        "`{$userCreatedAtColumn}`";
                    $placeholders[] = 'NOW()';
                }

                if (
                    $userUpdatedAtColumn !== ''
                ) {
                    $columns[] =
                        "`{$userUpdatedAtColumn}`";
                    $placeholders[] = 'NOW()';
                }

                $insertSql = "
                    INSERT INTO users (
                        " .
                        implode(', ', $columns) .
                        "
                    ) VALUES (
                        " .
                        implode(
                            ', ',
                            $placeholders
                        ) .
                        "
                    )
                ";
            }

            $insertStmt = $conn->prepare(
                $insertSql
            );

            if (!$insertStmt) {
                throw new Exception(
                    'Unable to prepare tenant user creation: ' .
                    $conn->error
                );
            }

            tenantUserAddBind(
                $insertStmt,
                $types,
                $values
            );

            if (!$insertStmt->execute()) {
                throw new Exception(
                    'Unable to create tenant user: ' .
                    $insertStmt->error
                );
            }

            $newUserId =
                (int) $insertStmt->insert_id;

            $insertStmt->close();

            $conn->commit();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Tenant user created successfully.';

            header(
                'Location: tenant-users.php?tenant_id=' .
                (int) $tenantId,
                true,
                303
            );

            exit;
        } catch (Exception $exception) {
            $conn->rollback();

            if (
                $uploadedAvatarPath !== '' &&
                file_exists(
                    __DIR__ .
                    '/../' .
                    $uploadedAvatarPath
                )
            ) {
                @unlink(
                    __DIR__ .
                    '/../' .
                    $uploadedAvatarPath
                );
            }

            error_log(
                'Tenant user creation failed: ' .
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
    .tenant-user-add-page {
        max-width: 1050px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .tenant-user-add-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .tenant-user-add-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .tenant-user-add-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .tenant-user-add-back {
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

    .tenant-user-add-back:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .tenant-user-add-alert {
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

    .tenant-user-add-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(260px, 315px);
        gap: 15px;
        align-items: start;
    }

    .tenant-user-add-main,
    .tenant-user-add-side {
        display: grid;
        gap: 15px;
    }

    .tenant-user-add-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .tenant-user-add-card-header {
        min-height: 53px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .tenant-user-add-card-icon {
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

    .tenant-user-add-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .tenant-user-add-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-user-add-card-body {
        padding: 15px;
    }

    .tenant-user-add-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .tenant-user-required {
        color: #dc2626;
    }

    .tenant-user-add-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    .tenant-user-add-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .tenant-user-add-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .tenant-user-password-wrap {
        position: relative;
    }

    .tenant-user-password-wrap .tenant-user-add-control {
        padding-right: 40px;
    }

    .tenant-user-password-toggle {
        width: 32px;
        height: 32px;
        padding: 0;
        position: absolute;
        top: 50%;
        right: 4px;
        transform: translateY(-50%);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: #9ca3af;
    }

    .tenant-user-password-toggle:hover {
        background: #f3f0ff;
        color: #7c3aed;
    }

    .tenant-user-avatar-upload {
        min-height: 145px;
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

    .tenant-user-avatar-upload:hover {
        border-color: #a78bfa;
        background: #faf8ff;
    }

    .tenant-user-avatar-preview {
        width: 70px;
        height: 70px;
        margin: 0 auto 9px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background:
            linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
        color: #ffffff;
        font-size: 18px;
        font-weight: 800;
    }

    .tenant-user-avatar-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .tenant-user-avatar-title {
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .tenant-user-avatar-text {
        margin-top: 3px;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-user-add-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .tenant-user-add-submit {
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

    .tenant-user-add-submit:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }

    .tenant-user-add-cancel {
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
        .tenant-user-add-layout {
            grid-template-columns: 1fr;
        }

        .tenant-user-add-side {
            order: -1;
        }
    }

    @media (max-width: 600px) {
        .tenant-user-add-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .tenant-user-add-back {
            width: 100%;
        }
    }
</style>

<div class="tenant-user-add-page">

    <div class="tenant-user-add-header">
        <div>
            <h2 class="tenant-user-add-title">
                Add Tenant User
            </h2>

            <div class="tenant-user-add-description">
                Create a user account for a tenant workspace.
            </div>
        </div>

        <div style="display:flex;gap:7px;flex-wrap:wrap;">
            <a
                href="roles.php<?= $tenantId > 0
                    ? '?tenant_id=' . (int) $tenantId
                    : ''; ?>"
                class="tenant-user-add-back"
            >
                <i class="bi bi-shield-check"></i>
                Manage Roles
            </a>

            <a
                href="tenant-users.php<?= $tenantId > 0
                    ? '?tenant_id=' . (int) $tenantId
                    : ''; ?>"
                class="tenant-user-add-back"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Tenant Users
            </a>
        </div>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="tenant-user-add-alert">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= tenantUserAddEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        enctype="multipart/form-data"
        id="tenantUserAddForm"
    >
        <?php csrfField(); ?>

        <div class="tenant-user-add-layout">

            <div class="tenant-user-add-main">

                <section class="tenant-user-add-card">
                    <div class="tenant-user-add-card-header">
                        <span class="tenant-user-add-card-icon">
                            <i class="bi bi-person"></i>
                        </span>

                        <div>
                            <h3 class="tenant-user-add-card-title">
                                User Information
                            </h3>

                            <div class="tenant-user-add-card-subtitle">
                                Basic tenant user details
                            </div>
                        </div>
                    </div>

                    <div class="tenant-user-add-card-body">
                        <div class="row g-3">

                            <div class="col-12">
                                <label
                                    for="tenantId"
                                    class="tenant-user-add-label"
                                >
                                    Tenant
                                    <span class="tenant-user-required">*</span>
                                </label>

                                <select
                                    class="form-select tenant-user-add-control"
                                    id="tenantId"
                                    name="tenant_id"
                                    required
                                >
                                    <option value="">
                                        Select tenant
                                    </option>

                                    <?php foreach (
                                        $tenantList as $tenantRow
                                    ): ?>
                                        <option
                                            value="<?= (int)
                                                $tenantRow[
                                                    'tenant_id'
                                                ]; ?>"
                                            <?= $tenantId ===
                                                (int)
                                                $tenantRow[
                                                    'tenant_id'
                                                ]
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= tenantUserAddEscape(
                                                $tenantRow[
                                                    'tenant_name'
                                                ]
                                            ); ?>

                                            <?php if (
                                                !empty(
                                                    $tenantRow[
                                                        'tenant_code'
                                                    ]
                                                )
                                            ): ?>
                                                -
                                                <?= tenantUserAddEscape(
                                                    $tenantRow[
                                                        'tenant_code'
                                                    ]
                                                ); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label
                                    for="firstName"
                                    class="tenant-user-add-label"
                                >
                                    First Name
                                    <span class="tenant-user-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    class="form-control tenant-user-add-control"
                                    id="firstName"
                                    name="first_name"
                                    value="<?= tenantUserAddEscape(
                                        $firstName
                                    ); ?>"
                                    maxlength="120"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label
                                    for="lastName"
                                    class="tenant-user-add-label"
                                >
                                    Last Name
                                </label>

                                <input
                                    type="text"
                                    class="form-control tenant-user-add-control"
                                    id="lastName"
                                    name="last_name"
                                    value="<?= tenantUserAddEscape(
                                        $lastName
                                    ); ?>"
                                    maxlength="120"
                                >
                            </div>

                            <?php if ($userEmailColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        for="email"
                                        class="tenant-user-add-label"
                                    >
                                        Email Address
                                        <span class="tenant-user-required">*</span>
                                    </label>

                                    <input
                                        type="email"
                                        class="form-control tenant-user-add-control"
                                        id="email"
                                        name="email"
                                        value="<?= tenantUserAddEscape(
                                            $email
                                        ); ?>"
                                        maxlength="190"
                                        required
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($userPhoneColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        for="phone"
                                        class="tenant-user-add-label"
                                    >
                                        Phone Number
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-user-add-control"
                                        id="phone"
                                        name="phone"
                                        value="<?= tenantUserAddEscape(
                                            $phone
                                        ); ?>"
                                        maxlength="50"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($userUsernameColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        for="username"
                                        class="tenant-user-add-label"
                                    >
                                        Username
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-user-add-control"
                                        id="username"
                                        name="username"
                                        value="<?= tenantUserAddEscape(
                                            $username
                                        ); ?>"
                                        maxlength="120"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($userJobTitleColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        for="jobTitle"
                                        class="tenant-user-add-label"
                                    >
                                        Job Title
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-user-add-control"
                                        id="jobTitle"
                                        name="job_title"
                                        value="<?= tenantUserAddEscape(
                                            $jobTitle
                                        ); ?>"
                                        maxlength="150"
                                    >
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </section>

                <section class="tenant-user-add-card">
                    <div class="tenant-user-add-card-header">
                        <span class="tenant-user-add-card-icon">
                            <i class="bi bi-lock"></i>
                        </span>

                        <div>
                            <h3 class="tenant-user-add-card-title">
                                Login Credentials
                            </h3>

                            <div class="tenant-user-add-card-subtitle">
                                Secure account login details
                            </div>
                        </div>
                    </div>

                    <div class="tenant-user-add-card-body">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label
                                    for="password"
                                    class="tenant-user-add-label"
                                >
                                    Password
                                    <span class="tenant-user-required">*</span>
                                </label>

                                <div class="tenant-user-password-wrap">
                                    <input
                                        type="password"
                                        class="form-control tenant-user-add-control"
                                        id="password"
                                        name="password"
                                        minlength="8"
                                        autocomplete="new-password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="tenant-user-password-toggle"
                                        data-password-toggle="password"
                                        aria-label="Show password"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label
                                    for="confirmPassword"
                                    class="tenant-user-add-label"
                                >
                                    Confirm Password
                                    <span class="tenant-user-required">*</span>
                                </label>

                                <div class="tenant-user-password-wrap">
                                    <input
                                        type="password"
                                        class="form-control tenant-user-add-control"
                                        id="confirmPassword"
                                        name="confirm_password"
                                        minlength="8"
                                        autocomplete="new-password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="tenant-user-password-toggle"
                                        data-password-toggle="confirmPassword"
                                        aria-label="Show password"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="tenant-user-add-help">
                                    Use at least 8 characters with an uppercase
                                    letter, lowercase letter and number.
                                </div>
                            </div>

                        </div>
                    </div>
                </section>

            </div>

            <aside class="tenant-user-add-side">

                <?php if ($userAvatarColumn !== ''): ?>
                    <section class="tenant-user-add-card">
                        <div class="tenant-user-add-card-header">
                            <span class="tenant-user-add-card-icon">
                                <i class="bi bi-image"></i>
                            </span>

                            <div>
                                <h3 class="tenant-user-add-card-title">
                                    Profile Image
                                </h3>

                                <div class="tenant-user-add-card-subtitle">
                                    JPG, PNG, or WEBP up to 2 MB
                                </div>
                            </div>
                        </div>

                        <div class="tenant-user-add-card-body">
                            <label
                                for="avatar"
                                class="tenant-user-avatar-upload"
                            >
                                <div>
                                    <div
                                        class="tenant-user-avatar-preview"
                                        id="avatarPreview"
                                    >
                                        <span id="avatarInitials">
                                            U
                                        </span>
                                    </div>

                                    <div class="tenant-user-avatar-title">
                                        Choose Profile Image
                                    </div>

                                    <div class="tenant-user-avatar-text">
                                        Click to select an image
                                    </div>
                                </div>
                            </label>

                            <input
                                type="file"
                                id="avatar"
                                name="avatar"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                class="d-none"
                            >
                        </div>
                    </section>
                <?php endif; ?>

                <section class="tenant-user-add-card">
                    <div class="tenant-user-add-card-header">
                        <span class="tenant-user-add-card-icon">
                            <i class="bi bi-shield-check"></i>
                        </span>

                        <div>
                            <h3 class="tenant-user-add-card-title">
                                Access Settings
                            </h3>

                            <div class="tenant-user-add-card-subtitle">
                                Role and account status
                            </div>
                        </div>
                    </div>

                    <div class="tenant-user-add-card-body">
                        <div class="row g-3">

                            <?php if (
                                $userRoleIdColumn !== ''
                            ): ?>
                                <div class="col-12">
                                    <label class="tenant-user-add-label">
                                        Role Option
                                    </label>

                                    <div
                                        style="display:grid;grid-template-columns:1fr 1fr;gap:7px;"
                                    >
                                        <label
                                            style="padding:9px;border:1px solid #e5e7eb;border-radius:8px;font-size:9px;cursor:pointer;"
                                        >
                                            <input
                                                type="radio"
                                                name="role_mode"
                                                value="existing"
                                                <?= $roleMode === 'existing'
                                                    ? 'checked'
                                                    : ''; ?>
                                            >
                                            Select Existing
                                        </label>

                                        <label
                                            style="padding:9px;border:1px solid #e5e7eb;border-radius:8px;font-size:9px;cursor:pointer;"
                                        >
                                            <input
                                                type="radio"
                                                name="role_mode"
                                                value="new"
                                                <?= $roleMode === 'new'
                                                    ? 'checked'
                                                    : ''; ?>
                                            >
                                            Add New Role
                                        </label>
                                    </div>
                                </div>

                                <div
                                    class="col-12"
                                    id="existingRoleFields"
                                >
                                    <label
                                        for="roleId"
                                        class="tenant-user-add-label"
                                    >
                                        Select Role
                                        <span class="tenant-user-required">*</span>
                                    </label>

                                    <select
                                        class="form-select tenant-user-add-control"
                                        id="roleId"
                                        name="role_id"
                                    >
                                        <option value="">
                                            Select role
                                        </option>

                                        <?php foreach (
                                            $roles as $roleRow
                                        ): ?>
                                            <option
                                                value="<?= (int)
                                                    $roleRow[
                                                        'role_id'
                                                    ]; ?>"
                                                <?= $roleId ===
                                                    (int)
                                                    $roleRow[
                                                        'role_id'
                                                    ]
                                                        ? 'selected'
                                                        : ''; ?>
                                            >
                                                <?= tenantUserAddEscape(
                                                    $roleRow[
                                                        'role_name'
                                                    ]
                                                ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <?php if (empty($roles)): ?>
                                        <div
                                            class="tenant-user-add-help"
                                            style="color:#b45309;"
                                        >
                                            No existing role is available.
                                            Choose Add New Role.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div
                                    class="col-12"
                                    id="newRoleFields"
                                >
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label
                                                for="newRoleName"
                                                class="tenant-user-add-label"
                                            >
                                                New Role Name
                                                <span class="tenant-user-required">*</span>
                                            </label>

                                            <input
                                                type="text"
                                                class="form-control tenant-user-add-control"
                                                id="newRoleName"
                                                name="new_role_name"
                                                value="<?= tenantUserAddEscape(
                                                    $newRoleName
                                                ); ?>"
                                                maxlength="150"
                                                placeholder="Example: Branch Manager"
                                            >
                                        </div>

                                        <div class="col-12">
                                            <label
                                                for="newRoleCode"
                                                class="tenant-user-add-label"
                                            >
                                                New Role Code
                                            </label>

                                            <input
                                                type="text"
                                                class="form-control tenant-user-add-control"
                                                id="newRoleCode"
                                                name="new_role_code"
                                                value="<?= tenantUserAddEscape(
                                                    $newRoleCode
                                                ); ?>"
                                                maxlength="150"
                                                placeholder="Auto generated"
                                            >
                                        </div>
                                    </div>
                                </div>
                            <?php elseif (
                                $userRoleCodeColumn !== ''
                            ): ?>
                                <div class="col-12">
                                    <label
                                        for="roleCode"
                                        class="tenant-user-add-label"
                                    >
                                        Role Code
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-user-add-control"
                                        id="roleCode"
                                        name="role_code"
                                        value="<?= tenantUserAddEscape(
                                            $roleCodeValue
                                        ); ?>"
                                        maxlength="100"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($userStatusColumn !== ''): ?>
                                <div class="col-12">
                                    <label
                                        for="status"
                                        class="tenant-user-add-label"
                                    >
                                        Status
                                    </label>

                                    <select
                                        class="form-select tenant-user-add-control"
                                        id="status"
                                        name="status"
                                    >
                                        <?php foreach (
                                            $statusOptions as $statusOption
                                        ): ?>
                                            <option
                                                value="<?= tenantUserAddEscape(
                                                    $statusOption
                                                ); ?>"
                                                <?= $status ===
                                                    $statusOption
                                                        ? 'selected'
                                                        : ''; ?>
                                            >
                                                <?= tenantUserAddEscape(
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

                        </div>
                    </div>
                </section>

                <div class="tenant-user-add-submit-card">
                    <button
                        type="submit"
                        class="tenant-user-add-submit"
                        id="tenantUserSubmitButton"
                    >
                        <i class="bi bi-person-plus"></i>
                        Create Tenant User
                    </button>

                    <a
                        href="tenant-users.php<?= $tenantId > 0
                            ? '?tenant_id=' . (int) $tenantId
                            : ''; ?>"
                        class="tenant-user-add-cancel"
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
        'tenantUserAddForm'
    );


    const firstName = document.getElementById(
        'firstName'
    );

    const lastName = document.getElementById(
        'lastName'
    );

    const avatarInput = document.getElementById(
        'avatar'
    );

    const avatarPreview = document.getElementById(
        'avatarPreview'
    );

    const avatarInitials = document.getElementById(
        'avatarInitials'
    );


    function updateInitials() {
        if (!avatarInitials) {
            return;
        }

        let initials = '';

        if (firstName && firstName.value.trim()) {
            initials += firstName.value
                .trim()
                .charAt(0);
        }

        if (lastName && lastName.value.trim()) {
            initials += lastName.value
                .trim()
                .charAt(0);
        }

        avatarInitials.textContent =
            initials.toUpperCase() || 'U';
    }

    if (firstName) {
        firstName.addEventListener(
            'input',
            updateInitials
        );
    }

    if (lastName) {
        lastName.addEventListener(
            'input',
            updateInitials
        );
    }

    const passwordButtons = document.querySelectorAll(
        '[data-password-toggle]'
    );

    passwordButtons.forEach(function (button) {
        button.addEventListener(
            'click',
            function () {
                const inputId = button.getAttribute(
                    'data-password-toggle'
                );

                const input =
                    document.getElementById(inputId);

                if (!input) {
                    return;
                }

                const show =
                    input.type === 'password';

                input.type = show
                    ? 'text'
                    : 'password';

                const icon =
                    button.querySelector('i');

                if (icon) {
                    icon.classList.toggle(
                        'bi-eye',
                        !show
                    );

                    icon.classList.toggle(
                        'bi-eye-slash',
                        show
                    );
                }
            }
        );
    });

    if (
        avatarInput &&
        avatarPreview
    ) {
        avatarInput.addEventListener(
            'change',
            function () {
                const file = avatarInput.files[0];

                if (!file) {
                    return;
                }

                if (!file.type.match(/^image\//)) {
                    alert(
                        'Select a valid image file.'
                    );

                    avatarInput.value = '';
                    return;
                }

                if (file.size > 2 * 1024 * 1024) {
                    alert(
                        'Profile image must not exceed 2 MB.'
                    );

                    avatarInput.value = '';
                    return;
                }

                const reader = new FileReader();

                reader.onload = function (event) {
                    avatarPreview.innerHTML = '';

                    const image =
                        document.createElement('img');

                    image.src = event.target.result;
                    image.alt = 'Profile preview';

                    avatarPreview.appendChild(image);
                };

                reader.readAsDataURL(file);
            }
        );
    }


    if (form) {
        form.addEventListener('submit', function (event) {
            const passwordInput =
                document.getElementById('password');

            const confirmInput =
                document.getElementById('confirmPassword');

            if (
                passwordInput &&
                confirmInput &&
                passwordInput.value !== confirmInput.value
            ) {
                event.preventDefault();
                alert(
                    'Password and confirmation password do not match.'
                );
                confirmInput.focus();
            }
        });
    }

    const roleModeInputs = document.querySelectorAll(
        'input[name="role_mode"]'
    );

    const existingRoleFields =
        document.getElementById(
            'existingRoleFields'
        );

    const newRoleFields =
        document.getElementById(
            'newRoleFields'
        );

    const roleSelect =
        document.getElementById('roleId');

    const newRoleNameInput =
        document.getElementById(
            'newRoleName'
        );

    const newRoleCodeInput =
        document.getElementById(
            'newRoleCode'
        );

    let newRoleCodeEdited = false;

    function updateRoleMode() {
        const checked = document.querySelector(
            'input[name="role_mode"]:checked'
        );

        const mode = checked
            ? checked.value
            : 'existing';

        if (existingRoleFields) {
            existingRoleFields.style.display =
                mode === 'existing'
                    ? ''
                    : 'none';
        }

        if (newRoleFields) {
            newRoleFields.style.display =
                mode === 'new'
                    ? ''
                    : 'none';
        }

        if (roleSelect) {
            roleSelect.required =
                mode === 'existing';
        }

        if (newRoleNameInput) {
            newRoleNameInput.required =
                mode === 'new';
        }
    }

    roleModeInputs.forEach(function (input) {
        input.addEventListener(
            'change',
            updateRoleMode
        );
    });

    if (newRoleCodeInput) {
        newRoleCodeInput.addEventListener(
            'input',
            function () {
                newRoleCodeEdited =
                    newRoleCodeInput.value.trim() !== '';
            }
        );
    }

    if (newRoleNameInput) {
        newRoleNameInput.addEventListener(
            'input',
            function () {
                if (
                    newRoleCodeInput &&
                    !newRoleCodeEdited
                ) {
                    newRoleCodeInput.value =
                        newRoleNameInput.value
                            .toLowerCase()
                            .trim()
                            .replace(
                                /[^a-z0-9]+/g,
                                '_'
                            )
                            .replace(
                                /^_+|_+$/g,
                                ''
                            );
                }
            }
        );
    }

    updateRoleMode();

})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
