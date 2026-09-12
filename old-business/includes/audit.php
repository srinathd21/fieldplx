<?php
/*
|--------------------------------------------------------------------------
| FieldPlx Tenant Audit Helper
|--------------------------------------------------------------------------
|
| Central audit helper for FieldPlx.
|
| Supports:
| - Login / Logout / Failed Login
| - Employee Changes
| - Role & Permission Changes
| - Job / Work Order Changes
| - Status Changes
| - Assignment / Reassignment
| - Service Request Changes
| - Schedule Changes
| - Invoice / Payment Changes
| - Mobile Check-In / Check-Out
|
| IMPORTANT:
| Passwords, hashes, tokens, API keys and credentials are NEVER logged.
|
| Compatible with PHP 7.2+
|
*/

if (!function_exists('tenantAuditClientIp')) {

    /*
    |--------------------------------------------------------------------------
    | Client IP
    |--------------------------------------------------------------------------
    */
    function tenantAuditClientIp()
    {
        /*
         * REMOTE_ADDR is intentionally used.
         *
         * Do not trust X-Forwarded-For unless your application is behind
         * a configured/trusted reverse proxy.
         */
        if (!isset($_SERVER['REMOTE_ADDR'])) {
            return null;
        }

        $ip = trim((string)$_SERVER['REMOTE_ADDR']);

        return $ip !== ''
            ? substr($ip, 0, 80)
            : null;
    }


    /*
    |--------------------------------------------------------------------------
    | User Agent
    |--------------------------------------------------------------------------
    */
    function tenantAuditUserAgent()
    {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return null;
        }

        $userAgent = trim(
            (string)$_SERVER['HTTP_USER_AGENT']
        );

        return $userAgent !== ''
            ? substr($userAgent, 0, 500)
            : null;
    }


    /*
    |--------------------------------------------------------------------------
    | Device Type
    |--------------------------------------------------------------------------
    */
    function tenantAuditDeviceType()
    {
        $ua = strtolower(
            (string)(
                isset($_SERVER['HTTP_USER_AGENT'])
                    ? $_SERVER['HTTP_USER_AGENT']
                    : ''
            )
        );

        if ($ua === '') {
            return 'unknown';
        }

        if (
            strpos($ua, 'ipad') !== false ||
            strpos($ua, 'tablet') !== false ||
            strpos($ua, 'kindle') !== false
        ) {
            return 'tablet';
        }

        if (
            strpos($ua, 'mobile') !== false ||
            strpos($ua, 'iphone') !== false ||
            strpos($ua, 'android') !== false
        ) {
            return 'mobile';
        }

        return 'desktop';
    }


    /*
    |--------------------------------------------------------------------------
    | Limit text safely
    |--------------------------------------------------------------------------
    */
    function tenantAuditText($value, $limit)
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        return substr(
            $value,
            0,
            (int)$limit
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Sensitive field detection
    |--------------------------------------------------------------------------
    */
    function tenantAuditIsSensitiveKey($key)
    {
        $key = strtolower(
            trim((string)$key)
        );

        $sensitiveKeys = array(
            'password',
            'password_hash',
            'current_password',
            'old_password',
            'new_password',
            'confirm_password',

            'token',
            'access_token',
            'refresh_token',
            'auth_token',
            'csrf_token',

            'api_key',
            'apikey',
            'secret',
            'client_secret',

            'authorization',
            'cookie',

            'credentials',
            'credentials_encrypted',

            'password_encrypted',

            'session_id'
        );

        return in_array(
            $key,
            $sensitiveKeys,
            true
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Recursive audit sanitization
    |--------------------------------------------------------------------------
    |
    | This is important because sensitive values may exist inside nested
    | arrays such as:
    |
    | [
    |     'employee' => [
    |         'password_hash' => '...'
    |     ]
    | ]
    |
    */
    function tenantAuditSanitize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $clean = array();

        foreach ($value as $key => $item) {

            if (
                is_string($key) &&
                tenantAuditIsSensitiveKey($key)
            ) {
                continue;
            }

            if (is_array($item)) {
                $clean[$key] =
                    tenantAuditSanitize($item);
            } else {
                $clean[$key] = $item;
            }
        }

        return $clean;
    }


    /*
    |--------------------------------------------------------------------------
    | Audit JSON
    |--------------------------------------------------------------------------
    */
    function tenantAuditJson($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value)) {
            $value = (array)$value;
        }

        if (!is_array($value)) {
            $value = array(
                'value' => $value
            );
        }

        $value =
            tenantAuditSanitize($value);

        $flags =
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES;

        /*
         * Available in PHP 7.2+
         */
        if (
            defined(
                'JSON_INVALID_UTF8_SUBSTITUTE'
            )
        ) {
            $flags |=
                JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $json =
            json_encode(
                $value,
                $flags
            );

        return $json === false
            ? null
            : $json;
    }


    /*
    |--------------------------------------------------------------------------
    | Check audit_logs table
    |--------------------------------------------------------------------------
    */
    function tenantAuditTableExists(PDO $pdo)
    {
        static $cache = array();

        $connectionKey =
            spl_object_hash($pdo);

        if (
            array_key_exists(
                $connectionKey,
                $cache
            )
        ) {
            return $cache[$connectionKey];
        }

        try {

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'audit_logs'
            ");

            $stmt->execute();

            $cache[$connectionKey] =
                ((int)$stmt->fetchColumn() > 0);

        } catch (Throwable $e) {

            $cache[$connectionKey] = false;
        }

        return $cache[$connectionKey];
    }


    /*
    |--------------------------------------------------------------------------
    | Resolve User Name
    |--------------------------------------------------------------------------
    |
    | Audit records keep a user-name snapshot.
    | This means the history still displays correctly even when an employee
    | changes their name later.
    |
    */
    function tenantAuditResolveUserName(
        PDO $pdo,
        $tenantId,
        $userId
    ) {
        if (
            $userId === null ||
            (int)$userId <= 0
        ) {
            return null;
        }

        static $cache = array();

        $cacheKey =
            (string)$tenantId .
            ':' .
            (string)$userId;

        if (
            array_key_exists(
                $cacheKey,
                $cache
            )
        ) {
            return $cache[$cacheKey];
        }

        try {

            $sql = "
                SELECT
                    first_name,
                    last_name
                FROM users
                WHERE id = :user_id
            ";

            $params = array(
                ':user_id' => (int)$userId
            );

            if (
                $tenantId !== null &&
                (int)$tenantId > 0
            ) {
                $sql .= "
                    AND tenant_id = :tenant_id
                ";

                $params[':tenant_id'] =
                    (int)$tenantId;
            }

            $sql .= "
                LIMIT 1
            ";

            $stmt =
                $pdo->prepare($sql);

            $stmt->execute($params);

            $row =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$row) {
                $cache[$cacheKey] = null;
                return null;
            }

            $name = trim(
                (string)$row['first_name'] .
                ' ' .
                (string)$row['last_name']
            );

            $cache[$cacheKey] =
                $name !== ''
                    ? substr($name, 0, 190)
                    : null;

            return $cache[$cacheKey];

        } catch (Throwable $e) {

            return null;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Determine Audit Module
    |--------------------------------------------------------------------------
    */
    function tenantAuditInferModule(
        $action,
        $objectType
    ) {
        $actionUpper =
            strtoupper(
                (string)$action
            );

        $type =
            strtolower(
                (string)$objectType
            );

        if (
            strpos(
                $actionUpper,
                'LOGIN'
            ) !== false ||
            strpos(
                $actionUpper,
                'LOGOUT'
            ) !== false ||
            strpos(
                $actionUpper,
                'SESSION'
            ) !== false ||
            in_array(
                $type,
                array(
                    'authentication',
                    'tenant_login',
                    'tenant_session',
                    'platform_login',
                    'platform_session',
                    'role',
                    'permission',
                    'branch',
                    'department',
                    'tenant',
                    'company_setting',
                    'platform_setting',
                    'document_sequence'
                ),
                true
            )
        ) {
            return 'Administration';
        }

        if (
            in_array(
                $type,
                array(
                    'employee',
                    'user'
                ),
                true
            )
        ) {
            return 'Employees';
        }

        if (
            in_array(
                $type,
                array(
                    'job',
                    'work_order',
                    'job_assignment'
                ),
                true
            )
        ) {
            return 'Jobs / Work Orders';
        }

        if (
            in_array(
                $type,
                array(
                    'service_request',
                    'request'
                ),
                true
            )
        ) {
            return 'Service Requests';
        }

        if (
            in_array(
                $type,
                array(
                    'invoice',
                    'payment',
                    'refund'
                ),
                true
            )
        ) {
            return 'Invoices';
        }

        if (
            in_array(
                $type,
                array(
                    'schedule',
                    'job_schedule',
                    'calendar_event',
                    'visit'
                ),
                true
            )
        ) {
            return 'Schedule';
        }

        if (
            in_array(
                $type,
                array(
                    'location_event',
                    'mobile_check_in',
                    'mobile_check_out'
                ),
                true
            ) ||
            strpos(
                $actionUpper,
                'CHECK_IN'
            ) !== false ||
            strpos(
                $actionUpper,
                'CHECK_OUT'
            ) !== false
        ) {
            return 'Mobile Workforce';
        }

        if ($type === 'quote') {
            return 'Quotes';
        }

        if ($type === 'assessment') {
            return 'Assessments';
        }

        return ucwords(
            str_replace(
                '_',
                ' ',
                $type !== ''
                    ? $type
                    : 'General'
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Determine Audit Category
    |--------------------------------------------------------------------------
    */
    function tenantAuditInferCategory(
        $action,
        $objectType
    ) {
        $actionUpper =
            strtoupper(
                (string)$action
            );

        $type =
            strtolower(
                (string)$objectType
            );

        /*
         * Login / Logout
         */
        if (
            strpos(
                $actionUpper,
                'LOGIN'
            ) !== false ||
            strpos(
                $actionUpper,
                'LOGOUT'
            ) !== false ||
            strpos(
                $actionUpper,
                'SESSION'
            ) !== false
        ) {
            return 'LOGIN_LOGOUT_ACTIVITY';
        }


        /*
         * Mobile Check In / Out
         */
        if (
            strpos(
                $actionUpper,
                'CHECK_IN'
            ) !== false ||
            strpos(
                $actionUpper,
                'CHECK_OUT'
            ) !== false ||
            in_array(
                $type,
                array(
                    'mobile_check_in',
                    'mobile_check_out'
                ),
                true
            )
        ) {
            return 'MOBILE_CHECK_IN_OUT';
        }


        /*
         * Assignment / Reassignment
         */
        if (
            strpos(
                $actionUpper,
                'REASSIGN'
            ) !== false ||
            strpos(
                $actionUpper,
                'ASSIGNMENT'
            ) !== false ||
            strpos(
                $actionUpper,
                'ASSIGNED'
            ) !== false
        ) {
            return 'ASSIGNMENTS_REASSIGNMENTS';
        }


        /*
         * Status Changes
         */
        if (
            strpos(
                $actionUpper,
                'STATUS'
            ) !== false
        ) {
            return 'STATUS_CHANGES';
        }


        /*
         * Employee
         */
        if (
            $type === 'employee' ||
            strpos(
                $actionUpper,
                'EMPLOYEE_'
            ) === 0
        ) {
            return 'EMPLOYEE_CHANGES';
        }


        /*
         * Role / Permissions
         */
        if (
            $type === 'role' ||
            $type === 'permission' ||
            strpos(
                $actionUpper,
                'ROLE_'
            ) === 0 ||
            strpos(
                $actionUpper,
                'PERMISSION_'
            ) === 0
        ) {
            return 'ROLE_PERMISSION_CHANGES';
        }


        /*
         * Invoice / Payment
         */
        if (
            in_array(
                $type,
                array(
                    'invoice',
                    'payment',
                    'refund'
                ),
                true
            ) ||
            strpos(
                $actionUpper,
                'INVOICE_'
            ) === 0 ||
            strpos(
                $actionUpper,
                'PAYMENT_'
            ) === 0
        ) {
            return 'INVOICE_CHANGES';
        }


        /*
         * Jobs
         */
        if (
            in_array(
                $type,
                array(
                    'job',
                    'work_order'
                ),
                true
            )
        ) {
            return 'JOB_CREATION_UPDATES';
        }


        /*
         * Service Request
         */
        if (
            in_array(
                $type,
                array(
                    'service_request',
                    'request'
                ),
                true
            )
        ) {
            return 'SERVICE_REQUEST_CHANGES';
        }


        /*
         * Schedule
         */
        if (
            in_array(
                $type,
                array(
                    'schedule',
                    'job_schedule',
                    'calendar_event',
                    'visit'
                ),
                true
            )
        ) {
            return 'SCHEDULE_CHANGES';
        }


        /*
         * Administration
         */
        if (
            in_array(
                $type,
                array(
                    'branch',
                    'department',
                    'tenant',
                    'company_setting',
                    'platform_setting',
                    'document_sequence'
                ),
                true
            )
        ) {
            return 'ADMINISTRATION_CHANGES';
        }


        return 'GENERAL_ACTIVITY';
    }


    /*
    |--------------------------------------------------------------------------
    | Resolve human-readable Record Number
    |--------------------------------------------------------------------------
    |
    | Converts:
    |
    | job #25          -> JOB-000025
    | invoice #4       -> INV-2026-0004
    | request #7       -> REQ-000007
    |
    */
    function tenantAuditResolveRecordNo(
        PDO $pdo,
        $tenantId,
        $objectType,
        $objectId
    ) {
        if (
            $objectId === null ||
            (int)$objectId <= 0
        ) {
            return null;
        }

        $type =
            strtolower(
                trim(
                    (string)$objectType
                )
            );

        $map = array(

            'job' => array(
                'table' => 'jobs',
                'column' => 'job_no'
            ),

            'work_order' => array(
                'table' => 'work_orders',
                'column' => 'work_order_no'
            ),

            'service_request' => array(
                'table' => 'service_requests',
                'column' => 'request_no'
            ),

            'request' => array(
                'table' => 'service_requests',
                'column' => 'request_no'
            ),

            'invoice' => array(
                'table' => 'invoices',
                'column' => 'invoice_no'
            ),

            'payment' => array(
                'table' => 'payments',
                'column' => 'payment_no'
            ),

            'quote' => array(
                'table' => 'quotes',
                'column' => 'quote_no'
            ),

            'assessment' => array(
                'table' => 'assessments',
                'column' => 'assessment_no'
            ),

            'booking' => array(
                'table' => 'bookings',
                'column' => 'booking_no'
            ),

            'employee' => array(
                'table' => 'users',
                'column' => 'employee_code'
            )
        );

        if (!isset($map[$type])) {
            return null;
        }

        try {

            $table =
                $map[$type]['table'];

            $column =
                $map[$type]['column'];

            /*
             * Table/column names come only from the hardcoded whitelist above.
             */
            $sql = "
                SELECT `" . $column . "`
                FROM `" . $table . "`
                WHERE id = :object_id
            ";

            $params = array(
                ':object_id' =>
                    (int)$objectId
            );

            if (
                $tenantId !== null &&
                (int)$tenantId > 0
            ) {
                $sql .= "
                    AND tenant_id = :tenant_id
                ";

                $params[':tenant_id'] =
                    (int)$tenantId;
            }

            $sql .= "
                LIMIT 1
            ";

            $stmt =
                $pdo->prepare($sql);

            $stmt->execute($params);

            $recordNo =
                $stmt->fetchColumn();

            return $recordNo !== false
                ? tenantAuditText(
                    $recordNo,
                    120
                )
                : null;

        } catch (Throwable $e) {

            return null;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Resolve Location Event
    |--------------------------------------------------------------------------
    */
    function tenantAuditResolveLocation(
        PDO $pdo,
        $locationEventId,
        $tenantId = null,
        $userId = null
    ) {
        if (
            $locationEventId === null ||
            (int)$locationEventId <= 0
        ) {
            return null;
        }

        try {

            $sql = "
                SELECT
                    id,
                    latitude,
                    longitude,
                    accuracy_meters
                FROM location_events
                WHERE id = :location_event_id
            ";

            $params = array(
                ':location_event_id' =>
                    (int)$locationEventId
            );

            if (
                $tenantId !== null &&
                (int)$tenantId > 0
            ) {
                $sql .= "
                    AND tenant_id = :tenant_id
                ";

                $params[':tenant_id'] =
                    (int)$tenantId;
            }

            if (
                $userId !== null &&
                (int)$userId > 0
            ) {
                $sql .= "
                    AND user_id = :user_id
                ";

                $params[':user_id'] =
                    (int)$userId;
            }

            $sql .= "
                LIMIT 1
            ";

            $stmt =
                $pdo->prepare($sql);

            $stmt->execute($params);

            $row =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            return $row
                ? $row
                : null;

        } catch (Throwable $e) {

            return null;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Validate Latitude
    |--------------------------------------------------------------------------
    */
    function tenantAuditLatitude($value)
    {
        if (
            $value === null ||
            $value === '' ||
            !is_numeric($value)
        ) {
            return null;
        }

        $value =
            (float)$value;

        if (
            $value < -90 ||
            $value > 90
        ) {
            return null;
        }

        return $value;
    }


    /*
    |--------------------------------------------------------------------------
    | Validate Longitude
    |--------------------------------------------------------------------------
    */
    function tenantAuditLongitude($value)
    {
        if (
            $value === null ||
            $value === '' ||
            !is_numeric($value)
        ) {
            return null;
        }

        $value =
            (float)$value;

        if (
            $value < -180 ||
            $value > 180
        ) {
            return null;
        }

        return $value;
    }


    /*
    |--------------------------------------------------------------------------
    | Validate Location Accuracy
    |--------------------------------------------------------------------------
    */
    function tenantAuditAccuracy($value)
    {
        if (
            $value === null ||
            $value === '' ||
            !is_numeric($value)
        ) {
            return null;
        }

        $value =
            (float)$value;

        return $value >= 0
            ? $value
            : null;
    }


    /*
    |--------------------------------------------------------------------------
    | Main Audit Logger
    |--------------------------------------------------------------------------
    |
    | Existing calls remain compatible:
    |
    | tenantAuditLog(
    |     $pdo,
    |     'LOGIN_SUCCESS',
    |     $tenantId,
    |     $branchId,
    |     $userId
    | );
    |
    | New options can be supplied using the final $options argument.
    |
    */
    function tenantAuditLog(
        PDO $pdo,
        $action,
        $tenantId = null,
        $branchId = null,
        $userId = null,
        $objectType = 'authentication',
        $objectId = null,
        $oldValues = null,
        $newValues = null,
        $options = array()
    ) {
        /*
         * Audit failure must NEVER break the business transaction,
         * login or logout operation.
         */
        try {

            if (!tenantAuditTableExists($pdo)) {
                return false;
            }


            /*
            |--------------------------------------------------------------------------
            | Normalize IDs
            |--------------------------------------------------------------------------
            */

            $tenantId =
                $tenantId !== null &&
                (int)$tenantId > 0
                    ? (int)$tenantId
                    : null;

            $branchId =
                $branchId !== null &&
                (int)$branchId > 0
                    ? (int)$branchId
                    : null;

            $userId =
                $userId !== null &&
                (int)$userId > 0
                    ? (int)$userId
                    : null;

            $objectId =
                $objectId !== null &&
                (int)$objectId > 0
                    ? (int)$objectId
                    : null;

            if (!is_array($options)) {
                $options = array();
            }


            /*
            |--------------------------------------------------------------------------
            | Action / Object Type
            |--------------------------------------------------------------------------
            */

            $action =
                tenantAuditText(
                    $action,
                    120
                );

            if ($action === null) {
                $action = 'UNKNOWN_ACTION';
            }

            $objectType =
                tenantAuditText(
                    $objectType,
                    80
                );

            if ($objectType === null) {
                $objectType = 'general';
            }


            /*
            |--------------------------------------------------------------------------
            | User Name
            |--------------------------------------------------------------------------
            */

            $userName =
                isset($options['user_name'])
                    ? tenantAuditText(
                        $options['user_name'],
                        190
                    )
                    : null;

            if (
                $userName === null &&
                $userId !== null
            ) {
                $userName =
                    tenantAuditResolveUserName(
                        $pdo,
                        $tenantId,
                        $userId
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | Module
            |--------------------------------------------------------------------------
            */

            $module =
                isset($options['module'])
                    ? tenantAuditText(
                        $options['module'],
                        100
                    )
                    : null;

            if ($module === null) {
                $module =
                    tenantAuditInferModule(
                        $action,
                        $objectType
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | Audit Category
            |--------------------------------------------------------------------------
            */

            $auditCategory =
                isset(
                    $options['audit_category']
                )
                    ? tenantAuditText(
                        $options['audit_category'],
                        80
                    )
                    : null;

            if ($auditCategory === null) {
                $auditCategory =
                    tenantAuditInferCategory(
                        $action,
                        $objectType
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | Record Number
            |--------------------------------------------------------------------------
            */

            $recordNo =
                isset($options['record_no'])
                    ? tenantAuditText(
                        $options['record_no'],
                        120
                    )
                    : null;

            if (
                $recordNo === null &&
                $objectId !== null
            ) {
                $recordNo =
                    tenantAuditResolveRecordNo(
                        $pdo,
                        $tenantId,
                        $objectType,
                        $objectId
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | Location Event
            |--------------------------------------------------------------------------
            */

            $locationEventId =
                isset(
                    $options['location_event_id']
                ) &&
                (int)$options['location_event_id'] > 0
                    ? (int)$options['location_event_id']
                    : null;


            /*
            |--------------------------------------------------------------------------
            | Direct Location Values
            |--------------------------------------------------------------------------
            */

            $latitude = null;
            $longitude = null;
            $accuracy = null;
            $locationLabel = null;


            if (
                isset($options['location']) &&
                is_array($options['location'])
            ) {

                $location =
                    $options['location'];

                if (
                    array_key_exists(
                        'latitude',
                        $location
                    )
                ) {
                    $latitude =
                        tenantAuditLatitude(
                            $location['latitude']
                        );
                }

                if (
                    array_key_exists(
                        'longitude',
                        $location
                    )
                ) {
                    $longitude =
                        tenantAuditLongitude(
                            $location['longitude']
                        );
                }

                if (
                    array_key_exists(
                        'accuracy_meters',
                        $location
                    )
                ) {
                    $accuracy =
                        tenantAuditAccuracy(
                            $location[
                                'accuracy_meters'
                            ]
                        );
                }

                if (
                    isset(
                        $location['label']
                    )
                ) {
                    $locationLabel =
                        tenantAuditText(
                            $location['label'],
                            500
                        );
                }
            }


            /*
             * Also support direct options.
             */
            if (
                array_key_exists(
                    'location_latitude',
                    $options
                )
            ) {
                $latitude =
                    tenantAuditLatitude(
                        $options[
                            'location_latitude'
                        ]
                    );
            }

            if (
                array_key_exists(
                    'location_longitude',
                    $options
                )
            ) {
                $longitude =
                    tenantAuditLongitude(
                        $options[
                            'location_longitude'
                        ]
                    );
            }

            if (
                array_key_exists(
                    'location_accuracy_meters',
                    $options
                )
            ) {
                $accuracy =
                    tenantAuditAccuracy(
                        $options[
                            'location_accuracy_meters'
                        ]
                    );
            }

            if (
                isset(
                    $options['location_label']
                )
            ) {
                $locationLabel =
                    tenantAuditText(
                        $options[
                            'location_label'
                        ],
                        500
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | Resolve Location From location_events
            |--------------------------------------------------------------------------
            |
            | If location_event_id is provided, copy its coordinates into
            | audit_logs as a permanent location snapshot.
            |
            */

            if (
                $locationEventId !== null &&
                (
                    $latitude === null ||
                    $longitude === null ||
                    $accuracy === null
                )
            ) {

                $locationEvent =
                    tenantAuditResolveLocation(
                        $pdo,
                        $locationEventId,
                        $tenantId,
                        $userId
                    );

                if ($locationEvent) {

                    if ($latitude === null) {
                        $latitude =
                            tenantAuditLatitude(
                                $locationEvent[
                                    'latitude'
                                ]
                            );
                    }

                    if ($longitude === null) {
                        $longitude =
                            tenantAuditLongitude(
                                $locationEvent[
                                    'longitude'
                                ]
                            );
                    }

                    if ($accuracy === null) {
                        $accuracy =
                            tenantAuditAccuracy(
                                $locationEvent[
                                    'accuracy_meters'
                                ]
                            );
                    }
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Insert Audit Record
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare("
                    INSERT INTO audit_logs (
                        tenant_id,
                        branch_id,

                        user_id,
                        user_name,
                        platform_user_id,

                        audit_category,
                        module,
                        action,

                        object_type,
                        object_id,
                        record_no,

                        location_event_id,

                        old_values,
                        new_values,

                        ip_address,
                        device_type,
                        user_agent,

                        location_latitude,
                        location_longitude,
                        location_accuracy_meters,
                        location_label,

                        created_at
                    ) VALUES (
                        :tenant_id,
                        :branch_id,

                        :user_id,
                        :user_name,
                        NULL,

                        :audit_category,
                        :module,
                        :action,

                        :object_type,
                        :object_id,
                        :record_no,

                        :location_event_id,

                        :old_values,
                        :new_values,

                        :ip_address,
                        :device_type,
                        :user_agent,

                        :location_latitude,
                        :location_longitude,
                        :location_accuracy_meters,
                        :location_label,

                        UTC_TIMESTAMP()
                    )
                ");


            $stmt->execute(
                array(

                    ':tenant_id' =>
                        $tenantId,

                    ':branch_id' =>
                        $branchId,

                    ':user_id' =>
                        $userId,

                    ':user_name' =>
                        $userName,

                    ':audit_category' =>
                        $auditCategory,

                    ':module' =>
                        $module,

                    ':action' =>
                        $action,

                    ':object_type' =>
                        $objectType,

                    ':object_id' =>
                        $objectId,

                    ':record_no' =>
                        $recordNo,

                    ':location_event_id' =>
                        $locationEventId,

                    ':old_values' =>
                        tenantAuditJson(
                            $oldValues
                        ),

                    ':new_values' =>
                        tenantAuditJson(
                            $newValues
                        ),

                    ':ip_address' =>
                        tenantAuditClientIp(),

                    ':device_type' =>
                        tenantAuditDeviceType(),

                    ':user_agent' =>
                        tenantAuditUserAgent(),

                    ':location_latitude' =>
                        $latitude,

                    ':location_longitude' =>
                        $longitude,

                    ':location_accuracy_meters' =>
                        $accuracy,

                    ':location_label' =>
                        $locationLabel
                )
            );


            return true;

        } catch (Throwable $e) {

            error_log(
                'FieldPlx tenant audit log error: ' .
                $e->getMessage()
            );

            return false;
        }
    }
}