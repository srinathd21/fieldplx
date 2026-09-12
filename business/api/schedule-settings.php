<?php
/**
 * FieldPlx - Schedule Settings API
 * File: business/api/schedule-settings.php
 * Compatible with PHP 7.2+ / MariaDB 11.x
 */

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../includes/auth.php';

if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

function ssapi_out($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code((int)$status);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ssapi_post($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}

function ssapi_table(PDO $pdo, $table)
{
    $q = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $q->execute(array(':table_name' => $table));
    return ((int)$q->fetchColumn() > 0);
}

function ssapi_csrf()
{
    $posted = ssapi_post('csrf_token');
    $saved = isset($_SESSION['schedule_settings_csrf'])
        ? (string)$_SESSION['schedule_settings_csrf']
        : '';

    if ($posted === '' || $saved === '' || !hash_equals($saved, $posted)) {
        ssapi_out(419, false, 'Your form session expired. Refresh the page and try again.');
    }
}

function ssapi_bool($key)
{
    return isset($_POST[$key]) && (string)$_POST[$key] === '1' ? 1 : 0;
}

function ssapi_audit(PDO $pdo, $tenantId, $branchId, $userId, $action, $objectType, $objectId, $values)
{
    if (function_exists('tenantAuditLog')) {
        try {
            tenantAuditLog(
                $pdo,
                $action,
                $tenantId,
                $branchId > 0 ? $branchId : null,
                $userId,
                $objectType,
                $objectId,
                null,
                $values
            );
        } catch (Throwable $ignore) {
        }
    }
}

$tenantId = isset($currentTenantId)
    ? (int)$currentTenantId
    : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);

$userId = isset($currentTenantUserId)
    ? (int)$currentTenantUserId
    : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0));

$branchId = isset($currentBranchId)
    ? (int)$currentBranchId
    : (isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0);

if ($tenantId <= 0 || $userId <= 0) {
    ssapi_out(401, false, 'Tenant session is not available.');
}

if (
    !ssapi_table($pdo, 'user_schedule_settings') ||
    !ssapi_table($pdo, 'calendar_color_assignments') ||
    !ssapi_table($pdo, 'calendar_sync_settings')
) {
    ssapi_out(503, false, 'Schedule settings database migration has not been installed.');
}

$action = ssapi_post('action');

try {
    if ($action === 'save_settings') {
        ssapi_csrf();

        $appointmentLayout = ssapi_post('appointment_layout', 'nested');
        $completedStyle = ssapi_post('completed_appointment_style', 'grayed_out');
        $orientation = ssapi_post('day_view_orientation', 'horizontal');

        if (!in_array($appointmentLayout, array('nested','stacked'), true)) {
            ssapi_out(422, false, 'Select a valid appointment layout.');
        }
        if (!in_array($completedStyle, array('grayed_out','strikethrough'), true)) {
            ssapi_out(422, false, 'Select a valid completed appointment style.');
        }
        if (!in_array($orientation, array('vertical','horizontal'), true)) {
            ssapi_out(422, false, 'Select a valid day view orientation.');
        }

        $showWeekends = ssapi_bool('show_weekends');
        $confirmReschedule = ssapi_bool('confirm_reschedule_notification');
        $propertyMap = ssapi_bool('day_sheet_property_map');
        $notesArea = ssapi_bool('day_sheet_notes_area');
        $customInfo = ssapi_bool('day_sheet_custom_information');
        $customTemplate = ssapi_post('day_sheet_custom_template');

        if (strlen($customTemplate) > 1000) {
            ssapi_out(422, false, 'Custom day sheet information must be 1000 characters or fewer.');
        }

        $pdo->beginTransaction();

        $q = $pdo->prepare("
            INSERT INTO user_schedule_settings (
                tenant_id,
                user_id,
                appointment_layout,
                completed_appointment_style,
                day_view_orientation,
                show_weekends,
                confirm_reschedule_notification,
                day_sheet_property_map,
                day_sheet_notes_area,
                day_sheet_custom_information,
                day_sheet_custom_template,
                created_at,
                updated_at
            ) VALUES (
                :tenant_id,
                :user_id,
                :appointment_layout,
                :completed_appointment_style,
                :day_view_orientation,
                :show_weekends,
                :confirm_reschedule_notification,
                :day_sheet_property_map,
                :day_sheet_notes_area,
                :day_sheet_custom_information,
                :day_sheet_custom_template,
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                appointment_layout = VALUES(appointment_layout),
                completed_appointment_style = VALUES(completed_appointment_style),
                day_view_orientation = VALUES(day_view_orientation),
                show_weekends = VALUES(show_weekends),
                confirm_reschedule_notification = VALUES(confirm_reschedule_notification),
                day_sheet_property_map = VALUES(day_sheet_property_map),
                day_sheet_notes_area = VALUES(day_sheet_notes_area),
                day_sheet_custom_information = VALUES(day_sheet_custom_information),
                day_sheet_custom_template = VALUES(day_sheet_custom_template),
                updated_at = NOW()
        ");

        $q->execute(array(
            ':tenant_id' => $tenantId,
            ':user_id' => $userId,
            ':appointment_layout' => $appointmentLayout,
            ':completed_appointment_style' => $completedStyle,
            ':day_view_orientation' => $orientation,
            ':show_weekends' => $showWeekends,
            ':confirm_reschedule_notification' => $confirmReschedule,
            ':day_sheet_property_map' => $propertyMap,
            ':day_sheet_notes_area' => $notesArea,
            ':day_sheet_custom_information' => $customInfo,
            ':day_sheet_custom_template' => $customTemplate !== '' ? $customTemplate : null
        ));

        $pdo->commit();

        ssapi_audit(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            'SCHEDULE_SETTINGS_UPDATED',
            'schedule_settings',
            $userId,
            array(
                'appointment_layout' => $appointmentLayout,
                'completed_appointment_style' => $completedStyle,
                'day_view_orientation' => $orientation,
                'show_weekends' => $showWeekends,
                'confirm_reschedule_notification' => $confirmReschedule,
                'day_sheet_property_map' => $propertyMap,
                'day_sheet_notes_area' => $notesArea,
                'day_sheet_custom_information' => $customInfo
            )
        );

        ssapi_out(200, true, 'Schedule settings updated successfully.');
    }

    if ($action === 'save_color') {
        ssapi_csrf();

        $ruleType = ssapi_post('rule_type', 'assigned_to');
        $targetUserId = (int)ssapi_post('user_id', '0');
        $ruleValue = ssapi_post('rule_value');
        $colorHex = strtoupper(ssapi_post('color_hex'));

        if (!in_array($ruleType, array('assigned_to','title_contains'), true)) {
            ssapi_out(422, false, 'Select a valid color rule.');
        }

        if (!preg_match('/^#[0-9A-F]{6}$/', $colorHex)) {
            ssapi_out(422, false, 'Select a valid calendar color.');
        }

        if ($ruleType === 'assigned_to') {
            if ($targetUserId <= 0) {
                ssapi_out(422, false, 'Select a team member.');
            }

            $q = $pdo->prepare("
                SELECT id
                FROM users
                WHERE id = :user_id
                  AND tenant_id = :tenant_id
                  AND deleted_at IS NULL
                LIMIT 1
            ");
            $q->execute(array(
                ':user_id' => $targetUserId,
                ':tenant_id' => $tenantId
            ));

            if (!(int)$q->fetchColumn()) {
                ssapi_out(404, false, 'Team member not found.');
            }

            $ruleValue = '';
        } else {
            $targetUserId = null;
            if ($ruleValue === '') {
                ssapi_out(422, false, 'Enter the item title text to match.');
            }
            if (strlen($ruleValue) > 190) {
                ssapi_out(422, false, 'Item title text must be 190 characters or fewer.');
            }
        }

        if ($ruleType === 'assigned_to') {
            $q = $pdo->prepare("
                SELECT id
                FROM calendar_color_assignments
                WHERE tenant_id = :tenant_id
                  AND rule_type = 'assigned_to'
                  AND user_id = :user_id
                LIMIT 1
            ");
            $q->execute(array(':tenant_id'=>$tenantId, ':user_id'=>$targetUserId));
        } else {
            $q = $pdo->prepare("
                SELECT id
                FROM calendar_color_assignments
                WHERE tenant_id = :tenant_id
                  AND rule_type = 'title_contains'
                  AND rule_value = :rule_value
                LIMIT 1
            ");
            $q->execute(array(':tenant_id'=>$tenantId, ':rule_value'=>$ruleValue));
        }

        $existingId = (int)$q->fetchColumn();

        if ($existingId > 0) {
            $q = $pdo->prepare("
                UPDATE calendar_color_assignments
                SET color_hex = :color_hex,
                    created_by = :created_by,
                    updated_at = NOW()
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ");
            $q->execute(array(
                ':color_hex'=>$colorHex,
                ':created_by'=>$userId,
                ':id'=>$existingId,
                ':tenant_id'=>$tenantId
            ));
        } else {
            $q = $pdo->prepare("
                INSERT INTO calendar_color_assignments (
                    tenant_id,
                    user_id,
                    rule_type,
                    rule_value,
                    color_hex,
                    created_by,
                    created_at,
                    updated_at
                ) VALUES (
                    :tenant_id,
                    :user_id,
                    :rule_type,
                    :rule_value,
                    :color_hex,
                    :created_by,
                    NOW(),
                    NOW()
                )
            ");
            $q->execute(array(
                ':tenant_id'=>$tenantId,
                ':user_id'=>$targetUserId,
                ':rule_type'=>$ruleType,
                ':rule_value'=>$ruleValue !== '' ? $ruleValue : null,
                ':color_hex'=>$colorHex,
                ':created_by'=>$userId
            ));
        }

        ssapi_audit(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            'CALENDAR_COLOR_ASSIGNED',
            $ruleType === 'assigned_to' ? 'user' : 'calendar_color_rule',
            $ruleType === 'assigned_to' ? $targetUserId : ($existingId > 0 ? $existingId : (int)$pdo->lastInsertId()),
            array(
                'rule_type'=>$ruleType,
                'rule_value'=>$ruleValue,
                'color_hex'=>$colorHex
            )
        );

        ssapi_out(200, true, 'Calendar color saved successfully.');
    }

    if ($action === 'save_calendar_sync') {
        ssapi_csrf();

        $syncTasks = ssapi_bool('sync_tasks');
        $syncReminders = ssapi_bool('sync_reminders');
        $syncEvents = ssapi_bool('sync_events');
        $syncVisits = ssapi_bool('sync_visits');
        $syncRequests = ssapi_bool('sync_requests');

        $q = $pdo->prepare("
            SELECT subscription_token
            FROM calendar_sync_settings
            WHERE tenant_id = :tenant_id
              AND user_id = :user_id
            LIMIT 1
        ");
        $q->execute(array(':tenant_id'=>$tenantId, ':user_id'=>$userId));
        $token = (string)$q->fetchColumn();

        if ($token === '') {
            $token = bin2hex(random_bytes(24));
        }

        $q = $pdo->prepare("
            INSERT INTO calendar_sync_settings (
                tenant_id,
                user_id,
                sync_tasks,
                sync_reminders,
                sync_events,
                sync_visits,
                sync_requests,
                subscription_token,
                created_at,
                updated_at
            ) VALUES (
                :tenant_id,
                :user_id,
                :sync_tasks,
                :sync_reminders,
                :sync_events,
                :sync_visits,
                :sync_requests,
                :subscription_token,
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                sync_tasks = VALUES(sync_tasks),
                sync_reminders = VALUES(sync_reminders),
                sync_events = VALUES(sync_events),
                sync_visits = VALUES(sync_visits),
                sync_requests = VALUES(sync_requests),
                subscription_token = VALUES(subscription_token),
                updated_at = NOW()
        ");

        $q->execute(array(
            ':tenant_id'=>$tenantId,
            ':user_id'=>$userId,
            ':sync_tasks'=>$syncTasks,
            ':sync_reminders'=>$syncReminders,
            ':sync_events'=>$syncEvents,
            ':sync_visits'=>$syncVisits,
            ':sync_requests'=>$syncRequests,
            ':subscription_token'=>$token
        ));

        ssapi_audit(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            'CALENDAR_SYNC_SETTINGS_UPDATED',
            'calendar_sync',
            $userId,
            array(
                'sync_tasks'=>$syncTasks,
                'sync_reminders'=>$syncReminders,
                'sync_events'=>$syncEvents,
                'sync_visits'=>$syncVisits,
                'sync_requests'=>$syncRequests
            )
        );

        ssapi_out(200, true, 'Calendar sync settings updated successfully.', array(
            'subscription_url'=>'calendar-feed.php?token=' . $token
        ));
    }

    ssapi_out(400, false, 'Invalid schedule settings action.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('FieldPlx schedule settings API database error: ' . $e->getMessage());
    ssapi_out(500, false, 'Unable to save schedule settings.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('FieldPlx schedule settings API error: ' . $e->getMessage());
    ssapi_out(500, false, 'Unable to save schedule settings.');
}
