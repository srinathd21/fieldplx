<?php
/**
 * FieldPlx - Edit Property
 *
 * Upload as:
 * /public_html/property-edit.php
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
        rawurlencode(
            'property-edit.php?id=' .
            (isset($_GET['id']) ? (int) $_GET['id'] : 0)
        )
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'properties.manage',
        'You do not have permission to edit properties.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Edit Property - FieldPlx';
$activePage = 'property-edit';
$searchPlaceholder = 'Search properties...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$propertyId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($propertyId <= 0) {
    header('Location: properties.php');
    exit;
}

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('propertyEditFetchAssoc')) {
    function propertyEditFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('propertyEditFetchAll')) {
    function propertyEditFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('propertyEditValue')) {
    function propertyEditValue(
        $key,
        array $property,
        $default = ''
    ) {
        if (isset($_POST[$key])) {
            return trim((string) $_POST[$key]);
        }

        if (array_key_exists($key, $property)) {
            return trim((string) $property[$key]);
        }

        return $default;
    }
}

if (!function_exists('propertyEditNullable')) {
    function propertyEditNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('propertyEditCsrfToken')) {
    function propertyEditCsrfToken()
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

if (!function_exists('propertyEditVerifyCsrf')) {
    function propertyEditVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('propertyEditLogActivity')) {
    function propertyEditLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $propertyId,
        $clientId,
        $propertyName,
        array $oldValues,
        array $newValues
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
                'property_updated',
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
            'Property updated: ' .
            $propertyName;

        $details = json_encode(
            array(
                'property_id' => (int) $propertyId,
                'client_id' => (int) $clientId,
                'old_values' => $oldValues,
                'new_values' => $newValues
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
| Load property
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT *
    FROM properties
    WHERE id = ?
      AND tenant_id = ?
      AND deleted_at IS NULL
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load property.');
}

$stmt->bind_param(
    'ii',
    $propertyId,
    $tenantId
);

$stmt->execute();
$property = propertyEditFetchAssoc($stmt);
$stmt->close();

if (!$property) {
    http_response_code(404);

    require_once __DIR__ . '/includes/topbar.php';
    ?>
    <div style="padding:30px;text-align:center;">
        <h2>Property not found</h2>
        <p>
            This property does not exist or is not available for your business.
        </p>
        <a href="properties.php">Back to Properties</a>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
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
    $clients = propertyEditFetchAll($stmt);
    $stmt->close();
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

    if (!propertyEditVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $clientId = isset($_POST['client_id'])
        ? (int) $_POST['client_id']
        : 0;

    $name = propertyEditValue(
        'name',
        $property
    );

    $addressLine1 = propertyEditValue(
        'address_line1',
        $property
    );

    $addressLine2 = propertyEditValue(
        'address_line2',
        $property
    );

    $city = propertyEditValue(
        'city',
        $property
    );

    $state = propertyEditValue(
        'state',
        $property
    );

    $postalCode = propertyEditValue(
        'postal_code',
        $property
    );

    $country = propertyEditValue(
        'country',
        $property
    );

    $latitude = propertyEditValue(
        'latitude',
        $property
    );

    $longitude = propertyEditValue(
        'longitude',
        $property
    );

    $serviceArea = propertyEditValue(
        'service_area',
        $property
    );

    $taxArea = propertyEditValue(
        'tax_area',
        $property
    );

    $gateCode = propertyEditValue(
        'gate_code',
        $property
    );

    $accessNotes = propertyEditValue(
        'access_notes',
        $property
    );

    $serviceInstructions = propertyEditValue(
        'service_instructions',
        $property
    );

    $isPrimary = isset($_POST['is_primary'])
        ? 1
        : 0;

    $status = propertyEditValue(
        'status',
        $property,
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
     * Validate selected client.
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
     * Duplicate address check excluding current property.
     */
    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT id
            FROM properties
            WHERE tenant_id = ?
              AND client_id = ?
              AND id <> ?
              AND deleted_at IS NULL
              AND LOWER(address_line1) =
                  LOWER(?)
              AND COALESCE(LOWER(city), '') =
                  COALESCE(LOWER(?), '')
            LIMIT 1
        ");

        if ($stmt) {
            $cityValue =
                propertyEditNullable($city);

            $stmt->bind_param(
                'iiiss',
                $tenantId,
                $clientId,
                $propertyId,
                $addressLine1,
                $cityValue
            );

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $errors[] =
                    'Another property with the same address already exists for this client.';
            }

            $stmt->close();
        }
    }

    if (empty($errors)) {
        $oldValues = array(
            'client_id' => (int) $property['client_id'],
            'name' => $property['name'],
            'address_line1' => $property['address_line1'],
            'address_line2' => $property['address_line2'],
            'city' => $property['city'],
            'state' => $property['state'],
            'postal_code' => $property['postal_code'],
            'country' => $property['country'],
            'latitude' => $property['latitude'],
            'longitude' => $property['longitude'],
            'service_area' => $property['service_area'],
            'tax_area' => $property['tax_area'],
            'gate_code' => $property['gate_code'],
            'access_notes' => $property['access_notes'],
            'service_instructions' =>
                $property['service_instructions'],
            'is_primary' => (int) $property['is_primary'],
            'status' => $property['status']
        );

        $conn->begin_transaction();

        try {
            /*
             * If primary is selected, remove primary status
             * from other properties of this client.
             */
            if ($isPrimary === 1) {
                $stmt = $conn->prepare("
                    UPDATE properties
                    SET
                        is_primary = 0,
                        updated_at = NOW()
                    WHERE tenant_id = ?
                      AND client_id = ?
                      AND id <> ?
                      AND deleted_at IS NULL
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to update primary property settings.'
                    );
                }

                $stmt->bind_param(
                    'iii',
                    $tenantId,
                    $clientId,
                    $propertyId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Primary property settings could not be updated: ' .
                        $stmt->error
                    );
                }

                $stmt->close();
            }

            $nameValue =
                propertyEditNullable($name);

            $addressLine2Value =
                propertyEditNullable($addressLine2);

            $cityValue =
                propertyEditNullable($city);

            $stateValue =
                propertyEditNullable($state);

            $postalCodeValue =
                propertyEditNullable($postalCode);

            $countryValue =
                propertyEditNullable($country);

            $latitudeValue =
                $latitude === ''
                    ? null
                    : (float) $latitude;

            $longitudeValue =
                $longitude === ''
                    ? null
                    : (float) $longitude;

            $serviceAreaValue =
                propertyEditNullable($serviceArea);

            $taxAreaValue =
                propertyEditNullable($taxArea);

            $gateCodeValue =
                propertyEditNullable($gateCode);

            $accessNotesValue =
                propertyEditNullable($accessNotes);

            $serviceInstructionsValue =
                propertyEditNullable(
                    $serviceInstructions
                );

            $stmt = $conn->prepare("
                UPDATE properties
                SET
                    client_id = ?,
                    name = ?,
                    address_line1 = ?,
                    address_line2 = ?,
                    city = ?,
                    state = ?,
                    postal_code = ?,
                    country = ?,
                    latitude = ?,
                    longitude = ?,
                    service_area = ?,
                    tax_area = ?,
                    gate_code = ?,
                    access_notes = ?,
                    service_instructions = ?,
                    is_primary = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                  AND deleted_at IS NULL
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the property update operation.'
                );
            }

            $stmt->bind_param(
                'isssssssddsssssisii',
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
                $status,
                $propertyId,
                $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Property could not be updated: ' .
                    $stmt->error
                );
            }

            $stmt->close();

            $conn->commit();

            $propertyName =
                $nameValue !== null
                    ? $nameValue
                    : $addressLine1;

            $newValues = array(
                'client_id' => $clientId,
                'name' => $nameValue,
                'address_line1' => $addressLine1,
                'address_line2' => $addressLine2Value,
                'city' => $cityValue,
                'state' => $stateValue,
                'postal_code' => $postalCodeValue,
                'country' => $countryValue,
                'latitude' => $latitudeValue,
                'longitude' => $longitudeValue,
                'service_area' => $serviceAreaValue,
                'tax_area' => $taxAreaValue,
                'gate_code' => $gateCodeValue,
                'access_notes' => $accessNotesValue,
                'service_instructions' =>
                    $serviceInstructionsValue,
                'is_primary' => $isPrimary,
                'status' => $status
            );

            propertyEditLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $propertyId,
                $clientId,
                $propertyName,
                $oldValues,
                $newValues
            );

            $_SESSION['flash_success'] =
                'Property updated successfully.';

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

$csrfToken = propertyEditCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
:root {
    --fieldplx-primary: #74b824;
    --fieldplx-primary-dark: #5d971b;
    --fieldplx-text: #0b1933;
    --fieldplx-muted: #6f7b90;
    --fieldplx-border: #e5eaf1;
    --fieldplx-surface: #ffffff;
    --fieldplx-background: #f6f8fb;
    --fieldplx-topbar-height: 70px;
    --fieldplx-sidebar-width: 250px;
    --fieldplx-sidebar-collapsed-width: 78px;
    --pe-navy: #001131;
    --pe-navy-light: #071f49;
    --pe-blue: #123d70;
    --pe-primary: #74b824;
    --pe-primary-dark: #5d971b;
    --pe-primary-soft: #f0f8e5;
    --pe-red: #e45b66;
    --pe-bg: #f6f8fb;
    --pe-text: #0b1933;
    --pe-muted: #6f7b90;
    --pe-border: #e5eaf1;
}

body {
    background: var(--pe-bg) !important;
    color: var(--pe-text);
    font-family: Arial, Helvetica, sans-serif !important;
    font-size: 14px;
}

/* Exact new FieldPlx dashboard shell */
.fieldplx-topbar {
    min-height: 70px !important;
    margin-left: var(--fieldplx-sidebar-width);
    width: calc(100% - var(--fieldplx-sidebar-width));
    background: #fff !important;
    border-bottom: 1px solid var(--pe-border) !important;
    box-shadow: 0 3px 14px rgba(0,17,49,.035);
    backdrop-filter: none !important;
    transition: margin-left .25s ease, width .25s ease;
}
body.fieldplx-sidebar-collapsed .fieldplx-topbar {
    margin-left: var(--fieldplx-sidebar-collapsed-width);
    width: calc(100% - var(--fieldplx-sidebar-collapsed-width));
}
.fieldplx-topbar-inner {
    min-height: 70px !important;
    padding: 0 27px !important;
    gap: 13px !important;
}
.fieldplx-page-heading { display: none !important; }
.fieldplx-menu-toggle,
.fieldplx-topbar-action {
    width: 41px !important;
    height: 41px !important;
    border: 0 !important;
    border-radius: 9px !important;
    color: var(--pe-navy) !important;
    background: transparent !important;
}
.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover {
    color: var(--pe-navy) !important;
    background: var(--pe-primary-soft) !important;
}
.fieldplx-search-wrap { width: 280px !important; margin-left: auto; }
.fieldplx-search-input {
    height: 41px !important;
    padding-left: 38px !important;
    border: 0 !important;
    border-radius: 8px !important;
    background: #f5f8fb !important;
    color: var(--pe-text) !important;
    font-size: 14px !important;
}
.fieldplx-search-input:focus {
    background: #f5f8fb !important;
    box-shadow: 0 0 0 3px rgba(116,184,36,.14) !important;
}
.fieldplx-profile-button {
    padding: 2px !important;
    border: 0 !important;
    border-radius: 9px !important;
    background: transparent !important;
}
.fieldplx-profile-button:hover { background: var(--pe-primary-soft) !important; }
.fieldplx-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    border: 0 !important;
    color: var(--pe-navy) !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
    font-size: 14px !important;
    font-weight: 800 !important;
}
.fieldplx-profile-name { font-size: 14px !important; }
.fieldplx-profile-role { color: var(--pe-muted) !important; font-size: 12px !important; }
.fieldplx-notification-count { background: var(--pe-red) !important; }
.fieldplx-dropdown,
.fieldplx-profile-menu {
    border-color: var(--pe-border) !important;
    box-shadow: 0 18px 45px rgba(29,38,74,.14) !important;
}
.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover { color: var(--pe-primary-dark) !important; }

.fieldplx-sidebar {
    width: var(--fieldplx-sidebar-width) !important;
    min-width: var(--fieldplx-sidebar-width) !important;
    height: 100vh !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    z-index: 1045 !important;
    color: #fff !important;
    background: linear-gradient(180deg,var(--pe-navy-light),var(--pe-navy)) !important;
    border-top: 4px solid var(--pe-primary) !important;
    border-right: 0 !important;
    transition: width .25s ease, min-width .25s ease, transform .25s ease !important;
}
body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: var(--fieldplx-sidebar-collapsed-width) !important;
    min-width: var(--fieldplx-sidebar-collapsed-width) !important;
}
.fieldplx-sidebar-header {
    min-height: 68px !important;
    padding: 9px 14px 10px !important;
    border-bottom: 1px solid rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-brand { color: #fff !important; }
.fieldplx-sidebar-logo,
.fieldplx-sidebar-logo-placeholder {
    width: 40px !important;
    height: 40px !important;
    flex: 0 0 40px !important;
    border-radius: 10px !important;
}
.fieldplx-sidebar-logo-placeholder {
    color: #fff !important;
    background: linear-gradient(135deg,#8fd236,#68aa1d) !important;
    font-size: 18px !important;
}
.fieldplx-sidebar-company-name {
    max-width: 155px !important;
    color: #fff !important;
    font-size: 16px !important;
    font-weight: 700 !important;
}
.fieldplx-sidebar-product-name { color: #9fda55 !important; font-size: 11px !important; }
.fieldplx-sidebar-body { padding: 12px 14px !important; scrollbar-width: none !important; }
.fieldplx-sidebar-body::-webkit-scrollbar { display: none; }
.fieldplx-sidebar-section-label {
    margin: 7px 12px 7px !important;
    color: rgba(255,255,255,.50) !important;
    font-size: 11px !important;
}
.fieldplx-sidebar-nav { gap: 3px !important; }
.fieldplx-sidebar-link {
    min-height: 46px !important;
    margin-bottom: 3px !important;
    padding: 0 14px !important;
    gap: 15px !important;
    border-radius: 9px !important;
    color: rgba(255,255,255,.94) !important;
    font-size: 15px !important;
    font-weight: 600 !important;
}
.fieldplx-sidebar-link:hover { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-link.active,
.fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link {
    color: #fff !important;
    background: linear-gradient(90deg,#7fc92d,#68aa1d) !important;
    box-shadow: 0 6px 18px rgba(0,17,49,.28) !important;
}
.fieldplx-sidebar-link-icon {
    width: 21px !important;
    height: 21px !important;
    flex: 0 0 21px !important;
    font-size: 19px !important;
}
.fieldplx-sidebar-arrow { color: rgba(255,255,255,.65) !important; }
.fieldplx-sidebar-submenu { padding-left: 36px !important; }
.fieldplx-sidebar-sublink {
    min-height: 34px !important;
    color: rgba(255,255,255,.72) !important;
    font-size: 13px !important;
}
.fieldplx-sidebar-sublink::before { background: rgba(255,255,255,.35) !important; }
.fieldplx-sidebar-sublink:hover,
.fieldplx-sidebar-sublink.active { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-sublink.active::before { background: #9fda55 !important; }
.fieldplx-sidebar-footer {
    padding: 10px 14px 14px !important;
    border-top: 1px solid rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-user { min-height: 62px; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-user-name { color: #fff !important; font-size: 14px !important; }
.fieldplx-sidebar-user-role { color: rgba(255,255,255,.60) !important; font-size: 11px !important; }
.fieldplx-sidebar-user-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    color: var(--pe-navy) !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
}
.fieldplx-sidebar-logout { color: rgba(255,255,255,.70) !important; }
.fieldplx-sidebar-logout:hover { color: #fff !important; background: rgba(228,91,102,.30) !important; }
.fieldplx-main-layout { display: block !important; min-height: calc(100vh - 70px) !important; }
.fieldplx-main-content {
    margin-left: var(--fieldplx-sidebar-width);
    min-width: 0;
    transition: margin-left .25s ease;
}
body.fieldplx-sidebar-collapsed .fieldplx-main-content { margin-left: var(--fieldplx-sidebar-collapsed-width); }
.fieldplx-content-wrapper { padding: 0 !important; }
.fieldplx-footer { display: none !important; }

/* Property Edit - exact new FieldPlx component language */
.property-edit-page {
    width: 100%;
    max-width: 1600px;
    margin: auto;
    padding: 25px 27px 35px;
}

.pe-header {
    min-height: 108px;
    margin-bottom: 18px;
    padding: 20px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    border: 1px solid var(--pe-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.pe-header-main {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 16px;
}
.pe-header-icon {
    width: 58px;
    height: 58px;
    flex: 0 0 58px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    background: linear-gradient(135deg,var(--pe-blue),var(--pe-navy));
    box-shadow: 0 8px 22px rgba(0,17,49,.16);
    font-size: 23px;
}
.pe-header h1 {
    margin: 0 0 7px;
    color: var(--pe-text);
    font-size: 28px;
    line-height: 1.1;
    font-weight: 700;
}
.pe-header p {
    margin: 0;
    color: var(--pe-muted);
    font-size: 14px;
    line-height: 1.5;
}
.pe-header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.pe-back {
    height: 46px;
    padding: 0 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid var(--pe-border);
    border-radius: 9px;
    background: #fff;
    color: #53627a;
    box-shadow: 0 4px 14px rgba(31,43,88,.04);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    transition: .2s ease;
}
.pe-back i { font-size: 14px; }
.pe-back:hover {
    border-color: #cfe3ae;
    color: var(--pe-primary-dark);
    background: #f9fcf4;
}

.pe-alert {
    margin-bottom: 18px;
    padding: 14px 16px;
    border: 1px solid #f1c7cb;
    border-radius: 9px;
    background: #fff5f6;
    color: #b5434d;
    box-shadow: 0 4px 14px rgba(31,43,88,.035);
    font-size: 13px;
    line-height: 1.65;
}
.pe-alert ul { margin: 0; padding-left: 20px; }

.pe-layout {
    display: grid;
    grid-template-columns: minmax(0,1.55fr) minmax(320px,.72fr);
    gap: 18px;
    align-items: start;
}
.pe-layout > div,
.pe-layout > aside { min-width: 0; }
.pe-layout aside {
    position: sticky;
    top: 88px;
}

.pe-card {
    overflow: hidden;
    border: 1px solid var(--pe-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.pe-card + .pe-card { margin-top: 18px; }
.pe-card-head {
    min-height: 68px;
    padding: 16px 18px 14px;
    border-bottom: 1px solid var(--pe-border);
    background: #fff;
}
.pe-card-head h2 {
    margin: 0;
    color: var(--pe-text);
    font-size: 18px;
    line-height: 1.25;
    font-weight: 700;
}
.pe-card-head p {
    margin: 5px 0 0;
    color: #8290a4;
    font-size: 12px;
    line-height: 1.5;
}
.pe-card-body { padding: 20px 18px; }

.pe-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 17px 16px;
}
.pe-field { min-width: 0; }
.pe-field.full { grid-column: 1 / -1; }
.pe-label {
    margin-bottom: 8px;
    display: block;
    color: #34455f;
    font-size: 13px;
    line-height: 1.3;
    font-weight: 700;
}
.pe-required { color: #e45b66; }

.pe-input,
.pe-select,
.pe-textarea {
    width: 100%;
    min-height: 46px;
    padding: 0 14px;
    border: 1px solid #dfe5ed;
    border-radius: 9px;
    background: #fff;
    color: var(--pe-text);
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.4;
    outline: none;
    transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
}
.pe-select {
    cursor: pointer;
    padding-right: 38px;
}
.pe-textarea {
    min-height: 126px;
    padding-top: 12px;
    padding-bottom: 12px;
    resize: vertical;
}
.pe-input::placeholder,
.pe-textarea::placeholder { color: #a1abba; }
.pe-input:hover,
.pe-select:hover,
.pe-textarea:hover { border-color: #cad4df; }
.pe-input:focus,
.pe-select:focus,
.pe-textarea:focus {
    border-color: var(--pe-primary);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(116,184,36,.14);
}

.pe-check-list { display: grid; gap: 12px; }
.pe-check {
    min-height: 88px;
    padding: 15px 14px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    border: 1px solid #e8edf3;
    border-radius: 9px;
    background: #fff;
    cursor: pointer;
    transition: border-color .18s ease, background .18s ease, box-shadow .18s ease;
}
.pe-check:hover {
    border-color: #cfe3ae;
    background: #fbfdf8;
    box-shadow: 0 3px 10px rgba(31,43,88,.035);
}
.pe-check input {
    width: 18px;
    height: 18px;
    flex: 0 0 18px;
    margin-top: 1px;
    accent-color: var(--pe-primary);
}
.pe-check-title {
    display: block;
    color: var(--pe-text);
    font-size: 13px;
    line-height: 1.4;
    font-weight: 700;
}
.pe-check-note {
    margin-top: 5px;
    display: block;
    color: #7f8c9f;
    font-size: 12px;
    line-height: 1.55;
}

.pe-actions {
    margin-top: 2px;
    padding: 16px 18px;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    border-top: 1px solid var(--pe-border);
    background: #fbfcfe;
}
.pe-btn {
    min-height: 46px;
    padding: 0 17px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid transparent;
    border-radius: 9px;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    transition: .18s ease;
}
.pe-btn i { font-size: 15px; }
.pe-btn.secondary {
    border-color: var(--pe-border);
    background: #fff;
    color: #53627a;
}
.pe-btn.secondary:hover {
    border-color: #cfe3ae;
    color: var(--pe-primary-dark);
    background: #f9fcf4;
}
.pe-btn.primary {
    border-color: var(--pe-primary);
    background: var(--pe-primary);
    color: #fff;
    box-shadow: 0 6px 16px rgba(116,184,36,.20);
}
.pe-btn.primary:hover {
    border-color: var(--pe-primary-dark);
    background: var(--pe-primary-dark);
    color: #fff;
}

/* Subtle section identity without changing the form structure */
.pe-card-head::before {
    content: '';
    width: 34px;
    height: 4px;
    display: block;
    margin-bottom: 10px;
    border-radius: 999px;
    background: var(--pe-primary);
}

@media (max-width: 1180px) {
    .pe-layout { grid-template-columns: 1fr; }
    .pe-layout aside { position: static; }
}
@media (max-width: 991.98px) {
    .fieldplx-topbar { margin-left: 0 !important; width: 100% !important; }
    .fieldplx-main-content { margin-left: 0 !important; }
    .property-edit-page { padding: 20px 18px 30px; }
}
@media (max-width: 680px) {
    .fieldplx-topbar-inner { padding: 0 14px !important; }
    .fieldplx-search-wrap { display: none !important; }
    .property-edit-page { padding: 18px 13px 28px; }
    .pe-header {
        align-items: flex-start;
        flex-direction: column;
        padding: 17px 15px;
    }
    .pe-header-main { align-items: flex-start; }
    .pe-header h1 { font-size: 24px; }
    .pe-header-actions { width: 100%; }
    .pe-back { flex: 1; }
    .pe-grid { grid-template-columns: 1fr; }
    .pe-field.full { grid-column: auto; }
    .pe-card-head { min-height: 0; padding: 15px; }
    .pe-card-body { padding: 15px; }
    .pe-input,
    .pe-select,
    .pe-textarea { font-size: 16px; }
    .pe-actions { padding: 15px; flex-direction: column-reverse; }
    .pe-btn { width: 100%; }
}
</style>

<div class="property-edit-page">
    <div class="pe-header">
        <div class="pe-header-main">
            <div class="pe-header-icon">
                <i class="bi bi-geo-alt-fill"></i>
            </div>
            <div>
                <h1>Edit Property</h1>
                <p>
                    Update
                    <?= e(
                        trim((string) $property['name']) !== ''
                            ? $property['name']
                            : $property['address_line1']
                    ); ?>
                    details, service information, and property settings.
                </p>
            </div>
        </div>

        <div class="pe-header-actions">
            <a
                href="property-view.php?id=<?= $propertyId; ?>"
                class="pe-back"
            >
                <i class="bi bi-eye"></i>
                View Property
            </a>

            <a
                href="properties.php"
                class="pe-back"
            >
                <i class="bi bi-arrow-left"></i>
                Properties
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="pe-alert">
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

        <div class="pe-layout">
            <main>
                <section class="pe-card">
                    <div class="pe-card-head">
                        <h2>Property Information</h2>
                        <p>
                            Update the client assignment, property identity, address, and coordinates.
                        </p>
                    </div>

                    <div class="pe-card-body">
                        <div class="pe-grid">
                            <div class="pe-field full">
                                <label class="pe-label">
                                    Client
                                    <span class="pe-required">*</span>
                                </label>

                                <select
                                    name="client_id"
                                    class="pe-select"
                                    required
                                >
                                    <option value="">
                                        Select Client
                                    </option>

                                    <?php
                                    $selectedClientId =
                                        isset($_POST['client_id'])
                                            ? (int) $_POST['client_id']
                                            : (int) $property['client_id'];
                                    ?>

                                    <?php foreach ($clients as $client): ?>
                                        <option
                                            value="<?= (int) $client['id']; ?>"
                                            <?= $selectedClientId === (int) $client['id']
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

                            <div class="pe-field full">
                                <label class="pe-label">
                                    Property Name
                                </label>

                                <input
                                    type="text"
                                    name="name"
                                    class="pe-input"
                                    maxlength="190"
                                    value="<?= e(
                                        propertyEditValue(
                                            'name',
                                            $property
                                        )
                                    ); ?>"
                                    placeholder="Home, Main Office, Warehouse..."
                                >
                            </div>

                            <div class="pe-field full">
                                <label class="pe-label">
                                    Address Line 1
                                    <span class="pe-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="address_line1"
                                    class="pe-input"
                                    maxlength="255"
                                    value="<?= e(
                                        propertyEditValue(
                                            'address_line1',
                                            $property
                                        )
                                    ); ?>"
                                    required
                                >
                            </div>

                            <div class="pe-field full">
                                <label class="pe-label">
                                    Address Line 2
                                </label>

                                <input
                                    type="text"
                                    name="address_line2"
                                    class="pe-input"
                                    maxlength="255"
                                    value="<?= e(
                                        propertyEditValue(
                                            'address_line2',
                                            $property
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="pe-field">
                                <label class="pe-label">
                                    City
                                </label>

                                <input
                                    type="text"
                                    name="city"
                                    class="pe-input"
                                    maxlength="120"
                                    value="<?= e(
                                        propertyEditValue(
                                            'city',
                                            $property
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="pe-field">
                                <label class="pe-label">
                                    State
                                </label>

                                <input
                                    type="text"
                                    name="state"
                                    class="pe-input"
                                    maxlength="120"
                                    value="<?= e(
                                        propertyEditValue(
                                            'state',
                                            $property
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="pe-field">
                                <label class="pe-label">
                                    Postal Code
                                </label>

                                <input
                                    type="text"
                                    name="postal_code"
                                    class="pe-input"
                                    maxlength="40"
                                    value="<?= e(
                                        propertyEditValue(
                                            'postal_code',
                                            $property
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="pe-field">
                                <label class="pe-label">
                                    Country
                                </label>

                                <input
                                    type="text"
                                    name="country"
                                    class="pe-input"
                                    maxlength="120"
                                    value="<?= e(
                                        propertyEditValue(
                                            'country',
                                            $property
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="pe-field">
                                <label class="pe-label">
                                    Latitude
                                </label>

                                <input
                                    type="number"
                                    name="latitude"
                                    class="pe-input"
                                    step="0.0000001"
                                    min="-90"
                                    max="90"
                                    value="<?= e(
                                        propertyEditValue(
                                            'latitude',
                                            $property
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="pe-field">
                                <label class="pe-label">
                                    Longitude
                                </label>

                                <input
                                    type="number"
                                    name="longitude"
                                    class="pe-input"
                                    step="0.0000001"
                                    min="-180"
                                    max="180"
                                    value="<?= e(
                                        propertyEditValue(
                                            'longitude',
                                            $property
                                        )
                                    ); ?>"
                                >
                            </div>
                        </div>
                    </div>
                </section>

                <section class="pe-card">
                    <div class="pe-card-head">
                        <h2>Service Details</h2>
                        <p>
                            Manage service area, access details, tax area, and field-worker instructions.
                        </p>
                    </div>

                    <div class="pe-card-body">
                        <div class="pe-grid">
                            <div class="pe-field">
                                <label class="pe-label">
                                    Service Area
                                </label>

                                <input
                                    type="text"
                                    name="service_area"
                                    class="pe-input"
                                    maxlength="190"
                                    value="<?= e(
                                        propertyEditValue(
                                            'service_area',
                                            $property
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="pe-field">
                                <label class="pe-label">
                                    Tax Area
                                </label>

                                <input
                                    type="text"
                                    name="tax_area"
                                    class="pe-input"
                                    maxlength="190"
                                    value="<?= e(
                                        propertyEditValue(
                                            'tax_area',
                                            $property
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="pe-field">
                                <label class="pe-label">
                                    Gate Code
                                </label>

                                <input
                                    type="text"
                                    name="gate_code"
                                    class="pe-input"
                                    maxlength="80"
                                    value="<?= e(
                                        propertyEditValue(
                                            'gate_code',
                                            $property
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="pe-field">
                                <label class="pe-label">
                                    Status
                                </label>

                                <select
                                    name="status"
                                    class="pe-select"
                                >
                                    <?php
                                    $statusValue =
                                        propertyEditValue(
                                            'status',
                                            $property,
                                            'active'
                                        );

                                    $statusOptions = array(
                                        'active' => 'Active',
                                        'inactive' => 'Inactive',
                                        'archived' => 'Archived'
                                    );

                                    foreach ($statusOptions as $value => $label):
                                    ?>
                                        <option
                                            value="<?= e($value); ?>"
                                            <?= $statusValue === $value
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="pe-field full">
                                <label class="pe-label">
                                    Access Notes
                                </label>

                                <textarea
                                    name="access_notes"
                                    class="pe-textarea"
                                    placeholder="Parking, gate, security, or access information"
                                ><?= e(
                                    propertyEditValue(
                                        'access_notes',
                                        $property
                                    )
                                ); ?></textarea>
                            </div>

                            <div class="pe-field full">
                                <label class="pe-label">
                                    Service Instructions
                                </label>

                                <textarea
                                    name="service_instructions"
                                    class="pe-textarea"
                                    placeholder="Important service instructions for field workers"
                                ><?= e(
                                    propertyEditValue(
                                        'service_instructions',
                                        $property
                                    )
                                ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <aside>
                <section class="pe-card">
                    <div class="pe-card-head">
                        <h2>Property Settings</h2>
                        <p>
                            Control whether this is the client’s primary service location.
                        </p>
                    </div>

                    <div class="pe-card-body">
                        <div class="pe-check-list">
                            <?php
                            $primaryChecked =
                                $_SERVER['REQUEST_METHOD'] === 'POST'
                                    ? isset($_POST['is_primary'])
                                    : !empty($property['is_primary']);
                            ?>

                            <label class="pe-check">
                                <input
                                    type="checkbox"
                                    name="is_primary"
                                    value="1"
                                    <?= $primaryChecked ? 'checked' : ''; ?>
                                >

                                <span>
                                    <span class="pe-check-title">
                                        Primary Property
                                    </span>

                                    <span class="pe-check-note">
                                        Make this the primary property for the selected client. Other properties for that client will be changed to non-primary.
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="pe-actions">
                        <a
                            href="property-view.php?id=<?= $propertyId; ?>"
                            class="pe-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="pe-btn primary"
                        >
                            <i class="bi bi-check2"></i>
                            Update Property
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
