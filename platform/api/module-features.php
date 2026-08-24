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

function mf_post(
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

function mf_json(
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

function mf_find_feature(
    PDO $pdo,
    int $id
): array {
    $stmt=$pdo->prepare("
        SELECT *
        FROM module_features
        WHERE id=:id
        LIMIT 1
    ");

    $stmt->execute(
        array(
            ':id'=>$id
        )
    );

    $row=$stmt->fetch();

    if(!$row){
        mf_json(
            404,
            false,
            'Module feature not found.'
        );
    }

    return $row;
}

function mf_validate_module(
    PDO $pdo,
    int $moduleId
): void {
    $stmt=$pdo->prepare("
        SELECT id
        FROM modules
        WHERE id=:id
          AND is_active=1
        LIMIT 1
    ");

    $stmt->execute(
        array(
            ':id'=>$moduleId
        )
    );

    if(!$stmt->fetchColumn()){
        mf_json(
            422,
            false,
            'Selected module is not available.'
        );
    }
}

if($_SERVER['REQUEST_METHOD']!=='POST'){
    mf_json(
        405,
        false,
        'Method not allowed.'
    );
}

$csrf=mf_post('csrf_token');

if(
    empty($_SESSION['module_features_csrf']) ||
    !is_string($_SESSION['module_features_csrf']) ||
    $csrf==='' ||
    !hash_equals(
        $_SESSION['module_features_csrf'],
        $csrf
    )
){
    mf_json(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$action=mf_post('action');

try{

    if($action==='save_feature'){

        $id=(int)mf_post(
            'id',
            '0'
        );

        $moduleId=(int)mf_post(
            'module_id',
            '0'
        );

        $featureName=mf_post(
            'feature_name'
        );

        $featureCode=strtolower(
            mf_post(
                'feature_code'
            )
        );

        $description=mf_post(
            'description'
        );

        $isActive=
            isset($_POST['is_active']) &&
            $_POST['is_active']==='1'
                ? 1
                : 0;

        if($moduleId<=0){
            mf_json(
                422,
                false,
                'Please select a module.'
            );
        }

        if(
            $featureName==='' ||
            strlen($featureName)>150
        ){
            mf_json(
                422,
                false,
                'Feature name is required and must be 150 characters or less.'
            );
        }

        if(
            $featureCode==='' ||
            strlen($featureCode)>120 ||
            !preg_match(
                '/^[a-z0-9][a-z0-9_-]*$/',
                $featureCode
            )
        ){
            mf_json(
                422,
                false,
                'Feature code may contain lowercase letters, numbers, hyphens and underscores only.'
            );
        }

        if(strlen($description)>500){
            mf_json(
                422,
                false,
                'Description must be 500 characters or less.'
            );
        }

        mf_validate_module(
            $pdo,
            $moduleId
        );

        /*
         * Feature codes are unique inside a module.
         * The same feature code may exist in a different module.
         */
        $duplicate=$pdo->prepare("
            SELECT id
            FROM module_features
            WHERE module_id=:module_id
              AND feature_code=:feature_code
              AND id<>:id
            LIMIT 1
        ");

        $duplicate->execute(
            array(
                ':module_id'=>$moduleId,
                ':feature_code'=>$featureCode,
                ':id'=>$id
            )
        );

        if($duplicate->fetchColumn()){
            mf_json(
                409,
                false,
                'This feature code already exists for the selected module.'
            );
        }

        $updatedBy=null;

        if(
            isset($_SESSION['platform_user_id']) &&
            (int)$_SESSION['platform_user_id']>0
        ){
            $updatedBy=
                (int)$_SESSION['platform_user_id'];
        }

        if($id>0){

            mf_find_feature(
                $pdo,
                $id
            );

            $stmt=$pdo->prepare("
                UPDATE module_features
                SET
                    module_id=:module_id,
                    feature_code=:feature_code,
                    feature_name=:feature_name,
                    description=:description,
                    is_active=:is_active,
                    updated_by=:updated_by
                WHERE id=:id
            ");

            $stmt->execute(
                array(
                    ':module_id'=>$moduleId,
                    ':feature_code'=>$featureCode,
                    ':feature_name'=>$featureName,
                    ':description'=>
                        $description===''
                            ? null
                            : $description,
                    ':is_active'=>$isActive,
                    ':updated_by'=>$updatedBy,
                    ':id'=>$id
                )
            );

            mf_json(
                200,
                true,
                'Module feature updated successfully.'
            );
        }

        $stmt=$pdo->prepare("
            INSERT INTO module_features(
                module_id,
                feature_code,
                feature_name,
                description,
                is_active,
                updated_by
            )VALUES(
                :module_id,
                :feature_code,
                :feature_name,
                :description,
                :is_active,
                :updated_by
            )
        ");

        $stmt->execute(
            array(
                ':module_id'=>$moduleId,
                ':feature_code'=>$featureCode,
                ':feature_name'=>$featureName,
                ':description'=>
                    $description===''
                        ? null
                        : $description,
                ':is_active'=>$isActive,
                ':updated_by'=>$updatedBy
            )
        );

        mf_json(
            201,
            true,
            'Module feature created successfully.',
            array(
                'feature_id'=>
                    (int)$pdo->lastInsertId()
            )
        );
    }

    if($action==='toggle_status'){

        $id=(int)mf_post(
            'id',
            '0'
        );

        $isActive=
            mf_post(
                'is_active',
                '0'
            )==='1'
                ? 1
                : 0;

        if($id<=0){
            mf_json(
                422,
                false,
                'Invalid module feature.'
            );
        }

        mf_find_feature(
            $pdo,
            $id
        );

        $updatedBy=null;

        if(
            isset($_SESSION['platform_user_id']) &&
            (int)$_SESSION['platform_user_id']>0
        ){
            $updatedBy=
                (int)$_SESSION['platform_user_id'];
        }

        $stmt=$pdo->prepare("
            UPDATE module_features
            SET
                is_active=:is_active,
                updated_by=:updated_by
            WHERE id=:id
        ");

        $stmt->execute(
            array(
                ':is_active'=>$isActive,
                ':updated_by'=>$updatedBy,
                ':id'=>$id
            )
        );

        mf_json(
            200,
            true,
            $isActive
                ? 'Module feature activated successfully.'
                : 'Module feature deactivated successfully.'
        );
    }

    mf_json(
        400,
        false,
        'Invalid action.'
    );

}catch(Throwable $e){

    error_log(
        'FieldPlx Module Features API Error: ' .
        $e->getMessage()
    );

    mf_json(
        500,
        false,
        'Unable to complete the module feature action.'
    );
}
