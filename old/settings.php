<?php
/**
 * FieldPlx - Workspace Settings
 * Upload as: /public_html/settings.php
 * PHP 7.2+ / MariaDB / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    header('Location: login.php?redirect=' . rawurlencode('settings.php'));
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'settings.view',
        'You do not have permission to view workspace settings.'
    );
}

$tenantId = (int) $_SESSION['tenant_id'];
$userId = (int) $_SESSION['user_id'];
$canManage = function_exists('hasPermission')
    ? hasPermission('settings.manage')
    : true;

$pageTitle = 'Settings - FieldPlx';
$activePage = 'settings';
$searchPlaceholder = 'Search settings...';
$basePath = '';
$errors = array();

if (!function_exists('stFetchOne')) {
    function stFetchOne(mysqli_stmt $stmt)
    {
        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            return $result ? $result->fetch_assoc() : null;
        }

        $meta = $stmt->result_metadata();
        if (!$meta) {
            return null;
        }

        $row = array();
        $bind = array();

        while ($field = $meta->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(array($stmt, 'bind_result'), $bind);

        if (!$stmt->fetch()) {
            return null;
        }

        $copy = array();
        foreach ($row as $key => $value) {
            $copy[$key] = $value;
        }

        return $copy;
    }
}

if (!function_exists('stFetchAll')) {
    function stFetchAll(mysqli_stmt $stmt)
    {
        $rows = array();

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        $meta = $stmt->result_metadata();
        if (!$meta) {
            return $rows;
        }

        $row = array();
        $bind = array();

        while ($field = $meta->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(array($stmt, 'bind_result'), $bind);

        while ($stmt->fetch()) {
            $copy = array();

            foreach ($row as $key => $value) {
                $copy[$key] = $value;
            }

            $rows[] = $copy;
        }

        return $rows;
    }
}

if (!function_exists('stPost')) {
    function stPost($key, $default = '')
    {
        return isset($_POST[$key]) && !is_array($_POST[$key])
            ? trim((string) $_POST[$key])
            : $default;
    }
}

if (!function_exists('stBool')) {
    function stBool($key)
    {
        return isset($_POST[$key]) ? 1 : 0;
    }
}

if (!function_exists('stNullable')) {
    function stNullable($value)
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}

if (!function_exists('stCsrf')) {
    function stCsrf()
    {
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Throwable $error) {
                $_SESSION['csrf_token'] = sha1(uniqid((string) mt_rand(), true));
            }
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('stVerifyCsrf')) {
    function stVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals((string) $_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('stTableExists')) {
    function stTableExists(mysqli $conn, $table)
    {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = stFetchOne($stmt);
        $stmt->close();

        return $row && (int) $row['total'] > 0;
    }
}

if (!function_exists('stDate')) {
    function stDate($value)
    {
        if (empty($value)) {
            return 'Not applicable';
        }

        $time = strtotime((string) $value);
        return $time ? date('d M Y', $time) : 'Not applicable';
    }
}

if (!function_exists('stLabel')) {
    function stLabel($value)
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }
}

if (!function_exists('stMoney')) {
    function stMoney($amount, $currency)
    {
        $currency = strtoupper(trim((string) $currency));
        $symbols = array(
            'INR' => '₹',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'CAD' => 'CAD ',
            'AUD' => 'AUD '
        );

        $prefix = isset($symbols[$currency])
            ? $symbols[$currency]
            : $currency . ' ';

        return $prefix . number_format((float) $amount, 2);
    }
}

if (!function_exists('stUploadLogo')) {
    function stUploadLogo($file, $tenantId, &$message)
    {
        if (
            !is_array($file) ||
            !isset($file['error']) ||
            (int) $file['error'] === UPLOAD_ERR_NO_FILE
        ) {
            return null;
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            $message = 'Logo upload failed.';
            return false;
        }

        if (
            empty($file['tmp_name']) ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            $message = 'Invalid logo upload.';
            return false;
        }

        if (!empty($file['size']) && (int) $file['size'] > 3 * 1024 * 1024) {
            $message = 'Logo size must not exceed 3 MB.';
            return false;
        }

        $mime = '';

        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($file['tmp_name']);
        }

        $allowed = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        );

        if (!isset($allowed[$mime])) {
            $message = 'Logo must be JPG, PNG, or WEBP.';
            return false;
        }

        $relativeDir = 'uploads/tenants/settings/' . (int) $tenantId;
        $absoluteDir = __DIR__ . DIRECTORY_SEPARATOR .
            str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

        if (
            !is_dir($absoluteDir) &&
            !mkdir($absoluteDir, 0775, true) &&
            !is_dir($absoluteDir)
        ) {
            $message = 'Unable to create the logo directory.';
            return false;
        }

        try {
            $random = bin2hex(random_bytes(8));
        } catch (Throwable $error) {
            $random = sha1(uniqid((string) mt_rand(), true));
        }

        $name = 'workspace-logo-' . date('YmdHis') . '-' .
            $random . '.' . $allowed[$mime];

        if (!move_uploaded_file(
            $file['tmp_name'],
            $absoluteDir . DIRECTORY_SEPARATOR . $name
        )) {
            $message = 'Unable to save the logo.';
            return false;
        }

        return $relativeDir . '/' . $name;
    }
}

if (!function_exists('stDeleteLogo')) {
    function stDeleteLogo($path)
    {
        $path = trim((string) $path);

        if ($path === '' || strpos($path, 'uploads/tenants/') !== 0) {
            return;
        }

        $absolute = __DIR__ . DIRECTORY_SEPARATOR .
            str_replace('/', DIRECTORY_SEPARATOR, $path);

        if (is_file($absolute) && is_writable($absolute)) {
            @unlink($absolute);
        }
    }
}

if (!function_exists('stLog')) {
    function stLog(mysqli $conn, $tenantId, $userId, $section, array $details)
    {
        try {
            $stmt = $conn->prepare(
                "INSERT INTO activity_events
                (
                    tenant_id,
                    actor_user_id,
                    actor_type,
                    event_type,
                    related_type,
                    related_id,
                    client_id,
                    title,
                    details_json,
                    visible_to_client,
                    created_at
                )
                VALUES (?, ?, 'user', 'settings_updated', 'settings', ?,
                        NULL, ?, ?, 0, NOW())"
            );

            if (!$stmt) {
                return;
            }

            $title = 'Workspace settings updated: ' . stLabel($section);
            $json = json_encode(
                $details,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $stmt->bind_param(
                'iiiss',
                $tenantId,
                $userId,
                $tenantId,
                $title,
                $json
            );

            $stmt->execute();
            $stmt->close();
        } catch (Throwable $error) {
            error_log('Settings log failed: ' . $error->getMessage());
        }
    }
}

$tabs = array(
    'business',
    'preferences',
    'booking',
    'tracking',
    'ai',
    'subscription'
);

$activeTab = isset($_GET['tab'])
    ? preg_replace('/[^a-z_]/', '', strtolower((string) $_GET['tab']))
    : 'business';

if (!in_array($activeTab, $tabs, true)) {
    $activeTab = 'business';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = stPost('section');

    if (in_array($section, $tabs, true)) {
        $activeTab = $section;
    }

    if (!$canManage) {
        $errors[] = 'You do not have permission to update settings.';
    }

    if (!stVerifyCsrf(stPost('csrf_token'))) {
        $errors[] = 'Your session token is invalid. Refresh and try again.';
    }

    if (empty($errors)) {
        $oldLogo = '';
        $newLogo = null;
        $uploadedLogo = null;

        try {
            $conn->begin_transaction();

            if ($section === 'business') {
                $companyName = stPost('company_name');
                $businessType = stPost('business_type');
                $email = stPost('business_email');
                $phone = stPost('business_phone');
                $website = stPost('website');
                $locationName = stPost('location_name', 'Primary Office');
                $address1 = stPost('address_line1');
                $address2 = stPost('address_line2');
                $city = stPost('city');
                $state = stPost('state');
                $postal = stPost('postal_code');
                $country = stPost('country', 'India');

                if ($companyName === '') {
                    throw new Exception('Company name is required.');
                }

                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Enter a valid business email.');
                }

                if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
                    throw new Exception('Enter a valid website URL including https://.');
                }

                $stmt = $conn->prepare(
                    "SELECT logo_path
                     FROM tenants
                     WHERE id = ?
                       AND deleted_at IS NULL
                     LIMIT 1
                     FOR UPDATE"
                );

                if (!$stmt) {
                    throw new Exception('Unable to load the business profile.');
                }

                $stmt->bind_param('i', $tenantId);
                $stmt->execute();
                $existing = stFetchOne($stmt);
                $stmt->close();

                if (!$existing) {
                    throw new Exception('Workspace not found.');
                }

                $oldLogo = trim((string) $existing['logo_path']);
                $logoError = '';

                $uploadedLogo = stUploadLogo(
                    isset($_FILES['logo']) ? $_FILES['logo'] : array(),
                    $tenantId,
                    $logoError
                );

                if ($uploadedLogo === false) {
                    throw new Exception($logoError);
                }

                if (is_string($uploadedLogo) && $uploadedLogo !== '') {
                    $newLogo = $uploadedLogo;
                } elseif (stBool('remove_logo')) {
                    $newLogo = null;
                } else {
                    $newLogo = $oldLogo !== '' ? $oldLogo : null;
                }

                $businessTypeValue = stNullable($businessType);
                $emailValue = stNullable($email);
                $phoneValue = stNullable($phone);
                $websiteValue = stNullable($website);

                $stmt = $conn->prepare(
                    "UPDATE tenants
                     SET company_name = ?,
                         business_type = ?,
                         email = ?,
                         phone = ?,
                         website = ?,
                         logo_path = ?,
                         updated_at = NOW()
                     WHERE id = ?
                       AND deleted_at IS NULL
                     LIMIT 1"
                );

                if (!$stmt) {
                    throw new Exception('Unable to prepare the profile update.');
                }

                $stmt->bind_param(
                    'ssssssi',
                    $companyName,
                    $businessTypeValue,
                    $emailValue,
                    $phoneValue,
                    $websiteValue,
                    $newLogo,
                    $tenantId
                );

                if (!$stmt->execute()) {
                    throw new Exception('Unable to update the business profile.');
                }

                $stmt->close();

                $stmt = $conn->prepare(
                    "SELECT id
                     FROM tenant_locations
                     WHERE tenant_id = ?
                       AND is_primary = 1
                     ORDER BY id ASC
                     LIMIT 1
                     FOR UPDATE"
                );

                if (!$stmt) {
                    throw new Exception('Unable to load the primary location.');
                }

                $stmt->bind_param('i', $tenantId);
                $stmt->execute();
                $primaryLocation = stFetchOne($stmt);
                $stmt->close();

                $locationName = $locationName !== '' ? $locationName : 'Primary Office';
                $address1 = stNullable($address1);
                $address2 = stNullable($address2);
                $city = stNullable($city);
                $state = stNullable($state);
                $postal = stNullable($postal);
                $country = stNullable($country);

                if ($primaryLocation) {
                    $locationId = (int) $primaryLocation['id'];

                    $stmt = $conn->prepare(
                        "UPDATE tenant_locations
                         SET name = ?,
                             address_line1 = ?,
                             address_line2 = ?,
                             city = ?,
                             state = ?,
                             postal_code = ?,
                             country = ?,
                             is_primary = 1,
                             updated_at = NOW()
                         WHERE id = ?
                           AND tenant_id = ?
                         LIMIT 1"
                    );

                    if (!$stmt) {
                        throw new Exception('Unable to prepare the location update.');
                    }

                    $stmt->bind_param(
                        'sssssssii',
                        $locationName,
                        $address1,
                        $address2,
                        $city,
                        $state,
                        $postal,
                        $country,
                        $locationId,
                        $tenantId
                    );
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO tenant_locations
                        (
                            tenant_id,
                            name,
                            address_line1,
                            address_line2,
                            city,
                            state,
                            postal_code,
                            country,
                            is_primary,
                            created_at,
                            updated_at
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())"
                    );

                    if (!$stmt) {
                        throw new Exception('Unable to prepare the location creation.');
                    }

                    $stmt->bind_param(
                        'isssssss',
                        $tenantId,
                        $locationName,
                        $address1,
                        $address2,
                        $city,
                        $state,
                        $postal,
                        $country
                    );
                }

                if (!$stmt->execute()) {
                    throw new Exception('Unable to save the primary location.');
                }

                $stmt->close();
                $_SESSION['company_name'] = $companyName;
                $message = 'Business profile updated successfully.';
                $details = array('section' => 'business');

            } elseif ($section === 'preferences') {
                $timezone = stPost('timezone', 'Asia/Kolkata');
                $currency = strtoupper(stPost('currency_code', 'INR'));
                $dateFormat = stPost('date_format', 'd-m-Y');

                $currencies = array('INR', 'USD', 'GBP', 'EUR', 'CAD', 'AUD');
                $dateFormats = array('d-m-Y', 'd/m/Y', 'm-d-Y', 'm/d/Y', 'Y-m-d');

                if (!in_array($timezone, timezone_identifiers_list(), true)) {
                    throw new Exception('Select a valid timezone.');
                }

                if (!in_array($currency, $currencies, true)) {
                    throw new Exception('Select a valid currency.');
                }

                if (!in_array($dateFormat, $dateFormats, true)) {
                    throw new Exception('Select a valid date format.');
                }

                $stmt = $conn->prepare(
                    "UPDATE tenants
                     SET timezone = ?,
                         currency_code = ?,
                         date_format = ?,
                         updated_at = NOW()
                     WHERE id = ?
                       AND deleted_at IS NULL
                     LIMIT 1"
                );

                if (!$stmt) {
                    throw new Exception('Unable to prepare preference changes.');
                }

                $stmt->bind_param('sssi', $timezone, $currency, $dateFormat, $tenantId);

                if (!$stmt->execute()) {
                    throw new Exception('Unable to update preferences.');
                }

                $stmt->close();
                $_SESSION['timezone'] = $timezone;
                $_SESSION['currency_code'] = $currency;
                $_SESSION['date_format'] = $dateFormat;
                $message = 'Regional preferences updated successfully.';
                $details = array('section' => 'preferences');

            } elseif ($section === 'booking') {
                $earliest = max(0, (int) stPost('earliest_availability_days', '1'));
                $maximum = max(1, (int) stPost('max_booking_days_ahead', '30'));
                $buffer = max(0, (int) stPost('default_buffer_minutes', '15'));

                if ($maximum < $earliest) {
                    throw new Exception(
                        'Maximum booking days must be greater than or equal to earliest availability.'
                    );
                }

                $allowReschedule = stBool('allow_client_reschedule');
                $officeConfirmation = stBool('require_office_confirmation');
                $emailConfirmation = stBool('confirmation_email_enabled');
                $smsConfirmation = stBool('confirmation_sms_enabled');

                $stmt = $conn->prepare(
                    "SELECT id
                     FROM booking_settings
                     WHERE tenant_id = ?
                     LIMIT 1
                     FOR UPDATE"
                );

                if (!$stmt) {
                    throw new Exception('Unable to load booking settings.');
                }

                $stmt->bind_param('i', $tenantId);
                $stmt->execute();
                $existing = stFetchOne($stmt);
                $stmt->close();

                if ($existing) {
                    $stmt = $conn->prepare(
                        "UPDATE booking_settings
                         SET earliest_availability_days = ?,
                             max_booking_days_ahead = ?,
                             default_buffer_minutes = ?,
                             allow_client_reschedule = ?,
                             require_office_confirmation = ?,
                             confirmation_email_enabled = ?,
                             confirmation_sms_enabled = ?,
                             updated_at = NOW()
                         WHERE tenant_id = ?
                         LIMIT 1"
                    );

                    if (!$stmt) {
                        throw new Exception('Unable to prepare booking settings.');
                    }

                    $stmt->bind_param(
                        'iiiiiiii',
                        $earliest,
                        $maximum,
                        $buffer,
                        $allowReschedule,
                        $officeConfirmation,
                        $emailConfirmation,
                        $smsConfirmation,
                        $tenantId
                    );
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO booking_settings
                        (
                            tenant_id,
                            earliest_availability_days,
                            max_booking_days_ahead,
                            default_buffer_minutes,
                            allow_client_reschedule,
                            require_office_confirmation,
                            confirmation_email_enabled,
                            confirmation_sms_enabled
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );

                    if (!$stmt) {
                        throw new Exception('Unable to prepare booking settings.');
                    }

                    $stmt->bind_param(
                        'iiiiiiii',
                        $tenantId,
                        $earliest,
                        $maximum,
                        $buffer,
                        $allowReschedule,
                        $officeConfirmation,
                        $emailConfirmation,
                        $smsConfirmation
                    );
                }

                if (!$stmt->execute()) {
                    throw new Exception('Unable to save booking settings.');
                }

                $stmt->close();
                $message = 'Booking settings updated successfully.';
                $details = array('section' => 'booking');

            } elseif ($section === 'tracking') {
                $enabled = stBool('tracking_enabled');
                $interval = max(1, min(1440, (int) stPost('waypoint_interval_minutes', '15')));
                $retention = max(1, min(3650, (int) stPost('retention_days', '30')));
                $consent = stBool('require_user_consent');
                $timer = stBool('location_timer_enabled');
                $radius = max(10, min(50000, (int) stPost('geofence_radius_meters', '150')));

                $stmt = $conn->prepare(
                    "SELECT id
                     FROM location_tracking_settings
                     WHERE tenant_id = ?
                     LIMIT 1
                     FOR UPDATE"
                );

                if (!$stmt) {
                    throw new Exception('Unable to load tracking settings.');
                }

                $stmt->bind_param('i', $tenantId);
                $stmt->execute();
                $existing = stFetchOne($stmt);
                $stmt->close();

                if ($existing) {
                    $stmt = $conn->prepare(
                        "UPDATE location_tracking_settings
                         SET enabled = ?,
                             waypoint_interval_minutes = ?,
                             retention_days = ?,
                             require_user_consent = ?,
                             location_timer_enabled = ?,
                             geofence_radius_meters = ?,
                             updated_at = NOW()
                         WHERE tenant_id = ?
                         LIMIT 1"
                    );

                    if (!$stmt) {
                        throw new Exception('Unable to prepare tracking settings.');
                    }

                    $stmt->bind_param(
                        'iiiiiii',
                        $enabled,
                        $interval,
                        $retention,
                        $consent,
                        $timer,
                        $radius,
                        $tenantId
                    );
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO location_tracking_settings
                        (
                            tenant_id,
                            enabled,
                            waypoint_interval_minutes,
                            retention_days,
                            require_user_consent,
                            location_timer_enabled,
                            geofence_radius_meters
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );

                    if (!$stmt) {
                        throw new Exception('Unable to prepare tracking settings.');
                    }

                    $stmt->bind_param(
                        'iiiiiii',
                        $tenantId,
                        $enabled,
                        $interval,
                        $retention,
                        $consent,
                        $timer,
                        $radius
                    );
                }

                if (!$stmt->execute()) {
                    throw new Exception('Unable to save tracking settings.');
                }

                $stmt->close();
                $message = 'Location tracking settings updated successfully.';
                $details = array('section' => 'tracking');

            } elseif ($section === 'ai') {
                $enabled = stBool('ai_enabled');
                $voice = stBool('voice_enabled');
                $sms = stBool('sms_enabled');
                $bookingEnabled = stBool('booking_enabled');
                $requestEnabled = stBool('request_creation_enabled');
                $escalationUser = (int) stPost('escalation_user_id', '0');
                $escalationUser = $escalationUser > 0 ? $escalationUser : null;

                if ($escalationUser !== null) {
                    $stmt = $conn->prepare(
                        "SELECT id
                         FROM users
                         WHERE id = ?
                           AND tenant_id = ?
                           AND status = 'active'
                           AND deleted_at IS NULL
                         LIMIT 1"
                    );

                    if (!$stmt) {
                        throw new Exception('Unable to validate the escalation user.');
                    }

                    $stmt->bind_param('ii', $escalationUser, $tenantId);
                    $stmt->execute();
                    $stmt->store_result();
                    $validUser = $stmt->num_rows > 0;
                    $stmt->close();

                    if (!$validUser) {
                        throw new Exception('Selected escalation user is unavailable.');
                    }
                }

                $stmt = $conn->prepare(
                    "SELECT id
                     FROM ai_receptionist_settings
                     WHERE tenant_id = ?
                     LIMIT 1
                     FOR UPDATE"
                );

                if (!$stmt) {
                    throw new Exception('Unable to load AI settings.');
                }

                $stmt->bind_param('i', $tenantId);
                $stmt->execute();
                $existing = stFetchOne($stmt);
                $stmt->close();

                if ($existing) {
                    $stmt = $conn->prepare(
                        "UPDATE ai_receptionist_settings
                         SET enabled = ?,
                             voice_enabled = ?,
                             sms_enabled = ?,
                             booking_enabled = ?,
                             request_creation_enabled = ?,
                             escalation_user_id = ?,
                             updated_at = NOW()
                         WHERE tenant_id = ?
                         LIMIT 1"
                    );

                    if (!$stmt) {
                        throw new Exception('Unable to prepare AI settings.');
                    }

                    $stmt->bind_param(
                        'iiiiiii',
                        $enabled,
                        $voice,
                        $sms,
                        $bookingEnabled,
                        $requestEnabled,
                        $escalationUser,
                        $tenantId
                    );
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO ai_receptionist_settings
                        (
                            tenant_id,
                            enabled,
                            voice_enabled,
                            sms_enabled,
                            booking_enabled,
                            request_creation_enabled,
                            escalation_user_id
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );

                    if (!$stmt) {
                        throw new Exception('Unable to prepare AI settings.');
                    }

                    $stmt->bind_param(
                        'iiiiiii',
                        $tenantId,
                        $enabled,
                        $voice,
                        $sms,
                        $bookingEnabled,
                        $requestEnabled,
                        $escalationUser
                    );
                }

                if (!$stmt->execute()) {
                    throw new Exception('Unable to save AI settings.');
                }

                $stmt->close();
                $message = 'AI receptionist settings updated successfully.';
                $details = array('section' => 'ai');

            } else {
                throw new Exception('Invalid settings section.');
            }

            $conn->commit();

            if ($oldLogo !== '' && $oldLogo !== $newLogo) {
                stDeleteLogo($oldLogo);
            }

            stLog($conn, $tenantId, $userId, $section, $details);
            $_SESSION['flash_success'] = $message;

            header('Location: settings.php?tab=' . rawurlencode($section));
            exit;

        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            if (
                is_string($uploadedLogo) &&
                $uploadedLogo !== '' &&
                $uploadedLogo !== $oldLogo
            ) {
                stDeleteLogo($uploadedLogo);
            }

            $errors[] = $error->getMessage();
            error_log('Settings update failed: ' . $error->getMessage());
        }
    }
}

$tenant = array();
$stmt = $conn->prepare(
    "SELECT
        id,
        company_name,
        slug,
        business_type,
        email,
        phone,
        website,
        logo_path,
        timezone,
        currency_code,
        date_format,
        status,
        trial_ends_at,
        subscription_plan,
        created_at,
        updated_at
     FROM tenants
     WHERE id = ?
       AND deleted_at IS NULL
     LIMIT 1"
);

if (!$stmt) {
    $errors[] = 'Unable to prepare workspace settings.';
} else {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $tenant = stFetchOne($stmt);
    $stmt->close();
}

if (!$tenant) {
    http_response_code(404);
    exit('Workspace not found.');
}

$location = array();
$stmt = $conn->prepare(
    "SELECT *
     FROM tenant_locations
     WHERE tenant_id = ?
     ORDER BY is_primary DESC, id ASC
     LIMIT 1"
);

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $location = stFetchOne($stmt);
    $stmt->close();
}

$booking = array();
$stmt = $conn->prepare(
    "SELECT *
     FROM booking_settings
     WHERE tenant_id = ?
     LIMIT 1"
);

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $booking = stFetchOne($stmt);
    $stmt->close();
}

$tracking = array();
$stmt = $conn->prepare(
    "SELECT *
     FROM location_tracking_settings
     WHERE tenant_id = ?
     LIMIT 1"
);

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $tracking = stFetchOne($stmt);
    $stmt->close();
}

$ai = array();
$stmt = $conn->prepare(
    "SELECT *
     FROM ai_receptionist_settings
     WHERE tenant_id = ?
     LIMIT 1"
);

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $ai = stFetchOne($stmt);
    $stmt->close();
}

$teamMembers = array();
$stmt = $conn->prepare(
    "SELECT
        id,
        first_name,
        last_name,
        job_title
     FROM users
     WHERE tenant_id = ?
       AND status = 'active'
       AND deleted_at IS NULL
     ORDER BY first_name ASC, last_name ASC"
);

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $teamMembers = stFetchAll($stmt);
    $stmt->close();
}

$subscription = array();

if (
    stTableExists($conn, 'subscriptions') &&
    stTableExists($conn, 'plans')
) {
    $stmt = $conn->prepare(
        "SELECT
            s.id AS subscription_id,
            s.reference_no,
            s.status AS subscription_status,
            s.starts_at,
            s.ends_at,
            s.trial_ends_at AS subscription_trial_ends_at,
            s.amount,
            s.currency,
            s.billing_cycle,
            s.auto_renew,
            p.name AS plan_name,
            p.code AS plan_code,
            p.description AS plan_description,
            p.price AS plan_price,
            p.currency AS plan_currency,
            p.billing_cycle AS plan_billing_cycle,
            p.max_users,
            p.max_branches,
            p.storage_limit_mb
         FROM subscriptions s
         LEFT JOIN plans p
           ON p.id = s.plan_id
         WHERE s.tenant_id = ?
           AND s.deleted_at IS NULL
         ORDER BY
            CASE
                WHEN s.status IN ('trial','active','past_due','suspended')
                THEN 0
                ELSE 1
            END,
            s.id DESC
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $subscription = stFetchOne($stmt);
        $stmt->close();
    }
}

$activeUsers = 0;
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM users
     WHERE tenant_id = ?
       AND status = 'active'
       AND deleted_at IS NULL"
);

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = stFetchOne($stmt);
    $activeUsers = $row ? (int) $row['total'] : 0;
    $stmt->close();
}

$locationCount = 0;
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM tenant_locations
     WHERE tenant_id = ?"
);

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = stFetchOne($stmt);
    $locationCount = $row ? (int) $row['total'] : 0;
    $stmt->close();
}

$csrfToken = stCsrf();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.settings-page{
    --st-primary:#6d28d9;
    --st-primary-soft:#f5f3ff;
    --st-text:#111827;
    --st-muted:#6b7280;
    --st-border:#e5e7eb;
}
.st-header{
    margin-bottom:14px;
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
}
.st-header h1{
    margin:0;
    color:var(--st-text);
    font-size:21px;
    font-weight:700;
}
.st-header p{
    margin:5px 0 0;
    color:var(--st-muted);
    font-size:11px;
}
.st-readonly{
    min-height:35px;
    padding:8px 12px;
    display:inline-flex;
    align-items:center;
    gap:6px;
    border:1px solid #fed7aa;
    border-radius:9px;
    background:#fff7ed;
    color:#c2410c;
    font-size:9px;
    font-weight:700;
}
.st-alert{
    margin-bottom:13px;
    padding:11px 13px;
    border-radius:10px;
    font-size:10px;
}
.st-alert.success{
    border:1px solid #bbf7d0;
    background:#f0fdf4;
    color:#047857;
}
.st-alert.error{
    border:1px solid #fecaca;
    background:#fef2f2;
    color:#b91c1c;
}
.st-layout{
    display:grid;
    grid-template-columns:230px minmax(0,1fr);
    gap:13px;
    align-items:start;
}
.st-nav,.st-card{
    overflow:hidden;
    border:1px solid var(--st-border);
    border-radius:12px;
    background:#fff;
    box-shadow:0 5px 18px rgba(15,23,42,.035);
}
.st-nav{
    position:sticky;
    top:76px;
    padding:9px;
}
.st-nav-link{
    min-height:38px;
    padding:9px 10px;
    display:flex;
    align-items:center;
    gap:9px;
    border-radius:8px;
    color:#4b5563;
    font-size:9px;
    font-weight:700;
    text-decoration:none;
}
.st-nav-link+.st-nav-link{margin-top:3px}
.st-nav-link i{
    width:17px;
    color:#6b7280;
    font-size:13px;
    text-align:center;
}
.st-nav-link:hover{
    background:#fafafa;
    color:var(--st-primary);
}
.st-nav-link.active{
    background:var(--st-primary-soft);
    color:var(--st-primary);
}
.st-nav-link.active i{color:var(--st-primary)}
.st-card-head{
    min-height:55px;
    padding:13px 15px;
    border-bottom:1px solid #f1f5f9;
}
.st-card-head h2{
    margin:0;
    color:var(--st-text);
    font-size:12px;
    font-weight:700;
}
.st-card-head p{
    margin:4px 0 0;
    color:#9ca3af;
    font-size:9px;
}
.st-card-body{padding:15px}
.st-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:11px;
}
.st-grid.three{
    grid-template-columns:repeat(3,minmax(0,1fr));
}
.st-field{min-width:0}
.st-field.full{grid-column:1/-1}
.st-label{
    margin-bottom:5px;
    display:block;
    color:#374151;
    font-size:9px;
    font-weight:700;
}
.st-required{color:#dc2626}
.st-input,.st-select{
    width:100%;
    height:38px;
    min-height:38px;
    padding:8px 10px;
    border:1px solid #dfe3e8;
    border-radius:9px;
    background:#fff;
    color:#111827;
    font-family:inherit;
    font-size:9px;
    outline:none;
}
.st-input:focus,.st-select:focus{
    border-color:#8b5cf6;
    box-shadow:0 0 0 3px rgba(139,92,246,.08);
}
.st-input:disabled,.st-select:disabled{
    background:#f9fafb;
    color:#9ca3af;
}
.st-help{
    margin-top:4px;
    color:#9ca3af;
    font-size:8px;
}
.st-section{
    margin-top:16px;
    padding-top:14px;
    border-top:1px solid #f1f5f9;
}
.st-section-title{
    margin:0 0 10px;
    color:#111827;
    font-size:10px;
    font-weight:700;
}
.st-logo-row{
    display:grid;
    grid-template-columns:92px minmax(0,1fr);
    gap:12px;
    align-items:center;
}
.st-logo{
    width:92px;
    height:72px;
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;
    border:1px dashed #d1d5db;
    border-radius:10px;
    background:#fafafa;
    color:#9ca3af;
}
.st-logo img{
    width:100%;
    height:100%;
    object-fit:contain;
}
.st-logo i{font-size:24px}
.st-switch-row{
    min-height:58px;
    padding:11px 0;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    border-bottom:1px solid #f1f5f9;
}
.st-switch-row:last-child{border-bottom:0}
.st-switch-title{
    color:#111827;
    font-size:9px;
    font-weight:700;
}
.st-switch-text{
    margin-top:3px;
    color:#9ca3af;
    font-size:8px;
}
.st-switch{
    position:relative;
    width:38px;
    height:21px;
    flex:0 0 auto;
}
.st-switch input{
    position:absolute;
    width:1px;
    height:1px;
    opacity:0;
}
.st-slider{
    position:absolute;
    inset:0;
    border-radius:999px;
    background:#d1d5db;
    cursor:pointer;
    transition:.18s;
}
.st-slider:before{
    content:"";
    position:absolute;
    top:3px;
    left:3px;
    width:15px;
    height:15px;
    border-radius:50%;
    background:#fff;
    box-shadow:0 1px 3px rgba(15,23,42,.2);
    transition:.18s;
}
.st-switch input:checked+.st-slider{background:var(--st-primary)}
.st-switch input:checked+.st-slider:before{transform:translateX(17px)}
.st-switch input:disabled+.st-slider{opacity:.5}
.st-actions{
    padding:12px 15px;
    display:flex;
    justify-content:flex-end;
    border-top:1px solid #f1f5f9;
    background:#fafafa;
}
.st-save{
    height:36px;
    padding:8px 13px;
    display:inline-flex;
    align-items:center;
    gap:7px;
    border:0;
    border-radius:9px;
    background:var(--st-primary);
    color:#fff;
    font-family:inherit;
    font-size:9px;
    font-weight:700;
    cursor:pointer;
}
.st-plan{
    padding:16px;
    display:grid;
    grid-template-columns:minmax(0,1.3fr) minmax(220px,.7fr);
    gap:14px;
    border-radius:11px;
    background:linear-gradient(135deg,#4c1d95,#7c3aed);
    color:#fff;
}
.st-plan h3{
    margin:0;
    font-size:20px;
    font-weight:700;
}
.st-plan p{
    margin:6px 0 0;
    color:rgba(255,255,255,.74);
    font-size:9px;
}
.st-price{text-align:right}
.st-price strong{
    display:block;
    font-size:20px;
}
.st-price span{
    color:rgba(255,255,255,.7);
    font-size:8px;
}
.st-info-grid{
    margin-top:12px;
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:9px;
}
.st-info{
    padding:11px;
    border:1px solid #edf0f5;
    border-radius:9px;
    background:#fafafa;
}
.st-info-label{
    color:#9ca3af;
    font-size:8px;
    font-weight:700;
    text-transform:uppercase;
}
.st-info-value{
    margin-top:4px;
    display:block;
    color:#111827;
    font-size:10px;
    font-weight:700;
}
.st-status{
    padding:4px 7px;
    display:inline-flex;
    border-radius:999px;
    font-size:8px;
    font-weight:700;
    background:#f3f4f6;
    color:#4b5563;
}
.st-status.active{background:#ecfdf5;color:#047857}
.st-status.trial,.st-status.pending,.st-status.past_due{background:#fff7ed;color:#c2410c}
.st-status.suspended,.st-status.expired,.st-status.cancelled,.st-status.inactive{background:#fef2f2;color:#b91c1c}
@media(max-width:1050px){
    .st-layout{grid-template-columns:1fr}
    .st-nav{
        position:static;
        overflow-x:auto;
        display:flex;
        gap:3px;
    }
    .st-nav-link{min-width:max-content}
    .st-nav-link+.st-nav-link{margin-top:0}
    .st-info-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:720px){
    .st-header{flex-direction:column}
    .st-grid,.st-grid.three,.st-plan{grid-template-columns:1fr}
    .st-price{text-align:left}
}
@media(max-width:560px){
    .st-grid,.st-info-grid{grid-template-columns:1fr}
    .st-field.full{grid-column:auto}
    .st-logo-row{grid-template-columns:1fr}
    .st-actions{justify-content:stretch}
    .st-save{width:100%;justify-content:center}
}
</style>

<div class="settings-page">
    <div class="st-header">
        <div>
            <h1>Settings</h1>
            <p>Manage business details, preferences, booking, tracking, AI, and subscription information.</p>
        </div>

        <?php if (!$canManage): ?>
            <div class="st-readonly">
                <i class="bi bi-lock"></i>
                Read-only access
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="st-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="st-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="st-layout">
        <nav class="st-nav">
            <?php
            $navTabs = array(
                'business' => array('bi-buildings', 'Business Profile'),
                'preferences' => array('bi-sliders', 'Preferences'),
                'booking' => array('bi-calendar2-check', 'Booking'),
                'tracking' => array('bi-geo-alt', 'Tracking'),
                'ai' => array('bi-robot', 'AI Receptionist'),
                'subscription' => array('bi-credit-card', 'Subscription')
            );
            ?>

            <?php foreach ($navTabs as $tabKey => $tab): ?>
                <a
                    href="settings.php?tab=<?= e($tabKey); ?>"
                    class="st-nav-link <?= $activeTab === $tabKey ? 'active' : ''; ?>"
                >
                    <i class="bi <?= e($tab[0]); ?>"></i>
                    <?= e($tab[1]); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <main>
            <?php if ($activeTab === 'business'): ?>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                    <input type="hidden" name="section" value="business">

                    <section class="st-card">
                        <div class="st-card-head">
                            <h2>Business Profile</h2>
                            <p>Workspace details used across documents and customer communication.</p>
                        </div>

                        <div class="st-card-body">
                            <div class="st-logo-row">
                                <div class="st-logo">
                                    <?php if (!empty($tenant['logo_path'])): ?>
                                        <img src="<?= e($tenant['logo_path']); ?>" alt="Workspace logo">
                                    <?php else: ?>
                                        <i class="bi bi-image"></i>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <label class="st-label">Workspace Logo</label>
                                    <input
                                        type="file"
                                        name="logo"
                                        class="st-input"
                                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                        <?= !$canManage ? 'disabled' : ''; ?>
                                    >
                                    <div class="st-help">JPG, PNG, or WEBP. Maximum size: 3 MB.</div>

                                    <?php if (!empty($tenant['logo_path'])): ?>
                                        <label style="margin-top:7px;display:inline-flex;gap:6px;font-size:8px;font-weight:700;color:#6b7280;">
                                            <input
                                                type="checkbox"
                                                name="remove_logo"
                                                value="1"
                                                <?= !$canManage ? 'disabled' : ''; ?>
                                            >
                                            Remove current logo
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="st-section">
                                <h3 class="st-section-title">Company Information</h3>

                                <div class="st-grid">
                                    <div class="st-field">
                                        <label class="st-label">
                                            Company Name <span class="st-required">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            name="company_name"
                                            class="st-input"
                                            maxlength="190"
                                            value="<?= e($tenant['company_name']); ?>"
                                            required
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>

                                    <div class="st-field">
                                        <label class="st-label">Business Type</label>
                                        <input
                                            type="text"
                                            name="business_type"
                                            class="st-input"
                                            maxlength="120"
                                            value="<?= e($tenant['business_type']); ?>"
                                            placeholder="Electrical, plumbing, HVAC..."
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>

                                    <div class="st-field">
                                        <label class="st-label">Business Email</label>
                                        <input
                                            type="email"
                                            name="business_email"
                                            class="st-input"
                                            maxlength="190"
                                            value="<?= e($tenant['email']); ?>"
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>

                                    <div class="st-field">
                                        <label class="st-label">Business Phone</label>
                                        <input
                                            type="text"
                                            name="business_phone"
                                            class="st-input"
                                            maxlength="50"
                                            value="<?= e($tenant['phone']); ?>"
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>

                                    <div class="st-field full">
                                        <label class="st-label">Website</label>
                                        <input
                                            type="url"
                                            name="website"
                                            class="st-input"
                                            maxlength="255"
                                            value="<?= e($tenant['website']); ?>"
                                            placeholder="https://example.com"
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>
                                </div>
                            </div>

                            <div class="st-section">
                                <h3 class="st-section-title">Primary Business Location</h3>

                                <div class="st-grid">
                                    <div class="st-field full">
                                        <label class="st-label">Location Name</label>
                                        <input
                                            type="text"
                                            name="location_name"
                                            class="st-input"
                                            maxlength="190"
                                            value="<?= e(!empty($location['name']) ? $location['name'] : 'Primary Office'); ?>"
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>

                                    <div class="st-field full">
                                        <label class="st-label">Address Line 1</label>
                                        <input
                                            type="text"
                                            name="address_line1"
                                            class="st-input"
                                            maxlength="255"
                                            value="<?= e(isset($location['address_line1']) ? $location['address_line1'] : ''); ?>"
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>

                                    <div class="st-field full">
                                        <label class="st-label">Address Line 2</label>
                                        <input
                                            type="text"
                                            name="address_line2"
                                            class="st-input"
                                            maxlength="255"
                                            value="<?= e(isset($location['address_line2']) ? $location['address_line2'] : ''); ?>"
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>

                                    <div class="st-field">
                                        <label class="st-label">City</label>
                                        <input
                                            type="text"
                                            name="city"
                                            class="st-input"
                                            maxlength="120"
                                            value="<?= e(isset($location['city']) ? $location['city'] : ''); ?>"
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>

                                    <div class="st-field">
                                        <label class="st-label">State</label>
                                        <input
                                            type="text"
                                            name="state"
                                            class="st-input"
                                            maxlength="120"
                                            value="<?= e(isset($location['state']) ? $location['state'] : ''); ?>"
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>

                                    <div class="st-field">
                                        <label class="st-label">Postal Code</label>
                                        <input
                                            type="text"
                                            name="postal_code"
                                            class="st-input"
                                            maxlength="40"
                                            value="<?= e(isset($location['postal_code']) ? $location['postal_code'] : ''); ?>"
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>

                                    <div class="st-field">
                                        <label class="st-label">Country</label>
                                        <input
                                            type="text"
                                            name="country"
                                            class="st-input"
                                            maxlength="120"
                                            value="<?= e(!empty($location['country']) ? $location['country'] : 'India'); ?>"
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($canManage): ?>
                            <div class="st-actions">
                                <button type="submit" class="st-save">
                                    <i class="bi bi-check2"></i>
                                    Save Business Profile
                                </button>
                            </div>
                        <?php endif; ?>
                    </section>
                </form>

            <?php elseif ($activeTab === 'preferences'): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                    <input type="hidden" name="section" value="preferences">

                    <section class="st-card">
                        <div class="st-card-head">
                            <h2>Regional Preferences</h2>
                            <p>Configure timezone, currency, and date display.</p>
                        </div>

                        <div class="st-card-body">
                            <div class="st-grid three">
                                <div class="st-field">
                                    <label class="st-label">Timezone</label>
                                    <select name="timezone" class="st-select" <?= !$canManage ? 'disabled' : ''; ?>>
                                        <?php
                                        $timezones = array(
                                            'Asia/Kolkata',
                                            'UTC',
                                            'Asia/Dubai',
                                            'Asia/Singapore',
                                            'Europe/London',
                                            'Europe/Paris',
                                            'America/New_York',
                                            'America/Chicago',
                                            'America/Denver',
                                            'America/Los_Angeles',
                                            'Australia/Sydney'
                                        );
                                        ?>
                                        <?php foreach ($timezones as $timezone): ?>
                                            <option
                                                value="<?= e($timezone); ?>"
                                                <?= ($tenant['timezone'] ?: 'Asia/Kolkata') === $timezone ? 'selected' : ''; ?>
                                            >
                                                <?= e($timezone); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="st-field">
                                    <label class="st-label">Currency</label>
                                    <select name="currency_code" class="st-select" <?= !$canManage ? 'disabled' : ''; ?>>
                                        <?php foreach (
                                            array(
                                                'INR' => 'INR - Indian Rupee',
                                                'USD' => 'USD - US Dollar',
                                                'GBP' => 'GBP - British Pound',
                                                'EUR' => 'EUR - Euro',
                                                'CAD' => 'CAD - Canadian Dollar',
                                                'AUD' => 'AUD - Australian Dollar'
                                            ) as $currency => $label
                                        ): ?>
                                            <option
                                                value="<?= e($currency); ?>"
                                                <?= strtoupper((string) $tenant['currency_code']) === $currency ? 'selected' : ''; ?>
                                            >
                                                <?= e($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="st-field">
                                    <label class="st-label">Date Format</label>
                                    <select name="date_format" class="st-select" <?= !$canManage ? 'disabled' : ''; ?>>
                                        <?php foreach (
                                            array(
                                                'd-m-Y' => 'DD-MM-YYYY',
                                                'd/m/Y' => 'DD/MM/YYYY',
                                                'm-d-Y' => 'MM-DD-YYYY',
                                                'm/d/Y' => 'MM/DD/YYYY',
                                                'Y-m-d' => 'YYYY-MM-DD'
                                            ) as $format => $label
                                        ): ?>
                                            <option
                                                value="<?= e($format); ?>"
                                                <?= ($tenant['date_format'] ?: 'd-m-Y') === $format ? 'selected' : ''; ?>
                                            >
                                                <?= e($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <?php if ($canManage): ?>
                            <div class="st-actions">
                                <button type="submit" class="st-save">
                                    <i class="bi bi-check2"></i>
                                    Save Preferences
                                </button>
                            </div>
                        <?php endif; ?>
                    </section>
                </form>

            <?php elseif ($activeTab === 'booking'): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                    <input type="hidden" name="section" value="booking">

                    <section class="st-card">
                        <div class="st-card-head">
                            <h2>Booking Settings</h2>
                            <p>Control availability, rescheduling, and confirmations.</p>
                        </div>

                        <div class="st-card-body">
                            <div class="st-grid three">
                                <div class="st-field">
                                    <label class="st-label">Earliest Availability (Days)</label>
                                    <input
                                        type="number"
                                        name="earliest_availability_days"
                                        class="st-input"
                                        min="0"
                                        value="<?= (int) (isset($booking['earliest_availability_days']) ? $booking['earliest_availability_days'] : 1); ?>"
                                        <?= !$canManage ? 'disabled' : ''; ?>
                                    >
                                </div>

                                <div class="st-field">
                                    <label class="st-label">Maximum Days Ahead</label>
                                    <input
                                        type="number"
                                        name="max_booking_days_ahead"
                                        class="st-input"
                                        min="1"
                                        value="<?= (int) (isset($booking['max_booking_days_ahead']) ? $booking['max_booking_days_ahead'] : 30); ?>"
                                        <?= !$canManage ? 'disabled' : ''; ?>
                                    >
                                </div>

                                <div class="st-field">
                                    <label class="st-label">Default Buffer (Minutes)</label>
                                    <input
                                        type="number"
                                        name="default_buffer_minutes"
                                        class="st-input"
                                        min="0"
                                        value="<?= (int) (isset($booking['default_buffer_minutes']) ? $booking['default_buffer_minutes'] : 15); ?>"
                                        <?= !$canManage ? 'disabled' : ''; ?>
                                    >
                                </div>
                            </div>

                            <div class="st-section">
                                <?php
                                $bookingSwitches = array(
                                    array(
                                        'allow_client_reschedule',
                                        'Allow Client Rescheduling',
                                        'Customers may request another appointment time.',
                                        !empty($booking['allow_client_reschedule'])
                                    ),
                                    array(
                                        'require_office_confirmation',
                                        'Require Office Confirmation',
                                        'Bookings remain pending until confirmed by your team.',
                                        array_key_exists('require_office_confirmation', $booking)
                                            ? !empty($booking['require_office_confirmation'])
                                            : true
                                    ),
                                    array(
                                        'confirmation_email_enabled',
                                        'Email Confirmation',
                                        'Send booking confirmations by email.',
                                        array_key_exists('confirmation_email_enabled', $booking)
                                            ? !empty($booking['confirmation_email_enabled'])
                                            : true
                                    ),
                                    array(
                                        'confirmation_sms_enabled',
                                        'SMS Confirmation',
                                        'Send booking confirmations by SMS.',
                                        !empty($booking['confirmation_sms_enabled'])
                                    )
                                );
                                ?>

                                <?php foreach ($bookingSwitches as $switch): ?>
                                    <div class="st-switch-row">
                                        <div>
                                            <div class="st-switch-title"><?= e($switch[1]); ?></div>
                                            <div class="st-switch-text"><?= e($switch[2]); ?></div>
                                        </div>

                                        <label class="st-switch">
                                            <input
                                                type="checkbox"
                                                name="<?= e($switch[0]); ?>"
                                                value="1"
                                                <?= $switch[3] ? 'checked' : ''; ?>
                                                <?= !$canManage ? 'disabled' : ''; ?>
                                            >
                                            <span class="st-slider"></span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ($canManage): ?>
                            <div class="st-actions">
                                <button type="submit" class="st-save">
                                    <i class="bi bi-check2"></i>
                                    Save Booking Settings
                                </button>
                            </div>
                        <?php endif; ?>
                    </section>
                </form>

            <?php elseif ($activeTab === 'tracking'): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                    <input type="hidden" name="section" value="tracking">

                    <section class="st-card">
                        <div class="st-card-head">
                            <h2>Location Tracking</h2>
                            <p>Configure GPS waypoints, retention, consent, timer, and geofence.</p>
                        </div>

                        <div class="st-card-body">
                            <div class="st-switch-row">
                                <div>
                                    <div class="st-switch-title">Enable Location Tracking</div>
                                    <div class="st-switch-text">Capture GPS waypoints during active field work.</div>
                                </div>

                                <label class="st-switch">
                                    <input
                                        type="checkbox"
                                        name="tracking_enabled"
                                        value="1"
                                        <?= !empty($tracking['enabled']) ? 'checked' : ''; ?>
                                        <?= !$canManage ? 'disabled' : ''; ?>
                                    >
                                    <span class="st-slider"></span>
                                </label>
                            </div>

                            <div class="st-section">
                                <div class="st-grid three">
                                    <div class="st-field">
                                        <label class="st-label">Waypoint Interval (Minutes)</label>
                                        <input
                                            type="number"
                                            name="waypoint_interval_minutes"
                                            class="st-input"
                                            min="1"
                                            max="1440"
                                            value="<?= (int) (isset($tracking['waypoint_interval_minutes']) ? $tracking['waypoint_interval_minutes'] : 15); ?>"
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>

                                    <div class="st-field">
                                        <label class="st-label">Retention Days</label>
                                        <input
                                            type="number"
                                            name="retention_days"
                                            class="st-input"
                                            min="1"
                                            max="3650"
                                            value="<?= (int) (isset($tracking['retention_days']) ? $tracking['retention_days'] : 30); ?>"
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>

                                    <div class="st-field">
                                        <label class="st-label">Geofence Radius (Meters)</label>
                                        <input
                                            type="number"
                                            name="geofence_radius_meters"
                                            class="st-input"
                                            min="10"
                                            max="50000"
                                            value="<?= (int) (isset($tracking['geofence_radius_meters']) ? $tracking['geofence_radius_meters'] : 150); ?>"
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                    </div>
                                </div>
                            </div>

                            <div class="st-section">
                                <div class="st-switch-row">
                                    <div>
                                        <div class="st-switch-title">Require User Consent</div>
                                        <div class="st-switch-text">Workers must approve tracking before collection starts.</div>
                                    </div>

                                    <label class="st-switch">
                                        <input
                                            type="checkbox"
                                            name="require_user_consent"
                                            value="1"
                                            <?= array_key_exists('require_user_consent', $tracking)
                                                ? (!empty($tracking['require_user_consent']) ? 'checked' : '')
                                                : 'checked'; ?>
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                        <span class="st-slider"></span>
                                    </label>
                                </div>

                                <div class="st-switch-row">
                                    <div>
                                        <div class="st-switch-title">Location Timer</div>
                                        <div class="st-switch-text">Prompt workers to start or stop time at job locations.</div>
                                    </div>

                                    <label class="st-switch">
                                        <input
                                            type="checkbox"
                                            name="location_timer_enabled"
                                            value="1"
                                            <?= !empty($tracking['location_timer_enabled']) ? 'checked' : ''; ?>
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                        <span class="st-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <?php if ($canManage): ?>
                            <div class="st-actions">
                                <button type="submit" class="st-save">
                                    <i class="bi bi-check2"></i>
                                    Save Tracking Settings
                                </button>
                            </div>
                        <?php endif; ?>
                    </section>
                </form>

            <?php elseif ($activeTab === 'ai'): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                    <input type="hidden" name="section" value="ai">

                    <section class="st-card">
                        <div class="st-card-head">
                            <h2>AI Receptionist</h2>
                            <p>Configure AI conversations, bookings, requests, and escalation.</p>
                        </div>

                        <div class="st-card-body">
                            <?php
                            $aiSwitches = array(
                                array(
                                    'ai_enabled',
                                    'Enable AI Receptionist',
                                    'Allow AI to handle supported customer conversations.',
                                    !empty($ai['enabled'])
                                ),
                                array(
                                    'voice_enabled',
                                    'Voice Calls',
                                    'Allow AI to assist with incoming calls.',
                                    !empty($ai['voice_enabled'])
                                ),
                                array(
                                    'sms_enabled',
                                    'SMS Conversations',
                                    'Allow AI to respond to supported text messages.',
                                    array_key_exists('sms_enabled', $ai)
                                        ? !empty($ai['sms_enabled'])
                                        : true
                                ),
                                array(
                                    'booking_enabled',
                                    'Create Bookings',
                                    'Allow AI to create customer bookings.',
                                    !empty($ai['booking_enabled'])
                                ),
                                array(
                                    'request_creation_enabled',
                                    'Create Requests',
                                    'Allow AI to create service requests.',
                                    array_key_exists('request_creation_enabled', $ai)
                                        ? !empty($ai['request_creation_enabled'])
                                        : true
                                )
                            );
                            ?>

                            <?php foreach ($aiSwitches as $switch): ?>
                                <div class="st-switch-row">
                                    <div>
                                        <div class="st-switch-title"><?= e($switch[1]); ?></div>
                                        <div class="st-switch-text"><?= e($switch[2]); ?></div>
                                    </div>

                                    <label class="st-switch">
                                        <input
                                            type="checkbox"
                                            name="<?= e($switch[0]); ?>"
                                            value="1"
                                            <?= $switch[3] ? 'checked' : ''; ?>
                                            <?= !$canManage ? 'disabled' : ''; ?>
                                        >
                                        <span class="st-slider"></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>

                            <div class="st-section">
                                <div class="st-field">
                                    <label class="st-label">Escalation Team Member</label>
                                    <select name="escalation_user_id" class="st-select" <?= !$canManage ? 'disabled' : ''; ?>>
                                        <option value="">No specific user</option>

                                        <?php foreach ($teamMembers as $member): ?>
                                            <?php
                                            $memberName = trim(
                                                (string) $member['first_name'] . ' ' .
                                                (string) $member['last_name']
                                            );
                                            ?>
                                            <option
                                                value="<?= (int) $member['id']; ?>"
                                                <?= (int) (isset($ai['escalation_user_id']) ? $ai['escalation_user_id'] : 0) ===
                                                    (int) $member['id'] ? 'selected' : ''; ?>
                                            >
                                                <?= e($memberName); ?>
                                                <?= !empty($member['job_title']) ? ' · ' . e($member['job_title']) : ''; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <?php if ($canManage): ?>
                            <div class="st-actions">
                                <button type="submit" class="st-save">
                                    <i class="bi bi-check2"></i>
                                    Save AI Settings
                                </button>
                            </div>
                        <?php endif; ?>
                    </section>
                </form>

            <?php else: ?>
                <?php
                $planName = !empty($subscription['plan_name'])
                    ? $subscription['plan_name']
                    : (!empty($tenant['subscription_plan'])
                        ? stLabel($tenant['subscription_plan'])
                        : 'Not Assigned');

                $planDescription = !empty($subscription['plan_description'])
                    ? $subscription['plan_description']
                    : 'Current workspace subscription and usage information.';

                $amount = isset($subscription['amount'])
                    ? $subscription['amount']
                    : (isset($subscription['plan_price']) ? $subscription['plan_price'] : 0);

                $currency = !empty($subscription['currency'])
                    ? $subscription['currency']
                    : (!empty($subscription['plan_currency'])
                        ? $subscription['plan_currency']
                        : $tenant['currency_code']);

                $cycle = !empty($subscription['billing_cycle'])
                    ? $subscription['billing_cycle']
                    : (!empty($subscription['plan_billing_cycle'])
                        ? $subscription['plan_billing_cycle']
                        : 'Not specified');

                $subscriptionStatus = !empty($subscription['subscription_status'])
                    ? $subscription['subscription_status']
                    : $tenant['status'];
                ?>

                <section class="st-card">
                    <div class="st-card-head">
                        <h2>Subscription</h2>
                        <p>Current plan, dates, usage, and limits.</p>
                    </div>

                    <div class="st-card-body">
                        <div class="st-plan">
                            <div>
                                <h3><?= e($planName); ?></h3>
                                <p><?= e($planDescription); ?></p>
                            </div>

                            <div class="st-price">
                                <strong><?= e(stMoney($amount, $currency)); ?></strong>
                                <span><?= e(stLabel($cycle)); ?></span>
                            </div>
                        </div>

                        <div class="st-info-grid">
                            <div class="st-info">
                                <div class="st-info-label">Status</div>
                                <span class="st-info-value">
                                    <span class="st-status <?= e(preg_replace('/[^a-z0-9_-]/', '', strtolower($subscriptionStatus))); ?>">
                                        <?= e(stLabel($subscriptionStatus)); ?>
                                    </span>
                                </span>
                            </div>

                            <div class="st-info">
                                <div class="st-info-label">Starts</div>
                                <span class="st-info-value">
                                    <?= e(stDate(isset($subscription['starts_at']) ? $subscription['starts_at'] : $tenant['created_at'])); ?>
                                </span>
                            </div>

                            <div class="st-info">
                                <div class="st-info-label">Ends</div>
                                <span class="st-info-value">
                                    <?= e(stDate(isset($subscription['ends_at']) ? $subscription['ends_at'] : null)); ?>
                                </span>
                            </div>

                            <div class="st-info">
                                <div class="st-info-label">Trial Ends</div>
                                <span class="st-info-value">
                                    <?= e(stDate(
                                        !empty($subscription['subscription_trial_ends_at'])
                                            ? $subscription['subscription_trial_ends_at']
                                            : $tenant['trial_ends_at']
                                    )); ?>
                                </span>
                            </div>

                            <div class="st-info">
                                <div class="st-info-label">Active Users</div>
                                <span class="st-info-value">
                                    <?= e($activeUsers); ?> /
                                    <?= isset($subscription['max_users']) && $subscription['max_users'] !== null
                                        ? e((int) $subscription['max_users'])
                                        : 'Unlimited'; ?>
                                </span>
                            </div>

                            <div class="st-info">
                                <div class="st-info-label">Locations</div>
                                <span class="st-info-value">
                                    <?= e($locationCount); ?> /
                                    <?= isset($subscription['max_branches']) && $subscription['max_branches'] !== null
                                        ? e((int) $subscription['max_branches'])
                                        : 'Unlimited'; ?>
                                </span>
                            </div>

                            <div class="st-info">
                                <div class="st-info-label">Storage Limit</div>
                                <span class="st-info-value">
                                    <?php if (
                                        isset($subscription['storage_limit_mb']) &&
                                        $subscription['storage_limit_mb'] !== null
                                    ): ?>
                                        <?= e(number_format((float) $subscription['storage_limit_mb'] / 1024, 1)); ?> GB
                                    <?php else: ?>
                                        Unlimited
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="st-info">
                                <div class="st-info-label">Auto Renew</div>
                                <span class="st-info-value">
                                    <?= !empty($subscription['auto_renew']) ? 'Enabled' : 'Disabled'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
