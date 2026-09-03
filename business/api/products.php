<?php
/* FieldPlx Products API - Version 2.0.0 - 2026-09-03 - Import Export Support */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function pmOut($status, $success, $message, $extra = array())
{
    http_response_code((int)$status);
    echo json_encode(
        array_merge(array('success' => (bool)$success, 'message' => (string)$message), $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function pmDb()
{
    global $pdo, $db;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    throw new RuntimeException('PDO database connection is not available.');
}

function pmTenantId()
{
    if (!empty($_SESSION['tenant_id'])) return (int)$_SESSION['tenant_id'];
    if (!empty($_SESSION['business_id'])) return (int)$_SESSION['business_id'];
    return 0;
}

function pmUserId()
{
    if (!empty($_SESSION['tenant_user_id'])) return (int)$_SESSION['tenant_user_id'];
    if (!empty($_SESSION['user_id'])) return (int)$_SESSION['user_id'];
    return 0;
}

function pmSellingPrice($basePrice, $markupType, $markupValue)
{
    $basePrice = max(0, (float)$basePrice);
    $markupValue = max(0, (float)$markupValue);

    if ($markupType === 'fixed') {
        return round($basePrice + $markupValue, 2);
    }

    return round($basePrice + ($basePrice * $markupValue / 100), 2);
}

try {
    $pdo = pmDb();
    $tenantId = pmTenantId();
    $userId = pmUserId();

    if ($tenantId <= 0 || $userId <= 0) {
        pmOut(401, false, 'Your login session is not valid.');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pmOut(405, false, 'Method not allowed.');
    }

    $csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $sessionCsrf = isset($_SESSION['products_csrf_token']) ? (string)$_SESSION['products_csrf_token'] : '';
    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        pmOut(419, false, 'Your form session expired. Refresh the page and try again.');
    }

    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

    if ($action === 'list') {
        $page = max(1, (int)(isset($_POST['page']) ? $_POST['page'] : 1));
        $perPage = (int)(isset($_POST['per_page']) ? $_POST['per_page'] : 10);
        if (!in_array($perPage, array(10, 25, 50, 100), true)) $perPage = 10;

        $search = trim((string)(isset($_POST['search']) ? $_POST['search'] : ''));
        $status = trim((string)(isset($_POST['status']) ? $_POST['status'] : ''));

        if ($status !== '' && !in_array($status, array('active', 'inactive', 'archived'), true)) {
            pmOut(422, false, 'Invalid status filter.');
        }

        $where = 'p.tenant_id = :tenant_id AND p.deleted_at IS NULL';
        $params = array(':tenant_id' => $tenantId);

        if ($search !== '') {
            $where .= ' AND (p.name LIKE :search_name OR p.sku LIKE :search_sku OR p.description LIKE :search_desc)';
            $like = '%' . $search . '%';
            $params[':search_name'] = $like;
            $params[':search_sku'] = $like;
            $params[':search_desc'] = $like;
        }

        if ($status !== '') {
            $where .= ' AND p.status = :status';
            $params[':status'] = $status;
        }

        $count = $pdo->prepare('SELECT COUNT(*) FROM products p WHERE ' . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT p.id, p.sku, p.name, p.description, p.unit_name, p.base_unit_price,
                       p.markup_type, p.markup_value, p.selling_price, p.tax_percent,
                       p.status, p.created_by, p.created_at, p.updated_at
                FROM products p
                WHERE ' . $where . '
                ORDER BY COALESCE(p.updated_at, p.created_at) DESC, p.id DESC
                LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['created_display'] = !empty($row['created_at']) ? date('d M Y', strtotime($row['created_at'])) : '-';
            $row['updated_display'] = !empty($row['updated_at']) ? date('d M Y', strtotime($row['updated_at'])) : '';
        }
        unset($row);

        $summary = array(
            'total' => (int)$pdo->query('SELECT COUNT(*) FROM products WHERE tenant_id=' . (int)$tenantId . ' AND deleted_at IS NULL')->fetchColumn(),
            'active' => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE tenant_id=" . (int)$tenantId . " AND deleted_at IS NULL AND status='active'")->fetchColumn(),
            'inactive' => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE tenant_id=" . (int)$tenantId . " AND deleted_at IS NULL AND status='inactive'")->fetchColumn(),
            'archived' => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE tenant_id=" . (int)$tenantId . " AND deleted_at IS NULL AND status='archived'")->fetchColumn()
        );

        $cur = $pdo->prepare('SELECT c.symbol, c.symbol_position, c.decimal_places
                              FROM tenants t
                              LEFT JOIN currencies c ON c.id = t.currency_id
                              WHERE t.id = :tenant_id
                              LIMIT 1');
        $cur->execute(array(':tenant_id' => $tenantId));
        $currency = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$currency) {
            $currency = array('symbol' => '', 'symbol_position' => 'before', 'decimal_places' => 2);
        }

        $from = $total > 0 ? $offset + 1 : 0;
        $to = min($offset + $perPage, $total);

        pmOut(200, true, 'Products loaded.', array(
            'products' => $rows,
            'summary' => $summary,
            'currency' => array(
                'symbol' => (string)(isset($currency['symbol']) ? $currency['symbol'] : ''),
                'position' => (string)(isset($currency['symbol_position']) ? $currency['symbol_position'] : 'before'),
                'decimals' => (int)(isset($currency['decimal_places']) ? $currency['decimal_places'] : 2)
            ),
            'pagination' => array(
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages,
                'from' => $from,
                'to' => $to
            )
        ));
    }


    if ($action === 'export') {
        $search = trim((string)(isset($_POST['search']) ? $_POST['search'] : ''));
        $status = trim((string)(isset($_POST['status']) ? $_POST['status'] : ''));

        if ($status !== '' && !in_array($status, array('active', 'inactive', 'archived'), true)) {
            pmOut(422, false, 'Invalid status filter.');
        }

        $where = 'p.tenant_id = :tenant_id AND p.deleted_at IS NULL';
        $params = array(':tenant_id' => $tenantId);
        if ($search !== '') {
            $where .= ' AND (p.name LIKE :search_name OR p.sku LIKE :search_sku OR p.description LIKE :search_desc)';
            $like = '%' . $search . '%';
            $params[':search_name'] = $like;
            $params[':search_sku'] = $like;
            $params[':search_desc'] = $like;
        }
        if ($status !== '') {
            $where .= ' AND p.status = :status';
            $params[':status'] = $status;
        }

        $st = $pdo->prepare('SELECT p.id, p.sku, p.name, p.description, p.unit_name, p.base_unit_price,
                                    p.markup_type, p.markup_value, p.selling_price, p.tax_percent,
                                    p.status, p.created_at, p.updated_at
                             FROM products p
                             WHERE ' . $where . '
                             ORDER BY p.name ASC, p.id ASC');
        $st->execute($params);
        pmOut(200, true, 'Products prepared for export.', array('products' => $st->fetchAll(PDO::FETCH_ASSOC)));
    }

    if ($action === 'import') {
        $rawRows = isset($_POST['rows_json']) ? (string)$_POST['rows_json'] : '';
        $rows = json_decode($rawRows, true);
        if (!is_array($rows)) pmOut(422, false, 'Invalid product import data.');
        if (count($rows) > 1000) pmOut(422, false, 'Import a maximum of 1000 products at one time.');

        $insert = $pdo->prepare('INSERT INTO products
            (tenant_id, sku, name, description, unit_name, base_unit_price,
             markup_type, markup_value, selling_price, tax_percent, status,
             created_by, created_at, updated_at, deleted_at)
            VALUES
            (:tenant_id, :sku, :name, :description, :unit_name, :base_unit_price,
             :markup_type, :markup_value, :selling_price, :tax_percent, :status,
             :created_by, NOW(), NOW(), NULL)');
        $skuCheck = $pdo->prepare('SELECT id, name, status FROM products
                                  WHERE tenant_id = :tenant_id AND sku = :sku AND deleted_at IS NULL
                                  LIMIT 1');

        $imported = 0;
        $existing = 0;
        $failed = 0;
        $results = array();

        foreach ($rows as $index => $row) {
            $rowNo = $index + 1;
            if (!is_array($row)) {
                $failed++;
                $results[] = array('row' => $rowNo, 'status' => 'Failed', 'message' => 'Invalid row format.', 'input' => array());
                continue;
            }

            $name = trim((string)(isset($row['name']) ? $row['name'] : ''));
            $sku = trim((string)(isset($row['sku']) ? $row['sku'] : ''));
            $description = trim((string)(isset($row['description']) ? $row['description'] : ''));
            $unitName = trim((string)(isset($row['unit_name']) ? $row['unit_name'] : 'unit'));
            $markupType = strtolower(trim((string)(isset($row['markup_type']) ? $row['markup_type'] : 'percentage')));
            $status = strtolower(trim((string)(isset($row['status']) ? $row['status'] : 'active')));
            $baseRaw = str_replace(',', '', trim((string)(isset($row['base_unit_price']) ? $row['base_unit_price'] : '0')));
            $markupRaw = str_replace(',', '', trim((string)(isset($row['markup_value']) ? $row['markup_value'] : '0')));
            $taxRaw = str_replace(',', '', trim((string)(isset($row['tax_percent']) ? $row['tax_percent'] : '0')));

            $input = array(
                'sku' => $sku,
                'name' => $name,
                'description' => $description,
                'unit_name' => $unitName,
                'base_unit_price' => $baseRaw,
                'markup_type' => $markupType,
                'markup_value' => $markupRaw,
                'tax_percent' => $taxRaw,
                'status' => $status
            );

            $error = '';
            if ($name === '') $error = 'Product Name is required.';
            elseif ($baseRaw === '' || !is_numeric($baseRaw) || (float)$baseRaw < 0) $error = 'Base Price must be zero or a positive number.';
            elseif (!in_array($markupType, array('percentage', 'fixed'), true)) $error = 'Markup Type must be percentage or fixed.';
            elseif ($markupRaw === '' || !is_numeric($markupRaw) || (float)$markupRaw < 0) $error = 'Markup Value must be zero or a positive number.';
            elseif ($taxRaw === '' || !is_numeric($taxRaw) || (float)$taxRaw < 0 || (float)$taxRaw > 100) $error = 'Tax Percent must be between 0 and 100.';
            elseif (!in_array($status, array('active', 'inactive', 'archived'), true)) $error = 'Status must be active, inactive or archived.';

            if ($error !== '') {
                $failed++;
                $results[] = array('row' => $rowNo, 'status' => 'Failed', 'message' => $error, 'input' => $input);
                continue;
            }

            if ($unitName === '') $unitName = 'unit';

            if ($sku !== '') {
                $skuCheck->execute(array(':tenant_id' => $tenantId, ':sku' => $sku));
                $found = $skuCheck->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    $existing++;
                    $results[] = array(
                        'row' => $rowNo,
                        'status' => 'Existing',
                        'message' => 'SKU already exists. This row was skipped.',
                        'input' => $input,
                        'existing' => array('id' => (int)$found['id'], 'name' => (string)$found['name'], 'sku' => $sku, 'status' => (string)$found['status'])
                    );
                    continue;
                }
            }

            $baseUnitPrice = (float)$baseRaw;
            $markupValue = (float)$markupRaw;
            $taxPercent = (float)$taxRaw;
            $sellingPrice = pmSellingPrice($baseUnitPrice, $markupType, $markupValue);

            try {
                $insert->execute(array(
                    ':tenant_id' => $tenantId,
                    ':sku' => $sku !== '' ? $sku : null,
                    ':name' => $name,
                    ':description' => $description !== '' ? $description : null,
                    ':unit_name' => $unitName,
                    ':base_unit_price' => number_format($baseUnitPrice, 2, '.', ''),
                    ':markup_type' => $markupType,
                    ':markup_value' => number_format($markupValue, 2, '.', ''),
                    ':selling_price' => number_format($sellingPrice, 2, '.', ''),
                    ':tax_percent' => number_format($taxPercent, 3, '.', ''),
                    ':status' => $status,
                    ':created_by' => $userId
                ));
                $imported++;
                $results[] = array(
                    'row' => $rowNo,
                    'status' => 'Imported',
                    'message' => 'Product imported successfully. Selling Price: ' . number_format($sellingPrice, 2, '.', ''),
                    'input' => $input,
                    'id' => (int)$pdo->lastInsertId()
                );
            } catch (Throwable $rowError) {
                $failed++;
                $results[] = array('row' => $rowNo, 'status' => 'Failed', 'message' => 'Unable to import this row.', 'input' => $input);
                error_log('FieldPlx product import row ' . $rowNo . ': ' . $rowError->getMessage());
            }
        }

        pmOut(200, true, 'Product import completed.', array(
            'imported' => $imported,
            'existing' => $existing,
            'failed' => $failed,
            'results' => $results
        ));
    }


    if ($action === 'get') {
        $id = max(0, (int)(isset($_POST['id']) ? $_POST['id'] : 0));
        if ($id <= 0) pmOut(422, false, 'Invalid product.');

        $st = $pdo->prepare('SELECT id, sku, name, description, unit_name, base_unit_price,
                                    markup_type, markup_value, selling_price, tax_percent, status
                             FROM products
                             WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL
                             LIMIT 1');
        $st->execute(array(':id' => $id, ':tenant_id' => $tenantId));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) pmOut(404, false, 'Product not found.');
        pmOut(200, true, 'Product loaded.', array('product' => $row));
    }

    if ($action === 'save') {
        $id = max(0, (int)(isset($_POST['id']) ? $_POST['id'] : 0));
        $name = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
        $sku = trim((string)(isset($_POST['sku']) ? $_POST['sku'] : ''));
        $description = trim((string)(isset($_POST['description']) ? $_POST['description'] : ''));
        $unitName = trim((string)(isset($_POST['unit_name']) ? $_POST['unit_name'] : 'unit'));
        $baseUnitPrice = (float)(isset($_POST['base_unit_price']) ? $_POST['base_unit_price'] : 0);
        $markupType = trim((string)(isset($_POST['markup_type']) ? $_POST['markup_type'] : 'percentage'));
        $markupValue = (float)(isset($_POST['markup_value']) ? $_POST['markup_value'] : 0);
        $taxPercent = (float)(isset($_POST['tax_percent']) ? $_POST['tax_percent'] : 0);
        $status = trim((string)(isset($_POST['status']) ? $_POST['status'] : 'active'));

        if ($name === '') pmOut(422, false, 'Product name is required.');
        if ($baseUnitPrice < 0) pmOut(422, false, 'Base unit price cannot be negative.');
        if ($markupValue < 0) pmOut(422, false, 'Markup value cannot be negative.');
        if (!in_array($markupType, array('percentage', 'fixed'), true)) pmOut(422, false, 'Invalid markup type.');
        if ($markupType === 'percentage' && $markupValue > 100000) pmOut(422, false, 'Markup percentage is too large.');
        if ($taxPercent < 0 || $taxPercent > 100) pmOut(422, false, 'Tax percentage must be between 0 and 100.');
        if (!in_array($status, array('active', 'inactive', 'archived'), true)) pmOut(422, false, 'Invalid product status.');

        if ($unitName === '') $unitName = 'unit';
        $sellingPrice = pmSellingPrice($baseUnitPrice, $markupType, $markupValue);

        if ($sku !== '') {
            $sql = 'SELECT id FROM products WHERE tenant_id = :tenant_id AND sku = :sku AND deleted_at IS NULL';
            $skuParams = array(':tenant_id' => $tenantId, ':sku' => $sku);
            if ($id > 0) {
                $sql .= ' AND id <> :id';
                $skuParams[':id'] = $id;
            }
            $sql .= ' LIMIT 1';
            $check = $pdo->prepare($sql);
            $check->execute($skuParams);
            if ($check->fetchColumn()) pmOut(409, false, 'This SKU / product code is already in use.');
        }

        if ($id > 0) {
            $exists = $pdo->prepare('SELECT id FROM products WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL LIMIT 1');
            $exists->execute(array(':id' => $id, ':tenant_id' => $tenantId));
            if (!$exists->fetchColumn()) pmOut(404, false, 'Product not found.');

            $st = $pdo->prepare('UPDATE products
                                 SET sku = :sku,
                                     name = :name,
                                     description = :description,
                                     unit_name = :unit_name,
                                     base_unit_price = :base_unit_price,
                                     markup_type = :markup_type,
                                     markup_value = :markup_value,
                                     selling_price = :selling_price,
                                     tax_percent = :tax_percent,
                                     status = :status,
                                     updated_at = NOW()
                                 WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL');
            $st->execute(array(
                ':sku' => $sku !== '' ? $sku : null,
                ':name' => $name,
                ':description' => $description !== '' ? $description : null,
                ':unit_name' => $unitName,
                ':base_unit_price' => number_format($baseUnitPrice, 2, '.', ''),
                ':markup_type' => $markupType,
                ':markup_value' => number_format($markupValue, 2, '.', ''),
                ':selling_price' => number_format($sellingPrice, 2, '.', ''),
                ':tax_percent' => number_format($taxPercent, 3, '.', ''),
                ':status' => $status,
                ':id' => $id,
                ':tenant_id' => $tenantId
            ));

            pmOut(200, true, 'Product updated successfully.', array('selling_price' => $sellingPrice));
        }

        $st = $pdo->prepare('INSERT INTO products
                             (tenant_id, sku, name, description, unit_name, base_unit_price,
                              markup_type, markup_value, selling_price, tax_percent, status,
                              created_by, created_at, updated_at, deleted_at)
                             VALUES
                             (:tenant_id, :sku, :name, :description, :unit_name, :base_unit_price,
                              :markup_type, :markup_value, :selling_price, :tax_percent, :status,
                              :created_by, NOW(), NOW(), NULL)');
        $st->execute(array(
            ':tenant_id' => $tenantId,
            ':sku' => $sku !== '' ? $sku : null,
            ':name' => $name,
            ':description' => $description !== '' ? $description : null,
            ':unit_name' => $unitName,
            ':base_unit_price' => number_format($baseUnitPrice, 2, '.', ''),
            ':markup_type' => $markupType,
            ':markup_value' => number_format($markupValue, 2, '.', ''),
            ':selling_price' => number_format($sellingPrice, 2, '.', ''),
            ':tax_percent' => number_format($taxPercent, 3, '.', ''),
            ':status' => $status,
            ':created_by' => $userId
        ));

        pmOut(200, true, 'Product created successfully.', array(
            'id' => (int)$pdo->lastInsertId(),
            'selling_price' => $sellingPrice
        ));
    }

    if ($action === 'archive') {
        $id = max(0, (int)(isset($_POST['id']) ? $_POST['id'] : 0));
        if ($id <= 0) pmOut(422, false, 'Invalid product.');

        $st = $pdo->prepare("UPDATE products
                             SET status = 'archived', updated_at = NOW()
                             WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL");
        $st->execute(array(':id' => $id, ':tenant_id' => $tenantId));

        if ($st->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT id FROM products WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL LIMIT 1');
            $exists->execute(array(':id' => $id, ':tenant_id' => $tenantId));
            if (!$exists->fetchColumn()) pmOut(404, false, 'Product not found.');
        }

        pmOut(200, true, 'Product archived successfully.');
    }

    pmOut(400, false, 'Unknown action.');

} catch (Throwable $e) {
    error_log('FieldPlx products API: ' . $e->getMessage());
    pmOut(500, false, 'Unable to process the product request. ' . $e->getMessage());
}
