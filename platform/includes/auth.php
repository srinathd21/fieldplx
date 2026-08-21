<?php
/**
 * FieldPlx Platform Authentication Guard
 *
 * Authentication table:
 * platform_users
 *
 * Compatible with PHP 7.2 and MySQLi.
 */

require_once __DIR__ . '/../../includes/db.php';

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

if (!defined('FIELDPLX_PLATFORM_SESSION_TIMEOUT')) {
    define('FIELDPLX_PLATFORM_SESSION_TIMEOUT', 7200);
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('platformAuthEscape')) {
    function platformAuthEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('isPlatformAjaxRequest')) {
    function isPlatformAjaxRequest()
    {
        if (
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower(
                (string) $_SERVER['HTTP_X_REQUESTED_WITH']
            ) === 'xmlhttprequest'
        ) {
            return true;
        }

        if (!empty($_SERVER['HTTP_ACCEPT'])) {
            return strpos(
                strtolower((string) $_SERVER['HTTP_ACCEPT']),
                'application/json'
            ) !== false;
        }

        return false;
    }
}

if (!function_exists('platformBaseUrl')) {
    function platformBaseUrl()
    {
        $scriptName = isset($_SERVER['SCRIPT_NAME'])
            ? str_replace(
                '\\',
                '/',
                (string) $_SERVER['SCRIPT_NAME']
            )
            : '';

        $position = strpos($scriptName, '/platform/');

        if ($position !== false) {
            return substr(
                $scriptName,
                0,
                $position + strlen('/platform')
            );
        }

        return '/platform';
    }
}

if (!function_exists('platformUrl')) {
    function platformUrl($path = '')
    {
        $baseUrl = rtrim(platformBaseUrl(), '/');
        $path = ltrim((string) $path, '/');

        return $path === ''
            ? $baseUrl
            : $baseUrl . '/' . $path;
    }
}

if (!function_exists('platformLoginUrl')) {
    function platformLoginUrl()
    {
        return platformUrl('login.php');
    }
}

if (!function_exists('platformAuthJsonError')) {
    function platformAuthJsonError(
        $message,
        $statusCode = 401
    ) {
        http_response_code((int) $statusCode);

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            array(
                'success' => false,
                'message' => (string) $message,
                'login_url' => platformLoginUrl()
            ),
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }
}

if (!function_exists('clearPlatformSession')) {
    function clearPlatformSession()
    {
        $keys = array(
            'platform_authenticated',
            'platform_user_id',
            'platform_user_name',
            'platform_first_name',
            'platform_last_name',
            'platform_email',
            'platform_phone',
            'platform_avatar_path',
            'platform_job_title',
            'platform_role_code',
            'platform_role_name',
            'platform_last_activity',
            'platform_login_at',
            'platform_login_ip',
            'platform_login_user_agent'
        );

        foreach ($keys as $key) {
            unset($_SESSION[$key]);
        }
    }
}

if (!function_exists('redirectToPlatformLogin')) {
    function redirectToPlatformLogin($message)
    {
        $currentUrl = isset($_SERVER['REQUEST_URI'])
            ? (string) $_SERVER['REQUEST_URI']
            : '/platform/dashboard.php';

        clearPlatformSession();

        if (isPlatformAjaxRequest()) {
            platformAuthJsonError(
                $message,
                401
            );
        }

        $_SESSION['platform_login_message'] =
            (string) $message;

        header(
            'Location: ' .
            platformLoginUrl() .
            '?redirect=' .
            urlencode($currentUrl)
        );

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Validate session
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['platform_authenticated']) ||
    empty($_SESSION['platform_user_id'])
) {
    redirectToPlatformLogin(
        'Please sign in to access the platform panel.'
    );
}

/*
|--------------------------------------------------------------------------
| Session timeout
|--------------------------------------------------------------------------
*/

$currentTime = time();

$lastActivity = isset(
    $_SESSION['platform_last_activity']
)
    ? (int) $_SESSION['platform_last_activity']
    : 0;

if (
    $lastActivity > 0 &&
    ($currentTime - $lastActivity) >
        FIELDPLX_PLATFORM_SESSION_TIMEOUT
) {
    redirectToPlatformLogin(
        'Your platform session expired due to inactivity.'
    );
}

$_SESSION['platform_last_activity'] = $currentTime;

/*
|--------------------------------------------------------------------------
| Validate platform user from platform_users
|--------------------------------------------------------------------------
*/

$platformUserId =
    (int) $_SESSION['platform_user_id'];

$stmt = $conn->prepare("
    SELECT
        id,
        first_name,
        last_name,
        email,
        phone,
        avatar_path,
        job_title,
        role_code,
        status,
        last_login_at,
        created_at,
        updated_at,
        deleted_at
    FROM platform_users
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    error_log(
        'Platform auth prepare error: ' .
        $conn->error
    );

    if (isPlatformAjaxRequest()) {
        platformAuthJsonError(
            'Unable to validate platform access.',
            500
        );
    }

    http_response_code(500);
    exit('Unable to validate platform access.');
}

$stmt->bind_param(
    'i',
    $platformUserId
);

if (!$stmt->execute()) {
    error_log(
        'Platform auth execute error: ' .
        $stmt->error
    );

    $stmt->close();

    if (isPlatformAjaxRequest()) {
        platformAuthJsonError(
            'Unable to validate platform access.',
            500
        );
    }

    http_response_code(500);
    exit('Unable to validate platform access.');
}

$result = $stmt->get_result();
$platformUserRecord = $result->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| Account validation
|--------------------------------------------------------------------------
*/

if (!$platformUserRecord) {
    redirectToPlatformLogin(
        'Your platform administrator account could not be found.'
    );
}

if (!empty($platformUserRecord['deleted_at'])) {
    redirectToPlatformLogin(
        'Your platform administrator account has been deleted.'
    );
}

if (
    strtolower(
        trim(
            (string) $platformUserRecord['status']
        )
    ) !== 'active'
) {
    redirectToPlatformLogin(
        'Your platform administrator account is inactive.'
    );
}

$allowedPlatformRoles = array(
    'super_admin',
    'platform_admin',
    'support_admin',
    'billing_admin',
    'platform_read_only'
);

$platformRoleCode = strtolower(
    trim(
        (string) $platformUserRecord['role_code']
    )
);

if (
    !in_array(
        $platformRoleCode,
        $allowedPlatformRoles,
        true
    )
) {
    redirectToPlatformLogin(
        'Your platform role is not authorised.'
    );
}

/*
|--------------------------------------------------------------------------
| Refresh session values
|--------------------------------------------------------------------------
*/

$platformFullName = trim(
    (string) $platformUserRecord['first_name'] .
    ' ' .
    (string) $platformUserRecord['last_name']
);

if ($platformFullName === '') {
    $platformFullName =
        'Platform Administrator';
}

$platformRoleName = ucwords(
    str_replace(
        '_',
        ' ',
        $platformRoleCode
    )
);

$_SESSION['platform_authenticated'] = true;

$_SESSION['platform_user_id'] =
    (int) $platformUserRecord['id'];

$_SESSION['platform_user_name'] =
    $platformFullName;

$_SESSION['platform_first_name'] =
    (string) $platformUserRecord['first_name'];

$_SESSION['platform_last_name'] =
    (string) $platformUserRecord['last_name'];

$_SESSION['platform_email'] =
    (string) $platformUserRecord['email'];

$_SESSION['platform_phone'] =
    (string) $platformUserRecord['phone'];

$_SESSION['platform_avatar_path'] =
    (string) $platformUserRecord['avatar_path'];

$_SESSION['platform_job_title'] =
    (string) $platformUserRecord['job_title'];

$_SESSION['platform_role_code'] =
    $platformRoleCode;

$_SESSION['platform_role_name'] =
    $platformRoleName;

/*
|--------------------------------------------------------------------------
| Authentication helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('isPlatformLoggedIn')) {
    function isPlatformLoggedIn()
    {
        return (
            !empty(
                $_SESSION['platform_authenticated']
            ) &&
            !empty(
                $_SESSION['platform_user_id']
            )
        );
    }
}

if (!function_exists('currentPlatformUserId')) {
    function currentPlatformUserId()
    {
        return isset(
            $_SESSION['platform_user_id']
        )
            ? (int) $_SESSION['platform_user_id']
            : 0;
    }
}

if (!function_exists('currentPlatformRoleCode')) {
    function currentPlatformRoleCode()
    {
        return isset(
            $_SESSION['platform_role_code']
        )
            ? (string)
                $_SESSION['platform_role_code']
            : '';
    }
}

if (!function_exists('platformAuthUser')) {
    function platformAuthUser()
    {
        return array(
            'id' => isset(
                $_SESSION['platform_user_id']
            )
                ? (int)
                    $_SESSION['platform_user_id']
                : 0,

            'name' => isset(
                $_SESSION['platform_user_name']
            )
                ? (string)
                    $_SESSION['platform_user_name']
                : '',

            'first_name' => isset(
                $_SESSION['platform_first_name']
            )
                ? (string)
                    $_SESSION['platform_first_name']
                : '',

            'last_name' => isset(
                $_SESSION['platform_last_name']
            )
                ? (string)
                    $_SESSION['platform_last_name']
                : '',

            'email' => isset(
                $_SESSION['platform_email']
            )
                ? (string)
                    $_SESSION['platform_email']
                : '',

            'phone' => isset(
                $_SESSION['platform_phone']
            )
                ? (string)
                    $_SESSION['platform_phone']
                : '',

            'avatar_path' => isset(
                $_SESSION['platform_avatar_path']
            )
                ? (string)
                    $_SESSION['platform_avatar_path']
                : '',

            'job_title' => isset(
                $_SESSION['platform_job_title']
            )
                ? (string)
                    $_SESSION['platform_job_title']
                : '',

            'role_code' => isset(
                $_SESSION['platform_role_code']
            )
                ? (string)
                    $_SESSION['platform_role_code']
                : '',

            'role_name' => isset(
                $_SESSION['platform_role_name']
            )
                ? (string)
                    $_SESSION['platform_role_name']
                : ''
        );
    }
}

if (!function_exists('hasPlatformRole')) {
    function hasPlatformRole($roleCodes)
    {
        if (!is_array($roleCodes)) {
            $roleCodes = array($roleCodes);
        }

        $normalisedRoleCodes = array();

        foreach ($roleCodes as $roleCode) {
            $normalisedRoleCodes[] = strtolower(
                trim((string) $roleCode)
            );
        }

        return in_array(
            currentPlatformRoleCode(),
            $normalisedRoleCodes,
            true
        );
    }
}

if (!function_exists('requirePlatformRole')) {
    function requirePlatformRole($roleCodes)
    {
        if (hasPlatformRole($roleCodes)) {
            return;
        }

        if (isPlatformAjaxRequest()) {
            platformAuthJsonError(
                'You do not have permission to perform this action.',
                403
            );
        }

        http_response_code(403);

        echo '<!DOCTYPE html>';
        echo '<html lang="en">';
        echo '<head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Access Denied</title>';
        echo '</head>';
        echo '<body>';
        echo '<h1>403 - Access Denied</h1>';
        echo '<p>You do not have permission to access this page.</p>';
        echo '</body>';
        echo '</html>';

        exit;
    }
}

if (!function_exists('isPlatformSuperAdmin')) {
    function isPlatformSuperAdmin()
    {
        return currentPlatformRoleCode() ===
            'super_admin';
    }
}

if (!function_exists('canManagePlatformTenants')) {
    function canManagePlatformTenants()
    {
        return hasPlatformRole(
            array(
                'super_admin',
                'platform_admin'
            )
        );
    }
}

if (!function_exists('canManagePlatformBilling')) {
    function canManagePlatformBilling()
    {
        return hasPlatformRole(
            array(
                'super_admin',
                'platform_admin',
                'billing_admin'
            )
        );
    }
}

if (!function_exists('canProvidePlatformSupport')) {
    function canProvidePlatformSupport()
    {
        return hasPlatformRole(
            array(
                'super_admin',
                'platform_admin',
                'support_admin'
            )
        );
    }
}