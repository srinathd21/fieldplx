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

function spp_post(
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

function spp_json(
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

function spp_date(string $value): string
{
    $date=
        DateTime::createFromFormat(
            'Y-m-d',
            $value
        );

    if (
        !$date ||
        $date->format('Y-m-d') !== $value
    ) {
        spp_json(
            422,
            false,
            'Enter a valid payment date.'
        );
    }

    return $value;
}

function spp_subscription(
    PDO $pdo,
    int $subscriptionId
): array {

    $stmt=$pdo->prepare("
        SELECT
            s.id,
            s.tenant_id,
            s.currency_id,
            s.status
        FROM subscriptions s
        INNER JOIN tenants t
            ON t.id = s.tenant_id
           AND t.deleted_at IS NULL
        WHERE s.id=:id
          AND s.deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute(
        array(
            ':id'=>$subscriptionId
        )
    );

    $row=$stmt->fetch();

    if(!$row){

        spp_json(
            422,
            false,
            'Selected subscription is not available.'
        );
    }

    return $row;
}

function spp_currency(
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

        spp_json(
            422,
            false,
            'Selected currency is not available.'
        );
    }
}

function spp_payment(
    PDO $pdo,
    int $id
): array {

    $stmt=$pdo->prepare("
        SELECT *
        FROM subscription_payments
        WHERE id=:id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute(
        array(':id'=>$id)
    );

    $row=$stmt->fetch();

    if(!$row){

        spp_json(
            404,
            false,
            'Subscription payment not found.'
        );
    }

    return $row;
}

function spp_generate_payment_no(
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

    spp_json(
        405,
        false,
        'Method not allowed.'
    );
}

$csrf=
    spp_post('csrf_token');

if(
    empty(
        $_SESSION[
            'subscription_payments_csrf'
        ]
    ) ||
    !is_string(
        $_SESSION[
            'subscription_payments_csrf'
        ]
    ) ||
    $csrf==='' ||
    !hash_equals(
        $_SESSION[
            'subscription_payments_csrf'
        ],
        $csrf
    )
){

    spp_json(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$action=
    spp_post('action');

try{

    if($action==='save_payment'){

        $id=
            (int)spp_post(
                'id',
                '0'
            );

        $subscriptionId=
            (int)spp_post(
                'subscription_id',
                '0'
            );

        $paymentNo=
            spp_post(
                'payment_no'
            );

        $paymentDate=
            spp_date(
                spp_post(
                    'payment_date'
                )
            );

        $currencyId=
            (int)spp_post(
                'currency_id',
                '0'
            );

        $amountRaw=
            spp_post(
                'amount',
                '0'
            );

        $paymentMethod=
            spp_post(
                'payment_method',
                'bank'
            );

        $paymentChannel=
            spp_post(
                'payment_channel',
                'manual'
            );

        $status=
            spp_post(
                'status',
                'pending'
            );

        $transactionFeeRaw=
            spp_post(
                'transaction_fee',
                '0'
            );

        $transactionReference=
            spp_post(
                'transaction_reference'
            );

        $provider=
            spp_post(
                'provider'
            );

        $providerPaymentId=
            spp_post(
                'provider_payment_id'
            );

        $notes=
            spp_post(
                'notes'
            );

        if($subscriptionId<=0){

            spp_json(
                422,
                false,
                'Please select a subscription.'
            );
        }

        $subscription=
            spp_subscription(
                $pdo,
                $subscriptionId
            );

        if($currencyId<=0){

            spp_json(
                422,
                false,
                'Please select a currency.'
            );
        }

        spp_currency(
            $pdo,
            $currencyId
        );

        if(
            !is_numeric($amountRaw) ||
            (float)$amountRaw<=0
        ){

            spp_json(
                422,
                false,
                'Enter a valid payment amount.'
            );
        }

        if(
            !is_numeric($transactionFeeRaw) ||
            (float)$transactionFeeRaw<0
        ){

            spp_json(
                422,
                false,
                'Enter a valid transaction fee.'
            );
        }

        $methods=array(
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
                $methods,
                true
            )
        ){

            spp_json(
                422,
                false,
                'Invalid payment method.'
            );
        }

        $channels=array(
            'online',
            'client_portal',
            'mobile',
            'office',
            'tap_to_pay',
            'manual'
        );

        if(
            !in_array(
                $paymentChannel,
                $channels,
                true
            )
        ){

            spp_json(
                422,
                false,
                'Invalid payment channel.'
            );
        }

        $statuses=array(
            'pending',
            'authorized',
            'succeeded',
            'failed',
            'refunded',
            'partially_refunded',
            'cancelled'
        );

        if(
            !in_array(
                $status,
                $statuses,
                true
            )
        ){

            spp_json(
                422,
                false,
                'Invalid payment status.'
            );
        }

        if(
            $paymentNo==='' ||
            strtoupper($paymentNo)==='AUTO'
        ){

            $paymentNo=
                spp_generate_payment_no(
                    $pdo
                );
        }

        if(
            strlen($paymentNo)>80
        ){

            spp_json(
                422,
                false,
                'Payment number is too long.'
            );
        }

        $duplicate=
            $pdo->prepare("
                SELECT id
                FROM subscription_payments
                WHERE payment_no=:payment_no
                  AND id<>:id
                  AND deleted_at IS NULL
                LIMIT 1
            ");

        $duplicate->execute(
            array(
                ':payment_no'=>$paymentNo,
                ':id'=>$id
            )
        );

        if($duplicate->fetchColumn()){

            spp_json(
                409,
                false,
                'Payment number already exists.'
            );
        }

        if($providerPaymentId!==''){

            $providerDuplicate=
                $pdo->prepare("
                    SELECT id
                    FROM subscription_payments
                    WHERE provider_payment_id=:provider_payment_id
                      AND id<>:id
                      AND deleted_at IS NULL
                    LIMIT 1
                ");

            $providerDuplicate->execute(
                array(
                    ':provider_payment_id'=>$providerPaymentId,
                    ':id'=>$id
                )
            );

            if(
                $providerDuplicate->fetchColumn()
            ){

                spp_json(
                    409,
                    false,
                    'Provider payment ID already exists.'
                );
            }
        }

        $amount=
            number_format(
                (float)$amountRaw,
                2,
                '.',
                ''
            );

        $transactionFee=
            number_format(
                (float)$transactionFeeRaw,
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

            spp_payment(
                $pdo,
                $id
            );

            $stmt=
                $pdo->prepare("
                    UPDATE subscription_payments
                    SET
                        subscription_id=:subscription_id,
                        tenant_id=:tenant_id,
                        payment_no=:payment_no,
                        payment_date=:payment_date,
                        amount=:amount,
                        currency_id=:currency_id,
                        payment_method=:payment_method,
                        payment_channel=:payment_channel,
                        status=:status,
                        transaction_reference=:transaction_reference,
                        provider=:provider,
                        provider_payment_id=:provider_payment_id,
                        transaction_fee=:transaction_fee,
                        notes=:notes
                    WHERE id=:id
                      AND deleted_at IS NULL
                ");

            $stmt->execute(
                array(
                    ':subscription_id'=>$subscriptionId,
                    ':tenant_id'=>(int)$subscription['tenant_id'],
                    ':payment_no'=>$paymentNo,
                    ':payment_date'=>$paymentDate,
                    ':amount'=>$amount,
                    ':currency_id'=>$currencyId,
                    ':payment_method'=>$paymentMethod,
                    ':payment_channel'=>$paymentChannel,
                    ':status'=>$status,
                    ':transaction_reference'=>$transactionReference===''?null:$transactionReference,
                    ':provider'=>$provider===''?null:$provider,
                    ':provider_payment_id'=>$providerPaymentId===''?null:$providerPaymentId,
                    ':transaction_fee'=>$transactionFee,
                    ':notes'=>$notes===''?null:$notes,
                    ':id'=>$id
                )
            );

            spp_json(
                200,
                true,
                'Subscription payment updated successfully.'
            );
        }

        $stmt=
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
                    provider,
                    provider_payment_id,
                    transaction_fee,
                    notes,
                    created_by
                )
                VALUES(
                    :subscription_id,
                    :tenant_id,
                    :payment_no,
                    :payment_date,
                    :amount,
                    :currency_id,
                    :payment_method,
                    :payment_channel,
                    :status,
                    :transaction_reference,
                    :provider,
                    :provider_payment_id,
                    :transaction_fee,
                    :notes,
                    :created_by
                )
            ");

        $stmt->execute(
            array(
                ':subscription_id'=>$subscriptionId,
                ':tenant_id'=>(int)$subscription['tenant_id'],
                ':payment_no'=>$paymentNo,
                ':payment_date'=>$paymentDate,
                ':amount'=>$amount,
                ':currency_id'=>$currencyId,
                ':payment_method'=>$paymentMethod,
                ':payment_channel'=>$paymentChannel,
                ':status'=>$status,
                ':transaction_reference'=>$transactionReference===''?null:$transactionReference,
                ':provider'=>$provider===''?null:$provider,
                ':provider_payment_id'=>$providerPaymentId===''?null:$providerPaymentId,
                ':transaction_fee'=>$transactionFee,
                ':notes'=>$notes===''?null:$notes,
                ':created_by'=>$createdBy
            )
        );

        spp_json(
            201,
            true,
            'Subscription payment created successfully.',
            array(
                'payment_id'=>
                    (int)$pdo->lastInsertId(),
                'payment_no'=>$paymentNo
            )
        );
    }

    if($action==='change_status'){

        $id=
            (int)spp_post(
                'id',
                '0'
            );

        $status=
            spp_post(
                'status'
            );

        if($id<=0){

            spp_json(
                422,
                false,
                'Invalid payment.'
            );
        }

        if(
            !in_array(
                $status,
                array(
                    'pending',
                    'authorized',
                    'succeeded',
                    'failed',
                    'refunded',
                    'partially_refunded',
                    'cancelled'
                ),
                true
            )
        ){

            spp_json(
                422,
                false,
                'Invalid payment status.'
            );
        }

        spp_payment(
            $pdo,
            $id
        );

        $stmt=
            $pdo->prepare("
                UPDATE subscription_payments
                SET status=:status
                WHERE id=:id
                  AND deleted_at IS NULL
            ");

        $stmt->execute(
            array(
                ':status'=>$status,
                ':id'=>$id
            )
        );

        spp_json(
            200,
            true,
            'Payment status updated successfully.'
        );
    }

    spp_json(
        400,
        false,
        'Invalid action.'
    );

}catch(Throwable $e){

    error_log(
        'FieldPlx Subscription Payments API Error: ' .
        $e->getMessage()
    );

    spp_json(
        500,
        false,
        'Unable to complete the payment action.'
    );
}
