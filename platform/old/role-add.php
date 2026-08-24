<?php
/**
 * FieldPlx Platform - Add Tenant Role
 *
 * File:
 * platform/role-add.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 * - platform_users authentication
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin'
));

$pageTitle = 'Add Tenant Role - FieldPlx';
$activePage = 'role-add';
$basePath = '';

@set_time_limit(30);

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('roleAddEscape')) {
    function roleAddEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('roleAddPost')) {
    function roleAddPost($key, $default = '')
    {
        if (
            !isset($_POST[$key]) ||
            is_array($_POST[$key])
        ) {
            return $default;
        }

        return trim((string) $_POST[$key]);
    }
}

if (!function_exists('roleAddTableExists')) {
    function roleAddTableExists(mysqli $conn, $tableName)
    {
        static $cache = array();

        $tableName = trim((string) $tableName);

        if ($tableName === '') {
            return false;
        }

        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        if (!$stmt) {
            $cache[$tableName] = false;
            return false;
        }

        $stmt->bind_param('s', $tableName);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        $cache[$tableName] = !empty($row['total']);

        return $cache[$tableName];
    }
}

if (!function_exists('roleAddColumns')) {
    function roleAddColumns(mysqli $conn, $tableName)
    {
        static $cache = array();

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $cache[$tableName] = array();

        $safeTable = str_replace('`', '``', $tableName);

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$safeTable}`"
        );

        while ($row = $result->fetch_assoc()) {
            if (!empty($row['Field'])) {
                $cache[$tableName][
                    (string) $row['Field']
                ] = $row;
            }
        }

        $result->free();

        return $cache[$tableName];
    }
}

if (!function_exists('roleAddFirstColumn')) {
    function roleAddFirstColumn(
        array $columns,
        array $candidates
    ) {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('roleAddBind')) {
    function roleAddBind(
        mysqli_stmt $stmt,
        $types,
        array &$values
    ) {
        if ($types === '') {
            return true;
        }

        $arguments = array($types);

        foreach ($values as $key => $value) {
            $arguments[] = &$values[$key];
        }

        return call_user_func_array(
            array($stmt, 'bind_param'),
            $arguments
        );
    }
}

if (!function_exists('roleAddCode')) {
    function roleAddCode($value)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = trim($value, '_');

        return $value;
    }
}

if (!function_exists('roleAddEnumValues')) {
    function roleAddEnumValues($columnType)
    {
        $values = array();

        if (stripos((string) $columnType, 'enum(') !== 0) {
            return $values;
        }

        preg_match_all(
            "/'((?:[^'\\\\]|\\\\.)*)'/",
            (string) $columnType,
            $matches
        );

        if (!empty($matches[1])) {
            foreach ($matches[1] as $value) {
                $values[] = stripcslashes($value);
            }
        }

        return $values;
    }
}

/*
|--------------------------------------------------------------------------
| Verify tables
|--------------------------------------------------------------------------
*/

if (!roleAddTableExists($conn, 'roles')) {
    http_response_code(500);
    exit('The roles table does not exist.');
}

if (!roleAddTableExists($conn, 'tenants')) {
    http_response_code(500);
    exit('The tenants table does not exist.');
}

$roleColumns = roleAddColumns($conn, 'roles');
$tenantColumns = roleAddColumns($conn, 'tenants');

/*
|--------------------------------------------------------------------------
| Detect role columns
|--------------------------------------------------------------------------
*/

$roleIdColumn = roleAddFirstColumn(
    $roleColumns,
    array('id', 'role_id')
);

$roleTenantColumn = roleAddFirstColumn(
    $roleColumns,
    array('tenant_id')
);

$roleNameColumn = roleAddFirstColumn(
    $roleColumns,
    array('name', 'role_name')
);

$roleCodeColumn = roleAddFirstColumn(
    $roleColumns,
    array('code', 'role_code')
);

$roleDescriptionColumn = roleAddFirstColumn(
    $roleColumns,
    array('description', 'notes', 'remarks')
);

$roleStatusColumn = roleAddFirstColumn(
    $roleColumns,
    array('status')
);

$roleSystemColumn = roleAddFirstColumn(
    $roleColumns,
    array('is_system', 'system_role', 'is_default')
);

$roleCreatedAtColumn = roleAddFirstColumn(
    $roleColumns,
    array('created_at')
);

$roleUpdatedAtColumn = roleAddFirstColumn(
    $roleColumns,
    array('updated_at')
);

if (
    $roleIdColumn === '' ||
    $roleNameColumn === ''
) {
    http_response_code(500);
    exit('The roles table requires id and name columns.');
}

/*
|--------------------------------------------------------------------------
| Detect tenant columns
|--------------------------------------------------------------------------
*/

$tenantIdColumn = roleAddFirstColumn(
    $tenantColumns,
    array('id', 'tenant_id')
);

$tenantNameColumn = roleAddFirstColumn(
    $tenantColumns,
    array(
        'company_name',
        'business_name',
        'tenant_name',
        'name'
    )
);

$tenantCodeColumn = roleAddFirstColumn(
    $tenantColumns,
    array(
        'tenant_code',
        'code',
        'business_code'
    )
);

$tenantDeletedColumn = roleAddFirstColumn(
    $tenantColumns,
    array('deleted_at')
);

if (
    $tenantIdColumn === '' ||
    $tenantNameColumn === ''
) {
    http_response_code(500);
    exit('The tenants table requires id and name columns.');
}

/*
|--------------------------------------------------------------------------
| Load tenants
|--------------------------------------------------------------------------
*/

$tenantList = array();

$tenantSql = "
    SELECT
        `{$tenantIdColumn}` AS tenant_id,
        `{$tenantNameColumn}` AS tenant_name
";

if ($tenantCodeColumn !== '') {
    $tenantSql .= ",
        `{$tenantCodeColumn}` AS tenant_code
    ";
} else {
    $tenantSql .= ",
        '' AS tenant_code
    ";
}

$tenantSql .= "
    FROM tenants
";

if ($tenantDeletedColumn !== '') {
    $tenantSql .= "
        WHERE `{$tenantDeletedColumn}` IS NULL
    ";
}

$tenantSql .= "
    ORDER BY `{$tenantNameColumn}` ASC
";

$result = $conn->query($tenantSql);

while ($row = $result->fetch_assoc()) {
    $tenantList[] = $row;
}

$result->free();

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$errorMessage = '';

$tenantId = isset($_POST['tenant_id'])
    ? (int) $_POST['tenant_id']
    : (
        isset($_GET['tenant_id'])
            ? (int) $_GET['tenant_id']
            : 0
    );

$roleName = roleAddPost('role_name');
$roleCode = roleAddPost('role_code');
$description = roleAddPost('description');
$status = strtolower(
    roleAddPost('status', 'active')
);

$isSystem = isset($_POST['is_system'])
    ? 1
    : 0;

$statusOptions = array(
    'active',
    'inactive'
);

if ($roleStatusColumn !== '') {
    $columnType = isset(
        $roleColumns[$roleStatusColumn]['Type']
    )
        ? $roleColumns[$roleStatusColumn]['Type']
        : '';

    $enumValues = roleAddEnumValues($columnType);

    if (!empty($enumValues)) {
        $statusOptions = $enumValues;
    }
}

if (!in_array($status, $statusOptions, true)) {
    $status = in_array('active', $statusOptions, true)
        ? 'active'
        : $statusOptions[0];
}

/*
|--------------------------------------------------------------------------
| Process form
|--------------------------------------------------------------------------
*/

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    $tenantExists = false;

    if ($roleTenantColumn === '') {
        $tenantExists = true;
        $tenantId = 0;
    } else {
        foreach ($tenantList as $tenantRow) {
            if ((int) $tenantRow['tenant_id'] === $tenantId) {
                $tenantExists = true;
                break;
            }
        }
    }

    if (!$tenantExists) {
        $errorMessage = 'Select a valid tenant.';
    } elseif ($roleName === '') {
        $errorMessage = 'Enter the role name.';
    } elseif (strlen($roleName) > 150) {
        $errorMessage =
            'Role name must not exceed 150 characters.';
    } else {
        if ($roleCode === '') {
            $roleCode = roleAddCode($roleName);
        } else {
            $roleCode = roleAddCode($roleCode);
        }

        if (
            $roleCodeColumn !== '' &&
            $roleCode === ''
        ) {
            $errorMessage =
                'Enter a valid role code.';
        }
    }

    if (
        $errorMessage === '' &&
        $roleCodeColumn !== ''
    ) {
        $duplicateSql = "
            SELECT COUNT(*) AS total
            FROM roles
            WHERE LOWER(`{$roleCodeColumn}`) = LOWER(?)
        ";

        $duplicateTypes = 's';
        $duplicateParams = array($roleCode);

        if ($roleTenantColumn !== '') {
            $duplicateSql .= "
                AND `{$roleTenantColumn}` = ?
            ";

            $duplicateTypes .= 'i';
            $duplicateParams[] = $tenantId;
        }

        $duplicateStmt = $conn->prepare(
            $duplicateSql
        );

        roleAddBind(
            $duplicateStmt,
            $duplicateTypes,
            $duplicateParams
        );

        $duplicateStmt->execute();

        $duplicateResult =
            $duplicateStmt->get_result();

        $duplicateRow =
            $duplicateResult->fetch_assoc();

        $duplicateStmt->close();

        if (!empty($duplicateRow['total'])) {
            $errorMessage =
                'This role code already exists for the selected tenant.';
        }
    }

    if ($errorMessage === '') {
        $insertData = array();

        if ($roleTenantColumn !== '') {
            $insertData[$roleTenantColumn] =
                $tenantId;
        }

        $insertData[$roleNameColumn] =
            $roleName;

        if ($roleCodeColumn !== '') {
            $insertData[$roleCodeColumn] =
                $roleCode;
        }

        if ($roleDescriptionColumn !== '') {
            $insertData[$roleDescriptionColumn] =
                $description;
        }

        if ($roleStatusColumn !== '') {
            $insertData[$roleStatusColumn] =
                $status;
        }

        if ($roleSystemColumn !== '') {
            $insertData[$roleSystemColumn] =
                $isSystem;
        }

        $columns = array();
        $placeholders = array();
        $types = '';
        $values = array();

        foreach ($insertData as $column => $value) {
            $columns[] = "`{$column}`";
            $placeholders[] = '?';
            $values[] = $value;

            $columnType = isset(
                $roleColumns[$column]['Type']
            )
                ? strtolower(
                    (string) $roleColumns[$column]['Type']
                )
                : '';

            if (
                preg_match(
                    '/^(tinyint|smallint|mediumint|int|bigint)/',
                    $columnType
                )
            ) {
                $types .= 'i';
            } else {
                $types .= 's';
            }
        }

        if ($roleCreatedAtColumn !== '') {
            $columns[] =
                "`{$roleCreatedAtColumn}`";
            $placeholders[] = 'NOW()';
        }

        if ($roleUpdatedAtColumn !== '') {
            $columns[] =
                "`{$roleUpdatedAtColumn}`";
            $placeholders[] = 'NOW()';
        }

        $insertSql = "
            INSERT INTO roles (
                " . implode(', ', $columns) . "
            ) VALUES (
                " . implode(', ', $placeholders) . "
            )
        ";

        try {
            $conn->begin_transaction();

            $insertStmt = $conn->prepare(
                $insertSql
            );

            roleAddBind(
                $insertStmt,
                $types,
                $values
            );

            $insertStmt->execute();

            $newRoleId =
                (int) $insertStmt->insert_id;

            $insertStmt->close();

            $conn->commit();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Tenant role created successfully.';

            header(
                'Location: roles.php' .
                (
                    $tenantId > 0
                        ? '?tenant_id=' . $tenantId
                        : ''
                ),
                true,
                303
            );

            exit;
        } catch (Exception $exception) {
            $conn->rollback();

            error_log(
                'Role creation failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                $exception->getMessage();
        }
    }
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .role-add-page {
        max-width: 920px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .role-add-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .role-add-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .role-add-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .role-add-back {
        min-height: 36px;
        padding: 7px 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #4b5563;
        font-size: 9px;
        font-weight: 700;
        text-decoration: none;
    }

    .role-add-back:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .role-add-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid #fecaca;
        border-radius: 10px;
        background: #fef2f2;
        color: #b91c1c;
        font-size: 10px;
        line-height: 1.55;
    }

    .role-add-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(250px, 290px);
        gap: 15px;
        align-items: start;
    }

    .role-add-main,
    .role-add-side {
        display: grid;
        gap: 15px;
    }

    .role-add-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .role-add-card-header {
        min-height: 53px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .role-add-card-icon {
        width: 32px;
        height: 32px;
        flex: 0 0 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 13px;
    }

    .role-add-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .role-add-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .role-add-card-body {
        padding: 15px;
    }

    .role-add-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .role-add-required {
        color: #dc2626;
    }

    .role-add-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    textarea.role-add-control {
        min-height: 110px;
        resize: vertical;
    }

    .role-add-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .role-add-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .role-add-toggle {
        padding: 12px;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        background: #fafafa;
    }

    .role-add-toggle .form-check-input {
        margin-top: 2px;
    }

    .role-add-toggle-title {
        display: block;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .role-add-toggle-text {
        margin-top: 3px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .role-add-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .role-add-submit {
        min-height: 41px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border: 0;
        border-radius: 9px;
        background:
            linear-gradient(
                135deg,
                #7c3aed,
                #6d28d9
            );
        color: #ffffff;
        font-size: 10px;
        font-weight: 700;
    }

    .role-add-cancel {
        min-height: 37px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #6b7280;
        font-size: 9px;
        font-weight: 600;
        text-decoration: none;
    }

    @media (max-width: 800px) {
        .role-add-layout {
            grid-template-columns: 1fr;
        }

        .role-add-side {
            order: -1;
        }
    }

    @media (max-width: 600px) {
        .role-add-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .role-add-back {
            width: 100%;
        }
    }
</style>

<div class="role-add-page">

    <div class="role-add-header">
        <div>
            <h2 class="role-add-title">
                Add Tenant Role
            </h2>

            <div class="role-add-description">
                Create a role that can be assigned to tenant users.
            </div>
        </div>

        <a
            href="roles.php<?= $tenantId > 0
                ? '?tenant_id=' . (int) $tenantId
                : ''; ?>"
            class="role-add-back"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Roles
        </a>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="role-add-alert">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= roleAddEscape($errorMessage); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        id="roleAddForm"
    >
        <?php csrfField(); ?>

        <div class="role-add-layout">

            <div class="role-add-main">

                <section class="role-add-card">
                    <div class="role-add-card-header">
                        <span class="role-add-card-icon">
                            <i class="bi bi-shield-plus"></i>
                        </span>

                        <div>
                            <h3 class="role-add-card-title">
                                Role Information
                            </h3>

                            <div class="role-add-card-subtitle">
                                Tenant, role name and unique code
                            </div>
                        </div>
                    </div>

                    <div class="role-add-card-body">
                        <div class="row g-3">

                            <?php if ($roleTenantColumn !== ''): ?>
                                <div class="col-12">
                                    <label
                                        for="tenantId"
                                        class="role-add-label"
                                    >
                                        Tenant
                                        <span class="role-add-required">*</span>
                                    </label>

                                    <select
                                        class="form-select role-add-control"
                                        id="tenantId"
                                        name="tenant_id"
                                        required
                                    >
                                        <option value="">
                                            Select tenant
                                        </option>

                                        <?php foreach (
                                            $tenantList as $tenantRow
                                        ): ?>
                                            <option
                                                value="<?= (int)
                                                    $tenantRow[
                                                        'tenant_id'
                                                    ]; ?>"
                                                <?= $tenantId ===
                                                    (int)
                                                    $tenantRow[
                                                        'tenant_id'
                                                    ]
                                                        ? 'selected'
                                                        : ''; ?>
                                            >
                                                <?= roleAddEscape(
                                                    $tenantRow[
                                                        'tenant_name'
                                                    ]
                                                ); ?>

                                                <?php if (
                                                    !empty(
                                                        $tenantRow[
                                                            'tenant_code'
                                                        ]
                                                    )
                                                ): ?>
                                                    -
                                                    <?= roleAddEscape(
                                                        $tenantRow[
                                                            'tenant_code'
                                                        ]
                                                    ); ?>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="col-md-6">
                                <label
                                    for="roleName"
                                    class="role-add-label"
                                >
                                    Role Name
                                    <span class="role-add-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    class="form-control role-add-control"
                                    id="roleName"
                                    name="role_name"
                                    value="<?= roleAddEscape(
                                        $roleName
                                    ); ?>"
                                    maxlength="150"
                                    required
                                >
                            </div>

                            <?php if ($roleCodeColumn !== ''): ?>
                                <div class="col-md-6">
                                    <label
                                        for="roleCode"
                                        class="role-add-label"
                                    >
                                        Role Code
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control role-add-control"
                                        id="roleCode"
                                        name="role_code"
                                        value="<?= roleAddEscape(
                                            $roleCode
                                        ); ?>"
                                        maxlength="150"
                                        placeholder="Auto generated"
                                    >

                                    <div class="role-add-help">
                                        Example: tenant_admin or billing_manager
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (
                                $roleDescriptionColumn !== ''
                            ): ?>
                                <div class="col-12">
                                    <label
                                        for="description"
                                        class="role-add-label"
                                    >
                                        Description
                                    </label>

                                    <textarea
                                        class="form-control role-add-control"
                                        id="description"
                                        name="description"
                                        placeholder="Describe what this role is used for"
                                    ><?= roleAddEscape(
                                        $description
                                    ); ?></textarea>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </section>

            </div>

            <aside class="role-add-side">

                <section class="role-add-card">
                    <div class="role-add-card-header">
                        <span class="role-add-card-icon">
                            <i class="bi bi-sliders"></i>
                        </span>

                        <div>
                            <h3 class="role-add-card-title">
                                Role Settings
                            </h3>

                            <div class="role-add-card-subtitle">
                                Status and system behaviour
                            </div>
                        </div>
                    </div>

                    <div class="role-add-card-body">
                        <div class="row g-3">

                            <?php if ($roleStatusColumn !== ''): ?>
                                <div class="col-12">
                                    <label
                                        for="status"
                                        class="role-add-label"
                                    >
                                        Status
                                    </label>

                                    <select
                                        class="form-select role-add-control"
                                        id="status"
                                        name="status"
                                    >
                                        <?php foreach (
                                            $statusOptions as $statusOption
                                        ): ?>
                                            <option
                                                value="<?= roleAddEscape(
                                                    $statusOption
                                                ); ?>"
                                                <?= $status ===
                                                    $statusOption
                                                        ? 'selected'
                                                        : ''; ?>
                                            >
                                                <?= roleAddEscape(
                                                    ucwords(
                                                        str_replace(
                                                            array(
                                                                '_',
                                                                '-'
                                                            ),
                                                            ' ',
                                                            $statusOption
                                                        )
                                                    )
                                                ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <?php if ($roleSystemColumn !== ''): ?>
                                <div class="col-12">
                                    <label class="role-add-toggle">
                                        <input
                                            type="checkbox"
                                            class="form-check-input"
                                            name="is_system"
                                            value="1"
                                            <?= $isSystem === 1
                                                ? 'checked'
                                                : ''; ?>
                                        >

                                        <span>
                                            <span class="role-add-toggle-title">
                                                System Role
                                            </span>

                                            <span class="role-add-toggle-text">
                                                Mark this as a protected or
                                                default role for the tenant.
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </section>

                <div class="role-add-submit-card">
                    <button
                        type="submit"
                        class="role-add-submit"
                    >
                        <i class="bi bi-shield-plus"></i>
                        Create Role
                    </button>

                    <a
                        href="roles.php<?= $tenantId > 0
                            ? '?tenant_id=' . (int) $tenantId
                            : ''; ?>"
                        class="role-add-cancel"
                    >
                        Cancel
                    </a>
                </div>

            </aside>

        </div>
    </form>
</div>

<script>
(function () {
    'use strict';

    const roleName = document.getElementById(
        'roleName'
    );

    const roleCode = document.getElementById(
        'roleCode'
    );

    let codeEdited = false;

    function makeRoleCode(value) {
        return value
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    if (roleCode) {
        roleCode.addEventListener(
            'input',
            function () {
                codeEdited =
                    roleCode.value.trim() !== '';
            }
        );
    }

    if (roleName) {
        roleName.addEventListener(
            'input',
            function () {
                if (roleCode && !codeEdited) {
                    roleCode.value =
                        makeRoleCode(
                            roleName.value
                        );
                }
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
