<?php
/* FieldPlx Job View API v3.0 - Jobber-style view/edit, visits, labor, expenses, billing and actions */
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
    http_response_code((int) $code);
    echo json_encode(array_merge(array(
        'success' => (bool) $ok,
        'message' => (string) $msg
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
    $cache[$key] = (int) $stmt->fetchColumn() > 0;
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
    $cache[$table] = (int) $stmt->fetchColumn() > 0;
    return $cache[$table];
}

function jbStoreCatalogImage($field, $tenant, $kind)
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]))
        return array('relative' => null, 'absolute' => null);

    $file = $_FILES[$field];
    $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE)
        return array('relative' => null, 'absolute' => null);
    if ($error !== UPLOAD_ERR_OK)
        throw new RuntimeException('Unable to upload the product / service image.');

    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($size <= 0 || $size > 4 * 1024 * 1024)
        throw new RuntimeException('Product / service image must be 4 MB or smaller.');

    $tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp))
        throw new RuntimeException('Uploaded product / service image is invalid.');

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string) @finfo_file($fi, $tmp);
            @finfo_close($fi);
        }
    }

    $allowed = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
    if (!isset($allowed[$mime]))
        throw new RuntimeException('Product / service image must be JPG, PNG or WEBP.');

    $folder = $kind === 'service' ? 'services' : 'products';
    $relativeDir = 'uploads/product-masters/tenant-' . (int) $tenant . '/' . $folder;
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir))
        throw new RuntimeException('Unable to create the product / service image upload folder.');

    $prefix = $kind === 'service' ? 'service' : 'product';
    $fileName = $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    $absolutePath = $absoluteDir . '/' . $fileName;
    if (!@move_uploaded_file($tmp, $absolutePath))
        throw new RuntimeException('Unable to save the product / service image.');

    return array('relative' => $relativeDir . '/' . $fileName, 'absolute' => $absolutePath);
}

function jbDate($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : false;
}

function jbTime($value)
{
    $value = trim((string) $value);
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
    $row['can_resend_email'] = strtolower((string) $row['status']) === 'scheduled' ? 1 : 0;
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
    return $value ? (int) $value : null;
}

function jbService(PDO $pdo, $tenant, $serviceId)
{
    $serviceId = (int) $serviceId;
    if ($serviceId <= 0)
        return null;

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
    $workflowId = (int) $workflowId;
    if ($workflowId <= 0)
        return '';

    $stmt = $pdo->prepare("SELECT name FROM workflows WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");
    $stmt->execute(array(':id' => $workflowId, ':t' => $tenant));
    $name = $stmt->fetchColumn();

    return $name !== false ? (string) $name : '';
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
        return 'JOB-' . str_pad((string) ((int) $q->fetchColumn() + 1), 6, '0', STR_PAD_LEFT);
    }

    $now = new DateTime('now');
    $year = $now->format('Y');
    $month = $now->format('m');
    $fyStart = max(1, min(12, (int) $row['financial_year_start_month']));
    $fyYear = (int) $now->format('n') >= $fyStart ? (int) $year : (int) $year - 1;
    $fy = $fyYear . '-' . substr((string) ($fyYear + 1), -2);
    $key = 'never';
    if ($row['reset_period'] === 'monthly')
        $key = $year . $month;
    elseif ($row['reset_period'] === 'yearly')
        $key = $year;
    elseif ($row['reset_period'] === 'financial_year')
        $key = $fy;

    $current = (int) $row['current_number'];
    if ($row['reset_period'] !== 'never' && (string) $row['last_reset_key'] !== (string) $key) {
        $current = 0;
    }
    $next = $current + 1;
    $middle = '';
    if ($row['middle_format'] === 'year')
        $middle = $year;
    elseif ($row['middle_format'] === 'year_month')
        $middle = $year . $month;
    elseif ($row['middle_format'] === 'financial_year')
        $middle = $fy;
    elseif ($row['middle_format'] === 'branch_year')
        $middle = (!empty($row['branch_code']) ? $row['branch_code'] : 'BR') . $year;

    $parts = array();
    if (!empty($row['prefix']))
        $parts[] = $row['prefix'];
    if ($middle !== '')
        $parts[] = $middle;
    $parts[] = str_pad((string) $next, max(1, (int) $row['number_length']), '0', STR_PAD_LEFT);
    if (!empty($row['suffix']))
        $parts[] = $row['suffix'];
    $number = implode(isset($row[$sep]) ? (string) $row[$sep] : '-', $parts);

    $upd = $pdo->prepare("UPDATE document_sequences SET current_number=:n,last_reset_key=:k WHERE id=:id");
    $upd->execute(array(':n' => $next, ':k' => $key, ':id' => $row['id']));
    return $number;
}

function jbJobNoExists(PDO $pdo, $tenant, $jobNo, $excludeId = 0)
{
    $jobNo = trim((string)$jobNo);
    if ($jobNo === '') return false;
    $sql = "SELECT id FROM jobs WHERE tenant_id=:t AND job_no=:no";
    $params = array(':t'=>(int)$tenant, ':no'=>$jobNo);
    if ((int)$excludeId > 0) {
        $sql .= " AND id<>:id";
        $params[':id'] = (int)$excludeId;
    }
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() ? true : false;
}

function jbMeta(PDO $pdo, $tenant, $jobId = 0)
{
    $meta = array();
    $stmt = $pdo->prepare("SELECT q.id,q.quote_no,q.title,q.client_id,q.location_id,q.request_id,q.branch_id,q.subtotal,q.tax_total,q.total,c.display_name client_name,c.email client_email,c.phone client_phone,c.allow_email,r.product_service_id,ps.name service_name FROM quotes q INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id LEFT JOIN service_requests r ON r.id=q.request_id AND r.tenant_id=q.tenant_id LEFT JOIN product_services ps ON ps.id=r.product_service_id AND ps.tenant_id=q.tenant_id WHERE q.tenant_id=:t AND (q.status='approved' OR EXISTS(SELECT 1 FROM jobs j WHERE j.id=:jid AND j.tenant_id=q.tenant_id AND j.quote_id=q.id)) AND (NOT EXISTS(SELECT 1 FROM jobs j2 WHERE j2.tenant_id=q.tenant_id AND j2.quote_id=q.id AND j2.deleted_at IS NULL) OR EXISTS(SELECT 1 FROM jobs j3 WHERE j3.id=:jid2 AND j3.tenant_id=q.tenant_id AND j3.quote_id=q.id)) ORDER BY q.id DESC");
    $stmt->execute(array(':t' => $tenant, ':jid' => $jobId, ':jid2' => $jobId));
    $meta['quotes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT c.id,c.display_name AS name,c.display_name,c.company_name,c.email,c.phone,c.branch_id,b.name AS branch_name FROM clients c LEFT JOIN branches b ON b.id=c.branch_id AND b.tenant_id=c.tenant_id WHERE c.tenant_id=:t AND c.deleted_at IS NULL AND c.client_type<>'archived' AND c.status<>'archived' ORDER BY c.display_name");
    $stmt->execute(array(':t' => $tenant));
    $meta['clients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $locationSelect = "id,client_id,location_type,name,address_line1,address_line2,city,state,postal_code,is_primary,status";
    if (jbCol($pdo, 'client_locations', 'country_id')) $locationSelect .= ",country_id"; else $locationSelect .= ",NULL AS country_id";
    if (jbCol($pdo, 'client_locations', 'tax_rate_id')) $locationSelect .= ",tax_rate_id"; else $locationSelect .= ",NULL AS tax_rate_id";
    $stmt = $pdo->prepare("SELECT " . $locationSelect . " FROM client_locations WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL ORDER BY is_primary DESC,name,id");
    $stmt->execute(array(':t' => $tenant));
    $meta['locations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id,CONCAT(first_name,CASE WHEN last_name IS NOT NULL AND last_name<>'' THEN CONCAT(' ',last_name) ELSE '' END) name,email,department_id,job_title,is_field_worker FROM users WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL AND (is_bookable=1 OR is_field_worker=1 OR is_tenant_admin=1) ORDER BY first_name,last_name");
    $stmt->execute(array(':t' => $tenant));
    $meta['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* All active tenant users are available for @mentions in Notes. */
    $teamStmt = $pdo->prepare("SELECT id,CONCAT(first_name,CASE WHEN last_name IS NOT NULL AND last_name<>'' THEN CONCAT(' ',last_name) ELSE '' END) name,email,department_id,job_title FROM users WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL ORDER BY first_name,last_name");
    $teamStmt->execute(array(':t' => $tenant));
    $meta['team_members'] = $teamStmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id,name,branch_id FROM departments WHERE tenant_id=:t AND status='active' ORDER BY name");
    $stmt->execute(array(':t' => $tenant));
    $meta['departments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id,name FROM branches WHERE tenant_id=:t AND status='active' ORDER BY is_head_office DESC,name");
    $stmt->execute(array(':t' => $tenant));
    $meta['branches'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $serviceCols = array('id', 'name');
    foreach (array('item_type', 'sku', 'description', 'unit_name', 'unit_cost', 'unit_price', 'tax_percent', 'image_path') as $c) {
        if (jbCol($pdo, 'product_services', $c))
            $serviceCols[] = $c;
    }
    $serviceSql = "SELECT " . implode(',', $serviceCols) . " FROM product_services WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL";
    if (jbCol($pdo, 'product_services', 'item_type')) {
        $serviceSql .= " AND item_type='service'";
    }
    $serviceSql .= " ORDER BY name";

    $stmt = $pdo->prepare($serviceSql);
    $stmt->execute(array(':t' => $tenant));
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($services as &$serviceRow) {
        $workflowId = jbDefaultWorkflow($pdo, $tenant, (int) $serviceRow['id']);
        $serviceRow['workflow_id'] = $workflowId;
        $serviceRow['workflow_name'] = $workflowId ? jbWorkflowName($pdo, $tenant, $workflowId) : '';
        if (!isset($serviceRow['sku']))
            $serviceRow['sku'] = '';
        if (!isset($serviceRow['description']))
            $serviceRow['description'] = '';
        if (!isset($serviceRow['unit_name']))
            $serviceRow['unit_name'] = '';
        if (!isset($serviceRow['unit_cost']))
            $serviceRow['unit_cost'] = 0;
        if (!isset($serviceRow['unit_price']))
            $serviceRow['unit_price'] = 0;
        if (!isset($serviceRow['tax_percent']))
            $serviceRow['tax_percent'] = 0;
        if (!isset($serviceRow['image_path']))
            $serviceRow['image_path'] = '';
    }
    unset($serviceRow);

    $meta['services'] = $services;

    $meta['catalog_items'] = array();
    $meta['products'] = array();

    if (jbTable($pdo, 'product_services')) {
        $cols = array('id', 'name');
        foreach (array('item_type', 'sku', 'description', 'unit_name', 'unit_cost', 'unit_price', 'tax_percent', 'image_path') as $c) {
            if (jbCol($pdo, 'product_services', $c))
                $cols[] = $c;
        }
        $stmt = $pdo->prepare("SELECT " . implode(',', $cols) . " FROM product_services WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL ORDER BY name");
        $stmt->execute(array(':t' => $tenant));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $row['catalog_key'] = 'ps:' . (int) $row['id'];
            $row['source_type'] = 'product_service';
            $row['source_label'] = !empty($row['item_type']) ? ucfirst((string) $row['item_type']) : 'Service';
            $row['product_service_id'] = (int) $row['id'];
            $row['product_id'] = null;
            if (!isset($row['sku']))
                $row['sku'] = '';
            if (!isset($row['description']))
                $row['description'] = '';
            if (!isset($row['unit_name']))
                $row['unit_name'] = '';
            if (!isset($row['unit_cost']))
                $row['unit_cost'] = 0;
            if (!isset($row['unit_price']))
                $row['unit_price'] = 0;
            if (!isset($row['tax_percent']))
                $row['tax_percent'] = 0;
            if (!isset($row['image_path']))
                $row['image_path'] = '';
            $meta['catalog_items'][] = $row;
        }
    }

    if (jbTable($pdo, 'products')) {
        $productCols = array('id', 'name');
        foreach (array('sku', 'description', 'unit_name', 'base_unit_price', 'selling_price', 'tax_percent', 'image_path') as $c) {
            if (jbCol($pdo, 'products', $c))
                $productCols[] = $c;
        }
        $productSql = "SELECT " . implode(',', $productCols) . " FROM products WHERE tenant_id=:t AND status='active'";
        if (jbCol($pdo, 'products', 'deleted_at'))
            $productSql .= " AND deleted_at IS NULL";
        $productSql .= " ORDER BY name";
        $stmt = $pdo->prepare($productSql);
        $stmt->execute(array(':t' => $tenant));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $meta['products'][] = array(
                'id' => (int) $row['id'],
                'name' => isset($row['name']) ? (string) $row['name'] : '',
                'sku' => isset($row['sku']) ? (string) $row['sku'] : '',
                'description' => isset($row['description']) ? (string) $row['description'] : '',
                'unit_name' => isset($row['unit_name']) ? (string) $row['unit_name'] : 'unit',
                'base_unit_price' => isset($row['base_unit_price']) ? (float) $row['base_unit_price'] : 0,
                'selling_price' => isset($row['selling_price']) ? (float) $row['selling_price'] : 0,
                'tax_percent' => isset($row['tax_percent']) ? (float) $row['tax_percent'] : 0,
                'image_path' => isset($row['image_path']) ? (string) $row['image_path'] : ''
            );
            $meta['catalog_items'][] = array(
                'catalog_key' => 'product:' . (int) $row['id'],
                'source_type' => 'product',
                'source_label' => 'Product',
                'product_service_id' => null,
                'product_id' => (int) $row['id'],
                'id' => (int) $row['id'],
                'name' => isset($row['name']) ? (string) $row['name'] : '',
                'sku' => isset($row['sku']) ? (string) $row['sku'] : '',
                'description' => isset($row['description']) ? (string) $row['description'] : '',
                'unit_name' => isset($row['unit_name']) ? (string) $row['unit_name'] : '',
                'unit_cost' => isset($row['base_unit_price']) ? (float) $row['base_unit_price'] : 0,
                'unit_price' => isset($row['selling_price']) ? (float) $row['selling_price'] : 0,
                'tax_percent' => isset($row['tax_percent']) ? (float) $row['tax_percent'] : 0,
                'image_path' => isset($row['image_path']) ? (string) $row['image_path'] : ''
            );
        }
    }

    /* Final job number comes from document_sequences inside jbNext() during save. */
    $meta['next_job_no_preview'] = 'Auto';

    $meta['checklist_templates'] = array();
    if (jbTable($pdo, 'checklist_templates')) {
        $stmt = $pdo->prepare("SELECT ct.id,ct.name,ct.description,(SELECT COUNT(*) FROM checklist_template_items cti WHERE cti.checklist_template_id=ct.id) item_count FROM checklist_templates ct WHERE ct.tenant_id=:t AND ct.status='active' ORDER BY ct.name");
        $stmt->execute(array(':t' => $tenant));
        $meta['checklist_templates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* Jobber-style property and quote tax metadata. */
    $meta['countries'] = array();
    if (jbTable($pdo, 'countries')) {
        $stmt = $pdo->query("SELECT id,name,iso2 FROM countries WHERE is_active=1 ORDER BY name");
        $meta['countries'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $meta['tax_rates'] = array();
    $meta['default_tax_rate_id'] = 0;
    if (jbTable($pdo, 'product_tax_rates')) {
        $hasDefaultTax = jbCol($pdo, 'product_tax_rates', 'is_default');
        $sql = "SELECT id,tax_name,rate_percent,jurisdiction_name,status," . ($hasDefaultTax ? "is_default" : "0 AS is_default") . " FROM product_tax_rates WHERE tenant_id=:t AND status='active' ORDER BY " . ($hasDefaultTax ? "is_default DESC," : "") . " tax_name,id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(':t' => $tenant));
        $meta['tax_rates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($meta['tax_rates'] as $taxRate) {
            if (!empty($taxRate['is_default'])) {
                $meta['default_tax_rate_id'] = (int)$taxRate['id'];
                break;
            }
        }
    }

    $meta['location_custom_fields'] = array();
    if (jbTable($pdo, 'client_custom_field_definitions')) {
        $stmt = $pdo->prepare("SELECT id,applies_to,field_name,field_type,is_transferable,default_value,options_json,sort_order FROM client_custom_field_definitions WHERE tenant_id=:t AND applies_to='location' AND status='active' ORDER BY sort_order,field_name,id");
        $stmt->execute(array(':t' => $tenant));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$fieldRow) {
            $decodedOptions = !empty($fieldRow['options_json']) ? json_decode((string)$fieldRow['options_json'], true) : array();
            $fieldRow['options'] = is_array($decodedOptions) ? array_values($decodedOptions) : array();
        }
        unset($fieldRow);
        $meta['location_custom_fields'] = $rows;
    }

    $meta['currency'] = jbCurrency($pdo, $tenant);
    $meta['schedule_time_columns'] = jbCol($pdo, 'jobs', 'start_time') && jbCol($pdo, 'jobs', 'end_time') ? 1 : 0;
    $meta['expanded_schedule_ready'] = (jbTable($pdo, 'job_schedules') && jbTable($pdo, 'job_schedule_assignees') && jbTable($pdo, 'visit_assignments') && jbTable($pdo, 'job_billing_settings') && jbTable($pdo, 'visits')) ? 1 : 0;
    $meta['attachments_ready'] = jbTable($pdo, 'attachments') ? 1 : 0;
    $meta['checklists_ready'] = (jbTable($pdo, 'checklist_templates') && jbTable($pdo, 'checklist_template_items') && jbTable($pdo, 'job_checklists') && jbTable($pdo, 'job_checklist_items')) ? 1 : 0;
    $meta['checklist_rich_fields_ready'] = ($meta['checklists_ready'] && jbCol($pdo, 'checklist_template_items', 'question_type') && jbCol($pdo, 'checklist_template_items', 'options_json') && jbCol($pdo, 'checklist_template_items', 'section_title') && jbCol($pdo, 'job_checklist_items', 'question_type') && jbCol($pdo, 'job_checklist_items', 'options_json') && jbCol($pdo, 'job_checklist_items', 'section_title')) ? 1 : 0;
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
            $quotationServiceId = (int) $quotationService['product_service_id'];
            $quotationServiceName = (string) $quotationService['name'];
        }
    }

    if ($quotationServiceId > 0) {
        $row['product_service_id'] = $quotationServiceId;
        $row['service_name'] = $quotationServiceName;
        $row['service_source'] = 'quotation';
    } elseif (!empty($row['request_product_service_id'])) {
        $requestService = jbService($pdo, $tenant, (int) $row['request_product_service_id']);

        if ($requestService) {
            $row['product_service_id'] = (int) $requestService['id'];
            $row['service_name'] = (string) $requestService['name'];
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
    $row['workflow_id'] = jbDefaultWorkflow($pdo, $tenant, (int) $row['product_service_id']);
    $row['workflow_name'] = !empty($row['workflow_id'])
        ? jbWorkflowName($pdo, $tenant, (int) $row['workflow_id'])
        : '';
    $row['source_mode'] = 'quotation';

    return $row;
}


function jbQuoteLineItems(PDO $pdo, $tenant, $quoteId)
{
    if (!jbTable($pdo, 'quote_line_items'))
        return array();

    $stmt = $pdo->prepare("SELECT * FROM quote_line_items WHERE tenant_id=:t AND quote_id=:q ORDER BY sort_order,id");
    $stmt->execute(array(':t' => $tenant, ':q' => $quoteId));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['item_source'] = !empty($row['product_id'])
            ? 'product'
            : (!empty($row['product_service_id']) ? 'product_service' : 'manual');
        if (empty($row['item_type']))
            $row['item_type'] = !empty($row['product_id']) ? 'product' : (!empty($row['product_service_id']) ? 'service' : 'manual');
    }
    unset($row);

    return $rows;
}

function jbRequestLineItems(PDO $pdo, $tenant, $requestId)
{
    if (!jbTable($pdo, 'service_request_line_items'))
        return array();

    $stmt = $pdo->prepare("SELECT * FROM service_request_line_items WHERE tenant_id=:t AND request_id=:r ORDER BY sort_order,id");
    $stmt->execute(array(':t' => $tenant, ':r' => $requestId));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['item_source'] = !empty($row['product_id'])
            ? 'product'
            : (!empty($row['product_service_id']) ? 'product_service' : 'manual');
        if (empty($row['item_type']))
            $row['item_type'] = !empty($row['product_id']) ? 'product' : (!empty($row['product_service_id']) ? 'service' : 'manual');
    }
    unset($row);

    return $rows;
}

function jbRequestContext(PDO $pdo, $tenant, $requestId, $jobId = 0)
{
    $requestId = (int) $requestId;
    if ($requestId <= 0)
        jbRes(422, false, 'Select a valid service request.');

    $deletedSql = jbCol($pdo, 'service_requests', 'deleted_at') ? " AND r.deleted_at IS NULL" : "";
    $stmt = $pdo->prepare("SELECT
            r.*,
            c.display_name AS client_name,
            c.email AS client_email,
            c.phone AS client_phone,
            c.allow_email,
            b.name AS branch_name,
            ps.name AS service_name
        FROM service_requests r
        INNER JOIN clients c
            ON c.id=r.client_id
           AND c.tenant_id=r.tenant_id
           AND c.deleted_at IS NULL
        LEFT JOIN branches b
            ON b.id=r.branch_id
           AND b.tenant_id=r.tenant_id
        LEFT JOIN product_services ps
            ON ps.id=r.product_service_id
           AND ps.tenant_id=r.tenant_id
        WHERE r.id=:id
          AND r.tenant_id=:t" . $deletedSql . "
        LIMIT 1");
    $stmt->execute(array(':id' => $requestId, ':t' => $tenant));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row)
        jbRes(404, false, 'Service request not found.');

    if ($jobId <= 0) {
        $dup = $pdo->prepare("SELECT id,job_no FROM jobs WHERE tenant_id=:t AND request_id=:r AND deleted_at IS NULL AND status NOT IN('cancelled','archived') ORDER BY id DESC LIMIT 1");
        $dup->execute(array(':t' => $tenant, ':r' => $requestId));
        $existing = $dup->fetch(PDO::FETCH_ASSOC);
        if ($existing)
            jbRes(409, false, 'This request is already linked to job ' . $existing['job_no'] . '.');
    }

    $serviceId = !empty($row['product_service_id']) ? (int) $row['product_service_id'] : 0;
    if ($serviceId <= 0) {
        foreach (jbRequestLineItems($pdo, $tenant, $requestId) as $line) {
            if (!empty($line['product_service_id']) && strtolower((string) $line['item_type']) === 'service') {
                $serviceId = (int) $line['product_service_id'];
                $service = jbService($pdo, $tenant, $serviceId);
                if ($service) {
                    $row['service_name'] = $service['name'];
                    break;
                }
            }
        }
    }

    $row['source_mode'] = 'request';
    $row['request_id'] = $requestId;
    $row['request_no'] = isset($row['request_no']) ? (string) $row['request_no'] : ('#' . $requestId);
    $row['request_title'] = isset($row['title']) ? (string) $row['title'] : '';
    $row['request_description'] = isset($row['description']) ? (string) $row['description'] : '';
    $row['product_service_id'] = $serviceId > 0 ? $serviceId : null;
    $row['quote_no'] = '';
    $row['quote_id'] = null;
    $row['subtotal'] = 0;
    $row['tax_total'] = 0;
    $row['total'] = 0;

    return $row;
}

function jbRequestStatusAllows(PDO $pdo, $status)
{
    try {
        $stmt = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_requests' AND COLUMN_NAME='status' LIMIT 1");
        $stmt->execute();
        $type = (string) $stmt->fetchColumn();
        if (stripos($type, 'enum(') !== 0)
            return true;
        return strpos($type, "'" . str_replace("'", "''", (string) $status) . "'") !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function jbRecordRequestConversion(PDO $pdo, $tenant, $branch, $user, $request, $jobId, $jobNo)
{
    if (empty($request['request_id']))
        return;

    $requestId = (int) $request['request_id'];
    $oldStatus = isset($request['status']) ? (string) $request['status'] : '';
    $newStatus = $oldStatus;

    if (jbRequestStatusAllows($pdo, 'converted')) {
        $upd = $pdo->prepare("UPDATE service_requests SET status='converted' WHERE id=:r AND tenant_id=:t");
        $upd->execute(array(':r' => $requestId, ':t' => $tenant));
        $newStatus = 'converted';
    }

    if (jbTable($pdo, 'request_status_history') && $newStatus !== $oldStatus) {
        $hist = $pdo->prepare("INSERT INTO request_status_history(tenant_id,request_id,old_status,new_status,notes,changed_by) VALUES(:t,:r,:old,:new,:notes,:u)");
        $hist->execute(array(
            ':t' => $tenant,
            ':r' => $requestId,
            ':old' => $oldStatus !== '' ? $oldStatus : null,
            ':new' => $newStatus,
            ':notes' => 'Request converted to Job ' . $jobNo,
            ':u' => $user > 0 ? $user : null
        ));
    }

    if (jbTable($pdo, 'activity_events')) {
        try {
            $activity = $pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client) VALUES(:t,:b,:u,'user','request_converted_to_job','service_request',:r,:c,:title,:details,0)");
            $activity->execute(array(
                ':t' => $tenant,
                ':b' => $branch > 0 ? $branch : null,
                ':u' => $user > 0 ? $user : null,
                ':r' => $requestId,
                ':c' => !empty($request['client_id']) ? (int) $request['client_id'] : null,
                ':title' => 'Request ' . (isset($request['request_no']) ? $request['request_no'] : ('#' . $requestId)) . ' converted to Job ' . $jobNo,
                ':details' => json_encode(array(
                    'request_id' => $requestId,
                    'request_no' => isset($request['request_no']) ? $request['request_no'] : null,
                    'job_id' => (int) $jobId,
                    'job_no' => (string) $jobNo,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus
                ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ));
        } catch (Throwable $e) {
            error_log('request conversion activity ' . $e->getMessage());
        }
    }
}

function jbDirectJobContext(PDO $pdo, $tenant, $clientId, $locationId, $serviceId, $branchId, $requestId, $sessionBranch)
{
    $clientId = (int) $clientId;
    if ($clientId <= 0)
        jbRes(422, false, 'Select a customer for the direct job.');
    $stmt = $pdo->prepare("SELECT c.id,c.display_name client_name,c.email client_email,c.phone client_phone,c.allow_email,c.branch_id,b.name branch_name FROM clients c LEFT JOIN branches b ON b.id=c.branch_id AND b.tenant_id=c.tenant_id WHERE c.id=:id AND c.tenant_id=:t AND c.deleted_at IS NULL AND c.client_type<>'archived' AND c.status<>'archived' LIMIT 1");
    $stmt->execute(array(':id' => $clientId, ':t' => $tenant));
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client)
        jbRes(422, false, 'Select a valid customer for the direct job.');

    $locationId = (int) $locationId;
    if ($locationId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM client_locations WHERE id=:id AND tenant_id=:t AND client_id=:c AND status='active' LIMIT 1");
        $stmt->execute(array(':id' => $locationId, ':t' => $tenant, ':c' => $clientId));
        if (!$stmt->fetchColumn())
            jbRes(422, false, 'Select a valid service location for this customer.');
    }

    $service = jbService($pdo, $tenant, (int) $serviceId);
    if (!$service)
        jbRes(422, false, 'Select a valid service for the direct job.');

    $branchId = (int) $branchId;
    if ($branchId <= 0)
        $branchId = !empty($client['branch_id']) ? (int) $client['branch_id'] : (int) $sessionBranch;
    if ($branchId > 0) {
        $stmt = $pdo->prepare("SELECT id,name FROM branches WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");
        $stmt->execute(array(':id' => $branchId, ':t' => $tenant));
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$branch)
            jbRes(422, false, 'Select a valid branch for the direct job.');
        $client['branch_name'] = $branch['name'];
    }

    $requestId = (int) $requestId;
    if ($requestId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM service_requests WHERE id=:id AND tenant_id=:t AND client_id=:c AND deleted_at IS NULL LIMIT 1");
        $stmt->execute(array(':id' => $requestId, ':t' => $tenant, ':c' => $clientId));
        if (!$stmt->fetchColumn())
            $requestId = 0;
    }

    return array(
        'source_mode' => 'direct',
        'id' => 0,
        'quote_no' => '',
        'title' => '',
        'request_title' => '',
        'client_id' => $clientId,
        'location_id' => $locationId > 0 ? $locationId : null,
        'request_id' => $requestId > 0 ? $requestId : null,
        'branch_id' => $branchId > 0 ? $branchId : null,
        'subtotal' => 0,
        'tax_total' => 0,
        'total' => 0,
        'client_name' => (string) $client['client_name'],
        'client_email' => (string) $client['client_email'],
        'client_phone' => (string) $client['client_phone'],
        'allow_email' => (int) $client['allow_email'],
        'product_service_id' => (int) $service['id'],
        'service_name' => (string) $service['name'],
        'service_source' => 'direct'
    );
}

function jbContextFromJob(PDO $pdo, $tenant, $job)
{
    if (!empty($job['quote_id']))
        return jbQuote($pdo, $tenant, (int) $job['quote_id'], (int) $job['id']);

    if (!empty($job['request_id']))
        return jbRequestContext($pdo, $tenant, (int) $job['request_id'], (int) $job['id']);

    return array(
        'source_mode' => 'direct',
        'client_id' => (int) $job['client_id'],
        'location_id' => !empty($job['location_id']) ? (int) $job['location_id'] : null,
        'request_id' => null,
        'branch_id' => !empty($job['branch_id']) ? (int) $job['branch_id'] : null,
        'subtotal' => isset($job['subtotal']) ? (float) $job['subtotal'] : 0,
        'tax_total' => isset($job['tax_total']) ? (float) $job['tax_total'] : 0,
        'total' => isset($job['total']) ? (float) $job['total'] : 0,
        'client_name' => isset($job['client_name']) ? (string) $job['client_name'] : '',
        'client_email' => isset($job['client_email']) ? (string) $job['client_email'] : '',
        'client_phone' => isset($job['client_phone']) ? (string) $job['client_phone'] : '',
        'allow_email' => isset($job['client_allow_email']) ? (int) $job['client_allow_email'] : 1,
        'product_service_id' => !empty($job['product_service_id']) ? (int) $job['product_service_id'] : null,
        'service_name' => isset($job['service_name']) ? (string) $job['service_name'] : '',
        'quote_no' => '',
        'title' => isset($job['title']) ? (string) $job['title'] : '',
        'request_title' => ''
    );
}

function jbInitWorkflow(PDO $pdo, $tenant, $job, $workflow, $primaryUser)
{
    if ($workflow <= 0 || !jbTable($pdo, 'job_workflow_progress'))
        return;
    $q = $pdo->prepare("SELECT id FROM workflow_steps WHERE workflow_id=:w ORDER BY sort_order,id");
    $q->execute(array(':w' => $workflow));
    $steps = $q->fetchAll(PDO::FETCH_COLUMN);
    if (!$steps)
        return;

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
        ? trim((string) FIELDPLX_SMTP_ENCRYPTION_KEY)
        : '';

    if ($key === '') {
        $env = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if ($env !== false)
            $key = trim((string) $env);
    }

    if ($key === '') {
        $env = getenv('APP_KEY');
        if ($env !== false)
            $key = trim((string) $env);
    }

    if ($key === '' || strlen($key) < 32) {
        throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY is not configured.');
    }

    return hash('sha256', $key, true);
}

function jbDecrypt($encrypted, $tenant)
{
    $encrypted = trim((string) $encrypted);
    if ($encrypted === '')
        return '';

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
        if ($line === false)
            break;
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ')
            break;
    }
    return trim($response);
}

function jbSmtpCmd($socket, $cmd, $ok, $label)
{
    if ($cmd !== null && @fwrite($socket, $cmd . "\r\n") === false)
        throw new RuntimeException('SMTP connection closed during ' . $label . '.');
    $response = jbSmtpRead($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, (array) $ok, true))
        throw new RuntimeException($label . ' failed (SMTP ' . $code . '): ' . substr(preg_replace('/[\r\n]+/', ' ', $response), 0, 220));
    return $response;
}

function jbSmtpConfig(PDO $pdo, $tenant, $branch)
{
    if (!jbTable($pdo, 'smtp_configurations'))
        return null;
    $stmt = $pdo->prepare("SELECT * FROM smtp_configurations WHERE tenant_id=:t AND is_active=1 AND scope_type IN('tenant','branch') AND (scope_type='tenant' OR (scope_type='branch' AND branch_id=:b)) ORDER BY CASE WHEN scope_type='branch' AND branch_id=:b2 THEN 0 ELSE 1 END,is_default DESC,id DESC LIMIT 1");
    $stmt->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : -1, ':b2' => $branch > 0 ? $branch : -1));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function jbMail($cfg, $password, $to, $subject, $html)
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL))
        throw new RuntimeException('Recipient email is invalid.');
    $host = trim($cfg['host']);
    $port = (int) $cfg['port'];
    $enc = strtolower(trim($cfg['encryption']));
    $user = trim((string) $cfg['username']);
    $from = trim((string) $cfg['from_email']);
    if (!filter_var($from, FILTER_VALIDATE_EMAIL))
        throw new RuntimeException('SMTP From Email is invalid.');
    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(array('ssl' => array('verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $host)));
    $errno = 0;
    $err = '';
    $socket = @stream_socket_client($remote, $errno, $err, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket)
        throw new RuntimeException('Unable to connect to SMTP server: ' . ($err !== '' ? $err : 'connection failed'));
    stream_set_timeout($socket, 20);
    try {
        jbSmtpCmd($socket, null, array(220), 'SMTP greeting');
        $ehlo = 'fieldplx.local';
        jbSmtpCmd($socket, 'EHLO ' . $ehlo, array(250), 'EHLO');
        if ($enc === 'tls' || $enc === 'starttls') {
            jbSmtpCmd($socket, 'STARTTLS', array(220), 'STARTTLS');
            $method = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT') ? STREAM_CRYPTO_METHOD_TLS_CLIENT : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
            if (@stream_socket_enable_crypto($socket, true, $method) !== true)
                throw new RuntimeException('Unable to establish TLS encryption.');
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
        $fromName = trim((string) $cfg['from_name']);
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
    if (!empty($job['start_time']))
        $start .= ' ' . date('h:i A', strtotime($job['start_time']));
    if (!empty($job['end_time']))
        $end .= ' ' . date('h:i A', strtotime($job['end_time']));
    return $start . ' to ' . $end;
}

function jbNotifyEmployees(PDO $pdo, $tenant, $branch, $job, $quote, $users, $includeInApp = true, $sendEmail = true)
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
        $uid = (int) $assigned['id'];
        $name = trim((string) $assigned['name']);
        $email = trim((string) $assigned['email']);
        if ($includeInApp) {
            try {
                $stmt = $pdo->prepare("INSERT INTO in_app_notifications(tenant_id,user_id,title,message,related_type,related_id,action_url,icon_name,is_read) VALUES(:t,:u,'Job Assigned',:m,'job',:j,:url,'briefcase',0)");
                $stmt->execute(array(':t' => $tenant, ':u' => $uid, ':m' => 'You have been assigned to ' . $job['job_no'] . ' - ' . $job['title'] . '. Schedule: ' . $schedule . '.', ':j' => $job['id'], ':url' => 'my-job-view.php?id=' . $job['id']));
                $summary['in_app']++;
            } catch (Throwable $e) {
                $summary['messages'][] = 'In-app notification failed for ' . $name . ': ' . $e->getMessage();
            }
        }
        if (!$sendEmail || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$cfg) {
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

function jbCustomerPortalUsers(PDO $pdo, $tenant, $clientId)
{
    if (!jbTable($pdo, 'client_portal_users'))
        return array();
    $stmt = $pdo->prepare("SELECT id,email FROM client_portal_users WHERE tenant_id=:t AND client_id=:c AND status='active' ORDER BY id");
    $stmt->execute(array(':t' => $tenant, ':c' => $clientId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jbNotifyCustomer(PDO $pdo, $tenant, $branch, $job, $quote)
{
    $summary = array(
        'customer_in_app_sent' => 0,
        'customer_in_app_skipped' => 0,
        'customer_email_sent' => 0,
        'customer_email_failed' => 0,
        'customer_email_skipped' => 0,
        'messages' => array()
    );

    $portalUsers = jbCustomerPortalUsers($pdo, $tenant, (int) $job['client_id']);
    if (jbTable($pdo, 'in_app_notifications') && $portalUsers) {
        $ins = $pdo->prepare("INSERT INTO in_app_notifications(tenant_id,user_id,portal_user_id,title,message,related_type,related_id,action_url,icon_name,is_read) VALUES(:t,NULL,:p,'Job Scheduled',:m,'job',:j,NULL,'briefcase',0)");
        $message = 'Your job ' . $job['job_no'] . ' - ' . $job['title'] . ' has been scheduled. ' . jbScheduleText($job) . '.';
        foreach ($portalUsers as $portalUser) {
            try {
                $ins->execute(array(':t' => $tenant, ':p' => (int) $portalUser['id'], ':m' => $message, ':j' => (int) $job['id']));
                $summary['customer_in_app_sent']++;
            } catch (Throwable $e) {
                $summary['messages'][] = 'Customer in-app notification failed: ' . $e->getMessage();
            }
        }
    } else {
        $summary['customer_in_app_skipped'] = 1;
    }

    $email = trim((string) $quote['client_email']);
    if (($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) && $portalUsers) {
        foreach ($portalUsers as $portalUser) {
            $candidate = trim((string) $portalUser['email']);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $email = $candidate;
                break;
            }
        }
    }

    if ((int) $quote['allow_email'] !== 1 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
            . '<p>Your job <strong>' . htmlspecialchars($job['job_no'], ENT_QUOTES, 'UTF-8') . '</strong> has been scheduled.</p>'
            . '<div style="margin:16px 0;padding:14px;background:#f6f8fb;border-radius:8px">'
            . '<strong>Job:</strong> ' . htmlspecialchars($job['title'], ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Service:</strong> ' . htmlspecialchars($quote['service_name'] ? $quote['service_name'] : 'Service Job', ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Scheduled:</strong> ' . htmlspecialchars($schedule, ENT_QUOTES, 'UTF-8')
            . '</div><p>Our assigned team will attend according to the schedule above.</p><p>Thank you,<br>FieldPlx</p></div></div>';
        jbMail($cfg, $password, $email, 'Job Scheduled - ' . $job['job_no'], $html);
        $summary['customer_email_sent'] = 1;
    } catch (Throwable $e) {
        $summary['customer_email_failed'] = 1;
        $summary['messages'][] = 'Customer ' . $email . ': ' . $e->getMessage();
    }
    return $summary;
}



/* ==========================================================
 * Job schedules, visits and billing
 * ========================================================== */
function jbIntList($value)
{
    $out = array();
    if (!is_array($value))
        return $out;
    foreach ($value as $item) {
        $id = (int) $item;
        if ($id > 0)
            $out[$id] = $id;
    }
    return array_values($out);
}

function jbUsersByIds(PDO $pdo, $tenant, $ids)
{
    $ids = jbIntList($ids);
    if (!$ids)
        return array();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT id,CONCAT(first_name,CASE WHEN last_name IS NOT NULL AND last_name<>'' THEN CONCAT(' ',last_name) ELSE '' END) name,email,department_id,job_title FROM users WHERE tenant_id=? AND id IN ($placeholders) AND status='active' AND deleted_at IS NULL AND (is_bookable=1 OR is_field_worker=1 OR is_tenant_admin=1)";
    $stmt = $pdo->prepare($sql);
    $params = array_merge(array((int) $tenant), $ids);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = array();
    foreach ($rows as $row)
        $map[(int) $row['id']] = $row;
    $ordered = array();
    foreach ($ids as $id)
        if (isset($map[$id]))
            $ordered[] = $map[$id];
    return $ordered;
}

function jbParseSchedulePayload($raw)
{
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded) || !$decoded)
        jbRes(422, false, 'Add at least one job schedule.');
    if (count($decoded) > 25)
        jbRes(422, false, 'A job can contain a maximum of 25 schedule definitions.');

    $validRepeat = array('none', 'daily', 'weekly', 'monthly', 'yearly');
    $validEnd = array('on_date', 'after_occurrences', 'after_duration');
    $out = array();

    foreach ($decoded as $index => $row) {
        if (!is_array($row))
            jbRes(422, false, 'Invalid schedule data.');

        $startDate = jbDate(isset($row['start_date']) ? $row['start_date'] : '');
        $endDate = jbDate(isset($row['end_date']) ? $row['end_date'] : (isset($row['start_date']) ? $row['start_date'] : ''));
        $anytime = !empty($row['anytime']) ? 1 : 0;
        $scheduleLater = !empty($row['schedule_later']) ? 1 : 0;

        $startRaw = isset($row['start_time']) ? $row['start_time'] : '';
        $endRaw = isset($row['end_time']) ? $row['end_time'] : '';
        if (($anytime || $scheduleLater) && trim((string) $startRaw) === '')
            $startRaw = '09:00';
        if (($anytime || $scheduleLater) && trim((string) $endRaw) === '')
            $endRaw = '10:00';

        $startTime = jbTime($startRaw);
        $endTime = jbTime($endRaw);

        if ($startDate === false || $endDate === false || $startDate === null || $endDate === null)
            jbRes(422, false, 'Schedule ' . ($index + 1) . ': enter a valid date.');
        if ($startTime === false || $endTime === false || $startTime === null || $endTime === null)
            jbRes(422, false, 'Schedule ' . ($index + 1) . ': enter start and end time, or choose Anytime / Schedule later.');

        $startStamp = strtotime($startDate . ' ' . $startTime);
        $endStamp = strtotime($endDate . ' ' . $endTime);
        if ($endStamp <= $startStamp)
            jbRes(422, false, 'Schedule ' . ($index + 1) . ': end date/time must be after start date/time.');

        $repeat = strtolower(trim(isset($row['repeat_type']) ? (string) $row['repeat_type'] : 'none'));
        if (!in_array($repeat, $validRepeat, true))
            $repeat = 'none';

        $interval = max(1, min(365, (int) (isset($row['repeat_interval']) ? $row['repeat_interval'] : 1)));
        $weekly = jbIntList(isset($row['weekly_days']) ? $row['weekly_days'] : array());
        $weekly = array_values(array_filter($weekly, function ($x) { return $x >= 0 && $x <= 6; }));
        if ($repeat === 'weekly' && !$weekly)
            $weekly = array((int) date('w', $startStamp));

        $monthlyMode = strtolower(trim(isset($row['monthly_mode']) ? (string) $row['monthly_mode'] : 'day_of_month'));
        if (!in_array($monthlyMode, array('day_of_month', 'day_of_week'), true))
            $monthlyMode = 'day_of_month';
        $monthlyDay = isset($row['monthly_day']) ? (int) $row['monthly_day'] : (int) date('j', $startStamp);
        if ($monthlyDay !== 0)
            $monthlyDay = max(1, min(31, $monthlyDay));
        $monthlyWeek = max(1, min(5, (int) (isset($row['monthly_week']) ? $row['monthly_week'] : (int) ceil(((int) date('j', $startStamp)) / 7))));
        $monthlyWeekday = max(0, min(6, (int) (isset($row['monthly_weekday']) ? $row['monthly_weekday'] : (int) date('w', $startStamp))));

        $endMode = strtolower(trim(isset($row['end_mode']) ? (string) $row['end_mode'] : 'after_occurrences'));
        if (!in_array($endMode, $validEnd, true))
            $endMode = 'after_occurrences';

        $repeatEndDate = null;
        $occurrences = 1;
        $endAfterValue = null;
        $endAfterUnit = null;

        if ($repeat !== 'none') {
            if ($endMode === 'on_date') {
                $repeatEndDate = jbDate(isset($row['repeat_end_date']) ? $row['repeat_end_date'] : '');
                if ($repeatEndDate === false || $repeatEndDate === null)
                    jbRes(422, false, 'Schedule ' . ($index + 1) . ': select the recurrence end date.');
                if (strtotime($repeatEndDate) < strtotime($startDate))
                    jbRes(422, false, 'Schedule ' . ($index + 1) . ': recurrence end date cannot be before the start date.');
                $occurrences = null;
            } elseif ($endMode === 'after_duration') {
                $endAfterValue = max(1, min(120, (int) (isset($row['end_after_value']) ? $row['end_after_value'] : 1)));
                $endAfterUnit = strtolower(trim(isset($row['end_after_unit']) ? (string) $row['end_after_unit'] : 'months'));
                if (!in_array($endAfterUnit, array('days', 'weeks', 'months', 'years'), true))
                    $endAfterUnit = 'months';
                $durationDate = new DateTime($startDate . ' 00:00:00');
                $durationDate->modify('+' . $endAfterValue . ' ' . $endAfterUnit);
                $repeatEndDate = $durationDate->format('Y-m-d');
                $occurrences = null;
            } else {
                $occurrences = max(1, min(500, (int) (isset($row['repeat_occurrences']) ? $row['repeat_occurrences'] : 1)));
            }
        } else {
            $endMode = 'after_occurrences';
            $occurrences = 1;
            $interval = 1;
            $weekly = array();
        }

        $out[] = array(
            'title' => substr(trim(isset($row['title']) ? (string) $row['title'] : ''), 0, 190),
            'start_date' => $startDate,
            'start_time' => $startTime,
            'end_date' => $endDate,
            'end_time' => $endTime,
            'repeat_type' => $repeat,
            'repeat_interval' => $interval,
            'weekly_days' => $weekly,
            'monthly_mode' => $monthlyMode,
            'monthly_day' => $monthlyDay,
            'monthly_week' => $monthlyWeek,
            'monthly_weekday' => $monthlyWeekday,
            'end_mode' => $endMode,
            'repeat_end_date' => $repeatEndDate,
            'repeat_occurrences' => $occurrences,
            'end_after_value' => $endAfterValue,
            'end_after_unit' => $endAfterUnit,
            'instructions' => trim(isset($row['instructions']) ? (string) $row['instructions'] : ''),
            'assignee_ids' => jbIntList(isset($row['assignee_ids']) ? $row['assignee_ids'] : array()),
            'schedule_later' => $scheduleLater,
            'anytime' => $anytime,
            'email_team_about_assignment' => !empty($row['email_team_about_assignment']) ? 1 : 0
        );
    }

    return $out;
}

function jbMonthOccurrence(DateTime $base, $months)
{
    $year = (int) $base->format('Y');
    $month = (int) $base->format('n');
    $day = (int) $base->format('j');
    $total = ($year * 12 + ($month - 1)) + (int) $months;
    $targetYear = (int) floor($total / 12);
    $targetMonth = ($total % 12) + 1;
    $last = (int) date('t', strtotime(sprintf('%04d-%02d-01', $targetYear, $targetMonth)));
    $targetDay = min($day, $last);
    return new DateTime(sprintf('%04d-%02d-%02d %s', $targetYear, $targetMonth, $targetDay, $base->format('H:i:s')));
}

function jbYearOccurrence(DateTime $base, $years)
{
    $year = (int) $base->format('Y') + (int) $years;
    $month = (int) $base->format('n');
    $day = (int) $base->format('j');
    $last = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    return new DateTime(sprintf('%04d-%02d-%02d %s', $year, $month, min($day, $last), $base->format('H:i:s')));
}

function jbMonthlyCustomOccurrence(DateTime $base, $months, $mode, $day, $week, $weekday)
{
    $target = jbMonthOccurrence($base, $months);
    $year = (int) $target->format('Y');
    $month = (int) $target->format('n');
    $hour = (int) $base->format('H');
    $minute = (int) $base->format('i');
    $second = (int) $base->format('s');

    if ($mode === 'day_of_week') {
        $first = new DateTime(sprintf('%04d-%02d-01 %02d:%02d:%02d', $year, $month, $hour, $minute, $second));
        $firstDow = (int) $first->format('w');
        $offset = (($weekday - $firstDow) + 7) % 7;
        $candidateDay = 1 + $offset + (($week - 1) * 7);
        $lastDay = (int) $first->format('t');
        if ($candidateDay > $lastDay) {
            $candidateDay -= 7;
        }
        return new DateTime(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $candidateDay, $hour, $minute, $second));
    }

    $lastDay = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    $targetDay = ((int) $day === 0) ? $lastDay : min(max(1, (int) $day), $lastDay);
    return new DateTime(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $targetDay, $hour, $minute, $second));
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
                if ($i > 0)
                    $d->modify('+' . ($i * $s['repeat_interval']) . ' days');
                if (in_array($s['end_mode'], array('on_date', 'after_duration'), true) && $d->format('Y-m-d') > $s['repeat_end_date'])
                    break;
                $starts[] = $d;
                $i++;
                if ($s['end_mode'] === 'after_occurrences' && count($starts) >= (int) $s['repeat_occurrences'])
                    break;
            }
        } elseif ($repeat === 'weekly') {
            $cursor = clone $baseStart;
            $cursor->setTime((int) $baseStart->format('H'), (int) $baseStart->format('i'), (int) $baseStart->format('s'));
            $anchor = clone $baseStart;
            $anchor->setTime(0, 0, 0);
            $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days');
            $guard = 0;
            while (count($starts) < $globalCap && $guard < 20000) {
                if (in_array($s['end_mode'], array('on_date', 'after_duration'), true) && $cursor->format('Y-m-d') > $s['repeat_end_date'])
                    break;
                $cursorMid = clone $cursor;
                $cursorMid->setTime(0, 0, 0);
                $days = (int) floor(($cursorMid->getTimestamp() - $anchor->getTimestamp()) / 86400);
                $weekIndex = (int) floor($days / 7);
                $dow = (int) $cursor->format('w');
                if ($weekIndex % $s['repeat_interval'] === 0 && in_array($dow, $s['weekly_days'], true)) {
                    $starts[] = clone $cursor;
                    if ($s['end_mode'] === 'after_occurrences' && count($starts) >= (int) $s['repeat_occurrences'])
                        break;
                }
                $cursor->modify('+1 day');
                $guard++;
            }
        } elseif ($repeat === 'monthly') {
            $i = 0;
            while (count($starts) < $globalCap) {
                $d = jbMonthlyCustomOccurrence($baseStart, $i * $s['repeat_interval'], isset($s['monthly_mode']) ? $s['monthly_mode'] : 'day_of_month', isset($s['monthly_day']) ? $s['monthly_day'] : (int)$baseStart->format('j'), isset($s['monthly_week']) ? $s['monthly_week'] : 1, isset($s['monthly_weekday']) ? $s['monthly_weekday'] : (int)$baseStart->format('w'));
                if (in_array($s['end_mode'], array('on_date', 'after_duration'), true) && $d->format('Y-m-d') > $s['repeat_end_date'])
                    break;
                $starts[] = $d;
                $i++;
                if ($s['end_mode'] === 'after_occurrences' && count($starts) >= (int) $s['repeat_occurrences'])
                    break;
            }
        } elseif ($repeat === 'yearly') {
            $i = 0;
            while (count($starts) < $globalCap) {
                $d = jbYearOccurrence($baseStart, $i * $s['repeat_interval']);
                if (in_array($s['end_mode'], array('on_date', 'after_duration'), true) && $d->format('Y-m-d') > $s['repeat_end_date'])
                    break;
                $starts[] = $d;
                $i++;
                if ($s['end_mode'] === 'after_occurrences' && count($starts) >= (int) $s['repeat_occurrences'])
                    break;
            }
        }

        foreach ($starts as $start) {
            $end = clone $start;
            if ($duration > 0)
                $end->modify('+' . $duration . ' seconds');
            $all[] = array(
                'schedule_index' => $scheduleIndex,
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
                'instructions' => $s['instructions'],
                'assignee_ids' => $s['assignee_ids']
            );
            if (count($all) > $globalCap)
                jbRes(422, false, 'The recurring schedule creates more than 500 visits. Reduce the recurrence range.');
        }
    }

    usort($all, function ($a, $b) {
        return strcmp($a['start'], $b['start']); });
    if (!$all)
        jbRes(422, false, 'The recurrence settings do not create any visits.');
    return $all;
}

function jbJobSchedules(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo, 'job_schedules'))
        return array();
    $stmt = $pdo->prepare("SELECT * FROM job_schedules WHERE tenant_id=:t AND job_id=:j ORDER BY schedule_order,id");
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['weekly_days'] = !empty($row['weekly_days_json']) ? json_decode($row['weekly_days_json'], true) : array();
        if (!is_array($row['weekly_days']))
            $row['weekly_days'] = array();
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
    if (!jbTable($pdo, 'job_billing_settings'))
        return null;
    $stmt = $pdo->prepare("SELECT * FROM job_billing_settings WHERE tenant_id=:t AND job_id=:j LIMIT 1");
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function jbJobAttachments(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo, 'attachments'))
        return array();
    $stmt = $pdo->prepare("SELECT id,file_name,file_path,file_mime,file_size,attachment_type,description,created_at FROM attachments WHERE tenant_id=:t AND job_id=:j ORDER BY id DESC");
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jbJobChecklistTemplateIds(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo, 'job_checklists'))
        return array();
    $sql = "SELECT DISTINCT checklist_template_id FROM job_checklists WHERE tenant_id=:t AND job_id=:j AND checklist_template_id IS NOT NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function jbChecklistQuestionType($value)
{
    $value = strtolower(trim((string) $value));
    $valid = array('short_answer', 'long_answer', 'dropdown', 'checkbox', 'number', 'image', 'date', 'signature');
    return in_array($value, $valid, true) ? $value : 'checkbox';
}

function jbSaveJobChecklists(PDO $pdo, $tenant, $jobId, $primaryUser, $createdBy)
{
    if (!jbTable($pdo, 'checklist_templates') || !jbTable($pdo, 'checklist_template_items') || !jbTable($pdo, 'job_checklists') || !jbTable($pdo, 'job_checklist_items'))
        return array();

    $selected = isset($_POST['checklist_template_ids']) && is_array($_POST['checklist_template_ids']) ? jbIntList($_POST['checklist_template_ids']) : array();
    $customRaw = trim((string) jbP('new_checklist_json', ''));
    if ($customRaw !== '') {
        $custom = json_decode($customRaw, true);
        if (!is_array($custom))
            jbRes(422, false, 'The new checklist data is invalid.');
        $name = substr(trim(isset($custom['name']) ? (string) $custom['name'] : ''), 0, 190);
        if ($name === '')
            jbRes(422, false, 'The new checklist needs a form title.');
        $desc = trim(isset($custom['description']) ? (string) $custom['description'] : '');
        $items = isset($custom['items']) && is_array($custom['items']) ? $custom['items'] : array();
        $validItems = array();
        foreach ($items as $item) {
            if (!is_array($item))
                continue;
            $title = substr(trim(isset($item['title']) ? (string) $item['title'] : ''), 0, 255);
            if ($title === '')
                continue;
            $sectionTitle = substr(trim(isset($item['section_title']) ? (string) $item['section_title'] : ''), 0, 190);
            $questionType = jbChecklistQuestionType(isset($item['question_type']) ? $item['question_type'] : 'checkbox');
            $options = isset($item['options']) && is_array($item['options']) ? $item['options'] : array();
            $cleanOptions = array();
            foreach ($options as $option) {
                $option = substr(trim((string) $option), 0, 190);
                if ($option !== '')
                    $cleanOptions[] = $option;
            }
            if (in_array($questionType, array('dropdown', 'checkbox'), true) && !$cleanOptions)
                $cleanOptions = array('Option 1');
            $validItems[] = array(
                'title' => $title,
                'description' => trim(isset($item['description']) ? (string) $item['description'] : ''),
                'required' => !empty($item['required']) ? 1 : 0,
                'section_title' => $sectionTitle,
                'question_type' => $questionType,
                'options_json' => $cleanOptions ? json_encode($cleanOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null
            );
        }
        if (!$validItems)
            jbRes(422, false, 'The new checklist needs at least one question.');

        $stmt = $pdo->prepare("INSERT INTO checklist_templates(tenant_id,name,description,status,created_by) VALUES(:t,:n,:d,'active',:u)");
        $stmt->execute(array(':t' => $tenant, ':n' => $name, ':d' => $desc !== '' ? $desc : null, ':u' => $createdBy));
        $templateId = (int) $pdo->lastInsertId();

        foreach ($validItems as $i => $item) {
            $cols = array('checklist_template_id', 'title', 'description', 'is_required', 'sort_order');
            $vals = array(':ct', ':title', ':d', ':r', ':o');
            $params = array(':ct' => $templateId, ':title' => $item['title'], ':d' => $item['description'] !== '' ? $item['description'] : null, ':r' => $item['required'], ':o' => $i + 1);
            if (jbCol($pdo, 'checklist_template_items', 'section_title')) {
                $cols[] = 'section_title';
                $vals[] = ':section';
                $params[':section'] = $item['section_title'] !== '' ? $item['section_title'] : null;
            }
            if (jbCol($pdo, 'checklist_template_items', 'question_type')) {
                $cols[] = 'question_type';
                $vals[] = ':qt';
                $params[':qt'] = $item['question_type'];
            }
            if (jbCol($pdo, 'checklist_template_items', 'options_json')) {
                $cols[] = 'options_json';
                $vals[] = ':opts';
                $params[':opts'] = $item['options_json'];
            }
            $ins = $pdo->prepare('INSERT INTO checklist_template_items(' . implode(',', $cols) . ') VALUES(' . implode(',', $vals) . ')');
            $ins->execute($params);
        }
        $selected[] = $templateId;
    }
    $selected = jbIntList($selected);

    $valid = array();
    if ($selected) {
        $placeholders = implode(',', array_fill(0, count($selected), '?'));
        $stmt = $pdo->prepare("SELECT id,name FROM checklist_templates WHERE tenant_id=? AND status='active' AND id IN ($placeholders)");
        $stmt->execute(array_merge(array((int) $tenant), $selected));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
            $valid[(int) $row['id']] = $row;
    }

    $existing = array();
    $stmt = $pdo->prepare("SELECT id,checklist_template_id,status FROM job_checklists WHERE tenant_id=:t AND job_id=:j AND visit_id IS NULL");
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tid = (int) $row['checklist_template_id'];
        if ($tid > 0)
            $existing[$tid] = $row;
    }

    foreach ($existing as $tid => $row) {
        if (!isset($valid[$tid]) && $row['status'] === 'open') {
            $pdo->prepare("DELETE FROM job_checklist_items WHERE job_checklist_id=:id")->execute(array(':id' => $row['id']));
            $pdo->prepare("DELETE FROM job_checklists WHERE id=:id AND tenant_id=:t")->execute(array(':id' => $row['id'], ':t' => $tenant));
        }
    }

    foreach ($valid as $tid => $template) {
        if (isset($existing[$tid]))
            continue;
        $cols = "tenant_id,job_id,visit_id,checklist_template_id,name,status";
        $vals = ":t,:j,NULL,:ct,:n,'open'";
        $params = array(':t' => $tenant, ':j' => $jobId, ':ct' => $tid, ':n' => $template['name']);
        if (jbCol($pdo, 'job_checklists', 'workflow_step_id')) {
            $cols .= ",workflow_step_id";
            $vals .= ",NULL";
        }
        if (jbCol($pdo, 'job_checklists', 'assigned_user_id')) {
            $cols .= ",assigned_user_id";
            $vals .= ",:au";
            $params[':au'] = $primaryUser > 0 ? $primaryUser : null;
        }
        $stmt = $pdo->prepare("INSERT INTO job_checklists($cols) VALUES($vals)");
        $stmt->execute($params);
        $jobChecklistId = (int) $pdo->lastInsertId();

        $selectCols = 'title,description,is_required,sort_order';
        if (jbCol($pdo, 'checklist_template_items', 'section_title'))
            $selectCols .= ',section_title';
        else
            $selectCols .= ',NULL AS section_title';
        if (jbCol($pdo, 'checklist_template_items', 'question_type'))
            $selectCols .= ',question_type';
        else
            $selectCols .= ",'checkbox' AS question_type";
        if (jbCol($pdo, 'checklist_template_items', 'options_json'))
            $selectCols .= ',options_json';
        else
            $selectCols .= ',NULL AS options_json';
        $items = $pdo->prepare("SELECT $selectCols FROM checklist_template_items WHERE checklist_template_id=:ct ORDER BY sort_order,id");
        $items->execute(array(':ct' => $tid));
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $itemCols = array('job_checklist_id', 'title', 'description', 'is_required', 'is_completed', 'sort_order');
            $itemVals = array(':jc', ':title', ':d', ':r', '0', ':o');
            $itemParams = array(':jc' => $jobChecklistId, ':title' => $item['title'], ':d' => $item['description'], ':r' => $item['is_required'], ':o' => $item['sort_order']);
            if (jbCol($pdo, 'job_checklist_items', 'tenant_id')) {
                array_unshift($itemCols, 'tenant_id');
                array_unshift($itemVals, ':t');
                $itemParams[':t'] = $tenant;
            }
            if (jbCol($pdo, 'job_checklist_items', 'section_title')) {
                $itemCols[] = 'section_title';
                $itemVals[] = ':section';
                $itemParams[':section'] = $item['section_title'];
            }
            if (jbCol($pdo, 'job_checklist_items', 'question_type')) {
                $itemCols[] = 'question_type';
                $itemVals[] = ':qt';
                $itemParams[':qt'] = jbChecklistQuestionType($item['question_type']);
            }
            if (jbCol($pdo, 'job_checklist_items', 'options_json')) {
                $itemCols[] = 'options_json';
                $itemVals[] = ':opts';
                $itemParams[':opts'] = $item['options_json'];
            }
            $ins = $pdo->prepare('INSERT INTO job_checklist_items(' . implode(',', $itemCols) . ') VALUES(' . implode(',', $itemVals) . ')');
            $ins->execute($itemParams);
        }
    }
    return array_keys($valid);
}

function jbJobLineItems(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo, 'job_line_items'))
        return array();
    $stmt = $pdo->prepare("SELECT * FROM job_line_items WHERE tenant_id=:t AND job_id=:j ORDER BY sort_order,id");
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jbJobInternalNote(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo, 'notes'))
        return '';
    $stmt = $pdo->prepare("SELECT note FROM notes WHERE tenant_id=:t AND related_type='job' AND related_id=:j AND is_internal=1 ORDER BY id DESC LIMIT 1");
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    $note = $stmt->fetchColumn();
    return $note !== false ? (string) $note : '';
}

function jbJobCustomFields(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo, 'job_custom_fields'))
        return array();

    $cols = array('id', 'field_label', 'field_value', 'sort_order');
    foreach (array('field_type', 'field_options_json', 'default_value', 'is_transferable') as $col) {
        if (jbCol($pdo, 'job_custom_fields', $col))
            $cols[] = $col;
    }

    $stmt = $pdo->prepare("SELECT " . implode(',', $cols) . " FROM job_custom_fields WHERE tenant_id=:t AND job_id=:j ORDER BY sort_order,id");
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['field_type'] = isset($row['field_type']) ? (string) $row['field_type'] : 'text';
        $row['options'] = !empty($row['field_options_json']) ? json_decode((string) $row['field_options_json'], true) : array();
        if (!is_array($row['options']))
            $row['options'] = array();
        $row['default_value'] = isset($row['default_value']) ? (string) $row['default_value'] : '';
        $row['is_transferable'] = !empty($row['is_transferable']) ? 1 : 0;
        unset($row['field_options_json']);
    }
    unset($row);

    return $rows;
}

function jbParseCustomFields($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '')
        return array();
    $decoded = json_decode($raw, true);
    if (!is_array($decoded))
        jbRes(422, false, 'Custom field data is invalid.');
    if (count($decoded) > 50)
        jbRes(422, false, 'A job can contain a maximum of 50 custom fields.');

    $validTypes = array('text', 'numeric', 'boolean', 'area', 'dropdown');
    $fields = array();

    foreach ($decoded as $index => $row) {
        if (!is_array($row))
            continue;

        $label = trim(isset($row['field_label']) ? (string) $row['field_label'] : (isset($row['label']) ? (string) $row['label'] : ''));
        $value = trim(isset($row['field_value']) ? (string) $row['field_value'] : (isset($row['value']) ? (string) $row['value'] : ''));
        $type = strtolower(trim(isset($row['field_type']) ? (string) $row['field_type'] : 'text'));
        if (!in_array($type, $validTypes, true))
            $type = 'text';

        if ($label === '' && $value === '')
            continue;
        if ($label === '')
            jbRes(422, false, 'Custom field ' . ($index + 1) . ': enter a custom field name.');

        $options = isset($row['options']) && is_array($row['options']) ? $row['options'] : array();
        $cleanOptions = array();
        foreach ($options as $option) {
            $option = trim((string) $option);
            if ($option !== '')
                $cleanOptions[] = substr($option, 0, 190);
        }
        if ($type === 'dropdown' && !$cleanOptions)
            jbRes(422, false, 'Custom field ' . ($index + 1) . ': add at least one dropdown option.');

        $defaultValue = trim(isset($row['default_value']) ? (string) $row['default_value'] : '');
        $fields[] = array(
            'field_label' => substr($label, 0, 120),
            'field_value' => substr($value, 0, 2000),
            'field_type' => $type,
            'options' => $cleanOptions,
            'default_value' => substr($defaultValue, 0, 2000),
            'is_transferable' => !empty($row['is_transferable']) ? 1 : 0,
            'sort_order' => count($fields) + 1
        );
    }

    return $fields;
}

function jbSaveJobCustomFields(PDO $pdo, $tenant, $jobId, $fields)
{
    if (!jbTable($pdo, 'job_custom_fields')) {
        if ($fields)
            throw new RuntimeException('Job custom fields are not installed. Run migration_job_custom_fields_v1.sql once.');
        return;
    }

    $pdo->prepare("DELETE FROM job_custom_fields WHERE tenant_id=:t AND job_id=:j")
        ->execute(array(':t' => $tenant, ':j' => $jobId));

    if (!$fields)
        return;

    $hasType = jbCol($pdo, 'job_custom_fields', 'field_type');
    $hasOptions = jbCol($pdo, 'job_custom_fields', 'field_options_json');
    $hasDefault = jbCol($pdo, 'job_custom_fields', 'default_value');
    $hasTransferable = jbCol($pdo, 'job_custom_fields', 'is_transferable');

    foreach ($fields as $field) {
        $cols = array('tenant_id', 'job_id', 'field_label', 'field_value', 'sort_order');
        $vals = array(':t', ':j', ':l', ':v', ':o');
        $params = array(
            ':t' => $tenant,
            ':j' => $jobId,
            ':l' => $field['field_label'],
            ':v' => $field['field_value'] !== '' ? $field['field_value'] : null,
            ':o' => (int) $field['sort_order']
        );

        if ($hasType) {
            $cols[] = 'field_type';
            $vals[] = ':ft';
            $params[':ft'] = $field['field_type'];
        }
        if ($hasOptions) {
            $cols[] = 'field_options_json';
            $vals[] = ':fo';
            $params[':fo'] = $field['options'] ? json_encode($field['options'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }
        if ($hasDefault) {
            $cols[] = 'default_value';
            $vals[] = ':dv';
            $params[':dv'] = $field['default_value'] !== '' ? $field['default_value'] : null;
        }
        if ($hasTransferable) {
            $cols[] = 'is_transferable';
            $vals[] = ':tr';
            $params[':tr'] = !empty($field['is_transferable']) ? 1 : 0;
        }

        $stmt = $pdo->prepare("INSERT INTO job_custom_fields(" . implode(',', $cols) . ") VALUES(" . implode(',', $vals) . ")");
        $stmt->execute($params);
    }
}

function jbParseLineItems(PDO $pdo, $tenant, $raw, $discountType = '', $discountValue = 0)
{
    if (!jbTable($pdo, 'job_line_items'))
        return array('items' => array(), 'subtotal' => 0.0, 'discount_type' => null, 'discount_value' => 0.0, 'discount_total' => 0.0, 'tax_total' => 0.0, 'total' => 0.0, 'cost_total' => 0.0);

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded) || !$decoded)
        jbRes(422, false, 'Add at least one Product / Service line item.');
    if (count($decoded) > 100)
        jbRes(422, false, 'A job can contain a maximum of 100 line items.');

    $psLookup = null;
    if (jbTable($pdo, 'product_services')) {
        $psLookup = $pdo->prepare("SELECT id,name,item_type,description,unit_cost,unit_price,tax_percent FROM product_services WHERE id=:id AND tenant_id=:t AND status='active' AND deleted_at IS NULL LIMIT 1");
    }

    $productLookup = null;
    if (jbTable($pdo, 'products')) {
        $productSql = "SELECT id,name,description,base_unit_price,selling_price,tax_percent FROM products WHERE id=:id AND tenant_id=:t AND status='active'";
        if (jbCol($pdo, 'products', 'deleted_at'))
            $productSql .= " AND deleted_at IS NULL";
        $productSql .= " LIMIT 1";
        $productLookup = $pdo->prepare($productSql);
    }

    $items = array();
    $subtotal = 0.0;
    $costTotal = 0.0;

    foreach ($decoded as $i => $row) {
        if (!is_array($row))
            continue;

        $productServiceId = (int) (isset($row['product_service_id']) ? $row['product_service_id'] : 0);
        $productId = (int) (isset($row['product_id']) ? $row['product_id'] : 0);
        $catalog = null;
        $requestedSource = strtolower(trim(isset($row['item_source']) ? (string) $row['item_source'] : ''));
        $source = 'manual';
        $itemType = 'manual';
        $createProduct = false;

        if ($productServiceId > 0) {
            if (!$psLookup)
                jbRes(422, false, 'Line item ' . ($i + 1) . ': service catalog is unavailable.');
            $psLookup->execute(array(':id' => $productServiceId, ':t' => $tenant));
            $catalog = $psLookup->fetch(PDO::FETCH_ASSOC);
            if (!$catalog)
                jbRes(422, false, 'Line item ' . ($i + 1) . ': select a valid Product / Service.');
            $source = 'product_service';
            $itemType = !empty($catalog['item_type']) ? (string) $catalog['item_type'] : 'service';
        } elseif ($productId > 0) {
            if (!$productLookup)
                jbRes(422, false, 'Line item ' . ($i + 1) . ': product catalog is unavailable.');
            $productLookup->execute(array(':id' => $productId, ':t' => $tenant));
            $catalog = $productLookup->fetch(PDO::FETCH_ASSOC);
            if (!$catalog)
                jbRes(422, false, 'Line item ' . ($i + 1) . ': select a valid product.');
            $source = 'product';
            $itemType = 'product';
        } elseif ($requestedSource === 'new_product') {
            if (!jbTable($pdo, 'products'))
                jbRes(422, false, 'Line item ' . ($i + 1) . ': products are unavailable.');
            $source = 'new_product';
            $itemType = 'product';
            $createProduct = true;
        }

        $fallbackName = $catalog ? (string) $catalog['name'] : '';
        $name = trim(isset($row['item_name']) ? (string) $row['item_name'] : $fallbackName);
        if ($name === '')
            jbRes(422, false, 'Line item ' . ($i + 1) . ': enter an item name.');
        $name = substr($name, 0, 190);

        $fallbackDescription = $catalog && isset($catalog['description']) ? (string) $catalog['description'] : '';
        $description = trim(isset($row['description']) ? (string) $row['description'] : $fallbackDescription);

        $qty = (float) (isset($row['quantity']) ? $row['quantity'] : 1);
        if ($qty <= 0)
            jbRes(422, false, 'Line item ' . ($i + 1) . ': quantity must be greater than zero.');

        if ($source === 'product') {
            $defaultCost = $catalog && isset($catalog['base_unit_price']) ? (float) $catalog['base_unit_price'] : 0;
            $defaultPrice = $catalog && isset($catalog['selling_price']) ? (float) $catalog['selling_price'] : 0;
        } else {
            $defaultCost = $catalog && isset($catalog['unit_cost']) ? (float) $catalog['unit_cost'] : 0;
            $defaultPrice = $catalog && isset($catalog['unit_price']) ? (float) $catalog['unit_price'] : 0;
        }
        $defaultTax = $catalog && isset($catalog['tax_percent']) ? (float) $catalog['tax_percent'] : 0;

        $unitCost = isset($row['unit_cost']) ? max(0, (float) $row['unit_cost']) : $defaultCost;
        $unitPrice = isset($row['unit_price']) ? max(0, (float) $row['unit_price']) : $defaultPrice;
        $taxPercent = isset($row['tax_percent']) ? max(0, min(100, (float) $row['tax_percent'])) : $defaultTax;
        $base = round($qty * $unitPrice, 2);

        $subtotal += $base;
        $costTotal += round($qty * $unitCost, 2);
        $items[] = array(
            'product_service_id' => $productServiceId > 0 ? $productServiceId : null,
            'product_id' => $productId > 0 ? $productId : null,
            'item_source' => $source,
            'item_type' => $itemType,
            'create_product' => $createProduct ? 1 : 0,
            'item_name' => $name,
            'description' => $description,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'unit_price' => $unitPrice,
            'tax_percent' => $taxPercent,
            'base_total' => $base,
            'sort_order' => $i + 1
        );
    }

    if (!$items)
        jbRes(422, false, 'Add at least one valid Product / Service line item.');

    $discountType = strtolower(trim((string) $discountType));
    $discountValue = max(0, (float) $discountValue);
    if (!in_array($discountType, array('fixed', 'percentage'), true) || $discountValue <= 0 || $subtotal <= 0) {
        $discountType = null;
        $discountValue = 0.0;
        $discountTotal = 0.0;
    } elseif ($discountType === 'percentage') {
        $discountValue = min(100, $discountValue);
        $discountTotal = round($subtotal * $discountValue / 100, 2);
    } else {
        $discountTotal = round(min($subtotal, $discountValue), 2);
        $discountValue = $discountTotal;
    }

    $taxTotal = 0.0;
    $remainingDiscount = $discountTotal;
    $lastIndex = count($items) - 1;
    foreach ($items as $index => &$item) {
        if ($discountTotal > 0 && $subtotal > 0) {
            $share = $index === $lastIndex ? $remainingDiscount : round($discountTotal * ($item['base_total'] / $subtotal), 2);
            $share = max(0, min($item['base_total'], $share));
        } else {
            $share = 0.0;
        }
        $remainingDiscount = round($remainingDiscount - $share, 2);
        $taxable = max(0, round($item['base_total'] - $share, 2));
        $tax = round($taxable * $item['tax_percent'] / 100, 2);
        $item['discount_amount'] = $share;
        $item['tax_amount'] = $tax;
        $item['line_total'] = round($taxable + $tax, 2);
        $taxTotal += $tax;
    }
    unset($item);

    $total = round(max(0, $subtotal - $discountTotal) + $taxTotal, 2);

    return array(
        'items' => $items,
        'subtotal' => round($subtotal, 2),
        'discount_type' => $discountType,
        'discount_value' => round($discountValue, 2),
        'discount_total' => round($discountTotal, 2),
        'tax_total' => round($taxTotal, 2),
        'total' => $total,
        'cost_total' => round($costTotal, 2)
    );
}

function jbResolveNewProducts(PDO $pdo, $tenant, $user, &$parsed)
{
    if (empty($parsed['items']) || !is_array($parsed['items']))
        return;
    if (!jbTable($pdo, 'products'))
        return;

    $hasDeletedAt = jbCol($pdo, 'products', 'deleted_at');
    $findSql = "SELECT id,name,description,base_unit_price,selling_price,tax_percent FROM products WHERE tenant_id=:t AND LOWER(TRIM(name))=LOWER(TRIM(:n))";
    if ($hasDeletedAt)
        $findSql .= " AND deleted_at IS NULL";
    $findSql .= " ORDER BY id ASC LIMIT 1";
    $find = $pdo->prepare($findSql);

    $insertCols = array('tenant_id', 'name', 'description', 'unit_name', 'base_unit_price', 'markup_type', 'markup_value', 'selling_price', 'tax_percent', 'status');
    $insertVals = array(':t', ':n', ':d', "'unit'", ':base', "'fixed'", ':markup', ':sell', ':tax', "'active'");
    if (jbCol($pdo, 'products', 'created_by')) {
        $insertCols[] = 'created_by';
        $insertVals[] = ':u';
    }
    $insert = $pdo->prepare("INSERT INTO products(" . implode(',', $insertCols) . ") VALUES(" . implode(',', $insertVals) . ")");

    foreach ($parsed['items'] as &$item) {
        if (empty($item['create_product']))
            continue;
        $name = trim((string) $item['item_name']);
        if ($name === '')
            continue;

        $find->execute(array(':t' => $tenant, ':n' => $name));
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $item['product_id'] = (int) $existing['id'];
            $item['item_source'] = 'product';
            $item['create_product'] = 0;
            continue;
        }

        $base = max(0, (float) $item['unit_cost']);
        $sell = max(0, (float) $item['unit_price']);
        $markup = max(0, $sell - $base);
        $params = array(
            ':t' => $tenant,
            ':n' => substr($name, 0, 190),
            ':d' => trim((string) $item['description']) !== '' ? (string) $item['description'] : null,
            ':base' => $base,
            ':markup' => $markup,
            ':sell' => $sell,
            ':tax' => max(0, min(100, (float) $item['tax_percent']))
        );
        if (jbCol($pdo, 'products', 'created_by'))
            $params[':u'] = $user > 0 ? $user : null;
        $insert->execute($params);
        $item['product_id'] = (int) $pdo->lastInsertId();
        $item['item_source'] = 'product';
        $item['create_product'] = 0;
    }
    unset($item);
}

function jbSaveLineItems(PDO $pdo, $tenant, $jobId, $parsed)
{
    if (!jbTable($pdo, 'job_line_items'))
        return;

    $pdo->prepare("DELETE FROM job_line_items WHERE tenant_id=:t AND job_id=:j")->execute(array(':t' => $tenant, ':j' => $jobId));

    $columns = array('tenant_id', 'job_id', 'product_service_id');
    $values = array(':t', ':j', ':ps');
    if (jbCol($pdo, 'job_line_items', 'product_id')) {
        $columns[] = 'product_id';
        $values[] = ':pid';
    }
    if (jbCol($pdo, 'job_line_items', 'item_source')) {
        $columns[] = 'item_source';
        $values[] = ':src';
    }
    $columns = array_merge($columns, array('item_name', 'description', 'quantity', 'unit_cost', 'unit_price'));
    $values = array_merge($values, array(':n', ':d', ':q', ':c', ':p'));
    if (jbCol($pdo, 'job_line_items', 'discount_amount')) {
        $columns[] = 'discount_amount';
        $values[] = ':disc';
    }
    if (jbCol($pdo, 'job_line_items', 'tax_percent')) {
        $columns[] = 'tax_percent';
        $values[] = ':tp';
    }
    if (jbCol($pdo, 'job_line_items', 'tax_rate_id')) {
        $columns[] = 'tax_rate_id';
        $values[] = 'NULL';
    }
    $columns = array_merge($columns, array('tax_amount', 'line_total', 'sort_order'));
    $values = array_merge($values, array(':tax', ':tot', ':o'));

    $stmt = $pdo->prepare("INSERT INTO job_line_items(" . implode(',', $columns) . ") VALUES(" . implode(',', $values) . ")");

    foreach ($parsed['items'] as $item) {
        $params = array(
            ':t' => $tenant,
            ':j' => $jobId,
            ':ps' => !empty($item['product_service_id']) ? (int) $item['product_service_id'] : null,
            ':n' => $item['item_name'],
            ':d' => $item['description'] !== '' ? $item['description'] : null,
            ':q' => $item['quantity'],
            ':c' => $item['unit_cost'],
            ':p' => $item['unit_price'],
            ':tax' => $item['tax_amount'],
            ':tot' => $item['line_total'],
            ':o' => $item['sort_order']
        );
        if (jbCol($pdo, 'job_line_items', 'product_id'))
            $params[':pid'] = !empty($item['product_id']) ? (int) $item['product_id'] : null;
        if (jbCol($pdo, 'job_line_items', 'item_source'))
            $params[':src'] = isset($item['item_source']) ? $item['item_source'] : 'manual';
        if (jbCol($pdo, 'job_line_items', 'discount_amount'))
            $params[':disc'] = isset($item['discount_amount']) ? $item['discount_amount'] : 0;
        if (jbCol($pdo, 'job_line_items', 'tax_percent'))
            $params[':tp'] = isset($item['tax_percent']) ? $item['tax_percent'] : 0;
        $stmt->execute($params);
    }
}

function jbSaveInternalNote(PDO $pdo, $tenant, $jobId, $user, $note)
{
    if (!jbTable($pdo, 'notes'))
        return;
    $note = trim((string) $note);
    $find = $pdo->prepare("SELECT id FROM notes WHERE tenant_id=:t AND related_type='job' AND related_id=:j AND is_internal=1 ORDER BY id DESC LIMIT 1");
    $find->execute(array(':t' => $tenant, ':j' => $jobId));
    $id = (int) $find->fetchColumn();
    if ($note === '') {
        if ($id > 0)
            $pdo->prepare("DELETE FROM notes WHERE id=:id AND tenant_id=:t")->execute(array(':id' => $id, ':t' => $tenant));
        return;
    }
    if ($id > 0)
        $pdo->prepare("UPDATE notes SET note=:n,user_id=:u,updated_at=NOW() WHERE id=:id AND tenant_id=:t")->execute(array(':n' => $note, ':u' => $user, ':id' => $id, ':t' => $tenant));
    else
        $pdo->prepare("INSERT INTO notes(tenant_id,related_type,related_id,user_id,note,is_internal) VALUES(:t,'job',:j,:u,:n,1)")->execute(array(':t' => $tenant, ':j' => $jobId, ':u' => $user, ':n' => $note));
}

function jbJobNoteMentionIds(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo, 'job_note_mentions'))
        return array();
    $stmt = $pdo->prepare("SELECT user_id FROM job_note_mentions WHERE tenant_id=:t AND job_id=:j ORDER BY id");
    $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function jbSaveJobNoteMentions(PDO $pdo, $tenant, $jobId, $raw)
{
    if (!jbTable($pdo, 'job_note_mentions'))
        return;

    $decoded = json_decode(trim((string)$raw), true);
    if (!is_array($decoded))
        $decoded = array();
    $ids = jbIntList($decoded);

    $pdo->prepare("DELETE FROM job_note_mentions WHERE tenant_id=:t AND job_id=:j")
        ->execute(array(':t' => $tenant, ':j' => $jobId));

    if (!$ids)
        return;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id=? AND status='active' AND deleted_at IS NULL AND id IN ($placeholders)");
    $stmt->execute(array_merge(array((int)$tenant), $ids));
    $valid = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$valid)
        return;

    $ins = $pdo->prepare("INSERT INTO job_note_mentions(tenant_id,job_id,user_id) VALUES(:t,:j,:u)");
    foreach ($valid as $userId)
        $ins->execute(array(':t' => $tenant, ':j' => $jobId, ':u' => $userId));
}

function jbSaveJobAttachments(PDO $pdo, $tenant, $jobId, $user)
{
    $result = array('saved' => 0, 'skipped' => 0, 'messages' => array());
    if (!jbTable($pdo, 'attachments') || empty($_FILES['job_attachments']) || !is_array($_FILES['job_attachments']['name']))
        return $result;
    $allowed = array('jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv');
    $count = min(12, count($_FILES['job_attachments']['name']));
    $baseDir = dirname(__DIR__) . '/uploads/jobs/' . (int) $tenant . '/' . (int) $jobId;
    if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        $result['messages'][] = 'Attachments could not be stored because the job upload folder is not writable.';
        return $result;
    }

    for ($i = 0; $i < $count; $i++) {
        $err = isset($_FILES['job_attachments']['error'][$i]) ? (int) $_FILES['job_attachments']['error'][$i] : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE)
            continue;
        if ($err !== UPLOAD_ERR_OK) {
            $result['skipped']++;
            continue;
        }
        $size = (int) $_FILES['job_attachments']['size'][$i];
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            $result['skipped']++;
            $result['messages'][] = 'A file was skipped because it exceeded 10 MB.';
            continue;
        }
        $original = basename((string) $_FILES['job_attachments']['name'][$i]);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $result['skipped']++;
            $result['messages'][] = $original . ' has an unsupported file type.';
            continue;
        }
        $tmp = (string) $_FILES['job_attachments']['tmp_name'][$i];
        $savedName = date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target = $baseDir . '/' . $savedName;
        if (!@move_uploaded_file($tmp, $target)) {
            $result['skipped']++;
            $result['messages'][] = $original . ' could not be uploaded.';
            continue;
        }
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = (string) @finfo_file($fi, $target);
                @finfo_close($fi);
            }
        }
        $relative = 'uploads/jobs/' . (int) $tenant . '/' . (int) $jobId . '/' . $savedName;
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

    $optionalCols = array(
        'title' => 'title',
        'schedule_later' => 'schedule_later',
        'anytime' => 'anytime',
        'email_team_about_assignment' => 'email_team_about_assignment',
        'monthly_mode' => 'monthly_mode',
        'monthly_day' => 'monthly_day',
        'monthly_week' => 'monthly_week',
        'monthly_weekday' => 'monthly_weekday'
    );

    $scheduleIds = array();
    foreach ($schedules as $index => $schedule) {
        $ids = $schedule['assignee_ids'] ? $schedule['assignee_ids'] : $defaultIds;
        $cols = array('tenant_id','job_id','schedule_order','start_date','start_time','end_date','end_time','repeat_type','repeat_interval','weekly_days_json','end_mode','repeat_end_date','repeat_occurrences','end_after_value','end_after_unit','instructions');
        $vals = array(':t',':j',':o',':sd',':st',':ed',':et',':rt',':ri',':wd',':em',':red',':ro',':eav',':eau',':ins');
        $params = array(
            ':t' => $tenant,
            ':j' => $jobId,
            ':o' => $index + 1,
            ':sd' => $schedule['start_date'],
            ':st' => $schedule['start_time'],
            ':ed' => $schedule['end_date'],
            ':et' => $schedule['end_time'],
            ':rt' => $schedule['repeat_type'],
            ':ri' => $schedule['repeat_interval'],
            ':wd' => $schedule['weekly_days'] ? json_encode($schedule['weekly_days']) : null,
            ':em' => $schedule['end_mode'],
            ':red' => $schedule['repeat_end_date'],
            ':ro' => $schedule['repeat_occurrences'],
            ':eav' => $schedule['end_after_value'],
            ':eau' => $schedule['end_after_unit'],
            ':ins' => $schedule['instructions'] !== '' ? $schedule['instructions'] : null
        );

        foreach ($optionalCols as $col => $key) {
            if (!jbCol($pdo, 'job_schedules', $col))
                continue;
            $ph = ':x_' . $col;
            $cols[] = $col;
            $vals[] = $ph;
            $params[$ph] = isset($schedule[$key]) && $schedule[$key] !== '' ? $schedule[$key] : null;
        }

        $stmt = $pdo->prepare("INSERT INTO job_schedules(" . implode(',', $cols) . ") VALUES(" . implode(',', $vals) . ")");
        $stmt->execute($params);
        $scheduleId = (int) $pdo->lastInsertId();
        $scheduleIds[$index] = $scheduleId;

        $insA = $pdo->prepare("INSERT INTO job_schedule_assignees(tenant_id,job_schedule_id,user_id,is_primary) VALUES(:t,:s,:u,:p)");
        foreach ($ids as $pos => $uid)
            $insA->execute(array(':t' => $tenant, ':s' => $scheduleId, ':u' => $uid, ':p' => $pos === 0 ? 1 : 0));
    }

    $protect = $pdo->prepare("SELECT COALESCE(MAX(visit_number),0) max_no,MAX(scheduled_start) cutoff FROM visits WHERE tenant_id=:t AND job_id=:j AND NOT (status IN('scheduled','rescheduled','cancelled') AND actual_arrival_at IS NULL AND work_started_at IS NULL AND work_ended_at IS NULL)");
    $protect->execute(array(':t' => $tenant, ':j' => $jobId));
    $protected = $protect->fetch(PDO::FETCH_ASSOC);
    $maxNo = (int) $protected['max_no'];
    $cutoff = !empty($protected['cutoff']) ? (string) $protected['cutoff'] : null;
    $pdo->prepare("DELETE FROM visits WHERE tenant_id=:t AND job_id=:j AND status IN('scheduled','rescheduled','cancelled') AND actual_arrival_at IS NULL AND work_started_at IS NULL AND work_ended_at IS NULL")->execute(array(':t' => $tenant, ':j' => $jobId));

    $visitInsert = $pdo->prepare("INSERT INTO visits(tenant_id,branch_id,visit_no,job_id,work_order_id,visit_number,visit_type,assigned_user_id,assigned_team_id,scheduled_start,scheduled_end,status,notes,created_by) VALUES(:t,:b,:no,:j,NULL,:vn,'service',:au,NULL,:ss,:se,'scheduled',:notes,:u)");
    $visitAssign = $pdo->prepare("INSERT INTO visit_assignments(tenant_id,visit_id,user_id,is_primary,status) VALUES(:t,:v,:u,:p,'assigned')");
    $created = 0;

    foreach ($occurrences as $occ) {
        if ($cutoff !== null && $occ['start'] <= $cutoff)
            continue;
        $ids = $occ['assignee_ids'] ? $occ['assignee_ids'] : $defaultIds;
        $maxNo++;
        $visitNo = $jobNo . '-V' . str_pad((string) $maxNo, 3, '0', STR_PAD_LEFT);
        $visitInsert->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : null, ':no' => $visitNo, ':j' => $jobId, ':vn' => $maxNo, ':au' => isset($ids[0]) ? $ids[0] : null, ':ss' => $occ['start'], ':se' => $occ['end'], ':notes' => $occ['instructions'] !== '' ? $occ['instructions'] : null, ':u' => $createdBy));
        $visitId = (int) $pdo->lastInsertId();
        foreach ($ids as $pos => $uid)
            $visitAssign->execute(array(':t' => $tenant, ':v' => $visitId, ':u' => $uid, ':p' => $pos === 0 ? 1 : 0));
        $created++;
    }

    return $created;
}



/* ========================================================================
   Job View v3 helpers
   ======================================================================== */
function jvxJobLocation(PDO $pdo, $tenant, $job)
{
    $locationId = !empty($job['location_id']) ? (int)$job['location_id'] : 0;
    if ($locationId <= 0 || !jbTable($pdo, 'client_locations')) return null;
    $countrySelect = 'NULL AS country';
    $countryJoin = '';
    if (jbCol($pdo, 'client_locations', 'country_id') && jbTable($pdo, 'countries')) {
        $countrySelect = 'co.name AS country';
        $countryJoin = ' LEFT JOIN countries co ON co.id=cl.country_id ';
    }
    $stmt=$pdo->prepare("SELECT cl.*, $countrySelect FROM client_locations cl $countryJoin WHERE cl.id=:id AND cl.tenant_id=:t AND cl.client_id=:c LIMIT 1");
    $stmt->execute(array(':id'=>$locationId, ':t'=>$tenant, ':c'=>(int)$job['client_id']));
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function jvxJobVisits(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo,'visits')) return array();
    $title = jbCol($pdo,'visits','title') ? 'v.title' : 'j.title';
    $instructions = jbCol($pdo,'visits','instructions') ? 'v.instructions' : 'v.notes';
    $anytime = jbCol($pdo,'visits','anytime') ? 'v.anytime' : '0';
    $scheduleLater = jbCol($pdo,'visits','schedule_later') ? 'v.schedule_later' : '0';
    $emailTeam = jbCol($pdo,'visits','email_team_about_assignment') ? 'v.email_team_about_assignment' : '0';
    $stmt=$pdo->prepare("SELECT v.*, $title AS display_title, $instructions AS display_instructions, $anytime AS anytime, $scheduleLater AS schedule_later, $emailTeam AS email_team_about_assignment,
        (SELECT GROUP_CONCAT(va.user_id ORDER BY va.is_primary DESC,va.id SEPARATOR ',') FROM visit_assignments va WHERE va.tenant_id=v.tenant_id AND va.visit_id=v.id AND va.status<>'removed') AS assignee_ids_csv,
        (SELECT GROUP_CONCAT(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) ORDER BY va.is_primary DESC,va.id SEPARATOR '||') FROM visit_assignments va INNER JOIN users u ON u.id=va.user_id AND u.tenant_id=va.tenant_id WHERE va.tenant_id=v.tenant_id AND va.visit_id=v.id AND va.status<>'removed') AS assignee_names_csv
        FROM visits v INNER JOIN jobs j ON j.id=v.job_id AND j.tenant_id=v.tenant_id
        WHERE v.tenant_id=:t AND v.job_id=:j AND v.status<>'cancelled' ORDER BY COALESCE(v.scheduled_start,'9999-12-31'),v.visit_number,v.id");
    $stmt->execute(array(':t'=>$tenant, ':j'=>$jobId));
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$r){
        $r['assignee_ids']=$r['assignee_ids_csv']!==null && $r['assignee_ids_csv']!=='' ? array_map('intval',explode(',',$r['assignee_ids_csv'])) : array();
        $r['assignee_names']=$r['assignee_names_csv']!==null && $r['assignee_names_csv']!=='' ? explode('||',$r['assignee_names_csv']) : array();
        unset($r['assignee_ids_csv'],$r['assignee_names_csv']);
    }
    unset($r);
    return $rows;
}

function jvxTimeEntries(PDO $pdo, $tenant, $jobId)
{
    if(!jbTable($pdo,'time_entries')) return array();
    $stmt=$pdo->prepare("SELECT te.*,TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) user_name,u.email FROM time_entries te LEFT JOIN users u ON u.id=te.user_id AND u.tenant_id=te.tenant_id WHERE te.tenant_id=:t AND te.job_id=:j ORDER BY te.entry_date DESC,te.id DESC");
    $stmt->execute(array(':t'=>$tenant,':j'=>$jobId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jvxExpenses(PDO $pdo, $tenant, $jobId)
{
    if(!jbTable($pdo,'expenses')) return array();
    $deleted=jbCol($pdo,'expenses','deleted_at') ? ' AND e.deleted_at IS NULL' : '';
    $stmt=$pdo->prepare("SELECT e.*,TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) reimburse_name FROM expenses e LEFT JOIN users u ON u.id=e.reimburse_user_id AND u.tenant_id=e.tenant_id WHERE e.tenant_id=:t AND e.job_id=:j $deleted ORDER BY e.expense_date DESC,e.id DESC");
    $stmt->execute(array(':t'=>$tenant,':j'=>$jobId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jvxInvoices(PDO $pdo, $tenant, $jobId)
{
    if(!jbTable($pdo,'invoices')) return array();
    $archived=jbCol($pdo,'invoices','archived_at') ? ' AND archived_at IS NULL' : '';
    $stmt=$pdo->prepare("SELECT id,invoice_no,status,issue_date,due_date,subtotal,discount_total,tax_total,total,amount_paid,balance_due,notes,created_at FROM invoices WHERE tenant_id=:t AND job_id=:j $archived ORDER BY id DESC");
    $stmt->execute(array(':t'=>$tenant,':j'=>$jobId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jvxHistory(PDO $pdo, $tenant, $jobId)
{
    if(!jbTable($pdo,'activity_events')) return array();
    $stmt=$pdo->prepare("SELECT ae.id,ae.event_type,ae.title,ae.details_json,ae.created_at,TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) actor_name FROM activity_events ae LEFT JOIN users u ON u.id=ae.actor_user_id AND u.tenant_id=ae.tenant_id WHERE ae.tenant_id=:t AND ae.related_type='job' AND ae.related_id=:j ORDER BY ae.id DESC LIMIT 100");
    $stmt->execute(array(':t'=>$tenant,':j'=>$jobId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jvxRichChecklists(PDO $pdo, $tenant, $jobId)
{
    if(!jbTable($pdo,'job_checklists') || !jbTable($pdo,'job_checklist_items')) return array();
    $stmt=$pdo->prepare("SELECT * FROM job_checklists WHERE tenant_id=:t AND job_id=:j ORDER BY id");
    $stmt->execute(array(':t'=>$tenant,':j'=>$jobId));
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $items=$pdo->prepare("SELECT * FROM job_checklist_items WHERE tenant_id=:t AND job_checklist_id=:id ORDER BY sort_order,id");
    foreach($rows as &$r){
        $items->execute(array(':t'=>$tenant,':id'=>$r['id']));
        $r['items']=$items->fetchAll(PDO::FETCH_ASSOC);
        $completed=0;
        foreach($r['items'] as &$i){
            $i['options']=!empty($i['options_json']) ? json_decode($i['options_json'],true) : array();
            if(!is_array($i['options'])) $i['options']=array();
            if(!empty($i['answer_json'])){
                $decoded=json_decode($i['answer_json'],true);
                $i['answer']=is_array($decoded)?$decoded:$i['answer_text'];
            } else $i['answer']=$i['answer_text'];
            if(!empty($i['is_completed'])) $completed++;
        }
        unset($i);
        $r['item_count']=count($r['items']);
        $r['completed_count']=$completed;
    }
    unset($r);
    return $rows;
}

function jvxCostSummary(PDO $pdo, $tenant, $jobId, $job)
{
    $itemCost=0.0;
    if(jbTable($pdo,'job_line_items')){
        $s=$pdo->prepare("SELECT COALESCE(SUM(quantity*unit_cost),0) FROM job_line_items WHERE tenant_id=:t AND job_id=:j");$s->execute(array(':t'=>$tenant,':j'=>$jobId));$itemCost=(float)$s->fetchColumn();
    }
    $laborCost=0.0;
    if(jbTable($pdo,'time_entries')){
        $s=$pdo->prepare("SELECT COALESCE(SUM(COALESCE(labor_cost,0)),0) FROM time_entries WHERE tenant_id=:t AND job_id=:j AND status<>'rejected'");$s->execute(array(':t'=>$tenant,':j'=>$jobId));$laborCost=(float)$s->fetchColumn();
    }
    $expenseCost=0.0;
    if(jbTable($pdo,'expenses')){
        $sql="SELECT COALESCE(SUM(COALESCE(amount,0)+COALESCE(tax_amount,0)),0) FROM expenses WHERE tenant_id=:t AND job_id=:j AND status<>'rejected'".(jbCol($pdo,'expenses','deleted_at')?" AND deleted_at IS NULL":"");
        $s=$pdo->prepare($sql);$s->execute(array(':t'=>$tenant,':j'=>$jobId));$expenseCost=(float)$s->fetchColumn();
    }
    $revenue=(float)(isset($job['total'])?$job['total']:0);
    $cost=$itemCost+$laborCost+$expenseCost;
    $profit=$revenue-$cost;
    $margin=$revenue>0?($profit/$revenue*100):0;
    return array('revenue'=>round($revenue,2),'item_cost'=>round($itemCost,2),'labor_cost'=>round($laborCost,2),'expense_cost'=>round($expenseCost,2),'cost'=>round($cost,2),'profit'=>round($profit,2),'margin_percent'=>round($margin,2));
}

function jvxExpenseNo($jobId){ return 'EXP-J'.(int)$jobId.'-'.date('YmdHis').'-'.substr(bin2hex(random_bytes(3)),0,6); }

function jvxStoreUpload($field,$tenant,$jobId,$folder,$allowed,$maxBytes)
{
    if(empty($_FILES[$field]) || !is_array($_FILES[$field])) return null;
    $f=$_FILES[$field];
    if((int)$f['error']===UPLOAD_ERR_NO_FILE) return null;
    if((int)$f['error']!==UPLOAD_ERR_OK) throw new RuntimeException('File upload failed.');
    if((int)$f['size']<=0 || (int)$f['size']>$maxBytes) throw new RuntimeException('Uploaded file is too large.');
    $tmp=(string)$f['tmp_name'];
    if(!is_uploaded_file($tmp)) throw new RuntimeException('Uploaded file is invalid.');
    $mime='application/octet-stream';
    if(function_exists('finfo_open')){$fi=@finfo_open(FILEINFO_MIME_TYPE);if($fi){$mime=(string)@finfo_file($fi,$tmp);@finfo_close($fi);}}
    $ext=strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION));
    if(!in_array($ext,$allowed,true)) throw new RuntimeException('This file type is not allowed.');
    $relative='uploads/jobs/'.(int)$tenant.'/'.(int)$jobId.'/'.$folder;
    $absolute=dirname(__DIR__).'/'.$relative;
    if(!is_dir($absolute) && !@mkdir($absolute,0775,true) && !is_dir($absolute)) throw new RuntimeException('Unable to create upload folder.');
    $name=date('YmdHis').'-'.bin2hex(random_bytes(5)).($ext!==''?'.'.$ext:'');
    if(!@move_uploaded_file($tmp,$absolute.'/'.$name)) throw new RuntimeException('Unable to save uploaded file.');
    return array('name'=>(string)$f['name'],'path'=>$relative.'/'.$name,'mime'=>$mime,'size'=>(int)$f['size']);
}

function jvxInsertVisit(PDO $pdo,$tenant,$user,$job,$data)
{
    $date=jbDate(isset($data['date'])?$data['date']:'');
    if($date===false || $date===null) throw new RuntimeException('Select a valid visit date.');
    $scheduleLater=!empty($data['schedule_later'])?1:0;
    $anytime=!empty($data['anytime'])?1:0;
    $start=jbTime(isset($data['start_time'])?$data['start_time']:'');
    $end=jbTime(isset($data['end_time'])?$data['end_time']:'');
    if($start===false||$end===false) throw new RuntimeException('Enter a valid visit time.');
    if(!$scheduleLater && !$anytime && ($start===null||$end===null)) throw new RuntimeException('Enter the visit start and end time, or select Anytime/Schedule later.');
    if($scheduleLater||$anytime){$start='00:00:00';$end='23:59:00';}
    $max=$pdo->prepare("SELECT COALESCE(MAX(visit_number),0) FROM visits WHERE tenant_id=:t AND job_id=:j FOR UPDATE");
    $max->execute(array(':t'=>$tenant,':j'=>$job['id']));$number=(int)$max->fetchColumn()+1;
    $visitNo=$job['job_no'].'-V'.str_pad((string)$number,3,'0',STR_PAD_LEFT);
    $cols=array('tenant_id','branch_id','visit_no','job_id','visit_number','visit_type','scheduled_start','scheduled_end','status','notes','created_by');
    $vals=array(':t',':b',':no',':j',':vn',"'service'",':ss',':se',"'scheduled'",':notes',':u');
    $params=array(':t'=>$tenant,':b'=>!empty($job['branch_id'])?(int)$job['branch_id']:null,':no'=>$visitNo,':j'=>(int)$job['id'],':vn'=>$number,':ss'=>$date.' '.$start,':se'=>$date.' '.$end,':notes'=>trim(isset($data['instructions'])?(string)$data['instructions']:'')?:null,':u'=>$user);
    foreach(array('title'=>isset($data['title'])?$data['title']:'','instructions'=>isset($data['instructions'])?$data['instructions']:'','schedule_later'=>$scheduleLater,'anytime'=>$anytime,'email_team_about_assignment'=>!empty($data['email_team_about_assignment'])?1:0) as $col=>$value){if(jbCol($pdo,'visits',$col)){$cols[]=$col;$key=':x_'.$col;$vals[]=$key;$params[$key]=$value;}}
    $stmt=$pdo->prepare("INSERT INTO visits(".implode(',',$cols).") VALUES(".implode(',',$vals).")");$stmt->execute($params);$visitId=(int)$pdo->lastInsertId();
    $ids=isset($data['assignee_ids'])&&is_array($data['assignee_ids'])?jbIntList($data['assignee_ids']):array();
    if(!$ids && !empty($job['created_by'])) $ids=array((int)$job['created_by']);
    if($ids && jbTable($pdo,'visit_assignments')){
        $validPh=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare("SELECT id FROM users WHERE tenant_id=? AND status='active' AND deleted_at IS NULL AND id IN ($validPh)");$q->execute(array_merge(array((int)$tenant),$ids));$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        $ins=$pdo->prepare("INSERT INTO visit_assignments(tenant_id,visit_id,user_id,is_primary,status) VALUES(:t,:v,:u,:p,'assigned')");
        foreach($ids as $i=>$uid)$ins->execute(array(':t'=>$tenant,':v'=>$visitId,':u'=>$uid,':p'=>$i===0?1:0));
        if($ids) $pdo->prepare("UPDATE visits SET assigned_user_id=:u WHERE id=:v AND tenant_id=:t")->execute(array(':u'=>$ids[0],':v'=>$visitId,':t'=>$tenant));
    }
    return $visitId;
}

function jvxMailWithCsv($cfg,$password,$to,$subject,$html,$csv,$filename)
{
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Recipient email is invalid.');
    $host=trim($cfg['host']);$port=(int)$cfg['port'];$enc=strtolower(trim($cfg['encryption']));$user=trim((string)$cfg['username']);$from=trim((string)$cfg['from_email']);
    $remote=($enc==='ssl'?'ssl://':'tcp://').$host.':'.$port;$ctx=stream_context_create(array('ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host)));$errno=0;$err='';
    $socket=@stream_socket_client($remote,$errno,$err,20,STREAM_CLIENT_CONNECT,$ctx);if(!$socket)throw new RuntimeException('Unable to connect to SMTP server.');stream_set_timeout($socket,20);
    try{
        jbSmtpCmd($socket,null,array(220),'SMTP greeting');jbSmtpCmd($socket,'EHLO fieldplx.local',array(250),'EHLO');
        if($enc==='tls'||$enc==='starttls'){jbSmtpCmd($socket,'STARTTLS',array(220),'STARTTLS');$method=defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')?STREAM_CRYPTO_METHOD_TLS_CLIENT:STREAM_CRYPTO_METHOD_SSLv23_CLIENT;if(@stream_socket_enable_crypto($socket,true,$method)!==true)throw new RuntimeException('Unable to establish TLS encryption.');jbSmtpCmd($socket,'EHLO fieldplx.local',array(250),'EHLO after TLS');}
        if($user!==''){jbSmtpCmd($socket,'AUTH LOGIN',array(334),'SMTP authentication');jbSmtpCmd($socket,base64_encode($user),array(334),'SMTP username');jbSmtpCmd($socket,base64_encode($password),array(235),'SMTP password');}
        jbSmtpCmd($socket,'MAIL FROM:<'.$from.'>',array(250),'MAIL FROM');jbSmtpCmd($socket,'RCPT TO:<'.$to.'>',array(250,251),'RCPT TO');jbSmtpCmd($socket,'DATA',array(354),'DATA');
        $boundary='=_FieldPlx_'.bin2hex(random_bytes(8));$fromName=trim((string)$cfg['from_name']);
        $headers=array('Date: '.date(DATE_RFC2822),'From: '.($fromName!==''?$fromName:'FieldPlx').' <'.$from.'>','To: <'.$to.'>','Subject: '.str_replace(array("\r","\n"),' ',$subject),'MIME-Version: 1.0','Content-Type: multipart/mixed; boundary="'.$boundary.'"');
        $body='--'.$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n".$html."\r\n";
        $body.='--'.$boundary."\r\nContent-Type: text/csv; name=\"".$filename."\"\r\nContent-Disposition: attachment; filename=\"".$filename."\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($csv))."\r\n--".$boundary."--\r\n";
        $payload=implode("\r\n",$headers)."\r\n\r\n".$body;$payload=preg_replace('/(?m)^\./','..',$payload);@fwrite($socket,$payload."\r\n.\r\n");jbSmtpCmd($socket,null,array(250),'Message delivery');@fwrite($socket,"QUIT\r\n");
    }finally{@fclose($socket);}return true;
}



/* ========================================================================
   Job View v4 helpers - Jobber-style customer edit, booking confirmation,
   visit bulk actions, arrival window and invoice reminders.
   ======================================================================== */
function jvxParseEmailList($raw)
{
    $raw = str_replace(array("\r", "\n", ';'), ',', (string)$raw);
    $out = array();
    foreach (explode(',', $raw) as $item) {
        $email = strtolower(trim((string)$item));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $out[$email] = $email;
        if (count($out) >= 20) break;
    }
    return array_values($out);
}

function jvxEmailUploadAttachments($field)
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return array();
    $f = $_FILES[$field];
    $names = isset($f['name']) ? $f['name'] : array();
    $tmps = isset($f['tmp_name']) ? $f['tmp_name'] : array();
    $sizes = isset($f['size']) ? $f['size'] : array();
    $errors = isset($f['error']) ? $f['error'] : array();
    $types = isset($f['type']) ? $f['type'] : array();
    if (!is_array($names)) {
        $names = array($names); $tmps = array($tmps); $sizes = array($sizes); $errors = array($errors); $types = array($types);
    }
    if (count($names) > 10) throw new RuntimeException('Booking confirmation supports a maximum of 10 attachments.');
    $allowed = array('pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','csv','txt');
    $total = 0;
    $out = array();
    foreach ($names as $i => $original) {
        $err = isset($errors[$i]) ? (int)$errors[$i] : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('One of the email attachments could not be uploaded.');
        $size = isset($sizes[$i]) ? (int)$sizes[$i] : 0;
        $tmp = isset($tmps[$i]) ? (string)$tmps[$i] : '';
        if ($size <= 0 || $tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('One of the email attachments is invalid.');
        $total += $size;
        if ($total > 10 * 1024 * 1024) throw new RuntimeException('Email attachments cannot exceed 10 MB in total.');
        $ext = strtolower(pathinfo((string)$original, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) throw new RuntimeException('Attachment type .' . $ext . ' is not allowed.');
        $mime = isset($types[$i]) ? trim((string)$types[$i]) : '';
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) { $detected = (string)@finfo_file($fi, $tmp); @finfo_close($fi); if ($detected !== '') $mime = $detected; }
        }
        if ($mime === '') $mime = 'application/octet-stream';
        $content = @file_get_contents($tmp);
        if ($content === false) throw new RuntimeException('Unable to read an email attachment.');
        $safe = preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename((string)$original));
        if ($safe === '') $safe = 'attachment.' . $ext;
        $out[] = array('name'=>$safe,'mime'=>$mime,'content'=>$content,'size'=>$size);
    }
    return $out;
}

function jvxSmtpSendAttachments($cfg, $password, $to, $subject, $html, $attachments)
{
    if (!is_array($attachments) || !$attachments) return jbMail($cfg, $password, $to, $subject, $html);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Recipient email is invalid.');
    $host = trim((string)$cfg['host']);
    $port = (int)$cfg['port'];
    $enc = strtolower(trim((string)$cfg['encryption']));
    $user = trim((string)$cfg['username']);
    $from = trim((string)$cfg['from_email']);
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP From Email is invalid.');
    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(array('ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host)));
    $errno = 0; $err = '';
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
        jbSmtpCmd($socket, 'RCPT TO:<' . $to . '>', array(250,251), 'RCPT TO');
        jbSmtpCmd($socket, 'DATA', array(354), 'DATA');
        $boundary = '=_FieldPlx_' . bin2hex(random_bytes(12));
        $fromName = trim((string)$cfg['from_name']);
        $headers = array(
            'Date: ' . date(DATE_RFC2822),
            'From: ' . ($fromName !== '' ? $fromName : 'FieldPlx') . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . str_replace(array("\r","\n"), ' ', substr((string)$subject,0,255)),
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"'
        );
        $body = '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $html . "\r\n";
        foreach ($attachments as $a) {
            $name = str_replace(array('"',"\r","\n"), '_', (string)$a['name']);
            $mime = !empty($a['mime']) ? (string)$a['mime'] : 'application/octet-stream';
            $body .= '--' . $boundary . "\r\nContent-Type: " . $mime . '; name="' . $name . '"' . "\r\nContent-Disposition: attachment; filename=\"" . $name . "\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string)$a['content'])) . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $payload = preg_replace('/(?m)^\./', '..', $payload);
        @fwrite($socket, $payload . "\r\n.\r\n");
        jbSmtpCmd($socket, null, array(250), 'Message delivery');
        @fwrite($socket, "QUIT\r\n");
    } finally {
        @fclose($socket);
    }
    return true;
}

function jvxInvoiceReminders(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo, 'job_invoice_reminders')) return array();
    $stmt = $pdo->prepare("SELECT r.*,TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) assigned_user_name,u.email assigned_email FROM job_invoice_reminders r LEFT JOIN users u ON u.id=r.assigned_user_id AND u.tenant_id=r.tenant_id WHERE r.tenant_id=:t AND r.job_id=:j AND r.status<>'cancelled' ORDER BY COALESCE(r.start_date,'9999-12-31'),COALESCE(r.start_time,'23:59:59'),r.id");
    $stmt->execute(array(':t'=>$tenant, ':j'=>$jobId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jvxRefreshJobVisitRange(PDO $pdo, $tenant, $jobId)
{
    if (!jbTable($pdo,'visits')) return;
    $stmt=$pdo->prepare("SELECT MIN(scheduled_start),MAX(COALESCE(scheduled_end,scheduled_start)) FROM visits WHERE tenant_id=:t AND job_id=:j AND status<>'cancelled' AND scheduled_start IS NOT NULL");
    $stmt->execute(array(':t'=>$tenant,':j'=>$jobId));
    $row=$stmt->fetch(PDO::FETCH_NUM);
    if (!$row || empty($row[0])) return;
    $pdo->prepare("UPDATE jobs SET start_date=:sd,end_date=:ed WHERE id=:j AND tenant_id=:t")->execute(array(':sd'=>substr((string)$row[0],0,10),':ed'=>substr((string)$row[1],0,10),':j'=>$jobId,':t'=>$tenant));
}

$tenant = isset($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : 0;
$user = !empty($_SESSION['tenant_user_id']) ? (int) $_SESSION['tenant_user_id'] : (!empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0);
$sessionBranch = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : 0;
if ($tenant <= 0 || $user <= 0)
    jbRes(401, false, 'Authentication required.');

$csrf = (string) jbP('csrf_token', '');
$sessionCsrf = isset($_SESSION['jobs_csrf_token']) ? (string) $_SESSION['jobs_csrf_token'] : '';
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf))
    jbRes(419, false, 'Your form session expired. Refresh and try again.');
$action = trim((string) jbP('action', ''));

try {
    if ($action === 'meta') {
        jbRes(200, true, 'Job form data loaded.', array('meta' => jbMeta($pdo, $tenant, (int) jbP('job_id', 0))));
    }

    /* Quick-create customer from New Job, same workflow as Add Quotation. */
    if ($action === 'create_customer') {
        if (!jbTable($pdo, 'clients'))
            jbRes(500, false, 'Customers table is not available.');

        $display = substr(trim((string) jbP('display_name', '')), 0, 190);
        $company = substr(trim((string) jbP('company_name', '')), 0, 190);
        $email = strtolower(substr(trim((string) jbP('email', '')), 0, 190));
        $phone = substr(trim((string) jbP('phone', '')), 0, 50);

        if ($display === '')
            jbRes(422, false, 'Customer name is required.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
            jbRes(422, false, 'Enter a valid customer email address.');

        if ($email !== '') {
            $du = $pdo->prepare("SELECT id FROM clients WHERE tenant_id=:t AND email=:e AND deleted_at IS NULL LIMIT 1");
            $du->execute(array(':t' => $tenant, ':e' => $email));
            if ($du->fetchColumn())
                jbRes(409, false, 'This email is already used by another customer.');
        }

        $branch = $sessionBranch > 0 ? $sessionBranch : null;
        $pref = $email !== '' ? 'email' : ($phone !== '' ? 'phone' : 'none');
        $stmt = $pdo->prepare("INSERT INTO clients(tenant_id,branch_id,client_type,display_name,company_name,first_name,last_name,email,phone,alternate_phone,source,preferred_contact_method,allow_email,allow_sms,status,tax_number,notes,account_manager_id,created_by) VALUES(:t,:b,'client',:display,:company,:first,NULL,:email,:phone,NULL,'job',:pref,:ae,:as,'active',NULL,NULL,NULL,:u)");
        $stmt->execute(array(
            ':t' => $tenant,
            ':b' => $branch,
            ':display' => $display,
            ':company' => $company !== '' ? $company : null,
            ':first' => $display,
            ':email' => $email !== '' ? $email : null,
            ':phone' => $phone !== '' ? $phone : null,
            ':pref' => $pref,
            ':ae' => $email !== '' ? 1 : 0,
            ':as' => $phone !== '' ? 1 : 0,
            ':u' => $user
        ));
        $id = (int) $pdo->lastInsertId();
        $client = array(
            'id' => $id,
            'branch_id' => $branch,
            'name' => $display,
            'display_name' => $display,
            'company_name' => $company,
            'email' => $email,
            'phone' => $phone,
            'status' => 'active'
        );
        if (function_exists('tenantAuditLog')) {
            try {
                tenantAuditLog($pdo, 'CLIENT_CREATED_FROM_JOB', $tenant, $branch, $user, 'client', $id, null, $client);
            } catch (Throwable $ae) {
                error_log('job quick customer audit ' . $ae->getMessage());
            }
        }
        jbRes(200, true, 'Customer created successfully.', array('client' => $client));
    }

    /* Create a Jobber-style property/location for the selected customer. */
    if ($action === 'create_location') {
        if (!jbTable($pdo, 'client_locations'))
            jbRes(500, false, 'Customer locations table is not available.');

        $clientId = (int) jbP('client_id', 0);
        $clientStmt = $pdo->prepare("SELECT id,branch_id FROM clients WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL AND status<>'archived' LIMIT 1");
        $clientStmt->execute(array(':id' => $clientId, ':t' => $tenant));
        $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
        if (!$client)
            jbRes(422, false, 'Select a valid customer first.');

        $type = strtolower(trim((string) jbP('location_type', 'site')));
        $allowedTypes = array('home', 'office', 'warehouse', 'factory', 'farm', 'shop', 'site', 'other');
        if (!in_array($type, $allowedTypes, true))
            $type = 'other';

        $name = substr(trim((string) jbP('name', '')), 0, 190);
        $a1 = substr(trim((string) jbP('address_line1', '')), 0, 255);
        $a2 = substr(trim((string) jbP('address_line2', '')), 0, 255);
        $city = substr(trim((string) jbP('city', '')), 0, 120);
        $state = substr(trim((string) jbP('state', '')), 0, 120);
        $postal = substr(trim((string) jbP('postal_code', '')), 0, 40);
        $countryId = max(0, (int)jbP('country_id', 0));
        $taxRateId = max(0, (int)jbP('tax_rate_id', 0));

        if ($name === '')
            jbRes(422, false, 'Property name is required.');
        if ($a1 === '')
            jbRes(422, false, 'Street 1 is required.');

        if ($countryId > 0) {
            if (!jbTable($pdo, 'countries'))
                jbRes(422, false, 'Country master is unavailable.');
            $checkCountry = $pdo->prepare("SELECT id FROM countries WHERE id=:id AND is_active=1 LIMIT 1");
            $checkCountry->execute(array(':id' => $countryId));
            if (!$checkCountry->fetchColumn())
                jbRes(422, false, 'Select a valid country.');
        }

        if ($taxRateId > 0) {
            if (!jbTable($pdo, 'product_tax_rates'))
                jbRes(422, false, 'Tax rate master is unavailable.');
            $checkTax = $pdo->prepare("SELECT id FROM product_tax_rates WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");
            $checkTax->execute(array(':id' => $taxRateId, ':t' => $tenant));
            if (!$checkTax->fetchColumn())
                jbRes(422, false, 'Select a valid tax rate.');
        }

        $customValues = json_decode(trim((string)jbP('custom_values_json', '')), true);
        if (!is_array($customValues))
            $customValues = array();
        $contacts = json_decode(trim((string)jbP('contacts_json', '')), true);
        if (!is_array($contacts))
            $contacts = array();
        if (count($contacts) > 20)
            jbRes(422, false, 'A property can contain a maximum of 20 contacts.');

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM client_locations WHERE tenant_id=:t AND client_id=:c AND deleted_at IS NULL");
        $cnt->execute(array(':t' => $tenant, ':c' => $clientId));
        $primary = ((int) $cnt->fetchColumn() === 0 || (int) jbP('is_primary', 0) === 1) ? 1 : 0;

        $pdo->beginTransaction();
        try {
            if ($primary === 1) {
                $pdo->prepare("UPDATE client_locations SET is_primary=0 WHERE tenant_id=:t AND client_id=:c AND deleted_at IS NULL")
                    ->execute(array(':t' => $tenant, ':c' => $clientId));
            }

            $columns = array('tenant_id','client_id','location_type','name','address_line1','address_line2','city','state','postal_code');
            $values = array(':t',':c',':type',':name',':a1',':a2',':city',':state',':postal');
            $params = array(
                ':t' => $tenant, ':c' => $clientId, ':type' => $type, ':name' => $name, ':a1' => $a1,
                ':a2' => $a2 !== '' ? $a2 : null, ':city' => $city !== '' ? $city : null,
                ':state' => $state !== '' ? $state : null, ':postal' => $postal !== '' ? $postal : null
            );
            if (jbCol($pdo, 'client_locations', 'country_id')) {
                $columns[] = 'country_id'; $values[] = ':country'; $params[':country'] = $countryId > 0 ? $countryId : null;
            }
            if (jbCol($pdo, 'client_locations', 'tax_rate_id')) {
                $columns[] = 'tax_rate_id'; $values[] = ':taxrate'; $params[':taxrate'] = $taxRateId > 0 ? $taxRateId : null;
            }
            if (jbCol($pdo, 'client_locations', 'billing_same_as_property')) {
                $columns[] = 'billing_same_as_property'; $values[] = '1';
            }
            $columns[] = 'is_primary'; $values[] = ':primary'; $params[':primary'] = $primary;
            $columns[] = 'status'; $values[] = "'active'";

            $stmt = $pdo->prepare("INSERT INTO client_locations(" . implode(',', $columns) . ") VALUES(" . implode(',', $values) . ")");
            $stmt->execute($params);
            $id = (int) $pdo->lastInsertId();

            /* Save values for existing property custom fields. */
            if ($customValues && jbTable($pdo, 'client_location_custom_field_values') && jbTable($pdo, 'client_custom_field_definitions')) {
                $fieldCheck = $pdo->prepare("SELECT id FROM client_custom_field_definitions WHERE id=:id AND tenant_id=:t AND applies_to='location' AND status='active' LIMIT 1");
                $fieldInsert = $pdo->prepare("INSERT INTO client_location_custom_field_values(tenant_id,location_id,field_id,field_value) VALUES(:t,:l,:f,:v)");
                foreach ($customValues as $fieldIdRaw => $fieldValueRaw) {
                    $fieldId = (int)$fieldIdRaw;
                    $fieldValue = trim((string)$fieldValueRaw);
                    if ($fieldId <= 0 || $fieldValue === '')
                        continue;
                    $fieldCheck->execute(array(':id' => $fieldId, ':t' => $tenant));
                    if (!$fieldCheck->fetchColumn())
                        continue;
                    $fieldInsert->execute(array(':t' => $tenant, ':l' => $id, ':f' => $fieldId, ':v' => substr($fieldValue, 0, 4000)));
                }
            }

            /* Save contacts limited to this property. */
            if ($contacts && jbTable($pdo, 'client_contacts') && jbCol($pdo, 'client_contacts', 'location_id')) {
                foreach ($contacts as $contactIndex => $contact) {
                    if (!is_array($contact))
                        continue;
                    $first = substr(trim(isset($contact['first_name']) ? (string)$contact['first_name'] : ''), 0, 120);
                    if ($first === '')
                        jbRes(422, false, 'Property contact ' . ($contactIndex + 1) . ': first name is required.');
                    $email = strtolower(substr(trim(isset($contact['email']) ? (string)$contact['email'] : ''), 0, 190));
                    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
                        jbRes(422, false, 'Property contact ' . ($contactIndex + 1) . ': enter a valid email address.');

                    $contactCols = array('tenant_id','client_id','location_id','first_name');
                    $contactVals = array(':t',':c',':l',':first');
                    $contactParams = array(':t'=>$tenant, ':c'=>$clientId, ':l'=>$id, ':first'=>$first);
                    $optional = array(
                        'title_prefix' => substr(trim(isset($contact['title_prefix']) ? (string)$contact['title_prefix'] : ''),0,20),
                        'last_name' => substr(trim(isset($contact['last_name']) ? (string)$contact['last_name'] : ''),0,120),
                        'role_name' => substr(trim(isset($contact['role_name']) ? (string)$contact['role_name'] : ''),0,120),
                        'email' => $email,
                        'phone' => substr(trim(isset($contact['phone']) ? (string)$contact['phone'] : ''),0,50)
                    );
                    foreach ($optional as $col => $val) {
                        if (jbCol($pdo, 'client_contacts', $col)) {
                            $key = ':' . $col;
                            $contactCols[] = $col; $contactVals[] = $key; $contactParams[$key] = $val !== '' ? $val : null;
                        }
                    }
                    $bools = array('is_billing_contact','portal_access','quote_followups','invoice_followups','visit_reminders','job_close_followups');
                    foreach ($bools as $col) {
                        if (jbCol($pdo, 'client_contacts', $col)) {
                            $key = ':' . $col;
                            $contactCols[] = $col; $contactVals[] = $key; $contactParams[$key] = !empty($contact[$col]) ? 1 : 0;
                        }
                    }
                    if (jbCol($pdo, 'client_contacts', 'is_primary')) {
                        $contactCols[] = 'is_primary'; $contactVals[] = '0';
                    }
                    $insContact = $pdo->prepare("INSERT INTO client_contacts(" . implode(',', $contactCols) . ") VALUES(" . implode(',', $contactVals) . ")");
                    $insContact->execute($contactParams);
                    $contactId = (int)$pdo->lastInsertId();
                    if (!empty($contact['portal_access']) && jbTable($pdo, 'client_portal_users') && $contactId > 0 && ($email !== '' || !empty($contactParams[':phone']))) {
                        $portalPhone = isset($contactParams[':phone']) ? trim((string)$contactParams[':phone']) : '';
                        if ($email !== '') {
                            $portalCheck = $pdo->prepare("SELECT id FROM client_portal_users WHERE tenant_id=:t AND email=:e LIMIT 1");
                            $portalCheck->execute(array(':t'=>$tenant, ':e'=>$email));
                            if ($portalCheck->fetchColumn())
                                throw new RuntimeException('A property contact email is already used for another client portal login.');
                        }
                        if ($portalPhone !== '' && jbCol($pdo, 'client_portal_users', 'phone')) {
                            $portalCheck = $pdo->prepare("SELECT id FROM client_portal_users WHERE tenant_id=:t AND phone=:p LIMIT 1");
                            $portalCheck->execute(array(':t'=>$tenant, ':p'=>$portalPhone));
                            if ($portalCheck->fetchColumn())
                                throw new RuntimeException('A property contact phone number is already used for another client portal login.');
                        }
                        $portalCols = array('tenant_id','client_id','contact_id','email','password_hash','status');
                        $portalVals = array(':t',':c',':contact',':email','NULL',"'invited'");
                        $portalParams = array(':t'=>$tenant, ':c'=>$clientId, ':contact'=>$contactId, ':email'=>$email !== '' ? $email : null);
                        if (jbCol($pdo, 'client_portal_users', 'phone')) {
                            $portalCols[]='phone'; $portalVals[]=':phone'; $portalParams[':phone']=$portalPhone !== '' ? $portalPhone : null;
                        }
                        $portalIns=$pdo->prepare("INSERT INTO client_portal_users(".implode(',',$portalCols).") VALUES(".implode(',',$portalVals).")");
                        $portalIns->execute($portalParams);
                    }
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            throw $e;
        }

        $location = array(
            'id' => $id, 'client_id' => $clientId, 'location_type' => $type, 'name' => $name,
            'address_line1' => $a1, 'address_line2' => $a2, 'city' => $city, 'state' => $state,
            'postal_code' => $postal, 'country_id' => $countryId > 0 ? $countryId : null,
            'tax_rate_id' => $taxRateId > 0 ? $taxRateId : null, 'is_primary' => $primary, 'status' => 'active'
        );
        if (function_exists('tenantAuditLog')) {
            try {
                tenantAuditLog($pdo, 'CLIENT_LOCATION_CREATED_FROM_JOB', $tenant, !empty($client['branch_id']) ? (int) $client['branch_id'] : $sessionBranch, $user, 'client_location', $id, null, $location);
            } catch (Throwable $ae) {
                error_log('job quick location audit ' . $ae->getMessage());
            }
        }
        jbRes(200, true, 'Property created successfully.', array('location' => $location));
    }

    /* Create a tax rate from the Job form, matching Add Quotation behavior. */
    if ($action === 'create_tax_rate') {
        if (!jbTable($pdo, 'product_tax_rates'))
            jbRes(500, false, 'Tax rate master is not available.');

        $name = substr(trim((string)jbP('name', '')), 0, 120);
        $rate = (float)jbP('rate_percent', 0);
        $description = substr(trim((string)jbP('description', '')), 0, 190);
        $isDefault = (int)jbP('is_default', 0) === 1 ? 1 : 0;
        if ($name === '')
            jbRes(422, false, 'Tax rate name is required.');
        if ($rate < 0 || $rate > 100)
            jbRes(422, false, 'Tax rate must be between 0 and 100.');

        $dup = $pdo->prepare("SELECT id FROM product_tax_rates WHERE tenant_id=:t AND LOWER(tax_name)=LOWER(:n) AND status='active' LIMIT 1");
        $dup->execute(array(':t'=>$tenant, ':n'=>$name));
        if ($dup->fetchColumn())
            jbRes(409, false, 'A tax rate with this name already exists.');

        $countryCode = 'US';
        if (jbTable($pdo, 'countries') && jbTable($pdo, 'tenants') && jbCol($pdo, 'tenants', 'country_id')) {
            $countryStmt = $pdo->prepare("SELECT c.iso2 FROM tenants t LEFT JOIN countries c ON c.id=t.country_id WHERE t.id=:t LIMIT 1");
            $countryStmt->execute(array(':t'=>$tenant));
            $candidate = strtoupper(trim((string)$countryStmt->fetchColumn()));
            if (preg_match('/^[A-Z]{2}$/', $candidate))
                $countryCode = $candidate;
        }

        $pdo->beginTransaction();
        try {
            $hasDefault = jbCol($pdo, 'product_tax_rates', 'is_default');
            if ($isDefault && $hasDefault)
                $pdo->prepare("UPDATE product_tax_rates SET is_default=0 WHERE tenant_id=:t")->execute(array(':t'=>$tenant));

            $cols = array('tenant_id','tax_name','rate_percent','country_code','state_code','state_name','tax_type','jurisdiction_name','status');
            $vals = array(':t',':n',':r',':cc',"''",'NULL',"'other'",':desc',"'active'");
            $params = array(':t'=>$tenant, ':n'=>$name, ':r'=>$rate, ':cc'=>$countryCode, ':desc'=>$description !== '' ? $description : null);
            if ($hasDefault) {
                $cols[]='is_default'; $vals[]=':def'; $params[':def']=$isDefault;
            }
            if (jbCol($pdo, 'product_tax_rates', 'created_by')) {
                $cols[]='created_by'; $vals[]=':u'; $params[':u']=$user;
            }
            $stmt=$pdo->prepare("INSERT INTO product_tax_rates(".implode(',',$cols).") VALUES(".implode(',',$vals).")");
            $stmt->execute($params);
            $taxId=(int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $sel = "SELECT id,tax_name,rate_percent,jurisdiction_name,status," . (jbCol($pdo,'product_tax_rates','is_default') ? 'is_default' : '0 AS is_default') . " FROM product_tax_rates WHERE id=:id AND tenant_id=:t LIMIT 1";
        $stmt=$pdo->prepare($sel);$stmt->execute(array(':id'=>$taxId,':t'=>$tenant));
        jbRes(200, true, 'Tax rate created successfully.', array('tax_rate'=>$stmt->fetch(PDO::FETCH_ASSOC)));
    }

    /* Create a custom field that applies to all properties. */
    if ($action === 'create_location_custom_field') {
        if (!jbTable($pdo, 'client_custom_field_definitions'))
            jbRes(500, false, 'Property custom fields are not installed.');

        $name = substr(trim((string)jbP('field_name', '')), 0, 190);
        $type = strtolower(trim((string)jbP('field_type', 'text')));
        $allowed = array('text','numeric','boolean','area','dropdown');
        if (!in_array($type, $allowed, true)) $type='text';
        $transferable = (int)jbP('is_transferable',0)===1 ? 1 : 0;
        $defaultValue = trim((string)jbP('default_value',''));
        $options = json_decode(trim((string)jbP('options_json','')), true);
        if (!is_array($options)) $options=array();
        $cleanOptions=array();
        foreach ($options as $option) {
            $option=substr(trim((string)$option),0,190);
            if ($option!=='') $cleanOptions[]=$option;
        }
        if ($name==='') jbRes(422,false,'Custom field name is required.');
        if ($type==='dropdown' && !$cleanOptions) jbRes(422,false,'Add at least one dropdown option.');

        $dup=$pdo->prepare("SELECT id FROM client_custom_field_definitions WHERE tenant_id=:t AND applies_to='location' AND LOWER(field_name)=LOWER(:n) AND status='active' LIMIT 1");
        $dup->execute(array(':t'=>$tenant,':n'=>$name));
        if ($dup->fetchColumn()) jbRes(409,false,'A property custom field with this name already exists.');

        $max=$pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM client_custom_field_definitions WHERE tenant_id=:t AND applies_to='location'");
        $max->execute(array(':t'=>$tenant));
        $sort=(int)$max->fetchColumn()+100;
        $stmt=$pdo->prepare("INSERT INTO client_custom_field_definitions(tenant_id,applies_to,field_name,field_type,is_transferable,default_value,options_json,status,sort_order,created_by) VALUES(:t,'location',:n,:ft,:tr,:dv,:opts,'active',:so,:u)");
        $stmt->execute(array(':t'=>$tenant,':n'=>$name,':ft'=>$type,':tr'=>$transferable,':dv'=>$defaultValue!==''?$defaultValue:null,':opts'=>$cleanOptions?json_encode($cleanOptions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,':so'=>$sort,':u'=>$user));
        $fieldId=(int)$pdo->lastInsertId();
        jbRes(200,true,'Property custom field created successfully.',array('custom_field'=>array('id'=>$fieldId,'applies_to'=>'location','field_name'=>$name,'field_type'=>$type,'is_transferable'=>$transferable,'default_value'=>$defaultValue,'options'=>$cleanOptions,'sort_order'=>$sort)));
    }

    /* Quick-create Product / Service from a job line. */
    if ($action === 'create_catalog_item') {
        $itemType = strtolower(trim((string) jbP('item_type', 'service')));
        if (!in_array($itemType, array('service', 'product'), true))
            jbRes(422, false, 'Select Service or Product.');

        $name = substr(trim((string) jbP('name', '')), 0, 190);
        $description = trim((string) jbP('description', ''));
        $unitCost = max(0, (float) jbP('unit_cost', 0));
        $markupPercent = max(0, (float) jbP('markup_percent', 0));
        $unitPriceRaw = trim((string) jbP('unit_price', ''));
        $unitPrice = $unitPriceRaw === '' ? round($unitCost * (1 + ($markupPercent / 100)), 2) : max(0, (float) $unitPriceRaw);
        $exemptTax = (int) jbP('exempt_tax', 0) === 1;
        $taxPercent = $exemptTax ? 0.0 : max(0, (float) jbP('tax_percent', 0));
        $taxRateId = $exemptTax ? 0 : max(0, (int) jbP('tax_rate_id', 0));
        if ($name === '')
            jbRes(422, false, 'Product / Service name is required.');

        $hasUploadedImage = !empty($_FILES['item_image']) && is_array($_FILES['item_image']) && isset($_FILES['item_image']['error']) && (int) $_FILES['item_image']['error'] !== UPLOAD_ERR_NO_FILE;

        if ($itemType === 'service') {
            $serviceHasImage = jbCol($pdo, 'product_services', 'image_path');
            $serviceImageSelect = $serviceHasImage ? ',image_path' : ',NULL AS image_path';
            $find = $pdo->prepare("SELECT id,item_type,name,sku,description,unit_name,unit_cost,unit_price,tax_percent" . $serviceImageSelect . " FROM product_services WHERE tenant_id=:t AND item_type='service' AND LOWER(name)=LOWER(:n) AND deleted_at IS NULL AND status='active' LIMIT 1");
            $find->execute(array(':t' => $tenant, ':n' => $name));
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $existing['catalog_key'] = 'ps:' . (int) $existing['id'];
                $existing['source_type'] = 'product_service';
                $existing['source_label'] = 'Service';
                $existing['product_service_id'] = (int) $existing['id'];
                $existing['product_id'] = null;
                jbRes(200, true, 'Existing service selected.', array('created' => 0, 'item_kind' => 'service', 'item' => $existing));
            }
            if ($hasUploadedImage && !$serviceHasImage)
                jbRes(500, false, 'Service image storage is not available.');

            $image = array('relative' => null, 'absolute' => null);
            try {
                if ($hasUploadedImage)
                    $image = jbStoreCatalogImage('item_image', $tenant, 'service');
                if ($serviceHasImage) {
                    $stmt = $pdo->prepare("INSERT INTO product_services(tenant_id,category_id,item_type,name,sku,image_path,description,unit_name,unit_cost,unit_price,tax_percent,is_bookable,estimated_duration_minutes,status,created_at,updated_at,deleted_at) VALUES(:t,NULL,'service',:n,NULL,:img,:d,'Service',:cost,:price,:tax,0,NULL,'active',NOW(),NOW(),NULL)");
                    $stmt->execute(array(':t' => $tenant, ':n' => $name, ':img' => $image['relative'], ':d' => $description !== '' ? $description : null, ':cost' => $unitCost, ':price' => $unitPrice, ':tax' => $taxPercent));
                } else {
                    $stmt = $pdo->prepare("INSERT INTO product_services(tenant_id,category_id,item_type,name,sku,description,unit_name,unit_cost,unit_price,tax_percent,is_bookable,estimated_duration_minutes,status,created_at,updated_at,deleted_at) VALUES(:t,NULL,'service',:n,NULL,:d,'Service',:cost,:price,:tax,0,NULL,'active',NOW(),NOW(),NULL)");
                    $stmt->execute(array(':t' => $tenant, ':n' => $name, ':d' => $description !== '' ? $description : null, ':cost' => $unitCost, ':price' => $unitPrice, ':tax' => $taxPercent));
                }
            } catch (Throwable $e) {
                if (!empty($image['absolute']) && is_file($image['absolute']))
                    @unlink($image['absolute']);
                throw $e;
            }

            $newId = (int) $pdo->lastInsertId();
            $item = array('id' => $newId, 'item_type' => 'service', 'name' => $name, 'sku' => '', 'image_path' => $image['relative'], 'description' => $description, 'unit_name' => 'Service', 'unit_cost' => $unitCost, 'unit_price' => $unitPrice, 'tax_percent' => $taxPercent, 'catalog_key' => 'ps:' . $newId, 'source_type' => 'product_service', 'source_label' => 'Service', 'product_service_id' => $newId, 'product_id' => null);
            if (function_exists('tenantAuditLog')) {
                try { tenantAuditLog($pdo, 'SERVICE_CREATED', $tenant, $sessionBranch, $user, 'service', $newId, null, $item); }
                catch (Throwable $ae) { error_log('job catalog service audit ' . $ae->getMessage()); }
            }
            jbRes(200, true, 'Service created successfully.', array('created' => 1, 'item_kind' => 'service', 'item' => $item));
        }

        if (!jbTable($pdo, 'products'))
            jbRes(500, false, 'Products table is not available.');

        $deletedFilter = jbCol($pdo, 'products', 'deleted_at') ? " AND deleted_at IS NULL" : "";
        $productHasImage = jbCol($pdo, 'products', 'image_path');
        $productImageSelect = $productHasImage ? ',image_path' : ',NULL AS image_path';
        $find = $pdo->prepare("SELECT id,sku,name,description,unit_name,base_unit_price,selling_price,tax_percent" . $productImageSelect . " FROM products WHERE tenant_id=:t AND LOWER(name)=LOWER(:n) AND status='active'" . $deletedFilter . " LIMIT 1");
        $find->execute(array(':t' => $tenant, ':n' => $name));
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $existing['catalog_key'] = 'product:' . (int) $existing['id'];
            $existing['source_type'] = 'product';
            $existing['source_label'] = 'Product';
            $existing['product_service_id'] = null;
            $existing['product_id'] = (int) $existing['id'];
            $existing['unit_cost'] = isset($existing['base_unit_price']) ? (float) $existing['base_unit_price'] : 0;
            $existing['unit_price'] = isset($existing['selling_price']) ? (float) $existing['selling_price'] : 0;
            jbRes(200, true, 'Existing product selected.', array('created' => 0, 'item_kind' => 'product', 'item' => $existing));
        }
        if ($hasUploadedImage && !$productHasImage)
            jbRes(500, false, 'Product image storage is not available.');

        $image = array('relative' => null, 'absolute' => null);
        try {
            if ($hasUploadedImage)
                $image = jbStoreCatalogImage('item_image', $tenant, 'product');
            if ($productHasImage) {
                $stmt = $pdo->prepare("INSERT INTO products(tenant_id,category_id,sku,name,image_path,description,unit_of_measure_id,unit_name,base_unit_price,markup_type,markup_value,selling_price,tax_id,tax_percent,track_inventory,status,created_by,created_at,updated_at,deleted_at) VALUES(:t,NULL,NULL,:n,:img,:d,NULL,'Unit',:cost,'percentage',:markup,:price,:taxid,:tax,0,'active',:u,NOW(),NOW(),NULL)");
                $stmt->execute(array(':t' => $tenant, ':n' => $name, ':img' => $image['relative'], ':d' => $description !== '' ? $description : null, ':cost' => $unitCost, ':markup' => $markupPercent, ':price' => $unitPrice, ':taxid' => $taxRateId > 0 ? $taxRateId : null, ':tax' => $taxPercent, ':u' => $user));
            } else {
                $stmt = $pdo->prepare("INSERT INTO products(tenant_id,category_id,sku,name,description,unit_of_measure_id,unit_name,base_unit_price,markup_type,markup_value,selling_price,tax_id,tax_percent,track_inventory,status,created_by,created_at,updated_at,deleted_at) VALUES(:t,NULL,NULL,:n,:d,NULL,'Unit',:cost,'percentage',:markup,:price,:taxid,:tax,0,'active',:u,NOW(),NOW(),NULL)");
                $stmt->execute(array(':t' => $tenant, ':n' => $name, ':d' => $description !== '' ? $description : null, ':cost' => $unitCost, ':markup' => $markupPercent, ':price' => $unitPrice, ':taxid' => $taxRateId > 0 ? $taxRateId : null, ':tax' => $taxPercent, ':u' => $user));
            }
        } catch (Throwable $e) {
            if (!empty($image['absolute']) && is_file($image['absolute']))
                @unlink($image['absolute']);
            throw $e;
        }

        $newId = (int) $pdo->lastInsertId();
        $item = array('id' => $newId, 'name' => $name, 'sku' => '', 'image_path' => $image['relative'], 'description' => $description, 'unit_name' => 'Unit', 'base_unit_price' => $unitCost, 'selling_price' => $unitPrice, 'unit_cost' => $unitCost, 'unit_price' => $unitPrice, 'tax_id' => $taxRateId > 0 ? $taxRateId : null, 'tax_percent' => $taxPercent, 'catalog_key' => 'product:' . $newId, 'source_type' => 'product', 'source_label' => 'Product', 'product_service_id' => null, 'product_id' => $newId);
        if (function_exists('tenantAuditLog')) {
            try { tenantAuditLog($pdo, 'PRODUCT_CREATED', $tenant, $sessionBranch, $user, 'product', $newId, null, $item); }
            catch (Throwable $ae) { error_log('job catalog product audit ' . $ae->getMessage()); }
        }
        jbRes(200, true, 'Product created successfully.', array('created' => 1, 'item_kind' => 'product', 'item' => $item));
    }

    if ($action === 'quote_details') {
        $id = (int) jbP('quote_id', 0);
        jbRes(200, true, 'Approved quotation loaded.', array(
            'quotation' => jbQuote($pdo, $tenant, $id, (int) jbP('job_id', 0)),
            'line_items' => jbQuoteLineItems($pdo, $tenant, $id),
            'currency' => jbCurrency($pdo, $tenant)
        ));
    }

    if ($action === 'request_details') {
        $id = (int) jbP('request_id', 0);
        jbRes(200, true, 'Service request loaded.', array(
            'request' => jbRequestContext($pdo, $tenant, $id, (int) jbP('job_id', 0)),
            'line_items' => jbRequestLineItems($pdo, $tenant, $id),
            'currency' => jbCurrency($pdo, $tenant)
        ));
    }

    if ($action === 'get') {
        $id = (int) jbP('job_id', 0);
        $job = jbJobDetails($pdo, $tenant, $id);
        jbRes(200, true, 'Job loaded.', array(
            'job' => $job,
            'assignments' => jbAssignments($pdo, $tenant, $id),
            'schedules' => jbJobSchedules($pdo, $tenant, $id),
            'billing' => jbJobBilling($pdo, $tenant, $id),
            'attachments' => jbJobAttachments($pdo, $tenant, $id),
            'line_items' => jbJobLineItems($pdo, $tenant, $id),
            'internal_note' => jbJobInternalNote($pdo, $tenant, $id),
            'note_mention_ids' => jbJobNoteMentionIds($pdo, $tenant, $id),
            'custom_fields' => jbJobCustomFields($pdo, $tenant, $id),
            'checklist_template_ids' => jbJobChecklistTemplateIds($pdo, $tenant, $id),
            'checklists' => jvxRichChecklists($pdo, $tenant, $id),
            'location' => jvxJobLocation($pdo, $tenant, $job),
            'visits' => jvxJobVisits($pdo, $tenant, $id),
            'time_entries' => jvxTimeEntries($pdo, $tenant, $id),
            'expenses' => jvxExpenses($pdo, $tenant, $id),
            'invoices' => jvxInvoices($pdo, $tenant, $id),
            'history' => jvxHistory($pdo, $tenant, $id),
            'invoice_reminders' => jvxInvoiceReminders($pdo, $tenant, $id),
            'cost_summary' => jvxCostSummary($pdo, $tenant, $id, $job),
            'meta' => jbMeta($pdo, $tenant, $id),
            'currency' => jbCurrency($pdo, $tenant)
        ));
    }



    if ($action === 'update_view_customer') {
        $id = (int)jbP('job_id',0);
        $job = jbJob($pdo,$tenant,$id);
        if (!empty($job['quote_id']) || !empty($job['request_id'])) jbRes(422,false,'Customer and property are locked because this job was created from a quotation or service request.');
        if (jbTable($pdo,'invoices')) {
            $q=$pdo->prepare("SELECT COUNT(*) FROM invoices WHERE tenant_id=:t AND job_id=:j" . (jbCol($pdo,'invoices','archived_at') ? " AND archived_at IS NULL" : ""));
            $q->execute(array(':t'=>$tenant,':j'=>$id));
            if ((int)$q->fetchColumn()>0) jbRes(409,false,'Customer cannot be changed after an invoice has been created for this job.');
        }
        $clientId=(int)jbP('client_id',0);
        if($clientId<=0)jbRes(422,false,'Select a customer.');
        $q=$pdo->prepare("SELECT id,branch_id,display_name FROM clients WHERE id=:c AND tenant_id=:t AND deleted_at IS NULL AND status<>'archived' LIMIT 1");
        $q->execute(array(':c'=>$clientId,':t'=>$tenant));$client=$q->fetch(PDO::FETCH_ASSOC);
        if(!$client)jbRes(422,false,'Select a valid customer.');
        $locationId=(int)jbP('location_id',0);
        if($locationId>0){$q=$pdo->prepare("SELECT id FROM client_locations WHERE id=:l AND tenant_id=:t AND client_id=:c AND status='active' AND deleted_at IS NULL LIMIT 1");$q->execute(array(':l'=>$locationId,':t'=>$tenant,':c'=>$clientId));if(!$q->fetchColumn())jbRes(422,false,'Select a valid property for this customer.');}
        $branch = !empty($client['branch_id']) ? (int)$client['branch_id'] : (!empty($job['branch_id']) ? (int)$job['branch_id'] : ($sessionBranch>0?$sessionBranch:null));
        $pdo->prepare("UPDATE jobs SET client_id=:c,location_id=:l,branch_id=:b WHERE id=:j AND tenant_id=:t")->execute(array(':c'=>$clientId,':l'=>$locationId>0?$locationId:null,':b'=>$branch,':j'=>$id,':t'=>$tenant));
        if(jbTable($pdo,'expenses') && jbCol($pdo,'expenses','client_id'))$pdo->prepare("UPDATE expenses SET client_id=:c WHERE tenant_id=:t AND job_id=:j")->execute(array(':c'=>$clientId,':t'=>$tenant,':j'=>$id));
        jbActivity($pdo,$tenant,$branch?$branch:$sessionBranch,$user,'job_customer_updated',$id,$clientId,'Job customer / property updated: '.$job['job_no'],array('old_client_id'=>(int)$job['client_id'],'new_client_id'=>$clientId,'location_id'=>$locationId>0?$locationId:null));
        jbRes(200,true,'Customer and property updated successfully.');
    }

    if ($action === 'save_arrival_window') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);
        if(!jbCol($pdo,'jobs','arrival_window_minutes'))jbRes(500,false,'Run migration_job_view_jobber_v4.sql first to enable arrival windows.');
        $minutes=(int)jbP('arrival_window_minutes',0);$allowed=array(0,15,30,60,120,180,240);if(!in_array($minutes,$allowed,true))jbRes(422,false,'Select a valid arrival window.');
        $pdo->prepare("UPDATE jobs SET arrival_window_minutes=:m WHERE id=:j AND tenant_id=:t")->execute(array(':m'=>$minutes,':j'=>$id,':t'=>$tenant));
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_arrival_window_updated',$id,(int)$job['client_id'],'Arrival window updated: '.$job['job_no'],array('minutes'=>$minutes));
        jbRes(200,true,'Arrival window saved successfully.');
    }

    if ($action === 'move_visits') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);if(!jbTable($pdo,'visits'))jbRes(500,false,'Visits table is not installed.');
        $moves=json_decode((string)jbP('moves_json','[]'),true);if(!is_array($moves)||!$moves)jbRes(422,false,'Select at least one visit to move.');if(count($moves)>100)jbRes(422,false,'A maximum of 100 visits can be moved at once.');
        $select=$pdo->prepare("SELECT id,scheduled_start,scheduled_end FROM visits WHERE id=:v AND tenant_id=:t AND job_id=:j AND status<>'cancelled' LIMIT 1");
        $update=$pdo->prepare("UPDATE visits SET scheduled_start=:s,scheduled_end=:e WHERE id=:v AND tenant_id=:t AND job_id=:j");$count=0;
        $pdo->beginTransaction();
        try{
            foreach($moves as $m){$vid=isset($m['visit_id'])?(int)$m['visit_id']:0;$date=jbDate(isset($m['date'])?$m['date']:'');if($vid<=0||$date===false||$date===null)throw new RuntimeException('Every selected visit needs a valid updated date.');$select->execute(array(':v'=>$vid,':t'=>$tenant,':j'=>$id));$v=$select->fetch(PDO::FETCH_ASSOC);if(!$v)throw new RuntimeException('One selected visit is no longer available.');
                if(!empty($v['scheduled_start'])){$oldStart=new DateTime($v['scheduled_start']);$newStart=new DateTime($date.' '.$oldStart->format('H:i:s'));$delta=$newStart->getTimestamp()-$oldStart->getTimestamp();$newEnd=null;if(!empty($v['scheduled_end'])){$oldEnd=new DateTime($v['scheduled_end']);$oldEnd->setTimestamp($oldEnd->getTimestamp()+$delta);$newEnd=$oldEnd->format('Y-m-d H:i:s');}$update->execute(array(':s'=>$newStart->format('Y-m-d H:i:s'),':e'=>$newEnd,':v'=>$vid,':t'=>$tenant,':j'=>$id));$count++;}
            }
            jvxRefreshJobVisitRange($pdo,$tenant,$id);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_visits_moved',$id,(int)$job['client_id'],'Visit dates moved: '.$job['job_no'],array('count'=>$count));
        jbRes(200,true,$count.' visit date(s) updated successfully.');
    }

    if ($action === 'delete_visits') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);if(!jbTable($pdo,'visits'))jbRes(500,false,'Visits table is not installed.');
        $ids=json_decode((string)jbP('visit_ids_json','[]'),true);if(!is_array($ids))$ids=array();$clean=array();foreach($ids as $v){$v=(int)$v;if($v>0)$clean[$v]=$v;}$clean=array_values($clean);if(!$clean)jbRes(422,false,'Select at least one visit.');if(count($clean)>100)jbRes(422,false,'A maximum of 100 visits can be deleted at once.');
        $ph=implode(',',array_fill(0,count($clean),'?'));$params=array_merge(array((int)$tenant,(int)$id),$clean);$stmt=$pdo->prepare("UPDATE visits SET status='cancelled' WHERE tenant_id=? AND job_id=? AND id IN ($ph) AND status<>'cancelled'");$stmt->execute($params);$count=(int)$stmt->rowCount();jvxRefreshJobVisitRange($pdo,$tenant,$id);
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_visits_deleted',$id,(int)$job['client_id'],'Visits deleted: '.$job['job_no'],array('count'=>$count,'visit_ids'=>$clean));
        jbRes(200,true,$count.' visit(s) removed successfully.');
    }

    if ($action === 'add_invoice_reminder') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);if(!jbTable($pdo,'job_invoice_reminders'))jbRes(500,false,'Run migration_job_view_jobber_v4.sql first to enable invoice reminders.');
        $description=substr(trim((string)jbP('description','')),0,2000);if($description==='')$description='Invoice reminder';
        $later=!empty($_POST['schedule_later'])?1:0;$anytime=!empty($_POST['anytime'])?1:0;$emailTeam=!empty($_POST['email_team_when_assigned'])?1:0;$uid=(int)jbP('assigned_user_id',0);
        if($uid>0){$q=$pdo->prepare("SELECT id,email,TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) name FROM users WHERE id=:u AND tenant_id=:t AND status='active' AND deleted_at IS NULL LIMIT 1");$q->execute(array(':u'=>$uid,':t'=>$tenant));$assigned=$q->fetch(PDO::FETCH_ASSOC);if(!$assigned)jbRes(422,false,'Select a valid team member.');}else{$assigned=null;}
        $sd=null;$ed=null;$st=null;$et=null;
        if(!$later){$sd=jbDate(jbP('start_date',''));$ed=jbDate(jbP('end_date',''));if($sd===false||$sd===null)jbRes(422,false,'Select a reminder start date.');if($ed===false||$ed===null)$ed=$sd;if(!$anytime){$st=jbTime(jbP('start_time',''));$et=jbTime(jbP('end_time',''));if($st===false||$st===null||$et===false||$et===null)jbRes(422,false,'Enter a valid reminder time.');}}
        $stmt=$pdo->prepare("INSERT INTO job_invoice_reminders(tenant_id,job_id,description,start_date,end_date,start_time,end_time,schedule_later,anytime,assigned_user_id,email_team_when_assigned,status,created_by) VALUES(:t,:j,:d,:sd,:ed,:st,:et,:sl,:a,:u,:em,'scheduled',:cb)");
        $stmt->execute(array(':t'=>$tenant,':j'=>$id,':d'=>$description,':sd'=>$sd,':ed'=>$ed,':st'=>$st,':et'=>$et,':sl'=>$later,':a'=>$anytime,':u'=>$uid>0?$uid:null,':em'=>$emailTeam,':cb'=>$user));$rid=(int)$pdo->lastInsertId();
        $emailSent=0;$emailMessage='';if($emailTeam&&$assigned&&!empty($assigned['email'])&&filter_var($assigned['email'],FILTER_VALIDATE_EMAIL))try{$cfg=jbSmtpConfig($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch);if($cfg){$password=jbDecrypt($cfg['password_encrypted'],$tenant);$html='<div style="font-family:Arial,sans-serif"><p>Hello '.htmlspecialchars($assigned['name'],ENT_QUOTES,'UTF-8').',</p><p>You were assigned an invoice reminder for <strong>'.htmlspecialchars($job['job_no'],ENT_QUOTES,'UTF-8').'</strong>.</p><p>'.nl2br(htmlspecialchars($description,ENT_QUOTES,'UTF-8')).'</p></div>';jbMail($cfg,$password,$assigned['email'],'Invoice reminder - '.$job['job_no'],$html);$emailSent=1;}}catch(Throwable $mailError){$emailMessage=$mailError->getMessage();}
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_invoice_reminder_added',$id,(int)$job['client_id'],'Invoice reminder added: '.$job['job_no'],array('reminder_id'=>$rid,'assigned_user_id'=>$uid>0?$uid:null));
        jbRes(200,true,'Invoice reminder saved successfully.'.($emailMessage!==''?' Team email could not be sent.':''),array('reminder_id'=>$rid,'team_email_sent'=>$emailSent));
    }

    if ($action === 'delete_invoice_reminder') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);$rid=(int)jbP('reminder_id',0);if(!jbTable($pdo,'job_invoice_reminders'))jbRes(500,false,'Invoice reminders are not installed.');$stmt=$pdo->prepare("UPDATE job_invoice_reminders SET status='cancelled',updated_at=NOW() WHERE id=:r AND tenant_id=:t AND job_id=:j");$stmt->execute(array(':r'=>$rid,':t'=>$tenant,':j'=>$id));if(!$stmt->rowCount())jbRes(404,false,'Invoice reminder not found.');jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_invoice_reminder_deleted',$id,(int)$job['client_id'],'Invoice reminder removed: '.$job['job_no'],array('reminder_id'=>$rid));jbRes(200,true,'Invoice reminder removed.');
    }

    if ($action === 'send_booking_confirmation') {
        $id=(int)jbP('job_id',0);$job=jbJobDetails($pdo,$tenant,$id);
        if(strtolower((string)$job['status'])!=='scheduled')jbRes(422,false,'Booking confirmation can be resent only while the job is Scheduled.');
        if(isset($job['client_allow_email']) && (int)$job['client_allow_email']!==1)jbRes(422,false,'Email is disabled for this customer.');
        $to=jvxParseEmailList(jbP('to_emails',''));if(!$to)jbRes(422,false,'Add at least one valid recipient email address.');
        $subject=substr(trim((string)jbP('email_subject','')),0,255);$message=trim((string)jbP('email_message',''));if($subject==='')jbRes(422,false,'Email subject is required.');if($message==='')jbRes(422,false,'Email message is required.');
        if((string)jbP('send_me_copy','')==='1' && jbTable($pdo,'users')){$q=$pdo->prepare("SELECT email FROM users WHERE id=:u AND tenant_id=:t LIMIT 1");$q->execute(array(':u'=>$user,':t'=>$tenant));$me=strtolower(trim((string)$q->fetchColumn()));if($me!==''&&filter_var($me,FILTER_VALIDATE_EMAIL)&&!in_array($me,$to,true))$to[]=$me;}
        $attachments=jvxEmailUploadAttachments('email_attachments');$cfg=jbSmtpConfig($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch);if(!$cfg)jbRes(422,false,'No active SMTP configuration was found.');$password=jbDecrypt($cfg['password_encrypted'],$tenant);
        $html='<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#173746;line-height:1.55">'.nl2br(htmlspecialchars($message,ENT_QUOTES,'UTF-8')).'</div>';$sent=array();$failed=array();foreach($to as $email){try{jvxSmtpSendAttachments($cfg,$password,$email,$subject,$html,$attachments);$sent[]=$email;}catch(Throwable $mailError){$failed[$email]=$mailError->getMessage();}}
        if(!$sent)jbRes(500,false,'Unable to send the booking confirmation email.'.($failed?' '.reset($failed):''));
        if(jbCol($pdo,'jobs','booking_confirmation_sent_at'))$pdo->prepare("UPDATE jobs SET booking_confirmation_sent_at=NOW() WHERE id=:j AND tenant_id=:t")->execute(array(':j'=>$id,':t'=>$tenant));
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_booking_confirmation_resent',$id,(int)$job['client_id'],'Booking confirmation emailed: '.$job['job_no'],array('recipients'=>$sent,'failed_recipients'=>array_keys($failed),'attachment_count'=>count($attachments)));
        jbRes(200,true,'Booking confirmation email sent successfully.'.($failed?' Some additional recipient(s) could not be sent.':''),array('sent_recipients'=>$sent,'failed_recipients'=>array_keys($failed),'attachment_count'=>count($attachments)));
    }

    if ($action === 'resend_email') {
        $id = (int) jbP('job_id', 0);
        if ($id <= 0)
            jbRes(422, false, 'Invalid job.');

        $job = jbJobDetails($pdo, $tenant, $id);
        if (strtolower((string) $job['status']) !== 'scheduled') {
            jbRes(422, false, 'Resend Email is available only when the job status is Scheduled.');
        }
        $quote = jbContextFromJob($pdo, $tenant, $job);
        $assignments = jbAssignments($pdo, $tenant, $id);
        $recipients = array();
        foreach ($assignments as $assignment) {
            if (empty($assignment['user_id']))
                continue;
            $recipients[] = array(
                'id' => (int) $assignment['user_id'],
                'name' => trim((string) $assignment['user_name']),
                'email' => trim((string) $assignment['email'])
            );
        }

        $branch = !empty($job['branch_id']) ? (int) $job['branch_id'] : $sessionBranch;
        $employeeNotif = $recipients
            ? jbNotifyEmployees($pdo, $tenant, $branch, $job, $quote, $recipients, false)
            : array('in_app' => 0, 'employee_email_sent' => 0, 'employee_email_failed' => 0, 'employee_email_skipped' => 0, 'messages' => array('No assigned employees were available for email.'));
        $customerNotif = jbNotifyCustomer($pdo, $tenant, $branch, $job, $quote);

        $notifications = array_merge($employeeNotif, $customerNotif);
        $notifications['email_sent'] = (int) $notifications['employee_email_sent'] + (int) $notifications['customer_email_sent'];
        $notifications['email_failed'] = (int) $notifications['employee_email_failed'] + (int) $notifications['customer_email_failed'];
        $notifications['email_skipped'] = (int) $notifications['employee_email_skipped'] + (int) $notifications['customer_email_skipped'];
        $notifications['messages'] = array_merge(
            isset($employeeNotif['messages']) ? $employeeNotif['messages'] : array(),
            isset($customerNotif['messages']) ? $customerNotif['messages'] : array()
        );

        if ((int) $notifications['email_sent'] > 0) {
            jbActivity($pdo, $tenant, $branch, $user, 'job_email_resent', $id, (int) $job['client_id'], 'Job email resent: ' . $job['job_no'], array('notifications' => $notifications));
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
        jbRes((int) $notifications['email_failed'] > 0 ? 500 : 422, false, $message, array('notifications' => $notifications));
    }



    /* ==================== Job View v3 actions ==================== */
    if ($action === 'update_view_details') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);
        $jobNo=substr(trim((string)jbP('job_no',$job['job_no'])),0,80);$title=substr(trim((string)jbP('title',$job['title'])),0,190);
        $jobType=strtolower(trim((string)jbP('job_type',$job['job_type'])));$priority=strtolower(trim((string)jbP('priority',$job['priority'])));
        if($title==='')jbRes(422,false,'Job title is required.');if($jobNo==='')jbRes(422,false,'Job number is required.');if(jbJobNoExists($pdo,$tenant,$jobNo,$id))jbRes(409,false,'Job number already exists.');
        if(!in_array($jobType,array('one_off','recurring'),true))$jobType='one_off';if(!in_array($priority,array('low','normal','high','urgent'),true))$priority='normal';
        $start=jbDate(jbP('start_date',''));$end=jbDate(jbP('end_date',''));if($start===false||$end===false)jbRes(422,false,'Enter valid start/end dates.');
        $stmt=$pdo->prepare("UPDATE jobs SET job_no=:no,title=:title,job_type=:jt,priority=:p,start_date=:sd,end_date=:ed WHERE id=:id AND tenant_id=:t");
        $stmt->execute(array(':no'=>$jobNo,':title'=>$title,':jt'=>$jobType,':p'=>$priority,':sd'=>$start,':ed'=>$end,':id'=>$id,':t'=>$tenant));
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_details_updated',$id,(int)$job['client_id'],'Job details updated: '.$jobNo,array());
        jbRes(200,true,'Job details updated successfully.');
    }

    if ($action === 'save_view_line_items') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);
        $parsed=jbParseLineItems($pdo,$tenant,(string)jbP('line_items_json',''),jbP('discount_type',''),jbP('discount_value',0));
        jbResolveNewProducts($pdo,$tenant,$user,$parsed);$pdo->beginTransaction();
        try{jbSaveLineItems($pdo,$tenant,$id,$parsed);$pdo->prepare("UPDATE jobs SET subtotal=:s,discount_type=:dt,discount_value=:dv,discount_total=:dd,tax_total=:tax,total=:tot WHERE id=:id AND tenant_id=:t")->execute(array(':s'=>$parsed['subtotal'],':dt'=>$parsed['discount_type'],':dv'=>$parsed['discount_value'],':dd'=>$parsed['discount_total'],':tax'=>$parsed['tax_total'],':tot'=>$parsed['total'],':id'=>$id,':t'=>$tenant));$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_line_items_updated',$id,(int)$job['client_id'],'Product / Service updated: '.$job['job_no'],array('total'=>$parsed['total']));
        jbRes(200,true,'Product / Service updated successfully.');
    }

    if ($action === 'save_view_note') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);jbSaveInternalNote($pdo,$tenant,$id,$user,(string)jbP('note',''));jbSaveJobNoteMentions($pdo,$tenant,$id,(string)jbP('mention_ids_json','[]'));$up=jbSaveJobAttachments($pdo,$tenant,$id,$user);
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_note_updated',$id,(int)$job['client_id'],'Job note updated: '.$job['job_no'],array('attachments'=>$up));jbRes(200,true,'Note saved successfully.');
    }

    if ($action === 'add_time_entry') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);if(!jbTable($pdo,'time_entries'))jbRes(500,false,'Time entries table is not installed.');
        $uid=(int)jbP('employee_id',0);$q=$pdo->prepare("SELECT id FROM users WHERE id=:u AND tenant_id=:t AND deleted_at IS NULL LIMIT 1");$q->execute(array(':u'=>$uid,':t'=>$tenant));if(!$q->fetchColumn())jbRes(422,false,'Select a valid employee.');
        $date=jbDate(jbP('entry_date',''));$st=jbTime(jbP('start_time',''));$et=jbTime(jbP('end_time',''));if($date===false||$date===null||$st===false||$et===false||$st===null||$et===null)jbRes(422,false,'Enter a valid date and start/end time.');
        $startTs=strtotime($date.' '.$st);$endTs=strtotime($date.' '.$et);if($endTs<=$startTs)$endTs+=86400;$minutes=max(1,(int)round(($endTs-$startTs)/60));$rate=max(0,(float)jbP('hourly_rate',0));$cost=round($minutes/60*$rate,2);
        $stmt=$pdo->prepare("INSERT INTO time_entries(tenant_id,user_id,job_id,visit_id,entry_date,start_time,end_time,duration_minutes,entry_type,status,hourly_rate,labor_cost,notes) VALUES(:t,:u,:j,NULL,:d,:st,:et,:m,'manual','submitted',:r,:c,:n)");
        $stmt->execute(array(':t'=>$tenant,':u'=>$uid,':j'=>$id,':d'=>$date,':st'=>date('Y-m-d H:i:s',$startTs),':et'=>date('Y-m-d H:i:s',$endTs),':m'=>$minutes,':r'=>$rate,':c'=>$cost,':n'=>trim((string)jbP('notes',''))?:null));
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_time_entry_added',$id,(int)$job['client_id'],'Time entry added: '.$job['job_no'],array('minutes'=>$minutes,'labor_cost'=>$cost));jbRes(200,true,'Time entry saved successfully.');
    }

    if ($action === 'add_expense') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);if(!jbTable($pdo,'expenses'))jbRes(500,false,'Expenses table is not installed.');
        $name=substr(trim((string)jbP('item_name','')),0,190);$date=jbDate(jbP('expense_date',''));$amount=max(0,(float)jbP('amount',0));if($name==='')jbRes(422,false,'Expense item name is required.');if($date===false||$date===null)jbRes(422,false,'Select a valid expense date.');
        $upload=jvxStoreUpload('receipt',$tenant,$id,'receipts',array('jpg','jpeg','png','webp','pdf'),10*1024*1024);
        $stmt=$pdo->prepare("INSERT INTO expenses(tenant_id,branch_id,expense_no,job_id,client_id,expense_date,item_name,amount,tax_amount,notes,accounting_code,reimburse_user_id,receipt_name,receipt_path,receipt_mime,receipt_size,status,created_by) VALUES(:t,:b,:no,:j,:c,:d,:n,:a,0,:notes,:code,:ru,:rn,:rp,:rm,:rs,'submitted',:u)");
        $stmt->execute(array(':t'=>$tenant,':b'=>!empty($job['branch_id'])?(int)$job['branch_id']:null,':no'=>jvxExpenseNo($id),':j'=>$id,':c'=>(int)$job['client_id'],':d'=>$date,':n'=>$name,':a'=>$amount,':notes'=>trim((string)jbP('description',''))?:null,':code'=>substr(trim((string)jbP('accounting_code','')),0,120)?:null,':ru'=>(int)jbP('reimburse_user_id',0)>0?(int)jbP('reimburse_user_id',0):null,':rn'=>$upload?$upload['name']:null,':rp'=>$upload?$upload['path']:null,':rm'=>$upload?$upload['mime']:null,':rs'=>$upload?$upload['size']:null,':u'=>$user));
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_expense_added',$id,(int)$job['client_id'],'Expense added: '.$job['job_no'],array('amount'=>$amount));jbRes(200,true,'Expense saved successfully.');
    }

    if ($action === 'save_visit') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);if(!jbTable($pdo,'visits'))jbRes(500,false,'Visits table is not installed.');
        $visitId=(int)jbP('visit_id',0);$data=json_decode((string)jbP('visit_json','{}'),true);if(!is_array($data))jbRes(422,false,'Visit data is invalid.');
        $pdo->beginTransaction();try{
            if($visitId<=0){$visitId=jvxInsertVisit($pdo,$tenant,$user,$job,$data);}else{
                $check=$pdo->prepare("SELECT * FROM visits WHERE id=:v AND tenant_id=:t AND job_id=:j LIMIT 1 FOR UPDATE");$check->execute(array(':v'=>$visitId,':t'=>$tenant,':j'=>$id));$old=$check->fetch(PDO::FETCH_ASSOC);if(!$old)throw new RuntimeException('Visit not found.');
                $date=jbDate(isset($data['date'])?$data['date']:'');$st=jbTime(isset($data['start_time'])?$data['start_time']:'');$et=jbTime(isset($data['end_time'])?$data['end_time']:'');if($date===false||$date===null||$st===false||$et===false)throw new RuntimeException('Enter valid visit date/time.');$any=!empty($data['anytime']);$later=!empty($data['schedule_later']);if($any||$later){$st='00:00:00';$et='23:59:00';}if($st===null)$st='00:00:00';if($et===null)$et='23:59:00';
                $sets=array('scheduled_start=:ss','scheduled_end=:se','notes=:notes');$params=array(':ss'=>$date.' '.$st,':se'=>$date.' '.$et,':notes'=>trim(isset($data['instructions'])?(string)$data['instructions']:'')?:null,':v'=>$visitId,':t'=>$tenant);
                foreach(array('title'=>isset($data['title'])?$data['title']:'','instructions'=>isset($data['instructions'])?$data['instructions']:'','schedule_later'=>$later?1:0,'anytime'=>$any?1:0,'email_team_about_assignment'=>!empty($data['email_team_about_assignment'])?1:0) as $col=>$val){if(jbCol($pdo,'visits',$col)){$sets[]=$col.'=:'.$col;$params[':'.$col]=$val;}}
                $pdo->prepare("UPDATE visits SET ".implode(',',$sets)." WHERE id=:v AND tenant_id=:t")->execute($params);
                if(jbTable($pdo,'visit_assignments')){$pdo->prepare("DELETE FROM visit_assignments WHERE tenant_id=:t AND visit_id=:v")->execute(array(':t'=>$tenant,':v'=>$visitId));$ids=isset($data['assignee_ids'])&&is_array($data['assignee_ids'])?jbIntList($data['assignee_ids']):array();$ins=$pdo->prepare("INSERT INTO visit_assignments(tenant_id,visit_id,user_id,is_primary,status) VALUES(:t,:v,:u,:p,'assigned')");foreach($ids as $i=>$uid)$ins->execute(array(':t'=>$tenant,':v'=>$visitId,':u'=>$uid,':p'=>$i===0?1:0));$pdo->prepare("UPDATE visits SET assigned_user_id=:u WHERE id=:v AND tenant_id=:t")->execute(array(':u'=>$ids?reset($ids):null,':v'=>$visitId,':t'=>$tenant));}
            }$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_visit_saved',$id,(int)$job['client_id'],'Visit saved: '.$job['job_no'],array('visit_id'=>$visitId));jbRes(200,true,'Visit saved successfully.',array('visit_id'=>$visitId));
    }

    if ($action === 'add_multiple_visits') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);$rows=json_decode((string)jbP('visits_json','[]'),true);if(!is_array($rows)||!$rows)jbRes(422,false,'Select at least one visit date.');if(count($rows)>20)jbRes(422,false,'You can add a maximum of 20 visits at once.');
        $pdo->beginTransaction();$ids=array();try{foreach($rows as $row){if(is_array($row))$ids[]=jvxInsertVisit($pdo,$tenant,$user,$job,$row);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_visits_added',$id,(int)$job['client_id'],count($ids).' visits added: '.$job['job_no'],array('visit_ids'=>$ids));jbRes(200,true,count($ids).' visits added successfully.');
    }

    if ($action === 'complete_visit') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);$visitId=(int)jbP('visit_id',0);$stmt=$pdo->prepare("UPDATE visits SET status='completed',completed_at=NOW(),work_ended_at=COALESCE(work_ended_at,NOW()) WHERE id=:v AND tenant_id=:t AND job_id=:j AND status<>'cancelled'");$stmt->execute(array(':v'=>$visitId,':t'=>$tenant,':j'=>$id));if(!$stmt->rowCount())jbRes(404,false,'Visit not found or cannot be completed.');if(jbTable($pdo,'visit_assignments'))$pdo->prepare("UPDATE visit_assignments SET status='completed' WHERE tenant_id=:t AND visit_id=:v AND status<>'removed'")->execute(array(':t'=>$tenant,':v'=>$visitId));
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_visit_completed',$id,(int)$job['client_id'],'Visit completed: '.$job['job_no'],array('visit_id'=>$visitId));jbRes(200,true,'Visit marked completed.');
    }

    if ($action === 'save_billing_settings') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);if(!jbTable($pdo,'job_billing_settings'))jbRes(500,false,'Job billing settings are not installed.');$remind=!empty($_POST['remind_to_invoice_on_close'])?1:0;$split=!empty($_POST['split_payment_schedule'])?1:0;$splitType=jbP('payment_split_type','percentage')==='amount'?'amount':'percentage';$json=trim((string)jbP('payment_schedule_json','[]'));$decoded=json_decode($json,true);if(!is_array($decoded))jbRes(422,false,'Payment schedule is invalid.');
        $sets=array();$params=array(':t'=>$tenant,':j'=>$id);if(jbCol($pdo,'job_billing_settings','remind_to_invoice_on_close')){$sets[]='remind_to_invoice_on_close=:r';$params[':r']=$remind;}if(jbCol($pdo,'job_billing_settings','split_payment_schedule')){$sets[]='split_payment_schedule=:s';$params[':s']=$split;}if(jbCol($pdo,'job_billing_settings','payment_split_type')){$sets[]='payment_split_type=:pt';$params[':pt']=$splitType;}if(jbCol($pdo,'job_billing_settings','payment_schedule_json')){$sets[]='payment_schedule_json=:pj';$params[':pj']=json_encode($decoded,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}if(!$sets)jbRes(500,false,'Run migration_job_view_jobber_v3.sql first.');
        $pdo->prepare("UPDATE job_billing_settings SET ".implode(',',$sets)." WHERE tenant_id=:t AND job_id=:j")->execute($params);jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_billing_updated',$id,(int)$job['client_id'],'Invoice settings updated: '.$job['job_no'],array());jbRes(200,true,'Invoice settings saved successfully.');
    }

    if ($action === 'close_job') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);if(in_array($job['status'],array('closed','cancelled','archived'),true))jbRes(422,false,'This job cannot be closed from its current status.');$sql="UPDATE jobs SET status='closed'".(jbCol($pdo,'jobs','closed_at')?",closed_at=NOW()":"")." WHERE id=:id AND tenant_id=:t";$pdo->prepare($sql)->execute(array(':id'=>$id,':t'=>$tenant));jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_closed',$id,(int)$job['client_id'],'Job closed: '.$job['job_no'],array());jbRes(200,true,'Job closed successfully.');
    }

    if ($action === 'delete_job') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);$pdo->prepare("UPDATE jobs SET status='archived',deleted_at=NOW() WHERE id=:id AND tenant_id=:t")->execute(array(':id'=>$id,':t'=>$tenant));jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_deleted',$id,(int)$job['client_id'],'Job deleted: '.$job['job_no'],array());jbRes(200,true,'Job deleted successfully.');
    }

    if ($action === 'create_similar_job') {
        $id=(int)jbP('job_id',0);$job=jbJob($pdo,$tenant,$id);$pdo->beginTransaction();try{$newNo=jbNext($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch);$stmt=$pdo->prepare("INSERT INTO jobs(tenant_id,branch_id,job_no,client_id,location_id,request_id,quote_id,product_service_id,workflow_id,title,description,job_type,priority,assignment_mode,assignment_completion_mode,status,start_date,start_time,end_date,end_time,recurrence_rule,invoicing_preference,subtotal,discount_type,discount_value,discount_total,tax_total,total,created_by) SELECT tenant_id,branch_id,:no,client_id,location_id,NULL,NULL,product_service_id,workflow_id,title,description,job_type,priority,assignment_mode,assignment_completion_mode,'draft',start_date,start_time,end_date,end_time,NULL,invoicing_preference,subtotal,discount_type,discount_value,discount_total,tax_total,total,:u FROM jobs WHERE id=:id AND tenant_id=:t");$stmt->execute(array(':no'=>$newNo,':u'=>$user,':id'=>$id,':t'=>$tenant));$newId=(int)$pdo->lastInsertId();if(jbTable($pdo,'job_line_items'))$pdo->prepare("INSERT INTO job_line_items(tenant_id,job_id,product_service_id,product_id,item_source,item_name,description,quantity,unit_cost,unit_price,discount_amount,tax_percent,tax_amount,line_total,sort_order) SELECT tenant_id,:new,product_service_id,product_id,item_source,item_name,description,quantity,unit_cost,unit_price,discount_amount,tax_percent,tax_amount,line_total,sort_order FROM job_line_items WHERE tenant_id=:t AND job_id=:old")->execute(array(':new'=>$newId,':t'=>$tenant,':old'=>$id));$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_similar_created',$newId,(int)$job['client_id'],'Similar job created from '.$job['job_no'],array('source_job_id'=>$id));jbRes(200,true,'Similar job created. Review and save it.',array('job_id'=>$newId,'job_no'=>$newNo));
    }

    if ($action === 'save_signature') {
        $id=(int)jbP('job_id',0);$job=jbJobDetails($pdo,$tenant,$id);if(!jbTable($pdo,'attachments'))jbRes(500,false,'Attachments table is not installed.');
        $data=(string)jbP('signature_data','');if(!preg_match('#^data:image/png;base64,(.+)$#s',$data,$m))jbRes(422,false,'Draw the customer signature first.');$binary=base64_decode($m[1],true);if($binary===false||strlen($binary)<100)jbRes(422,false,'Signature data is invalid.');if(strlen($binary)>3*1024*1024)jbRes(422,false,'Signature is too large.');
        $rel='uploads/jobs/'.(int)$tenant.'/'.(int)$id.'/signatures';$abs=dirname(__DIR__).'/'.$rel;if(!is_dir($abs)&&!@mkdir($abs,0775,true)&&!is_dir($abs))throw new RuntimeException('Unable to create signature folder.');$fn='signature-'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'.png';if(@file_put_contents($abs.'/'.$fn,$binary)===false)throw new RuntimeException('Unable to save signature.');
        $stmt=$pdo->prepare("INSERT INTO attachments(tenant_id,related_type,related_id,job_id,uploaded_by,file_name,file_path,file_mime,file_size,attachment_type,description) VALUES(:t,'job',:id,:j,:u,:n,:p,'image/png',:s,'signature','Customer job signature')");$stmt->execute(array(':t'=>$tenant,':id'=>$id,':j'=>$id,':u'=>$user,':n'=>$fn,':p'=>$rel.'/'.$fn,':s'=>strlen($binary)));$attachmentId=(int)$pdo->lastInsertId();
        $emailSent=0;$emailFailed='';if((string)jbP('send_copy','')==='1'){$to=strtolower(trim((string)$job['client_email']));if(isset($job['client_allow_email'])&&(int)$job['client_allow_email']!==1){$emailFailed='Email is disabled for this customer.';}elseif($to===''||!filter_var($to,FILTER_VALIDATE_EMAIL)){$emailFailed='Customer email address is not available.';}else{try{$cfg=jbSmtpConfig($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch);if(!$cfg)throw new RuntimeException('No active SMTP configuration was found.');$password=jbDecrypt($cfg['password_encrypted'],$tenant);$html='<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#173746"><h2 style="color:#073247">Job signature</h2><p>Hello '.htmlspecialchars($job['client_name'],ENT_QUOTES,'UTF-8').',</p><p>A signature was recorded for job <strong>'.htmlspecialchars($job['job_no'],ENT_QUOTES,'UTF-8').'</strong> - '.htmlspecialchars($job['title'],ENT_QUOTES,'UTF-8').'. A copy is attached for your records.</p><p>Thank you,<br>FieldPlx</p></div>';jvxSmtpSendAttachments($cfg,$password,$to,'Signature copy - '.$job['job_no'],$html,array(array('name'=>$fn,'mime'=>'image/png','content'=>$binary,'size'=>strlen($binary))));$emailSent=1;}catch(Throwable $mailError){$emailFailed=$mailError->getMessage();}}}
        jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_signature_collected',$id,(int)$job['client_id'],'Customer signature collected: '.$job['job_no'],array('attachment_id'=>$attachmentId,'customer_copy_sent'=>$emailSent));
        $msg='Signature saved successfully.';if($emailSent)$msg.=' A copy was emailed to the customer.';elseif($emailFailed!=='')$msg.=' '.$emailFailed;
        jbRes(200,true,$msg,array('attachment_id'=>$attachmentId,'customer_copy_sent'=>$emailSent,'customer_copy_error'=>$emailFailed));
    }

    if ($action === 'send_followup_email') {
        $id=(int)jbP('job_id',0);$job=jbJobDetails($pdo,$tenant,$id);$to=trim((string)jbP('to_email',$job['client_email']));$subject=substr(trim((string)jbP('subject','Follow-up for '.$job['job_no'])),0,255);$message=trim((string)jbP('message',''));if($message==='')jbRes(422,false,'Enter a follow-up message.');$cfg=jbSmtpConfig($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch);if(!$cfg)jbRes(422,false,'No active SMTP configuration was found.');$password=jbDecrypt($cfg['password_encrypted'],$tenant);jbMail($cfg,$password,$to,$subject,'<div style="font-family:Arial,sans-serif;white-space:pre-wrap">'.nl2br(htmlspecialchars($message,ENT_QUOTES,'UTF-8')).'</div>');jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_followup_email_sent',$id,(int)$job['client_id'],'Job follow-up email sent: '.$job['job_no'],array('to'=>$to));jbRes(200,true,'Job follow-up email sent successfully.');
    }

    if ($action === 'email_job_costs_csv') {
        $id=(int)jbP('job_id',0);$job=jbJobDetails($pdo,$tenant,$id);$to=trim((string)jbP('to_email',''));if($to===''||!filter_var($to,FILTER_VALIDATE_EMAIL))jbRes(422,false,'Enter a valid email address.');$c=jvxCostSummary($pdo,$tenant,$id,$job);$rows=array(array('Category','Description','Amount'));foreach(jbJobLineItems($pdo,$tenant,$id) as $r)$rows[]=array('Product / Service',$r['item_name'],number_format((float)$r['quantity']*(float)$r['unit_cost'],2,'.',''));foreach(jvxTimeEntries($pdo,$tenant,$id) as $r)$rows[]=array('Labor',$r['user_name'].' - '.$r['entry_date'],number_format((float)$r['labor_cost'],2,'.',''));foreach(jvxExpenses($pdo,$tenant,$id) as $r)$rows[]=array('Expense',$r['item_name'],number_format((float)$r['amount']+(float)$r['tax_amount'],2,'.',''));$rows[]=array('Summary','Revenue',number_format($c['revenue'],2,'.',''));$rows[]=array('Summary','Total cost',number_format($c['cost'],2,'.',''));$rows[]=array('Summary','Profit',number_format($c['profit'],2,'.',''));$fp=fopen('php://temp','r+');foreach($rows as $r)fputcsv($fp,$r);rewind($fp);$csv=stream_get_contents($fp);fclose($fp);$cfg=jbSmtpConfig($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch);if(!$cfg)jbRes(422,false,'No active SMTP configuration was found.');$password=jbDecrypt($cfg['password_encrypted'],$tenant);$fn=preg_replace('/[^A-Za-z0-9_-]+/','-',$job['job_no']).'-costs.csv';jvxMailWithCsv($cfg,$password,$to,'Job costs - '.$job['job_no'],'<p>Please find the FieldPlx job cost CSV attached.</p>',$csv,$fn);jbActivity($pdo,$tenant,!empty($job['branch_id'])?(int)$job['branch_id']:$sessionBranch,$user,'job_cost_csv_emailed',$id,(int)$job['client_id'],'Job costs CSV emailed: '.$job['job_no'],array('to'=>$to));jbRes(200,true,'Job costs CSV emailed successfully.');
    }

    if ($action === 'list') {
        $page = max(1, (int) jbP('page', 1));
        $per = (int) jbP('per_page', 10);
        if (!in_array($per, array(10, 25, 50), true))
            $per = 10;
        $search = trim((string) jbP('search', ''));
        $status = trim((string) jbP('status', ''));
        $from = trim((string) jbP('from_date', ''));
        $to = trim((string) jbP('to_date', ''));
        $where = array('j.tenant_id=:t', 'j.deleted_at IS NULL');
        $params = array(':t' => $tenant);
        if ($search !== '') {
            $sv = '%' . $search . '%';
            $where[] = '(j.job_no LIKE :s1 OR j.title LIKE :s2 OR q.quote_no LIKE :s3 OR c.display_name LIKE :s4)';
            $params[':s1'] = $sv;
            $params[':s2'] = $sv;
            $params[':s3'] = $sv;
            $params[':s4'] = $sv;
        }
        if ($status !== '') {
            $where[] = 'j.status=:st';
            $params[':st'] = $status;
        }
        if ($from !== '') {
            $where[] = 'j.start_date>=:fd';
            $params[':fd'] = $from;
        }
        if ($to !== '') {
            $where[] = 'j.start_date<=:td';
            $params[':td'] = $to;
        }
        $whereSql = implode(' AND ', $where);
        $count = $pdo->prepare("SELECT COUNT(*) FROM jobs j INNER JOIN clients c ON c.id=j.client_id AND c.tenant_id=j.tenant_id LEFT JOIN quotes q ON q.id=j.quote_id AND q.tenant_id=j.tenant_id WHERE $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $per));
        if ($page > $pages)
            $page = $pages;
        $offset = ($page - 1) * $per;
        $sql = "SELECT j.*,q.quote_no,c.display_name client_name,ps.name service_name,(SELECT GROUP_CONCAT(CONCAT(u.first_name,' ',COALESCE(u.last_name,'')) ORDER BY ja.is_primary_responsible DESC,ja.id SEPARATOR ', ') FROM job_assignments ja INNER JOIN users u ON u.id=ja.user_id AND u.tenant_id=ja.tenant_id WHERE ja.job_id=j.id AND ja.tenant_id=j.tenant_id AND ja.status<>'removed') assignees FROM jobs j INNER JOIN clients c ON c.id=j.client_id AND c.tenant_id=j.tenant_id LEFT JOIN quotes q ON q.id=j.quote_id AND q.tenant_id=j.tenant_id LEFT JOIN product_services ps ON ps.id=j.product_service_id AND ps.tenant_id=j.tenant_id WHERE $whereSql ORDER BY j.id DESC LIMIT " . (int) $per . " OFFSET " . (int) $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $summaryStmt = $pdo->prepare("SELECT COUNT(*) total,SUM(status IN('active','scheduled','upcoming','today')) assigned,SUM(status='in_progress') in_progress,SUM(status='completed') completed FROM jobs WHERE tenant_id=:t AND deleted_at IS NULL");
        $summaryStmt->execute(array(':t' => $tenant));
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        jbRes(200, true, 'Jobs loaded.', array(
            'jobs' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'summary' => array('total' => (int) $summary['total'], 'assigned' => (int) $summary['assigned'], 'in_progress' => (int) $summary['in_progress'], 'completed' => (int) $summary['completed']),
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

        $id = (int) jbP('job_id', 0);
        $requestedJobNo = trim((string)jbP('job_no', ''));
        if (strlen($requestedJobNo) > 80)
            jbRes(422, false, 'Job number cannot exceed 80 characters.');
        if ($requestedJobNo !== '' && preg_match('/[\x00-\x1F\x7F]/', $requestedJobNo))
            jbRes(422, false, 'Job number contains invalid characters.');
        if ($requestedJobNo !== '' && jbJobNoExists($pdo, $tenant, $requestedJobNo, $id))
            jbRes(409, false, 'Job number already exists. Enter a different job number.');

        $quoteId = (int) jbP('quote_id', 0);
        $requestId = (int) jbP('request_id', 0);
        $jobSource = trim((string) jbP('job_source', $quoteId > 0 ? 'quotation' : ($requestId > 0 ? 'request' : 'direct')));
        if (!in_array($jobSource, array('direct', 'quotation', 'request'), true))
            jbRes(422, false, 'Invalid job source.');

        if ($jobSource === 'quotation') {
            if ($quoteId <= 0)
                jbRes(422, false, 'Select an approved quotation.');
            $quote = jbQuote($pdo, $tenant, $quoteId, $id);
            $requestId = !empty($quote['request_id']) ? (int) $quote['request_id'] : 0;
        } elseif ($jobSource === 'request') {
            $quoteId = 0;
            if ($requestId <= 0)
                jbRes(422, false, 'Select a valid service request.');
            $quote = jbRequestContext($pdo, $tenant, $requestId, $id);
        } else {
            $quoteId = 0;
            $requestId = 0;
            $quote = jbDirectJobContext($pdo, $tenant, (int) jbP('client_id', 0), (int) jbP('location_id', 0), (int) jbP('direct_product_service_id', 0), (int) jbP('direct_branch_id', 0), 0, $sessionBranch);
        }

        $title = trim((string) jbP('title', ''));
        if ($title === '' && $jobSource === 'quotation')
            $title = trim((string) $quote['title']);
        if ($title === '' && $jobSource === 'request')
            $title = trim((string) $quote['request_title']);
        if ($title === '' && $jobSource === 'quotation')
            $title = trim((string) $quote['request_title']);
        if ($title === '')
            $title = 'Service Job';
        $description = trim((string) jbP('description', ''));
        $priority = trim((string) jbP('priority', 'normal'));
        $status = trim((string) jbP('status', 'scheduled'));
        $mode = trim((string) jbP('assignment_mode', 'single_user'));
        $completion = trim((string) jbP('assignment_completion_mode', 'primary_only'));
        $emailTeamAboutAssignment = (string) jbP('email_team_about_assignment', '0') === '1';

        if (!in_array($priority, array('low', 'normal', 'high', 'urgent'), true))
            jbRes(422, false, 'Invalid priority.');
        if (!in_array($status, array('active', 'scheduled', 'upcoming', 'today', 'in_progress', 'waiting_customer', 'waiting_material', 'rescheduled', 'completed', 'needs_review', 'ready_to_invoice', 'invoiced', 'closed', 'cancelled'), true))
            jbRes(422, false, 'Invalid job status.');
        if (!in_array($completion, array('primary_only', 'task_owner', 'all_assignees'), true))
            jbRes(422, false, 'Invalid completion rule.');

        if ($mode === 'single_user') {
            $defaultIds = array((int) jbP('single_user_id', 0));
            $dbMode = 'single_user';
        } elseif ($mode === 'multiple_users') {
            $defaultIds = isset($_POST['user_ids']) && is_array($_POST['user_ids']) ? $_POST['user_ids'] : array();
            $dbMode = 'multiple_users';
        } elseif ($mode === 'department') {
            $department = (int) jbP('department_id', 0);
            $check = $pdo->prepare("SELECT id FROM departments WHERE id=:d AND tenant_id=:t AND status='active' LIMIT 1");
            $check->execute(array(':d' => $department, ':t' => $tenant));
            if (!$check->fetchColumn())
                jbRes(422, false, 'Select a valid department.');
            $usersStmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id=:t AND department_id=:d AND status='active' AND deleted_at IS NULL AND (is_bookable=1 OR is_field_worker=1 OR is_tenant_admin=1) ORDER BY id");
            $usersStmt->execute(array(':t' => $tenant, ':d' => $department));
            $defaultIds = $usersStmt->fetchAll(PDO::FETCH_COLUMN);
            $dbMode = 'multiple_users';
            if (!$defaultIds)
                jbRes(422, false, 'The selected department has no active service users.');
        } else {
            jbRes(422, false, 'Invalid assignment mode.');
        }
        $defaultIds = jbIntList($defaultIds);
        $defaultUsers = jbUsersByIds($pdo, $tenant, $defaultIds);
        if (count($defaultUsers) !== count($defaultIds) || !$defaultUsers)
            jbRes(422, false, 'Select at least one valid default assignee.');
        $defaultIds = array_map(function ($x) {
            return (int) $x['id']; }, $defaultUsers);

        $schedules = jbParseSchedulePayload(jbP('schedule_json', ''));
        $allAssigneeIds = $defaultIds;
        foreach ($schedules as &$schedule) {
            $ids = $schedule['assignee_ids'] ? $schedule['assignee_ids'] : $defaultIds;
            $users = jbUsersByIds($pdo, $tenant, $ids);
            if (count($users) !== count(jbIntList($ids)) || !$users)
                jbRes(422, false, 'Each schedule must use valid assigned employees.');
            $schedule['assignee_ids'] = array_map(function ($x) {
                return (int) $x['id']; }, $users);
            $allAssigneeIds = array_merge($allAssigneeIds, $schedule['assignee_ids']);
        }
        unset($schedule);
        $allAssigneeIds = jbIntList($allAssigneeIds);
        $assignUsers = jbUsersByIds($pdo, $tenant, $allAssigneeIds);
        if (!$assignUsers)
            jbRes(422, false, 'Select at least one valid assignee.');

        $occurrences = jbBuildOccurrences($schedules);
        $firstOccurrence = $occurrences[0];
        $lastOccurrence = $occurrences[count($occurrences) - 1];
        $startDate = substr($firstOccurrence['start'], 0, 10);
        $startTime = substr($firstOccurrence['start'], 11, 8);
        $endDate = substr($lastOccurrence['end'], 0, 10);
        $endTime = substr($lastOccurrence['end'], 11, 8);
        $jobType = count($occurrences) > 1 ? 'recurring' : 'one_off';
        foreach ($schedules as $schedule)
            if ($schedule['repeat_type'] !== 'none') {
                $jobType = 'recurring';
                break;
            }

        $discountType = trim((string) jbP('discount_type', ''));
        $discountValue = (float) jbP('discount_value', 0);
        $lineData = jbParseLineItems($pdo, $tenant, jbP('line_items_json', ''), $discountType, $discountValue);
        $customFields = jbParseCustomFields(jbP('custom_fields_json', ''));
        if ($customFields && !jbTable($pdo, 'job_custom_fields'))
            jbRes(500, false, 'Job custom fields are not installed. Run migration_job_custom_fields_v1.sql once.');

        /* The job stores its own pricing snapshot, even when it originated from a quotation. */
        $quote['subtotal'] = $lineData['subtotal'];
        $quote['tax_total'] = $lineData['tax_total'];
        $quote['total'] = $lineData['total'];
        if (empty($quote['product_service_id'])) {
            foreach ($lineData['items'] as $lineItem) {
                if (!empty($lineItem['product_service_id']) && isset($lineItem['item_type']) && $lineItem['item_type'] === 'service') {
                    $quote['product_service_id'] = (int) $lineItem['product_service_id'];
                    $quote['service_name'] = (string) $lineItem['item_name'];
                    break;
                }
            }
        }

        $billingType = trim((string) jbP('billing_type', 'fixed_price'));
        if (!in_array($billingType, array('visit_based', 'fixed_price'), true))
            $billingType = 'fixed_price';
        $automaticPayments = (string) jbP('automatic_payments_enabled', '0') === '1' ? 1 : 0;

        /* Jobber-style Billing preferences. */
        $remindToInvoiceOnClose = (string)jbP('remind_to_invoice_on_close', '0') === '1' ? 1 : 0;
        $splitPaymentSchedule = (string)jbP('split_payment_schedule', '0') === '1' ? 1 : 0;
        $paymentSplitType = strtolower(trim((string)jbP('payment_split_type', 'percentage')));
        if (!in_array($paymentSplitType, array('percentage','amount'), true))
            $paymentSplitType = 'percentage';

        $paymentScheduleRaw = trim((string)jbP('payment_schedule_json', ''));
        $paymentSchedule = json_decode($paymentScheduleRaw, true);
        if (!is_array($paymentSchedule)) $paymentSchedule = array();
        if (count($paymentSchedule) > 50)
            jbRes(422, false, 'A payment schedule can contain a maximum of 50 invoices.');

        $cleanPaymentSchedule = array();
        $scheduledValue = 0.0;
        foreach ($paymentSchedule as $idx => $row) {
            if (!is_array($row)) continue;
            $description = substr(trim(isset($row['description']) ? (string)$row['description'] : ''), 0, 190);
            if ($description === '') $description = 'Payment ' . ($idx + 1);
            $value = max(0, (float)(isset($row['value']) ? $row['value'] : 0));
            $dueDateRaw = trim(isset($row['due_date']) ? (string)$row['due_date'] : '');
            $dueDate = null;
            if ($dueDateRaw !== '') {
                $validDueDate = jbDate($dueDateRaw);
                if ($validDueDate === false || $validDueDate === null)
                    jbRes(422, false, 'Payment ' . ($idx + 1) . ': select a valid due date.');
                $dueDate = $validDueDate;
            }
            $scheduledValue += $value;
            $cleanPaymentSchedule[] = array('description'=>$description,'value'=>round($value,2),'due_date'=>$dueDate);
        }
        if ($splitPaymentSchedule && !$cleanPaymentSchedule)
            $cleanPaymentSchedule = array(array('description'=>'Payment 1','value'=>0,'due_date'=>null),array('description'=>'Payment 2','value'=>0,'due_date'=>null));
        if ($splitPaymentSchedule && $paymentSplitType === 'percentage' && $scheduledValue > 100.00001)
            jbRes(422, false, 'Payment schedule percentages cannot exceed 100%.');
        if ($splitPaymentSchedule && $paymentSplitType === 'amount' && $lineData['total'] > 0 && $scheduledValue > $lineData['total'] + 0.01)
            jbRes(422, false, 'Payment schedule amount cannot exceed the job total.');

        $invoiceFrequency = $remindToInvoiceOnClose ? 'job_completion' : 'as_needed';
        $billingScheduleJson = '';
        $invoiceCount = $splitPaymentSchedule ? count($cleanPaymentSchedule) : ($remindToInvoiceOnClose ? 1 : 0);
        $dates = array();
        foreach ($cleanPaymentSchedule as $row) if (!empty($row['due_date'])) $dates[] = $row['due_date'];
        sort($dates);
        $firstInvoiceDate = $dates ? $dates[0] : ($remindToInvoiceOnClose ? substr($lastOccurrence['start'],0,10) : null);
        $lastInvoiceDate = $dates ? $dates[count($dates)-1] : $firstInvoiceDate;
        $fixedAmount = max(0, (float)jbP('fixed_invoice_amount', $lineData['total']));
        if ($fixedAmount <= 0 && $lineData['total'] > 0)
            $fixedAmount = $splitPaymentSchedule && $invoiceCount > 0 ? round((float)$lineData['total']/$invoiceCount,2) : (float)$lineData['total'];
        $paymentScheduleJson = $splitPaymentSchedule ? json_encode($cleanPaymentSchedule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $invoicingPreference = $remindToInvoiceOnClose ? 'when_job_complete' : 'manual';

        $old = $id > 0 ? jbJob($pdo, $tenant, $id) : null;
        $oldAssignments = $id > 0 ? jbAssignments($pdo, $tenant, $id) : array();
        $oldIds = array();
        foreach ($oldAssignments as $assignment)
            if (!empty($assignment['user_id']))
                $oldIds[] = (int) $assignment['user_id'];
        $branch = !empty($quote['branch_id']) ? (int) $quote['branch_id'] : $sessionBranch;

        $service = (int) $quote['product_service_id'];
        if ($service <= 0) {
            $service = (int) jbP('product_service_id', 0);
            $selectedService = jbService($pdo, $tenant, $service);
            if (!$selectedService)
                jbRes(422, false, 'Select a valid service for the job card.');
            $service = (int) $selectedService['id'];
            $quote['product_service_id'] = $service;
            $quote['service_name'] = (string) $selectedService['name'];
            $quote['service_source'] = 'job_form';
        }
        $workflow = jbDefaultWorkflow($pdo, $tenant, $service);
        $quote['workflow_id'] = $workflow;
        $quote['workflow_name'] = $workflow ? jbWorkflowName($pdo, $tenant, $workflow) : '';
        $recurrenceRule = json_encode(array('version' => 2, 'schedules' => $schedules), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $pdo->beginTransaction();
        try {
            jbResolveNewProducts($pdo, $tenant, $user, $lineData);
            $hasJobDiscount = jbCol($pdo, 'jobs', 'discount_type') && jbCol($pdo, 'jobs', 'discount_value') && jbCol($pdo, 'jobs', 'discount_total');

            if ($id > 0) {
                $jobNo = $requestedJobNo !== '' ? $requestedJobNo : (string)$old['job_no'];
                $sql = "UPDATE jobs SET job_no=:no,branch_id=:b,client_id=:c,location_id=:l,request_id=:r,quote_id=:q,product_service_id=:ps,workflow_id=:w,title=:title,description=:d,job_type=:jt,priority=:p,assignment_mode=:am,assignment_completion_mode=:cm,status=:st,start_date=:sd,start_time=:stm,end_date=:ed,end_time=:etm,recurrence_rule=:rr,invoicing_preference=:ip,subtotal=:sub,tax_total=:tax,total=:tot";
                if ($hasJobDiscount)
                    $sql .= ",discount_type=:dtype,discount_value=:dvalue,discount_total=:dtotal";
                $sql .= " WHERE id=:id AND tenant_id=:t";
                $stmt = $pdo->prepare($sql);
                $params = array(':no' => $jobNo, ':b' => $branch > 0 ? $branch : null, ':c' => $quote['client_id'], ':l' => $quote['location_id'], ':r' => $quote['request_id'], ':q' => $quoteId > 0 ? $quoteId : null, ':ps' => $service > 0 ? $service : null, ':w' => $workflow, ':title' => $title, ':d' => $description !== '' ? $description : null, ':jt' => $jobType, ':p' => $priority, ':am' => $dbMode, ':cm' => $completion, ':st' => $status, ':sd' => $startDate, ':stm' => $startTime, ':ed' => $endDate, ':etm' => $endTime, ':rr' => $recurrenceRule, ':ip' => $invoicingPreference, ':sub' => $lineData['subtotal'], ':tax' => $lineData['tax_total'], ':tot' => $lineData['total'], ':id' => $id, ':t' => $tenant);
                if ($hasJobDiscount) {
                    $params[':dtype'] = $lineData['discount_type'];
                    $params[':dvalue'] = $lineData['discount_value'];
                    $params[':dtotal'] = $lineData['discount_total'];
                }
                $stmt->execute($params);
            } else {
                if ($requestedJobNo !== '') {
                    $jobNo = $requestedJobNo;
                } else {
                    $attempts = 0;
                    do {
                        $jobNo = jbNext($pdo, $tenant, $branch);
                        $attempts++;
                    } while (jbJobNoExists($pdo, $tenant, $jobNo, 0) && $attempts < 100);
                    if (jbJobNoExists($pdo, $tenant, $jobNo, 0))
                        throw new RuntimeException('Unable to generate a unique job number. Please enter a job number manually.');
                }
                $columns = array('tenant_id', 'branch_id', 'job_no', 'client_id', 'location_id', 'request_id', 'quote_id', 'product_service_id', 'workflow_id', 'title', 'description', 'job_type', 'priority', 'assignment_mode', 'assignment_completion_mode', 'status', 'start_date', 'start_time', 'end_date', 'end_time', 'recurrence_rule', 'invoicing_preference', 'subtotal', 'tax_total', 'total');
                $values = array(':t', ':b', ':no', ':c', ':l', ':r', ':q', ':ps', ':w', ':title', ':d', ':jt', ':p', ':am', ':cm', ':st', ':sd', ':stm', ':ed', ':etm', ':rr', ':ip', ':sub', ':tax', ':tot');
                if ($hasJobDiscount) {
                    $columns = array_merge($columns, array('discount_type', 'discount_value', 'discount_total'));
                    $values = array_merge($values, array(':dtype', ':dvalue', ':dtotal'));
                }
                $columns[] = 'created_by';
                $values[] = ':u';
                $stmt = $pdo->prepare("INSERT INTO jobs(" . implode(',', $columns) . ") VALUES(" . implode(',', $values) . ")");
                $params = array(':t' => $tenant, ':b' => $branch > 0 ? $branch : null, ':no' => $jobNo, ':c' => $quote['client_id'], ':l' => $quote['location_id'], ':r' => $quote['request_id'], ':q' => $quoteId > 0 ? $quoteId : null, ':ps' => $service > 0 ? $service : null, ':w' => $workflow, ':title' => $title, ':d' => $description !== '' ? $description : null, ':jt' => $jobType, ':p' => $priority, ':am' => $dbMode, ':cm' => $completion, ':st' => $status, ':sd' => $startDate, ':stm' => $startTime, ':ed' => $endDate, ':etm' => $endTime, ':rr' => $recurrenceRule, ':ip' => $invoicingPreference, ':sub' => $lineData['subtotal'], ':tax' => $lineData['tax_total'], ':tot' => $lineData['total'], ':u' => $user);
                if ($hasJobDiscount) {
                    $params[':dtype'] = $lineData['discount_type'];
                    $params[':dvalue'] = $lineData['discount_value'];
                    $params[':dtotal'] = $lineData['discount_total'];
                }
                $stmt->execute($params);
                $id = (int) $pdo->lastInsertId();
            }

            $pdo->prepare("DELETE FROM job_assignments WHERE tenant_id=:t AND job_id=:j")->execute(array(':t' => $tenant, ':j' => $id));
            $insertAssignment = $pdo->prepare("INSERT INTO job_assignments(tenant_id,job_id,user_id,team_id,assignment_role,is_primary_responsible,assigned_by,status) VALUES(:t,:j,:u,NULL,:role,:primary,:by,'assigned')");
            foreach ($assignUsers as $index => $assignedUser)
                $insertAssignment->execute(array(':t' => $tenant, ':j' => $id, ':u' => $assignedUser['id'], ':role' => $index === 0 ? 'primary' : 'technician', ':primary' => $index === 0 ? 1 : 0, ':by' => $user));

            $createdVisits = jbPersistSchedules($pdo, $tenant, $branch, $id, $jobNo, $schedules, $occurrences, $defaultIds, $user);

            $billingCols = array('tenant_id','job_id','billing_type','automatic_payments_enabled','total_invoices','first_invoice_date','last_invoice_date','fixed_invoice_amount');
            $billingVals = array(':t',':j',':bt',':ap',':ti',':fd',':ld',':fa');
            $billingUpdates = array('billing_type=VALUES(billing_type)','automatic_payments_enabled=VALUES(automatic_payments_enabled)','total_invoices=VALUES(total_invoices)','first_invoice_date=VALUES(first_invoice_date)','last_invoice_date=VALUES(last_invoice_date)','fixed_invoice_amount=VALUES(fixed_invoice_amount)');
            $billingParams = array(':t'=>$tenant,':j'=>$id,':bt'=>$billingType,':ap'=>$automaticPayments,':ti'=>$invoiceCount,':fd'=>$firstInvoiceDate,':ld'=>$lastInvoiceDate,':fa'=>$billingType === 'fixed_price' ? $fixedAmount : null);
            if (jbCol($pdo, 'job_billing_settings', 'invoice_frequency')) {
                $billingCols[]='invoice_frequency';$billingVals[]=':ifr';$billingUpdates[]='invoice_frequency=VALUES(invoice_frequency)';$billingParams[':ifr']=$invoiceFrequency;
            }
            if (jbCol($pdo, 'job_billing_settings', 'billing_schedule_json')) {
                $billingCols[]='billing_schedule_json';$billingVals[]=':bsj';$billingUpdates[]='billing_schedule_json=VALUES(billing_schedule_json)';$billingParams[':bsj']=null;
            }
            if (jbCol($pdo, 'job_billing_settings', 'remind_to_invoice_on_close')) {
                $billingCols[]='remind_to_invoice_on_close';$billingVals[]=':ric';$billingUpdates[]='remind_to_invoice_on_close=VALUES(remind_to_invoice_on_close)';$billingParams[':ric']=$remindToInvoiceOnClose;
            }
            if (jbCol($pdo, 'job_billing_settings', 'split_payment_schedule')) {
                $billingCols[]='split_payment_schedule';$billingVals[]=':sps';$billingUpdates[]='split_payment_schedule=VALUES(split_payment_schedule)';$billingParams[':sps']=$splitPaymentSchedule;
            }
            if (jbCol($pdo, 'job_billing_settings', 'payment_split_type')) {
                $billingCols[]='payment_split_type';$billingVals[]=':pst';$billingUpdates[]='payment_split_type=VALUES(payment_split_type)';$billingParams[':pst']=$paymentSplitType;
            }
            if (jbCol($pdo, 'job_billing_settings', 'payment_schedule_json')) {
                $billingCols[]='payment_schedule_json';$billingVals[]=':psj';$billingUpdates[]='payment_schedule_json=VALUES(payment_schedule_json)';$billingParams[':psj']=$paymentScheduleJson;
            }
            $billing = $pdo->prepare("INSERT INTO job_billing_settings(".implode(',',$billingCols).") VALUES(".implode(',',$billingVals).") ON DUPLICATE KEY UPDATE ".implode(',',$billingUpdates));
            $billing->execute($billingParams);

            jbSaveLineItems($pdo, $tenant, $id, $lineData);
            jbSaveInternalNote($pdo, $tenant, $id, $user, jbP('internal_note', ''));
            jbSaveJobNoteMentions($pdo, $tenant, $id, jbP('note_mentions_json', '[]'));
            jbSaveJobCustomFields($pdo, $tenant, $id, $customFields);
            $checklistIds = jbSaveJobChecklists($pdo, $tenant, $id, (int) $assignUsers[0]['id'], $user);
            if ($quoteId > 0)
                $pdo->prepare("UPDATE quotes SET status='converted' WHERE id=:q AND tenant_id=:t AND status='approved'")->execute(array(':q' => $quoteId, ':t' => $tenant));
            if ($jobSource === 'request' && !$old && !empty($quote['request_id']))
                jbRecordRequestConversion($pdo, $tenant, $branch, $user, $quote, $id, $jobNo);
            jbInitWorkflow($pdo, $tenant, $id, $workflow, (int) $assignUsers[0]['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            throw $e;
        }

        $attachments = jbSaveJobAttachments($pdo, $tenant, $id, $user);
        $job = jbJob($pdo, $tenant, $id);
        $newUsers = array();
        foreach ($assignUsers as $assignedUser)
            if (!$old || !in_array((int) $assignedUser['id'], $oldIds, true))
                $newUsers[] = $assignedUser;

        $employeeNotif = $newUsers ? jbNotifyEmployees($pdo, $tenant, $branch, $job, $quote, $newUsers, true, $emailTeamAboutAssignment) : array('in_app' => 0, 'employee_email_sent' => 0, 'employee_email_failed' => 0, 'employee_email_skipped' => 0, 'messages' => array());
        $customerNotif = !$old ? jbNotifyCustomer($pdo, $tenant, $branch, $job, $quote) : array('customer_in_app_sent' => 0, 'customer_in_app_skipped' => 1, 'customer_email_sent' => 0, 'customer_email_failed' => 0, 'customer_email_skipped' => 0, 'messages' => array());
        $notifications = array_merge($employeeNotif, $customerNotif);
        $notifications['email_sent'] = (int) $notifications['employee_email_sent'] + (int) $notifications['customer_email_sent'];
        $notifications['email_failed'] = (int) $notifications['employee_email_failed'] + (int) $notifications['customer_email_failed'];
        $notifications['email_skipped'] = (int) $notifications['employee_email_skipped'] + (int) $notifications['customer_email_skipped'];
        $notifications['messages'] = array_merge(isset($employeeNotif['messages']) ? $employeeNotif['messages'] : array(), isset($customerNotif['messages']) ? $customerNotif['messages'] : array(), isset($attachments['messages']) ? $attachments['messages'] : array());

        jbActivity($pdo, $tenant, $branch, $user, $old ? 'job_reassigned' : 'job_created', $id, (int) $quote['client_id'], ($old ? 'Job updated: ' : 'Job created: ') . $job['job_no'], array('job_source' => $jobSource, 'quote_id' => $quoteId > 0 ? $quoteId : null, 'request_id' => !empty($quote['request_id']) ? (int)$quote['request_id'] : null, 'product_service_id' => $service, 'workflow_id' => $workflow, 'job_type' => $jobType, 'schedules' => count($schedules), 'visits' => count($occurrences), 'billing_type' => $billingType, 'invoice_frequency' => $invoiceFrequency, 'remind_to_invoice_on_close' => $remindToInvoiceOnClose, 'split_payment_schedule' => $splitPaymentSchedule, 'payment_split_type' => $paymentSplitType, 'custom_fields' => count($customFields), 'assignees' => $allAssigneeIds, 'checklists' => $checklistIds, 'attachments' => $attachments, 'notifications' => $notifications));

        if (function_exists('tenantAuditLog')) {
            try {
                tenantAuditLog($pdo, $old ? 'JOB_UPDATED' : 'JOB_CREATED', $tenant, $branch, $user, 'job', $id, $old, $job);
            } catch (Throwable $e) {
                error_log('job audit ' . $e->getMessage());
            }
        }

        jbRes(200, true, ($old ? 'Job updated' : 'Job created') . ' successfully with ' . count($occurrences) . ' visit schedule(s).', array('job_id' => $id, 'job_no' => $job['job_no'], 'visit_count' => count($occurrences), 'new_visits_created' => $createdVisits, 'billing' => array('type' => $billingType, 'total_invoices' => $invoiceCount, 'first' => $firstInvoiceDate, 'last' => $lastInvoiceDate, 'remind_to_invoice_on_close' => $remindToInvoiceOnClose, 'split_payment_schedule' => $splitPaymentSchedule, 'payment_split_type' => $paymentSplitType), 'attachments' => $attachments, 'notifications' => $notifications));
    }

    if ($action === 'cancel') {
        $id = (int) jbP('job_id', 0);
        $reason = trim((string) jbP('reason', ''));
        if ($reason === '')
            jbRes(422, false, 'Cancellation reason is required.');
        $old = jbJob($pdo, $tenant, $id);
        $pdo->prepare("UPDATE jobs SET status='cancelled' WHERE id=:id AND tenant_id=:t")->execute(array(':id' => $id, ':t' => $tenant));
        jbActivity($pdo, $tenant, (int) $old['branch_id'], $user, 'job_cancelled', $id, (int) $old['client_id'], 'Job cancelled: ' . $old['job_no'], array('reason' => $reason));
        jbRes(200, true, 'Job cancelled successfully.');
    }

    jbRes(400, false, 'Unsupported jobs action.');
} catch (PDOException $e) {
    error_log('FieldPlx jobs PDO ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062)
        jbRes(409, false, 'Job number already exists.');
    jbRes(500, false, 'Unable to process the jobs request.');
} catch (Throwable $e) {
    error_log('FieldPlx jobs ' . $e->getMessage());
    jbRes(500, false, $e->getMessage() !== '' ? $e->getMessage() : 'Unable to process the jobs request.');
}
