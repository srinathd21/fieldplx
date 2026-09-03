<?php
/**
 * FieldPlx API - Create Tenant + Administrator Provisioning
 * Endpoint: /platform/api/tenant-save.php
 * PHP 7.2+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/smtp-secret.php';
require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function tenantApiResponse(
    int $status,
    bool $success,
    string $message,
    array $extra = array()
): void {
    http_response_code($status);

    echo json_encode(
        array_merge(
            array(
                'success' => $success,
                'message' => $message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function tenantApiPost(
    string $key,
    string $default = ''
): string {
    if (
        !isset($_POST[$key]) ||
        is_array($_POST[$key])
    ) {
        return $default;
    }

    return trim((string) $_POST[$key]);
}

function tenantApiNullable(
    string $value
) {
    $value = trim($value);

    return $value === ''
        ? null
        : $value;
}

function tenantApiValidDate(
    string $value
): bool {
    if ($value === '') {
        return true;
    }

    $date = DateTime::createFromFormat(
        'Y-m-d',
        $value
    );

    return $date !== false &&
        $date->format('Y-m-d') === $value;
}

function tenantApiUpload(
    string $field,
    string $tenantCode
) {
    if (
        !isset($_FILES[$field]) ||
        !is_array($_FILES[$field]) ||
        !isset($_FILES[$field]['error'])
    ) {
        return null;
    }

    $file = $_FILES[$field];

    if (
        $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if (
        $file['error'] !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException(
            'One of the uploaded logo files could not be processed.'
        );
    }

    if (
        !isset($file['size']) ||
        (int) $file['size'] <= 0 ||
        (int) $file['size'] > 3 * 1024 * 1024
    ) {
        throw new RuntimeException(
            'Logo files must be 3 MB or smaller.'
        );
    }

    $tmpName = isset($file['tmp_name'])
        ? (string) $file['tmp_name']
        : '';

    if (
        $tmpName === '' ||
        !is_uploaded_file($tmpName)
    ) {
        throw new RuntimeException(
            'Invalid uploaded logo file.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpName);

    $allowed = array(
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    );

    if (
        !is_string($mime) ||
        !isset($allowed[$mime])
    ) {
        throw new RuntimeException(
            'Only JPG, PNG and WEBP logo files are allowed.'
        );
    }

    $safeTenantCode = preg_replace(
        '/[^A-Za-z0-9_-]+/',
        '-',
        $tenantCode
    );

    $safeTenantCode = trim(
        (string) $safeTenantCode,
        '-'
    );

    if ($safeTenantCode === '') {
        $safeTenantCode = 'tenant';
    }

    $uploadRelativeDir =
        'uploads/tenants/' .
        strtolower($safeTenantCode);

    $uploadAbsoluteDir =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $uploadRelativeDir
        );

    if (
        !is_dir($uploadAbsoluteDir) &&
        !mkdir(
            $uploadAbsoluteDir,
            0755,
            true
        ) &&
        !is_dir($uploadAbsoluteDir)
    ) {
        throw new RuntimeException(
            'Unable to create tenant upload directory.'
        );
    }

    $filename =
        $field .
        '-' .
        date('YmdHis') .
        '-' .
        bin2hex(random_bytes(4)) .
        '.' .
        $allowed[$mime];

    $absolutePath =
        $uploadAbsoluteDir .
        DIRECTORY_SEPARATOR .
        $filename;

    if (
        !move_uploaded_file(
            $tmpName,
            $absolutePath
        )
    ) {
        throw new RuntimeException(
            'Unable to save uploaded logo.'
        );
    }

    return $uploadRelativeDir . '/' . $filename;
}


/*
|--------------------------------------------------------------------------
| Tenant Administrator Provisioning Helpers
|--------------------------------------------------------------------------
*/

function tenantApiGenerateTemporaryPassword(
    int $length = 14
): string {
    if ($length < 12) {
        $length = 12;
    }

    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $special = '!@#$%';
    $all = $upper . $lower . $digits . $special;

    $characters = array(
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $special[random_int(0, strlen($special) - 1)]
    );

    while (count($characters) < $length) {
        $characters[] =
            $all[random_int(0, strlen($all) - 1)];
    }

    for ($i = count($characters) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $characters[$i];
        $characters[$i] = $characters[$j];
        $characters[$j] = $tmp;
    }

    return implode('', $characters);
}

function tenantApiAdminNameParts(
    string $adminName
): array {
    $adminName = preg_replace(
        '/\\s+/',
        ' ',
        trim($adminName)
    );

    if (!is_string($adminName) || $adminName === '') {
        return array('Administrator', null);
    }

    $parts = explode(' ', $adminName, 2);
    $firstName = substr((string)$parts[0], 0, 120);
    $lastName = isset($parts[1])
        ? trim(substr((string)$parts[1], 0, 120))
        : '';

    return array(
        $firstName !== '' ? $firstName : 'Administrator',
        $lastName !== '' ? $lastName : null
    );
}

function tenantApiBusinessLoginUrl(): string
{
    if (
        !isset($_SERVER['HTTP_HOST']) ||
        trim((string)$_SERVER['HTTP_HOST']) === ''
    ) {
        return '';
    }

    $scheme =
        isset($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== '' &&
        strtolower((string)$_SERVER['HTTPS']) !== 'off'
            ? 'https'
            : 'http';

    $scriptName = isset($_SERVER['SCRIPT_NAME'])
        ? str_replace('\\\\', '/', (string)$_SERVER['SCRIPT_NAME'])
        : '';

    $projectBase = '';
    $platformPos = strrpos($scriptName, '/platform/');

    if ($platformPos !== false) {
        $projectBase = substr($scriptName, 0, $platformPos);
    }

    return
        $scheme .
        '://' .
        trim((string)$_SERVER['HTTP_HOST']) .
        rtrim($projectBase, '/') .
        '/business/login.php';
}

function tenantApiCreateAdministrator(
    PDO $pdo,
    int $tenantId,
    string $adminName,
    string $email,
    ?string $phone,
    string $temporaryPassword
): array {
    $nameParts = tenantApiAdminNameParts($adminName);
    $firstName = $nameParts[0];
    $lastName = $nameParts[1];

    $roleStmt = $pdo->prepare("\n        INSERT INTO roles (\n            tenant_id,\n            name,\n            code,\n            description,\n            is_admin,\n            is_system_role,\n            status\n        ) VALUES (\n            :tenant_id,\n            'Administrator',\n            'ADMINISTRATOR',\n            'Primary tenant business administrator',\n            1,\n            1,\n            'active'\n        )\n    ");

    $roleStmt->execute(array(
        ':tenant_id' => $tenantId
    ));

    $roleId = (int)$pdo->lastInsertId();

    $passwordHash = password_hash(
        $temporaryPassword,
        PASSWORD_DEFAULT
    );

    if ($passwordHash === false) {
        throw new RuntimeException(
            'Unable to secure the tenant administrator password.'
        );
    }

    $userStmt = $pdo->prepare("\n        INSERT INTO users (\n            tenant_id,\n            branch_id,\n            department_id,\n            role_id,\n            employee_code,\n            first_name,\n            last_name,\n            email,\n            phone,\n            alternate_phone,\n            password_hash,\n            avatar_path,\n            job_title,\n            labor_rate,\n            is_bookable,\n            is_field_worker,\n            is_tenant_admin,\n            status\n        ) VALUES (\n            :tenant_id,\n            NULL,\n            NULL,\n            :role_id,\n            NULL,\n            :first_name,\n            :last_name,\n            :email,\n            :phone,\n            NULL,\n            :password_hash,\n            NULL,\n            'Administrator',\n            NULL,\n            0,\n            0,\n            1,\n            'active'\n        )\n    ");

    $userStmt->execute(array(
        ':tenant_id' => $tenantId,
        ':role_id' => $roleId,
        ':first_name' => $firstName,
        ':last_name' => $lastName,
        ':email' => strtolower($email),
        ':phone' => $phone,
        ':password_hash' => $passwordHash
    ));

    return array(
        'role_id' => $roleId,
        'user_id' => (int)$pdo->lastInsertId(),
        'name' => trim(
            $firstName .
            ($lastName !== null ? ' ' . $lastName : '')
        ),
        'email' => strtolower($email)
    );
}

function tenantApiEnableAdministratorPlanPermissions(
    PDO $pdo,
    int $tenantId,
    int $roleId,
    int $planId
): int {
    if ($planId <= 0) {
        return 0;
    }

    /*
     * Keep the standard permission master complete for every plan-enabled
     * sidebar module. This mirrors the Roles page standard actions.
     */
    $definitions = array(
        array('view', '.view', 'View '),
        array('create', '.create', 'Create in '),
        array('update', '.update', 'Update '),
        array('delete', '.delete', 'Delete/archive in '),
        array('approve', '.approve', 'Approve in '),
        array('export', '.export', 'Export from ')
    );

    $permissionStmt = $pdo->prepare("\n        INSERT IGNORE INTO permissions (\n            module_id,\n            action_code,\n            permission_code,\n            description\n        )\n        SELECT\n            m.id,\n            :action_code,\n            CONCAT(m.module_code, :permission_suffix),\n            CONCAT(:description_prefix, m.module_name)\n        FROM plan_modules pm\n        INNER JOIN modules m\n            ON m.id = pm.module_id\n        WHERE pm.plan_id = :plan_id\n          AND pm.is_enabled = 1\n          AND m.is_active = 1\n          AND m.is_sidebar_item = 1\n    ");

    foreach ($definitions as $definition) {
        $permissionStmt->execute(array(
            ':action_code' => $definition[0],
            ':permission_suffix' => $definition[1],
            ':description_prefix' => $definition[2],
            ':plan_id' => $planId
        ));
    }

    /*
     * Administrator receives every permission that exists for modules in
     * the selected plan, including custom actions beyond the standard six.
     */
    $grantStmt = $pdo->prepare("\n        INSERT INTO role_permissions (\n            tenant_id,\n            role_id,\n            permission_id,\n            access_type\n        )\n        SELECT\n            :tenant_id,\n            :role_id,\n            p.id,\n            'allow'\n        FROM permissions p\n        INNER JOIN modules m\n            ON m.id = p.module_id\n        INNER JOIN plan_modules pm\n            ON pm.module_id = m.id\n           AND pm.plan_id = :plan_id\n           AND pm.is_enabled = 1\n        WHERE m.is_active = 1\n          AND m.is_sidebar_item = 1\n        ON DUPLICATE KEY UPDATE\n            access_type = 'allow'\n    ");

    $grantStmt->execute(array(
        ':tenant_id' => $tenantId,
        ':role_id' => $roleId,
        ':plan_id' => $planId
    ));

    $countStmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM role_permissions rp\n        INNER JOIN permissions p\n            ON p.id = rp.permission_id\n        INNER JOIN modules m\n            ON m.id = p.module_id\n        INNER JOIN plan_modules pm\n            ON pm.module_id = m.id\n           AND pm.plan_id = :plan_id\n           AND pm.is_enabled = 1\n        WHERE rp.tenant_id = :tenant_id\n          AND rp.role_id = :role_id\n          AND rp.access_type = 'allow'\n          AND m.is_active = 1\n          AND m.is_sidebar_item = 1\n    ");

    $countStmt->execute(array(
        ':plan_id' => $planId,
        ':tenant_id' => $tenantId,
        ':role_id' => $roleId
    ));

    return (int)$countStmt->fetchColumn();
}

/*
|--------------------------------------------------------------------------
| SMTP / Welcome Email Helpers
|--------------------------------------------------------------------------
*/

function tenantApiSmtpSecretKey(): string
{
    $key = '';

    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        $key = trim((string) FIELDPLX_SMTP_ENCRYPTION_KEY);
    }

    if ($key === '') {
        $env = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');

        if ($env !== false) {
            $key = trim((string) $env);
        }
    }

    if ($key === '') {
        $env = getenv('APP_KEY');

        if ($env !== false) {
            $key = trim((string) $env);
        }
    }

    if (
        $key === '' ||
        $key === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY'
    ) {
        throw new RuntimeException(
            'FIELDPLX_SMTP_ENCRYPTION_KEY is not configured.'
        );
    }

    if (strlen($key) < 32) {
        throw new RuntimeException(
            'FIELDPLX_SMTP_ENCRYPTION_KEY must contain at least 32 characters.'
        );
    }

    return hash('sha256', $key, true);
}

function tenantApiDecryptSmtpPassword(
    ?string $stored
): string {
    $stored = (string) $stored;

    if ($stored === '') {
        return '';
    }

    if (strpos($stored, 'v1:') !== 0) {
        return '';
    }

    $raw = base64_decode(
        substr($stored, 3),
        true
    );

    if (
        $raw === false ||
        strlen($raw) <= 16
    ) {
        return '';
    }

    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);

    $plain = openssl_decrypt(
        $cipher,
        'AES-256-CBC',
        tenantApiSmtpSecretKey(),
        OPENSSL_RAW_DATA,
        $iv
    );

    return $plain === false
        ? ''
        : $plain;
}

function tenantApiComposerAutoloadPath(): string
{
    $projectRoot = dirname(
        dirname(__DIR__)
    );

    $paths = array(
        $projectRoot . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php'
    );

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return '';
}

function tenantApiLoadPhpMailer(): void
{
    if (
        class_exists(
            'PHPMailer\\PHPMailer\\PHPMailer',
            false
        )
    ) {
        return;
    }

    $autoloadPath =
        tenantApiComposerAutoloadPath();

    if ($autoloadPath === '') {
        throw new RuntimeException(
            'Composer vendor/autoload.php was not found.'
        );
    }

    require_once $autoloadPath;

    if (
        !class_exists(
            'PHPMailer\\PHPMailer\\PHPMailer'
        )
    ) {
        throw new RuntimeException(
            'PHPMailer could not be loaded.'
        );
    }
}

function tenantApiPlatformSmtp(
    PDO $pdo
) {
    $stmt = $pdo->query("
        SELECT *
        FROM smtp_configurations
        WHERE scope_type = 'platform'
          AND is_active = 1
        ORDER BY
            is_default DESC,
            id DESC
        LIMIT 1
    ");

    $smtp = $stmt->fetch();

    return $smtp
        ? $smtp
        : null;
}

function tenantApiEscapeEmail(
    $value
): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function tenantApiBuildWelcomeEmail(
    array $tenant,
    ?array $plan,
    array $subscription,
    array $admin
): array {
    $platformName = 'FieldPlx';

    $tenantName =
        trim(
            (string) $tenant['display_name']
        );

    if ($tenantName === '') {
        $tenantName =
            trim(
                (string) $tenant['legal_name']
            );
    }

    $tenantCode =
        (string) $tenant['tenant_code'];

    $status =
        ucfirst(
            (string) $tenant['status']
        );

    $planName =
        $plan &&
        isset($plan['name'])
            ? (string) $plan['name']
            : 'No plan selected';

    $startDate =
        isset($subscription['start_date']) &&
        $subscription['start_date'] !== ''
            ? date(
                'd M Y',
                strtotime(
                    $subscription['start_date']
                )
            )
            : '-';

    $expiryDate =
        isset($subscription['expiry_date']) &&
        $subscription['expiry_date'] !== ''
            ? date(
                'd M Y',
                strtotime(
                    $subscription['expiry_date']
                )
            )
            : 'Not set';

    $trialEndDate =
        isset($subscription['trial_end_date']) &&
        $subscription['trial_end_date'] !== ''
            ? date(
                'd M Y',
                strtotime(
                    $subscription['trial_end_date']
                )
            )
            : '';

    $portalUrl = tenantApiBusinessLoginUrl();

    $adminName = trim(
        (string)($admin['name'] ?? 'Administrator')
    );
    $adminEmail = strtolower(
        trim((string)($admin['email'] ?? ''))
    );
    $temporaryPassword =
        (string)($admin['temporary_password'] ?? '');
    $permissionCount =
        (int)($admin['permission_count'] ?? 0);

    $safeTenantName =
        tenantApiEscapeEmail($tenantName);

    $safeTenantCode =
        tenantApiEscapeEmail($tenantCode);

    $safeStatus =
        tenantApiEscapeEmail($status);

    $safePlanName =
        tenantApiEscapeEmail($planName);

    $safeStartDate =
        tenantApiEscapeEmail($startDate);

    $safeExpiryDate =
        tenantApiEscapeEmail($expiryDate);

    $safeTrialEndDate =
        tenantApiEscapeEmail($trialEndDate);

    $safePortalUrl =
        tenantApiEscapeEmail($portalUrl);

    $safeAdminName =
        tenantApiEscapeEmail($adminName);
    $safeAdminEmail =
        tenantApiEscapeEmail($adminEmail);
    $safeTemporaryPassword =
        tenantApiEscapeEmail($temporaryPassword);
    $safePermissionCount =
        tenantApiEscapeEmail((string)$permissionCount);

    $subject =
        'Welcome to ' .
        $platformName .
        ' - Your workspace is ready';

    $portalButton = '';

    if ($portalUrl !== '') {
        $portalButton = '
            <div style="
                text-align:center;
                margin:28px 0 6px;
            ">
                <a
                    href="' . $safePortalUrl . '"
                    style="
                        display:inline-block;
                        padding:12px 22px;
                        border-radius:9px;
                        background:#6d28d9;
                        color:#ffffff;
                        text-decoration:none;
                        font-size:14px;
                        font-weight:700;
                    "
                >
                    Open FieldPlx
                </a>
            </div>
        ';
    }

    $trialRow = '';

    if ($trialEndDate !== '') {
        $trialRow = '
            <tr>
                <td style="
                    padding:10px 0;
                    color:#7c738d;
                    font-size:13px;
                ">
                    Trial End
                </td>
                <td style="
                    padding:10px 0;
                    color:#211c32;
                    text-align:right;
                    font-size:13px;
                    font-weight:700;
                ">
                    ' . $safeTrialEndDate . '
                </td>
            </tr>
        ';
    }

    $html = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . tenantApiEscapeEmail($subject) . '</title>
</head>
<body style="
    margin:0;
    padding:0;
    background:#f5f2fb;
    font-family:Arial,Helvetica,sans-serif;
    color:#211c32;
">
<table
    width="100%"
    cellpadding="0"
    cellspacing="0"
    border="0"
    style="
        width:100%;
        background:#f5f2fb;
        padding:28px 12px;
    "
>
<tr>
<td align="center">

<table
    width="620"
    cellpadding="0"
    cellspacing="0"
    border="0"
    style="
        width:100%;
        max-width:620px;
        background:#ffffff;
        border:1px solid #e3dcf3;
        border-radius:16px;
        overflow:hidden;
        box-shadow:0 12px 34px rgba(37,29,80,.08);
    "
>
<tr>
<td style="
    padding:22px 26px;
    background:linear-gradient(
        135deg,
        #12182d 0%,
        #201f6b 100%
    );
">
    <div style="
        display:inline-block;
        padding:8px 10px;
        border-radius:9px;
        background:#8b5cf6;
        color:#ffffff;
        font-size:14px;
        font-weight:800;
        letter-spacing:.4px;
    ">
        FP
    </div>

    <div style="
        margin-top:14px;
        color:#ffffff;
        font-size:23px;
        font-weight:800;
    ">
        Welcome to FieldPlx
    </div>

    <div style="
        margin-top:5px;
        color:#cfc7f6;
        font-size:13px;
        line-height:1.6;
    ">
        Your business workspace has been created successfully.
    </div>
</td>
</tr>

<tr>
<td style="padding:28px 26px 8px">

    <div style="
        color:#211c32;
        font-size:18px;
        font-weight:800;
    ">
        Hello ' . $safeAdminName . ',
    </div>

    <div style="
        margin-top:12px;
        color:#6f677d;
        font-size:14px;
        line-height:1.75;
    ">
        We are pleased to confirm that your FieldPlx workspace
        has been created successfully. Your organization can now
        begin setting up branches, users, roles, customers,
        workflows and field-service operations.
    </div>

    <div style="
        margin-top:22px;
        padding:18px;
        border:1px solid #e4ddf3;
        border-radius:12px;
        background:#fbf9ff;
    ">
        <div style="
            margin-bottom:8px;
            color:#6d28d9;
            font-size:12px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.5px;
        ">
            Workspace Details
        </div>

        <table
            width="100%"
            cellpadding="0"
            cellspacing="0"
            border="0"
        >
            <tr>
                <td style="
                    padding:10px 0;
                    color:#7c738d;
                    font-size:13px;
                ">
                    Workspace
                </td>
                <td style="
                    padding:10px 0;
                    color:#211c32;
                    text-align:right;
                    font-size:13px;
                    font-weight:700;
                ">
                    ' . $safeTenantName . '
                </td>
            </tr>

            <tr>
                <td style="
                    padding:10px 0;
                    color:#7c738d;
                    font-size:13px;
                ">
                    Tenant Code
                </td>
                <td style="
                    padding:10px 0;
                    color:#211c32;
                    text-align:right;
                    font-size:13px;
                    font-weight:700;
                ">
                    ' . $safeTenantCode . '
                </td>
            </tr>

            <tr>
                <td style="
                    padding:10px 0;
                    color:#7c738d;
                    font-size:13px;
                ">
                    Plan
                </td>
                <td style="
                    padding:10px 0;
                    color:#211c32;
                    text-align:right;
                    font-size:13px;
                    font-weight:700;
                ">
                    ' . $safePlanName . '
                </td>
            </tr>

            <tr>
                <td style="
                    padding:10px 0;
                    color:#7c738d;
                    font-size:13px;
                ">
                    Status
                </td>
                <td style="
                    padding:10px 0;
                    color:#211c32;
                    text-align:right;
                    font-size:13px;
                    font-weight:700;
                ">
                    ' . $safeStatus . '
                </td>
            </tr>

            <tr>
                <td style="
                    padding:10px 0;
                    color:#7c738d;
                    font-size:13px;
                ">
                    Subscription Start
                </td>
                <td style="
                    padding:10px 0;
                    color:#211c32;
                    text-align:right;
                    font-size:13px;
                    font-weight:700;
                ">
                    ' . $safeStartDate . '
                </td>
            </tr>

            <tr>
                <td style="
                    padding:10px 0;
                    color:#7c738d;
                    font-size:13px;
                ">
                    Subscription Expiry
                </td>
                <td style="
                    padding:10px 0;
                    color:#211c32;
                    text-align:right;
                    font-size:13px;
                    font-weight:700;
                ">
                    ' . $safeExpiryDate . '
                </td>
            </tr>

            ' . $trialRow . '
        </table>
    </div>

    <div style="
        margin-top:18px;
        padding:18px;
        border:1px solid #cfc2f7;
        border-radius:12px;
        background:#f7f3ff;
    ">
        <div style="
            margin-bottom:10px;
            color:#6d28d9;
            font-size:12px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.5px;
        ">
            Business Administrator Login
        </div>

        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td style="padding:9px 0;color:#7c738d;font-size:13px;">Login Email</td>
                <td style="padding:9px 0;color:#211c32;text-align:right;font-size:13px;font-weight:700;">
                    ' . $safeAdminEmail . '
                </td>
            </tr>
            <tr>
                <td style="padding:9px 0;color:#7c738d;font-size:13px;">Tenant Code</td>
                <td style="padding:9px 0;color:#211c32;text-align:right;font-size:13px;font-weight:700;">
                    ' . $safeTenantCode . '
                </td>
            </tr>
            <tr>
                <td style="padding:9px 0;color:#7c738d;font-size:13px;">Temporary Password</td>
                <td style="padding:9px 0;color:#211c32;text-align:right;font-size:14px;font-weight:800;letter-spacing:.5px;">
                    ' . $safeTemporaryPassword . '
                </td>
            </tr>
            <tr>
                <td style="padding:9px 0;color:#7c738d;font-size:13px;">Permissions Enabled</td>
                <td style="padding:9px 0;color:#211c32;text-align:right;font-size:13px;font-weight:700;">
                    ' . $safePermissionCount . '
                </td>
            </tr>
        </table>

        <div style="margin-top:10px;color:#6f677d;font-size:12px;line-height:1.65;">
            Use either the Tenant Code or Login Email with the temporary password.
            Please change this password from My Profile after the first login.
        </div>
    </div>

    ' . $portalButton . '

    <div style="
        margin-top:24px;
        padding:16px;
        border-radius:10px;
        background:#f8f6ff;
        color:#675f76;
        font-size:13px;
        line-height:1.7;
    ">
        <strong style="color:#332d43">
            Next steps
        </strong>
        <br>
        1. Sign in using the administrator credentials above.<br>
        2. Change the temporary password from My Profile.<br>
        3. Configure branches, departments and employees.<br>
        4. Add customers, services and operational workflows.
    </div>

    <div style="
        margin-top:24px;
        color:#7a7288;
        font-size:12px;
        line-height:1.7;
    ">
        If you did not expect this workspace creation email,
        please contact the FieldPlx platform administrator.
    </div>

</td>
</tr>

<tr>
<td style="
    padding:18px 26px 24px;
    color:#9b94a7;
    font-size:11px;
    line-height:1.7;
">
    This is an automated message from FieldPlx.
    This email contains a temporary password; store it securely and change it after first login.
</td>
</tr>

</table>

</td>
</tr>
</table>
</body>
</html>
';

    $text =
        "Welcome to FieldPlx\n\n" .
        "Hello " . $tenantName . ",\n\n" .
        "Your FieldPlx workspace has been created successfully.\n\n" .
        "Workspace: " . $tenantName . "\n" .
        "Tenant Code: " . $tenantCode . "\n" .
        "Plan: " . $planName . "\n" .
        "Status: " . $status . "\n" .
        "Subscription Start: " . $startDate . "\n" .
        "Subscription Expiry: " . $expiryDate . "\n" .
        "\nBusiness Administrator Login\n" .
        "Login Email: " . $adminEmail . "\n" .
        "Tenant Code: " . $tenantCode . "\n" .
        "Temporary Password: " . $temporaryPassword . "\n" .
        "Permissions Enabled: " . $permissionCount . "\n" .
        (
            $trialEndDate !== ''
                ? "Trial End: " . $trialEndDate . "\n"
                : ''
        ) .
        "\nSign in and change the temporary password from My Profile, then configure your workspace.\n";

    if ($portalUrl !== '') {
        $text .=
            "\nOpen FieldPlx: " .
            $portalUrl .
            "\n";
    }

    return array(
        'subject' => $subject,
        'html' => $html,
        'text' => $text
    );
}

function tenantApiSendWelcomeEmail(
    PDO $pdo,
    string $recipient,
    array $tenant,
    ?array $plan,
    array $subscription,
    array $admin
): array {
    if (
        $recipient === '' ||
        !filter_var(
            $recipient,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return array(
            'sent' => false,
            'message' =>
                'Tenant email address is empty or invalid.'
        );
    }

    $smtp =
        tenantApiPlatformSmtp($pdo);

    if (!$smtp) {
        return array(
            'sent' => false,
            'message' =>
                'No active Platform SMTP configuration is available.'
        );
    }

    try {
        tenantApiLoadPhpMailer();

        $password =
            tenantApiDecryptSmtpPassword(
                isset(
                    $smtp['password_encrypted']
                )
                    ? (string) $smtp[
                        'password_encrypted'
                    ]
                    : ''
            );

        $mail =
            new \PHPMailer\PHPMailer\PHPMailer(
                true
            );

        $mail->isSMTP();

        $mail->Host =
            trim(
                (string) $smtp['host']
            );

        $mail->Port =
            (int) $smtp['port'];

        $mail->Timeout = 20;

        if (
            property_exists(
                $mail,
                'Timelimit'
            )
        ) {
            $mail->Timelimit = 20;
        }

        $mail->SMTPDebug = 0;

        $username =
            trim(
                (string) $smtp['username']
            );

        $mail->SMTPAuth =
            $username !== '';

        if ($mail->SMTPAuth) {
            if ($password === '') {
                throw new RuntimeException(
                    'SMTP password is empty or could not be decrypted.'
                );
            }

            $mail->Username =
                $username;

            $mail->Password =
                $password;
        }

        $encryption =
            strtolower(
                trim(
                    (string) $smtp[
                        'encryption'
                    ]
                )
            );

        if ($encryption === 'ssl') {
            $mail->SMTPSecure =
                \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;

            $mail->SMTPAutoTLS =
                false;

        } elseif (
            $encryption === 'tls' ||
            $encryption === 'starttls'
        ) {
            $mail->SMTPSecure =
                \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

            $mail->SMTPAutoTLS =
                true;

        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $fromEmail =
            trim(
                (string) $smtp[
                    'from_email'
                ]
            );

        if (
            !filter_var(
                $fromEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                'Platform SMTP From Email is invalid.'
            );
        }

        $fromName =
            trim(
                (string) $smtp[
                    'from_name'
                ]
            );

        if ($fromName === '') {
            $fromName = 'FieldPlx';
        }

        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            $fromEmail,
            $fromName
        );

        $replyTo =
            trim(
                (string) $smtp[
                    'reply_to_email'
                ]
            );

        if (
            $replyTo !== '' &&
            filter_var(
                $replyTo,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $mail->addReplyTo(
                $replyTo
            );
        }

        $mail->addAddress(
            $recipient,
            (string)($admin['name'] ?? $tenant['display_name'])
        );

        $template =
            tenantApiBuildWelcomeEmail(
                $tenant,
                $plan,
                $subscription,
                $admin
            );

        $mail->isHTML(true);

        $mail->Subject =
            $template['subject'];

        $mail->Body =
            $template['html'];

        $mail->AltBody =
            $template['text'];

        $mail->send();

        return array(
            'sent' => true,
            'message' =>
                'Welcome email sent successfully.'
        );

    } catch (Throwable $mailException) {
        error_log(
            'FieldPlx tenant welcome email error: ' .
            $mailException->getMessage()
        );

        return array(
            'sent' => false,
            'message' =>
                'Tenant was created, but welcome email could not be sent: ' .
                $mailException->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| Request validation
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {
    tenantApiResponse(
        405,
        false,
        'Method not allowed.'
    );
}

$csrfToken = tenantApiPost('csrf_token');

if (
    empty($_SESSION['tenant_add_csrf']) ||
    !is_string($_SESSION['tenant_add_csrf']) ||
    $csrfToken === '' ||
    !hash_equals(
        $_SESSION['tenant_add_csrf'],
        $csrfToken
    )
) {
    tenantApiResponse(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$tenantCode = strtoupper(
    tenantApiPost('tenant_code')
);

$legalName = tenantApiPost('legal_name');
$displayName = tenantApiPost('display_name');
$businessType = tenantApiPost('business_type');
$registrationNumber =
    tenantApiPost('registration_number');
$taxNumber = tenantApiPost('tax_number');

$adminName = tenantApiPost('admin_name');
$email = strtolower(tenantApiPost('email'));
$phone = tenantApiPost('phone');
$alternatePhone =
    tenantApiPost('alternate_phone');
$websiteUrl =
    tenantApiPost('website_url');

$countryId = (int) tenantApiPost(
    'country_id',
    '0'
);

$currencyId = (int) tenantApiPost(
    'currency_id',
    '0'
);

$timezone = tenantApiPost(
    'timezone',
    'UTC'
);

$dateFormat = tenantApiPost(
    'date_format',
    'd-m-Y'
);

$addressLine1 =
    tenantApiPost('address_line1');
$addressLine2 =
    tenantApiPost('address_line2');
$city = tenantApiPost('city');
$state = tenantApiPost('state');
$postalCode = tenantApiPost('postal_code');

$status = strtolower(
    tenantApiPost(
        'status',
        'trial'
    )
);

$planId = (int) tenantApiPost(
    'plan_id',
    '0'
);

$subscriptionStart =
    tenantApiPost(
        'subscription_start',
        date('Y-m-d')
    );

$subscriptionExpiry =
    tenantApiPost('subscription_expiry');

$trialEndDate =
    tenantApiPost('trial_end_date');

$autoRenew =
    tenantApiPost('auto_renew') === '1'
        ? 1
        : 0;

$allowedTenantStatuses = array(
    'trial',
    'active',
    'suspended'
);

$allowedDateFormats = array(
    'd-m-Y',
    'd/m/Y',
    'm-d-Y',
    'm/d/Y',
    'Y-m-d'
);

if ($tenantCode === '') {
    tenantApiResponse(
        422,
        false,
        'Tenant code is required.'
    );
}

if (
    strlen($tenantCode) > 80 ||
    !preg_match(
        '/^[A-Z0-9][A-Z0-9_-]*$/',
        $tenantCode
    )
) {
    tenantApiResponse(
        422,
        false,
        'Tenant code may contain only letters, numbers, hyphens and underscores.'
    );
}

if (
    $legalName === '' ||
    strlen($legalName) > 190
) {
    tenantApiResponse(
        422,
        false,
        'Legal name is required and must be 190 characters or less.'
    );
}

if (
    $displayName === '' ||
    strlen($displayName) > 190
) {
    tenantApiResponse(
        422,
        false,
        'Display name is required and must be 190 characters or less.'
    );
}

if (
    $adminName === '' ||
    strlen($adminName) > 190
) {
    tenantApiResponse(
        422,
        false,
        'Business administrator name is required and must be 190 characters or less.'
    );
}

if ($countryId <= 0) {
    tenantApiResponse(
        422,
        false,
        'Please select a country.'
    );
}

if ($currencyId <= 0) {
    tenantApiResponse(
        422,
        false,
        'Please select a currency.'
    );
}

if (
    $timezone === '' ||
    strlen($timezone) > 100
) {
    tenantApiResponse(
        422,
        false,
        'Timezone is required.'
    );
}

if (
    !in_array(
        $dateFormat,
        $allowedDateFormats,
        true
    )
) {
    tenantApiResponse(
        422,
        false,
        'Invalid date format.'
    );
}

if (
    !in_array(
        $status,
        $allowedTenantStatuses,
        true
    )
) {
    tenantApiResponse(
        422,
        false,
        'Invalid tenant status.'
    );
}

if (
    $email === '' ||
    !filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    )
) {
    tenantApiResponse(
        422,
        false,
        'A valid business administrator email address is required.'
    );
}

if (
    $websiteUrl !== '' &&
    !filter_var(
        $websiteUrl,
        FILTER_VALIDATE_URL
    )
) {
    tenantApiResponse(
        422,
        false,
        'Enter a valid website URL including https:// or http://.'
    );
}

if (
    !tenantApiValidDate(
        $subscriptionStart
    ) ||
    !tenantApiValidDate(
        $subscriptionExpiry
    ) ||
    !tenantApiValidDate(
        $trialEndDate
    )
) {
    tenantApiResponse(
        422,
        false,
        'One or more subscription dates are invalid.'
    );
}

if (
    $subscriptionExpiry !== '' &&
    $subscriptionExpiry < $subscriptionStart
) {
    tenantApiResponse(
        422,
        false,
        'Subscription expiry date cannot be before the start date.'
    );
}

if (
    $trialEndDate !== '' &&
    $trialEndDate < $subscriptionStart
) {
    tenantApiResponse(
        422,
        false,
        'Trial end date cannot be before the subscription start date.'
    );
}

/*
|--------------------------------------------------------------------------
| Validate foreign keys
|--------------------------------------------------------------------------
*/

$countryStmt = $pdo->prepare("
    SELECT id
    FROM countries
    WHERE id = :id
      AND is_active = 1
    LIMIT 1
");

$countryStmt->execute(
    array(
        ':id' => $countryId
    )
);

if (!$countryStmt->fetchColumn()) {
    tenantApiResponse(
        422,
        false,
        'Selected country is not available.'
    );
}

$currencyStmt = $pdo->prepare("
    SELECT
        id,
        currency_code
    FROM currencies
    WHERE id = :id
      AND is_active = 1
    LIMIT 1
");

$currencyStmt->execute(
    array(
        ':id' => $currencyId
    )
);

$currencyRow = $currencyStmt->fetch();

if (!$currencyRow) {
    tenantApiResponse(
        422,
        false,
        'Selected currency is not available.'
    );
}

$plan = null;

if ($planId > 0) {
    $planStmt = $pdo->prepare("
        SELECT
            id,
            name,
            price,
            currency,
            billing_cycle,
            trial_days,
            max_users,
            max_branches,
            storage_limit_mb
        FROM plans
        WHERE id = :id
          AND status = 'active'
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $planStmt->execute(
        array(
            ':id' => $planId
        )
    );

    $plan = $planStmt->fetch();

    if (!$plan) {
        tenantApiResponse(
            422,
            false,
            'Selected subscription plan is not available.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Duplicate tenant code
|--------------------------------------------------------------------------
*/

$duplicateStmt = $pdo->prepare("
    SELECT id
    FROM tenants
    WHERE tenant_code = :tenant_code
      AND deleted_at IS NULL
    LIMIT 1
");

$duplicateStmt->execute(
    array(
        ':tenant_code' => $tenantCode
    )
);

if ($duplicateStmt->fetchColumn()) {
    tenantApiResponse(
        409,
        false,
        'Tenant code already exists. Please use another code.'
    );
}

/*
|--------------------------------------------------------------------------
| Save tenant + subscription
|--------------------------------------------------------------------------
*/

$logoPath = null;
$invoiceLogoPath = null;
$temporaryPassword = tenantApiGenerateTemporaryPassword(14);
$administrator = null;
$permissionCount = 0;

try {
    $logoPath =
        tenantApiUpload(
            'logo',
            $tenantCode
        );

    $invoiceLogoPath =
        tenantApiUpload(
            'invoice_logo',
            $tenantCode
        );

    $pdo->beginTransaction();

    $createdBy = null;

    if (
        isset($_SESSION['platform_user_id']) &&
        (int) $_SESSION['platform_user_id'] > 0
    ) {
        $createdBy =
            (int) $_SESSION['platform_user_id'];
    }

    $tenantStmt = $pdo->prepare("
        INSERT INTO tenants (
            tenant_code,
            legal_name,
            display_name,
            business_type,
            registration_number,
            tax_number,
            email,
            phone,
            alternate_phone,
            website_url,
            country_id,
            currency_id,
            timezone,
            date_format,
            logo_path,
            invoice_logo_path,
            address_line1,
            address_line2,
            city,
            state,
            postal_code,
            status,
            created_by
        ) VALUES (
            :tenant_code,
            :legal_name,
            :display_name,
            :business_type,
            :registration_number,
            :tax_number,
            :email,
            :phone,
            :alternate_phone,
            :website_url,
            :country_id,
            :currency_id,
            :timezone,
            :date_format,
            :logo_path,
            :invoice_logo_path,
            :address_line1,
            :address_line2,
            :city,
            :state,
            :postal_code,
            :status,
            :created_by
        )
    ");

    $tenantStmt->execute(
        array(
            ':tenant_code' =>
                $tenantCode,
            ':legal_name' =>
                $legalName,
            ':display_name' =>
                $displayName,
            ':business_type' =>
                tenantApiNullable(
                    $businessType
                ),
            ':registration_number' =>
                tenantApiNullable(
                    $registrationNumber
                ),
            ':tax_number' =>
                tenantApiNullable(
                    $taxNumber
                ),
            ':email' =>
                tenantApiNullable(
                    $email
                ),
            ':phone' =>
                tenantApiNullable(
                    $phone
                ),
            ':alternate_phone' =>
                tenantApiNullable(
                    $alternatePhone
                ),
            ':website_url' =>
                tenantApiNullable(
                    $websiteUrl
                ),
            ':country_id' =>
                $countryId,
            ':currency_id' =>
                $currencyId,
            ':timezone' =>
                $timezone,
            ':date_format' =>
                $dateFormat,
            ':logo_path' =>
                $logoPath,
            ':invoice_logo_path' =>
                $invoiceLogoPath,
            ':address_line1' =>
                tenantApiNullable(
                    $addressLine1
                ),
            ':address_line2' =>
                tenantApiNullable(
                    $addressLine2
                ),
            ':city' =>
                tenantApiNullable(
                    $city
                ),
            ':state' =>
                tenantApiNullable(
                    $state
                ),
            ':postal_code' =>
                tenantApiNullable(
                    $postalCode
                ),
            ':status' =>
                $status,
            ':created_by' =>
                $createdBy
        )
    );

    $tenantId =
        (int) $pdo->lastInsertId();

    /*
     * Every tenant receives one primary business administrator login.
     * The plaintext temporary password exists only for this request/email;
     * only its password_hash is stored in the users table.
     */
    $administrator = tenantApiCreateAdministrator(
        $pdo,
        $tenantId,
        $adminName,
        $email,
        tenantApiNullable($phone),
        $temporaryPassword
    );

    $permissionCount =
        tenantApiEnableAdministratorPlanPermissions(
            $pdo,
            $tenantId,
            (int)$administrator['role_id'],
            $planId
        );

    $subscriptionId = null;

    if (
        $planId > 0 &&
        is_array($plan)
    ) {
        $subscriptionStatus =
            $status === 'trial'
                ? 'trial'
                : (
                    $status === 'suspended'
                        ? 'suspended'
                        : 'active'
                );

        if (
            $trialEndDate === '' &&
            (int) $plan['trial_days'] > 0
        ) {
            $trialDate = new DateTime(
                $subscriptionStart
            );

            $trialDate->modify(
                '+' .
                (int) $plan['trial_days'] .
                ' days'
            );

            $trialEndDate =
                $trialDate->format(
                    'Y-m-d'
                );
        }

        $subscriptionStmt =
            $pdo->prepare("
                INSERT INTO subscriptions (
                    tenant_id,
                    plan_id,
                    currency_id,
                    amount,
                    start_date,
                    expiry_date,
                    trial_end_date,
                    auto_renew,
                    status,
                    created_by
                ) VALUES (
                    :tenant_id,
                    :plan_id,
                    :currency_id,
                    :amount,
                    :start_date,
                    :expiry_date,
                    :trial_end_date,
                    :auto_renew,
                    :status,
                    :created_by
                )
            ");

        $subscriptionStmt->execute(
            array(
                ':tenant_id' =>
                    $tenantId,
                ':plan_id' =>
                    $planId,
                ':currency_id' =>
                    $currencyId,
                ':amount' =>
                    (float) $plan['price'],
                ':start_date' =>
                    $subscriptionStart,
                ':expiry_date' =>
                    tenantApiNullable(
                        $subscriptionExpiry
                    ),
                ':trial_end_date' =>
                    tenantApiNullable(
                        $trialEndDate
                    ),
                ':auto_renew' =>
                    $autoRenew,
                ':status' =>
                    $subscriptionStatus,
                ':created_by' =>
                    $createdBy
            )
        );

        $subscriptionId =
            (int) $pdo->lastInsertId();
    }

    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | Send professional tenant welcome email
    |--------------------------------------------------------------------------
    |
    | Email is sent only after the database transaction succeeds.
    | Email failure never rolls back an already-created tenant.
    |
    */

    $emailResult =
        tenantApiSendWelcomeEmail(
            $pdo,
            $email,
            array(
                'tenant_id' =>
                    $tenantId,
                'tenant_code' =>
                    $tenantCode,
                'legal_name' =>
                    $legalName,
                'display_name' =>
                    $displayName,
                'status' =>
                    $status
            ),
            $plan,
            array(
                'subscription_id' =>
                    $subscriptionId,
                'start_date' =>
                    $subscriptionStart,
                'expiry_date' =>
                    $subscriptionExpiry,
                'trial_end_date' =>
                    $trialEndDate
            ),
            array(
                'user_id' =>
                    (int)$administrator['user_id'],
                'name' =>
                    (string)$administrator['name'],
                'email' =>
                    (string)$administrator['email'],
                'temporary_password' =>
                    $temporaryPassword,
                'permission_count' =>
                    $permissionCount
            )
        );

    /*
     * Rotate token after successful creation.
     */
    $_SESSION['tenant_add_csrf'] =
        bin2hex(random_bytes(32));

    $successMessage =
        'Tenant created successfully.';

    if ($emailResult['sent']) {
        $successMessage .=
            ' Administrator login created and welcome email sent to ' .
            $email .
            '.';
    } else {
        $successMessage .=
            ' Administrator login was created, but ' .
            $emailResult['message'];
    }

    tenantApiResponse(
        201,
        true,
        $successMessage,
        array(
            'tenant_id' =>
                $tenantId,
            'tenant_code' =>
                $tenantCode,
            'subscription_id' =>
                $subscriptionId,
            'admin_user_id' =>
                (int)$administrator['user_id'],
            'admin_role_id' =>
                (int)$administrator['role_id'],
            'admin_name' =>
                (string)$administrator['name'],
            'admin_login_id' =>
                (string)$administrator['email'],
            'temporary_password' =>
                $temporaryPassword,
            'permissions_enabled' =>
                $permissionCount,
            'login_url' =>
                tenantApiBusinessLoginUrl(),
            'email_sent' =>
                (bool) $emailResult['sent'],
            'email_message' =>
                $emailResult['message'],
            'redirect' =>
                'tenants.php'
        )
    );

} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    /*
     * Remove uploaded files if DB transaction fails.
     */
    foreach (
        array(
            $logoPath,
            $invoiceLogoPath
        ) as $relativePath
    ) {
        if (
            is_string($relativePath) &&
            $relativePath !== ''
        ) {
            $absolutePath =
                dirname(__DIR__) .
                DIRECTORY_SEPARATOR .
                str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relativePath
                );

            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    error_log(
        'FieldPlx tenant creation error: ' .
        $exception->getMessage()
    );

    if (
        $exception instanceof PDOException &&
        $exception->getCode() === '23000'
    ) {
        tenantApiResponse(
            409,
            false,
            'Tenant could not be created because a unique or related record already exists.'
        );
    }

    tenantApiResponse(
        500,
        false,
        'Unable to create tenant. Please try again.'
    );
}