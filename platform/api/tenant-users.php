<?php
declare(strict_types=1);

header(
    'Content-Type: application/json; charset=utf-8'
);

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function tua_post(
    string $key,
    string $default = ''
): string {
    if (
        !isset($_POST[$key]) ||
        is_array($_POST[$key])
    ) {
        return $default;
    }

    return trim(
        (string)$_POST[$key]
    );
}

function tua_json(
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

function tua_nullable(
    string $value
) {
    return $value === ''
        ? null
        : $value;
}

function tua_find_tenant(
    PDO $pdo,
    int $tenantId
): array {
    $stmt = $pdo->prepare("
        SELECT
            id,
            tenant_code,
            display_name,
            status
        FROM tenants
        WHERE id = :tenant_id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute(array(
        ':tenant_id' => $tenantId
    ));

    $tenant = $stmt->fetch();

    if (!$tenant) {
        tua_json(
            404,
            false,
            'Tenant not found.'
        );
    }

    return $tenant;
}

function tua_find_user(
    PDO $pdo,
    int $tenantId,
    int $userId
): array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE id = :id
          AND tenant_id = :tenant_id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute(array(
        ':id' => $userId,
        ':tenant_id' => $tenantId
    ));

    $user = $stmt->fetch();

    if (!$user) {
        tua_json(
            404,
            false,
            'Tenant user not found.'
        );
    }

    return $user;
}

function tua_validate_relation(
    PDO $pdo,
    string $table,
    int $id,
    int $tenantId
): void {
    if ($id <= 0) {
        return;
    }

    $allowed = array(
        'branches',
        'departments',
        'roles'
    );

    if (
        !in_array(
            $table,
            $allowed,
            true
        )
    ) {
        tua_json(
            500,
            false,
            'Invalid tenant relation.'
        );
    }

    $sql = "
        SELECT id
        FROM " . $table . "
        WHERE id = :id
          AND tenant_id = :tenant_id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute(array(
        ':id' => $id,
        ':tenant_id' => $tenantId
    ));

    if (!$stmt->fetchColumn()) {
        tua_json(
            422,
            false,
            'Selected ' .
            rtrim($table, 's') .
            ' does not belong to this tenant.'
        );
    }
}

function tua_user_limit(
    PDO $pdo,
    int $tenantId
): ?int {
    $stmt = $pdo->prepare("
        SELECT
            s.max_users_override,
            p.max_users AS plan_max_users
        FROM subscriptions s
        INNER JOIN plans p
            ON p.id = s.plan_id
        WHERE s.tenant_id = :tenant_id
          AND s.deleted_at IS NULL
        ORDER BY
            CASE
                WHEN s.status = 'active' THEN 1
                WHEN s.status = 'trial' THEN 2
                ELSE 3
            END,
            s.id DESC
        LIMIT 1
    ");

    $stmt->execute(array(
        ':tenant_id' => $tenantId
    ));

    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if (
        $row['max_users_override'] !== null &&
        $row['max_users_override'] !== ''
    ) {
        return (int)$row['max_users_override'];
    }

    if (
        $row['plan_max_users'] !== null &&
        $row['plan_max_users'] !== ''
    ) {
        return (int)$row['plan_max_users'];
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| SMTP / Tenant User Welcome Email Helpers
|--------------------------------------------------------------------------
*/

function tua_smtp_secret_key(): string
{
    $key = '';

    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        $key = (string)FIELDPLX_SMTP_ENCRYPTION_KEY;
    }

    if ($key === '') {
        $env = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');

        if ($env !== false) {
            $key = trim((string)$env);
        }
    }

    if ($key === '') {
        $env = getenv('APP_KEY');

        if ($env !== false) {
            $key = trim((string)$env);
        }
    }

    if ($key === '') {
        $key = hash(
            'sha256',
            dirname(__DIR__) .
            '|fieldplx|smtp|credential-protection'
        );
    }

    return hash('sha256', $key, true);
}

function tua_decrypt_smtp_password(
    ?string $stored
): string {
    $stored = (string)$stored;

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
        tua_smtp_secret_key(),
        OPENSSL_RAW_DATA,
        $iv
    );

    return $plain === false
        ? ''
        : $plain;
}

function tua_composer_autoload_path(): string
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

function tua_load_phpmailer(): void
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
        tua_composer_autoload_path();

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

function tua_platform_smtp(
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

    $row = $stmt->fetch();

    return $row
        ? $row
        : null;
}

function tua_email_escape(
    $value
): string {
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function tua_role_name(
    PDO $pdo,
    int $tenantId,
    int $roleId
): string {
    if ($roleId <= 0) {
        return 'Not assigned';
    }

    $stmt = $pdo->prepare("
        SELECT name
        FROM roles
        WHERE id = :id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");

    $stmt->execute(array(
        ':id' => $roleId,
        ':tenant_id' => $tenantId
    ));

    $name = $stmt->fetchColumn();

    return $name
        ? (string)$name
        : 'Not assigned';
}

function tua_branch_name(
    PDO $pdo,
    int $tenantId,
    int $branchId
): string {
    if ($branchId <= 0) {
        return 'All / Unassigned';
    }

    $stmt = $pdo->prepare("
        SELECT name
        FROM branches
        WHERE id = :id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");

    $stmt->execute(array(
        ':id' => $branchId,
        ':tenant_id' => $tenantId
    ));

    $name = $stmt->fetchColumn();

    return $name
        ? (string)$name
        : 'All / Unassigned';
}

function tua_build_user_welcome_email(
    array $tenant,
    array $user,
    string $roleName,
    string $branchName,
    string $temporaryPassword
): array {
    $platformName = 'FieldPlx';

    $tenantName =
        trim(
            (string)$tenant['display_name']
        );

    if ($tenantName === '') {
        $tenantName = 'Your organization';
    }

    $fullName =
        trim(
            (string)$user['first_name'] .
            ' ' .
            (string)$user['last_name']
        );

    if ($fullName === '') {
        $fullName = 'User';
    }

    $email =
        (string)$user['email'];

    $status =
        ucwords(
            str_replace(
                '_',
                ' ',
                (string)$user['status']
            )
        );

    $safeFullName =
        tua_email_escape($fullName);

    $safeTenantName =
        tua_email_escape($tenantName);

    $safeTenantCode =
        tua_email_escape(
            isset($tenant['tenant_code'])
                ? $tenant['tenant_code']
                : ''
        );

    $safeEmail =
        tua_email_escape($email);

    $safeRole =
        tua_email_escape($roleName);

    $safeBranch =
        tua_email_escape($branchName);

    $safeStatus =
        tua_email_escape($status);

    $safeEmployeeCode =
        tua_email_escape(
            isset($user['employee_code'])
                ? $user['employee_code']
                : ''
        );

    $safePassword =
        tua_email_escape(
            $temporaryPassword
        );

    $loginUrl = '';

    if (
        isset($_SERVER['HTTP_HOST']) &&
        $_SERVER['HTTP_HOST'] !== ''
    ) {
        $scheme =
            (
                isset($_SERVER['HTTPS']) &&
                $_SERVER['HTTPS'] !== '' &&
                strtolower(
                    (string)$_SERVER['HTTPS']
                ) !== 'off'
            )
                ? 'https'
                : 'http';

        $loginUrl =
            $scheme .
            '://' .
            $_SERVER['HTTP_HOST'] .
            '/';
    }

    $safeLoginUrl =
        tua_email_escape($loginUrl);

    $subject =
        'Welcome to FieldPlx - Your account is ready';

    $loginButton = '';

    if ($loginUrl !== '') {
        $loginButton = '
            <div style="
                margin:26px 0 4px;
                text-align:center;
            ">
                <a
                    href="' . $safeLoginUrl . '"
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

    $employeeCodeRow = '';

    if ($safeEmployeeCode !== '') {
        $employeeCodeRow = '
            <tr>
                <td style="
                    padding:10px 0;
                    color:#7c738d;
                    font-size:13px;
                ">
                    Employee Code
                </td>
                <td style="
                    padding:10px 0;
                    color:#211c32;
                    text-align:right;
                    font-size:13px;
                    font-weight:700;
                ">
                    ' . $safeEmployeeCode . '
                </td>
            </tr>
        ';
    }

    $passwordBlock = '';

    if ($temporaryPassword !== '') {
        $passwordBlock = '
            <div style="
                margin-top:18px;
                padding:15px;
                border:1px solid #eadffd;
                border-radius:11px;
                background:#faf7ff;
            ">
                <div style="
                    color:#6d28d9;
                    font-size:12px;
                    font-weight:800;
                    margin-bottom:8px;
                ">
                    Temporary Login Details
                </div>

                <div style="
                    color:#5f5870;
                    font-size:13px;
                    line-height:1.7;
                ">
                    Email:
                    <strong style="color:#211c32">
                        ' . $safeEmail . '
                    </strong>
                    <br>
                    Temporary Password:
                    <strong style="color:#211c32">
                        ' . $safePassword . '
                    </strong>
                </div>

                <div style="
                    margin-top:9px;
                    color:#9a94aa;
                    font-size:11px;
                    line-height:1.6;
                ">
                    For security, please change this password after your first login.
                </div>
            </div>
        ';
    }

    $html = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . tua_email_escape($subject) . '</title>
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
    background:#1c2250;
">
    <div style="
        display:inline-block;
        padding:8px 10px;
        border-radius:9px;
        background:#8b5cf6;
        color:#ffffff;
        font-size:14px;
        font-weight:800;
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
        color:#d9d1f7;
        font-size:13px;
        line-height:1.6;
    ">
        Your user account has been created successfully.
    </div>
</td>
</tr>

<tr>
<td style="
    padding:28px 26px 8px;
">

    <div style="
        color:#211c32;
        font-size:18px;
        font-weight:800;
    ">
        Hello ' . $safeFullName . ',
    </div>

    <div style="
        margin-top:12px;
        color:#6f677d;
        font-size:14px;
        line-height:1.75;
    ">
        Your FieldPlx account for
        <strong style="color:#332d43">
            ' . $safeTenantName . '
        </strong>
        has been created. You can now access the workspace based on
        the role and permissions assigned to your account.
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
            Account Details
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
                    Organization
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

            ' . $employeeCodeRow . '

            <tr>
                <td style="
                    padding:10px 0;
                    color:#7c738d;
                    font-size:13px;
                ">
                    Role
                </td>
                <td style="
                    padding:10px 0;
                    color:#211c32;
                    text-align:right;
                    font-size:13px;
                    font-weight:700;
                ">
                    ' . $safeRole . '
                </td>
            </tr>

            <tr>
                <td style="
                    padding:10px 0;
                    color:#7c738d;
                    font-size:13px;
                ">
                    Branch
                </td>
                <td style="
                    padding:10px 0;
                    color:#211c32;
                    text-align:right;
                    font-size:13px;
                    font-weight:700;
                ">
                    ' . $safeBranch . '
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
        </table>
    </div>

    ' . $passwordBlock . '

    ' . $loginButton . '

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
            Getting started
        </strong>
        <br>
        1. Sign in to your FieldPlx account.<br>
        2. Review your profile and assigned role.<br>
        3. Change your temporary password.<br>
        4. Start using the modules available to your role.
    </div>

    <div style="
        margin-top:24px;
        color:#7a7288;
        font-size:12px;
        line-height:1.7;
    ">
        If you were not expecting this account,
        please contact your FieldPlx administrator.
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
    This is an automated account notification from FieldPlx.
</td>
</tr>

</table>

</td>
</tr>
</table>

</body>
</html>
';

    $plain =
        "Welcome to FieldPlx\n\n" .
        "Hello " . $fullName . ",\n\n" .
        "Your FieldPlx user account for " .
        $tenantName .
        " has been created successfully.\n\n" .
        "Tenant Code: " .
        (
            isset($tenant['tenant_code'])
                ? $tenant['tenant_code']
                : ''
        ) .
        "\nRole: " .
        $roleName .
        "\nBranch: " .
        $branchName .
        "\nStatus: " .
        $status .
        "\nEmail: " .
        $email .
        "\n";

    if ($temporaryPassword !== '') {
        $plain .=
            "Temporary Password: " .
            $temporaryPassword .
            "\n\nPlease change your password after your first login.\n";
    }

    if ($loginUrl !== '') {
        $plain .=
            "\nOpen FieldPlx: " .
            $loginUrl .
            "\n";
    }

    return array(
        'subject' => $subject,
        'html' => $html,
        'text' => $plain
    );
}

function tua_send_user_welcome_email(
    PDO $pdo,
    array $tenant,
    array $user,
    string $temporaryPassword,
    int $roleId,
    int $branchId
): array {
    $recipient =
        isset($user['email'])
            ? trim((string)$user['email'])
            : '';

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
                'User email address is empty or invalid.'
        );
    }

    $smtp =
        tua_platform_smtp($pdo);

    if (!$smtp) {
        return array(
            'sent' => false,
            'message' =>
                'No active Platform SMTP configuration is available.'
        );
    }

    try {
        tua_load_phpmailer();

        $password =
            tua_decrypt_smtp_password(
                isset($smtp['password_encrypted'])
                    ? (string)$smtp['password_encrypted']
                    : ''
            );

        $mail =
            new \PHPMailer\PHPMailer\PHPMailer(
                true
            );

        $mail->isSMTP();

        $mail->Host =
            trim(
                (string)$smtp['host']
            );

        $mail->Port =
            (int)$smtp['port'];

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
                (string)$smtp['username']
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
                    (string)$smtp['encryption']
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
                (string)$smtp['from_email']
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
                (string)$smtp['from_name']
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
                (string)$smtp['reply_to_email']
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

        $fullName =
            trim(
                (string)$user['first_name'] .
                ' ' .
                (string)$user['last_name']
            );

        $mail->addAddress(
            $recipient,
            $fullName
        );

        $roleName =
            tua_role_name(
                $pdo,
                (int)$tenant['id'],
                $roleId
            );

        $branchName =
            tua_branch_name(
                $pdo,
                (int)$tenant['id'],
                $branchId
            );

        $template =
            tua_build_user_welcome_email(
                $tenant,
                $user,
                $roleName,
                $branchName,
                $temporaryPassword
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

    } catch (Throwable $e) {
        error_log(
            'FieldPlx tenant user welcome email error: ' .
            $e->getMessage()
        );

        return array(
            'sent' => false,
            'message' =>
                'User created, but welcome email could not be sent: ' .
                $e->getMessage()
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tua_json(
        405,
        false,
        'Method not allowed.'
    );
}

$csrf = tua_post('csrf_token');

if (
    empty($_SESSION['tenant_users_csrf']) ||
    !is_string(
        $_SESSION['tenant_users_csrf']
    ) ||
    $csrf === '' ||
    !hash_equals(
        $_SESSION['tenant_users_csrf'],
        $csrf
    )
) {
    tua_json(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$action = tua_post('action');
$tenantId =
    (int)tua_post(
        'tenant_id',
        '0'
    );

if ($tenantId <= 0) {
    tua_json(
        422,
        false,
        'Invalid tenant.'
    );
}

$tenant =
    tua_find_tenant(
        $pdo,
        $tenantId
    );

try {

    /*
    |--------------------------------------------------------------------------
    | SAVE USER
    |--------------------------------------------------------------------------
    */

    if ($action === 'save_user') {
        $id =
            (int)tua_post(
                'id',
                '0'
            );

        $firstName =
            tua_post('first_name');

        $lastName =
            tua_post('last_name');

        $email =
            strtolower(
                tua_post('email')
            );

        $employeeCode =
            tua_post('employee_code');

        $phone =
            tua_post('phone');

        $alternatePhone =
            tua_post('alternate_phone');

        $jobTitle =
            tua_post('job_title');

        $laborRateRaw =
            tua_post('labor_rate');

        $branchId =
            (int)tua_post(
                'branch_id',
                '0'
            );

        $departmentId =
            (int)tua_post(
                'department_id',
                '0'
            );

        $roleId =
            (int)tua_post(
                'role_id',
                '0'
            );

        $status =
            tua_post(
                'status',
                'active'
            );

        $password =
            tua_post('password');

        $isTenantAdmin =
            isset(
                $_POST['is_tenant_admin']
            ) &&
            $_POST['is_tenant_admin'] === '1'
                ? 1
                : 0;

        $isFieldWorker =
            isset(
                $_POST['is_field_worker']
            ) &&
            $_POST['is_field_worker'] === '1'
                ? 1
                : 0;

        $isBookable =
            isset(
                $_POST['is_bookable']
            ) &&
            $_POST['is_bookable'] === '1'
                ? 1
                : 0;

        if (
            $firstName === '' ||
            strlen($firstName) > 120
        ) {
            tua_json(
                422,
                false,
                'First name is required.'
            );
        }

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            tua_json(
                422,
                false,
                'Enter a valid email address.'
            );
        }

        $validStatuses = array(
            'active',
            'inactive',
            'invited',
            'suspended'
        );

        if (
            !in_array(
                $status,
                $validStatuses,
                true
            )
        ) {
            tua_json(
                422,
                false,
                'Invalid user status.'
            );
        }

        if (
            $laborRateRaw !== '' &&
            (
                !is_numeric($laborRateRaw) ||
                (float)$laborRateRaw < 0
            )
        ) {
            tua_json(
                422,
                false,
                'Labor rate must be a valid positive amount.'
            );
        }

        tua_validate_relation(
            $pdo,
            'branches',
            $branchId,
            $tenantId
        );

        tua_validate_relation(
            $pdo,
            'departments',
            $departmentId,
            $tenantId
        );

        tua_validate_relation(
            $pdo,
            'roles',
            $roleId,
            $tenantId
        );

        if (
            $departmentId > 0 &&
            $branchId > 0
        ) {
            $departmentCheck =
                $pdo->prepare("
                    SELECT id
                    FROM departments
                    WHERE id = :department_id
                      AND tenant_id = :tenant_id
                      AND (
                            branch_id IS NULL
                            OR branch_id = :branch_id
                      )
                    LIMIT 1
                ");

            $departmentCheck->execute(
                array(
                    ':department_id' =>
                        $departmentId,
                    ':tenant_id' =>
                        $tenantId,
                    ':branch_id' =>
                        $branchId
                )
            );

            if (
                !$departmentCheck->fetchColumn()
            ) {
                tua_json(
                    422,
                    false,
                    'Selected department does not belong to the selected branch.'
                );
            }
        }

        $duplicateEmail =
            $pdo->prepare("
                SELECT id
                FROM users
                WHERE tenant_id = :tenant_id
                  AND email = :email
                  AND deleted_at IS NULL
                  AND id <> :id
                LIMIT 1
            ");

        $duplicateEmail->execute(
            array(
                ':tenant_id' =>
                    $tenantId,
                ':email' =>
                    $email,
                ':id' =>
                    $id
            )
        );

        if (
            $duplicateEmail->fetchColumn()
        ) {
            tua_json(
                409,
                false,
                'This email address already exists for the tenant.'
            );
        }

        if ($employeeCode !== '') {
            $duplicateEmployee =
                $pdo->prepare("
                    SELECT id
                    FROM users
                    WHERE tenant_id = :tenant_id
                      AND employee_code = :employee_code
                      AND deleted_at IS NULL
                      AND id <> :id
                    LIMIT 1
                ");

            $duplicateEmployee->execute(
                array(
                    ':tenant_id' =>
                        $tenantId,
                    ':employee_code' =>
                        $employeeCode,
                    ':id' =>
                        $id
                )
            );

            if (
                $duplicateEmployee->fetchColumn()
            ) {
                tua_json(
                    409,
                    false,
                    'Employee code already exists for the tenant.'
                );
            }
        }

        /*
        | New user: enforce subscription max_users.
        */
        if ($id <= 0) {
            $maxUsers =
                tua_user_limit(
                    $pdo,
                    $tenantId
                );

            if ($maxUsers !== null) {
                $countStmt =
                    $pdo->prepare("
                        SELECT COUNT(*)
                        FROM users
                        WHERE tenant_id = :tenant_id
                          AND deleted_at IS NULL
                    ");

                $countStmt->execute(
                    array(
                        ':tenant_id' =>
                            $tenantId
                    )
                );

                $currentCount =
                    (int)$countStmt->fetchColumn();

                if (
                    $currentCount >=
                    $maxUsers
                ) {
                    tua_json(
                        409,
                        false,
                        'This tenant has reached the maximum user limit for its subscription plan.'
                    );
                }
            }

            if (
                strlen($password) < 8
            ) {
                tua_json(
                    422,
                    false,
                    'Temporary password must contain at least 8 characters.'
                );
            }

            $passwordHash =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

            if ($passwordHash === false) {
                tua_json(
                    500,
                    false,
                    'Unable to secure the user password.'
                );
            }

            $stmt = $pdo->prepare("
                INSERT INTO users (
                    tenant_id,
                    branch_id,
                    department_id,
                    role_id,
                    employee_code,
                    first_name,
                    last_name,
                    email,
                    phone,
                    alternate_phone,
                    password_hash,
                    avatar_path,
                    job_title,
                    labor_rate,
                    is_bookable,
                    is_field_worker,
                    is_tenant_admin,
                    status
                ) VALUES (
                    :tenant_id,
                    :branch_id,
                    :department_id,
                    :role_id,
                    :employee_code,
                    :first_name,
                    :last_name,
                    :email,
                    :phone,
                    :alternate_phone,
                    :password_hash,
                    NULL,
                    :job_title,
                    :labor_rate,
                    :is_bookable,
                    :is_field_worker,
                    :is_tenant_admin,
                    :status
                )
            ");

            $stmt->execute(
                array(
                    ':tenant_id' =>
                        $tenantId,
                    ':branch_id' =>
                        $branchId > 0
                            ? $branchId
                            : null,
                    ':department_id' =>
                        $departmentId > 0
                            ? $departmentId
                            : null,
                    ':role_id' =>
                        $roleId > 0
                            ? $roleId
                            : null,
                    ':employee_code' =>
                        tua_nullable(
                            $employeeCode
                        ),
                    ':first_name' =>
                        $firstName,
                    ':last_name' =>
                        tua_nullable(
                            $lastName
                        ),
                    ':email' =>
                        $email,
                    ':phone' =>
                        tua_nullable(
                            $phone
                        ),
                    ':alternate_phone' =>
                        tua_nullable(
                            $alternatePhone
                        ),
                    ':password_hash' =>
                        $passwordHash,
                    ':job_title' =>
                        tua_nullable(
                            $jobTitle
                        ),
                    ':labor_rate' =>
                        $laborRateRaw === ''
                            ? null
                            : number_format(
                                (float)$laborRateRaw,
                                2,
                                '.',
                                ''
                            ),
                    ':is_bookable' =>
                        $isBookable,
                    ':is_field_worker' =>
                        $isFieldWorker,
                    ':is_tenant_admin' =>
                        $isTenantAdmin,
                    ':status' =>
                        $status
                )
            );

            $newUserId =
                (int)$pdo->lastInsertId();

            /*
            |--------------------------------------------------------------------------
            | Send professional welcome email
            |--------------------------------------------------------------------------
            |
            | User creation is already successful at this point.
            | Email failure must never delete or roll back the user.
            |
            */

            $emailResult =
                tua_send_user_welcome_email(
                    $pdo,
                    $tenant,
                    array(
                        'id' =>
                            $newUserId,
                        'first_name' =>
                            $firstName,
                        'last_name' =>
                            $lastName,
                        'email' =>
                            $email,
                        'employee_code' =>
                            $employeeCode,
                        'status' =>
                            $status
                    ),
                    $password,
                    $roleId,
                    $branchId
                );

            $successMessage =
                'Tenant user created successfully.';

            if ($emailResult['sent']) {
                $successMessage .=
                    ' Welcome email sent to ' .
                    $email .
                    '.';
            } else {
                $successMessage .=
                    ' ' .
                    $emailResult['message'];
            }

            tua_json(
                200,
                true,
                $successMessage,
                array(
                    'user_id' =>
                        $newUserId,
                    'email_sent' =>
                        (bool)$emailResult['sent'],
                    'email_message' =>
                        $emailResult['message']
                )
            );
        }

        /*
        | Existing user.
        */
        tua_find_user(
            $pdo,
            $tenantId,
            $id
        );

        $passwordSql = '';
        $updateParams =
            array(
                ':branch_id' =>
                    $branchId > 0
                        ? $branchId
                        : null,
                ':department_id' =>
                    $departmentId > 0
                        ? $departmentId
                        : null,
                ':role_id' =>
                    $roleId > 0
                        ? $roleId
                        : null,
                ':employee_code' =>
                    tua_nullable(
                        $employeeCode
                    ),
                ':first_name' =>
                    $firstName,
                ':last_name' =>
                    tua_nullable(
                        $lastName
                    ),
                ':email' =>
                    $email,
                ':phone' =>
                    tua_nullable(
                        $phone
                    ),
                ':alternate_phone' =>
                    tua_nullable(
                        $alternatePhone
                    ),
                ':job_title' =>
                    tua_nullable(
                        $jobTitle
                    ),
                ':labor_rate' =>
                    $laborRateRaw === ''
                        ? null
                        : number_format(
                            (float)$laborRateRaw,
                            2,
                            '.',
                            ''
                        ),
                ':is_bookable' =>
                    $isBookable,
                ':is_field_worker' =>
                    $isFieldWorker,
                ':is_tenant_admin' =>
                    $isTenantAdmin,
                ':status' =>
                    $status,
                ':id' =>
                    $id,
                ':tenant_id' =>
                    $tenantId
            );

        if ($password !== '') {
            if (
                strlen($password) < 8
            ) {
                tua_json(
                    422,
                    false,
                    'New password must contain at least 8 characters.'
                );
            }

            $passwordHash =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

            if ($passwordHash === false) {
                tua_json(
                    500,
                    false,
                    'Unable to secure the user password.'
                );
            }

            $passwordSql =
                ",
                password_hash = :password_hash
                ";

            $updateParams[
                ':password_hash'
            ] = $passwordHash;
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET
                branch_id = :branch_id,
                department_id = :department_id,
                role_id = :role_id,
                employee_code = :employee_code,
                first_name = :first_name,
                last_name = :last_name,
                email = :email,
                phone = :phone,
                alternate_phone = :alternate_phone,
                job_title = :job_title,
                labor_rate = :labor_rate,
                is_bookable = :is_bookable,
                is_field_worker = :is_field_worker,
                is_tenant_admin = :is_tenant_admin,
                status = :status
                " . $passwordSql . "
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND deleted_at IS NULL
        ");

        $stmt->execute(
            $updateParams
        );

        tua_json(
            200,
            true,
            'Tenant user updated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CHANGE STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === 'change_status') {
        $id =
            (int)tua_post(
                'id',
                '0'
            );

        $status =
            tua_post('status');

        if ($id <= 0) {
            tua_json(
                422,
                false,
                'Invalid tenant user.'
            );
        }

        if (
            !in_array(
                $status,
                array(
                    'active',
                    'inactive',
                    'invited',
                    'suspended'
                ),
                true
            )
        ) {
            tua_json(
                422,
                false,
                'Invalid user status.'
            );
        }

        tua_find_user(
            $pdo,
            $tenantId,
            $id
        );

        $stmt = $pdo->prepare("
            UPDATE users
            SET status = :status
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND deleted_at IS NULL
        ");

        $stmt->execute(
            array(
                ':status' =>
                    $status,
                ':id' =>
                    $id,
                ':tenant_id' =>
                    $tenantId
            )
        );

        tua_json(
            200,
            true,
            $status === 'active'
                ? 'Tenant user activated successfully.'
                : 'Tenant user status updated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SOFT DELETE
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete_user') {
        $id =
            (int)tua_post(
                'id',
                '0'
            );

        if ($id <= 0) {
            tua_json(
                422,
                false,
                'Invalid tenant user.'
            );
        }

        $user =
            tua_find_user(
                $pdo,
                $tenantId,
                $id
            );

        /*
         * Prevent removing the last active tenant administrator.
         */
        if (
            (int)$user['is_tenant_admin'] === 1 &&
            $user['status'] === 'active'
        ) {
            $adminCountStmt =
                $pdo->prepare("
                    SELECT COUNT(*)
                    FROM users
                    WHERE tenant_id = :tenant_id
                      AND is_tenant_admin = 1
                      AND status = 'active'
                      AND deleted_at IS NULL
                ");

            $adminCountStmt->execute(
                array(
                    ':tenant_id' =>
                        $tenantId
                )
            );

            if (
                (int)$adminCountStmt
                    ->fetchColumn() <= 1
            ) {
                tua_json(
                    409,
                    false,
                    'You cannot remove the last active tenant administrator. Assign another tenant administrator first.'
                );
            }
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET
                status = 'inactive',
                deleted_at = NOW()
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND deleted_at IS NULL
        ");

        $stmt->execute(
            array(
                ':id' =>
                    $id,
                ':tenant_id' =>
                    $tenantId
            )
        );

        tua_json(
            200,
            true,
            'Tenant user removed successfully.'
        );
    }

    tua_json(
        400,
        false,
        'Invalid action.'
    );

} catch (Throwable $e) {

    error_log(
        'FieldPlx Tenant Users API Error: ' .
        $e->getMessage()
    );

    tua_json(
        500,
        false,
        'Unable to complete the requested tenant user action.'
    );
}
