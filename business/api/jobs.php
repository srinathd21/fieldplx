<?php
/* FieldPlx Jobs API - Version 2.1.0 - direct jobs + quotation conversion + recurring schedules */
/* FieldPlx Jobs API - Version 2.0.0 - recurring schedules, visits, billing, attachments and checklists */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

function jbRes($code, $ok, $msg, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$code);
    echo json_encode(array_merge(array(
        'success' => (bool)$ok,
        'message' => (string)$msg
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jbP($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function jbCol(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $stmt->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = (int)$stmt->fetchColumn() > 0;
    return $cache[$key];
}

function jbTable(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(array(':t' => $table));
    $cache[$table] = (int)$stmt->fetchColumn() > 0;
    return $cache[$table];
}

function jbDate($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : false;
}

function jbTime($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) {
        return false;
    }
    return strlen($value) === 5 ? $value . ':00' : $value;
}

function jbJob(PDO $pdo, $tenant, $id)
{
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL LIMIT 1");
    $stmt->execute(array(':id' => $id, ':t' => $tenant));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jbRes(404, false, 'Job not found.');
    }
    return $row;
}

function jbJobDetails(PDO $pdo, $tenant, $id)
{
    $stmt = $pdo->prepare("SELECT
            j.*,
            q.quote_no,
            q.title AS quote_title,
            q.status AS quote_status,
            c.display_name AS client_name,
            c.company_name AS client_company,
            c.email AS client_email,
            c.phone AS client_phone,
            c.alternate_phone AS client_alternate_phone,
            c.allow_email AS client_allow_email,
            ps.name AS service_name,
            b.name AS branch_name,
            b.branch_code,
            r.request_no,
            r.title AS request_title,
            w.name AS workflow_name
        FROM jobs j
        INNER JOIN clients c
            ON c.id=j.client_id
           AND c.tenant_id=j.tenant_id
        LEFT JOIN quotes q
            ON q.id=j.quote_id
           AND q.tenant_id=j.tenant_id
        LEFT JOIN product_services ps
            ON ps.id=j.product_service_id
           AND ps.tenant_id=j.tenant_id
        LEFT JOIN branches b
            ON b.id=j.branch_id
           AND b.tenant_id=j.tenant_id
        LEFT JOIN service_requests r
            ON r.id=j.request_id
           AND r.tenant_id=j.tenant_id
        LEFT JOIN workflows w
            ON w.id=j.workflow_id
           AND w.tenant_id=j.tenant_id
        WHERE j.id=:id
          AND j.tenant_id=:t
          AND j.deleted_at IS NULL
        LIMIT 1");
    $stmt->execute(array(':id' => $id, ':t' => $tenant));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jbRes(404, false, 'Job not found.');
    }
    $row['can_resend_email'] = strtolower((string)$row['status']) === 'scheduled' ? 1 : 0;
    return $row;
}

function jbAssignments(PDO $pdo, $tenant, $job)
{
    $stmt = $pdo->prepare("SELECT ja.*, CONCAT(u.first_name, CASE WHEN u.last_name IS NOT NULL AND u.last_name<>'' THEN CONCAT(' ',u.last_name) ELSE '' END) user_name, u.email, u.department_id FROM job_assignments ja LEFT JOIN users u ON u.id=ja.user_id AND u.tenant_id=ja.tenant_id WHERE ja.tenant_id=:t AND ja.job_id=:j AND ja.status<>'removed' ORDER BY ja.is_primary_responsible DESC, ja.id");
    $stmt->execute(array(':t' => $tenant, ':j' => $job));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jbCurrency(PDO $pdo, $tenant)
{
    $stmt = $pdo->prepare("SELECT c.* FROM tenants t LEFT JOIN currencies c ON c.id=t.currency_id WHERE t.id=:t LIMIT 1");
    $stmt->execute(array(':t' => $tenant));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : array('symbol' => '', 'symbol_position' => 'before', 'decimal_places' => 2, 'currency_code' => '');
}

function jbDefaultWorkflow(PDO $pdo, $tenant, $service)
{
    if ($service <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT w.id FROM service_workflows sw INNER JOIN workflows w ON w.id=sw.workflow_id AND w.tenant_id=:t AND w.status='active' INNER JOIN product_services ps ON ps.id=sw.product_service_id AND ps.tenant_id=:t2 WHERE sw.product_service_id=:s ORDER BY sw.is_default DESC,w.id DESC LIMIT 1");
    $stmt->execute(array(':t' => $tenant, ':t2' => $tenant, ':s' => $service));
    $value = $stmt->fetchColumn();
    return $value ? (int)$value : null;
}

function jbService(PDO $pdo, $tenant, $serviceId)
{
    $serviceId = (int)$serviceId;
    if ($serviceId <= 0) return null;

    $sql = "SELECT id,name,status";
    if (jbCol($pdo, 'product_services', 'item_type')) {
        $sql .= ",item_type";
    } else {
        $sql .= ",NULL AS item_type";
    }

    $sql .= " FROM product_services
              WHERE id=:id
                AND tenant_id=:t
                AND status='active'
                AND deleted_at IS NULL";

    if (jbCol($pdo, 'product_services', 'item_type')) {
        $sql .= " AND item_type='service'";
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':id' => $serviceId, ':t' => $tenant));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? $row : null;
}

function jbWorkflowName(PDO $pdo, $tenant, $workflowId)
{
    $workflowId = (int)$workflowId;
    if ($workflowId <= 0) return '';

    $stmt = $pdo->prepare("SELECT name FROM workflows WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");
    $stmt->execute(array(':id' => $workflowId, ':t' => $tenant));
    $name = $stmt->fetchColumn();

    return $name !== false ? (string)$name : '';
}

function jbNext(PDO $pdo, $tenant, $branch)
{
    $sep = jbCol($pdo, 'document_sequences', 'number_separator') ? 'number_separator' : 'separator';
    $stmt = $pdo->prepare("SELECT ds.*,b.branch_code FROM document_sequences ds LEFT JOIN branches b ON b.id=ds.branch_id AND b.tenant_id=ds.tenant_id WHERE ds.tenant_id=:t AND ds.document_type='job' AND ds.is_active=1 AND (ds.branch_id=:b OR ds.branch_id IS NULL) ORDER BY CASE WHEN ds.branch_id=:b2 THEN 0 ELSE 1 END,ds.id LIMIT 1 FOR UPDATE");
    $stmt->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : 0, ':b2' => $branch > 0 ? $branch : 0));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $q = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(job_no,'-',-1) AS UNSIGNED)) FROM jobs WHERE tenant_id=:t AND job_no LIKE 'JOB-%'");
        $q->execute(array(':t' => $tenant));
        return 'JOB-' . str_pad((string)((int)$q->fetchColumn() + 1), 6, '0', STR_PAD_LEFT);
    }

    $now = new DateTime('now');
    $year = $now->format('Y');
    $month = $now->format('m');
    $fyStart = max(1, min(12, (int)$row['financial_year_start_month']));
    $fyYear = (int)$now->format('n') >= $fyStart ? (int)$year : (int)$year - 1;
    $fy = $fyYear . '-' . substr((string)($fyYear + 1), -2);
    $key = 'never';
    if ($row['reset_period'] === 'monthly') $key = $year . $month;
    elseif ($row['reset_period'] === 'yearly') $key = $year;
    elseif ($row['reset_period'] === 'financial_year') $key = $fy;

    $current = (int)$row['current_number'];
    if ($row['reset_period'] !== 'never' && (string)$row['last_reset_key'] !== (string)$key) {
        $current = 0;
    }
    $next = $current + 1;
    $middle = '';
    if ($row['middle_format'] === 'year') $middle = $year;
    elseif ($row['middle_format'] === 'year_month') $middle = $year . $month;
    elseif ($row['middle_format'] === 'financial_year') $middle = $fy;
    elseif ($row['middle_format'] === 'branch_year') $middle = (!empty($row['branch_code']) ? $row['branch_code'] : 'BR') . $year;

    $parts = array();
    if (!empty($row['prefix'])) $parts[] = $row['prefix'];
    if ($middle !== '') $parts[] = $middle;
    $parts[] = str_pad((string)$next, max(1, (int)$row['number_length']), '0', STR_PAD_LEFT);
    if (!empty($row['suffix'])) $parts[] = $row['suffix'];
    $number = implode(isset($row[$sep]) ? (string)$row[$sep] : '-', $parts);

    $upd = $pdo->prepare("UPDATE document_sequences SET current_number=:n,last_reset_key=:k WHERE id=:id");
    $upd->execute(array(':n' => $next, ':k' => $key, ':id' => $row['id']));
    return $number;
}

function jbMeta(PDO $pdo, $tenant, $jobId = 0)
{
    $meta = array();
    $stmt = $pdo->prepare("SELECT q.id,q.quote_no,q.title,q.client_id,q.location_id,q.request_id,q.branch_id,q.subtotal,q.tax_total,q.total,c.display_name client_name,c.email client_email,c.phone client_phone,c.allow_email,r.product_service_id,ps.name service_name FROM quotes q INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id LEFT JOIN service_requests r ON r.id=q.request_id AND r.tenant_id=q.tenant_id LEFT JOIN product_services ps ON ps.id=r.product_service_id AND ps.tenant_id=q.tenant_id WHERE q.tenant_id=:t AND (q.status='approved' OR EXISTS(SELECT 1 FROM jobs j WHERE j.id=:jid AND j.tenant_id=q.tenant_id AND j.quote_id=q.id)) AND (NOT EXISTS(SELECT 1 FROM jobs j2 WHERE j2.tenant_id=q.tenant_id AND j2.quote_id=q.id AND j2.deleted_at IS NULL) OR EXISTS(SELECT 1 FROM jobs j3 WHERE j3.id=:jid2 AND j3.tenant_id=q.tenant_id AND j3.quote_id=q.id)) ORDER BY q.id DESC");
    $stmt->execute(array(':t' => $tenant, ':jid' => $jobId, ':jid2' => $jobId));
    $meta['quotes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT c.id,c.display_name AS name,c.email,c.phone,c.branch_id,b.name AS branch_name FROM clients c LEFT JOIN branches b ON b.id=c.branch_id AND b.tenant_id=c.tenant_id WHERE c.tenant_id=:t AND c.deleted_at IS NULL AND c.client_type<>'archived' AND c.status<>'archived' ORDER BY c.display_name");
    $stmt->execute(array(':t' => $tenant));
    $meta['clients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id,client_id,CONCAT(name, CASE WHEN address_line1 IS NOT NULL AND address_line1<>'' THEN CONCAT(' · ',address_line1) ELSE '' END, CASE WHEN city IS NOT NULL AND city<>'' THEN CONCAT(', ',city) ELSE '' END) AS name FROM client_locations WHERE tenant_id=:t AND status='active' ORDER BY is_primary DESC,name,id");
    $stmt->execute(array(':t' => $tenant));
    $meta['locations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id,CONCAT(first_name,CASE WHEN last_name IS NOT NULL AND last_name<>'' THEN CONCAT(' ',last_name) ELSE '' END) name,email,department_id,job_title,is_field_worker FROM users WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL AND (is_bookable=1 OR is_field_worker=1 OR is_tenant_admin=1) ORDER BY first_name,last_name");
    $stmt->execute(array(':t' => $tenant));
    $meta['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id,name,branch_id FROM departments WHERE tenant_id=:t AND status='active' ORDER BY name");
    $stmt->execute(array(':t' => $tenant));
    $meta['departments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id,name FROM branches WHERE tenant_id=:t AND status='active' ORDER BY is_head_office DESC,name");
    $stmt->execute(array(':t' => $tenant));
    $meta['branches'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $serviceSql = "SELECT id,name FROM product_services WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL";
    if (jbCol($pdo, 'product_services', 'item_type')) {
        $serviceSql .= " AND item_type='service'";
    }
    $serviceSql .= " ORDER BY name";

    $stmt = $pdo->prepare($serviceSql);
    $stmt->execute(array(':t' => $tenant));
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($services as &$serviceRow) {
        $workflowId = jbDefaultWorkflow($pdo, $tenant, (int)$serviceRow['id']);
        $serviceRow['workflow_id'] = $workflowId;
        $serviceRow['workflow_name'] = $workflowId ? jbWorkflowName($pdo, $tenant, $workflowId) : '';
    }
    unset($serviceRow);

    $meta['services'] = $services;

    $meta['checklist_templates'] = array();
    if (jbTable($pdo, 'checklist_templates')) {
        $stmt = $pdo->prepare("SELECT ct.id,ct.name,ct.description,(SELECT COUNT(*) FROM checklist_template_items cti WHERE cti.checklist_template_id=ct.id) item_count FROM checklist_templates ct WHERE ct.tenant_id=:t AND ct.status='active' ORDER BY ct.name");
        $stmt->execute(array(':t' => $tenant));
        $meta['checklist_templates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $meta['currency'] = jbCurrency($pdo, $tenant);
    $meta['schedule_time_columns'] = jbCol($pdo, 'jobs', 'start_time') && jbCol($pdo, 'jobs', 'end_time') ? 1 : 0;
    $meta['expanded_schedule_ready'] = (jbTable($pdo, 'job_schedules') && jbTable($pdo, 'job_schedule_assignees') && jbTable($pdo, 'visit_assignments') && jbTable($pdo, 'job_billing_settings') && jbTable($pdo, 'visits')) ? 1 : 0;
    $meta['attachments_ready'] = jbTable($pdo, 'attachments') ? 1 : 0;
    $meta['checklists_ready'] = (jbTable($pdo, 'checklist_templates') && jbTable($pdo, 'checklist_template_items') && jbTable($pdo, 'job_checklists') && jbTable($pdo, 'job_checklist_items')) ? 1 : 0;
    return $meta;
}

function jbQuote(PDO $pdo, $tenant, $id, $jobId = 0)
{
    $stmt = $pdo->prepare("SELECT
            q.*,
            c.display_name client_name,
            c.email client_email,
            c.phone client_phone,
            c.allow_email,
            r.product_service_id request_product_service_id,
            r.title request_title,
            r.description request_description,
            rps.name request_service_name
        FROM quotes q
        INNER JOIN clients c
            ON c.id=q.client_id
           AND c.tenant_id=q.tenant_id
        LEFT JOIN service_requests r
            ON r.id=q.request_id
           AND r.tenant_id=q.tenant_id
        LEFT JOIN product_services rps
            ON rps.id=r.product_service_id
           AND rps.tenant_id=q.tenant_id
        WHERE q.id=:id
          AND q.tenant_id=:t
          AND (
              q.status='approved'
              OR EXISTS(
                  SELECT 1
                  FROM jobs j
                  WHERE j.id=:jid
                    AND j.tenant_id=q.tenant_id
                    AND j.quote_id=q.id
              )
          )
        LIMIT 1");

    $stmt->execute(array(':id' => $id, ':t' => $tenant, ':jid' => $jobId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jbRes(422, false, 'Select a valid approved quotation.');
    }

    /*
     * Preferred source is the service actually saved on the quotation line item.
     * This is required for direct quotations because request_id can be NULL.
     */
    $quotationServiceId = 0;
    $quotationServiceName = '';

    if (jbTable($pdo, 'quote_line_items') && jbCol($pdo, 'quote_line_items', 'product_service_id')) {
        $serviceSql = "SELECT qli.product_service_id,ps.name
                       FROM quote_line_items qli
                       INNER JOIN product_services ps
                           ON ps.id=qli.product_service_id
                          AND ps.tenant_id=:t
                          AND ps.status='active'
                          AND ps.deleted_at IS NULL
                       WHERE qli.quote_id=:q
                         AND qli.product_service_id IS NOT NULL";

        if (jbCol($pdo, 'product_services', 'item_type')) {
            $serviceSql .= " AND ps.item_type='service'";
        }

        $serviceSql .= " ORDER BY qli.sort_order,qli.id LIMIT 1";

        $serviceStmt = $pdo->prepare($serviceSql);
        $serviceStmt->execute(array(':t' => $tenant, ':q' => $id));
        $quotationService = $serviceStmt->fetch(PDO::FETCH_ASSOC);

        if ($quotationService) {
            $quotationServiceId = (int)$quotationService['product_service_id'];
            $quotationServiceName = (string)$quotationService['name'];
        }
    }

    if ($quotationServiceId > 0) {
        $row['product_service_id'] = $quotationServiceId;
        $row['service_name'] = $quotationServiceName;
        $row['service_source'] = 'quotation';
    } elseif (!empty($row['request_product_service_id'])) {
        $requestService = jbService($pdo, $tenant, (int)$row['request_product_service_id']);

        if ($requestService) {
            $row['product_service_id'] = (int)$requestService['id'];
            $row['service_name'] = (string)$requestService['name'];
            $row['service_source'] = 'request';
        } else {
            $row['product_service_id'] = null;
            $row['service_name'] = null;
            $row['service_source'] = 'none';
        }
    } else {
        $row['product_service_id'] = null;
        $row['service_name'] = null;
        $row['service_source'] = 'none';
    }

    $row['service_required'] = empty($row['product_service_id']) ? 1 : 0;
    $row['workflow_id'] = jbDefaultWorkflow($pdo, $tenant, (int)$row['product_service_id']);
    $row['workflow_name'] = !empty($row['workflow_id'])
        ? jbWorkflowName($pdo, $tenant, (int)$row['workflow_id'])
        : '';
    $row['source_mode'] = 'quotation';

    return $row;
}

function jbDirectJobContext(PDO $pdo, $tenant, $clientId, $locationId, $serviceId, $branchId, $requestId, $sessionBranch)
{
    $clientId = (int)$clientId;
    if ($clientId <= 0) jbRes(422, false, 'Select a customer for the direct job.');
    $stmt = $pdo->prepare("SELECT c.id,c.display_name client_name,c.email client_email,c.phone client_phone,c.allow_email,c.branch_id,b.name branch_name FROM clients c LEFT JOIN branches b ON b.id=c.branch_id AND b.tenant_id=c.tenant_id WHERE c.id=:id AND c.tenant_id=:t AND c.deleted_at IS NULL AND c.client_type<>'archived' AND c.status<>'archived' LIMIT 1");
    $stmt->execute(array(':id' => $clientId, ':t' => $tenant));
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) jbRes(422, false, 'Select a valid customer for the direct job.');

    $locationId = (int)$locationId;
    if ($locationId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM client_locations WHERE id=:id AND tenant_id=:t AND client_id=:c AND status='active' LIMIT 1");
        $stmt->execute(array(':id' => $locationId, ':t' => $tenant, ':c' => $clientId));
        if (!$stmt->fetchColumn()) jbRes(422, false, 'Select a valid service location for this customer.');
    }

    $service = jbService($pdo, $tenant, (int)$serviceId);
    if (!$service) jbRes(422, false, 'Select a valid service for the direct job.');

    $branchId = (int)$branchId;
    if ($branchId <= 0) $branchId = !empty($client['branch_id']) ? (int)$client['branch_id'] : (int)$sessionBranch;
    if ($branchId > 0) {
        $stmt = $pdo->prepare("SELECT id,name FROM branches WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");
        $stmt->execute(array(':id' => $branchId, ':t' => $tenant));
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$branch) jbRes(422, false, 'Select a valid branch for the direct job.');
        $client['branch_name'] = $branch['name'];
    }

    $requestId = (int)$requestId;
    if ($requestId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM service_requests WHERE id=:id AND tenant_id=:t AND client_id=:c AND deleted_at IS NULL LIMIT 1");
        $stmt->execute(array(':id' => $requestId, ':t' => $tenant, ':c' => $clientId));
        if (!$stmt->fetchColumn()) $requestId = 0;
    }

    return array(
        'source_mode' => 'direct', 'id' => 0, 'quote_no' => '', 'title' => '', 'request_title' => '',
        'client_id' => $clientId, 'location_id' => $locationId > 0 ? $locationId : null,
        'request_id' => $requestId > 0 ? $requestId : null, 'branch_id' => $branchId > 0 ? $branchId : null,
        'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        'client_name' => (string)$client['client_name'], 'client_email' => (string)$client['client_email'],
        'client_phone' => (string)$client['client_phone'], 'allow_email' => (int)$client['allow_email'],
        'product_service_id' => (int)$service['id'], 'service_name' => (string)$service['name'], 'service_source' => 'direct'
    );
}

function jbContextFromJob(PDO $pdo, $tenant, $job)
{
    if (!empty($job['quote_id'])) return jbQuote($pdo, $tenant, (int)$job['quote_id'], (int)$job['id']);
    return array(
        'source_mode' => 'direct', 'client_id' => (int)$job['client_id'], 'location_id' => !empty($job['location_id']) ? (int)$job['location_id'] : null,
        'request_id' => !empty($job['request_id']) ? (int)$job['request_id'] : null, 'branch_id' => !empty($job['branch_id']) ? (int)$job['branch_id'] : null,
        'subtotal' => isset($job['subtotal']) ? (float)$job['subtotal'] : 0, 'tax_total' => isset($job['tax_total']) ? (float)$job['tax_total'] : 0, 'total' => isset($job['total']) ? (float)$job['total'] : 0,
        'client_name' => isset($job['client_name']) ? (string)$job['client_name'] : '', 'client_email' => isset($job['client_email']) ? (string)$job['client_email'] : '',
        'client_phone' => isset($job['client_phone']) ? (string)$job['client_phone'] : '', 'allow_email' => isset($job['client_allow_email']) ? (int)$job['client_allow_email'] : 1,
        'product_service_id' => !empty($job['product_service_id']) ? (int)$job['product_service_id'] : null, 'service_name' => isset($job['service_name']) ? (string)$job['service_name'] : '',
        'quote_no' => '', 'title' => isset($job['title']) ? (string)$job['title'] : '', 'request_title' => isset($job['request_title']) ? (string)$job['request_title'] : ''
    );
}

function jbInitWorkflow(PDO $pdo, $tenant, $job, $workflow, $primaryUser)
{
    if ($workflow <= 0 || !jbTable($pdo, 'job_workflow_progress')) return;
    $q = $pdo->prepare("SELECT id FROM workflow_steps WHERE workflow_id=:w ORDER BY sort_order,id");
    $q->execute(array(':w' => $workflow));
    $steps = $q->fetchAll(PDO::FETCH_COLUMN);
    if (!$steps) return;

    $check = $pdo->prepare("SELECT id,status FROM job_workflow_progress WHERE tenant_id=:t AND job_id=:j AND visit_id IS NULL AND workflow_step_id=:s ORDER BY id LIMIT 1");
    $ins = $pdo->prepare("INSERT INTO job_workflow_progress(tenant_id,job_id,visit_id,workflow_step_id,assigned_user_id,assigned_team_id,status) VALUES(:t,:j,NULL,:s,:u,NULL,:st)");
    $upd = $pdo->prepare("UPDATE job_workflow_progress SET assigned_user_id=:u WHERE id=:id AND status IN('pending','available')");

    foreach ($steps as $index => $stepId) {
        $check->execute(array(':t' => $tenant, ':j' => $job, ':s' => $stepId));
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $ins->execute(array(':t' => $tenant, ':j' => $job, ':s' => $stepId, ':u' => $primaryUser > 0 ? $primaryUser : null, ':st' => $index === 0 ? 'available' : 'pending'));
        } else {
            $upd->execute(array(':u' => $primaryUser > 0 ? $primaryUser : null, ':id' => $row['id']));
        }
    }
}

function jbActivity(PDO $pdo, $tenant, $branch, $user, $type, $job, $client, $title, $details)
{
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client) VALUES(:t,:b,:u,'user',:e,'job',:rid,:cid,:title,:d,0)");
        $stmt->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : null, ':u' => $user, ':e' => $type, ':rid' => $job, ':cid' => $client, ':title' => substr($title, 0, 255), ':d' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    } catch (Throwable $e) {
        error_log('job activity ' . $e->getMessage());
    }
}

function jbSmtpSecretKey()
{
    if (!defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        $secretFile = __DIR__ . '/../includes/smtp-secret.php';
        if (is_file($secretFile)) {
            require_once $secretFile;
        }
    }

    $key = defined('FIELDPLX_SMTP_ENCRYPTION_KEY')
        ? trim((string)FIELDPLX_SMTP_ENCRYPTION_KEY)
        : '';

    if ($key === '') {
        $env = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if ($env !== false) $key = trim((string)$env);
    }

    if ($key === '') {
        $env = getenv('APP_KEY');
        if ($env !== false) $key = trim((string)$env);
    }

    if ($key === '' || strlen($key) < 32) {
        throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY is not configured.');
    }

    return hash('sha256', $key, true);
}

function jbDecrypt($encrypted, $tenant)
{
    $encrypted = trim((string)$encrypted);
    if ($encrypted === '') return '';

    /* Current permanent SMTP format used by Master Controls. */
    if (strpos($encrypted, 'v1:') === 0) {
        $raw = base64_decode(substr($encrypted, 3), true);
        if ($raw === false || strlen($raw) <= 16) {
            throw new RuntimeException('Stored SMTP password is invalid.');
        }

        $plain = openssl_decrypt(
            substr($raw, 16),
            'AES-256-CBC',
            jbSmtpSecretKey(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 16)
        );

        if ($plain === false) {
            throw new RuntimeException('Unable to decrypt SMTP password. Confirm the same permanent SMTP encryption key is used on this server.');
        }

        return $plain;
    }

    /* Do not silently try the obsolete tenant-derived format. */
    throw new RuntimeException('SMTP password uses the old encryption format. Re-enter and save the SMTP password once in Master Controls.');
}

function jbSmtpRead($socket)
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) break;
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return trim($response);
}

function jbSmtpCmd($socket, $cmd, $ok, $label)
{
    if ($cmd !== null && @fwrite($socket, $cmd . "\r\n") === false) throw new RuntimeException('SMTP connection closed during ' . $label . '.');
    $response = jbSmtpRead($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, (array)$ok, true)) throw new RuntimeException($label . ' failed (SMTP ' . $code . '): ' . substr(preg_replace('/[\r\n]+/', ' ', $response), 0, 220));
    return $response;
}

function jbSmtpConfig(PDO $pdo, $tenant, $branch)
{
    if (!jbTable($pdo, 'smtp_configurations')) return null;
    $stmt = $pdo->prepare("SELECT * FROM smtp_configurations WHERE tenant_id=:t AND is_active=1 AND scope_type IN('tenant','branch') AND (scope_type='tenant' OR (scope_type='branch' AND branch_id=:b)) ORDER BY CASE WHEN scope_type='branch' AND branch_id=:b2 THEN 0 ELSE 1 END,is_default DESC,id DESC LIMIT 1");
    $stmt->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : -1, ':b2' => $branch > 0 ? $branch : -1));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function jbMail($cfg, $password, $to, $subject, $html)
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Recipient email is invalid.');
    $host = trim($cfg['host']);
    $port = (int)$cfg['port'];
    $enc = strtolower(trim($cfg['encryption']));
    $user = trim((string)$cfg['username']);
    $from = trim((string)$cfg['from_email']);
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP From Email is invalid.');
    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(array('ssl' => array('verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $host)));
    $errno = 0;
    $err = '';
    $socket = @stream_socket_client($remote, $errno, $err, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) throw new RuntimeException('Unable to connect to SMTP server: ' . ($err !== '' ? $err : 'connection failed'));
    stream_set_timeout($socket, 20);
    try {
        jbSmtpCmd($socket, null, array(220), 'SMTP greeting');
        $ehlo = 'fieldplx.local';
        jbSmtpCmd($socket, 'EHLO ' . $ehlo, array(250), 'EHLO');
        if ($enc === 'tls' || $enc === 'starttls') {
            jbSmtpCmd($socket, 'STARTTLS', array(220), 'STARTTLS');
            $method = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT') ? STREAM_CRYPTO_METHOD_TLS_CLIENT : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
            if (@stream_socket_enable_crypto($socket, true, $method) !== true) throw new RuntimeException('Unable to establish TLS encryption.');
            jbSmtpCmd($socket, 'EHLO ' . $ehlo, array(250), 'EHLO after TLS');
        }
        if ($user !== '') {
            jbSmtpCmd($socket, 'AUTH LOGIN', array(334), 'SMTP authentication');
            jbSmtpCmd($socket, base64_encode($user), array(334), 'SMTP username');
            jbSmtpCmd($socket, base64_encode($password), array(235), 'SMTP password');
        }
        jbSmtpCmd($socket, 'MAIL FROM:<' . $from . '>', array(250), 'MAIL FROM');
        jbSmtpCmd($socket, 'RCPT TO:<' . $to . '>', array(250, 251), 'RCPT TO');
        jbSmtpCmd($socket, 'DATA', array(354), 'DATA');
        $fromName = trim((string)$cfg['from_name']);
        $headers = array(
            'Date: ' . date(DATE_RFC2822),
            'From: ' . ($fromName !== '' ? $fromName : 'FieldPlx') . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . str_replace(array("\r", "\n"), ' ', $subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8'
        );
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $html;
        $payload = preg_replace('/(?m)^\./', '..', $payload);
        @fwrite($socket, $payload . "\r\n.\r\n");
        jbSmtpCmd($socket, null, array(250), 'Message delivery');
        @fwrite($socket, "QUIT\r\n");
    } finally {
        @fclose($socket);
    }
    return true;
}

function jbScheduleText($job)
{
    $start = !empty($job['start_date']) ? date('d M Y', strtotime($job['start_date'])) : '-';
    $end = !empty($job['end_date']) ? date('d M Y', strtotime($job['end_date'])) : '-';
    if (!empty($job['start_time'])) $start .= ' ' . date('h:i A', strtotime($job['start_time']));
    if (!empty($job['end_time'])) $end .= ' ' . date('h:i A', strtotime($job['end_time']));
    return $start . ' to ' . $end;
}

function jbNotifyEmployees(PDO $pdo, $tenant, $branch, $job, $quote, $users, $includeInApp = true)
{
    $summary = array('in_app' => 0, 'employee_email_sent' => 0, 'employee_email_failed' => 0, 'employee_email_skipped' => 0, 'messages' => array());
    $cfg = jbSmtpConfig($pdo, $tenant, $branch);
    $password = null;
    if (!$cfg) {
        $summary['messages'][] = 'Employee email skipped because no active tenant/branch SMTP configuration was found.';
    }
    if ($cfg) {
        try {
            $password = jbDecrypt($cfg['password_encrypted'], $tenant);
        } catch (Throwable $e) {
            $summary['messages'][] = $e->getMessage();
            $cfg = null;
        }
    }
    $schedule = jbScheduleText($job);
    foreach ($users as $assigned) {
        $uid = (int)$assigned['id'];
        $name = trim((string)$assigned['name']);
        $email = trim((string)$assigned['email']);
        if ($includeInApp) {
            try {
                $stmt = $pdo->prepare("INSERT INTO in_app_notifications(tenant_id,user_id,title,message,related_type,related_id,action_url,icon_name,is_read) VALUES(:t,:u,'Job Assigned',:m,'job',:j,:url,'briefcase',0)");
                $stmt->execute(array(':t' => $tenant, ':u' => $uid, ':m' => 'You have been assigned to ' . $job['job_no'] . ' - ' . $job['title'] . '. Schedule: ' . $schedule . '.', ':j' => $job['id'], ':url' => 'my-job-view.php?id=' . $job['id']));
                $summary['in_app']++;
            } catch (Throwable $e) {
                $summary['messages'][] = 'In-app notification failed for ' . $name . ': ' . $e->getMessage();
            }
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$cfg) {
            $summary['employee_email_skipped']++;
            continue;
        }
        try {
            $html = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#1f2d3d">'
                . '<div style="padding:18px 20px;background:#001131;color:#fff"><h2 style="margin:0;font-size:20px">New Job Assigned</h2></div>'
                . '<div style="padding:20px;border:1px solid #e5eaf1;border-top:0">'
                . '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>You have been assigned to <strong>' . htmlspecialchars($job['job_no'], ENT_QUOTES, 'UTF-8') . '</strong> - ' . htmlspecialchars($job['title'], ENT_QUOTES, 'UTF-8') . '.</p>'
                . '<p><strong>Customer:</strong> ' . htmlspecialchars($quote['client_name'], ENT_QUOTES, 'UTF-8') . '<br>'
                . '<strong>Service:</strong> ' . htmlspecialchars($quote['service_name'] ? $quote['service_name'] : 'Service Job', ENT_QUOTES, 'UTF-8') . '<br>'
                . '<strong>Schedule:</strong> ' . htmlspecialchars($schedule, ENT_QUOTES, 'UTF-8') . '<br>'
                . '<strong>Priority:</strong> ' . htmlspecialchars(ucfirst($job['priority']), ENT_QUOTES, 'UTF-8') . '</p>'
                . (!empty($job['description']) ? '<p><strong>Work Instructions:</strong><br>' . nl2br(htmlspecialchars($job['description'], ENT_QUOTES, 'UTF-8')) . '</p>' : '')
                . '<p>Please login to FieldPlx and open My Jobs for the full job card.</p>'
                . '</div></div>';
            jbMail($cfg, $password, $email, 'Job Assigned - ' . $job['job_no'], $html);
            $summary['employee_email_sent']++;
        } catch (Throwable $e) {
            $summary['employee_email_failed']++;
            $summary['messages'][] = $email . ': ' . $e->getMessage();
        }
    }
    return $summary;
}

function jbNotifyCustomer(PDO $pdo, $tenant, $branch, $job, $quote)
{
    $summary = array('customer_email_sent' => 0, 'customer_email_failed' => 0, 'customer_email_skipped' => 0, 'messages' => array());
    $email = trim((string)$quote['client_email']);
    if ((int)$quote['allow_email'] !== 1 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $summary['customer_email_skipped'] = 1;
        return $summary;
    }
    $cfg = jbSmtpConfig($pdo, $tenant, $branch);
    if (!$cfg) {
        $summary['customer_email_skipped'] = 1;
        $summary['messages'][] = 'Customer email skipped because no active tenant/branch SMTP configuration was found.';
        return $summary;
    }
    try {
        $password = jbDecrypt($cfg['password_encrypted'], $tenant);
        $schedule = jbScheduleText($job);
        $html = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#1f2d3d">'
            . '<div style="padding:18px 20px;background:#001131;color:#fff"><h2 style="margin:0;font-size:20px">Your Job Has Been Scheduled</h2></div>'
            . '<div style="padding:20px;border:1px solid #e5eaf1;border-top:0">'
            . '<p>Hello ' . htmlspecialchars($quote['client_name'], ENT_QUOTES, 'UTF-8') . ',</p>'
            . (!empty($quote['source_mode']) && $quote['source_mode'] === 'direct' ? '<p>Your service job <strong>' . htmlspecialchars($job['job_no'], ENT_QUOTES, 'UTF-8') . '</strong> has been scheduled.</p>' : '<p>Your approved quotation has been converted into job <strong>' . htmlspecialchars($job['job_no'], ENT_QUOTES, 'UTF-8') . '</strong>.</p>')
            . '<div style="margin:16px 0;padding:14px;background:#f6f8fb;border-radius:8px">'
            . '<strong>Job:</strong> ' . htmlspecialchars($job['title'], ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Service:</strong> ' . htmlspecialchars($quote['service_name'] ? $quote['service_name'] : 'Service Job', ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Scheduled:</strong> ' . htmlspecialchars($schedule, ENT_QUOTES, 'UTF-8')
            . '</div>'
            . '<p>Our assigned service team will attend according to the above schedule. Please keep the service location accessible.</p>'
            . '<p>Thank you,<br>FieldPlx</p>'
            . '</div></div>';
        jbMail($cfg, $password, $email, 'Job Scheduled - ' . $job['job_no'], $html);
        $summary['customer_email_sent'] = 1;
    } catch (Throwable $e) {
        $summary['customer_email_failed'] = 1;
        $summary['messages'][] = 'Customer ' . $email . ': ' . $e->getMessage();
    }
    return $summary;
}



/* ==========================================================
 * Expanded Job Card helpers - schedules / visits / billing
 * ========================================================== */
function jbIntList($value)
{
    $out = array();
    if (!is_array($value)) return $out;
    foreach ($value as $item) {
        $id = (int)$item;
        if ($id > 0) $out[$id] = $id;
    }
    return array_values($out);
}

function jbUsersByIds(PDO $pdo, $tenant, $ids)
{
    $ids = jbIntList($ids);
    if (!$ids) return array();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT id,CONCAT(first_name,CASE WHEN last_name IS NOT NULL AND last_name<>'' THEN CONCAT(' ',last_name) ELSE '' END) name,email,department_id,job_title FROM users WHERE tenant_id=? AND id IN ($placeholders) AND status='active' AND deleted_at IS NULL AND (is_bookable=1 OR is_field_worker=1 OR is_tenant_admin=1)";
    $stmt = $pdo->prepare($sql);
    $params = array_merge(array((int)$tenant), $ids);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = array();
    foreach ($rows as $row) $map[(int)$row['id']] = $row;
    $ordered = array();
    foreach ($ids as $id) if (isset($map[$id])) $ordered[] = $map[$id];
    return $ordered;
}

function jbParseSchedulePayload($raw)
{
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded) || !$decoded) jbRes(422, false, 'Add at least one job schedule.');
    if (count($decoded) > 25) jbRes(422, false, 'A job can contain a maximum of 25 schedule definitions.');

    $validRepeat = array('none','daily','weekly','monthly','yearly');
    $validEnd = array('on_date','after_occurrences','after_duration');
    $out = array();

    foreach ($decoded as $index => $row) {
        if (!is_array($row)) jbRes(422, false, 'Invalid schedule data.');
        $startDate = jbDate(isset($row['start_date']) ? $row['start_date'] : '');
        $endDate = jbDate(isset($row['end_date']) ? $row['end_date'] : '');
        $startTime = jbTime(isset($row['start_time']) ? $row['start_time'] : '');
        $endTime = jbTime(isset($row['end_time']) ? $row['end_time'] : '');
        if ($startDate === false || $endDate === false || $startDate === null || $endDate === null) jbRes(422, false, 'Schedule ' . ($index + 1) . ': enter valid start and end dates.');
        if ($startTime === false || $endTime === false || $startTime === null || $endTime === null) jbRes(422, false, 'Schedule ' . ($index + 1) . ': enter valid start and end times.');

        $startStamp = strtotime($startDate . ' ' . $startTime);
        $endStamp = strtotime($endDate . ' ' . $endTime);
        if ($endStamp <= $startStamp) jbRes(422, false, 'Schedule ' . ($index + 1) . ': end date/time must be after start date/time.');

        $repeat = strtolower(trim(isset($row['repeat_type']) ? (string)$row['repeat_type'] : 'none'));
        if (!in_array($repeat, $validRepeat, true)) $repeat = 'none';
        $interval = max(1, min(365, (int)(isset($row['repeat_interval']) ? $row['repeat_interval'] : 1)));
        $weekly = jbIntList(isset($row['weekly_days']) ? $row['weekly_days'] : array());
        $weekly = array_values(array_filter($weekly, function($x){ return $x >= 0 && $x <= 6; }));
        if ($repeat === 'weekly' && !$weekly) $weekly = array((int)date('w', $startStamp));

        $endMode = strtolower(trim(isset($row['end_mode']) ? (string)$row['end_mode'] : 'after_occurrences'));
        if (!in_array($endMode, $validEnd, true)) $endMode = 'after_occurrences';
        $repeatEndDate = null;
        $occurrences = 1;
        $endAfterValue = null;
        $endAfterUnit = null;

        if ($repeat !== 'none') {
            if ($endMode === 'on_date') {
                $repeatEndDate = jbDate(isset($row['repeat_end_date']) ? $row['repeat_end_date'] : '');
                if ($repeatEndDate === false || $repeatEndDate === null) jbRes(422, false, 'Schedule ' . ($index + 1) . ': select the recurrence end date.');
                if (strtotime($repeatEndDate) < strtotime($startDate)) jbRes(422, false, 'Schedule ' . ($index + 1) . ': recurrence end date cannot be before the start date.');
                $occurrences = null;
            } elseif ($endMode === 'after_duration') {
                $endAfterValue = max(1, min(120, (int)(isset($row['end_after_value']) ? $row['end_after_value'] : 1)));
                $endAfterUnit = strtolower(trim(isset($row['end_after_unit']) ? (string)$row['end_after_unit'] : 'months'));
                if (!in_array($endAfterUnit, array('days','weeks','months','years'), true)) $endAfterUnit = 'months';
                $baseDurationDate = new DateTime($startDate . ' 00:00:00');
                if ($endAfterUnit === 'days') { $durationDate = clone $baseDurationDate; $durationDate->modify('+' . $endAfterValue . ' days'); }
                elseif ($endAfterUnit === 'weeks') { $durationDate = clone $baseDurationDate; $durationDate->modify('+' . $endAfterValue . ' weeks'); }
                elseif ($endAfterUnit === 'years') $durationDate = jbYearOccurrence($baseDurationDate, $endAfterValue);
                else $durationDate = jbMonthOccurrence($baseDurationDate, $endAfterValue);
                $repeatEndDate = $durationDate->format('Y-m-d');
                $occurrences = null;
            } else {
                $occurrences = max(1, min(500, (int)(isset($row['repeat_occurrences']) ? $row['repeat_occurrences'] : 1)));
            }
        } else {
            $endMode = 'after_occurrences';
            $occurrences = 1;
            $interval = 1;
            $weekly = array();
        }

        $out[] = array(
            'start_date' => $startDate,
            'start_time' => $startTime,
            'end_date' => $endDate,
            'end_time' => $endTime,
            'repeat_type' => $repeat,
            'repeat_interval' => $interval,
            'weekly_days' => $weekly,
            'end_mode' => $endMode,
            'repeat_end_date' => $repeatEndDate,
            'repeat_occurrences' => $occurrences,
            'end_after_value' => $endAfterValue,
            'end_after_unit' => $endAfterUnit,
            'instructions' => trim(isset($row['instructions']) ? (string)$row['instructions'] : ''),
            'assignee_ids' => jbIntList(isset($row['assignee_ids']) ? $row['assignee_ids'] : array())
        );
    }
    return $out;
}

function jbMonthOccurrence(DateTime $base, $months)
{
    $year = (int)$base->format('Y');
    $month = (int)$base->format('n');
    $day = (int)$base->format('j');
    $total = ($year * 12 + ($month - 1)) + (int)$months;
    $targetYear = (int)floor($total / 12);
    $targetMonth = ($total % 12) + 1;
    $last = (int)date('t', strtotime(sprintf('%04d-%02d-01', $targetYear, $targetMonth)));
    $targetDay = min($day, $last);
    return new DateTime(sprintf('%04d-%02d-%02d %s', $targetYear, $targetMonth, $targetDay, $base->format('H:i:s')));
}

function jbYearOccurrence(DateTime $base, $years)
{
    $year = (int)$base->format('Y') + (int)$years;
    $month = (int)$base->format('n');
    $day = (int)$base->format('j');
    $last = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    return new DateTime(sprintf('%04d-%02d-%02d %s', $year, $month, min($day, $last), $base->format('H:i:s')));
}

function jbBuildOccurrences($schedules)
{
    $all = array();
    $globalCap = 500;

    foreach ($schedules as $scheduleIndex => $s) {
        $baseStart = new DateTime($s['start_date'] . ' ' . $s['start_time']);
        $baseEnd = new DateTime($s['end_date'] . ' ' . $s['end_time']);
        $duration = $baseEnd->getTimestamp() - $baseStart->getTimestamp();
        $starts = array();
        $repeat = $s['repeat_type'];

        if ($repeat === 'none') {
            $starts[] = clone $baseStart;
        } elseif ($repeat === 'daily') {
            $i = 0;
            while (count($starts) < $globalCap) {
                $d = clone $baseStart;
                if ($i > 0) $d->modify('+' . ($i * $s['repeat_interval']) . ' days');
                if (in_array($s['end_mode'], array('on_date','after_duration'), true) && $d->format('Y-m-d') > $s['repeat_end_date']) break;
                $starts[] = $d;
                $i++;
                if ($s['end_mode'] === 'after_occurrences' && count($starts) >= (int)$s['repeat_occurrences']) break;
            }
        } elseif ($repeat === 'weekly') {
            $cursor = clone $baseStart;
            $cursor->setTime((int)$baseStart->format('H'), (int)$baseStart->format('i'), (int)$baseStart->format('s'));
            $anchor = clone $baseStart;
            $anchor->setTime(0,0,0);
            $anchor->modify('-' . ((int)$anchor->format('N') - 1) . ' days');
            $guard = 0;
            while (count($starts) < $globalCap && $guard < 20000) {
                if (in_array($s['end_mode'], array('on_date','after_duration'), true) && $cursor->format('Y-m-d') > $s['repeat_end_date']) break;
                $cursorMid = clone $cursor; $cursorMid->setTime(0,0,0);
                $days = (int)floor(($cursorMid->getTimestamp() - $anchor->getTimestamp()) / 86400);
                $weekIndex = (int)floor($days / 7);
                $dow = (int)$cursor->format('w');
                if ($weekIndex % $s['repeat_interval'] === 0 && in_array($dow, $s['weekly_days'], true)) {
                    $starts[] = clone $cursor;
                    if ($s['end_mode'] === 'after_occurrences' && count($starts) >= (int)$s['repeat_occurrences']) break;
                }
                $cursor->modify('+1 day');
                $guard++;
            }
        } elseif ($repeat === 'monthly') {
            $i = 0;
            while (count($starts) < $globalCap) {
                $d = jbMonthOccurrence($baseStart, $i * $s['repeat_interval']);
                if (in_array($s['end_mode'], array('on_date','after_duration'), true) && $d->format('Y-m-d') > $s['repeat_end_date']) break;
                $starts[] = $d;
                $i++;
                if ($s['end_mode'] === 'after_occurrences' && count($starts) >= (int)$s['repeat_occurrences']) break;
            }
        } elseif ($repeat === 'yearly') {
            $i = 0;
            while (count($starts) < $globalCap) {
                $d = jbYearOccurrence($baseStart, $i * $s['repeat_interval']);
                if (in_array($s['end_mode'], array('on_date','after_duration'), true) && $d->format('Y-m-d') > $s['repeat_end_date']) break;
                $starts[] = $d;
                $i++;
                if ($s['end_mode'] === 'after_occurrences' && count($starts) >= (int)$s['repeat_occurrences']) break;
            }
        }

        foreach ($starts as $start) {
            $end = clone $start;
            if ($duration > 0) $end->modify('+' . $duration . ' seconds');
            $all[] = array(
                'schedule_index' => $scheduleIndex,
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
                'instructions' => $s['instructions'],
                'assignee_ids' => $s['assignee_ids']
            );
            if (count($all) > $globalCap) jbRes(422, false, 'The recurring schedule creates more than 500 visits. Reduce the recurrence range.');
        }
    }

    usort($all, function($a, $b){ return strcmp($a['start'], $b['start']); });
    if (!$all) jbRes(422, false, 'The recurrence settings do not create any visits.');
    return $all;
}

function jbJobSchedules(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo, 'job_schedules')) return array();
    $stmt = $pdo->prepare("SELECT * FROM job_schedules WHERE tenant_id=:t AND job_id=:j ORDER BY schedule_order,id");
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['weekly_days'] = !empty($row['weekly_days_json']) ? json_decode($row['weekly_days_json'], true) : array();
        if (!is_array($row['weekly_days'])) $row['weekly_days'] = array();
        $row['assignee_ids'] = array();
        if (jbTable($pdo, 'job_schedule_assignees')) {
            $a = $pdo->prepare("SELECT user_id FROM job_schedule_assignees WHERE tenant_id=:t AND job_schedule_id=:s ORDER BY is_primary DESC,id");
            $a->execute(array(':t' => $tenant, ':s' => $row['id']));
            $row['assignee_ids'] = array_map('intval', $a->fetchAll(PDO::FETCH_COLUMN));
        }
        unset($row['weekly_days_json']);
    }
    unset($row);
    return $rows;
}

function jbJobBilling(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo, 'job_billing_settings')) return null;
    $stmt = $pdo->prepare("SELECT * FROM job_billing_settings WHERE tenant_id=:t AND job_id=:j LIMIT 1");
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function jbJobAttachments(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo, 'attachments')) return array();
    $stmt = $pdo->prepare("SELECT id,file_name,file_path,file_mime,file_size,attachment_type,description,created_at FROM attachments WHERE tenant_id=:t AND job_id=:j ORDER BY id DESC");
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jbJobChecklistTemplateIds(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo, 'job_checklists')) return array();
    $sql = "SELECT DISTINCT checklist_template_id FROM job_checklists WHERE tenant_id=:t AND job_id=:j AND checklist_template_id IS NOT NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function jbSaveJobChecklists(PDO $pdo, $tenant, $jobId, $primaryUser, $createdBy)
{
    if (!jbTable($pdo, 'checklist_templates') || !jbTable($pdo, 'checklist_template_items') || !jbTable($pdo, 'job_checklists') || !jbTable($pdo, 'job_checklist_items')) return array();

    $selected = isset($_POST['checklist_template_ids']) && is_array($_POST['checklist_template_ids']) ? jbIntList($_POST['checklist_template_ids']) : array();
    $customRaw = trim((string)jbP('new_checklist_json', ''));
    if ($customRaw !== '') {
        $custom = json_decode($customRaw, true);
        if (is_array($custom) && trim(isset($custom['name']) ? (string)$custom['name'] : '') !== '') {
            $name = substr(trim((string)$custom['name']), 0, 190);
            $desc = trim(isset($custom['description']) ? (string)$custom['description'] : '');
            $items = isset($custom['items']) && is_array($custom['items']) ? $custom['items'] : array();
            $validItems = array();
            foreach ($items as $item) {
                $title = substr(trim(isset($item['title']) ? (string)$item['title'] : ''), 0, 255);
                if ($title === '') continue;
                $validItems[] = array('title' => $title, 'description' => trim(isset($item['description']) ? (string)$item['description'] : ''), 'required' => !empty($item['required']) ? 1 : 0);
            }
            if (!$validItems) jbRes(422, false, 'The new checklist needs at least one checklist item.');
            $stmt = $pdo->prepare("INSERT INTO checklist_templates(tenant_id,name,description,status,created_by) VALUES(:t,:n,:d,'active',:u)");
            $stmt->execute(array(':t' => $tenant, ':n' => $name, ':d' => $desc !== '' ? $desc : null, ':u' => $createdBy));
            $templateId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO checklist_template_items(checklist_template_id,title,description,is_required,sort_order) VALUES(:ct,:title,:d,:r,:o)");
            foreach ($validItems as $i => $item) $ins->execute(array(':ct' => $templateId, ':title' => $item['title'], ':d' => $item['description'] !== '' ? $item['description'] : null, ':r' => $item['required'], ':o' => $i + 1));
            $selected[] = $templateId;
        }
    }
    $selected = jbIntList($selected);

    $valid = array();
    if ($selected) {
        $placeholders = implode(',', array_fill(0, count($selected), '?'));
        $stmt = $pdo->prepare("SELECT id,name FROM checklist_templates WHERE tenant_id=? AND status='active' AND id IN ($placeholders)");
        $stmt->execute(array_merge(array((int)$tenant), $selected));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $valid[(int)$row['id']] = $row;
    }

    $existing = array();
    $stmt = $pdo->prepare("SELECT id,checklist_template_id,status FROM job_checklists WHERE tenant_id=:t AND job_id=:j AND visit_id IS NULL");
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tid = (int)$row['checklist_template_id'];
        if ($tid > 0) $existing[$tid] = $row;
    }

    foreach ($existing as $tid => $row) {
        if (!isset($valid[$tid]) && $row['status'] === 'open') {
            $pdo->prepare("DELETE FROM job_checklist_items WHERE job_checklist_id=:id")->execute(array(':id' => $row['id']));
            $pdo->prepare("DELETE FROM job_checklists WHERE id=:id AND tenant_id=:t")->execute(array(':id' => $row['id'], ':t' => $tenant));
        }
    }

    foreach ($valid as $tid => $template) {
        if (isset($existing[$tid])) continue;
        $cols = "tenant_id,job_id,visit_id,checklist_template_id,name,status";
        $vals = ":t,:j,NULL,:ct,:n,'open'";
        $params = array(':t' => $tenant, ':j' => $jobId, ':ct' => $tid, ':n' => $template['name']);
        if (jbCol($pdo, 'job_checklists', 'workflow_step_id')) { $cols .= ",workflow_step_id"; $vals .= ",NULL"; }
        if (jbCol($pdo, 'job_checklists', 'assigned_user_id')) { $cols .= ",assigned_user_id"; $vals .= ",:au"; $params[':au'] = $primaryUser > 0 ? $primaryUser : null; }
        $stmt = $pdo->prepare("INSERT INTO job_checklists($cols) VALUES($vals)");
        $stmt->execute($params);
        $jobChecklistId = (int)$pdo->lastInsertId();

        $items = $pdo->prepare("SELECT title,description,is_required,sort_order FROM checklist_template_items WHERE checklist_template_id=:ct ORDER BY sort_order,id");
        $items->execute(array(':ct' => $tid));
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if (jbCol($pdo, 'job_checklist_items', 'tenant_id')) {
                $ins = $pdo->prepare("INSERT INTO job_checklist_items(tenant_id,job_checklist_id,title,description,is_required,is_completed,sort_order) VALUES(:t,:jc,:title,:d,:r,0,:o)");
                $ins->execute(array(':t' => $tenant, ':jc' => $jobChecklistId, ':title' => $item['title'], ':d' => $item['description'], ':r' => $item['is_required'], ':o' => $item['sort_order']));
            } else {
                $ins = $pdo->prepare("INSERT INTO job_checklist_items(job_checklist_id,title,description,is_required,is_completed,sort_order) VALUES(:jc,:title,:d,:r,0,:o)");
                $ins->execute(array(':jc' => $jobChecklistId, ':title' => $item['title'], ':d' => $item['description'], ':r' => $item['is_required'], ':o' => $item['sort_order']));
            }
        }
    }
    return array_keys($valid);
}

function jbSaveJobAttachments(PDO $pdo, $tenant, $jobId, $user)
{
    $result = array('saved' => 0, 'skipped' => 0, 'messages' => array());
    if (!jbTable($pdo, 'attachments') || empty($_FILES['job_attachments']) || !is_array($_FILES['job_attachments']['name'])) return $result;
    $allowed = array('jpg','jpeg','png','webp','gif','pdf','doc','docx','xls','xlsx','txt','csv');
    $count = min(12, count($_FILES['job_attachments']['name']));
    $baseDir = dirname(__DIR__) . '/uploads/jobs/' . (int)$tenant . '/' . (int)$jobId;
    if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        $result['messages'][] = 'Attachments could not be stored because the job upload folder is not writable.';
        return $result;
    }

    for ($i = 0; $i < $count; $i++) {
        $err = isset($_FILES['job_attachments']['error'][$i]) ? (int)$_FILES['job_attachments']['error'][$i] : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) { $result['skipped']++; continue; }
        $size = (int)$_FILES['job_attachments']['size'][$i];
        if ($size <= 0 || $size > 10 * 1024 * 1024) { $result['skipped']++; $result['messages'][] = 'A file was skipped because it exceeded 10 MB.'; continue; }
        $original = basename((string)$_FILES['job_attachments']['name'][$i]);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) { $result['skipped']++; $result['messages'][] = $original . ' has an unsupported file type.'; continue; }
        $tmp = (string)$_FILES['job_attachments']['tmp_name'][$i];
        $savedName = date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target = $baseDir . '/' . $savedName;
        if (!@move_uploaded_file($tmp, $target)) { $result['skipped']++; $result['messages'][] = $original . ' could not be uploaded.'; continue; }
        $mime = '';
        if (function_exists('finfo_open')) { $fi = @finfo_open(FILEINFO_MIME_TYPE); if ($fi) { $mime = (string)@finfo_file($fi, $target); @finfo_close($fi); } }
        $relative = 'uploads/jobs/' . (int)$tenant . '/' . (int)$jobId . '/' . $savedName;
        $type = strpos($mime, 'image/') === 0 ? 'progress_photo' : 'document';
        $stmt = $pdo->prepare("INSERT INTO attachments(tenant_id,related_type,related_id,job_id,visit_id,workflow_step_id,uploaded_by,file_name,file_path,file_mime,file_size,attachment_type,description) VALUES(:t,'job',:rid,:j,NULL,NULL,:u,:fn,:fp,:fm,:fs,:at,NULL)");
        $stmt->execute(array(':t' => $tenant, ':rid' => $jobId, ':j' => $jobId, ':u' => $user, ':fn' => $original, ':fp' => $relative, ':fm' => $mime !== '' ? $mime : null, ':fs' => $size, ':at' => $type));
        $result['saved']++;
    }
    return $result;
}

function jbPersistSchedules(PDO $pdo, $tenant, $branch, $jobId, $jobNo, $schedules, $occurrences, $defaultIds, $createdBy)
{
    $pdo->prepare("DELETE FROM job_schedule_assignees WHERE tenant_id=:t AND job_schedule_id IN (SELECT id FROM job_schedules WHERE tenant_id=:t2 AND job_id=:j)")->execute(array(':t' => $tenant, ':t2' => $tenant, ':j' => $jobId));
    $pdo->prepare("DELETE FROM job_schedules WHERE tenant_id=:t AND job_id=:j")->execute(array(':t' => $tenant, ':j' => $jobId));

    $scheduleIds = array();
    foreach ($schedules as $index => $schedule) {
        $ids = $schedule['assignee_ids'] ? $schedule['assignee_ids'] : $defaultIds;
        $stmt = $pdo->prepare("INSERT INTO job_schedules(tenant_id,job_id,schedule_order,start_date,start_time,end_date,end_time,repeat_type,repeat_interval,weekly_days_json,end_mode,repeat_end_date,repeat_occurrences,end_after_value,end_after_unit,instructions) VALUES(:t,:j,:o,:sd,:st,:ed,:et,:rt,:ri,:wd,:em,:red,:ro,:eav,:eau,:ins)");
        $stmt->execute(array(':t' => $tenant, ':j' => $jobId, ':o' => $index + 1, ':sd' => $schedule['start_date'], ':st' => $schedule['start_time'], ':ed' => $schedule['end_date'], ':et' => $schedule['end_time'], ':rt' => $schedule['repeat_type'], ':ri' => $schedule['repeat_interval'], ':wd' => $schedule['weekly_days'] ? json_encode($schedule['weekly_days']) : null, ':em' => $schedule['end_mode'], ':red' => $schedule['repeat_end_date'], ':ro' => $schedule['repeat_occurrences'], ':eav' => $schedule['end_after_value'], ':eau' => $schedule['end_after_unit'], ':ins' => $schedule['instructions'] !== '' ? $schedule['instructions'] : null));
        $scheduleId = (int)$pdo->lastInsertId();
        $scheduleIds[$index] = $scheduleId;
        $insA = $pdo->prepare("INSERT INTO job_schedule_assignees(tenant_id,job_schedule_id,user_id,is_primary) VALUES(:t,:s,:u,:p)");
        foreach ($ids as $pos => $uid) $insA->execute(array(':t' => $tenant, ':s' => $scheduleId, ':u' => $uid, ':p' => $pos === 0 ? 1 : 0));
    }

    $protect = $pdo->prepare("SELECT COALESCE(MAX(visit_number),0) max_no,MAX(scheduled_start) cutoff FROM visits WHERE tenant_id=:t AND job_id=:j AND NOT (status IN('scheduled','rescheduled','cancelled') AND actual_arrival_at IS NULL AND work_started_at IS NULL AND work_ended_at IS NULL)");
    $protect->execute(array(':t' => $tenant, ':j' => $jobId));
    $protected = $protect->fetch(PDO::FETCH_ASSOC);
    $maxNo = (int)$protected['max_no'];
    $cutoff = !empty($protected['cutoff']) ? (string)$protected['cutoff'] : null;
    $pdo->prepare("DELETE FROM visits WHERE tenant_id=:t AND job_id=:j AND status IN('scheduled','rescheduled','cancelled') AND actual_arrival_at IS NULL AND work_started_at IS NULL AND work_ended_at IS NULL")->execute(array(':t' => $tenant, ':j' => $jobId));

    $visitInsert = $pdo->prepare("INSERT INTO visits(tenant_id,branch_id,visit_no,job_id,work_order_id,visit_number,visit_type,assigned_user_id,assigned_team_id,scheduled_start,scheduled_end,status,notes,created_by) VALUES(:t,:b,:no,:j,NULL,:vn,'service',:au,NULL,:ss,:se,'scheduled',:notes,:u)");
    $visitAssign = $pdo->prepare("INSERT INTO visit_assignments(tenant_id,visit_id,user_id,is_primary,status) VALUES(:t,:v,:u,:p,'assigned')");
    $created = 0;

    foreach ($occurrences as $occ) {
        if ($cutoff !== null && $occ['start'] <= $cutoff) continue;
        $ids = $occ['assignee_ids'] ? $occ['assignee_ids'] : $defaultIds;
        $maxNo++;
        $visitNo = $jobNo . '-V' . str_pad((string)$maxNo, 3, '0', STR_PAD_LEFT);
        $visitInsert->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : null, ':no' => $visitNo, ':j' => $jobId, ':vn' => $maxNo, ':au' => isset($ids[0]) ? $ids[0] : null, ':ss' => $occ['start'], ':se' => $occ['end'], ':notes' => $occ['instructions'] !== '' ? $occ['instructions'] : null, ':u' => $createdBy));
        $visitId = (int)$pdo->lastInsertId();
        foreach ($ids as $pos => $uid) $visitAssign->execute(array(':t' => $tenant, ':v' => $visitId, ':u' => $uid, ':p' => $pos === 0 ? 1 : 0));
        $created++;
    }
    return $created;
}

$tenant = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$user = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : 0;
$sessionBranch = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
if ($tenant <= 0 || $user <= 0) jbRes(401, false, 'Authentication required.');

$csrf = (string)jbP('csrf_token', '');
$sessionCsrf = isset($_SESSION['jobs_csrf_token']) ? (string)$_SESSION['jobs_csrf_token'] : '';
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) jbRes(419, false, 'Your form session expired. Refresh and try again.');
$action = trim((string)jbP('action', ''));

try {
    if ($action === 'meta') {
        jbRes(200, true, 'Job form data loaded.', array('meta' => jbMeta($pdo, $tenant, (int)jbP('job_id', 0))));
    }

    if ($action === 'quote_details') {
        $id = (int)jbP('quote_id', 0);
        jbRes(200, true, 'Approved quotation loaded.', array('quotation' => jbQuote($pdo, $tenant, $id, (int)jbP('job_id', 0)), 'currency' => jbCurrency($pdo, $tenant)));
    }

    if ($action === 'get') {
        $id = (int)jbP('job_id', 0);
        $job = jbJobDetails($pdo, $tenant, $id);
        jbRes(200, true, 'Job loaded.', array(
            'job' => $job,
            'assignments' => jbAssignments($pdo, $tenant, $id),
            'schedules' => jbJobSchedules($pdo, $tenant, $id),
            'billing' => jbJobBilling($pdo, $tenant, $id),
            'attachments' => jbJobAttachments($pdo, $tenant, $id),
            'checklist_template_ids' => jbJobChecklistTemplateIds($pdo, $tenant, $id),
            'meta' => jbMeta($pdo, $tenant, $id),
            'currency' => jbCurrency($pdo, $tenant)
        ));
    }

    if ($action === 'resend_email') {
        $id = (int)jbP('job_id', 0);
        if ($id <= 0) jbRes(422, false, 'Invalid job.');

        $job = jbJobDetails($pdo, $tenant, $id);
        if (strtolower((string)$job['status']) !== 'scheduled') {
            jbRes(422, false, 'Resend Email is available only when the job status is Scheduled.');
        }
        $quote = jbContextFromJob($pdo, $tenant, $job);
        $assignments = jbAssignments($pdo, $tenant, $id);
        $recipients = array();
        foreach ($assignments as $assignment) {
            if (empty($assignment['user_id'])) continue;
            $recipients[] = array(
                'id' => (int)$assignment['user_id'],
                'name' => trim((string)$assignment['user_name']),
                'email' => trim((string)$assignment['email'])
            );
        }

        $branch = !empty($job['branch_id']) ? (int)$job['branch_id'] : $sessionBranch;
        $employeeNotif = $recipients
            ? jbNotifyEmployees($pdo, $tenant, $branch, $job, $quote, $recipients, false)
            : array('in_app' => 0, 'employee_email_sent' => 0, 'employee_email_failed' => 0, 'employee_email_skipped' => 0, 'messages' => array('No assigned employees were available for email.'));
        $customerNotif = jbNotifyCustomer($pdo, $tenant, $branch, $job, $quote);

        $notifications = array_merge($employeeNotif, $customerNotif);
        $notifications['email_sent'] = (int)$notifications['employee_email_sent'] + (int)$notifications['customer_email_sent'];
        $notifications['email_failed'] = (int)$notifications['employee_email_failed'] + (int)$notifications['customer_email_failed'];
        $notifications['email_skipped'] = (int)$notifications['employee_email_skipped'] + (int)$notifications['customer_email_skipped'];
        $notifications['messages'] = array_merge(
            isset($employeeNotif['messages']) ? $employeeNotif['messages'] : array(),
            isset($customerNotif['messages']) ? $customerNotif['messages'] : array()
        );

        if ((int)$notifications['email_sent'] > 0) {
            jbActivity($pdo, $tenant, $branch, $user, 'job_email_resent', $id, (int)$job['client_id'], 'Job email resent: ' . $job['job_no'], array('notifications' => $notifications));
            if (function_exists('tenantAuditLog')) {
                try {
                    tenantAuditLog($pdo, 'JOB_EMAIL_RESENT', $tenant, $branch, $user, 'job', $id, null, array('notifications' => $notifications));
                } catch (Throwable $e) {
                    error_log('job resend audit ' . $e->getMessage());
                }
            }
            jbRes(200, true, $notifications['email_sent'] . ' job email notification(s) sent successfully.', array('notifications' => $notifications));
        }

        $message = !empty($notifications['messages'])
            ? implode(' ', array_unique($notifications['messages']))
            : 'No eligible customer or employee email recipient was found.';
        jbRes((int)$notifications['email_failed'] > 0 ? 500 : 422, false, $message, array('notifications' => $notifications));
    }

    if ($action === 'list') {
        $page = max(1, (int)jbP('page', 1));
        $per = (int)jbP('per_page', 10);
        if (!in_array($per, array(10, 25, 50), true)) $per = 10;
        $search = trim((string)jbP('search', ''));
        $status = trim((string)jbP('status', ''));
        $from = trim((string)jbP('from_date', ''));
        $to = trim((string)jbP('to_date', ''));
        $where = array('j.tenant_id=:t', 'j.deleted_at IS NULL');
        $params = array(':t' => $tenant);
        if ($search !== '') {
            $sv = '%' . $search . '%';
            $where[] = '(j.job_no LIKE :s1 OR j.title LIKE :s2 OR q.quote_no LIKE :s3 OR c.display_name LIKE :s4)';
            $params[':s1'] = $sv; $params[':s2'] = $sv; $params[':s3'] = $sv; $params[':s4'] = $sv;
        }
        if ($status !== '') { $where[] = 'j.status=:st'; $params[':st'] = $status; }
        if ($from !== '') { $where[] = 'j.start_date>=:fd'; $params[':fd'] = $from; }
        if ($to !== '') { $where[] = 'j.start_date<=:td'; $params[':td'] = $to; }
        $whereSql = implode(' AND ', $where);
        $count = $pdo->prepare("SELECT COUNT(*) FROM jobs j INNER JOIN clients c ON c.id=j.client_id AND c.tenant_id=j.tenant_id LEFT JOIN quotes q ON q.id=j.quote_id AND q.tenant_id=j.tenant_id WHERE $whereSql");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $per));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $per;
        $sql = "SELECT j.*,q.quote_no,c.display_name client_name,ps.name service_name,(SELECT GROUP_CONCAT(CONCAT(u.first_name,' ',COALESCE(u.last_name,'')) ORDER BY ja.is_primary_responsible DESC,ja.id SEPARATOR ', ') FROM job_assignments ja INNER JOIN users u ON u.id=ja.user_id AND u.tenant_id=ja.tenant_id WHERE ja.job_id=j.id AND ja.tenant_id=j.tenant_id AND ja.status<>'removed') assignees FROM jobs j INNER JOIN clients c ON c.id=j.client_id AND c.tenant_id=j.tenant_id LEFT JOIN quotes q ON q.id=j.quote_id AND q.tenant_id=j.tenant_id LEFT JOIN product_services ps ON ps.id=j.product_service_id AND ps.tenant_id=j.tenant_id WHERE $whereSql ORDER BY j.id DESC LIMIT " . (int)$per . " OFFSET " . (int)$offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $summaryStmt = $pdo->prepare("SELECT COUNT(*) total,SUM(status IN('active','scheduled','upcoming','today')) assigned,SUM(status='in_progress') in_progress,SUM(status='completed') completed FROM jobs WHERE tenant_id=:t AND deleted_at IS NULL");
        $summaryStmt->execute(array(':t' => $tenant));
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        jbRes(200, true, 'Jobs loaded.', array(
            'jobs' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'summary' => array('total' => (int)$summary['total'], 'assigned' => (int)$summary['assigned'], 'in_progress' => (int)$summary['in_progress'], 'completed' => (int)$summary['completed']),
            'currency' => jbCurrency($pdo, $tenant),
            'pagination' => array('page' => $page, 'per_page' => $per, 'pages' => $pages, 'total' => $total, 'from' => $total ? $offset + 1 : 0, 'to' => $total ? min($offset + $per, $total) : 0)
        ));
    }

    if ($action === 'save') {
        if (!jbCol($pdo, 'jobs', 'start_time') || !jbCol($pdo, 'jobs', 'end_time')) {
            jbRes(500, false, 'Job schedule time columns are not installed. Run migration_job_schedule_times.sql once.');
        }
        if (!jbTable($pdo, 'job_schedules') || !jbTable($pdo, 'job_schedule_assignees') || !jbTable($pdo, 'visit_assignments') || !jbTable($pdo, 'job_billing_settings') || !jbTable($pdo, 'visits')) {
            jbRes(500, false, 'Expanded job scheduling tables are not installed. Run migration_job_recurring_schedules_v2.sql once.');
        }

        $id = (int)jbP('job_id', 0);
        $quoteId = (int)jbP('quote_id', 0);
        $jobSource = trim((string)jbP('job_source', $quoteId > 0 ? 'quotation' : 'direct'));
        if (!in_array($jobSource, array('direct','quotation'), true)) jbRes(422, false, 'Invalid job source.');
        if ($jobSource === 'quotation') {
            if ($quoteId <= 0) jbRes(422, false, 'Select an approved quotation.');
            $quote = jbQuote($pdo, $tenant, $quoteId, $id);
        } else {
            $quoteId = 0;
            $quote = jbDirectJobContext($pdo, $tenant, (int)jbP('client_id', 0), (int)jbP('location_id', 0), (int)jbP('direct_product_service_id', 0), (int)jbP('direct_branch_id', 0), (int)jbP('request_id', 0), $sessionBranch);
        }
        $title = trim((string)jbP('title', ''));
        if ($title === '' && $jobSource === 'quotation') $title = trim((string)$quote['title']);
        if ($title === '' && $jobSource === 'quotation') $title = trim((string)$quote['request_title']);
        if ($title === '') $title = 'Service Job';
        $description = trim((string)jbP('description', ''));
        $priority = trim((string)jbP('priority', 'normal'));
        $status = trim((string)jbP('status', 'scheduled'));
        $mode = trim((string)jbP('assignment_mode', 'single_user'));
        $completion = trim((string)jbP('assignment_completion_mode', 'primary_only'));

        if (!in_array($priority, array('low', 'normal', 'high', 'urgent'), true)) jbRes(422, false, 'Invalid priority.');
        if (!in_array($status, array('active', 'scheduled', 'upcoming', 'today', 'in_progress', 'waiting_customer', 'waiting_material', 'rescheduled', 'completed', 'needs_review', 'ready_to_invoice', 'invoiced', 'closed', 'cancelled'), true)) jbRes(422, false, 'Invalid job status.');
        if (!in_array($completion, array('primary_only', 'task_owner', 'all_assignees'), true)) jbRes(422, false, 'Invalid completion rule.');

        if ($mode === 'single_user') {
            $defaultIds = array((int)jbP('single_user_id', 0));
            $dbMode = 'single_user';
        } elseif ($mode === 'multiple_users') {
            $defaultIds = isset($_POST['user_ids']) && is_array($_POST['user_ids']) ? $_POST['user_ids'] : array();
            $dbMode = 'multiple_users';
        } elseif ($mode === 'department') {
            $department = (int)jbP('department_id', 0);
            $check = $pdo->prepare("SELECT id FROM departments WHERE id=:d AND tenant_id=:t AND status='active' LIMIT 1");
            $check->execute(array(':d' => $department, ':t' => $tenant));
            if (!$check->fetchColumn()) jbRes(422, false, 'Select a valid department.');
            $usersStmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id=:t AND department_id=:d AND status='active' AND deleted_at IS NULL AND (is_bookable=1 OR is_field_worker=1 OR is_tenant_admin=1) ORDER BY id");
            $usersStmt->execute(array(':t' => $tenant, ':d' => $department));
            $defaultIds = $usersStmt->fetchAll(PDO::FETCH_COLUMN);
            $dbMode = 'multiple_users';
            if (!$defaultIds) jbRes(422, false, 'The selected department has no active service users.');
        } else {
            jbRes(422, false, 'Invalid assignment mode.');
        }
        $defaultIds = jbIntList($defaultIds);
        $defaultUsers = jbUsersByIds($pdo, $tenant, $defaultIds);
        if (count($defaultUsers) !== count($defaultIds) || !$defaultUsers) jbRes(422, false, 'Select at least one valid default assignee.');
        $defaultIds = array_map(function($x){ return (int)$x['id']; }, $defaultUsers);

        $schedules = jbParseSchedulePayload(jbP('schedule_json', ''));
        $allAssigneeIds = $defaultIds;
        foreach ($schedules as &$schedule) {
            $ids = $schedule['assignee_ids'] ? $schedule['assignee_ids'] : $defaultIds;
            $users = jbUsersByIds($pdo, $tenant, $ids);
            if (count($users) !== count(jbIntList($ids)) || !$users) jbRes(422, false, 'Each schedule must use valid assigned employees.');
            $schedule['assignee_ids'] = array_map(function($x){ return (int)$x['id']; }, $users);
            $allAssigneeIds = array_merge($allAssigneeIds, $schedule['assignee_ids']);
        }
        unset($schedule);
        $allAssigneeIds = jbIntList($allAssigneeIds);
        $assignUsers = jbUsersByIds($pdo, $tenant, $allAssigneeIds);
        if (!$assignUsers) jbRes(422, false, 'Select at least one valid assignee.');

        $occurrences = jbBuildOccurrences($schedules);
        $firstOccurrence = $occurrences[0];
        $lastOccurrence = $occurrences[count($occurrences) - 1];
        $startDate = substr($firstOccurrence['start'], 0, 10);
        $startTime = substr($firstOccurrence['start'], 11, 8);
        $endDate = substr($lastOccurrence['end'], 0, 10);
        $endTime = substr($lastOccurrence['end'], 11, 8);
        $jobType = count($occurrences) > 1 ? 'recurring' : 'one_off';
        foreach ($schedules as $schedule) if ($schedule['repeat_type'] !== 'none') { $jobType = 'recurring'; break; }

        $billingType = trim((string)jbP('billing_type', 'visit_based'));
        if (!in_array($billingType, array('visit_based','fixed_price'), true)) jbRes(422, false, 'Invalid billing type.');
        $automaticPayments = (int)jbP('automatic_payments_enabled', 0) === 1 ? 1 : 0;
        $invoiceCount = count($occurrences);
        $firstInvoiceDate = substr($firstOccurrence['start'], 0, 10);
        $lastInvoiceDate = substr($lastOccurrence['start'], 0, 10);
        $fixedAmount = (float)jbP('fixed_invoice_amount', 0);
        if ($billingType === 'fixed_price' && $fixedAmount <= 0 && $invoiceCount > 0) $fixedAmount = round((float)$quote['total'] / $invoiceCount, 2);
        if ($billingType === 'fixed_price' && $fixedAmount <= 0) jbRes(422, false, 'Enter a fixed amount for each invoice.');
        $invoicingPreference = $billingType === 'visit_based' ? 'after_each_visit' : 'recurring_schedule';

        $old = $id > 0 ? jbJob($pdo, $tenant, $id) : null;
        $oldAssignments = $id > 0 ? jbAssignments($pdo, $tenant, $id) : array();
        $oldIds = array();
        foreach ($oldAssignments as $assignment) if (!empty($assignment['user_id'])) $oldIds[] = (int)$assignment['user_id'];
        $branch = !empty($quote['branch_id']) ? (int)$quote['branch_id'] : $sessionBranch;

        $service = (int)$quote['product_service_id'];
        if ($service <= 0) {
            $service = (int)jbP('product_service_id', 0);
            $selectedService = jbService($pdo, $tenant, $service);
            if (!$selectedService) jbRes(422, false, 'Select a valid service for the job card.');
            $service = (int)$selectedService['id'];
            $quote['product_service_id'] = $service;
            $quote['service_name'] = (string)$selectedService['name'];
            $quote['service_source'] = 'job_form';
        }
        $workflow = jbDefaultWorkflow($pdo, $tenant, $service);
        $quote['workflow_id'] = $workflow;
        $quote['workflow_name'] = $workflow ? jbWorkflowName($pdo, $tenant, $workflow) : '';
        $recurrenceRule = json_encode(array('version' => 2, 'schedules' => $schedules), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE jobs SET branch_id=:b,client_id=:c,location_id=:l,request_id=:r,quote_id=:q,product_service_id=:ps,workflow_id=:w,title=:title,description=:d,job_type=:jt,priority=:p,assignment_mode=:am,assignment_completion_mode=:cm,status=:st,start_date=:sd,start_time=:stm,end_date=:ed,end_time=:etm,recurrence_rule=:rr,invoicing_preference=:ip,subtotal=:sub,tax_total=:tax,total=:tot WHERE id=:id AND tenant_id=:t");
                $stmt->execute(array(':b' => $branch > 0 ? $branch : null, ':c' => $quote['client_id'], ':l' => $quote['location_id'], ':r' => $quote['request_id'], ':q' => $quoteId > 0 ? $quoteId : null, ':ps' => $service > 0 ? $service : null, ':w' => $workflow, ':title' => $title, ':d' => $description !== '' ? $description : null, ':jt' => $jobType, ':p' => $priority, ':am' => $dbMode, ':cm' => $completion, ':st' => $status, ':sd' => $startDate, ':stm' => $startTime, ':ed' => $endDate, ':etm' => $endTime, ':rr' => $recurrenceRule, ':ip' => $invoicingPreference, ':sub' => $quote['subtotal'], ':tax' => $quote['tax_total'], ':tot' => $quote['total'], ':id' => $id, ':t' => $tenant));
                $jobNo = (string)$old['job_no'];
            } else {
                $jobNo = jbNext($pdo, $tenant, $branch);
                $stmt = $pdo->prepare("INSERT INTO jobs(tenant_id,branch_id,job_no,client_id,location_id,request_id,quote_id,product_service_id,workflow_id,title,description,job_type,priority,assignment_mode,assignment_completion_mode,status,start_date,start_time,end_date,end_time,recurrence_rule,invoicing_preference,subtotal,tax_total,total,created_by) VALUES(:t,:b,:no,:c,:l,:r,:q,:ps,:w,:title,:d,:jt,:p,:am,:cm,:st,:sd,:stm,:ed,:etm,:rr,:ip,:sub,:tax,:tot,:u)");
                $stmt->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : null, ':no' => $jobNo, ':c' => $quote['client_id'], ':l' => $quote['location_id'], ':r' => $quote['request_id'], ':q' => $quoteId > 0 ? $quoteId : null, ':ps' => $service > 0 ? $service : null, ':w' => $workflow, ':title' => $title, ':d' => $description !== '' ? $description : null, ':jt' => $jobType, ':p' => $priority, ':am' => $dbMode, ':cm' => $completion, ':st' => $status, ':sd' => $startDate, ':stm' => $startTime, ':ed' => $endDate, ':etm' => $endTime, ':rr' => $recurrenceRule, ':ip' => $invoicingPreference, ':sub' => $quote['subtotal'], ':tax' => $quote['tax_total'], ':tot' => $quote['total'], ':u' => $user));
                $id = (int)$pdo->lastInsertId();
            }

            $pdo->prepare("DELETE FROM job_assignments WHERE tenant_id=:t AND job_id=:j")->execute(array(':t' => $tenant, ':j' => $id));
            $insertAssignment = $pdo->prepare("INSERT INTO job_assignments(tenant_id,job_id,user_id,team_id,assignment_role,is_primary_responsible,assigned_by,status) VALUES(:t,:j,:u,NULL,:role,:primary,:by,'assigned')");
            foreach ($assignUsers as $index => $assignedUser) $insertAssignment->execute(array(':t' => $tenant, ':j' => $id, ':u' => $assignedUser['id'], ':role' => $index === 0 ? 'primary' : 'technician', ':primary' => $index === 0 ? 1 : 0, ':by' => $user));

            $createdVisits = jbPersistSchedules($pdo, $tenant, $branch, $id, $jobNo, $schedules, $occurrences, $defaultIds, $user);

            $billing = $pdo->prepare("INSERT INTO job_billing_settings(tenant_id,job_id,billing_type,automatic_payments_enabled,total_invoices,first_invoice_date,last_invoice_date,fixed_invoice_amount) VALUES(:t,:j,:bt,:ap,:ti,:fd,:ld,:fa) ON DUPLICATE KEY UPDATE billing_type=VALUES(billing_type),automatic_payments_enabled=VALUES(automatic_payments_enabled),total_invoices=VALUES(total_invoices),first_invoice_date=VALUES(first_invoice_date),last_invoice_date=VALUES(last_invoice_date),fixed_invoice_amount=VALUES(fixed_invoice_amount)");
            $billing->execute(array(':t' => $tenant, ':j' => $id, ':bt' => $billingType, ':ap' => $automaticPayments, ':ti' => $invoiceCount, ':fd' => $firstInvoiceDate, ':ld' => $lastInvoiceDate, ':fa' => $billingType === 'fixed_price' ? $fixedAmount : null));

            $checklistIds = jbSaveJobChecklists($pdo, $tenant, $id, (int)$assignUsers[0]['id'], $user);
            if ($quoteId > 0) $pdo->prepare("UPDATE quotes SET status='converted' WHERE id=:q AND tenant_id=:t AND status='approved'")->execute(array(':q' => $quoteId, ':t' => $tenant));
            jbInitWorkflow($pdo, $tenant, $id, $workflow, (int)$assignUsers[0]['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $attachments = jbSaveJobAttachments($pdo, $tenant, $id, $user);
        $job = jbJob($pdo, $tenant, $id);
        $newUsers = array();
        foreach ($assignUsers as $assignedUser) if (!$old || !in_array((int)$assignedUser['id'], $oldIds, true)) $newUsers[] = $assignedUser;

        $employeeNotif = $newUsers ? jbNotifyEmployees($pdo, $tenant, $branch, $job, $quote, $newUsers) : array('in_app' => 0, 'employee_email_sent' => 0, 'employee_email_failed' => 0, 'employee_email_skipped' => 0, 'messages' => array());
        $customerNotif = !$old ? jbNotifyCustomer($pdo, $tenant, $branch, $job, $quote) : array('customer_email_sent' => 0, 'customer_email_failed' => 0, 'customer_email_skipped' => 0, 'messages' => array());
        $notifications = array_merge($employeeNotif, $customerNotif);
        $notifications['email_sent'] = (int)$notifications['employee_email_sent'] + (int)$notifications['customer_email_sent'];
        $notifications['email_failed'] = (int)$notifications['employee_email_failed'] + (int)$notifications['customer_email_failed'];
        $notifications['email_skipped'] = (int)$notifications['employee_email_skipped'] + (int)$notifications['customer_email_skipped'];
        $notifications['messages'] = array_merge(isset($employeeNotif['messages']) ? $employeeNotif['messages'] : array(), isset($customerNotif['messages']) ? $customerNotif['messages'] : array(), isset($attachments['messages']) ? $attachments['messages'] : array());

        jbActivity($pdo, $tenant, $branch, $user, $old ? 'job_reassigned' : 'job_created', $id, (int)$quote['client_id'], ($old ? 'Job updated: ' : 'Job created: ') . $job['job_no'], array('job_source' => $jobSource, 'quote_id' => $quoteId > 0 ? $quoteId : null, 'product_service_id' => $service, 'workflow_id' => $workflow, 'job_type' => $jobType, 'schedules' => count($schedules), 'visits' => count($occurrences), 'billing_type' => $billingType, 'assignees' => $allAssigneeIds, 'checklists' => $checklistIds, 'attachments' => $attachments, 'notifications' => $notifications));

        if (function_exists('tenantAuditLog')) {
            try { tenantAuditLog($pdo, $old ? 'JOB_UPDATED' : 'JOB_CREATED', $tenant, $branch, $user, 'job', $id, $old, $job); }
            catch (Throwable $e) { error_log('job audit ' . $e->getMessage()); }
        }

        jbRes(200, true, ($old ? 'Job updated' : 'Job created') . ' successfully with ' . count($occurrences) . ' visit schedule(s).', array('job_id' => $id, 'job_no' => $job['job_no'], 'visit_count' => count($occurrences), 'new_visits_created' => $createdVisits, 'billing' => array('type' => $billingType, 'total_invoices' => $invoiceCount, 'first' => $firstInvoiceDate, 'last' => $lastInvoiceDate), 'attachments' => $attachments, 'notifications' => $notifications));
    }

    if ($action === 'cancel') {
        $id = (int)jbP('job_id', 0);
        $reason = trim((string)jbP('reason', ''));
        if ($reason === '') jbRes(422, false, 'Cancellation reason is required.');
        $old = jbJob($pdo, $tenant, $id);
        $pdo->prepare("UPDATE jobs SET status='cancelled' WHERE id=:id AND tenant_id=:t")->execute(array(':id' => $id, ':t' => $tenant));
        jbActivity($pdo, $tenant, (int)$old['branch_id'], $user, 'job_cancelled', $id, (int)$old['client_id'], 'Job cancelled: ' . $old['job_no'], array('reason' => $reason));
        jbRes(200, true, 'Job cancelled successfully.');
    }

    jbRes(400, false, 'Unsupported jobs action.');
} catch (PDOException $e) {
    error_log('FieldPlx jobs PDO ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) jbRes(409, false, 'Job number already exists.');
    jbRes(500, false, 'Unable to process the jobs request.');
} catch (Throwable $e) {
    error_log('FieldPlx jobs ' . $e->getMessage());
    jbRes(500, false, $e->getMessage() !== '' ? $e->getMessage() : 'Unable to process the jobs request.');
}
