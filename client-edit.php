<?php
/**
 * FieldPlx - Edit Client
 *
 * Upload as:
 * /public_html/client-edit.php
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
            'client-edit.php?id=' .
            (isset($_GET['id']) ? (int) $_GET['id'] : 0)
        )
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'clients.update',
        'You do not have permission to edit clients.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Edit Client - FieldPlx';
$activePage = 'client-edit';
$searchPlaceholder = 'Search clients...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$clientId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($clientId <= 0) {
    header('Location: clients.php');
    exit;
}

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('clientEditFetchAssoc')) {
    function clientEditFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('clientEditValue')) {
    function clientEditValue(
        $key,
        array $client,
        $default = ''
    ) {
        if (isset($_POST[$key])) {
            return trim((string) $_POST[$key]);
        }

        if (array_key_exists($key, $client)) {
            return trim((string) $client[$key]);
        }

        return $default;
    }
}

if (!function_exists('clientEditNullable')) {
    function clientEditNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('clientEditCsrfToken')) {
    function clientEditCsrfToken()
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

if (!function_exists('clientEditVerifyCsrf')) {
    function clientEditVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('clientEditLogActivity')) {
    function clientEditLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $clientId,
        $clientName,
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
                'client_updated',
                'client',
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

        $title = 'Client updated: ' . $clientName;

        $details = json_encode(
            array(
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
            $clientId,
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
| Load client
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT *
    FROM clients
    WHERE id = ?
      AND tenant_id = ?
      AND deleted_at IS NULL
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load client.');
}

$stmt->bind_param(
    'ii',
    $clientId,
    $tenantId
);

$stmt->execute();
$client = clientEditFetchAssoc($stmt);
$stmt->close();

if (!$client) {
    http_response_code(404);

    $pageTitle = 'Client Not Found - FieldPlx';

    require_once __DIR__ . '/includes/topbar.php';
    ?>
    <div style="padding:30px;text-align:center;">
        <h2>Client not found</h2>
        <p>
            This client does not exist or is not available for your business.
        </p>
        <a href="clients.php">Back to Clients</a>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
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

    if (!clientEditVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $clientType = clientEditValue(
        'client_type',
        $client,
        'lead'
    );

    $displayName = clientEditValue(
        'display_name',
        $client
    );

    $companyName = clientEditValue(
        'company_name',
        $client
    );

    $firstName = clientEditValue(
        'first_name',
        $client
    );

    $lastName = clientEditValue(
        'last_name',
        $client
    );

    $email = strtolower(
        clientEditValue(
            'email',
            $client
        )
    );

    $phone = clientEditValue(
        'phone',
        $client
    );

    $alternatePhone = clientEditValue(
        'alternate_phone',
        $client
    );

    $source = clientEditValue(
        'source',
        $client
    );

    $status = clientEditValue(
        'status',
        $client,
        'new'
    );

    $notes = clientEditValue(
        'notes',
        $client
    );

    $preferredContactMethod =
        clientEditValue(
            'preferred_contact_method',
            $client,
            'email'
        );

    $allowEmail = isset($_POST['allow_email'])
        ? 1
        : 0;

    $allowSms = isset($_POST['allow_sms'])
        ? 1
        : 0;

    $billingAddressLine1 =
        clientEditValue(
            'billing_address_line1',
            $client
        );

    $billingAddressLine2 =
        clientEditValue(
            'billing_address_line2',
            $client
        );

    $billingCity =
        clientEditValue(
            'billing_city',
            $client
        );

    $billingState =
        clientEditValue(
            'billing_state',
            $client
        );

    $billingPostalCode =
        clientEditValue(
            'billing_postal_code',
            $client
        );

    $billingCountry =
        clientEditValue(
            'billing_country',
            $client
        );

    $taxNumber =
        clientEditValue(
            'tax_number',
            $client
        );

    $accountManagerId =
        isset($_POST['account_manager_id']) &&
        (int) $_POST['account_manager_id'] > 0
            ? (int) $_POST['account_manager_id']
            : null;

    $allowedClientTypes = array(
        'lead',
        'client',
        'archived'
    );

    $allowedStatuses = array(
        'new',
        'active',
        'inactive',
        'archived'
    );

    $allowedContactMethods = array(
        'email',
        'sms',
        'phone',
        'none'
    );

    if (!in_array($clientType, $allowedClientTypes, true)) {
        $errors[] = 'Please select a valid client type.';
    }

    if ($displayName === '') {
        if ($companyName !== '') {
            $displayName = $companyName;
        } else {
            $displayName = trim(
                $firstName . ' ' . $lastName
            );
        }
    }

    if ($displayName === '') {
        $errors[] = 'Client display name is required.';
    }

    if (strlen($displayName) > 190) {
        $errors[] =
            'Client display name cannot exceed 190 characters.';
    }

    if (
        $email !== '' &&
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] =
            'Please enter a valid email address.';
    }

    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Please select a valid status.';
    }

    if (
        !in_array(
            $preferredContactMethod,
            $allowedContactMethods,
            true
        )
    ) {
        $errors[] =
            'Please select a valid preferred contact method.';
    }

    /*
     * Confirm account manager belongs to the same tenant.
     */
    if (
        empty($errors) &&
        $accountManagerId !== null
    ) {
        $stmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
              AND status = 'active'
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param(
                'ii',
                $accountManagerId,
                $tenantId
            );

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $errors[] =
                    'The selected account manager is not valid.';
            }

            $stmt->close();
        }
    }

    /*
     * Duplicate check excluding current client.
     */
    if (empty($errors)) {
        $duplicateSql = "
            SELECT id
            FROM clients
            WHERE tenant_id = ?
              AND id <> ?
              AND deleted_at IS NULL
              AND (
                    LOWER(display_name) = LOWER(?)
        ";

        if ($email !== '') {
            $duplicateSql .= "
                    OR LOWER(email) = LOWER(?)
            ";
        }

        if ($phone !== '') {
            $duplicateSql .= "
                    OR phone = ?
            ";
        }

        $duplicateSql .= "
              )
            LIMIT 1
        ";

        $stmt = $conn->prepare($duplicateSql);

        if ($stmt) {
            if ($email !== '' && $phone !== '') {
                $stmt->bind_param(
                    'iisss',
                    $tenantId,
                    $clientId,
                    $displayName,
                    $email,
                    $phone
                );
            } elseif ($email !== '') {
                $stmt->bind_param(
                    'iiss',
                    $tenantId,
                    $clientId,
                    $displayName,
                    $email
                );
            } elseif ($phone !== '') {
                $stmt->bind_param(
                    'iiss',
                    $tenantId,
                    $clientId,
                    $displayName,
                    $phone
                );
            } else {
                $stmt->bind_param(
                    'iis',
                    $tenantId,
                    $clientId,
                    $displayName
                );
            }

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $errors[] =
                    'Another client with the same name, email, or phone already exists.';
            }

            $stmt->close();
        }
    }

    if (empty($errors)) {
        $oldValues = array(
            'client_type' => $client['client_type'],
            'display_name' => $client['display_name'],
            'company_name' => $client['company_name'],
            'first_name' => $client['first_name'],
            'last_name' => $client['last_name'],
            'email' => $client['email'],
            'phone' => $client['phone'],
            'alternate_phone' => $client['alternate_phone'],
            'source' => $client['source'],
            'status' => $client['status'],
            'preferred_contact_method' =>
                $client['preferred_contact_method'],
            'allow_email' => (int) $client['allow_email'],
            'allow_sms' => (int) $client['allow_sms'],
            'billing_address_line1' =>
                $client['billing_address_line1'],
            'billing_address_line2' =>
                $client['billing_address_line2'],
            'billing_city' => $client['billing_city'],
            'billing_state' => $client['billing_state'],
            'billing_postal_code' =>
                $client['billing_postal_code'],
            'billing_country' =>
                $client['billing_country'],
            'tax_number' => $client['tax_number'],
            'account_manager_id' =>
                $client['account_manager_id']
        );

        $companyNameValue =
            clientEditNullable($companyName);

        $firstNameValue =
            clientEditNullable($firstName);

        $lastNameValue =
            clientEditNullable($lastName);

        $emailValue =
            clientEditNullable($email);

        $phoneValue =
            clientEditNullable($phone);

        $alternatePhoneValue =
            clientEditNullable($alternatePhone);

        $sourceValue =
            clientEditNullable($source);

        $notesValue =
            clientEditNullable($notes);

        $billingAddressLine1Value =
            clientEditNullable($billingAddressLine1);

        $billingAddressLine2Value =
            clientEditNullable($billingAddressLine2);

        $billingCityValue =
            clientEditNullable($billingCity);

        $billingStateValue =
            clientEditNullable($billingState);

        $billingPostalCodeValue =
            clientEditNullable($billingPostalCode);

        $billingCountryValue =
            clientEditNullable($billingCountry);

        $taxNumberValue =
            clientEditNullable($taxNumber);

        $stmt = $conn->prepare("
            UPDATE clients
            SET
                client_type = ?,
                display_name = ?,
                company_name = ?,
                first_name = ?,
                last_name = ?,
                email = ?,
                phone = ?,
                alternate_phone = ?,
                source = ?,
                status = ?,
                notes = ?,
                preferred_contact_method = ?,
                allow_email = ?,
                allow_sms = ?,
                billing_address_line1 = ?,
                billing_address_line2 = ?,
                billing_city = ?,
                billing_state = ?,
                billing_postal_code = ?,
                billing_country = ?,
                tax_number = ?,
                account_manager_id = ?,
                updated_by = ?,
                last_activity_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to prepare the client update request.';
        } else {
            $stmt->bind_param(
                'ssssssssssssiiissssssiiiii',
                $clientType,
                $displayName,
                $companyNameValue,
                $firstNameValue,
                $lastNameValue,
                $emailValue,
                $phoneValue,
                $alternatePhoneValue,
                $sourceValue,
                $status,
                $notesValue,
                $preferredContactMethod,
                $allowEmail,
                $allowSms,
                $billingAddressLine1Value,
                $billingAddressLine2Value,
                $billingCityValue,
                $billingStateValue,
                $billingPostalCodeValue,
                $billingCountryValue,
                $taxNumberValue,
                $accountManagerId,
                $currentUserId,
                $clientId,
                $tenantId
            );

            if ($stmt->execute()) {
                $stmt->close();

                $newValues = array(
                    'client_type' => $clientType,
                    'display_name' => $displayName,
                    'company_name' => $companyNameValue,
                    'first_name' => $firstNameValue,
                    'last_name' => $lastNameValue,
                    'email' => $emailValue,
                    'phone' => $phoneValue,
                    'alternate_phone' => $alternatePhoneValue,
                    'source' => $sourceValue,
                    'status' => $status,
                    'preferred_contact_method' =>
                        $preferredContactMethod,
                    'allow_email' => $allowEmail,
                    'allow_sms' => $allowSms,
                    'billing_address_line1' =>
                        $billingAddressLine1Value,
                    'billing_address_line2' =>
                        $billingAddressLine2Value,
                    'billing_city' =>
                        $billingCityValue,
                    'billing_state' =>
                        $billingStateValue,
                    'billing_postal_code' =>
                        $billingPostalCodeValue,
                    'billing_country' =>
                        $billingCountryValue,
                    'tax_number' =>
                        $taxNumberValue,
                    'account_manager_id' =>
                        $accountManagerId
                );

                clientEditLogActivity(
                    $conn,
                    $tenantId,
                    $currentUserId,
                    $clientId,
                    $displayName,
                    $oldValues,
                    $newValues
                );

                $_SESSION['flash_success'] =
                    'Client updated successfully.';

                header(
                    'Location: client-view.php?id=' .
                    $clientId
                );
                exit;
            }

            $errors[] =
                'Client could not be updated: ' .
                $stmt->error;

            $stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Account managers
|--------------------------------------------------------------------------
*/

$accountManagers = array();

$stmt = $conn->prepare("
    SELECT
        id,
        first_name,
        last_name,
        email
    FROM users
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status = 'active'
    ORDER BY first_name ASC, last_name ASC
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    $stmt->execute();

    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $accountManagers[] = $row;
            }
        }
    } else {
        $stmt->bind_result(
            $managerId,
            $managerFirstName,
            $managerLastName,
            $managerEmail
        );

        while ($stmt->fetch()) {
            $accountManagers[] = array(
                'id' => $managerId,
                'first_name' => $managerFirstName,
                'last_name' => $managerLastName,
                'email' => $managerEmail
            );
        }
    }

    $stmt->close();
}

$csrfToken = clientEditCsrfToken();

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
    --cv-navy: #001131;
    --cv-navy-light: #071f49;
    --cv-blue: #123d70;
    --cv-primary: #74b824;
    --cv-primary-dark: #5d971b;
    --cv-primary-soft: #f0f8e5;
    --cv-red: #e45b66;
    --cv-bg: #f6f8fb;
    --cv-text: #0b1933;
    --cv-muted: #6f7b90;
    --cv-border: #e5eaf1;
}

body {
    background: var(--cv-bg) !important;
    color: var(--cv-text);
    font-family: Arial, Helvetica, sans-serif !important;
    font-size: 14px;
}

/* Exact new FieldPlx dashboard shell */
.fieldplx-topbar {
    min-height: 70px !important;
    margin-left: var(--fieldplx-sidebar-width);
    width: calc(100% - var(--fieldplx-sidebar-width));
    background: #fff !important;
    border-bottom: 1px solid var(--cv-border) !important;
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
    color: var(--cv-navy) !important;
    background: transparent !important;
}
.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover {
    color: var(--cv-navy) !important;
    background: var(--cv-primary-soft) !important;
}
.fieldplx-search-wrap { width: 280px !important; margin-left: auto; }
.fieldplx-search-input {
    height: 41px !important;
    padding-left: 38px !important;
    border: 0 !important;
    border-radius: 8px !important;
    background: #f5f8fb !important;
    color: var(--cv-text) !important;
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
.fieldplx-profile-button:hover { background: var(--cv-primary-soft) !important; }
.fieldplx-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    border: 0 !important;
    color: var(--cv-navy) !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
    font-size: 14px !important;
    font-weight: 800 !important;
}
.fieldplx-profile-name { font-size: 14px !important; }
.fieldplx-profile-role { color: var(--cv-muted) !important; font-size: 12px !important; }
.fieldplx-notification-count { background: var(--cv-red) !important; }
.fieldplx-dropdown,
.fieldplx-profile-menu {
    border-color: var(--cv-border) !important;
    box-shadow: 0 18px 45px rgba(29,38,74,.14) !important;
}
.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover { color: var(--cv-primary-dark) !important; }

.fieldplx-sidebar {
    width: var(--fieldplx-sidebar-width) !important;
    min-width: var(--fieldplx-sidebar-width) !important;
    height: 100vh !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    z-index: 1045 !important;
    color: #fff !important;
    background: linear-gradient(180deg,var(--cv-navy-light),var(--cv-navy)) !important;
    border-top: 4px solid var(--cv-primary) !important;
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
    color: var(--cv-navy) !important;
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

/* Client Edit - exact new FieldPlx component language */
.client-edit-page {
    width: 100%;
    max-width: 1600px;
    margin: auto;
    padding: 25px 27px 35px;
}

.ce-header {
    min-height: 108px;
    margin-bottom: 18px;
    padding: 20px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    border: 1px solid var(--cv-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.ce-header-main {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 16px;
}
.ce-header-icon {
    width: 58px;
    height: 58px;
    flex: 0 0 58px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    background: linear-gradient(135deg,var(--cv-blue),var(--cv-navy));
    box-shadow: 0 8px 22px rgba(0,17,49,.16);
    font-size: 23px;
}
.ce-header h1 {
    margin: 0 0 7px;
    color: var(--cv-text);
    font-size: 28px;
    line-height: 1.1;
    font-weight: 700;
}
.ce-header p {
    margin: 0;
    color: var(--cv-muted);
    font-size: 14px;
    line-height: 1.5;
}
.ce-header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.ce-back {
    height: 46px;
    padding: 0 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid var(--cv-border);
    border-radius: 9px;
    background: #fff;
    color: #53627a;
    box-shadow: 0 4px 14px rgba(31,43,88,.04);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    transition: .2s ease;
}
.ce-back i { font-size: 14px; }
.ce-back:hover {
    border-color: #cfe3ae;
    color: var(--cv-primary-dark);
    background: #f9fcf4;
}

.ce-alert {
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
.ce-alert ul { margin: 0; padding-left: 20px; }

.ce-layout {
    display: grid;
    grid-template-columns: minmax(0,1.55fr) minmax(320px,.72fr);
    gap: 18px;
    align-items: start;
}
.ce-layout > div,
.ce-layout > aside { min-width: 0; }
.ce-layout aside {
    position: sticky;
    top: 88px;
}

.ce-card {
    overflow: hidden;
    border: 1px solid var(--cv-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.ce-card + .ce-card { margin-top: 18px; }
.ce-card-head {
    min-height: 68px;
    padding: 16px 18px 14px;
    border-bottom: 1px solid var(--cv-border);
    background: #fff;
}
.ce-card-head h2 {
    margin: 0;
    color: var(--cv-text);
    font-size: 18px;
    line-height: 1.25;
    font-weight: 700;
}
.ce-card-head p {
    margin: 5px 0 0;
    color: #8290a4;
    font-size: 12px;
    line-height: 1.5;
}
.ce-card-body { padding: 20px 18px; }

.ce-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 17px 16px;
}
.ce-field { min-width: 0; }
.ce-field.full { grid-column: 1 / -1; }
.ce-label {
    margin-bottom: 8px;
    display: block;
    color: #34455f;
    font-size: 13px;
    line-height: 1.3;
    font-weight: 700;
}
.ce-required { color: #e45b66; }

.ce-input,
.ce-select,
.ce-textarea {
    width: 100%;
    min-height: 46px;
    padding: 0 14px;
    border: 1px solid #dfe5ed;
    border-radius: 9px;
    background: #fff;
    color: var(--cv-text);
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.4;
    outline: none;
    transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
}
.ce-select {
    cursor: pointer;
    padding-right: 38px;
}
.ce-textarea {
    min-height: 126px;
    padding-top: 12px;
    padding-bottom: 12px;
    resize: vertical;
}
.ce-input::placeholder,
.ce-textarea::placeholder { color: #a1abba; }
.ce-input:hover,
.ce-select:hover,
.ce-textarea:hover { border-color: #cad4df; }
.ce-input:focus,
.ce-select:focus,
.ce-textarea:focus {
    border-color: var(--cv-primary);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(116,184,36,.14);
}

.ce-check-list { display: grid; gap: 12px; }
.ce-check {
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
.ce-check:hover {
    border-color: #cfe3ae;
    background: #fbfdf8;
    box-shadow: 0 3px 10px rgba(31,43,88,.035);
}
.ce-check input {
    width: 18px;
    height: 18px;
    flex: 0 0 18px;
    margin-top: 1px;
    accent-color: var(--cv-primary);
}
.ce-check-title {
    display: block;
    color: var(--cv-text);
    font-size: 13px;
    line-height: 1.4;
    font-weight: 700;
}
.ce-check-note {
    margin-top: 5px;
    display: block;
    color: #7f8c9f;
    font-size: 12px;
    line-height: 1.55;
}

.ce-form-actions {
    margin-top: 2px;
    padding: 16px 18px;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    border-top: 1px solid var(--cv-border);
    background: #fbfcfe;
}
.ce-btn {
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
.ce-btn i { font-size: 15px; }
.ce-btn.secondary {
    border-color: var(--cv-border);
    background: #fff;
    color: #53627a;
}
.ce-btn.secondary:hover {
    border-color: #cfe3ae;
    color: var(--cv-primary-dark);
    background: #f9fcf4;
}
.ce-btn.primary {
    border-color: var(--cv-primary);
    background: var(--cv-primary);
    color: #fff;
    box-shadow: 0 6px 16px rgba(116,184,36,.20);
}
.ce-btn.primary:hover {
    border-color: var(--cv-primary-dark);
    background: var(--cv-primary-dark);
    color: #fff;
}

/* Subtle section identity without changing the form structure */
.ce-card-head::before {
    content: '';
    width: 34px;
    height: 4px;
    display: block;
    margin-bottom: 10px;
    border-radius: 999px;
    background: var(--cv-primary);
}

@media (max-width: 1180px) {
    .ce-layout { grid-template-columns: 1fr; }
    .ce-layout aside { position: static; }
}
@media (max-width: 991.98px) {
    .fieldplx-topbar { margin-left: 0 !important; width: 100% !important; }
    .fieldplx-main-content { margin-left: 0 !important; }
    .client-edit-page { padding: 20px 18px 30px; }
}
@media (max-width: 680px) {
    .fieldplx-topbar-inner { padding: 0 14px !important; }
    .fieldplx-search-wrap { display: none !important; }
    .client-edit-page { padding: 18px 13px 28px; }
    .ce-header {
        align-items: flex-start;
        flex-direction: column;
        padding: 17px 15px;
    }
    .ce-header-main { align-items: flex-start; }
    .ce-header h1 { font-size: 24px; }
    .ce-header-actions { width: 100%; }
    .ce-back { flex: 1; }
    .ce-grid { grid-template-columns: 1fr; }
    .ce-field.full { grid-column: auto; }
    .ce-card-head { min-height: 0; padding: 15px; }
    .ce-card-body { padding: 15px; }
    .ce-input,
    .ce-select,
    .ce-textarea { font-size: 16px; }
    .ce-form-actions { padding: 15px; flex-direction: column-reverse; }
    .ce-btn { width: 100%; }
}
</style>

<div class="client-edit-page">
    <div class="ce-header">
        <div class="ce-header-main">
            <div class="ce-header-icon">
                <i class="bi bi-person-gear"></i>
            </div>
            <div>
                <h1>Edit Client</h1>
                <p>
                    Update <?= e($client['display_name']); ?> details and preferences.
                </p>
            </div>
        </div>

        <div class="ce-header-actions">
            <a
                href="client-view.php?id=<?= $clientId; ?>"
                class="ce-back"
            >
                <i class="bi bi-eye"></i>
                View Client
            </a>

            <a
                href="clients.php"
                class="ce-back"
            >
                <i class="bi bi-arrow-left"></i>
                Clients
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="ce-alert">
            <ul>
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

        <div class="ce-layout">
            <div>
                <section class="ce-card">
                    <div class="ce-card-head">
                        <h2>Client Information</h2>
                        <p>
                            Update primary business and contact details.
                        </p>
                    </div>

                    <div class="ce-card-body">
                        <div class="ce-grid">
                            <div class="ce-field">
                                <label class="ce-label">
                                    Client Type
                                    <span class="ce-required">*</span>
                                </label>

                                <select
                                    name="client_type"
                                    class="ce-select"
                                    required
                                >
                                    <?php
                                    $clientTypes = array(
                                        'lead' => 'Lead',
                                        'client' => 'Client',
                                        'archived' => 'Archived'
                                    );

                                    foreach ($clientTypes as $value => $label):
                                    ?>
                                        <option
                                            value="<?= e($value); ?>"
                                            <?= clientEditValue(
                                                'client_type',
                                                $client,
                                                'lead'
                                            ) === $value ? 'selected' : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ce-field">
                                <label class="ce-label">
                                    Status
                                    <span class="ce-required">*</span>
                                </label>

                                <select
                                    name="status"
                                    class="ce-select"
                                    required
                                >
                                    <?php
                                    $statuses = array(
                                        'new' => 'New',
                                        'active' => 'Active',
                                        'inactive' => 'Inactive',
                                        'archived' => 'Archived'
                                    );

                                    foreach ($statuses as $value => $label):
                                    ?>
                                        <option
                                            value="<?= e($value); ?>"
                                            <?= clientEditValue(
                                                'status',
                                                $client,
                                                'new'
                                            ) === $value ? 'selected' : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ce-field full">
                                <label class="ce-label">
                                    Display Name
                                    <span class="ce-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="display_name"
                                    class="ce-input"
                                    maxlength="190"
                                    value="<?= e(
                                        clientEditValue(
                                            'display_name',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field full">
                                <label class="ce-label">
                                    Company Name
                                </label>

                                <input
                                    type="text"
                                    name="company_name"
                                    class="ce-input"
                                    maxlength="190"
                                    value="<?= e(
                                        clientEditValue(
                                            'company_name',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field">
                                <label class="ce-label">
                                    First Name
                                </label>

                                <input
                                    type="text"
                                    name="first_name"
                                    class="ce-input"
                                    maxlength="120"
                                    value="<?= e(
                                        clientEditValue(
                                            'first_name',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field">
                                <label class="ce-label">
                                    Last Name
                                </label>

                                <input
                                    type="text"
                                    name="last_name"
                                    class="ce-input"
                                    maxlength="120"
                                    value="<?= e(
                                        clientEditValue(
                                            'last_name',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field">
                                <label class="ce-label">
                                    Email
                                </label>

                                <input
                                    type="email"
                                    name="email"
                                    class="ce-input"
                                    maxlength="190"
                                    value="<?= e(
                                        clientEditValue(
                                            'email',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field">
                                <label class="ce-label">
                                    Phone
                                </label>

                                <input
                                    type="text"
                                    name="phone"
                                    class="ce-input"
                                    maxlength="50"
                                    value="<?= e(
                                        clientEditValue(
                                            'phone',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field">
                                <label class="ce-label">
                                    Alternate Phone
                                </label>

                                <input
                                    type="text"
                                    name="alternate_phone"
                                    class="ce-input"
                                    maxlength="50"
                                    value="<?= e(
                                        clientEditValue(
                                            'alternate_phone',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field">
                                <label class="ce-label">
                                    Source
                                </label>

                                <input
                                    type="text"
                                    name="source"
                                    class="ce-input"
                                    maxlength="120"
                                    value="<?= e(
                                        clientEditValue(
                                            'source',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field">
                                <label class="ce-label">
                                    Preferred Contact
                                </label>

                                <select
                                    name="preferred_contact_method"
                                    class="ce-select"
                                >
                                    <?php
                                    $contactMethods = array(
                                        'email' => 'Email',
                                        'sms' => 'SMS',
                                        'phone' => 'Phone',
                                        'none' => 'None'
                                    );

                                    foreach ($contactMethods as $value => $label):
                                    ?>
                                        <option
                                            value="<?= e($value); ?>"
                                            <?= clientEditValue(
                                                'preferred_contact_method',
                                                $client,
                                                'email'
                                            ) === $value ? 'selected' : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ce-field">
                                <label class="ce-label">
                                    Account Manager
                                </label>

                                <select
                                    name="account_manager_id"
                                    class="ce-select"
                                >
                                    <option value="">
                                        Not Assigned
                                    </option>

                                    <?php foreach ($accountManagers as $manager): ?>
                                        <?php
                                        $managerName = trim(
                                            (string) $manager['first_name'] .
                                            ' ' .
                                            (string) $manager['last_name']
                                        );

                                        $selectedManagerId =
                                            isset($_POST['account_manager_id'])
                                                ? (int) $_POST['account_manager_id']
                                                : (int) $client['account_manager_id'];
                                        ?>
                                        <option
                                            value="<?= (int) $manager['id']; ?>"
                                            <?= $selectedManagerId === (int) $manager['id']
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($managerName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ce-field full">
                                <label class="ce-label">
                                    Notes
                                </label>

                                <textarea
                                    name="notes"
                                    class="ce-textarea"
                                ><?= e(
                                    clientEditValue(
                                        'notes',
                                        $client
                                    )
                                ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="ce-card">
                    <div class="ce-card-head">
                        <h2>Billing Address</h2>
                        <p>
                            Update billing and tax information.
                        </p>
                    </div>

                    <div class="ce-card-body">
                        <div class="ce-grid">
                            <div class="ce-field full">
                                <label class="ce-label">
                                    Address Line 1
                                </label>

                                <input
                                    type="text"
                                    name="billing_address_line1"
                                    class="ce-input"
                                    maxlength="255"
                                    value="<?= e(
                                        clientEditValue(
                                            'billing_address_line1',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field full">
                                <label class="ce-label">
                                    Address Line 2
                                </label>

                                <input
                                    type="text"
                                    name="billing_address_line2"
                                    class="ce-input"
                                    maxlength="255"
                                    value="<?= e(
                                        clientEditValue(
                                            'billing_address_line2',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field">
                                <label class="ce-label">
                                    City
                                </label>

                                <input
                                    type="text"
                                    name="billing_city"
                                    class="ce-input"
                                    maxlength="120"
                                    value="<?= e(
                                        clientEditValue(
                                            'billing_city',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field">
                                <label class="ce-label">
                                    State
                                </label>

                                <input
                                    type="text"
                                    name="billing_state"
                                    class="ce-input"
                                    maxlength="120"
                                    value="<?= e(
                                        clientEditValue(
                                            'billing_state',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field">
                                <label class="ce-label">
                                    Postal Code
                                </label>

                                <input
                                    type="text"
                                    name="billing_postal_code"
                                    class="ce-input"
                                    maxlength="40"
                                    value="<?= e(
                                        clientEditValue(
                                            'billing_postal_code',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field">
                                <label class="ce-label">
                                    Country
                                </label>

                                <input
                                    type="text"
                                    name="billing_country"
                                    class="ce-input"
                                    maxlength="120"
                                    value="<?= e(
                                        clientEditValue(
                                            'billing_country',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ce-field full">
                                <label class="ce-label">
                                    Tax Number
                                </label>

                                <input
                                    type="text"
                                    name="tax_number"
                                    class="ce-input"
                                    maxlength="100"
                                    value="<?= e(
                                        clientEditValue(
                                            'tax_number',
                                            $client
                                        )
                                    ); ?>"
                                >
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <aside>
                <section class="ce-card">
                    <div class="ce-card-head">
                        <h2>Communication Preferences</h2>
                        <p>
                            Control allowed contact methods.
                        </p>
                    </div>

                    <div class="ce-card-body">
                        <div class="ce-check-list">
                            <?php
                            $allowEmailChecked =
                                $_SERVER['REQUEST_METHOD'] === 'POST'
                                    ? isset($_POST['allow_email'])
                                    : !empty($client['allow_email']);

                            $allowSmsChecked =
                                $_SERVER['REQUEST_METHOD'] === 'POST'
                                    ? isset($_POST['allow_sms'])
                                    : !empty($client['allow_sms']);
                            ?>

                            <label class="ce-check">
                                <input
                                    type="checkbox"
                                    name="allow_email"
                                    value="1"
                                    <?= $allowEmailChecked ? 'checked' : ''; ?>
                                >

                                <span>
                                    <span class="ce-check-title">
                                        Allow Email
                                    </span>

                                    <span class="ce-check-note">
                                        Allow quotes, invoices, reminders, and service updates by email.
                                    </span>
                                </span>
                            </label>

                            <label class="ce-check">
                                <input
                                    type="checkbox"
                                    name="allow_sms"
                                    value="1"
                                    <?= $allowSmsChecked ? 'checked' : ''; ?>
                                >

                                <span>
                                    <span class="ce-check-title">
                                        Allow SMS
                                    </span>

                                    <span class="ce-check-note">
                                        Allow appointment and service updates by SMS.
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="ce-form-actions">
                        <a
                            href="client-view.php?id=<?= $clientId; ?>"
                            class="ce-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="ce-btn primary"
                        >
                            <i class="bi bi-check2"></i>
                            Update Client
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
