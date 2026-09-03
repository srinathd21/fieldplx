<?php
/*
|--------------------------------------------------------------------------
| FieldPlx Database Connection
|--------------------------------------------------------------------------
|
| File:
| business/includes/db.php
|
| Used by:
| - business/login.php
| - business/logout.php
| - business/includes/auth.php
| - business/includes/audit.php
| - all tenant/business pages
|
*/

/*
|--------------------------------------------------------------------------
| Database Configuration
|--------------------------------------------------------------------------
|
| Change these values to match your WAMP / hosting database.
|
*/

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'u399080022_fieldplx');
define('DB_USER', 'u399080022_fieldplx');
define('DB_PASS', 'Hifi@2026');

/*
|--------------------------------------------------------------------------
| PDO DSN
|--------------------------------------------------------------------------
*/

$dsn =
    'mysql:host=' . DB_HOST .
    ';port=' . DB_PORT .
    ';dbname=' . DB_NAME .
    ';charset=utf8mb4';

/*
|--------------------------------------------------------------------------
| PDO Options
|--------------------------------------------------------------------------
*/

$options = array(

    /*
     * Throw exceptions for database errors.
     */
    PDO::ATTR_ERRMODE =>
        PDO::ERRMODE_EXCEPTION,

    /*
     * Return associative arrays by default.
     */
    PDO::ATTR_DEFAULT_FETCH_MODE =>
        PDO::FETCH_ASSOC,

    /*
     * Use real MySQL/MariaDB prepared statements.
     */
    PDO::ATTR_EMULATE_PREPARES =>
        false,

    /*
     * Do not stringify numeric values unnecessarily.
     */
    PDO::ATTR_STRINGIFY_FETCHES =>
        false

);

/*
|--------------------------------------------------------------------------
| Connect
|--------------------------------------------------------------------------
*/

try {

    $pdo =
        new PDO(
            $dsn,
            DB_USER,
            DB_PASS,
            $options
        );

    /*
    |--------------------------------------------------------------------------
    | Connection Session Settings
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        SET NAMES utf8mb4
        COLLATE utf8mb4_unicode_ci
    ");

    /*
     * Keep database connection timezone neutral.
     * Tenant timezone is handled by auth.php using
     * the tenant / branch timezone stored in session.
     */
    $pdo->exec("
        SET time_zone = '+00:00'
    ");

} catch (PDOException $e) {

    /*
     * Log technical error server-side.
     * Never display DB credentials or detailed connection errors to users.
     */
    error_log(
        'FieldPlx database connection error: ' .
        $e->getMessage()
    );

    /*
     * AJAX / API request
     */
    $isAjax =
        isset(
            $_SERVER[
                'HTTP_X_REQUESTED_WITH'
            ]
        ) &&
        strtolower(
            (string)$_SERVER[
                'HTTP_X_REQUESTED_WITH'
            ]
        ) ===
        'xmlhttprequest';

    if ($isAjax) {

        while (
            ob_get_level() > 0
        ) {
            @ob_end_clean();
        }

        http_response_code(500);

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode(
            array(
                'success' => false,
                'message' =>
                    'Unable to connect to the database.'
            )
        );

        exit;
    }

    /*
     * Normal page request
     */
    http_response_code(500);

    echo '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta
            name="viewport"
            content="width=device-width, initial-scale=1"
        >
        <title>Database Error - FieldPlx</title>

        <style>
        *{
            box-sizing:border-box
        }

        body{
            margin:0;
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:20px;
            background:#f6f8fb;
            color:#0b1933;
            font-family:Arial,Helvetica,sans-serif
        }

        .db-error{
            width:min(440px,100%);
            padding:28px;
            text-align:center;
            border:1px solid #e5eaf1;
            border-radius:14px;
            background:#fff;
            box-shadow:0 18px 40px rgba(0,17,49,.08)
        }

        .db-error-icon{
            width:48px;
            height:48px;
            margin:0 auto 14px;
            display:flex;
            align-items:center;
            justify-content:center;
            border-radius:12px;
            background:#fef2f2;
            color:#dc2626;
            font-size:22px
        }

        .db-error h2{
            margin:0;
            color:#001131;
            font-size:18px
        }

        .db-error p{
            margin:9px 0 0;
            color:#6f7b90;
            font-size:11px;
            line-height:1.6
        }
        </style>
    </head>

    <body>

        <div class="db-error">

            <div class="db-error-icon">
                !
            </div>

            <h2>
                Database Connection Error
            </h2>

            <p>
                FieldPlx could not connect to the database.
                Please contact the system administrator.
            </p>

        </div>

    </body>
    </html>
    ';

    exit;
}