<?php
/**
 * FieldPlx - Edit Product / Service
 *
 * Upload as:
 * /public_html/product-service-edit.php
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
        rawurlencode(
            'product-service-edit.php?id=' .
            (isset($_GET['id']) ? (int) $_GET['id'] : 0)
        )
    );
    exit;
}

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
        'manage'
    )
) {
    http_response_code(403);
    exit('Products & Services management feature is not enabled.');
}

if (function_exists('requirePermission')) {
    requirePermission(
        'product_services.manage',
        'You do not have permission to edit products or services.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Edit Product / Service - FieldPlx';
$activePage = 'product-services';
$searchPlaceholder = 'Search products and services...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$itemId = isset($_GET['id'])
    ? (int) $_GET['id']
    : (
        isset($_POST['item_id'])
            ? (int) $_POST['item_id']
            : 0
    );

if ($itemId <= 0) {
    http_response_code(400);
    exit('A valid product or service ID is required.');
}

$errors = array();

$returnPage = isset($_GET['return'])
    ? basename((string) $_GET['return'])
    : 'product-services.php';

$allowedReturnPages = array(
    'product-services.php',
    'job-add.php',
    'quote-add.php',
    'quote-add-pos.php'
);

if (!in_array($returnPage, $allowedReturnPages, true)) {
    $returnPage = 'product-services.php';
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('psEditFetchAll')) {
    function psEditFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('psEditValue')) {
    function psEditValue(
        $key,
        $record,
        $default = ''
    ) {
        if (
            isset($_POST[$key]) &&
            !is_array($_POST[$key])
        ) {
            return trim((string) $_POST[$key]);
        }

        if (array_key_exists($key, $record)) {
            return $record[$key] === null
                ? $default
                : (string) $record[$key];
        }

        return $default;
    }
}

if (!function_exists('psEditNullable')) {
    function psEditNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('psEditCsrfToken')) {
    function psEditCsrfToken()
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

if (!function_exists('psEditVerifyCsrf')) {
    function psEditVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('psEditLogActivity')) {
    function psEditLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $itemId,
        $name,
        $itemType
    ) {
        $stmt = $conn->prepare("
            INSERT INTO activity_events (
                tenant_id,
                actor_user_id,
                actor_type,
                event_type,
                related_type,
                related_id,
                title,
                details_json,
                visible_to_client,
                created_at
            ) VALUES (
                ?,
                ?,
                'user',
                'product_service_updated',
                'product_service',
                ?,
                ?,
                ?,
                0,
                NOW()
            )
        ");

        if (!$stmt) {
            return;
        }

        $activityTitle =
            ucfirst(
                str_replace(
                    '_',
                    ' ',
                    $itemType
                )
            ) .
            ' updated: ' .
            $name;

        $details = json_encode(
            array(
                'product_service_id' => (int) $itemId,
                'name' => (string) $name,
                'item_type' => (string) $itemType
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiss',
            $tenantId,
            $userId,
            $itemId,
            $activityTitle,
            $details
        );

        $stmt->execute();
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Load existing item with strict tenant isolation
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        tenant_id,
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
        status
    FROM product_services
    WHERE id = ?
      AND tenant_id = ?
      AND deleted_at IS NULL
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load the selected product or service.');
}

$stmt->bind_param(
    'ii',
    $itemId,
    $tenantId
);

$stmt->execute();
$records = psEditFetchAll($stmt);
$stmt->close();

if (empty($records)) {
    http_response_code(404);
    exit('Product or service not found.');
}

$item = $records[0];

/*
|--------------------------------------------------------------------------
| Tax rates from Platform System Settings
|--------------------------------------------------------------------------
*/

$taxRates = array();

$taxResult = $conn->query("
    SELECT
        id,
        tax_name,
        tax_rate
    FROM system_tax_rates
    WHERE is_active = 1
    ORDER BY
        tax_rate ASC,
        tax_name ASC
");

if ($taxResult) {
    while ($taxRow = $taxResult->fetch_assoc()) {
        $taxRates[] = $taxRow;
    }

    $taxResult->free();
}

/*
|--------------------------------------------------------------------------
| Resolve currently stored tenant tax to platform system tax
|--------------------------------------------------------------------------
*/

$currentSystemTaxRateId = null;

if (!empty($item['tax_rate_id'])) {
    $currentTenantTaxName = '';
    $currentTenantTaxRate = 0.000;

    $stmt = $conn->prepare("
        SELECT
            name,
            rate
        FROM tax_rates
        WHERE id = ?
          AND tenant_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $storedTaxRateId =
            (int) $item['tax_rate_id'];

        $stmt->bind_param(
            'ii',
            $storedTaxRateId,
            $tenantId
        );

        $stmt->execute();
        $stmt->bind_result(
            $currentTenantTaxName,
            $currentTenantTaxRate
        );

        if ($stmt->fetch()) {
            $stmt->close();

            $stmt = $conn->prepare("
                SELECT id
                FROM system_tax_rates
                WHERE LOWER(tax_name) = LOWER(?)
                  AND tax_rate = ?
                LIMIT 1
            ");

            if ($stmt) {
                $stmt->bind_param(
                    'sd',
                    $currentTenantTaxName,
                    $currentTenantTaxRate
                );

                $stmt->execute();
                $stmt->bind_result(
                    $matchedSystemTaxRateId
                );

                if ($stmt->fetch()) {
                    $currentSystemTaxRateId =
                        (int) $matchedSystemTaxRateId;
                }

                $stmt->close();
            }
        } else {
            $stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Units of measurement
|--------------------------------------------------------------------------
*/

$unitMeasurements = array();

$unitResult = $conn->query("
    SELECT
        id,
        unit_name
    FROM unit_measurements
    WHERE is_active = 1
    ORDER BY unit_name ASC
");

if ($unitResult) {
    while ($unitRow = $unitResult->fetch_assoc()) {
        $unitMeasurements[] = $unitRow;
    }

    $unitResult->free();
}

$unitNamesByLowercase = array();

foreach ($unitMeasurements as $unitMeasurement) {
    $unitNamesByLowercase[
        strtolower(
            trim(
                (string) $unitMeasurement['unit_name']
            )
        )
    ] = (string) $unitMeasurement['unit_name'];
}

$defaultUnitsByItemType = array(
    'service' => array(
        'service',
        'visit',
        'hour',
        'job',
        'each'
    ),
    'product' => array(
        'each',
        'piece',
        'unit',
        'box',
        'pack'
    ),
    'material' => array(
        'kilogram',
        'gram',
        'liter',
        'meter',
        'piece',
        'each'
    ),
    'fee' => array(
        'each',
        'service',
        'job'
    ),
    'discount' => array(
        'each',
        'service'
    )
);

if (!function_exists('psEditDefaultUnitForType')) {
    function psEditDefaultUnitForType(
        $itemType,
        $defaultUnitsByItemType,
        $unitNamesByLowercase,
        $unitMeasurements
    ) {
        $itemType = strtolower(
            trim((string) $itemType)
        );

        $preferredUnits = isset(
            $defaultUnitsByItemType[$itemType]
        )
            ? $defaultUnitsByItemType[$itemType]
            : array('each');

        foreach ($preferredUnits as $preferredUnit) {
            $key = strtolower(
                trim((string) $preferredUnit)
            );

            if (isset($unitNamesByLowercase[$key])) {
                return (string)
                    $unitNamesByLowercase[$key];
            }
        }

        if (!empty($unitMeasurements)) {
            return (string)
                $unitMeasurements[0]['unit_name'];
        }

        return '';
    }
}

/*
|--------------------------------------------------------------------------
| Save changes
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!psEditVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $itemType = psEditValue(
        'item_type',
        $item,
        'service'
    );

    $name = psEditValue(
        'name',
        $item
    );

    $sku = psEditValue(
        'sku',
        $item
    );

    $description = psEditValue(
        'description',
        $item
    );

    $unitName = psEditValue(
        'unit_name',
        $item,
        psEditDefaultUnitForType(
            $itemType,
            $defaultUnitsByItemType,
            $unitNamesByLowercase,
            $unitMeasurements
        )
    );

    $unitCostRaw = psEditValue(
        'unit_cost',
        $item,
        '0'
    );

    $unitPriceRaw = psEditValue(
        'unit_price',
        $item,
        '0'
    );

    $systemTaxRateId =
        isset($_POST['system_tax_rate_id']) &&
        (int) $_POST['system_tax_rate_id'] > 0
            ? (int) $_POST['system_tax_rate_id']
            : null;

    $taxRateId = null;

    $isBookable = isset($_POST['is_bookable'])
        ? 1
        : 0;

    $durationRaw = psEditValue(
        'estimated_duration_minutes',
        $item
    );

    $estimatedDurationMinutes =
        $durationRaw !== ''
            ? (int) $durationRaw
            : null;

    $status = psEditValue(
        'status',
        $item,
        'active'
    );

    $allowedTypes = array(
        'service',
        'product',
        'material',
        'fee',
        'discount'
    );

    $allowedStatuses = array(
        'active',
        'inactive',
        'archived'
    );

    if (!in_array($itemType, $allowedTypes, true)) {
        $errors[] =
            'Please select a valid item type.';
    }

    if ($name === '') {
        $errors[] =
            'Item name is required.';
    }

    if (strlen($name) > 190) {
        $errors[] =
            'Item name cannot exceed 190 characters.';
    }

    if ($sku !== '' && strlen($sku) > 100) {
        $errors[] =
            'SKU cannot exceed 100 characters.';
    }

    if ($unitName === '') {
        $errors[] =
            'Please select a unit of measurement.';
    } elseif (strlen($unitName) > 120) {
        $errors[] =
            'Unit of measurement cannot exceed 120 characters.';
    } else {
        $validUnit = false;

        foreach ($unitMeasurements as $unitMeasurement) {
            if (
                (string) $unitMeasurement['unit_name'] ===
                $unitName
            ) {
                $validUnit = true;
                break;
            }
        }

        if (!$validUnit) {
            $errors[] =
                'The selected unit of measurement is not valid or is inactive.';
        }
    }

    if (!is_numeric($unitCostRaw)) {
        $errors[] =
            'Unit cost must be a valid number.';
    }

    if (!is_numeric($unitPriceRaw)) {
        $errors[] =
            'Selling price must be a valid number.';
    }

    $unitCost = is_numeric($unitCostRaw)
        ? round((float) $unitCostRaw, 2)
        : 0.00;

    $unitPrice = is_numeric($unitPriceRaw)
        ? round((float) $unitPriceRaw, 2)
        : 0.00;

    if ($unitCost < 0) {
        $errors[] =
            'Unit cost cannot be negative.';
    }

    if (
        $itemType !== 'discount' &&
        $unitPrice < 0
    ) {
        $errors[] =
            'Selling price cannot be negative.';
    }

    if (
        $estimatedDurationMinutes !== null &&
        $estimatedDurationMinutes < 0
    ) {
        $errors[] =
            'Estimated duration cannot be negative.';
    }

    if (
        $isBookable === 1 &&
        $itemType !== 'service'
    ) {
        $errors[] =
            'Only service items can be marked as bookable.';
    }

    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] =
            'Please select a valid status.';
    }

    /*
     * Validate platform tax and map it to tenant tax_rates.id.
     */
    if (
        empty($errors) &&
        $systemTaxRateId !== null
    ) {
        $selectedTaxName = '';
        $selectedTaxRate = 0.000;

        $stmt = $conn->prepare("
            SELECT
                tax_name,
                tax_rate
            FROM system_tax_rates
            WHERE id = ?
              AND is_active = 1
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the selected tax rate.';
        } else {
            $stmt->bind_param(
                'i',
                $systemTaxRateId
            );

            $stmt->execute();
            $stmt->bind_result(
                $selectedTaxName,
                $selectedTaxRate
            );

            if (!$stmt->fetch()) {
                $errors[] =
                    'The selected tax rate is not valid or is inactive.';
            }

            $stmt->close();
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("
                SELECT id
                FROM tax_rates
                WHERE tenant_id = ?
                  AND LOWER(name) = LOWER(?)
                  AND rate = ?
                LIMIT 1
            ");

            if (!$stmt) {
                $errors[] =
                    'Unable to find the matching tenant tax rate.';
            } else {
                $stmt->bind_param(
                    'isd',
                    $tenantId,
                    $selectedTaxName,
                    $selectedTaxRate
                );

                $stmt->execute();
                $stmt->bind_result(
                    $matchedTenantTaxId
                );

                if ($stmt->fetch()) {
                    $taxRateId =
                        (int) $matchedTenantTaxId;
                }

                $stmt->close();
            }
        }

        if (
            empty($errors) &&
            $taxRateId === null
        ) {
            $taxType = 'percentage';
            $isDefaultTax = 0;
            $isActiveTax = 1;

            $stmt = $conn->prepare("
                INSERT INTO tax_rates (
                    tenant_id,
                    name,
                    rate,
                    tax_type,
                    is_default,
                    is_active,
                    created_at,
                    updated_at
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW(),
                    NOW()
                )
            ");

            if (!$stmt) {
                $errors[] =
                    'Unable to create the tenant tax rate.';
            } else {
                $stmt->bind_param(
                    'isdsii',
                    $tenantId,
                    $selectedTaxName,
                    $selectedTaxRate,
                    $taxType,
                    $isDefaultTax,
                    $isActiveTax
                );

                if ($stmt->execute()) {
                    $taxRateId =
                        (int) $stmt->insert_id;
                } else {
                    $errors[] =
                        'Unable to create the tenant tax rate: ' .
                        $stmt->error;
                }

                $stmt->close();
            }
        }
    }

    /*
     * Duplicate name or SKU check, excluding current item.
     */
    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT id
            FROM product_services
            WHERE tenant_id = ?
              AND id <> ?
              AND deleted_at IS NULL
              AND (
                    LOWER(name) = LOWER(?)
                    OR (
                        ? <> ''
                        AND sku IS NOT NULL
                        AND LOWER(sku) = LOWER(?)
                    )
              )
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate duplicate items.';
        } else {
            $stmt->bind_param(
                'iisss',
                $tenantId,
                $itemId,
                $name,
                $sku,
                $sku
            );

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $errors[] =
                    'Another item with the same name or SKU already exists.';
            }

            $stmt->close();
        }
    }

    if (empty($errors)) {
        $skuValue = psEditNullable($sku);
        $descriptionValue =
            psEditNullable($description);
        $unitNameValue =
            psEditNullable($unitName);

        $stmt = $conn->prepare("
            UPDATE product_services
            SET
                item_type = ?,
                name = ?,
                sku = ?,
                description = ?,
                unit_name = ?,
                unit_cost = ?,
                unit_price = ?,
                tax_rate_id = ?,
                is_bookable = ?,
                estimated_duration_minutes = ?,
                status = ?,
                updated_at = NOW()
            WHERE id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to prepare the update operation.';
        } else {
            /*
             * 13 variables / 13 type characters:
             * s s s s s d d i i i s i i
             */
            $stmt->bind_param(
                'sssssddiiisii',
                $itemType,
                $name,
                $skuValue,
                $descriptionValue,
                $unitNameValue,
                $unitCost,
                $unitPrice,
                $taxRateId,
                $isBookable,
                $estimatedDurationMinutes,
                $status,
                $itemId,
                $tenantId
            );

            if ($stmt->execute()) {
                $stmt->close();

                psEditLogActivity(
                    $conn,
                    $tenantId,
                    $currentUserId,
                    $itemId,
                    $name,
                    $itemType
                );

                $_SESSION['flash_success'] =
                    ucfirst(
                        str_replace(
                            '_',
                            ' ',
                            $itemType
                        )
                    ) .
                    ' updated successfully.';

                header(
                    'Location: ' .
                    $returnPage .
                    '?updated_product_service_id=' .
                    $itemId
                );
                exit;
            }

            $errors[] =
                'Product or service could not be updated: ' .
                $stmt->error;

            $stmt->close();
        }
    }
}

$csrfToken = psEditCsrfToken();

$selectedType = psEditValue(
    'item_type',
    $item,
    'service'
);

$selectedUnitName = psEditValue(
    'unit_name',
    $item,
    psEditDefaultUnitForType(
        $selectedType,
        $defaultUnitsByItemType,
        $unitNamesByLowercase,
        $unitMeasurements
    )
);

$selectedSystemTaxRateId =
    isset($_POST['system_tax_rate_id'])
        ? (int) $_POST['system_tax_rate_id']
        : (
            $currentSystemTaxRateId !== null
                ? (int) $currentSystemTaxRateId
                : 0
        );

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.ps-add-page {
    --pa-primary: #6d28d9;
    --pa-primary-dark: #4c1d95;
    --pa-soft: #f5f3ff;
    --pa-text: #111827;
    --pa-muted: #6b7280;
    --pa-border: #e5e7eb;
}

.pa-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.pa-header-main {
    display: flex;
    align-items: center;
    gap: 11px;
}

.pa-header-icon {
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: linear-gradient(
        135deg,
        var(--pa-primary),
        var(--pa-primary-dark)
    );
    color: #fff;
    font-size: 18px;
    box-shadow: 0 10px 22px rgba(109,40,217,.2);
}

.pa-header h1 {
    margin: 0;
    color: var(--pa-text);
    font-size: 21px;
    font-weight: 800;
}

.pa-header p {
    margin: 5px 0 0;
    color: var(--pa-muted);
    font-size: 10px;
}

.pa-back {
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 1px solid var(--pa-border);
    border-radius: 9px;
    background: #fff;
    color: #374151;
    font-size: 9px;
    font-weight: 800;
    text-decoration: none;
}

.pa-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 10px;
    line-height: 1.6;
}

.pa-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.35fr)
        minmax(290px,.65fr);
    gap: 13px;
    align-items: start;
}

.pa-card {
    overflow: hidden;
    border: 1px solid var(--pa-border);
    border-radius: 13px;
    background: #fff;
    box-shadow: 0 6px 20px rgba(15,23,42,.04);
}

.pa-card + .pa-card {
    margin-top: 13px;
}

.pa-card-head {
    padding: 12px 14px;
    border-bottom: 1px solid #eef0f4;
}

.pa-card-head h2 {
    margin: 0;
    color: var(--pa-text);
    font-size: 11px;
    font-weight: 800;
}

.pa-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 8px;
}

.pa-body {
    padding: 14px;
}

.pa-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 11px;
}

.pa-field {
    min-width: 0;
}

.pa-field.full {
    grid-column: 1 / -1;
}

.pa-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 800;
}

.pa-required {
    color: #dc2626;
}

.pa-input,
.pa-select,
.pa-textarea {
    width: 100%;
    min-height: 38px;
    padding: 9px 10px;
    border: 1px solid #dfe3e8;
    border-radius: 9px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 9px;
    outline: none;
}

.pa-textarea {
    min-height: 110px;
    resize: vertical;
}

.pa-input:focus,
.pa-select:focus,
.pa-textarea:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.1);
}

.pa-type-grid {
    display: grid;
    grid-template-columns:
        repeat(5,minmax(0,1fr));
    gap: 8px;
}

.pa-type-option {
    position: relative;
}

.pa-type-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.pa-type-option label {
    min-height: 70px;
    padding: 10px 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 5px;
    border: 1px solid var(--pa-border);
    border-radius: 10px;
    background: #fff;
    color: #6b7280;
    font-size: 8px;
    font-weight: 800;
    cursor: pointer;
}

.pa-type-option label i {
    font-size: 17px;
}

.pa-type-option input:checked + label {
    border-color: #a78bfa;
    background: var(--pa-soft);
    color: var(--pa-primary);
    box-shadow: 0 0 0 2px rgba(139,92,246,.08);
}

.pa-check {
    padding: 11px;
    display: flex;
    align-items: flex-start;
    gap: 9px;
    border: 1px solid #edf0f5;
    border-radius: 10px;
}

.pa-check input {
    margin-top: 2px;
}

.pa-check-title {
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 800;
}

.pa-check-note {
    margin-top: 3px;
    display: block;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.5;
}

.pa-summary {
    display: grid;
    gap: 9px;
}

.pa-summary-row {
    padding: 10px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
    color: #4b5563;
    font-size: 9px;
}

.pa-summary-row strong {
    color: #111827;
}

.pa-summary-row.margin {
    border-color: #ddd6fe;
    background: var(--pa-soft);
    color: var(--pa-primary-dark);
}

.pa-actions {
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #eef0f4;
    background: #fafafa;
}

.pa-btn {
    min-height: 38px;
    padding: 8px 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 9px;
    font-family: inherit;
    font-size: 9px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
}

.pa-btn.secondary {
    border: 1px solid var(--pa-border);
    background: #fff;
    color: #374151;
}

.pa-btn.primary {
    border: 0;
    background: linear-gradient(
        135deg,
        var(--pa-primary),
        var(--pa-primary-dark)
    );
    color: #fff;
}

@media (max-width: 1050px) {
    .pa-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 720px) {
    .pa-type-grid {
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 620px) {
    .pa-header {
        flex-direction: column;
    }

    .pa-grid {
        grid-template-columns: 1fr;
    }

    .pa-field.full {
        grid-column: auto;
    }

    .pa-actions {
        flex-direction: column-reverse;
    }

    .pa-btn {
        width: 100%;
    }
}
</style>

<div class="ps-add-page">
    <div class="pa-header">
        <div class="pa-header-main">
            <div class="pa-header-icon">
                <i class="bi bi-pencil-square"></i>
            </div>

            <div>
                <h1>Edit Product / Service</h1>
                <p>
                    Update item details used in quotations, jobs, invoices, and bookings.
                </p>
            </div>
        </div>

        <a
            href="<?= e($returnPage); ?>"
            class="pa-back"
        >
            <i class="bi bi-arrow-left"></i>
            Back
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="pa-alert">
            <ul style="margin:0;padding-left:18px;">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="" autocomplete="off">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken); ?>"
        >

        <input
            type="hidden"
            name="item_id"
            value="<?= (int) $itemId; ?>"
        >

        <div class="pa-layout">
            <main>
                <section class="pa-card">
                    <div class="pa-card-head">
                        <h2>Item Type</h2>
                        <p>
                            Choose how this item will be used.
                        </p>
                    </div>

                    <div class="pa-body">
                        <div class="pa-type-grid">
                            <?php
                            $typeOptions = array(
                                'service' => array(
                                    'Service',
                                    'bi-tools'
                                ),
                                'product' => array(
                                    'Product',
                                    'bi-box-seam'
                                ),
                                'material' => array(
                                    'Material',
                                    'bi-bricks'
                                ),
                                'fee' => array(
                                    'Fee',
                                    'bi-receipt'
                                ),
                                'discount' => array(
                                    'Discount',
                                    'bi-percent'
                                )
                            );

                            foreach ($typeOptions as $value => $option):
                            ?>
                                <div class="pa-type-option">
                                    <input
                                        type="radio"
                                        name="item_type"
                                        id="type-<?= e($value); ?>"
                                        value="<?= e($value); ?>"
                                        <?= $selectedType === $value
                                            ? 'checked'
                                            : ''; ?>
                                    >

                                    <label for="type-<?= e($value); ?>">
                                        <i class="bi <?= e($option[1]); ?>"></i>
                                        <?= e($option[0]); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <section class="pa-card">
                    <div class="pa-card-head">
                        <h2>Basic Information</h2>
                        <p>
                            Update the item name, code, description, and unit.
                        </p>
                    </div>

                    <div class="pa-body">
                        <div class="pa-grid">
                            <div class="pa-field full">
                                <label class="pa-label">
                                    Item Name
                                    <span class="pa-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="name"
                                    class="pa-input"
                                    maxlength="190"
                                    value="<?= e(
                                        psEditValue(
                                            'name',
                                            $item
                                        )
                                    ); ?>"
                                    required
                                >
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    SKU / Item Code
                                </label>

                                <input
                                    type="text"
                                    name="sku"
                                    class="pa-input"
                                    maxlength="100"
                                    value="<?= e(
                                        psEditValue(
                                            'sku',
                                            $item
                                        )
                                    ); ?>"
                                    placeholder="Optional unique code"
                                >
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Unit
                                    <span class="pa-required">*</span>
                                </label>

                                <select
                                    name="unit_name"
                                    id="unitName"
                                    class="pa-select"
                                    required
                                >
                                    <option value="">
                                        Select Unit
                                    </option>

                                    <?php foreach (
                                        $unitMeasurements as
                                        $unitMeasurement
                                    ): ?>
                                        <option
                                            value="<?= e(
                                                $unitMeasurement[
                                                    'unit_name'
                                                ]
                                            ); ?>"
                                            <?= $selectedUnitName ===
                                                (string) $unitMeasurement[
                                                    'unit_name'
                                                ]
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e(
                                                $unitMeasurement[
                                                    'unit_name'
                                                ]
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <?php if (empty($unitMeasurements)): ?>
                                    <div style="margin-top:5px;color:#b91c1c;font-size:8px;">
                                        No active UOM is available. Add one in Platform → System Settings.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="pa-field full">
                                <label class="pa-label">
                                    Description
                                </label>

                                <textarea
                                    name="description"
                                    class="pa-textarea"
                                    placeholder="Describe the item or service"
                                ><?= e(
                                    psEditValue(
                                        'description',
                                        $item
                                    )
                                ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="pa-card">
                    <div class="pa-card-head">
                        <h2>Pricing & Tax</h2>
                        <p>
                            Update cost, selling price, and applicable tax.
                        </p>
                    </div>

                    <div class="pa-body">
                        <div class="pa-grid">
                            <div class="pa-field">
                                <label class="pa-label">
                                    Unit Cost
                                </label>

                                <input
                                    type="number"
                                    name="unit_cost"
                                    id="unitCost"
                                    class="pa-input"
                                    min="0"
                                    step="0.01"
                                    value="<?= e(
                                        psEditValue(
                                            'unit_cost',
                                            $item,
                                            '0'
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Selling Price
                                </label>

                                <input
                                    type="number"
                                    name="unit_price"
                                    id="unitPrice"
                                    class="pa-input"
                                    step="0.01"
                                    value="<?= e(
                                        psEditValue(
                                            'unit_price',
                                            $item,
                                            '0'
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="pa-field full">
                                <label class="pa-label">
                                    Tax Rate
                                </label>

                                <select
                                    name="system_tax_rate_id"
                                    class="pa-select"
                                >
                                    <option value="">
                                        No Tax
                                    </option>

                                    <?php foreach ($taxRates as $taxRate): ?>
                                        <option
                                            value="<?= (int) $taxRate['id']; ?>"
                                            <?= $selectedSystemTaxRateId ===
                                                (int) $taxRate['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e(
                                                $taxRate['tax_name']
                                            ); ?>
                                            · <?= e(
                                                rtrim(
                                                    rtrim(
                                                        number_format(
                                                            (float) $taxRate[
                                                                'tax_rate'
                                                            ],
                                                            3,
                                                            '.',
                                                            ''
                                                        ),
                                                        '0'
                                                    ),
                                                    '.'
                                                )
                                            ); ?>%
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <?php if (empty($taxRates)): ?>
                                    <div style="margin-top:5px;color:#b91c1c;font-size:8px;">
                                        No active tax rate is available. Add one in Platform → System Settings.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <aside>
                <section class="pa-card">
                    <div class="pa-card-head">
                        <h2>Availability</h2>
                        <p>
                            Configure booking and item status.
                        </p>
                    </div>

                    <div class="pa-body">
                        <div class="pa-field">
                            <label class="pa-label">
                                Status
                            </label>

                            <?php
                            $selectedStatus = psEditValue(
                                'status',
                                $item,
                                'active'
                            );
                            ?>

                            <select
                                name="status"
                                class="pa-select"
                            >
                                <option
                                    value="active"
                                    <?= $selectedStatus === 'active'
                                        ? 'selected'
                                        : ''; ?>
                                >
                                    Active
                                </option>

                                <option
                                    value="inactive"
                                    <?= $selectedStatus === 'inactive'
                                        ? 'selected'
                                        : ''; ?>
                                >
                                    Inactive
                                </option>

                                <option
                                    value="archived"
                                    <?= $selectedStatus === 'archived'
                                        ? 'selected'
                                        : ''; ?>
                                >
                                    Archived
                                </option>
                            </select>
                        </div>

                        <?php
                        $bookableChecked =
                            $_SERVER['REQUEST_METHOD'] === 'POST'
                                ? isset($_POST['is_bookable'])
                                : (int) $item['is_bookable'] === 1;
                        ?>

                        <label class="pa-check">
                            <input
                                type="checkbox"
                                name="is_bookable"
                                id="isBookable"
                                value="1"
                                <?= $bookableChecked
                                    ? 'checked'
                                    : ''; ?>
                            >

                            <span>
                                <span class="pa-check-title">
                                    Bookable Service
                                </span>

                                <span class="pa-check-note">
                                    Allow customers or staff to select this service for bookings. This is available only for service-type items.
                                </span>
                            </span>
                        </label>

                        <div
                            class="pa-field"
                            id="durationField"
                            style="margin-top:11px;"
                        >
                            <label class="pa-label">
                                Estimated Duration
                            </label>

                            <input
                                type="number"
                                name="estimated_duration_minutes"
                                class="pa-input"
                                min="0"
                                step="1"
                                value="<?= e(
                                    psEditValue(
                                        'estimated_duration_minutes',
                                        $item
                                    )
                                ); ?>"
                                placeholder="Minutes"
                            >
                        </div>
                    </div>
                </section>

                <section class="pa-card">
                    <div class="pa-card-head">
                        <h2>Price Summary</h2>
                        <p>
                            Live pricing calculation.
                        </p>
                    </div>

                    <div class="pa-body">
                        <div class="pa-summary">
                            <div class="pa-summary-row">
                                <span>Unit Cost</span>
                                <strong id="summaryCost">0.00</strong>
                            </div>

                            <div class="pa-summary-row">
                                <span>Selling Price</span>
                                <strong id="summaryPrice">0.00</strong>
                            </div>

                            <div class="pa-summary-row margin">
                                <span>Margin</span>
                                <strong id="summaryMargin">0.00</strong>
                            </div>
                        </div>
                    </div>

                    <div class="pa-actions">
                        <a
                            href="<?= e($returnPage); ?>"
                            class="pa-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="pa-btn primary"
                        >
                            <i class="bi bi-check2-circle"></i>
                            Update Item
                        </button>
                    </div>
                </section>
            </aside>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var unitInput =
        document.getElementById('unitName');

    var unitDefaultsByType = <?= json_encode(
        array(
            'service' => psEditDefaultUnitForType(
                'service',
                $defaultUnitsByItemType,
                $unitNamesByLowercase,
                $unitMeasurements
            ),
            'product' => psEditDefaultUnitForType(
                'product',
                $defaultUnitsByItemType,
                $unitNamesByLowercase,
                $unitMeasurements
            ),
            'material' => psEditDefaultUnitForType(
                'material',
                $defaultUnitsByItemType,
                $unitNamesByLowercase,
                $unitMeasurements
            ),
            'fee' => psEditDefaultUnitForType(
                'fee',
                $defaultUnitsByItemType,
                $unitNamesByLowercase,
                $unitMeasurements
            ),
            'discount' => psEditDefaultUnitForType(
                'discount',
                $defaultUnitsByItemType,
                $unitNamesByLowercase,
                $unitMeasurements
            )
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var costInput =
        document.getElementById('unitCost');

    var priceInput =
        document.getElementById('unitPrice');

    var bookableInput =
        document.getElementById('isBookable');

    var durationField =
        document.getElementById('durationField');

    function selectedType() {
        var checked =
            document.querySelector(
                'input[name="item_type"]:checked'
            );

        return checked ? checked.value : 'service';
    }

    function updateBookableState() {
        var isService =
            selectedType() === 'service';

        if (!isService) {
            bookableInput.checked = false;
            bookableInput.disabled = true;
            durationField.style.display = 'none';
        } else {
            bookableInput.disabled = false;
            durationField.style.display =
                bookableInput.checked
                    ? ''
                    : 'none';
        }
    }

    function updateDefaultUnit() {
        if (!unitInput) {
            return;
        }

        var itemType = selectedType();
        var preferredUnit =
            unitDefaultsByType[itemType] || '';

        if (preferredUnit === '') {
            return;
        }

        var optionExists = false;

        Array.prototype.forEach.call(
            unitInput.options,
            function (option) {
                if (option.value === preferredUnit) {
                    optionExists = true;
                }
            }
        );

        if (optionExists) {
            unitInput.value = preferredUnit;
        }
    }

    function updateSummary() {
        var cost =
            parseFloat(costInput.value) || 0;

        var price =
            parseFloat(priceInput.value) || 0;

        var margin =
            price - cost;

        document.getElementById(
            'summaryCost'
        ).textContent = cost.toFixed(2);

        document.getElementById(
            'summaryPrice'
        ).textContent = price.toFixed(2);

        document.getElementById(
            'summaryMargin'
        ).textContent = margin.toFixed(2);
    }

    document
        .querySelectorAll(
            'input[name="item_type"]'
        )
        .forEach(function (input) {
            input.addEventListener(
                'change',
                function () {
                    updateBookableState();
                    updateDefaultUnit();
                }
            );
        });

    bookableInput.addEventListener(
        'change',
        updateBookableState
    );

    costInput.addEventListener(
        'input',
        updateSummary
    );

    priceInput.addEventListener(
        'input',
        updateSummary
    );

    updateBookableState();
    updateSummary();
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
