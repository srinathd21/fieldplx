<?php
/**
 * FieldPlx API - Country & Currency Master
 * PHP 7.2+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function gm_api_post(string $key, string $default = ''): string
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }

    return trim((string) $_POST[$key]);
}

function gm_api_json(
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gm_api_json(
        405,
        false,
        'Method not allowed.'
    );
}

$csrf = gm_api_post('csrf_token');

if (
    empty($_SESSION['country_currency_master_csrf']) ||
    !is_string($_SESSION['country_currency_master_csrf']) ||
    $csrf === '' ||
    !hash_equals(
        $_SESSION['country_currency_master_csrf'],
        $csrf
    )
) {
    gm_api_json(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$action = gm_api_post('ajax_action');

try {

    /*
    |--------------------------------------------------------------------------
    | COUNTRY SAVE
    |--------------------------------------------------------------------------
    */

    if ($action === 'country_save') {
        $id = (int) gm_api_post('id', '0');
        $name = gm_api_post('name');
        $iso2 = strtoupper(gm_api_post('iso2'));
        $iso3 = strtoupper(gm_api_post('iso3'));
        $phoneCode = gm_api_post('phone_code');
        $currencyCode = strtoupper(
            gm_api_post('default_currency_code')
        );
        $timezone = gm_api_post('default_timezone');
        $dateFormat = gm_api_post(
            'date_format',
            'd-m-Y'
        );
        $numberFormat = gm_api_post(
            'number_format',
            '1,234.56'
        );
        $taxLabel = gm_api_post('tax_label');
        $isActive =
            gm_api_post('is_active', '1') === '1'
                ? 1
                : 0;

        if (
            $name === '' ||
            strlen($name) > 120
        ) {
            gm_api_json(
                422,
                false,
                'Country name is required.'
            );
        }

        if (!preg_match('/^[A-Z]{2}$/', $iso2)) {
            gm_api_json(
                422,
                false,
                'ISO2 must contain exactly 2 letters.'
            );
        }

        if (!preg_match('/^[A-Z]{3}$/', $iso3)) {
            gm_api_json(
                422,
                false,
                'ISO3 must contain exactly 3 letters.'
            );
        }

        $dupStmt = $pdo->prepare("
            SELECT id
            FROM countries
            WHERE (
                name = :name
                OR iso2 = :iso2
                OR iso3 = :iso3
            )
            AND id <> :id
            LIMIT 1
        ");

        $dupStmt->execute(
            array(
                ':name' => $name,
                ':iso2' => $iso2,
                ':iso3' => $iso3,
                ':id' => $id
            )
        );

        if ($dupStmt->fetchColumn()) {
            gm_api_json(
                422,
                false,
                'Country name or ISO code already exists.'
            );
        }

        if ($currencyCode !== '') {
            $currencyStmt = $pdo->prepare("
                SELECT id
                FROM currencies
                WHERE currency_code = :code
                LIMIT 1
            ");

            $currencyStmt->execute(
                array(
                    ':code' => $currencyCode
                )
            );

            if (!$currencyStmt->fetchColumn()) {
                gm_api_json(
                    422,
                    false,
                    'Selected default currency is not available.'
                );
            }
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE countries
                SET
                    name = :name,
                    iso2 = :iso2,
                    iso3 = :iso3,
                    phone_code = :phone_code,
                    default_currency_code = :currency_code,
                    default_timezone = :timezone,
                    date_format = :date_format,
                    number_format = :number_format,
                    tax_label = :tax_label,
                    is_active = :is_active
                WHERE id = :id
            ");

            $stmt->execute(
                array(
                    ':name' => $name,
                    ':iso2' => $iso2,
                    ':iso3' => $iso3,
                    ':phone_code' =>
                        $phoneCode === ''
                            ? null
                            : $phoneCode,
                    ':currency_code' =>
                        $currencyCode === ''
                            ? null
                            : $currencyCode,
                    ':timezone' =>
                        $timezone === ''
                            ? null
                            : $timezone,
                    ':date_format' =>
                        $dateFormat,
                    ':number_format' =>
                        $numberFormat,
                    ':tax_label' =>
                        $taxLabel === ''
                            ? null
                            : $taxLabel,
                    ':is_active' =>
                        $isActive,
                    ':id' =>
                        $id
                )
            );

            gm_api_json(
                200,
                true,
                'Country updated successfully.'
            );
        }

        $stmt = $pdo->prepare("
            INSERT INTO countries (
                name,
                iso2,
                iso3,
                phone_code,
                default_currency_code,
                default_timezone,
                date_format,
                number_format,
                tax_label,
                is_active
            ) VALUES (
                :name,
                :iso2,
                :iso3,
                :phone_code,
                :currency_code,
                :timezone,
                :date_format,
                :number_format,
                :tax_label,
                :is_active
            )
        ");

        $stmt->execute(
            array(
                ':name' => $name,
                ':iso2' => $iso2,
                ':iso3' => $iso3,
                ':phone_code' =>
                    $phoneCode === ''
                        ? null
                        : $phoneCode,
                ':currency_code' =>
                    $currencyCode === ''
                        ? null
                        : $currencyCode,
                ':timezone' =>
                    $timezone === ''
                        ? null
                        : $timezone,
                ':date_format' =>
                    $dateFormat,
                ':number_format' =>
                    $numberFormat,
                ':tax_label' =>
                    $taxLabel === ''
                        ? null
                        : $taxLabel,
                ':is_active' =>
                    $isActive
            )
        );

        gm_api_json(
            200,
            true,
            'Country created successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | COUNTRY STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === 'country_status') {
        $id = (int) gm_api_post('id', '0');
        $isActive =
            gm_api_post('is_active', '0') === '1'
                ? 1
                : 0;

        if ($id <= 0) {
            gm_api_json(
                422,
                false,
                'Invalid country.'
            );
        }

        $stmt = $pdo->prepare("
            UPDATE countries
            SET is_active = :is_active
            WHERE id = :id
        ");

        $stmt->execute(
            array(
                ':is_active' => $isActive,
                ':id' => $id
            )
        );

        gm_api_json(
            200,
            true,
            $isActive
                ? 'Country activated successfully.'
                : 'Country deactivated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | COUNTRY DELETE
    |--------------------------------------------------------------------------
    */

    if ($action === 'country_delete') {
        $id = (int) gm_api_post('id', '0');

        if ($id <= 0) {
            gm_api_json(
                422,
                false,
                'Invalid country.'
            );
        }

        $checks = array(
            "SELECT COUNT(*) FROM tenants WHERE country_id = :id",
            "SELECT COUNT(*) FROM branches WHERE country_id = :id"
        );

        foreach ($checks as $sql) {
            try {
                $checkStmt = $pdo->prepare($sql);
                $checkStmt->execute(
                    array(':id' => $id)
                );

                if (
                    (int) $checkStmt->fetchColumn() > 0
                ) {
                    gm_api_json(
                        409,
                        false,
                        'This country is already in use. Deactivate it instead of deleting.'
                    );
                }

            } catch (Throwable $ignore) {
                // Compatibility check only.
            }
        }

        $stmt = $pdo->prepare("
            DELETE FROM countries
            WHERE id = :id
        ");

        $stmt->execute(
            array(':id' => $id)
        );

        gm_api_json(
            200,
            true,
            'Country deleted successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CURRENCY SAVE
    |--------------------------------------------------------------------------
    */

    if ($action === 'currency_save') {
        $id = (int) gm_api_post('id', '0');
        $code = strtoupper(
            gm_api_post('currency_code')
        );
        $name = gm_api_post('currency_name');
        $symbol = gm_api_post('symbol');
        $position = gm_api_post(
            'symbol_position',
            'before'
        );
        $decimalPlaces = (int) gm_api_post(
            'decimal_places',
            '2'
        );
        $decimalSeparator = gm_api_post(
            'decimal_separator',
            '.'
        );
        $thousandSeparator = gm_api_post(
            'thousand_separator',
            ','
        );
        $isActive =
            gm_api_post('is_active', '1') === '1'
                ? 1
                : 0;

        if (
            !preg_match(
                '/^[A-Z]{3}$/',
                $code
            )
        ) {
            gm_api_json(
                422,
                false,
                'Currency code must contain exactly 3 letters.'
            );
        }

        if (
            $name === '' ||
            strlen($name) > 120
        ) {
            gm_api_json(
                422,
                false,
                'Currency name is required.'
            );
        }

        if (
            $symbol === '' ||
            strlen($symbol) > 12
        ) {
            gm_api_json(
                422,
                false,
                'Currency symbol is required.'
            );
        }

        if (
            !in_array(
                $position,
                array('before', 'after'),
                true
            )
        ) {
            gm_api_json(
                422,
                false,
                'Invalid symbol position.'
            );
        }

        if (
            $decimalPlaces < 0 ||
            $decimalPlaces > 8
        ) {
            gm_api_json(
                422,
                false,
                'Decimal places must be between 0 and 8.'
            );
        }

        if ($decimalSeparator === '') {
            gm_api_json(
                422,
                false,
                'Decimal separator is required.'
            );
        }

        $dupStmt = $pdo->prepare("
            SELECT id
            FROM currencies
            WHERE currency_code = :code
              AND id <> :id
            LIMIT 1
        ");

        $dupStmt->execute(
            array(
                ':code' => $code,
                ':id' => $id
            )
        );

        if ($dupStmt->fetchColumn()) {
            gm_api_json(
                422,
                false,
                'Currency code already exists.'
            );
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE currencies
                SET
                    currency_code = :currency_code,
                    currency_name = :currency_name,
                    symbol = :symbol,
                    symbol_position = :symbol_position,
                    decimal_places = :decimal_places,
                    decimal_separator = :decimal_separator,
                    thousand_separator = :thousand_separator,
                    is_active = :is_active
                WHERE id = :id
            ");

            $stmt->execute(
                array(
                    ':currency_code' =>
                        $code,
                    ':currency_name' =>
                        $name,
                    ':symbol' =>
                        $symbol,
                    ':symbol_position' =>
                        $position,
                    ':decimal_places' =>
                        $decimalPlaces,
                    ':decimal_separator' =>
                        $decimalSeparator,
                    ':thousand_separator' =>
                        $thousandSeparator,
                    ':is_active' =>
                        $isActive,
                    ':id' =>
                        $id
                )
            );

            gm_api_json(
                200,
                true,
                'Currency updated successfully.'
            );
        }

        $stmt = $pdo->prepare("
            INSERT INTO currencies (
                currency_code,
                currency_name,
                symbol,
                symbol_position,
                decimal_places,
                decimal_separator,
                thousand_separator,
                is_active
            ) VALUES (
                :currency_code,
                :currency_name,
                :symbol,
                :symbol_position,
                :decimal_places,
                :decimal_separator,
                :thousand_separator,
                :is_active
            )
        ");

        $stmt->execute(
            array(
                ':currency_code' =>
                    $code,
                ':currency_name' =>
                    $name,
                ':symbol' =>
                    $symbol,
                ':symbol_position' =>
                    $position,
                ':decimal_places' =>
                    $decimalPlaces,
                ':decimal_separator' =>
                    $decimalSeparator,
                ':thousand_separator' =>
                    $thousandSeparator,
                ':is_active' =>
                    $isActive
            )
        );

        gm_api_json(
            200,
            true,
            'Currency created successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CURRENCY STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === 'currency_status') {
        $id = (int) gm_api_post('id', '0');
        $isActive =
            gm_api_post('is_active', '0') === '1'
                ? 1
                : 0;

        if ($id <= 0) {
            gm_api_json(
                422,
                false,
                'Invalid currency.'
            );
        }

        $stmt = $pdo->prepare("
            UPDATE currencies
            SET is_active = :is_active
            WHERE id = :id
        ");

        $stmt->execute(
            array(
                ':is_active' => $isActive,
                ':id' => $id
            )
        );

        gm_api_json(
            200,
            true,
            $isActive
                ? 'Currency activated successfully.'
                : 'Currency deactivated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CURRENCY DELETE
    |--------------------------------------------------------------------------
    */

    if ($action === 'currency_delete') {
        $id = (int) gm_api_post('id', '0');

        if ($id <= 0) {
            gm_api_json(
                422,
                false,
                'Invalid currency.'
            );
        }

        $checks = array(
            "SELECT COUNT(*) FROM tenants WHERE currency_id = :id",
            "SELECT COUNT(*) FROM branches WHERE currency_id = :id",
            "SELECT COUNT(*) FROM plan_prices WHERE currency_id = :id",
            "SELECT COUNT(*) FROM payments WHERE currency_id = :id"
        );

        foreach ($checks as $sql) {
            try {
                $checkStmt = $pdo->prepare($sql);
                $checkStmt->execute(
                    array(':id' => $id)
                );

                if (
                    (int) $checkStmt->fetchColumn() > 0
                ) {
                    gm_api_json(
                        409,
                        false,
                        'This currency is already in use. Deactivate it instead of deleting.'
                    );
                }

            } catch (Throwable $ignore) {
                // Compatibility check only.
            }
        }

        $stmt = $pdo->prepare("
            DELETE FROM currencies
            WHERE id = :id
        ");

        $stmt->execute(
            array(':id' => $id)
        );

        gm_api_json(
            200,
            true,
            'Currency deleted successfully.'
        );
    }

    gm_api_json(
        400,
        false,
        'Invalid action.'
    );

} catch (Throwable $e) {
    error_log(
        'FieldPlx Country/Currency Master API Error: ' .
        $e->getMessage()
    );

    gm_api_json(
        500,
        false,
        'Unable to complete the requested action.'
    );
}
