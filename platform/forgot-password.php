<?php
/**
 * FieldPlx Platform - Forgot Password / Reset Password
 *
 * File:
 * platform/forgot-password.php
 *
 * Features:
 * - Loads SMTP host, port, encryption, credentials, and sender from platform_settings
 * - Handles both request and reset modes in this single file
 * - Automatically creates password_reset_tokens table
 * - Stores only a SHA-256 hash of the reset token
 * - Prevents email-account enumeration
 * - Rate limits reset requests
 * - PHP 7.2 compatible
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';

date_default_timezone_set('Asia/Kolkata');

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

/*
|--------------------------------------------------------------------------
| SMTP configuration is loaded from platform_settings
|--------------------------------------------------------------------------
*/

function fpLoadSmtpSettings(mysqli $conn): array
{
    $tableCheck = $conn->query("
        SELECT COUNT(*) AS total
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'platform_settings'
    ");

    $tableRow = $tableCheck->fetch_assoc();
    $tableCheck->free();

    if (empty($tableRow['total'])) {
        throw new RuntimeException(
            'Platform SMTP settings have not been configured.'
        );
    }

    $result = $conn->query("
        SELECT
            `smtp_host`,
            `smtp_port`,
            `smtp_encryption`,
            `smtp_username`,
            `smtp_password`,
            `smtp_from_name`,
            `smtp_from_email`,
            `smtp_enabled`
        FROM platform_settings
        WHERE `id` = 1
        LIMIT 1
    ");

    $settings = $result->fetch_assoc();
    $result->free();

    if (!$settings) {
        throw new RuntimeException(
            'Platform SMTP settings were not found.'
        );
    }

    $smtpHost = trim(
        (string) ($settings['smtp_host'] ?? '')
    );

    $smtpPort = (int) (
        $settings['smtp_port'] ?? 0
    );

    $smtpEncryption = strtolower(
        trim(
            (string) (
                $settings['smtp_encryption'] ?? 'ssl'
            )
        )
    );

    $smtpUsername = trim(
        (string) ($settings['smtp_username'] ?? '')
    );

    $smtpPassword = (string) (
        $settings['smtp_password'] ?? ''
    );

    $smtpFromName = trim(
        (string) ($settings['smtp_from_name'] ?? '')
    );

    $smtpFromEmail = trim(
        (string) ($settings['smtp_from_email'] ?? '')
    );

    $smtpEnabled = (int) (
        $settings['smtp_enabled'] ?? 0
    );

    if ($smtpEnabled !== 1) {
        throw new RuntimeException(
            'SMTP email sending is disabled in platform settings.'
        );
    }

    if ($smtpHost === '') {
        throw new RuntimeException(
            'SMTP host is missing in platform settings.'
        );
    }

    if ($smtpPort < 1 || $smtpPort > 65535) {
        throw new RuntimeException(
            'SMTP port is invalid in platform settings.'
        );
    }

    if (
        !in_array(
            $smtpEncryption,
            array('ssl', 'tls', 'none'),
            true
        )
    ) {
        throw new RuntimeException(
            'SMTP encryption is invalid in platform settings.'
        );
    }

    if ($smtpUsername === '') {
        throw new RuntimeException(
            'SMTP username is missing in platform settings.'
        );
    }

    if ($smtpPassword === '') {
        throw new RuntimeException(
            'SMTP password is missing in platform settings.'
        );
    }

    if (
        $smtpFromEmail === '' ||
        !filter_var(
            $smtpFromEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $smtpFromEmail = $smtpUsername;
    }

    if ($smtpFromName === '') {
        $smtpFromName = 'FieldPlx';
    }

    return array(
        'host' => $smtpHost,
        'port' => $smtpPort,
        'encryption' => $smtpEncryption,
        'username' => $smtpUsername,
        'password' => $smtpPassword,
        'from_name' => $smtpFromName,
        'from_email' => $smtpFromEmail
    );
}

/*
|--------------------------------------------------------------------------
| Password-reset configuration
|--------------------------------------------------------------------------
*/

const FIELDPLX_RESET_EXPIRY_MINUTES = 30;
const FIELDPLX_MAX_REQUESTS_PER_WINDOW = 5;
const FIELDPLX_RATE_LIMIT_WINDOW_MINUTES = 15;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function fpEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function fpPost(string $key, string $default = ''): string
{
    if (
        !isset($_POST[$key]) ||
        is_array($_POST[$key])
    ) {
        return $default;
    }

    return trim((string) $_POST[$key]);
}

function fpBaseUrl(): string
{
    $isHttps =
        (!empty($_SERVER['HTTPS']) &&
        strtolower((string) $_SERVER['HTTPS']) !== 'off') ||
        (
            isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
            strtolower(
                (string) $_SERVER['HTTP_X_FORWARDED_PROTO']
            ) === 'https'
        );

    $scheme = $isHttps ? 'https' : 'http';

    $host = isset($_SERVER['HTTP_HOST'])
        ? preg_replace(
            '/[^a-zA-Z0-9.\-:\[\]]/',
            '',
            (string) $_SERVER['HTTP_HOST']
        )
        : '';

    if ($host === '') {
        $host = 'fieldplx.com';
    }

    $scriptName = isset($_SERVER['SCRIPT_NAME'])
        ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME'])
        : '/platform/forgot-password.php';

    $directory = rtrim(dirname($scriptName), '/');

    return $scheme . '://' . $host . $directory;
}

function fpClientIp(): string
{
    $candidates = array(
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? ''
    );

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);

        if ($candidate === '') {
            continue;
        }

        if (strpos($candidate, ',') !== false) {
            $candidate = trim(
                explode(',', $candidate)[0]
            );
        }

        if (
            filter_var(
                $candidate,
                FILTER_VALIDATE_IP
            )
        ) {
            return $candidate;
        }
    }

    return '0.0.0.0';
}

function fpCreateCsrfToken(): string
{
    if (
        empty($_SESSION['forgot_password_csrf']) ||
        !is_string(
            $_SESSION['forgot_password_csrf']
        )
    ) {
        $_SESSION['forgot_password_csrf'] =
            bin2hex(random_bytes(32));
    }

    return $_SESSION['forgot_password_csrf'];
}

function fpVerifyCsrfToken(): bool
{
    $submitted = fpPost('csrf_token');

    return
        $submitted !== '' &&
        !empty($_SESSION['forgot_password_csrf']) &&
        is_string(
            $_SESSION['forgot_password_csrf']
        ) &&
        hash_equals(
            $_SESSION['forgot_password_csrf'],
            $submitted
        );
}

function fpRegenerateCsrfToken(): void
{
    $_SESSION['forgot_password_csrf'] =
        bin2hex(random_bytes(32));
}

function fpReadSmtpResponse($socket): array
{
    $lines = array();
    $code = 0;

    while (!feof($socket)) {
        $line = fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $lines[] = rtrim($line, "\r\n");

        if (preg_match('/^(\d{3})([\s-])/', $line, $match)) {
            $code = (int) $match[1];

            if ($match[2] === ' ') {
                break;
            }
        }
    }

    return array(
        'code' => $code,
        'message' => implode("\n", $lines)
    );
}

function fpSmtpCommand(
    $socket,
    string $command,
    array $expectedCodes
): void {
    fwrite($socket, $command . "\r\n");

    $response = fpReadSmtpResponse($socket);

    if (
        !in_array(
            $response['code'],
            $expectedCodes,
            true
        )
    ) {
        throw new RuntimeException(
            'SMTP command failed: ' .
            $response['message']
        );
    }
}

function fpEncodeHeader(string $value): string
{
    if (
        function_exists('mb_encode_mimeheader')
    ) {
        return mb_encode_mimeheader(
            $value,
            'UTF-8',
            'B',
            "\r\n"
        );
    }

    return '=?UTF-8?B?' .
        base64_encode($value) .
        '?=';
}

function fpSendResetEmail(
    mysqli $conn,
    string $recipientEmail,
    string $recipientName,
    string $resetUrl
): void {
    $smtp = fpLoadSmtpSettings($conn);

    if ($smtp['encryption'] === 'ssl') {
        $transport =
            'ssl://' .
            $smtp['host'] .
            ':' .
            $smtp['port'];
    } else {
        $transport =
            'tcp://' .
            $smtp['host'] .
            ':' .
            $smtp['port'];
    }

    $context = stream_context_create(array(
        'ssl' => array(
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false
        )
    ));

    $socket = @stream_socket_client(
        $transport,
        $errorNumber,
        $errorMessage,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        throw new RuntimeException(
            'Unable to connect to the SMTP server: ' .
            $errorMessage
        );
    }

    stream_set_timeout($socket, 20);

    try {
        $response = fpReadSmtpResponse($socket);

        if ($response['code'] !== 220) {
            throw new RuntimeException(
                'SMTP connection rejected: ' .
                $response['message']
            );
        }

        $hostname = isset($_SERVER['SERVER_NAME'])
            ? preg_replace(
                '/[^a-zA-Z0-9.\-]/',
                '',
                (string) $_SERVER['SERVER_NAME']
            )
            : 'fieldplx.com';

        fpSmtpCommand(
            $socket,
            'EHLO ' . $hostname,
            array(250)
        );

        if ($smtp['encryption'] === 'tls') {
            fpSmtpCommand(
                $socket,
                'STARTTLS',
                array(220)
            );

            $cryptoEnabled =
                stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );

            if ($cryptoEnabled !== true) {
                throw new RuntimeException(
                    'Unable to enable TLS encryption.'
                );
            }

            fpSmtpCommand(
                $socket,
                'EHLO ' . $hostname,
                array(250)
            );
        }

        fpSmtpCommand(
            $socket,
            'AUTH LOGIN',
            array(334)
        );

        fpSmtpCommand(
            $socket,
            base64_encode(
                $smtp['username']
            ),
            array(334)
        );

        fpSmtpCommand(
            $socket,
            base64_encode(
                $smtp['password']
            ),
            array(235)
        );

        fpSmtpCommand(
            $socket,
            'MAIL FROM:<' .
            $smtp['from_email'] .
            '>',
            array(250)
        );

        fpSmtpCommand(
            $socket,
            'RCPT TO:<' .
            $recipientEmail .
            '>',
            array(250, 251)
        );

        fpSmtpCommand(
            $socket,
            'DATA',
            array(354)
        );

        $safeName = fpEscape(
            $recipientName !== ''
                ? $recipientName
                : 'Platform User'
        );

        $safeUrl = fpEscape($resetUrl);

        $subject =
            'Reset your FieldPlx password';

        $htmlBody = '
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reset your FieldPlx password</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f5f7;padding:30px 12px;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:580px;background:#ffffff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;">
<tr>
<td style="padding:24px 28px;background:linear-gradient(135deg,#111827,#6d28d9);color:#ffffff;">
<div style="font-size:22px;font-weight:700;">FieldPlx</div>
<div style="margin-top:5px;font-size:12px;opacity:.82;">Platform Account Security</div>
</td>
</tr>
<tr>
<td style="padding:30px 28px;">
<h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;color:#111827;">Reset your password</h1>
<p style="margin:0 0 14px;font-size:14px;line-height:1.7;color:#4b5563;">Hello ' .
            $safeName .
            ',</p>
<p style="margin:0 0 22px;font-size:14px;line-height:1.7;color:#4b5563;">We received a request to reset your FieldPlx platform password. Use the button below to choose a new password.</p>
<table role="presentation" cellspacing="0" cellpadding="0">
<tr>
<td style="border-radius:9px;background:#7c3aed;">
<a href="' .
            $safeUrl .
            '" style="display:inline-block;padding:13px 20px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">Reset Password</a>
</td>
</tr>
</table>
<p style="margin:22px 0 0;font-size:12px;line-height:1.65;color:#6b7280;">This link expires in ' .
            FIELDPLX_RESET_EXPIRY_MINUTES .
            ' minutes and can be used only once.</p>
<p style="margin:12px 0 0;font-size:12px;line-height:1.65;color:#6b7280;">If you did not request a password reset, you can safely ignore this email.</p>
<div style="margin-top:22px;padding-top:18px;border-top:1px solid #e5e7eb;font-size:11px;line-height:1.6;color:#9ca3af;">
Unable to use the button? Copy and paste this URL into your browser:<br>
<span style="word-break:break-all;color:#6d28d9;">' .
            $safeUrl .
            '</span>
</div>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>';

        $textBody =
            "Hello " .
            (
                $recipientName !== ''
                    ? $recipientName
                    : 'Platform User'
            ) .
            ",\n\n" .
            "We received a request to reset your FieldPlx platform password.\n\n" .
            "Reset your password using this link:\n" .
            $resetUrl .
            "\n\n" .
            "This link expires in " .
            FIELDPLX_RESET_EXPIRY_MINUTES .
            " minutes and can be used only once.\n\n" .
            "If you did not request this reset, ignore this email.";

        $boundary =
            'fieldplx_' .
            bin2hex(random_bytes(12));

        $headers = array(
            'Date: ' . date(DATE_RFC2822),
            'From: ' .
                fpEncodeHeader(
                    $smtp['from_name']
                ) .
                ' <' .
                $smtp['from_email'] .
                '>',
            'To: ' .
                fpEncodeHeader(
                    $recipientName !== ''
                        ? $recipientName
                        : $recipientEmail
                ) .
                ' <' .
                $recipientEmail .
                '>',
            'Subject: ' .
                fpEncodeHeader($subject),
            'Message-ID: <' .
                bin2hex(random_bytes(12)) .
                '@fieldplx.com>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' .
                $boundary .
                '"'
        );

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            '--' . $boundary . "\r\n" .
            'Content-Type: text/plain; charset=UTF-8' .
            "\r\n" .
            'Content-Transfer-Encoding: 8bit' .
            "\r\n\r\n" .
            $textBody .
            "\r\n\r\n" .
            '--' . $boundary . "\r\n" .
            'Content-Type: text/html; charset=UTF-8' .
            "\r\n" .
            'Content-Transfer-Encoding: 8bit' .
            "\r\n\r\n" .
            $htmlBody .
            "\r\n\r\n" .
            '--' . $boundary . '--';

        /*
         * SMTP dot-stuffing.
         */
        $message = preg_replace(
            '/^\./m',
            '..',
            $message
        );

        fwrite(
            $socket,
            $message . "\r\n.\r\n"
        );

        $dataResponse =
            fpReadSmtpResponse($socket);

        if ($dataResponse['code'] !== 250) {
            throw new RuntimeException(
                'SMTP message delivery failed: ' .
                $dataResponse['message']
            );
        }

        fpSmtpCommand(
            $socket,
            'QUIT',
            array(221)
        );
    } finally {
        fclose($socket);
    }
}

/*
|--------------------------------------------------------------------------
| Verify database connection
|--------------------------------------------------------------------------
*/

if (
    !isset($conn) ||
    !($conn instanceof mysqli)
) {
    http_response_code(500);
    exit('Database connection not found.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Create password reset table
|--------------------------------------------------------------------------
*/

$conn->query("
    CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `platform_user_id` BIGINT(20) UNSIGNED NOT NULL,
        `email` VARCHAR(190) NOT NULL,
        `token_hash` CHAR(64) NOT NULL,
        `request_ip` VARCHAR(45) DEFAULT NULL,
        `expires_at` DATETIME NOT NULL,
        `used_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_fieldplx_reset_token_hash`
            (`token_hash`),
        KEY `idx_fieldplx_reset_user`
            (`platform_user_id`),
        KEY `idx_fieldplx_reset_email_created`
            (`email`, `created_at`),
        KEY `idx_fieldplx_reset_expiry`
            (`expires_at`)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
");

/*
|--------------------------------------------------------------------------
| Remove expired old records periodically
|--------------------------------------------------------------------------
*/

try {
    $conn->query("
        DELETE FROM password_reset_tokens
        WHERE
            `expires_at` <
                DATE_SUB(NOW(), INTERVAL 7 DAY)
            OR (
                `used_at` IS NOT NULL
                AND `used_at` <
                    DATE_SUB(NOW(), INTERVAL 7 DAY)
            )
    ");
} catch (Exception $ignored) {
    // Cleanup failure must not block the reset page.
}

/*
|--------------------------------------------------------------------------
| Page state
|--------------------------------------------------------------------------
*/

$errorMessage = '';
$successMessage = '';

$token = isset($_GET['token']) &&
    !is_array($_GET['token'])
        ? trim((string) $_GET['token'])
        : fpPost('token');

$resetMode = $token !== '';

$validResetRecord = null;

if ($resetMode) {
    if (
        !preg_match(
            '/^[a-f0-9]{64}$/i',
            $token
        )
    ) {
        $errorMessage =
            'This password reset link is invalid or has expired.';
    } else {
        $tokenHash = hash('sha256', $token);

        $tokenStmt = $conn->prepare("
            SELECT
                prt.`id` AS reset_id,
                prt.`platform_user_id`,
                prt.`email`,
                prt.`expires_at`,
                pu.`first_name`,
                pu.`last_name`,
                pu.`status`
            FROM password_reset_tokens prt
            INNER JOIN platform_users pu
                ON pu.`id` = prt.`platform_user_id`
            WHERE prt.`token_hash` = ?
              AND prt.`used_at` IS NULL
              AND prt.`expires_at` > NOW()
              AND pu.`deleted_at` IS NULL
              AND pu.`status` = 'active'
            LIMIT 1
        ");

        $tokenStmt->bind_param(
            's',
            $tokenHash
        );

        $tokenStmt->execute();

        $validResetRecord = $tokenStmt
            ->get_result()
            ->fetch_assoc();

        $tokenStmt->close();

        if (!$validResetRecord) {
            $errorMessage =
                'This password reset link is invalid or has expired.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Process request or password reset
|--------------------------------------------------------------------------
*/

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    if (!fpVerifyCsrfToken()) {
        $errorMessage =
            'Your session expired. Refresh the page and try again.';
    } else {
        $action = fpPost('action');

        /*
        |--------------------------------------------------------------------------
        | Request reset link
        |--------------------------------------------------------------------------
        */

        if ($action === 'request_reset') {
            $email = strtolower(
                fpPost('email')
            );

            if (
                $email === '' ||
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                $errorMessage =
                    'Enter a valid email address.';
            } else {
                $clientIp = fpClientIp();

                $rateStmt = $conn->prepare("
                    SELECT COUNT(*) AS total
                    FROM password_reset_tokens
                    WHERE (
                        LOWER(`email`) = LOWER(?)
                        OR `request_ip` = ?
                    )
                      AND `created_at` >=
                        DATE_SUB(
                            NOW(),
                            INTERVAL " .
                            FIELDPLX_RATE_LIMIT_WINDOW_MINUTES .
                            " MINUTE
                        )
                ");

                $rateStmt->bind_param(
                    'ss',
                    $email,
                    $clientIp
                );

                $rateStmt->execute();

                $rateRow = $rateStmt
                    ->get_result()
                    ->fetch_assoc();

                $rateStmt->close();

                if (
                    isset($rateRow['total']) &&
                    (int) $rateRow['total'] >=
                    FIELDPLX_MAX_REQUESTS_PER_WINDOW
                ) {
                    $errorMessage =
                        'Too many reset requests. Try again after 15 minutes.';
                } else {
                    $userStmt = $conn->prepare("
                        SELECT
                            `id`,
                            `first_name`,
                            `last_name`,
                            `email`
                        FROM platform_users
                        WHERE LOWER(`email`) = LOWER(?)
                          AND `status` = 'active'
                          AND `deleted_at` IS NULL
                        LIMIT 1
                    ");

                    $userStmt->bind_param(
                        's',
                        $email
                    );

                    $userStmt->execute();

                    $platformUser = $userStmt
                        ->get_result()
                        ->fetch_assoc();

                    $userStmt->close();

                    /*
                     * Always show the same success message,
                     * whether or not the account exists.
                     */
                    $successMessage =
                        'If an active FieldPlx account exists for this email, a password reset link has been sent.';

                    if ($platformUser) {
                        try {
                            $plainToken =
                                bin2hex(
                                    random_bytes(32)
                                );

                            $tokenHash =
                                hash(
                                    'sha256',
                                    $plainToken
                                );

                            $expiresAt =
                                date(
                                    'Y-m-d H:i:s',
                                    time() +
                                    (
                                        FIELDPLX_RESET_EXPIRY_MINUTES *
                                        60
                                    )
                                );

                            /*
                             * Invalidate previous unused tokens.
                             */
                            $invalidateStmt =
                                $conn->prepare("
                                    UPDATE password_reset_tokens
                                    SET `used_at` = NOW()
                                    WHERE `platform_user_id` = ?
                                      AND `used_at` IS NULL
                                ");

                            $invalidateStmt->bind_param(
                                'i',
                                $platformUser['id']
                            );

                            $invalidateStmt->execute();
                            $invalidateStmt->close();

                            $insertStmt =
                                $conn->prepare("
                                    INSERT INTO password_reset_tokens (
                                        `platform_user_id`,
                                        `email`,
                                        `token_hash`,
                                        `request_ip`,
                                        `expires_at`,
                                        `created_at`
                                    ) VALUES (
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        NOW()
                                    )
                                ");

                            $insertStmt->bind_param(
                                'issss',
                                $platformUser['id'],
                                $platformUser['email'],
                                $tokenHash,
                                $clientIp,
                                $expiresAt
                            );

                            $insertStmt->execute();

                            $resetRecordId =
                                (int) $insertStmt->insert_id;

                            $insertStmt->close();

                            $resetUrl =
                                fpBaseUrl() .
                                '/forgot-password.php?token=' .
                                rawurlencode($plainToken);

                            $recipientName = trim(
                                (string) $platformUser['first_name'] .
                                ' ' .
                                (string) $platformUser['last_name']
                            );

                            fpSendResetEmail(
                                $conn,
                                (string) $platformUser['email'],
                                $recipientName,
                                $resetUrl
                            );
                        } catch (Exception $exception) {
                            error_log(
                                'FieldPlx forgot-password mail error: ' .
                                $exception->getMessage()
                            );

                            if (!empty($resetRecordId)) {
                                try {
                                    $deleteStmt =
                                        $conn->prepare("
                                            DELETE FROM password_reset_tokens
                                            WHERE `id` = ?
                                            LIMIT 1
                                        ");

                                    $deleteStmt->bind_param(
                                        'i',
                                        $resetRecordId
                                    );

                                    $deleteStmt->execute();
                                    $deleteStmt->close();
                                } catch (Exception $ignored) {
                                    // Keep the public response generic.
                                }
                            }
                        }
                    }

                    fpRegenerateCsrfToken();
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Save new password
        |--------------------------------------------------------------------------
        */

        if ($action === 'reset_password') {
            if (!$validResetRecord) {
                $errorMessage =
                    'This password reset link is invalid or has expired.';
            } else {
                $newPassword =
                    isset($_POST['new_password']) &&
                    !is_array(
                        $_POST['new_password']
                    )
                        ? (string) $_POST['new_password']
                        : '';

                $confirmPassword =
                    isset($_POST['confirm_password']) &&
                    !is_array(
                        $_POST['confirm_password']
                    )
                        ? (string) $_POST['confirm_password']
                        : '';

                if (strlen($newPassword) < 8) {
                    $errorMessage =
                        'Password must contain at least 8 characters.';
                } elseif (
                    !preg_match(
                        '/[A-Z]/',
                        $newPassword
                    ) ||
                    !preg_match(
                        '/[a-z]/',
                        $newPassword
                    ) ||
                    !preg_match(
                        '/[0-9]/',
                        $newPassword
                    )
                ) {
                    $errorMessage =
                        'Password must include uppercase, lowercase, and a number.';
                } elseif (
                    $newPassword !==
                    $confirmPassword
                ) {
                    $errorMessage =
                        'Password confirmation does not match.';
                } else {
                    try {
                        $passwordHash =
                            password_hash(
                                $newPassword,
                                PASSWORD_DEFAULT
                            );

                        if ($passwordHash === false) {
                            throw new RuntimeException(
                                'Unable to secure the new password.'
                            );
                        }

                        $conn->begin_transaction();

                        $updateStmt =
                            $conn->prepare("
                                UPDATE platform_users
                                SET
                                    `password_hash` = ?,
                                    `updated_at` = NOW()
                                WHERE `id` = ?
                                  AND `status` = 'active'
                                  AND `deleted_at` IS NULL
                                LIMIT 1
                            ");

                        $updateStmt->bind_param(
                            'si',
                            $passwordHash,
                            $validResetRecord[
                                'platform_user_id'
                            ]
                        );

                        $updateStmt->execute();

                        if (
                            $updateStmt->affected_rows !== 1
                        ) {
                            throw new RuntimeException(
                                'The platform account is not available.'
                            );
                        }

                        $updateStmt->close();

                        $usedStmt =
                            $conn->prepare("
                                UPDATE password_reset_tokens
                                SET `used_at` = NOW()
                                WHERE `id` = ?
                                  AND `used_at` IS NULL
                                LIMIT 1
                            ");

                        $usedStmt->bind_param(
                            'i',
                            $validResetRecord['reset_id']
                        );

                        $usedStmt->execute();

                        if (
                            $usedStmt->affected_rows !== 1
                        ) {
                            throw new RuntimeException(
                                'The reset token was already used.'
                            );
                        }

                        $usedStmt->close();

                        /*
                         * Invalidate any other outstanding reset tokens.
                         */
                        $invalidateOthersStmt =
                            $conn->prepare("
                                UPDATE password_reset_tokens
                                SET `used_at` = NOW()
                                WHERE `platform_user_id` = ?
                                  AND `used_at` IS NULL
                            ");

                        $invalidateOthersStmt->bind_param(
                            'i',
                            $validResetRecord[
                                'platform_user_id'
                            ]
                        );

                        $invalidateOthersStmt->execute();
                        $invalidateOthersStmt->close();

                        $conn->commit();

                        fpRegenerateCsrfToken();

                        $_SESSION['password_reset_success'] =
                            'Your password has been reset successfully. You can now sign in.';

                        header(
                            'Location: login.php?password_reset=1',
                            true,
                            303
                        );

                        exit;
                    } catch (Exception $exception) {
                        $conn->rollback();

                        error_log(
                            'FieldPlx reset-password error: ' .
                            $exception->getMessage()
                        );

                        $errorMessage =
                            'Unable to reset your password. Request a new reset link and try again.';
                    }
                }
            }
        }
    }
}

$csrfToken = fpCreateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        <?= $resetMode
            ? 'Reset Password'
            : 'Forgot Password'; ?>
        - FieldPlx
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --fp-purple: #7c3aed;
            --fp-purple-dark: #6d28d9;
            --fp-text: #111827;
            --fp-muted: #6b7280;
            --fp-border: #e5e7eb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 14px;
            background:
                radial-gradient(
                    circle at top left,
                    rgba(124, 58, 237, 0.13),
                    transparent 34%
                ),
                radial-gradient(
                    circle at bottom right,
                    rgba(17, 24, 39, 0.12),
                    transparent 34%
                ),
                #f4f5f7;
            color: var(--fp-text);
            font-family:
                Inter,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        .reset-shell {
            width: 100%;
            max-width: 430px;
        }

        .reset-brand {
            margin-bottom: 16px;
            text-align: center;
        }

        .reset-brand-mark {
            width: 54px;
            height: 54px;
            margin: 0 auto 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 15px;
            background:
                linear-gradient(
                    135deg,
                    #111827,
                    var(--fp-purple)
                );
            color: #ffffff;
            font-size: 18px;
            font-weight: 800;
            box-shadow:
                0 10px 26px rgba(91, 33, 182, 0.22);
        }

        .reset-brand-name {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
        }

        .reset-brand-subtitle {
            margin-top: 3px;
            color: var(--fp-muted);
            font-size: 10px;
        }

        .reset-card {
            overflow: hidden;
            border: 1px solid var(--fp-border);
            border-radius: 16px;
            background: #ffffff;
            box-shadow:
                0 18px 50px rgba(17, 24, 39, 0.09);
        }

        .reset-card-header {
            padding: 22px 24px 15px;
        }

        .reset-icon {
            width: 42px;
            height: 42px;
            margin-bottom: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 11px;
            background: #f3e8ff;
            color: var(--fp-purple);
            font-size: 17px;
        }

        .reset-title {
            margin: 0;
            font-size: 19px;
            font-weight: 800;
        }

        .reset-description {
            margin: 6px 0 0;
            color: var(--fp-muted);
            font-size: 10px;
            line-height: 1.65;
        }

        .reset-card-body {
            padding: 8px 24px 24px;
        }

        .reset-alert {
            margin-bottom: 14px;
            padding: 10px 12px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            border: 1px solid;
            border-radius: 9px;
            font-size: 9px;
            line-height: 1.55;
        }

        .reset-alert.success {
            border-color: #bbf7d0;
            background: #f0fdf4;
            color: #15803d;
        }

        .reset-alert.danger {
            border-color: #fecaca;
            background: #fef2f2;
            color: #b91c1c;
        }

        .reset-label {
            margin-bottom: 6px;
            color: #374151;
            font-size: 9px;
            font-weight: 700;
        }

        .reset-control {
            min-height: 42px;
            border: 1px solid var(--fp-border);
            border-radius: 9px;
            background: #fafafa;
            box-shadow: none;
            color: #374151;
            font-size: 10px;
        }

        .reset-control:focus {
            border-color: #c4b5fd;
            background: #ffffff;
            box-shadow:
                0 0 0 3px rgba(124, 58, 237, 0.08);
        }

        .reset-password-wrap {
            position: relative;
        }

        .reset-password-wrap .reset-control {
            padding-right: 43px;
        }

        .reset-password-toggle {
            position: absolute;
            top: 50%;
            right: 8px;
            width: 30px;
            height: 30px;
            transform: translateY(-50%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            background: transparent;
            color: #9ca3af;
        }

        .reset-help {
            margin-top: 6px;
            color: #9ca3af;
            font-size: 8px;
            line-height: 1.5;
        }

        .reset-submit {
            width: 100%;
            min-height: 42px;
            margin-top: 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border: 0;
            border-radius: 9px;
            background:
                linear-gradient(
                    135deg,
                    var(--fp-purple),
                    var(--fp-purple-dark)
                );
            color: #ffffff;
            font-size: 10px;
            font-weight: 700;
        }

        .reset-submit:disabled {
            opacity: 0.65;
        }

        .reset-footer {
            padding: 14px 20px;
            border-top: 1px solid #eef0f3;
            background: #fafafa;
            text-align: center;
        }

        .reset-login-link {
            color: var(--fp-purple);
            font-size: 9px;
            font-weight: 700;
            text-decoration: none;
        }

        .reset-login-link:hover {
            color: var(--fp-purple-dark);
        }

        .reset-expired-actions {
            display: grid;
            gap: 8px;
        }

        .reset-secondary-link {
            min-height: 39px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 1px solid var(--fp-border);
            border-radius: 9px;
            background: #ffffff;
            color: #4b5563;
            font-size: 9px;
            font-weight: 700;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <main class="reset-shell">
        <div class="reset-brand">
            <div class="reset-brand-mark">
                FP
            </div>

            <h1 class="reset-brand-name">
                FieldPlx
            </h1>

            <div class="reset-brand-subtitle">
                Platform Administration
            </div>
        </div>

        <section class="reset-card">
            <div class="reset-card-header">
                <span class="reset-icon">
                    <i class="bi <?= $resetMode
                        ? 'bi-shield-lock'
                        : 'bi-envelope-lock'; ?>"></i>
                </span>

                <h2 class="reset-title">
                    <?= $resetMode
                        ? 'Create a new password'
                        : 'Forgot your password?'; ?>
                </h2>

                <p class="reset-description">
                    <?php if ($resetMode): ?>
                        Enter a secure new password for your FieldPlx platform account.
                    <?php else: ?>
                        Enter your platform email address. We will send a secure password-reset link if an active account exists.
                    <?php endif; ?>
                </p>
            </div>

            <div class="reset-card-body">

                <?php if ($successMessage !== ''): ?>
                    <div class="reset-alert success">
                        <i class="bi bi-check-circle"></i>

                        <span>
                            <?= fpEscape($successMessage); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="reset-alert danger">
                        <i class="bi bi-exclamation-circle"></i>

                        <span>
                            <?= fpEscape($errorMessage); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if (
                    $resetMode &&
                    !$validResetRecord
                ): ?>
                    <div class="reset-expired-actions">
                        <a
                            href="forgot-password.php"
                            class="reset-secondary-link"
                        >
                            <i class="bi bi-arrow-repeat"></i>
                            Request a New Reset Link
                        </a>
                    </div>

                <?php elseif ($resetMode): ?>
                    <form
                        method="post"
                        action="forgot-password.php?token=<?= fpEscape(
                            rawurlencode($token)
                        ); ?>"
                        id="resetPasswordForm"
                        autocomplete="off"
                    >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= fpEscape($csrfToken); ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="reset_password"
                        >

                        <input
                            type="hidden"
                            name="token"
                            value="<?= fpEscape($token); ?>"
                        >

                        <div class="mb-3">
                            <label
                                class="reset-label"
                                for="newPassword"
                            >
                                New Password
                            </label>

                            <div class="reset-password-wrap">
                                <input
                                    type="password"
                                    name="new_password"
                                    id="newPassword"
                                    class="form-control reset-control"
                                    minlength="8"
                                    autocomplete="new-password"
                                    required
                                >

                                <button
                                    type="button"
                                    class="reset-password-toggle"
                                    data-password-target="newPassword"
                                    aria-label="Show password"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label
                                class="reset-label"
                                for="confirmPassword"
                            >
                                Confirm Password
                            </label>

                            <div class="reset-password-wrap">
                                <input
                                    type="password"
                                    name="confirm_password"
                                    id="confirmPassword"
                                    class="form-control reset-control"
                                    minlength="8"
                                    autocomplete="new-password"
                                    required
                                >

                                <button
                                    type="button"
                                    class="reset-password-toggle"
                                    data-password-target="confirmPassword"
                                    aria-label="Show password"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="reset-help">
                            Use at least 8 characters with uppercase, lowercase, and a number.
                        </div>

                        <button
                            type="submit"
                            class="reset-submit"
                            id="resetSubmit"
                        >
                            <i class="bi bi-check2-circle"></i>
                            Reset Password
                        </button>
                    </form>

                <?php else: ?>
                    <form
                        method="post"
                        action="forgot-password.php"
                        id="requestResetForm"
                        autocomplete="off"
                    >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= fpEscape($csrfToken); ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="request_reset"
                        >

                        <label
                            class="reset-label"
                            for="email"
                        >
                            Platform Email Address
                        </label>

                        <input
                            type="email"
                            name="email"
                            id="email"
                            class="form-control reset-control"
                            value="<?= fpEscape(
                                strtolower(
                                    fpPost('email')
                                )
                            ); ?>"
                            maxlength="190"
                            autocomplete="email"
                            placeholder="name@company.com"
                            required
                        >

                        <button
                            type="submit"
                            class="reset-submit"
                            id="requestSubmit"
                        >
                            <i class="bi bi-send"></i>
                            Send Reset Link
                        </button>
                    </form>
                <?php endif; ?>

            </div>

            <div class="reset-footer">
                <a
                    href="login.php"
                    class="reset-login-link"
                >
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Platform Login
                </a>
            </div>
        </section>
    </main>

    <script>
    (function () {
        'use strict';

        const passwordButtons =
            document.querySelectorAll(
                '[data-password-target]'
            );

        passwordButtons.forEach(
            function (button) {
                button.addEventListener(
                    'click',
                    function () {
                        const targetId =
                            button.getAttribute(
                                'data-password-target'
                            );

                        const input =
                            document.getElementById(
                                targetId
                            );

                        if (!input) {
                            return;
                        }

                        input.type =
                            input.type === 'password'
                                ? 'text'
                                : 'password';

                        const icon =
                            button.querySelector('i');

                        if (icon) {
                            icon.className =
                                input.type === 'password'
                                    ? 'bi bi-eye'
                                    : 'bi bi-eye-slash';
                        }
                    }
                );
            }
        );

        const resetForm =
            document.getElementById(
                'resetPasswordForm'
            );

        if (resetForm) {
            resetForm.addEventListener(
                'submit',
                function (event) {
                    const password =
                        document.getElementById(
                            'newPassword'
                        );

                    const confirmation =
                        document.getElementById(
                            'confirmPassword'
                        );

                    if (
                        password &&
                        confirmation &&
                        password.value !==
                        confirmation.value
                    ) {
                        event.preventDefault();

                        confirmation.setCustomValidity(
                            'Passwords do not match.'
                        );

                        confirmation.reportValidity();

                        confirmation.setCustomValidity(
                            ''
                        );

                        return;
                    }

                    const button =
                        document.getElementById(
                            'resetSubmit'
                        );

                    if (button) {
                        button.disabled = true;
                        button.innerHTML =
                            '<span class="spinner-border spinner-border-sm"></span> Resetting...';
                    }
                }
            );
        }

        const requestForm =
            document.getElementById(
                'requestResetForm'
            );

        if (requestForm) {
            requestForm.addEventListener(
                'submit',
                function () {
                    const button =
                        document.getElementById(
                            'requestSubmit'
                        );

                    if (button) {
                        button.disabled = true;
                        button.innerHTML =
                            '<span class="spinner-border spinner-border-sm"></span> Sending...';
                    }
                }
            );
        }
    })();
    </script>
</body>
</html>
