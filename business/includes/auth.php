<?php
/*
|--------------------------------------------------------------------------
| FieldPlx Tenant / Business Authentication Guard
|--------------------------------------------------------------------------
|
| Usage at the VERY TOP of every protected business page:
|
| require_once __DIR__ . '/includes/auth.php';
|
| For a page inside a subfolder:
|
| require_once __DIR__ . '/../includes/auth.php';
|
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function tenantAuthIsAjax()
{
    return (
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower(
            (string)$_SERVER['HTTP_X_REQUESTED_WITH']
        ) === 'xmlhttprequest'
    );
}

function tenantAuthFail(
    $message = 'Your session has expired.',
    $status = 401,
    $auditAction = 'SESSION_ACCESS_DENIED',
    $auditReason = 'session_invalid'
) {
    global $pdo;

    $auditTenantId =
        !empty($_SESSION['tenant_id'])
            ? (int)$_SESSION['tenant_id']
            : null;

    $auditBranchId =
        !empty($_SESSION['branch_id'])
            ? (int)$_SESSION['branch_id']
            : null;

    $auditUserId =
        !empty($_SESSION['tenant_user_id'])
            ? (int)$_SESSION['tenant_user_id']
            : null;

    if (
        isset($pdo) &&
        $pdo instanceof PDO
    ) {
        tenantAuditLog(
            $pdo,
            $auditAction,
            $auditTenantId,
            $auditBranchId,
            $auditUserId,
            'tenant_session',
            $auditUserId,
            null,
            array(
                'result' =>
                    'denied',
                'reason' =>
                    $auditReason,
                'message' =>
                    (string)$message,
                'session_id' =>
                    session_id(),
                'request_uri' =>
                    isset($_SERVER['REQUEST_URI'])
                        ? (string)$_SERVER['REQUEST_URI']
                        : ''
            )
        );
    }

    if (tenantAuthIsAjax()) {

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        http_response_code((int)$status);

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode(array(
            'success' => false,
            'message' => $message,
            'redirect' => 'login.php'
        ));

        exit;
    }

    $requestUri =
        isset($_SERVER['REQUEST_URI'])
            ? (string)$_SERVER['REQUEST_URI']
            : '';

    $returnTo = 'index.php';

    /*
     * Keep return target local to /business/.
     */
    if ($requestUri !== '') {

        $path =
            parse_url(
                $requestUri,
                PHP_URL_PATH
            );

        $query =
            parse_url(
                $requestUri,
                PHP_URL_QUERY
            );

        if (is_string($path)) {

            $businessPos =
                strpos(
                    $path,
                    '/business/'
                );

            if ($businessPos !== false) {

                $relative =
                    substr(
                        $path,
                        $businessPos +
                        strlen('/business/')
                    );

                if ($relative !== '') {
                    $returnTo = $relative;

                    if (
                        is_string($query) &&
                        $query !== ''
                    ) {
                        $returnTo .=
                            '?' . $query;
                    }
                }
            }
        }
    }

    header(
        'Location: login.php?return_to=' .
        rawurlencode($returnTo)
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Require tenant session
|--------------------------------------------------------------------------
*/
if (
    empty($_SESSION['tenant_authenticated']) ||
    empty($_SESSION['tenant_user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    tenantAuthFail(
        'Your session has expired.',
        401,
        'SESSION_ACCESS_DENIED',
        'missing_authentication_session'
    );
}

/*
|--------------------------------------------------------------------------
| Inactivity timeout
|--------------------------------------------------------------------------
|
| 2 hours, matching the platform authentication pattern.
|
*/
$maxIdleSeconds = 2 * 60 * 60;

$lastActivity =
    isset($_SESSION['tenant_last_activity'])
        ? (int)$_SESSION['tenant_last_activity']
        : 0;

if (
    $lastActivity > 0 &&
    (time() - $lastActivity) >
        $maxIdleSeconds
) {
    tenantAuditLog(
        $pdo,
        'LOGOUT_TIMEOUT',
        !empty($_SESSION['tenant_id'])
            ? (int)$_SESSION['tenant_id']
            : null,
        !empty($_SESSION['branch_id'])
            ? (int)$_SESSION['branch_id']
            : null,
        !empty($_SESSION['tenant_user_id'])
            ? (int)$_SESSION['tenant_user_id']
            : null,
        'tenant_session',
        !empty($_SESSION['tenant_user_id'])
            ? (int)$_SESSION['tenant_user_id']
            : null,
        null,
        array(
            'result' =>
                'logged_out',
            'reason' =>
                'inactivity_timeout',
            'idle_seconds' =>
                time() - $lastActivity,
            'session_id' =>
                session_id(),
            'logout_at' =>
                date('Y-m-d H:i:s')
        )
    );

    $_SESSION = array();

    if (ini_get('session.use_cookies')) {

        $params =
            session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    if (tenantAuthIsAjax()) {
        http_response_code(401);
        header(
            'Content-Type: application/json; charset=utf-8'
        );
        echo json_encode(array(
            'success' => false,
            'message' =>
                'Your session expired due to inactivity.',
            'redirect' => 'login.php'
        ));
        exit;
    }

    header(
        'Location: login.php?reason=timeout'
    );
    exit;
}

$_SESSION['tenant_last_activity'] =
    time();

/*
|--------------------------------------------------------------------------
| Revalidate user + tenant + assigned context
|--------------------------------------------------------------------------
|
| Never trust a long-lived session without checking the current DB state.
|
*/
$tenantId =
    (int)$_SESSION['tenant_id'];

$tenantUserId =
    (int)$_SESSION['tenant_user_id'];

$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.tenant_id,
        u.branch_id,
        u.department_id,
        u.role_id,
        u.first_name,
        u.last_name,
        u.email,
        u.avatar_path,
        u.job_title,
        u.is_tenant_admin,
        u.is_field_worker,
        u.is_bookable,
        u.status AS user_status,

        t.tenant_code,
        t.display_name AS tenant_name,
        t.status AS tenant_status,
        t.timezone AS tenant_timezone,
        t.date_format AS tenant_date_format,
        t.country_id AS tenant_country_id,
        t.currency_id AS tenant_currency_id,
        t.logo_path AS tenant_logo_path,
        t.invoice_logo_path AS tenant_invoice_logo_path,

        b.name AS branch_name,
        b.branch_code,
        b.status AS branch_status,
        b.timezone AS branch_timezone,
        b.country_id AS branch_country_id,
        b.currency_id AS branch_currency_id,
        b.logo_path AS branch_logo_path,
        b.invoice_logo_path AS branch_invoice_logo_path,

        d.name AS department_name,
        d.code AS department_code,
        d.status AS department_status,

        r.name AS role_name,
        r.code AS role_code,
        r.is_admin AS role_is_admin,
        r.status AS role_status

    FROM users u

    INNER JOIN tenants t
        ON t.id = u.tenant_id
       AND t.deleted_at IS NULL

    LEFT JOIN branches b
        ON b.id = u.branch_id
       AND b.tenant_id = u.tenant_id

    LEFT JOIN departments d
        ON d.id = u.department_id
       AND d.tenant_id = u.tenant_id

    LEFT JOIN roles r
        ON r.id = u.role_id
       AND r.tenant_id = u.tenant_id

    WHERE u.id = :user_id
      AND u.tenant_id = :tenant_id
      AND u.deleted_at IS NULL

    LIMIT 1
");

$stmt->execute(array(
    ':user_id' => $tenantUserId,
    ':tenant_id' => $tenantId
));

$currentTenantUser =
    $stmt->fetch();

if (!$currentTenantUser) {
    tenantAuthFail(
        'Your user account is no longer available.',
        401,
        'SESSION_REVOKED',
        'user_not_found'
    );
}

if (
    (string)$currentTenantUser['user_status']
    !== 'active'
) {
    tenantAuthFail(
        'Your user account is not active.',
        403,
        'SESSION_REVOKED',
        'user_not_active'
    );
}

if (
    !in_array(
        (string)$currentTenantUser['tenant_status'],
        array('trial', 'active'),
        true
    )
) {
    tenantAuthFail(
        'This business account is not active.',
        403,
        'SESSION_REVOKED',
        'tenant_not_active'
    );
}

if (
    !empty($currentTenantUser['branch_id']) &&
    (string)$currentTenantUser['branch_status']
    !== 'active'
) {
    tenantAuthFail(
        'Your assigned branch is not active.',
        403,
        'SESSION_REVOKED',
        'branch_not_active'
    );
}

if (
    !empty($currentTenantUser['department_id']) &&
    !empty($currentTenantUser['department_status']) &&
    (string)$currentTenantUser['department_status']
    !== 'active'
) {
    tenantAuthFail(
        'Your assigned department is not active.',
        403,
        'SESSION_REVOKED',
        'department_not_active'
    );
}

if (
    !empty($currentTenantUser['role_id']) &&
    !empty($currentTenantUser['role_status']) &&
    (string)$currentTenantUser['role_status']
    !== 'active'
) {
    tenantAuthFail(
        'Your assigned role is not active.',
        403,
        'SESSION_REVOKED',
        'role_not_active'
    );
}

/*
|--------------------------------------------------------------------------
| Revalidate active subscription
|--------------------------------------------------------------------------
*/
$currentSubscription = null;

$subStmt = $pdo->prepare("
    SELECT
        s.id,
        s.plan_id,
        s.currency_id,
        s.start_date,
        s.expiry_date,
        s.trial_end_date,
        s.auto_renew,
        s.max_users_override,
        s.max_branches_override,
        s.max_customers_override,
        s.storage_limit_mb_override,
        s.status,
        p.name AS plan_name,
        p.code AS plan_code,
        p.max_users,
        p.max_branches,
        p.max_customers,
        p.storage_limit_mb
    FROM subscriptions s
    LEFT JOIN plans p
        ON p.id = s.plan_id
       AND p.deleted_at IS NULL
    WHERE s.tenant_id = :tenant_id
    ORDER BY
        CASE s.status
            WHEN 'active' THEN 1
            WHEN 'trial' THEN 2
            WHEN 'suspended' THEN 3
            WHEN 'expired' THEN 4
            ELSE 5
        END,
        s.id DESC
    LIMIT 1
");

$subStmt->execute(array(
    ':tenant_id' => $tenantId
));

$currentSubscription =
    $subStmt->fetch();

if ($currentSubscription) {

    if (
        !in_array(
            (string)$currentSubscription['status'],
            array('trial', 'active'),
            true
        )
    ) {
        tenantAuthFail(
            'Your subscription is not active.',
            403,
            'SESSION_REVOKED',
            'subscription_not_active'
        );
    }

    if (
        !empty($currentSubscription['expiry_date']) &&
        $currentSubscription['expiry_date'] <
            date('Y-m-d')
    ) {
        tenantAuthFail(
            'Your subscription has expired.',
            403,
            'SESSION_REVOKED',
            'subscription_expired'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Refresh important mutable session context
|--------------------------------------------------------------------------
*/
$fullName =
    trim(
        (string)$currentTenantUser['first_name'] .
        ' ' .
        (string)$currentTenantUser['last_name']
    );

$_SESSION['tenant_user_name'] =
    $fullName !== ''
        ? $fullName
        : (string)$currentTenantUser['email'];

$_SESSION['user_name'] =
    $_SESSION['tenant_user_name'];

$_SESSION['tenant_user_email'] =
    (string)$currentTenantUser['email'];

$_SESSION['user_email'] =
    (string)$currentTenantUser['email'];

$_SESSION['tenant_user_avatar'] =
    (string)($currentTenantUser['avatar_path'] ?? '');

$_SESSION['tenant_user_job_title'] =
    (string)($currentTenantUser['job_title'] ?? '');

$_SESSION['tenant_user_is_admin'] =
    (int)$currentTenantUser['is_tenant_admin'];

$_SESSION['tenant_user_is_field_worker'] =
    (int)$currentTenantUser['is_field_worker'];

$_SESSION['tenant_user_is_bookable'] =
    (int)$currentTenantUser['is_bookable'];

$_SESSION['tenant_code'] =
    (string)$currentTenantUser['tenant_code'];

$_SESSION['tenant_name'] =
    (string)$currentTenantUser['tenant_name'];

$_SESSION['tenant_status'] =
    (string)$currentTenantUser['tenant_status'];

$_SESSION['branch_id'] =
    !empty($currentTenantUser['branch_id'])
        ? (int)$currentTenantUser['branch_id']
        : 0;

$_SESSION['branch_name'] =
    (string)($currentTenantUser['branch_name'] ?? '');

$_SESSION['branch_code'] =
    (string)($currentTenantUser['branch_code'] ?? '');

$_SESSION['department_id'] =
    !empty($currentTenantUser['department_id'])
        ? (int)$currentTenantUser['department_id']
        : 0;

$_SESSION['department_name'] =
    (string)($currentTenantUser['department_name'] ?? '');

$_SESSION['role_id'] =
    !empty($currentTenantUser['role_id'])
        ? (int)$currentTenantUser['role_id']
        : 0;

$_SESSION['role_name'] =
    (string)($currentTenantUser['role_name'] ?? '');

$_SESSION['role_code'] =
    (string)($currentTenantUser['role_code'] ?? '');

$_SESSION['role_is_admin'] =
    isset($currentTenantUser['role_is_admin'])
        ? (int)$currentTenantUser['role_is_admin']
        : 0;

$_SESSION['effective_timezone'] =
    !empty($currentTenantUser['branch_timezone'])
        ? (string)$currentTenantUser['branch_timezone']
        : (string)$currentTenantUser['tenant_timezone'];

$_SESSION['effective_country_id'] =
    !empty($currentTenantUser['branch_country_id'])
        ? (int)$currentTenantUser['branch_country_id']
        : (int)$currentTenantUser['tenant_country_id'];

$_SESSION['effective_currency_id'] =
    !empty($currentTenantUser['branch_currency_id'])
        ? (int)$currentTenantUser['branch_currency_id']
        : (int)$currentTenantUser['tenant_currency_id'];

$_SESSION['effective_logo_path'] =
    !empty($currentTenantUser['branch_logo_path'])
        ? (string)$currentTenantUser['branch_logo_path']
        : (string)$currentTenantUser['tenant_logo_path'];

$_SESSION['effective_invoice_logo_path'] =
    !empty($currentTenantUser['branch_invoice_logo_path'])
        ? (string)$currentTenantUser['branch_invoice_logo_path']
        : (string)$currentTenantUser['tenant_invoice_logo_path'];

/*
 * Refresh subscription context.
 */
if ($currentSubscription) {

    $_SESSION['subscription_id'] =
        (int)$currentSubscription['id'];

    $_SESSION['subscription_status'] =
        (string)$currentSubscription['status'];

    $_SESSION['subscription_start_date'] =
        (string)$currentSubscription['start_date'];

    $_SESSION['subscription_expiry_date'] =
        (string)($currentSubscription['expiry_date'] ?? '');

    $_SESSION['subscription_trial_end_date'] =
        (string)($currentSubscription['trial_end_date'] ?? '');

    $_SESSION['subscription_auto_renew'] =
        (int)$currentSubscription['auto_renew'];

    $_SESSION['plan_id'] =
        (int)$currentSubscription['plan_id'];

    $_SESSION['plan_name'] =
        (string)($currentSubscription['plan_name'] ?? '');

    $_SESSION['plan_code'] =
        (string)($currentSubscription['plan_code'] ?? '');

    $_SESSION['plan_max_users'] =
        $currentSubscription['max_users_override'] !== null
            ? (int)$currentSubscription['max_users_override']
            : (
                $currentSubscription['max_users'] !== null
                    ? (int)$currentSubscription['max_users']
                    : null
            );

    $_SESSION['plan_max_branches'] =
        $currentSubscription['max_branches_override'] !== null
            ? (int)$currentSubscription['max_branches_override']
            : (
                $currentSubscription['max_branches'] !== null
                    ? (int)$currentSubscription['max_branches']
                    : null
            );

    $_SESSION['plan_max_customers'] =
        $currentSubscription['max_customers_override'] !== null
            ? (int)$currentSubscription['max_customers_override']
            : (
                $currentSubscription['max_customers'] !== null
                    ? (int)$currentSubscription['max_customers']
                    : null
            );

    $_SESSION['plan_storage_limit_mb'] =
        $currentSubscription['storage_limit_mb_override'] !== null
            ? (int)$currentSubscription['storage_limit_mb_override']
            : (
                $currentSubscription['storage_limit_mb'] !== null
                    ? (int)$currentSubscription['storage_limit_mb']
                    : null
            );
}

/*
|--------------------------------------------------------------------------
| Expose convenient page variables
|--------------------------------------------------------------------------
*/
$currentTenantId =
    (int)$_SESSION['tenant_id'];

$currentTenantUserId =
    (int)$_SESSION['tenant_user_id'];

$currentBranchId =
    (int)($_SESSION['branch_id'] ?? 0);

$currentDepartmentId =
    (int)($_SESSION['department_id'] ?? 0);

$currentRoleId =
    (int)($_SESSION['role_id'] ?? 0);

$currentPlanId =
    (int)($_SESSION['plan_id'] ?? 0);

$currentTenantName =
    (string)($_SESSION['tenant_name'] ?? '');

$currentTenantUserName =
    (string)($_SESSION['tenant_user_name'] ?? '');

$currentTimezone =
    (string)($_SESSION['effective_timezone'] ?? 'UTC');

if (
    $currentTimezone !== '' &&
    in_array(
        $currentTimezone,
        timezone_identifiers_list(),
        true
    )
) {
    date_default_timezone_set(
        $currentTimezone
    );
}
