<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');

require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function cf_out($code, $success, $message, $extra = array())
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

function cf_post($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }
    return trim((string)$_POST[$key]);
}

function cf_table(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(array(':t' => $table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function cf_csrf()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $token = cf_post('csrf_token');
    if (
        empty($_SESSION['custom_fields_csrf']) ||
        !is_string($_SESSION['custom_fields_csrf']) ||
        $token === '' ||
        !hash_equals($_SESSION['custom_fields_csrf'], $token)
    ) {
        cf_out(419, false, 'Your form session expired. Refresh the page and try again.');
    }
}

function cf_audit(PDO $pdo, $tenantId, $branchId, $userId, $action, $entityId, $newValues)
{
    if (function_exists('tenantAuditLog')) {
        try {
            tenantAuditLog(
                $pdo,
                $action,
                $tenantId,
                $branchId > 0 ? $branchId : null,
                $userId,
                'custom_field_definition',
                $entityId,
                null,
                $newValues
            );
        } catch (Throwable $e) {
            error_log('FieldPlx custom fields audit error: ' . $e->getMessage());
        }
    }
}

function cf_entity_label($entity)
{
    $map = array(
        'client' => 'Client',
        'property' => 'Property',
        'quote' => 'Quote',
        'job' => 'Job',
        'invoice' => 'Invoice',
        'team' => 'Team'
    );
    return isset($map[$entity]) ? $map[$entity] : ucfirst($entity);
}

function cf_field_description($type)
{
    $map = array(
        'text' => 'Stores a text value',
        'numeric' => 'Stores a numeric value',
        'boolean' => 'Stores a true / false value',
        'area' => 'Stores an area in length × width',
        'dropdown' => 'Stores one selected option'
    );
    return isset($map[$type]) ? $map[$type] : 'Stores a value';
}

function cf_normalize_options($raw)
{
    $items = array();
    if (is_array($raw)) {
        $items = $raw;
    } else {
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $items = $decoded;
        }
    }
    $clean = array();
    foreach ($items as $item) {
        $value = trim((string)$item);
        if ($value === '') {
            continue;
        }
        if (!in_array($value, $clean, true)) {
            $clean[] = substr($value, 0, 190);
        }
        if (count($clean) >= 100) {
            break;
        }
    }
    return $clean;
}

function cf_fetch_one(PDO $pdo, $tenantId, $source, $id)
{
    if ($source === 'client') {
        $stmt = $pdo->prepare("SELECT id, applies_to, field_name, field_type, is_transferable, default_value, options_json, status, sort_order FROM client_custom_field_definitions WHERE id=:id AND tenant_id=:t LIMIT 1");
        $stmt->execute(array(':id' => $id, ':t' => $tenantId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $entity = ((string)$row['applies_to'] === 'location') ? 'property' : 'client';
        $default = $row['default_value'];
        $areaLength = null;
        $areaWidth = null;
        $areaUnit = '';
        if ((string)$row['field_type'] === 'area' && $default) {
            $j = json_decode((string)$default, true);
            if (is_array($j)) {
                $areaLength = isset($j['length']) ? $j['length'] : null;
                $areaWidth = isset($j['width']) ? $j['width'] : null;
                $areaUnit = isset($j['unit']) ? (string)$j['unit'] : '';
            }
        }
        return array(
            'key' => 'client:' . (int)$row['id'],
            'source' => 'client',
            'id' => (int)$row['id'],
            'entity_type' => $entity,
            'entity_label' => cf_entity_label($entity),
            'field_name' => (string)$row['field_name'],
            'field_type' => (string)$row['field_type'],
            'type_description' => cf_field_description((string)$row['field_type']),
            'is_transferable' => (int)$row['is_transferable'],
            'default_value' => ((string)$row['field_type'] === 'area') ? '' : (string)($default === null ? '' : $default),
            'area_default_length' => $areaLength,
            'area_default_width' => $areaWidth,
            'area_unit' => $areaUnit,
            'options' => cf_normalize_options($row['options_json']),
            'sort_order' => (int)$row['sort_order'],
            'status' => isset($row['status']) ? (string)$row['status'] : 'active'
        );
    }

    $stmt = $pdo->prepare("SELECT * FROM workflow_custom_field_definitions WHERE id=:id AND tenant_id=:t LIMIT 1");
    $stmt->execute(array(':id' => $id, ':t' => $tenantId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return array(
        'key' => 'workflow:' . (int)$row['id'],
        'source' => 'workflow',
        'id' => (int)$row['id'],
        'entity_type' => (string)$row['entity_type'],
        'entity_label' => cf_entity_label((string)$row['entity_type']),
        'field_name' => (string)$row['field_name'],
        'field_type' => (string)$row['field_type'],
        'type_description' => cf_field_description((string)$row['field_type']),
        'is_transferable' => (int)$row['is_transferable'],
        'default_value' => (string)($row['default_value'] === null ? '' : $row['default_value']),
        'area_default_length' => $row['area_default_length'],
        'area_default_width' => $row['area_default_width'],
        'area_unit' => (string)($row['area_unit'] === null ? '' : $row['area_unit']),
        'options' => cf_normalize_options($row['options_json']),
        'sort_order' => (int)$row['sort_order'],
        'status' => isset($row['status']) ? (string)$row['status'] : 'active'
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cf_out(405, false, 'Method not allowed.');
}

cf_csrf();

$tenantId = isset($currentTenantId) ? (int)$currentTenantId : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);
$userId = isset($currentTenantUserId) ? (int)$currentTenantUserId : (isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : 0);
$branchId = isset($currentBranchId) ? (int)$currentBranchId : (isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0);

if ($tenantId <= 0) {
    cf_out(401, false, 'Tenant session is not available.');
}

$action = cf_post('action');

try {
    if (!cf_table($pdo, 'client_custom_field_definitions')) {
        cf_out(500, false, 'Client custom field definitions are not installed.');
    }

    $workflowReady = cf_table($pdo, 'workflow_custom_field_definitions');

    if ($action === 'list') {
        $grouped = array(
            'client' => array(),
            'property' => array(),
            'quote' => array(),
            'job' => array(),
            'invoice' => array(),
            'team' => array()
        );
        $archivedGrouped = array(
            'client' => array(),
            'property' => array(),
            'quote' => array(),
            'job' => array(),
            'invoice' => array(),
            'team' => array()
        );

        $stmt = $pdo->prepare("SELECT id,status FROM client_custom_field_definitions WHERE tenant_id=:t ORDER BY applies_to,status,sort_order,field_name,id");
        $stmt->execute(array(':t' => $tenantId));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $one = cf_fetch_one($pdo, $tenantId, 'client', (int)$row['id']);
            if (!$one || !isset($grouped[$one['entity_type']])) continue;
            if ((string)$row['status'] === 'inactive') $archivedGrouped[$one['entity_type']][] = $one;
            else $grouped[$one['entity_type']][] = $one;
        }

        if ($workflowReady) {
            $stmt = $pdo->prepare("SELECT id,status FROM workflow_custom_field_definitions WHERE tenant_id=:t ORDER BY entity_type,status,sort_order,field_name,id");
            $stmt->execute(array(':t' => $tenantId));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $one = cf_fetch_one($pdo, $tenantId, 'workflow', (int)$row['id']);
                if (!$one || !isset($grouped[$one['entity_type']])) continue;
                if ((string)$row['status'] === 'inactive') $archivedGrouped[$one['entity_type']][] = $one;
                else $grouped[$one['entity_type']][] = $one;
            }
        }

        cf_out(200, true, 'Custom fields loaded.', array(
            'groups' => $grouped,
            'archived_groups' => $archivedGrouped,
            'schema' => array('workflow_ready' => $workflowReady)
        ));
    }

    if ($action === 'get') {
        $source = cf_post('source');
        $id = (int)cf_post('id', '0');
        if (!in_array($source, array('client','workflow'), true) || $id <= 0) {
            cf_out(422, false, 'Invalid custom field.');
        }
        $field = cf_fetch_one($pdo, $tenantId, $source, $id);
        if (!$field) {
            cf_out(404, false, 'Custom field not found.');
        }
        cf_out(200, true, 'Custom field loaded.', array('field' => $field));
    }

    if ($action === 'save') {
        $source = cf_post('source');
        $id = (int)cf_post('id', '0');
        $entity = strtolower(cf_post('entity_type'));
        $name = substr(cf_post('field_name'), 0, 190);
        $type = strtolower(cf_post('field_type', 'text'));
        $transferable = cf_post('is_transferable', '0') === '1' ? 1 : 0;
        $defaultValue = cf_post('default_value');
        $areaLengthRaw = cf_post('area_default_length');
        $areaWidthRaw = cf_post('area_default_width');
        $areaUnit = substr(cf_post('area_unit'), 0, 40);
        $options = cf_normalize_options(isset($_POST['options']) ? $_POST['options'] : cf_post('options_json', '[]'));

        $entities = array('client','property','quote','job','invoice','team');
        $types = array('text','numeric','boolean','area','dropdown');
        if (!in_array($entity, $entities, true)) {
            cf_out(422, false, 'Select where this custom field applies.');
        }
        if ($name === '') {
            cf_out(422, false, 'Custom field name is required.');
        }
        if (!in_array($type, $types, true)) {
            cf_out(422, false, 'Select a valid field type.');
        }
        if ($type === 'numeric' && $defaultValue !== '' && !is_numeric($defaultValue)) {
            cf_out(422, false, 'Default value must be numeric.');
        }
        if ($type === 'boolean') {
            if (!in_array($defaultValue, array('','1','0','yes','no'), true)) {
                $defaultValue = '';
            }
            if ($defaultValue === 'yes') $defaultValue = '1';
            if ($defaultValue === 'no') $defaultValue = '0';
        }
        if ($type === 'dropdown' && count($options) < 1) {
            cf_out(422, false, 'Add at least one dropdown option.');
        }

        $isClient = in_array($entity, array('client','property'), true);
        if (!$isClient && !$workflowReady) {
            cf_out(500, false, 'Run database/custom-fields-migration.sql before creating Quote, Job, Invoice or Team custom fields.');
        }

        $pdo->beginTransaction();

        if ($isClient) {
            $appliesTo = $entity === 'property' ? 'location' : 'client';
            $dupSql = "SELECT id FROM client_custom_field_definitions WHERE tenant_id=:t AND applies_to=:a AND LOWER(field_name)=LOWER(:n) AND status='active'";
            $dupParams = array(':t' => $tenantId, ':a' => $appliesTo, ':n' => $name);
            if ($id > 0 && $source === 'client') {
                $dupSql .= " AND id<>:id";
                $dupParams[':id'] = $id;
            }
            $dupSql .= " LIMIT 1";
            $dup = $pdo->prepare($dupSql);
            $dup->execute($dupParams);
            if ($dup->fetchColumn()) {
                throw new RuntimeException('An active ' . strtolower(cf_entity_label($entity)) . ' custom field with this name already exists.');
            }

            if ($type === 'area') {
                $defaultValue = json_encode(array(
                    'length' => $areaLengthRaw !== '' && is_numeric($areaLengthRaw) ? (float)$areaLengthRaw : 0,
                    'width' => $areaWidthRaw !== '' && is_numeric($areaWidthRaw) ? (float)$areaWidthRaw : 0,
                    'unit' => $areaUnit
                ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $optionsJson = $type === 'dropdown' ? json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

            if ($id > 0 && $source === 'client') {
                $stmt = $pdo->prepare("UPDATE client_custom_field_definitions SET applies_to=:a,field_name=:n,field_type=:ft,is_transferable=:tr,default_value=:dv,options_json=:op,updated_at=NOW() WHERE id=:id AND tenant_id=:t AND status='active'");
                $stmt->execute(array(':a'=>$appliesTo, ':n'=>$name, ':ft'=>$type, ':tr'=>$transferable, ':dv'=>$defaultValue !== '' ? $defaultValue : null, ':op'=>$optionsJson, ':id'=>$id, ':t'=>$tenantId));
                $savedId = $id;
                $savedSource = 'client';
            } else {
                $max = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+100 FROM client_custom_field_definitions WHERE tenant_id=:t AND applies_to=:a");
                $max->execute(array(':t'=>$tenantId, ':a'=>$appliesTo));
                $sortOrder = (int)$max->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO client_custom_field_definitions(tenant_id,applies_to,field_name,field_type,is_transferable,default_value,options_json,status,sort_order,created_by,created_at) VALUES(:t,:a,:n,:ft,:tr,:dv,:op,'active',:so,:u,NOW())");
                $stmt->execute(array(':t'=>$tenantId, ':a'=>$appliesTo, ':n'=>$name, ':ft'=>$type, ':tr'=>$transferable, ':dv'=>$defaultValue !== '' ? $defaultValue : null, ':op'=>$optionsJson, ':so'=>$sortOrder, ':u'=>$userId > 0 ? $userId : null));
                $savedId = (int)$pdo->lastInsertId();
                $savedSource = 'client';
            }
        } else {
            $dupSql = "SELECT id FROM workflow_custom_field_definitions WHERE tenant_id=:t AND entity_type=:e AND LOWER(field_name)=LOWER(:n) AND status='active'";
            $dupParams = array(':t'=>$tenantId, ':e'=>$entity, ':n'=>$name);
            if ($id > 0 && $source === 'workflow') {
                $dupSql .= " AND id<>:id";
                $dupParams[':id'] = $id;
            }
            $dupSql .= " LIMIT 1";
            $dup = $pdo->prepare($dupSql);
            $dup->execute($dupParams);
            if ($dup->fetchColumn()) {
                throw new RuntimeException('An active ' . strtolower(cf_entity_label($entity)) . ' custom field with this name already exists.');
            }

            $areaLength = ($type === 'area' && $areaLengthRaw !== '' && is_numeric($areaLengthRaw)) ? (float)$areaLengthRaw : null;
            $areaWidth = ($type === 'area' && $areaWidthRaw !== '' && is_numeric($areaWidthRaw)) ? (float)$areaWidthRaw : null;
            $areaUnitDb = $type === 'area' && $areaUnit !== '' ? $areaUnit : null;
            $optionsJson = $type === 'dropdown' ? json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            $defaultDb = in_array($type, array('text','numeric','boolean'), true) && $defaultValue !== '' ? $defaultValue : null;

            if ($id > 0 && $source === 'workflow') {
                $stmt = $pdo->prepare("UPDATE workflow_custom_field_definitions SET entity_type=:e,field_name=:n,field_type=:ft,is_transferable=:tr,default_value=:dv,area_default_length=:al,area_default_width=:aw,area_unit=:au,options_json=:op,updated_at=NOW() WHERE id=:id AND tenant_id=:t AND status='active'");
                $stmt->execute(array(':e'=>$entity, ':n'=>$name, ':ft'=>$type, ':tr'=>$transferable, ':dv'=>$defaultDb, ':al'=>$areaLength, ':aw'=>$areaWidth, ':au'=>$areaUnitDb, ':op'=>$optionsJson, ':id'=>$id, ':t'=>$tenantId));
                $savedId = $id;
                $savedSource = 'workflow';
            } else {
                $max = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+100 FROM workflow_custom_field_definitions WHERE tenant_id=:t AND entity_type=:e");
                $max->execute(array(':t'=>$tenantId, ':e'=>$entity));
                $sortOrder = (int)$max->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO workflow_custom_field_definitions(tenant_id,entity_type,field_name,field_type,is_transferable,default_value,area_default_length,area_default_width,area_unit,options_json,status,sort_order,created_by,created_at) VALUES(:t,:e,:n,:ft,:tr,:dv,:al,:aw,:au,:op,'active',:so,:u,NOW())");
                $stmt->execute(array(':t'=>$tenantId, ':e'=>$entity, ':n'=>$name, ':ft'=>$type, ':tr'=>$transferable, ':dv'=>$defaultDb, ':al'=>$areaLength, ':aw'=>$areaWidth, ':au'=>$areaUnitDb, ':op'=>$optionsJson, ':so'=>$sortOrder, ':u'=>$userId > 0 ? $userId : null));
                $savedId = (int)$pdo->lastInsertId();
                $savedSource = 'workflow';
            }
        }

        $pdo->commit();
        cf_audit($pdo, $tenantId, $branchId, $userId, $id > 0 ? 'CUSTOM_FIELD_UPDATED' : 'CUSTOM_FIELD_CREATED', $savedId, array('entity_type'=>$entity, 'field_name'=>$name, 'field_type'=>$type, 'is_transferable'=>$transferable));
        cf_out(200, true, $id > 0 ? 'Custom field updated successfully.' : 'Custom field created successfully.', array('field'=>cf_fetch_one($pdo, $tenantId, $savedSource, $savedId)));
    }

    if ($action === 'archive' || $action === 'delete') {
        $source = cf_post('source');
        $id = (int)cf_post('id', '0');
        if (!in_array($source, array('client','workflow'), true) || $id <= 0) {
            cf_out(422, false, 'Invalid custom field.');
        }
        $field = cf_fetch_one($pdo, $tenantId, $source, $id);
        if (!$field) cf_out(404, false, 'Custom field not found.');
        if ((string)$field['status'] === 'inactive') cf_out(200, true, 'Custom field is already archived.');
        $table = $source === 'client' ? 'client_custom_field_definitions' : 'workflow_custom_field_definitions';
        $stmt = $pdo->prepare("UPDATE `" . $table . "` SET status='inactive',updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        $stmt->execute(array(':id'=>$id, ':t'=>$tenantId));
        cf_audit($pdo, $tenantId, $branchId, $userId, 'CUSTOM_FIELD_ARCHIVED', $id, array('source'=>$source, 'entity_type'=>$field['entity_type'], 'field_name'=>$field['field_name']));
        cf_out(200, true, 'Custom field archived successfully.');
    }

    if ($action === 'restore') {
        $source = cf_post('source');
        $id = (int)cf_post('id', '0');
        if (!in_array($source, array('client','workflow'), true) || $id <= 0) cf_out(422, false, 'Invalid custom field.');
        $field = cf_fetch_one($pdo, $tenantId, $source, $id);
        if (!$field) cf_out(404, false, 'Custom field not found.');
        $table = $source === 'client' ? 'client_custom_field_definitions' : 'workflow_custom_field_definitions';
        $stmt = $pdo->prepare("UPDATE `" . $table . "` SET status='active',updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        $stmt->execute(array(':id'=>$id, ':t'=>$tenantId));
        cf_audit($pdo, $tenantId, $branchId, $userId, 'CUSTOM_FIELD_RESTORED', $id, array('source'=>$source, 'entity_type'=>$field['entity_type'], 'field_name'=>$field['field_name']));
        cf_out(200, true, 'Custom field restored successfully.');
    }

    if ($action === 'permanent_delete') {
        $source = cf_post('source');
        $id = (int)cf_post('id', '0');
        if (!in_array($source, array('client','workflow'), true) || $id <= 0) cf_out(422, false, 'Invalid custom field.');
        $field = cf_fetch_one($pdo, $tenantId, $source, $id);
        if (!$field) cf_out(404, false, 'Custom field not found.');
        if ((string)$field['status'] !== 'inactive') cf_out(409, false, 'Archive this custom field before permanently deleting it.');

        $pdo->beginTransaction();
        if ($source === 'client') {
            if ($field['entity_type'] === 'property' && cf_table($pdo, 'client_location_custom_field_values')) {
                $d = $pdo->prepare("DELETE FROM client_location_custom_field_values WHERE tenant_id=:t AND field_id=:id");
                $d->execute(array(':t'=>$tenantId, ':id'=>$id));
            }
            if ($field['entity_type'] === 'client' && cf_table($pdo, 'client_custom_field_values')) {
                $d = $pdo->prepare("DELETE FROM client_custom_field_values WHERE tenant_id=:t AND field_id=:id");
                $d->execute(array(':t'=>$tenantId, ':id'=>$id));
            }
            $d = $pdo->prepare("DELETE FROM client_custom_field_definitions WHERE id=:id AND tenant_id=:t AND status='inactive'");
            $d->execute(array(':id'=>$id, ':t'=>$tenantId));
        } else {
            $d = $pdo->prepare("DELETE FROM workflow_custom_field_definitions WHERE id=:id AND tenant_id=:t AND status='inactive'");
            $d->execute(array(':id'=>$id, ':t'=>$tenantId));
        }
        $pdo->commit();
        cf_audit($pdo, $tenantId, $branchId, $userId, 'CUSTOM_FIELD_PERMANENTLY_DELETED', $id, array('source'=>$source, 'entity_type'=>$field['entity_type'], 'field_name'=>$field['field_name']));
        cf_out(200, true, 'Custom field permanently deleted.');
    }

    if ($action === 'reorder') {
        $entity = strtolower(cf_post('entity_type'));
        $keysRaw = isset($_POST['keys']) ? $_POST['keys'] : array();
        if (!is_array($keysRaw)) {
            $decoded = json_decode((string)$keysRaw, true);
            $keysRaw = is_array($decoded) ? $decoded : array();
        }
        if (!in_array($entity, array('client','property','quote','job','invoice','team'), true)) {
            cf_out(422, false, 'Invalid custom field group.');
        }
        $pdo->beginTransaction();
        $order = 100;
        foreach ($keysRaw as $key) {
            $parts = explode(':', (string)$key, 2);
            if (count($parts) !== 2) continue;
            $source = $parts[0];
            $id = (int)$parts[1];
            if ($id <= 0) continue;
            if ($source === 'client' && in_array($entity, array('client','property'), true)) {
                $applies = $entity === 'property' ? 'location' : 'client';
                $stmt = $pdo->prepare("UPDATE client_custom_field_definitions SET sort_order=:o WHERE id=:id AND tenant_id=:t AND applies_to=:a AND status='active'");
                $stmt->execute(array(':o'=>$order, ':id'=>$id, ':t'=>$tenantId, ':a'=>$applies));
            } elseif ($source === 'workflow' && !in_array($entity, array('client','property'), true) && $workflowReady) {
                $stmt = $pdo->prepare("UPDATE workflow_custom_field_definitions SET sort_order=:o WHERE id=:id AND tenant_id=:t AND entity_type=:e AND status='active'");
                $stmt->execute(array(':o'=>$order, ':id'=>$id, ':t'=>$tenantId, ':e'=>$entity));
            }
            $order += 100;
        }
        $pdo->commit();
        cf_out(200, true, 'Custom field order updated.');
    }

    cf_out(400, false, 'Invalid action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('FieldPlx custom fields API error: ' . $e->getMessage());
    $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to complete the custom field action.';
    cf_out(500, false, $msg);
}
