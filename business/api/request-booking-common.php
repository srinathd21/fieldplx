<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/database.php';

function rbApiRespond($code, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message
    ), is_array($extra) ? $extra : array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rbTenantId()
{
    return !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
}

function rbUserId()
{
    if (!empty($_SESSION['tenant_user_id'])) return (int)$_SESSION['tenant_user_id'];
    if (!empty($_SESSION['user_id'])) return (int)$_SESSION['user_id'];
    return 0;
}

function rbRequireAuth()
{
    $tenantId = rbTenantId();
    $userId = rbUserId();
    if ($tenantId <= 0 || $userId <= 0) {
        rbApiRespond(401, false, 'Your session has expired. Please sign in again.', array('code' => 'auth_required'));
    }
    return array($tenantId, $userId);
}

function rbPost($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function rbRequireCsrf($sessionKey = 'request_booking_csrf')
{
    $posted = trim((string)rbPost('csrf_token', ''));
    $stored = isset($_SESSION[$sessionKey]) ? (string)$_SESSION[$sessionKey] : '';
    if ($posted === '' || $stored === '' || !hash_equals($stored, $posted)) {
        rbApiRespond(419, false, 'Your form session expired. Refresh the page and try again.', array('code' => 'csrf_failed'));
    }
}

function rbTableExists(PDO $pdo, $table)
{
    static $cache = array();
    $key = strtolower((string)$table);
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name");
    $stmt->execute(array(':table_name' => $table));
    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

function rbColumnExists(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = strtolower((string)$table . '.' . (string)$column);
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name");
    $stmt->execute(array(':table_name' => $table, ':column_name' => $column));
    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

function rbSchemaReady(PDO $pdo)
{
    return rbColumnExists($pdo, 'request_booking_form_configs', 'is_system_form')
        && rbColumnExists($pdo, 'request_booking_form_configs', 'system_key');
}

function rbSlug($value)
{
    $value = trim((string)$value);
    if ($value === '') return 'form';
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) $value = $converted;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string)$value, '-');
    return $value !== '' ? substr($value, 0, 160) : 'form';
}

function rbUniqueSlug(PDO $pdo, $tenantId, $name, $excludeConfigId = 0)
{
    $base = rbSlug($name);
    $candidate = $base;
    $suffix = 2;
    while (true) {
        $sql = "SELECT id FROM request_booking_form_configs WHERE tenant_id = :tenant_id AND slug = :slug";
        $params = array(':tenant_id' => $tenantId, ':slug' => $candidate);
        if ($excludeConfigId > 0) {
            $sql .= " AND id <> :exclude_id";
            $params[':exclude_id'] = $excludeConfigId;
        }
        $sql .= " LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetchColumn()) return $candidate;
        $candidate = substr($base, 0, 145) . '-' . $suffix;
        $suffix++;
    }
}

function rbContactSection()
{
    return array(
        'key' => 'contact_information',
        'title' => 'Contact information',
        'description' => '',
        'system' => 1,
        'items' => array(
            array('key'=>'first_name','kind'=>'standard','type'=>'short_answer','label'=>'First name','standard_key'=>'first_name','required'=>1,'width'=>'half'),
            array('key'=>'last_name','kind'=>'standard','type'=>'short_answer','label'=>'Last name','standard_key'=>'last_name','required'=>0,'width'=>'half'),
            array('key'=>'company_name','kind'=>'standard','type'=>'short_answer','label'=>'Company name','standard_key'=>'company_name','required'=>0,'width'=>'full'),
            array('key'=>'email','kind'=>'standard','type'=>'email','label'=>'Email','standard_key'=>'email','required'=>1,'width'=>'full'),
            array('key'=>'phone','kind'=>'standard','type'=>'phone','label'=>'Phone','standard_key'=>'phone','required'=>0,'width'=>'full'),
            array('key'=>'address','kind'=>'standard','type'=>'address','label'=>'Street address','standard_key'=>'address','required'=>0,'width'=>'full')
        )
    );
}

function rbServiceDetailsSection()
{
    return array(
        'key' => 'service_details',
        'title' => 'Service details',
        'description' => '',
        'system' => 0,
        'items' => array(
            array('key'=>'service_details_' . substr(bin2hex(random_bytes(5)),0,10),'kind'=>'custom','type'=>'long_answer','label'=>'Please provide as much information as you can','required'=>1,'width'=>'full'),
            array('key'=>'work_images_' . substr(bin2hex(random_bytes(5)),0,10),'kind'=>'custom','type'=>'upload_images','label'=>'Share images of the work to be done','required'=>0,'width'=>'full'),
            array('key'=>'lead_source_' . substr(bin2hex(random_bytes(5)),0,10),'kind'=>'standard','type'=>'dropdown_single','label'=>'How did you hear about us?','standard_key'=>'lead_source','required'=>0,'width'=>'full','options'=>array('Existing Client','Facebook','Flyer','Google','Instagram','Other','Referral','Vehicle Wrap'))
        )
    );
}

function rbStarterBuilder($type)
{
    $type = (string)$type;
    $sections = array(rbContactSection());
    $actions = array();

    if ($type === 'request') {
        $sections[] = rbServiceDetailsSection();
    } elseif ($type === 'job_booking') {
        $serviceAction = array(
            'key' => 'products_services_' . substr(bin2hex(random_bytes(4)),0,8),
            'kind' => 'action',
            'type' => 'products_services_action',
            'action_type' => 'add_products_services',
            'label' => 'Select your services',
            'required' => 1,
            'show_prices' => 0,
            'show_zero_as_free' => 0,
            'catalog_keys' => array()
        );
        $sections[0]['items'][] = $serviceAction;
        $bookingAction = array(
            'key' => 'job_booking_' . substr(bin2hex(random_bytes(4)),0,8),
            'kind' => 'action',
            'type' => 'job_booking_action',
            'action_type' => 'add_job_booking',
            'label' => 'Schedule job booking',
            'required' => 1,
            'user_ids' => array(),
            'duration_minutes' => 60
        );
        $sections[] = array('key'=>'schedule_job','title'=>'Schedule an appointment','description'=>'','system'=>0,'action_section'=>1,'items'=>array($bookingAction));
        $actions[] = array('key'=>$serviceAction['key'],'type'=>'add_products_services','label'=>$serviceAction['label'],'config'=>array('required'=>1,'show_prices'=>0,'show_zero_as_free'=>0,'catalog_keys'=>array()));
        $actions[] = array('key'=>$bookingAction['key'],'type'=>'add_job_booking','label'=>$bookingAction['label'],'config'=>array('required'=>1,'user_ids'=>array(),'duration_minutes'=>60));
    } else {
        $sections[] = rbServiceDetailsSection();
        $assessmentAction = array(
            'key' => 'assessment_booking_' . substr(bin2hex(random_bytes(4)),0,8),
            'kind' => 'action',
            'type' => 'assessment_booking_action',
            'action_type' => 'add_assessment',
            'label' => 'Schedule assessment',
            'required' => 1,
            'user_ids' => array(),
            'duration_minutes' => 60
        );
        $sections[] = array('key'=>'schedule_assessment','title'=>'Schedule an assessment','description'=>'','system'=>0,'action_section'=>1,'items'=>array($assessmentAction));
        $actions[] = array('key'=>$assessmentAction['key'],'type'=>'add_assessment','label'=>$assessmentAction['label'],'config'=>array('required'=>1,'user_ids'=>array(),'duration_minutes'=>60));
    }

    return array('version'=>2,'sections'=>$sections,'actions'=>$actions);
}

function rbRelatedModule($type)
{
    if ($type === 'assessment_booking') return 'assessment';
    if ($type === 'job_booking') return 'job';
    return 'request';
}

function rbFieldDbType($builderType)
{
    $map = array(
        'short_answer'=>'text','long_answer'=>'textarea','dropdown_multiple'=>'multiselect','dropdown_single'=>'select',
        'checkbox'=>'checkbox','radio'=>'radio','number'=>'number','upload_images'=>'photo','yes_no'=>'checkbox',
        'date'=>'date','area'=>'text','address'=>'text','email'=>'text','phone'=>'text'
    );
    return isset($map[$builderType]) ? $map[$builderType] : 'text';
}

function rbSyncBuilderTables(PDO $pdo, $tenantId, $formTemplateId, $builder)
{
    if (!rbTableExists($pdo, 'request_booking_form_sections') || !rbTableExists($pdo, 'form_fields')) return;
    $pdo->prepare("DELETE FROM request_booking_form_sections WHERE tenant_id = :tenant_id AND form_template_id = :form_template_id")
        ->execute(array(':tenant_id'=>$tenantId, ':form_template_id'=>$formTemplateId));
    $pdo->prepare("DELETE FROM form_fields WHERE form_template_id = :form_template_id")
        ->execute(array(':form_template_id'=>$formTemplateId));

    $sectionInsert = $pdo->prepare("INSERT INTO request_booking_form_sections (tenant_id, form_template_id, section_key, title, description, is_system, sort_order) VALUES (:tenant_id,:form_template_id,:section_key,:title,:description,:is_system,:sort_order)");
    $fieldInsert = $pdo->prepare("INSERT INTO form_fields (form_template_id,label,field_key,field_type,options_json,placeholder,validation_json,is_required,sort_order) VALUES (:form_template_id,:label,:field_key,:field_type,:options_json,NULL,:validation_json,:is_required,:sort_order)");
    $fieldOrder = 0;
    $sections = isset($builder['sections']) && is_array($builder['sections']) ? $builder['sections'] : array();
    foreach ($sections as $si => $section) {
        $sectionKey = !empty($section['key']) ? (string)$section['key'] : 'section_' . ($si + 1);
        $sectionInsert->execute(array(
            ':tenant_id'=>$tenantId,
            ':form_template_id'=>$formTemplateId,
            ':section_key'=>substr($sectionKey,0,120),
            ':title'=>substr((string)(isset($section['title'])?$section['title']:'Section'),0,190),
            ':description'=>isset($section['description']) && trim((string)$section['description'])!=='' ? (string)$section['description'] : null,
            ':is_system'=>!empty($section['system'])?1:0,
            ':sort_order'=>$si+1
        ));
        $items = isset($section['items']) && is_array($section['items']) ? $section['items'] : array();
        foreach ($items as $item) {
            if (isset($item['kind']) && $item['kind'] === 'action') continue;
            $fieldOrder++;
            $key = !empty($item['key']) ? (string)$item['key'] : 'field_' . $fieldOrder;
            $type = !empty($item['type']) ? (string)$item['type'] : 'short_answer';
            $validation = array(
                'builder_type'=>$type,
                'section_key'=>$sectionKey,
                'item_kind'=>isset($item['kind'])?(string)$item['kind']:'custom',
                'standard_key'=>isset($item['standard_key'])?$item['standard_key']:null,
                'help'=>isset($item['help'])?$item['help']:null,
                'width'=>isset($item['width'])?$item['width']:'full'
            );
            $fieldInsert->execute(array(
                ':form_template_id'=>$formTemplateId,
                ':label'=>substr((string)(isset($item['label'])?$item['label']:'Question'),0,190),
                ':field_key'=>substr($key,0,120),
                ':field_type'=>rbFieldDbType($type),
                ':options_json'=>isset($item['options']) && is_array($item['options']) ? json_encode(array_values($item['options']),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
                ':validation_json'=>json_encode($validation,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ':is_required'=>!empty($item['required'])?1:0,
                ':sort_order'=>$fieldOrder
            ));
        }
    }
}

function rbCreateForm(PDO $pdo, $tenantId, $userId, $name, $description, $type, $formPages, $systemKey = null)
{
    $type = in_array($type, array('request','job_booking','assessment_booking'), true) ? $type : 'request';
    $builder = rbStarterBuilder($type);
    $isSystem = $systemKey !== null ? 1 : 0;
    $requestDefault = 0;
    $bookingDefault = 0;
    if ($systemKey === 'default_request') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM request_booking_form_configs WHERE tenant_id=:tenant_id AND is_request_default=1");
        $stmt->execute(array(':tenant_id'=>$tenantId));
        $requestDefault = ((int)$stmt->fetchColumn() === 0) ? 1 : 0;
    } elseif ($systemKey === 'default_assessment') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM request_booking_form_configs WHERE tenant_id=:tenant_id AND is_booking_default=1");
        $stmt->execute(array(':tenant_id'=>$tenantId));
        $bookingDefault = ((int)$stmt->fetchColumn() === 0) ? 1 : 0;
    }
    $approval = $type === 'assessment_booking' ? 1 : 0;
    $slug = rbUniqueSlug($pdo, $tenantId, $name);
    $token = bin2hex(random_bytes(24));
    $booking = array('earliest_availability_days'=>1,'max_booking_days_ahead'=>30,'booking_interval_minutes'=>30,'service_id'=>null);
    $efficient = array('enabled'=>1,'mode'=>'fixed_buffer','fixed_buffer_minutes'=>30,'drive_time_limit_minutes'=>30);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO form_templates (tenant_id,name,description,related_module,status,created_by) VALUES (:tenant_id,:name,:description,:related_module,'active',:created_by)");
        $stmt->execute(array(':tenant_id'=>$tenantId,':name'=>$name,':description'=>$description!==''?$description:null,':related_module'=>rbRelatedModule($type),':created_by'=>$userId>0?$userId:null));
        $templateId = (int)$pdo->lastInsertId();

        $sql = "INSERT INTO request_booking_form_configs (tenant_id,form_template_id,form_type";
        if (rbColumnExists($pdo,'request_booking_form_configs','is_system_form')) $sql .= ",is_system_form,system_key";
        $sql .= ",public_token,slug,form_pages,is_request_default,is_booking_default,require_booking_approval,service_area_enabled,confirmation_title,confirmation_message,builder_json,booking_json,efficient_scheduling_json,google_business_profile_json,tracking_json,created_by,updated_by) VALUES (:tenant_id,:form_template_id,:form_type";
        if (rbColumnExists($pdo,'request_booking_form_configs','is_system_form')) $sql .= ",:is_system_form,:system_key";
        $sql .= ",:public_token,:slug,:form_pages,:is_request_default,:is_booking_default,:require_booking_approval,0,:confirmation_title,:confirmation_message,:builder_json,:booking_json,:efficient_json,:google_json,:tracking_json,:created_by,:updated_by)";
        $stmt = $pdo->prepare($sql);
        $params = array(
            ':tenant_id'=>$tenantId, ':form_template_id'=>$templateId, ':form_type'=>$type,
            ':public_token'=>$token, ':slug'=>$slug, ':form_pages'=>$formPages?1:0,
            ':is_request_default'=>$requestDefault, ':is_booking_default'=>$bookingDefault,
            ':require_booking_approval'=>$approval,
            ':confirmation_title'=>'Thanks! We received your request.',
            ':confirmation_message'=>'We will review your information and be in touch soon.',
            ':builder_json'=>json_encode($builder,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':booking_json'=>json_encode($booking,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':efficient_json'=>json_encode($efficient,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':google_json'=>json_encode(array('connected'=>0),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':tracking_json'=>json_encode(array('enabled'=>1),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':created_by'=>$userId>0?$userId:null, ':updated_by'=>$userId>0?$userId:null
        );
        if (rbColumnExists($pdo,'request_booking_form_configs','is_system_form')) {
            $params[':is_system_form']=$isSystem;
            $params[':system_key']=$systemKey;
        }
        $stmt->execute($params);
        $configId = (int)$pdo->lastInsertId();
        rbSyncBuilderTables($pdo,$tenantId,$templateId,$builder);
        $pdo->commit();
        return array('id'=>$configId,'form_template_id'=>$templateId,'public_token'=>$token,'builder'=>$builder);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function rbEnsureSystemForms(PDO $pdo, $tenantId, $userId)
{
    if (!rbSchemaReady($pdo)) return false;

    $defs = array(
        'default_assessment'=>array('name'=>'Assessment Booking Form','type'=>'assessment_booking','pages'=>1,'default_column'=>'is_booking_default'),
        'default_request'=>array('name'=>'Default Form','type'=>'request','pages'=>1,'default_column'=>'is_request_default')
    );

    foreach ($defs as $key=>$def) {
        $stmt = $pdo->prepare("SELECT id FROM request_booking_form_configs WHERE tenant_id=:tenant_id AND system_key=:system_key LIMIT 1");
        $stmt->execute(array(':tenant_id'=>$tenantId,':system_key'=>$key));
        $systemId = (int)$stmt->fetchColumn();

        if ($systemId <= 0) {
            /*
             * Upgrade existing installations without creating a duplicate.
             * If a tenant already has the Jobber-style built-in name/type, adopt
             * that row and protect it. Otherwise create the built-in form.
             */
            $stmt = $pdo->prepare("SELECT c.id
                FROM request_booking_form_configs c
                INNER JOIN form_templates t ON t.id=c.form_template_id AND t.tenant_id=c.tenant_id
                WHERE c.tenant_id=:tenant_id
                  AND c.form_type=:form_type
                  AND LOWER(TRIM(t.name))=LOWER(:name)
                ORDER BY c.id ASC
                LIMIT 1");
            $stmt->execute(array(':tenant_id'=>$tenantId,':form_type'=>$def['type'],':name'=>$def['name']));
            $systemId = (int)$stmt->fetchColumn();

            if ($systemId > 0) {
                $stmt = $pdo->prepare("UPDATE request_booking_form_configs
                    SET is_system_form=1, system_key=:system_key
                    WHERE id=:id AND tenant_id=:tenant_id");
                $stmt->execute(array(':system_key'=>$key,':id'=>$systemId,':tenant_id'=>$tenantId));
            } else {
                $created = rbCreateForm($pdo,$tenantId,$userId,$def['name'],'',$def['type'],$def['pages'],$key);
                $systemId = (int)$created['id'];
            }
        }

        /* Never leave the tenant without a default of this workflow type. */
        $column = $def['default_column'];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM request_booking_form_configs WHERE tenant_id=:tenant_id AND {$column}=1");
        $stmt->execute(array(':tenant_id'=>$tenantId));
        if ((int)$stmt->fetchColumn() === 0 && $systemId > 0) {
            $stmt = $pdo->prepare("UPDATE request_booking_form_configs SET {$column}=1 WHERE id=:id AND tenant_id=:tenant_id");
            $stmt->execute(array(':id'=>$systemId,':tenant_id'=>$tenantId));
        }
    }
    return true;
}

function rbJsonDecode($value, $default)
{
    if (is_array($value)) return $value;
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : $default;
}
