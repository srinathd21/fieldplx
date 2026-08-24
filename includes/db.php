<?php
/**
 * FieldPlx Database Connection
 *
 * PHP 7.2+
 * MySQL / MariaDB
 * MySQLi
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Database Configuration
|--------------------------------------------------------------------------
*/

define('DB_HOST', 'srv1336.hstgr.io');
define('DB_PORT', 3306);
define('DB_NAME', 'u923280188_fieldplx');
define('DB_USER', 'u923280188_fieldplx');
define('DB_PASS', 'Hifi@2026');
define('DB_CHARSET', 'utf8mb4');

/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(
    DB_HOST,
    DB_USER,
    DB_PASS,
    DB_NAME,
    DB_PORT
);

if ($conn->connect_errno) {
    error_log(
        'FieldPlx database connection failed: ' .
        $conn->connect_error
    );

    http_response_code(500);
    exit('Unable to connect to the database.');
}

/*
|--------------------------------------------------------------------------
| Character Set
|--------------------------------------------------------------------------
*/

if (!$conn->set_charset(DB_CHARSET)) {
    error_log(
        'FieldPlx character set error: ' .
        $conn->error
    );

    http_response_code(500);
    exit('Unable to configure the database connection.');
}

/*
|--------------------------------------------------------------------------
| Common Helper Functions
|--------------------------------------------------------------------------
*/

/**
 * Escape a value before displaying it in HTML.
 *
 * @param mixed $value
 * @return string
 */
if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

/**
 * Return the logged-in tenant ID.
 *
 * Tenant ID must always come from the session,
 * never directly from GET or POST data.
 *
 * @return int
 */
if (!function_exists('currentTenantId')) {
    function currentTenantId()
    {
        return !empty($_SESSION['tenant_id'])
            ? (int) $_SESSION['tenant_id']
            : 0;
    }
}

/**
 * Return the logged-in user ID.
 *
 * @return int
 */
if (!function_exists('currentUserId')) {
    function currentUserId()
    {
        return !empty($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : 0;
    }
}

/**
 * Return the logged-in role ID.
 *
 * @return int
 */
if (!function_exists('currentRoleId')) {
    function currentRoleId()
    {
        return !empty($_SESSION['role_id'])
            ? (int) $_SESSION['role_id']
            : 0;
    }
}