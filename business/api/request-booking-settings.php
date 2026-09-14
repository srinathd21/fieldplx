<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function rbs_out($code, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(array('success' => (bool)$success, 'message' => (string)$message), $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function rbs_post($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }
    return trim((string)$_POST[$key]);
}

function rbs_table(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name"
    );
    $stmt->execute(array(':table_name' => $table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function rbs_json_decode($value, $default = array())
{
    if ($value === null || $value === '') {
        return $default;
    }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : $default;
}

function rbs_json_encode($value)
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function rbs_csrf()
{
    $token = rbs_post('csrf_token');
    if (
        empty($_SESSION['request_booking_csrf']) ||
        !is_string($_SESSION['request_booking_csrf']) ||
        $token === '' ||
        !hash_equals($_SESSION['request_booking_csrf'], $token)
    ) {
        rbs_out(419, false, 'Your form session expired. Refresh the page and try again.');
    }
}

function rbs_require_schema(PDO $pdo)
{
    $required = array(
        'form_templates',
        'form_submissions',
        'request_booking_form_configs',
        'request_booking_service_areas'
    );
    foreach ($required as $table) {
        if (!rbs_table($pdo, $table)) {
            rbs_out(500, false, 'Requests & Bookings database migration is not installed. Run database/request-booking-forms-migration.sql.');
        }
    }
}

function rbs_business_path()
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
    if (preg_match('#^(.*?/business)(?:/api)?/[^/]+$#', $script, $matches)) {
        return rtrim($matches[1], '/');
    }
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if (substr($dir, -4) === '/api') {
        $dir = substr($dir, 0, -4);
    }
    return $dir;
}

function rbs_public_url($token, $source = '')
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
    $url = $scheme . '://' . $host . rbs_business_path() . '/request-booking-form.php?token=' . rawurlencode((string)$token);
    if ($source !== '') {
        $url .= '&source=' . rawurlencode((string)$source);
    }
    return $url;
}

function rbs_audit(PDO $pdo, $tenantId, $branchId, $userId, $action, $entityId, $values)
{
    if (!function_exists('tenantAuditLog')) {
        return;
    }
    try {
        tenantAuditLog(
            $pdo,
            (string)$action,
            (int)$tenantId,
            $branchId > 0 ? (int)$branchId : null,
            (int)$userId,
            'request_booking_form',
            (int)$entityId,
            null,
            $values
        );
    } catch (Throwable $e) {
        error_log('FieldPlx Requests & Bookings settings audit: ' . $e->getMessage());
    }
}

$tenantId = isset($currentTenantId)
    ? (int)$currentTenantId
    : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);
$userId = isset($currentTenantUserId)
    ? (int)$currentTenantUserId
    : (isset($_SESSION['user_id'])
        ? (int)$_SESSION['user_id']
        : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0));
$branchId = isset($currentBranchId)
    ? (int)$currentBranchId
    : (isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0);

if ($tenantId <= 0 || $userId <= 0) {
    rbs_out(401, false, 'Your authenticated tenant session is not available.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rbs_out(405, false, 'POST request required.');
}

rbs_csrf();
rbs_require_schema($pdo);
$action = rbs_post('action');

try {
    if ($action === 'bootstrap' || $action === 'list') {
        $stmt = $pdo->prepare(
            "SELECT
                ft.id,
                ft.name,
                ft.description,
                ft.related_module,
                ft.status,
                ft.created_at,
                ft.updated_at,
                c.form_type,
                c.public_token,
                c.slug,
                c.form_pages,
                c.is_request_default,
                c.is_booking_default,
                c.require_booking_approval,
                c.service_area_enabled,
                c.google_business_profile_json,
                c.google_analytics_code,
                c.tracking_json
             FROM form_templates ft
             INNER JOIN request_booking_form_configs c
                ON c.form_template_id = ft.id
               AND c.tenant_id = ft.tenant_id
             WHERE ft.tenant_id = :tenant_id
             ORDER BY
                c.is_booking_default DESC,
                c.is_request_default DESC,
                ft.updated_at DESC,
                ft.created_at DESC,
                ft.id DESC"
        );
        $stmt->execute(array(':tenant_id' => $tenantId));
        $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($forms as &$form) {
            $used = ((int)$form['is_request_default'] ? 1 : 0)
                + ((int)$form['is_booking_default'] ? 1 : 0);
            $google = rbs_json_decode($form['google_business_profile_json'], array());
            if (!empty($google['connected'])) {
                $used++;
            }
            $form['used_count'] = $used;
            $form['public_url'] = rbs_public_url($form['public_token']);
            $form['embed_code'] = '<iframe src="'
                . htmlspecialchars($form['public_url'], ENT_QUOTES, 'UTF-8')
                . '&embed=1" style="width:100%;min-height:720px;border:0" loading="lazy"></iframe>';

            $trackingLinks = array();
            foreach (array(
                'facebook' => 'Facebook',
                'google' => 'Google',
                'instagram' => 'Instagram',
                'yelp' => 'Yelp'
            ) as $key => $label) {
                $trackingLinks[] = array(
                    'key' => $key,
                    'label' => $label,
                    'url' => rbs_public_url($form['public_token'], $key)
                );
            }
            $form['tracking_links'] = $trackingLinks;
            unset($form['google_business_profile_json'], $form['tracking_json']);
        }
        unset($form);

        $areaStmt = $pdo->prepare(
            "SELECT id,name,address_text,latitude,longitude,radius_km,is_active
             FROM request_booking_service_areas
             WHERE tenant_id = :tenant_id
               AND (branch_id IS NULL OR branch_id = :branch_id)
             ORDER BY is_active DESC, id ASC
             LIMIT 1"
        );
        $areaStmt->execute(array(
            ':tenant_id' => $tenantId,
            ':branch_id' => $branchId
        ));
        $serviceArea = $areaStmt->fetch(PDO::FETCH_ASSOC);
        if (!$serviceArea) {
            $serviceArea = array(
                'id' => 0,
                'name' => 'Primary service area',
                'address_text' => '',
                'latitude' => null,
                'longitude' => null,
                'radius_km' => 25,
                'is_active' => 1
            );
        }

        $hours = array();
        if (rbs_table($pdo, 'tenant_business_hours')) {
            $hoursStmt = $pdo->prepare(
                "SELECT day_of_week,is_closed,open_time,close_time
                 FROM tenant_business_hours
                 WHERE tenant_id = :tenant_id
                 ORDER BY FIELD(day_of_week,'sunday','monday','tuesday','wednesday','thursday','friday','saturday')"
            );
            $hoursStmt->execute(array(':tenant_id' => $tenantId));
            $hours = $hoursStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $bookableUsers = array();
        if (rbs_table($pdo, 'users')) {
            $usersStmt = $pdo->prepare(
                "SELECT id,first_name,last_name,email
                 FROM users
                 WHERE tenant_id = :tenant_id
                   AND status = 'active'
                   AND is_bookable = 1
                 ORDER BY first_name,last_name"
            );
            $usersStmt->execute(array(':tenant_id' => $tenantId));
            $bookableUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        rbs_out(200, true, 'Requests and bookings loaded.', array(
            'forms' => $forms,
            'service_area' => $serviceArea,
            'business_hours' => $hours,
            'bookable_users' => $bookableUsers
        ));
    }

    if ($action === 'toggle_status') {
        $id = (int)rbs_post('id', '0');
        $enabled = rbs_post('enabled') === '1';
        if ($id <= 0) {
            rbs_out(422, false, 'Invalid form.');
        }

        $stmt = $pdo->prepare(
            "UPDATE form_templates
             SET status = :status, updated_at = NOW()
             WHERE id = :form_id AND tenant_id = :tenant_id"
        );
        $stmt->execute(array(
            ':status' => $enabled ? 'active' : 'inactive',
            ':form_id' => $id,
            ':tenant_id' => $tenantId
        ));

        if (!$stmt->rowCount()) {
            $check = $pdo->prepare(
                "SELECT id FROM form_templates WHERE id = :form_id AND tenant_id = :tenant_id LIMIT 1"
            );
            $check->execute(array(':form_id' => $id, ':tenant_id' => $tenantId));
            if (!$check->fetchColumn()) {
                rbs_out(404, false, 'Form not found.');
            }
        }

        rbs_audit($pdo, $tenantId, $branchId, $userId, 'REQUEST_BOOKING_FORM_STATUS_UPDATED', $id, array(
            'status' => $enabled ? 'active' : 'inactive'
        ));
        rbs_out(200, true, $enabled ? 'Form enabled.' : 'Form disabled.');
    }

    if ($action === 'set_default') {
        $id = (int)rbs_post('id', '0');
        $kind = rbs_post('kind');
        if ($id <= 0 || !in_array($kind, array('request', 'booking'), true)) {
            rbs_out(422, false, 'Invalid default request.');
        }

        $check = $pdo->prepare(
            "SELECT c.form_type
             FROM request_booking_form_configs c
             INNER JOIN form_templates ft ON ft.id = c.form_template_id
             WHERE c.form_template_id = :form_id
               AND c.tenant_id = :config_tenant_id
               AND ft.tenant_id = :template_tenant_id
             LIMIT 1"
        );
        $check->execute(array(
            ':form_id' => $id,
            ':config_tenant_id' => $tenantId,
            ':template_tenant_id' => $tenantId
        ));
        $formType = $check->fetchColumn();
        if ($formType === false) {
            rbs_out(404, false, 'Form not found.');
        }
        if ($kind === 'booking' && $formType === 'request') {
            rbs_out(422, false, 'A request-only form cannot be the default booking form.');
        }

        $column = $kind === 'request' ? 'is_request_default' : 'is_booking_default';
        $pdo->beginTransaction();

        $clear = $pdo->prepare(
            "UPDATE request_booking_form_configs SET {$column} = 0 WHERE tenant_id = :tenant_id"
        );
        $clear->execute(array(':tenant_id' => $tenantId));

        $set = $pdo->prepare(
            "UPDATE request_booking_form_configs
             SET {$column} = 1, updated_by = :updated_by, updated_at = NOW()
             WHERE tenant_id = :tenant_id AND form_template_id = :form_id"
        );
        $set->execute(array(
            ':updated_by' => $userId,
            ':tenant_id' => $tenantId,
            ':form_id' => $id
        ));

        $activate = $pdo->prepare(
            "UPDATE form_templates SET status = 'active', updated_at = NOW()
             WHERE id = :form_id AND tenant_id = :tenant_id"
        );
        $activate->execute(array(':form_id' => $id, ':tenant_id' => $tenantId));

        $pdo->commit();
        rbs_audit($pdo, $tenantId, $branchId, $userId, 'REQUEST_BOOKING_FORM_DEFAULT_UPDATED', $id, array('kind' => $kind));
        rbs_out(200, true, ucfirst($kind) . ' default updated.');
    }

    if ($action === 'delete') {
        $id = (int)rbs_post('id', '0');
        if ($id <= 0) {
            rbs_out(422, false, 'Invalid form.');
        }

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM form_submissions
             WHERE tenant_id = :tenant_id AND form_template_id = :form_id"
        );
        $countStmt->execute(array(':tenant_id' => $tenantId, ':form_id' => $id));
        $submissionCount = (int)$countStmt->fetchColumn();

        if ($submissionCount > 0) {
            $deactivate = $pdo->prepare(
                "UPDATE form_templates
                 SET status = 'inactive', updated_at = NOW()
                 WHERE id = :form_id AND tenant_id = :tenant_id"
            );
            $deactivate->execute(array(':form_id' => $id, ':tenant_id' => $tenantId));
            rbs_out(200, true, 'This form has submissions, so it was safely deactivated instead of permanently deleted.');
        }

        $delete = $pdo->prepare(
            "DELETE FROM form_templates WHERE id = :form_id AND tenant_id = :tenant_id"
        );
        $delete->execute(array(':form_id' => $id, ':tenant_id' => $tenantId));
        if (!$delete->rowCount()) {
            rbs_out(404, false, 'Form not found.');
        }
        rbs_out(200, true, 'Form deleted successfully.');
    }

    if ($action === 'save_service_area') {
        $id = (int)rbs_post('id', '0');
        $address = substr(rbs_post('address_text'), 0, 500);
        $lat = rbs_post('latitude');
        $lng = rbs_post('longitude');
        $radius = (float)rbs_post('radius_km', '25');

        if ($radius < 1) $radius = 1;
        if ($radius > 500) $radius = 500;

        $latValue = $lat === '' ? null : (float)$lat;
        $lngValue = $lng === '' ? null : (float)$lng;
        if ($latValue !== null && ($latValue < -90 || $latValue > 90)) {
            rbs_out(422, false, 'Latitude is invalid.');
        }
        if ($lngValue !== null && ($lngValue < -180 || $lngValue > 180)) {
            rbs_out(422, false, 'Longitude is invalid.');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE request_booking_service_areas
                 SET address_text = :address_text,
                     latitude = :latitude,
                     longitude = :longitude,
                     radius_km = :radius_km,
                     is_active = 1,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :area_id AND tenant_id = :tenant_id"
            );
            $stmt->execute(array(
                ':address_text' => $address !== '' ? $address : null,
                ':latitude' => $latValue,
                ':longitude' => $lngValue,
                ':radius_km' => $radius,
                ':updated_by' => $userId,
                ':area_id' => $id,
                ':tenant_id' => $tenantId
            ));
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO request_booking_service_areas
                 (tenant_id,branch_id,name,address_text,latitude,longitude,radius_km,is_active,created_by,updated_by)
                 VALUES
                 (:tenant_id,:branch_id,'Primary service area',:address_text,:latitude,:longitude,:radius_km,1,:created_by,:updated_by)"
            );
            $stmt->execute(array(
                ':tenant_id' => $tenantId,
                ':branch_id' => $branchId > 0 ? $branchId : null,
                ':address_text' => $address !== '' ? $address : null,
                ':latitude' => $latValue,
                ':longitude' => $lngValue,
                ':radius_km' => $radius,
                ':created_by' => $userId,
                ':updated_by' => $userId
            ));
            $id = (int)$pdo->lastInsertId();
        }

        if (rbs_table($pdo, 'booking_settings')) {
            $rules = rbs_json_encode(array(
                'service_area_id' => $id,
                'address' => $address,
                'latitude' => $latValue,
                'longitude' => $lngValue,
                'radius_km' => $radius
            ));

            if ($branchId > 0) {
                $find = $pdo->prepare(
                    "SELECT id FROM booking_settings
                     WHERE tenant_id = :tenant_id AND branch_id = :branch_id
                     LIMIT 1"
                );
                $find->execute(array(':tenant_id' => $tenantId, ':branch_id' => $branchId));
            } else {
                $find = $pdo->prepare(
                    "SELECT id FROM booking_settings
                     WHERE tenant_id = :tenant_id AND branch_id IS NULL
                     LIMIT 1"
                );
                $find->execute(array(':tenant_id' => $tenantId));
            }
            $bookingSettingsId = (int)$find->fetchColumn();

            if ($bookingSettingsId > 0) {
                $update = $pdo->prepare(
                    "UPDATE booking_settings
                     SET service_area_rules_json = :rules_json, updated_at = NOW()
                     WHERE id = :booking_settings_id"
                );
                $update->execute(array(
                    ':rules_json' => $rules,
                    ':booking_settings_id' => $bookingSettingsId
                ));
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO booking_settings(tenant_id,branch_id,service_area_rules_json)
                     VALUES(:tenant_id,:branch_id,:rules_json)"
                );
                $insert->execute(array(
                    ':tenant_id' => $tenantId,
                    ':branch_id' => $branchId > 0 ? $branchId : null,
                    ':rules_json' => $rules
                ));
            }
        }

        rbs_out(200, true, 'Service area saved.', array('id' => $id));
    }

    rbs_out(400, false, 'Unknown request action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('FieldPlx Requests & Bookings settings API: ' . $e->getMessage());
    rbs_out(500, false, 'Unable to process the request. ' . $e->getMessage());
}
