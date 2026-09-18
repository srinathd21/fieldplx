<?php
/**
 * FieldPlx Manage Team API
 * Compatible with PHP 7.2+ / MariaDB 11.x
 */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

function tm_out($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$status);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tm_post($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}

function tm_table(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $q->execute(array(':t' => $table));
    $cache[$table] = ((int)$q->fetchColumn() > 0);
    return $cache[$table];
}

function tm_column(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = ((int)$q->fetchColumn() > 0);
    return $cache[$key];
}

function tm_csrf()
{
    $posted = tm_post('csrf_token');
    $saved = isset($_SESSION['team_settings_csrf']) ? (string)$_SESSION['team_settings_csrf'] : '';
    if ($saved === '' || $posted === '' || !hash_equals($saved, $posted)) {
        tm_out(419, false, 'Your form session expired. Refresh the page and try again.');
    }
}

function tm_actor(PDO $pdo, $tenantId, $userId)
{
    $q = $pdo->prepare("SELECT id,tenant_id,role_id,is_tenant_admin,status FROM users WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id'=>$userId, ':t'=>$tenantId));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row || (string)$row['status'] !== 'active') {
        tm_out(401, false, 'Your login session is no longer active.');
    }
    return $row;
}

function tm_permission(PDO $pdo, $tenantId, $userId, $roleId, $isAdmin, $code)
{
    if ((int)$isAdmin === 1) return true;
    if (!tm_table($pdo, 'permissions')) return true;

    $q = $pdo->prepare("SELECT id FROM permissions WHERE permission_code=:c LIMIT 1");
    $q->execute(array(':c'=>$code));
    $permissionId = (int)$q->fetchColumn();
    if ($permissionId <= 0) return true; // backward-compatible when a permission is not installed yet

    if (tm_table($pdo, 'user_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u AND permission_id=:p LIMIT 1");
        $q->execute(array(':t'=>$tenantId, ':u'=>$userId, ':p'=>$permissionId));
        $override = $q->fetchColumn();
        if ($override !== false) return ((string)$override === 'allow');
    }

    if ($roleId > 0 && tm_table($pdo, 'role_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=:t AND role_id=:r AND permission_id=:p LIMIT 1");
        $q->execute(array(':t'=>$tenantId, ':r'=>$roleId, ':p'=>$permissionId));
        $access = $q->fetchColumn();
        if ($access !== false) return ((string)$access === 'allow');
    }
    return false;
}

function tm_can_any(PDO $pdo, $tenantId, $actor, $codes)
{
    foreach ($codes as $code) {
        if (tm_permission($pdo, $tenantId, (int)$actor['id'], (int)$actor['role_id'], (int)$actor['is_tenant_admin'], $code)) return true;
    }
    return false;
}

function tm_require_any(PDO $pdo, $tenantId, $actor, $codes)
{
    if (!tm_can_any($pdo, $tenantId, $actor, $codes)) {
        tm_out(403, false, 'You do not have permission to manage team members.');
    }
}

function tm_member(PDO $pdo, $tenantId, $userId)
{
    $q = $pdo->prepare("SELECT u.*,r.name AS role_name,r.code AS role_code,r.is_admin AS role_is_admin FROM users u LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id WHERE u.id=:id AND u.tenant_id=:t AND u.deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id'=>$userId, ':t'=>$tenantId));
    return $q->fetch(PDO::FETCH_ASSOC);
}

function tm_normalize_ids($values)
{
    $out = array();
    if (!is_array($values)) return $out;
    foreach ($values as $v) {
        $id = (int)$v;
        if ($id > 0) $out[$id] = $id;
    }
    return array_values($out);
}

function tm_audit(PDO $pdo, $tenantId, $branchId, $actorId, $action, $objectId, $newValues)
{
    if (function_exists('tenantAuditLog')) {
        try {
            tenantAuditLog($pdo, $action, $tenantId, $branchId > 0 ? $branchId : null, $actorId, 'user', $objectId, null, $newValues);
        } catch (Throwable $ignore) {
            // Do not fail the main team action if the optional audit helper has a different installation signature.
        }
    }
}

$tenantId = isset($currentTenantId) ? (int)$currentTenantId : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);
$actorId = isset($currentTenantUserId) ? (int)$currentTenantUserId : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0));
$branchId = isset($currentBranchId) ? (int)$currentBranchId : (isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0);
if ($tenantId <= 0 || $actorId <= 0) tm_out(401, false, 'Tenant session is not available.');
$actor = tm_actor($pdo, $tenantId, $actorId);

$action = tm_post('action', isset($_GET['action']) ? (string)$_GET['action'] : 'list');

try {
    if ($action === 'list') {
        tm_require_any($pdo, $tenantId, $actor, array('employees.view','teams.view','administration.view'));
        $search = tm_post('search');
        $page = max(1, (int)tm_post('page', '1'));
        $perPage = (int)tm_post('per_page', '25');
        if (!in_array($perPage, array(10,25,50), true)) $perPage = 25;
        $where = "u.tenant_id=:t AND u.deleted_at IS NULL";
        $params = array(':t'=>$tenantId);
        if ($search !== '') {
            $where .= " AND (u.first_name LIKE :s OR COALESCE(u.last_name,'') LIKE :s OR u.email LIKE :s OR COALESCE(u.phone,'') LIKE :s OR COALESCE(u.employee_code,'') LIKE :s OR COALESCE(r.name,'') LIKE :s)";
            $params[':s'] = '%' . $search . '%';
        }
        $q = $pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id WHERE $where");
        $q->execute($params);
        $total = (int)$q->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $perPage;

        $deviceSelect = tm_table($pdo, 'user_devices')
            ? ", (SELECT COUNT(*) FROM user_devices d WHERE d.tenant_id=u.tenant_id AND d.user_id=u.id AND d.status='active') AS active_session_count"
            : ", 0 AS active_session_count";
        $sql = "SELECT u.id,u.first_name,u.last_name,u.email,u.phone,u.avatar_path,u.role_id,u.is_tenant_admin,u.status,u.last_login_at,u.created_at,r.name AS role_name,r.code AS role_code $deviceSelect FROM users u LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id WHERE $where ORDER BY u.is_tenant_admin DESC, u.first_name ASC, u.last_name ASC, u.id ASC LIMIT :lim OFFSET :off";
        $q = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $q->bindValue($k, $v, PDO::PARAM_STR);
        $q->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $q->bindValue(':off', $offset, PDO::PARAM_INT);
        $q->execute();
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        $q = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id=:t AND deleted_at IS NULL");
        $q->execute(array(':t'=>$tenantId));
        $assigned = (int)$q->fetchColumn();

        tm_out(200, true, 'Team members loaded.', array(
            'members'=>$rows,
            'pagination'=>array('page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages,'from'=>$total ? $offset+1 : 0,'to'=>min($offset+$perPage,$total)),
            'seats'=>array('assigned'=>$assigned,'included'=>1,'paid'=>0,'total'=>max(1,$assigned),'changes_available'=>false),
            'can_invite'=>tm_can_any($pdo,$tenantId,$actor,array('employees.create','administration.create','teams.create'))
        ));
    }

    if ($action === 'meta') {
        tm_require_any($pdo, $tenantId, $actor, array('employees.view','teams.view','administration.view'));
        $roles = array();
        if (tm_table($pdo, 'roles')) {
            $q = $pdo->prepare("SELECT id,name,code,is_admin FROM roles WHERE tenant_id=:t AND status='active' ORDER BY is_admin DESC,name ASC");
            $q->execute(array(':t'=>$tenantId));
            $roles = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        $permissions = array();
        if (tm_table($pdo, 'permissions')) {
            $q = $pdo->query("SELECT id,permission_code,action_code,description FROM permissions ORDER BY permission_code ASC");
            $permissions = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        tm_out(200, true, 'Team metadata loaded.', array('roles'=>$roles,'permissions'=>$permissions));
    }

    if ($action === 'get_member') {
        tm_require_any($pdo, $tenantId, $actor, array('employees.view','teams.view','administration.view'));
        $id = (int)tm_post('user_id','0');
        if ($id <= 0) tm_out(422, false, 'Team member is required.');
        $member = tm_member($pdo, $tenantId, $id);
        if (!$member) tm_out(404, false, 'Team member not found.');

        $allow = array();
        $deny = array();
        if (tm_table($pdo, 'user_permissions')) {
            $q = $pdo->prepare("SELECT permission_id,access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u");
            $q->execute(array(':t'=>$tenantId, ':u'=>$id));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((string)$r['access_type'] === 'allow') $allow[] = (int)$r['permission_id']; else $deny[] = (int)$r['permission_id'];
            }
        }
        tm_out(200, true, 'Team member loaded.', array('member'=>$member,'permission_allow_ids'=>$allow,'permission_deny_ids'=>$deny));
    }

    if ($action === 'save_member') {
        tm_csrf();
        $id = (int)tm_post('user_id','0');
        tm_require_any($pdo, $tenantId, $actor, $id > 0 ? array('employees.update','administration.update','teams.update') : array('employees.create','administration.create','teams.create'));

        $fullName = trim(tm_post('full_name'));
        $firstName = trim(tm_post('first_name'));
        $lastName = trim(tm_post('last_name'));
        if ($firstName === '' && $fullName !== '') {
            $bits = preg_split('/\s+/', $fullName, 2);
            $firstName = isset($bits[0]) ? trim($bits[0]) : '';
            $lastName = isset($bits[1]) ? trim($bits[1]) : '';
        }
        $email = strtolower(tm_post('email'));
        $phone = tm_post('phone');
        $employeeCode = tm_post('employee_code');
        $jobTitle = tm_post('job_title');
        $laborRateRaw = tm_post('labor_rate');
        $roleId = (int)tm_post('role_id','0');
        $isAdmin = tm_post('is_tenant_admin','0') === '1' ? 1 : 0;
        $isFieldWorker = tm_post('save_and_assign','0') === '1' ? 1 : (tm_post('is_field_worker','0') === '1' ? 1 : 0);
        $isBookable = tm_post('is_bookable','1') === '0' ? 0 : 1;
        $permissionsMode = tm_post('permissions_mode','role');
        $permissionIds = isset($_POST['permission_ids']) ? tm_normalize_ids($_POST['permission_ids']) : array();

        if ($firstName === '') tm_out(422, false, 'Full name is required.');
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) tm_out(422, false, 'Enter a valid email address.');
        if ($laborRateRaw !== '' && (!is_numeric($laborRateRaw) || (float)$laborRateRaw < 0)) tm_out(422, false, 'Labour cost must be zero or higher.');
        $laborRate = $laborRateRaw === '' ? null : number_format((float)$laborRateRaw, 2, '.', '');

        if ($roleId > 0) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE id=:id AND tenant_id=:t AND status='active'");
            $q->execute(array(':id'=>$roleId, ':t'=>$tenantId));
            if (!(int)$q->fetchColumn()) tm_out(422, false, 'Select a valid role.');
        } else {
            $roleId = null;
        }

        $q = $pdo->prepare("SELECT id FROM users WHERE tenant_id=:t AND email=:e AND deleted_at IS NULL" . ($id > 0 ? " AND id<>:id" : '') . " LIMIT 1");
        $p = array(':t'=>$tenantId, ':e'=>$email); if ($id > 0) $p[':id']=$id; $q->execute($p);
        if ($q->fetchColumn()) tm_out(409, false, 'This email address is already used by another team member.');
        if ($employeeCode !== '') {
            $q = $pdo->prepare("SELECT id FROM users WHERE tenant_id=:t AND employee_code=:c AND deleted_at IS NULL" . ($id > 0 ? " AND id<>:id" : '') . " LIMIT 1");
            $p = array(':t'=>$tenantId, ':c'=>$employeeCode); if ($id > 0) $p[':id']=$id; $q->execute($p);
            if ($q->fetchColumn()) tm_out(409, false, 'This employee code is already used by another team member.');
        }

        $old = $id > 0 ? tm_member($pdo, $tenantId, $id) : null;
        if ($id > 0 && !$old) tm_out(404, false, 'Team member not found.');
        if ($id === $actorId && $isAdmin === 0 && !empty($old['is_tenant_admin'])) {
            tm_out(409, false, 'You cannot remove your own administrator access.');
        }

        $avatarPath = $old && !empty($old['avatar_path']) ? (string)$old['avatar_path'] : null;
        if (isset($_FILES['avatar']) && is_array($_FILES['avatar']) && (int)$_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ((int)$_FILES['avatar']['error'] !== UPLOAD_ERR_OK) tm_out(422, false, 'Unable to upload the profile image.');
            if ((int)$_FILES['avatar']['size'] > 3 * 1024 * 1024) tm_out(422, false, 'Profile image must be 3 MB or smaller.');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($_FILES['avatar']['tmp_name']);
            $allowed = array('image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp');
            if (!isset($allowed[$mime])) tm_out(422, false, 'Only JPG, PNG or WEBP profile images are allowed.');
            $root = dirname(__DIR__, 2) . '/uploads/profile/tenant-' . $tenantId;
            if (!is_dir($root) && !@mkdir($root, 0775, true)) tm_out(500, false, 'Unable to create the profile upload folder.');
            $filename = 'user-' . ($id > 0 ? $id : 'new') . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
            if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $root . '/' . $filename)) tm_out(500, false, 'Unable to save the profile image.');
            $avatarPath = 'uploads/profile/tenant-' . $tenantId . '/' . $filename;
        }

        $pdo->beginTransaction();
        if ($id > 0) {
            $q = $pdo->prepare("UPDATE users SET first_name=:fn,last_name=:ln,email=:email,phone=:phone,employee_code=:code,job_title=:job,labor_rate=:rate,role_id=:role,is_tenant_admin=:admin,is_field_worker=:field,is_bookable=:book,avatar_path=:avatar,updated_at=NOW() WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL");
            $q->execute(array(':fn'=>$firstName,':ln'=>$lastName!==''?$lastName:null,':email'=>$email,':phone'=>$phone!==''?$phone:null,':code'=>$employeeCode!==''?$employeeCode:null,':job'=>$jobTitle!==''?$jobTitle:null,':rate'=>$laborRate,':role'=>$roleId,':admin'=>$isAdmin,':field'=>$isFieldWorker,':book'=>$isBookable,':avatar'=>$avatarPath,':id'=>$id,':t'=>$tenantId));
        } else {
            $temporaryPassword = bin2hex(random_bytes(12));
            $q = $pdo->prepare("INSERT INTO users(tenant_id,branch_id,role_id,employee_code,first_name,last_name,email,phone,password_hash,avatar_path,job_title,labor_rate,is_bookable,is_field_worker,is_tenant_admin,status,created_at) VALUES(:t,:branch,:role,:code,:fn,:ln,:email,:phone,:password,:avatar,:job,:rate,:book,:field,:admin,'invited',NOW())");
            $q->execute(array(':t'=>$tenantId,':branch'=>$branchId>0?$branchId:null,':role'=>$roleId,':code'=>$employeeCode!==''?$employeeCode:null,':fn'=>$firstName,':ln'=>$lastName!==''?$lastName:null,':email'=>$email,':phone'=>$phone!==''?$phone:null,':password'=>password_hash($temporaryPassword,PASSWORD_DEFAULT),':avatar'=>$avatarPath,':job'=>$jobTitle!==''?$jobTitle:null,':rate'=>$laborRate,':book'=>$isBookable,':field'=>$isFieldWorker,':admin'=>$isAdmin));
            $id = (int)$pdo->lastInsertId();

            // Re-name a newly uploaded file is not necessary; stored path remains valid.
            if (tm_table($pdo, 'tenant_activation_tokens')) {
                $rawToken = bin2hex(random_bytes(32));
                $q = $pdo->prepare("INSERT INTO tenant_activation_tokens(tenant_id,user_id,token_hash,expires_at,created_at) VALUES(:t,:u,:h,DATE_ADD(NOW(),INTERVAL 48 HOUR),NOW())");
                $q->execute(array(':t'=>$tenantId,':u'=>$id,':h'=>hash('sha256',$rawToken)));
            }
        }

        if (tm_table($pdo, 'user_permissions')) {
            $q = $pdo->prepare("DELETE FROM user_permissions WHERE tenant_id=:t AND user_id=:u");
            $q->execute(array(':t'=>$tenantId, ':u'=>$id));
            if ($permissionsMode === 'custom' && !empty($permissionIds)) {
                $valid = array();
                $in = implode(',', array_fill(0, count($permissionIds), '?'));
                $q = $pdo->prepare("SELECT id FROM permissions WHERE id IN ($in)");
                $q->execute($permissionIds);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $pid) $valid[] = (int)$pid;
                if ($valid) {
                    $ins = $pdo->prepare("INSERT INTO user_permissions(tenant_id,user_id,permission_id,access_type,created_at) VALUES(:t,:u,:p,'allow',NOW())");
                    foreach ($valid as $pid) $ins->execute(array(':t'=>$tenantId,':u'=>$id,':p'=>$pid));
                }
            }
        }
        $pdo->commit();

        $member = tm_member($pdo, $tenantId, $id);
        tm_audit($pdo,$tenantId,$branchId,$actorId,$old?'TEAM_MEMBER_UPDATED':'TEAM_MEMBER_INVITED',$id,array('email'=>$email,'role_id'=>$roleId,'is_tenant_admin'=>$isAdmin));
        tm_out($old ? 200 : 201, true, $old ? 'Team member updated successfully.' : 'Team member invited successfully.', array('member'=>$member,'user_id'=>$id));
    }

    if ($action === 'sessions') {
        tm_require_any($pdo, $tenantId, $actor, array('employees.view','teams.view','administration.view'));
        $userId = (int)tm_post('user_id','0');
        if ($userId <= 0) tm_out(422, false, 'Team member is required.');
        $member = tm_member($pdo,$tenantId,$userId);
        if (!$member) tm_out(404,false,'Team member not found.');
        $rows = array();
        if (tm_table($pdo,'user_devices')) {
            $q = $pdo->prepare("SELECT id,platform,device_name,last_ip_address,last_seen_at,created_at,status FROM user_devices WHERE tenant_id=:t AND user_id=:u AND status='active' ORDER BY COALESCE(last_seen_at,created_at) DESC,id DESC");
            $q->execute(array(':t'=>$tenantId,':u'=>$userId));
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        tm_out(200,true,'Active sessions loaded.',array('member'=>$member,'sessions'=>$rows));
    }

    if ($action === 'signout_session' || $action === 'signout_all') {
        tm_csrf();
        tm_require_any($pdo, $tenantId, $actor, array('employees.update','administration.update','teams.update'));
        if (!tm_table($pdo,'user_devices')) tm_out(200,true,'No active device sessions are stored.');
        $userId = (int)tm_post('user_id','0');
        if ($userId <= 0 || !tm_member($pdo,$tenantId,$userId)) tm_out(404,false,'Team member not found.');
        if ($action === 'signout_all') {
            $q = $pdo->prepare("UPDATE user_devices SET status='revoked',updated_at=NOW() WHERE tenant_id=:t AND user_id=:u AND status='active'");
            $q->execute(array(':t'=>$tenantId,':u'=>$userId));
            tm_audit($pdo,$tenantId,$branchId,$actorId,'TEAM_MEMBER_SESSIONS_REVOKED',$userId,array('count'=>$q->rowCount()));
            tm_out(200,true,'All active sessions were signed out.');
        }
        $deviceId = (int)tm_post('device_id','0');
        if ($deviceId <= 0) tm_out(422,false,'Session is required.');
        $q = $pdo->prepare("UPDATE user_devices SET status='revoked',updated_at=NOW() WHERE id=:id AND tenant_id=:t AND user_id=:u AND status='active'");
        $q->execute(array(':id'=>$deviceId,':t'=>$tenantId,':u'=>$userId));
        tm_audit($pdo,$tenantId,$branchId,$actorId,'TEAM_MEMBER_SESSION_REVOKED',$userId,array('device_id'=>$deviceId));
        tm_out(200,true,'Session signed out successfully.');
    }


    if ($action === 'list_crews') {
        tm_require_any($pdo, $tenantId, $actor, array('teams.view','employees.view','administration.view'));
        if (!tm_table($pdo,'teams') || !tm_table($pdo,'team_members')) {
            tm_out(200,true,'Crews loaded.',array('crews'=>array(),'sort_supported'=>false));
        }
        $sortSupported = tm_column($pdo,'team_members','sort_order');
        $q=$pdo->prepare("SELECT id,name,code,leader_user_id,status,created_at,updated_at FROM teams WHERE tenant_id=:t AND status='active' ORDER BY name,id");
        $q->execute(array(':t'=>$tenantId));
        $crews=$q->fetchAll(PDO::FETCH_ASSOC);
        $order=$sortSupported ? 'tm.sort_order ASC, tm.is_primary DESC, tm.joined_at ASC, tm.user_id ASC' : 'tm.is_primary DESC, tm.joined_at ASC, tm.user_id ASC';
        $mq=$pdo->prepare("SELECT tm.team_id,u.id,u.first_name,u.last_name,u.email,u.avatar_path,u.status,tm.is_primary".($sortSupported?',tm.sort_order':'')." FROM team_members tm INNER JOIN teams t ON t.id=tm.team_id AND t.tenant_id=:tenant INNER JOIN users u ON u.id=tm.user_id AND u.tenant_id=t.tenant_id AND u.deleted_at IS NULL WHERE tm.team_id=:team ORDER BY $order");
        foreach($crews as &$crew){
            $mq->execute(array(':tenant'=>$tenantId,':team'=>$crew['id']));
            $crew['members']=$mq->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($crew);
        tm_out(200,true,'Crews loaded.',array('crews'=>$crews,'sort_supported'=>$sortSupported));
    }

    if ($action === 'create_crew') {
        tm_csrf();
        tm_require_any($pdo,$tenantId,$actor,array('teams.create','administration.create'));
        if (!tm_table($pdo,'teams')) tm_out(500,false,'Teams table is not available.');
        $name=substr(trim(tm_post('name')),0,190);
        if ($name==='') tm_out(422,false,'Crew name is required.');
        $q=$pdo->prepare("SELECT id FROM teams WHERE tenant_id=:t AND LOWER(name)=LOWER(:n) AND status='active' LIMIT 1");
        $q->execute(array(':t'=>$tenantId,':n'=>$name));
        if ($q->fetchColumn()) tm_out(409,false,'A crew with this name already exists.');
        $q=$pdo->prepare("INSERT INTO teams(tenant_id,branch_id,department_id,name,code,leader_user_id,description,status,created_at) VALUES(:t,:b,NULL,:n,NULL,NULL,NULL,'active',NOW())");
        $q->execute(array(':t'=>$tenantId,':b'=>$branchId>0?$branchId:null,':n'=>$name));
        $id=(int)$pdo->lastInsertId();
        tm_audit($pdo,$tenantId,$branchId,$actorId,'CREW_CREATED',$id,array('name'=>$name));
        tm_out(201,true,'Crew created successfully.',array('crew_id'=>$id));
    }

    if ($action === 'rename_crew') {
        tm_csrf();
        tm_require_any($pdo,$tenantId,$actor,array('teams.update','administration.update'));
        $crewId=(int)tm_post('crew_id','0');
        $name=substr(trim(tm_post('name')),0,190);
        if ($crewId<=0 || $name==='') tm_out(422,false,'Crew and crew name are required.');
        $q=$pdo->prepare("SELECT id,name FROM teams WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");
        $q->execute(array(':id'=>$crewId,':t'=>$tenantId));
        $old=$q->fetch(PDO::FETCH_ASSOC);
        if(!$old) tm_out(404,false,'Crew not found.');
        $q=$pdo->prepare("SELECT id FROM teams WHERE tenant_id=:t AND LOWER(name)=LOWER(:n) AND id<>:id AND status='active' LIMIT 1");
        $q->execute(array(':t'=>$tenantId,':n'=>$name,':id'=>$crewId));
        if($q->fetchColumn()) tm_out(409,false,'A crew with this name already exists.');
        $q=$pdo->prepare("UPDATE teams SET name=:n,updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        $q->execute(array(':n'=>$name,':id'=>$crewId,':t'=>$tenantId));
        tm_audit($pdo,$tenantId,$branchId,$actorId,'CREW_RENAMED',$crewId,array('old_name'=>$old['name'],'name'=>$name));
        tm_out(200,true,'Crew renamed successfully.');
    }

    if ($action === 'delete_crew') {
        tm_csrf();
        tm_require_any($pdo,$tenantId,$actor,array('teams.delete','administration.delete','teams.update'));
        $crewId=(int)tm_post('crew_id','0');
        if($crewId<=0) tm_out(422,false,'Crew is required.');
        $q=$pdo->prepare("SELECT id,name FROM teams WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");
        $q->execute(array(':id'=>$crewId,':t'=>$tenantId));
        $crew=$q->fetch(PDO::FETCH_ASSOC);
        if(!$crew) tm_out(404,false,'Crew not found.');
        $pdo->beginTransaction();
        $q=$pdo->prepare("DELETE FROM team_members WHERE team_id=:id");$q->execute(array(':id'=>$crewId));
        $q=$pdo->prepare("UPDATE teams SET status='inactive',updated_at=NOW() WHERE id=:id AND tenant_id=:t");$q->execute(array(':id'=>$crewId,':t'=>$tenantId));
        $pdo->commit();
        tm_audit($pdo,$tenantId,$branchId,$actorId,'CREW_DELETED',$crewId,array('name'=>$crew['name']));
        tm_out(200,true,'Crew deleted successfully.');
    }

    if ($action === 'crew_candidates') {
        tm_require_any($pdo,$tenantId,$actor,array('teams.view','teams.update','employees.view','administration.view'));
        $crewId=(int)tm_post('crew_id','0');
        $search=tm_post('search');
        $q=$pdo->prepare("SELECT id FROM teams WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");$q->execute(array(':id'=>$crewId,':t'=>$tenantId));
        if(!$q->fetchColumn()) tm_out(404,false,'Crew not found.');
        $where="u.tenant_id=:t AND u.deleted_at IS NULL AND u.status IN ('active','invited') AND NOT EXISTS(SELECT 1 FROM team_members tm WHERE tm.team_id=:team AND tm.user_id=u.id)";
        $params=array(':t'=>$tenantId,':team'=>$crewId);
        if($search!==''){$where.=" AND (u.first_name LIKE :s OR COALESCE(u.last_name,'') LIKE :s OR u.email LIKE :s)";$params[':s']='%'.$search.'%';}
        $q=$pdo->prepare("SELECT u.id,u.first_name,u.last_name,u.email,u.avatar_path,u.status FROM users u WHERE $where ORDER BY u.first_name,u.last_name,u.id LIMIT 100");
        $q->execute($params);
        tm_out(200,true,'Available teammates loaded.',array('members'=>$q->fetchAll(PDO::FETCH_ASSOC)));
    }

    if ($action === 'add_crew_members') {
        tm_csrf();
        tm_require_any($pdo,$tenantId,$actor,array('teams.update','administration.update'));
        $crewId=(int)tm_post('crew_id','0');
        $ids=isset($_POST['user_ids'])?tm_normalize_ids($_POST['user_ids']):array();
        if($crewId<=0 || !$ids) tm_out(422,false,'Select at least one teammate.');
        $q=$pdo->prepare("SELECT id FROM teams WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");$q->execute(array(':id'=>$crewId,':t'=>$tenantId));if(!$q->fetchColumn())tm_out(404,false,'Crew not found.');
        $sortSupported=tm_column($pdo,'team_members','sort_order');
        $maxSort=0;if($sortSupported){$q=$pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM team_members WHERE team_id=:id");$q->execute(array(':id'=>$crewId));$maxSort=(int)$q->fetchColumn();}
        $valid=$pdo->prepare("SELECT id FROM users WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL AND status IN ('active','invited') LIMIT 1");
        $added=0;
        if($sortSupported)$ins=$pdo->prepare("INSERT IGNORE INTO team_members(team_id,user_id,member_role,is_primary,sort_order,joined_at) VALUES(:team,:user,NULL,0,:sort,NOW())");
        else $ins=$pdo->prepare("INSERT IGNORE INTO team_members(team_id,user_id,member_role,is_primary,joined_at) VALUES(:team,:user,NULL,0,NOW())");
        foreach($ids as $uid){$valid->execute(array(':id'=>$uid,':t'=>$tenantId));if(!$valid->fetchColumn())continue;$maxSort+=10;$params=array(':team'=>$crewId,':user'=>$uid);if($sortSupported)$params[':sort']=$maxSort;$ins->execute($params);$added+=$ins->rowCount();}
        tm_audit($pdo,$tenantId,$branchId,$actorId,'CREW_MEMBERS_ADDED',$crewId,array('user_ids'=>$ids,'added'=>$added));
        tm_out(200,true,$added.' teammate'.($added===1?'':'s').' added to crew.');
    }

    if ($action === 'remove_crew_member') {
        tm_csrf();
        tm_require_any($pdo,$tenantId,$actor,array('teams.update','administration.update'));
        $crewId=(int)tm_post('crew_id','0');$userId=(int)tm_post('user_id','0');
        $q=$pdo->prepare("SELECT id FROM teams WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");$q->execute(array(':id'=>$crewId,':t'=>$tenantId));if(!$q->fetchColumn())tm_out(404,false,'Crew not found.');
        $q=$pdo->prepare("DELETE FROM team_members WHERE team_id=:team AND user_id=:user");$q->execute(array(':team'=>$crewId,':user'=>$userId));
        tm_audit($pdo,$tenantId,$branchId,$actorId,'CREW_MEMBER_REMOVED',$crewId,array('user_id'=>$userId));
        tm_out(200,true,'Teammate removed from crew.');
    }

    if ($action === 'reorder_crew_members') {
        tm_csrf();
        tm_require_any($pdo,$tenantId,$actor,array('teams.update','administration.update'));
        if(!tm_column($pdo,'team_members','sort_order')) tm_out(409,false,'Run the crew member sort-order migration before using drag and drop.');
        $crewId=(int)tm_post('crew_id','0');$ids=isset($_POST['user_ids'])?tm_normalize_ids($_POST['user_ids']):array();
        $q=$pdo->prepare("SELECT id FROM teams WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");$q->execute(array(':id'=>$crewId,':t'=>$tenantId));if(!$q->fetchColumn())tm_out(404,false,'Crew not found.');
        $q=$pdo->prepare("SELECT user_id FROM team_members WHERE team_id=:team");$q->execute(array(':team'=>$crewId));$existing=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));sort($existing);$check=$ids;sort($check);if($existing!==$check)tm_out(409,false,'Crew members changed while reordering. Refresh and try again.');
        $pdo->beginTransaction();$up=$pdo->prepare("UPDATE team_members SET sort_order=:sort WHERE team_id=:team AND user_id=:user");$sort=10;foreach($ids as $uid){$up->execute(array(':sort'=>$sort,':team'=>$crewId,':user'=>$uid));$sort+=10;}$pdo->commit();
        tm_audit($pdo,$tenantId,$branchId,$actorId,'CREW_MEMBERS_REORDERED',$crewId,array('user_ids'=>$ids));
        tm_out(200,true,'Crew order saved.');
    }

    tm_out(400,false,'Invalid team action.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx team API database error: ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        tm_out(409,false,'This team member conflicts with an existing email or employee code.');
    }
    tm_out(500,false,'Unable to complete the team request.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx team API error: ' . $e->getMessage());
    tm_out(500,false,'Unable to complete the team request.');
}
