<?php
declare(strict_types=1);

ob_start();

ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');

header(
    'Content-Type: application/json; charset=utf-8'
);

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sub_post(
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

function sub_json(
    int $status,
    bool $success,
    string $message,
    array $extra = array()
): void {

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($status);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        array_merge(
            array(
                'success'=>$success,
                'message'=>$message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function sub_nullable_int(
    string $value
) {

    if ($value === '') {
        return null;
    }

    if (!ctype_digit($value)) {

        sub_json(
            422,
            false,
            'One or more subscription limit values are invalid.'
        );
    }

    return (int)$value;
}

function sub_nullable_date(
    string $value
) {

    if ($value === '') {
        return null;
    }

    $date=
        DateTime::createFromFormat(
            'Y-m-d',
            $value
        );

    if (
        !$date ||
        $date->format('Y-m-d') !== $value
    ) {

        sub_json(
            422,
            false,
            'One or more subscription dates are invalid.'
        );
    }

    return $value;
}

function sub_has_column(
    PDO $pdo,
    string $table,
    string $column
): bool {

    static $cache=array();

    $key=
        $table.'.'.$column;

    if (
        isset($cache[$key])
    ) {
        return $cache[$key];
    }

    $stmt=$pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE()
          AND TABLE_NAME=:table_name
          AND COLUMN_NAME=:column_name
    ");

    $stmt->execute(
        array(
            ':table_name'=>$table,
            ':column_name'=>$column
        )
    );

    $cache[$key]=
        (int)$stmt->fetchColumn()>0;

    return $cache[$key];
}

function sub_find_subscription(
    PDO $pdo,
    int $id
): array {

    $sql="
        SELECT *
        FROM subscriptions
        WHERE id=:id
    ";

    if (
        sub_has_column(
            $pdo,
            'subscriptions',
            'deleted_at'
        )
    ) {
        $sql.="
          AND deleted_at IS NULL
        ";
    }

    $sql.="
        LIMIT 1
    ";

    $stmt=
        $pdo->prepare($sql);

    $stmt->execute(
        array(
            ':id'=>$id
        )
    );

    $row=$stmt->fetch();

    if(!$row){

        sub_json(
            404,
            false,
            'Subscription not found.'
        );
    }

    return $row;
}

function sub_validate_tenant(
    PDO $pdo,
    int $tenantId
): array {

    $stmt=$pdo->prepare("
        SELECT
            id,
            currency_id,
            status
        FROM tenants
        WHERE id=:id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute(
        array(
            ':id'=>$tenantId
        )
    );

    $row=$stmt->fetch();

    if(!$row){

        sub_json(
            422,
            false,
            'Selected tenant is not available.'
        );
    }

    return $row;
}

function sub_validate_plan(
    PDO $pdo,
    int $planId
): array {

    $stmt=$pdo->prepare("
        SELECT
            id,
            name,
            status
        FROM plans
        WHERE id=:id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute(
        array(
            ':id'=>$planId
        )
    );

    $row=$stmt->fetch();

    if(!$row){

        sub_json(
            422,
            false,
            'Selected plan is not available.'
        );
    }

    return $row;
}

function sub_validate_currency(
    PDO $pdo,
    int $currencyId
): void {

    $stmt=$pdo->prepare("
        SELECT id
        FROM currencies
        WHERE id=:id
          AND is_active=1
        LIMIT 1
    ");

    $stmt->execute(
        array(
            ':id'=>$currencyId
        )
    );

    if(!$stmt->fetchColumn()){

        sub_json(
            422,
            false,
            'Selected currency is not available.'
        );
    }
}

if($_SERVER['REQUEST_METHOD']!=='POST'){

    sub_json(
        405,
        false,
        'Method not allowed.'
    );
}

$csrf=
    sub_post(
        'csrf_token'
    );

if(
    empty(
        $_SESSION[
            'subscriptions_csrf'
        ]
    ) ||
    !is_string(
        $_SESSION[
            'subscriptions_csrf'
        ]
    ) ||
    $csrf==='' ||
    !hash_equals(
        $_SESSION[
            'subscriptions_csrf'
        ],
        $csrf
    )
){

    sub_json(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$action=
    sub_post(
        'action'
    );

try{

    if(
        $action===
        'save_subscription'
    ){

        $id=
            (int)sub_post(
                'id',
                '0'
            );

        $tenantId=
            (int)sub_post(
                'tenant_id',
                '0'
            );

        $planId=
            (int)sub_post(
                'plan_id',
                '0'
            );

        $currencyId=
            (int)sub_post(
                'currency_id',
                '0'
            );

        $amountRaw=
            sub_post(
                'amount',
                '0'
            );

        $startDate=
            sub_nullable_date(
                sub_post(
                    'start_date'
                )
            );

        $expiryDate=
            sub_nullable_date(
                sub_post(
                    'expiry_date'
                )
            );

        $trialEndDate=
            sub_nullable_date(
                sub_post(
                    'trial_end_date'
                )
            );

        $status=
            sub_post(
                'status',
                'active'
            );

        $autoRenew=
            isset(
                $_POST[
                    'auto_renew'
                ]
            ) &&
            $_POST[
                'auto_renew'
            ]==='1'
                ? 1
                : 0;

        $maxUsers=
            sub_nullable_int(
                sub_post(
                    'max_users_override'
                )
            );

        $maxBranches=
            sub_nullable_int(
                sub_post(
                    'max_branches_override'
                )
            );

        $maxCustomers=
            sub_nullable_int(
                sub_post(
                    'max_customers_override'
                )
            );

        $storageLimit=
            sub_nullable_int(
                sub_post(
                    'storage_limit_mb_override'
                )
            );

        if($tenantId<=0){

            sub_json(
                422,
                false,
                'Please select a tenant.'
            );
        }

        if($planId<=0){

            sub_json(
                422,
                false,
                'Please select a plan.'
            );
        }

        if($currencyId<=0){

            sub_json(
                422,
                false,
                'Please select a currency.'
            );
        }

        if(
            !is_numeric(
                $amountRaw
            ) ||
            (float)$amountRaw<0
        ){

            sub_json(
                422,
                false,
                'Enter a valid subscription amount.'
            );
        }

        if($startDate===null){

            sub_json(
                422,
                false,
                'Start date is required.'
            );
        }

        if(
            $expiryDate!==null &&
            $expiryDate<$startDate
        ){

            sub_json(
                422,
                false,
                'Expiry date cannot be before the start date.'
            );
        }

        if(
            $trialEndDate!==null &&
            $trialEndDate<$startDate
        ){

            sub_json(
                422,
                false,
                'Trial end date cannot be before the start date.'
            );
        }

        $allowedStatuses=array(
            'trial',
            'active',
            'expired',
            'cancelled',
            'suspended'
        );

        if(
            !in_array(
                $status,
                $allowedStatuses,
                true
            )
        ){

            sub_json(
                422,
                false,
                'Invalid subscription status.'
            );
        }

        sub_validate_tenant(
            $pdo,
            $tenantId
        );

        sub_validate_plan(
            $pdo,
            $planId
        );

        sub_validate_currency(
            $pdo,
            $currencyId
        );

        if($id>0){

            sub_find_subscription(
                $pdo,
                $id
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent duplicate identical current subscriptions
        |--------------------------------------------------------------------------
        */

        $duplicateSql="
            SELECT id
            FROM subscriptions
            WHERE tenant_id=:tenant_id
              AND plan_id=:plan_id
              AND start_date=:start_date
              AND id<>:id
        ";

        if(
            sub_has_column(
                $pdo,
                'subscriptions',
                'deleted_at'
            )
        ){
            $duplicateSql.="
              AND deleted_at IS NULL
            ";
        }

        $duplicateSql.="
            LIMIT 1
        ";

        $duplicate=
            $pdo->prepare(
                $duplicateSql
            );

        $duplicate->execute(
            array(
                ':tenant_id'=>$tenantId,
                ':plan_id'=>$planId,
                ':start_date'=>$startDate,
                ':id'=>$id
            )
        );

        if(
            $duplicate->fetchColumn()
        ){

            sub_json(
                409,
                false,
                'A subscription for this tenant, plan and start date already exists.'
            );
        }

        $amount=
            number_format(
                (float)$amountRaw,
                2,
                '.',
                ''
            );

        $createdBy=null;

        if(
            isset(
                $_SESSION[
                    'platform_user_id'
                ]
            ) &&
            (int)$_SESSION[
                'platform_user_id'
            ]>0
        ){

            $createdBy=
                (int)$_SESSION[
                    'platform_user_id'
                ];
        }

        if($id>0){

            $stmt=
                $pdo->prepare("
                    UPDATE subscriptions
                    SET
                        tenant_id=:tenant_id,
                        plan_id=:plan_id,
                        currency_id=:currency_id,
                        amount=:amount,
                        start_date=:start_date,
                        expiry_date=:expiry_date,
                        trial_end_date=:trial_end_date,
                        auto_renew=:auto_renew,
                        max_users_override=:max_users_override,
                        max_branches_override=:max_branches_override,
                        max_customers_override=:max_customers_override,
                        storage_limit_mb_override=:storage_limit_mb_override,
                        status=:status
                    WHERE id=:id
                ");

            $stmt->execute(
                array(
                    ':tenant_id'=>$tenantId,
                    ':plan_id'=>$planId,
                    ':currency_id'=>$currencyId,
                    ':amount'=>$amount,
                    ':start_date'=>$startDate,
                    ':expiry_date'=>$expiryDate,
                    ':trial_end_date'=>$trialEndDate,
                    ':auto_renew'=>$autoRenew,
                    ':max_users_override'=>$maxUsers,
                    ':max_branches_override'=>$maxBranches,
                    ':max_customers_override'=>$maxCustomers,
                    ':storage_limit_mb_override'=>$storageLimit,
                    ':status'=>$status,
                    ':id'=>$id
                )
            );

            sub_json(
                200,
                true,
                'Subscription updated successfully.'
            );
        }

        $stmt=
            $pdo->prepare("
                INSERT INTO subscriptions(
                    tenant_id,
                    plan_id,
                    currency_id,
                    amount,
                    start_date,
                    expiry_date,
                    trial_end_date,
                    auto_renew,
                    max_users_override,
                    max_branches_override,
                    max_customers_override,
                    storage_limit_mb_override,
                    status,
                    created_by
                )
                VALUES(
                    :tenant_id,
                    :plan_id,
                    :currency_id,
                    :amount,
                    :start_date,
                    :expiry_date,
                    :trial_end_date,
                    :auto_renew,
                    :max_users_override,
                    :max_branches_override,
                    :max_customers_override,
                    :storage_limit_mb_override,
                    :status,
                    :created_by
                )
            ");

        $stmt->execute(
            array(
                ':tenant_id'=>$tenantId,
                ':plan_id'=>$planId,
                ':currency_id'=>$currencyId,
                ':amount'=>$amount,
                ':start_date'=>$startDate,
                ':expiry_date'=>$expiryDate,
                ':trial_end_date'=>$trialEndDate,
                ':auto_renew'=>$autoRenew,
                ':max_users_override'=>$maxUsers,
                ':max_branches_override'=>$maxBranches,
                ':max_customers_override'=>$maxCustomers,
                ':storage_limit_mb_override'=>$storageLimit,
                ':status'=>$status,
                ':created_by'=>$createdBy
            )
        );

        sub_json(
            201,
            true,
            'Subscription created successfully.',
            array(
                'subscription_id'=>
                    (int)$pdo->lastInsertId()
            )
        );
    }

    if(
        $action===
        'change_status'
    ){

        $id=
            (int)sub_post(
                'id',
                '0'
            );

        $status=
            sub_post(
                'status'
            );

        if($id<=0){

            sub_json(
                422,
                false,
                'Invalid subscription.'
            );
        }

        if(
            !in_array(
                $status,
                array(
                    'trial',
                    'active',
                    'expired',
                    'cancelled',
                    'suspended'
                ),
                true
            )
        ){

            sub_json(
                422,
                false,
                'Invalid subscription status.'
            );
        }

        sub_find_subscription(
            $pdo,
            $id
        );

        $stmt=
            $pdo->prepare("
                UPDATE subscriptions
                SET status=:status
                WHERE id=:id
            ");

        $stmt->execute(
            array(
                ':status'=>$status,
                ':id'=>$id
            )
        );

        sub_json(
            200,
            true,
            'Subscription status updated successfully.'
        );
    }

    sub_json(
        400,
        false,
        'Invalid action.'
    );

}catch(Throwable $e){

    error_log(
        'FieldPlx Subscriptions API Error: ' .
        $e->getMessage()
    );

    sub_json(
        500,
        false,
        'Unable to complete the subscription action.'
    );
}
