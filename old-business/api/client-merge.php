<?php
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

function cmgResponse($status, $success, $message, $extra = array())
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

function cmgPost($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function cmgTableExists(PDO $pdo, $table)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n");
    $q->execute(array(':n' => $table));
    return (int)$q->fetchColumn() > 0;
}

function cmgColumnExists(PDO $pdo, $table, $column)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $q->execute(array(':t' => $table, ':c' => $column));
    return (int)$q->fetchColumn() > 0;
}

function cmgUserContext(PDO $pdo, $tenantId, $userId)
{
    $context = array(
        'role_id' => 0,
        'is_tenant_admin' => 0,
        'is_role_admin' => 0,
        'branch_id' => 0
    );

    if (!cmgTableExists($pdo, 'users')) {
        return $context;
    }

    $roleJoin = cmgTableExists($pdo, 'roles') ? "LEFT JOIN roles r ON r.id = u.role_id AND r.tenant_id = u.tenant_id" : '';
    $roleAdmin = cmgTableExists($pdo, 'roles') ? 'COALESCE(r.is_admin, 0)' : '0';

    $q = $pdo->prepare("SELECT u.role_id, u.is_tenant_admin, u.branch_id, $roleAdmin AS is_role_admin
                        FROM users u
                        $roleJoin
                        WHERE u.id = :u AND u.tenant_id = :t AND u.deleted_at IS NULL
                        LIMIT 1");
    $q->execute(array(':u' => $userId, ':t' => $tenantId));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $context['role_id'] = (int)($row['role_id'] ?? 0);
        $context['is_tenant_admin'] = (int)($row['is_tenant_admin'] ?? 0);
        $context['is_role_admin'] = (int)($row['is_role_admin'] ?? 0);
        $context['branch_id'] = (int)($row['branch_id'] ?? 0);
    }
    return $context;
}

function cmgHasPermission(PDO $pdo, $tenantId, $userId, $permissionCode, $context)
{
    if (!empty($context['is_tenant_admin']) || !empty($context['is_role_admin'])) {
        return true;
    }

    if (!cmgTableExists($pdo, 'permissions')) {
        return false;
    }

    $q = $pdo->prepare("SELECT id FROM permissions WHERE permission_code = :p LIMIT 1");
    $q->execute(array(':p' => $permissionCode));
    $permissionId = (int)$q->fetchColumn();
    if ($permissionId <= 0) {
        return false;
    }

    if (cmgTableExists($pdo, 'user_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id = :t AND user_id = :u AND permission_id = :p LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':u' => $userId, ':p' => $permissionId));
        $access = $q->fetchColumn();
        if ($access !== false) {
            return $access === 'allow';
        }
    }

    $roleId = (int)($context['role_id'] ?? 0);
    if ($roleId > 0 && cmgTableExists($pdo, 'role_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id = :t AND role_id = :r AND permission_id = :p LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':r' => $roleId, ':p' => $permissionId));
        $access = $q->fetchColumn();
        if ($access !== false) {
            return $access === 'allow';
        }
    }

    return false;
}

function cmgRequirePermission(PDO $pdo, $tenantId, $userId, $permissionCode, $context)
{
    if (!cmgHasPermission($pdo, $tenantId, $userId, $permissionCode, $context)) {
        cmgResponse(403, false, 'You do not have permission to perform this client merge action.');
    }
}

function cmgIdsFromPost($raw)
{
    $ids = json_decode((string)$raw, true);
    if (!is_array($ids)) {
        $ids = array();
    }
    $out = array();
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $out[$id] = $id;
        }
    }
    return array_values($out);
}

function cmgClientAccessible($row, $context)
{
    if (!empty($context['is_tenant_admin']) || !empty($context['is_role_admin'])) {
        return true;
    }
    $userBranchId = (int)($context['branch_id'] ?? 0);
    if ($userBranchId <= 0) {
        return true;
    }
    return (int)($row['branch_id'] ?? 0) === $userBranchId;
}

function cmgFetchClients(PDO $pdo, $tenantId, $ids, $context, $forUpdate = false)
{
    if (!$ids) {
        return array();
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT * FROM clients WHERE tenant_id = ? AND deleted_at IS NULL AND id IN ($ph)" . ($forUpdate ? ' FOR UPDATE' : '');
    $q = $pdo->prepare($sql);
    $q->execute(array_merge(array($tenantId), $ids));
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $map = array();
    foreach ($rows as $row) {
        if (cmgClientAccessible($row, $context)) {
            $map[(int)$row['id']] = $row;
        }
    }
    return $map;
}

function cmgAddressText($row)
{
    $parts = array();
    foreach (array('address_line1', 'address_line2', 'city', 'state', 'postal_code') as $key) {
        $v = trim((string)($row[$key] ?? ''));
        if ($v !== '') {
            $parts[] = $v;
        }
    }
    return implode(', ', $parts);
}

function cmgSearchClients(PDO $pdo, $tenantId, $search, $context)
{
    $params = array(':t' => $tenantId);
    $where = array("c.tenant_id = :t", "c.deleted_at IS NULL", "c.client_type <> 'archived'", "c.status <> 'archived'");

    if (!empty($context['branch_id']) && empty($context['is_tenant_admin']) && empty($context['is_role_admin'])) {
        $where[] = 'c.branch_id = :branch_id';
        $params[':branch_id'] = (int)$context['branch_id'];
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = "(c.display_name LIKE :s1 OR c.company_name LIKE :s2 OR c.first_name LIKE :s3 OR c.last_name LIKE :s4 OR c.email LIKE :s5 OR c.phone LIKE :s6 OR c.alternate_phone LIKE :s7" .
            (cmgTableExists($pdo, 'client_locations') ? " OR EXISTS (
                SELECT 1 FROM client_locations sl
                WHERE sl.tenant_id = c.tenant_id AND sl.client_id = c.id AND sl.deleted_at IS NULL
                  AND (sl.address_line1 LIKE :s8 OR sl.address_line2 LIKE :s9 OR sl.city LIKE :s10 OR sl.state LIKE :s11 OR sl.postal_code LIKE :s12)
            )" : '') . ")";
        for ($i = 1; $i <= (cmgTableExists($pdo, 'client_locations') ? 12 : 7); $i++) {
            $params[':s' . $i] = $like;
        }
    }

    $locationSelect = cmgTableExists($pdo, 'client_locations')
        ? ", cl.address_line1, cl.address_line2, cl.city, cl.state, cl.postal_code"
        : ", NULL AS address_line1, NULL AS address_line2, NULL AS city, NULL AS state, NULL AS postal_code";
    $locationJoin = cmgTableExists($pdo, 'client_locations')
        ? "LEFT JOIN client_locations cl ON cl.id = (
            SELECT cl2.id FROM client_locations cl2
            WHERE cl2.tenant_id = c.tenant_id AND cl2.client_id = c.id AND cl2.deleted_at IS NULL
            ORDER BY cl2.is_primary DESC, cl2.id ASC LIMIT 1
        )"
        : '';

    $q = $pdo->prepare("SELECT c.id, c.branch_id, c.client_type, c.display_name, c.company_name, c.first_name, c.last_name,
                               c.email, c.phone, c.alternate_phone, c.status, c.created_at, c.updated_at, c.last_activity_at
                               $locationSelect
                        FROM clients c
                        $locationJoin
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY c.display_name ASC, c.id ASC
                        LIMIT 100");
    $q->execute($params);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['address'] = cmgAddressText($row);
    }
    unset($row);
    return $rows;
}

function cmgLinkedTables(PDO $pdo)
{
    $q = $pdo->prepare("SELECT TABLE_NAME
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'client_id'
                        ORDER BY TABLE_NAME");
    $q->execute();
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

function cmgCountTableRows(PDO $pdo, $table, $tenantId, $sourceIds)
{
    if (!$sourceIds || !cmgTableExists($pdo, $table) || !cmgColumnExists($pdo, $table, 'client_id')) {
        return 0;
    }
    $ph = implode(',', array_fill(0, count($sourceIds), '?'));
    $args = $sourceIds;
    $tenantSql = '';
    if (cmgColumnExists($pdo, $table, 'tenant_id')) {
        $tenantSql = ' AND tenant_id = ?';
        $args[] = $tenantId;
    }
    $safeTable = str_replace('`', '``', $table);
    $q = $pdo->prepare("SELECT COUNT(*) FROM `$safeTable` WHERE client_id IN ($ph)$tenantSql");
    $q->execute($args);
    return (int)$q->fetchColumn();
}

function cmgPreviewCounts(PDO $pdo, $tenantId, $sourceIds)
{
    $labels = array(
        'client_locations' => 'Locations',
        'client_contacts' => 'Contacts',
        'service_requests' => 'Service requests',
        'bookings' => 'Bookings',
        'assessments' => 'Assessments',
        'quotes' => 'Quotes',
        'jobs' => 'Jobs',
        'invoices' => 'Invoices',
        'payments' => 'Payments',
        'expenses' => 'Expenses',
        'message_threads' => 'Conversations',
        'customer_signatures' => 'Signatures',
        'reviews' => 'Reviews',
        'review_requests' => 'Review requests',
        'client_portal_users' => 'Portal users',
        'client_custom_field_values' => 'Custom field values',
        'client_communication_preferences' => 'Communication preferences',
        'client_tag_assignments' => 'Tags',
        'campaign_events' => 'Campaign activity',
        'ai_receptionist_conversations' => 'AI conversations',
        'activity_events' => 'Activity history'
    );

    $counts = array();
    $knownTotal = 0;
    $allTables = cmgLinkedTables($pdo);
    foreach ($labels as $table => $label) {
        if (!in_array($table, $allTables, true)) {
            continue;
        }
        $count = cmgCountTableRows($pdo, $table, $tenantId, $sourceIds);
        if ($count > 0) {
            $counts[] = array('table' => $table, 'label' => $label, 'count' => $count);
            $knownTotal += $count;
        }
    }

    $other = 0;
    foreach ($allTables as $table) {
        if ($table === 'clients' || isset($labels[$table])) {
            continue;
        }
        $other += cmgCountTableRows($pdo, $table, $tenantId, $sourceIds);
    }
    if ($other > 0) {
        $counts[] = array('table' => '_other', 'label' => 'Other linked records', 'count' => $other);
    }

    return $counts;
}

function cmgProfileFill($primary, $sourceRows, PDO $pdo)
{
    $fieldLabels = array(
        'title_prefix' => 'Title',
        'company_name' => 'Company name',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email',
        'phone' => 'Phone',
        'alternate_phone' => 'Alternate phone',
        'source' => 'Lead source',
        'tax_number' => 'Tax number',
        'branch_id' => 'Branch',
        'account_manager_id' => 'Account manager'
    );

    $fills = array();
    foreach ($fieldLabels as $field => $label) {
        if (!cmgColumnExists($pdo, 'clients', $field)) {
            continue;
        }
        $current = trim((string)($primary[$field] ?? ''));
        if ($current !== '' && $current !== '0') {
            continue;
        }
        foreach ($sourceRows as $source) {
            $value = trim((string)($source[$field] ?? ''));
            if ($value !== '' && $value !== '0') {
                $fills[] = array(
                    'field' => $field,
                    'label' => $label,
                    'value' => $value,
                    'source_client_id' => (int)$source['id'],
                    'source_name' => (string)$source['display_name']
                );
                break;
            }
        }
    }
    return $fills;
}

function cmgAudit(PDO $pdo, $tenantId, $branchId, $userId, $action, $clientId, $oldData, $newData)
{
    if (function_exists('tenantAuditLog')) {
        try {
            tenantAuditLog($pdo, $action, $tenantId, $branchId, $userId, 'client', $clientId, $oldData, $newData);
        } catch (Throwable $e) {
            error_log('Client merge audit error: ' . $e->getMessage());
        }
    }
}

function cmgMergeTags(PDO $pdo, $tenantId, $userId, $primaryId, $sourceIds)
{
    if (!cmgTableExists($pdo, 'client_tag_assignments')) {
        return;
    }
    $ph = implode(',', array_fill(0, count($sourceIds), '?'));
    $args = array_merge(array($tenantId, $primaryId), $sourceIds);
    $hasCreatedBy = cmgColumnExists($pdo, 'client_tag_assignments', 'created_by');
    if ($hasCreatedBy) {
        $sql = "INSERT IGNORE INTO client_tag_assignments (tenant_id, client_id, tag_id, created_by)
                SELECT tenant_id, ?, tag_id, COALESCE(created_by, ?)
                FROM client_tag_assignments
                WHERE tenant_id = ? AND client_id IN ($ph)";
        $q = $pdo->prepare($sql);
        $q->execute(array_merge(array($primaryId, $userId, $tenantId), $sourceIds));
    } else {
        $sql = "INSERT IGNORE INTO client_tag_assignments (tenant_id, client_id, tag_id)
                SELECT tenant_id, ?, tag_id
                FROM client_tag_assignments
                WHERE tenant_id = ? AND client_id IN ($ph)";
        $q = $pdo->prepare($sql);
        $q->execute(array_merge(array($primaryId, $tenantId), $sourceIds));
    }
    $q = $pdo->prepare("DELETE FROM client_tag_assignments WHERE tenant_id = ? AND client_id IN ($ph)");
    $q->execute(array_merge(array($tenantId), $sourceIds));
}

function cmgMergeCommunicationPreferences(PDO $pdo, $tenantId, $primaryId, $sourceIds)
{
    $table = 'client_communication_preferences';
    if (!cmgTableExists($pdo, $table) || !cmgColumnExists($pdo, $table, 'client_id')) {
        return;
    }
    $ph = implode(',', array_fill(0, count($sourceIds), '?'));
    $q = $pdo->prepare("SELECT id FROM `$table` WHERE tenant_id = ? AND client_id = ? LIMIT 1");
    $q->execute(array($tenantId, $primaryId));
    $primaryRowId = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT id FROM `$table` WHERE tenant_id = ? AND client_id IN ($ph) ORDER BY id ASC");
    $q->execute(array_merge(array($tenantId), $sourceIds));
    $sourceRowIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    if (!$sourceRowIds) {
        return;
    }
    if ($primaryRowId <= 0) {
        $first = array_shift($sourceRowIds);
        $q = $pdo->prepare("UPDATE `$table` SET client_id = ? WHERE id = ? AND tenant_id = ?");
        $q->execute(array($primaryId, $first, $tenantId));
    }
    if ($sourceRowIds) {
        $ph2 = implode(',', array_fill(0, count($sourceRowIds), '?'));
        $q = $pdo->prepare("DELETE FROM `$table` WHERE tenant_id = ? AND id IN ($ph2)");
        $q->execute(array_merge(array($tenantId), $sourceRowIds));
    }
}

function cmgMergeCustomFields(PDO $pdo, $tenantId, $primaryId, $sourceIds)
{
    $table = 'client_custom_field_values';
    if (!cmgTableExists($pdo, $table) || !cmgColumnExists($pdo, $table, 'field_id')) {
        return;
    }
    $ph = implode(',', array_fill(0, count($sourceIds), '?'));
    $q = $pdo->prepare("SELECT id, client_id, field_id, field_value FROM `$table` WHERE tenant_id = ? AND client_id IN ($ph) ORDER BY id ASC");
    $q->execute(array_merge(array($tenantId), $sourceIds));
    $sourceRows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$sourceRows) {
        return;
    }

    $q = $pdo->prepare("SELECT id, field_id, field_value FROM `$table` WHERE tenant_id = ? AND client_id = ?");
    $q->execute(array($tenantId, $primaryId));
    $primaryMap = array();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $primaryMap[(int)$r['field_id']] = array(
            'id' => (int)$r['id'],
            'value' => trim((string)$r['field_value'])
        );
    }

    $movedField = array();
    $deleteIds = array();
    foreach ($sourceRows as $row) {
        $fieldId = (int)$row['field_id'];
        $value = trim((string)$row['field_value']);
        if (isset($primaryMap[$fieldId])) {
            if ($primaryMap[$fieldId]['value'] === '' && $value !== '') {
                $q = $pdo->prepare("UPDATE `$table` SET field_value = ? WHERE id = ? AND tenant_id = ? AND client_id = ?");
                $q->execute(array($row['field_value'], $primaryMap[$fieldId]['id'], $tenantId, $primaryId));
                $primaryMap[$fieldId]['value'] = $value;
            }
            $deleteIds[] = (int)$row['id'];
            continue;
        }
        if (!isset($movedField[$fieldId])) {
            $q = $pdo->prepare("UPDATE `$table` SET client_id = ? WHERE id = ? AND tenant_id = ?");
            $q->execute(array($primaryId, (int)$row['id'], $tenantId));
            $movedField[$fieldId] = true;
            $primaryMap[$fieldId] = array('id' => (int)$row['id'], 'value' => $value);
        } else {
            $deleteIds[] = (int)$row['id'];
        }
    }

    if ($deleteIds) {
        $ph2 = implode(',', array_fill(0, count($deleteIds), '?'));
        $q = $pdo->prepare("DELETE FROM `$table` WHERE tenant_id = ? AND id IN ($ph2)");
        $q->execute(array_merge(array($tenantId), $deleteIds));
    }
}

function cmgMergePhoneNumbers(PDO $pdo, $tenantId, $primaryId, $sourceIds)
{
    $table = 'client_phone_numbers';

    if (!$sourceIds || !cmgTableExists($pdo, $table) || !cmgColumnExists($pdo, $table, 'client_id') || !cmgColumnExists($pdo, $table, 'phone_number')) {
        return array('moved' => 0, 'duplicates_removed' => 0);
    }

    $ph = implode(',', array_fill(0, count($sourceIds), '?'));

    /*
     * Build a map of phone numbers already owned by the client we keep.
     * The table has a unique key on (tenant_id, client_id, phone_number),
     * so duplicate numbers must be merged before the generic reassign step.
     */
    $q = $pdo->prepare("SELECT id, phone_number" .
        (cmgColumnExists($pdo, $table, 'receives_messages') ? ", receives_messages" : "") .
        " FROM `$table`
          WHERE tenant_id = ? AND client_id = ?
          ORDER BY " . (cmgColumnExists($pdo, $table, 'is_primary') ? "is_primary DESC, " : "") .
          (cmgColumnExists($pdo, $table, 'sort_order') ? "sort_order ASC, " : "") . "id ASC");
    $q->execute(array($tenantId, $primaryId));

    $existingByPhone = array();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $phone = trim((string)$row['phone_number']);
        if ($phone !== '' && !isset($existingByPhone[$phone])) {
            $existingByPhone[$phone] = $row;
        }
    }

    $selectColumns = array('id', 'client_id', 'phone_number');
    foreach (array('phone_type', 'receives_messages', 'is_primary', 'sort_order') as $column) {
        if (cmgColumnExists($pdo, $table, $column)) {
            $selectColumns[] = $column;
        }
    }

    $order = array();
    if (cmgColumnExists($pdo, $table, 'is_primary')) {
        $order[] = 'is_primary DESC';
    }
    if (cmgColumnExists($pdo, $table, 'sort_order')) {
        $order[] = 'sort_order ASC';
    }
    $order[] = 'id ASC';

    $q = $pdo->prepare("SELECT " . implode(', ', $selectColumns) . "
                        FROM `$table`
                        WHERE tenant_id = ? AND client_id IN ($ph)
                        ORDER BY " . implode(', ', $order));
    $q->execute(array_merge(array($tenantId), $sourceIds));
    $sourcePhones = $q->fetchAll(PDO::FETCH_ASSOC);

    $moved = 0;
    $duplicatesRemoved = 0;

    foreach ($sourcePhones as $row) {
        $rowId = (int)$row['id'];
        $phone = trim((string)$row['phone_number']);

        /* Empty phone rows are invalid for the current schema, but handle them safely. */
        if ($phone === '') {
            $del = $pdo->prepare("DELETE FROM `$table` WHERE id = ? AND tenant_id = ?");
            $del->execute(array($rowId, $tenantId));
            $duplicatesRemoved += $del->rowCount();
            continue;
        }

        if (isset($existingByPhone[$phone])) {
            $existing = $existingByPhone[$phone];

            /* Preserve the more permissive messaging flag when the same number exists on both clients. */
            if (cmgColumnExists($pdo, $table, 'receives_messages') && !empty($row['receives_messages']) && empty($existing['receives_messages'])) {
                $up = $pdo->prepare("UPDATE `$table` SET receives_messages = 1 WHERE id = ? AND tenant_id = ? AND client_id = ?");
                $up->execute(array((int)$existing['id'], $tenantId, $primaryId));
                $existingByPhone[$phone]['receives_messages'] = 1;
            }

            $del = $pdo->prepare("DELETE FROM `$table` WHERE id = ? AND tenant_id = ?");
            $del->execute(array($rowId, $tenantId));
            $duplicatesRemoved += $del->rowCount();
            continue;
        }

        $up = $pdo->prepare("UPDATE `$table` SET client_id = ? WHERE id = ? AND tenant_id = ?");
        $up->execute(array($primaryId, $rowId, $tenantId));
        $moved += $up->rowCount();

        $row['client_id'] = $primaryId;
        $existingByPhone[$phone] = $row;
    }

    /* Keep exactly one primary phone after the merge. */
    if (cmgColumnExists($pdo, $table, 'is_primary')) {
        $order = array('is_primary DESC');
        if (cmgColumnExists($pdo, $table, 'sort_order')) {
            $order[] = 'sort_order ASC';
        }
        $order[] = 'id ASC';

        $q = $pdo->prepare("SELECT id FROM `$table`
                            WHERE tenant_id = ? AND client_id = ?
                            ORDER BY " . implode(', ', $order) . "
                            LIMIT 1");
        $q->execute(array($tenantId, $primaryId));
        $keepPhoneId = (int)$q->fetchColumn();

        if ($keepPhoneId > 0) {
            $q = $pdo->prepare("UPDATE `$table` SET is_primary = 0 WHERE tenant_id = ? AND client_id = ?");
            $q->execute(array($tenantId, $primaryId));

            $q = $pdo->prepare("UPDATE `$table` SET is_primary = 1 WHERE id = ? AND tenant_id = ? AND client_id = ?");
            $q->execute(array($keepPhoneId, $tenantId, $primaryId));
        }
    }

    /* Re-number the sort order so the kept client's phone list remains clean. */
    if (cmgColumnExists($pdo, $table, 'sort_order')) {
        $order = array();
        if (cmgColumnExists($pdo, $table, 'is_primary')) {
            $order[] = 'is_primary DESC';
        }
        $order[] = 'sort_order ASC';
        $order[] = 'id ASC';

        $q = $pdo->prepare("SELECT id FROM `$table`
                            WHERE tenant_id = ? AND client_id = ?
                            ORDER BY " . implode(', ', $order));
        $q->execute(array($tenantId, $primaryId));
        $phoneIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));

        $sort = 1;
        $upSort = $pdo->prepare("UPDATE `$table` SET sort_order = ? WHERE id = ? AND tenant_id = ? AND client_id = ?");
        foreach ($phoneIds as $phoneId) {
            $upSort->execute(array($sort++, $phoneId, $tenantId, $primaryId));
        }
    }

    return array('moved' => $moved, 'duplicates_removed' => $duplicatesRemoved);
}

function cmgNormalizePrimaryFlag(PDO $pdo, $table, $tenantId, $primaryId)
{
    if (!cmgTableExists($pdo, $table) || !cmgColumnExists($pdo, $table, 'is_primary')) {
        return;
    }
    $deletedClause = cmgColumnExists($pdo, $table, 'deleted_at') ? ' AND deleted_at IS NULL' : '';
    $q = $pdo->prepare("SELECT id FROM `$table` WHERE tenant_id = ? AND client_id = ?$deletedClause ORDER BY is_primary DESC, id ASC LIMIT 1");
    $q->execute(array($tenantId, $primaryId));
    $keepId = (int)$q->fetchColumn();
    if ($keepId <= 0) {
        return;
    }
    $q = $pdo->prepare("UPDATE `$table` SET is_primary = 0 WHERE tenant_id = ? AND client_id = ?");
    $q->execute(array($tenantId, $primaryId));
    $q = $pdo->prepare("UPDATE `$table` SET is_primary = 1 WHERE tenant_id = ? AND client_id = ? AND id = ?");
    $q->execute(array($tenantId, $primaryId, $keepId));
}

function cmgReassignDynamic(PDO $pdo, $tenantId, $primaryId, $sourceIds)
{
    $exclude = array(
        'clients',
        'client_tag_assignments',
        'client_communication_preferences',
        'client_custom_field_values',
        'client_phone_numbers',
        'client_merge_history'
    );
    $tables = cmgLinkedTables($pdo);
    $ph = implode(',', array_fill(0, count($sourceIds), '?'));
    $updated = array();

    foreach ($tables as $table) {
        if (in_array($table, $exclude, true)) {
            continue;
        }
        $safeTable = str_replace('`', '``', $table);
        $args = array_merge(array($primaryId), $sourceIds);
        $tenantSql = '';
        if (cmgColumnExists($pdo, $table, 'tenant_id')) {
            $tenantSql = ' AND tenant_id = ?';
            $args[] = $tenantId;
        }
        try {
            $q = $pdo->prepare("UPDATE `$safeTable` SET client_id = ? WHERE client_id IN ($ph)$tenantSql");
            $q->execute($args);
            $count = $q->rowCount();
            if ($count > 0) {
                $updated[$table] = $count;
            }
        } catch (PDOException $e) {
            if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
                throw new RuntimeException('The merge found duplicate linked data in ' . $table . '. Resolve the duplicate record and try again.');
            }
            throw $e;
        }
    }
    return $updated;
}

function cmgMergeProfile(PDO $pdo, $tenantId, $primary, $sourceRows)
{
    $primaryId = (int)$primary['id'];
    $updates = array();

    $fillable = array('title_prefix', 'branch_id', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'alternate_phone', 'source', 'tax_number', 'account_manager_id');
    foreach ($fillable as $field) {
        if (!cmgColumnExists($pdo, 'clients', $field)) {
            continue;
        }
        $current = trim((string)($primary[$field] ?? ''));
        if ($current !== '' && $current !== '0') {
            continue;
        }
        foreach ($sourceRows as $source) {
            $value = trim((string)($source[$field] ?? ''));
            if ($value !== '' && $value !== '0') {
                $updates[$field] = $source[$field];
                break;
            }
        }
    }

    $hasClientType = ((string)$primary['client_type'] === 'client');
    $hasActive = ((string)$primary['status'] === 'active');
    $latestActivity = trim((string)($primary['last_activity_at'] ?? ''));
    $notes = trim((string)($primary['notes'] ?? ''));

    foreach ($sourceRows as $source) {
        if ((string)$source['client_type'] === 'client') {
            $hasClientType = true;
        }
        if ((string)$source['status'] === 'active') {
            $hasActive = true;
        }
        $activity = trim((string)($source['last_activity_at'] ?? ''));
        if ($activity !== '' && ($latestActivity === '' || strtotime($activity) > strtotime($latestActivity))) {
            $latestActivity = $activity;
        }
        $sourceNotes = trim((string)($source['notes'] ?? ''));
        if ($sourceNotes !== '' && strpos($notes, $sourceNotes) === false) {
            if ($notes !== '') {
                $notes .= "\n\n";
            }
            $notes .= '[Merged from ' . (string)$source['display_name'] . '] ' . $sourceNotes;
        }
    }

    if ($hasClientType && (string)$primary['client_type'] !== 'client') {
        $updates['client_type'] = 'client';
    }
    if ((string)$primary['status'] === 'new' && $hasActive) {
        $updates['status'] = 'active';
    }
    if ($latestActivity !== trim((string)($primary['last_activity_at'] ?? ''))) {
        $updates['last_activity_at'] = $latestActivity !== '' ? $latestActivity : null;
    }
    if ($notes !== trim((string)($primary['notes'] ?? ''))) {
        $updates['notes'] = $notes;
    }

    if (!$updates) {
        return array();
    }

    $sets = array();
    $params = array(':id' => $primaryId, ':t' => $tenantId);
    foreach ($updates as $field => $value) {
        $safeField = str_replace('`', '``', $field);
        $key = ':v_' . $field;
        $sets[] = "`$safeField` = $key";
        $params[$key] = $value;
    }
    if (cmgColumnExists($pdo, 'clients', 'updated_at')) {
        $sets[] = 'updated_at = NOW()';
    }

    $q = $pdo->prepare("UPDATE clients SET " . implode(', ', $sets) . " WHERE id = :id AND tenant_id = :t AND deleted_at IS NULL");
    $q->execute($params);
    return $updates;
}

function cmgRecordMergeHistory(PDO $pdo, $tenantId, $primaryId, $sourceRows, $userId, $summary)
{
    if (!cmgTableExists($pdo, 'client_merge_history')) {
        return;
    }
    $q = $pdo->prepare("INSERT INTO client_merge_history
        (tenant_id, primary_client_id, source_client_id, merged_by, source_snapshot, merge_summary, created_at)
        VALUES (:t, :p, :s, :u, :snapshot, :summary, NOW())");
    foreach ($sourceRows as $source) {
        $q->execute(array(
            ':t' => $tenantId,
            ':p' => $primaryId,
            ':s' => (int)$source['id'],
            ':u' => $userId,
            ':snapshot' => json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ));
    }
}

$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
$sessionBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($tenantId <= 0 || $userId <= 0) {
    cmgResponse(401, false, 'Authentication required.');
}

$csrf = (string)cmgPost('csrf_token', '');
$sessionCsrf = isset($_SESSION['client_merge_csrf_token']) ? (string)$_SESSION['client_merge_csrf_token'] : '';
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    cmgResponse(419, false, 'Your merge session expired. Refresh the page and try again.');
}

$context = cmgUserContext($pdo, $tenantId, $userId);
cmgRequirePermission($pdo, $tenantId, $userId, 'clients.view', $context);
$action = trim((string)cmgPost('action', ''));

try {
    if ($action === 'list') {
        $search = trim((string)cmgPost('search', ''));
        cmgResponse(200, true, 'Clients loaded.', array(
            'clients' => cmgSearchClients($pdo, $tenantId, $search, $context),
            'permissions' => array(
                'can_merge' => cmgHasPermission($pdo, $tenantId, $userId, 'clients.update', $context) && cmgHasPermission($pdo, $tenantId, $userId, 'clients.delete', $context)
            )
        ));
    }

    if ($action === 'preview') {
        cmgRequirePermission($pdo, $tenantId, $userId, 'clients.update', $context);
        cmgRequirePermission($pdo, $tenantId, $userId, 'clients.delete', $context);

        $ids = cmgIdsFromPost(cmgPost('client_ids', '[]'));
        $primaryId = (int)cmgPost('primary_id', 0);
        if (count($ids) < 2) {
            cmgResponse(422, false, 'Select at least two clients to merge.');
        }
        if (count($ids) > 10) {
            cmgResponse(422, false, 'You can merge up to 10 clients at one time.');
        }
        if ($primaryId <= 0 || !in_array($primaryId, $ids, true)) {
            cmgResponse(422, false, 'Choose the client profile to keep.');
        }

        $map = cmgFetchClients($pdo, $tenantId, $ids, $context, false);
        if (count($map) !== count($ids)) {
            cmgResponse(404, false, 'One or more selected clients are unavailable or outside your access scope.');
        }
        $primary = $map[$primaryId];
        $sourceRows = array();
        $sourceIds = array();
        foreach ($ids as $id) {
            if ($id === $primaryId) {
                continue;
            }
            $sourceRows[] = $map[$id];
            $sourceIds[] = $id;
        }

        cmgResponse(200, true, 'Merge preview ready.', array(
            'primary' => $primary,
            'sources' => $sourceRows,
            'profile_fill' => cmgProfileFill($primary, $sourceRows, $pdo),
            'linked_counts' => cmgPreviewCounts($pdo, $tenantId, $sourceIds)
        ));
    }

    if ($action === 'merge') {
        cmgRequirePermission($pdo, $tenantId, $userId, 'clients.update', $context);
        cmgRequirePermission($pdo, $tenantId, $userId, 'clients.delete', $context);

        $ids = cmgIdsFromPost(cmgPost('client_ids', '[]'));
        $primaryId = (int)cmgPost('primary_id', 0);
        if (count($ids) < 2) {
            cmgResponse(422, false, 'Select at least two clients to merge.');
        }
        if (count($ids) > 10) {
            cmgResponse(422, false, 'You can merge up to 10 clients at one time.');
        }
        if ($primaryId <= 0 || !in_array($primaryId, $ids, true)) {
            cmgResponse(422, false, 'Choose the client profile to keep.');
        }

        $pdo->beginTransaction();
        try {
            $map = cmgFetchClients($pdo, $tenantId, $ids, $context, true);
            if (count($map) !== count($ids)) {
                throw new RuntimeException('One or more selected clients are unavailable or outside your access scope.');
            }
            $primary = $map[$primaryId];
            $sourceRows = array();
            $sourceIds = array();
            foreach ($ids as $id) {
                if ($id === $primaryId) {
                    continue;
                }
                $sourceRows[] = $map[$id];
                $sourceIds[] = $id;
            }

            $linkedCounts = cmgPreviewCounts($pdo, $tenantId, $sourceIds);
            $profileUpdates = cmgMergeProfile($pdo, $tenantId, $primary, $sourceRows);

            cmgMergeTags($pdo, $tenantId, $userId, $primaryId, $sourceIds);
            cmgMergeCommunicationPreferences($pdo, $tenantId, $primaryId, $sourceIds);
            cmgMergeCustomFields($pdo, $tenantId, $primaryId, $sourceIds);
            $phoneMerge = cmgMergePhoneNumbers($pdo, $tenantId, $primaryId, $sourceIds);

            $reassigned = cmgReassignDynamic($pdo, $tenantId, $primaryId, $sourceIds);
            if (!empty($phoneMerge['moved']) || !empty($phoneMerge['duplicates_removed'])) {
                $reassigned['client_phone_numbers'] = (int)$phoneMerge['moved'] + (int)$phoneMerge['duplicates_removed'];
            }

            cmgNormalizePrimaryFlag($pdo, 'client_locations', $tenantId, $primaryId);
            cmgNormalizePrimaryFlag($pdo, 'client_contacts', $tenantId, $primaryId);

            $ph = implode(',', array_fill(0, count($sourceIds), '?'));
            $args = array_merge(array($tenantId), $sourceIds);
            $q = $pdo->prepare("UPDATE clients
                                SET client_type = 'archived', status = 'archived', deleted_at = NOW(), updated_at = NOW()
                                WHERE tenant_id = ? AND id IN ($ph) AND deleted_at IS NULL");
            $q->execute($args);

            $summary = array(
                'primary_client_id' => $primaryId,
                'source_client_ids' => $sourceIds,
                'profile_updates' => $profileUpdates,
                'linked_counts' => $linkedCounts,
                'reassigned_tables' => $reassigned
            );
            cmgRecordMergeHistory($pdo, $tenantId, $primaryId, $sourceRows, $userId, $summary);

            cmgAudit($pdo, $tenantId, $sessionBranchId, $userId, 'CLIENTS_MERGED', $primaryId, null, $summary);
            foreach ($sourceRows as $source) {
                cmgAudit($pdo, $tenantId, $sessionBranchId, $userId, 'CLIENT_MERGED_INTO', (int)$source['id'], $source, array('primary_client_id' => $primaryId));
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        cmgResponse(200, true, 'Clients merged successfully.', array(
            'primary_client_id' => $primaryId,
            'redirect' => 'client-view.php?client_id=' . $primaryId
        ));
    }

    cmgResponse(400, false, 'Unsupported client merge action.');
} catch (PDOException $e) {
    error_log('FieldPlx client merge PDO error: ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        cmgResponse(409, false, 'The merge found a duplicate linked record. Review the duplicate data and try again.');
    }
    cmgResponse(500, false, 'Unable to process the client merge request.');
} catch (Throwable $e) {
    error_log('FieldPlx client merge error: ' . $e->getMessage());
    cmgResponse(500, false, $e->getMessage());
}
