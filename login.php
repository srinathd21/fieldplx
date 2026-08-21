<?php

// Start the session before any output. The login flow, CSRF token,
// flash errors, and authenticated tenant state all depend on it.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * FieldPlx Main Tenant Login
 *
 * Correct location:
 * /public_html/login.php
 *
 * Folder structure used:
 * /public_html/includes/db.php
 * /public_html/includes/csrf.php
 *
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';

/*
|--------------------------------------------------------------------------
| Basic helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('loginE')) {
    function loginE($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('loginPost')) {
    function loginPost($key, $default = '')
    {
        if (
            !isset($_POST[$key]) ||
            is_array($_POST[$key])
        ) {
            return (string) $default;
        }

        return trim(
            (string) $_POST[$key]
        );
    }
}

if (!function_exists('loginSafeRedirect')) {
    function loginSafeRedirect($url)
    {
        $url = trim((string) $url);

        if (
            $url === '' ||
            preg_match('/^(https?:)?\/\//i', $url) ||
            preg_match('/^(javascript|data):/i', $url) ||
            strpos($url, "\r") !== false ||
            strpos($url, "\n") !== false
        ) {
            return 'dashboard.php';
        }

        return ltrim($url, '/');
    }
}

if (!function_exists('loginTableExists')) {
    function loginTableExists(mysqli $conn, $tableName)
    {
        $escaped = $conn->real_escape_string(
            (string) $tableName
        );

        $result = $conn->query(
            "SHOW TABLES LIKE '{$escaped}'"
        );

        return $result &&
            $result->num_rows > 0;
    }
}

if (!function_exists('loginColumns')) {
    function loginColumns(mysqli $conn, $tableName)
    {
        static $cache = array();

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $cache[$tableName] = array();

        if (
            !loginTableExists(
                $conn,
                $tableName
            )
        ) {
            return $cache[$tableName];
        }

        $safeTable = str_replace(
            '`',
            '``',
            $tableName
        );

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$safeTable}`"
        );

        if (!$result) {
            return $cache[$tableName];
        }

        while (
            $row = $result->fetch_assoc()
        ) {
            if (!empty($row['Field'])) {
                $cache[$tableName][
                    (string) $row['Field']
                ] = true;
            }
        }

        $result->free();

        return $cache[$tableName];
    }
}

if (!function_exists('loginFindColumn')) {
    function loginFindColumn(
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

if (!function_exists('loginBind')) {
    function loginBind(
        mysqli_stmt $stmt,
        $types,
        array &$params
    ) {
        if ($types === '') {
            return true;
        }

        $arguments = array($types);

        foreach ($params as $key => $value) {
            $arguments[] = &$params[$key];
        }

        return call_user_func_array(
            array($stmt, 'bind_param'),
            $arguments
        );
    }
}

if (!function_exists('loginFetchAssoc')) {
    function loginFetchAssoc(mysqli_stmt $stmt)
    {
        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result) {
                return $result->fetch_assoc();
            }
        }

        $metadata = $stmt->result_metadata();

        if (!$metadata) {
            return null;
        }

        $row = array();
        $bind = array();

        while (
            $field = $metadata->fetch_field()
        ) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

        if (!$stmt->fetch()) {
            return null;
        }

        $resultRow = array();

        foreach ($row as $key => $value) {
            $resultRow[$key] = $value;
        }

        return $resultRow;
    }
}

/*
|--------------------------------------------------------------------------
| Redirect logged-in users
|--------------------------------------------------------------------------
*/

if (
    !empty($_SESSION['user_id']) &&
    !empty($_SESSION['tenant_id'])
) {
    header(
        'Location: dashboard.php',
        true,
        302
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Flash values
|--------------------------------------------------------------------------
*/

$errorMessage = isset($_SESSION['login_error'])
    ? (string) $_SESSION['login_error']
    : '';

$identifier = isset($_SESSION['login_identifier'])
    ? (string) $_SESSION['login_identifier']
    : '';

$workspace = isset($_SESSION['login_workspace'])
    ? (string) $_SESSION['login_workspace']
    : '';

$rememberMe = !empty(
    $_SESSION['login_remember']
);

unset(
    $_SESSION['login_error'],
    $_SESSION['login_identifier'],
    $_SESSION['login_workspace'],
    $_SESSION['login_remember']
);

$redirectUrl = loginSafeRedirect(
    isset($_POST['redirect'])
        ? $_POST['redirect']
        : (
            isset($_GET['redirect'])
                ? $_GET['redirect']
                : 'dashboard.php'
        )
);

/*
|--------------------------------------------------------------------------
| Process login
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = loginPost('identifier');
    $password = loginPost('password');
    $workspace = loginPost('workspace');
    $rememberMe = isset(
        $_POST['remember_me']
    );

    if (
        !isValidCsrfToken(
            loginPost('csrf_token')
        )
    ) {
        $errorMessage =
            'Your security token expired. Refresh the page and try again.';
    } elseif (
        $identifier === '' ||
        $password === ''
    ) {
        $errorMessage =
            'Enter your email or username and password.';
    } elseif (
        !loginTableExists($conn, 'users') ||
        !loginTableExists($conn, 'tenants')
    ) {
        $errorMessage =
            'Required users or tenants table is missing.';
    } else {
        try {
            $userColumns =
                loginColumns(
                    $conn,
                    'users'
                );

            $tenantColumns =
                loginColumns(
                    $conn,
                    'tenants'
                );

            $roleColumns =
                loginColumns(
                    $conn,
                    'roles'
                );

            $userIdColumn =
                loginFindColumn(
                    $userColumns,
                    array('id', 'user_id')
                );

            $userTenantColumn =
                loginFindColumn(
                    $userColumns,
                    array(
                        'tenant_id',
                        'business_id'
                    )
                );

            $userRoleColumn =
                loginFindColumn(
                    $userColumns,
                    array('role_id')
                );

            $userEmailColumn =
                loginFindColumn(
                    $userColumns,
                    array(
                        'email',
                        'email_address'
                    )
                );

            $userUsernameColumn =
                loginFindColumn(
                    $userColumns,
                    array(
                        'username',
                        'user_name',
                        'login_name'
                    )
                );

            $userPasswordColumn =
                loginFindColumn(
                    $userColumns,
                    array(
                        'password_hash',
                        'password',
                        'user_password'
                    )
                );

            $userFirstNameColumn =
                loginFindColumn(
                    $userColumns,
                    array(
                        'first_name',
                        'firstname',
                        'name'
                    )
                );

            $userLastNameColumn =
                loginFindColumn(
                    $userColumns,
                    array(
                        'last_name',
                        'lastname'
                    )
                );

            $userPhoneColumn =
                loginFindColumn(
                    $userColumns,
                    array(
                        'phone',
                        'mobile',
                        'phone_number'
                    )
                );

            $userStatusColumn =
                loginFindColumn(
                    $userColumns,
                    array(
                        'status',
                        'is_active'
                    )
                );

            $userDeletedColumn =
                loginFindColumn(
                    $userColumns,
                    array('deleted_at')
                );

            $tenantIdColumn =
                loginFindColumn(
                    $tenantColumns,
                    array(
                        'id',
                        'tenant_id',
                        'business_id'
                    )
                );

            $tenantNameColumn =
                loginFindColumn(
                    $tenantColumns,
                    array(
                        'company_name',
                        'business_name',
                        'name'
                    )
                );

            $tenantSlugColumn =
                loginFindColumn(
                    $tenantColumns,
                    array(
                        'slug',
                        'company_slug',
                        'business_slug'
                    )
                );

            $tenantLogoColumn =
                loginFindColumn(
                    $tenantColumns,
                    array(
                        'logo_path',
                        'company_logo',
                        'logo'
                    )
                );

            $tenantTimezoneColumn =
                loginFindColumn(
                    $tenantColumns,
                    array('timezone')
                );

            $tenantCurrencyColumn =
                loginFindColumn(
                    $tenantColumns,
                    array(
                        'currency_code',
                        'currency'
                    )
                );

            $tenantDateFormatColumn =
                loginFindColumn(
                    $tenantColumns,
                    array('date_format')
                );

            $tenantStatusColumn =
                loginFindColumn(
                    $tenantColumns,
                    array(
                        'status',
                        'is_active'
                    )
                );

            $tenantTrialColumn =
                loginFindColumn(
                    $tenantColumns,
                    array(
                        'trial_ends_at',
                        'trial_end_date'
                    )
                );

            $tenantPlanColumn =
                loginFindColumn(
                    $tenantColumns,
                    array(
                        'subscription_plan',
                        'plan_code'
                    )
                );

            $tenantDeletedColumn =
                loginFindColumn(
                    $tenantColumns,
                    array('deleted_at')
                );

            $roleIdColumn =
                loginFindColumn(
                    $roleColumns,
                    array('id', 'role_id')
                );

            $roleTenantColumn =
                loginFindColumn(
                    $roleColumns,
                    array(
                        'tenant_id',
                        'business_id'
                    )
                );

            $roleNameColumn =
                loginFindColumn(
                    $roleColumns,
                    array(
                        'name',
                        'role_name'
                    )
                );

            $roleCodeColumn =
                loginFindColumn(
                    $roleColumns,
                    array(
                        'code',
                        'role_code'
                    )
                );

            $roleStatusColumn =
                loginFindColumn(
                    $roleColumns,
                    array(
                        'is_active',
                        'status'
                    )
                );

            if (
                $userIdColumn === '' ||
                $userTenantColumn === '' ||
                $userPasswordColumn === '' ||
                $tenantIdColumn === '' ||
                $tenantNameColumn === '' ||
                (
                    $userEmailColumn === '' &&
                    $userUsernameColumn === ''
                )
            ) {
                throw new Exception(
                    'Required login columns are missing.'
                );
            }

            $select = array(
                "u.`{$userIdColumn}` AS user_id",
                "u.`{$userTenantColumn}` AS tenant_id",
                "u.`{$userPasswordColumn}` AS password_value",
                $userRoleColumn !== ''
                    ? "u.`{$userRoleColumn}` AS role_id"
                    : "0 AS role_id",
                $userFirstNameColumn !== ''
                    ? "u.`{$userFirstNameColumn}` AS first_name"
                    : "'' AS first_name",
                $userLastNameColumn !== ''
                    ? "u.`{$userLastNameColumn}` AS last_name"
                    : "'' AS last_name",
                $userEmailColumn !== ''
                    ? "u.`{$userEmailColumn}` AS email"
                    : "'' AS email",
                $userPhoneColumn !== ''
                    ? "u.`{$userPhoneColumn}` AS phone"
                    : "'' AS phone",
                $userStatusColumn !== ''
                    ? "u.`{$userStatusColumn}` AS user_status"
                    : "'active' AS user_status",
                $userDeletedColumn !== ''
                    ? "u.`{$userDeletedColumn}` AS user_deleted_at"
                    : "NULL AS user_deleted_at",
                "t.`{$tenantNameColumn}` AS company_name",
                $tenantSlugColumn !== ''
                    ? "t.`{$tenantSlugColumn}` AS company_slug"
                    : "'' AS company_slug",
                $tenantLogoColumn !== ''
                    ? "t.`{$tenantLogoColumn}` AS company_logo"
                    : "'' AS company_logo",
                $tenantTimezoneColumn !== ''
                    ? "t.`{$tenantTimezoneColumn}` AS timezone"
                    : "'Asia/Kolkata' AS timezone",
                $tenantCurrencyColumn !== ''
                    ? "t.`{$tenantCurrencyColumn}` AS currency_code"
                    : "'INR' AS currency_code",
                $tenantDateFormatColumn !== ''
                    ? "t.`{$tenantDateFormatColumn}` AS date_format"
                    : "'d-m-Y' AS date_format",
                $tenantStatusColumn !== ''
                    ? "t.`{$tenantStatusColumn}` AS tenant_status"
                    : "'active' AS tenant_status",
                $tenantTrialColumn !== ''
                    ? "t.`{$tenantTrialColumn}` AS trial_ends_at"
                    : "NULL AS trial_ends_at",
                $tenantPlanColumn !== ''
                    ? "t.`{$tenantPlanColumn}` AS subscription_plan"
                    : "'' AS subscription_plan",
                $tenantDeletedColumn !== ''
                    ? "t.`{$tenantDeletedColumn}` AS tenant_deleted_at"
                    : "NULL AS tenant_deleted_at"
            );

            $hasRoleJoin =
                $userRoleColumn !== '' &&
                $roleIdColumn !== '' &&
                loginTableExists(
                    $conn,
                    'roles'
                );

            if ($hasRoleJoin) {
                $select[] =
                    $roleNameColumn !== ''
                        ? "r.`{$roleNameColumn}` AS role_name"
                        : "'' AS role_name";

                $select[] =
                    $roleCodeColumn !== ''
                        ? "r.`{$roleCodeColumn}` AS role_code"
                        : "'' AS role_code";

                $select[] =
                    $roleStatusColumn !== ''
                        ? "r.`{$roleStatusColumn}` AS role_status"
                        : "1 AS role_status";
            } else {
                $select[] = "'' AS role_name";
                $select[] = "'' AS role_code";
                $select[] = "1 AS role_status";
            }

            $conditions = array();
            $params = array();
            $types = '';

            if ($userEmailColumn !== '') {
                $conditions[] =
                    "LOWER(u.`{$userEmailColumn}`) = LOWER(?)";

                $params[] = $identifier;
                $types .= 's';
            }

            if ($userUsernameColumn !== '') {
                $conditions[] =
                    "LOWER(u.`{$userUsernameColumn}`) = LOWER(?)";

                $params[] = $identifier;
                $types .= 's';
            }

            $sql = "
                SELECT
                    " .
                    implode(
                        ",\n                    ",
                        $select
                    ) .
                    "
                FROM users u
                INNER JOIN tenants t
                    ON t.`{$tenantIdColumn}` =
                       u.`{$userTenantColumn}`
            ";

            if ($hasRoleJoin) {
                $sql .= "
                    LEFT JOIN roles r
                        ON r.`{$roleIdColumn}` =
                           u.`{$userRoleColumn}`
                ";

                if ($roleTenantColumn !== '') {
                    $sql .= "
                        AND (
                            r.`{$roleTenantColumn}` =
                                u.`{$userTenantColumn}`
                            OR r.`{$roleTenantColumn}` IS NULL
                        )
                    ";
                }
            }

            $sql .= "
                WHERE (
                    " .
                    implode(
                        ' OR ',
                        $conditions
                    ) .
                    "
                )
            ";

            if (
                $workspace !== '' &&
                $tenantSlugColumn !== ''
            ) {
                $sql .= "
                    AND LOWER(
                        t.`{$tenantSlugColumn}`
                    ) = LOWER(?)
                ";

                $params[] = $workspace;
                $types .= 's';
            }

            $sql .= "
                ORDER BY
                    u.`{$userIdColumn}` ASC
                LIMIT 1
            ";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                throw new Exception(
                    $conn->error
                );
            }

            loginBind(
                $stmt,
                $types,
                $params
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    $stmt->error
                );
            }

            $user =
                loginFetchAssoc($stmt);

            $stmt->close();

            if (
                !$user ||
                !password_verify(
                    $password,
                    (string)
                    $user['password_value']
                )
            ) {
                $errorMessage =
                    'Invalid login credentials.';
            } elseif (
                !empty(
                    $user['user_deleted_at']
                )
            ) {
                $errorMessage =
                    'This user account is unavailable.';
            } elseif (
                in_array(
                    strtolower(
                        (string)
                        $user['user_status']
                    ),
                    array(
                        'inactive',
                        'disabled',
                        'suspended',
                        '0'
                    ),
                    true
                )
            ) {
                $errorMessage =
                    'This user account is inactive.';
            } elseif (
                !empty(
                    $user['tenant_deleted_at']
                )
            ) {
                $errorMessage =
                    'This workspace is unavailable.';
            } elseif (
                in_array(
                    strtolower(
                        (string)
                        $user['tenant_status']
                    ),
                    array(
                        'inactive',
                        'disabled',
                        'suspended',
                        'cancelled',
                        '0'
                    ),
                    true
                )
            ) {
                $errorMessage =
                    'This workspace is inactive.';
            } elseif (
                strtolower(
                    (string)
                    $user['tenant_status']
                ) === 'trial' &&
                !empty(
                    $user['trial_ends_at']
                ) &&
                strtotime(
                    (string)
                    $user['trial_ends_at']
                ) < time()
            ) {
                $errorMessage =
                    'This workspace trial has expired.';
            } elseif (
                !empty($user['role_id']) &&
                in_array(
                    strtolower(
                        (string)
                        $user['role_status']
                    ),
                    array(
                        'inactive',
                        'disabled',
                        '0'
                    ),
                    true
                )
            ) {
                $errorMessage =
                    'The assigned role is inactive.';
            } else {
                session_regenerate_id(true);

                $_SESSION['user_id'] =
                    (int) $user['user_id'];

                $_SESSION['tenant_id'] =
                    (int) $user['tenant_id'];

                $_SESSION['role_id'] =
                    (int) $user['role_id'];

                $_SESSION['user_name'] =
                    trim(
                        (string)
                        $user['first_name'] .
                        ' ' .
                        (string)
                        $user['last_name']
                    );

                $_SESSION['first_name'] =
                    (string)
                    $user['first_name'];

                $_SESSION['last_name'] =
                    (string)
                    $user['last_name'];

                $_SESSION['email'] =
                    (string)
                    $user['email'];

                $_SESSION['phone'] =
                    (string)
                    $user['phone'];

                $_SESSION['role_name'] =
                    (string)
                    $user['role_name'];

                $_SESSION['role_code'] =
                    (string)
                    $user['role_code'];

                $_SESSION['company_name'] =
                    (string)
                    $user['company_name'];

                $_SESSION['company_slug'] =
                    (string)
                    $user['company_slug'];

                $_SESSION['company_logo'] =
                    (string)
                    $user['company_logo'];

                $_SESSION['timezone'] =
                    !empty($user['timezone'])
                        ? (string)
                          $user['timezone']
                        : 'Asia/Kolkata';

                $_SESSION['currency_code'] =
                    !empty(
                        $user['currency_code']
                    )
                        ? (string)
                          $user['currency_code']
                        : 'INR';

                $_SESSION['date_format'] =
                    !empty(
                        $user['date_format']
                    )
                        ? (string)
                          $user['date_format']
                        : 'd-m-Y';

                $_SESSION['tenant_status'] =
                    (string)
                    $user['tenant_status'];

                $_SESSION['subscription_plan'] =
                    (string)
                    $user['subscription_plan'];

                $_SESSION['last_activity'] =
                    time();

                $_SESSION['login_time'] =
                    time();

                if ($rememberMe) {
                    setcookie(
                        session_name(),
                        session_id(),
                        time() + 2592000,
                        '/',
                        '',
                        !empty($_SERVER['HTTPS']) &&
                        strtolower(
                            (string)
                            $_SERVER['HTTPS']
                        ) !== 'off',
                        true
                    );
                }

                regenerateCsrfToken();

                header(
                    'Location: ' .
                    $redirectUrl,
                    true,
                    303
                );

                exit;
            }
        } catch (Exception $exception) {
            error_log(
                'FieldPlx root login error: ' .
                $exception->getMessage()
            );

            $errorMessage =
                'Unable to complete login. Please check the server error log.';
        }
    }

    /*
     * Post / Redirect / Get
     */
    $_SESSION['login_error'] =
        $errorMessage;

    $_SESSION['login_identifier'] =
        $identifier;

    $_SESSION['login_workspace'] =
        $workspace;

    $_SESSION['login_remember'] =
        $rememberMe ? 1 : 0;

    $returnUrl = '/login';

    if ($redirectUrl !== 'dashboard.php') {
        $returnUrl .=
            '?redirect=' .
            rawurlencode($redirectUrl);
    }

    header(
        'Location: ' . $returnUrl,
        true,
        303
    );

    exit;
}

$csrfTokenValue = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Login - FieldPlx</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root {
            --navy: #0b1f3a;
            --navy-2: #123564;
            --green: #5caf22;
            --text: #10213b;
            --muted: #6c7890;
            --line: #dfe6ee;
            --soft: #f7f9fc;
            --danger: #b42318;
        }

        * { box-sizing: border-box; }

        html, body { width: 100%; height: 100%; }

        body {
            margin: 0;
            width: 100%;
            height: 100vh;
            overflow: hidden;
            font-family: "Inter", sans-serif;
            color: var(--text);
            background: #fff;
        }

        .login-page {
            width: 100%;
            height: 100vh;
            min-height: 0;
            display: grid;
            grid-template-columns: 1.03fr .97fr;
            background: #fff;
        }

        .brand-side {
            position: relative;
            height: 100vh;
            min-height: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            padding: 42px 60px 34px;
            background-color: #eef7ff;
            background-image:
                linear-gradient(180deg, rgba(244,249,255,.98) 0%, rgba(244,249,255,.96) 31%, rgba(244,249,255,.78) 45%, rgba(244,249,255,.20) 59%, rgba(244,249,255,0) 72%),
                url('assets/login-banner.png');
            background-repeat: no-repeat, no-repeat;
            background-position: center top, center bottom;
            background-size: 100% 100%, cover;
        }

        .brand-side::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(90deg, rgba(255,255,255,.10), transparent 34%);
        }

        .brand-content {
            position: relative;
            z-index: 1;
            max-width: 700px;
        }

        .login-logo {
            display: block;
            width: 215px;
            max-width: 56%;
            height: auto;
            margin-bottom: 34px;
        }

        .brand-copy h1 {
            margin: 0;
            max-width: 720px;
            font-size: clamp(35px, 3.25vw, 54px);
            line-height: 1.12;
            letter-spacing: -1.6px;
            font-weight: 800;
            color: #0d203b;
        }

        .brand-copy p {
            margin: 18px 0 26px;
            max-width: 590px;
            color: #52647c;
            font-size: 14.5px;
            line-height: 1.72;
        }

        .benefits {
            display: grid;
            gap: 18px;
            max-width: 570px;
        }

        .benefit-item {
            display: grid;
            grid-template-columns: 44px 1fr;
            gap: 14px;
            align-items: center;
        }

        .benefit-icon {
            width: 44px;
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(92,175,34,.12);
            color: var(--green);
            font-size: 21px;
        }

        .benefit-item:nth-child(2) .benefit-icon {
            background: rgba(37,99,235,.10);
            color: #2563eb;
        }

        .benefit-item:nth-child(3) .benefit-icon {
            background: rgba(124,58,237,.10);
            color: #7c3aed;
        }

        .benefit-title {
            display: block;
            margin-bottom: 5px;
            font-weight: 800;
            font-size: 14px;
            color: #10213b;
        }

        .benefit-text {
            display: block;
            color: #657289;
            font-size: 12.5px;
            line-height: 1.55;
        }

        .login-side {
            position: relative;
            height: 100vh;
            min-height: 0;
            overflow: hidden;
            padding: 24px 46px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #fff;
        }


        .login-card {
            width: min(560px, 100%);
            margin: 0 auto;
            padding: 32px 42px 30px;
            border: 1px solid #e7ebf0;
            border-radius: 22px;
            background: #fff;
            box-shadow: 0 16px 42px rgba(21, 45, 80, .09);
        }

        .login-heading {
            margin-bottom: 18px;
            text-align: center;
        }

        .login-heading h2 {
            margin: 0;
            font-size: 27px;
            line-height: 1.15;
            color: #0c1f3b;
            font-weight: 800;
        }

        .login-heading p {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 12.5px;
        }

        .alert {
            margin-bottom: 18px;
            padding: 12px 14px;
            display: flex;
            gap: 9px;
            align-items: flex-start;
            border: 1px solid #fecaca;
            border-radius: 10px;
            background: #fff5f5;
            color: var(--danger);
            font-size: 12px;
            line-height: 1.5;
        }

        .form-group { margin-bottom: 11px; }

        .form-label {
            display: block;
            margin-bottom: 6px;
            color: #1f2d44;
            font-size: 12px;
            font-weight: 700;
        }

        .optional {
            color: #9aa5b5;
            font-weight: 500;
        }

        .input-wrap { position: relative; }

        .input-icon {
            position: absolute;
            top: 50%;
            left: 15px;
            transform: translateY(-50%);
            color: #8b97aa;
            font-size: 16px;
            pointer-events: none;
        }

        .form-control {
            width: 100%;
            height: 44px;
            padding: 10px 42px 10px 42px;
            border: 1px solid #cfd8e4;
            border-radius: 8px;
            background: #fff;
            color: #17233b;
            outline: none;
            font: inherit;
            font-size: 13px;
            transition: border-color .18s ease, box-shadow .18s ease;
        }

        .form-control::placeholder { color: #98a3b4; }

        .form-control:focus {
            border-color: #8ca2bf;
            box-shadow: 0 0 0 4px rgba(18,53,100,.07);
        }

        .password-toggle {
            position: absolute;
            top: 3px;
            right: 4px;
            width: 36px;
            height: 36px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: #8692a5;
            cursor: pointer;
        }

        .form-row {
            margin: 1px 0 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .remember {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #536176;
            font-size: 12px;
        }

        .remember input { accent-color: var(--navy); }

        .forgot-link {
            color: #1558c9;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
        }

        .forgot-link:hover { text-decoration: underline; }

        .login-button {
            width: 100%;
            min-height: 44px;
            border: 0;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            background: linear-gradient(135deg, #123d75, #0b2750);
            color: #fff;
            font: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 22px rgba(18,61,117,.18);
        }

        .login-button:hover { filter: brightness(1.04); }
        .login-button:disabled { opacity: .7; cursor: not-allowed; }

        .divider {
            margin: 14px 0 12px;
            display: flex;
            align-items: center;
            gap: 14px;
            color: #8a96a8;
            font-size: 12px;
        }

        .divider::before,
        .divider::after {
            content: "";
            height: 1px;
            flex: 1;
            background: #e0e5ec;
        }

        .google-button {
            width: 100%;
            min-height: 43px;
            border: 1px solid #d5dde7;
            border-radius: 8px;
            background: #fff;
            color: #1b2b44;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .google-icon {
            width: 20px;
            height: 20px;
            display: block;
            flex: 0 0 20px;
        }

        .signup-text {
            margin-top: 13px;
            text-align: center;
            color: #6e7a8d;
            font-size: 12px;
        }

        .signup-text a {
            color: #1558c9;
            font-weight: 700;
            text-decoration: none;
        }


        @media (max-height: 820px) and (min-width: 861px) {
            .brand-side { padding-top: 30px; padding-bottom: 26px; }
            .login-logo { width: 190px; margin-bottom: 24px; }
            .brand-copy h1 { font-size: clamp(32px, 3vw, 46px); }
            .brand-copy p { margin: 12px 0 18px; font-size: 12.5px; line-height: 1.6; }
            .benefits { gap: 12px; }
            .benefit-item { grid-template-columns: 38px 1fr; gap: 11px; }
            .benefit-icon { width: 38px; height: 38px; font-size: 17px; }
            .benefit-title { font-size: 13.5px; margin-bottom: 4px; }
            .benefit-text { font-size: 11.5px; line-height: 1.5; }
            .login-side { padding-top: 16px; padding-bottom: 16px; }
            .login-card { padding-top: 26px; padding-bottom: 24px; }
            .login-heading { margin-bottom: 15px; }
            .login-heading h2 { font-size: 25px; }
            .form-group { margin-bottom: 9px; }
            .form-control { height: 42px; }
            .password-toggle { top: 3px; }
            .form-row { margin-bottom: 12px; }
            .login-button { min-height: 42px; }
            .divider { margin: 11px 0 10px; }
            .google-button { min-height: 41px; }
            .signup-text { margin-top: 10px; }
        }

        @media (max-width: 1100px) {
            .login-page { grid-template-columns: .95fr 1.05fr; }
            .brand-side { padding: 42px 36px; }
            .login-side { padding: 38px 32px; }
            .login-card { padding: 44px 38px 38px; }
        }

        @media (max-width: 860px) {
            html, body {
                height: auto;
                min-height: 100%;
                overflow-x: hidden;
                overflow-y: auto;
            }
            .login-page {
                height: auto;
                min-height: 100vh;
                grid-template-columns: 1fr;
            }
            .brand-side { display: none; }
            .login-side {
                height: auto;
                min-height: 100vh;
                overflow: visible;
                padding: 28px 18px;
                background: linear-gradient(180deg, #f7fbff 0%, #fff 34%);
            }
            .login-card { margin-top: 0; }
        }

        @media (max-width: 520px) {
            .login-card {
                padding: 34px 22px 28px;
                border-radius: 18px;
            }
            .login-heading h2 { font-size: 27px; }
            .form-row {
                align-items: flex-start;
                flex-direction: column;
                gap: 10px;
            }
        }

        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="login-page">
    <section class="brand-side">
        <div class="brand-content">
            <img src="assets/logo.png" alt="FieldPlx" class="login-logo">

            <div class="brand-copy">
                <h1>The All-in-One Platform for Field Service Management</h1>
                <p>Streamline operations, empower your team, and deliver exceptional service — all from one powerful platform.</p>
            </div>

            <div class="benefits">
                <div class="benefit-item">
                    <span class="benefit-icon"><i class="bi bi-calendar2-check"></i></span>
                    <span>
                        <strong class="benefit-title">Smart Scheduling</strong>
                        <span class="benefit-text">Assign the right job to the right tech at the right time.</span>
                    </span>
                </div>

                <div class="benefit-item">
                    <span class="benefit-icon"><i class="bi bi-geo-alt-fill"></i></span>
                    <span>
                        <strong class="benefit-title">Real-time Tracking</strong>
                        <span class="benefit-text">Stay updated on every job with live status and insights.</span>
                    </span>
                </div>

                <div class="benefit-item">
                    <span class="benefit-icon"><i class="bi bi-chat-square-text-fill"></i></span>
                    <span>
                        <strong class="benefit-title">Customer Updates</strong>
                        <span class="benefit-text">Keep customers informed automatically at every step.</span>
                    </span>
                </div>
            </div>
        </div>
    </section>

    <section class="login-side">
        <div class="login-card">
            <div class="login-heading">
                <h2>Welcome Back</h2>
                <p>Log in to your FieldPlx account</p>
            </div>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert" role="alert">
                    <i class="bi bi-exclamation-circle"></i>
                    <span><?= loginE($errorMessage); ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="" id="loginForm" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= loginE($csrfTokenValue); ?>">
                <input type="hidden" name="redirect" value="<?= loginE($redirectUrl); ?>">

                <div class="form-group">
                    <label for="identifier" class="form-label">Email Address / Username</label>
                    <div class="input-wrap">
                        <i class="bi bi-envelope input-icon"></i>
                        <input
                            type="text"
                            id="identifier"
                            name="identifier"
                            class="form-control"
                            value="<?= loginE($identifier); ?>"
                            placeholder="Enter your email or username"
                            autocomplete="username"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="workspace" class="form-label">
                        Workspace Code <span class="optional">(optional)</span>
                    </label>
                    <div class="input-wrap">
                        <i class="bi bi-building input-icon"></i>
                        <input
                            type="text"
                            id="workspace"
                            name="workspace"
                            class="form-control"
                            value="<?= loginE($workspace); ?>"
                            placeholder="Example: your-company"
                            autocomplete="organization"
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-wrap">
                        <i class="bi bi-lock input-icon"></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="password-toggle" id="passwordToggle" aria-label="Show password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-row">
                    <label class="remember">
                        <input type="checkbox" name="remember_me" value="1" <?= $rememberMe ? 'checked' : ''; ?>>
                        Remember me
                    </label>
                    <a href="forgot-password.php" class="forgot-link">Forgot Password?</a>
                </div>

                <button type="submit" class="login-button" id="loginButton">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Log In
                </button>
            </form>

            <div class="divider">or</div>

            <button type="button" class="google-button" onclick="return false;" aria-label="Continue with Google">
                <svg class="google-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path fill="#4285F4" d="M21.805 12.23c0-.79-.071-1.55-.203-2.28H12v4.31h5.49a4.69 4.69 0 0 1-2.036 3.077v2.558h3.296c1.93-1.777 3.055-4.398 3.055-7.665z"/>
                    <path fill="#34A853" d="M12 22c2.76 0 5.075-.916 6.767-2.485l-3.296-2.558c-.915.614-2.084.977-3.471.977-2.665 0-4.922-1.8-5.729-4.219H2.866v2.65A10 10 0 0 0 12 22z"/>
                    <path fill="#FBBC05" d="M6.271 13.715A6.01 6.01 0 0 1 5.96 12c0-.595.102-1.173.311-1.715v-2.65H2.866A10 10 0 0 0 2 12c0 1.614.386 3.142.866 4.365l3.405-2.65z"/>
                    <path fill="#EA4335" d="M12 6.066c1.501 0 2.847.516 3.906 1.53l2.93-2.93C17.07 3.02 14.755 2 12 2A10 10 0 0 0 2.866 7.635l3.405 2.65C7.078 7.866 9.335 6.066 12 6.066z"/>
                </svg>
                Continue with Google
            </button>

            <div class="signup-text">
                Don't have an account? <a href="#" onclick="return false;">Sign up</a>
            </div>
        </div>

    </section>
</div>

<script>
(function () {
    'use strict';

    const password = document.getElementById('password');
    const toggle = document.getElementById('passwordToggle');
    const form = document.getElementById('loginForm');
    const button = document.getElementById('loginButton');

    if (toggle && password) {
        toggle.addEventListener('click', function () {
            const visible = password.type === 'text';
            password.type = visible ? 'password' : 'text';

            const icon = toggle.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-eye', visible);
                icon.classList.toggle('bi-eye-slash', !visible);
            }

            toggle.setAttribute('aria-label', visible ? 'Show password' : 'Hide password');
        });
    }

    if (form && button) {
        form.addEventListener('submit', function () {
            if (!form.checkValidity()) return;
            button.disabled = true;
            button.innerHTML =
                '<span style="width:14px;height:14px;display:inline-block;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite"></span>' +
                'Logging in...';
        });
    }
})();
</script>
</body>
</html>
