<?php
/**
 * FieldPlx - Add Request
 *
 * Upload as:
 * /public_html/request-add.php
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
        rawurlencode('request-add.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'requests.manage',
        'You do not have permission to create requests.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Add Request - FieldPlx';
$activePage = 'request-add';
$searchPlaceholder = 'Search requests...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];
$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('requestAddFetchAssoc')) {
    function requestAddFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('requestAddFetchAll')) {
    function requestAddFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('requestAddOld')) {
    function requestAddOld($key, $default = '')
    {
        if (
            isset($_POST[$key]) &&
            !is_array($_POST[$key])
        ) {
            return trim((string) $_POST[$key]);
        }

        return $default;
    }
}

if (!function_exists('requestAddNullable')) {
    function requestAddNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('requestAddCsrfToken')) {
    function requestAddCsrfToken()
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

if (!function_exists('requestAddVerifyCsrf')) {
    function requestAddVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('requestAddGenerateNumber')) {
    function requestAddGenerateNumber(
        mysqli $conn,
        $tenantId
    ) {
        $prefix = 'REQ';
        $nextNumber = 1;
        $paddingLength = 6;

        $stmt = $conn->prepare("
            SELECT
                id,
                prefix,
                next_number,
                padding_length
            FROM tenant_number_sequences
            WHERE tenant_id = ?
              AND document_type = 'request'
            LIMIT 1
            FOR UPDATE
        ");

        if (!$stmt) {
            return 'REQ-' . date('YmdHis');
        }

        $stmt->bind_param('i', $tenantId);
        $stmt->execute();

        $row = requestAddFetchAssoc($stmt);
        $stmt->close();

        if ($row) {
            $sequenceId =
                (int) $row['id'];

            $prefix =
                trim((string) $row['prefix']) !== ''
                    ? (string) $row['prefix']
                    : 'REQ';

            $nextNumber =
                max(
                    1,
                    (int) $row['next_number']
                );

            $paddingLength =
                max(
                    1,
                    (int) $row['padding_length']
                );

            $newNextNumber =
                $nextNumber + 1;

            $stmt = $conn->prepare("
                UPDATE tenant_number_sequences
                SET
                    next_number = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
            ");

            if ($stmt) {
                $stmt->bind_param(
                    'iii',
                    $newNextNumber,
                    $sequenceId,
                    $tenantId
                );

                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("
                INSERT INTO tenant_number_sequences (
                    tenant_id,
                    document_type,
                    prefix,
                    next_number,
                    padding_length,
                    reset_frequency,
                    last_reset_period,
                    updated_at
                ) VALUES (
                    ?,
                    'request',
                    'REQ',
                    2,
                    6,
                    'never',
                    NULL,
                    NOW()
                )
            ");

            if ($stmt) {
                $stmt->bind_param('i', $tenantId);
                $stmt->execute();
                $stmt->close();
            }
        }

        return $prefix .
            '-' .
            str_pad(
                (string) $nextNumber,
                $paddingLength,
                '0',
                STR_PAD_LEFT
            );
    }
}

if (!function_exists('requestAddLogActivity')) {
    function requestAddLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $requestId,
        $clientId,
        $requestNo,
        $title
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
                'request_created',
                'request',
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

        $activityTitle =
            'Request created: ' .
            $requestNo .
            ' - ' .
            $title;

        $details = json_encode(
            array(
                'request_id' => (int) $requestId,
                'request_no' => (string) $requestNo,
                'title' => (string) $title
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiiss',
            $tenantId,
            $userId,
            $requestId,
            $clientId,
            $activityTitle,
            $details
        );

        $stmt->execute();
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Selectable data
|--------------------------------------------------------------------------
*/

$clients = array();
$properties = array();
$propertiesByClient = array();
$users = array();

$stmt = $conn->prepare("
    SELECT
        id,
        display_name,
        phone,
        email
    FROM clients
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status <> 'archived'
    ORDER BY display_name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $clients = requestAddFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        client_id,
        name,
        address_line1,
        address_line2,
        city,
        state,
        postal_code,
        is_primary,
        status
    FROM properties
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status IN ('active', 'inactive')
    ORDER BY
        client_id ASC,
        is_primary DESC,
        COALESCE(NULLIF(name, ''), address_line1) ASC,
        id ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $properties = requestAddFetchAll($stmt);
    $stmt->close();
}

foreach ($properties as $property) {
    $propertyClientId =
        (int) $property['client_id'];

    $propertyName =
        trim((string) $property['name']) !== ''
            ? (string) $property['name']
            : (string) $property['address_line1'];

    $locationParts = array_filter(
        array(
            $property['address_line1'],
            $property['address_line2'],
            $property['city'],
            $property['state'],
            $property['postal_code']
        ),
        function ($value) {
            return trim((string) $value) !== '';
        }
    );

    $location =
        implode(', ', $locationParts);

    $label = $propertyName;

    if (
        $location !== '' &&
        strcasecmp(
            trim($propertyName),
            trim($location)
        ) !== 0
    ) {
        $label .= ' · ' . $location;
    }

    if (!empty($property['is_primary'])) {
        $label .= ' · Primary';
    }

    if ($property['status'] === 'inactive') {
        $label .= ' · Inactive';
    }

    if (!isset($propertiesByClient[$propertyClientId])) {
        $propertiesByClient[$propertyClientId] = array();
    }

    $propertiesByClient[$propertyClientId][] = array(
        'id' => (int) $property['id'],
        'label' => $label
    );
}

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
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $users = requestAddFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Preselected values
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!empty($_GET['client_id'])) {
        $_POST['client_id'] =
            (string) (int) $_GET['client_id'];
    }

    if (!empty($_GET['property_id'])) {
        $_POST['property_id'] =
            (string) (int) $_GET['property_id'];
    }
}

/*
|--------------------------------------------------------------------------
| Save request
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!requestAddVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $clientId = isset($_POST['client_id'])
        ? (int) $_POST['client_id']
        : 0;

    $propertyId =
        isset($_POST['property_id']) &&
        (int) $_POST['property_id'] > 0
            ? (int) $_POST['property_id']
            : null;

    $assignedUserId =
        isset($_POST['assigned_user_id']) &&
        (int) $_POST['assigned_user_id'] > 0
            ? (int) $_POST['assigned_user_id']
            : null;

    $title =
        requestAddOld('title');

    $description =
        requestAddOld('description');

    $source =
        requestAddOld('source', 'manual');

    $status =
        requestAddOld('status', 'new');

    $requestedDate =
        requestAddOld('requested_date');

    $priority =
        requestAddOld('priority', 'normal');

    $allowedSources = array(
        'manual',
        'public_form',
        'client_portal',
        'online_booking',
        'phone',
        'sms',
        'ai_receptionist',
        'import'
    );

    $allowedStatuses = array(
        'new',
        'needs_review',
        'assessment_required',
        'unscheduled',
        'overdue',
        'assessment_completed',
        'quote_required',
        'converted',
        'closed',
        'rejected',
        'archived'
    );

    $allowedPriorities = array(
        'low',
        'normal',
        'high',
        'urgent'
    );

    if ($clientId <= 0) {
        $errors[] =
            'Please select a client.';
    }

    if ($title === '') {
        $errors[] =
            'Request title is required.';
    }

    if (strlen($title) > 190) {
        $errors[] =
            'Request title cannot exceed 190 characters.';
    }

    if (!in_array($source, $allowedSources, true)) {
        $errors[] =
            'Please select a valid request source.';
    }

    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] =
            'Please select a valid request status.';
    }

    if (!in_array($priority, $allowedPriorities, true)) {
        $errors[] =
            'Please select a valid request priority.';
    }

    if (
        $requestedDate !== '' &&
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $requestedDate
        )
    ) {
        $errors[] =
            'Please enter a valid requested date.';
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
     * Validate property.
     */
    if (
        empty($errors) &&
        $propertyId !== null
    ) {
        $stmt = $conn->prepare("
            SELECT id
            FROM properties
            WHERE id = ?
              AND client_id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
              AND status <> 'archived'
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the selected property.';
        } else {
            $stmt->bind_param(
                'iii',
                $propertyId,
                $clientId,
                $tenantId
            );

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $errors[] =
                    'The selected property does not belong to this client.';
            }

            $stmt->close();
        }
    }

    /*
     * Validate assigned user.
     */
    if (
        empty($errors) &&
        $assignedUserId !== null
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

        if (!$stmt) {
            $errors[] =
                'Unable to validate the assigned user.';
        } else {
            $stmt->bind_param(
                'ii',
                $assignedUserId,
                $tenantId
            );

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $errors[] =
                    'The selected assigned user is not valid.';
            }

            $stmt->close();
        }
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            $requestNo =
                requestAddGenerateNumber(
                    $conn,
                    $tenantId
                );

            $descriptionValue =
                requestAddNullable($description);

            $requestedDateValue =
                requestAddNullable($requestedDate);

            $archivedAt =
                $status === 'archived'
                    ? date('Y-m-d H:i:s')
                    : null;

            $stmt = $conn->prepare("
                INSERT INTO requests (
                    tenant_id,
                    request_no,
                    client_id,
                    property_id,
                    title,
                    description,
                    source,
                    status,
                    requested_date,
                    assigned_user_id,
                    priority,
                    converted_quote_id,
                    converted_job_id,
                    created_by,
                    created_at,
                    updated_at,
                    archived_at
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
                    NULL,
                    NULL,
                    ?,
                    NOW(),
                    NOW(),
                    ?
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the request save operation: ' .
                    $conn->error
                );
            }

            /*
             * 13 variables / 13 type characters
             * i s i i s s s s s i s i s
             */
            $stmt->bind_param(
                'isiisssssisis',
                $tenantId,
                $requestNo,
                $clientId,
                $propertyId,
                $title,
                $descriptionValue,
                $source,
                $status,
                $requestedDateValue,
                $assignedUserId,
                $priority,
                $currentUserId,
                $archivedAt
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Request could not be saved: ' .
                    $stmt->error
                );
            }

            $requestId =
                (int) $stmt->insert_id;

            $stmt->close();

            $conn->commit();

            requestAddLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $requestId,
                $clientId,
                $requestNo,
                $title
            );

            $_SESSION['flash_success'] =
                'Request created successfully.';

            header(
                'Location: request-view.php?id=' .
                $requestId
            );
            exit;
        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            $errors[] =
                $error->getMessage();
        }
    }
}

$selectedClientId =
    (int) requestAddOld('client_id');

$selectedPropertyId =
    (int) requestAddOld('property_id');

$csrfToken =
    requestAddCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.request-add-page {
    --ra-primary: #6d28d9;
    --ra-text: #111827;
    --ra-muted: #6b7280;
    --ra-border: #e5e7eb;
}

.ra-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.ra-header h1 {
    margin: 0;
    color: var(--ra-text);
    font-size: 21px;
    font-weight: 700;
}

.ra-header p {
    margin: 5px 0 0;
    color: var(--ra-muted);
    font-size: 11px;
}

.ra-back,
.ra-btn {
    min-height: 36px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 9px;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.ra-back,
.ra-btn.secondary {
    border: 1px solid var(--ra-border);
    background: #fff;
    color: #374151;
}

.ra-btn.primary {
    border: 0;
    background: var(--ra-primary);
    color: #fff;
    cursor: pointer;
}

.ra-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 10px;
    line-height: 1.6;
}

.ra-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.45fr)
        minmax(290px,.65fr);
    gap: 13px;
    align-items: start;
}

.ra-card {
    overflow: hidden;
    border: 1px solid var(--ra-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.ra-card + .ra-card {
    margin-top: 13px;
}

.ra-card-head {
    min-height: 46px;
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
}

.ra-card-head h2 {
    margin: 0;
    color: var(--ra-text);
    font-size: 11px;
    font-weight: 700;
}

.ra-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.ra-card-body {
    padding: 14px;
}

.ra-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 11px;
}

.ra-field {
    min-width: 0;
}

.ra-field.full {
    grid-column: 1 / -1;
}

.ra-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.ra-required {
    color: #dc2626;
}

.ra-input,
.ra-select,
.ra-textarea {
    width: 100%;
    min-height: 38px;
    padding: 9px 10px;
    border: 1px solid #dfe3e8;
    border-radius: 9px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 10px;
    outline: none;
}

.ra-textarea {
    min-height: 120px;
    resize: vertical;
}

.ra-input:focus,
.ra-select:focus,
.ra-textarea:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.1);
}

.ra-summary {
    display: grid;
    gap: 9px;
}

.ra-summary-item {
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.ra-summary-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.ra-summary-value {
    margin-top: 4px;
    display: block;
    color: #111827;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.5;
}

.ra-actions {
    margin-top: 13px;
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}

@media (max-width: 1050px) {
    .ra-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
    .ra-header {
        flex-direction: column;
    }

    .ra-grid {
        grid-template-columns: 1fr;
    }

    .ra-field.full {
        grid-column: auto;
    }

    .ra-actions {
        flex-direction: column-reverse;
    }

    .ra-btn {
        width: 100%;
    }
}
</style>

<div class="request-add-page">
    <div class="ra-header">
        <div>
            <h1>Add Request</h1>
            <p>
                Create a new service request for a client and property.
            </p>
        </div>

        <a href="requests.php" class="ra-back">
            <i class="bi bi-arrow-left"></i>
            Back to Requests
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="ra-alert">
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

        <div class="ra-layout">
            <main>
                <section class="ra-card">
                    <div class="ra-card-head">
                        <h2>Request Information</h2>
                        <p>
                            Select the client and enter the service request details.
                        </p>
                    </div>

                    <div class="ra-card-body">
                        <div class="ra-grid">
                            <div class="ra-field full">
                                <label class="ra-label">
                                    Client
                                    <span class="ra-required">*</span>
                                </label>

                                <select
                                    name="client_id"
                                    id="requestClient"
                                    class="ra-select"
                                    required
                                >
                                    <option value="">
                                        Select Client
                                    </option>

                                    <?php foreach ($clients as $client): ?>
                                        <option
                                            value="<?= (int) $client['id']; ?>"
                                            data-phone="<?= e($client['phone']); ?>"
                                            data-email="<?= e($client['email']); ?>"
                                            <?= $selectedClientId ===
                                                (int) $client['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($client['display_name']); ?>

                                            <?php if (
                                                trim(
                                                    (string) $client['phone']
                                                ) !== ''
                                            ): ?>
                                                · <?= e($client['phone']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ra-field full">
                                <label class="ra-label">
                                    Property
                                </label>

                                <select
                                    name="property_id"
                                    id="requestProperty"
                                    class="ra-select"
                                    data-selected-id="<?= (int) $selectedPropertyId; ?>"
                                >
                                    <option value="">
                                        Select Client First
                                    </option>
                                </select>
                            </div>

                            <div class="ra-field full">
                                <label class="ra-label">
                                    Request Title
                                    <span class="ra-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="title"
                                    class="ra-input"
                                    maxlength="190"
                                    value="<?= e(
                                        requestAddOld('title')
                                    ); ?>"
                                    required
                                >
                            </div>

                            <div class="ra-field full">
                                <label class="ra-label">
                                    Description
                                </label>

                                <textarea
                                    name="description"
                                    class="ra-textarea"
                                    placeholder="Describe the service required, problem, or customer request."
                                ><?= e(
                                    requestAddOld('description')
                                ); ?></textarea>
                            </div>

                            <div class="ra-field">
                                <label class="ra-label">
                                    Source
                                </label>

                                <select
                                    name="source"
                                    class="ra-select"
                                >
                                    <?php
                                    $sourceOptions = array(
                                        'manual' => 'Manual',
                                        'public_form' => 'Public Form',
                                        'client_portal' => 'Client Portal',
                                        'online_booking' => 'Online Booking',
                                        'phone' => 'Phone',
                                        'sms' => 'SMS',
                                        'ai_receptionist' => 'AI Receptionist',
                                        'import' => 'Import'
                                    );

                                    $selectedSource =
                                        requestAddOld(
                                            'source',
                                            'manual'
                                        );

                                    foreach (
                                        $sourceOptions as
                                        $value => $label
                                    ):
                                    ?>
                                        <option
                                            value="<?= e($value); ?>"
                                            <?= $selectedSource === $value
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ra-field">
                                <label class="ra-label">
                                    Status
                                </label>

                                <select
                                    name="status"
                                    class="ra-select"
                                >
                                    <?php
                                    $statusOptions = array(
                                        'new' => 'New',
                                        'needs_review' => 'Needs Review',
                                        'assessment_required' => 'Assessment Required',
                                        'unscheduled' => 'Unscheduled',
                                        'overdue' => 'Overdue',
                                        'assessment_completed' => 'Assessment Completed',
                                        'quote_required' => 'Quote Required',
                                        'converted' => 'Converted',
                                        'closed' => 'Closed',
                                        'rejected' => 'Rejected',
                                        'archived' => 'Archived'
                                    );

                                    $selectedStatus =
                                        requestAddOld(
                                            'status',
                                            'new'
                                        );

                                    foreach (
                                        $statusOptions as
                                        $value => $label
                                    ):
                                    ?>
                                        <option
                                            value="<?= e($value); ?>"
                                            <?= $selectedStatus === $value
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ra-field">
                                <label class="ra-label">
                                    Priority
                                </label>

                                <select
                                    name="priority"
                                    class="ra-select"
                                >
                                    <?php
                                    $priorityOptions = array(
                                        'low' => 'Low',
                                        'normal' => 'Normal',
                                        'high' => 'High',
                                        'urgent' => 'Urgent'
                                    );

                                    $selectedPriority =
                                        requestAddOld(
                                            'priority',
                                            'normal'
                                        );

                                    foreach (
                                        $priorityOptions as
                                        $value => $label
                                    ):
                                    ?>
                                        <option
                                            value="<?= e($value); ?>"
                                            <?= $selectedPriority === $value
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ra-field">
                                <label class="ra-label">
                                    Requested Date
                                </label>

                                <input
                                    type="date"
                                    name="requested_date"
                                    class="ra-input"
                                    value="<?= e(
                                        requestAddOld(
                                            'requested_date'
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ra-field full">
                                <label class="ra-label">
                                    Assign To
                                </label>

                                <select
                                    name="assigned_user_id"
                                    class="ra-select"
                                >
                                    <option value="">
                                        Unassigned
                                    </option>

                                    <?php foreach ($users as $user): ?>
                                        <?php
                                        $userName = trim(
                                            (string) $user['first_name'] .
                                            ' ' .
                                            (string) $user['last_name']
                                        );
                                        ?>
                                        <option
                                            value="<?= (int) $user['id']; ?>"
                                            <?= (int) requestAddOld(
                                                'assigned_user_id'
                                            ) === (int) $user['id']
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($userName); ?>

                                            <?php if (
                                                trim(
                                                    (string) $user['email']
                                                ) !== ''
                                            ): ?>
                                                · <?= e($user['email']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <aside>
                <section class="ra-card">
                    <div class="ra-card-head">
                        <h2>Request Summary</h2>
                        <p>
                            Review the selected client and property.
                        </p>
                    </div>

                    <div class="ra-card-body">
                        <div class="ra-summary">
                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Client
                                </span>

                                <span
                                    class="ra-summary-value"
                                    id="summaryClient"
                                >
                                    Not selected
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Property
                                </span>

                                <span
                                    class="ra-summary-value"
                                    id="summaryProperty"
                                >
                                    No property
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Client Contact
                                </span>

                                <span
                                    class="ra-summary-value"
                                    id="summaryContact"
                                >
                                    —
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="ra-actions">
                        <a
                            href="requests.php"
                            class="ra-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="ra-btn primary"
                        >
                            <i class="bi bi-check2"></i>
                            Save Request
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

    var clientSelect =
        document.getElementById('requestClient');

    var propertySelect =
        document.getElementById('requestProperty');

    var summaryClient =
        document.getElementById('summaryClient');

    var summaryProperty =
        document.getElementById('summaryProperty');

    var summaryContact =
        document.getElementById('summaryContact');

    var propertiesByClient = <?= json_encode(
        $propertiesByClient,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var initialPropertyId =
        propertySelect
            ? String(
                propertySelect.getAttribute(
                    'data-selected-id'
                ) || ''
            )
            : '';

    function populateProperties(
        clientId,
        selectedId
    ) {
        if (!propertySelect) {
            return;
        }

        propertySelect.innerHTML = '';

        var firstOption =
            document.createElement('option');

        firstOption.value = '';

        if (clientId === '') {
            firstOption.textContent =
                'Select Client First';

            propertySelect.appendChild(
                firstOption
            );

            propertySelect.disabled = true;
            return;
        }

        firstOption.textContent =
            'No Property';

        propertySelect.appendChild(
            firstOption
        );

        var rows =
            propertiesByClient[
                String(clientId)
            ] || [];

        rows.forEach(function (property) {
            var option =
                document.createElement('option');

            option.value =
                String(property.id);

            option.textContent =
                property.label;

            if (
                String(property.id) ===
                String(selectedId)
            ) {
                option.selected = true;
            }

            propertySelect.appendChild(
                option
            );
        });

        propertySelect.disabled = false;
    }

    function updateSummary() {
        var clientOption =
            clientSelect &&
            clientSelect.selectedIndex >= 0
                ? clientSelect.options[
                    clientSelect.selectedIndex
                ]
                : null;

        var propertyOption =
            propertySelect &&
            propertySelect.selectedIndex >= 0
                ? propertySelect.options[
                    propertySelect.selectedIndex
                ]
                : null;

        summaryClient.textContent =
            clientOption &&
            clientOption.value !== ''
                ? clientOption.textContent.trim()
                : 'Not selected';

        summaryProperty.textContent =
            propertyOption &&
            propertyOption.value !== ''
                ? propertyOption.textContent.trim()
                : 'No property';

        if (
            clientOption &&
            clientOption.value !== ''
        ) {
            var phone =
                clientOption.getAttribute(
                    'data-phone'
                ) || '';

            var email =
                clientOption.getAttribute(
                    'data-email'
                ) || '';

            var contactParts = [];

            if (phone !== '') {
                contactParts.push(phone);
            }

            if (email !== '') {
                contactParts.push(email);
            }

            summaryContact.textContent =
                contactParts.length > 0
                    ? contactParts.join(' · ')
                    : '—';
        } else {
            summaryContact.textContent =
                '—';
        }
    }

    if (clientSelect) {
        clientSelect.addEventListener(
            'change',
            function () {
                initialPropertyId = '';

                populateProperties(
                    String(
                        clientSelect.value || ''
                    ),
                    ''
                );

                updateSummary();
            }
        );
    }

    if (propertySelect) {
        propertySelect.addEventListener(
            'change',
            updateSummary
        );
    }

    populateProperties(
        clientSelect
            ? String(clientSelect.value || '')
            : '',
        initialPropertyId
    );

    updateSummary();
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
