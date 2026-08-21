<?php
/**
 * FieldPlx Platform - Edit Tenant User
 *
 * File:
 * platform/tenant-user-edit.php
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

$pageTitle = 'Edit Tenant User - FieldPlx';
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

if (!function_exists('tenantUserEditEscape')) {
    function tenantUserEditEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('tenantUserEditPost')) {
    function tenantUserEditPost($key, $default = '')
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

if (!function_exists('tenantUserEditTableExists')) {
    function tenantUserEditTableExists(
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

if (!function_exists('tenantUserEditColumns')) {
    function tenantUserEditColumns(
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

if (!function_exists('tenantUserEditFirstColumn')) {
    function tenantUserEditFirstColumn(
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

if (!function_exists('tenantUserEditBind')) {
    function tenantUserEditBind(
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

if (!function_exists('tenantUserEditRoleCode')) {
    function tenantUserEditRoleCode($value)
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

if (!function_exists('tenantUserEditPasswordError')) {
    function tenantUserEditPasswordError($password)
    {
        if ($password === '') {
            return '';
        }

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

if (!function_exists('tenantUserEditEnumValues')) {
    function tenantUserEditEnumValues($columnType)
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

if (!function_exists('tenantUserEditUploadAvatar')) {
    function tenantUserEditUploadAvatar($fieldName)
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

if (!tenantUserEditTableExists($conn, 'users')) {
    http_response_code(500);
    exit('The users table does not exist.');
}

if (!tenantUserEditTableExists($conn, 'tenants')) {
    http_response_code(500);
    exit('The tenants table does not exist.');
}

$userColumns = tenantUserEditColumns($conn, 'users');
$tenantColumns = tenantUserEditColumns($conn, 'tenants');

/*
|--------------------------------------------------------------------------
| Detect user columns
|--------------------------------------------------------------------------
*/

$userIdColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('id', 'user_id')
);

$userTenantColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('tenant_id')
);

$userFirstNameColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('first_name', 'firstname', 'given_name')
);

$userLastNameColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('last_name', 'lastname', 'surname')
);

$userNameColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('name', 'full_name', 'display_name')
);

$userEmailColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('email', 'email_address')
);

$userPhoneColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('phone', 'mobile', 'phone_number')
);

$userUsernameColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('username', 'user_name')
);

$userPasswordColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('password_hash', 'password', 'passwd')
);

$userStatusColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('status', 'account_status')
);

$userRoleIdColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('role_id')
);

$userRoleCodeColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('role_code', 'user_role', 'role')
);

$userAvatarColumn = tenantUserEditFirstColumn(
    $userColumns,
    array(
        'avatar_path',
        'profile_photo',
        'photo_path',
        'image'
    )
);

$userJobTitleColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('job_title', 'designation', 'position')
);

$userUpdatedAtColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('updated_at')
);

$userDeletedColumn = tenantUserEditFirstColumn(
    $userColumns,
    array('deleted_at')
);

if (
    $userIdColumn === '' ||
    $userTenantColumn === ''
) {
    http_response_code(500);
    exit('The users table requires id and tenant_id columns.');
}

/*
|--------------------------------------------------------------------------
| Detect tenant columns
|--------------------------------------------------------------------------
*/

$tenantIdColumn = tenantUserEditFirstColumn(
    $tenantColumns,
    array('id', 'tenant_id')
);

$tenantNameColumn = tenantUserEditFirstColumn(
    $tenantColumns,
    array(
        'company_name',
        'business_name',
        'tenant_name',
        'name'
    )
);

$tenantCodeColumn = tenantUserEditFirstColumn(
    $tenantColumns,
    array('tenant_code', 'code', 'business_code')
);

$tenantDeletedColumn = tenantUserEditFirstColumn(
    $tenantColumns,
    array('deleted_at')
);

/*
|--------------------------------------------------------------------------
| Detect roles
|--------------------------------------------------------------------------
*/

$hasRolesTable = tenantUserEditTableExists(
    $conn,
    'roles'
);

$roleColumns = array();
$roleIdColumn = '';
$roleNameColumn = '';
$roleCodeColumn = '';
$roleTenantColumn = '';
$roleStatusColumn = '';
$roleDeletedColumn = '';

if ($hasRolesTable) {
    $roleColumns = tenantUserEditColumns($conn, 'roles');

    $roleIdColumn = tenantUserEditFirstColumn(
        $roleColumns,
        array('id', 'role_id')
    );

    $roleNameColumn = tenantUserEditFirstColumn(
        $roleColumns,
        array('name', 'role_name')
    );

    $roleCodeColumn = tenantUserEditFirstColumn(
        $roleColumns,
        array('code', 'role_code')
    );

    $roleTenantColumn = tenantUserEditFirstColumn(
        $roleColumns,
        array('tenant_id')
    );

    $roleStatusColumn = tenantUserEditFirstColumn(
        $roleColumns,
        array('status')
    );

    $roleDeletedColumn = tenantUserEditFirstColumn(
        $roleColumns,
        array('deleted_at')
    );
}

/*
|--------------------------------------------------------------------------
| Load current user
|--------------------------------------------------------------------------
*/

$userId = isset($_GET['id'])
    ? (int) $_GET['id']
    : (
        isset($_POST['user_id'])
            ? (int) $_POST['user_id']
            : 0
    );

if ($userId <= 0) {
    $_SESSION['platform_error_message'] =
        'Invalid tenant user.';

    header('Location: tenant-users.php');
    exit;
}

$userSelect = array(
    "`{$userIdColumn}` AS user_id",
    "`{$userTenantColumn}` AS tenant_id"
);

$userSelect[] = $userFirstNameColumn !== ''
    ? "`{$userFirstNameColumn}` AS first_name"
    : "'' AS first_name";

$userSelect[] = $userLastNameColumn !== ''
    ? "`{$userLastNameColumn}` AS last_name"
    : "'' AS last_name";

$userSelect[] = $userNameColumn !== ''
    ? "`{$userNameColumn}` AS full_name"
    : "'' AS full_name";

$userSelect[] = $userEmailColumn !== ''
    ? "`{$userEmailColumn}` AS email"
    : "'' AS email";

$userSelect[] = $userPhoneColumn !== ''
    ? "`{$userPhoneColumn}` AS phone"
    : "'' AS phone";

$userSelect[] = $userUsernameColumn !== ''
    ? "`{$userUsernameColumn}` AS username"
    : "'' AS username";

$userSelect[] = $userStatusColumn !== ''
    ? "`{$userStatusColumn}` AS user_status"
    : "'active' AS user_status";

$userSelect[] = $userRoleIdColumn !== ''
    ? "`{$userRoleIdColumn}` AS role_id"
    : "0 AS role_id";

$userSelect[] = $userRoleCodeColumn !== ''
    ? "`{$userRoleCodeColumn}` AS role_code"
    : "'' AS role_code";

$userSelect[] = $userAvatarColumn !== ''
    ? "`{$userAvatarColumn}` AS avatar_path"
    : "'' AS avatar_path";

$userSelect[] = $userJobTitleColumn !== ''
    ? "`{$userJobTitleColumn}` AS job_title"
    : "'' AS job_title";

$userSql = "
    SELECT
        " . implode(",\n        ", $userSelect) . "
    FROM users
    WHERE `{$userIdColumn}` = ?
";

if ($userDeletedColumn !== '') {
    $userSql .= "
        AND `{$userDeletedColumn}` IS NULL
    ";
}

$userSql .= " LIMIT 1";

$userStmt = $conn->prepare($userSql);
$userStmt->bind_param('i', $userId);
$userStmt->execute();

$userResult = $userStmt->get_result();
$currentUser = $userResult->fetch_assoc();

$userStmt->close();

if (!$currentUser) {
    $_SESSION['platform_error_message'] =
        'Tenant user not found.';

    header('Location: tenant-users.php');
    exit;
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

while ($tenantRow = $tenantResult->fetch_assoc()) {
    $tenantList[] = $tenantRow;
}

$tenantResult->free();

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$errorMessage = '';

$tenantId = isset($_POST['tenant_id'])
    ? (int) $_POST['tenant_id']
    : (int) $currentUser['tenant_id'];

$firstName = isset($_POST['first_name'])
    ? tenantUserEditPost('first_name')
    : (string) $currentUser['first_name'];

$lastName = isset($_POST['last_name'])
    ? tenantUserEditPost('last_name')
    : (string) $currentUser['last_name'];

$email = isset($_POST['email'])
    ? strtolower(tenantUserEditPost('email'))
    : strtolower((string) $currentUser['email']);

$phone = isset($_POST['phone'])
    ? tenantUserEditPost('phone')
    : (string) $currentUser['phone'];

$username = isset($_POST['username'])
    ? tenantUserEditPost('username')
    : (string) $currentUser['username'];

$jobTitle = isset($_POST['job_title'])
    ? tenantUserEditPost('job_title')
    : (string) $currentUser['job_title'];

$status = isset($_POST['status'])
    ? strtolower(tenantUserEditPost('status'))
    : strtolower((string) $currentUser['user_status']);

$roleId = isset($_POST['role_id'])
    ? (int) $_POST['role_id']
    : (int) $currentUser['role_id'];

$roleCodeValue = isset($_POST['role_code'])
    ? tenantUserEditPost('role_code')
    : (string) $currentUser['role_code'];

$roleMode = tenantUserEditPost(
    'role_mode',
    'existing'
);

$newRoleName = tenantUserEditPost('new_role_name');
$newRoleCode = tenantUserEditPost('new_role_code');

$password = isset($_POST['password']) &&
    !is_array($_POST['password'])
        ? (string) $_POST['password']
        : '';

$confirmPassword = isset($_POST['confirm_password']) &&
    !is_array($_POST['confirm_password'])
        ? (string) $_POST['confirm_password']
        : '';

$removeAvatar = !empty($_POST['remove_avatar'])
    ? 1
    : 0;

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

    $enumValues = tenantUserEditEnumValues(
        $columnType
    );

    if (!empty($enumValues)) {
        $statusOptions = $enumValues;
    }
}

/*
|--------------------------------------------------------------------------
| Load roles
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
    $roleTypes = '';
    $roleParams = array();

    if ($roleTenantColumn !== '' && $tenantId > 0) {
        $roleWhere[] = "
            (
                `{$roleTenantColumn}` = ?
                OR `{$roleTenantColumn}` IS NULL
            )
        ";

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

    $roleSql .= "
        ORDER BY role_name ASC
    ";

    $roleStmt = $conn->prepare($roleSql);

    tenantUserEditBind(
        $roleStmt,
        $roleTypes,
        $roleParams
    );

    $roleStmt->execute();

    $roleResult = $roleStmt->get_result();

    while ($roleRow = $roleResult->fetch_assoc()) {
        $roles[] = $roleRow;
    }

    $roleStmt->close();
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

    $tenantExists = false;

    foreach ($tenantList as $tenantRow) {
        if ((int) $tenantRow['tenant_id'] === $tenantId) {
            $tenantExists = true;
            break;
        }
    }

    if (!$tenantExists) {
        $errorMessage = 'Select a valid tenant.';
    } elseif ($firstName === '') {
        $errorMessage = 'Enter the first name.';
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
    } elseif (
        $userRoleIdColumn !== '' &&
        $roleMode === 'existing' &&
        $roleId <= 0
    ) {
        $errorMessage = 'Select a valid user role.';
    } elseif (
        $userRoleIdColumn !== '' &&
        $roleMode === 'new' &&
        $newRoleName === ''
    ) {
        $errorMessage = 'Enter the new role name.';
    } else {
        $passwordError =
            tenantUserEditPasswordError($password);

        if ($passwordError !== '') {
            $errorMessage = $passwordError;
        } elseif (
            $password !== '' &&
            $password !== $confirmPassword
        ) {
            $errorMessage =
                'Password and confirmation password do not match.';
        }
    }

    if (
        $errorMessage === '' &&
        $userEmailColumn !== ''
    ) {
        $duplicateSql = "
            SELECT COUNT(*) AS total
            FROM users
            WHERE LOWER(`{$userEmailColumn}`) =
                  LOWER(?)
              AND `{$userIdColumn}` <> ?
        ";

        $duplicateParams = array(
            $email,
            $userId
        );

        $duplicateStmt = $conn->prepare(
            $duplicateSql
        );

        tenantUserEditBind(
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
                'Another user already uses this email address.';
        }
    }

    if (
        $errorMessage === '' &&
        $userUsernameColumn !== '' &&
        $username !== ''
    ) {
        $duplicateSql = "
            SELECT COUNT(*) AS total
            FROM users
            WHERE LOWER(`{$userUsernameColumn}`) =
                  LOWER(?)
              AND `{$userIdColumn}` <> ?
        ";

        $duplicateParams = array(
            $username,
            $userId
        );

        $duplicateStmt = $conn->prepare(
            $duplicateSql
        );

        tenantUserEditBind(
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
                'Another user already uses this username.';
        }
    }

    $uploadedAvatarPath = '';

    if (
        $errorMessage === '' &&
        $userAvatarColumn !== ''
    ) {
        $avatarUpload =
            tenantUserEditUploadAvatar('avatar');

        if (!$avatarUpload['success']) {
            $errorMessage =
                $avatarUpload['message'];
        } else {
            $uploadedAvatarPath =
                $avatarUpload['path'];
        }
    }

    if ($errorMessage === '') {
        try {
            $conn->begin_transaction();

            if (
                $userRoleIdColumn !== '' &&
                $roleMode === 'existing'
            ) {
                $roleCheckSql = "
                    SELECT
                        `{$roleIdColumn}` AS role_id
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

                if ($roleDeletedColumn !== '') {
                    $roleCheckSql .= "
                        AND `{$roleDeletedColumn}` IS NULL
                    ";
                }

                $roleCheckSql .= " LIMIT 1";

                $roleCheckStmt = $conn->prepare(
                    $roleCheckSql
                );

                tenantUserEditBind(
                    $roleCheckStmt,
                    $roleCheckTypes,
                    $roleCheckParams
                );

                $roleCheckStmt->execute();

                $validRole =
                    $roleCheckStmt
                        ->get_result()
                        ->fetch_assoc();

                $roleCheckStmt->close();

                if (!$validRole) {
                    throw new Exception(
                        'The selected role is invalid for this tenant.'
                    );
                }
            }

            if (
                $userRoleIdColumn !== '' &&
                $roleMode === 'new'
            ) {
                $newRoleCode =
                    tenantUserEditRoleCode(
                        $newRoleCode !== ''
                            ? $newRoleCode
                            : $newRoleName
                    );

                if ($newRoleCode === '') {
                    throw new Exception(
                        'Enter a valid new role code.'
                    );
                }

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

                tenantUserEditBind(
                    $duplicateRoleStmt,
                    $duplicateRoleTypes,
                    $duplicateRoleParams
                );

                $duplicateRoleStmt->execute();

                $duplicateRoleRow =
                    $duplicateRoleStmt
                        ->get_result()
                        ->fetch_assoc();

                $duplicateRoleStmt->close();

                if (!empty(
                    $duplicateRoleRow['total']
                )) {
                    throw new Exception(
                        'This role code already exists for the selected tenant.'
                    );
                }

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

                $roleInsertColumns = array();
                $roleInsertPlaceholders = array();
                $roleInsertValues = array();
                $roleInsertTypes = '';

                foreach (
                    $newRoleData as
                    $column => $value
                ) {
                    $roleInsertColumns[] =
                        "`{$column}`";
                    $roleInsertPlaceholders[] = '?';
                    $roleInsertValues[] = $value;

                    $columnType = isset(
                        $roleColumns[$column]['Type']
                    )
                        ? strtolower(
                            (string)
                            $roleColumns[$column]['Type']
                        )
                        : '';

                    if (
                        preg_match(
                            '/^(tinyint|smallint|mediumint|int|bigint)/',
                            $columnType
                        )
                    ) {
                        $roleInsertTypes .= 'i';
                    } else {
                        $roleInsertTypes .= 's';
                    }
                }

                if (isset($roleColumns['created_at'])) {
                    $roleInsertColumns[] =
                        "`created_at`";
                    $roleInsertPlaceholders[] =
                        'NOW()';
                }

                if (isset($roleColumns['updated_at'])) {
                    $roleInsertColumns[] =
                        "`updated_at`";
                    $roleInsertPlaceholders[] =
                        'NOW()';
                }

                $roleInsertSql = "
                    INSERT INTO roles (
                        " .
                        implode(', ', $roleInsertColumns) .
                        "
                    ) VALUES (
                        " .
                        implode(
                            ', ',
                            $roleInsertPlaceholders
                        ) .
                        "
                    )
                ";

                $roleInsertStmt =
                    $conn->prepare($roleInsertSql);

                tenantUserEditBind(
                    $roleInsertStmt,
                    $roleInsertTypes,
                    $roleInsertValues
                );

                $roleInsertStmt->execute();

                $roleId = (int)
                    $roleInsertStmt->insert_id;

                $roleInsertStmt->close();
            }

            $updateData = array();

            $updateData[$userTenantColumn] =
                $tenantId;

            if ($userFirstNameColumn !== '') {
                $updateData[$userFirstNameColumn] =
                    $firstName;
            }

            if ($userLastNameColumn !== '') {
                $updateData[$userLastNameColumn] =
                    $lastName;
            }

            if ($userNameColumn !== '') {
                $updateData[$userNameColumn] =
                    trim($firstName . ' ' . $lastName);
            }

            if ($userEmailColumn !== '') {
                $updateData[$userEmailColumn] =
                    $email;
            }

            if ($userPhoneColumn !== '') {
                $updateData[$userPhoneColumn] =
                    $phone;
            }

            if ($userUsernameColumn !== '') {
                $updateData[$userUsernameColumn] =
                    $username;
            }

            if ($userStatusColumn !== '') {
                $updateData[$userStatusColumn] =
                    $status;
            }

            if ($userRoleIdColumn !== '') {
                $updateData[$userRoleIdColumn] =
                    $roleId;
            } elseif ($userRoleCodeColumn !== '') {
                $updateData[$userRoleCodeColumn] =
                    $roleCodeValue;
            }

            if ($userJobTitleColumn !== '') {
                $updateData[$userJobTitleColumn] =
                    $jobTitle;
            }

            if (
                $userPasswordColumn !== '' &&
                $password !== ''
            ) {
                $updateData[$userPasswordColumn] =
                    password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );
            }

            $oldAvatarPath =
                (string) $currentUser['avatar_path'];

            if ($userAvatarColumn !== '') {
                if ($uploadedAvatarPath !== '') {
                    $updateData[$userAvatarColumn] =
                        $uploadedAvatarPath;
                } elseif ($removeAvatar === 1) {
                    $updateData[$userAvatarColumn] =
                        '';
                }
            }

            $setParts = array();
            $updateValues = array();
            $updateTypes = '';

            foreach (
                $updateData as
                $column => $value
            ) {
                $setParts[] = "`{$column}` = ?";
                $updateValues[] = $value;

                $columnType = isset(
                    $userColumns[$column]['Type']
                )
                    ? strtolower(
                        (string)
                        $userColumns[$column]['Type']
                    )
                    : '';

                if (
                    preg_match(
                        '/^(tinyint|smallint|mediumint|int|bigint)/',
                        $columnType
                    )
                ) {
                    $updateTypes .= 'i';
                } else {
                    $updateTypes .= 's';
                }
            }

            if ($userUpdatedAtColumn !== '') {
                $setParts[] =
                    "`{$userUpdatedAtColumn}` = NOW()";
            }

            $updateValues[] = $userId;
            $updateTypes .= 'i';

            $updateSql = "
                UPDATE users
                SET " . implode(', ', $setParts) . "
                WHERE `{$userIdColumn}` = ?
                LIMIT 1
            ";

            $updateStmt = $conn->prepare($updateSql);

            tenantUserEditBind(
                $updateStmt,
                $updateTypes,
                $updateValues
            );

            $updateStmt->execute();
            $updateStmt->close();

            $conn->commit();

            if (
                $userAvatarColumn !== '' &&
                (
                    $uploadedAvatarPath !== '' ||
                    $removeAvatar === 1
                ) &&
                $oldAvatarPath !== '' &&
                file_exists(
                    __DIR__ .
                    '/../' .
                    ltrim($oldAvatarPath, '/')
                )
            ) {
                @unlink(
                    __DIR__ .
                    '/../' .
                    ltrim($oldAvatarPath, '/')
                );
            }

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Tenant user updated successfully.';

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
                'Tenant user update failed: ' .
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
    .tenant-user-edit-page {
        max-width: 1050px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .tenant-user-edit-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .tenant-user-edit-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .tenant-user-edit-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .tenant-user-edit-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .tenant-user-edit-back {
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

    .tenant-user-edit-back:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .tenant-user-edit-alert {
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

    .tenant-user-edit-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(260px, 315px);
        gap: 15px;
        align-items: start;
    }

    .tenant-user-edit-main,
    .tenant-user-edit-side {
        display: grid;
        gap: 15px;
    }

    .tenant-user-edit-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .tenant-user-edit-card-header {
        min-height: 53px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .tenant-user-edit-card-icon {
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

    .tenant-user-edit-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .tenant-user-edit-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-user-edit-card-body {
        padding: 15px;
    }

    .tenant-user-edit-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .tenant-user-edit-required {
        color: #dc2626;
    }

    .tenant-user-edit-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    .tenant-user-edit-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .tenant-user-edit-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .tenant-user-edit-avatar {
        width: 78px;
        height: 78px;
        margin: 0 auto 10px;
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
        font-size: 20px;
        font-weight: 800;
    }

    .tenant-user-edit-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .tenant-user-edit-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .tenant-user-edit-submit {
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

    .tenant-user-edit-cancel {
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
        .tenant-user-edit-layout {
            grid-template-columns: 1fr;
        }

        .tenant-user-edit-side {
            order: -1;
        }
    }

    @media (max-width: 600px) {
        .tenant-user-edit-header {
            align-items: flex-start;
            flex-direction: column;
        }
    }
</style>

<div class="tenant-user-edit-page">

    <div class="tenant-user-edit-header">
        <div>
            <h2 class="tenant-user-edit-title">
                Edit Tenant User
            </h2>

            <div class="tenant-user-edit-description">
                Update user details, role, status, and login credentials.
            </div>
        </div>

        <div class="tenant-user-edit-actions">
            <a
                href="roles.php?tenant_id=<?= (int) $tenantId; ?>"
                class="tenant-user-edit-back"
            >
                <i class="bi bi-shield-check"></i>
                Manage Roles
            </a>

            <a
                href="tenant-users.php?tenant_id=<?= (int) $tenantId; ?>"
                class="tenant-user-edit-back"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Users
            </a>
        </div>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="tenant-user-edit-alert">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= tenantUserEditEscape($errorMessage); ?></span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        enctype="multipart/form-data"
        id="tenantUserEditForm"
    >
        <?php csrfField(); ?>

        <input
            type="hidden"
            name="user_id"
            value="<?= (int) $userId; ?>"
        >

        <div class="tenant-user-edit-layout">

            <div class="tenant-user-edit-main">

                <section class="tenant-user-edit-card">
                    <div class="tenant-user-edit-card-header">
                        <span class="tenant-user-edit-card-icon">
                            <i class="bi bi-person"></i>
                        </span>

                        <div>
                            <h3 class="tenant-user-edit-card-title">
                                User Information
                            </h3>

                            <div class="tenant-user-edit-card-subtitle">
                                Update tenant and personal details
                            </div>
                        </div>
                    </div>

                    <div class="tenant-user-edit-card-body">
                        <div class="row g-3">

                            <div class="col-12">
                                <label
                                    class="tenant-user-edit-label"
                                    for="tenantId"
                                >
                                    Tenant
                                    <span class="tenant-user-edit-required">*</span>
                                </label>

                                <select
                                    class="form-select tenant-user-edit-control"
                                    id="tenantId"
                                    name="tenant_id"
                                    required
                                >
                                    <?php foreach (
                                        $tenantList as $tenantRow
                                    ): ?>
                                        <option
                                            value="<?= (int)
                                                $tenantRow['tenant_id']; ?>"
                                            <?= $tenantId ===
                                                (int)
                                                $tenantRow['tenant_id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= tenantUserEditEscape(
                                                $tenantRow['tenant_name']
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="tenant-user-edit-label"
                                    for="firstName"
                                >
                                    First Name
                                    <span class="tenant-user-edit-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    class="form-control tenant-user-edit-control"
                                    id="firstName"
                                    name="first_name"
                                    value="<?= tenantUserEditEscape($firstName); ?>"
                                    maxlength="120"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="tenant-user-edit-label"
                                    for="lastName"
                                >
                                    Last Name
                                </label>

                                <input
                                    type="text"
                                    class="form-control tenant-user-edit-control"
                                    id="lastName"
                                    name="last_name"
                                    value="<?= tenantUserEditEscape($lastName); ?>"
                                    maxlength="120"
                                >
                            </div>

                            <?php if ($userEmailColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-user-edit-label"
                                        for="email"
                                    >
                                        Email Address
                                        <span class="tenant-user-edit-required">*</span>
                                    </label>

                                    <input
                                        type="email"
                                        class="form-control tenant-user-edit-control"
                                        id="email"
                                        name="email"
                                        value="<?= tenantUserEditEscape($email); ?>"
                                        maxlength="190"
                                        required
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($userPhoneColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-user-edit-label"
                                        for="phone"
                                    >
                                        Phone Number
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-user-edit-control"
                                        id="phone"
                                        name="phone"
                                        value="<?= tenantUserEditEscape($phone); ?>"
                                        maxlength="50"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($userUsernameColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-user-edit-label"
                                        for="username"
                                    >
                                        Username
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-user-edit-control"
                                        id="username"
                                        name="username"
                                        value="<?= tenantUserEditEscape($username); ?>"
                                        maxlength="120"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($userJobTitleColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        class="tenant-user-edit-label"
                                        for="jobTitle"
                                    >
                                        Job Title
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-user-edit-control"
                                        id="jobTitle"
                                        name="job_title"
                                        value="<?= tenantUserEditEscape($jobTitle); ?>"
                                        maxlength="150"
                                    >
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </section>

                <section class="tenant-user-edit-card">
                    <div class="tenant-user-edit-card-header">
                        <span class="tenant-user-edit-card-icon">
                            <i class="bi bi-lock"></i>
                        </span>

                        <div>
                            <h3 class="tenant-user-edit-card-title">
                                Password Reset
                            </h3>

                            <div class="tenant-user-edit-card-subtitle">
                                Leave blank to keep the current password
                            </div>
                        </div>
                    </div>

                    <div class="tenant-user-edit-card-body">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label
                                    class="tenant-user-edit-label"
                                    for="password"
                                >
                                    New Password
                                </label>

                                <input
                                    type="password"
                                    class="form-control tenant-user-edit-control"
                                    id="password"
                                    name="password"
                                    minlength="8"
                                    autocomplete="new-password"
                                >
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="tenant-user-edit-label"
                                    for="confirmPassword"
                                >
                                    Confirm Password
                                </label>

                                <input
                                    type="password"
                                    class="form-control tenant-user-edit-control"
                                    id="confirmPassword"
                                    name="confirm_password"
                                    minlength="8"
                                    autocomplete="new-password"
                                >
                            </div>

                        </div>
                    </div>
                </section>

            </div>

            <aside class="tenant-user-edit-side">

                <?php if ($userAvatarColumn !== ''): ?>
                    <section class="tenant-user-edit-card">
                        <div class="tenant-user-edit-card-header">
                            <span class="tenant-user-edit-card-icon">
                                <i class="bi bi-image"></i>
                            </span>

                            <div>
                                <h3 class="tenant-user-edit-card-title">
                                    Profile Image
                                </h3>

                                <div class="tenant-user-edit-card-subtitle">
                                    Replace or remove the current image
                                </div>
                            </div>
                        </div>

                        <div class="tenant-user-edit-card-body text-center">
                            <div
                                class="tenant-user-edit-avatar"
                                id="avatarPreview"
                            >
                                <?php if (
                                    !empty($currentUser['avatar_path'])
                                ): ?>
                                    <img
                                        src="../<?= tenantUserEditEscape(
                                            ltrim(
                                                $currentUser['avatar_path'],
                                                '/'
                                            )
                                        ); ?>"
                                        alt=""
                                    >
                                <?php else: ?>
                                    <?= tenantUserEditEscape(
                                        strtoupper(
                                            substr($firstName, 0, 1) .
                                            substr($lastName, 0, 1)
                                        )
                                    ); ?>
                                <?php endif; ?>
                            </div>

                            <input
                                type="file"
                                name="avatar"
                                id="avatar"
                                class="form-control tenant-user-edit-control"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                            >

                            <?php if (
                                !empty($currentUser['avatar_path'])
                            ): ?>
                                <label
                                    class="mt-3"
                                    style="font-size:9px;color:#6b7280;"
                                >
                                    <input
                                        type="checkbox"
                                        name="remove_avatar"
                                        value="1"
                                    >
                                    Remove current image
                                </label>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="tenant-user-edit-card">
                    <div class="tenant-user-edit-card-header">
                        <span class="tenant-user-edit-card-icon">
                            <i class="bi bi-shield-check"></i>
                        </span>

                        <div>
                            <h3 class="tenant-user-edit-card-title">
                                Access Settings
                            </h3>

                            <div class="tenant-user-edit-card-subtitle">
                                Role and account status
                            </div>
                        </div>
                    </div>

                    <div class="tenant-user-edit-card-body">
                        <div class="row g-3">

                            <?php if ($userRoleIdColumn !== ''): ?>
                                <div class="col-12">
                                    <label class="tenant-user-edit-label">
                                        Role Option
                                    </label>

                                    <div
                                        style="display:grid;grid-template-columns:1fr 1fr;gap:7px;"
                                    >
                                        <label
                                            style="padding:9px;border:1px solid #e5e7eb;border-radius:8px;font-size:9px;"
                                        >
                                            <input
                                                type="radio"
                                                name="role_mode"
                                                value="existing"
                                                <?= $roleMode === 'existing'
                                                    ? 'checked'
                                                    : ''; ?>
                                            >
                                            Existing
                                        </label>

                                        <label
                                            style="padding:9px;border:1px solid #e5e7eb;border-radius:8px;font-size:9px;"
                                        >
                                            <input
                                                type="radio"
                                                name="role_mode"
                                                value="new"
                                                <?= $roleMode === 'new'
                                                    ? 'checked'
                                                    : ''; ?>
                                            >
                                            Add New
                                        </label>
                                    </div>
                                </div>

                                <div
                                    class="col-12"
                                    id="existingRoleFields"
                                >
                                    <label
                                        class="tenant-user-edit-label"
                                        for="roleId"
                                    >
                                        Select Role
                                    </label>

                                    <select
                                        class="form-select tenant-user-edit-control"
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
                                                    $roleRow['role_id']; ?>"
                                                <?= $roleId ===
                                                    (int)
                                                    $roleRow['role_id']
                                                        ? 'selected'
                                                        : ''; ?>
                                            >
                                                <?= tenantUserEditEscape(
                                                    $roleRow['role_name']
                                                ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div
                                    class="col-12"
                                    id="newRoleFields"
                                >
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label
                                                class="tenant-user-edit-label"
                                                for="newRoleName"
                                            >
                                                New Role Name
                                            </label>

                                            <input
                                                type="text"
                                                class="form-control tenant-user-edit-control"
                                                id="newRoleName"
                                                name="new_role_name"
                                                value="<?= tenantUserEditEscape(
                                                    $newRoleName
                                                ); ?>"
                                                maxlength="150"
                                            >
                                        </div>

                                        <div class="col-12">
                                            <label
                                                class="tenant-user-edit-label"
                                                for="newRoleCode"
                                            >
                                                New Role Code
                                            </label>

                                            <input
                                                type="text"
                                                class="form-control tenant-user-edit-control"
                                                id="newRoleCode"
                                                name="new_role_code"
                                                value="<?= tenantUserEditEscape(
                                                    $newRoleCode
                                                ); ?>"
                                                maxlength="150"
                                            >
                                        </div>
                                    </div>
                                </div>
                            <?php elseif (
                                $userRoleCodeColumn !== ''
                            ): ?>
                                <div class="col-12">
                                    <label
                                        class="tenant-user-edit-label"
                                        for="roleCode"
                                    >
                                        Role Code
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control tenant-user-edit-control"
                                        id="roleCode"
                                        name="role_code"
                                        value="<?= tenantUserEditEscape(
                                            $roleCodeValue
                                        ); ?>"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if ($userStatusColumn !== ''): ?>
                                <div class="col-12">
                                    <label
                                        class="tenant-user-edit-label"
                                        for="status"
                                    >
                                        Status
                                    </label>

                                    <select
                                        class="form-select tenant-user-edit-control"
                                        id="status"
                                        name="status"
                                    >
                                        <?php foreach (
                                            $statusOptions as $statusOption
                                        ): ?>
                                            <option
                                                value="<?= tenantUserEditEscape(
                                                    $statusOption
                                                ); ?>"
                                                <?= $status === $statusOption
                                                    ? 'selected'
                                                    : ''; ?>
                                            >
                                                <?= tenantUserEditEscape(
                                                    ucwords(
                                                        str_replace(
                                                            array('_', '-'),
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

                <div class="tenant-user-edit-submit-card">
                    <button
                        type="submit"
                        class="tenant-user-edit-submit"
                    >
                        <i class="bi bi-check2-circle"></i>
                        Update Tenant User
                    </button>

                    <a
                        href="tenant-users.php?tenant_id=<?= (int) $tenantId; ?>"
                        class="tenant-user-edit-cancel"
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
        document.getElementById('newRoleName');

    const newRoleCodeInput =
        document.getElementById('newRoleCode');

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

    let roleCodeEdited = false;

    if (newRoleCodeInput) {
        newRoleCodeInput.addEventListener(
            'input',
            function () {
                roleCodeEdited =
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
                    !roleCodeEdited
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

    const avatarInput =
        document.getElementById('avatar');

    const avatarPreview =
        document.getElementById(
            'avatarPreview'
        );

    if (
        avatarInput &&
        avatarPreview
    ) {
        avatarInput.addEventListener(
            'change',
            function () {
                const file =
                    avatarInput.files[0];

                if (!file) {
                    return;
                }

                const reader = new FileReader();

                reader.onload = function (event) {
                    avatarPreview.innerHTML = '';

                    const image =
                        document.createElement('img');

                    image.src = event.target.result;
                    image.alt = '';

                    avatarPreview.appendChild(image);
                };

                reader.readAsDataURL(file);
            }
        );
    }

    const form =
        document.getElementById(
            'tenantUserEditForm'
        );

    if (form) {
        form.addEventListener(
            'submit',
            function (event) {
                const password =
                    document.getElementById(
                        'password'
                    );

                const confirmPassword =
                    document.getElementById(
                        'confirmPassword'
                    );

                if (
                    password &&
                    confirmPassword &&
                    password.value !==
                        confirmPassword.value
                ) {
                    event.preventDefault();

                    alert(
                        'Password and confirmation password do not match.'
                    );

                    confirmPassword.focus();
                }
            }
        );
    }

    updateRoleMode();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
