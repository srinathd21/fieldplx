<?php
/**
 * FieldPlx Common Helper Functions
 *
 * File:
 * includes/functions.php
 *
 * Compatible with:
 * - PHP 7.2+
 * - MySQLi
 * - MySQL / MariaDB
 */

require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| General helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('redirect')) {
    function redirect($url, $statusCode = 302)
    {
        header(
            'Location: ' . (string) $url,
            true,
            (int) $statusCode
        );

        exit;
    }
}

if (!function_exists('isPostRequest')) {
    function isPostRequest()
    {
        return isset($_SERVER['REQUEST_METHOD']) &&
            strtoupper(
                (string) $_SERVER['REQUEST_METHOD']
            ) === 'POST';
    }
}

if (!function_exists('isGetRequest')) {
    function isGetRequest()
    {
        return isset($_SERVER['REQUEST_METHOD']) &&
            strtoupper(
                (string) $_SERVER['REQUEST_METHOD']
            ) === 'GET';
    }
}

if (!function_exists('isAjaxRequest')) {
    function isAjaxRequest()
    {
        if (
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower(
                (string) $_SERVER['HTTP_X_REQUESTED_WITH']
            ) === 'xmlhttprequest'
        ) {
            return true;
        }

        $accept = strtolower(
            (string) (
                isset($_SERVER['HTTP_ACCEPT'])
                    ? $_SERVER['HTTP_ACCEPT']
                    : ''
            )
        );

        $contentType = strtolower(
            (string) (
                isset($_SERVER['CONTENT_TYPE'])
                    ? $_SERVER['CONTENT_TYPE']
                    : ''
            )
        );

        return strpos(
            $accept,
            'application/json'
        ) !== false ||
        strpos(
            $contentType,
            'application/json'
        ) !== false;
    }
}

if (!function_exists('jsonResponse')) {
    function jsonResponse(
        $success,
        $message = '',
        $data = array(),
        $statusCode = 200,
        $extra = array()
    ) {
        http_response_code(
            (int) $statusCode
        );

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        $payload = array(
            'success' => (bool) $success,
            'message' => (string) $message,
            'data' => is_array($data)
                ? $data
                : array()
        );

        if (is_array($extra)) {
            foreach ($extra as $key => $value) {
                if (
                    !array_key_exists(
                        $key,
                        $payload
                    )
                ) {
                    $payload[$key] = $value;
                }
            }
        }

        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Input helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('postValue')) {
    function postValue(
        $key,
        $default = ''
    ) {
        if (!isset($_POST[$key])) {
            return $default;
        }

        return $_POST[$key];
    }
}

if (!function_exists('postString')) {
    function postString(
        $key,
        $default = ''
    ) {
        $value = postValue(
            $key,
            $default
        );

        if (is_array($value)) {
            return (string) $default;
        }

        return trim(
            (string) $value
        );
    }
}

if (!function_exists('postInt')) {
    function postInt(
        $key,
        $default = 0
    ) {
        $value = postValue(
            $key,
            null
        );

        if (
            is_array($value) ||
            $value === null ||
            $value === ''
        ) {
            return (int) $default;
        }

        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT
        );

        return $validated !== false
            ? (int) $validated
            : (int) $default;
    }
}

if (!function_exists('postFloat')) {
    function postFloat(
        $key,
        $default = 0.0
    ) {
        $value = postString(
            $key,
            ''
        );

        if ($value === '') {
            return (float) $default;
        }

        $value = str_replace(
            array(',', ' '),
            '',
            $value
        );

        return is_numeric($value)
            ? (float) $value
            : (float) $default;
    }
}

if (!function_exists('postCheckbox')) {
    function postCheckbox($key)
    {
        return isset($_POST[$key])
            ? 1
            : 0;
    }
}

if (!function_exists('getValue')) {
    function getValue(
        $key,
        $default = ''
    ) {
        if (!isset($_GET[$key])) {
            return $default;
        }

        return $_GET[$key];
    }
}

if (!function_exists('getString')) {
    function getString(
        $key,
        $default = ''
    ) {
        $value = getValue(
            $key,
            $default
        );

        if (is_array($value)) {
            return (string) $default;
        }

        return trim(
            (string) $value
        );
    }
}

if (!function_exists('getInt')) {
    function getInt(
        $key,
        $default = 0
    ) {
        $value = getValue(
            $key,
            null
        );

        if (
            is_array($value) ||
            $value === null ||
            $value === ''
        ) {
            return (int) $default;
        }

        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT
        );

        return $validated !== false
            ? (int) $validated
            : (int) $default;
    }
}

if (!function_exists('getJsonInput')) {
    function getJsonInput()
    {
        static $jsonInput = null;

        if ($jsonInput !== null) {
            return $jsonInput;
        }

        $rawInput = file_get_contents(
            'php://input'
        );

        if (
            $rawInput === false ||
            trim($rawInput) === ''
        ) {
            $jsonInput = array();
            return $jsonInput;
        }

        $decoded = json_decode(
            $rawInput,
            true
        );

        $jsonInput =
            json_last_error() === JSON_ERROR_NONE &&
            is_array($decoded)
                ? $decoded
                : array();

        return $jsonInput;
    }
}

/*
|--------------------------------------------------------------------------
| Validation helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('isValidEmail')) {
    function isValidEmail($email)
    {
        return filter_var(
            trim((string) $email),
            FILTER_VALIDATE_EMAIL
        ) !== false;
    }
}

if (!function_exists('isValidPhone')) {
    function isValidPhone($phone)
    {
        $phone = trim(
            (string) $phone
        );

        if ($phone === '') {
            return false;
        }

        return preg_match(
            '/^[0-9+\-\s()]{7,25}$/',
            $phone
        ) === 1;
    }
}

if (!function_exists('isValidDate')) {
    function isValidDate($date)
    {
        $date = (string) $date;

        $dateObject =
            DateTime::createFromFormat(
                'Y-m-d',
                $date
            );

        return $dateObject &&
            $dateObject->format(
                'Y-m-d'
            ) === $date;
    }
}

if (!function_exists('isValidDateTime')) {
    function isValidDateTime($dateTime)
    {
        $dateTime =
            (string) $dateTime;

        $dateObject =
            DateTime::createFromFormat(
                'Y-m-d H:i:s',
                $dateTime
            );

        return $dateObject &&
            $dateObject->format(
                'Y-m-d H:i:s'
            ) === $dateTime;
    }
}

if (!function_exists('isAllowedValue')) {
    function isAllowedValue(
        $value,
        $allowedValues
    ) {
        return is_array($allowedValues) &&
            in_array(
                $value,
                $allowedValues,
                true
            );
    }
}

/*
|--------------------------------------------------------------------------
| Session messages
|--------------------------------------------------------------------------
*/

if (!function_exists('setFlashMessage')) {
    function setFlashMessage(
        $type,
        $message
    ) {
        $_SESSION['flash_message'] =
            array(
                'type' =>
                    (string) $type,
                'message' =>
                    (string) $message
            );
    }
}

if (!function_exists('getFlashMessage')) {
    function getFlashMessage()
    {
        if (
            empty(
                $_SESSION[
                    'flash_message'
                ]
            )
        ) {
            return null;
        }

        $message =
            $_SESSION[
                'flash_message'
            ];

        unset(
            $_SESSION[
                'flash_message'
            ]
        );

        return $message;
    }
}

if (!function_exists('displayFlashMessage')) {
    function displayFlashMessage()
    {
        $flashMessage =
            getFlashMessage();

        if (!$flashMessage) {
            return;
        }

        $allowedTypes = array(
            'success',
            'danger',
            'warning',
            'info'
        );

        $type =
            isset($flashMessage['type'])
                ? (string)
                  $flashMessage['type']
                : 'info';

        if (
            !in_array(
                $type,
                $allowedTypes,
                true
            )
        ) {
            $type = 'info';
        }

        echo '<div class="alert alert-' .
            e($type) .
            ' alert-dismissible fade show" ' .
            'role="alert" data-auto-dismiss="4500">';

        echo e(
            isset($flashMessage['message'])
                ? $flashMessage['message']
                : ''
        );

        echo '<button type="button" class="btn-close" ';
        echo 'data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
    }
}

/*
|--------------------------------------------------------------------------
| Tenant and user helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('requireTenantId')) {
    function requireTenantId()
    {
        $tenantId =
            currentTenantId();

        if ($tenantId > 0) {
            return $tenantId;
        }

        if (isAjaxRequest()) {
            jsonResponse(
                false,
                'Tenant session is unavailable.',
                array(),
                401,
                array(
                    'error_code' =>
                        'tenant_session_missing'
                )
            );
        }

        http_response_code(401);
        exit(
            'Tenant session is unavailable.'
        );
    }
}

if (!function_exists('requireCurrentUserId')) {
    function requireCurrentUserId()
    {
        $userId =
            currentUserId();

        if ($userId > 0) {
            return $userId;
        }

        if (isAjaxRequest()) {
            jsonResponse(
                false,
                'User session is unavailable.',
                array(),
                401,
                array(
                    'error_code' =>
                        'user_session_missing'
                )
            );
        }

        http_response_code(401);
        exit(
            'User session is unavailable.'
        );
    }
}

if (!function_exists('recordBelongsToTenant')) {
    function recordBelongsToTenant(
        $table,
        $recordId,
        $idColumn = 'id',
        $tenantColumn = 'tenant_id'
    ) {
        global $conn;

        $allowedTables = array(
            'clients',
            'client_contacts',
            'properties',
            'requests',
            'assessments',
            'bookings',
            'jobs',
            'job_assignments',
            'work_orders',
            'visits',
            'tasks',
            'quotes',
            'invoices',
            'payments',
            'route_plans',
            'schedule_events',
            'attachments',
            'notes',
            'users',
            'roles',
            'branches'
        );

        if (
            !in_array(
                $table,
                $allowedTables,
                true
            )
        ) {
            return false;
        }

        if (
            !dbTableExists(
                $conn,
                $table
            ) ||
            !dbColumnExists(
                $conn,
                $table,
                $idColumn
            ) ||
            !dbColumnExists(
                $conn,
                $table,
                $tenantColumn
            )
        ) {
            return false;
        }

        $tenantId =
            currentTenantId();

        $recordId =
            (int) $recordId;

        if (
            $tenantId <= 0 ||
            $recordId <= 0
        ) {
            return false;
        }

        $safeTable =
            str_replace(
                '`',
                '``',
                $table
            );

        $safeIdColumn =
            str_replace(
                '`',
                '``',
                $idColumn
            );

        $safeTenantColumn =
            str_replace(
                '`',
                '``',
                $tenantColumn
            );

        $stmt = $conn->prepare("
            SELECT `{$safeIdColumn}`
            FROM `{$safeTable}`
            WHERE `{$safeIdColumn}` = ?
              AND `{$safeTenantColumn}` = ?
            LIMIT 1
        ");

        if (!$stmt) {
            dbLogError(
                'recordBelongsToTenant.prepare',
                $conn->error
            );

            return false;
        }

        $stmt->bind_param(
            'ii',
            $recordId,
            $tenantId
        );

        $stmt->execute();

        $exists =
            $stmt
            ->get_result()
            ->num_rows > 0;

        $stmt->close();

        return $exists;
    }
}

/*
|--------------------------------------------------------------------------
| Module, feature and limit wrappers
|--------------------------------------------------------------------------
*/

if (!function_exists('hasModuleAccess')) {
    function hasModuleAccess($moduleCode)
    {
        return function_exists(
            'tenantHasModule'
        )
            ? tenantHasModule(
                $moduleCode
            )
            : false;
    }
}

if (!function_exists('requireModuleAccess')) {
    function requireModuleAccess(
        $moduleCode,
        $message = ''
    ) {
        if (
            function_exists(
                'requireTenantModule'
            )
        ) {
            return requireTenantModule(
                $moduleCode,
                $message
            );
        }

        if (
            !hasModuleAccess(
                $moduleCode
            )
        ) {
            http_response_code(403);
            exit(
                $message !== ''
                    ? $message
                    : 'This module is not available.'
            );
        }

        return true;
    }
}

if (!function_exists('hasFeatureAccess')) {
    function hasFeatureAccess(
        $moduleCode,
        $featureCode
    ) {
        return function_exists(
            'tenantHasFeature'
        )
            ? tenantHasFeature(
                $moduleCode,
                $featureCode
            )
            : false;
    }
}

if (!function_exists('requireFeatureAccess')) {
    function requireFeatureAccess(
        $moduleCode,
        $featureCode,
        $message = ''
    ) {
        if (
            function_exists(
                'requireTenantFeature'
            )
        ) {
            return requireTenantFeature(
                $moduleCode,
                $featureCode,
                $message
            );
        }

        if (
            !hasFeatureAccess(
                $moduleCode,
                $featureCode
            )
        ) {
            http_response_code(403);
            exit(
                $message !== ''
                    ? $message
                    : 'This feature is not available.'
            );
        }

        return true;
    }
}

if (!function_exists('tenantLimit')) {
    function tenantLimit(
        $limitCode,
        $default = null
    ) {
        return function_exists(
            'getTenantLimit'
        )
            ? getTenantLimit(
                $limitCode,
                $default
            )
            : $default;
    }
}

if (!function_exists('requireLimitAvailable')) {
    function requireLimitAvailable(
        $limitCode,
        $currentUsage,
        $message = ''
    ) {
        if (
            function_exists(
                'requireTenantLimitAvailable'
            )
        ) {
            return requireTenantLimitAvailable(
                $limitCode,
                $currentUsage,
                $message
            );
        }

        $limit =
            tenantLimit(
                $limitCode,
                null
            );

        if (
            $limit !== null &&
            is_numeric($limit) &&
            (int) $currentUsage >=
                (int) $limit
        ) {
            http_response_code(403);

            exit(
                $message !== ''
                    ? $message
                    : 'The workspace limit has been reached.'
            );
        }

        return true;
    }
}

/*
|--------------------------------------------------------------------------
| Formatting
|--------------------------------------------------------------------------
*/

if (!function_exists('currentCurrencyCode')) {
    function currentCurrencyCode()
    {
        return !empty(
            $_SESSION[
                'currency_code'
            ]
        )
            ? strtoupper(
                (string)
                $_SESSION[
                    'currency_code'
                ]
            )
            : 'INR';
    }
}

if (!function_exists('currencySymbol')) {
    function currencySymbol(
        $currencyCode = null
    ) {
        if (
            $currencyCode === null ||
            $currencyCode === ''
        ) {
            $currencyCode =
                currentCurrencyCode();
        }

        $symbols = array(
            'INR' => '₹',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'AED' => 'د.إ',
            'AUD' => 'A$',
            'CAD' => 'C$',
            'SGD' => 'S$'
        );

        $currencyCode =
            strtoupper(
                (string)
                $currencyCode
            );

        return isset(
            $symbols[$currencyCode]
        )
            ? $symbols[$currencyCode]
            : $currencyCode . ' ';
    }
}

if (!function_exists('formatMoney')) {
    function formatMoney(
        $amount,
        $showCurrencyCode = false,
        $decimals = 2
    ) {
        $amount = is_numeric($amount)
            ? (float) $amount
            : 0.0;

        $decimals = max(
            0,
            min(
                4,
                (int) $decimals
            )
        );

        $currencyCode =
            currentCurrencyCode();

        $formattedAmount =
            number_format(
                $amount,
                $decimals,
                '.',
                ','
            );

        if ($showCurrencyCode) {
            return $currencyCode .
                ' ' .
                $formattedAmount;
        }

        return currencySymbol(
            $currencyCode
        ) . $formattedAmount;
    }
}

if (!function_exists('currentDateFormat')) {
    function currentDateFormat()
    {
        return !empty(
            $_SESSION[
                'date_format'
            ]
        )
            ? (string)
              $_SESSION[
                'date_format'
              ]
            : 'd-m-Y';
    }
}

if (!function_exists('formatDate')) {
    function formatDate(
        $date,
        $format = null
    ) {
        if (empty($date)) {
            return '-';
        }

        $timestamp =
            strtotime(
                (string) $date
            );

        if ($timestamp === false) {
            return '-';
        }

        if (
            $format === null ||
            $format === ''
        ) {
            $format =
                currentDateFormat();
        }

        return date(
            $format,
            $timestamp
        );
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime(
        $dateTime,
        $format = null
    ) {
        if (empty($dateTime)) {
            return '-';
        }

        $timestamp =
            strtotime(
                (string) $dateTime
            );

        if ($timestamp === false) {
            return '-';
        }

        if (
            $format === null ||
            $format === ''
        ) {
            $format =
                currentDateFormat() .
                ' h:i A';
        }

        return date(
            $format,
            $timestamp
        );
    }
}

if (!function_exists('toDatabaseDateTime')) {
    function toDatabaseDateTime($dateTime)
    {
        $dateTime =
            trim(
                (string) $dateTime
            );

        if ($dateTime === '') {
            return null;
        }

        $timestamp =
            strtotime($dateTime);

        return $timestamp === false
            ? null
            : date(
                'Y-m-d H:i:s',
                $timestamp
            );
    }
}

if (!function_exists('toDateTimeLocal')) {
    function toDateTimeLocal($dateTime)
    {
        if (empty($dateTime)) {
            return '';
        }

        $timestamp =
            strtotime(
                (string) $dateTime
            );

        return $timestamp === false
            ? ''
            : date(
                'Y-m-d\TH:i',
                $timestamp
            );
    }
}

if (!function_exists('timeAgo')) {
    function timeAgo($dateTime)
    {
        if (empty($dateTime)) {
            return '';
        }

        $timestamp =
            strtotime(
                (string) $dateTime
            );

        if ($timestamp === false) {
            return '';
        }

        $difference =
            time() - $timestamp;

        if ($difference < 0) {
            return formatDateTime(
                $dateTime
            );
        }

        if ($difference < 60) {
            return 'Just now';
        }

        if ($difference < 3600) {
            $minutes =
                (int) floor(
                    $difference / 60
                );

            return $minutes .
                ' minute' .
                (
                    $minutes !== 1
                        ? 's'
                        : ''
                ) .
                ' ago';
        }

        if ($difference < 86400) {
            $hours =
                (int) floor(
                    $difference / 3600
                );

            return $hours .
                ' hour' .
                (
                    $hours !== 1
                        ? 's'
                        : ''
                ) .
                ' ago';
        }

        if ($difference < 604800) {
            $days =
                (int) floor(
                    $difference / 86400
                );

            return $days .
                ' day' .
                (
                    $days !== 1
                        ? 's'
                        : ''
                ) .
                ' ago';
        }

        return formatDateTime(
            $dateTime
        );
    }
}

/*
|--------------------------------------------------------------------------
| Status helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('statusLabel')) {
    function statusLabel($status)
    {
        return ucwords(
            str_replace(
                array('_', '-'),
                ' ',
                trim(
                    (string) $status
                )
            )
        );
    }
}

if (!function_exists('statusBadgeClass')) {
    function statusBadgeClass($status)
    {
        $status =
            strtolower(
                trim(
                    (string) $status
                )
            );

        $groups = array(
            'bg-success-subtle text-success' =>
                array(
                    'active',
                    'completed',
                    'approved',
                    'accepted',
                    'paid',
                    'succeeded',
                    'delivered',
                    'converted',
                    'closed'
                ),
            'bg-warning-subtle text-warning' =>
                array(
                    'pending',
                    'draft',
                    'scheduled',
                    'partially_paid',
                    'needs_review',
                    'awaiting_response',
                    'on_hold',
                    'submitted'
                ),
            'bg-danger-subtle text-danger' =>
                array(
                    'cancelled',
                    'rejected',
                    'failed',
                    'suspended',
                    'overdue',
                    'declined',
                    'missed'
                ),
            'bg-info-subtle text-info' =>
                array(
                    'in_progress',
                    'issued',
                    'sent',
                    'viewed',
                    'assigned',
                    'dispatched',
                    'on_my_way'
                )
        );

        foreach (
            $groups as
            $className =>
            $statuses
        ) {
            if (
                in_array(
                    $status,
                    $statuses,
                    true
                )
            ) {
                return $className;
            }
        }

        return
            'bg-secondary-subtle text-secondary';
    }
}

if (!function_exists('statusBadge')) {
    function statusBadge($status)
    {
        return '<span class="badge ' .
            e(
                statusBadgeClass(
                    $status
                )
            ) .
            '">' .
            e(
                statusLabel(
                    $status
                )
            ) .
            '</span>';
    }
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

if (!function_exists('getPagination')) {
    function getPagination(
        $totalRecords,
        $perPage = 20,
        $currentPage = 1
    ) {
        $totalRecords = max(
            0,
            (int) $totalRecords
        );

        $perPage = max(
            1,
            (int) $perPage
        );

        $totalPages = max(
            1,
            (int) ceil(
                $totalRecords /
                $perPage
            )
        );

        $currentPage = max(
            1,
            min(
                (int) $currentPage,
                $totalPages
            )
        );

        return array(
            'total_records' =>
                $totalRecords,
            'per_page' =>
                $perPage,
            'current_page' =>
                $currentPage,
            'total_pages' =>
                $totalPages,
            'offset' =>
                ($currentPage - 1) *
                $perPage,
            'has_previous' =>
                $currentPage > 1,
            'has_next' =>
                $currentPage <
                $totalPages,
            'previous_page' =>
                max(
                    1,
                    $currentPage - 1
                ),
            'next_page' =>
                min(
                    $totalPages,
                    $currentPage + 1
                )
        );
    }
}

if (!function_exists('buildQueryUrl')) {
    function buildQueryUrl($parameters)
    {
        $query = $_GET;

        foreach (
            (array) $parameters as
            $key =>
            $value
        ) {
            if (
                $value === null ||
                $value === ''
            ) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        $path =
            isset($_SERVER['PHP_SELF'])
                ? (string)
                  $_SERVER['PHP_SELF']
                : '';

        $queryString =
            http_build_query($query);

        return $path .
            (
                $queryString !== ''
                    ? '?' . $queryString
                    : ''
            );
    }
}

/*
|--------------------------------------------------------------------------
| Database helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('bindStatementParams')) {
    function bindStatementParams(
        $stmt,
        $types,
        &$params
    ) {
        if (
            function_exists(
                'dbBindParams'
            )
        ) {
            return dbBindParams(
                $stmt,
                $types,
                $params
            );
        }

        if (
            $types === '' ||
            empty($params)
        ) {
            return true;
        }

        $bindParams =
            array($types);

        foreach (
            $params as
            $key =>
            $value
        ) {
            $bindParams[] =
                &$params[$key];
        }

        return call_user_func_array(
            array(
                $stmt,
                'bind_param'
            ),
            $bindParams
        );
    }
}

if (!function_exists('dbFetchOne')) {
    function dbFetchOne(
        $sql,
        $types = '',
        $params = array()
    ) {
        global $conn;

        try {
            $stmt =
                $conn->prepare($sql);

            if (!$stmt) {
                throw new Exception(
                    $conn->error
                );
            }

            if (
                $types !== '' &&
                !empty($params)
            ) {
                bindStatementParams(
                    $stmt,
                    $types,
                    $params
                );
            }

            $stmt->execute();

            $row =
                $stmt
                ->get_result()
                ->fetch_assoc();

            $stmt->close();

            return $row ?: null;
        } catch (Exception $exception) {
            dbLogError(
                'dbFetchOne',
                $exception
            );

            return null;
        }
    }
}

if (!function_exists('dbFetchAll')) {
    function dbFetchAll(
        $sql,
        $types = '',
        $params = array()
    ) {
        global $conn;

        $rows = array();

        try {
            $stmt =
                $conn->prepare($sql);

            if (!$stmt) {
                throw new Exception(
                    $conn->error
                );
            }

            if (
                $types !== '' &&
                !empty($params)
            ) {
                bindStatementParams(
                    $stmt,
                    $types,
                    $params
                );
            }

            $stmt->execute();

            $result =
                $stmt->get_result();

            while (
                $row =
                $result->fetch_assoc()
            ) {
                $rows[] = $row;
            }

            $stmt->close();
        } catch (Exception $exception) {
            dbLogError(
                'dbFetchAll',
                $exception
            );
        }

        return $rows;
    }
}

if (!function_exists('dbExecute')) {
    function dbExecute(
        $sql,
        $types = '',
        $params = array()
    ) {
        global $conn;

        try {
            $stmt =
                $conn->prepare($sql);

            if (!$stmt) {
                throw new Exception(
                    $conn->error
                );
            }

            if (
                $types !== '' &&
                !empty($params)
            ) {
                bindStatementParams(
                    $stmt,
                    $types,
                    $params
                );
            }

            $success =
                $stmt->execute();

            $response = array(
                'success' =>
                    $success,
                'insert_id' =>
                    $success
                        ? (int)
                          $stmt->insert_id
                        : 0,
                'affected_rows' =>
                    $success
                        ? (int)
                          $stmt->affected_rows
                        : 0,
                'error' =>
                    $success
                        ? ''
                        : 'Database operation failed.'
            );

            if (!$success) {
                dbLogError(
                    'dbExecute.execute',
                    $stmt->error
                );
            }

            $stmt->close();

            return $response;
        } catch (Exception $exception) {
            dbLogError(
                'dbExecute',
                $exception
            );

            return array(
                'success' => false,
                'insert_id' => 0,
                'affected_rows' => 0,
                'error' =>
                    'Database operation failed.'
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Number generation
|--------------------------------------------------------------------------
*/

if (!function_exists('generateDocumentNumber')) {
    function generateDocumentNumber(
        $documentType
    ) {
        global $conn;

        $tenantId =
            currentTenantId();

        $documentType =
            trim(
                (string) $documentType
            );

        if (
            $tenantId <= 0 ||
            $documentType === ''
        ) {
            return null;
        }

        try {
            return dbTransaction(
                $conn,
                function (
                    mysqli $connection
                ) use (
                    $tenantId,
                    $documentType
                ) {
                    $stmt =
                        $connection->prepare("
                            SELECT
                                id,
                                prefix,
                                next_number,
                                padding_length,
                                reset_frequency,
                                last_reset_period
                            FROM tenant_number_sequences
                            WHERE tenant_id = ?
                              AND document_type = ?
                            LIMIT 1
                            FOR UPDATE
                        ");

                    if (!$stmt) {
                        throw new Exception(
                            $connection->error
                        );
                    }

                    $stmt->bind_param(
                        'is',
                        $tenantId,
                        $documentType
                    );

                    $stmt->execute();

                    $sequence =
                        $stmt
                        ->get_result()
                        ->fetch_assoc();

                    $stmt->close();

                    if (!$sequence) {
                        throw new Exception(
                            'Number sequence is not configured.'
                        );
                    }

                    $currentPeriod = null;

                    $frequency =
                        strtolower(
                            trim(
                                (string)
                                $sequence[
                                    'reset_frequency'
                                ]
                            )
                        );

                    if ($frequency === 'yearly') {
                        $currentPeriod =
                            date('Y');
                    } elseif (
                        $frequency === 'monthly'
                    ) {
                        $currentPeriod =
                            date('Y-m');
                    }

                    $nextNumber =
                        max(
                            1,
                            (int)
                            $sequence[
                                'next_number'
                            ]
                        );

                    $lastResetPeriod =
                        isset(
                            $sequence[
                                'last_reset_period'
                            ]
                        )
                            ? (string)
                              $sequence[
                                'last_reset_period'
                              ]
                            : '';

                    if (
                        $currentPeriod !== null &&
                        $lastResetPeriod !==
                            $currentPeriod
                    ) {
                        $nextNumber = 1;
                    }

                    $number =
                        (string)
                        $sequence['prefix'] .
                        str_pad(
                            (string)
                            $nextNumber,
                            max(
                                1,
                                (int)
                                $sequence[
                                    'padding_length'
                                ]
                            ),
                            '0',
                            STR_PAD_LEFT
                        );

                    $newNextNumber =
                        $nextNumber + 1;

                    $sequenceId =
                        (int)
                        $sequence['id'];

                    $updateStmt =
                        $connection->prepare("
                            UPDATE tenant_number_sequences
                            SET
                                next_number = ?,
                                last_reset_period = ?
                            WHERE id = ?
                              AND tenant_id = ?
                        ");

                    if (!$updateStmt) {
                        throw new Exception(
                            $connection->error
                        );
                    }

                    $updateStmt->bind_param(
                        'isii',
                        $newNextNumber,
                        $currentPeriod,
                        $sequenceId,
                        $tenantId
                    );

                    $updateStmt->execute();
                    $updateStmt->close();

                    return $number;
                }
            );
        } catch (Exception $exception) {
            dbLogError(
                'generateDocumentNumber',
                $exception
            );

            return null;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Audit log
|--------------------------------------------------------------------------
*/

if (!function_exists('writeAuditLog')) {
    function writeAuditLog(
        $action,
        $objectType,
        $objectId = null,
        $oldValues = null,
        $newValues = null,
        $description = ''
    ) {
        global $conn;

        $tenantId =
            currentTenantId();

        $userId =
            currentUserId();

        if ($tenantId <= 0) {
            return false;
        }

        if (
            !dbTableExists(
                $conn,
                'audit_logs'
            )
        ) {
            try {
                $conn->query("
                    CREATE TABLE IF NOT EXISTS `audit_logs` (
                        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                        `tenant_id` BIGINT(20) UNSIGNED NOT NULL,
                        `user_id` BIGINT(20) UNSIGNED DEFAULT NULL,
                        `action` VARCHAR(120) NOT NULL,
                        `object_type` VARCHAR(120) DEFAULT NULL,
                        `object_id` BIGINT(20) UNSIGNED DEFAULT NULL,
                        `description` VARCHAR(1000) DEFAULT NULL,
                        `old_values` LONGTEXT DEFAULT NULL,
                        `new_values` LONGTEXT DEFAULT NULL,
                        `ip_address` VARCHAR(64) DEFAULT NULL,
                        `user_agent` VARCHAR(500) DEFAULT NULL,
                        `request_method` VARCHAR(20) DEFAULT NULL,
                        `request_url` VARCHAR(1000) DEFAULT NULL,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        KEY `idx_audit_tenant_date`
                            (`tenant_id`, `created_at`),
                        KEY `idx_audit_user`
                            (`user_id`),
                        KEY `idx_audit_action`
                            (`action`)
                    ) ENGINE=InnoDB
                      DEFAULT CHARSET=utf8mb4
                      COLLATE=utf8mb4_unicode_ci
                ");
            } catch (Exception $exception) {
                dbLogError(
                    'writeAuditLog.createTable',
                    $exception
                );

                return false;
            }
        }

        $oldJson =
            is_array($oldValues)
                ? json_encode(
                    $oldValues,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
                : (
                    $oldValues === null
                        ? null
                        : (string)
                          $oldValues
                );

        $newJson =
            is_array($newValues)
                ? json_encode(
                    $newValues,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
                : (
                    $newValues === null
                        ? null
                        : (string)
                          $newValues
                );

        $ipAddress =
            isset($_SERVER['REMOTE_ADDR'])
                ? substr(
                    (string)
                    $_SERVER['REMOTE_ADDR'],
                    0,
                    64
                )
                : null;

        $userAgent =
            isset($_SERVER['HTTP_USER_AGENT'])
                ? substr(
                    (string)
                    $_SERVER['HTTP_USER_AGENT'],
                    0,
                    500
                )
                : null;

        $requestMethod =
            isset($_SERVER['REQUEST_METHOD'])
                ? substr(
                    (string)
                    $_SERVER['REQUEST_METHOD'],
                    0,
                    20
                )
                : null;

        $requestUrl =
            isset($_SERVER['REQUEST_URI'])
                ? substr(
                    (string)
                    $_SERVER['REQUEST_URI'],
                    0,
                    1000
                )
                : null;

        try {
            $stmt =
                $conn->prepare("
                    INSERT INTO audit_logs (
                        tenant_id,
                        user_id,
                        action,
                        object_type,
                        object_id,
                        description,
                        old_values,
                        new_values,
                        ip_address,
                        user_agent,
                        request_method,
                        request_url,
                        created_at
                    ) VALUES (
                        ?,
                        NULLIF(?, 0),
                        ?,
                        ?,
                        NULLIF(?, 0),
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        NOW()
                    )
                ");

            if (!$stmt) {
                throw new Exception(
                    $conn->error
                );
            }

            $objectIdValue =
                $objectId === null
                    ? 0
                    : (int) $objectId;

            $stmt->bind_param(
                'iississsssss',
                $tenantId,
                $userId,
                $action,
                $objectType,
                $objectIdValue,
                $description,
                $oldJson,
                $newJson,
                $ipAddress,
                $userAgent,
                $requestMethod,
                $requestUrl
            );

            $success =
                $stmt->execute();

            if (!$success) {
                dbLogError(
                    'writeAuditLog.execute',
                    $stmt->error
                );
            }

            $stmt->close();

            return $success;
        } catch (Exception $exception) {
            dbLogError(
                'writeAuditLog',
                $exception
            );

            return false;
        }
    }
}
