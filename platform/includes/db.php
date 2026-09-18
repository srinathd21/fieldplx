<?php
/**
 * FieldPlx PDO Database Connection
 *
 * PHP 7.2+
 * MySQL / MariaDB
 * PDO
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Database Configuration
|--------------------------------------------------------------------------
*/

define('DB_HOST', 'srv483.hstgr.io');
define('DB_PORT', '3306');
define('DB_NAME', 'u399080022_fieldplx');
define('DB_USER', 'u399080022_fieldplx');

/*
 * Keep the real password in this server-side file only.
 * Do not expose this file publicly or commit it to a public repository.
 */
define('DB_PASS', 'Hifi@2026');

define('DB_CHARSET', 'utf8mb4');

/*
|--------------------------------------------------------------------------
| PDO Connection
|--------------------------------------------------------------------------
*/

$dsn = 'mysql:host=' . DB_HOST .
       ';port=' . DB_PORT .
       ';dbname=' . DB_NAME .
       ';charset=' . DB_CHARSET;

$options = array(
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_STRINGIFY_FETCHES  => false
);

try {
    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        $options
    );
} catch (PDOException $exception) {
    error_log(
        'FieldPlx PDO connection failed: ' .
        $exception->getMessage()
    );

    http_response_code(500);

    exit(
        'Unable to connect to the database.'
    );
}

/*
|--------------------------------------------------------------------------
| Common Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('currentTenantId')) {
    function currentTenantId()
    {
        return !empty($_SESSION['tenant_id'])
            ? (int) $_SESSION['tenant_id']
            : 0;
    }
}

if (!function_exists('currentUserId')) {
    function currentUserId()
    {
        return !empty($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : 0;
    }
}

if (!function_exists('currentRoleId')) {
    function currentRoleId()
    {
        return !empty($_SESSION['role_id'])
            ? (int) $_SESSION['role_id']
            : 0;
    }
}