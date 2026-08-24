<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function es_post(string $key, string $default = ''): string
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }

    return trim((string)$_POST[$key]);
}

function es_json(
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
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function es_secret_key(): string
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

    /*
     * Compatibility fallback:
     * This avoids plaintext database storage when an application secret
     * has not yet been configured. For production, define
     * FIELDPLX_SMTP_ENCRYPTION_KEY in the server environment.
     */
    if ($key === '') {
        $key = hash(
            'sha256',
            __DIR__ . '|fieldplx|smtp|credential-protection'
        );
    }

    return hash('sha256', $key, true);
}

function es_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException(
            'OpenSSL extension is required for SMTP password encryption.'
        );
    }

    $iv = random_bytes(16);

    $encrypted = openssl_encrypt(
        $plain,
        'AES-256-CBC',
        es_secret_key(),
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($encrypted === false) {
        throw new RuntimeException(
            'Unable to encrypt SMTP password.'
        );
    }

    return 'v1:' . base64_encode($iv . $encrypted);
}

function es_decrypt(?string $stored): string
{
    $stored = (string)$stored;

    if ($stored === '') {
        return '';
    }

    if (strpos($stored, 'v1:') !== 0) {
        /*
         * Legacy compatibility only.
         * Existing projects may have previously stored another format.
         * Returning an empty password is safer than treating unknown data
         * as plaintext credentials.
         */
        return '';
    }

    $raw = base64_decode(substr($stored, 3), true);

    if ($raw === false || strlen($raw) <= 16) {
        return '';
    }

    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);

    $plain = openssl_decrypt(
        $cipher,
        'AES-256-CBC',
        es_secret_key(),
        OPENSSL_RAW_DATA,
        $iv
    );

    return $plain === false ? '' : $plain;
}

function es_load_config(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM smtp_configurations
        WHERE id = :id
          AND scope_type = 'platform'
        LIMIT 1
    ");

    $stmt->execute(array(':id' => $id));

    $row = $stmt->fetch();

    if (!$row) {
        es_json(
            404,
            false,
            'SMTP configuration not found.'
        );
    }

    return $row;
}

function es_load_phpmailer(): bool
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return true;
    }

    $paths = array(
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../mailer/vendor/autoload.php',
        __DIR__ . '/../mailer/vendor/autoload.php'
    );

    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;

            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                return true;
            }
        }
    }

    return false;
}

function es_send_test(array $config, string $recipient): void
{
    if (!es_load_phpmailer()) {
        throw new RuntimeException(
            'PHPMailer is not available. Install PHPMailer with Composer to send a test email.'
        );
    }

    $password = es_decrypt(
        isset($config['password_encrypted'])
            ? (string)$config['password_encrypted']
            : ''
    );

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = (string)$config['host'];
    $mail->Port = (int)$config['port'];
    $mail->Timeout = 20;
    $mail->SMTPAuth = trim((string)$config['username']) !== '';

    if ($mail->SMTPAuth) {
        $mail->Username = (string)$config['username'];
        $mail->Password = $password;
    }

    $encryption = strtolower((string)$config['encryption']);

    if ($encryption === 'ssl') {
        $mail->SMTPSecure =
            \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif (
        $encryption === 'tls' ||
        $encryption === 'starttls'
    ) {
        $mail->SMTPSecure =
            \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    }

    $fromEmail = trim((string)$config['from_email']);

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(
            'The configured From Email is invalid.'
        );
    }

    $fromName = trim((string)$config['from_name']);
    if ($fromName === '') {
        $fromName = 'FieldPlx';
    }

    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);

    $replyTo = trim((string)$config['reply_to_email']);
    if (
        $replyTo !== '' &&
        filter_var($replyTo, FILTER_VALIDATE_EMAIL)
    ) {
        $mail->addReplyTo($replyTo);
    }

    $mail->addAddress($recipient);
    $mail->isHTML(true);
    $mail->Subject = 'FieldPlx SMTP Test';
    $mail->Body = '
        <div style="font-family:Arial,sans-serif">
            <h2>FieldPlx SMTP Test</h2>
            <p>Your SMTP configuration is working correctly.</p>
            <p>This email was generated from the FieldPlx Platform SMTP page.</p>
        </div>
    ';
    $mail->AltBody =
        'FieldPlx SMTP Test - Your SMTP configuration is working correctly.';

    $mail->send();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    es_json(
        405,
        false,
        'Method not allowed.'
    );
}

$csrf = es_post('csrf_token');

if (
    empty($_SESSION['email_smtp_csrf']) ||
    !is_string($_SESSION['email_smtp_csrf']) ||
    $csrf === '' ||
    !hash_equals(
        $_SESSION['email_smtp_csrf'],
        $csrf
    )
) {
    es_json(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$action = es_post('action');

try {

    if ($action === 'save_smtp') {
        $id = (int)es_post('id', '0');

        $configName = es_post('config_name');
        $host = es_post('host');
        $port = (int)es_post('port', '587');
        $encryption = strtolower(
            es_post('encryption', 'tls')
        );
        $username = es_post('username');
        $password = es_post('password');
        $fromName = es_post('from_name');
        $fromEmail = es_post('from_email');
        $replyTo = es_post('reply_to_email');
        $isDefault =
            isset($_POST['is_default']) &&
            $_POST['is_default'] === '1'
                ? 1
                : 0;
        $isActive =
            es_post('is_active', '1') === '1'
                ? 1
                : 0;

        if ($configName === '') {
            es_json(
                422,
                false,
                'Configuration name is required.'
            );
        }

        if ($host === '') {
            es_json(
                422,
                false,
                'SMTP host is required.'
            );
        }

        if ($port < 1 || $port > 65535) {
            es_json(
                422,
                false,
                'SMTP port must be between 1 and 65535.'
            );
        }

        if (
            !in_array(
                $encryption,
                array(
                    'none',
                    'ssl',
                    'tls',
                    'starttls'
                ),
                true
            )
        ) {
            es_json(
                422,
                false,
                'Invalid SMTP encryption type.'
            );
        }

        if (
            !filter_var(
                $fromEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            es_json(
                422,
                false,
                'Enter a valid From Email address.'
            );
        }

        if (
            $replyTo !== '' &&
            !filter_var(
                $replyTo,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            es_json(
                422,
                false,
                'Enter a valid Reply-To Email address.'
            );
        }

        $dupStmt = $pdo->prepare("
            SELECT id
            FROM smtp_configurations
            WHERE scope_type = 'platform'
              AND config_name = :name
              AND id <> :id
            LIMIT 1
        ");

        $dupStmt->execute(
            array(
                ':name' => $configName,
                ':id' => $id
            )
        );

        if ($dupStmt->fetchColumn()) {
            es_json(
                422,
                false,
                'An SMTP configuration with this name already exists.'
            );
        }

        $pdo->beginTransaction();

        if ($isDefault === 1) {
            $pdo->exec("
                UPDATE smtp_configurations
                SET is_default = 0
                WHERE scope_type = 'platform'
            ");
        }

        $createdBy = null;

        if (
            isset($_SESSION['platform_user_id']) &&
            (int)$_SESSION['platform_user_id'] > 0
        ) {
            $createdBy =
                (int)$_SESSION['platform_user_id'];
        }

        if ($id > 0) {
            $current = es_load_config($pdo, $id);

            $passwordEncrypted =
                (string)$current['password_encrypted'];

            if ($password !== '') {
                $passwordEncrypted =
                    es_encrypt($password);
            }

            $stmt = $pdo->prepare("
                UPDATE smtp_configurations
                SET
                    config_name = :config_name,
                    host = :host,
                    port = :port,
                    encryption = :encryption,
                    username = :username,
                    password_encrypted = :password_encrypted,
                    from_name = :from_name,
                    from_email = :from_email,
                    reply_to_email = :reply_to_email,
                    is_default = :is_default,
                    is_active = :is_active
                WHERE id = :id
                  AND scope_type = 'platform'
            ");

            $stmt->execute(
                array(
                    ':config_name' => $configName,
                    ':host' => $host,
                    ':port' => $port,
                    ':encryption' => $encryption,
                    ':username' =>
                        $username === ''
                            ? null
                            : $username,
                    ':password_encrypted' =>
                        $passwordEncrypted === ''
                            ? null
                            : $passwordEncrypted,
                    ':from_name' =>
                        $fromName === ''
                            ? null
                            : $fromName,
                    ':from_email' => $fromEmail,
                    ':reply_to_email' =>
                        $replyTo === ''
                            ? null
                            : $replyTo,
                    ':is_default' => $isDefault,
                    ':is_active' => $isActive,
                    ':id' => $id
                )
            );

            $pdo->commit();

            es_json(
                200,
                true,
                'SMTP configuration updated successfully.'
            );
        }

        $passwordEncrypted = '';

        if ($password !== '') {
            $passwordEncrypted =
                es_encrypt($password);
        }

        $stmt = $pdo->prepare("
            INSERT INTO smtp_configurations (
                scope_type,
                tenant_id,
                branch_id,
                config_name,
                host,
                port,
                encryption,
                username,
                password_encrypted,
                from_name,
                from_email,
                reply_to_email,
                is_default,
                is_active,
                created_by_platform_user_id
            ) VALUES (
                'platform',
                NULL,
                NULL,
                :config_name,
                :host,
                :port,
                :encryption,
                :username,
                :password_encrypted,
                :from_name,
                :from_email,
                :reply_to_email,
                :is_default,
                :is_active,
                :created_by
            )
        ");

        $stmt->execute(
            array(
                ':config_name' => $configName,
                ':host' => $host,
                ':port' => $port,
                ':encryption' => $encryption,
                ':username' =>
                    $username === ''
                        ? null
                        : $username,
                ':password_encrypted' =>
                    $passwordEncrypted === ''
                        ? null
                        : $passwordEncrypted,
                ':from_name' =>
                    $fromName === ''
                        ? null
                        : $fromName,
                ':from_email' => $fromEmail,
                ':reply_to_email' =>
                    $replyTo === ''
                        ? null
                        : $replyTo,
                ':is_default' => $isDefault,
                ':is_active' => $isActive,
                ':created_by' => $createdBy
            )
        );

        $pdo->commit();

        es_json(
            200,
            true,
            'SMTP configuration created successfully.'
        );
    }

    if ($action === 'toggle_status') {
        $id = (int)es_post('id', '0');
        $isActive =
            es_post('is_active', '0') === '1'
                ? 1
                : 0;

        if ($id <= 0) {
            es_json(
                422,
                false,
                'Invalid SMTP configuration.'
            );
        }

        es_load_config($pdo, $id);

        $stmt = $pdo->prepare("
            UPDATE smtp_configurations
            SET is_active = :is_active
            WHERE id = :id
              AND scope_type = 'platform'
        ");

        $stmt->execute(
            array(
                ':is_active' => $isActive,
                ':id' => $id
            )
        );

        es_json(
            200,
            true,
            $isActive
                ? 'SMTP configuration activated successfully.'
                : 'SMTP configuration deactivated successfully.'
        );
    }

    if ($action === 'delete_smtp') {
        $id = (int)es_post('id', '0');

        if ($id <= 0) {
            es_json(
                422,
                false,
                'Invalid SMTP configuration.'
            );
        }

        $config = es_load_config($pdo, $id);

        $checkStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM notification_queue
            WHERE smtp_config_id = :id
        ");

        $checkStmt->execute(
            array(':id' => $id)
        );

        if ((int)$checkStmt->fetchColumn() > 0) {
            es_json(
                409,
                false,
                'This SMTP configuration is already used by email history. Deactivate it instead of deleting.'
            );
        }

        $campaignCheck = $pdo->prepare("
            SELECT COUNT(*)
            FROM email_campaigns
            WHERE smtp_config_id = :id
        ");

        try {
            $campaignCheck->execute(
                array(':id' => $id)
            );

            if (
                (int)$campaignCheck->fetchColumn() > 0
            ) {
                es_json(
                    409,
                    false,
                    'This SMTP configuration is already used by an email campaign. Deactivate it instead of deleting.'
                );
            }
        } catch (Throwable $ignore) {
            // Compatibility if email_campaigns is not enabled.
        }

        $stmt = $pdo->prepare("
            DELETE FROM smtp_configurations
            WHERE id = :id
              AND scope_type = 'platform'
        ");

        $stmt->execute(
            array(':id' => $id)
        );

        es_json(
            200,
            true,
            'SMTP configuration deleted successfully.'
        );
    }

    if ($action === 'test_connection') {
        $id = (int)es_post('id', '0');

        if ($id <= 0) {
            es_json(
                422,
                false,
                'Invalid SMTP configuration.'
            );
        }

        $config = es_load_config($pdo, $id);

        $host = trim(
            (string)$config['host']
        );
        $port = (int)$config['port'];

        $transport =
            strtolower(
                (string)$config['encryption']
            ) === 'ssl'
                ? 'ssl://'
                : '';

        $errorNo = 0;
        $errorString = '';

        $socket = @fsockopen(
            $transport . $host,
            $port,
            $errorNo,
            $errorString,
            10
        );

        if (!is_resource($socket)) {
            $message =
                'Connection failed: ' .
                (
                    $errorString !== ''
                        ? $errorString
                        : 'Unable to reach SMTP server.'
                );

            $stmt = $pdo->prepare("
                UPDATE smtp_configurations
                SET
                    last_test_status = 'failed',
                    last_test_message = :message,
                    last_tested_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute(
                array(
                    ':message' => $message,
                    ':id' => $id
                )
            );

            es_json(
                422,
                false,
                $message
            );
        }

        fclose($socket);

        $message =
            'SMTP server connection successful.';

        $stmt = $pdo->prepare("
            UPDATE smtp_configurations
            SET
                last_test_status = 'success',
                last_test_message = :message,
                last_tested_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute(
            array(
                ':message' => $message,
                ':id' => $id
            )
        );

        es_json(
            200,
            true,
            $message
        );
    }

    if ($action === 'send_test_email') {
        $id = (int)es_post(
            'smtp_config_id',
            '0'
        );
        $recipient = es_post(
            'recipient_email'
        );

        if ($id <= 0) {
            es_json(
                422,
                false,
                'Select an SMTP configuration.'
            );
        }

        if (
            !filter_var(
                $recipient,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            es_json(
                422,
                false,
                'Enter a valid recipient email address.'
            );
        }

        $config = es_load_config($pdo, $id);

        if ((int)$config['is_active'] !== 1) {
            es_json(
                422,
                false,
                'Selected SMTP configuration is inactive.'
            );
        }

        try {
            es_send_test(
                $config,
                $recipient
            );

            $message =
                'Test email sent successfully.';

            $stmt = $pdo->prepare("
                UPDATE smtp_configurations
                SET
                    last_test_status = 'success',
                    last_test_message = :message,
                    last_tested_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute(
                array(
                    ':message' => $message,
                    ':id' => $id
                )
            );

            es_json(
                200,
                true,
                $message
            );

        } catch (Throwable $e) {
            $safeMessage =
                'Test email failed: ' .
                $e->getMessage();

            $stmt = $pdo->prepare("
                UPDATE smtp_configurations
                SET
                    last_test_status = 'failed',
                    last_test_message = :message,
                    last_tested_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute(
                array(
                    ':message' => $safeMessage,
                    ':id' => $id
                )
            );

            error_log(
                'FieldPlx SMTP Test Error: ' .
                $e->getMessage()
            );

            es_json(
                422,
                false,
                $safeMessage
            );
        }
    }

    es_json(
        400,
        false,
        'Invalid action.'
    );

} catch (Throwable $e) {

    if (
        isset($pdo) &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    error_log(
        'FieldPlx Email/SMTP API Error: ' .
        $e->getMessage()
    );

    es_json(
        500,
        false,
        'Unable to complete the requested email/SMTP action.'
    );
}
