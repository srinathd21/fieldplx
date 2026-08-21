<?php
/**
 * FieldPlx CSRF Protection
 *
 * Correct location:
 * /public_html/includes/csrf.php
 *
 * PHP 7.2+
 * Supports:
 * - Normal HTML forms
 * - AJAX requests
 * - JSON requests
 */

require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| CSRF Configuration
|--------------------------------------------------------------------------
*/

if (!defined('FIELDPLX_CSRF_SESSION_KEY')) {
    define(
        'FIELDPLX_CSRF_SESSION_KEY',
        'fieldplx_csrf_token'
    );
}

if (!defined('FIELDPLX_CSRF_TIME_KEY')) {
    define(
        'FIELDPLX_CSRF_TIME_KEY',
        'fieldplx_csrf_created_at'
    );
}

if (!defined('FIELDPLX_CSRF_FIELD_NAME')) {
    define(
        'FIELDPLX_CSRF_FIELD_NAME',
        'csrf_token'
    );
}

if (!defined('FIELDPLX_CSRF_HEADER_NAME')) {
    define(
        'FIELDPLX_CSRF_HEADER_NAME',
        'HTTP_X_CSRF_TOKEN'
    );
}

/*
 * Token lifetime: 4 hours.
 */
if (!defined('FIELDPLX_CSRF_TOKEN_LIFETIME')) {
    define(
        'FIELDPLX_CSRF_TOKEN_LIFETIME',
        14400
    );
}

/*
|--------------------------------------------------------------------------
| Detect AJAX request
|--------------------------------------------------------------------------
*/

if (!function_exists('isCsrfAjaxRequest')) {
    function isCsrfAjaxRequest()
    {
        return !empty(
            $_SERVER['HTTP_X_REQUESTED_WITH']
        ) &&
        strtolower(
            (string)
            $_SERVER['HTTP_X_REQUESTED_WITH']
        ) === 'xmlhttprequest';
    }
}

/*
|--------------------------------------------------------------------------
| Generate secure token
|--------------------------------------------------------------------------
*/

if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken()
    {
        try {
            return bin2hex(
                random_bytes(32)
            );
        } catch (Exception $exception) {
            error_log(
                'CSRF token generation failed: ' .
                $exception->getMessage()
            );

            return hash(
                'sha256',
                uniqid(
                    (string) mt_rand(),
                    true
                ) .
                session_id() .
                microtime(true)
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Create or retrieve token
|--------------------------------------------------------------------------
*/

if (!function_exists('csrfToken')) {
    function csrfToken(
        $forceRegenerate = false
    ) {
        $token = isset(
            $_SESSION[
                FIELDPLX_CSRF_SESSION_KEY
            ]
        )
            ? (string)
              $_SESSION[
                  FIELDPLX_CSRF_SESSION_KEY
              ]
            : '';

        $createdAt = isset(
            $_SESSION[
                FIELDPLX_CSRF_TIME_KEY
            ]
        )
            ? (int)
              $_SESSION[
                  FIELDPLX_CSRF_TIME_KEY
              ]
            : 0;

        $expired =
            $createdAt <= 0 ||
            (
                time() - $createdAt
            ) >
            FIELDPLX_CSRF_TOKEN_LIFETIME;

        if (
            $forceRegenerate ||
            $token === '' ||
            $expired
        ) {
            $token =
                generateCsrfToken();

            $_SESSION[
                FIELDPLX_CSRF_SESSION_KEY
            ] = $token;

            $_SESSION[
                FIELDPLX_CSRF_TIME_KEY
            ] = time();
        }

        return $token;
    }
}

/*
|--------------------------------------------------------------------------
| Regenerate token
|--------------------------------------------------------------------------
*/

if (!function_exists('regenerateCsrfToken')) {
    function regenerateCsrfToken()
    {
        return csrfToken(true);
    }
}

/*
|--------------------------------------------------------------------------
| HTML form field
|--------------------------------------------------------------------------
*/

if (!function_exists('csrfField')) {
    function csrfField()
    {
        echo csrfFieldHtml();
    }
}

if (!function_exists('csrfFieldHtml')) {
    function csrfFieldHtml()
    {
        return
            '<input type="hidden" name="' .
            htmlspecialchars(
                FIELDPLX_CSRF_FIELD_NAME,
                ENT_QUOTES,
                'UTF-8'
            ) .
            '" value="' .
            htmlspecialchars(
                csrfToken(),
                ENT_QUOTES,
                'UTF-8'
            ) .
            '">';
    }
}

/*
|--------------------------------------------------------------------------
| CSRF meta tag
|--------------------------------------------------------------------------
*/

if (!function_exists('csrfMetaTag')) {
    function csrfMetaTag()
    {
        echo
            '<meta name="csrf-token" content="' .
            htmlspecialchars(
                csrfToken(),
                ENT_QUOTES,
                'UTF-8'
            ) .
            '">';
    }
}

/*
|--------------------------------------------------------------------------
| Read token from request
|--------------------------------------------------------------------------
*/

if (!function_exists('getRequestCsrfToken')) {
    function getRequestCsrfToken()
    {
        if (
            !empty(
                $_SERVER[
                    FIELDPLX_CSRF_HEADER_NAME
                ]
            )
        ) {
            return trim(
                (string)
                $_SERVER[
                    FIELDPLX_CSRF_HEADER_NAME
                ]
            );
        }

        if (
            !empty(
                $_POST[
                    FIELDPLX_CSRF_FIELD_NAME
                ]
            ) &&
            !is_array(
                $_POST[
                    FIELDPLX_CSRF_FIELD_NAME
                ]
            )
        ) {
            return trim(
                (string)
                $_POST[
                    FIELDPLX_CSRF_FIELD_NAME
                ]
            );
        }

        $contentType = isset(
            $_SERVER['CONTENT_TYPE']
        )
            ? strtolower(
                (string)
                $_SERVER['CONTENT_TYPE']
            )
            : '';

        if (
            strpos(
                $contentType,
                'application/json'
            ) !== false
        ) {
            $rawInput =
                file_get_contents(
                    'php://input'
                );

            if (
                $rawInput !== false &&
                $rawInput !== ''
            ) {
                $jsonData =
                    json_decode(
                        $rawInput,
                        true
                    );

                if (
                    is_array($jsonData) &&
                    !empty(
                        $jsonData[
                            FIELDPLX_CSRF_FIELD_NAME
                        ]
                    ) &&
                    !is_array(
                        $jsonData[
                            FIELDPLX_CSRF_FIELD_NAME
                        ]
                    )
                ) {
                    return trim(
                        (string)
                        $jsonData[
                            FIELDPLX_CSRF_FIELD_NAME
                        ]
                    );
                }
            }
        }

        return '';
    }
}

/*
|--------------------------------------------------------------------------
| Validate token
|--------------------------------------------------------------------------
*/

if (!function_exists('isValidCsrfToken')) {
    function isValidCsrfToken(
        $submittedToken
    ) {
        $submittedToken =
            trim(
                (string)
                $submittedToken
            );

        if ($submittedToken === '') {
            return false;
        }

        $sessionToken = isset(
            $_SESSION[
                FIELDPLX_CSRF_SESSION_KEY
            ]
        )
            ? (string)
              $_SESSION[
                  FIELDPLX_CSRF_SESSION_KEY
              ]
            : '';

        $createdAt = isset(
            $_SESSION[
                FIELDPLX_CSRF_TIME_KEY
            ]
        )
            ? (int)
              $_SESSION[
                  FIELDPLX_CSRF_TIME_KEY
              ]
            : 0;

        if (
            $sessionToken === '' ||
            $createdAt <= 0
        ) {
            return false;
        }

        if (
            (
                time() - $createdAt
            ) >
            FIELDPLX_CSRF_TOKEN_LIFETIME
        ) {
            return false;
        }

        return hash_equals(
            $sessionToken,
            $submittedToken
        );
    }
}

/*
|--------------------------------------------------------------------------
| Error response
|--------------------------------------------------------------------------
*/

if (!function_exists('csrfErrorResponse')) {
    function csrfErrorResponse(
        $message =
            'The security token is invalid or expired. Please refresh the page and try again.'
    ) {
        http_response_code(419);

        $accept = isset(
            $_SERVER['HTTP_ACCEPT']
        )
            ? strtolower(
                (string)
                $_SERVER['HTTP_ACCEPT']
            )
            : '';

        if (
            isCsrfAjaxRequest() ||
            strpos(
                $accept,
                'application/json'
            ) !== false
        ) {
            header(
                'Content-Type: application/json; charset=UTF-8'
            );

            echo json_encode(
                array(
                    'success' => false,
                    'message' => $message,
                    'csrf_token' =>
                        regenerateCsrfToken()
                )
            );

            exit;
        }

        echo '<!DOCTYPE html>';
        echo '<html lang="en">';
        echo '<head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Security Validation Failed</title>';
        echo '<style>';
        echo 'body{margin:0;font-family:Arial,sans-serif;background:#f5f3ff;color:#1f2937;}';
        echo '.box{max-width:520px;margin:80px auto;background:#fff;padding:28px;border-radius:14px;box-shadow:0 12px 35px rgba(76,29,149,.12);}';
        echo 'h1{font-size:22px;margin:0 0 12px;color:#6d28d9;}';
        echo 'p{font-size:14px;line-height:1.6;margin:0 0 20px;}';
        echo 'a{display:inline-block;padding:10px 16px;background:#6d28d9;color:#fff;text-decoration:none;border-radius:8px;font-size:14px;}';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<div class="box">';
        echo '<h1>Security Validation Failed</h1>';
        echo '<p>' .
            htmlspecialchars(
                $message,
                ENT_QUOTES,
                'UTF-8'
            ) .
            '</p>';
        echo '<a href="javascript:history.back()">Go Back</a>';
        echo '</div>';
        echo '</body>';
        echo '</html>';

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Verify request token
|--------------------------------------------------------------------------
*/

if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken(
        $submittedToken = null
    ) {
        if ($submittedToken === null) {
            $submittedToken =
                getRequestCsrfToken();
        }

        if (
            !isValidCsrfToken(
                $submittedToken
            )
        ) {
            csrfErrorResponse();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Protect unsafe request methods
|--------------------------------------------------------------------------
*/

if (!function_exists('protectCsrfRequest')) {
    function protectCsrfRequest()
    {
        $requestMethod = isset(
            $_SERVER['REQUEST_METHOD']
        )
            ? strtoupper(
                (string)
                $_SERVER['REQUEST_METHOD']
            )
            : 'GET';

        $safeMethods = array(
            'GET',
            'HEAD',
            'OPTIONS'
        );

        if (
            in_array(
                $requestMethod,
                $safeMethods,
                true
            )
        ) {
            return;
        }

        verifyCsrfToken();
    }
}
