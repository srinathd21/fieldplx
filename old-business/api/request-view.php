<?php
/*
 * FieldPlx Request View API - Version 2.0.1 - 2026-09-08
 * Dedicated API for request-view.php.
 * Does not depend on api/request-form.php.
 *
 * Supports:
 * - Jobber-style request view metadata/history
 * - Overview edit
 * - On-site assessment create/update + team email + checklist
 * - Product / Service edit (services from product_services, products from products)
 * - Quick create Service/Product
 * - Internal notes + @mention notifications + attachments
 * - Archive / delete
 */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/platform-smtp.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function rvApiResponse($code, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$code);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rvPost($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function rvTable(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $s->execute(array(':t' => $table));
    $cache[$table] = ((int)$s->fetchColumn() > 0);
    return $cache[$table];
}

function rvColumn(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $s->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = ((int)$s->fetchColumn() > 0);
    return $cache[$key];
}

function rvStatusAllows(PDO $pdo, $value)
{
    try {
        $s = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_requests' AND COLUMN_NAME='status' LIMIT 1");
        $s->execute();
        $type = (string)$s->fetchColumn();
        if (stripos($type, 'enum(') !== 0) return true;
        return strpos($type, "'" . str_replace("'", "''", $value) . "'") !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function rvCurrency(PDO $pdo, $tenant)
{
    try {
        $s = $pdo->prepare("SELECT c.currency_code,c.currency_name,c.symbol,c.symbol_position,c.decimal_places,c.decimal_separator,c.thousand_separator FROM tenants t INNER JOIN currencies c ON c.id=t.currency_id WHERE t.id=:t LIMIT 1");
        $s->execute(array(':t' => $tenant));
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;
    } catch (Throwable $e) {
    }
    return array(
        'currency_code' => '',
        'currency_name' => '',
        'symbol' => '',
        'symbol_position' => 'before',
        'decimal_places' => 2,
        'decimal_separator' => '.',
        'thousand_separator' => ','
    );
}

function rvValidRequest(PDO $pdo, $tenant, $requestId)
{
    if ($requestId <= 0) return null;
    $sql = "SELECT id FROM service_requests WHERE id=:id AND tenant_id=:t";
    if (rvColumn($pdo, 'service_requests', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
    $sql .= " LIMIT 1";
    $s = $pdo->prepare($sql);
    $s->execute(array(':id' => $requestId, ':t' => $tenant));
    return $s->fetchColumn() ? (int)$requestId : null;
}

function rvActivity(PDO $pdo, $tenant, $branch, $user, $eventType, $requestId, $clientId, $title, $details)
{
    if (!rvTable($pdo, 'activity_events')) return;
    try {
        $s = $pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client) VALUES(:t,:b,:u,'user',:e,'service_request',:r,:c,:title,:details,0)");
        $s->execute(array(
            ':t' => $tenant,
            ':b' => $branch > 0 ? $branch : null,
            ':u' => $user > 0 ? $user : null,
            ':e' => $eventType,
            ':r' => $requestId,
            ':c' => $clientId > 0 ? $clientId : null,
            ':title' => substr((string)$title, 0, 255),
            ':details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ));
    } catch (Throwable $e) {
        error_log('request-view activity: ' . $e->getMessage());
    }
}

function rvAudit(PDO $pdo, $action, $tenant, $branch, $user, $requestId, $oldData, $newData)
{
    if (!function_exists('tenantAuditLog')) return;
    try {
        tenantAuditLog(
            $pdo,
            $action,
            $tenant,
            $branch > 0 ? $branch : null,
            $user,
            'service_request',
            $requestId,
            $oldData,
            $newData
        );
    } catch (Throwable $e) {
        error_log('request-view audit: ' . $e->getMessage());
    }
}

function rvLoadRequest(PDO $pdo, $tenant, $requestId)
{
    $deleted = rvColumn($pdo, 'service_requests', 'deleted_at') ? " AND r.deleted_at IS NULL" : "";
    $sql = "SELECT r.*,
                   c.display_name AS client_name,
                   c.company_name AS client_company,
                   c.email AS client_email,
                   c.phone AS client_phone,
                   c.alternate_phone AS client_alternate_phone,
                   c.client_type,
                   l.name AS location_name,
                   l.location_type,
                   l.address_line1 AS location_address1,
                   l.address_line2 AS location_address2,
                   l.city AS location_city,
                   l.state AS location_state,
                   l.postal_code AS location_postal_code,
                   l.contact_name AS location_contact_name,
                   l.contact_phone AS location_contact_phone,
                   b.name AS branch_name,
                   CONCAT_WS(' ',cu.first_name,cu.last_name) AS created_by_name
            FROM service_requests r
            INNER JOIN clients c ON c.id=r.client_id AND c.tenant_id=r.tenant_id
            LEFT JOIN client_locations l ON l.id=r.location_id AND l.tenant_id=r.tenant_id
            LEFT JOIN branches b ON b.id=r.branch_id AND b.tenant_id=r.tenant_id
            LEFT JOIN users cu ON cu.id=r.created_by_user_id AND cu.tenant_id=r.tenant_id
            WHERE r.id=:id AND r.tenant_id=:t" . $deleted . " LIMIT 1";
    $s = $pdo->prepare($sql);
    $s->execute(array(':id' => $requestId, ':t' => $tenant));
    return $s->fetch(PDO::FETCH_ASSOC);
}

function rvLoadCatalog(PDO $pdo, $tenant)
{
    $catalog = array();

    if (rvTable($pdo, 'product_services')) {
        $sql = "SELECT id,name,sku,description,unit_cost,unit_price,tax_percent FROM product_services WHERE tenant_id=:t AND item_type='service' AND status='active'";
        if (rvColumn($pdo, 'product_services', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
        $sql .= " ORDER BY name,id";
        $s = $pdo->prepare($sql);
        $s->execute(array(':t' => $tenant));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $catalog[] = array(
                'id' => 's:' . (int)$r['id'],
                'source_id' => (int)$r['id'],
                'item_source' => 'service',
                'item_type' => 'service',
                'name' => $r['name'],
                'sku' => isset($r['sku']) ? $r['sku'] : null,
                'description' => isset($r['description']) ? (string)$r['description'] : '',
                'unit_cost' => isset($r['unit_cost']) ? (float)$r['unit_cost'] : 0,
                'unit_price' => isset($r['unit_price']) ? (float)$r['unit_price'] : 0,
                'tax_percent' => isset($r['tax_percent']) ? (float)$r['tax_percent'] : 0
            );
        }
    }

    if (rvTable($pdo, 'products')) {
        $hasTaxPercent = rvColumn($pdo, 'products', 'tax_percent');
        $hasTaxId = rvColumn($pdo, 'products', 'tax_id') && rvTable($pdo, 'product_tax_rates');
        $taxExpr = $hasTaxPercent ? 'p.tax_percent' : '0';
        $taxJoin = '';
        if ($hasTaxId && $hasTaxPercent) {
            $taxExpr = "COALESCE(NULLIF(p.tax_percent,0),ptr.rate_percent,0)";
            $taxJoin = " LEFT JOIN product_tax_rates ptr ON ptr.id=p.tax_id AND ptr.tenant_id=p.tenant_id AND ptr.status='active' ";
        }
        $descExpr = rvColumn($pdo, 'products', 'description') ? 'p.description' : 'NULL';
        $skuExpr = rvColumn($pdo, 'products', 'sku') ? 'p.sku' : 'NULL';
        $costExpr = rvColumn($pdo, 'products', 'base_unit_price') ? 'p.base_unit_price' : '0';
        $priceExpr = rvColumn($pdo, 'products', 'selling_price') ? 'p.selling_price' : $costExpr;
        $sql = "SELECT p.id,$skuExpr AS sku,p.name,$descExpr AS description,$costExpr AS unit_cost,$priceExpr AS unit_price,$taxExpr AS tax_percent FROM products p" . $taxJoin . " WHERE p.tenant_id=:t AND p.status='active'";
        if (rvColumn($pdo, 'products', 'deleted_at')) $sql .= " AND p.deleted_at IS NULL";
        $sql .= " ORDER BY p.name,p.id";
        $s = $pdo->prepare($sql);
        $s->execute(array(':t' => $tenant));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $catalog[] = array(
                'id' => 'p:' . (int)$r['id'],
                'source_id' => (int)$r['id'],
                'item_source' => 'product',
                'item_type' => 'product',
                'name' => $r['name'],
                'sku' => isset($r['sku']) ? $r['sku'] : null,
                'description' => isset($r['description']) ? (string)$r['description'] : '',
                'unit_cost' => isset($r['unit_cost']) ? (float)$r['unit_cost'] : 0,
                'unit_price' => isset($r['unit_price']) ? (float)$r['unit_price'] : 0,
                'tax_percent' => isset($r['tax_percent']) ? (float)$r['tax_percent'] : 0
            );
        }
    }

    usort($catalog, function ($a, $b) {
        $ta = $a['item_source'] === 'service' ? 0 : 1;
        $tb = $b['item_source'] === 'service' ? 0 : 1;
        if ($ta !== $tb) return $ta - $tb;
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    return $catalog;
}

function rvResolveCatalog(PDO $pdo, $tenant, $key)
{
    $key = trim((string)$key);
    if ($key === '') return null;
    $parts = explode(':', $key, 2);
    if (count($parts) !== 2) return null;
    $kind = strtolower($parts[0]);
    $id = (int)$parts[1];
    if ($id <= 0) return null;

    if ($kind === 's') {
        $sql = "SELECT id,name,'service' AS item_type,description,unit_cost,unit_price,tax_percent FROM product_services WHERE id=:id AND tenant_id=:t AND item_type='service' AND status='active'";
        if (rvColumn($pdo, 'product_services', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
        $sql .= " LIMIT 1";
        $s = $pdo->prepare($sql);
        $s->execute(array(':id' => $id, ':t' => $tenant));
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['catalog_key'] = 's:' . $id;
        $r['product_service_id'] = $id;
        $r['product_id'] = null;
        $r['item_source'] = 'service';
        return $r;
    }

    if ($kind === 'p' && rvTable($pdo, 'products')) {
        $hasTaxPercent = rvColumn($pdo, 'products', 'tax_percent');
        $hasTaxId = rvColumn($pdo, 'products', 'tax_id') && rvTable($pdo, 'product_tax_rates');
        $taxExpr = $hasTaxPercent ? 'p.tax_percent' : '0';
        $taxJoin = '';
        if ($hasTaxId && $hasTaxPercent) {
            $taxExpr = "COALESCE(NULLIF(p.tax_percent,0),ptr.rate_percent,0)";
            $taxJoin = " LEFT JOIN product_tax_rates ptr ON ptr.id=p.tax_id AND ptr.tenant_id=p.tenant_id AND ptr.status='active' ";
        }
        $descExpr = rvColumn($pdo, 'products', 'description') ? 'p.description' : 'NULL';
        $costExpr = rvColumn($pdo, 'products', 'base_unit_price') ? 'p.base_unit_price' : '0';
        $priceExpr = rvColumn($pdo, 'products', 'selling_price') ? 'p.selling_price' : $costExpr;
        $sql = "SELECT p.id,p.name,'product' AS item_type,$descExpr AS description,$costExpr AS unit_cost,$priceExpr AS unit_price,$taxExpr AS tax_percent FROM products p" . $taxJoin . " WHERE p.id=:id AND p.tenant_id=:t AND p.status='active'";
        if (rvColumn($pdo, 'products', 'deleted_at')) $sql .= " AND p.deleted_at IS NULL";
        $sql .= " LIMIT 1";
        $s = $pdo->prepare($sql);
        $s->execute(array(':id' => $id, ':t' => $tenant));
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['catalog_key'] = 'p:' . $id;
        $r['product_service_id'] = null;
        $r['product_id'] = $id;
        $r['item_source'] = 'product';
        return $r;
    }
    return null;
}

function rvLoadLines(PDO $pdo, $tenant, $requestId)
{
    if (!rvTable($pdo, 'service_request_line_items')) return array();
    $hasProductId = rvColumn($pdo, 'service_request_line_items', 'product_id');
    $selectProduct = $hasProductId ? ',li.product_id' : ',NULL AS product_id';
    $s = $pdo->prepare("SELECT li.*" . $selectProduct . " FROM service_request_line_items li WHERE li.tenant_id=:t AND li.request_id=:r ORDER BY li.sort_order,li.id");
    $s->execute(array(':t' => $tenant, ':r' => $requestId));
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $catalogKey = '';
        if (!empty($row['product_id'])) {
            $catalogKey = 'p:' . (int)$row['product_id'];
        } elseif (!empty($row['product_service_id'])) {
            $catalogKey = 's:' . (int)$row['product_service_id'];
        } elseif (strtolower((string)$row['item_type']) === 'product' && rvTable($pdo, 'products')) {
            /* Backward compatibility for request lines created before product_id support. */
            $sql = "SELECT id FROM products WHERE tenant_id=:t AND LOWER(name)=LOWER(:n) AND status='active'";
            if (rvColumn($pdo, 'products', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
            $sql .= " ORDER BY id LIMIT 1";
            $p = $pdo->prepare($sql);
            $p->execute(array(':t' => $tenant, ':n' => $row['item_name']));
            $pid = (int)$p->fetchColumn();
            if ($pid > 0) $catalogKey = 'p:' . $pid;
        }
        $row['catalog_key'] = $catalogKey;
    }
    unset($row);
    return $rows;
}

function rvSaveLines(PDO $pdo, $tenant, $requestId, $raw)
{
    if (!rvTable($pdo, 'service_request_line_items')) {
        rvApiResponse(500, false, 'The request line item table is missing. Run the request migration first.');
    }
    $rows = json_decode((string)$raw, true);
    if (!is_array($rows)) rvApiResponse(422, false, 'Product / Service data is invalid.');

    $normalized = array();
    foreach ($rows as $i => $x) {
        $key = isset($x['catalog_key']) ? trim((string)$x['catalog_key']) : '';
        $cat = $key !== '' ? rvResolveCatalog($pdo, $tenant, $key) : null;
        $name = trim((string)(isset($x['item_name']) ? $x['item_name'] : ($cat ? $cat['name'] : '')));
        if ($name === '') continue;
        $qty = max(0.001, (float)(isset($x['quantity']) ? $x['quantity'] : 1));
        $cost = max(0, (float)(isset($x['unit_cost']) ? $x['unit_cost'] : ($cat ? $cat['unit_cost'] : 0)));
        $price = max(0, (float)(isset($x['unit_price']) ? $x['unit_price'] : ($cat ? $cat['unit_price'] : 0)));
        $taxPct = max(0, min(100, (float)(isset($x['tax_percent']) ? $x['tax_percent'] : ($cat ? $cat['tax_percent'] : 0))));
        $description = trim((string)(isset($x['description']) ? $x['description'] : ($cat ? $cat['description'] : '')));
        $base = $qty * $price;
        $tax = $base * $taxPct / 100;
        $normalized[] = array(
            'product_service_id' => $cat ? $cat['product_service_id'] : null,
            'product_id' => $cat ? $cat['product_id'] : null,
            'item_type' => $cat ? $cat['item_type'] : (isset($x['item_type']) ? strtolower(trim((string)$x['item_type'])) : 'other'),
            'item_name' => substr($name, 0, 255),
            'description' => $description,
            'quantity' => $qty,
            'unit_cost' => $cost,
            'unit_price' => $price,
            'tax_percent' => $taxPct,
            'tax_amount' => $tax,
            'line_total' => $base + $tax,
            'sort_order' => $i + 1
        );
    }

    $d = $pdo->prepare("DELETE FROM service_request_line_items WHERE tenant_id=:t AND request_id=:r");
    $d->execute(array(':t' => $tenant, ':r' => $requestId));

    $hasProductId = rvColumn($pdo, 'service_request_line_items', 'product_id');
    if ($hasProductId) {
        $sql = "INSERT INTO service_request_line_items(tenant_id,request_id,product_service_id,product_id,item_type,item_name,description,quantity,unit_cost,unit_price,tax_percent,tax_amount,line_total,sort_order) VALUES(:t,:r,:ps,:product,:type,:name,:d,:q,:cost,:price,:tp,:ta,:total,:sort)";
    } else {
        $sql = "INSERT INTO service_request_line_items(tenant_id,request_id,product_service_id,item_type,item_name,description,quantity,unit_cost,unit_price,tax_percent,tax_amount,line_total,sort_order) VALUES(:t,:r,:ps,:type,:name,:d,:q,:cost,:price,:tp,:ta,:total,:sort)";
    }
    $ins = $pdo->prepare($sql);
    foreach ($normalized as $x) {
        $params = array(
            ':t' => $tenant,
            ':r' => $requestId,
            ':ps' => $x['product_service_id'],
            ':type' => $x['item_type'] !== '' ? $x['item_type'] : 'other',
            ':name' => $x['item_name'],
            ':d' => $x['description'] !== '' ? $x['description'] : null,
            ':q' => $x['quantity'],
            ':cost' => $x['unit_cost'],
            ':price' => $x['unit_price'],
            ':tp' => $x['tax_percent'],
            ':ta' => $x['tax_amount'],
            ':total' => $x['line_total'],
            ':sort' => $x['sort_order']
        );
        if ($hasProductId) $params[':product'] = $x['product_id'];
        $ins->execute($params);
    }
    return $normalized;
}

function rvLoadUsers(PDO $pdo, $tenant)
{
    $s = $pdo->prepare("SELECT id,CONCAT(first_name,CASE WHEN last_name IS NOT NULL AND last_name<>'' THEN CONCAT(' ',last_name) ELSE '' END) name,email,job_title FROM users WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL ORDER BY first_name,last_name");
    $s->execute(array(':t' => $tenant));
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function rvLoadAssessment(PDO $pdo, $tenant, $requestId)
{
    if (!rvTable($pdo, 'assessments')) return null;
    $s = $pdo->prepare("SELECT * FROM assessments WHERE tenant_id=:t AND request_id=:r ORDER BY id DESC LIMIT 1");
    $s->execute(array(':t' => $tenant, ':r' => $requestId));
    $assessment = $s->fetch(PDO::FETCH_ASSOC);
    if (!$assessment) return null;

    $assessment['assigned_user_ids'] = array();
    $assessment['assignment_mode'] = 'multiple';
    $assessment['team_id'] = null;
    if (rvTable($pdo, 'request_assignments')) {
        $a = $pdo->prepare("SELECT assignment_mode,team_id,user_id,is_primary FROM request_assignments WHERE tenant_id=:t AND request_id=:r ORDER BY is_primary DESC,id");
        $a->execute(array(':t' => $tenant, ':r' => $requestId));
        $rows = $a->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $assessment['assignment_mode'] = $rows[0]['assignment_mode'];
            $assessment['team_id'] = $rows[0]['team_id'] !== null ? (int)$rows[0]['team_id'] : null;
            foreach ($rows as $row) {
                if (!empty($row['user_id'])) $assessment['assigned_user_ids'][] = (int)$row['user_id'];
            }
        }
    }
    if (!$assessment['assigned_user_ids'] && !empty($assessment['assigned_user_id'])) {
        $assessment['assigned_user_ids'][] = (int)$assessment['assigned_user_id'];
    }
    return $assessment;
}

function rvLoadChecklists(PDO $pdo, $tenant, $requestId)
{
    if (!rvTable($pdo, 'service_request_checklists') || !rvTable($pdo, 'checklist_templates')) return array();
    $s = $pdo->prepare("SELECT src.id AS link_id,ct.id,ct.name,ct.description,src.created_at,(SELECT COUNT(*) FROM checklist_template_items i WHERE i.checklist_template_id=ct.id) item_count FROM service_request_checklists src INNER JOIN checklist_templates ct ON ct.id=src.checklist_template_id AND ct.tenant_id=src.tenant_id WHERE src.tenant_id=:t AND src.request_id=:r ORDER BY src.id DESC");
    $s->execute(array(':t' => $tenant, ':r' => $requestId));
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function rvSaveChecklist(PDO $pdo, $tenant, $requestId, $user, $raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (!rvTable($pdo, 'checklist_templates') || !rvTable($pdo, 'checklist_template_items') || !rvTable($pdo, 'service_request_checklists')) {
        rvApiResponse(500, false, 'Checklist tables are unavailable. Run the request/checklist migration first.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) rvApiResponse(422, false, 'Checklist data is invalid.');
    $name = substr(trim((string)(isset($data['name']) ? $data['name'] : '')), 0, 190);
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
    if ($name === '' || !$items) rvApiResponse(422, false, 'The checklist needs a name and at least one question.');

    $s = $pdo->prepare("INSERT INTO checklist_templates(tenant_id,name,description,status,created_by) VALUES(:t,:n,:d,'active',:u)");
    $s->execute(array(':t' => $tenant, ':n' => $name, ':d' => !empty($data['description']) ? $data['description'] : null, ':u' => $user));
    $templateId = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO checklist_template_items(checklist_template_id,title,description,is_required,sort_order) VALUES(:ct,:title,:d,:r,:o)");
    $order = 0;
    foreach ($items as $item) {
        $title = substr(trim((string)(isset($item['title']) ? $item['title'] : '')), 0, 255);
        if ($title === '') continue;
        $order++;
        $meta = array(
            'section_title' => isset($item['section_title']) ? (string)$item['section_title'] : '',
            'question_type' => isset($item['question_type']) ? (string)$item['question_type'] : 'short_answer',
            'options' => isset($item['options']) && is_array($item['options']) ? $item['options'] : array()
        );
        $ins->execute(array(
            ':ct' => $templateId,
            ':title' => $title,
            ':d' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':r' => !empty($item['is_required']) || !empty($item['required']) ? 1 : 0,
            ':o' => $order
        ));
    }
    if ($order === 0) rvApiResponse(422, false, 'The checklist needs at least one valid question.');

    $link = $pdo->prepare("INSERT INTO service_request_checklists(tenant_id,request_id,checklist_template_id,created_by) VALUES(:t,:r,:ct,:u)");
    $link->execute(array(':t' => $tenant, ':r' => $requestId, ':ct' => $templateId, ':u' => $user));
    return array('id' => $templateId, 'name' => $name, 'item_count' => $order);
}

function rvNextAssessmentNo(PDO $pdo, $tenant, $branch)
{
    if (rvTable($pdo, 'document_sequences')) {
        try {
            $sepCol = rvColumn($pdo, 'document_sequences', 'number_separator') ? 'number_separator' : 'separator';
            $s = $pdo->prepare("SELECT ds.*,b.branch_code FROM document_sequences ds LEFT JOIN branches b ON b.id=ds.branch_id AND b.tenant_id=ds.tenant_id WHERE ds.tenant_id=:t AND ds.document_type='assessment' AND ds.is_active=1 AND (ds.branch_id=:b OR ds.branch_id IS NULL) ORDER BY CASE WHEN ds.branch_id=:b2 THEN 0 ELSE 1 END,ds.id LIMIT 1 FOR UPDATE");
            $s->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : 0, ':b2' => $branch > 0 ? $branch : 0));
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $now = new DateTime('now');
                $year = $now->format('Y');
                $month = $now->format('m');
                $fyStart = max(1, min(12, (int)$r['financial_year_start_month']));
                $fyYear = (int)$now->format('n') >= $fyStart ? (int)$year : (int)$year - 1;
                $fy = $fyYear . '-' . substr((string)($fyYear + 1), -2);
                $key = 'never';
                if ($r['reset_period'] === 'monthly') $key = $year . $month;
                elseif ($r['reset_period'] === 'yearly') $key = $year;
                elseif ($r['reset_period'] === 'financial_year') $key = $fy;
                $current = (int)$r['current_number'];
                if ($r['reset_period'] !== 'never' && (string)$r['last_reset_key'] !== (string)$key) $current = 0;
                $next = $current + 1;
                $middle = '';
                if ($r['middle_format'] === 'year') $middle = $year;
                elseif ($r['middle_format'] === 'year_month') $middle = $year . $month;
                elseif ($r['middle_format'] === 'financial_year') $middle = $fy;
                elseif ($r['middle_format'] === 'branch_year') $middle = (!empty($r['branch_code']) ? $r['branch_code'] : 'BR') . $year;
                $parts = array();
                if (!empty($r['prefix'])) $parts[] = $r['prefix'];
                if ($middle !== '') $parts[] = $middle;
                $parts[] = str_pad((string)$next, max(1, (int)$r['number_length']), '0', STR_PAD_LEFT);
                if (!empty($r['suffix'])) $parts[] = $r['suffix'];
                $separator = isset($r[$sepCol]) ? (string)$r[$sepCol] : '-';
                $number = implode($separator, $parts);
                $u = $pdo->prepare("UPDATE document_sequences SET current_number=:n,last_reset_key=:k WHERE id=:id");
                $u->execute(array(':n' => $next, ':k' => $key, ':id' => $r['id']));
                return $number;
            }
        } catch (Throwable $e) {
            error_log('assessment sequence: ' . $e->getMessage());
        }
    }
    $s = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(assessment_no,'-',-1) AS UNSIGNED)) FROM assessments WHERE tenant_id=:t AND assessment_no LIKE 'ASM-%'");
    $s->execute(array(':t' => $tenant));
    return 'ASM-' . str_pad((string)((int)$s->fetchColumn() + 1), 6, '0', STR_PAD_LEFT);
}

function rvSendTeamMail(PDO $pdo, $tenant, $branch, $userIds, $request, $assessment)
{
    $sum = array('email_sent' => 0, 'email_failed' => 0, 'email_skipped' => 0, 'messages' => array());
    if (!$userIds) return $sum;
    if (!function_exists('fieldplxSendPlatformMail')) {
        $sum['email_skipped'] = count($userIds);
        $sum['messages'][] = 'Platform SMTP mail helper is unavailable.';
        return $sum;
    }
    $ph = array();
    $params = array(':t' => $tenant);
    foreach ($userIds as $i => $uid) {
        $key = ':u' . $i;
        $ph[] = $key;
        $params[$key] = (int)$uid;
    }
    $q = $pdo->prepare("SELECT id,email,first_name,last_name FROM users WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL AND id IN(" . implode(',', $ph) . ")");
    $q->execute($params);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $email = trim((string)$u['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $sum['email_skipped']++;
            continue;
        }
        $name = trim((string)$u['first_name'] . ' ' . (string)$u['last_name']);
        $subject = 'On-site assessment assigned - ' . $request['request_no'];
        $scheduleText = 'Schedule later';
        if (!empty($assessment['scheduled_start'])) {
            $scheduleText = date('d M Y, h:i A', strtotime($assessment['scheduled_start']));
            if (!empty($assessment['scheduled_end'])) $scheduleText .= ' - ' . date('d M Y, h:i A', strtotime($assessment['scheduled_end']));
        }
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:650px;margin:auto;color:#183548;line-height:1.55">' .
            '<h2 style="color:#0b3142">On-site assessment assigned</h2>' .
            '<p>Hello ' . htmlspecialchars($name !== '' ? $name : 'Team member', ENT_QUOTES, 'UTF-8') . ',</p>' .
            '<p>You have been assigned to an on-site assessment for request <strong>' . htmlspecialchars($request['request_no'], ENT_QUOTES, 'UTF-8') . '</strong>.</p>' .
            '<div style="padding:14px 16px;border:1px solid #e3e9ed;border-radius:8px;background:#f8fafb">' .
            '<p style="margin:0 0 6px"><strong>Request:</strong> ' . htmlspecialchars($request['title'], ENT_QUOTES, 'UTF-8') . '</p>' .
            '<p style="margin:0 0 6px"><strong>Customer:</strong> ' . htmlspecialchars($request['client_name'], ENT_QUOTES, 'UTF-8') . '</p>' .
            '<p style="margin:0"><strong>Schedule:</strong> ' . htmlspecialchars($scheduleText, ENT_QUOTES, 'UTF-8') . '</p>' .
            '</div><p>Please login to FieldPlx for complete request details.</p></div>';
        try {
            fieldplxSendPlatformMail($pdo, $email, $subject, $html);
            $sum['email_sent']++;
        } catch (Throwable $e) {
            $sum['email_failed']++;
            $sum['messages'][] = $email . ': ' . $e->getMessage();
            error_log('request-view team email: ' . $e->getMessage());
        }
    }
    return $sum;
}

function rvNotifyMentions(PDO $pdo, $tenant, $requestId, $requestNo, $title, $userIds)
{
    if (!$userIds || !rvTable($pdo, 'in_app_notifications')) return 0;
    $count = 0;
    foreach ($userIds as $uid) {
        try {
            $s = $pdo->prepare("SELECT id FROM users WHERE id=:id AND tenant_id=:t AND status='active' AND deleted_at IS NULL LIMIT 1");
            $s->execute(array(':id' => (int)$uid, ':t' => $tenant));
            if (!$s->fetchColumn()) continue;
            $n = $pdo->prepare("INSERT INTO in_app_notifications(tenant_id,user_id,title,message,related_type,related_id,action_url,icon_name,is_read) VALUES(:t,:u,'Mentioned in request note',:m,'service_request',:r,:url,'chat-left-text',0)");
            $n->execute(array(
                ':t' => $tenant,
                ':u' => (int)$uid,
                ':m' => 'You were mentioned in an internal note on ' . $requestNo . ' - ' . $title,
                ':r' => $requestId,
                ':url' => 'request-view.php?request_id=' . $requestId
            ));
            $count++;
        } catch (Throwable $e) {
            error_log('request-view mention notify: ' . $e->getMessage());
        }
    }
    return $count;
}

function rvStoreNoteAttachments(PDO $pdo, $tenant, $requestId, $user)
{
    $out = array('saved' => 0, 'failed' => 0);
    if (!rvTable($pdo, 'attachments') || empty($_FILES['note_files']) || !isset($_FILES['note_files']['name'])) return $out;
    $base = dirname(__DIR__) . '/uploads/requests/' . $tenant . '/' . $requestId . '/notes';
    if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) return $out;

    $names = (array)$_FILES['note_files']['name'];
    $tmps = (array)$_FILES['note_files']['tmp_name'];
    $errors = (array)$_FILES['note_files']['error'];
    $sizes = (array)$_FILES['note_files']['size'];
    $types = (array)$_FILES['note_files']['type'];

    for ($i = 0; $i < count($names) && $i < 20; $i++) {
        if ((int)$errors[$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmps[$i])) continue;
        $original = basename((string)$names[$i]);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($original, PATHINFO_FILENAME));
        if ($safe === '') $safe = 'file';
        $stored = $safe . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . ($ext !== '' ? '.' . $ext : '');
        $dest = $base . '/' . $stored;
        if (!@move_uploaded_file($tmps[$i], $dest)) {
            $out['failed']++;
            continue;
        }
        $rel = 'uploads/requests/' . $tenant . '/' . $requestId . '/notes/' . $stored;
        try {
            $q = $pdo->prepare("INSERT INTO attachments(tenant_id,related_type,related_id,uploaded_by,file_name,file_path,file_mime,file_size,attachment_type) VALUES(:t,'service_request',:r,:u,:n,:p,:m,:s,'note_file')");
            $q->execute(array(':t' => $tenant, ':r' => $requestId, ':u' => $user, ':n' => $original, ':p' => $rel, ':m' => isset($types[$i]) ? $types[$i] : null, ':s' => isset($sizes[$i]) ? (int)$sizes[$i] : null));
            $out['saved']++;
        } catch (Throwable $e) {
            $out['failed']++;
            error_log('request-view note attachment: ' . $e->getMessage());
        }
    }
    return $out;
}

function rvStoreLineImages(PDO $pdo, $tenant, $requestId, $user)
{
    $out = array('saved' => 0, 'failed' => 0);
    if (!rvTable($pdo, 'attachments') || empty($_FILES['line_item_images']) || !isset($_FILES['line_item_images']['name'])) return $out;
    $base = dirname(__DIR__) . '/uploads/requests/' . $tenant . '/' . $requestId . '/items';
    if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) return $out;
    $names=(array)$_FILES['line_item_images']['name'];$tmps=(array)$_FILES['line_item_images']['tmp_name'];$errors=(array)$_FILES['line_item_images']['error'];$sizes=(array)$_FILES['line_item_images']['size'];$types=(array)$_FILES['line_item_images']['type'];
    for($i=0;$i<count($names)&&$i<30;$i++){
        if((int)$errors[$i]!==UPLOAD_ERR_OK || !is_uploaded_file($tmps[$i]))continue;
        $original=basename((string)$names[$i]);$ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));$safe=preg_replace('/[^A-Za-z0-9._-]+/','-',pathinfo($original,PATHINFO_FILENAME));if($safe==='')$safe='item-image';
        $stored=$safe.'-'.date('YmdHis').'-'.bin2hex(random_bytes(3)).($ext!==''?'.'.$ext:'');$dest=$base.'/'.$stored;
        if(!@move_uploaded_file($tmps[$i],$dest)){$out['failed']++;continue;}
        $rel='uploads/requests/'.$tenant.'/'.$requestId.'/items/'.$stored;
        try{$q=$pdo->prepare("INSERT INTO attachments(tenant_id,related_type,related_id,uploaded_by,file_name,file_path,file_mime,file_size,attachment_type) VALUES(:t,'service_request',:r,:u,:n,:p,:m,:s,'line_item_image')");$q->execute(array(':t'=>$tenant,':r'=>$requestId,':u'=>$user,':n'=>$original,':p'=>$rel,':m'=>isset($types[$i])?$types[$i]:null,':s'=>isset($sizes[$i])?(int)$sizes[$i]:null));$out['saved']++;}catch(Throwable $e){$out['failed']++;error_log('request-view line image: '.$e->getMessage());}
    }
    return $out;
}

function rvLoadNotes(PDO $pdo, $tenant, $requestId)
{
    if (!rvTable($pdo, 'activity_events')) return array();
    $s = $pdo->prepare("SELECT ae.id,ae.title,ae.details_json,ae.created_at,ae.actor_user_id,CONCAT_WS(' ',u.first_name,u.last_name) actor_name FROM activity_events ae LEFT JOIN users u ON u.id=ae.actor_user_id AND u.tenant_id=ae.tenant_id WHERE ae.tenant_id=:t AND ae.related_type='service_request' AND ae.related_id=:r AND ae.event_type IN('service_request_note_added','request_note_added') ORDER BY ae.id DESC LIMIT 50");
    $s->execute(array(':t' => $tenant, ':r' => $requestId));
    $out = array();
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details = json_decode((string)$row['details_json'], true);
        $row['note'] = is_array($details) && isset($details['note']) ? (string)$details['note'] : (string)$row['title'];
        $row['mentioned_user_ids'] = is_array($details) && isset($details['mentioned_user_ids']) && is_array($details['mentioned_user_ids']) ? $details['mentioned_user_ids'] : array();
        $out[] = $row;
    }
    return $out;
}

function rvLoadHistory(PDO $pdo, $tenant, $requestId)
{
    $out = array();
    if (rvTable($pdo, 'activity_events')) {
        $s = $pdo->prepare("SELECT ae.id,ae.event_type,ae.title,ae.details_json,ae.created_at,ae.actor_user_id,CONCAT_WS(' ',u.first_name,u.last_name) actor_name FROM activity_events ae LEFT JOIN users u ON u.id=ae.actor_user_id AND u.tenant_id=ae.tenant_id WHERE ae.tenant_id=:t AND ae.related_type='service_request' AND ae.related_id=:r ORDER BY ae.id DESC LIMIT 100");
        $s->execute(array(':t' => $tenant, ':r' => $requestId));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['details'] = json_decode((string)$row['details_json'], true);
            unset($row['details_json']);
            $out[] = $row;
        }
    }
    if (rvTable($pdo, 'request_status_history')) {
        /* Core FieldPlx schema uses changed_at. Older/custom installs may use created_at. */
        $historyDateColumn = rvColumn($pdo, 'request_status_history', 'changed_at')
            ? 'changed_at'
            : (rvColumn($pdo, 'request_status_history', 'created_at') ? 'created_at' : null);

        if ($historyDateColumn !== null) {
            try {
                $sql = "SELECT h.id,h.old_status,h.new_status,h.notes,h." . $historyDateColumn . " AS created_at,h.changed_by,CONCAT_WS(' ',u.first_name,u.last_name) actor_name FROM request_status_history h LEFT JOIN users u ON u.id=h.changed_by AND u.tenant_id=h.tenant_id WHERE h.tenant_id=:t AND h.request_id=:r ORDER BY h.id DESC LIMIT 50";
                $s = $pdo->prepare($sql);
                $s->execute(array(':t' => $tenant, ':r' => $requestId));
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $out[] = array(
                        'id' => 'status-' . $row['id'],
                        'event_type' => 'request_status_changed',
                        'title' => 'Request status changed',
                        'created_at' => $row['created_at'],
                        'actor_user_id' => $row['changed_by'],
                        'actor_name' => $row['actor_name'],
                        'details' => array('old_status' => $row['old_status'], 'new_status' => $row['new_status'], 'notes' => $row['notes'])
                    );
                }
            } catch (Throwable $e) {
                /* History is supplemental; never make the entire Request View unavailable. */
                error_log('request-view status history load: ' . $e->getMessage());
            }
        }
    }
    usort($out, function ($a, $b) {
        return strtotime((string)$b['created_at']) - strtotime((string)$a['created_at']);
    });
    return array_slice($out, 0, 120);
}

function rvLoadAttachments(PDO $pdo, $tenant, $requestId)
{
    if (!rvTable($pdo, 'attachments')) return array();
    $s = $pdo->prepare("SELECT id,file_name,file_path,file_mime,file_size,attachment_type,created_at FROM attachments WHERE tenant_id=:t AND related_type='service_request' AND related_id=:r ORDER BY id DESC");
    $s->execute(array(':t' => $tenant, ':r' => $requestId));
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function rvLoadRelated(PDO $pdo, $tenant, $requestId)
{
    $related = array('quotes' => array(), 'jobs' => array());
    if (rvTable($pdo, 'quotes')) {
        $s = $pdo->prepare("SELECT id,quote_no,title,status,total,created_at FROM quotes WHERE tenant_id=:t AND request_id=:r ORDER BY id DESC");
        $s->execute(array(':t' => $tenant, ':r' => $requestId));
        $related['quotes'] = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    if (rvTable($pdo, 'jobs')) {
        $sql = "SELECT id,job_no,title,status,start_date,created_at FROM jobs WHERE tenant_id=:t AND request_id=:r";
        if (rvColumn($pdo, 'jobs', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
        $sql .= " ORDER BY id DESC";
        $s = $pdo->prepare($sql);
        $s->execute(array(':t' => $tenant, ':r' => $requestId));
        $related['jobs'] = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    return $related;
}

$tenant = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$user = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
$sessionBranch = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
if ($tenant <= 0 || $user <= 0) rvApiResponse(401, false, 'Authentication required.');

$csrf = (string)rvPost('csrf_token', '');
$sessionCsrf = isset($_SESSION['request_view_csrf_token']) ? (string)$_SESSION['request_view_csrf_token'] : '';
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    rvApiResponse(419, false, 'Your request view session expired. Refresh the page and try again.');
}

$action = trim((string)rvPost('action', ''));
$requestId = (int)rvPost('request_id', 0);
if ($action !== 'create_catalog_item' && $action !== 'load') {
    if (rvValidRequest($pdo, $tenant, $requestId) === null) rvApiResponse(404, false, 'Service request not found.');
}

try {
    if ($action === 'load') {
        if (rvValidRequest($pdo, $tenant, $requestId) === null) rvApiResponse(404, false, 'Service request not found.');
        $request = rvLoadRequest($pdo, $tenant, $requestId);
        $assessment = rvLoadAssessment($pdo, $tenant, $requestId);
        $data = array(
            'request' => $request,
            'assessment' => $assessment,
            'checklists' => rvLoadChecklists($pdo, $tenant, $requestId),
            'line_items' => rvLoadLines($pdo, $tenant, $requestId),
            'catalog' => rvLoadCatalog($pdo, $tenant),
            'users' => rvLoadUsers($pdo, $tenant),
            'currency' => rvCurrency($pdo, $tenant),
            'notes' => rvLoadNotes($pdo, $tenant, $requestId),
            'history' => rvLoadHistory($pdo, $tenant, $requestId),
            'attachments' => rvLoadAttachments($pdo, $tenant, $requestId),
            'related' => rvLoadRelated($pdo, $tenant, $requestId),
            'supports_product_id' => rvColumn($pdo, 'service_request_line_items', 'product_id') ? 1 : 0
        );
        rvApiResponse(200, true, 'Request loaded.', array('data' => $data));
    }

    if ($action === 'save_overview') {
        $old = rvLoadRequest($pdo, $tenant, $requestId);
        $title = substr(trim((string)rvPost('title', '')), 0, 255);
        $description = trim((string)rvPost('description', ''));
        $source = strtolower(trim((string)rvPost('source', 'office')));
        if ($title === '') rvApiResponse(422, false, 'Request title is required.');
        if (!in_array($source, array('office','website','portal','phone','sms','email','ai','other'), true)) $source = 'other';
        $u = $pdo->prepare("UPDATE service_requests SET title=:title,description=:d,source=:src,updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        $u->execute(array(':title' => $title, ':d' => $description !== '' ? $description : null, ':src' => $source, ':id' => $requestId, ':t' => $tenant));
        $new = rvLoadRequest($pdo, $tenant, $requestId);
        rvActivity($pdo, $tenant, (int)$new['branch_id'], $user, 'service_request_overview_updated', $requestId, (int)$new['client_id'], 'Request overview updated', array('old' => array('title'=>$old['title'],'description'=>$old['description'],'source'=>$old['source']), 'new' => array('title'=>$title,'description'=>$description,'source'=>$source)));
        rvAudit($pdo, 'SERVICE_REQUEST_OVERVIEW_UPDATED', $tenant, (int)$new['branch_id'], $user, $requestId, $old, $new);
        rvApiResponse(200, true, 'Request details saved.', array('request' => $new));
    }

    if ($action === 'save_assessment') {
        if (!rvTable($pdo, 'assessments')) rvApiResponse(500, false, 'Assessments table is unavailable.');
        $request = rvLoadRequest($pdo, $tenant, $requestId);
        $assessmentId = (int)rvPost('assessment_id', 0);
        $scheduleLater = (string)rvPost('schedule_later', '0') === '1';
        $anytime = (string)rvPost('anytime', '0') === '1';
        $startDate = trim((string)rvPost('start_date', ''));
        $endDate = trim((string)rvPost('end_date', ''));
        $startTime = trim((string)rvPost('start_time', ''));
        $endTime = trim((string)rvPost('end_time', ''));
        $instructions = trim((string)rvPost('instructions', ''));
        $reminder = trim((string)rvPost('team_reminder', 'none'));
        $emailTeam = (string)rvPost('email_team_when_assigned', '0') === '1';
        $assignedIds = json_decode((string)rvPost('assigned_user_ids', '[]'), true);
        if (!is_array($assignedIds)) $assignedIds = array();
        $cleanUsers = array();
        foreach ($assignedIds as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) continue;
            $s = $pdo->prepare("SELECT id FROM users WHERE id=:id AND tenant_id=:t AND status='active' AND deleted_at IS NULL LIMIT 1");
            $s->execute(array(':id' => $uid, ':t' => $tenant));
            if ($s->fetchColumn()) $cleanUsers[$uid] = $uid;
        }
        $cleanUsers = array_values($cleanUsers);
        $primaryUser = $cleanUsers ? (int)$cleanUsers[0] : null;

        $scheduledStart = null;
        $scheduledEnd = null;
        $status = 'draft';
        if (!$scheduleLater) {
            if ($startDate === '') rvApiResponse(422, false, 'Select the assessment start date or choose Schedule later.');
            if ($endDate === '') $endDate = $startDate;
            if ($anytime) {
                $startTime = '00:00';
                $endTime = '23:59';
            }
            if ($startTime === '' || $endTime === '') rvApiResponse(422, false, 'Enter the assessment start and end time, or choose Anytime.');
            $scheduledStart = $startDate . ' ' . $startTime . ':00';
            $scheduledEnd = $endDate . ' ' . $endTime . ':00';
            if (strtotime($scheduledEnd) <= strtotime($scheduledStart)) rvApiResponse(422, false, 'Assessment end must be after the start.');
            $status = 'scheduled';
        }

        $oldAssessment = rvLoadAssessment($pdo, $tenant, $requestId);
        $pdo->beginTransaction();
        try {
            if ($assessmentId > 0) {
                $check = $pdo->prepare("SELECT id FROM assessments WHERE id=:id AND tenant_id=:t AND request_id=:r LIMIT 1 FOR UPDATE");
                $check->execute(array(':id' => $assessmentId, ':t' => $tenant, ':r' => $requestId));
                if (!$check->fetchColumn()) throw new RuntimeException('Assessment not found.');
                $set = array('assigned_user_id=:uid','scheduled_start=:ss','scheduled_end=:se','status=:st','notes=:notes');
                $params = array(':uid'=>$primaryUser,':ss'=>$scheduledStart,':se'=>$scheduledEnd,':st'=>$status,':notes'=>$instructions !== '' ? $instructions : null,':id'=>$assessmentId,':t'=>$tenant,':r'=>$requestId);
                if (rvColumn($pdo,'assessments','team_reminder')) { $set[]='team_reminder=:reminder'; $params[':reminder']=$reminder !== '' ? $reminder : 'none'; }
                if (rvColumn($pdo,'assessments','schedule_later')) { $set[]='schedule_later=:later'; $params[':later']=$scheduleLater?1:0; }
                if (rvColumn($pdo,'assessments','anytime')) { $set[]='anytime=:any'; $params[':any']=$anytime?1:0; }
                $u = $pdo->prepare("UPDATE assessments SET ".implode(',', $set)." WHERE id=:id AND tenant_id=:t AND request_id=:r");
                $u->execute($params);
            } else {
                $number = rvNextAssessmentNo($pdo, $tenant, (int)$request['branch_id']);
                $cols=array('tenant_id','branch_id','assessment_no','request_id','client_id','location_id','assigned_user_id','scheduled_start','scheduled_end','status','notes','created_by');
                $vals=array(':t',':b',':no',':r',':c',':l',':uid',':ss',':se',':st',':notes',':u');
                $params=array(':t'=>$tenant,':b'=>!empty($request['branch_id'])?(int)$request['branch_id']:null,':no'=>$number,':r'=>$requestId,':c'=>(int)$request['client_id'],':l'=>!empty($request['location_id'])?(int)$request['location_id']:null,':uid'=>$primaryUser,':ss'=>$scheduledStart,':se'=>$scheduledEnd,':st'=>$status,':notes'=>$instructions !== '' ? $instructions : null,':u'=>$user);
                if(rvColumn($pdo,'assessments','team_reminder')){$cols[]='team_reminder';$vals[]=':reminder';$params[':reminder']=$reminder!==''?$reminder:'none';}
                if(rvColumn($pdo,'assessments','schedule_later')){$cols[]='schedule_later';$vals[]=':later';$params[':later']=$scheduleLater?1:0;}
                if(rvColumn($pdo,'assessments','anytime')){$cols[]='anytime';$vals[]=':any';$params[':any']=$anytime?1:0;}
                $s=$pdo->prepare("INSERT INTO assessments(".implode(',', $cols).") VALUES(".implode(',', $vals).")");
                $s->execute($params);
                $assessmentId = (int)$pdo->lastInsertId();
            }

            if (rvTable($pdo, 'request_assignments')) {
                $d = $pdo->prepare("DELETE FROM request_assignments WHERE tenant_id=:t AND request_id=:r");
                $d->execute(array(':t'=>$tenant,':r'=>$requestId));
                if ($cleanUsers) {
                    $ins = $pdo->prepare("INSERT INTO request_assignments(tenant_id,request_id,assignment_mode,team_id,user_id,is_primary,assigned_by) VALUES(:t,:r,'multiple',NULL,:uid,:p,:u)");
                    foreach ($cleanUsers as $i => $uid) {
                        $ins->execute(array(':t'=>$tenant,':r'=>$requestId,':uid'=>$uid,':p'=>$i===0?1:0,':u'=>$user));
                    }
                }
            }

            $checklist = rvSaveChecklist($pdo, $tenant, $requestId, $user, (string)rvPost('new_checklist_json', ''));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $assessment = rvLoadAssessment($pdo, $tenant, $requestId);
        $mail = $emailTeam ? rvSendTeamMail($pdo, $tenant, (int)$request['branch_id'], $cleanUsers, $request, $assessment) : array('email_sent'=>0,'email_failed'=>0,'email_skipped'=>0,'messages'=>array());
        rvActivity($pdo, $tenant, (int)$request['branch_id'], $user, $oldAssessment ? 'service_request_assessment_updated' : 'service_request_assessment_created', $requestId, (int)$request['client_id'], $oldAssessment ? 'On-site assessment updated' : 'On-site assessment scheduled', array('assessment_id'=>$assessmentId,'assigned_user_ids'=>$cleanUsers,'email_team'=>$emailTeam,'mail'=>$mail,'team_reminder'=>$reminder,'checklist'=>$checklist));
        rvAudit($pdo, $oldAssessment ? 'SERVICE_REQUEST_ASSESSMENT_UPDATED' : 'SERVICE_REQUEST_ASSESSMENT_CREATED', $tenant, (int)$request['branch_id'], $user, $requestId, $oldAssessment, $assessment);
        rvApiResponse(200, true, $oldAssessment ? 'Assessment updated.' : 'Assessment scheduled.', array('assessment'=>$assessment,'team_email'=>$mail,'checklists'=>rvLoadChecklists($pdo,$tenant,$requestId)));
    }

    if ($action === 'save_lines') {
        $request = rvLoadRequest($pdo, $tenant, $requestId);
        $old = rvLoadLines($pdo, $tenant, $requestId);
        $pdo->beginTransaction();
        try {
            $normalized = rvSaveLines($pdo, $tenant, $requestId, (string)rvPost('line_items_json', '[]'));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $lineImages=rvStoreLineImages($pdo,$tenant,$requestId,$user);
        rvActivity($pdo, $tenant, (int)$request['branch_id'], $user, 'service_request_line_items_updated', $requestId, (int)$request['client_id'], 'Request products and services updated', array('old_count'=>count($old),'new_count'=>count($normalized),'line_images'=>$lineImages));
        rvAudit($pdo, 'SERVICE_REQUEST_LINE_ITEMS_UPDATED', $tenant, (int)$request['branch_id'], $user, $requestId, $old, array('lines'=>$normalized,'line_images'=>$lineImages));
        rvApiResponse(200, true, 'Product / Service items saved.', array('line_items'=>rvLoadLines($pdo,$tenant,$requestId),'line_images'=>$lineImages));
    }

    if ($action === 'create_catalog_item') {
        $itemType = strtolower(trim((string)rvPost('item_type', 'service')));
        if (!in_array($itemType, array('service','product'), true)) rvApiResponse(422, false, 'Select Service or Product.');
        $name = substr(trim((string)rvPost('name', '')), 0, 190);
        $description = trim((string)rvPost('description', ''));
        $unitCost = max(0, (float)rvPost('unit_cost', 0));
        $markup = max(0, (float)rvPost('markup_percent', 0));
        $unitPriceRaw = trim((string)rvPost('unit_price', ''));
        $unitPrice = $unitPriceRaw === '' ? round($unitCost * (1 + ($markup / 100)), 2) : max(0, (float)$unitPriceRaw);
        $taxPercent = max(0, min(100, (float)rvPost('tax_percent', 0)));
        if ($name === '') rvApiResponse(422, false, 'Product / Service name is required.');

        if ($itemType === 'service') {
            if (!rvTable($pdo, 'product_services')) rvApiResponse(500, false, 'Service table is unavailable.');
            $sql = "SELECT id,name,description,unit_cost,unit_price,tax_percent FROM product_services WHERE tenant_id=:t AND item_type='service' AND LOWER(name)=LOWER(:n) AND status='active'";
            if (rvColumn($pdo, 'product_services', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
            $sql .= " LIMIT 1";
            $f = $pdo->prepare($sql);
            $f->execute(array(':t'=>$tenant,':n'=>$name));
            $existing = $f->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $existing['id'] = 's:' . (int)$existing['id'];
                $existing['source_id'] = (int)substr($existing['id'], 2);
                $existing['item_source'] = 'service';
                $existing['item_type'] = 'service';
                rvApiResponse(200, true, 'Existing service selected.', array('item'=>$existing,'created'=>0));
            }
            $s = $pdo->prepare("INSERT INTO product_services(tenant_id,category_id,item_type,name,sku,description,unit_name,unit_cost,unit_price,tax_percent,is_bookable,estimated_duration_minutes,status,created_at,updated_at,deleted_at) VALUES(:t,NULL,'service',:n,NULL,:d,'Service',:cost,:price,:tax,0,NULL,'active',NOW(),NOW(),NULL)");
            $s->execute(array(':t'=>$tenant,':n'=>$name,':d'=>$description !== '' ? $description : null,':cost'=>$unitCost,':price'=>$unitPrice,':tax'=>$taxPercent));
            $id = (int)$pdo->lastInsertId();
            $item = array('id'=>'s:'.$id,'source_id'=>$id,'item_source'=>'service','item_type'=>'service','name'=>$name,'description'=>$description,'unit_cost'=>$unitCost,'unit_price'=>$unitPrice,'tax_percent'=>$taxPercent);
            rvActivity($pdo,$tenant,$sessionBranch,$user,'service_created_from_request_view',0,0,'Service created from request view',array('item'=>$item));
            rvApiResponse(200,true,'Service created successfully.',array('item'=>$item,'created'=>1));
        }

        if (!rvTable($pdo, 'products')) rvApiResponse(500, false, 'Products table is unavailable.');
        $sql = "SELECT id,name" . (rvColumn($pdo,'products','description')?',description':",NULL AS description") . (rvColumn($pdo,'products','base_unit_price')?',base_unit_price':",0 AS base_unit_price") . (rvColumn($pdo,'products','selling_price')?',selling_price':",0 AS selling_price") . (rvColumn($pdo,'products','tax_percent')?',tax_percent':",0 AS tax_percent") . " FROM products WHERE tenant_id=:t AND LOWER(name)=LOWER(:n) AND status='active'" . (rvColumn($pdo,'products','deleted_at')?" AND deleted_at IS NULL":"") . " LIMIT 1";
        $f = $pdo->prepare($sql);
        $f->execute(array(':t'=>$tenant,':n'=>$name));
        $existing = $f->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $id = (int)$existing['id'];
            $item = array('id'=>'p:'.$id,'source_id'=>$id,'item_source'=>'product','item_type'=>'product','name'=>$existing['name'],'description'=>(string)$existing['description'],'unit_cost'=>(float)$existing['base_unit_price'],'unit_price'=>(float)$existing['selling_price'],'tax_percent'=>(float)$existing['tax_percent']);
            rvApiResponse(200,true,'Existing product selected.',array('item'=>$item,'created'=>0));
        }
        $columns = array('tenant_id','name','status','created_at','updated_at');
        $values = array(':t',':n',"'active'",'NOW()','NOW()');
        $params = array(':t'=>$tenant,':n'=>$name);
        if (rvColumn($pdo,'products','description')) { $columns[]='description';$values[]=':d';$params[':d']=$description!==''?$description:null; }
        if (rvColumn($pdo,'products','unit_name')) { $columns[]='unit_name';$values[]="'Unit'"; }
        if (rvColumn($pdo,'products','base_unit_price')) { $columns[]='base_unit_price';$values[]=':cost';$params[':cost']=$unitCost; }
        if (rvColumn($pdo,'products','markup_type')) { $columns[]='markup_type';$values[]="'percentage'"; }
        if (rvColumn($pdo,'products','markup_value')) { $columns[]='markup_value';$values[]=':markup';$params[':markup']=$markup; }
        if (rvColumn($pdo,'products','selling_price')) { $columns[]='selling_price';$values[]=':price';$params[':price']=$unitPrice; }
        if (rvColumn($pdo,'products','tax_percent')) { $columns[]='tax_percent';$values[]=':tax';$params[':tax']=$taxPercent; }
        if (rvColumn($pdo,'products','track_inventory')) { $columns[]='track_inventory';$values[]='0'; }
        if (rvColumn($pdo,'products','created_by')) { $columns[]='created_by';$values[]=':u';$params[':u']=$user; }
        if (rvColumn($pdo,'products','deleted_at')) { $columns[]='deleted_at';$values[]='NULL'; }
        $s = $pdo->prepare("INSERT INTO products(" . implode(',', $columns) . ") VALUES(" . implode(',', $values) . ")");
        $s->execute($params);
        $id = (int)$pdo->lastInsertId();
        $item = array('id'=>'p:'.$id,'source_id'=>$id,'item_source'=>'product','item_type'=>'product','name'=>$name,'description'=>$description,'unit_cost'=>$unitCost,'unit_price'=>$unitPrice,'tax_percent'=>$taxPercent);
        rvApiResponse(200,true,'Product created successfully.',array('item'=>$item,'created'=>1));
    }

    if ($action === 'add_note') {
        $request = rvLoadRequest($pdo, $tenant, $requestId);
        $note = trim((string)rvPost('note', ''));
        if ($note === '') rvApiResponse(422, false, 'Enter a note.');
        $mentions = json_decode((string)rvPost('mention_user_ids', '[]'), true);
        if (!is_array($mentions)) $mentions = array();
        $clean = array();
        foreach ($mentions as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) $clean[$uid] = $uid;
        }
        $clean = array_values($clean);
        $files = rvStoreNoteAttachments($pdo, $tenant, $requestId, $user);
        rvActivity($pdo, $tenant, (int)$request['branch_id'], $user, 'service_request_note_added', $requestId, (int)$request['client_id'], 'Internal note added', array('note'=>$note,'mentioned_user_ids'=>$clean,'attachments'=>$files));
        $notified = rvNotifyMentions($pdo, $tenant, $requestId, $request['request_no'], $request['title'], $clean);
        rvAudit($pdo, 'SERVICE_REQUEST_NOTE_ADDED', $tenant, (int)$request['branch_id'], $user, $requestId, null, array('note'=>$note,'mentioned_user_ids'=>$clean,'attachments'=>$files));
        rvApiResponse(200, true, 'Note added.', array('notes'=>rvLoadNotes($pdo,$tenant,$requestId),'mention_notifications'=>$notified,'attachments'=>$files));
    }

    if ($action === 'archive') {
        $old = rvLoadRequest($pdo, $tenant, $requestId);
        if (rvColumn($pdo, 'service_requests', 'archived_at')) {
            $u = $pdo->prepare("UPDATE service_requests SET archived_at=NOW(),updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        } elseif (rvColumn($pdo, 'service_requests', 'is_archived')) {
            $u = $pdo->prepare("UPDATE service_requests SET is_archived=1,updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        } elseif (rvStatusAllows($pdo, 'archived')) {
            $u = $pdo->prepare("UPDATE service_requests SET status='archived',updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        } else {
            $u = $pdo->prepare("UPDATE service_requests SET status='closed',updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        }
        $u->execute(array(':id'=>$requestId,':t'=>$tenant));
        $new = rvLoadRequest($pdo,$tenant,$requestId);
        rvActivity($pdo,$tenant,(int)$old['branch_id'],$user,'service_request_archived',$requestId,(int)$old['client_id'],'Service request archived',array());
        rvAudit($pdo,'SERVICE_REQUEST_ARCHIVED',$tenant,(int)$old['branch_id'],$user,$requestId,$old,$new);
        rvApiResponse(200,true,'Request archived.');
    }

    if ($action === 'delete') {
        $old = rvLoadRequest($pdo, $tenant, $requestId);
        if (rvColumn($pdo, 'service_requests', 'deleted_at')) {
            $u = $pdo->prepare("UPDATE service_requests SET deleted_at=NOW(),updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        } else {
            $u = $pdo->prepare("UPDATE service_requests SET status='cancelled',updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        }
        $u->execute(array(':id'=>$requestId,':t'=>$tenant));
        rvActivity($pdo,$tenant,(int)$old['branch_id'],$user,'service_request_deleted',$requestId,(int)$old['client_id'],'Service request deleted',array('soft_delete'=>rvColumn($pdo,'service_requests','deleted_at')?1:0));
        rvAudit($pdo,'SERVICE_REQUEST_DELETED',$tenant,(int)$old['branch_id'],$user,$requestId,$old,null);
        rvApiResponse(200,true,'Request deleted.');
    }

    rvApiResponse(400, false, 'Unsupported request view action.');
} catch (PDOException $e) {
    error_log('request-view PDO: ' . $e->getMessage());
    rvApiResponse(500, false, 'Unable to update the service request.');
} catch (Throwable $e) {
    error_log('request-view: ' . $e->getMessage());
    rvApiResponse(500, false, $e->getMessage() !== '' ? $e->getMessage() : 'Unable to update the service request.');
}
