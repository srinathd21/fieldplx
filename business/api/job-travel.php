<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function jtOut($success, $message, array $extra = array(), $status = 200)
{
    http_response_code($status);
    echo json_encode(array_merge(array('success' => (bool)$success, 'message' => (string)$message), $extra));
    exit;
}

function jtDb()
{
    global $pdo, $db;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    throw new RuntimeException('PDO database connection is not available.');
}

function jtTenantId()
{
    foreach (array('tenant_id', 'business_id') as $k) {
        if (!empty($_SESSION[$k])) return (int)$_SESSION[$k];
    }
    return 0;
}

function jtUserId()
{
    foreach (array('user_id', 'id', 'business_user_id') as $k) {
        if (!empty($_SESSION[$k])) return (int)$_SESSION[$k];
    }
    return 0;
}

function jtJobAccess(PDO $pdo, $tenantId, $userId, $jobId)
{
    $sql = "SELECT j.id, j.status, j.client_id
            FROM jobs j
            WHERE j.id = :job_id
              AND j.tenant_id = :tenant_id
              AND j.deleted_at IS NULL
              AND (
                    EXISTS (
                        SELECT 1 FROM job_assignments ja
                        WHERE ja.job_id = j.id
                          AND ja.tenant_id = j.tenant_id
                          AND ja.user_id = :user_direct
                          AND ja.status <> 'removed'
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM job_assignments ja2
                        INNER JOIN team_members tm ON tm.team_id = ja2.team_id
                        WHERE ja2.job_id = j.id
                          AND ja2.tenant_id = j.tenant_id
                          AND tm.user_id = :user_team
                          AND ja2.status <> 'removed'
                    )
              )
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute(array(
        ':job_id' => $jobId,
        ':tenant_id' => $tenantId,
        ':user_direct' => $userId,
        ':user_team' => $userId,
    ));
    return $st->fetch(PDO::FETCH_ASSOC);
}

try {
    $pdo = jtDb();
    $tenantId = jtTenantId();
    $userId = jtUserId();
    if ($tenantId <= 0 || $userId <= 0) jtOut(false, 'Your login session is not valid.', array(), 401);

    $csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $sessionCsrf = isset($_SESSION['my_jobs_csrf_token']) ? (string)$_SESSION['my_jobs_csrf_token'] : '';
    if ($sessionCsrf === '' || $csrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        jtOut(false, 'Invalid request token. Refresh the page and try again.', array(), 419);
    }

    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    if ($jobId <= 0) jtOut(false, 'Invalid job.', array(), 422);

    $job = jtJobAccess($pdo, $tenantId, $userId, $jobId);
    if (!$job) jtOut(false, 'Job not found or you are not assigned to this job.', array(), 403);

    $terminal = array('cancelled', 'completed', 'ready_to_invoice', 'invoiced', 'closed', 'archived');
    if (in_array((string)$job['status'], $terminal, true) && $action !== 'get') {
        jtOut(false, 'Travelling cannot be changed for this job status.', array(), 422);
    }

    if ($action === 'get') {
        $st = $pdo->prepare("SELECT status, tracking_token, started_at, arrived_at, stopped_at,
                                   latest_latitude, latest_longitude, latest_accuracy,
                                   latest_heading, latest_speed, last_location_at
                            FROM job_travel_tracking
                            WHERE tenant_id = :tenant_id AND job_id = :job_id
                            LIMIT 1");
        $st->execute(array(':tenant_id' => $tenantId, ':job_id' => $jobId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        jtOut(true, 'Travel status loaded.', array('travel' => $row ?: null));
    }

    if ($action === 'start') {
        $latitude = isset($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $accuracy = isset($_POST['accuracy']) && $_POST['accuracy'] !== '' ? (float)$_POST['accuracy'] : null;
        if ($latitude === null || $longitude === null || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            jtOut(false, 'A valid current GPS location is required to start travelling.', array(), 422);
        }

        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT id, tracking_token FROM job_travel_tracking WHERE job_id = :job_id FOR UPDATE");
        $st->execute(array(':job_id' => $jobId));
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        $token = $existing && !empty($existing['tracking_token']) ? (string)$existing['tracking_token'] : bin2hex(random_bytes(32));

        if ($existing) {
            $trackingId = (int)$existing['id'];
            $up = $pdo->prepare("UPDATE job_travel_tracking
                                 SET tenant_id = :tenant_id, user_id = :user_id, status = 'on_the_way',
                                     started_at = COALESCE(started_at, NOW()), arrived_at = NULL, stopped_at = NULL,
                                     latest_latitude = :lat, latest_longitude = :lng, latest_accuracy = :accuracy,
                                     last_location_at = NOW()
                                 WHERE id = :id");
            $up->execute(array(':tenant_id'=>$tenantId, ':user_id'=>$userId, ':lat'=>$latitude, ':lng'=>$longitude, ':accuracy'=>$accuracy, ':id'=>$trackingId));
        } else {
            $ins = $pdo->prepare("INSERT INTO job_travel_tracking
                                  (tenant_id, job_id, user_id, status, tracking_token, started_at,
                                   latest_latitude, latest_longitude, latest_accuracy, last_location_at)
                                  VALUES (:tenant_id, :job_id, :user_id, 'on_the_way', :token, NOW(), :lat, :lng, :accuracy, NOW())");
            $ins->execute(array(':tenant_id'=>$tenantId, ':job_id'=>$jobId, ':user_id'=>$userId, ':token'=>$token, ':lat'=>$latitude, ':lng'=>$longitude, ':accuracy'=>$accuracy));
            $trackingId = (int)$pdo->lastInsertId();
        }

        $loc = $pdo->prepare("INSERT INTO job_travel_locations
                              (tracking_id, tenant_id, job_id, user_id, latitude, longitude, accuracy, recorded_at)
                              VALUES (:tracking_id, :tenant_id, :job_id, :user_id, :lat, :lng, :accuracy, NOW())");
        $loc->execute(array(':tracking_id'=>$trackingId, ':tenant_id'=>$tenantId, ':job_id'=>$jobId, ':user_id'=>$userId, ':lat'=>$latitude, ':lng'=>$longitude, ':accuracy'=>$accuracy));
        $pdo->commit();

        jtOut(true, 'You are now marked On the Way. Live location tracking has started.', array(
            'travel_status' => 'on_the_way',
            'tracking_token' => $token,
            'tracking_url' => 'customer/job-tracking.php?token=' . rawurlencode($token)
        ));
    }

    if ($action === 'location') {
        $latitude = isset($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $accuracy = isset($_POST['accuracy']) && $_POST['accuracy'] !== '' ? (float)$_POST['accuracy'] : null;
        $heading = isset($_POST['heading']) && $_POST['heading'] !== '' ? (float)$_POST['heading'] : null;
        $speed = isset($_POST['speed']) && $_POST['speed'] !== '' ? (float)$_POST['speed'] : null;
        if ($latitude === null || $longitude === null || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            jtOut(false, 'Invalid location update.', array(), 422);
        }
        $st = $pdo->prepare("SELECT id, status FROM job_travel_tracking WHERE tenant_id = :tenant_id AND job_id = :job_id LIMIT 1");
        $st->execute(array(':tenant_id'=>$tenantId, ':job_id'=>$jobId));
        $travel = $st->fetch(PDO::FETCH_ASSOC);
        if (!$travel || $travel['status'] !== 'on_the_way') jtOut(false, 'Travelling has not been started for this job.', array(), 409);

        $pdo->beginTransaction();
        $up = $pdo->prepare("UPDATE job_travel_tracking
                             SET user_id=:user_id, latest_latitude=:lat, latest_longitude=:lng,
                                 latest_accuracy=:accuracy, latest_heading=:heading, latest_speed=:speed,
                                 last_location_at=NOW()
                             WHERE id=:id");
        $up->execute(array(':user_id'=>$userId, ':lat'=>$latitude, ':lng'=>$longitude, ':accuracy'=>$accuracy, ':heading'=>$heading, ':speed'=>$speed, ':id'=>(int)$travel['id']));
        $loc = $pdo->prepare("INSERT INTO job_travel_locations
                              (tracking_id, tenant_id, job_id, user_id, latitude, longitude, accuracy, heading, speed, recorded_at)
                              VALUES (:tracking_id, :tenant_id, :job_id, :user_id, :lat, :lng, :accuracy, :heading, :speed, NOW())");
        $loc->execute(array(':tracking_id'=>(int)$travel['id'], ':tenant_id'=>$tenantId, ':job_id'=>$jobId, ':user_id'=>$userId, ':lat'=>$latitude, ':lng'=>$longitude, ':accuracy'=>$accuracy, ':heading'=>$heading, ':speed'=>$speed));
        $pdo->commit();
        jtOut(true, 'Location updated.', array('travel_status'=>'on_the_way'));
    }

    if ($action === 'arrived') {
        $st = $pdo->prepare("UPDATE job_travel_tracking
                             SET status='arrived', arrived_at=NOW(), stopped_at=NOW()
                             WHERE tenant_id=:tenant_id AND job_id=:job_id AND status='on_the_way'");
        $st->execute(array(':tenant_id'=>$tenantId, ':job_id'=>$jobId));
        jtOut(true, 'Marked as Arrived.', array('travel_status'=>'arrived'));
    }

    jtOut(false, 'Unknown action.', array(), 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    jtOut(false, $e->getMessage(), array(), 500);
}
