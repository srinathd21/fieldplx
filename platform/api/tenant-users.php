<?php
declare(strict_types=1);

header(
    'Content-Type: application/json; charset=utf-8'
);

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function tua_post(
    string $key,
    string $default = ''
): string {
    if (
        !isset($_POST[$key]) ||
        is_array($_POST[$key])
    ) {
        return $default;
    }

    return trim(
        (string)$_POST[$key]
    );
}

function tua_json(
    int $status,
    bool $success,
    string $message,
    array $extra = array()
): void {
    http_response_code($status);

    echo json_encode(
        array_merge(
            array(
                'success' => $success,
                'message' => $message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function tua_nullable(
    string $value
) {
    return $value === ''
        ? null
        : $value;
}

function tua_find_tenant(
    PDO $pdo,
    int $tenantId
): array {
    $stmt = $pdo->prepare("
        SELECT
            id,
            tenant_code,
            display_name,
            status
        FROM tenants
        WHERE id = :tenant_id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute(array(
        ':tenant_id' => $tenantId
    ));

    $tenant = $stmt->fetch();

    if (!$tenant) {
        tua_json(
            404,
            false,
            'Tenant not found.'
        );
    }

    return $tenant;
}

function tua_find_user(
    PDO $pdo,
    int $tenantId,
    int $userId
): array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE id = :id
          AND tenant_id = :tenant_id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute(array(
        ':id' => $userId,
        ':tenant_id' => $tenantId
    ));

    $user = $stmt->fetch();

    if (!$user) {
        tua_json(
            404,
            false,
            'Tenant user not found.'
        );
    }

    return $user;
}

function tua_validate_relation(
    PDO $pdo,
    string $table,
    int $id,
    int $tenantId
): void {
    if ($id <= 0) {
        return;
    }

    $allowed = array(
        'branches',
        'departments',
        'roles'
    );

    if (
        !in_array(
            $table,
            $allowed,
            true
        )
    ) {
        tua_json(
            500,
            false,
            'Invalid tenant relation.'
        );
    }

    $sql = "
        SELECT id
        FROM " . $table . "
        WHERE id = :id
          AND tenant_id = :tenant_id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute(array(
        ':id' => $id,
        ':tenant_id' => $tenantId
    ));

    if (!$stmt->fetchColumn()) {
        tua_json(
            422,
            false,
            'Selected ' .
            rtrim($table, 's') .
            ' does not belong to this tenant.'
        );
    }
}

function tua_user_limit(
    PDO $pdo,
    int $tenantId
): ?int {
    $stmt = $pdo->prepare("
        SELECT
            s.max_users_override,
            p.max_users AS plan_max_users
        FROM subscriptions s
        INNER JOIN plans p
            ON p.id = s.plan_id
        WHERE s.tenant_id = :tenant_id
          AND s.deleted_at IS NULL
        ORDER BY
            CASE
                WHEN s.status = 'active' THEN 1
                WHEN s.status = 'trial' THEN 2
                ELSE 3
            END,
            s.id DESC
        LIMIT 1
    ");

    $stmt->execute(array(
        ':tenant_id' => $tenantId
    ));

    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if (
        $row['max_users_override'] !== null &&
        $row['max_users_override'] !== ''
    ) {
        return (int)$row['max_users_override'];
    }

    if (
        $row['plan_max_users'] !== null &&
        $row['plan_max_users'] !== ''
    ) {
        return (int)$row['plan_max_users'];
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tua_json(
        405,
        false,
        'Method not allowed.'
    );
}

$csrf = tua_post('csrf_token');

if (
    empty($_SESSION['tenant_users_csrf']) ||
    !is_string(
        $_SESSION['tenant_users_csrf']
    ) ||
    $csrf === '' ||
    !hash_equals(
        $_SESSION['tenant_users_csrf'],
        $csrf
    )
) {
    tua_json(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$action = tua_post('action');
$tenantId =
    (int)tua_post(
        'tenant_id',
        '0'
    );

if ($tenantId <= 0) {
    tua_json(
        422,
        false,
        'Invalid tenant.'
    );
}

tua_find_tenant(
    $pdo,
    $tenantId
);

try {

    /*
    |--------------------------------------------------------------------------
    | SAVE USER
    |--------------------------------------------------------------------------
    */

    if ($action === 'save_user') {
        $id =
            (int)tua_post(
                'id',
                '0'
            );

        $firstName =
            tua_post('first_name');

        $lastName =
            tua_post('last_name');

        $email =
            strtolower(
                tua_post('email')
            );

        $employeeCode =
            tua_post('employee_code');

        $phone =
            tua_post('phone');

        $alternatePhone =
            tua_post('alternate_phone');

        $jobTitle =
            tua_post('job_title');

        $laborRateRaw =
            tua_post('labor_rate');

        $branchId =
            (int)tua_post(
                'branch_id',
                '0'
            );

        $departmentId =
            (int)tua_post(
                'department_id',
                '0'
            );

        $roleId =
            (int)tua_post(
                'role_id',
                '0'
            );

        $status =
            tua_post(
                'status',
                'active'
            );

        $password =
            tua_post('password');

        $isTenantAdmin =
            isset(
                $_POST['is_tenant_admin']
            ) &&
            $_POST['is_tenant_admin'] === '1'
                ? 1
                : 0;

        $isFieldWorker =
            isset(
                $_POST['is_field_worker']
            ) &&
            $_POST['is_field_worker'] === '1'
                ? 1
                : 0;

        $isBookable =
            isset(
                $_POST['is_bookable']
            ) &&
            $_POST['is_bookable'] === '1'
                ? 1
                : 0;

        if (
            $firstName === '' ||
            strlen($firstName) > 120
        ) {
            tua_json(
                422,
                false,
                'First name is required.'
            );
        }

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            tua_json(
                422,
                false,
                'Enter a valid email address.'
            );
        }

        $validStatuses = array(
            'active',
            'inactive',
            'invited',
            'suspended'
        );

        if (
            !in_array(
                $status,
                $validStatuses,
                true
            )
        ) {
            tua_json(
                422,
                false,
                'Invalid user status.'
            );
        }

        if (
            $laborRateRaw !== '' &&
            (
                !is_numeric($laborRateRaw) ||
                (float)$laborRateRaw < 0
            )
        ) {
            tua_json(
                422,
                false,
                'Labor rate must be a valid positive amount.'
            );
        }

        tua_validate_relation(
            $pdo,
            'branches',
            $branchId,
            $tenantId
        );

        tua_validate_relation(
            $pdo,
            'departments',
            $departmentId,
            $tenantId
        );

        tua_validate_relation(
            $pdo,
            'roles',
            $roleId,
            $tenantId
        );

        if (
            $departmentId > 0 &&
            $branchId > 0
        ) {
            $departmentCheck =
                $pdo->prepare("
                    SELECT id
                    FROM departments
                    WHERE id = :department_id
                      AND tenant_id = :tenant_id
                      AND (
                            branch_id IS NULL
                            OR branch_id = :branch_id
                      )
                    LIMIT 1
                ");

            $departmentCheck->execute(
                array(
                    ':department_id' =>
                        $departmentId,
                    ':tenant_id' =>
                        $tenantId,
                    ':branch_id' =>
                        $branchId
                )
            );

            if (
                !$departmentCheck->fetchColumn()
            ) {
                tua_json(
                    422,
                    false,
                    'Selected department does not belong to the selected branch.'
                );
            }
        }

        $duplicateEmail =
            $pdo->prepare("
                SELECT id
                FROM users
                WHERE tenant_id = :tenant_id
                  AND email = :email
                  AND deleted_at IS NULL
                  AND id <> :id
                LIMIT 1
            ");

        $duplicateEmail->execute(
            array(
                ':tenant_id' =>
                    $tenantId,
                ':email' =>
                    $email,
                ':id' =>
                    $id
            )
        );

        if (
            $duplicateEmail->fetchColumn()
        ) {
            tua_json(
                409,
                false,
                'This email address already exists for the tenant.'
            );
        }

        if ($employeeCode !== '') {
            $duplicateEmployee =
                $pdo->prepare("
                    SELECT id
                    FROM users
                    WHERE tenant_id = :tenant_id
                      AND employee_code = :employee_code
                      AND deleted_at IS NULL
                      AND id <> :id
                    LIMIT 1
                ");

            $duplicateEmployee->execute(
                array(
                    ':tenant_id' =>
                        $tenantId,
                    ':employee_code' =>
                        $employeeCode,
                    ':id' =>
                        $id
                )
            );

            if (
                $duplicateEmployee->fetchColumn()
            ) {
                tua_json(
                    409,
                    false,
                    'Employee code already exists for the tenant.'
                );
            }
        }

        /*
        | New user: enforce subscription max_users.
        */
        if ($id <= 0) {
            $maxUsers =
                tua_user_limit(
                    $pdo,
                    $tenantId
                );

            if ($maxUsers !== null) {
                $countStmt =
                    $pdo->prepare("
                        SELECT COUNT(*)
                        FROM users
                        WHERE tenant_id = :tenant_id
                          AND deleted_at IS NULL
                    ");

                $countStmt->execute(
                    array(
                        ':tenant_id' =>
                            $tenantId
                    )
                );

                $currentCount =
                    (int)$countStmt->fetchColumn();

                if (
                    $currentCount >=
                    $maxUsers
                ) {
                    tua_json(
                        409,
                        false,
                        'This tenant has reached the maximum user limit for its subscription plan.'
                    );
                }
            }

            if (
                strlen($password) < 8
            ) {
                tua_json(
                    422,
                    false,
                    'Temporary password must contain at least 8 characters.'
                );
            }

            $passwordHash =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

            if ($passwordHash === false) {
                tua_json(
                    500,
                    false,
                    'Unable to secure the user password.'
                );
            }

            $stmt = $pdo->prepare("
                INSERT INTO users (
                    tenant_id,
                    branch_id,
                    department_id,
                    role_id,
                    employee_code,
                    first_name,
                    last_name,
                    email,
                    phone,
                    alternate_phone,
                    password_hash,
                    avatar_path,
                    job_title,
                    labor_rate,
                    is_bookable,
                    is_field_worker,
                    is_tenant_admin,
                    status
                ) VALUES (
                    :tenant_id,
                    :branch_id,
                    :department_id,
                    :role_id,
                    :employee_code,
                    :first_name,
                    :last_name,
                    :email,
                    :phone,
                    :alternate_phone,
                    :password_hash,
                    NULL,
                    :job_title,
                    :labor_rate,
                    :is_bookable,
                    :is_field_worker,
                    :is_tenant_admin,
                    :status
                )
            ");

            $stmt->execute(
                array(
                    ':tenant_id' =>
                        $tenantId,
                    ':branch_id' =>
                        $branchId > 0
                            ? $branchId
                            : null,
                    ':department_id' =>
                        $departmentId > 0
                            ? $departmentId
                            : null,
                    ':role_id' =>
                        $roleId > 0
                            ? $roleId
                            : null,
                    ':employee_code' =>
                        tua_nullable(
                            $employeeCode
                        ),
                    ':first_name' =>
                        $firstName,
                    ':last_name' =>
                        tua_nullable(
                            $lastName
                        ),
                    ':email' =>
                        $email,
                    ':phone' =>
                        tua_nullable(
                            $phone
                        ),
                    ':alternate_phone' =>
                        tua_nullable(
                            $alternatePhone
                        ),
                    ':password_hash' =>
                        $passwordHash,
                    ':job_title' =>
                        tua_nullable(
                            $jobTitle
                        ),
                    ':labor_rate' =>
                        $laborRateRaw === ''
                            ? null
                            : number_format(
                                (float)$laborRateRaw,
                                2,
                                '.',
                                ''
                            ),
                    ':is_bookable' =>
                        $isBookable,
                    ':is_field_worker' =>
                        $isFieldWorker,
                    ':is_tenant_admin' =>
                        $isTenantAdmin,
                    ':status' =>
                        $status
                )
            );

            tua_json(
                200,
                true,
                'Tenant user created successfully.',
                array(
                    'user_id' =>
                        (int)$pdo->lastInsertId()
                )
            );
        }

        /*
        | Existing user.
        */
        tua_find_user(
            $pdo,
            $tenantId,
            $id
        );

        $passwordSql = '';
        $updateParams =
            array(
                ':branch_id' =>
                    $branchId > 0
                        ? $branchId
                        : null,
                ':department_id' =>
                    $departmentId > 0
                        ? $departmentId
                        : null,
                ':role_id' =>
                    $roleId > 0
                        ? $roleId
                        : null,
                ':employee_code' =>
                    tua_nullable(
                        $employeeCode
                    ),
                ':first_name' =>
                    $firstName,
                ':last_name' =>
                    tua_nullable(
                        $lastName
                    ),
                ':email' =>
                    $email,
                ':phone' =>
                    tua_nullable(
                        $phone
                    ),
                ':alternate_phone' =>
                    tua_nullable(
                        $alternatePhone
                    ),
                ':job_title' =>
                    tua_nullable(
                        $jobTitle
                    ),
                ':labor_rate' =>
                    $laborRateRaw === ''
                        ? null
                        : number_format(
                            (float)$laborRateRaw,
                            2,
                            '.',
                            ''
                        ),
                ':is_bookable' =>
                    $isBookable,
                ':is_field_worker' =>
                    $isFieldWorker,
                ':is_tenant_admin' =>
                    $isTenantAdmin,
                ':status' =>
                    $status,
                ':id' =>
                    $id,
                ':tenant_id' =>
                    $tenantId
            );

        if ($password !== '') {
            if (
                strlen($password) < 8
            ) {
                tua_json(
                    422,
                    false,
                    'New password must contain at least 8 characters.'
                );
            }

            $passwordHash =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

            if ($passwordHash === false) {
                tua_json(
                    500,
                    false,
                    'Unable to secure the user password.'
                );
            }

            $passwordSql =
                ",
                password_hash = :password_hash
                ";

            $updateParams[
                ':password_hash'
            ] = $passwordHash;
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET
                branch_id = :branch_id,
                department_id = :department_id,
                role_id = :role_id,
                employee_code = :employee_code,
                first_name = :first_name,
                last_name = :last_name,
                email = :email,
                phone = :phone,
                alternate_phone = :alternate_phone,
                job_title = :job_title,
                labor_rate = :labor_rate,
                is_bookable = :is_bookable,
                is_field_worker = :is_field_worker,
                is_tenant_admin = :is_tenant_admin,
                status = :status
                " . $passwordSql . "
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND deleted_at IS NULL
        ");

        $stmt->execute(
            $updateParams
        );

        tua_json(
            200,
            true,
            'Tenant user updated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CHANGE STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === 'change_status') {
        $id =
            (int)tua_post(
                'id',
                '0'
            );

        $status =
            tua_post('status');

        if ($id <= 0) {
            tua_json(
                422,
                false,
                'Invalid tenant user.'
            );
        }

        if (
            !in_array(
                $status,
                array(
                    'active',
                    'inactive',
                    'invited',
                    'suspended'
                ),
                true
            )
        ) {
            tua_json(
                422,
                false,
                'Invalid user status.'
            );
        }

        tua_find_user(
            $pdo,
            $tenantId,
            $id
        );

        $stmt = $pdo->prepare("
            UPDATE users
            SET status = :status
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND deleted_at IS NULL
        ");

        $stmt->execute(
            array(
                ':status' =>
                    $status,
                ':id' =>
                    $id,
                ':tenant_id' =>
                    $tenantId
            )
        );

        tua_json(
            200,
            true,
            $status === 'active'
                ? 'Tenant user activated successfully.'
                : 'Tenant user status updated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SOFT DELETE
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete_user') {
        $id =
            (int)tua_post(
                'id',
                '0'
            );

        if ($id <= 0) {
            tua_json(
                422,
                false,
                'Invalid tenant user.'
            );
        }

        $user =
            tua_find_user(
                $pdo,
                $tenantId,
                $id
            );

        /*
         * Prevent removing the last active tenant administrator.
         */
        if (
            (int)$user['is_tenant_admin'] === 1 &&
            $user['status'] === 'active'
        ) {
            $adminCountStmt =
                $pdo->prepare("
                    SELECT COUNT(*)
                    FROM users
                    WHERE tenant_id = :tenant_id
                      AND is_tenant_admin = 1
                      AND status = 'active'
                      AND deleted_at IS NULL
                ");

            $adminCountStmt->execute(
                array(
                    ':tenant_id' =>
                        $tenantId
                )
            );

            if (
                (int)$adminCountStmt
                    ->fetchColumn() <= 1
            ) {
                tua_json(
                    409,
                    false,
                    'You cannot remove the last active tenant administrator. Assign another tenant administrator first.'
                );
            }
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET
                status = 'inactive',
                deleted_at = NOW()
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND deleted_at IS NULL
        ");

        $stmt->execute(
            array(
                ':id' =>
                    $id,
                ':tenant_id' =>
                    $tenantId
            )
        );

        tua_json(
            200,
            true,
            'Tenant user removed successfully.'
        );
    }

    tua_json(
        400,
        false,
        'Invalid action.'
    );

} catch (Throwable $e) {

    error_log(
        'FieldPlx Tenant Users API Error: ' .
        $e->getMessage()
    );

    tua_json(
        500,
        false,
        'Unable to complete the requested tenant user action.'
    );
}
