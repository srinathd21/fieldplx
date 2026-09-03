<?php
/**
 * FieldPlx API - Edit / Update Tenant
 * Endpoint:
 *   GET  /platform/api/tenant-update.php?id=TENANT_ID
 *   POST /platform/api/tenant-update.php
 * PHP 7.2+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function tenantUpdateResponse(int $status, bool $success, string $message, array $extra = array()): void
{
    http_response_code($status);

    echo json_encode(
        array_merge(
            array(
                'success' => $success,
                'message' => $message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function tenantUpdatePost(string $key, string $default = ''): string
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }

    return trim((string) $_POST[$key]);
}

function tenantUpdateNullable(string $value)
{
    $value = trim($value);
    return $value === '' ? null : $value;
}

function tenantUpdateValidDate(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);

    return $date !== false &&
        $date->format('Y-m-d') === $value;
}

function tenantUpdateUpload(string $field, string $tenantCode)
{
    if (
        !isset($_FILES[$field]) ||
        !is_array($_FILES[$field]) ||
        !isset($_FILES[$field]['error'])
    ) {
        return null;
    }

    $file = $_FILES[$field];

    if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('One of the uploaded logo files could not be processed.');
    }

    if (
        !isset($file['size']) ||
        (int) $file['size'] <= 0 ||
        (int) $file['size'] > 3 * 1024 * 1024
    ) {
        throw new RuntimeException('Logo files must be 3 MB or smaller.');
    }

    $tmpName = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid uploaded logo file.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpName);

    $allowed = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    );

    if (!is_string($mime) || !isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG and WEBP logo files are allowed.');
    }

    $safeTenantCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', $tenantCode);
    $safeTenantCode = trim((string) $safeTenantCode, '-');

    if ($safeTenantCode === '') {
        $safeTenantCode = 'tenant';
    }

    $uploadRelativeDir = 'uploads/tenants/' . strtolower($safeTenantCode);

    $uploadAbsoluteDir =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        str_replace('/', DIRECTORY_SEPARATOR, $uploadRelativeDir);

    if (
        !is_dir($uploadAbsoluteDir) &&
        !mkdir($uploadAbsoluteDir, 0755, true) &&
        !is_dir($uploadAbsoluteDir)
    ) {
        throw new RuntimeException('Unable to create tenant upload directory.');
    }

    $filename =
        $field . '-' .
        date('YmdHis') . '-' .
        bin2hex(random_bytes(4)) . '.' .
        $allowed[$mime];

    $absolutePath = $uploadAbsoluteDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpName, $absolutePath)) {
        throw new RuntimeException('Unable to save uploaded logo.');
    }

    return array(
        'relative_path' => $uploadRelativeDir . '/' . $filename,
        'absolute_path' => $absolutePath
    );
}

function tenantUpdateDeleteOldFile(?string $relativePath): void
{
    $relativePath = trim((string) $relativePath);

    if ($relativePath === '') {
        return;
    }

    $normalized = str_replace('\\', '/', $relativePath);

    if (strpos($normalized, 'uploads/tenants/') !== 0) {
        return;
    }

    $absolute =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        str_replace('/', DIRECTORY_SEPARATOR, $normalized);

    if (is_file($absolute)) {
        @unlink($absolute);
    }
}


/* Admin permission and email helpers */
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
        "Your FieldPlx administrator login details have been updated successfully.\n\n" .
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
                'Tenant was updated, but administrator email could not be sent: ' .
                $mailException->getMessage()
        );
    }
}



function tenantUpdateLatestSubscription(PDO $pdo, int $tenantId)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM subscriptions
        WHERE tenant_id = :tenant_id
          AND deleted_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute(array(':tenant_id' => $tenantId));

    return $stmt->fetch();
}

function tenantUpdateLoadData(PDO $pdo, int $tenantId): void
{
    if (
        empty($_SESSION['tenant_edit_csrf']) ||
        !is_string($_SESSION['tenant_edit_csrf'])
    ) {
        $_SESSION['tenant_edit_csrf'] = bin2hex(random_bytes(32));
    }

    $tenantStmt = $pdo->prepare("
        SELECT *
        FROM tenants
        WHERE id = :tenant_id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $tenantStmt->execute(array(':tenant_id' => $tenantId));
    $tenant = $tenantStmt->fetch();

    if (!$tenant) {
        tenantUpdateResponse(404, false, 'Tenant not found.');
    }

    $subscription = tenantUpdateLatestSubscription($pdo, $tenantId);

    $countryStmt = $pdo->prepare("
        SELECT
            id,
            name,
            iso2,
            phone_code,
            default_currency_code,
            default_timezone,
            date_format
        FROM countries
        WHERE is_active = 1
           OR id = :current_id
        ORDER BY name ASC
    ");
    $countryStmt->execute(array(':current_id' => (int) $tenant['country_id']));
    $countries = $countryStmt->fetchAll();

    $currencyStmt = $pdo->prepare("
        SELECT
            id,
            currency_code,
            currency_name,
            symbol
        FROM currencies
        WHERE is_active = 1
           OR id = :current_id
        ORDER BY currency_code ASC
    ");
    $currencyStmt->execute(array(':current_id' => (int) $tenant['currency_id']));
    $currencies = $currencyStmt->fetchAll();

    $currentPlanId =
        $subscription && !empty($subscription['plan_id'])
            ? (int) $subscription['plan_id']
            : 0;

    $planStmt = $pdo->prepare("
        SELECT
            id,
            name,
            code,
            price,
            currency,
            billing_cycle,
            trial_days,
            max_users,
            max_branches,
            storage_limit_mb
        FROM plans
        WHERE deleted_at IS NULL
          AND (status = 'active' OR id = :current_plan_id)
        ORDER BY is_featured DESC, price ASC, name ASC
    ");
    $planStmt->execute(array(':current_plan_id' => $currentPlanId));
    $plans = $planStmt->fetchAll();

    if (!$subscription) {
        $subscription = array(
            'id' => null,
            'plan_id' => null,
            'currency_id' => null,
            'amount' => null,
            'start_date' => null,
            'expiry_date' => null,
            'trial_end_date' => null,
            'auto_renew' => 0,
            'status' => null
        );
    }

    tenantUpdateResponse(
        200,
        true,
        'Tenant loaded successfully.',
        array(
            'csrf_token' => $_SESSION['tenant_edit_csrf'],
            'data' => array(
                'tenant' => $tenant,
                'subscription' => $subscription,
                'countries' => $countries,
                'currencies' => $currencies,
                'plans' => $plans
            )
        )
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tenantId =
        isset($_GET['id']) && !is_array($_GET['id'])
            ? (int) $_GET['id']
            : 0;

    if ($tenantId <= 0) {
        tenantUpdateResponse(422, false, 'Invalid tenant ID.');
    }

    tenantUpdateLoadData($pdo, $tenantId);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tenantUpdateResponse(405, false, 'Method not allowed.');
}

$csrfToken = tenantUpdatePost('csrf_token');

if (
    empty($_SESSION['tenant_edit_csrf']) ||
    !is_string($_SESSION['tenant_edit_csrf']) ||
    $csrfToken === '' ||
    !hash_equals($_SESSION['tenant_edit_csrf'], $csrfToken)
) {
    tenantUpdateResponse(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$tenantId = (int) tenantUpdatePost('tenant_id', '0');

if ($tenantId <= 0) {
    tenantUpdateResponse(422, false, 'Invalid tenant ID.');
}

$currentStmt = $pdo->prepare("
    SELECT *
    FROM tenants
    WHERE id = :tenant_id
      AND deleted_at IS NULL
    LIMIT 1
");
$currentStmt->execute(array(':tenant_id' => $tenantId));
$currentTenant = $currentStmt->fetch();

if (!$currentTenant) {
    tenantUpdateResponse(404, false, 'Tenant not found.');
}


$adminLookupStmt = $pdo->prepare("
    SELECT u.*, r.name AS role_name, r.is_admin
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id AND r.tenant_id = u.tenant_id
    WHERE u.tenant_id = :tenant_id
      AND u.is_tenant_admin = 1
      AND u.deleted_at IS NULL
    ORDER BY u.id ASC
    LIMIT 1
");
$adminLookupStmt->execute(array(':tenant_id' => $tenantId));
$currentAdmin = $adminLookupStmt->fetch();

$tenantCode = strtoupper(tenantUpdatePost('tenant_code'));
$legalName = tenantUpdatePost('legal_name');
$displayName = tenantUpdatePost('display_name');
$businessType = tenantUpdatePost('business_type');
$registrationNumber = tenantUpdatePost('registration_number');
$taxNumber = tenantUpdatePost('tax_number');
$email = tenantUpdatePost('email');
$phone = tenantUpdatePost('phone');
$alternatePhone = tenantUpdatePost('alternate_phone');
$websiteUrl = tenantUpdatePost('website_url');
$countryId = (int) tenantUpdatePost('country_id', '0');
$currencyId = (int) tenantUpdatePost('currency_id', '0');
$timezone = tenantUpdatePost('timezone', 'UTC');
$dateFormat = tenantUpdatePost('date_format', 'd-m-Y');
$addressLine1 = tenantUpdatePost('address_line1');
$addressLine2 = tenantUpdatePost('address_line2');
$city = tenantUpdatePost('city');
$state = tenantUpdatePost('state');
$postalCode = tenantUpdatePost('postal_code');
$status = strtolower(tenantUpdatePost('status', 'trial'));
$planId = (int) tenantUpdatePost('plan_id', '0');
$subscriptionStart = tenantUpdatePost('subscription_start');
$subscriptionExpiry = tenantUpdatePost('subscription_expiry');
$trialEndDate = tenantUpdatePost('trial_end_date');
$autoRenew = tenantUpdatePost('auto_renew') === '1' ? 1 : 0;
$adminUserId = (int) tenantUpdatePost('admin_user_id', '0');
$adminName = tenantUpdatePost('admin_name');
$adminEmail = strtolower(tenantUpdatePost('admin_email'));
$enableMissingPermissions = tenantUpdatePost('enable_missing_permissions') === '1';
$resendAdminEmail = tenantUpdatePost('resend_admin_email') === '1';


$allowedTenantStatuses = array(
    'trial','active','expired','suspended','cancelled','archived'
);

$allowedDateFormats = array(
    'd-m-Y','d/m/Y','m-d-Y','m/d/Y','Y-m-d'
);

if (
    $tenantCode === '' ||
    strlen($tenantCode) > 80 ||
    !preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $tenantCode)
) {
    tenantUpdateResponse(
        422,
        false,
        'Tenant code is required and may contain only letters, numbers, hyphens and underscores.'
    );
}

if ($legalName === '' || strlen($legalName) > 190) {
    tenantUpdateResponse(422, false, 'Legal name is required and must be 190 characters or less.');
}

if ($displayName === '' || strlen($displayName) > 190) {
    tenantUpdateResponse(422, false, 'Display name is required and must be 190 characters or less.');
}

if ($countryId <= 0) {
    tenantUpdateResponse(422, false, 'Please select a country.');
}

if ($currencyId <= 0) {
    tenantUpdateResponse(422, false, 'Please select a currency.');
}

if ($timezone === '' || strlen($timezone) > 100) {
    tenantUpdateResponse(422, false, 'Timezone is required.');
}

if (!in_array($dateFormat, $allowedDateFormats, true)) {
    tenantUpdateResponse(422, false, 'Invalid date format.');
}

if (!in_array($status, $allowedTenantStatuses, true)) {
    tenantUpdateResponse(422, false, 'Invalid tenant status.');
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    tenantUpdateResponse(422, false, 'Enter a valid email address.');
}


if ($currentAdmin) {
    if ($adminUserId <= 0 || $adminUserId !== (int) $currentAdmin['id']) {
        tenantUpdateResponse(422, false, 'Invalid tenant administrator selection.');
    }
    if ($adminName === '' || strlen($adminName) > 240) {
        tenantUpdateResponse(422, false, 'Administrator name is required and must be 240 characters or less.');
    }
    if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        tenantUpdateResponse(422, false, 'Enter a valid administrator login email.');
    }
    $adminDupStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id <> :id AND deleted_at IS NULL LIMIT 1");
    $adminDupStmt->execute(array(':email' => $adminEmail, ':id' => (int) $currentAdmin['id']));
    if ($adminDupStmt->fetchColumn()) {
        tenantUpdateResponse(409, false, 'Administrator login email is already used by another user.');
    }
}

if ($websiteUrl !== '' && !filter_var($websiteUrl, FILTER_VALIDATE_URL)) {
    tenantUpdateResponse(422, false, 'Enter a valid website URL including https:// or http://.');
}

if (
    !tenantUpdateValidDate($subscriptionStart) ||
    !tenantUpdateValidDate($subscriptionExpiry) ||
    !tenantUpdateValidDate($trialEndDate)
) {
    tenantUpdateResponse(422, false, 'One or more subscription dates are invalid.');
}

if ($planId > 0 && $subscriptionStart === '') {
    tenantUpdateResponse(422, false, 'Subscription start date is required when a plan is selected.');
}

if (
    $subscriptionExpiry !== '' &&
    $subscriptionStart !== '' &&
    $subscriptionExpiry < $subscriptionStart
) {
    tenantUpdateResponse(422, false, 'Subscription expiry date cannot be before the start date.');
}

if (
    $trialEndDate !== '' &&
    $subscriptionStart !== '' &&
    $trialEndDate < $subscriptionStart
) {
    tenantUpdateResponse(422, false, 'Trial end date cannot be before the subscription start date.');
}

$countryStmt = $pdo->prepare("SELECT id FROM countries WHERE id = :id LIMIT 1");
$countryStmt->execute(array(':id' => $countryId));

if (!$countryStmt->fetchColumn()) {
    tenantUpdateResponse(422, false, 'Selected country is not available.');
}

$currencyStmt = $pdo->prepare("
    SELECT id, currency_code
    FROM currencies
    WHERE id = :id
    LIMIT 1
");
$currencyStmt->execute(array(':id' => $currencyId));
$currencyRow = $currencyStmt->fetch();

if (!$currencyRow) {
    tenantUpdateResponse(422, false, 'Selected currency is not available.');
}

$plan = null;

if ($planId > 0) {
    $planStmt = $pdo->prepare("
        SELECT
            id,
            name,
            code,
            price,
            currency,
            billing_cycle,
            trial_days,
            max_users,
            max_branches,
            storage_limit_mb
        FROM plans
        WHERE id = :id
          AND deleted_at IS NULL
        LIMIT 1
    ");
    $planStmt->execute(array(':id' => $planId));
    $plan = $planStmt->fetch();

    if (!$plan) {
        tenantUpdateResponse(422, false, 'Selected subscription plan is not available.');
    }
}

$duplicateStmt = $pdo->prepare("
    SELECT id
    FROM tenants
    WHERE tenant_code = :tenant_code
      AND id <> :tenant_id
      AND deleted_at IS NULL
    LIMIT 1
");
$duplicateStmt->execute(
    array(
        ':tenant_code' => $tenantCode,
        ':tenant_id' => $tenantId
    )
);

if ($duplicateStmt->fetchColumn()) {
    tenantUpdateResponse(409, false, 'Tenant code already exists. Please use another code.');
}

$currentSubscription = tenantUpdateLatestSubscription($pdo, $tenantId);

$newLogo = null;
$newInvoiceLogo = null;

try {
    $newLogo = tenantUpdateUpload('logo', $tenantCode);
    $newInvoiceLogo = tenantUpdateUpload('invoice_logo', $tenantCode);

    $logoPath =
        $newLogo !== null
            ? $newLogo['relative_path']
            : $currentTenant['logo_path'];

    $invoiceLogoPath =
        $newInvoiceLogo !== null
            ? $newInvoiceLogo['relative_path']
            : $currentTenant['invoice_logo_path'];

    $pdo->beginTransaction();

    $updateTenantStmt = $pdo->prepare("
        UPDATE tenants
        SET
            tenant_code = :tenant_code,
            legal_name = :legal_name,
            display_name = :display_name,
            business_type = :business_type,
            registration_number = :registration_number,
            tax_number = :tax_number,
            email = :email,
            phone = :phone,
            alternate_phone = :alternate_phone,
            website_url = :website_url,
            country_id = :country_id,
            currency_id = :currency_id,
            timezone = :timezone,
            date_format = :date_format,
            logo_path = :logo_path,
            invoice_logo_path = :invoice_logo_path,
            address_line1 = :address_line1,
            address_line2 = :address_line2,
            city = :city,
            state = :state,
            postal_code = :postal_code,
            status = :status,
            updated_at = NOW()
        WHERE id = :tenant_id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $updateTenantStmt->execute(
        array(
            ':tenant_code' => $tenantCode,
            ':legal_name' => $legalName,
            ':display_name' => $displayName,
            ':business_type' => tenantUpdateNullable($businessType),
            ':registration_number' => tenantUpdateNullable($registrationNumber),
            ':tax_number' => tenantUpdateNullable($taxNumber),
            ':email' => tenantUpdateNullable($email),
            ':phone' => tenantUpdateNullable($phone),
            ':alternate_phone' => tenantUpdateNullable($alternatePhone),
            ':website_url' => tenantUpdateNullable($websiteUrl),
            ':country_id' => $countryId,
            ':currency_id' => $currencyId,
            ':timezone' => $timezone,
            ':date_format' => $dateFormat,
            ':logo_path' => tenantUpdateNullable((string) $logoPath),
            ':invoice_logo_path' => tenantUpdateNullable((string) $invoiceLogoPath),
            ':address_line1' => tenantUpdateNullable($addressLine1),
            ':address_line2' => tenantUpdateNullable($addressLine2),
            ':city' => tenantUpdateNullable($city),
            ':state' => tenantUpdateNullable($state),
            ':postal_code' => tenantUpdateNullable($postalCode),
            ':status' => $status,
            ':tenant_id' => $tenantId
        )
    );


    $adminIdentityChanged = false;
    $temporaryPassword = '';
    $adminMailRequested = false;
    $adminRoleId = 0;
    $permissionCount = 0;
    $missingPermissionCount = 0;

    if ($currentAdmin) {
        $oldAdminName = trim((string) $currentAdmin['first_name'] . ' ' . (string) $currentAdmin['last_name']);
        $oldAdminEmail = strtolower(trim((string) $currentAdmin['email']));
        $adminIdentityChanged = (
            $oldAdminName !== trim($adminName) ||
            $oldAdminEmail !== $adminEmail ||
            strtoupper((string) $currentTenant['tenant_code']) !== $tenantCode
        );
        $adminMailRequested = $adminIdentityChanged || $resendAdminEmail;

        list($adminFirstName, $adminLastName) = tenantApiAdminNameParts($adminName);

        if ($adminMailRequested) {
            $temporaryPassword = tenantApiGenerateTemporaryPassword(14);
            $newPasswordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
            if ($newPasswordHash === false) {
                throw new RuntimeException('Unable to secure the new administrator temporary password.');
            }
            $adminUpdateStmt = $pdo->prepare("
                UPDATE users SET
                    first_name = :first_name,
                    last_name = :last_name,
                    email = :email,
                    password_hash = :password_hash,
                    updated_at = NOW()
                WHERE id = :user_id AND tenant_id = :tenant_id AND is_tenant_admin = 1 AND deleted_at IS NULL
                LIMIT 1
            ");
            $adminUpdateStmt->execute(array(
                ':first_name' => $adminFirstName,
                ':last_name' => $adminLastName,
                ':email' => $adminEmail,
                ':password_hash' => $newPasswordHash,
                ':user_id' => (int) $currentAdmin['id'],
                ':tenant_id' => $tenantId
            ));
        } else {
            $adminUpdateStmt = $pdo->prepare("
                UPDATE users SET first_name=:first_name,last_name=:last_name,email=:email,updated_at=NOW()
                WHERE id=:user_id AND tenant_id=:tenant_id AND is_tenant_admin=1 AND deleted_at IS NULL LIMIT 1
            ");
            $adminUpdateStmt->execute(array(
                ':first_name'=>$adminFirstName, ':last_name'=>$adminLastName, ':email'=>$adminEmail,
                ':user_id'=>(int)$currentAdmin['id'], ':tenant_id'=>$tenantId
            ));
        }

        /* Ensure a canonical active Admin role exists and is assigned. */
        $adminRoleStmt = $pdo->prepare("SELECT id FROM roles WHERE tenant_id=:tenant_id AND code='admin' LIMIT 1");
        $adminRoleStmt->execute(array(':tenant_id'=>$tenantId));
        $adminRoleId = (int) $adminRoleStmt->fetchColumn();
        if ($adminRoleId <= 0) {
            $createRoleStmt = $pdo->prepare("INSERT INTO roles (tenant_id,name,code,description,is_admin,is_system_role,status) VALUES (:tenant_id,'Admin','admin','Tenant administrator',1,1,'active')");
            $createRoleStmt->execute(array(':tenant_id'=>$tenantId));
            $adminRoleId = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare("UPDATE roles SET is_admin=1,status='active' WHERE id=:id AND tenant_id=:tenant_id")->execute(array(':id'=>$adminRoleId,':tenant_id'=>$tenantId));
        }
        $pdo->prepare("UPDATE users SET role_id=:role_id WHERE id=:user_id AND tenant_id=:tenant_id")->execute(array(':role_id'=>$adminRoleId,':user_id'=>(int)$currentAdmin['id'],':tenant_id'=>$tenantId));
    }

    $createdBy = null;

    if (
        isset($_SESSION['platform_user_id']) &&
        (int) $_SESSION['platform_user_id'] > 0
    ) {
        $createdBy = (int) $_SESSION['platform_user_id'];
    }

    if ($planId > 0 && is_array($plan)) {
        $subscriptionStatus =
            $status === 'trial'
                ? 'trial'
                : (
                    $status === 'suspended'
                        ? 'suspended'
                        : (
                            $status === 'expired'
                                ? 'expired'
                                : (
                                    $status === 'cancelled' || $status === 'archived'
                                        ? 'cancelled'
                                        : 'active'
                                )
                        )
                );

        if ($trialEndDate === '' && (int) $plan['trial_days'] > 0) {
            $trialDate = new DateTime($subscriptionStart);
            $trialDate->modify('+' . (int) $plan['trial_days'] . ' days');
            $trialEndDate = $trialDate->format('Y-m-d');
        }

        $amount = (float) $plan['price'];

        $priceStmt = $pdo->prepare("
            SELECT amount
            FROM plan_prices
            WHERE plan_id = :plan_id
              AND currency_id = :currency_id
              AND is_active = 1
            ORDER BY is_default DESC, id DESC
            LIMIT 1
        ");
        $priceStmt->execute(
            array(
                ':plan_id' => $planId,
                ':currency_id' => $currencyId
            )
        );

        $priceValue = $priceStmt->fetchColumn();

        if ($priceValue !== false) {
            $amount = (float) $priceValue;
        }

        if ($currentSubscription) {
            $subscriptionStmt = $pdo->prepare("
                UPDATE subscriptions
                SET
                    plan_id = :plan_id,
                    currency_id = :currency_id,
                    amount = :amount,
                    start_date = :start_date,
                    expiry_date = :expiry_date,
                    trial_end_date = :trial_end_date,
                    auto_renew = :auto_renew,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :subscription_id
                  AND tenant_id = :tenant_id
                  AND deleted_at IS NULL
                LIMIT 1
            ");

            $subscriptionStmt->execute(
                array(
                    ':plan_id' => $planId,
                    ':currency_id' => $currencyId,
                    ':amount' => $amount,
                    ':start_date' => $subscriptionStart,
                    ':expiry_date' => tenantUpdateNullable($subscriptionExpiry),
                    ':trial_end_date' => tenantUpdateNullable($trialEndDate),
                    ':auto_renew' => $autoRenew,
                    ':status' => $subscriptionStatus,
                    ':subscription_id' => (int) $currentSubscription['id'],
                    ':tenant_id' => $tenantId
                )
            );
        } else {
            $subscriptionStmt = $pdo->prepare("
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
                    ':tenant_id' => $tenantId,
                    ':plan_id' => $planId,
                    ':currency_id' => $currencyId,
                    ':amount' => $amount,
                    ':start_date' => $subscriptionStart,
                    ':expiry_date' => tenantUpdateNullable($subscriptionExpiry),
                    ':trial_end_date' => tenantUpdateNullable($trialEndDate),
                    ':auto_renew' => $autoRenew,
                    ':status' => $subscriptionStatus,
                    ':created_by' => $createdBy
                )
            );
        }

        $legacyPlanValue =
            !empty($plan['code'])
                ? (string) $plan['code']
                : (string) $plan['name'];

        $syncLegacyStmt = $pdo->prepare("
            UPDATE tenants
            SET subscription_plan = :subscription_plan
            WHERE id = :tenant_id
            LIMIT 1
        ");
        $syncLegacyStmt->execute(
            array(
                ':subscription_plan' => $legacyPlanValue,
                ':tenant_id' => $tenantId
            )
        );
    } else {
        if ($currentSubscription) {
            $removeStmt = $pdo->prepare("
                UPDATE subscriptions
                SET
                    status = 'cancelled',
                    deleted_at = NOW(),
                    updated_at = NOW()
                WHERE id = :subscription_id
                  AND tenant_id = :tenant_id
                LIMIT 1
            ");
            $removeStmt->execute(
                array(
                    ':subscription_id' => (int) $currentSubscription['id'],
                    ':tenant_id' => $tenantId
                )
            );
        }

        $syncLegacyStmt = $pdo->prepare("
            UPDATE tenants
            SET subscription_plan = NULL
            WHERE id = :tenant_id
            LIMIT 1
        ");
        $syncLegacyStmt->execute(array(':tenant_id' => $tenantId));
    }


    $effectivePlanId = $planId > 0 ? $planId : ($currentSubscription ? (int) $currentSubscription['plan_id'] : 0);
    if ($currentAdmin && $adminRoleId > 0 && $effectivePlanId > 0) {
        if ($enableMissingPermissions) {
            $grantStmt = $pdo->prepare("
                INSERT INTO role_permissions (tenant_id, role_id, permission_id, access_type, created_at)
                SELECT :tenant_id, :role_id, p.id, 'allow', NOW()
                FROM permissions p
                INNER JOIN modules m ON m.id=p.module_id AND m.is_active=1 AND m.is_sidebar_item=1
                INNER JOIN plan_modules pm ON pm.module_id=m.id AND pm.plan_id=:plan_id AND pm.is_enabled=1
                ON DUPLICATE KEY UPDATE access_type='allow'
            ");
            $grantStmt->execute(array(':tenant_id'=>$tenantId,':role_id'=>$adminRoleId,':plan_id'=>$effectivePlanId));

            $denyStmt = $pdo->prepare("
                DELETE up FROM user_permissions up
                INNER JOIN permissions p ON p.id=up.permission_id
                INNER JOIN modules m ON m.id=p.module_id AND m.is_active=1 AND m.is_sidebar_item=1
                INNER JOIN plan_modules pm ON pm.module_id=m.id AND pm.plan_id=:plan_id AND pm.is_enabled=1
                WHERE up.tenant_id=:tenant_id AND up.user_id=:user_id AND up.access_type='deny'
            ");
            $denyStmt->execute(array(':plan_id'=>$effectivePlanId,':tenant_id'=>$tenantId,':user_id'=>(int)$currentAdmin['id']));
        }

        $countStmt = $pdo->prepare("
            SELECT
              COUNT(DISTINCT p.id) required_count,
              COUNT(DISTINCT CASE WHEN rp.access_type='allow' THEN p.id END) granted_count
            FROM plan_modules pm
            INNER JOIN modules m ON m.id=pm.module_id AND m.is_active=1 AND m.is_sidebar_item=1
            INNER JOIN permissions p ON p.module_id=m.id
            LEFT JOIN role_permissions rp ON rp.tenant_id=:tenant_id AND rp.role_id=:role_id AND rp.permission_id=p.id
            WHERE pm.plan_id=:plan_id AND pm.is_enabled=1
        ");
        $countStmt->execute(array(':tenant_id'=>$tenantId,':role_id'=>$adminRoleId,':plan_id'=>$effectivePlanId));
        $permissionSummary = $countStmt->fetch();
        $requiredPermissionCount = $permissionSummary ? (int)$permissionSummary['required_count'] : 0;
        $permissionCount = $permissionSummary ? (int)$permissionSummary['granted_count'] : 0;
        $missingPermissionCount = max(0, $requiredPermissionCount - $permissionCount);
    }

    $pdo->commit();

    if (
        $newLogo !== null &&
        !empty($currentTenant['logo_path']) &&
        $currentTenant['logo_path'] !== $newLogo['relative_path']
    ) {
        tenantUpdateDeleteOldFile((string) $currentTenant['logo_path']);
    }

    if (
        $newInvoiceLogo !== null &&
        !empty($currentTenant['invoice_logo_path']) &&
        $currentTenant['invoice_logo_path'] !== $newInvoiceLogo['relative_path']
    ) {
        tenantUpdateDeleteOldFile((string) $currentTenant['invoice_logo_path']);
    }

    $_SESSION['tenant_edit_csrf'] = bin2hex(random_bytes(32));

    $mailResult = array('sent' => false, 'message' => 'Administrator email was not requested.');
    if ($currentAdmin && $adminMailRequested) {
        $freshTenantStmt = $pdo->prepare("SELECT * FROM tenants WHERE id=:id LIMIT 1");
        $freshTenantStmt->execute(array(':id'=>$tenantId));
        $freshTenant = $freshTenantStmt->fetch();
        $freshSubscription = tenantUpdateLatestSubscription($pdo, $tenantId);
        $mailPlan = $plan;
        if (!$mailPlan && $freshSubscription && !empty($freshSubscription['plan_id'])) {
            $mailPlanStmt = $pdo->prepare("SELECT * FROM plans WHERE id=:id LIMIT 1");
            $mailPlanStmt->execute(array(':id'=>(int)$freshSubscription['plan_id']));
            $mailPlan = $mailPlanStmt->fetch() ?: null;
        }
        $mailResult = tenantApiSendWelcomeEmail(
            $pdo,
            $adminEmail,
            $freshTenant ?: $currentTenant,
            $mailPlan,
            $freshSubscription ?: array(),
            array(
                'name' => $adminName,
                'email' => $adminEmail,
                'temporary_password' => $temporaryPassword,
                'permission_count' => $permissionCount
            )
        );
    }

    tenantUpdateResponse(
        200,
        true,
        'Tenant updated successfully.' . ($adminMailRequested ? ($mailResult['sent'] ? ' Administrator login email sent.' : ' Administrator email could not be sent: ' . $mailResult['message']) : ''),
        array(
            'tenant_id' => $tenantId,
            'permission_count' => $permissionCount,
            'missing_permission_count' => $missingPermissionCount,
            'admin_email_sent' => !empty($mailResult['sent']),
            'redirect' => 'tenant-edit.php?id=' . $tenantId
        )
    );

} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    foreach (array($newLogo, $newInvoiceLogo) as $uploaded) {
        if (
            is_array($uploaded) &&
            !empty($uploaded['absolute_path']) &&
            is_file($uploaded['absolute_path'])
        ) {
            @unlink($uploaded['absolute_path']);
        }
    }

    error_log('FieldPlx tenant update error: ' . $exception->getMessage());

    if (
        $exception instanceof PDOException &&
        $exception->getCode() === '23000'
    ) {
        tenantUpdateResponse(
            409,
            false,
            'Tenant could not be updated because a unique or related record already exists.'
        );
    }

    tenantUpdateResponse(
        500,
        false,
        'Unable to update tenant. Please try again.'
    );
}
