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

function rbn_out($code, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(array('success' => (bool)$success, 'message' => (string)$message), $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function rbn_post($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }
    return trim((string)$_POST[$key]);
}

function rbn_table(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name"
    );
    $stmt->execute(array(':table_name' => $table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function rbn_json_encode($value)
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function rbn_csrf()
{
    $token = rbn_post('csrf_token');
    if (
        empty($_SESSION['request_booking_csrf']) ||
        !is_string($_SESSION['request_booking_csrf']) ||
        $token === '' ||
        !hash_equals($_SESSION['request_booking_csrf'], $token)
    ) {
        rbn_out(419, false, 'Your form session expired. Refresh the page and try again.');
    }
}

function rbn_require_schema(PDO $pdo)
{
    $required = array(
        'form_templates',
        'form_fields',
        'form_submissions',
        'request_booking_form_configs',
        'request_booking_form_sections'
    );
    foreach ($required as $table) {
        if (!rbn_table($pdo, $table)) {
            rbn_out(500, false, 'Requests & Bookings database migration is not installed. Run database/request-booking-forms-migration.sql.');
        }
    }
}

function rbn_slug($value)
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');
    return $value !== '' ? substr($value, 0, 160) : 'form';
}

function rbn_unique_slug(PDO $pdo, $tenantId, $name)
{
    $base = rbn_slug($name);
    $slug = $base;
    $counter = 2;
    while (true) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM request_booking_form_configs
             WHERE tenant_id = :tenant_id AND slug = :slug"
        );
        $stmt->execute(array(':tenant_id' => $tenantId, ':slug' => $slug));
        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = substr($base, 0, 150) . '-' . $counter;
        $counter++;
    }
}

function rbn_token($bytes = 24)
{
    return bin2hex(random_bytes((int)$bytes));
}

function rbn_default_builder($formType)
{
    $contact = array(
        'key' => 'contact_information',
        'title' => 'Contact information',
        'description' => '',
        'system' => 1,
        'items' => array(
            array('key' => 'first_name', 'kind' => 'standard', 'type' => 'short_answer', 'label' => 'First name', 'standard_key' => 'first_name', 'required' => 1, 'width' => 'half'),
            array('key' => 'last_name', 'kind' => 'standard', 'type' => 'short_answer', 'label' => 'Last name', 'standard_key' => 'last_name', 'required' => 0, 'width' => 'half'),
            array('key' => 'company_name', 'kind' => 'standard', 'type' => 'short_answer', 'label' => 'Company name', 'standard_key' => 'company_name', 'required' => 0, 'width' => 'full'),
            array('key' => 'email', 'kind' => 'standard', 'type' => 'email', 'label' => 'Email', 'standard_key' => 'email', 'required' => 1, 'width' => 'full'),
            array('key' => 'email_marketing_consent', 'kind' => 'standard', 'type' => 'checkbox', 'label' => 'I\'d like to receive marketing emails. Unsubscribe at any time.', 'standard_key' => 'email_marketing_consent', 'required' => 0, 'width' => 'full'),
            array('key' => 'phone', 'kind' => 'standard', 'type' => 'phone', 'label' => 'Phone', 'standard_key' => 'phone', 'required' => 0, 'width' => 'full', 'help' => 'By providing your phone number, you agree to receive transactional service messages. Message and data rates may apply.'),
            array('key' => 'sms_marketing_consent', 'kind' => 'standard', 'type' => 'checkbox', 'label' => 'I also agree to receive marketing SMS. Reply STOP to opt out.', 'standard_key' => 'sms_marketing_consent', 'required' => 0, 'width' => 'full'),
            array('key' => 'address', 'kind' => 'standard', 'type' => 'address', 'label' => 'Street address', 'standard_key' => 'address', 'required' => 0, 'width' => 'full')
        )
    );

    $service = array(
        'key' => 'service_details',
        'title' => 'Service details',
        'description' => '',
        'system' => 0,
        'items' => array(
            array('key' => 'service_details_' . substr(rbn_token(4), 0, 8), 'kind' => 'custom', 'type' => 'long_answer', 'label' => 'Please provide as much information as you can', 'required' => 1, 'width' => 'full'),
            array('key' => 'work_images_' . substr(rbn_token(4), 0, 8), 'kind' => 'custom', 'type' => 'upload_images', 'label' => 'Share images of the work to be done', 'required' => 0, 'width' => 'full'),
            array('key' => 'lead_source_' . substr(rbn_token(4), 0, 8), 'kind' => 'standard', 'type' => 'dropdown_single', 'label' => 'How did you hear about us?', 'standard_key' => 'lead_source', 'required' => 0, 'width' => 'full', 'options' => array('Existing Client', 'Facebook', 'Flyer', 'Google', 'Instagram', 'Other', 'Referral', 'Vehicle Wrap'))
        )
    );

    $actions = array();
    if ($formType === 'job_booking') {
        $actions[] = array('key' => 'job_booking', 'type' => 'add_job_booking', 'label' => 'Job booking', 'config' => array());
    } elseif ($formType === 'assessment_booking') {
        $actions[] = array('key' => 'assessment_booking', 'type' => 'add_assessment', 'label' => 'Assessment booking', 'config' => array());
    }

    return array('version' => 1, 'sections' => array($contact, $service), 'actions' => $actions);
}

function rbn_default_booking_settings()
{
    return array(
        'earliest_availability_days' => 1,
        'max_booking_days_ahead' => 30,
        'booking_interval_minutes' => 30,
        'service_id' => null
    );
}

function rbn_default_efficient_settings()
{
    return array(
        'mode' => 'fixed_buffer',
        'fixed_buffer_minutes' => 30,
        'drive_time_limit_minutes' => 30
    );
}

function rbn_builder_type_to_db($type)
{
    $map = array(
        'short_answer' => 'text',
        'email' => 'text',
        'phone' => 'text',
        'address' => 'text',
        'area' => 'text',
        'yes_no' => 'checkbox',
        'long_answer' => 'textarea',
        'dropdown_multiple' => 'multiselect',
        'dropdown_single' => 'select',
        'checkbox' => 'checkbox',
        'radio' => 'radio',
        'number' => 'number',
        'upload_images' => 'photo',
        'date' => 'date'
    );
    return isset($map[$type]) ? $map[$type] : 'text';
}

function rbn_sync_form_fields(PDO $pdo, $tenantId, $formId, $builder)
{
    $deleteFields = $pdo->prepare("DELETE FROM form_fields WHERE form_template_id = :form_id");
    $deleteFields->execute(array(':form_id' => $formId));

    $deleteSections = $pdo->prepare(
        "DELETE FROM request_booking_form_sections
         WHERE form_template_id = :form_id AND tenant_id = :tenant_id"
    );
    $deleteSections->execute(array(':form_id' => $formId, ':tenant_id' => $tenantId));

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

    $sortOrder = 0;
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

            $sortOrder++;
            $fieldKey = isset($item['key'])
                ? preg_replace('/[^a-zA-Z0-9_-]/', '_', substr((string)$item['key'], 0, 120))
                : 'field_' . $sortOrder;
            if ($fieldKey === '') $fieldKey = 'field_' . $sortOrder;

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
                ':field_type' => rbn_builder_type_to_db($builderType),
                ':options_json' => isset($item['options']) && is_array($item['options']) ? rbn_json_encode(array_values($item['options'])) : null,
                ':placeholder' => isset($item['placeholder']) && trim((string)$item['placeholder']) !== '' ? substr((string)$item['placeholder'], 0, 255) : null,
                ':validation_json' => rbn_json_encode($validation),
                ':is_required' => !empty($item['required']) ? 1 : 0,
                ':sort_order' => $sortOrder
            ));
        }
    }
}

function rbn_audit(PDO $pdo, $tenantId, $branchId, $userId, $formId, $name, $formType)
{
    if (!function_exists('tenantAuditLog')) return;
    try {
        tenantAuditLog(
            $pdo,
            'REQUEST_BOOKING_FORM_CREATED',
            $tenantId,
            $branchId > 0 ? $branchId : null,
            $userId,
            'request_booking_form',
            $formId,
            null,
            array('name' => $name, 'form_type' => $formType)
        );
    } catch (Throwable $e) {
        error_log('FieldPlx request booking new-form audit: ' . $e->getMessage());
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
    rbn_out(401, false, 'Your authenticated tenant session is not available.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rbn_out(405, false, 'POST request required.');
}

rbn_csrf();
rbn_require_schema($pdo);

try {
    $formType = rbn_post('form_type', 'request');
    if (!in_array($formType, array('request', 'job_booking', 'assessment_booking'), true)) {
        rbn_out(422, false, 'Choose a valid form type.');
    }

    $name = substr(rbn_post('name'), 0, 190);
    $description = rbn_post('description');
    $formPages = rbn_post('form_pages') === '1' ? 1 : 0;
    $requireBookingApproval = rbn_post('require_booking_approval') === '1' ? 1 : 0;
    $serviceAreaEnabled = rbn_post('service_area_enabled') === '1' ? 1 : 0;

    if ($formType === 'request') {
        $requireBookingApproval = 0;
        $serviceAreaEnabled = 0;
    } elseif ($formType === 'job_booking') {
        $requireBookingApproval = 0;
        $formPages = 0;
    } else {
        $formPages = 0;
    }
    if ($name === '') {
        rbn_out(422, false, 'Form title is required.');
    }

    $relatedModule = $formType === 'assessment_booking'
        ? 'assessment'
        : ($formType === 'job_booking' ? 'job' : 'request');

    $publicToken = rbn_token(24);
    $slug = rbn_unique_slug($pdo, $tenantId, $name);
    $builder = rbn_default_builder($formType);
    $booking = rbn_default_booking_settings();
    $efficient = rbn_default_efficient_settings();
    $efficientPosted = json_decode(rbn_post('efficient_scheduling_json', '{}'), true);
    if (is_array($efficientPosted)) {
        $efficient = array_merge($efficient, $efficientPosted);
    }
    $efficient['enabled'] = !empty($efficient['enabled']) ? 1 : 0;
    $efficient['mode'] = isset($efficient['mode']) && in_array($efficient['mode'], array('drive_time','fixed_buffer','none'), true) ? $efficient['mode'] : 'fixed_buffer';
    $efficient['fixed_buffer_minutes'] = max(0, min(240, (int)(isset($efficient['fixed_buffer_minutes']) ? $efficient['fixed_buffer_minutes'] : 30)));
    $efficient['drive_time_limit_minutes'] = max(5, min(240, (int)(isset($efficient['drive_time_limit_minutes']) ? $efficient['drive_time_limit_minutes'] : 30)));

    $serviceAreaAddress = substr(rbn_post('service_area_address'), 0, 500);
    $serviceAreaLatRaw = rbn_post('service_area_latitude');
    $serviceAreaLngRaw = rbn_post('service_area_longitude');
    $serviceAreaRadius = (float)rbn_post('service_area_radius_km', '25');
    if ($serviceAreaRadius < 1) $serviceAreaRadius = 1;
    if ($serviceAreaRadius > 500) $serviceAreaRadius = 500;
    $serviceAreaLat = $serviceAreaLatRaw === '' ? null : (float)$serviceAreaLatRaw;
    $serviceAreaLng = $serviceAreaLngRaw === '' ? null : (float)$serviceAreaLngRaw;
    if ($serviceAreaLat !== null && ($serviceAreaLat < -90 || $serviceAreaLat > 90)) rbn_out(422, false, 'Service area latitude is invalid.');
    if ($serviceAreaLng !== null && ($serviceAreaLng < -180 || $serviceAreaLng > 180)) rbn_out(422, false, 'Service area longitude is invalid.');

    $pdo->beginTransaction();

    $templateStmt = $pdo->prepare(
        "INSERT INTO form_templates
         (tenant_id,name,description,related_module,status,created_by)
         VALUES(:tenant_id,:name,:description,:related_module,'active',:created_by)"
    );
    $templateStmt->execute(array(
        ':tenant_id' => $tenantId,
        ':name' => $name,
        ':description' => $description !== '' ? $description : null,
        ':related_module' => $relatedModule,
        ':created_by' => $userId
    ));
    $formId = (int)$pdo->lastInsertId();

    /* Important: every named placeholder is unique. This prevents HY093 when native PDO prepares are enabled. */
    $configStmt = $pdo->prepare(
        "INSERT INTO request_booking_form_configs
         (tenant_id,form_template_id,form_type,public_token,slug,form_pages,
          require_booking_approval,service_area_enabled,
          confirmation_title,confirmation_message,builder_json,booking_json,
          efficient_scheduling_json,tracking_json,created_by,updated_by)
         VALUES
         (:tenant_id,:form_template_id,:form_type,:public_token,:slug,:form_pages,
          :require_booking_approval,:service_area_enabled,
          :confirmation_title,:confirmation_message,:builder_json,:booking_json,
          :efficient_scheduling_json,:tracking_json,:created_by,:updated_by)"
    );
    $configStmt->execute(array(
        ':tenant_id' => $tenantId,
        ':form_template_id' => $formId,
        ':form_type' => $formType,
        ':public_token' => $publicToken,
        ':slug' => $slug,
        ':form_pages' => $formPages,
        ':require_booking_approval' => $requireBookingApproval,
        ':service_area_enabled' => $serviceAreaEnabled,
        ':confirmation_title' => 'Thanks! We received your request.',
        ':confirmation_message' => 'We will review your information and be in touch soon.',
        ':builder_json' => rbn_json_encode($builder),
        ':booking_json' => rbn_json_encode($booking),
        ':efficient_scheduling_json' => rbn_json_encode($efficient),
        ':tracking_json' => rbn_json_encode(array('enabled' => 1)),
        ':created_by' => $userId,
        ':updated_by' => $userId
    ));

    rbn_sync_form_fields($pdo, $tenantId, $formId, $builder);

    /* Service area is tenant/branch scoped. The wizard only writes it after Create form. */
    if ($serviceAreaEnabled && rbn_table($pdo, 'request_booking_service_areas')) {
        $areaSelectSql = "SELECT id FROM request_booking_service_areas WHERE tenant_id=:tenant_id AND is_active=1";
        $areaSelectParams = array(':tenant_id' => $tenantId);
        if ($branchId > 0) {
            $areaSelectSql .= " AND (branch_id=:branch_id OR branch_id IS NULL) ORDER BY branch_id IS NULL ASC,id ASC";
            $areaSelectParams[':branch_id'] = $branchId;
        } else {
            $areaSelectSql .= " AND branch_id IS NULL ORDER BY id ASC";
        }
        $areaSelectSql .= " LIMIT 1";
        $areaSelect = $pdo->prepare($areaSelectSql);
        $areaSelect->execute($areaSelectParams);
        $areaId = (int)$areaSelect->fetchColumn();

        if ($areaId > 0) {
            $areaUpdate = $pdo->prepare(
                "UPDATE request_booking_service_areas
                 SET address_text=:address_text,latitude=:latitude,longitude=:longitude,radius_km=:radius_km,updated_by=:updated_by,updated_at=NOW()
                 WHERE id=:area_id AND tenant_id=:tenant_id"
            );
            $areaUpdate->execute(array(
                ':address_text' => $serviceAreaAddress !== '' ? $serviceAreaAddress : null,
                ':latitude' => $serviceAreaLat,
                ':longitude' => $serviceAreaLng,
                ':radius_km' => $serviceAreaRadius,
                ':updated_by' => $userId,
                ':area_id' => $areaId,
                ':tenant_id' => $tenantId
            ));
        } else {
            $areaInsert = $pdo->prepare(
                "INSERT INTO request_booking_service_areas
                 (tenant_id,branch_id,name,address_text,latitude,longitude,radius_km,is_active,created_by,updated_by)
                 VALUES(:tenant_id,:branch_id,'Primary service area',:address_text,:latitude,:longitude,:radius_km,1,:created_by,:updated_by)"
            );
            $areaInsert->execute(array(
                ':tenant_id' => $tenantId,
                ':branch_id' => $branchId > 0 ? $branchId : null,
                ':address_text' => $serviceAreaAddress !== '' ? $serviceAreaAddress : null,
                ':latitude' => $serviceAreaLat,
                ':longitude' => $serviceAreaLng,
                ':radius_km' => $serviceAreaRadius,
                ':created_by' => $userId,
                ':updated_by' => $userId
            ));
        }
    }

    $pdo->commit();
    rbn_audit($pdo, $tenantId, $branchId, $userId, $formId, $name, $formType);

    rbn_out(200, true, 'Form created successfully.', array(
        'id' => $formId,
        'redirect' => 'request-booking-form-builder.php?id=' . $formId
    ));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('FieldPlx request booking new-form API: ' . $e->getMessage());
    rbn_out(500, false, 'Unable to create the form. ' . $e->getMessage());
}
