<?php
/**
 * FieldPlx Mobile REST API - Dashboard
 *
 * Upload as:
 * /public_html/api/dashboard.php
 *
 * Uses:
 * /public_html/includes/db.php
 *
 * Request:
 * GET /api/dashboard.php
 * Authorization: Bearer <access_token>
 *
 * PHP 7.2+ / MySQLi
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

const FIELDPLX_API_SECRET = 'coreplx';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function apiResponse(int $status, array $payload): void
{
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function apiBase64UrlDecode(string $value): string
{
    $remainder = strlen($value) % 4;

    if ($remainder) {
        $value .= str_repeat('=', 4 - $remainder);
    }

    $decoded = base64_decode(
        strtr($value, '-_', '+/'),
        true
    );

    return $decoded === false ? '' : $decoded;
}

function apiAuthorizationHeader(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim((string) $_SERVER['HTTP_AUTHORIZATION']);
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strtolower((string) $key) === 'authorization') {
                    return trim((string) $value);
                }
            }
        }
    }

    return '';
}

function apiBearerToken(): string
{
    $header = apiAuthorizationHeader();

    if (
        $header === '' ||
        !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)
    ) {
        return '';
    }

    return trim((string) $matches[1]);
}

function apiValidateToken(string $token): array
{
    $parts = explode('.', $token);

    if (count($parts) !== 3) {
        apiResponse(
            401,
            array(
                'success' => false,
                'code' => 'invalid_token',
                'message' => 'Invalid access token.'
            )
        );
    }

    list($encodedHeader, $encodedPayload, $encodedSignature) = $parts;

    $headerJson = apiBase64UrlDecode($encodedHeader);
    $payloadJson = apiBase64UrlDecode($encodedPayload);
    $signature = apiBase64UrlDecode($encodedSignature);

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (
        !is_array($header) ||
        !is_array($payload) ||
        !isset($header['alg']) ||
        $header['alg'] !== 'HS256'
    ) {
        apiResponse(
            401,
            array(
                'success' => false,
                'code' => 'invalid_token',
                'message' => 'Invalid access token.'
            )
        );
    }

    $expected = hash_hmac(
        'sha256',
        $encodedHeader . '.' . $encodedPayload,
        FIELDPLX_API_SECRET,
        true
    );

    if (
        $signature === '' ||
        !hash_equals($expected, $signature)
    ) {
        apiResponse(
            401,
            array(
                'success' => false,
                'code' => 'invalid_token',
                'message' => 'Invalid access token signature.'
            )
        );
    }

    $now = time();

    if (
        !isset($payload['exp']) ||
        (int) $payload['exp'] <= $now
    ) {
        apiResponse(
            401,
            array(
                'success' => false,
                'code' => 'token_expired',
                'message' => 'Access token has expired.'
            )
        );
    }

    if (
        isset($payload['nbf']) &&
        (int) $payload['nbf'] > ($now + 60)
    ) {
        apiResponse(
            401,
            array(
                'success' => false,
                'code' => 'token_not_active',
                'message' => 'Access token is not active yet.'
            )
        );
    }

    if (
        !isset($payload['iss']) ||
        $payload['iss'] !== 'FieldPlx' ||
        !isset($payload['aud']) ||
        $payload['aud'] !== 'FieldPlx-Mobile'
    ) {
        apiResponse(
            401,
            array(
                'success' => false,
                'code' => 'invalid_token',
                'message' => 'Invalid access token.'
            )
        );
    }

    if (
        empty($payload['sub']) ||
        empty($payload['tenant_id'])
    ) {
        apiResponse(
            401,
            array(
                'success' => false,
                'code' => 'invalid_token',
                'message' => 'Access token does not contain the required user information.'
            )
        );
    }

    return $payload;
}

function dashboardFetchAll(mysqli_stmt $stmt): array
{
    $rows = array();

    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }

            return $rows;
        }
    }

    $metadata = $stmt->result_metadata();

    if (!$metadata) {
        return $rows;
    }

    $row = array();
    $bind = array();

    while ($field = $metadata->fetch_field()) {
        $row[$field->name] = null;
        $bind[] = &$row[$field->name];
    }

    call_user_func_array(
        array($stmt, 'bind_result'),
        $bind
    );

    while ($stmt->fetch()) {
        $copy = array();

        foreach ($row as $key => $value) {
            $copy[$key] = $value;
        }

        $rows[] = $copy;
    }

    return $rows;
}

function dashboardFetchOne(mysqli_stmt $stmt)
{
    $rows = dashboardFetchAll($stmt);

    return !empty($rows)
        ? $rows[0]
        : null;
}

function dashboardScalar(
    mysqli $conn,
    string $sql,
    int $tenantId
) {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('i', $tenantId);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception($error);
    }

    $row = dashboardFetchOne($stmt);
    $stmt->close();

    if (!$row) {
        return 0;
    }

    $values = array_values($row);

    return isset($values[0])
        ? $values[0]
        : 0;
}

function dashboardPermissionList(
    mysqli $conn,
    int $tenantId,
    int $userId,
    int $roleId
): array {
    $permissions = array();

    if ($roleId > 0) {
        $stmt = $conn->prepare(
            "SELECT DISTINCT p.code
             FROM role_permissions rp
             INNER JOIN permissions p
                 ON p.id = rp.permission_id
             WHERE rp.role_id = ?
               AND (
                    rp.tenant_id = ?
                    OR rp.tenant_id IS NULL
               )"
        );

        if ($stmt) {
            $stmt->bind_param(
                'ii',
                $roleId,
                $tenantId
            );

            if ($stmt->execute()) {
                foreach (dashboardFetchAll($stmt) as $row) {
                    if (!empty($row['code'])) {
                        $permissions[(string) $row['code']] = true;
                    }
                }
            }

            $stmt->close();
        }
    }

    /*
     * User-specific permission overrides take precedence over the role.
     */
    $stmt = $conn->prepare(
        "SELECT
            p.code,
            up.effect
         FROM user_permissions up
         INNER JOIN permissions p
             ON p.id = up.permission_id
         WHERE up.tenant_id = ?
           AND up.user_id = ?"
    );

    if ($stmt) {
        $stmt->bind_param(
            'ii',
            $tenantId,
            $userId
        );

        if ($stmt->execute()) {
            foreach (dashboardFetchAll($stmt) as $row) {
                $code = isset($row['code'])
                    ? (string) $row['code']
                    : '';

                if ($code === '') {
                    continue;
                }

                if (
                    isset($row['effect']) &&
                    $row['effect'] === 'deny'
                ) {
                    unset($permissions[$code]);
                } else {
                    $permissions[$code] = true;
                }
            }
        }

        $stmt->close();
    }

    $list = array_keys($permissions);
    sort($list);

    return $list;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiResponse(
        405,
        array(
            'success' => false,
            'code' => 'method_not_allowed',
            'message' => 'Only GET requests are allowed.'
        )
    );
}

$token = apiBearerToken();

if ($token === '') {
    apiResponse(
        401,
        array(
            'success' => false,
            'code' => 'missing_token',
            'message' => 'Bearer access token is required.'
        )
    );
}

$claims = apiValidateToken($token);

$userId = (int) $claims['sub'];
$tenantId = (int) $claims['tenant_id'];

try {
    /*
    |--------------------------------------------------------------------------
    | Re-check user, tenant and role on every API request
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        "SELECT
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

            t.company_name,
            t.slug AS company_slug,
            t.logo_path AS company_logo,
            t.timezone,
            t.currency_code,
            t.date_format,
            t.status AS tenant_status,
            t.trial_ends_at,
            t.subscription_plan,
            t.deleted_at AS tenant_deleted_at,

            r.name AS role_name,
            r.code AS role_code,
            r.is_active AS role_is_active

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
         LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param(
        'ii',
        $userId,
        $tenantId
    );

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $user = dashboardFetchOne($stmt);
    $stmt->close();

    if (!$user) {
        apiResponse(
            401,
            array(
                'success' => false,
                'code' => 'user_not_found',
                'message' => 'The user associated with this token no longer exists.'
            )
        );
    }

    if (
        !empty($user['user_deleted_at']) ||
        strtolower((string) $user['user_status']) !== 'active'
    ) {
        apiResponse(
            403,
            array(
                'success' => false,
                'code' => 'user_inactive',
                'message' => 'This user account is not active.'
            )
        );
    }

    if (!empty($user['tenant_deleted_at'])) {
        apiResponse(
            403,
            array(
                'success' => false,
                'code' => 'workspace_unavailable',
                'message' => 'This workspace is unavailable.'
            )
        );
    }

    $tenantStatus = strtolower(
        (string) $user['tenant_status']
    );

    if (
        in_array(
            $tenantStatus,
            array('inactive', 'suspended'),
            true
        )
    ) {
        apiResponse(
            403,
            array(
                'success' => false,
                'code' => 'workspace_inactive',
                'message' => 'This workspace is inactive.'
            )
        );
    }

    if (
        $tenantStatus === 'trial' &&
        !empty($user['trial_ends_at']) &&
        strtotime((string) $user['trial_ends_at']) < time()
    ) {
        apiResponse(
            403,
            array(
                'success' => false,
                'code' => 'trial_expired',
                'message' => 'This workspace trial has expired.'
            )
        );
    }

    if (
        !empty($user['role_id']) &&
        isset($user['role_is_active']) &&
        (int) $user['role_is_active'] !== 1
    ) {
        apiResponse(
            403,
            array(
                'success' => false,
                'code' => 'role_inactive',
                'message' => 'The assigned role is inactive.'
            )
        );
    }

    $permissions = dashboardPermissionList(
        $conn,
        $tenantId,
        $userId,
        (int) $user['role_id']
    );

    if (
        !in_array('dashboard.view', $permissions, true) &&
        (string) $user['role_code'] !== 'owner' &&
        (string) $user['role_code'] !== 'admin'
    ) {
        apiResponse(
            403,
            array(
                'success' => false,
                'code' => 'permission_denied',
                'message' => 'You do not have permission to view the dashboard.'
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Dashboard totals - same rules as dashboard.php
    |--------------------------------------------------------------------------
    */
    $totalClients = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(*)
         FROM clients
         WHERE tenant_id = ?
           AND deleted_at IS NULL
           AND client_type <> 'archived'",
        $tenantId
    );

    $totalRequests = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(*)
         FROM requests
         WHERE tenant_id = ?
           AND archived_at IS NULL
           AND status NOT IN (
                'closed',
                'rejected',
                'archived'
           )",
        $tenantId
    );

    $openJobs = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(*)
         FROM jobs
         WHERE tenant_id = ?
           AND deleted_at IS NULL
           AND status NOT IN (
                'completed',
                'closed',
                'cancelled',
                'archived'
           )",
        $tenantId
    );

    $todayVisits = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(*)
         FROM visits
         WHERE tenant_id = ?
           AND scheduled_start IS NOT NULL
           AND DATE(scheduled_start) = CURDATE()
           AND status <> 'cancelled'",
        $tenantId
    );

    $fieldWorkers = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(*)
         FROM users
         WHERE tenant_id = ?
           AND deleted_at IS NULL
           AND status = 'active'
           AND is_field_worker = 1",
        $tenantId
    );

    $unpaidInvoices = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(*)
         FROM invoices
         WHERE tenant_id = ?
           AND archived_at IS NULL
           AND balance_due > 0
           AND status NOT IN (
                'paid',
                'cancelled',
                'written_off',
                'archived'
           )",
        $tenantId
    );

    $outstandingAmount = (float) dashboardScalar(
        $conn,
        "SELECT COALESCE(SUM(balance_due), 0)
         FROM invoices
         WHERE tenant_id = ?
           AND archived_at IS NULL
           AND balance_due > 0
           AND status NOT IN (
                'paid',
                'cancelled',
                'written_off',
                'archived'
           )",
        $tenantId
    );

    /*
    |--------------------------------------------------------------------------
    | Recent jobs
    |--------------------------------------------------------------------------
    */
    $recentJobs = array();

    $stmt = $conn->prepare(
        "SELECT
            j.id,
            j.job_no,
            j.title,
            j.status,
            j.start_date,
            j.created_at,
            c.id AS client_id,
            c.display_name AS client_name
         FROM jobs j
         INNER JOIN clients c
             ON c.id = j.client_id
            AND c.tenant_id = j.tenant_id
         WHERE j.tenant_id = ?
           AND j.deleted_at IS NULL
         ORDER BY j.created_at DESC
         LIMIT 6"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('i', $tenantId);

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $recentJobs = dashboardFetchAll($stmt);
    $stmt->close();

    foreach ($recentJobs as &$job) {
        $job['id'] = (int) $job['id'];
        $job['client_id'] = (int) $job['client_id'];
    }
    unset($job);

    /*
    |--------------------------------------------------------------------------
    | Upcoming visits
    |--------------------------------------------------------------------------
    */
    $upcomingVisits = array();

    $stmt = $conn->prepare(
        "SELECT
            v.id,
            v.visit_no,
            v.status,
            v.scheduled_start,
            v.scheduled_end,
            v.assigned_user_id,
            j.id AS job_id,
            j.job_no,
            j.title AS job_title,
            c.id AS client_id,
            c.display_name AS client_name,
            CONCAT(
                COALESCE(u.first_name, ''),
                CASE
                    WHEN COALESCE(u.last_name, '') <> ''
                    THEN CONCAT(' ', u.last_name)
                    ELSE ''
                END
            ) AS worker_name
         FROM visits v
         INNER JOIN jobs j
             ON j.id = v.job_id
            AND j.tenant_id = v.tenant_id
         INNER JOIN clients c
             ON c.id = j.client_id
            AND c.tenant_id = j.tenant_id
         LEFT JOIN users u
             ON u.id = v.assigned_user_id
            AND u.tenant_id = v.tenant_id
         WHERE v.tenant_id = ?
           AND v.scheduled_start IS NOT NULL
           AND v.scheduled_start >= NOW()
           AND v.status NOT IN (
                'completed',
                'cancelled',
                'missed'
           )
         ORDER BY v.scheduled_start ASC
         LIMIT 5"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('i', $tenantId);

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $upcomingVisits = dashboardFetchAll($stmt);
    $stmt->close();

    foreach ($upcomingVisits as &$visit) {
        $visit['id'] = (int) $visit['id'];
        $visit['job_id'] = (int) $visit['job_id'];
        $visit['client_id'] = (int) $visit['client_id'];

        $visit['assigned_user_id'] =
            $visit['assigned_user_id'] !== null
                ? (int) $visit['assigned_user_id']
                : null;
    }
    unset($visit);

    /*
    |--------------------------------------------------------------------------
    | Recent activity
    |--------------------------------------------------------------------------
    */
    $recentActivity = array();

    $stmt = $conn->prepare(
        "SELECT
            id,
            actor_user_id,
            actor_type,
            event_type,
            related_type,
            related_id,
            title,
            visible_to_client,
            created_at
         FROM activity_events
         WHERE tenant_id = ?
         ORDER BY created_at DESC
         LIMIT 5"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('i', $tenantId);

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $recentActivity = dashboardFetchAll($stmt);
    $stmt->close();

    foreach ($recentActivity as &$activity) {
        $activity['id'] = (int) $activity['id'];

        $activity['actor_user_id'] =
            $activity['actor_user_id'] !== null
                ? (int) $activity['actor_user_id']
                : null;

        $activity['related_id'] =
            $activity['related_id'] !== null
                ? (int) $activity['related_id']
                : null;

        $activity['visible_to_client'] =
            (bool) $activity['visible_to_client'];
    }
    unset($activity);

    /*
    |--------------------------------------------------------------------------
    | Keep device/session activity fresh
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        "UPDATE users
         SET last_login_at = COALESCE(last_login_at, NOW())
         WHERE id = ?
           AND tenant_id = ?
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param(
            'ii',
            $userId,
            $tenantId
        );

        $stmt->execute();
        $stmt->close();
    }

    $fullName = trim(
        (string) $user['first_name'] . ' ' .
        (string) $user['last_name']
    );

    apiResponse(
        200,
        array(
            'success' => true,
            'message' => 'Dashboard loaded successfully.',
            'data' => array(
                'user' => array(
                    'id' => (int) $user['user_id'],
                    'tenant_id' => (int) $user['tenant_id'],
                    'role_id' => (int) $user['role_id'],
                    'name' => $fullName,
                    'first_name' => (string) $user['first_name'],
                    'last_name' => (string) $user['last_name'],
                    'email' => (string) $user['email'],
                    'phone' => (string) $user['phone'],
                    'avatar_path' => (string) $user['avatar_path'],
                    'job_title' => (string) $user['job_title'],
                    'employee_code' => (string) $user['employee_code'],
                    'is_bookable' => (bool) $user['is_bookable'],
                    'is_field_worker' => (bool) $user['is_field_worker'],
                    'role' => array(
                        'id' => (int) $user['role_id'],
                        'name' => (string) $user['role_name'],
                        'code' => (string) $user['role_code']
                    )
                ),

                'workspace' => array(
                    'id' => (int) $user['tenant_id'],
                    'company_name' => (string) $user['company_name'],
                    'slug' => (string) $user['company_slug'],
                    'logo_path' => (string) $user['company_logo'],
                    'timezone' => (string) $user['timezone'],
                    'currency_code' => (string) $user['currency_code'],
                    'date_format' => (string) $user['date_format'],
                    'status' => (string) $user['tenant_status'],
                    'subscription_plan' => (string) $user['subscription_plan']
                ),

                'stats' => array(
                    'total_clients' => $totalClients,
                    'open_requests' => $totalRequests,
                    'open_jobs' => $openJobs,
                    'today_visits' => $todayVisits,
                    'field_workers' => $fieldWorkers,
                    'unpaid_invoices' => $unpaidInvoices,
                    'outstanding_amount' => $outstandingAmount,
                    'currency_code' => (string) $user['currency_code']
                ),

                'recent_jobs' => $recentJobs,
                'upcoming_visits' => $upcomingVisits,
                'recent_activity' => $recentActivity,

                'permissions' => $permissions,

                'access' => array(
                    'clients' => array(
                        'view' => in_array('clients.view', $permissions, true),
                        'create' => in_array('clients.create', $permissions, true)
                    ),
                    'requests' => array(
                        'view' => in_array('requests.view', $permissions, true),
                        'manage' => in_array('requests.manage', $permissions, true)
                    ),
                    'jobs' => array(
                        'view' => in_array('jobs.view', $permissions, true),
                        'manage' => in_array('jobs.manage', $permissions, true)
                    ),
                    'schedule' => array(
                        'view' => in_array('schedule.view', $permissions, true),
                        'manage' => in_array('schedule.manage', $permissions, true)
                    ),
                    'invoices' => array(
                        'view' => in_array('invoices.view', $permissions, true),
                        'manage' => in_array('invoices.manage', $permissions, true)
                    ),
                    'reports' => array(
                        'view' => in_array('reports.view', $permissions, true)
                    ),
                    'team' => array(
                        'view' => in_array('team.view', $permissions, true),
                        'manage' => in_array('team.manage', $permissions, true)
                    )
                ),

                'generated_at' => gmdate('Y-m-d\TH:i:s\Z')
            )
        )
    );
} catch (Throwable $exception) {
    error_log(
        'FieldPlx dashboard API error: ' .
        $exception->getMessage()
    );

    apiResponse(
        500,
        array(
            'success' => false,
            'code' => 'server_error',
            'message' => 'Unable to load dashboard.'
        )
    );
}
