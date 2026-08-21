<?php
/**
 * FieldPlx - Add Client
 *
 * Upload as:
 * /public_html/client-add.php
 *
 * Compatible with:
 * - PHP 7.2+
 * - MySQLi
 * - Latest FieldPlx database structure
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
        rawurlencode('client-add.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'clients.create',
        'You do not have permission to create clients.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Add Client - FieldPlx';
$activePage = 'client-add';
$searchPlaceholder = 'Search clients...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$errors = array();
$successMessage = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('clientAddOld')) {
    function clientAddOld($key, $default = '')
    {
        return isset($_POST[$key])
            ? trim((string) $_POST[$key])
            : $default;
    }
}

if (!function_exists('clientAddNullable')) {
    function clientAddNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('clientAddCsrfToken')) {
    function clientAddCsrfToken()
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

if (!function_exists('clientAddVerifyCsrf')) {
    function clientAddVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('clientAddLogActivity')) {
    function clientAddLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $clientId,
        $clientName
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
                'client_created',
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

        $details = json_encode(
            array(
                'client_id' => (int) $clientId,
                'client_name' => (string) $clientName
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $title = 'Client created: ' . $clientName;

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
| Form submission
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!clientAddVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $clientType = clientAddOld(
        'client_type',
        'lead'
    );

    $displayName = clientAddOld('display_name');
    $companyName = clientAddOld('company_name');
    $firstName = clientAddOld('first_name');
    $lastName = clientAddOld('last_name');
    $email = strtolower(clientAddOld('email'));
    $phone = clientAddOld('phone');
    $alternatePhone = clientAddOld('alternate_phone');
    $source = clientAddOld('source');
    $status = clientAddOld('status', 'new');
    $notes = clientAddOld('notes');
    $preferredContactMethod = clientAddOld(
        'preferred_contact_method',
        'email'
    );

    $allowEmail = isset($_POST['allow_email'])
        ? 1
        : 0;

    $allowSms = isset($_POST['allow_sms'])
        ? 1
        : 0;

    $billingAddressLine1 =
        clientAddOld('billing_address_line1');

    $billingAddressLine2 =
        clientAddOld('billing_address_line2');

    $billingCity =
        clientAddOld('billing_city');

    $billingState =
        clientAddOld('billing_state');

    $billingPostalCode =
        clientAddOld('billing_postal_code');

    $billingCountry =
        clientAddOld('billing_country');

    $taxNumber =
        clientAddOld('tax_number');

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

    if (
        strlen($displayName) > 190
    ) {
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

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {
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
     * Duplicate check inside the same tenant.
     */
    if (empty($errors)) {
        $duplicateSql = "
            SELECT id
            FROM clients
            WHERE tenant_id = ?
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
                    'isss',
                    $tenantId,
                    $displayName,
                    $email,
                    $phone
                );
            } elseif ($email !== '') {
                $stmt->bind_param(
                    'iss',
                    $tenantId,
                    $displayName,
                    $email
                );
            } elseif ($phone !== '') {
                $stmt->bind_param(
                    'iss',
                    $tenantId,
                    $displayName,
                    $phone
                );
            } else {
                $stmt->bind_param(
                    'is',
                    $tenantId,
                    $displayName
                );
            }

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $errors[] =
                    'A client with the same name, email, or phone already exists.';
            }

            $stmt->close();
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO clients (
                tenant_id,
                client_type,
                display_name,
                company_name,
                first_name,
                last_name,
                email,
                phone,
                alternate_phone,
                source,
                status,
                notes,
                preferred_contact_method,
                allow_email,
                allow_sms,
                created_by,
                last_activity_at,
                created_at,
                updated_at,
                deleted_at,
                billing_address_line1,
                billing_address_line2,
                billing_city,
                billing_state,
                billing_postal_code,
                billing_country,
                tax_number,
                account_manager_id,
                updated_by
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
                NOW(),
                NOW(),
                NOW(),
                NULL,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to prepare the client save request.';
        } else {
            $companyNameValue =
                clientAddNullable($companyName);

            $firstNameValue =
                clientAddNullable($firstName);

            $lastNameValue =
                clientAddNullable($lastName);

            $emailValue =
                clientAddNullable($email);

            $phoneValue =
                clientAddNullable($phone);

            $alternatePhoneValue =
                clientAddNullable($alternatePhone);

            $sourceValue =
                clientAddNullable($source);

            $notesValue =
                clientAddNullable($notes);

            $billingAddressLine1Value =
                clientAddNullable($billingAddressLine1);

            $billingAddressLine2Value =
                clientAddNullable($billingAddressLine2);

            $billingCityValue =
                clientAddNullable($billingCity);

            $billingStateValue =
                clientAddNullable($billingState);

            $billingPostalCodeValue =
                clientAddNullable($billingPostalCode);

            $billingCountryValue =
                clientAddNullable($billingCountry);

            $taxNumberValue =
                clientAddNullable($taxNumber);

            $stmt->bind_param(
                'issssssssssssiiisssssssii',
                $tenantId,
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
                $currentUserId,
                $billingAddressLine1Value,
                $billingAddressLine2Value,
                $billingCityValue,
                $billingStateValue,
                $billingPostalCodeValue,
                $billingCountryValue,
                $taxNumberValue,
                $accountManagerId,
                $currentUserId
            );

            if ($stmt->execute()) {
                $clientId = (int) $stmt->insert_id;
                $stmt->close();

                clientAddLogActivity(
                    $conn,
                    $tenantId,
                    $currentUserId,
                    $clientId,
                    $displayName
                );

                $_SESSION['flash_success'] =
                    'Client created successfully.';

                header(
                    'Location: client-view.php?id=' .
                    $clientId
                );
                exit;
            }

            $errors[] =
                'Client could not be saved: ' .
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

    if ($stmt->execute()) {
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
    }

    $stmt->close();
}

$csrfToken = clientAddCsrfToken();

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

/* Client Add - exact new FieldPlx component language */
.client-add-page {
    width: 100%;
    max-width: 1600px;
    margin: auto;
    padding: 25px 27px 35px;
}

.client-add-header {
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
.client-add-header-main {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 16px;
}
.client-add-header-icon {
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
.client-add-header h1 {
    margin: 0 0 7px;
    color: var(--cv-text);
    font-size: 28px;
    line-height: 1.1;
    font-weight: 700;
}
.client-add-header p {
    margin: 0;
    color: var(--cv-muted);
    font-size: 14px;
    line-height: 1.5;
}
.client-add-header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.client-add-back {
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
.client-add-back i { font-size: 14px; }
.client-add-back:hover {
    border-color: #cfe3ae;
    color: var(--cv-primary-dark);
    background: #f9fcf4;
}

.client-add-alert {
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
.client-add-alert ul { margin: 0; padding-left: 20px; }

.client-add-form {
    display: grid;
    grid-template-columns: minmax(0,1.55fr) minmax(320px,.72fr);
    gap: 18px;
    align-items: start;
}
.client-add-form > div,
.client-add-form > aside { min-width: 0; }
.client-add-form aside {
    position: sticky;
    top: 88px;
}

.client-card {
    overflow: hidden;
    border: 1px solid var(--cv-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.client-card + .client-card { margin-top: 18px; }
.client-card-header {
    min-height: 68px;
    padding: 16px 18px 14px;
    border-bottom: 1px solid var(--cv-border);
    background: #fff;
}
.client-card-header h2 {
    margin: 0;
    color: var(--cv-text);
    font-size: 18px;
    line-height: 1.25;
    font-weight: 700;
}
.client-card-header p {
    margin: 5px 0 0;
    color: #8290a4;
    font-size: 12px;
    line-height: 1.5;
}
.client-card-body { padding: 20px 18px; }

.client-form-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 17px 16px;
}
.client-field { min-width: 0; }
.client-field.full { grid-column: 1 / -1; }
.client-label {
    margin-bottom: 8px;
    display: block;
    color: #34455f;
    font-size: 13px;
    line-height: 1.3;
    font-weight: 700;
}
.client-required { color: #e45b66; }

.client-input,
.client-select,
.client-textarea {
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
.client-select {
    cursor: pointer;
    padding-right: 38px;
}
.client-textarea {
    min-height: 126px;
    padding-top: 12px;
    padding-bottom: 12px;
    resize: vertical;
}
.client-input::placeholder,
.client-textarea::placeholder { color: #a1abba; }
.client-input:hover,
.client-select:hover,
.client-textarea:hover { border-color: #cad4df; }
.client-input:focus,
.client-select:focus,
.client-textarea:focus {
    border-color: var(--cv-primary);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(116,184,36,.14);
}

.client-check-list { display: grid; gap: 12px; }
.client-check {
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
.client-check:hover {
    border-color: #cfe3ae;
    background: #fbfdf8;
    box-shadow: 0 3px 10px rgba(31,43,88,.035);
}
.client-check input {
    width: 18px;
    height: 18px;
    flex: 0 0 18px;
    margin-top: 1px;
    accent-color: var(--cv-primary);
}
.client-check-title {
    display: block;
    color: var(--cv-text);
    font-size: 13px;
    line-height: 1.4;
    font-weight: 700;
}
.client-check-note {
    margin-top: 5px;
    display: block;
    color: #7f8c9f;
    font-size: 12px;
    line-height: 1.55;
}

.client-form-actions {
    margin-top: 2px;
    padding: 16px 18px;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    border-top: 1px solid var(--cv-border);
    background: #fbfcfe;
}
.client-btn {
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
.client-btn i { font-size: 15px; }
.client-btn.secondary {
    border-color: var(--cv-border);
    background: #fff;
    color: #53627a;
}
.client-btn.secondary:hover {
    border-color: #cfe3ae;
    color: var(--cv-primary-dark);
    background: #f9fcf4;
}
.client-btn.primary {
    border-color: var(--cv-primary);
    background: var(--cv-primary);
    color: #fff;
    box-shadow: 0 6px 16px rgba(116,184,36,.20);
}
.client-btn.primary:hover {
    border-color: var(--cv-primary-dark);
    background: var(--cv-primary-dark);
    color: #fff;
}

/* Subtle section identity without changing the form structure */
.client-card-header::before {
    content: '';
    width: 34px;
    height: 4px;
    display: block;
    margin-bottom: 10px;
    border-radius: 999px;
    background: var(--cv-primary);
}

@media (max-width: 1180px) {
    .client-add-form { grid-template-columns: 1fr; }
    .client-add-form aside { position: static; }
}
@media (max-width: 991.98px) {
    .fieldplx-topbar { margin-left: 0 !important; width: 100% !important; }
    .fieldplx-main-content { margin-left: 0 !important; }
    .client-add-page { padding: 20px 18px 30px; }
}
@media (max-width: 680px) {
    .fieldplx-topbar-inner { padding: 0 14px !important; }
    .fieldplx-search-wrap { display: none !important; }
    .client-add-page { padding: 18px 13px 28px; }
    .client-add-header {
        align-items: flex-start;
        flex-direction: column;
        padding: 17px 15px;
    }
    .client-add-header-main { align-items: flex-start; }
    .client-add-header h1 { font-size: 24px; }
    .client-add-header-actions { width: 100%; }
    .client-add-back { flex: 1; }
    .client-form-grid { grid-template-columns: 1fr; }
    .client-field.full { grid-column: auto; }
    .client-card-header { min-height: 0; padding: 15px; }
    .client-card-body { padding: 15px; }
    .client-input,
    .client-select,
    .client-textarea { font-size: 16px; }
    .client-form-actions { padding: 15px; flex-direction: column-reverse; }
    .client-btn { width: 100%; }
}
</style>

<div class="client-add-page">

    <div class="client-add-header">
        <div class="client-add-header-main">
            <div class="client-add-header-icon" aria-hidden="true">
                <i class="bi bi-person-plus"></i>
            </div>

            <div>
                <h1>Add Client</h1>
                <p>Create a new client or lead for the current business.</p>
            </div>
        </div>

        <div class="client-add-header-actions">
            <a
                href="clients.php"
                class="client-add-back"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Clients
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="client-add-alert error">
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

        <div class="client-add-form">
            <div>
                <section class="client-card">
                    <div class="client-card-header">
                        <h2>Client Information</h2>
                        <p>
                            Enter the primary client, business and contact details.
                        </p>
                    </div>

                    <div class="client-card-body">
                        <div class="client-form-grid">
                            <div class="client-field">
                                <label class="client-label">
                                    Client Type
                                    <span class="client-required">*</span>
                                </label>

                                <select
                                    name="client_type"
                                    class="client-select"
                                    required
                                >
                                    <option
                                        value="lead"
                                        <?= clientAddOld('client_type', 'lead') === 'lead' ? 'selected' : ''; ?>
                                    >
                                        Lead
                                    </option>

                                    <option
                                        value="client"
                                        <?= clientAddOld('client_type') === 'client' ? 'selected' : ''; ?>
                                    >
                                        Client
                                    </option>

                                    <option
                                        value="archived"
                                        <?= clientAddOld('client_type') === 'archived' ? 'selected' : ''; ?>
                                    >
                                        Archived
                                    </option>
                                </select>
                            </div>

                            <div class="client-field">
                                <label class="client-label">
                                    Status
                                    <span class="client-required">*</span>
                                </label>

                                <select
                                    name="status"
                                    class="client-select"
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
                                            <?= clientAddOld('status', 'new') === $value ? 'selected' : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="client-field full">
                                <label class="client-label">
                                    Display Name
                                    <span class="client-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="display_name"
                                    class="client-input"
                                    maxlength="190"
                                    value="<?= e(clientAddOld('display_name')); ?>"
                                    placeholder="Customer or company name"
                                >
                            </div>

                            <div class="client-field full">
                                <label class="client-label">
                                    Company Name
                                </label>

                                <input
                                    type="text"
                                    name="company_name"
                                    class="client-input"
                                    maxlength="190"
                                    value="<?= e(clientAddOld('company_name')); ?>"
                                    placeholder="Company name"
                                >
                            </div>

                            <div class="client-field">
                                <label class="client-label">
                                    First Name
                                </label>

                                <input
                                    type="text"
                                    name="first_name"
                                    class="client-input"
                                    maxlength="120"
                                    value="<?= e(clientAddOld('first_name')); ?>"
                                >
                            </div>

                            <div class="client-field">
                                <label class="client-label">
                                    Last Name
                                </label>

                                <input
                                    type="text"
                                    name="last_name"
                                    class="client-input"
                                    maxlength="120"
                                    value="<?= e(clientAddOld('last_name')); ?>"
                                >
                            </div>

                            <div class="client-field">
                                <label class="client-label">
                                    Email
                                </label>

                                <input
                                    type="email"
                                    name="email"
                                    class="client-input"
                                    maxlength="190"
                                    value="<?= e(clientAddOld('email')); ?>"
                                >
                            </div>

                            <div class="client-field">
                                <label class="client-label">
                                    Phone
                                </label>

                                <input
                                    type="text"
                                    name="phone"
                                    class="client-input"
                                    maxlength="50"
                                    value="<?= e(clientAddOld('phone')); ?>"
                                >
                            </div>

                            <div class="client-field">
                                <label class="client-label">
                                    Alternate Phone
                                </label>

                                <input
                                    type="text"
                                    name="alternate_phone"
                                    class="client-input"
                                    maxlength="50"
                                    value="<?= e(clientAddOld('alternate_phone')); ?>"
                                >
                            </div>

                            <div class="client-field">
                                <label class="client-label">
                                    Source
                                </label>

                                <input
                                    type="text"
                                    name="source"
                                    class="client-input"
                                    maxlength="120"
                                    value="<?= e(clientAddOld('source')); ?>"
                                    placeholder="Website, referral, phone..."
                                >
                            </div>

                            <div class="client-field">
                                <label class="client-label">
                                    Preferred Contact
                                </label>

                                <select
                                    name="preferred_contact_method"
                                    class="client-select"
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
                                            <?= clientAddOld(
                                                'preferred_contact_method',
                                                'email'
                                            ) === $value ? 'selected' : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="client-field">
                                <label class="client-label">
                                    Account Manager
                                </label>

                                <select
                                    name="account_manager_id"
                                    class="client-select"
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
                                        ?>
                                        <option
                                            value="<?= (int) $manager['id']; ?>"
                                            <?= (int) clientAddOld('account_manager_id') === (int) $manager['id'] ? 'selected' : ''; ?>
                                        >
                                            <?= e($managerName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="client-field full">
                                <label class="client-label">
                                    Notes
                                </label>

                                <textarea
                                    name="notes"
                                    class="client-textarea"
                                    placeholder="Internal notes about this client"
                                ><?= e(clientAddOld('notes')); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="client-card">
                    <div class="client-card-header">
                        <h2>Billing Address</h2>
                        <p>
                            Add the billing address and tax details used for documents.
                        </p>
                    </div>

                    <div class="client-card-body">
                        <div class="client-form-grid">
                            <div class="client-field full">
                                <label class="client-label">
                                    Address Line 1
                                </label>

                                <input
                                    type="text"
                                    name="billing_address_line1"
                                    class="client-input"
                                    maxlength="255"
                                    value="<?= e(clientAddOld('billing_address_line1')); ?>"
                                >
                            </div>

                            <div class="client-field full">
                                <label class="client-label">
                                    Address Line 2
                                </label>

                                <input
                                    type="text"
                                    name="billing_address_line2"
                                    class="client-input"
                                    maxlength="255"
                                    value="<?= e(clientAddOld('billing_address_line2')); ?>"
                                >
                            </div>

                            <div class="client-field">
                                <label class="client-label">
                                    City
                                </label>

                                <input
                                    type="text"
                                    name="billing_city"
                                    class="client-input"
                                    maxlength="120"
                                    value="<?= e(clientAddOld('billing_city')); ?>"
                                >
                            </div>

                            <div class="client-field">
                                <label class="client-label">
                                    State
                                </label>

                                <input
                                    type="text"
                                    name="billing_state"
                                    class="client-input"
                                    maxlength="120"
                                    value="<?= e(clientAddOld('billing_state')); ?>"
                                >
                            </div>

                            <div class="client-field">
                                <label class="client-label">
                                    Postal Code
                                </label>

                                <input
                                    type="text"
                                    name="billing_postal_code"
                                    class="client-input"
                                    maxlength="40"
                                    value="<?= e(clientAddOld('billing_postal_code')); ?>"
                                >
                            </div>

                            <div class="client-field">
                                <label class="client-label">
                                    Country
                                </label>

                                <input
                                    type="text"
                                    name="billing_country"
                                    class="client-input"
                                    maxlength="120"
                                    value="<?= e(clientAddOld('billing_country')); ?>"
                                >
                            </div>

                            <div class="client-field full">
                                <label class="client-label">
                                    Tax Number
                                </label>

                                <input
                                    type="text"
                                    name="tax_number"
                                    class="client-input"
                                    maxlength="100"
                                    value="<?= e(clientAddOld('tax_number')); ?>"
                                >
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <aside>
                <section class="client-card">
                    <div class="client-card-header">
                        <h2>Communication Preferences</h2>
                        <p>
                            Choose which communication channels are allowed for this client.
                        </p>
                    </div>

                    <div class="client-card-body">
                        <div class="client-check-list">
                            <label class="client-check">
                                <input
                                    type="checkbox"
                                    name="allow_email"
                                    value="1"
                                    <?= !isset($_POST['csrf_token']) || isset($_POST['allow_email']) ? 'checked' : ''; ?>
                                >

                                <span>
                                    <span class="client-check-title">
                                        Allow Email
                                    </span>

                                    <span class="client-check-note">
                                        Permit invoices, quotes, reminders, and updates by email.
                                    </span>
                                </span>
                            </label>

                            <label class="client-check">
                                <input
                                    type="checkbox"
                                    name="allow_sms"
                                    value="1"
                                    <?= !isset($_POST['csrf_token']) || isset($_POST['allow_sms']) ? 'checked' : ''; ?>
                                >

                                <span>
                                    <span class="client-check-title">
                                        Allow SMS
                                    </span>

                                    <span class="client-check-note">
                                        Permit appointment reminders and service updates by SMS.
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="client-form-actions">
                        <a
                            href="clients.php"
                            class="client-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="client-btn primary"
                        >
                            <i class="bi bi-check2"></i>
                            Save Client
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
