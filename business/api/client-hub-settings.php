<?php
/**
 * FieldPlx - Customer Hub Settings API
 * File: business/api/client-hub-settings.php
 * PHP 7.2+ / MariaDB 11.x
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

function chOut($code, $ok, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$code);
    echo json_encode(
        array_merge(array('success' => (bool)$ok, 'message' => (string)$message), $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function chPost($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }
    return trim((string)$_POST[$key]);
}

function chTable(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
    $q->execute(array(':t' => $table));
    $cache[$table] = ((int)$q->fetchColumn() > 0);
    return $cache[$table];
}

function chCol(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $q->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = ((int)$q->fetchColumn() > 0);
    return $cache[$key];
}

function chCsrf()
{
    $saved = isset($_SESSION['client_hub_settings_csrf']) ? (string)$_SESSION['client_hub_settings_csrf'] : '';
    $posted = chPost('csrf_token');
    if ($saved === '' || $posted === '' || !hash_equals($saved, $posted)) {
        chOut(419, false, 'Your form session expired. Refresh the page and try again.');
    }
}

function chActor(PDO $pdo, $tenantId, $userId)
{
    $select = array('id');
    foreach (array('tenant_id', 'role_id', 'is_tenant_admin', 'status') as $column) {
        if (chCol($pdo, 'users', $column)) {
            $select[] = '`' . $column . '`';
        }
    }

    $sql = 'SELECT ' . implode(',', $select) . ' FROM users WHERE id = :id';
    $params = array(':id' => $userId);

    if (chCol($pdo, 'users', 'tenant_id')) {
        $sql .= ' AND tenant_id = :tenant';
        $params[':tenant'] = $tenantId;
    }
    if (chCol($pdo, 'users', 'deleted_at')) {
        $sql .= ' AND deleted_at IS NULL';
    }
    $sql .= ' LIMIT 1';

    $q = $pdo->prepare($sql);
    $q->execute($params);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        chOut(401, false, 'Your login session is no longer active.');
    }
    if (isset($row['status']) && !in_array((string)$row['status'], array('active', 'invited'), true)) {
        chOut(401, false, 'Your login session is no longer active.');
    }

    if (!isset($row['tenant_id'])) $row['tenant_id'] = $tenantId;
    if (!isset($row['role_id'])) $row['role_id'] = 0;
    if (!isset($row['is_tenant_admin'])) $row['is_tenant_admin'] = 0;

    return $row;
}

function chPermission(PDO $pdo, $tenantId, $actor, $code)
{
    if (!empty($actor['is_tenant_admin'])) {
        return true;
    }

    if (!chTable($pdo, 'permissions') || !chCol($pdo, 'permissions', 'permission_code')) {
        return true;
    }

    $q = $pdo->prepare('SELECT id FROM permissions WHERE permission_code = :code LIMIT 1');
    $q->execute(array(':code' => $code));
    $permissionId = (int)$q->fetchColumn();
    if ($permissionId <= 0) {
        return true;
    }

    if (chTable($pdo, 'user_permissions') && chCol($pdo, 'user_permissions', 'access_type')) {
        $sql = 'SELECT access_type FROM user_permissions WHERE user_id = :user AND permission_id = :permission';
        $params = array(':user' => (int)$actor['id'], ':permission' => $permissionId);
        if (chCol($pdo, 'user_permissions', 'tenant_id')) {
            $sql .= ' AND tenant_id = :tenant';
            $params[':tenant'] = $tenantId;
        }
        $sql .= ' LIMIT 1';
        $q = $pdo->prepare($sql);
        $q->execute($params);
        $value = $q->fetchColumn();
        if ($value !== false) {
            return ((string)$value === 'allow');
        }
    }

    if (!empty($actor['role_id']) && chTable($pdo, 'role_permissions') && chCol($pdo, 'role_permissions', 'access_type')) {
        $sql = 'SELECT access_type FROM role_permissions WHERE role_id = :role AND permission_id = :permission';
        $params = array(':role' => (int)$actor['role_id'], ':permission' => $permissionId);
        if (chCol($pdo, 'role_permissions', 'tenant_id')) {
            $sql .= ' AND tenant_id = :tenant';
            $params[':tenant'] = $tenantId;
        }
        $sql .= ' LIMIT 1';
        $q = $pdo->prepare($sql);
        $q->execute($params);
        $value = $q->fetchColumn();
        if ($value !== false) {
            return ((string)$value === 'allow');
        }
    }

    return false;
}

function chRequire(PDO $pdo, $tenantId, $actor, $code)
{
    if (!chPermission($pdo, $tenantId, $actor, $code)) {
        chOut(403, false, 'You do not have permission to update Customer Hub settings.');
    }
}

function chAudit(PDO $pdo, $tenantId, $branchId, $userId, $action, $recordId, $values)
{
    if (function_exists('tenantAuditLog')) {
        try {
            tenantAuditLog(
                $pdo,
                $action,
                $tenantId,
                $branchId > 0 ? $branchId : null,
                $userId,
                'client_hub_settings',
                $recordId,
                null,
                $values
            );
        } catch (Throwable $e) {
            error_log('Customer Hub audit error: ' . $e->getMessage());
        }
    }
}

function chEnsureSchema(PDO $pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tenant_client_hub_settings` (
        `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `tenant_id` bigint(20) UNSIGNED NOT NULL,
        `quotes_invoices_visible` tinyint(1) NOT NULL DEFAULT 1,
        `require_quote_signature` tinyint(1) NOT NULL DEFAULT 1,
        `allow_quote_change_requests` tinyint(1) NOT NULL DEFAULT 1,
        `show_scheduled_time` tinyint(1) NOT NULL DEFAULT 1,
        `request_form_page_id` bigint(20) UNSIGNED DEFAULT NULL,
        `booking_form_page_id` bigint(20) UNSIGNED DEFAULT NULL,
        `primary_action` varchar(20) NOT NULL DEFAULT 'request',
        `referrals_enabled` tinyint(1) NOT NULL DEFAULT 0,
        `referral_message` varchar(500) DEFAULT NULL,
        `share_token` varchar(80) DEFAULT NULL,
        `created_by` bigint(20) UNSIGNED DEFAULT NULL,
        `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_tenant_client_hub_settings_tenant` (`tenant_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $required = array(
        'quotes_invoices_visible' => "tinyint(1) NOT NULL DEFAULT 1",
        'require_quote_signature' => "tinyint(1) NOT NULL DEFAULT 1",
        'allow_quote_change_requests' => "tinyint(1) NOT NULL DEFAULT 1",
        'show_scheduled_time' => "tinyint(1) NOT NULL DEFAULT 1",
        'request_form_page_id' => "bigint(20) UNSIGNED DEFAULT NULL",
        'booking_form_page_id' => "bigint(20) UNSIGNED DEFAULT NULL",
        'primary_action' => "varchar(20) NOT NULL DEFAULT 'request'",
        'referrals_enabled' => "tinyint(1) NOT NULL DEFAULT 0",
        'referral_message' => "varchar(500) DEFAULT NULL",
        'share_token' => "varchar(80) DEFAULT NULL",
        'created_by' => "bigint(20) UNSIGNED DEFAULT NULL",
        'updated_by' => "bigint(20) UNSIGNED DEFAULT NULL",
        'created_at' => "datetime NOT NULL DEFAULT current_timestamp()",
        'updated_at' => "datetime DEFAULT NULL ON UPDATE current_timestamp()"
    );

    foreach ($required as $column => $definition) {
        if (!chCol($pdo, 'tenant_client_hub_settings', $column)) {
            $pdo->exec('ALTER TABLE `tenant_client_hub_settings` ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
}

function chEnsureSettings(PDO $pdo, $tenantId, $userId)
{
    $q = $pdo->prepare('SELECT id FROM tenant_client_hub_settings WHERE tenant_id = :tenant LIMIT 1');
    $q->execute(array(':tenant' => $tenantId));
    $id = (int)$q->fetchColumn();
    if ($id > 0) {
        return $id;
    }

    $data = array(
        'tenant_id' => $tenantId,
        'quotes_invoices_visible' => 1,
        'require_quote_signature' => 1,
        'allow_quote_change_requests' => 1,
        'show_scheduled_time' => 1,
        'request_form_page_id' => null,
        'booking_form_page_id' => null,
        'primary_action' => 'request',
        'referrals_enabled' => 0,
        'referral_message' => '{{COMPANY_NAME}} did some great work for me recently. Contact them here if you are interested!',
        'share_token' => bin2hex(random_bytes(20))
    );

    $fields = array();
    $marks = array();
    $params = array();
    foreach ($data as $column => $value) {
        if (chCol($pdo, 'tenant_client_hub_settings', $column)) {
            $fields[] = '`' . $column . '`';
            $marks[] = ':' . $column;
            $params[':' . $column] = $value;
        }
    }
    if (chCol($pdo, 'tenant_client_hub_settings', 'created_by')) {
        $fields[] = '`created_by`';
        $marks[] = ':created_by';
        $params[':created_by'] = $userId > 0 ? $userId : null;
    }
    if (chCol($pdo, 'tenant_client_hub_settings', 'updated_by')) {
        $fields[] = '`updated_by`';
        $marks[] = ':updated_by';
        $params[':updated_by'] = $userId > 0 ? $userId : null;
    }
    if (chCol($pdo, 'tenant_client_hub_settings', 'created_at')) {
        $fields[] = '`created_at`';
        $marks[] = 'NOW()';
    }
    if (chCol($pdo, 'tenant_client_hub_settings', 'updated_at')) {
        $fields[] = '`updated_at`';
        $marks[] = 'NOW()';
    }

    $sql = 'INSERT INTO tenant_client_hub_settings (' . implode(',', $fields) . ') VALUES (' . implode(',', $marks) . ')';
    $pdo->prepare($sql)->execute($params);
    return (int)$pdo->lastInsertId();
}

function chBaseUrl()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)$_SERVER['HTTP_HOST']) : 'localhost';
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
    $root = dirname(dirname(dirname($script)));
    if ($root === '/' || $root === '\\' || $root === '.') {
        $root = '';
    }
    return $scheme . '://' . $host . rtrim($root, '/');
}

function chSettings(PDO $pdo, $tenantId)
{
    $q = $pdo->prepare('SELECT * FROM tenant_client_hub_settings WHERE tenant_id = :tenant LIMIT 1');
    $q->execute(array(':tenant' => $tenantId));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return array();
    }

    if (empty($row['share_token'])) {
        $token = bin2hex(random_bytes(20));
        $u = $pdo->prepare('UPDATE tenant_client_hub_settings SET share_token = :token WHERE id = :id AND tenant_id = :tenant');
        $u->execute(array(':token' => $token, ':id' => (int)$row['id'], ':tenant' => $tenantId));
        $row['share_token'] = $token;
    }

    $row['share_url'] = chBaseUrl() . '/customer/client-hub-login.php?hub=' . rawurlencode((string)$row['share_token']);
    return $row;
}

function chForms(PDO $pdo, $tenantId, $pageType)
{
    if (!chTable($pdo, 'website_pages') || !chCol($pdo, 'website_pages', 'id') || !chCol($pdo, 'website_pages', 'tenant_id')) {
        return array();
    }

    $select = array('id');
    $select[] = chCol($pdo, 'website_pages', 'title') ? 'title' : "CONCAT('Form ', id) AS title";
    $select[] = chCol($pdo, 'website_pages', 'slug') ? 'slug' : "'' AS slug";

    $sql = 'SELECT ' . implode(',', $select) . ' FROM website_pages WHERE tenant_id = :tenant';
    $params = array(':tenant' => $tenantId);

    if (chCol($pdo, 'website_pages', 'page_type')) {
        $sql .= ' AND page_type = :page_type';
        $params[':page_type'] = $pageType;
    }
    if (chCol($pdo, 'website_pages', 'status')) {
        $sql .= " AND status = 'published'";
    }

    $sql .= ' ORDER BY ' . (chCol($pdo, 'website_pages', 'title') ? 'title,' : '') . 'id';
    $q = $pdo->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function chValidForm(PDO $pdo, $tenantId, $id, $pageType)
{
    if ($id <= 0) return null;
    if (!chTable($pdo, 'website_pages') || !chCol($pdo, 'website_pages', 'tenant_id')) return null;

    $sql = 'SELECT id FROM website_pages WHERE id = :id AND tenant_id = :tenant';
    $params = array(':id' => $id, ':tenant' => $tenantId);

    if (chCol($pdo, 'website_pages', 'page_type')) {
        $sql .= ' AND page_type = :page_type';
        $params[':page_type'] = $pageType;
    }
    if (chCol($pdo, 'website_pages', 'status')) {
        $sql .= " AND status = 'published'";
    }
    $sql .= ' LIMIT 1';

    $q = $pdo->prepare($sql);
    $q->execute($params);
    return $q->fetchColumn() ? $id : null;
}

$tenantId = isset($currentTenantId) ? (int)$currentTenantId : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);
$userId = isset($currentTenantUserId) ? (int)$currentTenantUserId : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0));
$branchId = isset($currentBranchId) ? (int)$currentBranchId : (isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0);

if ($tenantId <= 0 || $userId <= 0) {
    chOut(401, false, 'Tenant session is not available.');
}

$action = chPost('action', 'load');

try {
    chEnsureSchema($pdo);
    $actor = chActor($pdo, $tenantId, $userId);
    $settingsId = chEnsureSettings($pdo, $tenantId, $userId);

    if ($action === 'load') {
        chCsrf();
        if (!chPermission($pdo, $tenantId, $actor, 'settings.view') && !chPermission($pdo, $tenantId, $actor, 'settings.update')) {
            chOut(403, false, 'You do not have permission to view Customer Hub settings.');
        }

        chOut(200, true, 'Customer Hub settings loaded.', array(
            'settings' => chSettings($pdo, $tenantId),
            'request_forms' => chForms($pdo, $tenantId, 'request_form'),
            'booking_forms' => chForms($pdo, $tenantId, 'booking'),
            'can_update' => chPermission($pdo, $tenantId, $actor, 'settings.update')
        ));
    }

    if ($action === 'save_settings') {
        chCsrf();
        chRequire($pdo, $tenantId, $actor, 'settings.update');

        $requestFormId = chValidForm($pdo, $tenantId, (int)chPost('request_form_page_id', '0'), 'request_form');
        $bookingFormId = chValidForm($pdo, $tenantId, (int)chPost('booking_form_page_id', '0'), 'booking');
        $primaryAction = chPost('primary_action', 'request');
        if (!in_array($primaryAction, array('request', 'booking'), true)) {
            $primaryAction = 'request';
        }
        if ($primaryAction === 'booking' && !$bookingFormId) {
            $primaryAction = 'request';
        }

        $message = chPost('referral_message', '');
        if ($message === '') {
            $message = '{{COMPANY_NAME}} did some great work for me recently. Contact them here if you are interested!';
        }
        if (strlen($message) > 160) {
            $message = substr($message, 0, 160);
        }

        $data = array(
            'quotes_invoices_visible' => chPost('quotes_invoices_visible', '0') === '1' ? 1 : 0,
            'require_quote_signature' => chPost('require_quote_signature', '0') === '1' ? 1 : 0,
            'allow_quote_change_requests' => chPost('allow_quote_change_requests', '0') === '1' ? 1 : 0,
            'show_scheduled_time' => chPost('show_scheduled_time', '0') === '1' ? 1 : 0,
            'request_form_page_id' => $requestFormId,
            'booking_form_page_id' => $bookingFormId,
            'primary_action' => $primaryAction,
            'referrals_enabled' => chPost('referrals_enabled', '0') === '1' ? 1 : 0,
            'referral_message' => $message
        );

        $sets = array();
        $params = array(':id' => $settingsId, ':tenant' => $tenantId);
        foreach ($data as $column => $value) {
            if (chCol($pdo, 'tenant_client_hub_settings', $column)) {
                $sets[] = '`' . $column . '` = :' . $column;
                $params[':' . $column] = $value;
            }
        }
        if (chCol($pdo, 'tenant_client_hub_settings', 'updated_by')) {
            $sets[] = '`updated_by` = :updated_by';
            $params[':updated_by'] = $userId;
        }
        if (chCol($pdo, 'tenant_client_hub_settings', 'updated_at')) {
            $sets[] = '`updated_at` = NOW()';
        }

        if (!$sets) {
            chOut(500, false, 'Customer Hub settings table has no writable settings columns.');
        }

        $sql = 'UPDATE tenant_client_hub_settings SET ' . implode(',', $sets) . ' WHERE id = :id AND tenant_id = :tenant';
        $pdo->prepare($sql)->execute($params);

        chAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_HUB_SETTINGS_UPDATED', $settingsId, $data);
        chOut(200, true, 'Customer Hub settings updated successfully.', array('settings' => chSettings($pdo, $tenantId)));
    }

    chOut(400, false, 'Invalid Customer Hub action.');
} catch (PDOException $e) {
    error_log('FieldPlx Customer Hub API database error: ' . $e->getMessage());
    $dbCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
    chOut(500, false, 'Unable to complete the Customer Hub request. Database error code: ' . $dbCode . '.', array('error_code' => $dbCode));
} catch (Throwable $e) {
    error_log('FieldPlx Customer Hub API error: ' . $e->getMessage());
    chOut(500, false, 'Unable to complete the Customer Hub request.');
}
