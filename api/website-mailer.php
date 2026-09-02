<?php
/**
 * Small website mail helper.
 * Uses PHP mail() so it does not depend on your encrypted SMTP password format.
 * If your project already has a central SMTP helper, replace the body of
 * websiteSendHtmlMail() with that helper call.
 */

if (!function_exists('websiteSendHtmlMail')) {
    function websiteSendHtmlMail($to, $subject, $html)
    {
        $to = trim((string) $to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $host = !empty($_SERVER['HTTP_HOST'])
            ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', $_SERVER['HTTP_HOST'])
            : 'fieldplx.com';

        $fromDomain = preg_replace('/:\d+$/', '', $host);
        $fromEmail = 'no-reply@' . $fromDomain;

        $headers = array(
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: FieldPlx <' . $fromEmail . '>',
            'Reply-To: support@coreplx.com'
        );

        return @mail(
            $to,
            '=?UTF-8?B?' . base64_encode($subject) . '?=',
            $html,
            implode("\r\n", $headers)
        );
    }
}

if (!function_exists('websiteBaseUrl')) {
    function websiteBaseUrl()
    {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $script = !empty($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/api/website-free-trial.php';
        $basePath = rtrim(dirname(dirname($script)), '/\\');

        if ($basePath === '.' || $basePath === DIRECTORY_SEPARATOR) {
            $basePath = '';
        }

        return $scheme . '://' . $host . $basePath;
    }
}
