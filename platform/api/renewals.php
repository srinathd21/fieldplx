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

function rna_post(
    string $key,
    string $default = ''
): string {

    if (
        !isset($_POST[$key]) ||
        is_array($_POST[$key])
    ) {
        return $default;
    }

    return trim((string)$_POST[$key]);
}

function rna_json(
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

function rna_table_exists(
    PDO $pdo,
    string $table
): bool {

    $stmt=$pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA=DATABASE()
          AND TABLE_NAME=:table_name
    ");

    $stmt->execute(
        array(
            ':table_name'=>$table
        )
    );

    return
        (int)$stmt->fetchColumn()>0;
}

function rna_date(
    string $value,
    string $label
): string {

    $date=
        DateTime::createFromFormat(
            'Y-m-d',
            $value
        );

    if(
        !$date ||
        $date->format('Y-m-d')!==$value
    ){

        rna_json(
            422,
            false,
            'Enter a valid '.$label.'.'
        );
    }

    return $value;
}

function rna_subscription(
    PDO $pdo,
    int $id
): array {

    $stmt=$pdo->prepare("
        SELECT
            s.*,
            t.tenant_code,
            t.display_name AS tenant_name,
            p.name AS plan_name,
            p.code AS plan_code
        FROM subscriptions s
        INNER JOIN tenants t
            ON t.id=s.tenant_id
           AND t.deleted_at IS NULL
        LEFT JOIN plans p
            ON p.id=s.plan_id
           AND p.deleted_at IS NULL
        WHERE s.id=:id
          AND s.deleted_at IS NULL
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute(
        array(
            ':id'=>$id
        )
    );

    $row=$stmt->fetch();

    if(!$row){

        rna_json(
            404,
            false,
            'Subscription not found.'
        );
    }

    return $row;
}

function rna_plan(
    PDO $pdo,
    int $id
): array {

    $stmt=$pdo->prepare("
        SELECT
            id,
            name,
            code,
            price,
            currency,
            billing_cycle,
            duration_days,
            max_users,
            max_branches,
            max_customers,
            storage_limit_mb,
            status
        FROM plans
        WHERE id=:id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute(
        array(
            ':id'=>$id
        )
    );

    $row=$stmt->fetch();

    if(!$row){

        rna_json(
            422,
            false,
            'Selected renewal plan is not available.'
        );
    }

    return $row;
}

function rna_currency(
    PDO $pdo,
    int $id
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
            ':id'=>$id
        )
    );

    if(!$stmt->fetchColumn()){

        rna_json(
            422,
            false,
            'Selected currency is not available.'
        );
    }
}

function rna_generate_payment_no(
    PDO $pdo
): string {

    $stmt=$pdo->query("
        SELECT
            MAX(
                CAST(
                    SUBSTRING_INDEX(
                        payment_no,
                        '-',
                        -1
                    ) AS UNSIGNED
                )
            )
        FROM subscription_payments
        WHERE payment_no LIKE 'SPAY-%'
    ");

    $next=
        (int)$stmt->fetchColumn()+1;

    return
        'SPAY-' .
        str_pad(
            (string)$next,
            6,
            '0',
            STR_PAD_LEFT
        );
}

if($_SERVER['REQUEST_METHOD']!=='POST'){

    rna_json(
        405,
        false,
        'Method not allowed.'
    );
}

$csrf=
    rna_post('csrf_token');

if(
    empty(
        $_SESSION[
            'renewals_csrf'
        ]
    ) ||
    !is_string(
        $_SESSION[
            'renewals_csrf'
        ]
    ) ||
    $csrf==='' ||
    !hash_equals(
        $_SESSION[
            'renewals_csrf'
        ],
        $csrf
    )
){

    rna_json(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

if(
    !rna_table_exists(
        $pdo,
        'subscription_renewals'
    )
){

    rna_json(
        500,
        false,
        'Renewal history table is not installed. Run the supplied renewal migration first.'
    );
}

$action=
    rna_post('action');

try{

    if(
        $action===
        'renew_subscription'
    ){

        $subscriptionId=
            (int)rna_post(
                'subscription_id',
                '0'
            );

        $tenantId=
            (int)rna_post(
                'tenant_id',
                '0'
            );

        $planId=
            (int)rna_post(
                'plan_id',
                '0'
            );

        $currencyId=
            (int)rna_post(
                'currency_id',
                '0'
            );

        $renewalStartDate=
            rna_date(
                rna_post(
                    'renewal_start_date'
                ),
                'renewal start date'
            );

        $newExpiryDate=
            rna_date(
                rna_post(
                    'new_expiry_date'
                ),
                'new expiry date'
            );

        $amountRaw=
            rna_post(
                'amount',
                '0'
            );

        $renewalStatus=
            rna_post(
                'status',
                'completed'
            );

        $autoRenew=
            isset(
                $_POST['auto_renew']
            ) &&
            $_POST['auto_renew']==='1'
                ? 1
                : 0;

        $recordPayment=
            isset(
                $_POST['record_payment']
            ) &&
            $_POST['record_payment']==='1'
                ? 1
                : 0;

        $paymentMethod=
            rna_post(
                'payment_method',
                'bank'
            );

        $transactionReference=
            rna_post(
                'transaction_reference'
            );

        $notes=
            rna_post(
                'notes'
            );

        if(
            $subscriptionId<=0 ||
            $tenantId<=0
        ){

            rna_json(
                422,
                false,
                'Invalid subscription renewal request.'
            );
        }

        if($planId<=0){

            rna_json(
                422,
                false,
                'Please select a renewal plan.'
            );
        }

        if($currencyId<=0){

            rna_json(
                422,
                false,
                'Please select a currency.'
            );
        }

        if(
            !is_numeric($amountRaw) ||
            (float)$amountRaw<0
        ){

            rna_json(
                422,
                false,
                'Enter a valid renewal amount.'
            );
        }

        if(
            $newExpiryDate<
            $renewalStartDate
        ){

            rna_json(
                422,
                false,
                'New expiry date cannot be before the renewal start date.'
            );
        }

        if(
            !in_array(
                $renewalStatus,
                array(
                    'pending',
                    'completed',
                    'cancelled'
                ),
                true
            )
        ){

            rna_json(
                422,
                false,
                'Invalid renewal status.'
            );
        }

        $paymentMethods=array(
            'cash',
            'card',
            'bank',
            'upi',
            'cheque',
            'wallet',
            'other'
        );

        if(
            !in_array(
                $paymentMethod,
                $paymentMethods,
                true
            )
        ){

            rna_json(
                422,
                false,
                'Invalid payment method.'
            );
        }

        rna_currency(
            $pdo,
            $currencyId
        );

        $plan=
            rna_plan(
                $pdo,
                $planId
            );

        $pdo->beginTransaction();

        $subscription=
            rna_subscription(
                $pdo,
                $subscriptionId
            );

        if(
            (int)$subscription[
                'tenant_id'
            ]!==$tenantId
        ){

            $pdo->rollBack();

            rna_json(
                422,
                false,
                'Subscription does not belong to the selected tenant.'
            );
        }

        $duplicate=
            $pdo->prepare("
                SELECT id
                FROM subscription_renewals
                WHERE subscription_id=:subscription_id
                  AND new_start_date=:new_start_date
                  AND new_expiry_date=:new_expiry_date
                  AND deleted_at IS NULL
                  AND status<>'cancelled'
                LIMIT 1
            ");

        $duplicate->execute(
            array(
                ':subscription_id'=>$subscriptionId,
                ':new_start_date'=>$renewalStartDate,
                ':new_expiry_date'=>$newExpiryDate
            )
        );

        if(
            $duplicate->fetchColumn()
        ){

            $pdo->rollBack();

            rna_json(
                409,
                false,
                'This renewal period already exists.'
            );
        }

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

        $amount=
            number_format(
                (float)$amountRaw,
                2,
                '.',
                ''
            );

        $renewalStmt=
            $pdo->prepare("
                INSERT INTO subscription_renewals(
                    subscription_id,
                    tenant_id,
                    old_plan_id,
                    new_plan_id,
                    currency_id,
                    renewal_amount,
                    previous_start_date,
                    previous_expiry_date,
                    new_start_date,
                    new_expiry_date,
                    auto_renew,
                    status,
                    notes,
                    renewed_by,
                    renewed_at
                )
                VALUES(
                    :subscription_id,
                    :tenant_id,
                    :old_plan_id,
                    :new_plan_id,
                    :currency_id,
                    :renewal_amount,
                    :previous_start_date,
                    :previous_expiry_date,
                    :new_start_date,
                    :new_expiry_date,
                    :auto_renew,
                    :status,
                    :notes,
                    :renewed_by,
                    NOW()
                )
            ");

        $renewalStmt->execute(
            array(
                ':subscription_id'=>$subscriptionId,
                ':tenant_id'=>$tenantId,
                ':old_plan_id'=>(int)$subscription['plan_id'],
                ':new_plan_id'=>$planId,
                ':currency_id'=>$currencyId,
                ':renewal_amount'=>$amount,
                ':previous_start_date'=>$subscription['start_date'],
                ':previous_expiry_date'=>$subscription['expiry_date'],
                ':new_start_date'=>$renewalStartDate,
                ':new_expiry_date'=>$newExpiryDate,
                ':auto_renew'=>$autoRenew,
                ':status'=>$renewalStatus,
                ':notes'=>$notes===''?null:$notes,
                ':renewed_by'=>$createdBy
            )
        );

        $renewalId=
            (int)$pdo->lastInsertId();

        /*
         * Only completed renewal changes live subscription.
         * Pending renewal remains as renewal history/action item.
         */
        if(
            $renewalStatus===
            'completed'
        ){

            $update=
                $pdo->prepare("
                    UPDATE subscriptions
                    SET
                        plan_id=:plan_id,
                        currency_id=:currency_id,
                        amount=:amount,
                        start_date=:start_date,
                        expiry_date=:expiry_date,
                        trial_end_date=NULL,
                        auto_renew=:auto_renew,
                        status='active'
                    WHERE id=:id
                      AND deleted_at IS NULL
                ");

            $update->execute(
                array(
                    ':plan_id'=>$planId,
                    ':currency_id'=>$currencyId,
                    ':amount'=>$amount,
                    ':start_date'=>$renewalStartDate,
                    ':expiry_date'=>$newExpiryDate,
                    ':auto_renew'=>$autoRenew,
                    ':id'=>$subscriptionId
                )
            );
        }

        $paymentId=null;

        if(
            $recordPayment===1 &&
            $renewalStatus===
            'completed'
        ){

            if(
                !rna_table_exists(
                    $pdo,
                    'subscription_payments'
                )
            ){

                $pdo->rollBack();

                rna_json(
                    500,
                    false,
                    'Subscription payment table is not installed.'
                );
            }

            $paymentNo=
                rna_generate_payment_no(
                    $pdo
                );

            $paymentStmt=
                $pdo->prepare("
                    INSERT INTO subscription_payments(
                        subscription_id,
                        tenant_id,
                        payment_no,
                        payment_date,
                        amount,
                        currency_id,
                        payment_method,
                        payment_channel,
                        status,
                        transaction_reference,
                        transaction_fee,
                        notes,
                        created_by
                    )
                    VALUES(
                        :subscription_id,
                        :tenant_id,
                        :payment_no,
                        CURDATE(),
                        :amount,
                        :currency_id,
                        :payment_method,
                        'manual',
                        'succeeded',
                        :transaction_reference,
                        0.00,
                        :notes,
                        :created_by
                    )
                ");

            $paymentStmt->execute(
                array(
                    ':subscription_id'=>$subscriptionId,
                    ':tenant_id'=>$tenantId,
                    ':payment_no'=>$paymentNo,
                    ':amount'=>$amount,
                    ':currency_id'=>$currencyId,
                    ':payment_method'=>$paymentMethod,
                    ':transaction_reference'=>$transactionReference===''?null:$transactionReference,
                    ':notes'=>'Subscription renewal #'.$renewalId,
                    ':created_by'=>$createdBy
                )
            );

            $paymentId=
                (int)$pdo->lastInsertId();

            $linkPayment=
                $pdo->prepare("
                    UPDATE subscription_renewals
                    SET payment_id=:payment_id
                    WHERE id=:id
                ");

            $linkPayment->execute(
                array(
                    ':payment_id'=>$paymentId,
                    ':id'=>$renewalId
                )
            );
        }

        $pdo->commit();

        rna_json(
            200,
            true,
            $renewalStatus==='completed'
                ? 'Subscription renewed successfully.'
                : 'Renewal saved successfully.',
            array(
                'renewal_id'=>$renewalId,
                'payment_id'=>$paymentId
            )
        );
    }

    rna_json(
        400,
        false,
        'Invalid action.'
    );

}catch(Throwable $e){

    if(
        isset($pdo) &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ){
        $pdo->rollBack();
    }

    error_log(
        'FieldPlx Renewals API Error: ' .
        $e->getMessage()
    );

    rna_json(
        500,
        false,
        'Unable to complete the renewal action.'
    );
}
