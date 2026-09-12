<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Already logged in
|--------------------------------------------------------------------------
*/
if (
    !empty($_SESSION['tenant_authenticated']) &&
    !empty($_SESSION['tenant_user_id']) &&
    !empty($_SESSION['tenant_id'])
) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['tenant_login_csrf'])) {
    $_SESSION['tenant_login_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['tenant_login_csrf'];
$errorMessage = '';
$successMessage = '';
$loginIdentifier = '';

$loginReason =
    isset($_GET['reason'])
        ? trim((string)$_GET['reason'])
        : '';

if ($loginReason === 'logout') {
    $successMessage =
        'You have been signed out successfully.';
} elseif ($loginReason === 'timeout') {
    $errorMessage =
        'Your session expired due to inactivity. Please sign in again.';
}

function tl_h($value)
{
    return htmlspecialchars(
        (string)($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

function tl_table_exists(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");

    $stmt->execute(array(
        ':table_name' => $table
    ));

    $cache[$table] =
        ((int)$stmt->fetchColumn() > 0);

    return $cache[$table];
}

function tl_column_exists(PDO $pdo, $table, $column)
{
    static $cache = array();

    $key = $table . '.' . $column;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");

    $stmt->execute(array(
        ':table_name' => $table,
        ':column_name' => $column
    ));

    $cache[$key] =
        ((int)$stmt->fetchColumn() > 0);

    return $cache[$key];
}

function tl_safe_return_path($value)
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'index.php';
    }

    /*
     * Only permit local business-panel relative paths.
     * Reject absolute URLs, protocol-relative URLs and traversal.
     */
    if (
        strpos($value, '://') !== false ||
        strpos($value, '//') === 0 ||
        strpos($value, '..') !== false ||
        substr($value, 0, 1) === '/'
    ) {
        return 'index.php';
    }

    return $value;
}

function tl_audit_failed_login(
    PDO $pdo,
    $tenantCode,
    $email,
    $reason,
    $resolvedUser = null
) {
    $tenantId = null;
    $branchId = null;
    $userId = null;

    if (is_array($resolvedUser)) {
        $tenantId =
            !empty($resolvedUser['tenant_id'])
                ? (int)$resolvedUser['tenant_id']
                : null;

        $branchId =
            !empty($resolvedUser['branch_id'])
                ? (int)$resolvedUser['branch_id']
                : null;

        $userId =
            !empty($resolvedUser['user_id'])
                ? (int)$resolvedUser['user_id']
                : null;
    } elseif ($tenantCode !== '') {

        /*
         * Resolve tenant only when the supplied tenant code exists,
         * allowing failed attempts to be tied to a tenant without
         * exposing that information to the end user.
         */
        try {
            $tenantStmt = $pdo->prepare("
                SELECT id
                FROM tenants
                WHERE tenant_code = :tenant_code
                  AND deleted_at IS NULL
                LIMIT 1
            ");

            $tenantStmt->execute(array(
                ':tenant_code' => $tenantCode
            ));

            $resolvedTenantId =
                $tenantStmt->fetchColumn();

            if ($resolvedTenantId) {
                $tenantId =
                    (int)$resolvedTenantId;
            }
        } catch (Throwable $ignored) {
        }
    }

    tenantAuditLog(
        $pdo,
        'LOGIN_FAILED',
        $tenantId,
        $branchId,
        $userId,
        'tenant_login',
        $userId,
        null,
        array(
            'tenant_code' =>
                (string)$tenantCode,
            'email' =>
                (string)$email,
            'result' =>
                'failed',
            'reason' =>
                (string)$reason,
            'request_uri' =>
                isset($_SERVER['REQUEST_URI'])
                    ? (string)$_SERVER['REQUEST_URI']
                    : ''
        )
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $loginIdentifier =
        trim(
            (string)($_POST['login_identifier'] ?? '')
        );

    $password =
        (string)($_POST['password'] ?? '');

    $postedToken =
        (string)($_POST['csrf_token'] ?? '');

    $returnTo =
        tl_safe_return_path(
            $_POST['return_to'] ?? 'index.php'
        );

    if (
        $postedToken === '' ||
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {
        $errorMessage =
            'Your login session expired. Refresh the page and try again.';

        tl_audit_failed_login(
            $pdo,
            '',
            $loginIdentifier,
            'csrf_validation_failed'
        );
    } elseif ($loginIdentifier === '') {
        $errorMessage =
            'Enter your Tenant Code or Email Address.';

        tl_audit_failed_login(
            $pdo,
            '',
            '',
            'login_identifier_missing'
        );
    } elseif ($password === '') {
        $errorMessage =
            'Enter your password.';

        tl_audit_failed_login(
            $pdo,
            '',
            $loginIdentifier,
            'password_missing'
        );
    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Find tenant user by Tenant Code OR Email
            |--------------------------------------------------------------------------
            |
            | Email:
            |   Finds the user directly.
            |
            | Tenant Code:
            |   Resolves the tenant's tenant-admin user. This keeps Tenant Code
            |   usable as a single login identifier without requiring a second
            |   email field.
            |
            */
            $isEmail =
                filter_var(
                    $loginIdentifier,
                    FILTER_VALIDATE_EMAIL
                ) !== false;

            $lookupValue =
                $isEmail
                    ? strtolower($loginIdentifier)
                    : strtoupper($loginIdentifier);

            $baseSelect = "
                SELECT
                    u.id AS user_id,
                    u.tenant_id,
                    u.branch_id,
                    u.department_id,
                    u.role_id,
                    u.employee_code,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.phone,
                    u.alternate_phone,
                    u.password_hash,
                    u.avatar_path,
                    u.job_title,
                    u.labor_rate,
                    u.is_bookable,
                    u.is_field_worker,
                    u.is_tenant_admin,
                    u.status AS user_status,
                    u.last_login_at,

                    t.tenant_code,
                    t.legal_name,
                    t.display_name AS tenant_name,
                    t.business_type,
                    t.registration_number,
                    t.tax_number,
                    t.email AS tenant_email,
                    t.phone AS tenant_phone,
                    t.website_url,
                    t.country_id AS tenant_country_id,
                    t.currency_id AS tenant_currency_id,
                    t.timezone AS tenant_timezone,
                    t.date_format AS tenant_date_format,
                    t.logo_path AS tenant_logo_path,
                    t.invoice_logo_path AS tenant_invoice_logo_path,
                    t.status AS tenant_status,

                    b.branch_code,
                    b.name AS branch_name,
                    b.country_id AS branch_country_id,
                    b.currency_id AS branch_currency_id,
                    b.timezone AS branch_timezone,
                    b.logo_path AS branch_logo_path,
                    b.invoice_logo_path AS branch_invoice_logo_path,
                    b.is_head_office,
                    b.status AS branch_status,

                    d.name AS department_name,
                    d.code AS department_code,
                    d.status AS department_status,

                    r.name AS role_name,
                    r.code AS role_code,
                    r.is_admin AS role_is_admin,
                    r.is_system_role,
                    r.status AS role_status,

                    tc.name AS tenant_country_name,
                    tc.iso2 AS tenant_country_iso2,

                    tcur.currency_code AS tenant_currency_code,
                    tcur.currency_name AS tenant_currency_name,
                    tcur.symbol AS tenant_currency_symbol,
                    tcur.symbol_position AS tenant_currency_symbol_position,
                    tcur.decimal_places AS tenant_currency_decimal_places

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

                LEFT JOIN countries tc
                    ON tc.id = t.country_id

                LEFT JOIN currencies tcur
                    ON tcur.id = t.currency_id
            ";

            if ($isEmail) {

                $stmt = $pdo->prepare(
                    $baseSelect . "
                    WHERE LOWER(u.email) = :identifier
                      AND u.deleted_at IS NULL
                    ORDER BY
                        u.is_tenant_admin DESC,
                        u.id ASC
                    "
                );

            } else {

                $stmt = $pdo->prepare(
                    $baseSelect . "
                    WHERE UPPER(t.tenant_code) = :identifier
                      AND u.is_tenant_admin = 1
                      AND u.deleted_at IS NULL
                    ORDER BY u.id ASC
                    LIMIT 1
                    "
                );
            }

            $stmt->execute(array(
                ':identifier' => $lookupValue
            ));

            if ($isEmail) {

                $candidates =
                    $stmt->fetchAll();

                $user = false;
                $matchedCount = 0;

                foreach ($candidates as $candidate) {

                    if (
                        password_verify(
                            $password,
                            (string)$candidate['password_hash']
                        )
                    ) {
                        $matchedCount++;
                        $user = $candidate;
                    }
                }

                /*
                 * Same email can technically exist in multiple tenants.
                 * If the same password matches more than one account, do not
                 * guess which tenant the user meant.
                 */
                if ($matchedCount > 1) {
                    $errorMessage =
                        'This email is linked to multiple businesses. Please use your Tenant Code.';

                    tl_audit_failed_login(
                        $pdo,
                        '',
                        strtolower($loginIdentifier),
                        'ambiguous_email_login'
                    );

                    $user = false;
                }

            } else {

                $user =
                    $stmt->fetch();
            }

            /*
             * Use one generic credential error for unknown tenant/user/password.
             */
            if (
                !$user ||
                (
                    !$isEmail &&
                    !password_verify(
                        $password,
                        (string)$user['password_hash']
                    )
                )
            ) {
                if ($errorMessage === '') {
                    $errorMessage =
                        'Invalid Tenant Code / Email or password.';

                    tl_audit_failed_login(
                        $pdo,
                        $isEmail ? '' : strtoupper($loginIdentifier),
                        $isEmail ? strtolower($loginIdentifier) : '',
                        'invalid_credentials',
                        $user ?: null
                    );
                }
            } elseif (
                (string)$user['user_status'] !== 'active'
            ) {
                $errorMessage =
                    'Your user account is not active. Contact your administrator.';

                tl_audit_failed_login(
                    $pdo,
                    (string)$user['tenant_code'],
                    (string)$user['email'],
                    'user_not_active',
                    $user
                );
            } elseif (
                !in_array(
                    (string)$user['tenant_status'],
                    array('trial', 'active'),
                    true
                )
            ) {
                $errorMessage =
                    'This business account is not currently active. Contact FieldPlx support.';

                tl_audit_failed_login(
                    $pdo,
                    (string)$user['tenant_code'],
                    (string)$user['email'],
                    'tenant_not_active',
                    $user
                );
            } elseif (
                !empty($user['branch_id']) &&
                !empty($user['branch_status']) &&
                (string)$user['branch_status'] !== 'active'
            ) {
                $errorMessage =
                    'Your assigned branch is not active. Contact your administrator.';

                tl_audit_failed_login(
                    $pdo,
                    (string)$user['tenant_code'],
                    (string)$user['email'],
                    'branch_not_active',
                    $user
                );
            } elseif (
                !empty($user['department_id']) &&
                !empty($user['department_status']) &&
                (string)$user['department_status'] !== 'active'
            ) {
                $errorMessage =
                    'Your assigned department is not active. Contact your administrator.';

                tl_audit_failed_login(
                    $pdo,
                    (string)$user['tenant_code'],
                    (string)$user['email'],
                    'department_not_active',
                    $user
                );
            } elseif (
                !empty($user['role_id']) &&
                !empty($user['role_status']) &&
                (string)$user['role_status'] !== 'active'
            ) {
                $errorMessage =
                    'Your assigned role is not active. Contact your administrator.';

                tl_audit_failed_login(
                    $pdo,
                    (string)$user['tenant_code'],
                    (string)$user['email'],
                    'role_not_active',
                    $user
                );
            } else {

                /*
                |--------------------------------------------------------------------------
                | Current subscription
                |--------------------------------------------------------------------------
                |
                | Pick the newest usable current subscription.
                |
                */
                $subscription = null;

                if (
                    tl_table_exists(
                        $pdo,
                        'subscriptions'
                    )
                ) {
                    $subStmt = $pdo->prepare("
                        SELECT
                            s.id AS subscription_id,
                            s.plan_id,
                            s.currency_id AS subscription_currency_id,
                            s.amount AS subscription_amount,
                            s.start_date AS subscription_start_date,
                            s.expiry_date AS subscription_expiry_date,
                            s.trial_end_date,
                            s.auto_renew,
                            s.max_users_override,
                            s.max_branches_override,
                            s.max_customers_override,
                            s.storage_limit_mb_override,
                            s.status AS subscription_status,

                            p.name AS plan_name,
                            p.code AS plan_code,
                            p.billing_cycle,
                            p.duration_days,
                            p.trial_days,
                            p.max_users AS plan_max_users,
                            p.max_branches AS plan_max_branches,
                            p.max_customers AS plan_max_customers,
                            p.storage_limit_mb AS plan_storage_limit_mb,
                            p.api_calls_per_month,
                            p.sms_per_month,
                            p.email_per_month,
                            p.ai_minutes_per_month,

                            scur.currency_code AS subscription_currency_code,
                            scur.symbol AS subscription_currency_symbol

                        FROM subscriptions s

                        LEFT JOIN plans p
                            ON p.id = s.plan_id
                           AND p.deleted_at IS NULL

                        LEFT JOIN currencies scur
                            ON scur.id = s.currency_id

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
                        ':tenant_id' =>
                            (int)$user['tenant_id']
                    ));

                    $subscription =
                        $subStmt->fetch();
                }

                /*
                 * A tenant can sign in only when subscription is usable.
                 * This can be relaxed later if you want a billing-only
                 * "subscription expired" screen.
                 */
                if (
                    $subscription &&
                    !in_array(
                        (string)$subscription['subscription_status'],
                        array('trial', 'active'),
                        true
                    )
                ) {
                    $errorMessage =
                        'Your subscription is not active. Contact your administrator or renew the subscription.';

                    tl_audit_failed_login(
                        $pdo,
                        (string)$user['tenant_code'],
                        (string)$user['email'],
                        'subscription_not_active',
                        $user
                    );
                } elseif (
                    $subscription &&
                    !empty(
                        $subscription['subscription_expiry_date']
                    ) &&
                    $subscription['subscription_expiry_date'] <
                        date('Y-m-d')
                ) {
                    $errorMessage =
                        'Your subscription has expired. Please renew your subscription.';

                    tl_audit_failed_login(
                        $pdo,
                        (string)$user['tenant_code'],
                        (string)$user['email'],
                        'subscription_expired',
                        $user
                    );
                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Successful login
                    |--------------------------------------------------------------------------
                    */
                    session_regenerate_id(true);

                    $fullName =
                        trim(
                            (string)$user['first_name'] .
                            ' ' .
                            (string)$user['last_name']
                        );

                    if ($fullName === '') {
                        $fullName =
                            (string)$user['email'];
                    }

                    /*
                     * Effective operational localization.
                     * Branch settings override tenant defaults when assigned.
                     */
                    $effectiveTimezone =
                        !empty($user['branch_timezone'])
                            ? (string)$user['branch_timezone']
                            : (string)$user['tenant_timezone'];

                    if ($effectiveTimezone === '') {
                        $effectiveTimezone = 'UTC';
                    }

                    $effectiveCurrencyId =
                        !empty($user['branch_currency_id'])
                            ? (int)$user['branch_currency_id']
                            : (int)$user['tenant_currency_id'];

                    $effectiveCountryId =
                        !empty($user['branch_country_id'])
                            ? (int)$user['branch_country_id']
                            : (int)$user['tenant_country_id'];

                    $effectiveLogo =
                        !empty($user['branch_logo_path'])
                            ? (string)$user['branch_logo_path']
                            : (string)$user['tenant_logo_path'];

                    $effectiveInvoiceLogo =
                        !empty($user['branch_invoice_logo_path'])
                            ? (string)$user['branch_invoice_logo_path']
                            : (string)$user['tenant_invoice_logo_path'];

                    /*
                     * Effective subscription limits.
                     * Override wins over plan default.
                     */
                    $maxUsers = null;
                    $maxBranches = null;
                    $maxCustomers = null;
                    $storageLimitMb = null;

                    if ($subscription) {

                        $maxUsers =
                            $subscription['max_users_override'] !== null
                                ? (int)$subscription['max_users_override']
                                : (
                                    $subscription['plan_max_users'] !== null
                                        ? (int)$subscription['plan_max_users']
                                        : null
                                );

                        $maxBranches =
                            $subscription['max_branches_override'] !== null
                                ? (int)$subscription['max_branches_override']
                                : (
                                    $subscription['plan_max_branches'] !== null
                                        ? (int)$subscription['plan_max_branches']
                                        : null
                                );

                        $maxCustomers =
                            $subscription['max_customers_override'] !== null
                                ? (int)$subscription['max_customers_override']
                                : (
                                    $subscription['plan_max_customers'] !== null
                                        ? (int)$subscription['plan_max_customers']
                                        : null
                                );

                        $storageLimitMb =
                            $subscription['storage_limit_mb_override'] !== null
                                ? (int)$subscription['storage_limit_mb_override']
                                : (
                                    $subscription['plan_storage_limit_mb'] !== null
                                        ? (int)$subscription['plan_storage_limit_mb']
                                        : null
                                );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Core authentication session
                    |--------------------------------------------------------------------------
                    */
                    $_SESSION['tenant_authenticated'] = true;
                    $_SESSION['tenant_login_at'] = time();
                    $_SESSION['tenant_last_activity'] = time();
                    $_SESSION['tenant_session_id'] = session_id();

                    /*
                    |--------------------------------------------------------------------------
                    | Tenant / business context
                    |--------------------------------------------------------------------------
                    */
                    $_SESSION['tenant_id'] =
                        (int)$user['tenant_id'];

                    $_SESSION['tenant_code'] =
                        (string)$user['tenant_code'];

                    $_SESSION['tenant_name'] =
                        (string)$user['tenant_name'];

                    $_SESSION['tenant_legal_name'] =
                        (string)$user['legal_name'];

                    $_SESSION['tenant_business_type'] =
                        (string)($user['business_type'] ?? '');

                    $_SESSION['tenant_status'] =
                        (string)$user['tenant_status'];

                    $_SESSION['tenant_email'] =
                        (string)($user['tenant_email'] ?? '');

                    $_SESSION['tenant_phone'] =
                        (string)($user['tenant_phone'] ?? '');

                    $_SESSION['tenant_country_id'] =
                        (int)$user['tenant_country_id'];

                    $_SESSION['tenant_country_name'] =
                        (string)($user['tenant_country_name'] ?? '');

                    $_SESSION['tenant_country_iso2'] =
                        (string)($user['tenant_country_iso2'] ?? '');

                    $_SESSION['tenant_currency_id'] =
                        (int)$user['tenant_currency_id'];

                    $_SESSION['tenant_currency_code'] =
                        (string)($user['tenant_currency_code'] ?? '');

                    $_SESSION['tenant_currency_name'] =
                        (string)($user['tenant_currency_name'] ?? '');

                    $_SESSION['tenant_currency_symbol'] =
                        (string)($user['tenant_currency_symbol'] ?? '');

                    $_SESSION['tenant_currency_symbol_position'] =
                        (string)($user['tenant_currency_symbol_position'] ?? 'before');

                    $_SESSION['tenant_currency_decimal_places'] =
                        isset($user['tenant_currency_decimal_places'])
                            ? (int)$user['tenant_currency_decimal_places']
                            : 2;

                    $_SESSION['tenant_timezone'] =
                        (string)$user['tenant_timezone'];

                    $_SESSION['tenant_date_format'] =
                        (string)$user['tenant_date_format'];

                    $_SESSION['tenant_logo_path'] =
                        (string)($user['tenant_logo_path'] ?? '');

                    $_SESSION['tenant_invoice_logo_path'] =
                        (string)($user['tenant_invoice_logo_path'] ?? '');

                    /*
                    |--------------------------------------------------------------------------
                    | Current user context
                    |--------------------------------------------------------------------------
                    */
                    $_SESSION['tenant_user_id'] =
                        (int)$user['user_id'];

                    $_SESSION['tenant_user_first_name'] =
                        (string)$user['first_name'];

                    $_SESSION['tenant_user_last_name'] =
                        (string)($user['last_name'] ?? '');

                    $_SESSION['tenant_user_name'] =
                        $fullName;

                    $_SESSION['tenant_user_email'] =
                        (string)$user['email'];

                    $_SESSION['tenant_user_phone'] =
                        (string)($user['phone'] ?? '');

                    $_SESSION['tenant_user_avatar'] =
                        (string)($user['avatar_path'] ?? '');

                    $_SESSION['tenant_user_employee_code'] =
                        (string)($user['employee_code'] ?? '');

                    $_SESSION['tenant_user_job_title'] =
                        (string)($user['job_title'] ?? '');

                    $_SESSION['tenant_user_status'] =
                        (string)$user['user_status'];

                    $_SESSION['tenant_user_is_admin'] =
                        (int)$user['is_tenant_admin'];

                    $_SESSION['tenant_user_is_field_worker'] =
                        (int)$user['is_field_worker'];

                    $_SESSION['tenant_user_is_bookable'] =
                        (int)$user['is_bookable'];

                    /*
                     * Compatibility aliases commonly useful in
                     * business-panel pages.
                     */
                    $_SESSION['user_id'] =
                        (int)$user['user_id'];

                    $_SESSION['user_name'] =
                        $fullName;

                    $_SESSION['user_email'] =
                        (string)$user['email'];

                    /*
                    |--------------------------------------------------------------------------
                    | Branch context
                    |--------------------------------------------------------------------------
                    */
                    $_SESSION['branch_id'] =
                        !empty($user['branch_id'])
                            ? (int)$user['branch_id']
                            : 0;

                    $_SESSION['branch_code'] =
                        (string)($user['branch_code'] ?? '');

                    $_SESSION['branch_name'] =
                        (string)($user['branch_name'] ?? '');

                    $_SESSION['branch_is_head_office'] =
                        isset($user['is_head_office'])
                            ? (int)$user['is_head_office']
                            : 0;

                    $_SESSION['branch_status'] =
                        (string)($user['branch_status'] ?? '');

                    /*
                    |--------------------------------------------------------------------------
                    | Department context
                    |--------------------------------------------------------------------------
                    */
                    $_SESSION['department_id'] =
                        !empty($user['department_id'])
                            ? (int)$user['department_id']
                            : 0;

                    $_SESSION['department_name'] =
                        (string)($user['department_name'] ?? '');

                    $_SESSION['department_code'] =
                        (string)($user['department_code'] ?? '');

                    /*
                    |--------------------------------------------------------------------------
                    | Role context
                    |--------------------------------------------------------------------------
                    */
                    $_SESSION['role_id'] =
                        !empty($user['role_id'])
                            ? (int)$user['role_id']
                            : 0;

                    $_SESSION['role_name'] =
                        (string)($user['role_name'] ?? '');

                    $_SESSION['role_code'] =
                        (string)($user['role_code'] ?? '');

                    $_SESSION['role_is_admin'] =
                        isset($user['role_is_admin'])
                            ? (int)$user['role_is_admin']
                            : 0;

                    $_SESSION['role_is_system'] =
                        isset($user['is_system_role'])
                            ? (int)$user['is_system_role']
                            : 0;

                    /*
                    |--------------------------------------------------------------------------
                    | Effective context
                    |--------------------------------------------------------------------------
                    */
                    $_SESSION['effective_timezone'] =
                        $effectiveTimezone;

                    $_SESSION['effective_country_id'] =
                        $effectiveCountryId;

                    $_SESSION['effective_currency_id'] =
                        $effectiveCurrencyId;

                    $_SESSION['effective_logo_path'] =
                        $effectiveLogo;

                    $_SESSION['effective_invoice_logo_path'] =
                        $effectiveInvoiceLogo;

                    /*
                    |--------------------------------------------------------------------------
                    | Subscription / plan context
                    |--------------------------------------------------------------------------
                    */
                    $_SESSION['subscription_id'] =
                        $subscription
                            ? (int)$subscription['subscription_id']
                            : 0;

                    $_SESSION['subscription_status'] =
                        $subscription
                            ? (string)$subscription['subscription_status']
                            : '';

                    $_SESSION['subscription_start_date'] =
                        $subscription
                            ? (string)$subscription['subscription_start_date']
                            : '';

                    $_SESSION['subscription_expiry_date'] =
                        $subscription
                            ? (string)($subscription['subscription_expiry_date'] ?? '')
                            : '';

                    $_SESSION['subscription_trial_end_date'] =
                        $subscription
                            ? (string)($subscription['trial_end_date'] ?? '')
                            : '';

                    $_SESSION['subscription_auto_renew'] =
                        $subscription
                            ? (int)$subscription['auto_renew']
                            : 0;

                    $_SESSION['plan_id'] =
                        $subscription
                            ? (int)$subscription['plan_id']
                            : 0;

                    $_SESSION['plan_name'] =
                        $subscription
                            ? (string)($subscription['plan_name'] ?? '')
                            : '';

                    $_SESSION['plan_code'] =
                        $subscription
                            ? (string)($subscription['plan_code'] ?? '')
                            : '';

                    $_SESSION['plan_billing_cycle'] =
                        $subscription
                            ? (string)($subscription['billing_cycle'] ?? '')
                            : '';

                    $_SESSION['plan_max_users'] =
                        $maxUsers;

                    $_SESSION['plan_max_branches'] =
                        $maxBranches;

                    $_SESSION['plan_max_customers'] =
                        $maxCustomers;

                    $_SESSION['plan_storage_limit_mb'] =
                        $storageLimitMb;

                    $_SESSION['plan_api_calls_per_month'] =
                        $subscription &&
                        $subscription['api_calls_per_month'] !== null
                            ? (int)$subscription['api_calls_per_month']
                            : null;

                    $_SESSION['plan_sms_per_month'] =
                        $subscription &&
                        $subscription['sms_per_month'] !== null
                            ? (int)$subscription['sms_per_month']
                            : null;

                    $_SESSION['plan_email_per_month'] =
                        $subscription &&
                        $subscription['email_per_month'] !== null
                            ? (int)$subscription['email_per_month']
                            : null;

                    $_SESSION['plan_ai_minutes_per_month'] =
                        $subscription &&
                        $subscription['ai_minutes_per_month'] !== null
                            ? (int)$subscription['ai_minutes_per_month']
                            : null;

                    /*
                    |--------------------------------------------------------------------------
                    | Tenant-panel CSRF
                    |--------------------------------------------------------------------------
                    */
                    $_SESSION['tenant_csrf_token'] =
                        bin2hex(
                            random_bytes(32)
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | Update last login
                    |--------------------------------------------------------------------------
                    */
                    $updateLogin =
                        $pdo->prepare("
                            UPDATE users
                            SET last_login_at = NOW()
                            WHERE id = :id
                              AND tenant_id = :tenant_id
                              AND deleted_at IS NULL
                        ");

                    $updateLogin->execute(array(
                        ':id' =>
                            (int)$user['user_id'],
                        ':tenant_id' =>
                            (int)$user['tenant_id']
                    ));

                    /*
                    |--------------------------------------------------------------------------
                    | Optional activity event
                    |--------------------------------------------------------------------------
                    */
                    if (
                        tl_table_exists(
                            $pdo,
                            'activity_events'
                        )
                    ) {
                        try {
                            $activityColumns = array();

                            $activityColumnStmt =
                                $pdo->query("
                                    SELECT COLUMN_NAME
                                    FROM INFORMATION_SCHEMA.COLUMNS
                                    WHERE TABLE_SCHEMA = DATABASE()
                                      AND TABLE_NAME = 'activity_events'
                                ");

                            foreach (
                                $activityColumnStmt->fetchAll(
                                    PDO::FETCH_COLUMN
                                ) as $columnName
                            ) {
                                $activityColumns[
                                    (string)$columnName
                                ] = true;
                            }

                            /*
                             * Only log when the common core columns
                             * actually exist. Authentication never fails
                             * because activity logging failed.
                             */
                            if (
                                isset($activityColumns['tenant_id']) &&
                                isset($activityColumns['event_type']) &&
                                isset($activityColumns['title'])
                            ) {
                                $columns = array(
                                    'tenant_id',
                                    'event_type',
                                    'title'
                                );

                                $values = array(
                                    ':tenant_id',
                                    ':event_type',
                                    ':title'
                                );

                                $activityParams = array(
                                    ':tenant_id' =>
                                        (int)$user['tenant_id'],
                                    ':event_type' =>
                                        'tenant_login',
                                    ':title' =>
                                        $fullName . ' signed in'
                                );

                                if (
                                    isset(
                                        $activityColumns[
                                            'actor_user_id'
                                        ]
                                    )
                                ) {
                                    $columns[] =
                                        'actor_user_id';

                                    $values[] =
                                        ':actor_user_id';

                                    $activityParams[
                                        ':actor_user_id'
                                    ] =
                                        (int)$user['user_id'];
                                }

                                if (
                                    isset(
                                        $activityColumns[
                                            'branch_id'
                                        ]
                                    )
                                ) {
                                    $columns[] =
                                        'branch_id';

                                    $values[] =
                                        ':branch_id';

                                    $activityParams[
                                        ':branch_id'
                                    ] =
                                        !empty($user['branch_id'])
                                            ? (int)$user['branch_id']
                                            : null;
                                }

                                $activitySql =
                                    "INSERT INTO activity_events (" .
                                    implode(', ', $columns) .
                                    ") VALUES (" .
                                    implode(', ', $values) .
                                    ")";

                                $activityStmt =
                                    $pdo->prepare(
                                        $activitySql
                                    );

                                $activityStmt->execute(
                                    $activityParams
                                );
                            }
                        } catch (Throwable $ignored) {
                            /*
                             * Do not block login due to audit/activity
                             * compatibility differences.
                             */
                        }
                    }

                    /*
                     * Apply business timezone for this request.
                     */
                    if (
                        in_array(
                            $effectiveTimezone,
                            timezone_identifiers_list(),
                            true
                        )
                    ) {
                        date_default_timezone_set(
                            $effectiveTimezone
                        );
                    }

                    tenantAuditLog(
                        $pdo,
                        'LOGIN_SUCCESS',
                        (int)$user['tenant_id'],
                        !empty($user['branch_id'])
                            ? (int)$user['branch_id']
                            : null,
                        (int)$user['user_id'],
                        'tenant_login',
                        (int)$user['user_id'],
                        null,
                        array(
                            'result' =>
                                'success',
                            'tenant_code' =>
                                (string)$user['tenant_code'],
                            'user_email' =>
                                (string)$user['email'],
                            'user_name' =>
                                $fullName,
                            'role_id' =>
                                !empty($user['role_id'])
                                    ? (int)$user['role_id']
                                    : null,
                            'role_code' =>
                                (string)($user['role_code'] ?? ''),
                            'branch_id' =>
                                !empty($user['branch_id'])
                                    ? (int)$user['branch_id']
                                    : null,
                            'department_id' =>
                                !empty($user['department_id'])
                                    ? (int)$user['department_id']
                                    : null,
                            'subscription_id' =>
                                $subscription
                                    ? (int)$subscription['subscription_id']
                                    : null,
                            'plan_id' =>
                                $subscription
                                    ? (int)$subscription['plan_id']
                                    : null,
                            'session_id' =>
                                session_id(),
                            'login_at' =>
                                date('Y-m-d H:i:s'),
                            'return_to' =>
                                $returnTo
                        )
                    );

                    unset(
                        $_SESSION['tenant_login_csrf']
                    );

                    header(
                        'Location: ' . $returnTo
                    );
                    exit;
                }
            }

        } catch (Throwable $e) {

            error_log(
                'FieldPlx tenant login error: ' .
                $e->getMessage()
            );

            $errorMessage =
                'Unable to sign in right now. Please try again.';

            tl_audit_failed_login(
                $pdo,
                $isEmail && isset($isEmail) && !$isEmail
                    ? strtoupper($loginIdentifier)
                    : '',
                isset($isEmail) && $isEmail
                    ? strtolower($loginIdentifier)
                    : $loginIdentifier,
                'server_error'
            );
        }
    }
}

$returnTo =
    tl_safe_return_path(
        $_GET['return_to'] ?? 'index.php'
    );
?>
<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>Tenant Login - FieldPlx</title>

<?php require_once __DIR__ . '/includes/links.php'; ?>

<style>
:root{
    --fd-navy:#001131;
    --fd-navy-light:#071f49;
    --fd-green:#74b824;
    --fd-green-dark:#5d971b;
    --fd-green-soft:#f0f8e5;
    --fd-bg:#f6f8fb;
    --fd-text:#0b1933;
    --fd-muted:#6f7b90;
    --fd-border:#e5eaf1;
    --fd-danger:#dc2626
}

*{
    box-sizing:border-box
}

html,
body{
    min-height:100%
}

body{
    margin:0;
    min-height:100vh;
    display:grid;
    place-items:center;
    padding:22px;
    overflow-x:hidden;
    background:
        radial-gradient(
            circle at top right,
            rgba(116,184,36,.11),
            transparent 28%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f3f6fa 55%,
            #eef3f8 100%
        );
    color:var(--fd-text);
    font-family:Arial,Helvetica,sans-serif;
    font-size:14px
}

a{
    text-decoration:none
}

button,
input{
    font:inherit
}

.tl-shell{
    width:min(960px,100%);
    min-height:570px;
    display:grid;
    grid-template-columns:minmax(0,1fr) 440px;
    overflow:hidden;
    border:1px solid #dfe6ef;
    border-radius:20px;
    background:#fff;
    box-shadow:
        0 28px 70px rgba(0,17,49,.11)
}

.tl-brand-panel{
    position:relative;
    min-height:570px;
    padding:46px;
    overflow:hidden;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
    color:#fff;
    background:
        linear-gradient(
            150deg,
            var(--fd-navy-light),
            var(--fd-navy)
        )
}

.tl-brand-panel:before,
.tl-brand-panel:after{
    position:absolute;
    content:"";
    border-radius:50%;
    pointer-events:none
}

.tl-brand-panel:before{
    width:320px;
    height:320px;
    right:-160px;
    top:-100px;
    border:55px solid rgba(116,184,36,.10)
}

.tl-brand-panel:after{
    width:210px;
    height:210px;
    left:-105px;
    bottom:-95px;
    background:rgba(116,184,36,.08)
}

.tl-brand-content,
.tl-brand-footer{
    position:relative;
    z-index:2
}

.tl-logo{
    width:54px;
    height:54px;
    display:grid;
    place-items:center;
    border-radius:14px;
    color:#fff;
    background:
        linear-gradient(
            135deg,
            #8fd236,
            #68aa1d
        );
    box-shadow:
        0 12px 28px rgba(0,0,0,.18);
    font-size:24px;
    font-weight:800
}

.tl-brand-title{
    margin:30px 0 10px;
    max-width:420px;
    font-size:32px;
    line-height:1.15;
    font-weight:700;
    letter-spacing:-.5px
}

.tl-brand-description{
    max-width:430px;
    margin:0;
    color:rgba(255,255,255,.72);
    font-size:13px;
    line-height:1.75
}

.tl-feature-list{
    margin-top:31px;
    display:grid;
    gap:13px
}

.tl-feature{
    display:flex;
    align-items:center;
    gap:10px;
    color:rgba(255,255,255,.88);
    font-size:11px
}

.tl-feature-icon{
    width:28px;
    height:28px;
    flex:0 0 28px;
    display:grid;
    place-items:center;
    border-radius:8px;
    color:#a7dc61;
    background:rgba(255,255,255,.08);
    font-size:13px
}

.tl-brand-footer{
    color:rgba(255,255,255,.48);
    font-size:9px
}

.tl-login-panel{
    min-height:570px;
    padding:45px 42px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    background:#fff
}

.tl-mobile-logo{
    display:none
}

.tl-login-title{
    margin:0;
    color:var(--fd-navy);
    font-size:25px;
    font-weight:700
}

.tl-login-subtitle{
    margin:8px 0 27px;
    color:var(--fd-muted);
    font-size:11px;
    line-height:1.55
}

.tl-alert{
    margin-bottom:17px;
    padding:11px 12px;
    display:flex;
    align-items:flex-start;
    gap:9px;
    border:1px solid #fecaca;
    border-radius:9px;
    color:#b91c1c;
    background:#fff7f7;
    font-size:10px;
    line-height:1.5
}

.tl-alert i{
    margin-top:1px;
    font-size:13px
}

.tl-alert.success{
    border-color:#cce9a9;
    color:#4d7d16;
    background:#f4faec
}

.tl-field{
    margin-bottom:16px
}

.tl-field label{
    margin-bottom:7px;
    display:block;
    color:#384762;
    font-size:10px;
    font-weight:700
}

.tl-input-wrap{
    position:relative
}

.tl-input-icon{
    position:absolute;
    left:13px;
    top:50%;
    transform:translateY(-50%);
    color:#97a4b5;
    font-size:15px;
    pointer-events:none
}

.tl-input{
    width:100%;
    height:46px;
    padding:9px 42px 9px 39px;
    border:1px solid #dbe2eb;
    border-radius:9px;
    outline:0;
    color:var(--fd-navy);
    background:#fff;
    font-size:12px;
    transition:
        border-color .18s ease,
        box-shadow .18s ease
}

.tl-input::placeholder{
    color:#a3adba
}

.tl-input:focus{
    border-color:#a6cb72;
    box-shadow:
        0 0 0 3px rgba(116,184,36,.12)
}

.tl-password-toggle{
    width:36px;
    height:36px;
    position:absolute;
    right:5px;
    top:50%;
    transform:translateY(-50%);
    display:grid;
    place-items:center;
    border:0;
    border-radius:7px;
    color:#8390a3;
    background:transparent;
    cursor:pointer;
    font-size:14px
}

.tl-password-toggle:hover{
    color:var(--fd-green-dark);
    background:var(--fd-green-soft)
}

.tl-row{
    margin:-2px 0 20px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px
}

.tl-remember{
    display:flex;
    align-items:center;
    gap:7px;
    color:var(--fd-muted);
    font-size:9.5px;
    cursor:pointer
}

.tl-remember input{
    width:14px;
    height:14px;
    accent-color:var(--fd-green)
}

.tl-help{
    color:var(--fd-green-dark);
    font-size:9.5px;
    font-weight:700
}

.tl-help:hover{
    color:var(--fd-green)
}

.tl-submit{
    width:100%;
    height:47px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    border:0;
    border-radius:9px;
    color:#fff;
    background:
        linear-gradient(
            90deg,
            #7fc92d,
            #68aa1d
        );
    box-shadow:
        0 9px 24px rgba(104,170,29,.22);
    cursor:pointer;
    font-size:11px;
    font-weight:700
}

.tl-submit:hover{
    background:
        linear-gradient(
            90deg,
            #74b824,
            #5d971b
        )
}

.tl-submit:disabled{
    opacity:.68;
    cursor:not-allowed
}

.tl-loader{
    width:14px;
    height:14px;
    display:none;
    border:2px dotted rgba(255,255,255,.95);
    border-radius:50%;
    animation:tlSpin .75s linear infinite
}

.tl-submit.loading .tl-loader{
    display:inline-block
}

@keyframes tlSpin{
    to{
        transform:rotate(360deg)
    }
}

.tl-security{
    margin-top:19px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    color:#96a0af;
    font-size:8.5px
}

.tl-security i{
    color:var(--fd-green-dark);
    font-size:11px
}

@media(max-width:820px){
    body{
        padding:0;
        background:#fff
    }

    .tl-shell{
        width:100%;
        min-height:100vh;
        display:block;
        border:0;
        border-radius:0;
        box-shadow:none
    }

    .tl-brand-panel{
        display:none
    }

    .tl-login-panel{
        min-height:100vh;
        max-width:470px;
        margin:auto;
        padding:34px 27px
    }

    .tl-mobile-logo{
        width:48px;
        height:48px;
        margin-bottom:28px;
        display:grid;
        place-items:center;
        border-radius:13px;
        color:#fff;
        background:
            linear-gradient(
                135deg,
                #8fd236,
                #68aa1d
            );
        font-size:20px;
        font-weight:800
    }
}

@media(max-width:420px){
    .tl-login-panel{
        padding:28px 20px
    }

    .tl-login-title{
        font-size:22px
    }

    .tl-row{
        align-items:flex-start;
        flex-direction:column
    }
}
</style>

</head>

<body>

<div class="tl-shell">

    <section class="tl-brand-panel">

        <div class="tl-brand-content">

            <div class="tl-logo">
                F
            </div>

            <h1 class="tl-brand-title">
                Field service,
                organized around your business.
            </h1>

            <p class="tl-brand-description">
                Sign in to manage customers, jobs, teams,
                schedules, invoices, payments and field
                operations from one secure workspace.
            </p>

            <div class="tl-feature-list">

                <div class="tl-feature">
                    <span class="tl-feature-icon">
                        <i class="bi bi-briefcase"></i>
                    </span>
                    Jobs, visits and workforce operations
                </div>

                <div class="tl-feature">
                    <span class="tl-feature-icon">
                        <i class="bi bi-shield-check"></i>
                    </span>
                    Tenant, role and branch scoped access
                </div>

                <div class="tl-feature">
                    <span class="tl-feature-icon">
                        <i class="bi bi-graph-up-arrow"></i>
                    </span>
                    Billing, reports and business visibility
                </div>

            </div>

        </div>

        <div class="tl-brand-footer">
            FieldPlx Business Workspace
        </div>

    </section>

    <section class="tl-login-panel">

        <div class="tl-mobile-logo">
            F
        </div>

        <h2 class="tl-login-title">
            Welcome back
        </h2>

        <p class="tl-login-subtitle">
            Enter your Tenant Code or Email Address
            and password to continue to FieldPlx.
        </p>

        <?php if ($successMessage !== ''): ?>

        <div class="tl-alert success">
            <i class="bi bi-check-circle"></i>
            <span>
                <?= tl_h($successMessage) ?>
            </span>
        </div>

        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>

        <div class="tl-alert">
            <i class="bi bi-exclamation-circle"></i>
            <span>
                <?= tl_h($errorMessage) ?>
            </span>
        </div>

        <?php endif; ?>

        <form
            method="post"
            id="tenantLoginForm"
            autocomplete="on"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= tl_h($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="return_to"
                value="<?= tl_h($returnTo) ?>"
            >

            <div class="tl-field">

                <label for="loginIdentifier">
                    Tenant Code or Email Address
                </label>

                <div class="tl-input-wrap">

                    <i
                        class="bi bi-person-badge tl-input-icon"
                    ></i>

                    <input
                        type="text"
                        class="tl-input"
                        id="loginIdentifier"
                        name="login_identifier"
                        value="<?= tl_h($loginIdentifier) ?>"
                        maxlength="190"
                        autocomplete="username"
                        placeholder="TNT-0001 or you@company.com"
                        required
                    >

                </div>

            </div>

            <div class="tl-field">

                <label for="loginPassword">
                    Password
                </label>

                <div class="tl-input-wrap">

                    <i
                        class="bi bi-lock tl-input-icon"
                    ></i>

                    <input
                        type="password"
                        class="tl-input"
                        id="loginPassword"
                        name="password"
                        autocomplete="current-password"
                        placeholder="Enter your password"
                        required
                    >

                    <button
                        type="button"
                        class="tl-password-toggle"
                        id="passwordToggle"
                        aria-label="Show password"
                    >
                        <i class="bi bi-eye"></i>
                    </button>

                </div>

            </div>

            <div class="tl-row">

                <label class="tl-remember">
                    <input
                        type="checkbox"
                        id="rememberLogin"
                    >
                    Remember Login
                </label>

                <a
                    href="#"
                    class="tl-help"
                >
                    Need help signing in?
                </a>

            </div>

            <button
                type="submit"
                class="tl-submit"
                id="loginButton"
            >
                <span class="tl-loader"></span>
                <i class="bi bi-box-arrow-in-right"></i>
                <span id="loginButtonText">
                    Sign in to FieldPlx
                </span>
            </button>

        </form>

        <div class="tl-security">
            <i class="bi bi-shield-lock"></i>
            Secure tenant-scoped authentication
        </div>

    </section>

</div>

<script>
(function(){
'use strict';

var form=
    document.getElementById(
        'tenantLoginForm'
    );

var button=
    document.getElementById(
        'loginButton'
    );

var buttonText=
    document.getElementById(
        'loginButtonText'
    );

var password=
    document.getElementById(
        'loginPassword'
    );

var toggle=
    document.getElementById(
        'passwordToggle'
    );

var loginIdentifier=
    document.getElementById(
        'loginIdentifier'
    );

var remember=
    document.getElementById(
        'rememberLogin'
    );

var storageKey=
    'fieldplx_tenant_login';

try{

    var savedTenant=
        localStorage.getItem(
            storageKey
        );

    if(
        savedTenant &&
        !loginIdentifier.value
    ){
        loginIdentifier.value=
            savedTenant;

        remember.checked=true;
    }

}catch(e){}

toggle.addEventListener(
    'click',
    function(){

        var show=
            password.type==='password';

        password.type=
            show
                ? 'text'
                : 'password';

        toggle.innerHTML=
            show
                ? '<i class="bi bi-eye-slash"></i>'
                : '<i class="bi bi-eye"></i>';

        toggle.setAttribute(
            'aria-label',
            show
                ? 'Hide password'
                : 'Show password'
        );
    }
);

form.addEventListener(
    'submit',
    function(){

        try{

            if(remember.checked){

                localStorage.setItem(
                    storageKey,
                    loginIdentifier.value.trim()
                );

            }else{

                localStorage.removeItem(
                    storageKey
                );
            }

        }catch(e){}

        button.disabled=true;
        button.classList.add('loading');

        buttonText.textContent=
            'Signing in...';
    }
);

})();
</script>

</body>
</html>