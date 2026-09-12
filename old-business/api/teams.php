<?php
ob_start();

ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

function teamsResponse(
    $status,
    $success,
    $message,
    $extra = array()
) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code((int)$status);

    echo json_encode(
        array_merge(
            array(
                'success'=>(bool)$success,
                'message'=>(string)$message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function teamsPost($key,$default='')
{
    return isset($_POST[$key])
        ? $_POST[$key]
        : $default;
}

function teamsJson($value)
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    return $json === false
        ? null
        : $json;
}

function teamsGet(
    PDO $pdo,
    $tenantId,
    $teamId
) {
    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.tenant_id,
            t.branch_id,
            t.department_id,
            t.name,
            t.code,
            t.leader_user_id,
            t.description,
            t.status,
            t.created_at,
            t.updated_at
        FROM teams t
        WHERE t.id = :id
          AND t.tenant_id = :tenant_id
        LIMIT 1
    ");

    $stmt->execute(array(
        ':id'=>(int)$teamId,
        ':tenant_id'=>(int)$tenantId
    ));

    $team = $stmt->fetch();

    if (!$team) {
        teamsResponse(
            404,
            false,
            'Team not found.'
        );
    }

    return $team;
}

function teamsMeta(
    PDO $pdo,
    $tenantId
) {
    $branchesStmt = $pdo->prepare("
        SELECT id,name,branch_code
        FROM branches
        WHERE tenant_id = :tenant_id
          AND status = 'active'
        ORDER BY
            is_head_office DESC,
            name ASC
    ");

    $branchesStmt->execute(array(
        ':tenant_id'=>(int)$tenantId
    ));

    $departmentsStmt = $pdo->prepare("
        SELECT id,branch_id,name,code
        FROM departments
        WHERE tenant_id = :tenant_id
          AND status = 'active'
        ORDER BY name ASC
    ");

    $departmentsStmt->execute(array(
        ':tenant_id'=>(int)$tenantId
    ));

    $usersStmt = $pdo->prepare("
        SELECT
            id,
            employee_code,
            first_name,
            last_name,
            job_title,
            CONCAT(
                first_name,
                CASE
                    WHEN last_name IS NOT NULL
                         AND last_name <> ''
                    THEN CONCAT(' ',last_name)
                    ELSE ''
                END
            ) AS name
        FROM users
        WHERE tenant_id = :tenant_id
          AND status = 'active'
          AND deleted_at IS NULL
        ORDER BY first_name,last_name
    ");

    $usersStmt->execute(array(
        ':tenant_id'=>(int)$tenantId
    ));

    return array(
        'branches'=>$branchesStmt->fetchAll(),
        'departments'=>$departmentsStmt->fetchAll(),
        'users'=>$usersStmt->fetchAll()
    );
}

function teamsValidateTenantId(
    PDO $pdo,
    $table,
    $tenantId,
    $id
) {
    if ($id <= 0) {
        return null;
    }

    if (!in_array(
        $table,
        array(
            'branches',
            'departments',
            'users'
        ),
        true
    )) {
        return null;
    }

    $sql = "
        SELECT id
        FROM ".$table."
        WHERE id = :id
          AND tenant_id = :tenant_id
    ";

    if ($table === 'users') {
        $sql .= "
          AND status = 'active'
          AND deleted_at IS NULL
        ";
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);

    $stmt->execute(array(
        ':id'=>(int)$id,
        ':tenant_id'=>(int)$tenantId
    ));

    return $stmt->fetchColumn()
        ? (int)$id
        : null;
}

function teamsMembers(
    PDO $pdo,
    $tenantId,
    $teamId
) {
    $stmt = $pdo->prepare("
        SELECT
            tm.team_id,
            tm.user_id,
            tm.member_role,
            tm.is_primary,
            tm.joined_at,

            u.first_name,
            u.last_name,
            u.employee_code,
            u.job_title

        FROM team_members tm

        INNER JOIN teams t
            ON t.id = tm.team_id
           AND t.tenant_id = :tenant_id

        INNER JOIN users u
            ON u.id = tm.user_id
           AND u.tenant_id = t.tenant_id

        WHERE tm.team_id = :team_id

        ORDER BY
            tm.is_primary DESC,
            u.first_name ASC,
            u.last_name ASC
    ");

    $stmt->execute(array(
        ':tenant_id'=>(int)$tenantId,
        ':team_id'=>(int)$teamId
    ));

    return $stmt->fetchAll();
}

function teamsActivity(
    PDO $pdo,
    $tenantId,
    $branchId,
    $userId,
    $eventType,
    $teamId,
    $title,
    $details
) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_events (
                tenant_id,
                branch_id,
                actor_user_id,
                actor_type,
                event_type,
                related_type,
                related_id,
                title,
                details_json,
                visible_to_client
            ) VALUES (
                :tenant_id,
                :branch_id,
                :actor_user_id,
                'user',
                :event_type,
                'team',
                :related_id,
                :title,
                :details_json,
                0
            )
        ");

        $stmt->execute(array(
            ':tenant_id'=>(int)$tenantId,
            ':branch_id'=>$branchId > 0 ? (int)$branchId : null,
            ':actor_user_id'=>(int)$userId,
            ':event_type'=>substr((string)$eventType,0,120),
            ':related_id'=>$teamId > 0 ? (int)$teamId : null,
            ':title'=>substr((string)$title,0,255),
            ':details_json'=>teamsJson($details)
        ));
    } catch (Throwable $e) {
        error_log(
            'FieldPlx team activity log error: ' .
            $e->getMessage()
        );
    }
}

function teamsAudit(
    PDO $pdo,
    $tenantId,
    $branchId,
    $userId,
    $action,
    $teamId,
    $oldValues,
    $newValues
) {
    if (function_exists('tenantAuditLog')) {
        tenantAuditLog(
            $pdo,
            $action,
            $tenantId,
            $branchId,
            $userId,
            'team',
            $teamId,
            $oldValues,
            $newValues
        );

        return;
    }
}

$tenantId =
    isset($_SESSION['tenant_id'])
        ? (int)$_SESSION['tenant_id']
        : 0;

$userId =
    isset($_SESSION['tenant_user_id'])
        ? (int)$_SESSION['tenant_user_id']
        : 0;

$sessionBranchId =
    isset($_SESSION['branch_id'])
        ? (int)$_SESSION['branch_id']
        : 0;

if ($tenantId <= 0 || $userId <= 0) {
    teamsResponse(
        401,
        false,
        'Authentication required.'
    );
}

$csrf =
    (string)teamsPost(
        'csrf_token',
        ''
    );

$sessionCsrf =
    isset($_SESSION['teams_csrf_token'])
        ? (string)$_SESSION['teams_csrf_token']
        : '';

if (
    $csrf === '' ||
    $sessionCsrf === '' ||
    !hash_equals(
        $sessionCsrf,
        $csrf
    )
) {
    teamsResponse(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$action =
    trim(
        (string)teamsPost(
            'action',
            ''
        )
    );

try {

    if ($action === 'list') {

        $page =
            max(
                1,
                (int)teamsPost(
                    'page',
                    1
                )
            );

        $perPage =
            (int)teamsPost(
                'per_page',
                10
            );

        if (!in_array(
            $perPage,
            array(10,25,50),
            true
        )) {
            $perPage = 10;
        }

        $search =
            trim(
                (string)teamsPost(
                    'search',
                    ''
                )
            );

        $status =
            trim(
                (string)teamsPost(
                    'status',
                    ''
                )
            );

        $branchFilter =
            (int)teamsPost(
                'branch_id',
                0
            );

        $departmentFilter =
            (int)teamsPost(
                'department_id',
                0
            );

        $where = array(
            't.tenant_id = :tenant_id'
        );

        $params = array(
            ':tenant_id'=>$tenantId
        );

        if ($search !== '') {
            $searchValue = '%' . $search . '%';

            $where[] = "(
                t.name LIKE :search1
                OR t.code LIKE :search2
                OR CONCAT(
                    COALESCE(lu.first_name,''),
                    ' ',
                    COALESCE(lu.last_name,'')
                ) LIKE :search3
            )";

            $params[':search1'] = $searchValue;
            $params[':search2'] = $searchValue;
            $params[':search3'] = $searchValue;
        }

        if (in_array(
            $status,
            array('active','inactive'),
            true
        )) {
            $where[] = 't.status = :status';
            $params[':status'] = $status;
        }

        if ($branchFilter > 0) {
            $where[] = 't.branch_id = :branch_id';
            $params[':branch_id'] = $branchFilter;
        }

        if ($departmentFilter > 0) {
            $where[] =
                't.department_id = :department_id';

            $params[':department_id'] =
                $departmentFilter;
        }

        $whereSql =
            implode(
                ' AND ',
                $where
            );

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM teams t
            LEFT JOIN users lu
                ON lu.id = t.leader_user_id
               AND lu.tenant_id = t.tenant_id
            WHERE $whereSql
        ");

        $countStmt->execute($params);

        $total =
            (int)$countStmt->fetchColumn();

        $pages =
            max(
                1,
                (int)ceil(
                    $total / $perPage
                )
            );

        if ($page > $pages) {
            $page = $pages;
        }

        $offset =
            ($page - 1) *
            $perPage;

        $sql = "
            SELECT
                t.id,
                t.name,
                t.code,
                t.status,
                t.created_at,
                t.updated_at,

                b.name AS branch_name,
                d.name AS department_name,

                CONCAT(
                    COALESCE(lu.first_name,''),
                    CASE
                        WHEN lu.last_name IS NOT NULL
                             AND lu.last_name <> ''
                        THEN CONCAT(' ',lu.last_name)
                        ELSE ''
                    END
                ) AS leader_name,

                (
                    SELECT COUNT(*)
                    FROM team_members tm
                    WHERE tm.team_id = t.id
                ) AS member_count

            FROM teams t

            LEFT JOIN branches b
                ON b.id = t.branch_id
               AND b.tenant_id = t.tenant_id

            LEFT JOIN departments d
                ON d.id = t.department_id
               AND d.tenant_id = t.tenant_id

            LEFT JOIN users lu
                ON lu.id = t.leader_user_id
               AND lu.tenant_id = t.tenant_id

            WHERE $whereSql

            ORDER BY
                t.status = 'active' DESC,
                t.name ASC

            LIMIT " .
            (int)$perPage .
            " OFFSET " .
            (int)$offset;

        $stmt =
            $pdo->prepare($sql);

        $stmt->execute($params);

        $rows =
            $stmt->fetchAll();

        $summaryStmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(
                    CASE
                        WHEN status = 'active'
                        THEN 1
                        ELSE 0
                    END
                ) AS active,
                COUNT(DISTINCT branch_id) AS branches
            FROM teams
            WHERE tenant_id = :tenant_id
        ");

        $summaryStmt->execute(array(
            ':tenant_id'=>$tenantId
        ));

        $summary =
            $summaryStmt->fetch();

        $memberStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT tm.user_id)
            FROM team_members tm
            INNER JOIN teams t
                ON t.id = tm.team_id
               AND t.tenant_id = :tenant_id
        ");

        $memberStmt->execute(array(
            ':tenant_id'=>$tenantId
        ));

        $members =
            (int)$memberStmt->fetchColumn();

        $from =
            $total > 0
                ? $offset + 1
                : 0;

        $to =
            $total > 0
                ? min(
                    $offset +
                    count($rows),
                    $total
                )
                : 0;

        teamsResponse(
            200,
            true,
            'Teams loaded.',
            array(
                'teams'=>$rows,
                'meta'=>teamsMeta(
                    $pdo,
                    $tenantId
                ),
                'summary'=>array(
                    'total'=>(int)($summary['total'] ?? 0),
                    'active'=>(int)($summary['active'] ?? 0),
                    'members'=>$members,
                    'branches'=>(int)($summary['branches'] ?? 0)
                ),
                'pagination'=>array(
                    'page'=>$page,
                    'per_page'=>$perPage,
                    'total'=>$total,
                    'pages'=>$pages,
                    'from'=>$from,
                    'to'=>$to
                )
            )
        );
    }

    if ($action === 'get') {

        $teamId =
            (int)teamsPost(
                'team_id',
                0
            );

        if ($teamId <= 0) {
            teamsResponse(
                422,
                false,
                'Invalid team.'
            );
        }

        teamsResponse(
            200,
            true,
            'Team loaded.',
            array(
                'team'=>teamsGet(
                    $pdo,
                    $tenantId,
                    $teamId
                ),
                'members'=>teamsMembers(
                    $pdo,
                    $tenantId,
                    $teamId
                ),
                'meta'=>teamsMeta(
                    $pdo,
                    $tenantId
                )
            )
        );
    }

    if ($action === 'save') {

        $teamId =
            (int)teamsPost(
                'team_id',
                0
            );

        $name =
            trim(
                (string)teamsPost(
                    'name',
                    ''
                )
            );

        $code =
            trim(
                (string)teamsPost(
                    'code',
                    ''
                )
            );

        $branchId =
            (int)teamsPost(
                'branch_id',
                0
            );

        $departmentId =
            (int)teamsPost(
                'department_id',
                0
            );

        $leaderUserId =
            (int)teamsPost(
                'leader_user_id',
                0
            );

        $description =
            trim(
                (string)teamsPost(
                    'description',
                    ''
                )
            );

        $status =
            trim(
                (string)teamsPost(
                    'status',
                    'active'
                )
            );

        if ($name === '') {
            teamsResponse(
                422,
                false,
                'Team name is required.'
            );
        }

        if (!in_array(
            $status,
            array('active','inactive'),
            true
        )) {
            teamsResponse(
                422,
                false,
                'Invalid team status.'
            );
        }

        $branchId =
            teamsValidateTenantId(
                $pdo,
                'branches',
                $tenantId,
                $branchId
            );

        $departmentId =
            teamsValidateTenantId(
                $pdo,
                'departments',
                $tenantId,
                $departmentId
            );

        $leaderUserId =
            teamsValidateTenantId(
                $pdo,
                'users',
                $tenantId,
                $leaderUserId
            );

        if (
            $departmentId !== null &&
            $branchId !== null
        ) {
            $depStmt = $pdo->prepare("
                SELECT branch_id
                FROM departments
                WHERE id = :id
                  AND tenant_id = :tenant_id
                LIMIT 1
            ");

            $depStmt->execute(array(
                ':id'=>$departmentId,
                ':tenant_id'=>$tenantId
            ));

            $departmentBranch =
                $depStmt->fetchColumn();

            if (
                $departmentBranch !== null &&
                (int)$departmentBranch > 0 &&
                (int)$departmentBranch !==
                (int)$branchId
            ) {
                teamsResponse(
                    422,
                    false,
                    'Selected department does not belong to the selected branch.'
                );
            }
        }

        $duplicateSql = "
            SELECT id
            FROM teams
            WHERE tenant_id = :tenant_id
              AND name = :name
        ";

        $duplicateParams = array(
            ':tenant_id'=>$tenantId,
            ':name'=>$name
        );

        if ($teamId > 0) {
            $duplicateSql .=
                " AND id <> :id";

            $duplicateParams[':id'] =
                $teamId;
        }

        $duplicateStmt =
            $pdo->prepare(
                $duplicateSql
            );

        $duplicateStmt->execute(
            $duplicateParams
        );

        if ($duplicateStmt->fetchColumn()) {
            teamsResponse(
                409,
                false,
                'A team with this name already exists.'
            );
        }

        if ($code !== '') {
            $codeSql = "
                SELECT id
                FROM teams
                WHERE tenant_id = :tenant_id
                  AND code = :code
            ";

            $codeParams = array(
                ':tenant_id'=>$tenantId,
                ':code'=>$code
            );

            if ($teamId > 0) {
                $codeSql .=
                    " AND id <> :id";

                $codeParams[':id'] =
                    $teamId;
            }

            $codeStmt =
                $pdo->prepare(
                    $codeSql
                );

            $codeStmt->execute(
                $codeParams
            );

            if ($codeStmt->fetchColumn()) {
                teamsResponse(
                    409,
                    false,
                    'This team code is already in use.'
                );
            }
        }

        $postedMembers =
            isset($_POST['members']) &&
            is_array($_POST['members'])
                ? $_POST['members']
                : array();

        $members = array();

        foreach (
            $postedMembers
            as $member
        ) {
            if (!is_array($member)) {
                continue;
            }

            $memberUserId =
                isset($member['user_id'])
                    ? (int)$member['user_id']
                    : 0;

            $validUser =
                teamsValidateTenantId(
                    $pdo,
                    'users',
                    $tenantId,
                    $memberUserId
                );

            if ($validUser === null) {
                continue;
            }

            $members[$validUser] = array(
                'user_id'=>$validUser,
                'member_role'=>isset($member['member_role'])
                    ? substr(
                        trim(
                            (string)$member['member_role']
                        ),
                        0,
                        120
                    )
                    : null,
                'is_primary'=>!empty($member['is_primary'])
                    ? 1
                    : 0
            );
        }

        /*
         * If leader is selected, ensure leader is also a team member.
         */
        if ($leaderUserId !== null) {
            if (!isset($members[$leaderUserId])) {
                $members[$leaderUserId] = array(
                    'user_id'=>$leaderUserId,
                    'member_role'=>'Team Leader',
                    'is_primary'=>1
                );
            } else {
                $members[$leaderUserId]['is_primary'] = 1;

                if (
                    empty(
                        $members[$leaderUserId]['member_role']
                    )
                ) {
                    $members[$leaderUserId]['member_role'] =
                        'Team Leader';
                }
            }
        }

        $oldTeam = null;
        $oldMembers = array();

        if ($teamId > 0) {
            $oldTeam =
                teamsGet(
                    $pdo,
                    $tenantId,
                    $teamId
                );

            $oldMembers =
                teamsMembers(
                    $pdo,
                    $tenantId,
                    $teamId
                );
        }

        $pdo->beginTransaction();

        try {

            if ($teamId > 0) {

                $stmt = $pdo->prepare("
                    UPDATE teams
                    SET
                        branch_id = :branch_id,
                        department_id = :department_id,
                        name = :name,
                        code = :code,
                        leader_user_id = :leader_user_id,
                        description = :description,
                        status = :status
                    WHERE id = :id
                      AND tenant_id = :tenant_id
                ");

                $stmt->execute(array(
                    ':branch_id'=>$branchId,
                    ':department_id'=>$departmentId,
                    ':name'=>$name,
                    ':code'=>$code !== '' ? $code : null,
                    ':leader_user_id'=>$leaderUserId,
                    ':description'=>$description !== '' ? $description : null,
                    ':status'=>$status,
                    ':id'=>$teamId,
                    ':tenant_id'=>$tenantId
                ));

            } else {

                $stmt = $pdo->prepare("
                    INSERT INTO teams (
                        tenant_id,
                        branch_id,
                        department_id,
                        name,
                        code,
                        leader_user_id,
                        description,
                        status
                    ) VALUES (
                        :tenant_id,
                        :branch_id,
                        :department_id,
                        :name,
                        :code,
                        :leader_user_id,
                        :description,
                        :status
                    )
                ");

                $stmt->execute(array(
                    ':tenant_id'=>$tenantId,
                    ':branch_id'=>$branchId,
                    ':department_id'=>$departmentId,
                    ':name'=>$name,
                    ':code'=>$code !== '' ? $code : null,
                    ':leader_user_id'=>$leaderUserId,
                    ':description'=>$description !== '' ? $description : null,
                    ':status'=>$status
                ));

                $teamId =
                    (int)$pdo->lastInsertId();
            }

            $deleteMembers =
                $pdo->prepare("
                    DELETE FROM team_members
                    WHERE team_id = :team_id
                ");

            $deleteMembers->execute(array(
                ':team_id'=>$teamId
            ));

            if (!empty($members)) {

                $insertMember =
                    $pdo->prepare("
                        INSERT INTO team_members (
                            team_id,
                            user_id,
                            member_role,
                            is_primary
                        ) VALUES (
                            :team_id,
                            :user_id,
                            :member_role,
                            :is_primary
                        )
                    ");

                foreach (
                    $members
                    as $member
                ) {
                    $insertMember->execute(array(
                        ':team_id'=>$teamId,
                        ':user_id'=>$member['user_id'],
                        ':member_role'=>$member['member_role'] !== ''
                            ? $member['member_role']
                            : null,
                        ':is_primary'=>$member['is_primary']
                    ));
                }
            }

            $pdo->commit();

            $newTeam =
                teamsGet(
                    $pdo,
                    $tenantId,
                    $teamId
                );

            $newMembers =
                teamsMembers(
                    $pdo,
                    $tenantId,
                    $teamId
                );

            teamsActivity(
                $pdo,
                $tenantId,
                $sessionBranchId,
                $userId,
                $oldTeam
                    ? 'team_updated'
                    : 'team_created',
                $teamId,
                $oldTeam
                    ? 'Team updated: '.$name
                    : 'Team created: '.$name,
                array(
                    'team'=>$newTeam,
                    'members'=>$newMembers
                )
            );

            teamsAudit(
                $pdo,
                $tenantId,
                $sessionBranchId,
                $userId,
                $oldTeam
                    ? 'TEAM_UPDATED'
                    : 'TEAM_CREATED',
                $teamId,
                $oldTeam
                    ? array(
                        'team'=>$oldTeam,
                        'members'=>$oldMembers
                    )
                    : null,
                array(
                    'team'=>$newTeam,
                    'members'=>$newMembers
                )
            );

            teamsResponse(
                200,
                true,
                $oldTeam
                    ? 'Team updated successfully.'
                    : 'Team created successfully.',
                array(
                    'team_id'=>$teamId
                )
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    if ($action === 'change_status') {

        $teamId =
            (int)teamsPost(
                'team_id',
                0
            );

        $status =
            trim(
                (string)teamsPost(
                    'status',
                    ''
                )
            );

        if (
            $teamId <= 0 ||
            !in_array(
                $status,
                array('active','inactive'),
                true
            )
        ) {
            teamsResponse(
                422,
                false,
                'Invalid team status request.'
            );
        }

        $oldTeam =
            teamsGet(
                $pdo,
                $tenantId,
                $teamId
            );

        $stmt = $pdo->prepare("
            UPDATE teams
            SET status = :status
            WHERE id = :id
              AND tenant_id = :tenant_id
        ");

        $stmt->execute(array(
            ':status'=>$status,
            ':id'=>$teamId,
            ':tenant_id'=>$tenantId
        ));

        $newTeam =
            teamsGet(
                $pdo,
                $tenantId,
                $teamId
            );

        teamsActivity(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            'team_status_changed',
            $teamId,
            'Team status changed: ' .
            $newTeam['name'],
            array(
                'old_status'=>$oldTeam['status'],
                'new_status'=>$newTeam['status']
            )
        );

        teamsAudit(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            'TEAM_STATUS_CHANGED',
            $teamId,
            array(
                'status'=>$oldTeam['status']
            ),
            array(
                'status'=>$newTeam['status']
            )
        );

        teamsResponse(
            200,
            true,
            'Team status updated successfully.'
        );
    }

    if ($action === 'delete') {

        $teamId =
            (int)teamsPost(
                'team_id',
                0
            );

        if ($teamId <= 0) {
            teamsResponse(
                422,
                false,
                'Invalid team.'
            );
        }

        $team =
            teamsGet(
                $pdo,
                $tenantId,
                $teamId
            );

        $members =
            teamsMembers(
                $pdo,
                $tenantId,
                $teamId
            );

        /*
         * teams is referenced by tasks via assigned_team_id.
         * FK is ON DELETE SET NULL, so deletion is safe for task history.
         * team_members cascades by FK.
         */
        $stmt = $pdo->prepare("
            DELETE FROM teams
            WHERE id = :id
              AND tenant_id = :tenant_id
        ");

        $stmt->execute(array(
            ':id'=>$teamId,
            ':tenant_id'=>$tenantId
        ));

        teamsActivity(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            'team_deleted',
            $teamId,
            'Team deleted: '.$team['name'],
            array(
                'team'=>$team,
                'members'=>$members
            )
        );

        teamsAudit(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            'TEAM_DELETED',
            $teamId,
            array(
                'team'=>$team,
                'members'=>$members
            ),
            null
        );

        teamsResponse(
            200,
            true,
            'Team deleted successfully.'
        );
    }

    teamsResponse(
        400,
        false,
        'Unsupported teams action.'
    );

} catch (PDOException $e) {

    error_log(
        'FieldPlx teams PDO error: ' .
        $e->getMessage()
    );

    if (
        isset($e->errorInfo[1]) &&
        (int)$e->errorInfo[1] === 1062
    ) {
        teamsResponse(
            409,
            false,
            'Team name or code already exists.'
        );
    }

    teamsResponse(
        500,
        false,
        'Unable to process the teams request.'
    );

} catch (Throwable $e) {

    error_log(
        'FieldPlx teams API error: ' .
        $e->getMessage()
    );

    teamsResponse(
        500,
        false,
        'Unable to process the teams request.'
    );
}
