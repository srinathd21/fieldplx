<?php
/**
 * FieldPlx Common Tenant Authentication Guard
 *
 * File:
 * includes/auth.php
 *
 * Responsibilities:
 * - Verifies the tenant login session
 * - Validates the logged-in user, role, and tenant
 * - Validates trial and subscription availability
 * - Loads plan-level modules and features
 * - Applies tenant module and feature overrides
 * - Loads tenant limits
 * - Exposes reusable access-control helpers
 *
 * Compatible with:
 * - PHP 7.2+
 * - MariaDB / MySQLi
 */

require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Authentication configuration
|--------------------------------------------------------------------------
*/

if (!defined('FIELDPLX_LOGIN_URL')) {
    define('FIELDPLX_LOGIN_URL', 'login.php');
}

if (!defined('FIELDPLX_SESSION_TIMEOUT')) {
    define('FIELDPLX_SESSION_TIMEOUT', 7200);
}

if (!defined('FIELDPLX_ACCESS_CACHE_TTL')) {
    define('FIELDPLX_ACCESS_CACHE_TTL', 300);
}

/*
|--------------------------------------------------------------------------
| Generic helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('authTableExists')) {
    function authTableExists(mysqli $conn, $tableName)
    {
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

        $row = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        $cache[$tableName] = !empty($row['total']);

        return $cache[$tableName];
    }
}

if (!function_exists('authTableColumns')) {
    function authTableColumns(mysqli $conn, $tableName, $refresh = false)
    {
        static $cache = array();

        if (
            !$refresh &&
            isset($cache[$tableName])
        ) {
            return $cache[$tableName];
        }

        $cache[$tableName] = array();

        if (!authTableExists($conn, $tableName)) {
            return $cache[$tableName];
        }

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

if (!function_exists('authFirstColumn')) {
    function authFirstColumn(array $columns, array $candidates)
    {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('authBind')) {
    function authBind(mysqli_stmt $stmt, $types, array &$values)
    {
        if ($types === '') {
            return;
        }

        $arguments = array($types);

        foreach ($values as $key => $value) {
            $arguments[] = &$values[$key];
        }

        call_user_func_array(
            array($stmt, 'bind_param'),
            $arguments
        );
    }
}

if (!function_exists('authNormaliseCode')) {
    function authNormaliseCode($value)
    {
        $value = strtolower(trim((string) $value));

        $value = preg_replace(
            '/[^a-z0-9]+/',
            '_',
            $value
        );

        return trim((string) $value, '_');
    }
}

/*
|--------------------------------------------------------------------------
| Detect AJAX / JSON request
|--------------------------------------------------------------------------
*/

if (!function_exists('isAjaxRequest')) {
    function isAjaxRequest()
    {
        if (
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower(
                (string) $_SERVER['HTTP_X_REQUESTED_WITH']
            ) === 'xmlhttprequest'
        ) {
            return true;
        }

        $accept = strtolower(
            (string) (
                isset($_SERVER['HTTP_ACCEPT'])
                    ? $_SERVER['HTTP_ACCEPT']
                    : ''
            )
        );

        $contentType = strtolower(
            (string) (
                isset($_SERVER['CONTENT_TYPE'])
                    ? $_SERVER['CONTENT_TYPE']
                    : ''
            )
        );

        return strpos($accept, 'application/json') !== false ||
            strpos($contentType, 'application/json') !== false;
    }
}

/*
|--------------------------------------------------------------------------
| JSON error response
|--------------------------------------------------------------------------
*/

if (!function_exists('authJsonError')) {
    function authJsonError($message, $statusCode, array $extra = array())
    {
        http_response_code((int) $statusCode);

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            array_merge(
                array(
                    'success' => false,
                    'message' => (string) $message
                ),
                $extra
            )
        );

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Build login URL
|--------------------------------------------------------------------------
*/

if (!function_exists('getLoginUrl')) {
    function getLoginUrl()
    {
        $currentScript = basename(
            isset($_SERVER['PHP_SELF'])
                ? $_SERVER['PHP_SELF']
                : ''
        );

        if ($currentScript === 'login.php') {
            return FIELDPLX_LOGIN_URL;
        }

        $loginUrl = FIELDPLX_LOGIN_URL;

        if (
            strpos($loginUrl, '/') === false &&
            defined('FIELDPLX_BASE_URL') &&
            FIELDPLX_BASE_URL !== ''
        ) {
            $loginUrl =
                rtrim(FIELDPLX_BASE_URL, '/') .
                '/' .
                ltrim($loginUrl, '/');
        }

        $requestUri = isset($_SERVER['REQUEST_URI'])
            ? (string) $_SERVER['REQUEST_URI']
            : '';

        if ($requestUri !== '') {
            $separator =
                strpos($loginUrl, '?') !== false
                    ? '&'
                    : '?';

            $loginUrl .=
                $separator .
                'redirect=' .
                urlencode($requestUri);
        }

        return $loginUrl;
    }
}

/*
|--------------------------------------------------------------------------
| Destroy login session
|--------------------------------------------------------------------------
*/

if (!function_exists('destroyAuthSession')) {
    function destroyAuthSession()
    {
        $_SESSION = array();

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                isset($params['path'])
                    ? $params['path']
                    : '/',
                isset($params['domain'])
                    ? $params['domain']
                    : '',
                !empty($params['secure']),
                !empty($params['httponly'])
            );
        }

        if (
            session_status() ===
            PHP_SESSION_ACTIVE
        ) {
            session_destroy();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Authentication and access failures
|--------------------------------------------------------------------------
*/

if (!function_exists('redirectToLogin')) {
    function redirectToLogin($message)
    {
        if (isAjaxRequest()) {
            authJsonError($message, 401);
        }

        destroyAuthSession();

        header('Location: ' . getLoginUrl());
        exit;
    }
}

if (!function_exists('denyTenantAccess')) {
    function denyTenantAccess(
        $message,
        $statusCode = 403,
        array $extra = array()
    ) {
        if (isAjaxRequest()) {
            authJsonError(
                $message,
                $statusCode,
                $extra
            );
        }

        http_response_code((int) $statusCode);

        $safeMessage = htmlspecialchars(
            (string) $message,
            ENT_QUOTES,
            'UTF-8'
        );

        echo '<!DOCTYPE html>';
        echo '<html lang="en">';
        echo '<head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Access Denied - FieldPlx</title>';
        echo '<style>';
        echo 'body{margin:0;background:#f8fafc;font-family:Arial,sans-serif;color:#111827;}';
        echo '.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}';
        echo '.card{width:100%;max-width:430px;padding:28px;border:1px solid #e5e7eb;border-radius:16px;background:#fff;text-align:center;box-shadow:0 12px 35px rgba(17,24,39,.08);}';
        echo '.icon{width:58px;height:58px;margin:0 auto 15px;display:flex;align-items:center;justify-content:center;border-radius:16px;background:#fef2f2;color:#b91c1c;font-size:25px;}';
        echo 'h1{margin:0;font-size:20px;}';
        echo 'p{margin:10px 0 0;color:#6b7280;font-size:13px;line-height:1.6;}';
        echo 'a{margin-top:18px;padding:10px 16px;display:inline-block;border-radius:9px;background:#7c3aed;color:#fff;text-decoration:none;font-size:12px;font-weight:700;}';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<div class="wrap">';
        echo '<div class="card">';
        echo '<div class="icon">&#9888;</div>';
        echo '<h1>Access Denied</h1>';
        echo '<p>' . $safeMessage . '</p>';
        echo '<a href="dashboard.php">Back to Dashboard</a>';
        echo '</div>';
        echo '</div>';
        echo '</body>';
        echo '</html>';

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Validate required session values
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    redirectToLogin(
        'Your login session has expired.'
    );
}

/*
|--------------------------------------------------------------------------
| Session timeout validation
|--------------------------------------------------------------------------
*/

$currentTime = time();

$lastActivity =
    isset($_SESSION['last_activity'])
        ? (int) $_SESSION['last_activity']
        : 0;

if (
    $lastActivity > 0 &&
    ($currentTime - $lastActivity) >
        FIELDPLX_SESSION_TIMEOUT
) {
    redirectToLogin(
        'Your session has expired due to inactivity.'
    );
}

$_SESSION['last_activity'] = $currentTime;

/*
|--------------------------------------------------------------------------
| Validate user and tenant
|--------------------------------------------------------------------------
*/

$userId = (int) $_SESSION['user_id'];
$tenantId = (int) $_SESSION['tenant_id'];

$stmt = $conn->prepare("
    SELECT
        u.id AS user_id,
        u.tenant_id,
        u.role_id,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.avatar_path,
        u.job_title,
        u.employee_code,
        u.is_bookable,
        u.is_field_worker,
        u.status AS user_status,
        u.deleted_at AS user_deleted_at,

        r.id AS matched_role_id,
        r.name AS role_name,
        r.code AS role_code,
        r.is_active AS role_status,

        t.company_name,
        t.slug AS company_slug,
        t.logo_path AS company_logo,
        t.timezone,
        t.currency_code,
        t.date_format,
        t.status AS tenant_status,
        t.trial_ends_at,
        t.subscription_plan,
        t.deleted_at AS tenant_deleted_at

    FROM users u

    INNER JOIN tenants t
        ON t.id = u.tenant_id

    LEFT JOIN roles r
        ON r.id = u.role_id
        AND (
            r.tenant_id = u.tenant_id
            OR r.tenant_id IS NULL
        )

    WHERE u.id = ?
      AND u.tenant_id = ?
    LIMIT 1
");

if (!$stmt) {
    error_log(
        'Auth query preparation failed: ' .
        $conn->error
    );

    if (isAjaxRequest()) {
        authJsonError(
            'Authentication validation failed.',
            500
        );
    }

    http_response_code(500);
    exit('Authentication validation failed.');
}

$stmt->bind_param(
    'ii',
    $userId,
    $tenantId
);

$stmt->execute();

$result = $stmt->get_result();
$authUser = $result->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| User and tenant validation
|--------------------------------------------------------------------------
*/

if (!$authUser) {
    redirectToLogin(
        'Your account could not be found.'
    );
}

if (!empty($authUser['user_deleted_at'])) {
    redirectToLogin(
        'Your account is no longer available.'
    );
}

if (
    strtolower(
        (string) $authUser['user_status']
    ) !== 'active'
) {
    redirectToLogin(
        'Your account is not active.'
    );
}

if (!empty($authUser['tenant_deleted_at'])) {
    redirectToLogin(
        'This company workspace is no longer available.'
    );
}

$tenantStatus = strtolower(
    trim(
        (string)
        $authUser['tenant_status']
    )
);

$allowedTenantStatuses = array(
    'active',
    'trial'
);

if (
    !in_array(
        $tenantStatus,
        $allowedTenantStatuses,
        true
    )
) {
    redirectToLogin(
        'This company workspace is currently unavailable.'
    );
}

if (
    $tenantStatus === 'trial' &&
    !empty($authUser['trial_ends_at'])
) {
    $trialExpiry = strtotime(
        (string) $authUser['trial_ends_at']
    );

    if (
        $trialExpiry !== false &&
        $trialExpiry < time()
    ) {
        redirectToLogin(
            'Your company trial period has expired.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Role validation
|--------------------------------------------------------------------------
*/

if (
    !empty($authUser['role_id']) &&
    empty($authUser['matched_role_id'])
) {
    redirectToLogin(
        'Your assigned role could not be found.'
    );
}

if (
    !empty($authUser['role_id']) &&
    isset($authUser['role_status']) &&
    (int) $authUser['role_status'] !== 1
) {
    redirectToLogin(
        'Your assigned role is inactive.'
    );
}

/*
|--------------------------------------------------------------------------
| Resolve active subscription and plan
|--------------------------------------------------------------------------
*/

$subscriptionId = 0;
$subscriptionStatus = '';
$subscriptionEndsAt = '';
$subscriptionPlanId = 0;
$subscriptionPlanCode = trim(
    (string) $authUser['subscription_plan']
);

if (
    authTableExists($conn, 'subscriptions')
) {
    $subscriptionColumns =
        authTableColumns(
            $conn,
            'subscriptions'
        );

    $subscriptionIdColumn =
        authFirstColumn(
            $subscriptionColumns,
            array('id', 'subscription_id')
        );

    $subscriptionTenantColumn =
        authFirstColumn(
            $subscriptionColumns,
            array(
                'tenant_id',
                'business_id',
                'workspace_id'
            )
        );

    $subscriptionPlanColumn =
        authFirstColumn(
            $subscriptionColumns,
            array('plan_id')
        );

    $subscriptionStatusColumn =
        authFirstColumn(
            $subscriptionColumns,
            array('status')
        );

    $subscriptionStartColumn =
        authFirstColumn(
            $subscriptionColumns,
            array(
                'starts_at',
                'start_date',
                'started_at'
            )
        );

    $subscriptionEndColumn =
        authFirstColumn(
            $subscriptionColumns,
            array(
                'ends_at',
                'end_date',
                'expires_at',
                'expiry_date'
            )
        );

    $subscriptionDeletedColumn =
        authFirstColumn(
            $subscriptionColumns,
            array('deleted_at')
        );

    if (
        $subscriptionTenantColumn !== '' &&
        $subscriptionPlanColumn !== ''
    ) {
        $subscriptionSelect = array(
            "`{$subscriptionPlanColumn}` AS plan_id"
        );

        $subscriptionSelect[] =
            $subscriptionIdColumn !== ''
                ? "`{$subscriptionIdColumn}` AS subscription_id"
                : "0 AS subscription_id";

        $subscriptionSelect[] =
            $subscriptionStatusColumn !== ''
                ? "`{$subscriptionStatusColumn}` AS subscription_status"
                : "'active' AS subscription_status";

        $subscriptionSelect[] =
            $subscriptionEndColumn !== ''
                ? "`{$subscriptionEndColumn}` AS subscription_ends_at"
                : "NULL AS subscription_ends_at";

        $subscriptionSql = "
            SELECT
                " .
                implode(
                    ",\n                ",
                    $subscriptionSelect
                ) .
                "
            FROM subscriptions
            WHERE `{$subscriptionTenantColumn}` = ?
        ";

        if ($subscriptionDeletedColumn !== '') {
            $subscriptionSql .= "
                AND `{$subscriptionDeletedColumn}` IS NULL
            ";
        }

        if ($subscriptionStatusColumn !== '') {
            $subscriptionSql .= "
                AND `{$subscriptionStatusColumn}` IN (
                    'active',
                    'trial'
                )
            ";
        }

        if ($subscriptionStartColumn !== '') {
            $subscriptionSql .= "
                AND (
                    `{$subscriptionStartColumn}` IS NULL
                    OR `{$subscriptionStartColumn}` <= NOW()
                )
            ";
        }

        if ($subscriptionEndColumn !== '') {
            $subscriptionSql .= "
                AND (
                    `{$subscriptionEndColumn}` IS NULL
                    OR `{$subscriptionEndColumn}` >= NOW()
                )
            ";
        }

        $subscriptionSql .= "
            ORDER BY
        ";

        if ($subscriptionIdColumn !== '') {
            $subscriptionSql .= "
                `{$subscriptionIdColumn}` DESC
            ";
        } else {
            $subscriptionSql .= "
                `{$subscriptionPlanColumn}` DESC
            ";
        }

        $subscriptionSql .= "
            LIMIT 1
        ";

        $subscriptionStmt =
            $conn->prepare(
                $subscriptionSql
            );

        $subscriptionStmt->bind_param(
            'i',
            $tenantId
        );

        $subscriptionStmt->execute();

        $subscription =
            $subscriptionStmt
            ->get_result()
            ->fetch_assoc();

        $subscriptionStmt->close();

        if ($subscription) {
            $subscriptionId =
                (int)
                $subscription['subscription_id'];

            $subscriptionPlanId =
                (int)
                $subscription['plan_id'];

            $subscriptionStatus =
                strtolower(
                    trim(
                        (string)
                        $subscription[
                            'subscription_status'
                        ]
                    )
                );

            $subscriptionEndsAt =
                (string)
                $subscription[
                    'subscription_ends_at'
                ];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Resolve plan from tenants.subscription_plan when needed
|--------------------------------------------------------------------------
*/

if (
    $subscriptionPlanId <= 0 &&
    $subscriptionPlanCode !== '' &&
    authTableExists($conn, 'plans')
) {
    $planColumns =
        authTableColumns(
            $conn,
            'plans'
        );

    $planIdColumn =
        authFirstColumn(
            $planColumns,
            array('id', 'plan_id')
        );

    $planCodeColumn =
        authFirstColumn(
            $planColumns,
            array('code', 'plan_code')
        );

    $planNameColumn =
        authFirstColumn(
            $planColumns,
            array('name', 'plan_name')
        );

    $planStatusColumn =
        authFirstColumn(
            $planColumns,
            array('status')
        );

    $planDeletedColumn =
        authFirstColumn(
            $planColumns,
            array('deleted_at')
        );

    if ($planIdColumn !== '') {
        $planConditions = array();
        $planParams = array();
        $planTypes = '';

        if ($planCodeColumn !== '') {
            $planConditions[] =
                "LOWER(`{$planCodeColumn}`) = LOWER(?)";

            $planParams[] =
                $subscriptionPlanCode;

            $planTypes .= 's';
        }

        if ($planNameColumn !== '') {
            $planConditions[] =
                "LOWER(`{$planNameColumn}`) = LOWER(?)";

            $planParams[] =
                $subscriptionPlanCode;

            $planTypes .= 's';
        }

        if (!empty($planConditions)) {
            $planSql = "
                SELECT
                    `{$planIdColumn}` AS plan_id
                FROM plans
                WHERE (
                    " .
                    implode(
                        ' OR ',
                        $planConditions
                    ) .
                    "
                )
            ";

            if ($planStatusColumn !== '') {
                $planSql .= "
                    AND `{$planStatusColumn}` = 'active'
                ";
            }

            if ($planDeletedColumn !== '') {
                $planSql .= "
                    AND `{$planDeletedColumn}` IS NULL
                ";
            }

            $planSql .= "
                LIMIT 1
            ";

            $planStmt =
                $conn->prepare(
                    $planSql
                );

            authBind(
                $planStmt,
                $planTypes,
                $planParams
            );

            $planStmt->execute();

            $planRow =
                $planStmt
                ->get_result()
                ->fetch_assoc();

            $planStmt->close();

            if ($planRow) {
                $subscriptionPlanId =
                    (int) $planRow['plan_id'];
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Subscription enforcement
|--------------------------------------------------------------------------
*/

if (
    $tenantStatus === 'active' &&
    authTableExists($conn, 'subscriptions') &&
    $subscriptionPlanId <= 0 &&
    $subscriptionPlanCode === ''
) {
    denyTenantAccess(
        'No active subscription is assigned to this workspace.',
        403,
        array(
            'error_code' =>
                'subscription_required'
        )
    );
}

if (
    $subscriptionStatus !== '' &&
    !in_array(
        $subscriptionStatus,
        array('active', 'trial'),
        true
    )
) {
    denyTenantAccess(
        'This workspace subscription is not active.',
        403,
        array(
            'error_code' =>
                'subscription_inactive'
        )
    );
}

if ($subscriptionEndsAt !== '') {
    $subscriptionExpiry =
        strtotime($subscriptionEndsAt);

    if (
        $subscriptionExpiry !== false &&
        $subscriptionExpiry < time()
    ) {
        denyTenantAccess(
            'This workspace subscription has expired.',
            403,
            array(
                'error_code' =>
                    'subscription_expired'
            )
        );
    }
}

/*
|--------------------------------------------------------------------------
| Refresh session values
|--------------------------------------------------------------------------
*/

$fullName = trim(
    (string) $authUser['first_name'] .
    ' ' .
    (
        isset($authUser['last_name'])
            ? (string) $authUser['last_name']
            : ''
    )
);

$_SESSION['user_id'] =
    (int) $authUser['user_id'];

$_SESSION['tenant_id'] =
    (int) $authUser['tenant_id'];

$_SESSION['role_id'] =
    !empty($authUser['role_id'])
        ? (int) $authUser['role_id']
        : 0;

$_SESSION['user_name'] = $fullName;
$_SESSION['first_name'] =
    (string) $authUser['first_name'];
$_SESSION['last_name'] =
    (string) $authUser['last_name'];
$_SESSION['email'] =
    (string) $authUser['email'];
$_SESSION['phone'] =
    (string) $authUser['phone'];
$_SESSION['avatar_path'] =
    (string) $authUser['avatar_path'];
$_SESSION['job_title'] =
    (string) $authUser['job_title'];
$_SESSION['employee_code'] =
    (string) $authUser['employee_code'];

$_SESSION['is_bookable'] =
    (int) $authUser['is_bookable'];

$_SESSION['is_field_worker'] =
    (int) $authUser['is_field_worker'];

$_SESSION['role_name'] =
    isset($authUser['role_name'])
        ? (string) $authUser['role_name']
        : '';

$_SESSION['role_code'] =
    isset($authUser['role_code'])
        ? (string) $authUser['role_code']
        : '';

$_SESSION['company_name'] =
    (string) $authUser['company_name'];

$_SESSION['company_slug'] =
    (string) $authUser['company_slug'];

$_SESSION['company_logo'] =
    (string) $authUser['company_logo'];

$_SESSION['timezone'] =
    !empty($authUser['timezone'])
        ? (string) $authUser['timezone']
        : 'Asia/Kolkata';

$_SESSION['currency_code'] =
    !empty($authUser['currency_code'])
        ? (string) $authUser['currency_code']
        : 'INR';

$_SESSION['date_format'] =
    !empty($authUser['date_format'])
        ? (string) $authUser['date_format']
        : 'd-m-Y';

$_SESSION['tenant_status'] =
    $tenantStatus;

$_SESSION['subscription_plan'] =
    $subscriptionPlanCode;

$_SESSION['subscription_id'] =
    $subscriptionId;

$_SESSION['subscription_plan_id'] =
    $subscriptionPlanId;

$_SESSION['subscription_status'] =
    $subscriptionStatus;

$_SESSION['subscription_ends_at'] =
    $subscriptionEndsAt;

if (
    !empty($_SESSION['timezone']) &&
    in_array(
        $_SESSION['timezone'],
        timezone_identifiers_list(),
        true
    )
) {
    date_default_timezone_set(
        $_SESSION['timezone']
    );
}

/*
|--------------------------------------------------------------------------
| Load module and feature access
|--------------------------------------------------------------------------
*/

$tenantModules = array();
$tenantFeatures = array();
$tenantLimits = array();

$accessCacheValid =
    !empty($_SESSION['tenant_access_loaded_at']) &&
    (time() -
        (int) $_SESSION['tenant_access_loaded_at']) <
        FIELDPLX_ACCESS_CACHE_TTL &&
    isset($_SESSION['tenant_modules']) &&
    isset($_SESSION['tenant_features']) &&
    isset($_SESSION['tenant_limits']);

if ($accessCacheValid) {
    $tenantModules =
        (array) $_SESSION['tenant_modules'];

    $tenantFeatures =
        (array) $_SESSION['tenant_features'];

    $tenantLimits =
        (array) $_SESSION['tenant_limits'];
} else {
    /*
    |--------------------------------------------------------------------------
    | Load module master
    |--------------------------------------------------------------------------
    */

    $moduleMaster = array();

    if (authTableExists($conn, 'modules')) {
        $moduleColumns =
            authTableColumns(
                $conn,
                'modules'
            );

        $moduleIdColumn =
            authFirstColumn(
                $moduleColumns,
                array('id', 'module_id')
            );

        $moduleCodeColumn =
            authFirstColumn(
                $moduleColumns,
                array('module_code', 'code')
            );

        $moduleNameColumn =
            authFirstColumn(
                $moduleColumns,
                array('module_name', 'name')
            );

        $moduleActiveColumn =
            authFirstColumn(
                $moduleColumns,
                array('is_active', 'active')
            );

        $moduleCoreColumn =
            authFirstColumn(
                $moduleColumns,
                array('is_core', 'core')
            );

        if (
            $moduleIdColumn !== '' &&
            $moduleCodeColumn !== ''
        ) {
            $moduleSelect = array(
                "`{$moduleIdColumn}` AS module_id",
                "`{$moduleCodeColumn}` AS module_code"
            );

            $moduleSelect[] =
                $moduleNameColumn !== ''
                    ? "`{$moduleNameColumn}` AS module_name"
                    : "'' AS module_name";

            $moduleSelect[] =
                $moduleCoreColumn !== ''
                    ? "`{$moduleCoreColumn}` AS is_core"
                    : "0 AS is_core";

            $moduleSql = "
                SELECT
                    " .
                    implode(
                        ",\n                    ",
                        $moduleSelect
                    ) .
                    "
                FROM modules
            ";

            if ($moduleActiveColumn !== '') {
                $moduleSql .= "
                    WHERE `{$moduleActiveColumn}` = 1
                ";
            }

            $moduleResult =
                $conn->query(
                    $moduleSql
                );

            while (
                $moduleRow =
                $moduleResult->fetch_assoc()
            ) {
                $moduleCode =
                    authNormaliseCode(
                        $moduleRow['module_code']
                    );

                if ($moduleCode === '') {
                    continue;
                }

                $moduleMaster[
                    (int)
                    $moduleRow['module_id']
                ] = array(
                    'id' =>
                        (int)
                        $moduleRow['module_id'],
                    'code' =>
                        $moduleCode,
                    'name' =>
                        (string)
                        $moduleRow['module_name'],
                    'is_core' =>
                        (int)
                        $moduleRow['is_core']
                );

                $tenantModules[$moduleCode] =
                    (int)
                    $moduleRow['is_core'] === 1;
            }

            $moduleResult->free();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Apply plan module access
    |--------------------------------------------------------------------------
    */

    if (
        $subscriptionPlanId > 0 &&
        authTableExists($conn, 'plan_modules') &&
        !empty($moduleMaster)
    ) {
        $planModuleColumns =
            authTableColumns(
                $conn,
                'plan_modules'
            );

        $planModulePlanColumn =
            authFirstColumn(
                $planModuleColumns,
                array('plan_id')
            );

        $planModuleModuleColumn =
            authFirstColumn(
                $planModuleColumns,
                array('module_id')
            );

        $planModuleEnabledColumn =
            authFirstColumn(
                $planModuleColumns,
                array('is_enabled', 'enabled')
            );

        if (
            $planModulePlanColumn !== '' &&
            $planModuleModuleColumn !== ''
        ) {
            $planModuleSelect = array(
                "`{$planModuleModuleColumn}` AS module_id"
            );

            $planModuleSelect[] =
                $planModuleEnabledColumn !== ''
                    ? "`{$planModuleEnabledColumn}` AS is_enabled"
                    : "1 AS is_enabled";

            $planModuleSql = "
                SELECT
                    " .
                    implode(
                        ', ',
                        $planModuleSelect
                    ) .
                    "
                FROM plan_modules
                WHERE `{$planModulePlanColumn}` = ?
            ";

            $planModuleStmt =
                $conn->prepare(
                    $planModuleSql
                );

            $planModuleStmt->bind_param(
                'i',
                $subscriptionPlanId
            );

            $planModuleStmt->execute();

            $planModuleResult =
                $planModuleStmt->get_result();

            while (
                $planModuleRow =
                $planModuleResult->fetch_assoc()
            ) {
                $moduleId =
                    (int)
                    $planModuleRow['module_id'];

                if (
                    !isset(
                        $moduleMaster[$moduleId]
                    )
                ) {
                    continue;
                }

                $moduleCode =
                    $moduleMaster[$moduleId]['code'];

                $tenantModules[$moduleCode] =
                    (int)
                    $planModuleRow['is_enabled']
                    === 1;
            }

            $planModuleStmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Apply tenant module overrides
    |--------------------------------------------------------------------------
    */

    if (
        authTableExists($conn, 'tenant_modules') &&
        !empty($moduleMaster)
    ) {
        $tenantModuleColumns =
            authTableColumns(
                $conn,
                'tenant_modules'
            );

        $tenantModuleTenantColumn =
            authFirstColumn(
                $tenantModuleColumns,
                array(
                    'tenant_id',
                    'business_id',
                    'workspace_id'
                )
            );

        $tenantModuleModuleColumn =
            authFirstColumn(
                $tenantModuleColumns,
                array('module_id')
            );

        $tenantModuleEnabledColumn =
            authFirstColumn(
                $tenantModuleColumns,
                array('is_enabled', 'enabled')
            );

        $tenantModuleAccessColumn =
            authFirstColumn(
                $tenantModuleColumns,
                array('access_type')
            );

        if (
            $tenantModuleTenantColumn !== '' &&
            $tenantModuleModuleColumn !== ''
        ) {
            $tenantModuleSelect = array(
                "`{$tenantModuleModuleColumn}` AS module_id"
            );

            if ($tenantModuleAccessColumn !== '') {
                $tenantModuleSelect[] =
                    "`{$tenantModuleAccessColumn}` AS access_type";
            } elseif ($tenantModuleEnabledColumn !== '') {
                $tenantModuleSelect[] =
                    "`{$tenantModuleEnabledColumn}` AS is_enabled";
            } else {
                $tenantModuleSelect[] =
                    "1 AS is_enabled";
            }

            $tenantModuleSql = "
                SELECT
                    " .
                    implode(
                        ', ',
                        $tenantModuleSelect
                    ) .
                    "
                FROM tenant_modules
                WHERE `{$tenantModuleTenantColumn}` = ?
            ";

            $tenantModuleStmt =
                $conn->prepare(
                    $tenantModuleSql
                );

            $tenantModuleStmt->bind_param(
                'i',
                $tenantId
            );

            $tenantModuleStmt->execute();

            $tenantModuleResult =
                $tenantModuleStmt->get_result();

            while (
                $tenantModuleRow =
                $tenantModuleResult->fetch_assoc()
            ) {
                $moduleId =
                    (int)
                    $tenantModuleRow['module_id'];

                if (
                    !isset(
                        $moduleMaster[$moduleId]
                    )
                ) {
                    continue;
                }

                $moduleCode =
                    $moduleMaster[$moduleId]['code'];

                if (
                    isset(
                        $tenantModuleRow[
                            'access_type'
                        ]
                    )
                ) {
                    $accessType =
                        strtolower(
                            trim(
                                (string)
                                $tenantModuleRow[
                                    'access_type'
                                ]
                            )
                        );

                    if (
                        in_array(
                            $accessType,
                            array(
                                'enabled',
                                'allow',
                                'allowed',
                                'include',
                                'included'
                            ),
                            true
                        )
                    ) {
                        $tenantModules[$moduleCode] =
                            true;
                    } elseif (
                        in_array(
                            $accessType,
                            array(
                                'disabled',
                                'deny',
                                'denied',
                                'exclude',
                                'excluded'
                            ),
                            true
                        )
                    ) {
                        $tenantModules[$moduleCode] =
                            false;
                    }
                } else {
                    $tenantModules[$moduleCode] =
                        !empty(
                            $tenantModuleRow[
                                'is_enabled'
                            ]
                        );
                }
            }

            $tenantModuleStmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Load feature master
    |--------------------------------------------------------------------------
    */

    $featureMaster = array();

    if (
        authTableExists($conn, 'module_features') &&
        !empty($moduleMaster)
    ) {
        $featureColumns =
            authTableColumns(
                $conn,
                'module_features'
            );

        $featureIdColumn =
            authFirstColumn(
                $featureColumns,
                array('id', 'feature_id')
            );

        $featureModuleColumn =
            authFirstColumn(
                $featureColumns,
                array('module_id')
            );

        $featureCodeColumn =
            authFirstColumn(
                $featureColumns,
                array('feature_code', 'code')
            );

        $featureNameColumn =
            authFirstColumn(
                $featureColumns,
                array('feature_name', 'name')
            );

        $featureActiveColumn =
            authFirstColumn(
                $featureColumns,
                array('is_active', 'active')
            );

        if (
            $featureIdColumn !== '' &&
            $featureModuleColumn !== '' &&
            $featureCodeColumn !== ''
        ) {
            $featureSelect = array(
                "`{$featureIdColumn}` AS feature_id",
                "`{$featureModuleColumn}` AS module_id",
                "`{$featureCodeColumn}` AS feature_code"
            );

            $featureSelect[] =
                $featureNameColumn !== ''
                    ? "`{$featureNameColumn}` AS feature_name"
                    : "'' AS feature_name";

            $featureSql = "
                SELECT
                    " .
                    implode(
                        ",\n                    ",
                        $featureSelect
                    ) .
                    "
                FROM module_features
            ";

            if ($featureActiveColumn !== '') {
                $featureSql .= "
                    WHERE `{$featureActiveColumn}` = 1
                ";
            }

            $featureResult =
                $conn->query(
                    $featureSql
                );

            while (
                $featureRow =
                $featureResult->fetch_assoc()
            ) {
                $moduleId =
                    (int)
                    $featureRow['module_id'];

                if (
                    !isset(
                        $moduleMaster[$moduleId]
                    )
                ) {
                    continue;
                }

                $featureCode =
                    authNormaliseCode(
                        $featureRow['feature_code']
                    );

                if ($featureCode === '') {
                    continue;
                }

                $featureMaster[
                    (int)
                    $featureRow['feature_id']
                ] = array(
                    'id' =>
                        (int)
                        $featureRow['feature_id'],
                    'module_id' =>
                        $moduleId,
                    'module_code' =>
                        $moduleMaster[$moduleId]['code'],
                    'feature_code' =>
                        $featureCode,
                    'feature_name' =>
                        (string)
                        $featureRow['feature_name']
                );
            }

            $featureResult->free();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Apply plan feature access
    |--------------------------------------------------------------------------
    */

    if (
        $subscriptionPlanId > 0 &&
        authTableExists($conn, 'plan_features') &&
        !empty($featureMaster)
    ) {
        $planFeatureColumns =
            authTableColumns(
                $conn,
                'plan_features'
            );

        $planFeaturePlanColumn =
            authFirstColumn(
                $planFeatureColumns,
                array('plan_id')
            );

        $planFeatureFeatureColumn =
            authFirstColumn(
                $planFeatureColumns,
                array('feature_id')
            );

        $planFeatureEnabledColumn =
            authFirstColumn(
                $planFeatureColumns,
                array('is_enabled', 'enabled')
            );

        if (
            $planFeaturePlanColumn !== '' &&
            $planFeatureFeatureColumn !== ''
        ) {
            $planFeatureSelect = array(
                "`{$planFeatureFeatureColumn}` AS feature_id"
            );

            $planFeatureSelect[] =
                $planFeatureEnabledColumn !== ''
                    ? "`{$planFeatureEnabledColumn}` AS is_enabled"
                    : "1 AS is_enabled";

            $planFeatureSql = "
                SELECT
                    " .
                    implode(
                        ', ',
                        $planFeatureSelect
                    ) .
                    "
                FROM plan_features
                WHERE `{$planFeaturePlanColumn}` = ?
            ";

            $planFeatureStmt =
                $conn->prepare(
                    $planFeatureSql
                );

            $planFeatureStmt->bind_param(
                'i',
                $subscriptionPlanId
            );

            $planFeatureStmt->execute();

            $planFeatureResult =
                $planFeatureStmt->get_result();

            while (
                $planFeatureRow =
                $planFeatureResult->fetch_assoc()
            ) {
                $featureId =
                    (int)
                    $planFeatureRow['feature_id'];

                if (
                    !isset(
                        $featureMaster[$featureId]
                    )
                ) {
                    continue;
                }

                $feature =
                    $featureMaster[$featureId];

                $moduleCode =
                    $feature['module_code'];

                $featureCode =
                    $feature['feature_code'];

                if (
                    !isset(
                        $tenantFeatures[
                            $moduleCode
                        ]
                    )
                ) {
                    $tenantFeatures[
                        $moduleCode
                    ] = array();
                }

                $tenantFeatures[
                    $moduleCode
                ][
                    $featureCode
                ] =
                    (int)
                    $planFeatureRow['is_enabled']
                    === 1;
            }

            $planFeatureStmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Apply tenant feature overrides
    |--------------------------------------------------------------------------
    */

    if (
        authTableExists($conn, 'tenant_features') &&
        !empty($featureMaster)
    ) {
        $tenantFeatureColumns =
            authTableColumns(
                $conn,
                'tenant_features'
            );

        $tenantFeatureTenantColumn =
            authFirstColumn(
                $tenantFeatureColumns,
                array(
                    'tenant_id',
                    'business_id',
                    'workspace_id'
                )
            );

        $tenantFeatureFeatureColumn =
            authFirstColumn(
                $tenantFeatureColumns,
                array('feature_id')
            );

        $tenantFeatureEnabledColumn =
            authFirstColumn(
                $tenantFeatureColumns,
                array('is_enabled', 'enabled')
            );

        $tenantFeatureAccessColumn =
            authFirstColumn(
                $tenantFeatureColumns,
                array('access_type')
            );

        if (
            $tenantFeatureTenantColumn !== '' &&
            $tenantFeatureFeatureColumn !== ''
        ) {
            $tenantFeatureSelect = array(
                "`{$tenantFeatureFeatureColumn}` AS feature_id"
            );

            if ($tenantFeatureAccessColumn !== '') {
                $tenantFeatureSelect[] =
                    "`{$tenantFeatureAccessColumn}` AS access_type";
            } elseif ($tenantFeatureEnabledColumn !== '') {
                $tenantFeatureSelect[] =
                    "`{$tenantFeatureEnabledColumn}` AS is_enabled";
            } else {
                $tenantFeatureSelect[] =
                    "1 AS is_enabled";
            }

            $tenantFeatureSql = "
                SELECT
                    " .
                    implode(
                        ', ',
                        $tenantFeatureSelect
                    ) .
                    "
                FROM tenant_features
                WHERE `{$tenantFeatureTenantColumn}` = ?
            ";

            $tenantFeatureStmt =
                $conn->prepare(
                    $tenantFeatureSql
                );

            $tenantFeatureStmt->bind_param(
                'i',
                $tenantId
            );

            $tenantFeatureStmt->execute();

            $tenantFeatureResult =
                $tenantFeatureStmt->get_result();

            while (
                $tenantFeatureRow =
                $tenantFeatureResult->fetch_assoc()
            ) {
                $featureId =
                    (int)
                    $tenantFeatureRow['feature_id'];

                if (
                    !isset(
                        $featureMaster[$featureId]
                    )
                ) {
                    continue;
                }

                $feature =
                    $featureMaster[$featureId];

                $moduleCode =
                    $feature['module_code'];

                $featureCode =
                    $feature['feature_code'];

                if (
                    !isset(
                        $tenantFeatures[
                            $moduleCode
                        ]
                    )
                ) {
                    $tenantFeatures[
                        $moduleCode
                    ] = array();
                }

                if (
                    isset(
                        $tenantFeatureRow[
                            'access_type'
                        ]
                    )
                ) {
                    $accessType =
                        strtolower(
                            trim(
                                (string)
                                $tenantFeatureRow[
                                    'access_type'
                                ]
                            )
                        );

                    if (
                        in_array(
                            $accessType,
                            array(
                                'enabled',
                                'allow',
                                'allowed',
                                'include',
                                'included'
                            ),
                            true
                        )
                    ) {
                        $tenantFeatures[
                            $moduleCode
                        ][
                            $featureCode
                        ] = true;
                    } elseif (
                        in_array(
                            $accessType,
                            array(
                                'disabled',
                                'deny',
                                'denied',
                                'exclude',
                                'excluded'
                            ),
                            true
                        )
                    ) {
                        $tenantFeatures[
                            $moduleCode
                        ][
                            $featureCode
                        ] = false;
                    }
                } else {
                    $tenantFeatures[
                        $moduleCode
                    ][
                        $featureCode
                    ] =
                        !empty(
                            $tenantFeatureRow[
                                'is_enabled'
                            ]
                        );
                }
            }

            $tenantFeatureStmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Load tenant limits
    |--------------------------------------------------------------------------
    */

    if (authTableExists($conn, 'tenant_limits')) {
        $limitColumns =
            authTableColumns(
                $conn,
                'tenant_limits'
            );

        $limitTenantColumn =
            authFirstColumn(
                $limitColumns,
                array(
                    'tenant_id',
                    'business_id',
                    'workspace_id'
                )
            );

        $limitCodeColumn =
            authFirstColumn(
                $limitColumns,
                array(
                    'limit_code',
                    'code',
                    'limit_key',
                    'name'
                )
            );

        $limitValueColumn =
            authFirstColumn(
                $limitColumns,
                array(
                    'limit_value',
                    'value',
                    'max_value'
                )
            );

        if (
            $limitTenantColumn !== '' &&
            $limitCodeColumn !== '' &&
            $limitValueColumn !== ''
        ) {
            $limitStmt =
                $conn->prepare("
                    SELECT
                        `{$limitCodeColumn}` AS limit_code,
                        `{$limitValueColumn}` AS limit_value
                    FROM tenant_limits
                    WHERE `{$limitTenantColumn}` = ?
                ");

            $limitStmt->bind_param(
                'i',
                $tenantId
            );

            $limitStmt->execute();

            $limitResult =
                $limitStmt->get_result();

            while (
                $limitRow =
                $limitResult->fetch_assoc()
            ) {
                $limitCode =
                    authNormaliseCode(
                        $limitRow['limit_code']
                    );

                if ($limitCode === '') {
                    continue;
                }

                $limitValue =
                    $limitRow['limit_value'];

                if (
                    $limitValue === null ||
                    $limitValue === ''
                ) {
                    $tenantLimits[$limitCode] =
                        null;
                } elseif (is_numeric($limitValue)) {
                    $tenantLimits[$limitCode] =
                        (int) $limitValue;
                } else {
                    $tenantLimits[$limitCode] =
                        $limitValue;
                }
            }

            $limitStmt->close();
        }
    }

    $_SESSION['tenant_modules'] =
        $tenantModules;

    $_SESSION['tenant_features'] =
        $tenantFeatures;

    $_SESSION['tenant_limits'] =
        $tenantLimits;

    $_SESSION['tenant_access_loaded_at'] =
        time();
}

/*
|--------------------------------------------------------------------------
| Authenticated user helper
|--------------------------------------------------------------------------
*/

if (!function_exists('authUser')) {
    function authUser()
    {
        return array(
            'id' =>
                (int)
                (
                    isset($_SESSION['user_id'])
                        ? $_SESSION['user_id']
                        : 0
                ),
            'tenant_id' =>
                (int)
                (
                    isset($_SESSION['tenant_id'])
                        ? $_SESSION['tenant_id']
                        : 0
                ),
            'role_id' =>
                (int)
                (
                    isset($_SESSION['role_id'])
                        ? $_SESSION['role_id']
                        : 0
                ),
            'name' =>
                isset($_SESSION['user_name'])
                    ? $_SESSION['user_name']
                    : '',
            'email' =>
                isset($_SESSION['email'])
                    ? $_SESSION['email']
                    : '',
            'phone' =>
                isset($_SESSION['phone'])
                    ? $_SESSION['phone']
                    : '',
            'avatar_path' =>
                isset($_SESSION['avatar_path'])
                    ? $_SESSION['avatar_path']
                    : '',
            'job_title' =>
                isset($_SESSION['job_title'])
                    ? $_SESSION['job_title']
                    : '',
            'role_name' =>
                isset($_SESSION['role_name'])
                    ? $_SESSION['role_name']
                    : '',
            'role_code' =>
                isset($_SESSION['role_code'])
                    ? $_SESSION['role_code']
                    : '',
            'is_field_worker' =>
                (int)
                (
                    isset(
                        $_SESSION[
                            'is_field_worker'
                        ]
                    )
                        ? $_SESSION[
                            'is_field_worker'
                        ]
                        : 0
                ),
            'is_bookable' =>
                (int)
                (
                    isset(
                        $_SESSION[
                            'is_bookable'
                        ]
                    )
                        ? $_SESSION[
                            'is_bookable'
                        ]
                        : 0
                ),
            'subscription_id' =>
                (int)
                (
                    isset(
                        $_SESSION[
                            'subscription_id'
                        ]
                    )
                        ? $_SESSION[
                            'subscription_id'
                        ]
                        : 0
                ),
            'subscription_plan_id' =>
                (int)
                (
                    isset(
                        $_SESSION[
                            'subscription_plan_id'
                        ]
                    )
                        ? $_SESSION[
                            'subscription_plan_id'
                        ]
                        : 0
                )
        );
    }
}

/*
|--------------------------------------------------------------------------
| Logged-in and role helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('isLoggedIn')) {
    function isLoggedIn()
    {
        return !empty($_SESSION['user_id']) &&
            !empty($_SESSION['tenant_id']);
    }
}

if (!function_exists('isTenantOwner')) {
    function isTenantOwner()
    {
        return isset($_SESSION['role_code']) &&
            strtolower(
                (string) $_SESSION['role_code']
            ) === 'owner';
    }
}

if (!function_exists('isFieldWorker')) {
    function isFieldWorker()
    {
        return !empty(
            $_SESSION['is_field_worker']
        );
    }
}

/*
|--------------------------------------------------------------------------
| Module helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('tenantHasModule')) {
    function tenantHasModule($moduleCode)
    {
        if (isTenantOwner()) {
            return true;
        }

        $moduleCode =
            authNormaliseCode($moduleCode);

        if ($moduleCode === '') {
            return false;
        }

        return !empty(
            $_SESSION['tenant_modules'][
                $moduleCode
            ]
        );
    }
}

if (!function_exists('requireTenantModule')) {
    function requireTenantModule($moduleCode, $message = '')
    {
        if (tenantHasModule($moduleCode)) {
            return true;
        }

        if ($message === '') {
            $message =
                'This module is not available for your workspace.';
        }

        denyTenantAccess(
            $message,
            403,
            array(
                'error_code' =>
                    'module_not_available',
                'module_code' =>
                    authNormaliseCode(
                        $moduleCode
                    )
            )
        );

        return false;
    }
}

/*
|--------------------------------------------------------------------------
| Feature helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('tenantHasFeature')) {
    function tenantHasFeature(
        $moduleCode,
        $featureCode
    ) {
        if (isTenantOwner()) {
            return true;
        }

        $moduleCode =
            authNormaliseCode($moduleCode);

        $featureCode =
            authNormaliseCode($featureCode);

        if (
            $moduleCode === '' ||
            $featureCode === ''
        ) {
            return false;
        }

        if (!tenantHasModule($moduleCode)) {
            return false;
        }

        return !empty(
            $_SESSION['tenant_features'][
                $moduleCode
            ][
                $featureCode
            ]
        );
    }
}

if (!function_exists('requireTenantFeature')) {
    function requireTenantFeature(
        $moduleCode,
        $featureCode,
        $message = ''
    ) {
        if (
            tenantHasFeature(
                $moduleCode,
                $featureCode
            )
        ) {
            return true;
        }

        if ($message === '') {
            $message =
                'This feature is not available for your workspace.';
        }

        denyTenantAccess(
            $message,
            403,
            array(
                'error_code' =>
                    'feature_not_available',
                'module_code' =>
                    authNormaliseCode(
                        $moduleCode
                    ),
                'feature_code' =>
                    authNormaliseCode(
                        $featureCode
                    )
            )
        );

        return false;
    }
}

/*
|--------------------------------------------------------------------------
| Tenant limit helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('getTenantLimit')) {
    function getTenantLimit(
        $limitCode,
        $default = null
    ) {
        $limitCode =
            authNormaliseCode(
                $limitCode
            );

        if ($limitCode === '') {
            return $default;
        }

        if (
            !array_key_exists(
                $limitCode,
                (array)
                $_SESSION['tenant_limits']
            )
        ) {
            return $default;
        }

        return $_SESSION['tenant_limits'][
            $limitCode
        ];
    }
}

if (!function_exists('tenantLimitReached')) {
    function tenantLimitReached(
        $limitCode,
        $currentUsage
    ) {
        $limit =
            getTenantLimit(
                $limitCode,
                null
            );

        if (
            $limit === null ||
            $limit === '' ||
            !is_numeric($limit)
        ) {
            return false;
        }

        return (int) $currentUsage >=
            (int) $limit;
    }
}

if (!function_exists('requireTenantLimitAvailable')) {
    function requireTenantLimitAvailable(
        $limitCode,
        $currentUsage,
        $message = ''
    ) {
        if (
            !tenantLimitReached(
                $limitCode,
                $currentUsage
            )
        ) {
            return true;
        }

        if ($message === '') {
            $message =
                'Your workspace has reached the allowed limit.';
        }

        denyTenantAccess(
            $message,
            403,
            array(
                'error_code' =>
                    'tenant_limit_reached',
                'limit_code' =>
                    authNormaliseCode(
                        $limitCode
                    ),
                'current_usage' =>
                    (int) $currentUsage,
                'limit' =>
                    getTenantLimit(
                        $limitCode,
                        null
                    )
            )
        );

        return false;
    }
}

/*
|--------------------------------------------------------------------------
| Access cache refresh helper
|--------------------------------------------------------------------------
*/

if (!function_exists('clearTenantAccessCache')) {
    function clearTenantAccessCache()
    {
        unset(
            $_SESSION['tenant_modules'],
            $_SESSION['tenant_features'],
            $_SESSION['tenant_limits'],
            $_SESSION[
                'tenant_access_loaded_at'
            ]
        );
    }
}
