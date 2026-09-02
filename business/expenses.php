<?php
/* FieldPlx Expenses - Customers-style Summary UI - 2026-09-02 */
require_once __DIR__ . '/includes/auth.php';
if (file_exists(__DIR__ . '/includes/audit.php')) {
    require_once __DIR__ . '/includes/audit.php';
}

$pageTitle = 'Expenses';
$activePage = 'expenses';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$expenseTenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$expenseUserId = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : 0;
$expenseBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
$expenseCurrencySymbol = isset($_SESSION['tenant_currency_symbol']) && $_SESSION['tenant_currency_symbol'] !== ''
    ? (string)$_SESSION['tenant_currency_symbol']
    : '₹';
$expenseCurrencyCode = isset($_SESSION['tenant_currency_code']) ? (string)$_SESSION['tenant_currency_code'] : '';

if (empty($_SESSION['expenses_csrf_token'])) {
    $_SESSION['expenses_csrf_token'] = bin2hex(random_bytes(32));
}
$expensesCsrfToken = (string)$_SESSION['expenses_csrf_token'];

function ex_table_exists(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:n");
    $q->execute(array(':n' => $table));
    $cache[$table] = ((int)$q->fetchColumn() > 0);
    return $cache[$table];
}

function ex_column_exists(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = ((int)$q->fetchColumn() > 0);
    return $cache[$key];
}

function ex_json($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ex_post($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function ex_ensure_schema(PDO $pdo)
{
    if (!ex_table_exists($pdo, 'expenses')) {
        $pdo->exec("CREATE TABLE expenses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED DEFAULT NULL,
            expense_no VARCHAR(80) DEFAULT NULL,
            category_id BIGINT UNSIGNED DEFAULT NULL,
            job_id BIGINT UNSIGNED DEFAULT NULL,
            visit_id BIGINT UNSIGNED DEFAULT NULL,
            client_id BIGINT UNSIGNED DEFAULT NULL,
            expense_date DATE NOT NULL,
            vendor VARCHAR(190) DEFAULT NULL,
            amount DECIMAL(12,2) NOT NULL,
            tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            notes TEXT DEFAULT NULL,
            status ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'submitted',
            created_by BIGINT UNSIGNED DEFAULT NULL,
            approved_by BIGINT UNSIGNED DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            item_name VARCHAR(190) DEFAULT NULL,
            accounting_code VARCHAR(120) DEFAULT NULL,
            reimburse_user_id BIGINT UNSIGNED DEFAULT NULL,
            receipt_name VARCHAR(255) DEFAULT NULL,
            receipt_path VARCHAR(500) DEFAULT NULL,
            receipt_mime VARCHAR(120) DEFAULT NULL,
            receipt_size BIGINT UNSIGNED DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_expense_tenant_date (tenant_id, expense_date),
            KEY idx_expense_job (job_id),
            KEY idx_expense_reimburse_user (reimburse_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return;
    }

    $columns = array(
        'item_name' => "ALTER TABLE expenses ADD COLUMN item_name VARCHAR(190) DEFAULT NULL AFTER expense_date",
        'accounting_code' => "ALTER TABLE expenses ADD COLUMN accounting_code VARCHAR(120) DEFAULT NULL AFTER notes",
        'reimburse_user_id' => "ALTER TABLE expenses ADD COLUMN reimburse_user_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER accounting_code",
        'receipt_name' => "ALTER TABLE expenses ADD COLUMN receipt_name VARCHAR(255) DEFAULT NULL AFTER reimburse_user_id",
        'receipt_path' => "ALTER TABLE expenses ADD COLUMN receipt_path VARCHAR(500) DEFAULT NULL AFTER receipt_name",
        'receipt_mime' => "ALTER TABLE expenses ADD COLUMN receipt_mime VARCHAR(120) DEFAULT NULL AFTER receipt_path",
        'receipt_size' => "ALTER TABLE expenses ADD COLUMN receipt_size BIGINT(20) UNSIGNED DEFAULT NULL AFTER receipt_mime",
        'deleted_at' => "ALTER TABLE expenses ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER updated_at"
    );

    foreach ($columns as $column => $sql) {
        if (!ex_column_exists($pdo, 'expenses', $column)) {
            $pdo->exec($sql);
        }
    }
}

function ex_valid_job(PDO $pdo, $tenantId, $jobId)
{
    if ($jobId <= 0 || !ex_table_exists($pdo, 'jobs')) return null;
    $q = $pdo->prepare("SELECT id, branch_id, client_id, job_no, title FROM jobs WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id' => $jobId, ':t' => $tenantId));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : null;
}

function ex_valid_user(PDO $pdo, $tenantId, $userId)
{
    if ($userId <= 0 || !ex_table_exists($pdo, 'users')) return null;
    $q = $pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE id=:id AND tenant_id=:t AND status='active' AND deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id' => $userId, ':t' => $tenantId));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : null;
}

function ex_get_expense(PDO $pdo, $tenantId, $id)
{
    $q = $pdo->prepare("SELECT e.* FROM expenses e WHERE e.id=:id AND e.tenant_id=:t AND e.deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id' => $id, ':t' => $tenantId));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) ex_json(404, false, 'Expense not found.');
    return $r;
}

function ex_meta(PDO $pdo, $tenantId)
{
    $jobs = array();
    if (ex_table_exists($pdo, 'jobs')) {
        $q = $pdo->prepare("SELECT id, job_no, title, status FROM jobs WHERE tenant_id=:t AND deleted_at IS NULL AND status NOT IN('cancelled','archived') ORDER BY id DESC LIMIT 1000");
        $q->execute(array(':t' => $tenantId));
        $jobs = $q->fetchAll(PDO::FETCH_ASSOC);
    }

    $users = array();
    if (ex_table_exists($pdo, 'users')) {
        $q = $pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL ORDER BY first_name,last_name");
        $q->execute(array(':t' => $tenantId));
        $users = $q->fetchAll(PDO::FETCH_ASSOC);
    }

    $codes = array();
    $q = $pdo->prepare("SELECT DISTINCT accounting_code FROM expenses WHERE tenant_id=:t AND accounting_code IS NOT NULL AND TRIM(accounting_code)<>'' ORDER BY accounting_code");
    $q->execute(array(':t' => $tenantId));
    $codes = $q->fetchAll(PDO::FETCH_COLUMN);

    return array('jobs' => $jobs, 'users' => $users, 'accounting_codes' => $codes);
}

function ex_stats(PDO $pdo, $tenantId)
{
    $stats = array(
        'count_total' => 0,
        'amount_total' => 0.00,
        'month_total' => 0.00,
        'prior_month_total' => 0.00,
        'month_change' => 0.0,
        'job_linked' => 0,
        'reimbursable' => 0,
        'with_receipt' => 0,
        'draft_count' => 0,
        'submitted_count' => 0,
        'approved_count' => 0,
        'rejected_count' => 0
    );

    $q = $pdo->prepare("SELECT
            COUNT(*) AS count_total,
            COALESCE(SUM(amount),0) AS amount_total,
            COALESCE(SUM(CASE
                WHEN expense_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
                 AND expense_date < DATE_ADD(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 1 MONTH)
                THEN amount ELSE 0 END),0) AS month_total,
            COALESCE(SUM(CASE
                WHEN expense_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 1 MONTH)
                 AND expense_date < DATE_FORMAT(CURDATE(),'%Y-%m-01')
                THEN amount ELSE 0 END),0) AS prior_month_total,
            COALESCE(SUM(CASE WHEN job_id IS NOT NULL THEN 1 ELSE 0 END),0) AS job_linked,
            COALESCE(SUM(CASE WHEN reimburse_user_id IS NOT NULL THEN 1 ELSE 0 END),0) AS reimbursable,
            COALESCE(SUM(CASE WHEN receipt_path IS NOT NULL AND TRIM(receipt_path)<>'' THEN 1 ELSE 0 END),0) AS with_receipt,
            COALESCE(SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END),0) AS draft_count,
            COALESCE(SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END),0) AS submitted_count,
            COALESCE(SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END),0) AS approved_count,
            COALESCE(SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END),0) AS rejected_count
        FROM expenses
        WHERE tenant_id=:t
          AND deleted_at IS NULL");
    $q->execute(array(':t' => $tenantId));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row) $stats = array_merge($stats, $row);

    $current = (float)$stats['month_total'];
    $prior = (float)$stats['prior_month_total'];
    $stats['month_change'] = $prior > 0
        ? (($current - $prior) / $prior) * 100
        : ($current > 0 ? 100.0 : 0.0);

    return $stats;
}

function ex_activity(PDO $pdo, $tenantId, $branchId, $userId, $eventType, $expenseId, $title, $details)
{
    if (!ex_table_exists($pdo, 'activity_events')) return;
    try {
        $q = $pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,title,details_json,visible_to_client) VALUES(:t,:b,:u,'user',:e,'expense',:r,:title,:d,0)");
        $q->execute(array(
            ':t' => $tenantId,
            ':b' => $branchId > 0 ? $branchId : null,
            ':u' => $userId > 0 ? $userId : null,
            ':e' => $eventType,
            ':r' => $expenseId,
            ':title' => substr($title, 0, 255),
            ':d' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ));
    } catch (Throwable $e) {
        error_log('FieldPlx expense activity error: ' . $e->getMessage());
    }
}

function ex_audit(PDO $pdo, $tenantId, $branchId, $userId, $action, $expenseId, $old, $new)
{
    if (function_exists('tenantAuditLog')) {
        try {
            tenantAuditLog($pdo, $action, $tenantId, $branchId > 0 ? $branchId : null, $userId, 'expense', $expenseId, $old, $new);
        } catch (Throwable $e) {
            error_log('FieldPlx expense audit error: ' . $e->getMessage());
        }
    }
}

function ex_store_receipt($tenantId, $file)
{
    if (!isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Receipt upload failed. Please try again.');
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Invalid receipt upload.');

    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0 || $size > 10 * 1024 * 1024) throw new RuntimeException('Receipt must be 10 MB or smaller.');

    $original = isset($file['name']) ? (string)$file['name'] : 'receipt';
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowedExt = array('pdf','jpg','jpeg','png','webp');
    if (!in_array($ext, $allowedExt, true)) throw new RuntimeException('Receipt must be PDF, JPG, PNG or WEBP.');

    $mime = isset($file['type']) ? (string)$file['type'] : '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $detected = finfo_file($fi, $file['tmp_name']);
            finfo_close($fi);
            if ($detected) $mime = (string)$detected;
        }
    }
    $allowedMime = array('application/pdf','image/jpeg','image/png','image/webp');
    if ($mime !== '' && !in_array($mime, $allowedMime, true)) throw new RuntimeException('The selected receipt file type is not allowed.');

    $relativeDir = 'uploads/expenses/' . (int)$tenantId;
    $absoluteDir = __DIR__ . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Unable to create the expense receipt upload folder.');
    }

    $safeExt = $ext === 'jpeg' ? 'jpg' : $ext;
    $name = 'expense-' . date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $safeExt;
    $absolutePath = $absoluteDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) throw new RuntimeException('Unable to save the receipt file.');

    return array(
        'receipt_name' => substr($original, 0, 255),
        'receipt_path' => $relativeDir . '/' . $name,
        'receipt_mime' => $mime !== '' ? $mime : null,
        'receipt_size' => $size,
        '_absolute_path' => $absolutePath
    );
}

$expensePdo = null;
if (isset($pdo) && $pdo instanceof PDO) {
    $expensePdo = $pdo;
} elseif (isset($db) && $db instanceof PDO) {
    $expensePdo = $db;
}

$expenseSchemaError = '';
if ($expensePdo instanceof PDO && $expenseTenantId > 0) {
    try {
        ex_ensure_schema($expensePdo);
    } catch (Throwable $e) {
        $expenseSchemaError = $e->getMessage();
        error_log('FieldPlx expenses schema error: ' . $e->getMessage());
    }
}

/* Single-file AJAX/API actions. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['expense_action'])) {
    if (!($expensePdo instanceof PDO) || $expenseTenantId <= 0 || $expenseUserId <= 0) {
        ex_json(401, false, 'Authentication required.');
    }
    if ($expenseSchemaError !== '') ex_json(500, false, 'Expense database setup failed: ' . $expenseSchemaError);

    $postedCsrf = (string)ex_post('csrf_token', '');
    if ($postedCsrf === '' || !hash_equals($expensesCsrfToken, $postedCsrf)) {
        ex_json(419, false, 'Your form session expired. Refresh the page and try again.');
    }

    $action = trim((string)ex_post('expense_action', ''));

    try {
        if ($action === 'meta') {
            ex_json(200, true, 'Expense form data loaded.', array('meta' => ex_meta($expensePdo, $expenseTenantId)));
        }

        if ($action === 'list') {
            $page = max(1, (int)ex_post('page', 1));
            $perPage = (int)ex_post('per_page', 10);
            if (!in_array($perPage, array(10,25,50), true)) $perPage = 10;
            $search = trim((string)ex_post('search', ''));
            $status = trim((string)ex_post('status', ''));
            $jobId = (int)ex_post('job_id', 0);
            $dateFrom = trim((string)ex_post('date_from', ''));
            $dateTo = trim((string)ex_post('date_to', ''));

            $where = array('e.tenant_id=:t', 'e.deleted_at IS NULL');
            $params = array(':t' => $expenseTenantId);

            if ($search !== '') {
                $like = '%' . $search . '%';
                $where[] = '(e.expense_no LIKE :s1 OR e.item_name LIKE :s2 OR e.notes LIKE :s3 OR e.accounting_code LIKE :s4 OR j.job_no LIKE :s5 OR j.title LIKE :s6)';
                for ($i=1; $i<=6; $i++) $params[':s'.$i] = $like;
            }
            if (in_array($status, array('draft','submitted','approved','rejected'), true)) {
                $where[] = 'e.status=:status';
                $params[':status'] = $status;
            }
            if ($jobId > 0) {
                $where[] = 'e.job_id=:job_id';
                $params[':job_id'] = $jobId;
            }
            if ($dateFrom !== '') {
                $where[] = 'e.expense_date>=:date_from';
                $params[':date_from'] = $dateFrom;
            }
            if ($dateTo !== '') {
                $where[] = 'e.expense_date<=:date_to';
                $params[':date_to'] = $dateTo;
            }

            $ws = implode(' AND ', $where);
            $count = $expensePdo->prepare("SELECT COUNT(*) FROM expenses e LEFT JOIN jobs j ON j.id=e.job_id AND j.tenant_id=e.tenant_id WHERE $ws");
            $count->execute($params);
            $total = (int)$count->fetchColumn();
            $pages = max(1, (int)ceil($total / $perPage));
            if ($page > $pages) $page = $pages;
            $offset = ($page - 1) * $perPage;

            $sql = "SELECT e.id,e.expense_no,e.expense_date,e.item_name,e.notes,e.amount,e.tax_amount,e.accounting_code,e.reimburse_user_id,e.receipt_name,e.receipt_path,e.receipt_mime,e.receipt_size,e.status,e.job_id,e.created_at,e.updated_at,
                           j.job_no,j.title AS job_title,
                           CONCAT(COALESCE(ru.first_name,''),CASE WHEN ru.last_name IS NOT NULL AND ru.last_name<>'' THEN CONCAT(' ',ru.last_name) ELSE '' END) AS reimburse_user_name
                    FROM expenses e
                    LEFT JOIN jobs j ON j.id=e.job_id AND j.tenant_id=e.tenant_id
                    LEFT JOIN users ru ON ru.id=e.reimburse_user_id AND ru.tenant_id=e.tenant_id
                    WHERE $ws
                    ORDER BY e.expense_date DESC,e.id DESC
                    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
            $q = $expensePdo->prepare($sql);
            $q->execute($params);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);

            ex_json(200, true, 'Expenses loaded.', array(
                'expenses' => $rows,
                'meta' => ex_meta($expensePdo, $expenseTenantId),
                'stats' => ex_stats($expensePdo, $expenseTenantId),
                'pagination' => array(
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'pages' => $pages,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => $total > 0 ? min($offset + count($rows), $total) : 0
                )
            ));
        }

        if ($action === 'get') {
            $id = (int)ex_post('expense_id', 0);
            if ($id <= 0) ex_json(422, false, 'Invalid expense.');
            ex_json(200, true, 'Expense loaded.', array(
                'expense' => ex_get_expense($expensePdo, $expenseTenantId, $id),
                'meta' => ex_meta($expensePdo, $expenseTenantId)
            ));
        }

        if ($action === 'save') {
            $id = (int)ex_post('expense_id', 0);
            $date = trim((string)ex_post('expense_date', ''));
            $item = trim((string)ex_post('item_name', ''));
            $description = trim((string)ex_post('description', ''));
            $amountRaw = trim((string)ex_post('amount', ''));
            $jobId = (int)ex_post('job_id', 0);
            $accountingCode = trim((string)ex_post('accounting_code', ''));
            $reimburseUserId = (int)ex_post('reimburse_user_id', 0);
            $status = trim((string)ex_post('status', 'submitted'));

            if ($date === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) ex_json(422, false, 'Enter a valid expense date.');
            if ($item === '') ex_json(422, false, 'Item name is required.');
            if ($amountRaw === '' || !is_numeric($amountRaw) || (float)$amountRaw <= 0) ex_json(422, false, 'Total amount must be greater than zero.');
            $amount = round((float)$amountRaw, 2);
            if (strlen($accountingCode) > 120) ex_json(422, false, 'Accounting code must be 120 characters or less.');
            if (!in_array($status, array('draft','submitted','approved','rejected'), true)) $status = 'submitted';

            $job = null;
            if ($jobId > 0) {
                $job = ex_valid_job($expensePdo, $expenseTenantId, $jobId);
                if (!$job) ex_json(422, false, 'The selected job is not valid for this business.');
            }

            $reimburseUser = null;
            if ($reimburseUserId > 0) {
                $reimburseUser = ex_valid_user($expensePdo, $expenseTenantId, $reimburseUserId);
                if (!$reimburseUser) ex_json(422, false, 'The selected reimbursement user is not valid.');
            }

            $old = $id > 0 ? ex_get_expense($expensePdo, $expenseTenantId, $id) : null;
            $uploaded = null;
            if (isset($_FILES['receipt'])) {
                $uploaded = ex_store_receipt($expenseTenantId, $_FILES['receipt']);
            }

            $branchId = $job && !empty($job['branch_id']) ? (int)$job['branch_id'] : ($old && !empty($old['branch_id']) ? (int)$old['branch_id'] : $expenseBranchId);
            $clientId = $job && !empty($job['client_id']) ? (int)$job['client_id'] : ($old && !empty($old['client_id']) ? (int)$old['client_id'] : null);

            $expensePdo->beginTransaction();
            try {
                if ($id > 0) {
                    $sql = "UPDATE expenses SET branch_id=:branch,job_id=:job,client_id=:client,expense_date=:expense_date,item_name=:item,amount=:amount,tax_amount=0,notes=:notes,accounting_code=:code,reimburse_user_id=:reimburse,status=:status";
                    $params = array(
                        ':branch' => $branchId > 0 ? $branchId : null,
                        ':job' => $job ? (int)$job['id'] : null,
                        ':client' => $clientId,
                        ':expense_date' => $date,
                        ':item' => $item,
                        ':amount' => $amount,
                        ':notes' => $description !== '' ? $description : null,
                        ':code' => $accountingCode !== '' ? $accountingCode : null,
                        ':reimburse' => $reimburseUser ? (int)$reimburseUser['id'] : null,
                        ':status' => $status,
                        ':id' => $id,
                        ':t' => $expenseTenantId
                    );
                    if ($uploaded) {
                        $sql .= ",receipt_name=:receipt_name,receipt_path=:receipt_path,receipt_mime=:receipt_mime,receipt_size=:receipt_size";
                        $params[':receipt_name'] = $uploaded['receipt_name'];
                        $params[':receipt_path'] = $uploaded['receipt_path'];
                        $params[':receipt_mime'] = $uploaded['receipt_mime'];
                        $params[':receipt_size'] = $uploaded['receipt_size'];
                    }
                    $sql .= " WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL";
                    $q = $expensePdo->prepare($sql);
                    $q->execute($params);
                } else {
                    $q = $expensePdo->prepare("INSERT INTO expenses(tenant_id,branch_id,expense_no,category_id,job_id,visit_id,client_id,expense_date,item_name,vendor,amount,tax_amount,notes,accounting_code,reimburse_user_id,receipt_name,receipt_path,receipt_mime,receipt_size,status,created_by) VALUES(:t,:branch,NULL,NULL,:job,NULL,:client,:expense_date,:item,NULL,:amount,0,:notes,:code,:reimburse,:receipt_name,:receipt_path,:receipt_mime,:receipt_size,:status,:created_by)");
                    $q->execute(array(
                        ':t' => $expenseTenantId,
                        ':branch' => $branchId > 0 ? $branchId : null,
                        ':job' => $job ? (int)$job['id'] : null,
                        ':client' => $clientId,
                        ':expense_date' => $date,
                        ':item' => $item,
                        ':amount' => $amount,
                        ':notes' => $description !== '' ? $description : null,
                        ':code' => $accountingCode !== '' ? $accountingCode : null,
                        ':reimburse' => $reimburseUser ? (int)$reimburseUser['id'] : null,
                        ':receipt_name' => $uploaded ? $uploaded['receipt_name'] : null,
                        ':receipt_path' => $uploaded ? $uploaded['receipt_path'] : null,
                        ':receipt_mime' => $uploaded ? $uploaded['receipt_mime'] : null,
                        ':receipt_size' => $uploaded ? $uploaded['receipt_size'] : null,
                        ':status' => $status,
                        ':created_by' => $expenseUserId
                    ));
                    $id = (int)$expensePdo->lastInsertId();
                    $expenseNo = 'EXP-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
                    $u = $expensePdo->prepare("UPDATE expenses SET expense_no=:no WHERE id=:id AND tenant_id=:t");
                    $u->execute(array(':no' => $expenseNo, ':id' => $id, ':t' => $expenseTenantId));
                }
                $expensePdo->commit();
            } catch (Throwable $e) {
                if ($expensePdo->inTransaction()) $expensePdo->rollBack();
                if ($uploaded && isset($uploaded['_absolute_path']) && is_file($uploaded['_absolute_path'])) @unlink($uploaded['_absolute_path']);
                throw $e;
            }

            if ($uploaded && $old && !empty($old['receipt_path'])) {
                $oldAbs = __DIR__ . '/' . ltrim((string)$old['receipt_path'], '/');
                if (is_file($oldAbs)) @unlink($oldAbs);
            }

            $new = ex_get_expense($expensePdo, $expenseTenantId, $id);
            ex_activity($expensePdo, $expenseTenantId, $branchId, $expenseUserId, $old ? 'expense_updated' : 'expense_created', $id, ($old ? 'Expense updated: ' : 'Expense created: ') . $item, array('expense' => $new));
            ex_audit($expensePdo, $expenseTenantId, $branchId, $expenseUserId, $old ? 'EXPENSE_UPDATED' : 'EXPENSE_CREATED', $id, $old, $new);

            ex_json(200, true, $old ? 'Expense updated successfully.' : 'Expense created successfully.', array('expense_id' => $id));
        }

        if ($action === 'delete') {
            $id = (int)ex_post('expense_id', 0);
            if ($id <= 0) ex_json(422, false, 'Invalid expense.');
            $old = ex_get_expense($expensePdo, $expenseTenantId, $id);
            $q = $expensePdo->prepare("UPDATE expenses SET deleted_at=NOW() WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL");
            $q->execute(array(':id' => $id, ':t' => $expenseTenantId));
            ex_activity($expensePdo, $expenseTenantId, !empty($old['branch_id']) ? (int)$old['branch_id'] : $expenseBranchId, $expenseUserId, 'expense_deleted', $id, 'Expense deleted: ' . (string)$old['item_name'], $old);
            ex_audit($expensePdo, $expenseTenantId, !empty($old['branch_id']) ? (int)$old['branch_id'] : $expenseBranchId, $expenseUserId, 'EXPENSE_DELETED', $id, $old, array('deleted_at' => date('Y-m-d H:i:s')));
            ex_json(200, true, 'Expense deleted successfully.');
        }

        ex_json(400, false, 'Unsupported expense action.');
    } catch (PDOException $e) {
        error_log('FieldPlx expenses PDO error: ' . $e->getMessage());
        ex_json(500, false, 'Unable to process the expense request.');
    } catch (Throwable $e) {
        error_log('FieldPlx expenses error: ' . $e->getMessage());
        ex_json(500, false, $e->getMessage());
    }
}

$expenseStats = array(
    'count_total'=>0,
    'amount_total'=>0.00,
    'month_total'=>0.00,
    'prior_month_total'=>0.00,
    'month_change'=>0.0,
    'job_linked'=>0,
    'reimbursable'=>0,
    'with_receipt'=>0,
    'draft_count'=>0,
    'submitted_count'=>0,
    'approved_count'=>0,
    'rejected_count'=>0
);
if ($expensePdo instanceof PDO && $expenseTenantId > 0 && $expenseSchemaError === '') {
    try {
        $expenseStats = ex_stats($expensePdo, $expenseTenantId);
    } catch (Throwable $e) {
        error_log('FieldPlx expenses stats error: ' . $e->getMessage());
    }
}

$expenseMonthChange = (float)$expenseStats['month_change'];
$expenseMonthTrendClass = $expenseMonthChange > 0 ? '' : ($expenseMonthChange < 0 ? ' down' : ' neutral');
$expenseMonthTrendArrow = $expenseMonthChange > 0 ? '↑ ' : ($expenseMonthChange < 0 ? '↓ ' : '');
$expenseCurrentMonthLabel = date('M Y');
$expensePriorMonthLabel = date('M Y', strtotime('first day of last month'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>Expenses - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet" />
    <style>
    :root {
        --fieldplx-primary: #6d28d9;
        --fieldplx-primary-dark: #5b21b6;
        --fieldplx-text: #1f2937;
        --fieldplx-muted: #6b7280;
        --fieldplx-border: #e5e7eb;
        --fieldplx-surface: #ffffff;
        --fieldplx-background: #f7f7fb;
        --fieldplx-topbar-height: 64px;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        min-height: 100vh;
        overflow-x: hidden;
        background: var(--fieldplx-background);
        color: var(--fieldplx-text);
        font-family: "Inter", sans-serif;
        font-size: 13px;
    }

    .fieldplx-topbar {
        position: sticky;
        top: 0;
        z-index: 1030;
        min-height: var(--fieldplx-topbar-height);
        background: rgba(255, 255, 255, 0.96);
        border-bottom: 1px solid var(--fieldplx-border);
        backdrop-filter: blur(12px);
    }

    .fieldplx-topbar-inner {
        min-height: var(--fieldplx-topbar-height);
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 8px 18px;
    }

    .fieldplx-brand-mobile {
        display: none;
        align-items: center;
        gap: 9px;
        min-width: 0;
        text-decoration: none;
        color: var(--fieldplx-text);
    }

    .fieldplx-brand-logo {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        border-radius: 9px;
        object-fit: contain;
        background: #f3f0ff;
    }

    .fieldplx-brand-placeholder {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        color: #ffffff;
        background: linear-gradient(135deg, #7c3aed, #5b21b6);
        font-size: 15px;
        font-weight: 700;
    }

    .fieldplx-brand-name {
        max-width: 170px;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        font-size: 14px;
        font-weight: 700;
    }

    .fieldplx-menu-toggle {
        width: 36px;
        height: 36px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--fieldplx-border);
        border-radius: 9px;
        background: #ffffff;
        color: #4b5563;
        font-size: 19px;
    }

    .fieldplx-menu-toggle:hover {
        color: var(--fieldplx-primary);
        border-color: #d8ccfb;
        background: #faf8ff;
    }

    .fieldplx-page-heading {
        min-width: 0;
        margin-right: auto;
    }

    .fieldplx-page-title {
        margin: 0;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        color: #111827;
        font-size: 15px;
        font-weight: 700;
    }

    .fieldplx-page-subtitle {
        margin-top: 2px;
        color: var(--fieldplx-muted);
        font-size: 11px;
    }

    .fieldplx-search-wrap {
        width: min(340px, 31vw);
        position: relative;
    }

    .fieldplx-search-icon {
        position: absolute;
        top: 50%;
        left: 12px;
        z-index: 2;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 14px;
        pointer-events: none;
    }

    .fieldplx-search-input {
        height: 38px;
        padding: 8px 13px 8px 35px;
        border: 1px solid var(--fieldplx-border);
        border-radius: 10px;
        background: #f9fafb;
        box-shadow: none;
        font-size: 12px;
    }

    .fieldplx-search-input:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.09);
    }

    .fieldplx-topbar-action {
        width: 38px;
        height: 38px;
        padding: 0;
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--fieldplx-border);
        border-radius: 10px;
        background: #ffffff;
        color: #4b5563;
        font-size: 17px;
    }

    .fieldplx-topbar-action:hover {
        color: var(--fieldplx-primary);
        border-color: #d8ccfb;
        background: #faf8ff;
    }

    .fieldplx-notification-count {
        position: absolute;
        top: -5px;
        right: -5px;
        min-width: 18px;
        height: 18px;
        padding: 0 5px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 2px solid #ffffff;
        border-radius: 999px;
        background: #dc2626;
        color: #ffffff;
        font-size: 9px;
        font-weight: 700;
    }

    .fieldplx-profile-button {
        min-width: 0;
        padding: 4px 8px 4px 5px;
        display: flex;
        align-items: center;
        gap: 9px;
        border: 1px solid var(--fieldplx-border);
        border-radius: 11px;
        background: #ffffff;
        text-align: left;
    }

    .fieldplx-profile-button:hover {
        border-color: #d8ccfb;
        background: #faf8ff;
    }

    .fieldplx-avatar {
        width: 32px;
        height: 32px;
        flex: 0 0 32px;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: linear-gradient(135deg, #7c3aed, #5b21b6);
        color: #ffffff;
        font-size: 11px;
        font-weight: 700;
    }

    .fieldplx-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .fieldplx-profile-details {
        max-width: 145px;
        min-width: 0;
    }

    .fieldplx-profile-name,
    .fieldplx-profile-role {
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .fieldplx-profile-name {
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .fieldplx-profile-role {
        margin-top: 1px;
        color: var(--fieldplx-muted);
        font-size: 9px;
    }

    .fieldplx-dropdown {
        width: 340px;
        max-width: calc(100vw - 24px);
        padding: 0;
        margin-top: 10px !important;
        overflow: hidden;
        border: 1px solid var(--fieldplx-border);
        border-radius: 14px;
        background: #ffffff;
        box-shadow: 0 14px 34px rgba(31, 41, 55, 0.12);
    }

    .fieldplx-dropdown-header {
        min-height: 48px;
        padding: 11px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid var(--fieldplx-border);
        background: #ffffff;
    }

    .fieldplx-dropdown-title {
        margin: 0;
        color: #111827;
        font-size: 14px;
        line-height: 1.2;
        font-weight: 700;
    }

    .fieldplx-notification-item {
        padding: 11px 14px;
        display: flex;
        gap: 10px;
        border-bottom: 1px solid #f1f2f4;
        color: inherit;
        text-decoration: none;
    }

    .fieldplx-notification-item:hover {
        background: #faf8ff;
    }

    .fieldplx-notification-item.is-unread {
        background: #fbf9ff;
    }

    .fieldplx-notification-icon {
        width: 32px;
        height: 32px;
        flex: 0 0 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 14px;
    }

    .fieldplx-notification-content {
        min-width: 0;
    }

    .fieldplx-notification-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .fieldplx-notification-message {
        margin-top: 3px;
        overflow: hidden;
        display: -webkit-box;
        color: var(--fieldplx-muted);
        font-size: 10px;
        line-height: 1.45;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }

    .fieldplx-notification-time {
        margin-top: 4px;
        color: #9ca3af;
        font-size: 9px;
    }

    .fieldplx-empty-notifications {
        min-height: 155px;
        padding: 28px 18px 24px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: #718096;
        background: #ffffff;
        font-size: 13px;
        line-height: 1.45;
    }

    .fieldplx-empty-notifications i {
        display: block;
        margin-bottom: 10px;
        color: #b9a8ff;
        font-size: 30px;
        line-height: 1;
    }

    .fieldplx-dropdown-footer {
        min-height: 44px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-top: 1px solid var(--fieldplx-border);
        text-align: center;
        background: #ffffff;
    }

    .fieldplx-dropdown-footer a {
        color: var(--fieldplx-primary);
        font-size: 11px;
        font-weight: 700;
        text-decoration: none;
    }

    .fieldplx-dropdown-footer a:hover {
        text-decoration: underline;
    }

    .fieldplx-profile-menu {
        width: 230px;
        padding: 7px;
        border: 1px solid var(--fieldplx-border);
        border-radius: 12px;
        box-shadow: 0 18px 50px rgba(31, 41, 55, 0.13);
    }

    .fieldplx-profile-menu-header {
        padding: 9px 10px 11px;
        border-bottom: 1px solid #f0f1f3;
    }

    .fieldplx-profile-menu-name {
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        color: #111827;
        font-size: 12px;
        font-weight: 700;
    }

    .fieldplx-profile-menu-email {
        margin-top: 2px;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        color: var(--fieldplx-muted);
        font-size: 10px;
    }

    .fieldplx-profile-menu .dropdown-item {
        padding: 9px 10px;
        display: flex;
        align-items: center;
        gap: 9px;
        border-radius: 8px;
        color: #374151;
        font-size: 11px;
    }

    .fieldplx-profile-menu .dropdown-item:hover {
        color: var(--fieldplx-primary);
        background: #faf8ff;
    }

    .fieldplx-profile-menu .dropdown-item.text-danger:hover {
        color: #b91c1c !important;
        background: #fff5f5;
    }

    .fieldplx-main-layout {
        display: flex;
        min-height: calc(100vh - var(--fieldplx-topbar-height));
    }

    .fieldplx-main-content {
        min-width: 0;
        flex: 1;
    }

    .fieldplx-content-wrapper {
        padding: 18px;
    }

    @media (max-width: 991.98px) {
        .fieldplx-brand-mobile {
            display: flex;
        }

        .fieldplx-page-heading {
            display: none;
        }

        .fieldplx-search-wrap {
            margin-left: auto;
            width: min(280px, 40vw);
        }

        .fieldplx-profile-details {
            display: none;
        }

        .fieldplx-profile-button {
            padding-right: 5px;
        }
    }

    @media (max-width: 767.98px) {
        .fieldplx-topbar-inner {
            gap: 8px;
            padding: 8px 11px;
        }

        .fieldplx-brand-name {
            display: none;
        }

        .fieldplx-search-wrap {
            display: none;
        }

        .fieldplx-topbar-spacer {
            margin-left: auto;
        }

        .fieldplx-dropdown {
            width: min(330px, calc(100vw - 22px));
        }

        .fieldplx-content-wrapper {
            padding: 12px;
        }
    }

    :root {
        --fieldplx-sidebar-width: 246px;
        --fieldplx-sidebar-collapsed-width: 72px;
    }

    .fieldplx-sidebar {
        width: var(--fieldplx-sidebar-width);
        min-width: var(--fieldplx-sidebar-width);
        height: calc(100vh - var(--fieldplx-topbar-height));
        position: sticky;
        top: var(--fieldplx-topbar-height);
        z-index: 1020;
        display: flex;
        flex-direction: column;
        background: #ffffff;
        border-right: 1px solid var(--fieldplx-border);
        transition:
            width 0.22s ease,
            min-width 0.22s ease,
            transform 0.22s ease;
    }

    .fieldplx-sidebar-header {
        min-height: 64px;
        padding: 10px 13px;
        display: flex;
        align-items: center;
        border-bottom: 1px solid #f0f1f3;
    }

    .fieldplx-sidebar-brand {
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        color: #111827;
        text-decoration: none;
    }

    .fieldplx-sidebar-logo,
    .fieldplx-sidebar-logo-placeholder {
        width: 38px;
        height: 38px;
        flex: 0 0 38px;
        border-radius: 10px;
    }

    .fieldplx-sidebar-logo {
        object-fit: contain;
        background: #f7f4ff;
    }

    .fieldplx-sidebar-logo-placeholder {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #7c3aed, #5b21b6);
        color: #ffffff;
        font-size: 16px;
        font-weight: 700;
    }

    .fieldplx-sidebar-brand-text {
        min-width: 0;
        display: block;
    }

    .fieldplx-sidebar-company-name {
        max-width: 160px;
        display: block;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        font-size: 12px;
        font-weight: 700;
    }

    .fieldplx-sidebar-product-name {
        margin-top: 1px;
        display: block;
        color: #8b5cf6;
        font-size: 9px;
        font-weight: 600;
        letter-spacing: 0.4px;
        text-transform: uppercase;
    }

    .fieldplx-sidebar-close {
        width: 32px;
        height: 32px;
        margin-left: auto;
        padding: 0;
        display: none;
        align-items: center;
        justify-content: center;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: #6b7280;
        font-size: 16px;
    }

    .fieldplx-sidebar-body {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 12px 9px;
        scrollbar-width: thin;
        scrollbar-color: #d8d4e5 transparent;
    }

    .fieldplx-sidebar-section-label {
        margin: 4px 10px 7px;
        color: #9ca3af;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 0.65px;
        text-transform: uppercase;
    }

    .fieldplx-sidebar-nav {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .fieldplx-sidebar-link {
        width: 100%;
        min-height: 39px;
        padding: 8px 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        border: 0;
        border-radius: 9px;
        background: transparent;
        color: #4b5563;
        text-align: left;
        text-decoration: none;
        font-family: inherit;
        font-size: 11px;
        font-weight: 500;
        transition:
            color 0.16s ease,
            background 0.16s ease;
    }

    .fieldplx-sidebar-link:hover {
        background: #f8f6ff;
        color: #6d28d9;
    }

    .fieldplx-sidebar-link.active {
        background: #f0ebff;
        color: #6d28d9;
        font-weight: 700;
    }

    .fieldplx-sidebar-link-icon {
        width: 20px;
        height: 20px;
        flex: 0 0 20px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
    }

    .fieldplx-sidebar-link-text {
        min-width: 0;
        flex: 1;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .fieldplx-sidebar-arrow {
        margin-left: auto;
        color: #9ca3af;
        font-size: 10px;
        transition: transform 0.2s ease;
    }

    .fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-arrow {
        transform: rotate(180deg);
    }

    .fieldplx-sidebar-submenu {
        max-height: 0;
        overflow: hidden;
        padding-left: 39px;
        transition: max-height 0.25s ease;
    }

    .fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-submenu {
        max-height: 520px;
        padding-top: 3px;
        padding-bottom: 3px;
    }

    .fieldplx-sidebar-sublink {
        min-height: 31px;
        padding: 7px 9px;
        position: relative;
        display: flex;
        align-items: center;
        border-radius: 7px;
        color: #6b7280;
        text-decoration: none;
        font-size: 10px;
        font-weight: 500;
    }

    .fieldplx-sidebar-sublink::before {
        width: 5px;
        height: 5px;
        margin-right: 9px;
        flex: 0 0 5px;
        content: "";
        border-radius: 50%;
        background: #d1d5db;
    }

    .fieldplx-sidebar-sublink:hover {
        background: #faf8ff;
        color: #6d28d9;
    }

    .fieldplx-sidebar-sublink.active {
        background: #f7f3ff;
        color: #6d28d9;
        font-weight: 700;
    }

    .fieldplx-sidebar-sublink.active::before {
        background: #7c3aed;
    }

    .fieldplx-sidebar-empty {
        margin: 8px 10px 14px;
        padding: 14px 12px;
        display: grid;
        justify-items: center;
        gap: 7px;
        border: 1px dashed #ddd6fe;
        border-radius: 10px;
        background: #faf8ff;
        color: #7c3aed;
        font-size: 9px;
        line-height: 1.5;
        text-align: center;
    }

    .fieldplx-sidebar-empty i {
        font-size: 17px;
    }

    .fieldplx-sidebar-footer {
        padding: 10px;
        border-top: 1px solid #f0f1f3;
    }

    .fieldplx-sidebar-user {
        padding: 8px;
        display: flex;
        align-items: center;
        gap: 9px;
        border-radius: 10px;
        background: #fafafa;
    }

    .fieldplx-sidebar-user-avatar {
        width: 31px;
        height: 31px;
        flex: 0 0 31px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: linear-gradient(135deg, #7c3aed, #5b21b6);
        color: #ffffff;
        font-size: 11px;
        font-weight: 700;
    }

    .fieldplx-sidebar-user-details {
        min-width: 0;
        flex: 1;
    }

    .fieldplx-sidebar-user-name,
    .fieldplx-sidebar-user-role {
        display: block;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .fieldplx-sidebar-user-name {
        color: #111827;
        font-size: 10px;
        font-weight: 700;
    }

    .fieldplx-sidebar-user-role {
        margin-top: 1px;
        color: #9ca3af;
        font-size: 8px;
    }

    .fieldplx-sidebar-logout {
        width: 29px;
        height: 29px;
        flex: 0 0 29px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        color: #9ca3af;
        text-decoration: none;
        font-size: 14px;
    }

    .fieldplx-sidebar-logout:hover {
        background: #fee2e2;
        color: #dc2626;
    }

    .fieldplx-sidebar-overlay {
        display: none;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
        width: var(--fieldplx-sidebar-collapsed-width);
        min-width: var(--fieldplx-sidebar-collapsed-width);
    }

    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout {
        display: none;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header {
        justify-content: center;
        padding-left: 8px;
        padding-right: 8px;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link {
        justify-content: center;
        padding-left: 8px;
        padding-right: 8px;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user {
        justify-content: center;
        padding-left: 5px;
        padding-right: 5px;
    }

    @media (max-width: 991.98px) {
        .fieldplx-sidebar {
            width: 260px;
            min-width: 260px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1050;
            transform: translateX(-100%);
            box-shadow: none;
        }

        body.fieldplx-sidebar-mobile-open .fieldplx-sidebar {
            transform: translateX(0);
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
            width: 260px;
            min-width: 260px;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout {
            display: block;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu {
            display: block;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user {
            justify-content: initial;
        }

        .fieldplx-sidebar-close {
            display: inline-flex;
        }

        .fieldplx-sidebar-overlay {
            position: fixed;
            inset: 0;
            z-index: 1040;
            display: block;
            visibility: hidden;
            background: rgba(17, 24, 39, 0.42);
            opacity: 0;
            transition:
                opacity 0.2s ease,
                visibility 0.2s ease;
        }

        body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay {
            visibility: visible;
            opacity: 1;
        }
    }

    .fieldplx-fallback-sidebar {
        width: 236px;
        min-width: 236px;
        height: calc(100vh - var(--fieldplx-topbar-height));
        position: sticky;
        top: var(--fieldplx-topbar-height);
        z-index: 1020;
        display: flex;
        flex-direction: column;
        border-right: 1px solid var(--fieldplx-border);
        background: #ffffff;
    }

    .fieldplx-fallback-brand {
        min-height: 62px;
        padding: 11px 13px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #f1f5f9;
    }

    .fieldplx-fallback-logo {
        width: 37px;
        height: 37px;
        flex: 0 0 37px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: linear-gradient(135deg, #7c3aed, #5b21b6);
        color: #ffffff;
        font-size: 14px;
        font-weight: 700;
    }

    .fieldplx-fallback-brand-text {
        min-width: 0;
    }

    .fieldplx-fallback-brand-text strong,
    .fieldplx-fallback-brand-text small {
        display: block;
    }

    .fieldplx-fallback-brand-text strong {
        max-width: 155px;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        color: #111827;
        font-size: 11px;
    }

    .fieldplx-fallback-brand-text small {
        margin-top: 2px;
        color: #8b5cf6;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 0.4px;
        text-transform: uppercase;
    }

    .fieldplx-fallback-nav {
        flex: 1;
        overflow-y: auto;
        padding: 10px 8px;
    }

    .fieldplx-fallback-nav a,
    .fieldplx-fallback-footer a {
        min-height: 38px;
        padding: 8px 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-radius: 9px;
        color: #4b5563;
        font-size: 10px;
        font-weight: 600;
        text-decoration: none;
    }

    .fieldplx-fallback-nav a:hover,
    .fieldplx-fallback-nav a.active {
        background: #f0ebff;
        color: #6d28d9;
    }

    .fieldplx-fallback-nav i,
    .fieldplx-fallback-footer i {
        width: 19px;
        flex: 0 0 19px;
        font-size: 14px;
        text-align: center;
    }

    .fieldplx-fallback-footer {
        padding: 10px;
        border-top: 1px solid #f1f5f9;
    }

    .fieldplx-fallback-footer a:hover {
        background: #fef2f2;
        color: #dc2626;
    }

    @media (max-width: 991.98px) {
        .fieldplx-fallback-sidebar {
            display: none;
        }
    }

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
        --fd-navy: #001131;
        --fd-navy-light: #071f49;
        --fd-blue: #123d70;
        --fd-green: #74b824;
        --fd-green-dark: #5d971b;
        --fd-green-soft: #f0f8e5;
        --fd-orange: #96c945;
        --fd-red: #e45b66;
        --fd-bg: #f6f8fb;
        --fd-text: #0b1933;
        --fd-muted: #6f7b90;
        --fd-border: #e5eaf1;
    }

    body {
        background: var(--fd-bg) !important;
        color: var(--fd-text);
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 14px;
    }

    .fieldplx-topbar {
        min-height: 70px !important;
        margin-left: var(--fieldplx-sidebar-width);
        width: calc(100% - var(--fieldplx-sidebar-width));
        background: #fff !important;
        border-bottom: 1px solid var(--fd-border) !important;
        box-shadow: 0 3px 14px rgba(0, 17, 49, 0.035);
        backdrop-filter: none !important;
        transition:
            margin-left 0.25s ease,
            width 0.25s ease;
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

    .fieldplx-page-heading {
        display: none !important;
    }

    .fieldplx-menu-toggle,
    .fieldplx-topbar-action {
        width: 41px !important;
        height: 41px !important;
        border: 0 !important;
        border-radius: 9px !important;
        color: var(--fd-navy) !important;
        background: transparent !important;
    }

    .fieldplx-menu-toggle:hover,
    .fieldplx-topbar-action:hover {
        color: var(--fd-navy) !important;
        background: var(--fd-green-soft) !important;
    }

    .fieldplx-search-wrap {
        width: 280px !important;
        margin-left: auto;
    }

    .fieldplx-search-input {
        height: 41px !important;
        padding-left: 38px !important;
        border: 0 !important;
        border-radius: 8px !important;
        background: #f5f8fb !important;
        color: var(--fd-text) !important;
        font-size: 12px !important;
    }

    .fieldplx-search-input:focus {
        background: #f5f8fb !important;
        box-shadow: 0 0 0 3px rgba(116, 184, 36, 0.14) !important;
    }

    .fieldplx-profile-button {
        padding: 2px !important;
        border: 0 !important;
        border-radius: 9px !important;
        background: transparent !important;
    }

    .fieldplx-profile-button:hover {
        background: var(--fd-green-soft) !important;
    }

    .fieldplx-avatar {
        width: 38px !important;
        height: 38px !important;
        flex: 0 0 38px !important;
        border-radius: 50% !important;
        border: 0 !important;
        color: var(--fd-navy) !important;
        background: linear-gradient(135deg, #fff, #e8f3d9) !important;
        font-size: 12px !important;
        font-weight: 800 !important;
    }

    .fieldplx-profile-name {
        font-size: 12px !important;
    }

    .fieldplx-profile-role {
        color: var(--fd-muted) !important;
        font-size: 10px !important;
    }

    .fieldplx-notification-count {
        background: var(--fd-red) !important;
    }

    .fieldplx-dropdown,
    .fieldplx-profile-menu {
        border-color: var(--fd-border) !important;
        box-shadow: 0 18px 45px rgba(29, 38, 74, 0.14) !important;
    }

    .fieldplx-dropdown-footer a,
    .fieldplx-profile-menu .dropdown-item:hover {
        color: var(--fd-green-dark) !important;
    }

    .fieldplx-sidebar {
        width: var(--fieldplx-sidebar-width) !important;
        min-width: var(--fieldplx-sidebar-width) !important;
        height: 100vh !important;
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        z-index: 1045 !important;
        color: #fff !important;
        background: linear-gradient(180deg,
                var(--fd-navy-light),
                var(--fd-navy)) !important;

        border-right: 0 !important;
        transition:
            width 0.25s ease,
            min-width 0.25s ease,
            transform 0.25s ease !important;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
        width: var(--fieldplx-sidebar-collapsed-width) !important;
        min-width: var(--fieldplx-sidebar-collapsed-width) !important;
    }

    .fieldplx-sidebar-header {
        min-height: 68px !important;
        padding: 9px 14px 10px !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
    }

    .fieldplx-sidebar-brand {
        color: #fff !important;
    }

    .fieldplx-sidebar-logo,
    .fieldplx-sidebar-logo-placeholder {
        width: 40px !important;
        height: 40px !important;
        flex: 0 0 40px !important;
        border-radius: 10px !important;
    }

    .fieldplx-sidebar-logo-placeholder {
        color: #fff !important;
        background: linear-gradient(135deg, #8fd236, #68aa1d) !important;
        font-size: 18px !important;
    }

    .fieldplx-sidebar-company-name {
        max-width: 155px !important;
        color: #fff !important;
        font-size: 16px !important;
        font-weight: 700 !important;
    }

    .fieldplx-sidebar-product-name {
        color: #9fda55 !important;
        font-size: 9px !important;
    }

    .fieldplx-sidebar-body {
        padding: 12px 14px !important;
        scrollbar-width: none !important;
    }

    .fieldplx-sidebar-body::-webkit-scrollbar {
        display: none;
    }

    .fieldplx-sidebar-section-label {
        margin: 7px 12px 7px !important;
        color: rgba(255, 255, 255, 0.5) !important;
        font-size: 9px !important;
    }

    .fieldplx-sidebar-nav {
        gap: 3px !important;
    }

    .fieldplx-sidebar-link {
        min-height: 46px !important;
        margin-bottom: 3px !important;
        padding: 0 14px !important;
        gap: 15px !important;
        border-radius: 9px !important;
        color: rgba(255, 255, 255, 0.94) !important;
        font-size: 14px !important;
        font-weight: 600 !important;
    }

    .fieldplx-sidebar-link:hover {
        color: #fff !important;
        background: rgba(255, 255, 255, 0.08) !important;
    }

    .fieldplx-sidebar-link.active,
    .fieldplx-sidebar-menu.menu-open>.fieldplx-sidebar-link {
        color: #fff !important;
        background: linear-gradient(90deg, #7fc92d, #68aa1d) !important;
        box-shadow: 0 6px 18px rgba(0, 17, 49, 0.28) !important;
    }

    .fieldplx-sidebar-link-icon {
        width: 21px !important;
        height: 21px !important;
        flex: 0 0 21px !important;
        font-size: 19px !important;
    }

    .fieldplx-sidebar-arrow {
        color: rgba(255, 255, 255, 0.65) !important;
    }

    .fieldplx-sidebar-submenu {
        padding-left: 36px !important;
    }

    .fieldplx-sidebar-sublink {
        min-height: 34px !important;
        color: rgba(255, 255, 255, 0.72) !important;
        font-size: 11px !important;
    }

    .fieldplx-sidebar-sublink::before {
        background: rgba(255, 255, 255, 0.35) !important;
    }

    .fieldplx-sidebar-sublink:hover,
    .fieldplx-sidebar-sublink.active {
        color: #fff !important;
        background: rgba(255, 255, 255, 0.08) !important;
    }

    .fieldplx-sidebar-sublink.active::before {
        background: #9fda55 !important;
    }

    .fieldplx-sidebar-footer {
        padding: 10px 14px 14px !important;
        border-top: 1px solid rgba(255, 255, 255, 0.08) !important;
    }

    .fieldplx-sidebar-user {
        min-height: 62px;
        background: rgba(255, 255, 255, 0.08) !important;
    }

    .fieldplx-sidebar-user-name {
        color: #fff !important;
        font-size: 12px !important;
    }

    .fieldplx-sidebar-user-role {
        color: rgba(255, 255, 255, 0.6) !important;
        font-size: 9px !important;
    }

    .fieldplx-sidebar-user-avatar {
        width: 38px !important;
        height: 38px !important;
        flex: 0 0 38px !important;
        border-radius: 50% !important;
        color: var(--fd-navy) !important;
        background: linear-gradient(135deg, #fff, #e8f3d9) !important;
    }

    .fieldplx-sidebar-logout {
        color: rgba(255, 255, 255, 0.7) !important;
    }

    .fieldplx-sidebar-logout:hover {
        color: #fff !important;
        background: rgba(228, 91, 102, 0.3) !important;
    }

    .fieldplx-main-layout {
        display: block !important;
        min-height: calc(100vh - 70px) !important;
    }

    .fieldplx-main-content {
        margin-left: var(--fieldplx-sidebar-width);
        min-width: 0;
        transition: margin-left 0.25s ease;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-main-content {
        margin-left: var(--fieldplx-sidebar-collapsed-width);
    }

    .fieldplx-content-wrapper {
        padding: 0 !important;
    }

    .fieldplx-footer {
        display: block !important;
    }

    .fd-dashboard {
        width: 100%;
        max-width: 1600px;
        margin: auto;
        padding: 25px 27px 35px;
    }

    .fd-dashboard .row>* {
        min-width: 0;
    }

    .fd-welcome {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 23px;
    }

    .fd-welcome h1 {
        margin: 0 0 8px;
        color: var(--fd-text);
        font-size: 21px;
        font-weight: 700;
    }

    .fd-welcome p {
        margin: 0;
        color: var(--fd-muted);
        font-size: 12px;
    }

    .fd-date-actions {
        display: flex;
        gap: 9px;
    }

    .fd-date-button,
    .fd-filter-button {
        height: 46px;
        border: 1px solid var(--fd-border);
        border-radius: 9px;
        color: var(--fd-navy);
        background: #fff;
        box-shadow: 0 5px 15px rgba(31, 43, 88, 0.05);
        text-decoration: none;
    }

    .fd-date-button {
        min-width: 213px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 11px;
        padding: 0 14px;
        font-size: 11px;
        font-weight: 700;
    }

    .fd-filter-button {
        width: 46px;
        display: grid;
        place-items: center;
    }

    .fd-date-button:hover,
    .fd-filter-button:hover {
        border-color: #cfe3ae;
        color: var(--fd-green-dark);
        background: #f9fcf4;
    }

    .fd-card {
        height: 100%;
        border: 1px solid var(--fd-border);
        border-radius: 9px;
        background: #fff;
        box-shadow: 0 4px 14px rgba(31, 43, 88, 0.05);
    }

    /* Summary cards - clean reference style */
    .fd-stat-card {
        position: relative;
        min-height: 112px;
        padding: 18px 20px;
        overflow: hidden;
        border: 1px solid #dfe6ef;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 3px 12px rgba(24, 45, 76, 0.035);
    }

    .fd-stat-more {
        position: absolute;
        top: 14px;
        right: 15px;
        color: #8b9bb0;
        font-size: 18px;
        line-height: 1;
    }

    .fd-stat-row {
        display: flex;
        align-items: center;
        gap: 18px;
        min-height: 72px;
    }

    .fd-stat-row>div {
        min-width: 0;
    }

    .fd-stat-icon {
        width: 58px;
        height: 58px;
        flex: 0 0 58px;
        display: grid;
        place-items: center;
        border-radius: 16px;
        color: #ffffff;
        background: #123f73 !important;
        font-size: 26px;
    }

    .fd-stat-icon i {
        line-height: 1;
    }

    .fd-stat-icon.blue,
    .fd-stat-icon.green,
    .fd-stat-icon.lime,
    .fd-stat-icon.orange {
        background: #123f73 !important;
    }

    .fd-stat-label {
        display: block;
        margin-bottom: 8px;
        color: #506784;
        font-size: 13px;
        line-height: 1.2;
        font-weight: 400;
    }

    .fd-stat-value {
        display: block;
        color: #020b16;
        font-size: 31px;
        line-height: 1;
        font-weight: 700;
        letter-spacing: -0.5px;
    }

    .fd-stat-card .fd-growth,
    .fd-stat-card .fd-sparkline {
        display: none !important;
    }

    .fd-growth {
        display: block;
        margin-top: 14px;
        color: #8a95a8;
        font-size: 9px;
    }

    .fd-growth strong {
        font-size: 10px;
    }

    .fd-growth.up strong {
        color: var(--fd-green-dark);
    }

    .fd-growth.down strong {
        color: var(--fd-red);
    }

    .fd-growth.flat strong {
        color: #7d899d;
    }

    .fd-sparkline {
        position: absolute;
        right: 18px;
        bottom: 7px;
        left: 18px;
        height: 45px;
    }

    .fd-panel {
        padding: 18px;
    }

    .fd-panel-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 13px;
    }

    .fd-panel-title h2 {
        margin: 0;
        color: var(--fd-text);
        font-size: 14px;
        font-weight: 700;
    }

    .fd-chart-card {
        min-height: 313px;
    }

    .fd-chart-area {
        position: relative;
        height: 245px;
    }

    .fd-chart-area canvas {
        width: 100% !important;
        height: 100% !important;
    }

    .fd-chart-legend {
        color: var(--fd-muted);
        font-size: 10px;
        white-space: nowrap;
    }

    .fd-status-wrapper {
        min-height: 245px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 22px;
    }

    .fd-donut {
        position: relative;
        width: 165px;
        height: 165px;
        flex: 0 0 165px;
        display: grid;
        place-items: center;
        border-radius: 50%;
    }

    .fd-donut::before {
        position: absolute;
        width: 104px;
        height: 104px;
        border-radius: 50%;
        background: #fff;
        content: "";
    }

    .fd-donut-center {
        position: relative;
        z-index: 1;
        text-align: center;
    }

    .fd-donut-center strong {
        display: block;
        color: var(--fd-text);
        font-size: 21px;
    }

    .fd-donut-center small {
        color: var(--fd-muted);
        font-size: 10px;
    }

    .fd-status-legend {
        display: flex;
        flex-direction: column;
        gap: 11px;
    }

    .fd-legend-row {
        display: flex;
        gap: 8px;
        color: var(--fd-muted);
        font-size: 10px;
        line-height: 1.45;
    }

    .fd-legend-dot {
        width: 8px;
        height: 8px;
        flex: 0 0 8px;
        margin-top: 3px;
        border-radius: 50%;
    }

    .fd-legend-row strong {
        color: var(--fd-text);
    }

    .fd-tasks-count {
        padding: 4px 8px;
        border-radius: 999px;
        color: var(--fd-green-dark);
        background: var(--fd-green-soft);
        font-size: 9px;
        font-weight: 700;
    }

    .fd-task-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .fd-task-item {
        min-height: 41px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 10px;
        border: 1px solid var(--fd-border);
        border-radius: 8px;
        color: inherit;
        background: #fbfcfa;
        text-decoration: none;
        transition:
            border-color 0.2s ease,
            background 0.2s ease;
    }

    .fd-task-item:hover {
        border-color: #cfe3ae;
        color: inherit;
        background: #f7fbed;
    }

    .fd-task-check {
        width: 17px;
        height: 17px;
        flex: 0 0 17px;
        display: grid;
        place-items: center;
        border: 1px solid #cdd3df;
        border-radius: 4px;
        color: #fff;
        font-size: 10px;
    }

    .fd-task-item.complete {
        background: #f5faee;
    }

    .fd-task-item.complete .fd-task-check {
        border-color: var(--fd-green);
        background: var(--fd-green);
    }

    .fd-task-content {
        min-width: 0;
        flex: 1;
    }

    .fd-task-content strong,
    .fd-task-content small {
        display: block;
    }

    .fd-task-content strong {
        overflow: hidden;
        color: var(--fd-navy);
        font-size: 10px;
        font-weight: 700;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .fd-task-content small {
        margin-top: 2px;
        color: var(--fd-muted);
        font-size: 9px;
    }

    .fd-task-item.complete .fd-task-content strong {
        color: #8792a4;
        text-decoration: line-through;
    }

    .fd-task-time {
        flex: 0 0 auto;
        padding: 4px 7px;
        border-radius: 999px;
        color: #5c6b81;
        background: #eef2f6;
        font-size: 8.5px;
        font-weight: 700;
        white-space: nowrap;
    }

    .fd-task-footer {
        display: flex;
        justify-content: flex-end;
        padding-top: 7px;
    }

    .fd-link {
        color: var(--fd-green-dark);
        font-size: 10px;
        font-weight: 600;
        text-decoration: none;
    }

    .fd-link:hover {
        color: var(--fd-green);
    }

    .fd-recent-jobs-card {
        min-height: 360px;
        overflow: hidden;
    }

    .fd-view-button {
        padding: 6px 11px;
        border: 1px solid var(--fd-border);
        border-radius: 5px;
        color: #53627a;
        background: #fff;
        font-size: 10px;
        text-decoration: none;
    }

    .fd-view-button:hover {
        border-color: #cfe3ae;
        color: var(--fd-green-dark);
        background: #f9fcf4;
    }

    .fd-jobs-table {
        min-width: 820px;
        margin: 4px 0 0;
        white-space: nowrap;
    }

    .fd-jobs-table th {
        padding: 11px 6px;
        border-bottom-color: var(--fd-border);
        color: #65738a;
        font-size: 9px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .fd-jobs-table td {
        padding: 12px 6px;
        border-bottom-color: #f1f3f7;
        color: #33445f;
        font-size: 9.5px;
        vertical-align: middle;
    }

    .fd-job-name {
        color: var(--fd-text);
        font-weight: 700;
    }

    .fd-status {
        display: inline-flex;
        padding: 5px 7px;
        border-radius: 5px;
        font-size: 9px;
        font-weight: 600;
    }

    .fd-status.progress {
        color: #123d70;
        background: #edf2f7;
    }

    .fd-status.completed {
        color: #5d971b;
        background: #f0f8e5;
    }

    .fd-status.pending {
        color: #678a23;
        background: #f5f9ea;
    }

    .fd-status.cancelled {
        color: #b9444d;
        background: #fff0f1;
    }

    .fd-action-link {
        width: 28px;
        height: 28px;
        display: grid;
        place-items: center;
        border-radius: 6px;
        color: #66748b;
        text-decoration: none;
    }

    .fd-action-link:hover {
        color: var(--fd-green-dark);
        background: var(--fd-green-soft);
    }

    .fd-schedule-event {
        min-height: 45px;
        display: grid;
        grid-template-columns: 10px 58px 1fr;
        align-items: start;
        color: inherit;
        text-decoration: none;
    }

    .fd-schedule-event:hover .fd-schedule-info strong {
        color: var(--fd-green-dark);
    }

    .fd-schedule-dot {
        width: 8px;
        height: 8px;
        margin-top: 3px;
        border-radius: 50%;
        background: var(--fd-green);
    }

    .fd-schedule-time {
        padding-top: 1px;
        color: var(--fd-muted);
        font-size: 9px;
    }

    .fd-schedule-info strong,
    .fd-schedule-info small {
        display: block;
    }

    .fd-schedule-info strong {
        color: var(--fd-text);
        font-size: 10px;
    }

    .fd-schedule-info small {
        margin-top: 2px;
        color: var(--fd-muted);
        font-size: 9px;
    }

    .fd-activity-item {
        display: flex;
        gap: 10px;
        padding: 8px 0;
    }

    .fd-activity-icon {
        width: 30px;
        height: 30px;
        flex: 0 0 30px;
        display: grid;
        place-items: center;
        border-radius: 9px;
    }

    .fd-activity-icon.green,
    .fd-activity-icon.lime {
        color: var(--fd-green-dark);
        background: #f0f8e5;
    }

    .fd-activity-icon.orange {
        color: #789d2c;
        background: #f4f9ea;
    }

    .fd-activity-icon.blue {
        color: #123d70;
        background: #edf2f7;
    }

    .fd-activity-content strong,
    .fd-activity-content small {
        display: block;
    }

    .fd-activity-content strong {
        color: var(--fd-text);
        font-size: 10px;
    }

    .fd-activity-content small {
        margin-top: 2px;
        color: var(--fd-muted);
        font-size: 9px;
        line-height: 1.4;
    }

    .fd-bottom-card {
        position: relative;
        min-height: 132px;
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 22px;
        overflow: hidden;
    }

    .fd-bottom-icon {
        width: 52px;
        height: 52px;
        flex: 0 0 52px;
        display: grid;
        place-items: center;
        border-radius: 14px;
        font-size: 22px;
    }

    .fd-bottom-content small,
    .fd-bottom-content strong,
    .fd-bottom-content span {
        display: block;
    }

    .fd-bottom-content small {
        color: var(--fd-muted);
        font-size: 10px;
        font-weight: 600;
    }

    .fd-bottom-content strong {
        margin-top: 5px;
        color: var(--fd-text);
        font-size: 23px;
        line-height: 1.1;
    }

    .fd-bottom-content span {
        margin-top: 7px;
        color: #33445f;
        font-size: 9px;
        font-weight: 700;
    }

    .fd-bottom-content .growth {
        color: var(--fd-green-dark);
    }

    .fd-empty {
        min-height: 120px;
        display: grid;
        place-items: center;
        padding: 20px;
        color: #9aa4b3;
        font-size: 10px;
        text-align: center;
    }

    @media (max-width: 1199.98px) {
        .fd-status-wrapper {
            gap: 14px;
        }
    }

    @media (max-width: 991.98px) {

        .fieldplx-topbar,
        body.fieldplx-sidebar-collapsed .fieldplx-topbar {
            margin-left: 0 !important;
            width: 100% !important;
        }

        .fieldplx-sidebar,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
            width: 250px !important;
            min-width: 250px !important;
            transform: translateX(-100%);
            box-shadow: none !important;
            filter: none !important;
        }

        body.fieldplx-sidebar-mobile-open .fieldplx-sidebar {
            transform: translateX(0) !important;
        }

        .fieldplx-main-content,
        body.fieldplx-sidebar-collapsed .fieldplx-main-content {
            margin-left: 0 !important;
        }

        .fieldplx-sidebar-brand-text,
        .fieldplx-sidebar-section-label,
        .fieldplx-sidebar-link-text,
        .fieldplx-sidebar-arrow,
        .fieldplx-sidebar-user-details,
        .fieldplx-sidebar-logout,
        .fieldplx-sidebar-submenu {
            display: initial;
        }
    }

    @media (max-width: 767.98px) {
        :root {
            --fieldplx-topbar-height: 64px;
        }

        .fieldplx-topbar,
        .fieldplx-topbar-inner {
            min-height: 64px !important;
        }

        .fieldplx-topbar-inner {
            padding: 0 13px !important;
        }

        .fieldplx-search-wrap {
            display: none !important;
        }

        .fd-dashboard {
            padding: 17px 13px 28px;
        }

        .fd-welcome {
            align-items: flex-start;
        }

        .fd-welcome h1 {
            font-size: 19px;
        }

        .fd-welcome p {
            max-width: 260px;
            font-size: 11px;
            line-height: 1.5;
        }

        .fd-date-button {
            min-width: 46px;
            width: 46px;
            padding: 0;
            justify-content: center;
        }

        .fd-date-button span,
        .fd-date-button .bi-chevron-down {
            display: none;
        }

        .fd-stat-card {
            min-height: 108px;
            padding: 17px 18px;
        }

        .fd-donut {
            width: 145px;
            height: 145px;
            flex-basis: 145px;
        }

        .fd-donut::before {
            width: 91px;
            height: 91px;
        }
    }

    @media (max-width: 420px) {
        .fd-welcome {
            min-height: 65px;
            gap: 10px;
        }

        .fd-date-actions {
            gap: 5px;
        }

        .fd-filter-button {
            width: 42px;
        }

        .fd-status-wrapper {
            transform: scale(0.92);
            margin-inline: -14px;
        }
    }

    @media (max-width: 575.98px) {
        .fd-stat-card {
            min-height: 102px;
            padding: 15px 17px;
        }

        .fd-stat-row {
            gap: 15px;
            min-height: 66px;
        }

        .fd-stat-icon {
            width: 54px;
            height: 54px;
            flex-basis: 54px;
            border-radius: 15px;
            font-size: 24px;
        }

        .fd-stat-label {
            margin-bottom: 7px;
        }

        .fd-stat-value {
            font-size: 28px;
        }

        .fd-stat-row {
            gap: 18px;
            min-height: 72px;
        }

        .fd-stat-value {
            font-size: 29px;
        }
    }

    .fieldplx-footer {
        min-height: 52px;
        margin-left: var(--fieldplx-sidebar-width);
        border-top: 1px solid var(--fieldplx-border);
        background: #ffffff;
        transition:
            margin-left 0.22s ease,
            background-color 0.22s ease;
    }

    .fieldplx-footer-inner {
        min-height: 52px;
        padding: 10px 18px;
        display: flex;
        align-items: center;
        gap: 18px;
        color: #6b7280;
        font-size: 10px;
    }

    .fieldplx-footer-copyright {
        min-width: 0;
    }

    .fieldplx-footer-links {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .fieldplx-footer-links a {
        color: #6b7280;
        text-decoration: none;
        transition: color 0.18s ease;
    }

    .fieldplx-footer-links a:hover {
        color: var(--fieldplx-primary);
    }

    .fieldplx-footer-separator {
        color: #d1d5db;
        font-size: 8px;
    }

    .fieldplx-footer-product {
        margin-left: auto;
        white-space: nowrap;
        color: #9ca3af;
    }

    .fieldplx-footer-product strong {
        color: var(--fieldplx-primary);
        font-weight: 700;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-footer {
        margin-left: var(--fieldplx-sidebar-collapsed-width);
    }

    @media (max-width: 991.98px) {
        .fieldplx-footer {
            margin-left: 0;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-footer {
            margin-left: 0;
        }
    }

    @media (max-width: 767.98px) {
        .fieldplx-footer-inner {
            padding: 12px;
            flex-wrap: wrap;
            justify-content: center;
            gap: 7px 14px;
            text-align: center;
        }

        .fieldplx-footer-product {
            width: 100%;
            margin-left: 0;
        }
    }

    /* Notification dropdown correction */
    .dropdown:has(.fieldplx-topbar-action) .fieldplx-dropdown {
        right: 0 !important;
        left: auto !important;
        width: 340px !important;
        max-width: calc(100vw - 24px) !important;
        margin-top: 10px !important;
        border: 1px solid var(--fd-border) !important;
        border-radius: 14px !important;
        background: #ffffff !important;
        box-shadow: 0 14px 34px rgba(29, 38, 74, 0.12) !important;
    }

    #topbarNotificationList {
        max-height: 300px;
        overflow-y: auto;
        background: #ffffff;
    }

    .fieldplx-empty-notifications {
        min-height: 155px !important;
        padding: 28px 18px 24px !important;
    }

    .fieldplx-dropdown-footer {
        border-top: 1px solid var(--fd-border) !important;
    }

    @media (max-width: 575.98px) {
        .dropdown:has(.fieldplx-topbar-action) .fieldplx-dropdown {
            width: min(320px, calc(100vw - 20px)) !important;
        }

        .fieldplx-empty-notifications {
            min-height: 135px !important;
            padding: 22px 15px !important;
        }
    }

    /* ==========================================================
   FieldPlx mobile sidebar final correction
   Desktop sidebar appearance is intentionally unchanged.
   ========================================================== */
    @media (max-width: 991.98px) {

        html,
        body {
            overflow-x: hidden !important;
        }

        body.fieldplx-sidebar-mobile-open {
            overflow: hidden !important;
        }

        .fieldplx-topbar,
        body.fieldplx-sidebar-collapsed .fieldplx-topbar {
            margin-left: 0 !important;
            width: 100% !important;
        }

        .fieldplx-main-content,
        body.fieldplx-sidebar-collapsed .fieldplx-main-content {
            margin-left: 0 !important;
            width: 100% !important;
        }

        .fieldplx-sidebar,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
            width: min(300px, calc(100vw - 52px)) !important;
            min-width: 0 !important;
            max-width: 300px !important;
            height: 100vh !important;
            height: 100dvh !important;
            position: fixed !important;
            top: 0 !important;
            bottom: 0 !important;
            left: 0 !important;
            z-index: 1060 !important;

            display: flex !important;
            flex-direction: column !important;

            overflow: hidden !important;
            visibility: hidden !important;
            transform: translate3d(-100%, 0, 0) !important;

            border-right: 0 !important;
            box-shadow: none !important;
            filter: none !important;

            transition:
                transform 0.25s ease,
                visibility 0.25s ease !important;

            will-change: transform;
        }

        body.fieldplx-sidebar-mobile-open .fieldplx-sidebar,
        body.fieldplx-sidebar-mobile-open.fieldplx-sidebar-collapsed .fieldplx-sidebar {
            visibility: visible !important;
            transform: translate3d(0, 0, 0) !important;
        }

        .fieldplx-sidebar-header,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header {
            flex: 0 0 auto !important;
            justify-content: flex-start !important;
            padding-left: 14px !important;
            padding-right: 10px !important;
        }

        .fieldplx-sidebar-close {
            width: 34px !important;
            height: 34px !important;
            margin-left: auto !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;

            color: rgba(255, 255, 255, 0.88) !important;
            background: rgba(255, 255, 255, 0.08) !important;
        }

        .fieldplx-sidebar-close:hover {
            color: #ffffff !important;
            background: rgba(255, 255, 255, 0.14) !important;
        }

        .fieldplx-sidebar-body {
            min-height: 0 !important;
            flex: 1 1 auto !important;
            overflow-x: hidden !important;
            overflow-y: auto !important;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
        }

        .fieldplx-sidebar-footer {
            flex: 0 0 auto !important;
        }

        /* Never allow the desktop collapsed state to hide mobile labels. */
        .fieldplx-sidebar-brand-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text {
            display: block !important;
        }

        .fieldplx-sidebar-section-label,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label {
            display: block !important;
        }

        .fieldplx-sidebar-link-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text {
            display: block !important;
        }

        .fieldplx-sidebar-arrow,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow {
            display: inline-flex !important;
        }

        .fieldplx-sidebar-user-details,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details {
            display: block !important;
        }

        .fieldplx-sidebar-logout,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout {
            display: inline-flex !important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user {
            justify-content: flex-start !important;
        }

        /* Restore proper accordion behavior on mobile.
       Do not use display:initial here: it turns the submenu into inline
       content and breaks max-height animation/spacing. */
        .fieldplx-sidebar-submenu,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu {
            display: block !important;
            max-height: 0 !important;
            overflow: hidden !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;

            transition:
                max-height 0.25s ease,
                padding-top 0.25s ease,
                padding-bottom 0.25s ease !important;
        }

        .fieldplx-sidebar-menu.menu-open>.fieldplx-sidebar-submenu,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-menu.menu-open>.fieldplx-sidebar-submenu {
            display: block !important;
            max-height: 680px !important;
            padding-top: 4px !important;
            padding-bottom: 5px !important;
        }

        .fieldplx-sidebar-overlay {
            position: fixed !important;
            inset: 0 !important;
            z-index: 1055 !important;

            display: block !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;

            background: rgba(0, 17, 49, 0.48) !important;
            transition:
                opacity 0.25s ease,
                visibility 0.25s ease !important;
        }

        body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay {
            visibility: visible !important;
            opacity: 1 !important;
            pointer-events: auto !important;
        }
    }

    @media (max-width: 575.98px) {

        .fieldplx-sidebar,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
            width: min(288px, calc(100vw - 44px)) !important;
        }

        .fieldplx-sidebar-body {
            padding-left: 10px !important;
            padding-right: 10px !important;
        }

        .fieldplx-sidebar-link {
            min-height: 43px !important;
            padding-left: 12px !important;
            padding-right: 12px !important;
            gap: 12px !important;
            font-size: 13px !important;
        }

        .fieldplx-sidebar-submenu {
            padding-left: 31px !important;
        }

        .fieldplx-sidebar-sublink {
            min-height: 33px !important;
            font-size: 11px !important;
        }
    }


    /* Employees page */
    .fd-employees-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px
    }

    .fd-employees-title {
        margin: 0 0 7px;
        color: var(--fd-text);
        font-size: 21px;
        font-weight: 700
    }

    .fd-employees-subtitle {
        margin: 0;
        color: var(--fd-muted);
        font-size: 11px
    }

    .fd-employees-actions {
        display: flex;
        gap: 8px
    }

    .fd-employee-btn {
        min-height: 39px;
        padding: 0 13px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border: 1px solid var(--fd-border);
        border-radius: 8px;
        background: #fff;
        color: #43546c;
        font-size: 10px;
        font-weight: 700;
        cursor: pointer
    }

    .fd-employee-btn.primary {
        border-color: var(--fd-green);
        background: linear-gradient(90deg, #7fc92d, #68aa1d);
        color: #fff
    }

    .fd-employee-btn:hover {
        border-color: #cfe3ae;
        background: #f9fcf4;
        color: var(--fd-green-dark)
    }

    .fd-employee-btn.primary:hover {
        background: linear-gradient(90deg, #74b824, #5d971b);
        color: #fff
    }

    .fd-employee-btn.danger {
        border-color: #ffd5d9;
        color: #b9444d
    }

    .fd-employee-loader {
        width: 13px;
        height: 13px;
        display: none;
        border: 2px dotted currentColor;
        border-radius: 50%;
        animation: eSpin .75s linear infinite
    }

    .fd-employee-btn.loading .fd-employee-loader {
        display: inline-block
    }

    @keyframes eSpin {
        to {
            transform: rotate(360deg)
        }
    }

    .fd-employee-stat {
        min-height: 112px;
        padding: 18px 20px;
        border: 1px solid #dfe6ef;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(24, 45, 76, .035)
    }

    .fd-employee-stat-row {
        min-height: 72px;
        display: flex;
        align-items: center;
        gap: 18px
    }

    .fd-employee-stat-icon {
        width: 58px;
        height: 58px;
        flex: 0 0 58px;
        display: grid;
        place-items: center;
        border-radius: 16px;
        background: #123f73;
        color: #fff;
        font-size: 25px
    }

    .fd-employee-stat-label {
        display: block;
        margin-bottom: 8px;
        color: #506784;
        font-size: 13px
    }

    .fd-employee-stat-value {
        display: block;
        color: #020b16;
        font-size: 31px;
        line-height: 1;
        font-weight: 700
    }

    .fd-employees-card {
        overflow: hidden
    }

    .fd-employees-toolbar {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        border-bottom: 1px solid var(--fd-border);
        background: #fbfcfd
    }

    .fd-employee-search {
        width: 270px;
        position: relative
    }

    .fd-employee-search i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #8a96a7
    }

    .fd-employee-search input,
    .fd-employee-filter {
        height: 39px;
        border: 1px solid #dde4ec;
        border-radius: 8px;
        background: #fff;
        color: #33445f;
        font-size: 10px;
        outline: 0
    }

    .fd-employee-search input {
        width: 100%;
        padding: 8px 11px 8px 34px
    }

    .fd-employee-filter {
        min-width: 140px;
        padding: 8px 10px
    }

    .fd-employee-toolbar-spacer {
        margin-left: auto
    }

    .fd-employee-table-wrap {
        overflow-x: auto
    }

    .fd-employee-table {
        width: 100%;
        min-width: 1180px;
        border-collapse: collapse;
        white-space: nowrap
    }

    .fd-employee-table th {
        padding: 11px 12px;
        border-bottom: 1px solid var(--fd-border);
        background: #f8fafc;
        color: #65738a;
        font-size: 9px;
        font-weight: 600;
        text-transform: uppercase
    }

    .fd-employee-table td {
        padding: 12px;
        border-bottom: 1px solid #f1f3f7;
        color: #33445f;
        font-size: 9.5px
    }

    .fd-employee-person {
        display: flex;
        align-items: center;
        gap: 10px
    }

    .fd-employee-avatar {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: grid;
        place-items: center;
        border-radius: 50%;
        background: linear-gradient(135deg, #fff, #e8f3d9);
        border: 1px solid #dce8cf;
        color: var(--fd-navy);
        font-size: 10px;
        font-weight: 700;
        overflow: hidden
    }

    .fd-employee-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover
    }

    .fd-employee-person strong,
    .fd-employee-person small {
        display: block
    }

    .fd-employee-person small {
        margin-top: 2px;
        color: #8d98a8;
        font-size: 8.5px
    }

    .fd-employee-badge {
        display: inline-flex;
        padding: 5px 7px;
        border-radius: 5px;
        font-size: 8.5px;
        font-weight: 600
    }

    .fd-employee-badge.active,
    .fd-employee-badge.field {
        color: #5d971b;
        background: #f0f8e5
    }

    .fd-employee-badge.inactive {
        color: #6f7b90;
        background: #eef2f6
    }

    .fd-employee-badge.invited,
    .fd-employee-badge.admin {
        color: #123d70;
        background: #edf2f7
    }

    .fd-employee-badge.suspended {
        color: #b9444d;
        background: #fff0f1
    }

    .fd-employee-actions-cell {
        display: flex;
        gap: 5px
    }

    .fd-employee-icon {
        width: 29px;
        height: 29px;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 6px;
        background: transparent;
        color: #66748b;
        cursor: pointer
    }

    .fd-employee-icon:hover {
        background: var(--fd-green-soft);
        color: var(--fd-green-dark)
    }

    .fd-employee-icon.danger:hover {
        background: #fff0f1;
        color: #b9444d
    }

    .fd-employee-empty {
        padding: 28px 18px !important;
        text-align: center;
        color: #9aa4b3 !important;
        font-size: 10px !important
    }

    .fd-employee-pagination {
        padding: 10px 14px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top: 1px solid var(--fd-border);
        font-size: 9px;
        color: #768397
    }

    .fd-employee-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 15000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
        background: rgba(0, 17, 49, .46);
        backdrop-filter: blur(3px)
    }

    .fd-employee-modal-backdrop.show {
        display: flex
    }

    .fd-employee-modal {
        width: min(860px, 100%);
        max-height: calc(100vh - 36px);
        overflow: auto;
        border: 1px solid #dfe5ec;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 24px 65px rgba(0, 17, 49, .24)
    }

    .fd-employee-modal-header {
        padding: 11px 14px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid var(--fd-border);
        background: #fbfcfd
    }

    .fd-employee-modal-icon {
        width: 34px;
        height: 34px;
        display: grid;
        place-items: center;
        border-radius: 9px;
        background: var(--fd-green-soft);
        color: var(--fd-green-dark)
    }

    .fd-employee-modal-heading {
        flex: 1
    }

    .fd-employee-modal-heading h3 {
        margin: 0;
        font-size: 12px
    }

    .fd-employee-modal-heading p {
        margin: 3px 0 0;
        color: var(--fd-muted);
        font-size: 8.5px
    }

    .fd-employee-modal-close {
        width: 30px;
        height: 30px;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: #8490a0
    }

    .fd-employee-modal-body {
        padding: 15px
    }

    .fd-employee-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 13px
    }

    .fd-employee-field.full {
        grid-column: 1/-1
    }

    .fd-employee-field label {
        display: block;
        margin-bottom: 6px;
        color: #42536c;
        font-size: 9px;
        font-weight: 700
    }

    .fd-employee-field input,
    .fd-employee-field select {
        width: 100%;
        min-height: 40px;
        padding: 8px 10px;
        border: 1px solid #dfe5ec;
        border-radius: 8px;
        background: #fff;
        color: #263750;
        font-size: 10px;
        outline: 0
    }

    .fd-employee-section {
        grid-column: 1/-1;
        padding: 7px 0 2px;
        border-bottom: 1px solid #eef2f5;
        color: #31425b;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase
    }

    .fd-employee-switches {
        grid-column: 1/-1;
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 9px
    }

    .fd-employee-switch-row {
        padding: 10px;
        border: 1px solid var(--fd-border);
        border-radius: 9px;
        background: #fbfcfd;
        display: flex;
        align-items: center;
        justify-content: space-between
    }

    .fd-employee-switch-row strong,
    .fd-employee-switch-row small {
        display: block
    }

    .fd-employee-switch-row strong {
        font-size: 9.5px
    }

    .fd-employee-switch-row small {
        margin-top: 2px;
        color: #8a96a7;
        font-size: 8px
    }

    .fd-employee-switch input {
        width: 15px;
        height: 15px;
        accent-color: var(--fd-green)
    }

    .fd-employee-modal-footer {
        padding: 12px 15px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        border-top: 1px solid var(--fd-border);
        background: #fbfcfd
    }

    .fd-employee-confirm {
        width: min(440px, 100%)
    }

    .fd-employee-toast {
        width: min(290px, calc(100vw - 24px));
        position: fixed;
        top: 82px;
        right: 16px;
        z-index: 25000;
        padding: 8px 9px;
        display: flex;
        align-items: center;
        gap: 7px;
        border-radius: 7px;
        color: #fff;
        opacity: 0;
        transform: translateY(-8px);
        pointer-events: none;
        transition: .18s ease;
        box-shadow: 0 10px 26px rgba(0, 17, 49, .18)
    }

    .fd-employee-toast.show {
        opacity: 1;
        transform: translateY(0)
    }

    .fd-employee-toast.success {
        background: #5d971b
    }

    .fd-employee-toast.error {
        background: #e45b66
    }

    .fd-employee-toast.warning {
        background: #96a52f
    }

    .fd-employee-toast.info {
        background: #123d70
    }

    .fd-employee-toast span {
        font-size: 8.5px
    }

    .fd-employee-toast button {
        margin-left: auto;
        border: 0;
        background: transparent;
        color: #fff
    }

    @media(max-width:767.98px) {
        .fd-employees-header {
            flex-direction: column
        }

        .fd-employee-grid {
            grid-template-columns: 1fr
        }

        .fd-employee-field.full,
        .fd-employee-section,
        .fd-employee-switches {
            grid-column: auto
        }

        .fd-employee-switches {
            grid-template-columns: 1fr
        }

        .fd-employee-search {
            width: 100%
        }

        .fd-employee-toolbar-spacer {
            display: none
        }
    }

    @media(max-width:575.98px) {
        .fd-employee-toast {
            top: 72px;
            left: 12px;
            right: 12px;
            width: auto
        }

        .fd-employee-modal-footer {
            flex-direction: column-reverse
        }

        .fd-employee-modal-footer .fd-employee-btn {
            width: 100%
        }
    }

    /* ==========================================================
   Teams page - canonical tenant template
   ========================================================== */
    .fd-teams-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
    }

    .fd-teams-title {
        margin: 0 0 7px;
        color: var(--fd-text);
        font-size: 21px;
        line-height: 1.2;
        font-weight: 700;
    }

    .fd-teams-subtitle {
        margin: 0;
        max-width: 780px;
        color: var(--fd-muted);
        font-size: 11px;
        line-height: 1.55;
    }

    .fd-teams-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .fd-team-button {
        min-height: 39px;
        padding: 0 13px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border: 1px solid var(--fd-border);
        border-radius: 8px;
        color: #43546c;
        background: #fff;
        box-shadow: 0 4px 12px rgba(31, 43, 88, .04);
        font-size: 10px;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
    }

    .fd-team-button:hover {
        border-color: #cfe3ae;
        color: var(--fd-green-dark);
        background: #f9fcf4;
    }

    .fd-team-button.primary {
        border-color: var(--fd-green);
        color: #fff;
        background: linear-gradient(90deg, #7fc92d, #68aa1d);
        box-shadow: 0 7px 16px rgba(104, 170, 29, .18);
    }

    .fd-team-button.primary:hover {
        color: #fff;
        background: linear-gradient(90deg, #74b824, #5d971b);
    }

    .fd-team-button.danger {
        border-color: #ffd5d9;
        color: #b9444d;
        background: #fff;
    }

    .fd-team-button:disabled {
        opacity: .58;
        cursor: not-allowed;
    }

    .fd-team-loader {
        width: 13px;
        height: 13px;
        display: none;
        border: 2px dotted currentColor;
        border-radius: 50%;
        animation: fdTeamSpin .75s linear infinite;
    }

    .fd-team-button.loading .fd-team-loader {
        display: inline-block
    }

    @keyframes fdTeamSpin {
        to {
            transform: rotate(360deg)
        }
    }

    .fd-teams-summary {
        margin-bottom: 16px
    }

    .fd-team-stat-card {
        min-height: 112px;
        padding: 18px 20px;
        border: 1px solid #dfe6ef;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(24, 45, 76, .035);
    }

    .fd-team-stat-row {
        min-height: 72px;
        display: flex;
        align-items: center;
        gap: 18px;
    }

    .fd-team-stat-icon {
        width: 58px;
        height: 58px;
        flex: 0 0 58px;
        display: grid;
        place-items: center;
        border-radius: 16px;
        color: #fff;
        background: #123f73;
        font-size: 25px;
    }

    .fd-team-stat-label {
        display: block;
        margin-bottom: 8px;
        color: #506784;
        font-size: 13px;
    }

    .fd-team-stat-value {
        display: block;
        color: #020b16;
        font-size: 31px;
        line-height: 1;
        font-weight: 700;
    }

    .fd-teams-card {
        overflow: hidden
    }

    .fd-teams-toolbar {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        border-bottom: 1px solid var(--fd-border);
        background: #fbfcfd;
    }

    .fd-team-search {
        width: 270px;
        position: relative
    }

    .fd-team-search i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #8a96a7;
        font-size: 13px;
    }

    .fd-team-search input,
    .fd-team-filter {
        height: 39px;
        border: 1px solid #dde4ec;
        border-radius: 8px;
        outline: 0;
        color: #33445f;
        background: #fff;
        font-size: 10px;
    }

    .fd-team-search input {
        width: 100%;
        padding: 8px 11px 8px 34px;
    }

    .fd-team-filter {
        min-width: 140px;
        padding: 8px 10px;
    }

    .fd-team-search input:focus,
    .fd-team-filter:focus {
        border-color: #a9cf75;
        box-shadow: 0 0 0 3px rgba(116, 184, 36, .11);
    }

    .fd-team-toolbar-spacer {
        margin-left: auto
    }

    .fd-team-table-wrap {
        width: 100%;
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: thin;
        scrollbar-color: #9aa0a6 transparent;
    }

    .fd-team-table-wrap::-webkit-scrollbar {
        height: 3px !important
    }

    .fd-team-table-wrap::-webkit-scrollbar-track {
        background: transparent !important
    }

    .fd-team-table-wrap::-webkit-scrollbar-thumb {
        min-width: 20px;
        border-radius: 999px !important;
        background: #9aa0a6 !important;
    }

    .fd-team-table-wrap::-webkit-scrollbar-button {
        width: 0 !important;
        height: 0 !important;
        display: none !important;
    }

    .fd-team-table {
        width: 100%;
        min-width: 1080px;
        margin: 0;
        border-collapse: collapse;
        white-space: nowrap;
    }

    .fd-team-table th {
        padding: 11px 12px;
        border-bottom: 1px solid var(--fd-border);
        color: #65738a;
        background: #f8fafc;
        font-size: 9px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .fd-team-table td {
        padding: 12px;
        border-bottom: 1px solid #f1f3f7;
        color: #33445f;
        font-size: 9.5px;
        vertical-align: middle;
    }

    .fd-team-table tbody tr:hover {
        background: #fbfcfa
    }

    .fd-team-name {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .fd-team-name-icon {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: grid;
        place-items: center;
        border-radius: 10px;
        color: var(--fd-green-dark);
        background: var(--fd-green-soft);
        font-size: 15px;
    }

    .fd-team-name strong,
    .fd-team-name small {
        display: block
    }

    .fd-team-name strong {
        color: var(--fd-text);
        font-size: 10.5px;
    }

    .fd-team-name small {
        margin-top: 2px;
        color: #8d98a8;
        font-size: 8.5px;
    }

    .fd-team-badge {
        display: inline-flex;
        align-items: center;
        padding: 5px 7px;
        border-radius: 5px;
        font-size: 8.5px;
        font-weight: 600;
    }

    .fd-team-badge.active {
        color: #5d971b;
        background: #f0f8e5;
    }

    .fd-team-badge.inactive {
        color: #6f7b90;
        background: #eef2f6;
    }

    .fd-team-badge.primary {
        color: #123d70;
        background: #edf2f7;
    }

    .fd-team-actions-cell {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .fd-team-icon-button {
        width: 29px;
        height: 29px;
        padding: 0;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 6px;
        color: #66748b;
        background: transparent;
        cursor: pointer;
        font-size: 12px;
    }

    .fd-team-icon-button:hover {
        color: var(--fd-green-dark);
        background: var(--fd-green-soft);
    }

    .fd-team-icon-button.danger:hover {
        color: #b9444d;
        background: #fff0f1;
    }

    .fd-team-empty {
        padding: 28px 18px !important;
        text-align: center;
        color: #9aa4b3 !important;
        font-size: 10px !important;
    }

    .fd-team-pagination {
        min-height: 49px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border-top: 1px solid var(--fd-border);
        color: #768397;
        background: #fff;
        font-size: 9px;
    }

    .fd-team-pagination-actions {
        display: flex;
        gap: 5px
    }

    .fd-team-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 15000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
        background: rgba(0, 17, 49, .46);
        backdrop-filter: blur(3px);
    }

    .fd-team-modal-backdrop.show {
        display: flex
    }

    .fd-team-modal {
        width: min(860px, 100%);
        max-height: calc(100vh - 36px);
        overflow: auto;
        border: 1px solid #dfe5ec;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 24px 65px rgba(0, 17, 49, .24);
    }

    .fd-team-modal-header {
        min-height: 58px;
        padding: 11px 14px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid var(--fd-border);
        background: #fbfcfd;
    }

    .fd-team-modal-icon {
        width: 34px;
        height: 34px;
        display: grid;
        place-items: center;
        border-radius: 9px;
        color: var(--fd-green-dark);
        background: var(--fd-green-soft);
        font-size: 15px;
    }

    .fd-team-modal-heading {
        min-width: 0;
        flex: 1
    }

    .fd-team-modal-heading h3 {
        margin: 0;
        color: var(--fd-text);
        font-size: 12px;
        font-weight: 700;
    }

    .fd-team-modal-heading p {
        margin: 3px 0 0;
        color: var(--fd-muted);
        font-size: 8.5px;
    }

    .fd-team-modal-close {
        width: 30px;
        height: 30px;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 7px;
        color: #8490a0;
        background: transparent;
        cursor: pointer;
    }

    .fd-team-modal-body {
        padding: 15px
    }

    .fd-team-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 13px;
    }

    .fd-team-field.full {
        grid-column: 1/-1
    }

    .fd-team-field label {
        margin-bottom: 6px;
        display: block;
        color: #42536c;
        font-size: 9px;
        font-weight: 700;
    }

    .fd-team-field input,
    .fd-team-field select,
    .fd-team-field textarea {
        width: 100%;
        min-height: 40px;
        padding: 8px 10px;
        border: 1px solid #dfe5ec;
        border-radius: 8px;
        outline: 0;
        color: #263750;
        background: #fff;
        font-size: 10px;
    }

    .fd-team-field textarea {
        min-height: 76px;
        resize: vertical;
    }

    .fd-team-field input:focus,
    .fd-team-field select:focus,
    .fd-team-field textarea:focus {
        border-color: #a9cf75;
        box-shadow: 0 0 0 3px rgba(116, 184, 36, .11);
    }

    .fd-team-section-title {
        grid-column: 1/-1;
        margin-top: 3px;
        padding: 8px 0 2px;
        border-bottom: 1px solid #eef2f5;
        color: #31425b;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .fd-team-members-box {
        grid-column: 1/-1;
        border: 1px solid var(--fd-border);
        border-radius: 9px;
        overflow: hidden;
    }

    .fd-team-members-head {
        padding: 10px 11px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        background: #f9fbfc;
        border-bottom: 1px solid var(--fd-border);
    }

    .fd-team-members-head strong {
        color: #31425b;
        font-size: 10px;
    }

    .fd-team-members-list {
        max-height: 260px;
        overflow: auto;
    }

    .fd-team-member-row {
        padding: 9px 11px;
        display: grid;
        grid-template-columns: 24px minmax(0, 1fr) 170px 110px;
        align-items: center;
        gap: 8px;
        border-bottom: 1px solid #f0f3f6;
    }

    .fd-team-member-row:last-child {
        border-bottom: 0
    }

    .fd-team-member-row input[type="checkbox"] {
        width: 14px;
        height: 14px;
        accent-color: var(--fd-green);
    }

    .fd-team-member-copy strong,
    .fd-team-member-copy small {
        display: block
    }

    .fd-team-member-copy strong {
        color: #34465f;
        font-size: 9.5px;
    }

    .fd-team-member-copy small {
        margin-top: 2px;
        color: #8a96a7;
        font-size: 8px;
    }

    .fd-team-member-row input[type="text"] {
        width: 100%;
        height: 34px;
        padding: 6px 8px;
        border: 1px solid #dfe5ec;
        border-radius: 7px;
        font-size: 9px;
    }

    .fd-team-primary-label {
        display: flex;
        align-items: center;
        gap: 6px;
        color: #607086;
        font-size: 8.5px;
    }

    .fd-team-modal-footer {
        padding: 12px 15px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        border-top: 1px solid var(--fd-border);
        background: #fbfcfd;
    }

    .fd-team-confirm {
        width: min(440px, 100%)
    }

    .fd-team-confirm .fd-team-modal-body {
        padding: 18px 16px;
        color: #56667c;
        font-size: 10px;
        line-height: 1.6;
    }

    .fd-team-toast {
        width: min(290px, calc(100vw - 24px));
        position: fixed;
        top: 82px;
        right: 16px;
        z-index: 25000;
        padding: 8px 9px;
        display: flex;
        align-items: center;
        gap: 7px;
        border-radius: 7px;
        color: #fff;
        box-shadow: 0 10px 26px rgba(0, 17, 49, .18);
        opacity: 0;
        transform: translateY(-8px);
        pointer-events: none;
        transition: .18s ease;
    }

    .fd-team-toast.show {
        opacity: 1;
        transform: translateY(0);
    }

    .fd-team-toast.success {
        background: #5d971b
    }

    .fd-team-toast.error {
        background: #e45b66
    }

    .fd-team-toast.warning {
        background: #96a52f
    }

    .fd-team-toast.info {
        background: #123d70
    }

    .fd-team-toast-message {
        min-width: 0;
        flex: 1;
        font-size: 8.5px;
        font-weight: 600;
    }

    .fd-team-toast-close {
        width: 19px;
        height: 19px;
        padding: 0;
        border: 0;
        color: #fff;
        background: transparent;
        cursor: pointer;
    }

    @media(max-width:767.98px) {
        .fd-teams-header {
            flex-direction: column
        }

        .fd-teams-actions {
            justify-content: flex-end
        }

        .fd-team-form-grid {
            grid-template-columns: 1fr
        }

        .fd-team-field.full,
        .fd-team-section-title,
        .fd-team-members-box {
            grid-column: auto
        }

        .fd-team-search {
            width: 100%
        }

        .fd-team-toolbar-spacer {
            display: none
        }

        .fd-team-member-row {
            grid-template-columns: 24px minmax(0, 1fr);
        }

        .fd-team-member-row input[type="text"],
        .fd-team-primary-label {
            grid-column: 2;
        }
    }

    @media(max-width:575.98px) {
        .fd-team-stat-card {
            min-height: 102px;
            padding: 15px 17px;
        }

        .fd-team-stat-icon {
            width: 54px;
            height: 54px;
            flex-basis: 54px;
        }

        .fd-team-stat-value {
            font-size: 29px
        }

        .fd-team-filter {
            flex: 1
        }

        .fd-team-modal-footer {
            flex-direction: column-reverse
        }

        .fd-team-modal-footer .fd-team-button {
            width: 100%
        }

        .fd-team-toast {
            top: 72px;
            left: 12px;
            right: 12px;
            width: auto;
        }
    }



    /* ==========================================================
       Customers page - Version 3.2.1
       Header action overflow / topbar overlap fix
       ========================================================== */

    /*
     * The shared navigation is fixed in the live layout. Keep this page's
     * content below it instead of allowing the Customers header to sit
     * underneath the topbar. This fixes Add Customer / More Actions overlap.
     */
    .fieldplx-topbar {
        position: fixed !important;
        top: 0 !important;
        right: 0 !important;
        z-index: 1030 !important;
    }

    .fieldplx-main-content {
        padding-top: var(--fieldplx-topbar-height);
    }

    .fd-teams-header {
        min-width: 0;
        position: relative;
        z-index: 20;
        overflow: visible;
    }

    .fd-teams-header>div:first-child {
        min-width: 0;
        flex: 1 1 auto;
    }

    .fd-teams-actions {
        min-width: 0;
        flex: 0 0 auto;
        margin-left: auto;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: nowrap;
    }

    .fd-teams-actions .fd-team-button {
        flex: 0 0 auto;
        white-space: nowrap;
    }

    .fd-customer-more {
        position: relative;
        z-index: 30;
    }

    .fd-customer-more-menu {
        z-index: 40;
        max-width: min(215px, calc(100vw - 24px));
    }

    @media (max-width: 991.98px) {
        .fd-teams-header {
            align-items: flex-start;
        }

        .fd-teams-actions {
            flex-wrap: wrap;
        }
    }

    @media (max-width: 767.98px) {
        .fieldplx-main-content {
            padding-top: 64px;
        }

        .fd-teams-actions {
            width: 100%;
            margin-left: 0;
            justify-content: flex-start;
        }
    }

    @media (max-width: 420px) {

        .fd-teams-actions>.fd-team-button,
        .fd-teams-actions>.fd-customer-more {
            flex: 1 1 calc(50% - 4px);
            min-width: 0;
        }

        .fd-teams-actions>.fd-customer-more>.fd-team-button {
            width: 100%;
        }
    }

    /* ==========================================================
       Customers page - Version 3.1.0
       Quotation-style summary cards + customer More menus
       ========================================================== */
    .fd-teams-header {
        position: relative;
        z-index: 20;
        overflow: visible;
    }

    .fd-customer-more {
        position: relative;
        z-index: 30;
    }

    .fd-customer-more-menu {
        position: absolute;
        top: calc(100% + 7px);
        right: 0;
        z-index: 40;
        width: 215px;
        padding: 6px;
        display: none;
        border: 1px solid #e1e7ef;
        border-radius: 10px;
        background: #fff;
        box-shadow: 0 16px 38px rgba(15, 23, 42, .15);
    }

    .fd-customer-more.open .fd-customer-more-menu {
        display: block;
    }

    .fd-customer-more-item {
        width: 100%;
        min-height: 36px;
        padding: 8px 10px;
        display: flex;
        align-items: center;
        gap: 9px;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: #33445f !important;
        text-align: left;
        text-decoration: none !important;
        font: inherit;
        font-size: 10px;
        cursor: pointer;
    }

    .fd-customer-more-item i {
        width: 16px;
        color: #123d70;
        font-size: 13px;
        text-align: center;
    }

    .fd-customer-more-item:hover,
    .fd-customer-more-item:focus {
        color: var(--fd-green-dark) !important;
        background: var(--fd-green-soft);
        outline: 0;
    }

    .fd-customer-more-item:hover i,
    .fd-customer-more-item:focus i {
        color: var(--fd-green-dark);
    }

    /* Same visual structure as Quotations: Overview + 3 metric cards. */
    .fd-customer-summary {
        margin-bottom: 16px;
    }

    .fd-customer-summary>div {
        display: flex;
    }

    .fd-customer-summary-card {
        width: 100%;
        min-height: 134px;
        padding: 15px 18px;
        position: relative;
        overflow: visible;
        border: 1px solid #dfe6ef;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(24, 45, 76, .035);
    }

    .fd-customer-summary-title {
        margin: 0;
        color: #10213c;
        font-size: 15px;
        line-height: 1.2;
        font-weight: 700;
    }

    .fd-customer-summary-period {
        display: block;
        margin-top: 3px;
        color: #7f8da1;
        font-size: 10px;
        line-height: 1.2;
    }

    .fd-customer-summary-arrow {
        position: absolute;
        top: 15px;
        right: 16px;
        color: #8191a6;
        font-size: 15px;
        line-height: 1;
    }

    .fd-customer-summary-number-row {
        min-height: 67px;
        display: flex;
        align-items: flex-end;
        gap: 8px;
        padding-top: 10px;
    }

    .fd-customer-summary-value {
        color: #030d1b;
        font-size: 31px;
        line-height: 1;
        font-weight: 700;
        letter-spacing: -.45px;
    }

    .fd-customer-summary-subvalue {
        display: block;
        margin-top: 7px;
        color: #728197;
        font-size: 10px;
    }

    .fd-customer-trend-change {
        position: relative;
        margin-bottom: 1px;
        padding: 4px 7px;
        border-radius: 999px;
        color: #398523;
        background: #edf7e8;
        font-size: 10px;
        line-height: 1;
        font-weight: 600;
        cursor: help;
    }

    .fd-customer-trend-change.down {
        color: #bf4d54;
        background: #fff0f1;
    }

    .fd-customer-trend-change.neutral {
        color: #64748b;
        background: #f1f5f9;
    }

    .fd-customer-trend-tooltip {
        position: absolute;
        left: 50%;
        bottom: calc(100% + 9px);
        z-index: 1080;
        width: 190px;
        padding: 10px 11px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        background: #fff;
        box-shadow: 0 12px 30px rgba(15, 23, 42, .17);
        color: #334155;
        font-size: 10px;
        font-weight: 500;
        line-height: 1.55;
        opacity: 0;
        visibility: hidden;
        transform: translate(-50%, 5px);
        transition: .15s ease;
        pointer-events: none;
    }

    .fd-customer-trend-tooltip::after {
        position: absolute;
        top: 100%;
        left: 50%;
        margin-left: -5px;
        border: 5px solid transparent;
        border-top-color: #fff;
        content: "";
    }

    .fd-customer-trend-change:hover .fd-customer-trend-tooltip,
    .fd-customer-trend-change:focus .fd-customer-trend-tooltip {
        opacity: 1;
        visibility: visible;
        transform: translate(-50%, 0);
    }

    .fd-customer-tooltip-title {
        display: block;
        margin-bottom: 4px;
        color: #64748b;
        font-weight: 600;
    }

    .fd-customer-tooltip-row {
        display: flex;
        justify-content: space-between;
        gap: 10px;
    }

    .fd-customer-tooltip-row strong {
        color: #0f172a;
    }

    .fd-customer-overview-list {
        margin-top: 7px;
        display: grid;
        gap: 5px;
    }

    .fd-customer-overview-row {
        min-height: 14px;
        display: grid;
        grid-template-columns: 7px minmax(0, 1fr) auto;
        align-items: center;
        gap: 6px;
        color: #5d6c82;
        font-size: 9.5px;
        line-height: 1.15;
    }

    .fd-customer-overview-row strong {
        color: #17243a;
        font-size: 9.5px;
        font-weight: 700;
    }

    .fd-customer-overview-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #91a0b4;
    }

    .fd-customer-overview-dot.lead {
        background: #9aa9bc;
    }

    .fd-customer-overview-dot.customer {
        background: #d6a825;
    }

    .fd-customer-overview-dot.active {
        background: #68aa1d;
    }

    .fd-customer-overview-dot.inactive {
        background: #e45b66;
    }

    /* Version 3.2: the customer row itself opens Customer View. */
    .fd-client-click-row {
        cursor: pointer;
    }

    .fd-client-click-row:active {
        background: #f7fbed;
    }

    /* Row-level More menu is fixed so table scrolling never clips it. */
    .fd-client-row-more-button {
        min-width: 64px;
        height: 29px;
        padding: 0 9px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        border: 1px solid #dfe7ef;
        border-radius: 7px;
        color: #43546c;
        background: #fff;
        font-size: 9px;
        font-weight: 700;
        cursor: pointer;
    }

    .fd-client-row-more-button:hover,
    .fd-client-row-more-button.active {
        border-color: #cfe3ae;
        color: var(--fd-green-dark);
        background: var(--fd-green-soft);
    }

    .fd-client-row-more-menu {
        width: 190px;
        padding: 6px;
        position: fixed;
        z-index: 24000;
        display: none;
        border: 1px solid #dfe6ef;
        border-radius: 10px;
        background: #fff;
        box-shadow: 0 18px 42px rgba(0, 17, 49, .17);
    }

    .fd-client-row-more-menu.show {
        display: block;
    }

    .fd-client-row-more-menu .fd-customer-more-item+.fd-customer-more-item {
        margin-top: 2px;
    }

    @media (max-width: 991.98px) {
        .fd-customer-summary-card {
            min-height: 126px;
        }
    }

    @media (max-width: 575.98px) {
        .fd-customer-summary-card {
            min-height: 122px;
        }

        .fd-customer-summary-value {
            font-size: 28px;
        }
    }

    a,
    a:link,
    a:visited,
    a:hover,
    a:focus,
    a:active {
        text-decoration: none !important
    }

    .fd-client-type {
        display: inline-flex;
        align-items: center;
        padding: 5px 7px;
        border-radius: 5px;
        font-size: 8.5px;
        font-weight: 600
    }

    .fd-client-type.client,
    .fd-client-type.active {
        color: #5d971b;
        background: #f0f8e5
    }

    .fd-client-type.lead,
    .fd-client-type.new {
        color: #123d70;
        background: #edf2f7
    }

    .fd-client-type.inactive {
        color: #6f7b90;
        background: #eef2f6
    }

    .fd-client-type.archived {
        color: #8a5e10;
        background: #fff7df
    }

    .fd-client-checks {
        grid-column: 1/-1;
        display: flex;
        flex-wrap: wrap;
        gap: 8px
    }

    .fd-client-check {
        min-height: 38px;
        padding: 7px 9px;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        border: 1px solid #e3e8ed;
        border-radius: 7px;
        color: #5c6d82;
        background: #fff;
        font-size: 8.5px
    }

    .fd-client-check input {
        width: 14px;
        height: 14px;
        accent-color: var(--fd-green)
    }



    /* Customers table font + alignment correction */
    .fd-client-table {
        table-layout: auto;
    }

    .fd-client-table th {
        padding: 12px 14px !important;
        color: #5f6f86 !important;
        font-size: 9px !important;
        line-height: 1.2 !important;
        font-weight: 700 !important;
        letter-spacing: .01em !important;
        text-align: left !important;
        vertical-align: middle !important;
        white-space: nowrap !important;
    }

    .fd-client-table td {
        padding: 12px 14px !important;
        color: #33445f !important;
        font-size: 9.5px !important;
        line-height: 1.45 !important;
        font-weight: 400 !important;
        text-align: left !important;
        vertical-align: middle !important;
    }

    .fd-client-table th:first-child,
    .fd-client-table td:first-child {
        width: 58px;
        text-align: center !important;
    }

    .fd-client-person {
        min-width: 185px;
        align-items: center !important;
    }

    .fd-client-person strong {
        color: #17233b !important;
        font-size: 10.5px !important;
        line-height: 1.35 !important;
        font-weight: 700 !important;
    }

    .fd-client-person small {
        margin-top: 3px !important;
        color: #8793a5 !important;
        font-size: 8.5px !important;
        line-height: 1.3 !important;
        font-weight: 400 !important;
    }

    .fd-client-table td small {
        color: #66758a !important;
        font-size: 8.5px !important;
        line-height: 1.35 !important;
    }

    .fd-client-badge {
        min-height: 22px;
        padding: 4px 7px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        border-radius: 5px !important;
        font-size: 8.5px !important;
        line-height: 1 !important;
        font-weight: 700 !important;
        text-transform: capitalize !important;
        white-space: nowrap !important;
    }

    .fd-client-actions-cell {
        min-width: 100px;
        justify-content: flex-start !important;
        align-items: center !important;
        gap: 4px !important;
    }

    .fd-client-icon-btn {
        width: 29px !important;
        height: 29px !important;
        min-width: 29px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        line-height: 1 !important;
    }

    .fd-client-icon-btn i {
        line-height: 1 !important;
        font-size: 12px !important;
    }

    .fd-client-table td:nth-child(3),
    .fd-client-table td:nth-child(9) {
        vertical-align: middle !important;
    }

    .fd-client-table td:nth-child(10) {
        color: #52627a !important;
        font-size: 9px !important;
    }

    .fd-client-table th:last-child,
    .fd-client-table td:last-child {
        text-align: left !important;
    }

    .fd-client-table a,
    .fd-client-table a:visited,
    .fd-client-table a:hover,
    .fd-client-table a:focus,
    .fd-client-table a:active {
        color: inherit;
        text-decoration: none !important;
    }

    .fd-client-table a.fd-client-badge,
    .fd-client-table a.fd-client-badge:visited {
        color: #123d70 !important;
    }

    @media(max-width:767.98px) {

        .fd-client-table th,
        .fd-client-table td {
            padding: 10px 11px !important;
        }
    }

    /* Client location/view/delete controls */
    .fd-client-location-link {
        min-width: 42px;
        height: 28px;
        padding: 0 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        border: 1px solid #dfe7ef;
        border-radius: 7px;
        color: #123d70 !important;
        background: #f8fafc;
        font-size: 9px;
        font-weight: 700;
        text-decoration: none !important
    }

    .fd-client-location-link:hover {
        border-color: #cfe3ae;
        color: var(--fd-green-dark) !important;
        background: var(--fd-green-soft)
    }

    .fd-team-actions-cell a.fd-team-icon-button {
        text-decoration: none !important
    }


    /* Customers - Version 3.1.0 action column */
    .fd-client-table th:last-child,
    .fd-client-table td:last-child {
        min-width: 250px;
        white-space: nowrap;
    }

    .fd-client-actions-cell {
        min-width: 238px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        flex-wrap: nowrap;
    }

    .fd-client-actions-cell .fd-team-icon-button {
        flex: 0 0 29px;
    }

    .fd-client-action-divider {
        width: 1px;
        height: 23px;
        flex: 0 0 1px;
        margin: 0 2px;
        background: #e5eaf1;
    }

    @media(max-width:575.98px) {

        .fd-client-table th:last-child,
        .fd-client-table td:last-child {
            min-width: 238px;
        }
    }



    /* ==========================================================
       FieldPlx Expenses - Jobber-inspired expense workspace
       ========================================================== */
    .fd-expense-header {
        position: relative;
        z-index: 20;
        overflow: visible;
    }

    .fd-expense-summary {
        margin-bottom: 16px;
    }

    /* Expenses uses the same summary-card language as Customers. */
    .fd-expense-summary .fd-customer-overview-dot.draft {
        background: #d6a825;
    }

    .fd-expense-summary .fd-customer-overview-dot.submitted {
        background: #7f8da1;
    }

    .fd-expense-summary .fd-customer-overview-dot.approved {
        background: #68aa1d;
    }

    .fd-expense-summary .fd-customer-overview-dot.rejected {
        background: #e45b66;
    }

    .fd-expense-summary .fd-customer-summary-subvalue strong {
        color: #17243a;
        font-weight: 700;
    }

    .fd-expense-stat-card {
        min-height: 108px;
        padding: 17px 18px;
        border: 1px solid #dfe6ef;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(24, 45, 76, .035);
    }

    .fd-expense-stat-label {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        color: #64748b;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .45px;
    }

    .fd-expense-stat-label i {
        color: var(--fd-green-dark);
        font-size: 17px;
    }

    .fd-expense-stat-value {
        display: block;
        margin-top: 18px;
        color: #0f172a;
        font-size: 25px;
        line-height: 1;
        font-weight: 800;
    }

    .fd-expense-toolbar {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        padding: 14px;
        border-bottom: 1px solid #edf1f5;
    }

    .fd-expense-search {
        width: min(330px, 100%);
        position: relative;
    }

    .fd-expense-search i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #91a0b4;
        font-size: 13px;
        pointer-events: none;
    }

    .fd-expense-search input,
    .fd-expense-filter {
        height: 39px;
        border: 1px solid #dfe6ef;
        border-radius: 8px;
        outline: 0;
        color: #27364f;
        background: #fff;
        font-size: 10px;
    }

    .fd-expense-search input {
        width: 100%;
        padding: 8px 12px 8px 34px;
    }

    .fd-expense-filter {
        min-width: 145px;
        padding: 0 30px 0 10px;
    }

    .fd-expense-search input:focus,
    .fd-expense-filter:focus {
        border-color: #b9d78e;
        box-shadow: 0 0 0 3px rgba(116, 184, 36, .10);
    }

    .fd-expense-toolbar-spacer {
        margin-left: auto;
    }

    .fd-expense-table td,
    .fd-expense-table th {
        vertical-align: middle;
    }

    .fd-expense-table th:last-child,
    .fd-expense-table td:last-child {
        min-width: 116px;
        white-space: nowrap;
    }

    .fd-expense-item strong {
        display: block;
        color: #17243a;
        font-size: 10.5px;
        font-weight: 700;
    }

    .fd-expense-item small {
        display: block;
        max-width: 230px;
        margin-top: 3px;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        color: #8390a4;
        font-size: 9px;
    }

    .fd-expense-amount {
        color: #17243a;
        font-weight: 800;
        white-space: nowrap;
    }

    .fd-expense-badge {
        min-height: 23px;
        padding: 4px 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: #f3f6f9;
        color: #607087;
        font-size: 8.5px;
        font-weight: 700;
        text-transform: capitalize;
    }

    .fd-expense-badge.submitted,
    .fd-expense-badge.approved {
        color: #4f7e18;
        background: #eff8e4;
    }

    .fd-expense-badge.draft {
        color: #8a6411;
        background: #fff7dc;
    }

    .fd-expense-badge.rejected {
        color: #b9444d;
        background: #fff0f1;
    }

    .fd-expense-receipt {
        min-height: 27px;
        padding: 0 8px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        border: 1px solid #dfe6ef;
        border-radius: 7px;
        color: #43546c;
        background: #fff;
        text-decoration: none;
        font-size: 9px;
        font-weight: 700;
    }

    .fd-expense-receipt:hover {
        border-color: #cfe3ae;
        color: var(--fd-green-dark);
        background: #f9fcf4;
    }

    .fd-expense-action {
        width: 29px;
        height: 29px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #dfe6ef;
        border-radius: 7px;
        color: #526279;
        background: #fff;
        cursor: pointer;
        text-decoration: none;
    }

    .fd-expense-action:hover {
        color: var(--fd-green-dark);
        border-color: #cfe3ae;
        background: #f9fcf4;
    }

    .fd-expense-action.danger:hover {
        color: #b9444d;
        border-color: #ffd5d9;
        background: #fff7f7;
    }

    .fd-expense-empty {
        padding: 42px 20px !important;
        color: #8190a5;
        text-align: center;
        font-size: 10px;
    }

    .fd-expense-pagination {
        min-height: 58px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-top: 1px solid #edf1f5;
        color: #738299;
        font-size: 9px;
    }

    .fd-expense-pagination-actions {
        display: flex;
        gap: 6px;
    }

    .fd-expense-backdrop {
        position: fixed;
        inset: var(--fieldplx-topbar-height, 64px) 0 0 0;
        z-index: 1040;
        display: none;
        background: rgba(1, 17, 49, .26);
        backdrop-filter: blur(1px);
    }

    .fd-expense-backdrop.show {
        display: block;
    }

    .fd-expense-drawer {
        width: min(470px, 100vw);
        position: fixed;
        top: var(--fieldplx-topbar-height, 64px);
        right: 0;
        bottom: 0;
        z-index: 1050;
        display: flex;
        flex-direction: column;
        transform: translateX(104%);
        background: #fff;
        box-shadow: -20px 0 50px rgba(0, 17, 49, .18);
        transition: transform .22s ease;
    }

    .fd-expense-drawer.show {
        transform: translateX(0);
    }

    .fd-expense-drawer-header {
        min-height: 67px;
        padding: 14px 18px;
        display: flex;
        align-items: center;
        gap: 12px;
        flex: 0 0 auto;
        border-bottom: 1px solid #e8edf3;
        background: #fff;
    }

    /*
     * The form is the flex child of the drawer. Without this wrapper being
     * constrained, the form grows to the full height of all fields and the
     * inner body never receives a scrollable height.
     */
    .fd-expense-drawer>form {
        min-height: 0;
        flex: 1 1 auto;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .fd-expense-drawer-title {
        margin: 0;
        color: #001131;
        font-size: 20px;
        font-weight: 800;
    }

    .fd-expense-drawer-subtitle {
        margin-top: 3px;
        color: #7b8799;
        font-size: 9.5px;
    }

    .fd-expense-drawer-close {
        width: 35px;
        height: 35px;
        margin-left: auto;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 0;
        border-radius: 8px;
        color: #3d4d64;
        background: transparent;
        cursor: pointer;
        font-size: 20px;
    }

    .fd-expense-drawer-close:hover {
        background: #f3f6f9;
        color: #001131;
    }

    .fd-expense-drawer-body {
        min-height: 0;
        flex: 1 1 auto;
        overflow-x: hidden;
        overflow-y: auto;
        padding: 18px;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
        scrollbar-gutter: stable;
    }

    .fd-expense-drawer-body::-webkit-scrollbar {
        width: 8px;
    }

    .fd-expense-drawer-body::-webkit-scrollbar-track {
        background: transparent;
    }

    .fd-expense-drawer-body::-webkit-scrollbar-thumb {
        border: 2px solid transparent;
        border-radius: 999px;
        background: #cbd5e1;
        background-clip: padding-box;
    }

    .fd-expense-drawer-body::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
        background-clip: padding-box;
    }

    .fd-expense-form-grid {
        display: grid;
        gap: 14px;
    }

    .fd-expense-field label {
        margin-bottom: 6px;
        display: block;
        color: #4c5c72;
        font-size: 9.5px;
        font-weight: 700;
    }

    .fd-expense-field input,
    .fd-expense-field textarea,
    .fd-expense-field select {
        width: 100%;
        min-height: 44px;
        padding: 10px 12px;
        border: 1px solid #dfe6ef;
        border-radius: 8px;
        outline: 0;
        color: #203047;
        background: #fff;
        font: inherit;
        font-size: 11px;
    }

    .fd-expense-field textarea {
        min-height: 90px;
        resize: vertical;
    }

    .fd-expense-field input:focus,
    .fd-expense-field textarea:focus,
    .fd-expense-field select:focus {
        border-color: #b9d78e;
        box-shadow: 0 0 0 3px rgba(116, 184, 36, .10);
    }

    .fd-expense-money-wrap {
        position: relative;
    }

    .fd-expense-money-symbol {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #5f6f84;
        font-size: 12px;
        font-weight: 700;
        pointer-events: none;
    }

    .fd-expense-money-wrap input {
        padding-left: 30px;
        font-weight: 700;
    }

    .fd-expense-help {
        margin-top: 5px;
        color: #8b97a8;
        font-size: 8.5px;
        line-height: 1.45;
    }

    .fd-expense-receipt-box {
        min-height: 92px;
        padding: 13px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px dashed #cfd8e4;
        border-radius: 9px;
        background: #fbfcfd;
        text-align: center;
    }

    .fd-expense-receipt-input {
        display: none;
    }

    .fd-expense-receipt-name {
        margin-top: 7px;
        color: #6f7f93;
        font-size: 9px;
        word-break: break-word;
    }

    .fd-expense-drawer-footer {
        min-height: 70px;
        padding: 12px 18px;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        flex: 0 0 auto;
        border-top: 1px solid #e8edf3;
        background: #fff;
        box-shadow: 0 -6px 18px rgba(15, 23, 42, .035);
    }

    .fd-expense-delete-backdrop {
        position: fixed;
        inset: 0;
        z-index: 1080;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(0, 17, 49, .34);
    }

    .fd-expense-delete-backdrop.show {
        display: flex;
    }

    .fd-expense-confirm {
        width: min(410px, 100%);
        padding: 20px;
        border-radius: 13px;
        background: #fff;
        box-shadow: 0 24px 65px rgba(0, 17, 49, .22);
    }

    .fd-expense-confirm h3 {
        margin: 0 0 8px;
        color: #17243a;
        font-size: 16px;
    }

    .fd-expense-confirm p {
        margin: 0;
        color: #718096;
        font-size: 10px;
        line-height: 1.55;
    }

    .fd-expense-confirm-actions {
        margin-top: 18px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }

    /* Select2 styled to match the Customers page form controls. */
    .fd-expense-field .select2-container {
        width: 100% !important;
    }

    .fd-expense-field .select2-container .select2-selection--single {
        height: 44px;
        border: 1px solid #dfe6ef;
        border-radius: 8px;
        background: #fff;
        outline: 0;
    }

    .fd-expense-field .select2-container--default.select2-container--focus .select2-selection--single,
    .fd-expense-field .select2-container--default.select2-container--open .select2-selection--single {
        border-color: #b9d78e;
        box-shadow: 0 0 0 3px rgba(116, 184, 36, .10);
    }

    .fd-expense-field .select2-container--default .select2-selection--single .select2-selection__rendered {
        height: 42px;
        padding-left: 12px;
        padding-right: 44px;
        line-height: 42px;
        color: #203047;
        font-size: 11px;
    }

    .fd-expense-field .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 42px;
        right: 6px;
    }

    .fd-expense-field .select2-container--default .select2-selection--single .select2-selection__clear {
        width: 22px;
        height: 22px;
        position: absolute;
        right: 28px;
        top: 10px;
        z-index: 3;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        color: #718096;
        background: #eef2f6;
        font-size: 15px;
        line-height: 1;
        cursor: pointer;
    }

    .select2-dropdown {
        z-index: 1100 !important;
        border-color: #dfe6ef !important;
        border-radius: 8px !important;
        overflow: hidden;
        box-shadow: 0 12px 30px rgba(0, 17, 49, .12);
    }

    .select2-search--dropdown {
        padding: 8px;
    }

    .select2-search--dropdown .select2-search__field {
        height: 36px;
        border: 1px solid #dfe6ef !important;
        border-radius: 7px;
        outline: 0;
        font-size: 11px;
    }

    .select2-results__option {
        padding: 9px 11px;
        font-size: 10.5px;
    }

    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background: #68aa1d !important;
    }

    .fd-expense-toast {
        min-width: 270px;
        max-width: min(390px, calc(100vw - 28px));
        min-height: 44px;
        padding: 10px 12px;
        position: fixed;
        right: 18px;
        bottom: 18px;
        z-index: 1200;
        display: none;
        align-items: center;
        gap: 9px;
        border: 1px solid #dfe6ef;
        border-radius: 9px;
        background: #fff;
        box-shadow: 0 16px 40px rgba(0, 17, 49, .16);
        color: #34445b;
        font-size: 10px;
    }

    .fd-expense-toast.show {
        display: flex;
    }

    .fd-expense-toast.success {
        border-left: 4px solid #68aa1d;
    }

    .fd-expense-toast.error {
        border-left: 4px solid #dc2626;
    }

    .fd-expense-toast.warning {
        border-left: 4px solid #f59e0b;
    }

    .fd-expense-toast i {
        font-size: 15px;
    }

    .fd-expense-toast.success i {
        color: #68aa1d;
    }

    .fd-expense-toast.error i {
        color: #dc2626;
    }

    .fd-expense-toast.warning i {
        color: #f59e0b;
    }

    @media(max-width:767.98px) {
        .fd-expense-header {
            flex-direction: column;
        }

        .fd-expense-header .fd-teams-actions {
            width: 100%;
            justify-content: flex-start;
            margin-left: 0;
        }

        .fd-expense-search {
            width: 100%;
        }

        .fd-expense-filter {
            flex: 1 1 145px;
        }

        .fd-expense-toolbar-spacer {
            display: none;
        }

        .fd-expense-drawer {
            width: 100%;
        }
    }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/includes/nav.php'; ?>
    <div class="fieldplx-main-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="fieldplx-main-content">
            <div class="fieldplx-content-wrapper">
                <div class="fd-dashboard">

                    <section class="fd-teams-header fd-expense-header">
                        <div>
                            <h1 class="fd-teams-title">Expenses</h1>
                            <p class="fd-teams-subtitle">Track business expenses, attach receipts, link costs to jobs,
                                assign accounting codes and manage reimbursements.</p>
                        </div>
                        <div class="fd-teams-actions">
                            <button type="button" class="fd-team-button primary" id="addExpenseButton"><i
                                    class="bi bi-plus-circle"></i> Add Expense</button>
                        </div>
                    </section>

                    <?php if ($expenseSchemaError !== ''): ?>
                    <div class="alert alert-danger py-2 px-3" style="font-size:11px;">Expense database setup error:
                        <?= htmlspecialchars($expenseSchemaError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <section class="row g-3 fd-customer-summary fd-expense-summary">
                        <div class="col-xl-3 col-md-6">
                            <article class="fd-customer-summary-card">
                                <h2 class="fd-customer-summary-title">Overview</h2>
                                <div class="fd-customer-overview-list">
                                    <div class="fd-customer-overview-row"><span
                                            class="fd-customer-overview-dot draft"></span><span>Draft</span><strong
                                            id="expenseStatDraft"><?= (int)$expenseStats['draft_count'] ?></strong>
                                    </div>
                                    <div class="fd-customer-overview-row"><span
                                            class="fd-customer-overview-dot submitted"></span><span>Submitted</span><strong
                                            id="expenseStatSubmitted"><?= (int)$expenseStats['submitted_count'] ?></strong>
                                    </div>
                                    <div class="fd-customer-overview-row"><span
                                            class="fd-customer-overview-dot approved"></span><span>Approved</span><strong
                                            id="expenseStatApproved"><?= (int)$expenseStats['approved_count'] ?></strong>
                                    </div>
                                    <div class="fd-customer-overview-row"><span
                                            class="fd-customer-overview-dot rejected"></span><span>Rejected</span><strong
                                            id="expenseStatRejected"><?= (int)$expenseStats['rejected_count'] ?></strong>
                                    </div>
                                </div>
                            </article>
                        </div>

                        <div class="col-xl-3 col-md-6">
                            <article class="fd-customer-summary-card">
                                <span class="fd-customer-summary-arrow"><i class="bi bi-arrow-up-right"></i></span>
                                <h2 class="fd-customer-summary-title">Total Expenses</h2>
                                <span class="fd-customer-summary-period">All recorded expenses</span>
                                <div class="fd-customer-summary-number-row">
                                    <strong class="fd-customer-summary-value"
                                        id="expenseStatTotalAmount"><?= htmlspecialchars($expenseCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$expenseStats['amount_total'], 2) ?></strong>
                                </div>
                                <span class="fd-customer-summary-subvalue"
                                    id="expenseStatTotalCount"><?= (int)$expenseStats['count_total'] ?>
                                    expense<?= (int)$expenseStats['count_total'] === 1 ? '' : 's' ?></span>
                            </article>
                        </div>

                        <div class="col-xl-3 col-md-6">
                            <article class="fd-customer-summary-card">
                                <span class="fd-customer-summary-arrow"><i class="bi bi-arrow-up-right"></i></span>
                                <h2 class="fd-customer-summary-title">This Month</h2>
                                <span
                                    class="fd-customer-summary-period"><?= htmlspecialchars($expenseCurrentMonthLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="fd-customer-summary-number-row">
                                    <strong class="fd-customer-summary-value"
                                        id="expenseStatMonthAmount"><?= htmlspecialchars($expenseCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$expenseStats['month_total'], 2) ?></strong>
                                    <span
                                        class="fd-customer-trend-change<?= htmlspecialchars($expenseMonthTrendClass, ENT_QUOTES, 'UTF-8') ?>"
                                        id="expenseMonthTrend" tabindex="0">
                                        <span
                                            id="expenseMonthChangeText"><?= htmlspecialchars($expenseMonthTrendArrow . rtrim(rtrim(number_format(abs($expenseMonthChange), 1, '.', ''), '0'), '.') . '%', ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="fd-customer-trend-tooltip">
                                            <span class="fd-customer-tooltip-title">Expense total</span>
                                            <span
                                                class="fd-customer-tooltip-row"><span><?= htmlspecialchars($expensePriorMonthLabel, ENT_QUOTES, 'UTF-8') ?></span><strong
                                                    id="expenseStatPriorMonth"><?= htmlspecialchars($expenseCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$expenseStats['prior_month_total'], 2) ?></strong></span>
                                            <span
                                                class="fd-customer-tooltip-row"><span><?= htmlspecialchars($expenseCurrentMonthLabel, ENT_QUOTES, 'UTF-8') ?></span><strong
                                                    id="expenseStatCurrentMonth"><?= htmlspecialchars($expenseCurrencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$expenseStats['month_total'], 2) ?></strong></span>
                                        </span>
                                    </span>
                                </div>
                            </article>
                        </div>

                        <div class="col-xl-3 col-md-6">
                            <article class="fd-customer-summary-card">
                                <span class="fd-customer-summary-arrow"><i class="bi bi-arrow-up-right"></i></span>
                                <h2 class="fd-customer-summary-title">Job Linked</h2>
                                <span class="fd-customer-summary-period">Expenses connected to jobs</span>
                                <div class="fd-customer-summary-number-row">
                                    <strong class="fd-customer-summary-value"
                                        id="expenseStatJobLinked"><?= (int)$expenseStats['job_linked'] ?></strong>
                                </div>
                                <span class="fd-customer-summary-subvalue"><strong
                                        id="expenseStatReimbursable"><?= (int)$expenseStats['reimbursable'] ?></strong>
                                    reimbursable · <strong
                                        id="expenseStatReceipts"><?= (int)$expenseStats['with_receipt'] ?></strong> with
                                    receipt</span>
                            </article>
                        </div>
                    </section>

                    <section class="fd-card fd-teams-card">
                        <div class="fd-expense-toolbar">
                            <div class="fd-expense-search"><i class="bi bi-search"></i><input type="search"
                                    id="expenseSearch" placeholder="Search expense, item, job or accounting code"
                                    autocomplete="off"></div>
                            <select class="fd-expense-filter" id="expenseStatusFilter">
                                <option value="">All Status</option>
                                <option value="draft">Draft</option>
                                <option value="submitted">Submitted</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                            </select>
                            <select class="fd-expense-filter" id="expenseJobFilter">
                                <option value="">All Jobs</option>
                            </select>
                            <input class="fd-expense-filter" type="date" id="expenseDateFrom" title="From date">
                            <input class="fd-expense-filter" type="date" id="expenseDateTo" title="To date">
                            <div class="fd-expense-toolbar-spacer"></div>
                            <button type="button" class="fd-team-button" id="clearExpenseFilters"><i
                                    class="bi bi-x-circle"></i> Clear</button>
                        </div>
                        <div class="fd-team-table-wrap">
                            <table class="fd-team-table fd-expense-table">
                                <thead>
                                    <tr>
                                        <th>S/No</th>
                                        <th>Expense</th>
                                        <th>Date</th>
                                        <th>Job</th>
                                        <th>Accounting Code</th>
                                        <th>Reimburse To</th>
                                        <th>Total</th>
                                        <th>Receipt</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="expensesTableBody">
                                    <tr>
                                        <td colspan="10" class="fd-expense-empty">Loading expenses...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="fd-expense-pagination">
                            <span id="expensesCountText">Showing 0 expenses</span>
                            <div class="fd-expense-pagination-actions">
                                <button type="button" class="fd-team-button" id="expensePrevPage"><i
                                        class="bi bi-chevron-left"></i></button>
                                <button type="button" class="fd-team-button" id="expenseNextPage"><i
                                        class="bi bi-chevron-right"></i></button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </div>

    <div class="fd-expense-backdrop" id="expenseBackdrop"></div>
    <aside class="fd-expense-drawer" id="expenseDrawer" aria-hidden="true">
        <div class="fd-expense-drawer-header">
            <div>
                <h2 class="fd-expense-drawer-title" id="expenseDrawerTitle">New expense</h2>
                <div class="fd-expense-drawer-subtitle">Record a business expense and optionally connect it to a job.
                </div>
            </div>
            <button type="button" class="fd-expense-drawer-close" id="expenseDrawerClose"
                aria-label="Cancel and close"><i class="bi bi-x-lg"></i></button>
        </div>

        <form id="expenseForm" enctype="multipart/form-data">
            <div class="fd-expense-drawer-body">
                <input type="hidden" name="expense_id" id="expenseId" value="0">
                <div class="fd-expense-form-grid">
                    <div class="fd-expense-field">
                        <label for="expenseDate">Date</label>
                        <input type="date" id="expenseDate" name="expense_date" required>
                    </div>
                    <div class="fd-expense-field">
                        <label for="expenseItemName">Item name</label>
                        <input type="text" id="expenseItemName" name="item_name" maxlength="190" placeholder="Item name"
                            required>
                    </div>
                    <div class="fd-expense-field">
                        <label for="expenseDescription">Description</label>
                        <textarea id="expenseDescription" name="description" maxlength="5000"
                            placeholder="Description"></textarea>
                    </div>
                    <div class="fd-expense-field">
                        <label for="expenseAmount">Total</label>
                        <div class="fd-expense-money-wrap"><span
                                class="fd-expense-money-symbol"><?= htmlspecialchars($expenseCurrencySymbol, ENT_QUOTES, 'UTF-8') ?></span><input
                                type="number" min="0.01" step="0.01" id="expenseAmount" name="amount" placeholder="0.00"
                                required></div>
                    </div>
                    <div class="fd-expense-field">
                        <label for="expenseJob">Job</label>
                        <select id="expenseJob" name="job_id">
                            <option value=""></option>
                        </select>
                        <div class="fd-expense-help">Search and select a FieldPlx job. Leave blank for a general
                            business expense.</div>
                    </div>
                    <div class="fd-expense-field">
                        <label for="expenseAccountingCode">Accounting code</label>
                        <select id="expenseAccountingCode" name="accounting_code">
                            <option value=""></option>
                        </select>
                        <div class="fd-expense-help">Select an existing accounting code, or type a new code and press
                            Enter. Use the × clear symbol to cancel the selected code.</div>
                    </div>
                    <div class="fd-expense-field">
                        <label for="expenseReimburseUser">Reimburse to</label>
                        <select id="expenseReimburseUser" name="reimburse_user_id">
                            <option value="">Not reimbursable</option>
                        </select>
                    </div>
                    <div class="fd-expense-field">
                        <label for="expenseStatus">Status</label>
                        <select id="expenseStatus" name="status">
                            <option value="submitted">Submitted</option>
                            <option value="draft">Draft</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                    <div class="fd-expense-field">
                        <label>Receipt</label>
                        <div class="fd-expense-receipt-box">
                            <div>
                                <label class="fd-team-button" for="expenseReceipt" style="margin:0;cursor:pointer;"><i
                                        class="bi bi-paperclip"></i> Add receipt</label>
                                <input class="fd-expense-receipt-input" type="file" id="expenseReceipt" name="receipt"
                                    accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp">
                                <div class="fd-expense-receipt-name" id="expenseReceiptName">PDF, JPG, PNG or WEBP · Max
                                    10 MB</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="fd-expense-drawer-footer">
                <button type="button" class="fd-team-button" id="expenseCancelButton"><i class="bi bi-x-lg"></i>
                    Cancel</button>
                <button type="submit" class="fd-team-button primary" id="expenseSaveButton"><span
                        class="fd-team-loader"></span><i class="bi bi-check-lg"></i><span id="expenseSaveText">Save
                        Expense</span></button>
            </div>
        </form>
    </aside>

    <div class="fd-expense-delete-backdrop" id="expenseDeleteBackdrop">
        <div class="fd-expense-confirm">
            <h3>Delete Expense</h3>
            <p id="expenseDeleteMessage">Delete this expense? The record will be soft-deleted so audit history remains
                available.</p>
            <div class="fd-expense-confirm-actions">
                <button type="button" class="fd-team-button" id="expenseDeleteCancel">Cancel</button>
                <button type="button" class="fd-team-button danger" id="expenseDeleteConfirm"><span
                        class="fd-team-loader"></span><i class="bi bi-trash"></i> Delete</button>
            </div>
        </div>
    </div>

    <div class="fd-expense-toast" id="expenseToast"><i class="bi bi-info-circle"></i><span
            id="expenseToastMessage">Notification</span></div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function() {
        'use strict';
        var csrfToken = <?= json_encode($expensesCsrfToken) ?>;
        var currencySymbol = <?= json_encode($expenseCurrencySymbol) ?>;
        var state = {
            page: 1,
            perPage: 10,
            search: '',
            status: '',
            jobId: '',
            dateFrom: '',
            dateTo: '',
            deleteId: 0,
            meta: {
                jobs: [],
                users: [],
                accounting_codes: []
            }
        };
        var body = document.getElementById('expensesTableBody');
        var drawer = document.getElementById('expenseDrawer');
        var backdrop = document.getElementById('expenseBackdrop');
        var form = document.getElementById('expenseForm');
        var saveButton = document.getElementById('expenseSaveButton');
        var deleteBackdrop = document.getElementById('expenseDeleteBackdrop');
        var toast = document.getElementById('expenseToast');
        var toastTimer = null,
            searchTimer = null;

        function esc(v) {
            return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function money(v) {
            var n = Number(v || 0);
            return esc(currencySymbol) + n.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function fmtDate(v) {
            if (!v) return '-';
            var d = new Date(String(v) + 'T00:00:00');
            return isNaN(d.getTime()) ? esc(v) : d.toLocaleDateString(undefined, {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        }

        function toastShow(type, msg) {
            if (toastTimer) clearTimeout(toastTimer);
            toast.className = 'fd-expense-toast ' + (type || '') + ' show';
            var icon = toast.querySelector('i');
            icon.className = 'bi ' + (type === 'success' ? 'bi-check-circle' : type === 'error' ?
                'bi-exclamation-circle' : type === 'warning' ? 'bi-exclamation-triangle' : 'bi-info-circle');
            document.getElementById('expenseToastMessage').textContent = msg || 'Notification';
            toastTimer = setTimeout(function() {
                toast.classList.remove('show');
            }, 3200);
        }

        function loading(button, on) {
            if (!button) return;
            button.disabled = !!on;
            button.classList.toggle('loading', !!on);
        }

        function parseResponse(r) {
            return r.text().then(function(raw) {
                var d, t = (raw || '').trim();
                try {
                    d = t ? JSON.parse(t) : {};
                } catch (e) {
                    var clean = t.replace(/<br\s*\/?>/gi, ' ').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ')
                        .trim();
                    throw new Error(clean ? 'Server error: ' + clean :
                        'Server returned an invalid response.');
                }
                if (!r.ok || !d.success) throw new Error(d.message || 'Request failed.');
                return d;
            });
        }

        function request(fd) {
            fd.append('csrf_token', csrfToken);
            return fetch('expenses.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            }).then(parseResponse);
        }

        function userName(u) {
            return ((u.first_name || '') + (u.last_name ? ' ' + u.last_name : '')).trim() || u.email || ('User #' +
                u.id);
        }

        function jobName(j) {
            return (j.job_no ? j.job_no + ' · ' : '') + (j.title || 'Job');
        }

        function initSelect2() {
            if (!window.jQuery || !jQuery.fn || !jQuery.fn.select2) return;
            var $job = jQuery('#expenseJob');
            var $code = jQuery('#expenseAccountingCode');
            if ($job.hasClass('select2-hidden-accessible')) $job.select2('destroy');
            if ($code.hasClass('select2-hidden-accessible')) $code.select2('destroy');
            $job.select2({
                width: '100%',
                placeholder: 'Search jobs',
                allowClear: true,
                dropdownParent: jQuery('#expenseDrawer')
            });
            $code.select2({
                width: '100%',
                placeholder: 'Accounting code',
                allowClear: true,
                tags: true,
                dropdownParent: jQuery('#expenseDrawer'),
                createTag: function(params) {
                    var term = jQuery.trim(params.term);
                    if (term === '') return null;
                    return {
                        id: term,
                        text: term,
                        newTag: true
                    };
                }
            });
        }

        function applyMeta(meta) {
            state.meta = meta || state.meta;
            var jobs = state.meta.jobs || [],
                users = state.meta.users || [],
                codes = state.meta.accounting_codes || [];
            var jobOptions = '<option value=""></option>',
                filterOptions = '<option value="">All Jobs</option>';
            jobs.forEach(function(j) {
                jobOptions += '<option value="' + Number(j.id) + '">' + esc(jobName(j)) + '</option>';
                filterOptions += '<option value="' + Number(j.id) + '">' + esc(jobName(j)) + '</option>';
            });
            document.getElementById('expenseJob').innerHTML = jobOptions;
            document.getElementById('expenseJobFilter').innerHTML = filterOptions;
            var userOptions = '<option value="">Not reimbursable</option>';
            users.forEach(function(u) {
                userOptions += '<option value="' + Number(u.id) + '">' + esc(userName(u)) + '</option>';
            });
            document.getElementById('expenseReimburseUser').innerHTML = userOptions;
            var codeOptions = '<option value=""></option>';
            codes.forEach(function(c) {
                codeOptions += '<option value="' + esc(c) + '">' + esc(c) + '</option>';
            });
            document.getElementById('expenseAccountingCode').innerHTML = codeOptions;
            initSelect2();
        }

        function applyStats(stats) {
            stats = stats || {};

            function text(id, value) {
                var el = document.getElementById(id);
                if (el) el.textContent = value;
            }

            function html(id, value) {
                var el = document.getElementById(id);
                if (el) el.innerHTML = value;
            }
            text('expenseStatDraft', Number(stats.draft_count || 0));
            text('expenseStatSubmitted', Number(stats.submitted_count || 0));
            text('expenseStatApproved', Number(stats.approved_count || 0));
            text('expenseStatRejected', Number(stats.rejected_count || 0));
            html('expenseStatTotalAmount', money(stats.amount_total));
            var totalCount = Number(stats.count_total || 0);
            text('expenseStatTotalCount', totalCount + ' expense' + (totalCount === 1 ? '' : 's'));
            html('expenseStatMonthAmount', money(stats.month_total));
            html('expenseStatPriorMonth', money(stats.prior_month_total));
            html('expenseStatCurrentMonth', money(stats.month_total));
            text('expenseStatJobLinked', Number(stats.job_linked || 0));
            text('expenseStatReimbursable', Number(stats.reimbursable || 0));
            text('expenseStatReceipts', Number(stats.with_receipt || 0));

            var change = Number(stats.month_change || 0),
                trend = document.getElementById('expenseMonthTrend');
            if (trend) {
                trend.className = 'fd-customer-trend-change' + (change > 0 ? '' : change < 0 ? ' down' :
                ' neutral');
            }
            var arrow = change > 0 ? '↑ ' : change < 0 ? '↓ ' : '';
            var rounded = Math.round(Math.abs(change) * 10) / 10;
            text('expenseMonthChangeText', arrow + String(rounded).replace(/\.0$/, '') + '%');
        }

        function render(rows) {
            if (!rows.length) {
                body.innerHTML =
                    '<tr><td colspan="10" class="fd-expense-empty"><i class="bi bi-receipt" style="display:block;font-size:26px;margin-bottom:8px;color:#b9c4d1"></i>No expenses found.</td></tr>';
                return;
            }
            var h = '';
            rows.forEach(function(r, i) {
                var id = Number(r.id || 0),
                    serial = ((state.page - 1) * state.perPage + i + 1);
                var receipt = r.receipt_path ? '<a class="fd-expense-receipt" href="' + esc(r
                    .receipt_path) +
                    '" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> View</a>' : '-';
                h += '<tr>' +
                    '<td>' + serial + '</td>' +
                    '<td><div class="fd-expense-item"><strong>' + esc(r.expense_no || ('EXP-' + id)) +
                    ' · ' + esc(r.item_name || '-') + '</strong><small>' + esc(r.notes ||
                    'No description') + '</small></div></td>' +
                    '<td>' + fmtDate(r.expense_date) + '</td>' +
                    '<td>' + esc(r.job_no ? ((r.job_no || '') + (r.job_title ? ' · ' + r.job_title : '')) :
                        'General expense') + '</td>' +
                    '<td>' + esc(r.accounting_code || '-') + '</td>' +
                    '<td>' + esc(r.reimburse_user_name || 'Not reimbursable') + '</td>' +
                    '<td class="fd-expense-amount">' + money(r.amount) + '</td>' +
                    '<td>' + receipt + '</td>' +
                    '<td><span class="fd-expense-badge ' + esc(r.status || 'submitted') + '">' + esc(r
                        .status || 'submitted') + '</span></td>' +
                    '<td><div style="display:flex;gap:5px;"><button type="button" class="fd-expense-action" data-action="edit" data-id="' +
                    id +
                    '" title="Edit"><i class="bi bi-pencil"></i></button><button type="button" class="fd-expense-action danger" data-action="delete" data-id="' +
                    id + '" title="Delete"><i class="bi bi-trash"></i></button></div></td>' +
                    '</tr>';
            });
            body.innerHTML = h;
        }

        function load() {
            var fd = new FormData();
            fd.append('expense_action', 'list');
            fd.append('page', state.page);
            fd.append('per_page', state.perPage);
            fd.append('search', state.search);
            fd.append('status', state.status);
            fd.append('job_id', state.jobId);
            fd.append('date_from', state.dateFrom);
            fd.append('date_to', state.dateTo);
            body.innerHTML = '<tr><td colspan="10" class="fd-expense-empty">Loading expenses...</td></tr>';
            request(fd).then(function(d) {
                render(d.expenses || []);
                applyMeta(d.meta || {});
                applyStats(d.stats || {});
                var p = d.pagination || {};
                document.getElementById('expensesCountText').textContent = 'Showing ' + Number(p.from ||
                    0) + '-' + Number(p.to || 0) + ' of ' + Number(p.total || 0) + ' expenses';
                document.getElementById('expensePrevPage').disabled = state.page <= 1;
                document.getElementById('expenseNextPage').disabled = state.page >= Number(p.pages || 1);
            }).catch(function(e) {
                body.innerHTML = '<tr><td colspan="10" class="fd-expense-empty">' + esc(e.message) +
                    '</td></tr>';
                toastShow('error', e.message);
            });
        }

        function resetForm() {
            form.reset();
            document.getElementById('expenseId').value = '0';
            document.getElementById('expenseDate').value = new Date().toISOString().slice(0, 10);
            document.getElementById('expenseStatus').value = 'submitted';
            document.getElementById('expenseReceiptName').textContent = 'PDF, JPG, PNG or WEBP · Max 10 MB';
            if (window.jQuery && jQuery.fn && jQuery.fn.select2) {
                jQuery('#expenseJob').val(null).trigger('change');
                jQuery('#expenseAccountingCode').val(null).trigger('change');
            }
        }

        function openDrawer() {
            backdrop.classList.add('show');
            drawer.classList.add('show');
            drawer.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            var drawerBody = drawer.querySelector('.fd-expense-drawer-body');
            if (drawerBody) drawerBody.scrollTop = 0;
        }

        function closeDrawer() {
            drawer.classList.remove('show');
            backdrop.classList.remove('show');
            drawer.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        function newExpense() {
            resetForm();
            document.getElementById('expenseDrawerTitle').textContent = 'New expense';
            document.getElementById('expenseSaveText').textContent = 'Save Expense';
            openDrawer();
        }

        function editExpense(id) {
            resetForm();
            openDrawer();
            document.getElementById('expenseDrawerTitle').textContent = 'Edit expense';
            document.getElementById('expenseSaveText').textContent = 'Update Expense';
            var fd = new FormData();
            fd.append('expense_action', 'get');
            fd.append('expense_id', id);
            request(fd).then(function(d) {
                applyMeta(d.meta || {});
                var r = d.expense || {};
                document.getElementById('expenseId').value = r.id || 0;
                document.getElementById('expenseDate').value = r.expense_date || '';
                document.getElementById('expenseItemName').value = r.item_name || '';
                document.getElementById('expenseDescription').value = r.notes || '';
                document.getElementById('expenseAmount').value = r.amount || '';
                document.getElementById('expenseReimburseUser').value = r.reimburse_user_id || '';
                document.getElementById('expenseStatus').value = r.status || 'submitted';
                document.getElementById('expenseReceiptName').textContent = r.receipt_name ? ('Current: ' +
                    r.receipt_name) : 'PDF, JPG, PNG or WEBP · Max 10 MB';
                if (window.jQuery && jQuery.fn && jQuery.fn.select2) {
                    jQuery('#expenseJob').val(r.job_id ? String(r.job_id) : null).trigger('change');
                    var code = r.accounting_code || '';
                    if (code && jQuery('#expenseAccountingCode option[value="' + CSS.escape(code) + '"]')
                        .length === 0) {
                        var opt = new Option(code, code, true, true);
                        jQuery('#expenseAccountingCode').append(opt);
                    }
                    jQuery('#expenseAccountingCode').val(code || null).trigger('change');
                }
            }).catch(function(e) {
                closeDrawer();
                toastShow('error', e.message);
            });
        }

        document.getElementById('addExpenseButton').onclick = newExpense;
        document.getElementById('expenseDrawerClose').onclick = closeDrawer;
        document.getElementById('expenseCancelButton').onclick = closeDrawer;
        backdrop.onclick = closeDrawer;
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (deleteBackdrop.classList.contains('show')) deleteBackdrop.classList.remove('show');
                else if (drawer.classList.contains('show')) closeDrawer();
            }
        });

        document.getElementById('expenseReceipt').onchange = function() {
            var f = this.files && this.files[0] ? this.files[0] : null;
            document.getElementById('expenseReceiptName').textContent = f ? f.name :
                'PDF, JPG, PNG or WEBP · Max 10 MB';
        };

        form.onsubmit = function(e) {
            e.preventDefault();
            if (!form.reportValidity()) {
                toastShow('warning', 'Complete the required expense fields.');
                return;
            }
            var fd = new FormData(form);
            fd.append('expense_action', 'save');
            loading(saveButton, true);
            request(fd).then(function(d) {
                closeDrawer();
                toastShow('success', d.message);
                setTimeout(function() {
                    window.location.reload();
                }, 350);
            }).catch(function(err) {
                toastShow('error', err.message);
            }).finally(function() {
                loading(saveButton, false);
            });
        };

        body.onclick = function(e) {
            var b = e.target.closest('[data-action]');
            if (!b || !body.contains(b)) return;
            var id = Number(b.dataset.id || 0),
                a = b.dataset.action;
            if (a === 'edit') editExpense(id);
            if (a === 'delete') {
                state.deleteId = id;
                var row = b.closest('tr');
                var label = row ? row.querySelector('.fd-expense-item strong') : null;
                document.getElementById('expenseDeleteMessage').textContent = 'Delete ' + (label ? ('"' + label
                        .textContent + '"') : 'this expense') +
                    '? The expense will be soft-deleted and audit history will remain preserved.';
                deleteBackdrop.classList.add('show');
            }
        };
        document.getElementById('expenseDeleteCancel').onclick = function() {
            state.deleteId = 0;
            deleteBackdrop.classList.remove('show');
        };
        document.getElementById('expenseDeleteConfirm').onclick = function() {
            if (state.deleteId <= 0) return;
            var button = this,
                fd = new FormData();
            fd.append('expense_action', 'delete');
            fd.append('expense_id', state.deleteId);
            loading(button, true);
            request(fd).then(function(d) {
                state.deleteId = 0;
                deleteBackdrop.classList.remove('show');
                toastShow('success', d.message);
                setTimeout(function() {
                    window.location.reload();
                }, 300);
            }).catch(function(e) {
                toastShow('error', e.message);
            }).finally(function() {
                loading(button, false);
            });
        };

        document.getElementById('expenseSearch').oninput = function(e) {
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(function() {
                state.search = e.target.value.trim();
                state.page = 1;
                load();
            }, 250);
        };
        document.getElementById('expenseStatusFilter').onchange = function(e) {
            state.status = e.target.value;
            state.page = 1;
            load();
        };
        document.getElementById('expenseJobFilter').onchange = function(e) {
            state.jobId = e.target.value;
            state.page = 1;
            load();
        };
        document.getElementById('expenseDateFrom').onchange = function(e) {
            state.dateFrom = e.target.value;
            state.page = 1;
            load();
        };
        document.getElementById('expenseDateTo').onchange = function(e) {
            state.dateTo = e.target.value;
            state.page = 1;
            load();
        };
        document.getElementById('clearExpenseFilters').onclick = function() {
            document.getElementById('expenseSearch').value = '';
            document.getElementById('expenseStatusFilter').value = '';
            document.getElementById('expenseJobFilter').value = '';
            document.getElementById('expenseDateFrom').value = '';
            document.getElementById('expenseDateTo').value = '';
            state.search = '';
            state.status = '';
            state.jobId = '';
            state.dateFrom = '';
            state.dateTo = '';
            state.page = 1;
            load();
        };
        document.getElementById('expensePrevPage').onclick = function() {
            if (state.page > 1) {
                state.page--;
                load();
            }
        };
        document.getElementById('expenseNextPage').onclick = function() {
            state.page++;
            load();
        };

        load();
    })();
    </script>
</body>

</html>