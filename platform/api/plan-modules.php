<?php
declare(strict_types=1);

ob_start();

ini_set(
    'display_errors',
    '0'
);

ini_set(
    'html_errors',
    '0'
);

ini_set(
    'log_errors',
    '1'
);

header(
    'Content-Type: application/json; charset=utf-8'
);

require_once __DIR__ . '/../includes/db.php';

if (
    session_status() === PHP_SESSION_NONE
) {
    session_start();
}

function pm_post(
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

function pm_json(
    int $status,
    bool $success,
    string $message,
    array $extra = array()
): void {

    while (
        ob_get_level() > 0
    ) {
        @ob_end_clean();
    }

    http_response_code(
        $status
    );

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

function pm_validate_plan(
    PDO $pdo,
    int $planId
): void {

    $stmt=
        $pdo->prepare("
            SELECT id
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

    if(
        !$stmt->fetchColumn()
    ) {

        pm_json(
            404,
            false,
            'Plan not found.'
        );
    }
}

if(
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {

    pm_json(
        405,
        false,
        'Method not allowed.'
    );
}

$csrf=
    pm_post(
        'csrf_token'
    );

if(
    empty(
        $_SESSION[
            'plan_modules_csrf'
        ]
    ) ||
    !is_string(
        $_SESSION[
            'plan_modules_csrf'
        ]
    ) ||
    $csrf === '' ||
    !hash_equals(
        $_SESSION[
            'plan_modules_csrf'
        ],
        $csrf
    )
) {

    pm_json(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$action=
    pm_post(
        'action'
    );

try {

    if(
        $action ===
        'save_plan_modules'
    ) {

        $planId=
            (int)pm_post(
                'plan_id',
                '0'
            );

        if(
            $planId <= 0
        ) {

            pm_json(
                422,
                false,
                'Please select a plan.'
            );
        }

        pm_validate_plan(
            $pdo,
            $planId
        );

        $selectedIds=
            isset(
                $_POST[
                    'module_ids'
                ]
            ) &&
            is_array(
                $_POST[
                    'module_ids'
                ]
            )
                ? $_POST[
                    'module_ids'
                ]
                : array();

        $cleanIds=
            array();

        foreach(
            $selectedIds as $value
        ) {

            $moduleId=
                (int)$value;

            if(
                $moduleId > 0
            ) {

                $cleanIds[
                    $moduleId
                ]=
                    $moduleId;
            }
        }

        $cleanIds=
            array_values(
                $cleanIds
            );

        /*
        |--------------------------------------------------------------------------
        | Validate modules and enforce hierarchy
        |--------------------------------------------------------------------------
        |
        | If a child module is selected,
        | its parent is automatically included.
        |
        */

        $moduleRows=
            $pdo->query("
                SELECT
                    id,
                    parent_id,
                    is_active
                FROM modules
            ")->fetchAll();

        $moduleMap=
            array();

        foreach(
            $moduleRows as $row
        ) {

            $moduleMap[
                (int)$row['id']
            ]=
                $row;
        }

        $finalIds=
            array();

        foreach(
            $cleanIds as $moduleId
        ) {

            if(
                !isset(
                    $moduleMap[
                        $moduleId
                    ]
                )
            ) {
                continue;
            }

            if(
                (int)$moduleMap[
                    $moduleId
                ]['is_active'] !== 1
            ) {
                continue;
            }

            $finalIds[
                $moduleId
            ]=
                $moduleId;

            $parentId=
                (int)(
                    $moduleMap[
                        $moduleId
                    ]['parent_id']
                    ?: 0
                );

            if(
                $parentId > 0 &&
                isset(
                    $moduleMap[
                        $parentId
                    ]
                ) &&
                (int)$moduleMap[
                    $parentId
                ]['is_active'] === 1
            ) {

                $finalIds[
                    $parentId
                ]=
                    $parentId;
            }
        }

        $finalIds=
            array_values(
                $finalIds
            );

        $pdo->beginTransaction();

        /*
        |--------------------------------------------------------------------------
        | Ensure every module has a row for this plan
        |--------------------------------------------------------------------------
        */

        $upsert=
            $pdo->prepare("
                INSERT INTO plan_modules(
                    plan_id,
                    module_id,
                    is_enabled
                )
                VALUES(
                    :plan_id,
                    :module_id,
                    :is_enabled
                )
                ON DUPLICATE KEY UPDATE
                    is_enabled=
                        VALUES(is_enabled)
            ");

        foreach(
            $moduleMap as $moduleId => $moduleRow
        ) {

            $enabled=
                in_array(
                    (int)$moduleId,
                    $finalIds,
                    true
                )
                    ? 1
                    : 0;

            $upsert->execute(
                array(
                    ':plan_id'=>
                        $planId,
                    ':module_id'=>
                        (int)$moduleId,
                    ':is_enabled'=>
                        $enabled
                )
            );
        }

        $pdo->commit();

        pm_json(
            200,
            true,
            'Plan modules updated successfully.',
            array(
                'enabled_count'=>
                    count(
                        $finalIds
                    )
            )
        );
    }

    pm_json(
        400,
        false,
        'Invalid action.'
    );

} catch(
    Throwable $e
) {

    if(
        isset($pdo) &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {

        $pdo->rollBack();

    }

    error_log(
        'FieldPlx Plan Modules API Error: ' .
        $e->getMessage()
    );

    pm_json(
        500,
        false,
        'Unable to complete the plan module action.'
    );
}
