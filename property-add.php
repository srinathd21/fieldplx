<?php
/**
 * FieldPlx - Add Property
 *
 * Upload as:
 * /public_html/property-add.php
 *
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| Authentication and permission
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('property-add.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'properties.manage',
        'You do not have permission to create properties.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Add Property - FieldPlx';
$activePage = 'property-add';
$searchPlaceholder = 'Search properties...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('propertyAddFetchAssoc')) {
    function propertyAddFetchAssoc(mysqli_stmt $stmt)
    {
        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result) {
                return $result->fetch_assoc();
            }
        }

        $metadata = $stmt->result_metadata();

        if (!$metadata) {
            return null;
        }

        $row = array();
        $bind = array();

        while ($field = $metadata->fetch_field()) {
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

if (!function_exists('propertyAddFetchAll')) {
    function propertyAddFetchAll(mysqli_stmt $stmt)
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

        $metadata = $stmt->result_metadata();

        if (!$metadata) {
            return $rows;
        }

        $row = array();
        $bind = array();

        while ($field = $metadata->fetch_field()) {
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

if (!function_exists('propertyAddOld')) {
    function propertyAddOld($key, $default = '')
    {
        return isset($_POST[$key])
            ? trim((string) $_POST[$key])
            : $default;
    }
}

if (!function_exists('propertyAddNullable')) {
    function propertyAddNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('propertyAddCsrfToken')) {
    function propertyAddCsrfToken()
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

if (!function_exists('propertyAddVerifyCsrf')) {
    function propertyAddVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('propertyAddLogActivity')) {
    function propertyAddLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $propertyId,
        $clientId,
        $propertyName
    ) {
        $stmt = $conn->prepare("
            INSERT INTO activity_events (
                tenant_id,
                actor_user_id,
                actor_type,
                event_type,
                related_type,
                related_id,
                client_id,
                title,
                details_json,
                visible_to_client,
                created_at
            ) VALUES (
                ?,
                ?,
                'user',
                'property_created',
                'property',
                ?,
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

        $title =
            'Property created: ' .
            $propertyName;

        $details = json_encode(
            array(
                'property_id' => (int) $propertyId,
                'client_id' => (int) $clientId,
                'property_name' =>
                    (string) $propertyName
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiiss',
            $tenantId,
            $userId,
            $propertyId,
            $clientId,
            $title,
            $details
        );

        $stmt->execute();
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Clients
|--------------------------------------------------------------------------
*/

$clients = array();

$stmt = $conn->prepare("
    SELECT
        id,
        display_name,
        company_name,
        email,
        phone
    FROM clients
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status <> 'archived'
    ORDER BY display_name ASC
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    $stmt->execute();
    $clients = propertyAddFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Preselected client
|--------------------------------------------------------------------------
*/

$preselectedClientId = isset($_GET['client_id'])
    ? (int) $_GET['client_id']
    : 0;

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' &&
    $preselectedClientId > 0
) {
    $_POST['client_id'] =
        (string) $preselectedClientId;
}

/*
|--------------------------------------------------------------------------
| Save property
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!propertyAddVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $clientId = isset($_POST['client_id'])
        ? (int) $_POST['client_id']
        : 0;

    $name = propertyAddOld('name');
    $addressLine1 =
        propertyAddOld('address_line1');
    $addressLine2 =
        propertyAddOld('address_line2');
    $city = propertyAddOld('city');
    $state = propertyAddOld('state');
    $postalCode =
        propertyAddOld('postal_code');
    $country = propertyAddOld('country');
    $latitude =
        propertyAddOld('latitude');
    $longitude =
        propertyAddOld('longitude');
    $serviceArea =
        propertyAddOld('service_area');
    $taxArea =
        propertyAddOld('tax_area');
    $gateCode =
        propertyAddOld('gate_code');
    $accessNotes =
        propertyAddOld('access_notes');
    $serviceInstructions =
        propertyAddOld('service_instructions');

    $isPrimary = isset($_POST['is_primary'])
        ? 1
        : 0;

    $status = propertyAddOld(
        'status',
        'active'
    );

    $allowedStatuses = array(
        'active',
        'inactive',
        'archived'
    );

    if ($clientId <= 0) {
        $errors[] = 'Please select a client.';
    }

    if ($addressLine1 === '') {
        $errors[] =
            'Address line 1 is required.';
    }

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {
        $errors[] =
            'Please select a valid status.';
    }

    if (
        $latitude !== '' &&
        !is_numeric($latitude)
    ) {
        $errors[] =
            'Latitude must be a valid number.';
    }

    if (
        $longitude !== '' &&
        !is_numeric($longitude)
    ) {
        $errors[] =
            'Longitude must be a valid number.';
    }

    if (
        $latitude !== '' &&
        (
            (float) $latitude < -90 ||
            (float) $latitude > 90
        )
    ) {
        $errors[] =
            'Latitude must be between -90 and 90.';
    }

    if (
        $longitude !== '' &&
        (
            (float) $longitude < -180 ||
            (float) $longitude > 180
        )
    ) {
        $errors[] =
            'Longitude must be between -180 and 180.';
    }

    /*
     * Validate client.
     */
    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT id
            FROM clients
            WHERE id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
              AND status <> 'archived'
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the selected client.';
        } else {
            $stmt->bind_param(
                'ii',
                $clientId,
                $tenantId
            );

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $errors[] =
                    'The selected client is not valid.';
            }

            $stmt->close();
        }
    }

    /*
     * Duplicate property check.
     */
    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT id
            FROM properties
            WHERE tenant_id = ?
              AND client_id = ?
              AND deleted_at IS NULL
              AND LOWER(address_line1) =
                  LOWER(?)
              AND COALESCE(LOWER(city), '') =
                  COALESCE(LOWER(?), '')
            LIMIT 1
        ");

        if ($stmt) {
            $cityValue =
                propertyAddNullable($city);

            $stmt->bind_param(
                'iiss',
                $tenantId,
                $clientId,
                $addressLine1,
                $cityValue
            );

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $errors[] =
                    'A property with the same address already exists for this client.';
            }

            $stmt->close();
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            /*
             * When this is the primary property,
             * clear any previous primary property
             * for the same client.
             */
            if ($isPrimary === 1) {
                $stmt = $conn->prepare("
                    UPDATE properties
                    SET
                        is_primary = 0,
                        updated_at = NOW()
                    WHERE tenant_id = ?
                      AND client_id = ?
                      AND deleted_at IS NULL
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to update primary property settings.'
                    );
                }

                $stmt->bind_param(
                    'ii',
                    $tenantId,
                    $clientId
                );

                $stmt->execute();
                $stmt->close();
            }

            $nameValue =
                propertyAddNullable($name);

            $addressLine2Value =
                propertyAddNullable($addressLine2);

            $cityValue =
                propertyAddNullable($city);

            $stateValue =
                propertyAddNullable($state);

            $postalCodeValue =
                propertyAddNullable($postalCode);

            $countryValue =
                propertyAddNullable($country);

            $latitudeValue =
                $latitude === ''
                    ? null
                    : (float) $latitude;

            $longitudeValue =
                $longitude === ''
                    ? null
                    : (float) $longitude;

            $serviceAreaValue =
                propertyAddNullable($serviceArea);

            $taxAreaValue =
                propertyAddNullable($taxArea);

            $gateCodeValue =
                propertyAddNullable($gateCode);

            $accessNotesValue =
                propertyAddNullable($accessNotes);

            $serviceInstructionsValue =
                propertyAddNullable(
                    $serviceInstructions
                );

            $stmt = $conn->prepare("
                INSERT INTO properties (
                    tenant_id,
                    client_id,
                    name,
                    address_line1,
                    address_line2,
                    city,
                    state,
                    postal_code,
                    country,
                    latitude,
                    longitude,
                    service_area,
                    tax_area,
                    gate_code,
                    access_notes,
                    service_instructions,
                    is_primary,
                    status,
                    created_at,
                    updated_at,
                    deleted_at
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW(),
                    NOW(),
                    NULL
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the property save operation.'
                );
            }

            $stmt->bind_param(
                'iisssssssddsssssis',
                $tenantId,
                $clientId,
                $nameValue,
                $addressLine1,
                $addressLine2Value,
                $cityValue,
                $stateValue,
                $postalCodeValue,
                $countryValue,
                $latitudeValue,
                $longitudeValue,
                $serviceAreaValue,
                $taxAreaValue,
                $gateCodeValue,
                $accessNotesValue,
                $serviceInstructionsValue,
                $isPrimary,
                $status
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Property could not be saved: ' .
                    $stmt->error
                );
            }

            $propertyId =
                (int) $stmt->insert_id;

            $stmt->close();

            $conn->commit();

            $propertyName =
                $nameValue !== null
                    ? $nameValue
                    : $addressLine1;

            propertyAddLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $propertyId,
                $clientId,
                $propertyName
            );

            $_SESSION['flash_success'] =
                'Property created successfully.';

            header(
                'Location: property-view.php?id=' .
                $propertyId
            );
            exit;
        } catch (Throwable $error) {
            $conn->rollback();

            $errors[] = $error->getMessage();
        }
    }
}

$csrfToken = propertyAddCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.property-add-page {
    --pa-primary: #6d28d9;
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

.pa-header h1 {
    margin: 0;
    color: var(--pa-text);
    font-size: 21px;
    font-weight: 700;
}

.pa-header p {
    margin: 5px 0 0;
    color: var(--pa-muted);
    font-size: 11px;
}

.pa-back {
    min-height: 34px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 1px solid var(--pa-border);
    border-radius: 9px;
    background: #fff;
    color: #374151;
    font-size: 10px;
    font-weight: 700;
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

.pa-alert ul {
    margin: 0;
    padding-left: 18px;
}

.pa-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.45fr)
        minmax(280px,.7fr);
    gap: 13px;
}

.pa-card {
    overflow: hidden;
    border: 1px solid var(--pa-border);
    border-radius: 12px;
    background: #fff;
    box-shadow:
        0 5px 18px
        rgba(15,23,42,.035);
}

.pa-card + .pa-card {
    margin-top: 13px;
}

.pa-card-head {
    padding: 12px 14px;
    border-bottom:
        1px solid #f1f5f9;
}

.pa-card-head h2 {
    margin: 0;
    color: var(--pa-text);
    font-size: 11px;
    font-weight: 700;
}

.pa-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.pa-card-body {
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
    font-weight: 700;
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
    border:
        1px solid #dfe3e8;
    border-radius: 9px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 10px;
    outline: none;
}

.pa-textarea {
    min-height: 95px;
    resize: vertical;
}

.pa-input:focus,
.pa-select:focus,
.pa-textarea:focus {
    border-color: #8b5cf6;
    box-shadow:
        0 0 0 3px
        rgba(139,92,246,.1);
}

.pa-check-list {
    display: grid;
    gap: 9px;
}

.pa-check {
    padding: 10px;
    display: flex;
    align-items: flex-start;
    gap: 9px;
    border:
        1px solid #edf0f5;
    border-radius: 9px;
}

.pa-check input {
    margin-top: 2px;
}

.pa-check-title {
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.pa-check-note {
    margin-top: 2px;
    display: block;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.5;
}

.pa-actions {
    margin-top: 13px;
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top:
        1px solid #f1f5f9;
    background: #fafafa;
}

.pa-btn {
    min-height: 36px;
    padding: 8px 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border: 0;
    border-radius: 9px;
    font-family: inherit;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
}

.pa-btn.secondary {
    border:
        1px solid var(--pa-border);
    background: #fff;
    color: #374151;
}

.pa-btn.primary {
    background: var(--pa-primary);
    color: #fff;
}

@media (max-width: 1050px) {
    .pa-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
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

<div class="property-add-page">
    <div class="pa-header">
        <div>
            <h1>Add Property</h1>
            <p>
                Add a client service location or property.
            </p>
        </div>

        <a
            href="properties.php"
            class="pa-back"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Properties
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="pa-alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action=""
        autocomplete="off"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken); ?>"
        >

        <div class="pa-layout">
            <main>
                <section class="pa-card">
                    <div class="pa-card-head">
                        <h2>Property Information</h2>
                        <p>
                            Select the client and enter the service address.
                        </p>
                    </div>

                    <div class="pa-card-body">
                        <div class="pa-grid">
                            <div class="pa-field full">
                                <label class="pa-label">
                                    Client
                                    <span class="pa-required">*</span>
                                </label>

                                <select
                                    name="client_id"
                                    class="pa-select"
                                    required
                                >
                                    <option value="">
                                        Select Client
                                    </option>

                                    <?php foreach ($clients as $client): ?>
                                        <option
                                            value="<?= (int) $client['id']; ?>"
                                            <?= (int) propertyAddOld('client_id') === (int) $client['id']
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($client['display_name']); ?>
                                            <?php if (!empty($client['phone'])): ?>
                                                · <?= e($client['phone']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="pa-field full">
                                <label class="pa-label">
                                    Property Name
                                </label>

                                <input
                                    type="text"
                                    name="name"
                                    class="pa-input"
                                    maxlength="190"
                                    value="<?= e(propertyAddOld('name')); ?>"
                                    placeholder="Home, Main Office, Warehouse..."
                                >
                            </div>

                            <div class="pa-field full">
                                <label class="pa-label">
                                    Address Line 1
                                    <span class="pa-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="address_line1"
                                    class="pa-input"
                                    maxlength="255"
                                    value="<?= e(propertyAddOld('address_line1')); ?>"
                                    required
                                >
                            </div>

                            <div class="pa-field full">
                                <label class="pa-label">
                                    Address Line 2
                                </label>

                                <input
                                    type="text"
                                    name="address_line2"
                                    class="pa-input"
                                    maxlength="255"
                                    value="<?= e(propertyAddOld('address_line2')); ?>"
                                >
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    City
                                </label>

                                <input
                                    type="text"
                                    name="city"
                                    class="pa-input"
                                    maxlength="120"
                                    value="<?= e(propertyAddOld('city')); ?>"
                                >
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    State
                                </label>

                                <input
                                    type="text"
                                    name="state"
                                    class="pa-input"
                                    maxlength="120"
                                    value="<?= e(propertyAddOld('state')); ?>"
                                >
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Postal Code
                                </label>

                                <input
                                    type="text"
                                    name="postal_code"
                                    class="pa-input"
                                    maxlength="40"
                                    value="<?= e(propertyAddOld('postal_code')); ?>"
                                >
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Country
                                </label>

                                <input
                                    type="text"
                                    name="country"
                                    class="pa-input"
                                    maxlength="120"
                                    value="<?= e(propertyAddOld('country')); ?>"
                                >
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Latitude
                                </label>

                                <input
                                    type="number"
                                    name="latitude"
                                    class="pa-input"
                                    step="0.0000001"
                                    min="-90"
                                    max="90"
                                    value="<?= e(propertyAddOld('latitude')); ?>"
                                >
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Longitude
                                </label>

                                <input
                                    type="number"
                                    name="longitude"
                                    class="pa-input"
                                    step="0.0000001"
                                    min="-180"
                                    max="180"
                                    value="<?= e(propertyAddOld('longitude')); ?>"
                                >
                            </div>
                        </div>
                    </div>
                </section>

                <section class="pa-card">
                    <div class="pa-card-head">
                        <h2>Service Details</h2>
                        <p>
                            Optional access, tax, and service instructions.
                        </p>
                    </div>

                    <div class="pa-card-body">
                        <div class="pa-grid">
                            <div class="pa-field">
                                <label class="pa-label">
                                    Service Area
                                </label>

                                <input
                                    type="text"
                                    name="service_area"
                                    class="pa-input"
                                    maxlength="190"
                                    value="<?= e(propertyAddOld('service_area')); ?>"
                                >
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Tax Area
                                </label>

                                <input
                                    type="text"
                                    name="tax_area"
                                    class="pa-input"
                                    maxlength="190"
                                    value="<?= e(propertyAddOld('tax_area')); ?>"
                                >
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Gate Code
                                </label>

                                <input
                                    type="text"
                                    name="gate_code"
                                    class="pa-input"
                                    maxlength="80"
                                    value="<?= e(propertyAddOld('gate_code')); ?>"
                                >
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Status
                                </label>

                                <select
                                    name="status"
                                    class="pa-select"
                                >
                                    <option
                                        value="active"
                                        <?= propertyAddOld('status', 'active') === 'active'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Active
                                    </option>

                                    <option
                                        value="inactive"
                                        <?= propertyAddOld('status') === 'inactive'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Inactive
                                    </option>

                                    <option
                                        value="archived"
                                        <?= propertyAddOld('status') === 'archived'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Archived
                                    </option>
                                </select>
                            </div>

                            <div class="pa-field full">
                                <label class="pa-label">
                                    Access Notes
                                </label>

                                <textarea
                                    name="access_notes"
                                    class="pa-textarea"
                                    placeholder="Parking, gate, security, or access information"
                                ><?= e(propertyAddOld('access_notes')); ?></textarea>
                            </div>

                            <div class="pa-field full">
                                <label class="pa-label">
                                    Service Instructions
                                </label>

                                <textarea
                                    name="service_instructions"
                                    class="pa-textarea"
                                    placeholder="Important service instructions for field workers"
                                ><?= e(propertyAddOld('service_instructions')); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <aside>
                <section class="pa-card">
                    <div class="pa-card-head">
                        <h2>Property Settings</h2>
                        <p>
                            Configure the client’s primary service location.
                        </p>
                    </div>

                    <div class="pa-card-body">
                        <div class="pa-check-list">
                            <label class="pa-check">
                                <input
                                    type="checkbox"
                                    name="is_primary"
                                    value="1"
                                    <?= isset($_POST['is_primary'])
                                        ? 'checked'
                                        : ''; ?>
                                >

                                <span>
                                    <span class="pa-check-title">
                                        Primary Property
                                    </span>

                                    <span class="pa-check-note">
                                        Make this the primary property for the selected client. Any previous primary property will be changed to non-primary.
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="pa-actions">
                        <a
                            href="properties.php"
                            class="pa-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="pa-btn primary"
                        >
                            <i class="bi bi-check2"></i>
                            Save Property
                        </button>
                    </div>
                </section>
            </aside>
        </div>
    </form>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
