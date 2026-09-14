<?php
/*
 * FieldPlx public Requests & Bookings API.
 * This file is intentionally self-contained and does not include a
 * Requests & Bookings common/helper file.
 * Compatible with PHP 7.2+.
 */

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$GLOBALS['rbp_response_sent'] = false;

function rbp_is_localhost()
{
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
    return strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
}

function rbp_safe_json($payload)
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
        $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
    }
    $json = json_encode($payload, $flags);
    if ($json !== false && $json !== '') return $json;

    return '{"success":false,"message":"Unable to encode the API response as JSON."}';
}

function rbp_fatal_shutdown()
{
    if (!empty($GLOBALS['rbp_response_sent'])) return;

    $error = error_get_last();
    if (!$error) return;

    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array((int)$error['type'], $fatalTypes, true)) return;

    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    $message = 'The public form API encountered a server error.';
    $extra = array();
    if (rbp_is_localhost()) {
        $extra['debug'] = basename((string)$error['file']) . ':' . (int)$error['line'] . ' - ' . (string)$error['message'];
    }

    echo rbp_safe_json(array_merge(array(
        'success' => false,
        'message' => $message,
        'error_code' => 'PUBLIC_API_FATAL'
    ), $extra));
}
register_shutdown_function('rbp_fatal_shutdown');

/* Public form: load the same PDO bootstrap used by FieldPlx business/auth pages,
   but do not load auth.php because this endpoint must remain publicly accessible. */
$dbFile = __DIR__ . '/../includes/database.php';
if (!is_file($dbFile)) {
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $GLOBALS['rbp_response_sent'] = true;
    echo rbp_safe_json(array(
        'success' => false,
        'message' => 'FieldPlx database bootstrap file business/includes/database.php was not found.',
        'error_code' => 'DB_BOOTSTRAP_NOT_FOUND'
    ));
    exit;
}

require_once $dbFile;

/* business/includes/database.php normally creates $pdo. If it only defines DB_* constants,
   create the identical PDO connection here without requiring any common Requests file. */
if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    } elseif (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
        $dbPort = defined('DB_PORT') ? (string)DB_PORT : '3306';
        $dbCharset = defined('DB_CHARSET') ? (string)DB_CHARSET : 'utf8mb4';
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . $dbPort . ';dbname=' . DB_NAME . ';charset=' . $dbCharset;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false
            ));
        } catch (Throwable $connectionError) {
            error_log('FieldPlx public form PDO connection error: ' . $connectionError->getMessage());
        }
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $GLOBALS['rbp_response_sent'] = true;
    echo rbp_safe_json(array(
        'success' => false,
        'message' => 'FieldPlx PDO connection could not be initialized from business/includes/database.php.',
        'error_code' => 'PDO_NOT_AVAILABLE'
    ));
    exit;
}

/* This API is intentionally self-contained. Do not include a Requests & Bookings common helper file. */
function rb_table(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
    $stmt->execute(array(':table_name' => $table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function rb_column(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
    $stmt->execute(array(':table_name' => $table, ':column_name' => $column));
    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

function rb_json_decode($value, $default = array())
{
    if ($value === null || $value === '') return $default;
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : $default;
}

function rb_json_encode($value)
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function rb_token($bytes = 24)
{
    return bin2hex(random_bytes((int)$bytes));
}

function rb_default_builder($formType)
{
    $contact = array(
        'key' => 'contact_information',
        'title' => 'Contact information',
        'description' => '',
        'system' => 1,
        'items' => array(
            array('key'=>'first_name','kind'=>'standard','type'=>'short_answer','label'=>'First name','standard_key'=>'first_name','required'=>1,'width'=>'half'),
            array('key'=>'last_name','kind'=>'standard','type'=>'short_answer','label'=>'Last name','standard_key'=>'last_name','required'=>0,'width'=>'half'),
            array('key'=>'company_name','kind'=>'standard','type'=>'short_answer','label'=>'Company name','standard_key'=>'company_name','required'=>0,'width'=>'full'),
            array('key'=>'email','kind'=>'standard','type'=>'email','label'=>'Email','standard_key'=>'email','required'=>1,'width'=>'full'),
            array('key'=>'email_marketing_consent','kind'=>'standard','type'=>'checkbox','label'=>'I\'d like to receive marketing emails. Unsubscribe at any time.','standard_key'=>'email_marketing_consent','required'=>0,'width'=>'full'),
            array('key'=>'phone','kind'=>'standard','type'=>'phone','label'=>'Phone','standard_key'=>'phone','required'=>0,'width'=>'full'),
            array('key'=>'sms_marketing_consent','kind'=>'standard','type'=>'checkbox','label'=>'I also agree to receive marketing SMS. Reply STOP to opt out.','standard_key'=>'sms_marketing_consent','required'=>0,'width'=>'full'),
            array('key'=>'address','kind'=>'standard','type'=>'address','label'=>'Street address','standard_key'=>'address','required'=>0,'width'=>'full')
        )
    );
    $service = array(
        'key' => 'service_details',
        'title' => 'Service details',
        'description' => '',
        'system' => 0,
        'items' => array(
            array('key'=>'service_details','kind'=>'custom','type'=>'long_answer','label'=>'Please provide as much information as you can','required'=>1,'width'=>'full'),
            array('key'=>'work_images','kind'=>'custom','type'=>'upload_images','label'=>'Share images of the work to be done','required'=>0,'width'=>'full'),
            array('key'=>'lead_source','kind'=>'standard','type'=>'dropdown_single','label'=>'How did you hear about us?','standard_key'=>'lead_source','required'=>0,'width'=>'full','options'=>array('Existing Client','Facebook','Flyer','Google','Instagram','Other','Referral','Vehicle Wrap'))
        )
    );
    $actions = array();
    if ($formType === 'job_booking') {
        $actions[] = array('key'=>'job_booking','type'=>'add_job_booking','label'=>'Job booking','config'=>array());
    } elseif ($formType === 'assessment_booking') {
        $actions[] = array('key'=>'assessment_booking','type'=>'add_assessment','label'=>'Assessment booking','config'=>array());
    }
    return array('version'=>1,'sections'=>array($contact,$service),'actions'=>$actions);
}

function rb_default_booking_settings()
{
    return array(
        'earliest_availability_days' => 1,
        'max_booking_days_ahead' => 30,
        'booking_interval_minutes' => 30,
        'service_id' => null
    );
}

function rb_default_efficient_settings()
{
    return array(
        'mode' => 'fixed_buffer',
        'fixed_buffer_minutes' => 30,
        'drive_time_limit_minutes' => 30
    );
}

function rb_load_builder(PDO $pdo, $config)
{
    $builder = rb_json_decode(isset($config['builder_json']) ? $config['builder_json'] : null, array());
    if (!isset($builder['sections']) || !is_array($builder['sections'])) {
        $builder = rb_default_builder(isset($config['form_type']) ? $config['form_type'] : 'request');
    }
    if (!isset($builder['actions']) || !is_array($builder['actions'])) {
        $builder['actions'] = array();
    }
    return $builder;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function rbp_out($code, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) {
        http_response_code((int)$code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $GLOBALS['rbp_response_sent'] = true;
    echo rbp_safe_json(array_merge(
        array('success' => (bool)$success, 'message' => (string)$message),
        $extra
    ));
    exit;
}

function rbp_val($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}

function rbp_load_form(PDO $pdo, $token)
{
    $stmt = $pdo->prepare(
        "SELECT
            ft.id AS form_template_id,
            ft.tenant_id,
            ft.name,
            ft.description,
            ft.status,
            c.form_type,
            c.public_token,
            c.slug,
            c.form_pages,
            c.is_request_default,
            c.is_booking_default,
            c.require_booking_approval,
            c.service_area_enabled,
            c.confirmation_title,
            c.confirmation_message,
            c.confirmation_url,
            c.builder_json,
            c.booking_json,
            c.efficient_scheduling_json,
            c.google_analytics_code
         FROM form_templates ft
         INNER JOIN request_booking_form_configs c
           ON c.form_template_id = ft.id
          AND c.tenant_id = ft.tenant_id
         WHERE c.public_token = :token
           AND ft.status = 'active'
         LIMIT 1"
    );
    $stmt->execute(array(':token' => $token));
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function rbp_number(PDO $pdo, $tenantId, $branchId, $type)
{
    if (rb_table($pdo, 'document_sequences')) {
        $sql = "SELECT *
                FROM document_sequences
                WHERE tenant_id = :tenant
                  AND document_type = :type
                  AND is_active = 1
                  AND " . ($branchId > 0 ? "(branch_id = :branch OR branch_id IS NULL)" : "branch_id IS NULL") . "
                ORDER BY branch_id IS NULL ASC, id ASC
                LIMIT 1
                FOR UPDATE";
        $params = array(':tenant' => $tenantId, ':type' => $type);
        if ($branchId > 0) $params[':branch'] = $branchId;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $now = new DateTimeImmutable('now');
            $resetKey = 'never';
            if ($row['reset_period'] === 'monthly') {
                $resetKey = $now->format('Y-m');
            } elseif ($row['reset_period'] === 'yearly') {
                $resetKey = $now->format('Y');
            } elseif ($row['reset_period'] === 'financial_year') {
                $month = (int)$now->format('n');
                $year = (int)$now->format('Y');
                $startMonth = (int)$row['financial_year_start_month'];
                $fy = $month < $startMonth ? $year - 1 : $year;
                $resetKey = $fy . '-' . ($fy + 1);
            }

            $current = (int)$row['current_number'];
            if ($row['reset_period'] !== 'never' && (string)$row['last_reset_key'] !== $resetKey) {
                $current = 0;
            }
            $current++;

            $pdo->prepare(
                "UPDATE document_sequences
                 SET current_number = :number,
                     last_reset_key = :reset_key,
                     updated_at = NOW()
                 WHERE id = :id"
            )->execute(array(
                ':number' => $current,
                ':reset_key' => $resetKey,
                ':id' => $row['id']
            ));

            $middle = '';
            if ($row['middle_format'] === 'year') {
                $middle = $now->format('Y');
            } elseif ($row['middle_format'] === 'year_month') {
                $middle = $now->format('Ym');
            } elseif ($row['middle_format'] === 'financial_year') {
                $month = (int)$now->format('n');
                $year = (int)$now->format('Y');
                $startMonth = (int)$row['financial_year_start_month'];
                $fy = $month < $startMonth ? $year - 1 : $year;
                $middle = $fy . '-' . substr((string)($fy + 1), -2);
            } elseif ($row['middle_format'] === 'branch_year') {
                $middle = ($branchId > 0 ? (string)$branchId : '') . $now->format('Y');
            }

            $parts = array();
            if (trim((string)$row['prefix']) !== '') $parts[] = trim((string)$row['prefix']);
            if ($middle !== '') $parts[] = $middle;
            $parts[] = str_pad((string)$current, max(1, (int)$row['number_length']), '0', STR_PAD_LEFT);
            if (trim((string)$row['suffix']) !== '') $parts[] = trim((string)$row['suffix']);
            return implode((string)$row['number_separator'], $parts);
        }
    }

    $prefix = $type === 'request' ? 'REQ' : ($type === 'booking' ? 'BOOK' : ($type === 'assessment' ? 'ASM' : strtoupper(substr($type, 0, 3))));
    return $prefix . '-' . date('YmdHis') . '-' . str_pad((string)random_int(1, 999), 3, '0', STR_PAD_LEFT);
}

function rbp_client(PDO $pdo, $tenantId, $branchId, $data)
{
    $email = trim(isset($data['email']) ? (string)$data['email'] : '');
    $phone = trim(isset($data['phone']) ? (string)$data['phone'] : '');
    $clientId = 0;

    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE tenant_id=:tenant AND email=:email AND status<>'archived' ORDER BY id DESC LIMIT 1");
        $stmt->execute(array(':tenant' => $tenantId, ':email' => $email));
        $clientId = (int)$stmt->fetchColumn();
    }
    if ($clientId <= 0 && $phone !== '') {
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE tenant_id=:tenant AND phone=:phone AND status<>'archived' ORDER BY id DESC LIMIT 1");
        $stmt->execute(array(':tenant' => $tenantId, ':phone' => $phone));
        $clientId = (int)$stmt->fetchColumn();
    }
    if ($clientId > 0) return $clientId;

    $first = trim(isset($data['first_name']) ? (string)$data['first_name'] : '');
    $last = trim(isset($data['last_name']) ? (string)$data['last_name'] : '');
    $company = trim(isset($data['company_name']) ? (string)$data['company_name'] : '');
    $display = trim($first . ' ' . $last);
    if ($display === '') $display = $company !== '' ? $company : ($email !== '' ? $email : ($phone !== '' ? $phone : 'Website lead'));
    $leadSource = 'website';
    if (isset($data['_meta']['source']) && trim((string)$data['_meta']['source']) !== '') {
        $leadSource = substr(trim((string)$data['_meta']['source']), 0, 120);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO clients
         (tenant_id,branch_id,client_type,display_name,company_name,first_name,last_name,email,phone,source,allow_email,allow_sms,status)
         VALUES
         (:tenant,:branch,'lead',:display,:company,:first,:last,:email,:phone,:source,:allow_email,:allow_sms,'new')"
    );
    $stmt->execute(array(
        ':tenant' => $tenantId,
        ':branch' => $branchId > 0 ? $branchId : null,
        ':display' => substr($display, 0, 190),
        ':company' => $company !== '' ? $company : null,
        ':first' => $first !== '' ? $first : null,
        ':last' => $last !== '' ? $last : null,
        ':email' => $email !== '' ? $email : null,
        ':phone' => $phone !== '' ? $phone : null,
        ':source' => $leadSource,
        ':allow_email' => !empty($data['email_marketing_consent']) ? 1 : 0,
        ':allow_sms' => !empty($data['sms_marketing_consent']) ? 1 : 0
    ));
    return (int)$pdo->lastInsertId();
}

function rbp_location(PDO $pdo, $tenantId, $clientId, $data)
{
    $requiresServiceAction = false;
    $requiresBookingAction = false;
    foreach ((array)$builder['sections'] as $section) {
        foreach ((array)(isset($section['items']) ? $section['items'] : array()) as $item) {
            if (!is_array($item) || !isset($item['kind']) || (string)$item['kind'] !== 'action') continue;
            $actionType = isset($item['action_type']) ? (string)$item['action_type'] : '';
            if ($actionType === '' && isset($item['type'])) {
                if ($item['type'] === 'products_services_action') $actionType = 'add_products_services';
                if ($item['type'] === 'job_booking_action') $actionType = 'add_job_booking';
                if ($item['type'] === 'assessment_booking_action') $actionType = 'add_assessment';
            }
            if (!empty($item['required']) && $actionType === 'add_products_services') $requiresServiceAction = true;
            if (!empty($item['required']) && ($actionType === 'add_job_booking' || $actionType === 'add_assessment')) $requiresBookingAction = true;
        }
    }
    if ($requiresServiceAction && empty($data['_catalog_item_key'])) {
        rbp_out(422, false, 'Select at least one product or service.');
    }
    if ($requiresBookingAction) {
        $requiredDate = isset($data['_booking_date']) ? trim((string)$data['_booking_date']) : '';
        $requiredTime = isset($data['_booking_time']) ? trim((string)$data['_booking_time']) : '';
        if ($requiredDate === '' || $requiredTime === '') {
            rbp_out(422, false, 'Select a booking date and time.');
        }
    }

    $address = isset($data['address']) && is_array($data['address']) ? $data['address'] : array();
    $line1 = trim(isset($address['line1']) ? (string)$address['line1'] : (isset($address['address_line1']) ? (string)$address['address_line1'] : ''));
    if ($line1 === '') return null;

    $stmt = $pdo->prepare(
        "INSERT INTO client_locations
         (tenant_id,client_id,location_type,name,address_line1,address_line2,city,state,postal_code,latitude,longitude)
         VALUES
         (:tenant,:client,'site','Service Address',:line1,:line2,:city,:state,:postal,:lat,:lng)"
    );
    $stmt->execute(array(
        ':tenant' => $tenantId,
        ':client' => $clientId,
        ':line1' => substr($line1, 0, 255),
        ':line2' => !empty($address['line2']) ? substr((string)$address['line2'], 0, 255) : (!empty($address['address_line2']) ? substr((string)$address['address_line2'], 0, 255) : null),
        ':city' => !empty($address['city']) ? substr((string)$address['city'], 0, 120) : null,
        ':state' => !empty($address['state']) ? substr((string)$address['state'], 0, 120) : null,
        ':postal' => !empty($address['postal_code']) ? substr((string)$address['postal_code'], 0, 40) : null,
        ':lat' => isset($address['latitude']) && $address['latitude'] !== '' ? (float)$address['latitude'] : null,
        ':lng' => isset($address['longitude']) && $address['longitude'] !== '' ? (float)$address['longitude'] : null
    ));
    return (int)$pdo->lastInsertId();
}

function rbp_distance_km($lat1, $lon1, $lat2, $lon2)
{
    $earth = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLon / 2) * sin($dLon / 2);
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function rbp_services(PDO $pdo, $tenantId)
{
    if (!rb_table($pdo, 'bookable_services')) return array();
    $stmt = $pdo->prepare(
        "SELECT id,product_service_id,name,description,estimated_price,duration_minutes
         FROM bookable_services
         WHERE tenant_id=:tenant AND is_active=1
         ORDER BY name"
    );
    $stmt->execute(array(':tenant' => $tenantId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function rbp_catalog(PDO $pdo, $tenantId)
{
    $items = array();
    $bookableMap = array();
    if (rb_table($pdo, 'bookable_services') && rb_column($pdo, 'bookable_services', 'product_service_id')) {
        $stmt = $pdo->prepare("SELECT id,product_service_id,duration_minutes FROM bookable_services WHERE tenant_id=:tenant AND is_active=1 AND product_service_id IS NOT NULL");
        $stmt->execute(array(':tenant' => $tenantId));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['product_service_id'];
            if ($pid > 0 && !isset($bookableMap[$pid])) {
                $bookableMap[$pid] = array('id'=>(int)$row['id'],'duration_minutes'=>(int)$row['duration_minutes']);
            }
        }
    }
    if (rb_table($pdo, 'product_services')) {
        $cols = "id,item_type,name,description";
        $cols .= rb_column($pdo, 'product_services', 'unit_price') ? ",unit_price" : ",0 AS unit_price";
        $sql = "SELECT " . $cols . " FROM product_services WHERE tenant_id=:tenant";
        if (rb_column($pdo, 'product_services', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
        if (rb_column($pdo, 'product_services', 'status')) $sql .= " AND status='active'";
        $sql .= " ORDER BY name,id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(':tenant' => $tenantId));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['id'];
            $bookable = isset($bookableMap[$pid]) ? $bookableMap[$pid] : null;
            $items[] = array(
                'key' => 'product_services:' . $pid,
                'id' => $pid,
                'source_table' => 'product_services',
                'item_type' => isset($row['item_type']) ? (string)$row['item_type'] : 'service',
                'name' => (string)$row['name'],
                'description' => isset($row['description']) ? $row['description'] : null,
                'unit_price' => (float)$row['unit_price'],
                'bookable_service_id' => $bookable ? (int)$bookable['id'] : null,
                'duration_minutes' => $bookable ? (int)$bookable['duration_minutes'] : null
            );
        }
    }
    if (rb_table($pdo, 'products')) {
        $cols = "id,name,description";
        $cols .= rb_column($pdo, 'products', 'selling_price') ? ",selling_price" : ",0 AS selling_price";
        $sql = "SELECT " . $cols . " FROM products WHERE tenant_id=:tenant";
        if (rb_column($pdo, 'products', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
        if (rb_column($pdo, 'products', 'status')) $sql .= " AND status='active'";
        $sql .= " ORDER BY name,id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(':tenant' => $tenantId));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = array(
                'key' => 'products:' . (int)$row['id'],
                'id' => (int)$row['id'],
                'source_table' => 'products',
                'item_type' => 'product',
                'name' => (string)$row['name'],
                'description' => isset($row['description']) ? $row['description'] : null,
                'unit_price' => (float)$row['selling_price'],
                'bookable_service_id' => null,
                'duration_minutes' => null
            );
        }
    }
    return $items;
}

function rbp_business_hours(PDO $pdo, $tenantId)
{
    if (!rb_table($pdo, 'tenant_business_hours')) return array();
    $stmt = $pdo->prepare(
        "SELECT day_of_week,is_closed,open_time,close_time
         FROM tenant_business_hours
         WHERE tenant_id=:tenant
         ORDER BY FIELD(day_of_week,'sunday','monday','tuesday','wednesday','thursday','friday','saturday')"
    );
    $stmt->execute(array(':tenant' => $tenantId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function rbp_availability(PDO $pdo, $form, $date, $serviceId)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return array();

    $tenantId = (int)$form['tenant_id'];
    $bookingCfg = rb_json_decode($form['booking_json'], rb_default_booking_settings());
    $efficientCfg = rb_json_decode($form['efficient_scheduling_json'], rb_default_efficient_settings());
    $builderCfg = rb_load_builder($pdo, $form);
    $configuredUsers = array();
    $configuredDuration = 0;
    foreach ((array)$builderCfg['sections'] as $section) {
        foreach ((array)(isset($section['items']) ? $section['items'] : array()) as $item) {
            if (!is_array($item) || !isset($item['kind']) || (string)$item['kind'] !== 'action') continue;
            $type = isset($item['action_type']) ? (string)$item['action_type'] : '';
            if ($type !== 'add_job_booking' && $type !== 'add_assessment') continue;
            if (!empty($item['user_ids']) && is_array($item['user_ids'])) {
                foreach ($item['user_ids'] as $uid) {
                    $uid = (int)$uid;
                    if ($uid > 0) $configuredUsers[$uid] = $uid;
                }
            }
            if (!empty($item['duration_minutes'])) $configuredDuration = max(15, (int)$item['duration_minutes']);
        }
    }
    foreach ((array)$builderCfg['actions'] as $action) {
        if (!is_array($action) || !isset($action['type']) || ($action['type'] !== 'add_job_booking' && $action['type'] !== 'add_assessment')) continue;
        $cfg = isset($action['config']) && is_array($action['config']) ? $action['config'] : array();
        if (empty($configuredUsers) && !empty($cfg['user_ids']) && is_array($cfg['user_ids'])) {
            foreach ($cfg['user_ids'] as $uid) {
                $uid = (int)$uid;
                if ($uid > 0) $configuredUsers[$uid] = $uid;
            }
        }
        if ($configuredDuration <= 0 && !empty($cfg['duration_minutes'])) $configuredDuration = max(15, (int)$cfg['duration_minutes']);
    }
    $configuredUsers = array_values($configuredUsers);

    $duration = $configuredDuration > 0 ? $configuredDuration : 60;
    if ($serviceId > 0 && rb_table($pdo, 'bookable_services')) {
        $stmt = $pdo->prepare("SELECT duration_minutes FROM bookable_services WHERE id=:id AND tenant_id=:tenant AND is_active=1 LIMIT 1");
        $stmt->execute(array(':id' => $serviceId, ':tenant' => $tenantId));
        $durationValue = $stmt->fetchColumn();
        if ($durationValue !== false) $duration = max(15, (int)$durationValue);
    }

    $day = strtolower(date('l', strtotime($date)));
    $open = '09:00:00';
    $close = '17:00:00';
    $closed = false;
    if (rb_table($pdo, 'tenant_business_hours')) {
        $stmt = $pdo->prepare("SELECT is_closed,open_time,close_time FROM tenant_business_hours WHERE tenant_id=:tenant AND day_of_week=:day LIMIT 1");
        $stmt->execute(array(':tenant' => $tenantId, ':day' => $day));
        $hours = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($hours) {
            $closed = (int)$hours['is_closed'] === 1;
            if (!empty($hours['open_time'])) $open = $hours['open_time'];
            if (!empty($hours['close_time'])) $close = $hours['close_time'];
        }
    }
    if ($closed) return array();

    $users = array();
    if ($serviceId > 0 && rb_table($pdo, 'bookable_service_team_members') && rb_table($pdo, 'users')) {
        $stmt = $pdo->prepare(
            "SELECT u.id
             FROM bookable_service_team_members bstm
             INNER JOIN users u
                ON u.id=bstm.user_id
               AND u.tenant_id=:tenant
               AND u.status='active'
               AND u.is_bookable=1
             WHERE bstm.bookable_service_id=:service
             ORDER BY u.id"
        );
        $stmt->execute(array(':tenant' => $tenantId, ':service' => $serviceId));
        $users = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!$users && rb_table($pdo, 'users')) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id=:tenant AND status='active' AND is_bookable=1 ORDER BY id");
        $stmt->execute(array(':tenant' => $tenantId));
        $users = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($configuredUsers) {
        $users = array_values(array_intersect($users, $configuredUsers));
    }

    $interval = max(15, isset($bookingCfg['booking_interval_minutes']) ? (int)$bookingCfg['booking_interval_minutes'] : 30);
    $buffer = 0;
    $mode = isset($efficientCfg['mode']) ? (string)$efficientCfg['mode'] : 'fixed_buffer';
    if ($mode === 'fixed_buffer') {
        $buffer = max(0, isset($efficientCfg['fixed_buffer_minutes']) ? (int)$efficientCfg['fixed_buffer_minutes'] : 30);
    } elseif ($mode === 'drive_time') {
        // Without live routing geometry, use the configured drive-time limit as a conservative spacing buffer.
        $buffer = max(0, isset($efficientCfg['drive_time_limit_minutes']) ? (int)$efficientCfg['drive_time_limit_minutes'] : 30);
    }

    $start = strtotime($date . ' ' . $open);
    $end = strtotime($date . ' ' . $close);
    $slots = array();

    for ($time = $start; $time + ($duration * 60) <= $end; $time += $interval * 60) {
        $slotEnd = $time + ($duration * 60);
        $availableUser = 0;

        if (!$users) {
            $availableUser = 0;
        } else {
            foreach ($users as $userId) {
                $conflict = false;
                if (rb_table($pdo, 'bookings')) {
                    $stmt = $pdo->prepare(
                        "SELECT COUNT(*)
                         FROM bookings
                         WHERE tenant_id=:tenant
                           AND assigned_user_id=:user
                           AND status IN ('submitted','confirmed')
                           AND scheduled_start IS NOT NULL
                           AND scheduled_end IS NOT NULL
                           AND scheduled_start < :slot_end
                           AND scheduled_end > :slot_start"
                    );
                    $stmt->execute(array(
                        ':tenant' => $tenantId,
                        ':user' => $userId,
                        ':slot_end' => date('Y-m-d H:i:s', $slotEnd + ($buffer * 60)),
                        ':slot_start' => date('Y-m-d H:i:s', $time - ($buffer * 60))
                    ));
                    $conflict = (int)$stmt->fetchColumn() > 0;
                }
                if (!$conflict) {
                    $availableUser = $userId;
                    break;
                }
            }
        }

        if ($availableUser > 0 || !$users) {
            $slots[] = array(
                'time' => date('H:i', $time),
                'label' => date('g:i A', $time),
                'user_id' => $availableUser
            );
        }
    }
    return $slots;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['health'])) {
        rbp_out(200, true, 'Public form API is available.', array(
            'php_version' => PHP_VERSION,
            'pdo' => true
        ));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
        $source = isset($_GET['source']) ? preg_replace('/[^a-z0-9_-]/i', '', strtolower((string)$_GET['source'])) : '';
        if ($token === '') rbp_out(422, false, 'Form token is required.');

        $form = rbp_load_form($pdo, $token);
        if (!$form) rbp_out(404, false, 'This form is not available.');

        $availabilityDate = isset($_GET['availability_date']) ? trim((string)$_GET['availability_date']) : '';
        if ($availabilityDate !== '') {
            $serviceId = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
            rbp_out(200, true, 'Availability loaded.', array(
                'slots' => rbp_availability($pdo, $form, $availabilityDate, $serviceId)
            ));
        }

        $tenantStmt = $pdo->prepare(
            "SELECT display_name,email,phone,website_url,logo_path,address_line1,address_line2,city,state,postal_code
             FROM tenants WHERE id=:tenant LIMIT 1"
        );
        $tenantStmt->execute(array(':tenant' => $form['tenant_id']));
        $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

        $builder = rb_load_builder($pdo, $form);
        $booking = rb_json_decode($form['booking_json'], rb_default_booking_settings());
        $serviceArea = null;
        if ((int)$form['service_area_enabled'] === 1 && rb_table($pdo, 'request_booking_service_areas')) {
            $stmt = $pdo->prepare(
                "SELECT id,address_text,latitude,longitude,radius_km
                 FROM request_booking_service_areas
                 WHERE tenant_id=:tenant AND is_active=1
                 ORDER BY id LIMIT 1"
            );
            $stmt->execute(array(':tenant' => $form['tenant_id']));
            $serviceArea = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (empty($_SESSION['request_booking_public_csrf'])) {
            $_SESSION['request_booking_public_csrf'] = bin2hex(random_bytes(32));
        }

        if ($source !== '' && rb_table($pdo, 'request_booking_tracking_links')) {
            $stmt = $pdo->prepare(
                "INSERT INTO request_booking_tracking_links
                 (tenant_id,form_template_id,source_key,source_label,tracking_token,clicks)
                 VALUES(:tenant,:form,:source,:label,:token,1)
                 ON DUPLICATE KEY UPDATE clicks=clicks+1,updated_at=NOW()"
            );
            $stmt->execute(array(
                ':tenant' => $form['tenant_id'],
                ':form' => $form['form_template_id'],
                ':source' => $source,
                ':label' => ucfirst($source),
                ':token' => rb_token(16)
            ));
        }

        $publicForm = array(
            'id' => (int)$form['form_template_id'],
            'name' => $form['name'],
            'description' => $form['description'],
            'form_type' => $form['form_type'],
            'form_pages' => (int)$form['form_pages'],
            'confirmation_title' => $form['confirmation_title'],
            'confirmation_message' => $form['confirmation_message'],
            'confirmation_url' => $form['confirmation_url'],
            'builder' => $builder,
            'booking' => $booking,
            'service_area_enabled' => (int)$form['service_area_enabled'],
            'google_analytics_code' => $form['google_analytics_code']
        );

        rbp_out(200, true, 'Form loaded.', array(
            'form' => $publicForm,
            'tenant' => $tenant,
            'services' => rbp_services($pdo, (int)$form['tenant_id']),
            'catalog_items' => rbp_catalog($pdo, (int)$form['tenant_id']),
            'service_area' => $serviceArea,
            'business_hours' => rbp_business_hours($pdo, (int)$form['tenant_id']),
            'csrf_token' => $_SESSION['request_booking_public_csrf']
        ));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        rbp_out(405, false, 'Unsupported request method.');
    }

    $token = rbp_val('token');
    $csrf = rbp_val('csrf_token');
    if (
        empty($_SESSION['request_booking_public_csrf']) ||
        $csrf === '' ||
        !hash_equals((string)$_SESSION['request_booking_public_csrf'], $csrf)
    ) {
        rbp_out(419, false, 'Your form session expired. Refresh and try again.');
    }

    $form = rbp_load_form($pdo, $token);
    if (!$form) rbp_out(404, false, 'This form is not available.');

    $data = json_decode(rbp_val('data_json', '{}'), true);
    if (!is_array($data)) rbp_out(422, false, 'Form data is invalid.');
    $builder = rb_load_builder($pdo, $form);

    foreach ($builder['sections'] as $section) {
        foreach ((array)$section['items'] as $item) {
            if (isset($item['kind']) && (string)$item['kind'] === 'action') continue;
            if (empty($item['required'])) continue;
            $key = isset($item['standard_key']) ? (string)$item['standard_key'] : (string)$item['key'];
            if (isset($item['type']) && $item['type'] === 'upload_images') {
                $hasUpload = false;
                if (isset($_FILES[$key])) {
                    $errors = is_array($_FILES[$key]['error']) ? $_FILES[$key]['error'] : array($_FILES[$key]['error']);
                    foreach ($errors as $error) {
                        if ((int)$error === UPLOAD_ERR_OK) { $hasUpload = true; break; }
                    }
                }
                if (!$hasUpload) rbp_out(422, false, (isset($item['label']) ? $item['label'] : 'An image') . ' is required.');
                continue;
            }
            $value = isset($data[$key]) ? $data[$key] : null;
            if ($value === null || $value === '' || (is_array($value) && !count(array_filter($value)))) {
                rbp_out(422, false, (isset($item['label']) ? $item['label'] : 'A required field') . ' is required.');
            }
        }
    }

    $address = isset($data['address']) && is_array($data['address']) ? $data['address'] : array();
    if ((int)$form['service_area_enabled'] === 1 && rb_table($pdo, 'request_booking_service_areas')) {
        $stmt = $pdo->prepare(
            "SELECT latitude,longitude,radius_km
             FROM request_booking_service_areas
             WHERE tenant_id=:tenant AND is_active=1
               AND latitude IS NOT NULL AND longitude IS NOT NULL
             ORDER BY id LIMIT 1"
        );
        $stmt->execute(array(':tenant' => $form['tenant_id']));
        $area = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($area && !empty($address['latitude']) && !empty($address['longitude'])) {
            $distance = rbp_distance_km(
                (float)$area['latitude'], (float)$area['longitude'],
                (float)$address['latitude'], (float)$address['longitude']
            );
            if ($distance > (float)$area['radius_km']) {
                rbp_out(422, false, 'This address is outside the available service area.');
            }
        }
    }

    $source = rbp_val('source');
    $data['_meta'] = array(
        'source' => $source,
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : null
    );

    $pdo->beginTransaction();

    $tenantId = (int)$form['tenant_id'];
    $clientId = rbp_client($pdo, $tenantId, 0, $data);
    $locationId = rbp_location($pdo, $tenantId, $clientId, $data);

    $requestId = null;
    $bookingId = null;
    $assessmentId = null;
    $serviceId = isset($data['_service_id']) ? (int)$data['_service_id'] : 0;
    $catalogItemKey = isset($data['_catalog_item_key']) ? trim((string)$data['_catalog_item_key']) : '';
    $assignedUserId = isset($data['_assigned_user_id']) ? (int)$data['_assigned_user_id'] : 0;
    $productServiceId = null;
    if ($catalogItemKey !== '' && preg_match('/^product_services:(\d+)$/', $catalogItemKey, $catalogMatch)) {
        $candidateProductServiceId = (int)$catalogMatch[1];
        if ($candidateProductServiceId > 0 && rb_table($pdo, 'product_services')) {
            $sql = "SELECT id FROM product_services WHERE id=:id AND tenant_id=:tenant";
            if (rb_column($pdo, 'product_services', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
            if (rb_column($pdo, 'product_services', 'status')) $sql .= " AND status='active'";
            $sql .= " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array(':id'=>$candidateProductServiceId, ':tenant'=>$tenantId));
            if ($stmt->fetchColumn()) $productServiceId = $candidateProductServiceId;
        }
    }
    $serviceDuration = 60;

    if ($serviceId > 0 && rb_table($pdo, 'bookable_services')) {
        $stmt = $pdo->prepare("SELECT product_service_id,duration_minutes FROM bookable_services WHERE id=:id AND tenant_id=:tenant AND is_active=1 LIMIT 1");
        $stmt->execute(array(':id' => $serviceId, ':tenant' => $tenantId));
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($service) {
            $productServiceId = $service['product_service_id'] ? (int)$service['product_service_id'] : null;
            $serviceDuration = max(15, (int)$service['duration_minutes']);
        }
    }

    $hasAssessmentAction = false;
    $hasJobBookingAction = false;
    foreach ((array)$builder['actions'] as $action) {
        if (!isset($action['type'])) continue;
        if ($action['type'] === 'add_assessment') $hasAssessmentAction = true;
        if ($action['type'] === 'add_job_booking') $hasJobBookingAction = true;
    }

    $needsRequest = $form['form_type'] === 'request' || $form['form_type'] === 'assessment_booking' || $hasAssessmentAction;
    if ($needsRequest) {
        $requestNo = rbp_number($pdo, $tenantId, 0, 'request');
        $description = '';
        foreach ($builder['sections'] as $section) {
            foreach ((array)$section['items'] as $item) {
                if (isset($item['type']) && $item['type'] === 'long_answer') {
                    $key = isset($item['standard_key']) ? $item['standard_key'] : $item['key'];
                    if (!empty($data[$key])) { $description = (string)$data[$key]; break 2; }
                }
            }
        }
        $requestStatus = $form['form_type'] === 'assessment_booking' || $hasAssessmentAction ? 'assessment_required' : 'new';
        $stmt = $pdo->prepare(
            "INSERT INTO service_requests
             (tenant_id,branch_id,request_no,client_id,location_id,product_service_id,source,priority,title,description,status)
             VALUES
             (:tenant,NULL,:number,:client,:location,:service,'website','normal',:title,:description,:status)"
        );
        $stmt->execute(array(
            ':tenant' => $tenantId,
            ':number' => $requestNo,
            ':client' => $clientId,
            ':location' => $locationId,
            ':service' => $productServiceId,
            ':title' => substr((string)$form['name'], 0, 190),
            ':description' => $description !== '' ? $description : null,
            ':status' => $requestStatus
        ));
        $requestId = (int)$pdo->lastInsertId();
    }

    $date = isset($data['_booking_date']) ? trim((string)$data['_booking_date']) : '';
    $time = isset($data['_booking_time']) ? trim((string)$data['_booking_time']) : '';
    $scheduledStart = null;
    $scheduledEnd = null;
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        if ($time === '') $time = '09:00';
        $scheduledStart = $date . ' ' . $time . ':00';
        $scheduledEnd = date('Y-m-d H:i:s', strtotime($scheduledStart) + ($serviceDuration * 60));
    }

    $hasJobBooking = $form['form_type'] === 'job_booking' || $hasJobBookingAction;
    if ($hasJobBooking) {
        $bookingNo = rbp_number($pdo, $tenantId, 0, 'booking');
        $customerName = trim((string)(isset($data['first_name']) ? $data['first_name'] : '') . ' ' . (string)(isset($data['last_name']) ? $data['last_name'] : ''));
        if ($customerName === '') $customerName = isset($data['company_name']) && $data['company_name'] !== '' ? (string)$data['company_name'] : 'Customer';
        $bookingStatus = !empty($form['require_booking_approval']) ? 'submitted' : 'confirmed';

        $stmt = $pdo->prepare(
            "INSERT INTO bookings
             (tenant_id,branch_id,booking_no,client_id,location_id,bookable_service_id,request_id,assigned_user_id,customer_name,customer_email,customer_phone,scheduled_start,scheduled_end,status,payload_json)
             VALUES
             (:tenant,NULL,:number,:client,:location,:service,:request,:assigned,:name,:email,:phone,:start,:end,:status,:payload)"
        );
        $stmt->execute(array(
            ':tenant' => $tenantId,
            ':number' => $bookingNo,
            ':client' => $clientId,
            ':location' => $locationId,
            ':service' => $serviceId > 0 ? $serviceId : null,
            ':request' => $requestId,
            ':assigned' => $assignedUserId > 0 ? $assignedUserId : null,
            ':name' => substr($customerName, 0, 190),
            ':email' => !empty($data['email']) ? substr((string)$data['email'], 0, 190) : null,
            ':phone' => !empty($data['phone']) ? substr((string)$data['phone'], 0, 50) : null,
            ':start' => $scheduledStart,
            ':end' => $scheduledEnd,
            ':status' => $bookingStatus,
            ':payload' => rb_json_encode($data)
        ));
        $bookingId = (int)$pdo->lastInsertId();
    }

    $hasAssessment = $form['form_type'] === 'assessment_booking' || $hasAssessmentAction;
    if ($hasAssessment && $requestId && rb_table($pdo, 'assessments')) {
        $assessmentNo = rbp_number($pdo, $tenantId, 0, 'assessment');
        $stmt = $pdo->prepare(
            "INSERT INTO assessments
             (tenant_id,branch_id,assessment_no,request_id,client_id,location_id,assigned_user_id,scheduled_start,scheduled_end,status,schedule_later)
             VALUES
             (:tenant,NULL,:number,:request,:client,:location,:assigned,:start,:end,:status,:later)"
        );
        $stmt->execute(array(
            ':tenant' => $tenantId,
            ':number' => $assessmentNo,
            ':request' => $requestId,
            ':client' => $clientId,
            ':location' => $locationId,
            ':assigned' => $assignedUserId > 0 ? $assignedUserId : null,
            ':start' => $scheduledStart,
            ':end' => $scheduledEnd,
            ':status' => $scheduledStart ? 'scheduled' : 'draft',
            ':later' => $scheduledStart ? 0 : 1
        ));
        $assessmentId = (int)$pdo->lastInsertId();
    }

    $data['_created'] = array(
        'client_id' => $clientId,
        'location_id' => $locationId,
        'request_id' => $requestId,
        'booking_id' => $bookingId,
        'assessment_id' => $assessmentId
    );

    $stmt = $pdo->prepare(
        "INSERT INTO form_submissions
         (tenant_id,form_template_id,job_id,visit_id,workflow_step_id,submitted_by,status,data_json)
         VALUES(:tenant,:form,NULL,NULL,NULL,NULL,'submitted',:data)"
    );
    $stmt->execute(array(
        ':tenant' => $tenantId,
        ':form' => $form['form_template_id'],
        ':data' => rb_json_encode($data)
    ));
    $submissionId = (int)$pdo->lastInsertId();

    if (!empty($_FILES) && rb_table($pdo, 'request_booking_submission_files')) {
        $base = dirname(__DIR__) . '/uploads/request-forms/' . $tenantId . '/' . date('Ym');
        if (!is_dir($base) && !@mkdir($base, 0775, true)) {
            throw new RuntimeException('Upload folder could not be created.');
        }
        $insertFile = $pdo->prepare(
            "INSERT INTO request_booking_submission_files
             (tenant_id,form_submission_id,field_key,original_name,stored_path,mime_type,size_bytes)
             VALUES(:tenant,:submission,:field,:name,:path,:mime,:size)"
        );

        foreach ($_FILES as $key => $file) {
            $names = is_array($file['name']) ? $file['name'] : array($file['name']);
            $tmps = is_array($file['tmp_name']) ? $file['tmp_name'] : array($file['tmp_name']);
            $sizes = is_array($file['size']) ? $file['size'] : array($file['size']);
            $errors = is_array($file['error']) ? $file['error'] : array($file['error']);

            foreach ($names as $i => $name) {
                if ((int)$errors[$i] === UPLOAD_ERR_NO_FILE) continue;
                if ((int)$errors[$i] !== UPLOAD_ERR_OK) throw new RuntimeException('An image upload failed.');
                if ((int)$sizes[$i] > 50 * 1024 * 1024) throw new RuntimeException('Each image must be 50MB or smaller.');
                $mime = function_exists('mime_content_type') ? mime_content_type($tmps[$i]) : '';
                if (strpos((string)$mime, 'image/') !== 0) throw new RuntimeException('Only image files are allowed.');
                $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
                if (!preg_match('/^[a-z0-9]{2,5}$/', $ext)) $ext = 'jpg';
                $fileName = rb_token(16) . '.' . $ext;
                $destination = $base . '/' . $fileName;
                if (!move_uploaded_file($tmps[$i], $destination)) throw new RuntimeException('An uploaded image could not be saved.');
                $relative = 'uploads/request-forms/' . $tenantId . '/' . date('Ym') . '/' . $fileName;
                $insertFile->execute(array(
                    ':tenant' => $tenantId,
                    ':submission' => $submissionId,
                    ':field' => substr((string)$key, 0, 120),
                    ':name' => substr((string)$name, 0, 255),
                    ':path' => $relative,
                    ':mime' => $mime,
                    ':size' => (int)$sizes[$i]
                ));
            }
        }
    }

    if ($source !== '' && rb_table($pdo, 'request_booking_tracking_links')) {
        $stmt = $pdo->prepare(
            "UPDATE request_booking_tracking_links
             SET submissions=submissions+1,updated_at=NOW()
             WHERE tenant_id=:tenant AND form_template_id=:form AND source_key=:source"
        );
        $stmt->execute(array(':tenant' => $tenantId, ':form' => $form['form_template_id'], ':source' => $source));
    }

    $pdo->commit();
    rbp_out(200, true, 'Thank you. Your form was submitted successfully.', array(
        'submission_id' => $submissionId,
        'confirmation_title' => $form['confirmation_title'],
        'confirmation_message' => $form['confirmation_message'],
        'confirmation_url' => $form['confirmation_url'],
        'booking_id' => $bookingId,
        'request_id' => $requestId,
        'assessment_id' => $assessmentId
    ));
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx public request form: ' . $e->getMessage());

    $message = $_SERVER['REQUEST_METHOD'] === 'GET'
        ? 'Unable to load the public form.'
        : 'Unable to submit the form. Please try again.';
    $extra = array('error_code' => 'PUBLIC_API_EXCEPTION');
    if (rbp_is_localhost()) {
        $extra['debug'] = $e->getMessage();
    }
    rbp_out(500, false, $message, $extra);
}
