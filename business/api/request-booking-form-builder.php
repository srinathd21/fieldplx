<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function rbbapi_out($code, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code((int)$code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(array('success' => (bool)$success, 'message' => (string)$message), $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function rbbapi_post($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}

function rbbapi_table(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name"
    );
    $stmt->execute(array(':table_name' => $table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function rbbapi_column(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name"
    );
    $stmt->execute(array(':table_name' => $table, ':column_name' => $column));
    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

function rbbapi_json_decode($value, $default = array())
{
    if ($value === null || $value === '') return $default;
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : $default;
}

function rbbapi_json_encode($value)
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function rbbapi_csrf()
{
    $token = rbbapi_post('csrf_token');
    if (
        empty($_SESSION['request_booking_csrf']) ||
        !is_string($_SESSION['request_booking_csrf']) ||
        $token === '' ||
        !hash_equals($_SESSION['request_booking_csrf'], $token)
    ) {
        rbbapi_out(419, false, 'Your form session expired. Refresh the page and try again.');
    }
}

function rbbapi_require_schema(PDO $pdo)
{
    $required = array(
        'form_templates',
        'form_fields',
        'request_booking_form_configs',
        'request_booking_form_sections',
        'request_booking_service_areas'
    );
    foreach ($required as $table) {
        if (!rbbapi_table($pdo, $table)) {
            rbbapi_out(500, false, 'Requests & Bookings database migration is not installed. Run database/request-booking-forms-migration.sql.');
        }
    }
}

function rbbapi_slug($value)
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');
    return $value !== '' ? substr($value, 0, 160) : 'form';
}

function rbbapi_unique_slug(PDO $pdo, $tenantId, $name, $excludeFormId)
{
    $base = rbbapi_slug($name);
    $slug = $base;
    $counter = 2;
    while (true) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM request_booking_form_configs
             WHERE tenant_id = :tenant_id
               AND slug = :slug
               AND form_template_id <> :exclude_form_id"
        );
        $stmt->execute(array(
            ':tenant_id' => $tenantId,
            ':slug' => $slug,
            ':exclude_form_id' => $excludeFormId
        ));
        if ((int)$stmt->fetchColumn() === 0) return $slug;
        $slug = substr($base, 0, 150) . '-' . $counter;
        $counter++;
    }
}

function rbbapi_token($bytes = 24)
{
    return bin2hex(random_bytes((int)$bytes));
}

function rbbapi_default_builder($formType)
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
            array('key'=>'service_details_' . substr(rbbapi_token(4),0,8),'kind'=>'custom','type'=>'long_answer','label'=>'Please provide as much information as you can','required'=>1,'width'=>'full'),
            array('key'=>'work_images_' . substr(rbbapi_token(4),0,8),'kind'=>'custom','type'=>'upload_images','label'=>'Share images of the work to be done','required'=>0,'width'=>'full'),
            array('key'=>'lead_source_' . substr(rbbapi_token(4),0,8),'kind'=>'standard','type'=>'dropdown_single','label'=>'How did you hear about us?','standard_key'=>'lead_source','required'=>0,'width'=>'full','options'=>array('Existing Client','Facebook','Flyer','Google','Instagram','Other','Referral','Vehicle Wrap'))
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

function rbbapi_default_booking_settings()
{
    return array(
        'earliest_availability_days' => 1,
        'max_booking_days_ahead' => 30,
        'booking_interval_minutes' => 30,
        'service_id' => null
    );
}

function rbbapi_default_efficient_settings()
{
    return array(
        'mode' => 'fixed_buffer',
        'fixed_buffer_minutes' => 30,
        'drive_time_limit_minutes' => 30
    );
}

function rbbapi_builder_type_to_db($type)
{
    $map = array(
        'short_answer'=>'text','email'=>'text','phone'=>'text','address'=>'text','area'=>'text','yes_no'=>'checkbox',
        'long_answer'=>'textarea','dropdown_multiple'=>'multiselect','dropdown_single'=>'select','checkbox'=>'checkbox',
        'radio'=>'radio','number'=>'number','upload_images'=>'photo','date'=>'date'
    );
    return isset($map[$type]) ? $map[$type] : 'text';
}

function rbbapi_sync_form_fields(PDO $pdo, $tenantId, $formId, $builder)
{
    $pdo->prepare("DELETE FROM form_fields WHERE form_template_id = :form_id")
        ->execute(array(':form_id' => $formId));
    $pdo->prepare(
        "DELETE FROM request_booking_form_sections
         WHERE form_template_id = :form_id AND tenant_id = :tenant_id"
    )->execute(array(':form_id' => $formId, ':tenant_id' => $tenantId));

    $insertSection = $pdo->prepare(
        "INSERT INTO request_booking_form_sections
         (tenant_id,form_template_id,section_key,title,description,is_system,sort_order)
         VALUES(:tenant_id,:form_id,:section_key,:title,:description,:is_system,:sort_order)"
    );
    $insertField = $pdo->prepare(
        "INSERT INTO form_fields
         (form_template_id,label,field_key,field_type,options_json,placeholder,validation_json,is_required,sort_order)
         VALUES(:form_id,:label,:field_key,:field_type,:options_json,:placeholder,:validation_json,:is_required,:sort_order)"
    );

    $fieldOrder = 0;
    $sections = isset($builder['sections']) && is_array($builder['sections']) ? $builder['sections'] : array();
    foreach ($sections as $sectionIndex => $section) {
        if (!is_array($section)) continue;
        $sectionKey = isset($section['key'])
            ? preg_replace('/[^a-zA-Z0-9_-]/', '_', substr((string)$section['key'], 0, 120))
            : 'section_' . ($sectionIndex + 1);
        if ($sectionKey === '') $sectionKey = 'section_' . ($sectionIndex + 1);

        $insertSection->execute(array(
            ':tenant_id' => $tenantId,
            ':form_id' => $formId,
            ':section_key' => $sectionKey,
            ':title' => substr(isset($section['title']) ? (string)$section['title'] : 'Section', 0, 190),
            ':description' => isset($section['description']) && trim((string)$section['description']) !== '' ? (string)$section['description'] : null,
            ':is_system' => !empty($section['system']) ? 1 : 0,
            ':sort_order' => $sectionIndex + 1
        ));

        $items = isset($section['items']) && is_array($section['items']) ? $section['items'] : array();
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            if (isset($item['kind']) && $item['kind'] === 'action') continue;
            $fieldOrder++;

            $fieldKey = isset($item['key'])
                ? preg_replace('/[^a-zA-Z0-9_-]/', '_', substr((string)$item['key'], 0, 120))
                : 'field_' . $fieldOrder;
            if ($fieldKey === '') $fieldKey = 'field_' . $fieldOrder;
            $builderType = isset($item['type']) ? (string)$item['type'] : 'short_answer';

            $validation = array(
                'builder_type' => $builderType,
                'section_key' => $sectionKey,
                'item_kind' => isset($item['kind']) ? (string)$item['kind'] : 'custom',
                'standard_key' => isset($item['standard_key']) ? (string)$item['standard_key'] : null,
                'help' => isset($item['help']) ? (string)$item['help'] : null,
                'width' => isset($item['width']) ? (string)$item['width'] : 'full'
            );

            $insertField->execute(array(
                ':form_id' => $formId,
                ':label' => substr(isset($item['label']) ? (string)$item['label'] : 'Question', 0, 190),
                ':field_key' => $fieldKey,
                ':field_type' => rbbapi_builder_type_to_db($builderType),
                ':options_json' => isset($item['options']) && is_array($item['options']) ? rbbapi_json_encode(array_values($item['options'])) : null,
                ':placeholder' => isset($item['placeholder']) && trim((string)$item['placeholder']) !== '' ? substr((string)$item['placeholder'], 0, 255) : null,
                ':validation_json' => rbbapi_json_encode($validation),
                ':is_required' => !empty($item['required']) ? 1 : 0,
                ':sort_order' => $fieldOrder
            ));
        }
    }
}

function rbbapi_load_builder($config)
{
    $builder = rbbapi_json_decode(isset($config['builder_json']) ? $config['builder_json'] : null, array());
    if (!isset($builder['sections']) || !is_array($builder['sections'])) {
        $builder = rbbapi_default_builder(isset($config['form_type']) ? $config['form_type'] : 'request');
    }
    if (!isset($builder['actions']) || !is_array($builder['actions'])) {
        $builder['actions'] = array();
    }
    return $builder;
}

function rbbapi_business_path()
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
    if (preg_match('#^(.*?/business)(?:/api)?/[^/]+$#', $script, $matches)) {
        return rtrim($matches[1], '/');
    }
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if (substr($dir, -4) === '/api') $dir = substr($dir, 0, -4);
    return $dir;
}

function rbbapi_public_url($token)
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
    return $scheme . '://' . $host . rbbapi_business_path() . '/request-booking-form.php?token=' . rawurlencode((string)$token);
}

function rbbapi_audit(PDO $pdo, $tenantId, $branchId, $userId, $action, $formId, $values)
{
    if (!function_exists('tenantAuditLog')) return;
    try {
        tenantAuditLog(
            $pdo,
            (string)$action,
            $tenantId,
            $branchId > 0 ? $branchId : null,
            $userId,
            'request_booking_form',
            $formId,
            null,
            $values
        );
    } catch (Throwable $e) {
        error_log('FieldPlx request booking builder audit: ' . $e->getMessage());
    }
}

$tenantId = isset($currentTenantId)
    ? (int)$currentTenantId
    : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);
$userId = isset($currentTenantUserId)
    ? (int)$currentTenantUserId
    : (isset($_SESSION['user_id'])
        ? (int)$_SESSION['user_id']
        : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0));
$branchId = isset($currentBranchId)
    ? (int)$currentBranchId
    : (isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0);

if ($tenantId <= 0 || $userId <= 0) {
    rbbapi_out(401, false, 'Your authenticated tenant session is not available.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rbbapi_out(405, false, 'POST request required.');
}

rbbapi_csrf();
rbbapi_require_schema($pdo);
$action = rbbapi_post('action');

try {
    if ($action === 'get') {
        $id = (int)rbbapi_post('id', '0');
        if ($id <= 0) rbbapi_out(422, false, 'Invalid form.');

        $stmt = $pdo->prepare(
            "SELECT
                ft.id AS form_template_id,
                ft.name,
                ft.description,
                ft.related_module,
                ft.status,
                ft.created_at AS template_created_at,
                ft.updated_at AS template_updated_at,
                c.id AS config_id,
                c.tenant_id,
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
                c.google_business_profile_json,
                c.google_analytics_code,
                c.tracking_json,
                c.created_at AS config_created_at,
                c.updated_at AS config_updated_at
             FROM form_templates ft
             INNER JOIN request_booking_form_configs c
                ON c.form_template_id = ft.id
               AND c.tenant_id = ft.tenant_id
             WHERE ft.id = :form_id
               AND ft.tenant_id = :tenant_id
             LIMIT 1"
        );
        $stmt->execute(array(':form_id' => $id, ':tenant_id' => $tenantId));
        $form = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$form) rbbapi_out(404, false, 'Form not found.');

        $form['builder'] = rbbapi_load_builder($form);
        $form['booking'] = rbbapi_json_decode($form['booking_json'], rbbapi_default_booking_settings());
        $form['efficient_scheduling'] = rbbapi_json_decode($form['efficient_scheduling_json'], rbbapi_default_efficient_settings());
        $form['google_business_profile'] = rbbapi_json_decode($form['google_business_profile_json'], array('connected' => 0));
        $form['tracking'] = rbbapi_json_decode($form['tracking_json'], array('enabled' => 1));
        $form['public_url'] = rbbapi_public_url($form['public_token']);
        $form['embed_code'] = '<iframe src="'
            . htmlspecialchars($form['public_url'], ENT_QUOTES, 'UTF-8')
            . '&embed=1" style="width:100%;min-height:720px;border:0" loading="lazy"></iframe>';

        foreach (array('builder_json','booking_json','efficient_scheduling_json','google_business_profile_json','tracking_json') as $key) {
            unset($form[$key]);
        }

        $services = array();
        if (rbbapi_table($pdo, 'bookable_services')) {
            $serviceStmt = $pdo->prepare(
                "SELECT id,name,description,estimated_price,duration_minutes
                 FROM bookable_services
                 WHERE tenant_id = :tenant_id AND is_active = 1
                 ORDER BY name"
            );
            $serviceStmt->execute(array(':tenant_id' => $tenantId));
            $services = $serviceStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $catalogItems = array();
        $bookableByProductService = array();
        if (rbbapi_table($pdo, 'bookable_services') && rbbapi_column($pdo, 'bookable_services', 'product_service_id')) {
            $mapStmt = $pdo->prepare("SELECT id,product_service_id,duration_minutes FROM bookable_services WHERE tenant_id=:tenant_id AND is_active=1 AND product_service_id IS NOT NULL");
            $mapStmt->execute(array(':tenant_id' => $tenantId));
            foreach ($mapStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pid = (int)$row['product_service_id'];
                if ($pid > 0 && !isset($bookableByProductService[$pid])) {
                    $bookableByProductService[$pid] = array('id'=>(int)$row['id'],'duration_minutes'=>(int)$row['duration_minutes']);
                }
            }
        }
        if (rbbapi_table($pdo, 'product_services')) {
            $cols = "id,item_type,name,description";
            $cols .= rbbapi_column($pdo, 'product_services', 'unit_price') ? ",unit_price" : ",0 AS unit_price";
            $cols .= rbbapi_column($pdo, 'product_services', 'estimated_duration_minutes') ? ",estimated_duration_minutes" : ",NULL AS estimated_duration_minutes";
            $sql = "SELECT " . $cols . " FROM product_services WHERE tenant_id=:tenant_id";
            if (rbbapi_column($pdo, 'product_services', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
            if (rbbapi_column($pdo, 'product_services', 'status')) $sql .= " AND status='active'";
            $sql .= " ORDER BY name,id";
            $catStmt = $pdo->prepare($sql);
            $catStmt->execute(array(':tenant_id' => $tenantId));
            foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pid = (int)$row['id'];
                $bookable = isset($bookableByProductService[$pid]) ? $bookableByProductService[$pid] : null;
                $catalogItems[] = array(
                    'key' => 'product_services:' . $pid,
                    'id' => $pid,
                    'source_table' => 'product_services',
                    'item_type' => isset($row['item_type']) ? (string)$row['item_type'] : 'service',
                    'name' => (string)$row['name'],
                    'description' => isset($row['description']) ? $row['description'] : null,
                    'unit_price' => (float)$row['unit_price'],
                    'bookable_service_id' => $bookable ? (int)$bookable['id'] : null,
                    'duration_minutes' => $bookable ? (int)$bookable['duration_minutes'] : (isset($row['estimated_duration_minutes']) ? (int)$row['estimated_duration_minutes'] : null)
                );
            }
        }
        if (rbbapi_table($pdo, 'products')) {
            $cols = "id,name,description";
            $cols .= rbbapi_column($pdo, 'products', 'selling_price') ? ",selling_price" : ",0 AS selling_price";
            $sql = "SELECT " . $cols . " FROM products WHERE tenant_id=:tenant_id";
            if (rbbapi_column($pdo, 'products', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
            if (rbbapi_column($pdo, 'products', 'status')) $sql .= " AND status='active'";
            $sql .= " ORDER BY name,id";
            $productStmt = $pdo->prepare($sql);
            $productStmt->execute(array(':tenant_id' => $tenantId));
            foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $catalogItems[] = array(
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

        $users = array();
        if (rbbapi_table($pdo, 'users')) {
            $userStmt = $pdo->prepare(
                "SELECT id,first_name,last_name,email
                 FROM users
                 WHERE tenant_id = :tenant_id
                   AND status = 'active'
                   AND is_bookable = 1
                 ORDER BY first_name,last_name"
            );
            $userStmt->execute(array(':tenant_id' => $tenantId));
            $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $areaStmt = $pdo->prepare(
            "SELECT id,name,address_text,latitude,longitude,radius_km,is_active
             FROM request_booking_service_areas
             WHERE tenant_id = :tenant_id
               AND (branch_id IS NULL OR branch_id = :branch_id)
             ORDER BY is_active DESC,id ASC
             LIMIT 1"
        );
        $areaStmt->execute(array(':tenant_id' => $tenantId, ':branch_id' => $branchId));
        $serviceArea = $areaStmt->fetch(PDO::FETCH_ASSOC);
        if (!$serviceArea) {
            $serviceArea = array('id'=>0,'address_text'=>'','latitude'=>null,'longitude'=>null,'radius_km'=>25,'is_active'=>1);
        }

        $googleConnection = null;
        if (rbbapi_table($pdo, 'integration_connections')) {
            $googleStmt = $pdo->prepare(
                "SELECT id,name,provider,status
                 FROM integration_connections
                 WHERE tenant_id = :tenant_id
                   AND status = 'connected'
                   AND (LOWER(provider) LIKE '%google%' OR LOWER(integration_key) LIKE '%google%')
                 LIMIT 1"
            );
            $googleStmt->execute(array(':tenant_id' => $tenantId));
            $googleConnection = $googleStmt->fetch(PDO::FETCH_ASSOC);
            if (!$googleConnection) $googleConnection = null;
        }

        $tenantStmt = $pdo->prepare(
            "SELECT display_name,email,phone,website_url,address_line1,address_line2,city,state,postal_code
             FROM tenants
             WHERE id = :tenant_id
             LIMIT 1"
        );
        $tenantStmt->execute(array(':tenant_id' => $tenantId));
        $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

        rbbapi_out(200, true, 'Form loaded.', array(
            'form' => $form,
            'services' => $services,
            'catalog_items' => $catalogItems,
            'users' => $users,
            'service_area' => $serviceArea,
            'google_connection' => $googleConnection,
            'tenant' => $tenant
        ));
    }

    if ($action === 'save') {
        $id = (int)rbbapi_post('id', '0');
        if ($id <= 0) rbbapi_out(422, false, 'Invalid form.');

        $name = substr(rbbapi_post('name'), 0, 190);
        if ($name === '') rbbapi_out(422, false, 'Form title is required.');
        $description = rbbapi_post('description');

        $builder = rbbapi_json_decode(rbbapi_post('builder_json', '{}'), array());
        if (empty($builder['sections']) || !is_array($builder['sections'])) {
            rbbapi_out(422, false, 'The form must contain at least one section.');
        }
        if (!isset($builder['actions']) || !is_array($builder['actions'])) {
            $builder['actions'] = array();
        }

        $booking = rbbapi_json_decode(rbbapi_post('booking_json', '{}'), rbbapi_default_booking_settings());
        $efficient = rbbapi_json_decode(rbbapi_post('efficient_scheduling_json', '{}'), rbbapi_default_efficient_settings());
        $google = rbbapi_json_decode(rbbapi_post('google_business_profile_json', '{}'), array('connected' => 0));
        $tracking = rbbapi_json_decode(rbbapi_post('tracking_json', '{}'), array('enabled' => 1));

        $formPages = rbbapi_post('form_pages') === '1' ? 1 : 0;
        $requestDefault = rbbapi_post('is_request_default') === '1' ? 1 : 0;
        $bookingDefault = rbbapi_post('is_booking_default') === '1' ? 1 : 0;
        $approval = rbbapi_post('require_booking_approval') === '1' ? 1 : 0;
        $serviceAreaEnabled = rbbapi_post('service_area_enabled') === '1' ? 1 : 0;
        $confirmTitle = substr(rbbapi_post('confirmation_title'), 0, 190);
        $confirmMessage = rbbapi_post('confirmation_message');
        $confirmUrl = substr(rbbapi_post('confirmation_url'), 0, 1000);
        $gaCode = substr(rbbapi_post('google_analytics_code'), 0, 120);

        $check = $pdo->prepare(
            "SELECT c.form_type
             FROM request_booking_form_configs c
             INNER JOIN form_templates ft ON ft.id = c.form_template_id
             WHERE c.form_template_id = :form_id
               AND c.tenant_id = :config_tenant_id
               AND ft.tenant_id = :template_tenant_id
             LIMIT 1"
        );
        $check->execute(array(
            ':form_id' => $id,
            ':config_tenant_id' => $tenantId,
            ':template_tenant_id' => $tenantId
        ));
        $formType = $check->fetchColumn();
        if ($formType === false) rbbapi_out(404, false, 'Form not found.');
        if ($formType === 'request') $bookingDefault = 0;

        $pdo->beginTransaction();

        if ($requestDefault) {
            $clearRequest = $pdo->prepare(
                "UPDATE request_booking_form_configs
                 SET is_request_default = 0
                 WHERE tenant_id = :tenant_id AND form_template_id <> :form_id"
            );
            $clearRequest->execute(array(':tenant_id' => $tenantId, ':form_id' => $id));
        }
        if ($bookingDefault) {
            $clearBooking = $pdo->prepare(
                "UPDATE request_booking_form_configs
                 SET is_booking_default = 0
                 WHERE tenant_id = :tenant_id AND form_template_id <> :form_id"
            );
            $clearBooking->execute(array(':tenant_id' => $tenantId, ':form_id' => $id));
        }

        $templateUpdate = $pdo->prepare(
            "UPDATE form_templates
             SET name = :name,
                 description = :description,
                 status = 'active',
                 updated_at = NOW()
             WHERE id = :form_id AND tenant_id = :tenant_id"
        );
        $templateUpdate->execute(array(
            ':name' => $name,
            ':description' => $description !== '' ? $description : null,
            ':form_id' => $id,
            ':tenant_id' => $tenantId
        ));

        $slug = rbbapi_unique_slug($pdo, $tenantId, $name, $id);
        $configUpdate = $pdo->prepare(
            "UPDATE request_booking_form_configs
             SET slug = :slug,
                 form_pages = :form_pages,
                 is_request_default = :request_default,
                 is_booking_default = :booking_default,
                 require_booking_approval = :booking_approval,
                 service_area_enabled = :service_area_enabled,
                 confirmation_title = :confirmation_title,
                 confirmation_message = :confirmation_message,
                 confirmation_url = :confirmation_url,
                 builder_json = :builder_json,
                 booking_json = :booking_json,
                 efficient_scheduling_json = :efficient_json,
                 google_business_profile_json = :google_json,
                 google_analytics_code = :google_analytics_code,
                 tracking_json = :tracking_json,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE tenant_id = :tenant_id AND form_template_id = :form_id"
        );
        $configUpdate->execute(array(
            ':slug' => $slug,
            ':form_pages' => $formPages,
            ':request_default' => $requestDefault,
            ':booking_default' => $bookingDefault,
            ':booking_approval' => $approval,
            ':service_area_enabled' => $serviceAreaEnabled,
            ':confirmation_title' => $confirmTitle !== '' ? $confirmTitle : null,
            ':confirmation_message' => $confirmMessage !== '' ? $confirmMessage : null,
            ':confirmation_url' => $confirmUrl !== '' ? $confirmUrl : null,
            ':builder_json' => rbbapi_json_encode($builder),
            ':booking_json' => rbbapi_json_encode($booking),
            ':efficient_json' => rbbapi_json_encode($efficient),
            ':google_json' => rbbapi_json_encode($google),
            ':google_analytics_code' => $gaCode !== '' ? $gaCode : null,
            ':tracking_json' => rbbapi_json_encode($tracking),
            ':updated_by' => $userId,
            ':tenant_id' => $tenantId,
            ':form_id' => $id
        ));

        rbbapi_sync_form_fields($pdo, $tenantId, $id, $builder);
        $pdo->commit();

        rbbapi_audit($pdo, $tenantId, $branchId, $userId, 'REQUEST_BOOKING_FORM_UPDATED', $id, array(
            'name' => $name,
            'form_type' => $formType
        ));
        rbbapi_out(200, true, 'Form saved successfully.', array('id' => $id));
    }

    if ($action === 'save_service_area') {
        $id = (int)rbbapi_post('id', '0');
        $address = substr(rbbapi_post('address_text'), 0, 500);
        $lat = rbbapi_post('latitude');
        $lng = rbbapi_post('longitude');
        $radius = (float)rbbapi_post('radius_km', '25');
        if ($radius < 1) $radius = 1;
        if ($radius > 500) $radius = 500;

        $latValue = $lat === '' ? null : (float)$lat;
        $lngValue = $lng === '' ? null : (float)$lng;
        if ($latValue !== null && ($latValue < -90 || $latValue > 90)) rbbapi_out(422, false, 'Latitude is invalid.');
        if ($lngValue !== null && ($lngValue < -180 || $lngValue > 180)) rbbapi_out(422, false, 'Longitude is invalid.');

        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE request_booking_service_areas
                 SET address_text = :address_text,
                     latitude = :latitude,
                     longitude = :longitude,
                     radius_km = :radius_km,
                     is_active = 1,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :area_id AND tenant_id = :tenant_id"
            );
            $stmt->execute(array(
                ':address_text' => $address !== '' ? $address : null,
                ':latitude' => $latValue,
                ':longitude' => $lngValue,
                ':radius_km' => $radius,
                ':updated_by' => $userId,
                ':area_id' => $id,
                ':tenant_id' => $tenantId
            ));
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO request_booking_service_areas
                 (tenant_id,branch_id,name,address_text,latitude,longitude,radius_km,is_active,created_by,updated_by)
                 VALUES
                 (:tenant_id,:branch_id,'Primary service area',:address_text,:latitude,:longitude,:radius_km,1,:created_by,:updated_by)"
            );
            $stmt->execute(array(
                ':tenant_id' => $tenantId,
                ':branch_id' => $branchId > 0 ? $branchId : null,
                ':address_text' => $address !== '' ? $address : null,
                ':latitude' => $latValue,
                ':longitude' => $lngValue,
                ':radius_km' => $radius,
                ':created_by' => $userId,
                ':updated_by' => $userId
            ));
            $id = (int)$pdo->lastInsertId();
        }

        if (rbbapi_table($pdo, 'booking_settings')) {
            $rules = rbbapi_json_encode(array(
                'service_area_id' => $id,
                'address' => $address,
                'latitude' => $latValue,
                'longitude' => $lngValue,
                'radius_km' => $radius
            ));

            if ($branchId > 0) {
                $find = $pdo->prepare(
                    "SELECT id FROM booking_settings
                     WHERE tenant_id = :tenant_id AND branch_id = :branch_id LIMIT 1"
                );
                $find->execute(array(':tenant_id' => $tenantId, ':branch_id' => $branchId));
            } else {
                $find = $pdo->prepare(
                    "SELECT id FROM booking_settings
                     WHERE tenant_id = :tenant_id AND branch_id IS NULL LIMIT 1"
                );
                $find->execute(array(':tenant_id' => $tenantId));
            }
            $bookingSettingsId = (int)$find->fetchColumn();

            if ($bookingSettingsId > 0) {
                $update = $pdo->prepare(
                    "UPDATE booking_settings
                     SET service_area_rules_json = :rules_json, updated_at = NOW()
                     WHERE id = :booking_settings_id"
                );
                $update->execute(array(':rules_json' => $rules, ':booking_settings_id' => $bookingSettingsId));
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO booking_settings(tenant_id,branch_id,service_area_rules_json)
                     VALUES(:tenant_id,:branch_id,:rules_json)"
                );
                $insert->execute(array(
                    ':tenant_id' => $tenantId,
                    ':branch_id' => $branchId > 0 ? $branchId : null,
                    ':rules_json' => $rules
                ));
            }
        }

        rbbapi_out(200, true, 'Service area saved.', array('id' => $id));
    }

    if ($action === 'google_business_connection') {
        $id = (int)rbbapi_post('id', '0');
        $connect = rbbapi_post('connect') === '1';
        if ($id <= 0) rbbapi_out(422, false, 'Invalid form.');

        $formStmt = $pdo->prepare(
            "SELECT google_business_profile_json
             FROM request_booking_form_configs
             WHERE form_template_id = :form_id AND tenant_id = :tenant_id
             LIMIT 1"
        );
        $formStmt->execute(array(':form_id' => $id, ':tenant_id' => $tenantId));
        $raw = $formStmt->fetchColumn();
        if ($raw === false) rbbapi_out(404, false, 'Form not found.');

        if ($connect) {
            $connection = null;
            if (rbbapi_table($pdo, 'integration_connections')) {
                $connectionStmt = $pdo->prepare(
                    "SELECT id,name,provider
                     FROM integration_connections
                     WHERE tenant_id = :tenant_id
                       AND status = 'connected'
                       AND (LOWER(provider) LIKE '%google%' OR LOWER(integration_key) LIKE '%google%')
                     LIMIT 1"
                );
                $connectionStmt->execute(array(':tenant_id' => $tenantId));
                $connection = $connectionStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$connection) {
                rbbapi_out(422, false, 'Connect Google in Account Connections before connecting this form.');
            }
            $google = array(
                'connected' => 1,
                'connection_id' => (int)$connection['id'],
                'connected_at' => date('c')
            );
        } else {
            $google = array('connected' => 0);
        }

        $update = $pdo->prepare(
            "UPDATE request_booking_form_configs
             SET google_business_profile_json = :google_json,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE form_template_id = :form_id AND tenant_id = :tenant_id"
        );
        $update->execute(array(
            ':google_json' => rbbapi_json_encode($google),
            ':updated_by' => $userId,
            ':form_id' => $id,
            ':tenant_id' => $tenantId
        ));

        rbbapi_out(200, true, $connect
            ? 'Form connected to the available Google integration.'
            : 'Form connection removed.');
    }

    rbbapi_out(400, false, 'Unknown builder action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx request booking builder API: ' . $e->getMessage());
    rbbapi_out(500, false, 'Unable to process the form builder request. ' . $e->getMessage());
}
