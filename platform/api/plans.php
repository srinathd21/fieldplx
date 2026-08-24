<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function pl_post(string $key, string $default = ''): string
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }

    return trim((string)$_POST[$key]);
}

function pl_json(int $status, bool $success, string $message, array $extra = array()): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        array_merge(array('success'=>$success,'message'=>$message),$extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function pl_nullable_int(string $value)
{
    if ($value === '') {
        return null;
    }

    if (!ctype_digit($value)) {
        pl_json(422, false, 'One or more plan limits are invalid.');
    }

    return (int)$value;
}

function pl_find_plan(PDO $pdo, int $id): array
{
    $stmt=$pdo->prepare("
        SELECT *
        FROM plans
        WHERE id=:id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute(array(':id'=>$id));
    $row=$stmt->fetch();

    if(!$row){
        pl_json(404,false,'Plan not found.');
    }

    return $row;
}

if($_SERVER['REQUEST_METHOD']!=='POST'){
    pl_json(405,false,'Method not allowed.');
}

$csrf=pl_post('csrf_token');

if(
    empty($_SESSION['plans_csrf']) ||
    !is_string($_SESSION['plans_csrf']) ||
    $csrf==='' ||
    !hash_equals($_SESSION['plans_csrf'],$csrf)
){
    pl_json(419,false,'Your form session expired. Refresh the page and try again.');
}

$action=pl_post('action');

try{

    if($action==='save_plan'){
        $id=(int)pl_post('id','0');
        $name=pl_post('name');
        $code=strtolower(pl_post('code'));
        $description=pl_post('description');
        $priceRaw=pl_post('price','0');
        $currency=strtoupper(pl_post('currency'));
        $billingCycle=pl_post('billing_cycle','monthly');
        $durationDays=pl_nullable_int(pl_post('duration_days'));
        $trialDays=pl_nullable_int(pl_post('trial_days','0'));
        $maxUsers=pl_nullable_int(pl_post('max_users'));
        $maxBranches=pl_nullable_int(pl_post('max_branches'));
        $maxCustomers=pl_nullable_int(pl_post('max_customers'));
        $storageLimit=pl_nullable_int(pl_post('storage_limit_mb'));
        $apiCalls=pl_nullable_int(pl_post('api_calls_per_month'));
        $smsPerMonth=pl_nullable_int(pl_post('sms_per_month'));
        $emailPerMonth=pl_nullable_int(pl_post('email_per_month'));
        $aiMinutes=pl_nullable_int(pl_post('ai_minutes_per_month'));
        $status=pl_post('status','active');
        $isFeatured=isset($_POST['is_featured']) && $_POST['is_featured']==='1' ? 1 : 0;

        if($name==='' || strlen($name)>190){
            pl_json(422,false,'Plan name is required and must be 190 characters or less.');
        }

        if(
            $code==='' ||
            strlen($code)>120 ||
            !preg_match('/^[a-z0-9][a-z0-9_-]*$/',$code)
        ){
            pl_json(422,false,'Plan code may contain lowercase letters, numbers, hyphens and underscores only.');
        }

        if(!is_numeric($priceRaw) || (float)$priceRaw<0){
            pl_json(422,false,'Enter a valid plan price.');
        }

        $price=number_format((float)$priceRaw,2,'.','');

        if($currency==='' || strlen($currency)>10){
            pl_json(422,false,'Select a valid currency.');
        }

        $currencyCheck=$pdo->prepare("
            SELECT currency_code
            FROM currencies
            WHERE currency_code=:code
              AND is_active=1
            LIMIT 1
        ");
        $currencyCheck->execute(array(':code'=>$currency));

        if(!$currencyCheck->fetchColumn()){
            pl_json(422,false,'Selected currency is not available.');
        }

        $cycles=array('monthly','quarterly','half_yearly','yearly','lifetime','custom');

        if(!in_array($billingCycle,$cycles,true)){
            pl_json(422,false,'Invalid billing cycle.');
        }

        if($billingCycle==='custom' && ($durationDays===null || $durationDays<1)){
            pl_json(422,false,'Duration days is required for a custom billing cycle.');
        }

        if($billingCycle!=='custom'){
            $durationDays=null;
        }

        if($trialDays===null){
            $trialDays=0;
        }

        $statuses=array('active','inactive','draft','archived');

        if(!in_array($status,$statuses,true)){
            pl_json(422,false,'Invalid plan status.');
        }

        $duplicate=$pdo->prepare("
            SELECT id
            FROM plans
            WHERE code=:code
              AND id<>:id
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $duplicate->execute(array(':code'=>$code,':id'=>$id));

        if($duplicate->fetchColumn()){
            pl_json(409,false,'Plan code already exists.');
        }

        $createdBy=null;

        if(isset($_SESSION['platform_user_id']) && (int)$_SESSION['platform_user_id']>0){
            $createdBy=(int)$_SESSION['platform_user_id'];
        }

        $params=array(
            ':name'=>$name,
            ':code'=>$code,
            ':description'=>$description===''?null:$description,
            ':price'=>$price,
            ':currency'=>$currency,
            ':billing_cycle'=>$billingCycle,
            ':duration_days'=>$durationDays,
            ':trial_days'=>$trialDays,
            ':max_users'=>$maxUsers,
            ':max_branches'=>$maxBranches,
            ':max_customers'=>$maxCustomers,
            ':storage_limit_mb'=>$storageLimit,
            ':api_calls_per_month'=>$apiCalls,
            ':sms_per_month'=>$smsPerMonth,
            ':email_per_month'=>$emailPerMonth,
            ':ai_minutes_per_month'=>$aiMinutes,
            ':is_featured'=>$isFeatured,
            ':status'=>$status
        );

        if($id>0){
            pl_find_plan($pdo,$id);
            $params[':id']=$id;

            $stmt=$pdo->prepare("
                UPDATE plans
                SET
                    name=:name,
                    code=:code,
                    description=:description,
                    price=:price,
                    currency=:currency,
                    billing_cycle=:billing_cycle,
                    duration_days=:duration_days,
                    trial_days=:trial_days,
                    max_users=:max_users,
                    max_branches=:max_branches,
                    max_customers=:max_customers,
                    storage_limit_mb=:storage_limit_mb,
                    api_calls_per_month=:api_calls_per_month,
                    sms_per_month=:sms_per_month,
                    email_per_month=:email_per_month,
                    ai_minutes_per_month=:ai_minutes_per_month,
                    is_featured=:is_featured,
                    status=:status
                WHERE id=:id
                  AND deleted_at IS NULL
            ");

            $stmt->execute($params);
            pl_json(200,true,'Plan updated successfully.');
        }

        $params[':created_by']=$createdBy;

        $stmt=$pdo->prepare("
            INSERT INTO plans(
                name,code,description,price,currency,billing_cycle,duration_days,trial_days,
                max_users,max_branches,max_customers,storage_limit_mb,api_calls_per_month,
                sms_per_month,email_per_month,ai_minutes_per_month,is_featured,status,created_by
            )VALUES(
                :name,:code,:description,:price,:currency,:billing_cycle,:duration_days,:trial_days,
                :max_users,:max_branches,:max_customers,:storage_limit_mb,:api_calls_per_month,
                :sms_per_month,:email_per_month,:ai_minutes_per_month,:is_featured,:status,:created_by
            )
        ");

        $stmt->execute($params);

        pl_json(
            201,
            true,
            'Plan created successfully.',
            array('plan_id'=>(int)$pdo->lastInsertId())
        );
    }

    if($action==='change_status'){
        $id=(int)pl_post('id','0');
        $status=pl_post('status');

        if($id<=0){
            pl_json(422,false,'Invalid plan.');
        }

        if(!in_array($status,array('active','inactive','draft','archived'),true)){
            pl_json(422,false,'Invalid plan status.');
        }

        pl_find_plan($pdo,$id);

        $stmt=$pdo->prepare("
            UPDATE plans
            SET status=:status
            WHERE id=:id
              AND deleted_at IS NULL
        ");

        $stmt->execute(array(':status'=>$status,':id'=>$id));
        pl_json(200,true,'Plan status updated successfully.');
    }

    if($action==='toggle_featured'){
        $id=(int)pl_post('id','0');
        $isFeatured=pl_post('is_featured','0')==='1'?1:0;

        if($id<=0){
            pl_json(422,false,'Invalid plan.');
        }

        pl_find_plan($pdo,$id);

        $stmt=$pdo->prepare("
            UPDATE plans
            SET is_featured=:is_featured
            WHERE id=:id
              AND deleted_at IS NULL
        ");

        $stmt->execute(array(':is_featured'=>$isFeatured,':id'=>$id));

        pl_json(
            200,
            true,
            $isFeatured?'Plan marked as featured.':'Plan removed from featured.'
        );
    }

    if($action==='archive_plan'){
        $id=(int)pl_post('id','0');

        if($id<=0){
            pl_json(422,false,'Invalid plan.');
        }

        pl_find_plan($pdo,$id);

        $check=$pdo->prepare("
            SELECT COUNT(*)
            FROM subscriptions
            WHERE plan_id=:plan_id
              AND deleted_at IS NULL
        ");
        $check->execute(array(':plan_id'=>$id));

        if((int)$check->fetchColumn()>0){
            pl_json(409,false,'This plan has subscription history. Set it inactive or archived instead of removing it.');
        }

        $stmt=$pdo->prepare("
            UPDATE plans
            SET
                status='archived',
                deleted_at=NOW()
            WHERE id=:id
              AND deleted_at IS NULL
        ");

        $stmt->execute(array(':id'=>$id));
        pl_json(200,true,'Plan archived successfully.');
    }

    pl_json(400,false,'Invalid action.');

}catch(Throwable $e){
    error_log('FieldPlx Plans API Error: '.$e->getMessage());
    pl_json(500,false,'Unable to complete the requested plan action.');
}
