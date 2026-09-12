<?php
/*
 * FieldPlx Common Platform SMTP - PDF Attachment Version 2.5.0
 *
 * One active platform SMTP is shared by every tenant and branch.
 * This file owns platform SMTP selection, password decryption, SMTP delivery, and optional MIME attachments.
 *
 * Deployment path:
 *   /business/includes/platform-smtp.php
 *
 * PHP 7.2 compatible.
 *
 * Password encryption/decryption matches the Platform SMTP settings API:
 *   v1: + base64(16-byte IV + AES-256-CBC ciphertext)
 *   key = SHA-256(FIELDPLX_SMTP_ENCRYPTION_KEY), raw binary output.
 */

if (!function_exists('fieldplxPlatformSmtpTableExists')) {
    function fieldplxPlatformSmtpTableExists(PDO $pdo)
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES " .
                "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='smtp_configurations'"
            );
            $stmt->execute();
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            error_log('FieldPlx SMTP table check: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('fieldplxPlatformSmtpConfig')) {
    function fieldplxPlatformSmtpConfig(PDO $pdo)
    {
        if (!fieldplxPlatformSmtpTableExists($pdo)) {
            return null;
        }

        /*
         * IMPORTANT: FieldPlx uses ONE global/default platform SMTP for every
         * tenant, branch and user. Tenant/branch SMTP records are intentionally
         * excluded here and must never be used by business modules.
         *
         * Only the active PLATFORM DEFAULT is eligible. This makes the result
         * deterministic and prevents a newer tenant SMTP or another tested
         * platform record from silently taking over invoice delivery.
         */
        $sql = "SELECT *
                FROM smtp_configurations
                WHERE scope_type='platform'
                  AND tenant_id IS NULL
                  AND branch_id IS NULL
                  AND is_active=1
                  AND is_default=1
                ORDER BY
                    CASE
                        WHEN last_test_status='success' THEN 0
                        WHEN last_test_status='not_tested' THEN 1
                        ELSE 2
                    END,
                    id DESC
                LIMIT 1";

        try {
            $stmt = $pdo->query($sql);
            if (!$stmt) {
                return null;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : null;
        } catch (Throwable $e) {
            error_log('FieldPlx platform SMTP selection: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('fieldplxLoadPlatformSmtpSecret')) {
    function fieldplxLoadPlatformSmtpSecret()
    {
        if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
            return;
        }

        /*
         * The SMTP password is encrypted by the PLATFORM SMTP settings API.
         * Business modules must therefore load the SAME smtp-secret.php used
         * by that platform API. The explicit constant/env path is preferred.
         */
        $candidates = array();

        if (defined('FIELDPLX_PLATFORM_SMTP_SECRET_FILE')) {
            $explicit = trim((string)FIELDPLX_PLATFORM_SMTP_SECRET_FILE);
            if ($explicit !== '') $candidates[] = $explicit;
        }

        $envFile = getenv('FIELDPLX_PLATFORM_SMTP_SECRET_FILE');
        if ($envFile !== false && trim((string)$envFile) !== '') {
            $candidates[] = trim((string)$envFile);
        }

        $projectRoot = dirname(__DIR__, 2);

        /* Most common production locations. */
        $candidates[] = $projectRoot . '/platform/includes/smtp-secret.php';
        $candidates[] = $projectRoot . '/admin/includes/smtp-secret.php';
        $candidates[] = $projectRoot . '/includes/smtp-secret.php';

        /* Backward-compatible local business location. */
        $candidates[] = __DIR__ . '/smtp-secret.php';

        $seen = array();
        foreach ($candidates as $file) {
            $file = trim((string)$file);
            if ($file === '' || isset($seen[$file])) continue;
            $seen[$file] = true;

            if (is_file($file)) {
                require_once $file;
                if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
                    return;
                }
            }
        }
    }
}

if (!function_exists('fieldplxSmtpSecretKey')) {
    function fieldplxSmtpSecretKey()
    {
        /* Match es_secret_key() from the Platform SMTP API. */
        fieldplxLoadPlatformSmtpSecret();

        $key = '';

        if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
            $key = trim((string)FIELDPLX_SMTP_ENCRYPTION_KEY);
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

        if (
            $key === '' ||
            $key === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY'
        ) {
            throw new RuntimeException(
                'FIELDPLX_SMTP_ENCRYPTION_KEY is not configured. Configure the same permanent SMTP encryption key used by the Platform SMTP settings.'
            );
        }

        if (strlen($key) < 32) {
            throw new RuntimeException(
                'FIELDPLX_SMTP_ENCRYPTION_KEY must contain at least 32 characters.'
            );
        }

        return hash('sha256', $key, true);
    }
}

if (!function_exists('fieldplxDecryptSmtpPassword')) {
    function fieldplxDecryptSmtpPassword($stored)
    {
        /* Match es_decrypt() from the Platform SMTP API exactly. */
        $stored = (string)$stored;

        if ($stored === '') {
            return '';
        }

        if (strpos($stored, 'v1:') !== 0) {
            return '';
        }

        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }

        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('OpenSSL extension is required for SMTP password decryption.');
        }

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);

        $plain = openssl_decrypt(
            $cipher,
            'AES-256-CBC',
            fieldplxSmtpSecretKey(),
            OPENSSL_RAW_DATA,
            $iv
        );

        return $plain === false ? '' : $plain;
    }
}

if (!function_exists('fieldplxSmtpRead')) {
    function fieldplxSmtpRead($socket)
    {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('SMTP server response timed out.');
                }
                break;
            }
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return trim($response);
    }
}

if (!function_exists('fieldplxSmtpWriteAll')) {
    function fieldplxSmtpWriteAll($socket, $data, $label)
    {
        $data = (string)$data;
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $n = @fwrite($socket, substr($data, $written));
            if ($n === false || $n === 0) {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException($label . ' timed out while writing to the SMTP server.');
                }
                throw new RuntimeException('SMTP connection closed during ' . $label . '.');
            }
            $written += $n;
        }

        return $written;
    }
}

if (!function_exists('fieldplxSmtpCommand')) {
    function fieldplxSmtpCommand($socket, $command, $acceptedCodes, $label)
    {
        if ($command !== null) {
            fieldplxSmtpWriteAll($socket, $command . "\r\n", $label);
        }

        $response = fieldplxSmtpRead($socket);
        $code = (int)substr($response, 0, 3);

        if (!in_array($code, (array)$acceptedCodes, true)) {
            throw new RuntimeException(
                $label . ' failed (SMTP ' . $code . '): ' .
                substr(preg_replace('/[\r\n]+/', ' ', $response), 0, 350)
            );
        }

        return $response;
    }
}

if (!function_exists('fieldplxSmtpHeaderText')) {
    function fieldplxSmtpHeaderText($text)
    {
        $text = str_replace(array("\r", "\n"), ' ', trim((string)$text));
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n");
        }

        return $text;
    }
}

if (!function_exists('fieldplxSmtpAttachmentPayload')) {
    function fieldplxSmtpAttachmentPayload($attachments)
    {
        $out = array();
        $total = 0;

        foreach ((array)$attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $name = isset($attachment['name']) ? trim((string)$attachment['name']) : '';
            $mime = isset($attachment['mime']) ? trim((string)$attachment['mime']) : 'application/octet-stream';
            $content = null;

            if (array_key_exists('content', $attachment)) {
                $content = (string)$attachment['content'];
            } elseif (!empty($attachment['path'])) {
                $path = (string)$attachment['path'];
                if (!is_file($path) || !is_readable($path)) {
                    throw new RuntimeException('Email attachment could not be read: ' . basename($path));
                }
                $content = @file_get_contents($path);
                if ($content === false) {
                    throw new RuntimeException('Email attachment could not be loaded: ' . basename($path));
                }
            }

            if ($content === null) {
                continue;
            }

            if ($name === '') {
                $name = 'attachment';
            }
            $name = preg_replace('/[^A-Za-z0-9._() -]/', '_', basename($name));
            if ($name === '') {
                $name = 'attachment';
            }

            if ($mime === '' || preg_match('/[\r\n]/', $mime)) {
                $mime = 'application/octet-stream';
            }

            $size = strlen($content);
            $total += $size;
            if ($total > 15 * 1024 * 1024) {
                throw new RuntimeException('Total email attachments cannot exceed 15 MB.');
            }

            $out[] = array(
                'name' => $name,
                'mime' => $mime,
                'content' => $content,
                'size' => $size
            );
        }

        return $out;
    }
}

if (!function_exists('fieldplxSmtpSendWithConfig')) {
    function fieldplxSmtpSendWithConfig($cfg, $password, $to, $subject, $html, $attachments = array())
    {
        if (!is_array($cfg)) {
            throw new RuntimeException('Platform SMTP configuration is not available.');
        }

        $to = trim((string)$to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Client email address is invalid.');
        }

        $host = trim((string)(isset($cfg['host']) ? $cfg['host'] : ''));
        $port = (int)(isset($cfg['port']) ? $cfg['port'] : 0);
        $enc = strtolower(trim((string)(isset($cfg['encryption']) ? $cfg['encryption'] : 'none')));
        $user = trim((string)(isset($cfg['username']) ? $cfg['username'] : ''));
        $from = trim((string)(isset($cfg['from_email']) ? $cfg['from_email'] : ''));

        if ($host === '' || $port <= 0) {
            throw new RuntimeException('SMTP host or port is not configured.');
        }
        if (!in_array($enc, array('none', 'ssl', 'tls', 'starttls'), true)) {
            throw new RuntimeException('SMTP encryption setting is invalid.');
        }
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP From Email is invalid.');
        }
        if ($user !== '' && trim((string)$password) === '') {
            throw new RuntimeException('SMTP password is empty.');
        }

        $normalizedAttachments = fieldplxSmtpAttachmentPayload($attachments);

        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(array(
            'ssl' => array(
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
                'SNI_enabled' => true
            )
        ));

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            $message = trim((string)$errstr);
            if ($message === '') {
                $message = 'connection failed';
            }
            throw new RuntimeException(
                'Unable to connect to SMTP server ' . $host . ':' . $port . ': ' . $message
            );
        }

        stream_set_timeout($socket, 20);

        try {
            fieldplxSmtpCommand($socket, null, array(220), 'SMTP greeting');

            $ehlo = isset($_SERVER['SERVER_NAME']) ? trim((string)$_SERVER['SERVER_NAME']) : '';
            $ehlo = preg_replace('/[^A-Za-z0-9.\-]/', '', $ehlo);
            if ($ehlo === '' || strpos($ehlo, '.') === false) {
                $ehlo = 'fieldplx.com';
            }

            fieldplxSmtpCommand($socket, 'EHLO ' . $ehlo, array(250), 'EHLO');

            if ($enc === 'tls' || $enc === 'starttls') {
                fieldplxSmtpCommand($socket, 'STARTTLS', array(220), 'STARTTLS');

                $cryptoMethod = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
                    ? STREAM_CRYPTO_METHOD_TLS_CLIENT
                    : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;

                if (@stream_socket_enable_crypto($socket, true, $cryptoMethod) !== true) {
                    throw new RuntimeException(
                        'Unable to establish TLS encryption with the SMTP server. Check the server CA certificate bundle.'
                    );
                }

                fieldplxSmtpCommand($socket, 'EHLO ' . $ehlo, array(250), 'EHLO after TLS');
            }

            if ($user !== '') {
                fieldplxSmtpCommand($socket, 'AUTH LOGIN', array(334), 'SMTP authentication');
                fieldplxSmtpCommand($socket, base64_encode($user), array(334), 'SMTP username');
                fieldplxSmtpCommand($socket, base64_encode((string)$password), array(235), 'SMTP password');
            }

            fieldplxSmtpCommand($socket, 'MAIL FROM:<' . $from . '>', array(250), 'MAIL FROM');
            fieldplxSmtpCommand($socket, 'RCPT TO:<' . $to . '>', array(250, 251), 'RCPT TO');
            fieldplxSmtpCommand($socket, 'DATA', array(354), 'DATA');

            $fromName = trim((string)(isset($cfg['from_name']) ? $cfg['from_name'] : ''));
            if ($fromName === '') {
                $fromName = 'FieldPlx';
            }

            $messageIdHost = preg_replace('/[^A-Za-z0-9.\-]/', '', $host);
            if ($messageIdHost === '') {
                $messageIdHost = 'fieldplx.com';
            }

            $headers = array(
                'Date: ' . date(DATE_RFC2822),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $messageIdHost . '>',
                'From: ' . fieldplxSmtpHeaderText($fromName) . ' <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . fieldplxSmtpHeaderText($subject),
                'MIME-Version: 1.0',
                'X-Mailer: FieldPlx Platform SMTP'
            );

            if (!empty($cfg['reply_to_email']) && filter_var($cfg['reply_to_email'], FILTER_VALIDATE_EMAIL)) {
                $headers[] = 'Reply-To: <' . trim((string)$cfg['reply_to_email']) . '>';
            }

            if ($normalizedAttachments) {
                $boundary = '=_FieldPlx_' . bin2hex(random_bytes(18));
                $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

                $body = '--' . $boundary . "\r\n";
                $body .= "Content-Type: text/html; charset=UTF-8\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $body .= chunk_split(base64_encode((string)$html), 76, "\r\n");

                foreach ($normalizedAttachments as $attachment) {
                    $body .= '--' . $boundary . "\r\n";
                    $body .= 'Content-Type: ' . $attachment['mime'] . '; name="' . addcslashes($attachment['name'], '\\"') . '"' . "\r\n";
                    $body .= "Content-Transfer-Encoding: base64\r\n";
                    $body .= 'Content-Disposition: attachment; filename="' . addcslashes($attachment['name'], '\\"') . '"' . "\r\n\r\n";
                    $body .= chunk_split(base64_encode($attachment['content']), 76, "\r\n");
                }

                $body .= '--' . $boundary . "--\r\n";
                $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body;
            } else {
                $headers[] = 'Content-Type: text/html; charset=UTF-8';
                $headers[] = 'Content-Transfer-Encoding: 8bit';
                $payload = implode("\r\n", $headers) . "\r\n\r\n" . (string)$html;
            }

            $payload = preg_replace('/(?m)^\./', '..', $payload);
            fieldplxSmtpWriteAll($socket, $payload . "\r\n.\r\n", 'message body');
            fieldplxSmtpCommand($socket, null, array(250), 'Message delivery');

            try {
                fieldplxSmtpCommand($socket, 'QUIT', array(221), 'QUIT');
            } catch (Throwable $quitError) {
                /* Delivery already succeeded; a QUIT failure must not mark it failed. */
            }
        } finally {
            @fclose($socket);
        }

        return true;
    }
}

if (!function_exists('fieldplxSendPlatformMail')) {
    function fieldplxSendPlatformMail(PDO $pdo, $to, $subject, $html, $attachments = array())
    {
        $cfg = fieldplxPlatformSmtpConfig($pdo);
        if (!$cfg) {
            throw new RuntimeException('No active default platform SMTP configuration was found. Configure one platform SMTP as Active + Default in Master Controls.');
        }

        $password = fieldplxDecryptSmtpPassword(
            isset($cfg['password_encrypted']) ? $cfg['password_encrypted'] : ''
        );

        fieldplxSmtpSendWithConfig($cfg, $password, $to, $subject, $html, $attachments);

        return array(
            'smtp_id' => (int)$cfg['id'],
            'config_name' => isset($cfg['config_name']) ? (string)$cfg['config_name'] : '',
            'host' => isset($cfg['host']) ? (string)$cfg['host'] : '',
            'from_email' => isset($cfg['from_email']) ? (string)$cfg['from_email'] : ''
        );
    }
}
