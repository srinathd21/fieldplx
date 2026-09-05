<?php

declare(strict_types=1);

/**
 * FieldPlx Mobile Invoice Settings API
 *
 * Upload to:
 *   /business/api/mobile/invoice-settings.php
 *
 * Production URL:
 *   https://fieldplx.com/business/api/mobile/invoice-settings.php
 *
 * Authentication:
 *   Authorization: Bearer <access_token>
 *
 * Methods:
 *   GET  /invoice-settings.php
 *   GET  /invoice-settings.php?branch_id=0
 *   GET  /invoice-settings.php?branch_id=2
 *   POST /invoice-settings.php
 *   PUT  /invoice-settings.php
 *
 * POST/PUT:
 *   Use multipart/form-data when uploading logo, invoice_logo or signature.
 *   JSON is also accepted when no files are being uploaded.
 */

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api-config.php';

if (file_exists(__DIR__ . '/../../includes/audit.php')) {
    require_once __DIR__ . '/../../includes/audit.php';
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (
    !defined('FIELDPLX_API_SECRET') ||
    trim((string)FIELDPLX_API_SECRET) === ''
) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error' => array(
            'code' => 'api_secret_not_configured',
            'message' => 'FieldPlx mobile API configuration is incomplete.'
        )
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!defined('FIELDPLX_TOKEN_ISSUER')) {
    define('FIELDPLX_TOKEN_ISSUER', 'FieldPlx');
}

if (!defined('FIELDPLX_TOKEN_AUDIENCE')) {
    define('FIELDPLX_TOKEN_AUDIENCE', 'FieldPlx-Mobile');
}

/* -------------------------------------------------------------------------
 * Response helpers
 * ---------------------------------------------------------------------- */

function fisapi_response($statusCode, $success, $message, $data = null, $errorCode = null)
{
    http_response_code((int)$statusCode);

    if ($errorCode !== null) {
        $payload = array(
            'success' => false,
            'error' => array(
                'code' => (string)$errorCode,
                'message' => (string)$message
            )
        );

        if ($data !== null) {
            $payload['data'] = $data;
        }
    } else {
        $payload = array(
            'success' => (bool)$success,
            'message' => (string)$message,
            'version' => '1.1.0'
        );

        if ($data !== null) {
            $payload['data'] = $data;
        }
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function fisapi_base64url_decode($data)
{
    $data = (string)$data;
    $remainder = strlen($data) % 4;

    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode(
        strtr($data, '-_', '+/'),
        true
    );
}

function fisapi_authorization_header()
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim((string)$_SERVER['HTTP_AUTHORIZATION']);
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strtolower((string)$name) === 'authorization') {
                    return trim((string)$value);
                }
            }
        }
    }

    return '';
}

function fisapi_bearer_token()
{
    $header = fisapi_authorization_header();

    if (
        $header === '' ||
        !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)
    ) {
        return '';
    }

    return trim((string)$matches[1]);
}

function fisapi_verify_token($token)
{
    $parts = explode('.', (string)$token);

    if (count($parts) !== 3) {
        fisapi_response(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token'
        );
    }

    $headerEncoded = $parts[0];
    $payloadEncoded = $parts[1];
    $signatureEncoded = $parts[2];

    $headerJson = fisapi_base64url_decode($headerEncoded);
    $payloadJson = fisapi_base64url_decode($payloadEncoded);
    $signature = fisapi_base64url_decode($signatureEncoded);

    if (
        $headerJson === false ||
        $payloadJson === false ||
        $signature === false
    ) {
        fisapi_response(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token'
        );
    }

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (!is_array($header) || !is_array($payload)) {
        fisapi_response(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token'
        );
    }

    if (
        ($header['alg'] ?? '') !== 'HS256' ||
        ($header['typ'] ?? '') !== 'JWT'
    ) {
        fisapi_response(
            401,
            false,
            'Unsupported access token.',
            null,
            'invalid_token'
        );
    }

    $expectedSignature = hash_hmac(
        'sha256',
        $headerEncoded . '.' . $payloadEncoded,
        FIELDPLX_API_SECRET,
        true
    );

    if (!hash_equals($expectedSignature, $signature)) {
        fisapi_response(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token_signature'
        );
    }

    $now = time();

    if (isset($payload['nbf']) && (int)$payload['nbf'] > $now) {
        fisapi_response(
            401,
            false,
            'Access token is not active yet.',
            null,
            'token_not_active'
        );
    }

    if (!isset($payload['exp']) || (int)$payload['exp'] <= $now) {
        fisapi_response(
            401,
            false,
            'Access token has expired. Please sign in again.',
            null,
            'token_expired'
        );
    }

    if (
        isset($payload['iss']) &&
        (string)$payload['iss'] !== (string)FIELDPLX_TOKEN_ISSUER
    ) {
        fisapi_response(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token_issuer'
        );
    }

    if (
        isset($payload['aud']) &&
        (string)$payload['aud'] !== (string)FIELDPLX_TOKEN_AUDIENCE
    ) {
        fisapi_response(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token_audience'
        );
    }

    if (
        empty($payload['user_id']) ||
        empty($payload['tenant_id'])
    ) {
        fisapi_response(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token_context'
        );
    }

    return $payload;
}

function fisapi_table_exists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name"
    );

    $stmt->execute(array(
        ':table_name' => (string)$table
    ));

    return ((int)$stmt->fetchColumn() > 0);
}

/* -------------------------------------------------------------------------
 * Authenticated current user
 * ---------------------------------------------------------------------- */

function fisapi_require_auth(PDO $pdo)
{
    $token = fisapi_bearer_token();

    if ($token === '') {
        fisapi_response(
            401,
            false,
            'Authorization Bearer token is required.',
            null,
            'authorization_required'
        );
    }

    $claims = fisapi_verify_token($token);

    $userId = (int)$claims['user_id'];
    $tenantId = (int)$claims['tenant_id'];

    $stmt = $pdo->prepare(
        "SELECT
            u.id AS user_id,
            u.tenant_id,
            u.branch_id,
            u.department_id,
            u.role_id,
            u.email,
            u.first_name,
            u.last_name,
            u.is_tenant_admin,
            u.status AS user_status,

            t.tenant_code,
            t.display_name AS tenant_name,
            t.status AS tenant_status,

            b.status AS branch_status,
            d.status AS department_status,
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

         LIMIT 1"
    );

    $stmt->execute(array(
        ':user_id' => $userId,
        ':tenant_id' => $tenantId
    ));

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        fisapi_response(
            401,
            false,
            'Authenticated user account was not found.',
            null,
            'user_not_found'
        );
    }

    if ((string)$user['user_status'] !== 'active') {
        fisapi_response(
            403,
            false,
            'Your user account is not active.',
            null,
            'user_not_active'
        );
    }

    if (
        !in_array(
            (string)$user['tenant_status'],
            array('trial', 'active'),
            true
        )
    ) {
        fisapi_response(
            403,
            false,
            'This business account is not active.',
            null,
            'tenant_not_active'
        );
    }

    if (
        !empty($user['branch_id']) &&
        !empty($user['branch_status']) &&
        (string)$user['branch_status'] !== 'active'
    ) {
        fisapi_response(
            403,
            false,
            'Your assigned branch is not active.',
            null,
            'branch_not_active'
        );
    }

    if (
        !empty($user['department_id']) &&
        !empty($user['department_status']) &&
        (string)$user['department_status'] !== 'active'
    ) {
        fisapi_response(
            403,
            false,
            'Your assigned department is not active.',
            null,
            'department_not_active'
        );
    }

    if (
        !empty($user['role_id']) &&
        !empty($user['role_status']) &&
        (string)$user['role_status'] !== 'active'
    ) {
        fisapi_response(
            403,
            false,
            'Your assigned role is not active.',
            null,
            'role_not_active'
        );
    }

    if (fisapi_table_exists($pdo, 'subscriptions')) {
        $subStmt = $pdo->prepare(
            "SELECT
                id,
                plan_id,
                status,
                expiry_date
             FROM subscriptions
             WHERE tenant_id = :tenant_id
             ORDER BY
                CASE status
                    WHEN 'active' THEN 1
                    WHEN 'trial' THEN 2
                    WHEN 'suspended' THEN 3
                    WHEN 'expired' THEN 4
                    ELSE 5
                END,
                id DESC
             LIMIT 1"
        );

        $subStmt->execute(array(
            ':tenant_id' => $tenantId
        ));

        $subscription = $subStmt->fetch(PDO::FETCH_ASSOC);

        if ($subscription) {
            if (
                !in_array(
                    (string)$subscription['status'],
                    array('active', 'trial'),
                    true
                )
            ) {
                fisapi_response(
                    403,
                    false,
                    'Your subscription is not active.',
                    null,
                    'subscription_not_active'
                );
            }

            if (
                !empty($subscription['expiry_date']) &&
                (string)$subscription['expiry_date'] < date('Y-m-d')
            ) {
                fisapi_response(
                    403,
                    false,
                    'Your subscription has expired.',
                    null,
                    'subscription_expired'
                );
            }
        }
    }

    return array(
        'user_id' => $userId,
        'tenant_id' => $tenantId,
        'branch_id' => !empty($user['branch_id'])
            ? (int)$user['branch_id']
            : 0,
        'user' => $user,
        'claims' => $claims
    );
}

/* -------------------------------------------------------------------------
 * Data helpers
 * ---------------------------------------------------------------------- */

function fisapi_load_tenant(PDO $pdo, $tenantId)
{
    $stmt = $pdo->prepare(
        "SELECT
            id,
            display_name,
            legal_name,
            registration_number,
            tax_number,
            email,
            phone,
            alternate_phone,
            website_url,
            logo_path,
            invoice_logo_path,
            address_line1,
            address_line2,
            city,
            state,
            postal_code,
            currency_id
         FROM tenants
         WHERE id = :tenant_id
           AND deleted_at IS NULL
         LIMIT 1"
    );

    $stmt->execute(array(
        ':tenant_id' => (int)$tenantId
    ));

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function fisapi_load_branches(PDO $pdo, $tenantId)
{
    $stmt = $pdo->prepare(
        "SELECT
            id,
            branch_code,
            name,
            email,
            phone,
            address_line1,
            address_line2,
            city,
            state,
            postal_code,
            logo_path,
            invoice_logo_path,
            is_head_office,
            status
         FROM branches
         WHERE tenant_id = :tenant_id
           AND status <> 'archived'
         ORDER BY is_head_office DESC, name ASC"
    );

    $stmt->execute(array(
        ':tenant_id' => (int)$tenantId
    ));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fisapi_branch(PDO $pdo, $tenantId, $branchId)
{
    if ((int)$branchId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT
            id,
            branch_code,
            name,
            email,
            phone,
            address_line1,
            address_line2,
            city,
            state,
            postal_code,
            logo_path,
            invoice_logo_path,
            is_head_office,
            status
         FROM branches
         WHERE id = :branch_id
           AND tenant_id = :tenant_id
           AND status <> 'archived'
         LIMIT 1"
    );

    $stmt->execute(array(
        ':branch_id' => (int)$branchId,
        ':tenant_id' => (int)$tenantId
    ));

    $branch = $stmt->fetch(PDO::FETCH_ASSOC);

    return $branch ?: null;
}

function fisapi_load_settings(PDO $pdo, $tenantId, $branchId)
{
    if (!fisapi_table_exists($pdo, 'invoice_settings')) {
        return null;
    }

    if ((int)$branchId > 0) {
        $stmt = $pdo->prepare(
            "SELECT *
             FROM invoice_settings
             WHERE tenant_id = :tenant_id
               AND branch_id = :branch_id
             LIMIT 1"
        );

        $stmt->execute(array(
            ':tenant_id' => (int)$tenantId,
            ':branch_id' => (int)$branchId
        ));
    } else {
        $stmt = $pdo->prepare(
            "SELECT *
             FROM invoice_settings
             WHERE tenant_id = :tenant_id
               AND branch_id IS NULL
             LIMIT 1"
        );

        $stmt->execute(array(
            ':tenant_id' => (int)$tenantId
        ));
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fisapi_effective_value($settings, $tenant, $branch, $key)
{
    if (
        is_array($settings) &&
        isset($settings[$key]) &&
        $settings[$key] !== null &&
        $settings[$key] !== ''
    ) {
        return $settings[$key];
    }

    if (
        is_array($branch) &&
        isset($branch[$key]) &&
        $branch[$key] !== null &&
        $branch[$key] !== ''
    ) {
        return $branch[$key];
    }

    if (
        is_array($tenant) &&
        isset($tenant[$key]) &&
        $tenant[$key] !== null
    ) {
        return $tenant[$key];
    }

    return '';
}


function fisapi_public_url($path)
{
    $path = trim((string)$path);

    if ($path === '') {
        return '';
    }

    if (
        stripos($path, 'http://') === 0 ||
        stripos($path, 'https://') === 0
    ) {
        return $path;
    }

    return
        'https://fieldplx.com/business/' .
        ltrim($path, '/');
}

function fisapi_branding_and_signature($settings, $tenant, $branch)
{
    $effective = fisapi_effective_settings(
        $settings,
        $tenant,
        $branch
    );

    $businessLogoPath = trim(
        (string)($effective['logo_path'] ?? '')
    );

    $invoiceLogoPath = trim(
        (string)($effective['invoice_logo_path'] ?? '')
    );

    $signaturePath = trim(
        (string)($effective['signature_path'] ?? '')
    );

    return array(
        'business_logo' => array(
            'path' => $businessLogoPath,
            'url' => fisapi_public_url($businessLogoPath),
            'uploaded' => $businessLogoPath !== ''
        ),

        'invoice_logo' => array(
            'path' => $invoiceLogoPath,
            'url' => fisapi_public_url($invoiceLogoPath),
            'uploaded' => $invoiceLogoPath !== ''
        ),

        'authorized_signature' => array(
            'path' => $signaturePath,
            'url' => fisapi_public_url($signaturePath),
            'uploaded' => $signaturePath !== ''
        ),

        'authorized_signatory_name' => trim(
            (string)(
                $effective['authorized_signatory_name'] ?? ''
            )
        ),

        'invoice_heading' => trim(
            (string)(
                $effective['invoice_title'] ?? 'Invoice'
            )
        )
    );
}

function fisapi_effective_settings($settings, $tenant, $branch)
{
    return array(
        'company_name' =>
            !empty($settings['company_name'])
                ? $settings['company_name']
                : (
                    $branch
                        ? $branch['name']
                        : ($tenant['display_name'] ?? '')
                ),

        'legal_name' =>
            fisapi_effective_value(
                $settings,
                $tenant,
                $branch,
                'legal_name'
            ),

        'email' =>
            fisapi_effective_value(
                $settings,
                $tenant,
                $branch,
                'email'
            ),

        'website_url' =>
            fisapi_effective_value(
                $settings,
                $tenant,
                $branch,
                'website_url'
            ),

        'phone' =>
            fisapi_effective_value(
                $settings,
                $tenant,
                $branch,
                'phone'
            ),

        'alternate_phone' =>
            fisapi_effective_value(
                $settings,
                $tenant,
                $branch,
                'alternate_phone'
            ),

        'registration_number' =>
            fisapi_effective_value(
                $settings,
                $tenant,
                $branch,
                'registration_number'
            ),

        'tax_number' =>
            fisapi_effective_value(
                $settings,
                $tenant,
                $branch,
                'tax_number'
            ),

        'address_line1' =>
            fisapi_effective_value(
                $settings,
                $tenant,
                $branch,
                'address_line1'
            ),

        'address_line2' =>
            fisapi_effective_value(
                $settings,
                $tenant,
                $branch,
                'address_line2'
            ),

        'city' =>
            fisapi_effective_value(
                $settings,
                $tenant,
                $branch,
                'city'
            ),

        'state' =>
            fisapi_effective_value(
                $settings,
                $tenant,
                $branch,
                'state'
            ),

        'postal_code' =>
            fisapi_effective_value(
                $settings,
                $tenant,
                $branch,
                'postal_code'
            ),

        'logo_path' =>
            !empty($settings['logo_path'])
                ? $settings['logo_path']
                : fisapi_effective_value(
                    $settings,
                    $tenant,
                    $branch,
                    'logo_path'
                ),

        'invoice_logo_path' =>
            !empty($settings['invoice_logo_path'])
                ? $settings['invoice_logo_path']
                : fisapi_effective_value(
                    $settings,
                    $tenant,
                    $branch,
                    'invoice_logo_path'
                ),

        'signature_path' =>
            $settings['signature_path'] ?? '',

        'authorized_signatory_name' =>
            $settings['authorized_signatory_name'] ?? '',

        'invoice_title' =>
            !empty($settings['invoice_title'])
                ? $settings['invoice_title']
                : 'Invoice',

        'footer_note' =>
            $settings['footer_note'] ?? '',

        'terms_and_conditions' =>
            $settings['terms_and_conditions'] ?? ''
    );
}

/* -------------------------------------------------------------------------
 * Input helpers
 * ---------------------------------------------------------------------- */

function fisapi_input()
{
    static $input = null;

    if (is_array($input)) {
        return $input;
    }

    if (!empty($_POST) && is_array($_POST)) {
        $input = $_POST;
        return $input;
    }

    $raw = file_get_contents('php://input');

    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            $input = $decoded;
            return $input;
        }

        parse_str($raw, $parsed);

        if (is_array($parsed) && !empty($parsed)) {
            $input = $parsed;
            return $input;
        }

        fisapi_response(
            400,
            false,
            'Invalid request body.',
            null,
            'invalid_request_body'
        );
    }

    $input = array();
    return $input;
}

function fisapi_value($key, $default = null)
{
    $input = fisapi_input();

    if (array_key_exists($key, $input)) {
        return $input[$key];
    }

    if (isset($_GET[$key])) {
        return $_GET[$key];
    }

    return $default;
}

function fisapi_string($key, $default = '')
{
    return trim((string)fisapi_value($key, $default));
}

function fisapi_int($key, $default = 0)
{
    return (int)fisapi_value($key, $default);
}

/* -------------------------------------------------------------------------
 * Upload helper
 * ---------------------------------------------------------------------- */

function fisapi_upload($fieldName, $tenantId, $branchId, $type)
{
    if (
        !isset($_FILES[$fieldName]) ||
        !is_array($_FILES[$fieldName]) ||
        (int)$_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ((int)$_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'Unable to upload ' . $type . '.'
        );
    }

    if ((int)$_FILES[$fieldName]['size'] > 4 * 1024 * 1024) {
        throw new RuntimeException(
            ucfirst($type) . ' must be 4 MB or smaller.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file(
        $_FILES[$fieldName]['tmp_name']
    );

    $allowed = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    );

    if (!isset($allowed[$mime])) {
        throw new RuntimeException(
            ucfirst($type) .
            ' must be JPG, PNG or WEBP.'
        );
    }

    $relativeFolder =
        'uploads/invoice-settings/tenant-' .
        (int)$tenantId;

    if ((int)$branchId > 0) {
        $relativeFolder .=
            '/branch-' .
            (int)$branchId;
    } else {
        $relativeFolder .=
            '/business-default';
    }

    /*
     * API file:
     * /business/api/mobile/invoice-settings.php
     *
     * Project root:
     * /business
     */
    $businessRoot = dirname(__DIR__, 2);

    $absoluteFolder =
        $businessRoot .
        '/' .
        $relativeFolder;

    if (
        !is_dir($absoluteFolder) &&
        !@mkdir($absoluteFolder, 0755, true) &&
        !is_dir($absoluteFolder)
    ) {
        throw new RuntimeException(
            'Unable to create invoice settings upload directory.'
        );
    }

    $filename =
        $type .
        '-' .
        date('YmdHis') .
        '-' .
        bin2hex(random_bytes(4)) .
        '.' .
        $allowed[$mime];

    $relativePath =
        $relativeFolder .
        '/' .
        $filename;

    $absolutePath =
        $businessRoot .
        '/' .
        $relativePath;

    if (
        !move_uploaded_file(
            $_FILES[$fieldName]['tmp_name'],
            $absolutePath
        )
    ) {
        throw new RuntimeException(
            'Unable to save ' . $type . '.'
        );
    }

    return $relativePath;
}

/* -------------------------------------------------------------------------
 * Audit helpers
 * ---------------------------------------------------------------------- */

function fisapi_activity(
    PDO $pdo,
    $tenantId,
    $branchId,
    $userId,
    $eventType,
    $relatedId,
    $title,
    $details
) {
    try {
        if (!fisapi_table_exists($pdo, 'activity_events')) {
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO activity_events (
                tenant_id,
                branch_id,
                actor_user_id,
                actor_type,
                event_type,
                related_type,
                related_id,
                title,
                details_json,
                visible_to_client
             ) VALUES (
                :tenant_id,
                :branch_id,
                :actor_user_id,
                'user',
                :event_type,
                'invoice_settings',
                :related_id,
                :title,
                :details_json,
                0
             )"
        );

        $stmt->execute(array(
            ':tenant_id' => (int)$tenantId,
            ':branch_id' =>
                (int)$branchId > 0
                    ? (int)$branchId
                    : null,
            ':actor_user_id' => (int)$userId,
            ':event_type' => substr((string)$eventType, 0, 120),
            ':related_id' =>
                (int)$relatedId > 0
                    ? (int)$relatedId
                    : null,
            ':title' => substr((string)$title, 0, 255),
            ':details_json' => json_encode(
                $details,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
        ));
    } catch (Throwable $e) {
        error_log(
            'Invoice settings mobile activity log: ' .
            $e->getMessage()
        );
    }
}

function fisapi_audit(
    PDO $pdo,
    $tenantId,
    $branchId,
    $userId,
    $action,
    $objectId,
    $oldValues,
    $newValues
) {
    try {
        if (function_exists('tenantAuditLog')) {
            tenantAuditLog(
                $pdo,
                $action,
                (int)$tenantId,
                (int)$branchId > 0
                    ? (int)$branchId
                    : null,
                (int)$userId,
                'invoice_settings',
                (int)$objectId > 0
                    ? (int)$objectId
                    : null,
                $oldValues,
                $newValues
            );
        }
    } catch (Throwable $e) {
        error_log(
            'Invoice settings mobile audit log: ' .
            $e->getMessage()
        );
    }
}

/* -------------------------------------------------------------------------
 * Start
 * ---------------------------------------------------------------------- */

$auth = fisapi_require_auth($pdo);

$tenantId = (int)$auth['tenant_id'];
$userId = (int)$auth['user_id'];
$currentUserBranchId = (int)$auth['branch_id'];

if (!fisapi_table_exists($pdo, 'invoice_settings')) {
    fisapi_response(
        500,
        false,
        'invoice_settings table is missing. Run the invoice-settings migration first.',
        null,
        'invoice_settings_table_missing'
    );
}

$tenant = fisapi_load_tenant(
    $pdo,
    $tenantId
);

if (!$tenant) {
    fisapi_response(
        404,
        false,
        'Tenant not found.',
        null,
        'tenant_not_found'
    );
}

$method = strtoupper(
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
);

/* -------------------------------------------------------------------------
 * GET
 * ---------------------------------------------------------------------- */

if ($method === 'GET') {
    $branchId =
        isset($_GET['branch_id'])
            ? max(0, (int)$_GET['branch_id'])
            : 0;

    $branch = null;

    if ($branchId > 0) {
        $branch = fisapi_branch(
            $pdo,
            $tenantId,
            $branchId
        );

        if (!$branch) {
            fisapi_response(
                422,
                false,
                'Invalid branch selected.',
                null,
                'invalid_branch'
            );
        }
    }

    $settings = fisapi_load_settings(
        $pdo,
        $tenantId,
        $branchId
    );

    $branches = fisapi_load_branches(
        $pdo,
        $tenantId
    );

    fisapi_response(
        200,
        true,
        'Invoice settings loaded successfully.',
        array(
            'scope' => array(
                'type' =>
                    $branchId > 0
                        ? 'branch'
                        : 'business_default',
                'branch_id' =>
                    $branchId > 0
                        ? $branchId
                        : null,
                'branch' => $branch
            ),

            'settings' => $settings,

            'effective_settings' =>
                fisapi_effective_settings(
                    $settings ?: array(),
                    $tenant,
                    $branch
                ),

            'branding_and_signature' =>
                fisapi_branding_and_signature(
                    $settings ?: array(),
                    $tenant,
                    $branch
                ),

            'meta' => array(
                'branches' => $branches,

                'upload_rules' => array(
                    'fields' => array(
                        'logo',
                        'invoice_logo',
                        'signature'
                    ),
                    'allowed_mime_types' => array(
                        'image/jpeg',
                        'image/png',
                        'image/webp'
                    ),
                    'allowed_extensions' => array(
                        'jpg',
                        'jpeg',
                        'png',
                        'webp'
                    ),
                    'max_size_bytes' =>
                        4 * 1024 * 1024,
                    'max_size_mb' => 4
                )
            )
        )
    );
}

/* -------------------------------------------------------------------------
 * POST / PUT / PATCH => UPSERT
 * ---------------------------------------------------------------------- */

if (
    in_array(
        $method,
        array('POST', 'PUT', 'PATCH'),
        true
    )
) {
    try {
        $branchId = max(
            0,
            fisapi_int('branch_id', 0)
        );

        $branch = null;

        if ($branchId > 0) {
            $branch = fisapi_branch(
                $pdo,
                $tenantId,
                $branchId
            );

            if (!$branch) {
                fisapi_response(
                    422,
                    false,
                    'Invalid branch selected.',
                    null,
                    'invalid_branch'
                );
            }
        }

        $current = fisapi_load_settings(
            $pdo,
            $tenantId,
            $branchId
        );

        $companyName =
            fisapi_string('company_name', '');

        $legalName =
            fisapi_string('legal_name', '');

        $email =
            fisapi_string('email', '');

        $website =
            fisapi_string('website_url', '');

        $phone =
            fisapi_string('phone', '');

        $alternatePhone =
            fisapi_string('alternate_phone', '');

        $registrationNumber =
            fisapi_string('registration_number', '');

        $taxNumber =
            fisapi_string('tax_number', '');

        $address1 =
            fisapi_string('address_line1', '');

        $address2 =
            fisapi_string('address_line2', '');

        $city =
            fisapi_string('city', '');

        $state =
            fisapi_string('state', '');

        $postalCode =
            fisapi_string('postal_code', '');

        $invoiceTitle =
            fisapi_string(
                'invoice_title',
                'Invoice'
            );

        $signatoryName =
            fisapi_string(
                'authorized_signatory_name',
                ''
            );

        $footerNote =
            fisapi_string('footer_note', '');

        $terms =
            fisapi_string(
                'terms_and_conditions',
                ''
            );

        if ($companyName === '') {
            fisapi_response(
                422,
                false,
                'Company name is required.',
                null,
                'company_name_required'
            );
        }

        if (
            strlen($companyName) > 190 ||
            strlen($legalName) > 190 ||
            strlen($email) > 190 ||
            strlen($website) > 255 ||
            strlen($phone) > 50 ||
            strlen($alternatePhone) > 50 ||
            strlen($registrationNumber) > 120 ||
            strlen($taxNumber) > 120 ||
            strlen($address1) > 255 ||
            strlen($address2) > 255 ||
            strlen($city) > 120 ||
            strlen($state) > 120 ||
            strlen($postalCode) > 40 ||
            strlen($invoiceTitle) > 120 ||
            strlen($signatoryName) > 190 ||
            strlen($footerNote) > 2000 ||
            strlen($terms) > 10000
        ) {
            fisapi_response(
                422,
                false,
                'One or more fields exceed the allowed maximum length.',
                null,
                'validation_error'
            );
        }

        if (
            $email !== '' &&
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            fisapi_response(
                422,
                false,
                'Enter a valid company email.',
                null,
                'invalid_email'
            );
        }

        if (
            $website !== '' &&
            !filter_var(
                $website,
                FILTER_VALIDATE_URL
            )
        ) {
            fisapi_response(
                422,
                false,
                'Enter a valid website URL including https://',
                null,
                'invalid_website'
            );
        }

        /*
         * Multipart file uploads work with POST.
         *
         * PHP does not populate $_FILES for every server setup when using
         * PUT/PATCH multipart requests. Android should therefore use POST
         * for saves that contain images.
         */
        $logoPath =
            fisapi_upload(
                'logo',
                $tenantId,
                $branchId,
                'logo'
            );

        $invoiceLogoPath =
            fisapi_upload(
                'invoice_logo',
                $tenantId,
                $branchId,
                'invoice-logo'
            );

        $signaturePath =
            fisapi_upload(
                'signature',
                $tenantId,
                $branchId,
                'signature'
            );

        if ($logoPath === null && $current) {
            $logoPath =
                $current['logo_path'];
        }

        if (
            $invoiceLogoPath === null &&
            $current
        ) {
            $invoiceLogoPath =
                $current['invoice_logo_path'];
        }

        if (
            $signaturePath === null &&
            $current
        ) {
            $signaturePath =
                $current['signature_path'];
        }

        $pdo->beginTransaction();

        if ($current) {
            $stmt = $pdo->prepare(
                "UPDATE invoice_settings
                 SET
                    company_name = :company_name,
                    legal_name = :legal_name,
                    email = :email,
                    website_url = :website_url,
                    phone = :phone,
                    alternate_phone = :alternate_phone,
                    registration_number = :registration_number,
                    tax_number = :tax_number,
                    address_line1 = :address_line1,
                    address_line2 = :address_line2,
                    city = :city,
                    state = :state,
                    postal_code = :postal_code,
                    logo_path = :logo_path,
                    invoice_logo_path = :invoice_logo_path,
                    signature_path = :signature_path,
                    authorized_signatory_name = :authorized_signatory_name,
                    invoice_title = :invoice_title,
                    footer_note = :footer_note,
                    terms_and_conditions = :terms_and_conditions,
                    updated_by = :updated_by,
                    updated_at = NOW()
                 WHERE id = :id
                   AND tenant_id = :tenant_id"
            );

            $stmt->execute(array(
                ':company_name' => $companyName,
                ':legal_name' =>
                    $legalName !== ''
                        ? $legalName
                        : null,
                ':email' =>
                    $email !== ''
                        ? $email
                        : null,
                ':website_url' =>
                    $website !== ''
                        ? $website
                        : null,
                ':phone' =>
                    $phone !== ''
                        ? $phone
                        : null,
                ':alternate_phone' =>
                    $alternatePhone !== ''
                        ? $alternatePhone
                        : null,
                ':registration_number' =>
                    $registrationNumber !== ''
                        ? $registrationNumber
                        : null,
                ':tax_number' =>
                    $taxNumber !== ''
                        ? $taxNumber
                        : null,
                ':address_line1' =>
                    $address1 !== ''
                        ? $address1
                        : null,
                ':address_line2' =>
                    $address2 !== ''
                        ? $address2
                        : null,
                ':city' =>
                    $city !== ''
                        ? $city
                        : null,
                ':state' =>
                    $state !== ''
                        ? $state
                        : null,
                ':postal_code' =>
                    $postalCode !== ''
                        ? $postalCode
                        : null,
                ':logo_path' => $logoPath,
                ':invoice_logo_path' =>
                    $invoiceLogoPath,
                ':signature_path' =>
                    $signaturePath,
                ':authorized_signatory_name' =>
                    $signatoryName !== ''
                        ? $signatoryName
                        : null,
                ':invoice_title' =>
                    $invoiceTitle !== ''
                        ? $invoiceTitle
                        : 'Invoice',
                ':footer_note' =>
                    $footerNote !== ''
                        ? $footerNote
                        : null,
                ':terms_and_conditions' =>
                    $terms !== ''
                        ? $terms
                        : null,
                ':updated_by' => $userId,
                ':id' => (int)$current['id'],
                ':tenant_id' => $tenantId
            ));

            $settingsId =
                (int)$current['id'];
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO invoice_settings
                (
                    tenant_id,
                    branch_id,
                    company_name,
                    legal_name,
                    email,
                    website_url,
                    phone,
                    alternate_phone,
                    registration_number,
                    tax_number,
                    address_line1,
                    address_line2,
                    city,
                    state,
                    postal_code,
                    logo_path,
                    invoice_logo_path,
                    signature_path,
                    authorized_signatory_name,
                    invoice_title,
                    footer_note,
                    terms_and_conditions,
                    created_by,
                    updated_by,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    :tenant_id,
                    :branch_id,
                    :company_name,
                    :legal_name,
                    :email,
                    :website_url,
                    :phone,
                    :alternate_phone,
                    :registration_number,
                    :tax_number,
                    :address_line1,
                    :address_line2,
                    :city,
                    :state,
                    :postal_code,
                    :logo_path,
                    :invoice_logo_path,
                    :signature_path,
                    :authorized_signatory_name,
                    :invoice_title,
                    :footer_note,
                    :terms_and_conditions,
                    :created_by,
                    :updated_by,
                    NOW(),
                    NOW()
                )"
            );

            $stmt->execute(array(
                ':tenant_id' => $tenantId,
                ':branch_id' =>
                    $branchId > 0
                        ? $branchId
                        : null,
                ':company_name' => $companyName,
                ':legal_name' =>
                    $legalName !== ''
                        ? $legalName
                        : null,
                ':email' =>
                    $email !== ''
                        ? $email
                        : null,
                ':website_url' =>
                    $website !== ''
                        ? $website
                        : null,
                ':phone' =>
                    $phone !== ''
                        ? $phone
                        : null,
                ':alternate_phone' =>
                    $alternatePhone !== ''
                        ? $alternatePhone
                        : null,
                ':registration_number' =>
                    $registrationNumber !== ''
                        ? $registrationNumber
                        : null,
                ':tax_number' =>
                    $taxNumber !== ''
                        ? $taxNumber
                        : null,
                ':address_line1' =>
                    $address1 !== ''
                        ? $address1
                        : null,
                ':address_line2' =>
                    $address2 !== ''
                        ? $address2
                        : null,
                ':city' =>
                    $city !== ''
                        ? $city
                        : null,
                ':state' =>
                    $state !== ''
                        ? $state
                        : null,
                ':postal_code' =>
                    $postalCode !== ''
                        ? $postalCode
                        : null,
                ':logo_path' => $logoPath,
                ':invoice_logo_path' =>
                    $invoiceLogoPath,
                ':signature_path' =>
                    $signaturePath,
                ':authorized_signatory_name' =>
                    $signatoryName !== ''
                        ? $signatoryName
                        : null,
                ':invoice_title' =>
                    $invoiceTitle !== ''
                        ? $invoiceTitle
                        : 'Invoice',
                ':footer_note' =>
                    $footerNote !== ''
                        ? $footerNote
                        : null,
                ':terms_and_conditions' =>
                    $terms !== ''
                        ? $terms
                        : null,
                ':created_by' => $userId,
                ':updated_by' => $userId
            ));

            $settingsId =
                (int)$pdo->lastInsertId();
        }

        $pdo->commit();

        $saved = fisapi_load_settings(
            $pdo,
            $tenantId,
            $branchId
        );

        fisapi_activity(
            $pdo,
            $tenantId,
            $currentUserBranchId,
            $userId,
            $current
                ? 'invoice_settings_updated'
                : 'invoice_settings_created',
            $settingsId,
            $current
                ? 'Invoice settings updated'
                : 'Invoice settings created',
            array(
                'scope' =>
                    $branchId > 0
                        ? 'branch'
                        : 'business_default',
                'branch_id' =>
                    $branchId > 0
                        ? $branchId
                        : null
            )
        );

        fisapi_audit(
            $pdo,
            $tenantId,
            $currentUserBranchId,
            $userId,
            $current
                ? 'INVOICE_SETTINGS_UPDATED'
                : 'INVOICE_SETTINGS_CREATED',
            $settingsId,
            $current,
            $saved
        );

        fisapi_response(
            200,
            true,
            'Invoice settings saved successfully.',
            array(
                'scope' => array(
                    'type' =>
                        $branchId > 0
                            ? 'branch'
                            : 'business_default',
                    'branch_id' =>
                        $branchId > 0
                            ? $branchId
                            : null
                ),
                'settings' => $saved,
                'effective_settings' =>
                    fisapi_effective_settings(
                        $saved ?: array(),
                        $tenant,
                        $branch
                    ),

                'branding_and_signature' =>
                    fisapi_branding_and_signature(
                        $saved ?: array(),
                        $tenant,
                        $branch
                    )
            )
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'FieldPlx Mobile Invoice Settings API: ' .
            $e->getMessage()
        );

        fisapi_response(
            500,
            false,
            'Unable to save invoice settings. ' .
            $e->getMessage(),
            null,
            'invoice_settings_save_failed'
        );
    }
}

fisapi_response(
    405,
    false,
    'Method not allowed.',
    null,
    'method_not_allowed'
);
