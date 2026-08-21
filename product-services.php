<?php
/**
 * FieldPlx - Products & Services
 *
 * Upload as:
 * /public_html/product-services.php
 *
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| Authentication and access
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('product-services.php')
    );
    exit;
}

/*
 * Module and feature access.
 */
if (
    function_exists('tenantHasModule') &&
    !tenantHasModule('product_services')
) {
    http_response_code(403);
    exit('Products & Services module is not enabled for this account.');
}

if (
    function_exists('tenantHasFeature') &&
    !tenantHasFeature(
        'product_services',
        'view'
    )
) {
    http_response_code(403);
    exit('Products & Services view feature is not enabled.');
}

if (function_exists('requirePermission')) {
    requirePermission(
        'product_services.view',
        'You do not have permission to view products and services.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Products & Services - FieldPlx';
$activePage = 'product-services';
$searchPlaceholder = 'Search products and services...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];

$canManage = function_exists('hasPermission')
    ? hasPermission('product_services.manage')
    : true;

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('psFetchAssoc')) {
    function psFetchAssoc(mysqli_stmt $stmt)
    {
        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result) {
                return $result->fetch_assoc();
            }
        }

        $meta = $stmt->result_metadata();

        if (!$meta) {
            return null;
        }

        $row = array();
        $bind = array();

        while ($field = $meta->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

        if (!$stmt->fetch()) {
            return null;
        }

        $copy = array();

        foreach ($row as $key => $value) {
            $copy[$key] = $value;
        }

        return $copy;
    }
}

if (!function_exists('psFetchAll')) {
    function psFetchAll(mysqli_stmt $stmt)
    {
        $rows = array();

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }

                return $rows;
            }
        }

        $meta = $stmt->result_metadata();

        if (!$meta) {
            return $rows;
        }

        $row = array();
        $bind = array();

        while ($field = $meta->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

        while ($stmt->fetch()) {
            $copy = array();

            foreach ($row as $key => $value) {
                $copy[$key] = $value;
            }

            $rows[] = $copy;
        }

        return $rows;
    }
}

if (!function_exists('psBindParams')) {
    function psBindParams(
        mysqli_stmt $stmt,
        $types,
        array &$params
    ) {
        if ($types === '' || empty($params)) {
            return true;
        }

        $args = array($types);

        foreach ($params as $key => $value) {
            $args[] = &$params[$key];
        }

        return call_user_func_array(
            array($stmt, 'bind_param'),
            $args
        );
    }
}

if (!function_exists('psCsrfToken')) {
    function psCsrfToken()
    {
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));
            } catch (Throwable $error) {
                $_SESSION['csrf_token'] =
                    sha1(
                        uniqid(
                            (string) mt_rand(),
                            true
                        )
                    );
            }
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('psVerifyCsrf')) {
    function psVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('psQueryString')) {
    function psQueryString(array $changes = array())
    {
        $query = $_GET;

        foreach ($changes as $key => $value) {
            if ($value === '' || $value === null) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return http_build_query($query);
    }
}

if (!function_exists('psMoney')) {
    function psMoney($currency, $amount)
    {
        return trim((string) $currency) .
            ' ' .
            number_format((float) $amount, 2);
    }
}

/*
|--------------------------------------------------------------------------
| Archive action
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'archive'
) {
    if (!$canManage) {
        $errors[] =
            'You do not have permission to archive products or services.';
    }

    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!psVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $itemId = isset($_POST['item_id'])
        ? (int) $_POST['item_id']
        : 0;

    if ($itemId <= 0) {
        $errors[] =
            'Invalid product or service selected.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE product_services
            SET
                status = 'archived',
                deleted_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to prepare the archive operation.';
        } else {
            $stmt->bind_param(
                'ii',
                $itemId,
                $tenantId
            );

            if ($stmt->execute()) {
                $stmt->close();

                $_SESSION['flash_success'] =
                    'Product or service archived successfully.';

                header('Location: product-services.php');
                exit;
            }

            $errors[] =
                'Product or service could not be archived: ' .
                $stmt->error;

            $stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search'])
    ? trim((string) $_GET['search'])
    : '';

$typeFilter = isset($_GET['item_type'])
    ? trim((string) $_GET['item_type'])
    : '';

$statusFilter = isset($_GET['status'])
    ? trim((string) $_GET['status'])
    : '';

$sort = isset($_GET['sort'])
    ? trim((string) $_GET['sort'])
    : 'name_asc';

$allowedTypes = array(
    '',
    'service',
    'product',
    'material',
    'fee',
    'discount'
);

$allowedStatuses = array(
    '',
    'active',
    'inactive'
);

$allowedSorts = array(
    'name_asc',
    'name_desc',
    'price_desc',
    'price_asc',
    'cost_desc',
    'cost_asc',
    'newest',
    'oldest'
);

if (!in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = '';
}

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'name_asc';
}

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 20;
$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Stats
|--------------------------------------------------------------------------
*/

$stats = array(
    'total' => 0,
    'active' => 0,
    'products' => 0,
    'services' => 0,
    'materials' => 0,
    'average_margin' => 0.00
);

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active_count,
        SUM(item_type = 'product') AS product_count,
        SUM(item_type = 'service') AS service_count,
        SUM(item_type = 'material') AS material_count,
        COALESCE(
            AVG(unit_price - unit_cost),
            0
        ) AS average_margin
    FROM product_services
    WHERE tenant_id = ?
      AND deleted_at IS NULL
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = psFetchAssoc($stmt);
    $stmt->close();

    if ($row) {
        $stats['total'] =
            (int) $row['total'];

        $stats['active'] =
            (int) $row['active_count'];

        $stats['products'] =
            (int) $row['product_count'];

        $stats['services'] =
            (int) $row['service_count'];

        $stats['materials'] =
            (int) $row['material_count'];

        $stats['average_margin'] =
            (float) $row['average_margin'];
    }
}

/*
|--------------------------------------------------------------------------
| List query
|--------------------------------------------------------------------------
*/

$where = array(
    'tenant_id = ?',
    'deleted_at IS NULL'
);

$params = array($tenantId);
$types = 'i';

if ($search !== '') {
    $where[] = "(
        name LIKE ?
        OR sku LIKE ?
        OR description LIKE ?
        OR unit_name LIKE ?
    )";

    $searchLike = '%' . $search . '%';

    for ($i = 0; $i < 4; $i++) {
        $params[] = $searchLike;
        $types .= 's';
    }
}

if ($typeFilter !== '') {
    $where[] = 'item_type = ?';
    $params[] = $typeFilter;
    $types .= 's';
}

if ($statusFilter !== '') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

$orderSql = 'name ASC';

if ($sort === 'name_desc') {
    $orderSql = 'name DESC';
} elseif ($sort === 'price_desc') {
    $orderSql = 'unit_price DESC, name ASC';
} elseif ($sort === 'price_asc') {
    $orderSql = 'unit_price ASC, name ASC';
} elseif ($sort === 'cost_desc') {
    $orderSql = 'unit_cost DESC, name ASC';
} elseif ($sort === 'cost_asc') {
    $orderSql = 'unit_cost ASC, name ASC';
} elseif ($sort === 'newest') {
    $orderSql = 'created_at DESC';
} elseif ($sort === 'oldest') {
    $orderSql = 'created_at ASC';
}

$totalFiltered = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM product_services
    WHERE {$whereSql}
");

if ($stmt) {
    psBindParams(
        $stmt,
        $types,
        $params
    );

    $stmt->execute();
    $row = psFetchAssoc($stmt);
    $stmt->close();

    if ($row) {
        $totalFiltered = (int) $row['total'];
    }
}

$totalPages = max(
    1,
    (int) ceil(
        $totalFiltered / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$items = array();

$stmt = $conn->prepare("
    SELECT
        id,
        item_type,
        name,
        sku,
        description,
        unit_name,
        unit_cost,
        unit_price,
        tax_rate_id,
        is_bookable,
        estimated_duration_minutes,
        status,
        created_at,
        updated_at
    FROM product_services
    WHERE {$whereSql}
    ORDER BY {$orderSql}
    LIMIT ? OFFSET ?
");

if ($stmt) {
    $listParams = $params;
    $listTypes = $types . 'ii';

    $listParams[] = $perPage;
    $listParams[] = $offset;

    psBindParams(
        $stmt,
        $listTypes,
        $listParams
    );

    $stmt->execute();
    $items = psFetchAll($stmt);
    $stmt->close();
}

$currencyCode = !empty($_SESSION['currency_code'])
    ? (string) $_SESSION['currency_code']
    : 'INR';

$csrfToken = psCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.product-services-page {
    --ps-primary: #6d28d9;
    --ps-primary-dark: #4c1d95;
    --ps-soft: #f5f3ff;
    --ps-text: #111827;
    --ps-muted: #6b7280;
    --ps-border: #e5e7eb;
}

.ps-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.ps-header-main {
    display: flex;
    align-items: center;
    gap: 11px;
}

.ps-header-icon {
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: linear-gradient(
        135deg,
        var(--ps-primary),
        var(--ps-primary-dark)
    );
    color: #fff;
    font-size: 18px;
    box-shadow: 0 10px 22px rgba(109,40,217,.2);
}

.ps-header h1 {
    margin: 0;
    color: var(--ps-text);
    font-size: 21px;
    font-weight: 800;
}

.ps-header p {
    margin: 5px 0 0;
    color: var(--ps-muted);
    font-size: 10px;
}

.ps-add {
    min-height: 38px;
    padding: 9px 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border-radius: 10px;
    background: linear-gradient(
        135deg,
        var(--ps-primary),
        var(--ps-primary-dark)
    );
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    text-decoration: none;
    box-shadow: 0 9px 20px rgba(109,40,217,.18);
}

.ps-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
    line-height: 1.6;
}

.ps-alert.success {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.ps-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.ps-stats {
    margin-bottom: 13px;
    display: grid;
    grid-template-columns: repeat(6,minmax(0,1fr));
    gap: 9px;
}

.ps-stat {
    padding: 12px;
    border: 1px solid var(--ps-border);
    border-radius: 11px;
    background: #fff;
}

.ps-stat.highlight {
    border-color: #ddd6fe;
    background: linear-gradient(
        135deg,
        #faf8ff,
        #f5f3ff
    );
}

.ps-stat-label {
    color: #9ca3af;
    font-size: 7px;
    font-weight: 800;
    text-transform: uppercase;
}

.ps-stat-value {
    margin-top: 4px;
    color: var(--ps-text);
    font-size: 17px;
    font-weight: 800;
}

.ps-stat.highlight .ps-stat-value {
    color: var(--ps-primary-dark);
}

.ps-panel {
    overflow: hidden;
    border: 1px solid var(--ps-border);
    border-radius: 13px;
    background: #fff;
    box-shadow: 0 6px 20px rgba(15,23,42,.04);
}

.ps-filters {
    padding: 11px;
    display: grid;
    grid-template-columns:
        minmax(230px,1.4fr)
        minmax(135px,.55fr)
        minmax(135px,.55fr)
        minmax(150px,.65fr)
        auto;
    gap: 8px;
    border-bottom: 1px solid #eef0f4;
    background: #fcfcfd;
}

.ps-input,
.ps-select {
    width: 100%;
    min-height: 36px;
    padding: 8px 10px;
    border: 1px solid #dfe3e8;
    border-radius: 8px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 9px;
    outline: none;
}

.ps-input:focus,
.ps-select:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.1);
}

.ps-filter-btn,
.ps-reset {
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 800;
}

.ps-filter-btn {
    border: 0;
    background: var(--ps-primary);
    color: #fff;
    cursor: pointer;
}

.ps-reset {
    border: 1px solid var(--ps-border);
    background: #fff;
    color: #4b5563;
    text-decoration: none;
}

.ps-table-wrap {
    overflow-x: auto;
}

.ps-table {
    width: 100%;
    border-collapse: collapse;
}

.ps-table th,
.ps-table td {
    padding: 11px 12px;
    border-bottom: 1px solid #f0f1f4;
    text-align: left;
    white-space: nowrap;
}

.ps-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 7px;
    font-weight: 800;
    text-transform: uppercase;
}

.ps-table td {
    color: #374151;
    font-size: 9px;
    vertical-align: middle;
}

.ps-item {
    display: flex;
    align-items: center;
    gap: 9px;
}

.ps-item-icon {
    width: 35px;
    height: 35px;
    flex: 0 0 35px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    background: var(--ps-soft);
    color: var(--ps-primary);
    font-size: 13px;
}

.ps-main {
    color: #111827;
    font-size: 9px;
    font-weight: 800;
}

.ps-sub {
    margin-top: 2px;
    display: block;
    max-width: 260px;
    overflow: hidden;
    color: #9ca3af;
    font-size: 8px;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.ps-badge {
    padding: 4px 7px;
    display: inline-flex;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 800;
    text-transform: capitalize;
}

.ps-badge.active {
    background: #ecfdf5;
    color: #047857;
}

.ps-badge.inactive {
    background: #fef2f2;
    color: #b91c1c;
}

.ps-margin.positive {
    color: #047857;
    font-weight: 800;
}

.ps-margin.negative {
    color: #b91c1c;
    font-weight: 800;
}

.ps-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 5px;
}

.ps-action {
    width: 29px;
    height: 29px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--ps-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    text-decoration: none;
    cursor: pointer;
}

.ps-action:hover {
    border-color: #c4b5fd;
    background: #faf8ff;
    color: var(--ps-primary);
}

.ps-action.danger:hover {
    border-color: #fecaca;
    background: #fef2f2;
    color: #dc2626;
}

.ps-footer {
    padding: 11px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px solid #eef0f4;
}

.ps-result-count {
    color: #6b7280;
    font-size: 9px;
}

.ps-pagination {
    display: flex;
    align-items: center;
    gap: 5px;
}

.ps-page-link {
    min-width: 30px;
    height: 30px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--ps-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-size: 9px;
    font-weight: 800;
    text-decoration: none;
}

.ps-page-link.active {
    border-color: var(--ps-primary);
    background: var(--ps-primary);
    color: #fff;
}

.ps-empty {
    padding: 46px 15px;
    color: #9ca3af;
    font-size: 10px;
    text-align: center;
}

@media (max-width: 1200px) {
    .ps-stats {
        grid-template-columns:
            repeat(3,minmax(0,1fr));
    }

    .ps-filters {
        grid-template-columns:
            repeat(3,minmax(0,1fr));
    }
}

@media (max-width: 760px) {
    .ps-stats,
    .ps-filters {
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 600px) {
    .ps-header {
        flex-direction: column;
    }

    .ps-stats,
    .ps-filters {
        grid-template-columns: 1fr;
    }

    .ps-footer {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="product-services-page">
    <div class="ps-header">
        <div class="ps-header-main">
            <div class="ps-header-icon">
                <i class="bi bi-box-seam"></i>
            </div>

            <div>
                <h1>Products &amp; Services</h1>
                <p>
                    Manage reusable products, services, materials, fees, and discounts.
                </p>
            </div>
        </div>

        <?php if ($canManage): ?>
            <a
                href="product-service-add.php?return=product-services.php"
                class="ps-add"
            >
                <i class="bi bi-plus-lg"></i>
                Add Product / Service
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="ps-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="ps-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="ps-stats">
        <article class="ps-stat">
            <div class="ps-stat-label">Total Items</div>
            <div class="ps-stat-value">
                <?= e($stats['total']); ?>
            </div>
        </article>

        <article class="ps-stat">
            <div class="ps-stat-label">Active</div>
            <div class="ps-stat-value">
                <?= e($stats['active']); ?>
            </div>
        </article>

        <article class="ps-stat">
            <div class="ps-stat-label">Products</div>
            <div class="ps-stat-value">
                <?= e($stats['products']); ?>
            </div>
        </article>

        <article class="ps-stat">
            <div class="ps-stat-label">Services</div>
            <div class="ps-stat-value">
                <?= e($stats['services']); ?>
            </div>
        </article>

        <article class="ps-stat">
            <div class="ps-stat-label">Materials</div>
            <div class="ps-stat-value">
                <?= e($stats['materials']); ?>
            </div>
        </article>

        <article class="ps-stat highlight">
            <div class="ps-stat-label">Average Margin</div>
            <div class="ps-stat-value">
                <?= e(
                    psMoney(
                        $currencyCode,
                        $stats['average_margin']
                    )
                ); ?>
            </div>
        </article>
    </section>

    <section class="ps-panel">
        <form
            method="get"
            action=""
            class="ps-filters"
        >
            <input
                type="search"
                name="search"
                class="ps-input"
                value="<?= e($search); ?>"
                placeholder="Search name, SKU, unit, or description"
            >

            <select
                name="item_type"
                class="ps-select"
            >
                <option value="">All Types</option>

                <?php
                $typeOptions = array(
                    'service' => 'Services',
                    'product' => 'Products',
                    'material' => 'Materials',
                    'fee' => 'Fees',
                    'discount' => 'Discounts'
                );

                foreach ($typeOptions as $value => $label):
                ?>
                    <option
                        value="<?= e($value); ?>"
                        <?= $typeFilter === $value
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="status"
                class="ps-select"
            >
                <option value="">All Statuses</option>
                <option
                    value="active"
                    <?= $statusFilter === 'active'
                        ? 'selected'
                        : ''; ?>
                >
                    Active
                </option>
                <option
                    value="inactive"
                    <?= $statusFilter === 'inactive'
                        ? 'selected'
                        : ''; ?>
                >
                    Inactive
                </option>
            </select>

            <select
                name="sort"
                class="ps-select"
            >
                <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : ''; ?>>
                    Name A-Z
                </option>
                <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : ''; ?>>
                    Name Z-A
                </option>
                <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : ''; ?>>
                    Highest Price
                </option>
                <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : ''; ?>>
                    Lowest Price
                </option>
                <option value="cost_desc" <?= $sort === 'cost_desc' ? 'selected' : ''; ?>>
                    Highest Cost
                </option>
                <option value="cost_asc" <?= $sort === 'cost_asc' ? 'selected' : ''; ?>>
                    Lowest Cost
                </option>
                <option value="newest" <?= $sort === 'newest' ? 'selected' : ''; ?>>
                    Newest First
                </option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : ''; ?>>
                    Oldest First
                </option>
            </select>

            <div style="display:flex;gap:6px;">
                <button
                    type="submit"
                    class="ps-filter-btn"
                >
                    Apply
                </button>

                <a
                    href="product-services.php"
                    class="ps-reset"
                >
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($items)): ?>
            <div class="ps-table-wrap">
                <table class="ps-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Type</th>
                            <th>SKU</th>
                            <th>Unit</th>
                            <th>Unit Cost</th>
                            <th>Selling Price</th>
                            <th>Margin</th>
                            <th>Bookable</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $margin =
                            (float) $item['unit_price'] -
                            (float) $item['unit_cost'];

                        $marginClass =
                            $margin >= 0
                                ? 'positive'
                                : 'negative';
                        ?>

                        <tr>
                            <td>
                                <div class="ps-item">
                                    <span class="ps-item-icon">
                                        <i class="bi <?= $item['item_type'] === 'service'
                                            ? 'bi-tools'
                                            : 'bi-box-seam'; ?>"></i>
                                    </span>

                                    <span>
                                        <span class="ps-main">
                                            <?= e($item['name']); ?>
                                        </span>

                                        <span class="ps-sub">
                                            <?= e(
                                                !empty($item['description'])
                                                    ? $item['description']
                                                    : 'No description'
                                            ); ?>
                                        </span>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <span class="ps-badge">
                                    <?= e(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $item['item_type']
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <?= e(
                                    !empty($item['sku'])
                                        ? $item['sku']
                                        : '—'
                                ); ?>
                            </td>

                            <td>
                                <?= e(
                                    !empty($item['unit_name'])
                                        ? $item['unit_name']
                                        : '—'
                                ); ?>
                            </td>

                            <td>
                                <?= e(
                                    psMoney(
                                        $currencyCode,
                                        $item['unit_cost']
                                    )
                                ); ?>
                            </td>

                            <td>
                                <strong>
                                    <?= e(
                                        psMoney(
                                            $currencyCode,
                                            $item['unit_price']
                                        )
                                    ); ?>
                                </strong>
                            </td>

                            <td>
                                <span class="ps-margin <?= e($marginClass); ?>">
                                    <?= e(
                                        psMoney(
                                            $currencyCode,
                                            $margin
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <?= !empty($item['is_bookable'])
                                    ? 'Yes'
                                    : 'No'; ?>
                            </td>

                            <td>
                                <span class="ps-badge <?= e($item['status']); ?>">
                                    <?= e($item['status']); ?>
                                </span>
                            </td>

                            <td>
                                <div class="ps-actions">
                                    <?php if ($canManage): ?>
                                        <a
                                            href="product-service-edit.php?id=<?= (int) $item['id']; ?>"
                                            class="ps-action"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>

                                        <form
                                            method="post"
                                            action=""
                                            class="archive-product-service-form"
                                            style="display:inline;"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($csrfToken); ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="archive"
                                            >

                                            <input
                                                type="hidden"
                                                name="item_id"
                                                value="<?= (int) $item['id']; ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="ps-action danger"
                                                title="Archive"
                                            >
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="ps-footer">
                <div class="ps-result-count">
                    Showing
                    <?= e(
                        min(
                            $totalFiltered,
                            $offset + 1
                        )
                    ); ?>
                    -
                    <?= e(
                        min(
                            $totalFiltered,
                            $offset + count($items)
                        )
                    ); ?>
                    of
                    <?= e($totalFiltered); ?>
                    items
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="ps-pagination">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(
                                    psQueryString(
                                        array(
                                            'page' => $page - 1
                                        )
                                    )
                                ); ?>"
                                class="ps-page-link"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min(
                            $totalPages,
                            $page + 2
                        );

                        for (
                            $pageNumber = $startPage;
                            $pageNumber <= $endPage;
                            $pageNumber++
                        ):
                        ?>
                            <a
                                href="?<?= e(
                                    psQueryString(
                                        array(
                                            'page' => $pageNumber
                                        )
                                    )
                                ); ?>"
                                class="ps-page-link <?= $pageNumber === $page ? 'active' : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                href="?<?= e(
                                    psQueryString(
                                        array(
                                            'page' => $page + 1
                                        )
                                    )
                                ); ?>"
                                class="ps-page-link"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="ps-empty">
                No products or services found.
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document
        .querySelectorAll(
            '.archive-product-service-form'
        )
        .forEach(function (form) {
            form.addEventListener(
                'submit',
                function (event) {
                    if (
                        !window.confirm(
                            'Archive this product or service?'
                        )
                    ) {
                        event.preventDefault();
                    }
                }
            );
        });
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
