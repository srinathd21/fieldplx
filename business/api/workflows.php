<?php
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php'))
    require_once __DIR__ . '/../includes/audit.php';
function rj($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0)
        @ob_end_clean();
    http_response_code((int) $status);
    echo json_encode(array_merge(array('success' => (bool) $success, 'message' => (string) $message), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function postv($k, $d = '')
{
    return isset($_POST[$k]) ? $_POST[$k] : $d;
}
function jv($v)
{
    $x = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $x === false ? null : $x;
}
function tableExists(PDO $pdo, $table)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table_name");
    $q->execute(array(':table_name' => $table));
    return (int) $q->fetchColumn() > 0;
}
function builderSchemaReady(PDO $pdo)
{
    return tableExists($pdo, 'workflow_step_fields') && tableExists($pdo, 'workflow_field_options');
}
function requireBuilderSchema(PDO $pdo)
{
    if (!builderSchemaReady($pdo))
        rj(409, false, 'Workflow Builder database update is missing. Run migration_workflow_builder.sql once, then refresh this page.');
}
function tenantWorkflow(PDO $pdo, $tenantId, $id)
{
    $s = $pdo->prepare("SELECT w.*,sw.product_service_id AS service_id,ps.name AS service_name,ps.sku AS service_sku FROM workflows w LEFT JOIN service_workflows sw ON sw.workflow_id=w.id LEFT JOIN product_services ps ON ps.id=sw.product_service_id AND ps.tenant_id=w.tenant_id WHERE w.id=:id AND w.tenant_id=:tenant_id ORDER BY sw.is_default DESC LIMIT 1");
    $s->execute(array(':id' => $id, ':tenant_id' => $tenantId));
    $r = $s->fetch();
    if (!$r)
        rj(404, false, 'Workflow not found.');
    return $r;
}
function services(PDO $pdo, $tenantId)
{
    $s = $pdo->prepare("SELECT id,name,sku FROM product_services WHERE tenant_id=:tenant_id AND item_type='service' AND status='active' AND deleted_at IS NULL ORDER BY name");
    $s->execute(array(':tenant_id' => $tenantId));
    return $s->fetchAll();
}
function fullSteps(PDO $pdo, $tenantId, $workflowId)
{
    requireBuilderSchema($pdo);
    $s = $pdo->prepare("SELECT ws.* FROM workflow_steps ws INNER JOIN workflows w ON w.id=ws.workflow_id AND w.tenant_id=:tenant_id WHERE ws.workflow_id=:workflow_id ORDER BY ws.sort_order,ws.id");
    $s->execute(array(':tenant_id' => $tenantId, ':workflow_id' => $workflowId));
    $steps = $s->fetchAll();
    $fs = $pdo->prepare("SELECT * FROM workflow_step_fields WHERE tenant_id=:tenant_id AND workflow_step_id=:step_id AND status='active' ORDER BY sort_order,id");
    $os = $pdo->prepare("SELECT * FROM workflow_field_options WHERE workflow_field_id=:field_id AND status='active' ORDER BY sort_order,id");
    foreach ($steps as &$step) {
        $fs->execute(array(':tenant_id' => $tenantId, ':step_id' => $step['id']));
        $fields = $fs->fetchAll();
        foreach ($fields as &$f) {
            $f['config'] = array();
            if (!empty($f['config_json'])) {
                $c = json_decode($f['config_json'], true);
                if (is_array($c))
                    $f['config'] = $c;
            }
            $os->execute(array(':field_id' => $f['id']));
            $f['options'] = $os->fetchAll();
        }
        $step['fields'] = $fields;
    }
    return $steps;
}
function slug($s)
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim($s, '_');
}
function activity(PDO $pdo, $tenantId, $branchId, $userId, $event, $id, $title, $details)
{
    try {
        $s = $pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,title,details_json,visible_to_client) VALUES(:tenant_id,:branch_id,:user_id,'user',:event,'workflow',:id,:title,:details,0)");
        $s->execute(array(':tenant_id' => $tenantId, ':branch_id' => $branchId > 0 ? $branchId : null, ':user_id' => $userId, ':event' => $event, ':id' => $id, ':title' => $title, ':details' => jv($details)));
    } catch (Throwable $e) {
        error_log('workflow activity: ' . $e->getMessage());
    }
}
function audit(PDO $pdo, $tenantId, $branchId, $userId, $action, $id, $old, $new)
{
    if (function_exists('tenantAuditLog'))
        tenantAuditLog($pdo, $action, $tenantId, $branchId, $userId, 'workflow', $id, $old, $new);
}
$tenantId = isset($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : 0;
$userId = isset($_SESSION['tenant_user_id']) ? (int) $_SESSION['tenant_user_id'] : 0;
$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : 0;
if ($tenantId <= 0 || $userId <= 0)
    rj(401, false, 'Authentication required.');
$csrf = (string) postv('csrf_token', '');
$sessionCsrf = isset($_SESSION['workflows_csrf_token']) ? (string) $_SESSION['workflows_csrf_token'] : '';
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf))
    rj(419, false, 'Your form session expired. Refresh the page and try again.');
$action = trim((string) postv('action', ''));
try {
    if ($action === 'builder_meta')
        rj(200, true, 'Builder data loaded.', array('services' => services($pdo, $tenantId), 'builder_schema_ready' => builderSchemaReady($pdo)));
    if ($action === 'builder_get') {
        requireBuilderSchema($pdo);
        $id = (int) postv('workflow_id', 0);
        $w = tenantWorkflow($pdo, $tenantId, $id);
        rj(200, true, 'Workflow loaded.', array('workflow' => $w, 'steps' => fullSteps($pdo, $tenantId, $id), 'services' => services($pdo, $tenantId)));
    }
    if ($action === 'list') {
        $schemaReady = builderSchemaReady($pdo);
        $page = max(1, (int) postv('page', 1));
        $per = 10;
        $search = trim((string) postv('search', ''));
        $status = trim((string) postv('status', ''));
        $serviceId = (int) postv('service_id', 0);
        $where = array('w.tenant_id=:tenant_id');
        $params = array(':tenant_id' => $tenantId);
        if ($search !== '') {
            $sv = '%' . $search . '%';
            $where[] = '(w.name LIKE :s1 OR w.code LIKE :s2 OR ps.name LIKE :s3 OR ps.sku LIKE :s4)';
            $params[':s1'] = $sv;
            $params[':s2'] = $sv;
            $params[':s3'] = $sv;
            $params[':s4'] = $sv;
        }
        if (in_array($status, array('draft', 'active', 'inactive', 'archived'), true)) {
            $where[] = 'w.status=:status';
            $params[':status'] = $status;
        }
        if ($serviceId > 0) {
            $where[] = 'sw.product_service_id=:service_id';
            $params[':service_id'] = $serviceId;
        }
        $whereSql = implode(' AND ', $where);
        $count = $pdo->prepare("SELECT COUNT(DISTINCT w.id) FROM workflows w LEFT JOIN service_workflows sw ON sw.workflow_id=w.id LEFT JOIN product_services ps ON ps.id=sw.product_service_id AND ps.tenant_id=w.tenant_id WHERE $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $per));
        if ($page > $pages)
            $page = $pages;
        $offset = ($page - 1) * $per;
        $fieldCountSql = $schemaReady ? "(SELECT COUNT(*) FROM workflow_step_fields f INNER JOIN workflow_steps x2 ON x2.id=f.workflow_step_id WHERE x2.workflow_id=w.id AND f.status='active')" : "0";
        $stmt = $pdo->prepare("SELECT w.id,w.name,w.code,w.version_no,w.status,w.created_at,w.updated_at,ps.id AS service_id,ps.name AS service_name,ps.sku AS service_sku,(SELECT COUNT(*) FROM workflow_steps x WHERE x.workflow_id=w.id) step_count,$fieldCountSql field_count FROM workflows w LEFT JOIN service_workflows sw ON sw.workflow_id=w.id LEFT JOIN product_services ps ON ps.id=sw.product_service_id AND ps.tenant_id=w.tenant_id WHERE $whereSql GROUP BY w.id ORDER BY FIELD(w.status,'active','draft','inactive','archived'),w.name LIMIT $per OFFSET $offset");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if ($schemaReady) {
            $sum = $pdo->prepare("SELECT COUNT(*) total,SUM((SELECT COUNT(*) FROM workflow_steps ws WHERE ws.workflow_id=w.id)) steps,SUM((SELECT COUNT(*) FROM workflow_step_fields f INNER JOIN workflow_steps ws2 ON ws2.id=f.workflow_step_id WHERE ws2.workflow_id=w.id AND f.status='active')) fields FROM workflows w WHERE w.tenant_id=:tenant_id");
        } else {
            $sum = $pdo->prepare("SELECT COUNT(*) total,SUM((SELECT COUNT(*) FROM workflow_steps ws WHERE ws.workflow_id=w.id)) steps,0 fields FROM workflows w WHERE w.tenant_id=:tenant_id");
        }
        $sum->execute(array(':tenant_id' => $tenantId));
        $summary = $sum->fetch();
        $sc = $pdo->prepare("SELECT COUNT(DISTINCT sw.product_service_id) FROM service_workflows sw INNER JOIN workflows w ON w.id=sw.workflow_id AND w.tenant_id=:tenant_id");
        $sc->execute(array(':tenant_id' => $tenantId));
        $summary['services'] = (int) $sc->fetchColumn();
        rj(200, true, 'Workflows loaded.', array('workflows' => $rows, 'services' => services($pdo, $tenantId), 'summary' => $summary, 'builder_schema_ready' => $schemaReady, 'builder_schema_message' => $schemaReady ? '' : 'Workflow Builder database update is missing. Run migration_workflow_builder.sql once to enable dynamic checklist/form/photo/signature fields.', 'pagination' => array('page' => $page, 'pages' => $pages, 'total' => $total, 'from' => $total ? $offset + 1 : 0, 'to' => $total ? min($offset + count($rows), $total) : 0)));
    }
    if ($action === 'builder_save') {
        requireBuilderSchema($pdo);
        $id = (int) postv('workflow_id', 0);
        $serviceId = (int) postv('service_id', 0);
        $name = trim((string) postv('name', ''));
        $code = trim((string) postv('code', ''));
        $desc = trim((string) postv('description', ''));
        $mode = trim((string) postv('assignment_completion_mode', 'primary_only'));
        $version = max(1, (int) postv('version_no', 1));
        $status = trim((string) postv('status', 'draft'));
        $steps = isset($_POST['steps']) && is_array($_POST['steps']) ? $_POST['steps'] : array();
        if ($serviceId <= 0)
            rj(422, false, 'Select the service for this workflow.');
        $ss = $pdo->prepare("SELECT id,name FROM product_services WHERE id=:id AND tenant_id=:tenant_id AND item_type='service' AND status='active' AND deleted_at IS NULL LIMIT 1");
        $ss->execute(array(':id' => $serviceId, ':tenant_id' => $tenantId));
        $service = $ss->fetch();
        if (!$service)
            rj(422, false, 'Selected service is invalid.');
        if ($name === '')
            rj(422, false, 'Workflow name is required.');
        if (empty($steps))
            rj(422, false, 'Add at least one work-process step.');
        if (!in_array($mode, array('primary_only', 'task_owner', 'all_assignees'), true))
            rj(422, false, 'Invalid completion mode.');
        if (!in_array($status, array('draft', 'active', 'inactive', 'archived'), true))
            rj(422, false, 'Invalid workflow status.');
        $dupSql = "SELECT id FROM workflows WHERE tenant_id=:tenant_id AND name=:name";
        $dupP = array(':tenant_id' => $tenantId, ':name' => $name);
        if ($id > 0) {
            $dupSql .= ' AND id<>:id';
            $dupP[':id'] = $id;
        }
        $dup = $pdo->prepare($dupSql);
        $dup->execute($dupP);
        if ($dup->fetchColumn())
            rj(409, false, 'A workflow with this name already exists.');
        $old = null;
        if ($id > 0) {
            $old = array('workflow' => tenantWorkflow($pdo, $tenantId, $id), 'steps' => fullSteps($pdo, $tenantId, $id));
        }
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $u = $pdo->prepare("UPDATE workflows SET name=:name,code=:code,description=:description,assignment_completion_mode=:mode,version_no=:version,status=:status WHERE id=:id AND tenant_id=:tenant_id");
                $u->execute(array(':name' => $name, ':code' => $code !== '' ? $code : null, ':description' => $desc !== '' ? $desc : null, ':mode' => $mode, ':version' => $version, ':status' => $status, ':id' => $id, ':tenant_id' => $tenantId));
            } else {
                $u = $pdo->prepare("INSERT INTO workflows(tenant_id,name,code,description,assignment_completion_mode,version_no,status,created_by) VALUES(:tenant_id,:name,:code,:description,:mode,:version,:status,:created_by)");
                $u->execute(array(':tenant_id' => $tenantId, ':name' => $name, ':code' => $code !== '' ? $code : null, ':description' => $desc !== '' ? $desc : null, ':mode' => $mode, ':version' => $version, ':status' => $status, ':created_by' => $userId));
                $id = (int) $pdo->lastInsertId();
            }
            // One selected service is authoritative for this workflow builder.
            $pdo->prepare("DELETE FROM service_workflows WHERE workflow_id=:id")->execute(array(':id' => $id));
            $pdo->prepare("INSERT INTO service_workflows(product_service_id,workflow_id,is_default) VALUES(:service_id,:workflow_id,1)")->execute(array(':service_id' => $serviceId, ':workflow_id' => $id));
            // Protect operational history: do not rebuild definitions already used in jobs.
            $progress = $pdo->prepare("SELECT COUNT(*) FROM job_workflow_progress jwp INNER JOIN workflow_steps ws ON ws.id=jwp.workflow_step_id WHERE ws.workflow_id=:id");
            $progress->execute(array(':id' => $id));
            if ((int) $progress->fetchColumn() > 0 && $old) {
                throw new RuntimeException('This workflow already has technician job history. Duplicate it to create a new version instead of changing its builder structure.');
            }
            $pdo->prepare("DELETE FROM workflow_steps WHERE workflow_id=:id")->execute(array(':id' => $id));
            $insertStep = $pdo->prepare("INSERT INTO workflow_steps(workflow_id,step_code,step_name,description,sort_order,required,require_notes,require_form,require_checklist,require_photo,min_photos,require_signature,require_location,allow_reschedule,allow_quote_revision,allowed_roles_json) VALUES(:workflow_id,:step_code,:step_name,:description,:sort_order,:required,0,1,0,0,0,0,0,0,0,NULL)");
            $insertField = $pdo->prepare("INSERT INTO workflow_step_fields(tenant_id,workflow_step_id,field_key,label,field_type,help_text,placeholder,default_value,is_required,sort_order,min_value,max_value,min_length,max_length,min_files,max_files,accept_types,config_json,status) VALUES(:tenant_id,:step_id,:field_key,:label,:field_type,:help_text,:placeholder,NULL,:is_required,:sort_order,:min_value,:max_value,:min_length,:max_length,:min_files,:max_files,:accept_types,:config_json,'active')");
            $insertOpt = $pdo->prepare("INSERT INTO workflow_field_options(workflow_field_id,option_label,option_value,sort_order,is_default,status) VALUES(:field_id,:label,:value,:sort_order,0,'active')");
            $allowedTypes = array('checklist', 'text', 'textarea', 'number', 'decimal', 'yes_no', 'select', 'radio', 'checkbox', 'photo_single', 'photo_multiple', 'signature', 'date', 'time', 'datetime', 'location', 'file', 'customer_confirmation', 'heading');
            foreach ($steps as $si => $step) {
                if (!is_array($step))
                    continue;
                $stepName = trim((string) ($step['step_name'] ?? ''));
                if ($stepName === '')
                    throw new RuntimeException('Every process step needs a name.');
                $stepCode = slug($stepName);
                $insertStep->execute(array(':workflow_id' => $id, ':step_code' => $stepCode !== '' ? $stepCode : null, ':step_name' => $stepName, ':description' => trim((string) ($step['description'] ?? '')) ?: null, ':sort_order' => $si + 1, ':required' => !empty($step['required']) ? 1 : 0));
                $stepId = (int) $pdo->lastInsertId();
                $fields = isset($step['fields']) && is_array($step['fields']) ? $step['fields'] : array();
                foreach ($fields as $fi => $field) {
                    if (!is_array($field))
                        continue;
                    $type = trim((string) ($field['field_type'] ?? ''));
                    if (!in_array($type, $allowedTypes, true))
                        throw new RuntimeException('Invalid builder field type.');
                    $label = trim((string) ($field['label'] ?? ''));
                    if ($type !== 'heading' && $label === '')
                        throw new RuntimeException('Every workflow field needs a label.');
                    $key = trim((string) ($field['field_key'] ?? ''));
                    if ($key === '')
                        $key = slug($label !== '' ? $label : 'instruction_' . ($fi + 1));
                    $configRaw = trim((string) ($field['config_json'] ?? ''));
                    $config = array();
                    if ($configRaw !== '') {
                        $tmp = json_decode($configRaw, true);
                        if (is_array($tmp))
                            $config = $tmp;
                    }
                    if ($type === 'photo_single') {
                        $field['min_files'] = !empty($field['is_required']) ? 1 : 0;
                        $field['max_files'] = 1;
                    }
                    if ($type === 'photo_multiple' && !isset($field['max_files']))
                        $field['max_files'] = 10;
                    $insertField->execute(array(':tenant_id' => $tenantId, ':step_id' => $stepId, ':field_key' => $key, ':label' => $label !== '' ? $label : null, ':field_type' => $type, ':help_text' => trim((string) ($field['help_text'] ?? '')) ?: null, ':placeholder' => trim((string) ($field['placeholder'] ?? '')) ?: null, ':is_required' => !empty($field['is_required']) ? 1 : 0, ':sort_order' => $fi + 1, ':min_value' => ($field['min_value'] ?? '') !== '' ? $field['min_value'] : null, ':max_value' => ($field['max_value'] ?? '') !== '' ? $field['max_value'] : null, ':min_length' => ($field['min_length'] ?? '') !== '' ? (int) $field['min_length'] : null, ':max_length' => ($field['max_length'] ?? '') !== '' ? (int) $field['max_length'] : null, ':min_files' => ($field['min_files'] ?? '') !== '' ? (int) $field['min_files'] : null, ':max_files' => ($field['max_files'] ?? '') !== '' ? (int) $field['max_files'] : null, ':accept_types' => trim((string) ($field['accept_types'] ?? '')) ?: null, ':config_json' => empty($config) ? null : jv($config)));
                    $fieldId = (int) $pdo->lastInsertId();
                    $opts = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
                    foreach ($opts as $oi => $opt) {
                        if (!is_array($opt))
                            continue;
                        $ol = trim((string) ($opt['option_label'] ?? ''));
                        if ($ol === '')
                            continue;
                        $ov = trim((string) ($opt['option_value'] ?? ''));
                        if ($ov === '')
                            $ov = slug($ol);
                        $insertOpt->execute(array(':field_id' => $fieldId, ':label' => $ol, ':value' => $ov, ':sort_order' => $oi + 1));
                    }
                }
            }
            $pdo->commit();
            $new = array('workflow' => tenantWorkflow($pdo, $tenantId, $id), 'steps' => fullSteps($pdo, $tenantId, $id));
            activity($pdo, $tenantId, $branchId, $userId, $old ? 'workflow_builder_updated' : 'workflow_builder_created', $id, ($old ? 'Workflow builder updated: ' : 'Workflow builder created: ') . $name, $new);
            audit($pdo, $tenantId, $branchId, $userId, $old ? 'WORKFLOW_BUILDER_UPDATED' : 'WORKFLOW_BUILDER_CREATED', $id, $old, $new);
            rj(200, true, $old ? 'Workflow updated successfully.' : 'Workflow created successfully.', array('workflow_id' => $id));
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            if ($e instanceof RuntimeException)
                rj(409, false, $e->getMessage());
            throw $e;
        }
    }
    if ($action === 'duplicate') {
        requireBuilderSchema($pdo);
        $sourceId = (int) postv('workflow_id', 0);
        $source = tenantWorkflow($pdo, $tenantId, $sourceId);
        $steps = fullSteps($pdo, $tenantId, $sourceId);
        $newName = $source['name'] . ' Copy';
        $n = 1;
        while (true) {
            $c = $pdo->prepare("SELECT id FROM workflows WHERE tenant_id=:tenant_id AND name=:name LIMIT 1");
            $c->execute(array(':tenant_id' => $tenantId, ':name' => $newName));
            if (!$c->fetchColumn())
                break;
            $n++;
            $newName = $source['name'] . ' Copy ' . $n;
        }
        $pdo->beginTransaction();
        try {
            $i = $pdo->prepare("INSERT INTO workflows(tenant_id,name,code,description,assignment_completion_mode,version_no,status,created_by) VALUES(:tenant_id,:name,NULL,:description,:mode,:version,'draft',:created_by)");
            $i->execute(array(':tenant_id' => $tenantId, ':name' => $newName, ':description' => $source['description'], ':mode' => $source['assignment_completion_mode'], ':version' => (int) $source['version_no'] + 1, ':created_by' => $userId));
            $newId = (int) $pdo->lastInsertId();
            if (!empty($source['service_id']))
                $pdo->prepare("INSERT INTO service_workflows(product_service_id,workflow_id,is_default) VALUES(:service_id,:workflow_id,1)")->execute(array(':service_id' => $source['service_id'], ':workflow_id' => $newId));
            $stepIns = $pdo->prepare("INSERT INTO workflow_steps(workflow_id,step_code,step_name,description,sort_order,required,require_notes,require_form,require_checklist,require_photo,min_photos,require_signature,require_location,allow_reschedule,allow_quote_revision,allowed_roles_json) VALUES(:workflow_id,:step_code,:step_name,:description,:sort_order,:required,0,1,0,0,0,0,0,0,0,NULL)");
            $fIns = $pdo->prepare("INSERT INTO workflow_step_fields(tenant_id,workflow_step_id,field_key,label,field_type,help_text,placeholder,default_value,is_required,sort_order,min_value,max_value,min_length,max_length,min_files,max_files,accept_types,config_json,status) VALUES(:tenant_id,:step_id,:field_key,:label,:field_type,:help_text,:placeholder,:default_value,:is_required,:sort_order,:min_value,:max_value,:min_length,:max_length,:min_files,:max_files,:accept_types,:config_json,:status)");
            $oIns = $pdo->prepare("INSERT INTO workflow_field_options(workflow_field_id,option_label,option_value,sort_order,is_default,status) VALUES(:field_id,:label,:value,:sort_order,:is_default,:status)");
            foreach ($steps as $s) {
                $stepIns->execute(array(':workflow_id' => $newId, ':step_code' => $s['step_code'], ':step_name' => $s['step_name'], ':description' => $s['description'], ':sort_order' => $s['sort_order'], ':required' => $s['required']));
                $ns = (int) $pdo->lastInsertId();
                foreach ($s['fields'] as $f) {
                    $fIns->execute(array(':tenant_id' => $tenantId, ':step_id' => $ns, ':field_key' => $f['field_key'], ':label' => $f['label'], ':field_type' => $f['field_type'], ':help_text' => $f['help_text'], ':placeholder' => $f['placeholder'], ':default_value' => $f['default_value'], ':is_required' => $f['is_required'], ':sort_order' => $f['sort_order'], ':min_value' => $f['min_value'], ':max_value' => $f['max_value'], ':min_length' => $f['min_length'], ':max_length' => $f['max_length'], ':min_files' => $f['min_files'], ':max_files' => $f['max_files'], ':accept_types' => $f['accept_types'], ':config_json' => $f['config_json'], ':status' => $f['status']));
                    $nf = (int) $pdo->lastInsertId();
                    foreach ($f['options'] as $o)
                        $oIns->execute(array(':field_id' => $nf, ':label' => $o['option_label'], ':value' => $o['option_value'], ':sort_order' => $o['sort_order'], ':is_default' => $o['is_default'], ':status' => $o['status']));
                }
            }
            $pdo->commit();
            rj(200, true, 'Workflow duplicated as a new draft version.', array('workflow_id' => $newId));
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            throw $e;
        }
    }
    if ($action === 'archive') {
        $id = (int) postv('workflow_id', 0);
        $w = tenantWorkflow($pdo, $tenantId, $id);
        $pdo->prepare("UPDATE workflows SET status='archived' WHERE id=:id AND tenant_id=:tenant_id")->execute(array(':id' => $id, ':tenant_id' => $tenantId));
        activity($pdo, $tenantId, $branchId, $userId, 'workflow_archived', $id, 'Workflow archived: ' . $w['name'], array('status' => 'archived'));
        rj(200, true, 'Workflow archived successfully.');
    }
    rj(400, false, 'Unsupported workflow action.');
} catch (PDOException $e) {
    error_log('workflow builder PDO: ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1146)
        rj(409, false, 'Workflow Builder database update is missing. Run migration_workflow_builder.sql once, then refresh this page.');
    if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062)
        rj(409, false, 'A duplicate workflow, field key or option already exists.');
    rj(500, false, 'Unable to process the workflow request.');
} catch (Throwable $e) {
    error_log('workflow builder: ' . $e->getMessage());
    rj(500, false, 'Unable to process the workflow request.');
}
