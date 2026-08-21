<?php
/**
 * FieldPlx - Add Route
 *
 * Upload as:
 * /public_html/route-add.php
 *
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('route-add.php')
    );
    exit;
}

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Permission
|--------------------------------------------------------------------------
|
| Dedicated route permissions are preferred. jobs.manage is retained as a
| compatibility fallback because Routes belongs to the Operations area.
|
*/

$canManageRoutes = true;

if (function_exists('hasPermission')) {
    $canManageRoutes =
        hasPermission('routes.manage') ||
        hasPermission('jobs.manage');
}

if (!$canManageRoutes) {
    http_response_code(403);
    exit(
        '403 - Access Denied. ' .
        'You do not have permission to create routes.'
    );
}

$pageTitle = 'Add Route - FieldPlx';
$activePage = 'routes';
$searchPlaceholder = 'Search routes...';
$basePath = '';

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('routeAddFetchAssoc')) {
    function routeAddFetchAssoc(mysqli_stmt $stmt)
    {
        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result) {
                return $result->fetch_assoc();
            }
        }

        $metadata = $stmt->result_metadata();

        if (!$metadata) {
            return null;
        }

        $row = array();
        $bind = array();

        while ($field = $metadata->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

        if (!$stmt->fetch()) {
            return null;
        }

        $copy = array();

        foreach ($row as $key => $value) {
            $copy[$key] = $value;
        }

        return $copy;
    }
}

if (!function_exists('routeAddFetchAll')) {
    function routeAddFetchAll(mysqli_stmt $stmt)
    {
        $rows = array();

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }

                return $rows;
            }
        }

        $metadata = $stmt->result_metadata();

        if (!$metadata) {
            return $rows;
        }

        $row = array();
        $bind = array();

        while ($field = $metadata->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

        while ($stmt->fetch()) {
            $copy = array();

            foreach ($row as $key => $value) {
                $copy[$key] = $value;
            }

            $rows[] = $copy;
        }

        return $rows;
    }
}

if (!function_exists('routeAddOld')) {
    function routeAddOld($key, $default = '')
    {
        if (
            isset($_POST[$key]) &&
            !is_array($_POST[$key])
        ) {
            return trim((string) $_POST[$key]);
        }

        return $default;
    }
}

if (!function_exists('routeAddArrayValue')) {
    function routeAddArrayValue(
        array $source,
        $index,
        $default = ''
    ) {
        return isset($source[$index]) &&
            !is_array($source[$index])
                ? trim((string) $source[$index])
                : $default;
    }
}

if (!function_exists('routeAddNullable')) {
    function routeAddNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('routeAddNormalizeDateTime')) {
    function routeAddNormalizeDateTime($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}

if (!function_exists('routeAddValidDate')) {
    function routeAddValidDate($value)
    {
        if (
            !preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                (string) $value
            )
        ) {
            return false;
        }

        $date = DateTime::createFromFormat(
            'Y-m-d',
            (string) $value
        );

        return $date &&
            $date->format('Y-m-d') === $value;
    }
}

if (!function_exists('routeAddCsrfToken')) {
    function routeAddCsrfToken()
    {
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));
            } catch (Throwable $error) {
                $_SESSION['csrf_token'] =
                    sha1(
                        uniqid(
                            (string) mt_rand(),
                            true
                        )
                    );
            }
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('routeAddVerifyCsrf')) {
    function routeAddVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('routeAddBuildAddress')) {
    function routeAddBuildAddress(array $row)
    {
        return implode(
            ', ',
            array_filter(
                array(
                    isset($row['address_line1'])
                        ? $row['address_line1']
                        : '',
                    isset($row['address_line2'])
                        ? $row['address_line2']
                        : '',
                    isset($row['city'])
                        ? $row['city']
                        : '',
                    isset($row['state'])
                        ? $row['state']
                        : '',
                    isset($row['postal_code'])
                        ? $row['postal_code']
                        : '',
                    isset($row['country'])
                        ? $row['country']
                        : ''
                ),
                function ($value) {
                    return trim((string) $value) !== '';
                }
            )
        );
    }
}

if (!function_exists('routeAddLogActivity')) {
    function routeAddLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $routeId,
        $routeName,
        $routeDate,
        $workerId,
        $stopCount,
        $status
    ) {
        $stmt = $conn->prepare("
            INSERT INTO activity_events (
                tenant_id,
                actor_user_id,
                actor_type,
                event_type,
                related_type,
                related_id,
                client_id,
                title,
                details_json,
                visible_to_client,
                created_at
            ) VALUES (
                ?,
                ?,
                'user',
                'route_created',
                'route_plan',
                ?,
                NULL,
                ?,
                ?,
                0,
                NOW()
            )
        ");

        if (!$stmt) {
            return;
        }

        $title =
            'Route created: ' .
            $routeName;

        $details = json_encode(
            array(
                'route_plan_id' =>
                    (int) $routeId,
                'route_name' =>
                    (string) $routeName,
                'route_date' =>
                    (string) $routeDate,
                'assigned_user_id' =>
                    $workerId !== null
                        ? (int) $workerId
                        : null,
                'stop_count' =>
                    (int) $stopCount,
                'status' =>
                    (string) $status
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiss',
            $tenantId,
            $userId,
            $routeId,
            $title,
            $details
        );

        $stmt->execute();
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Load active workers
|--------------------------------------------------------------------------
*/

$workers = array();
$workerMap = array();

$stmt = $conn->prepare("
    SELECT
        id,
        first_name,
        last_name,
        email,
        phone,
        job_title,
        employee_code,
        color_code,
        is_field_worker,
        is_bookable
    FROM users
    WHERE tenant_id = ?
      AND status = 'active'
      AND deleted_at IS NULL
    ORDER BY
        is_field_worker DESC,
        is_bookable DESC,
        first_name ASC,
        last_name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);

    if ($stmt->execute()) {
        $workers =
            routeAddFetchAll($stmt);
    }

    $stmt->close();
}

foreach ($workers as $worker) {
    $workerMap[(int) $worker['id']] =
        $worker;
}

/*
|--------------------------------------------------------------------------
| Load active properties
|--------------------------------------------------------------------------
*/

$properties = array();
$propertyMap = array();

$stmt = $conn->prepare("
    SELECT
        p.id,
        p.client_id,
        p.name,
        p.address_line1,
        p.address_line2,
        p.city,
        p.state,
        p.postal_code,
        p.country,
        p.latitude,
        p.longitude,

        c.display_name AS client_name

    FROM properties p

    INNER JOIN clients c
        ON c.id = p.client_id
       AND c.tenant_id = p.tenant_id
       AND c.deleted_at IS NULL

    WHERE p.tenant_id = ?
      AND p.deleted_at IS NULL
      AND p.status = 'active'

    ORDER BY
        c.display_name ASC,
        p.name ASC,
        p.address_line1 ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);

    if ($stmt->execute()) {
        $properties =
            routeAddFetchAll($stmt);
    }

    $stmt->close();
}

foreach ($properties as $property) {
    $propertyId =
        (int) $property['id'];

    $property['full_address'] =
        routeAddBuildAddress($property);

    $propertyMap[$propertyId] =
        $property;
}

/*
|--------------------------------------------------------------------------
| Load route-eligible visits
|--------------------------------------------------------------------------
*/

$visits = array();
$visitMap = array();

$stmt = $conn->prepare("
    SELECT
        v.id,
        v.visit_no,
        v.job_id,
        v.assigned_user_id,
        v.scheduled_start,
        v.scheduled_end,
        v.status,

        j.job_no,
        j.title AS job_title,
        j.client_id,
        j.property_id,

        c.display_name AS client_name,

        p.name AS property_name,
        p.address_line1,
        p.address_line2,
        p.city,
        p.state,
        p.postal_code,
        p.country,
        p.latitude,
        p.longitude

    FROM visits v

    INNER JOIN jobs j
        ON j.id = v.job_id
       AND j.tenant_id = v.tenant_id
       AND j.deleted_at IS NULL

    INNER JOIN clients c
        ON c.id = j.client_id
       AND c.tenant_id = j.tenant_id
       AND c.deleted_at IS NULL

    LEFT JOIN properties p
        ON p.id = j.property_id
       AND p.tenant_id = j.tenant_id
       AND p.deleted_at IS NULL

    WHERE v.tenant_id = ?
      AND v.status NOT IN (
          'completed',
          'missed',
          'cancelled'
      )

    ORDER BY
        CASE
            WHEN v.scheduled_start IS NULL
            THEN 1
            ELSE 0
        END,
        v.scheduled_start ASC,
        v.id DESC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);

    if ($stmt->execute()) {
        $visits =
            routeAddFetchAll($stmt);
    }

    $stmt->close();
}

foreach ($visits as $visit) {
    $visitId =
        (int) $visit['id'];

    $visit['full_address'] =
        routeAddBuildAddress($visit);

    $visitMap[$visitId] =
        $visit;
}

/*
|--------------------------------------------------------------------------
| Initial values from query string
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!empty($_GET['assigned_user_id'])) {
        $_POST['assigned_user_id'] =
            (string) (int) $_GET['assigned_user_id'];
    }

    if (
        !empty($_GET['route_date']) &&
        routeAddValidDate(
            trim((string) $_GET['route_date'])
        )
    ) {
        $_POST['route_date'] =
            trim((string) $_GET['route_date']);
    }

    if (!empty($_GET['visit_id'])) {
        $_POST['stop_visit_id'] = array(
            (string) (int) $_GET['visit_id']
        );

        $_POST['stop_property_id'] = array('');
        $_POST['stop_address'] = array('');
        $_POST['stop_latitude'] = array('');
        $_POST['stop_longitude'] = array('');
        $_POST['stop_arrival'] = array('');
        $_POST['stop_departure'] = array('');
        $_POST['stop_distance'] = array('');
        $_POST['stop_duration'] = array('');
    }
}

/*
|--------------------------------------------------------------------------
| Build submitted stop rows for validation and redisplay
|--------------------------------------------------------------------------
*/

$submittedStops = array();

$stopVisitIds =
    isset($_POST['stop_visit_id']) &&
    is_array($_POST['stop_visit_id'])
        ? $_POST['stop_visit_id']
        : array();

$stopPropertyIds =
    isset($_POST['stop_property_id']) &&
    is_array($_POST['stop_property_id'])
        ? $_POST['stop_property_id']
        : array();

$stopAddresses =
    isset($_POST['stop_address']) &&
    is_array($_POST['stop_address'])
        ? $_POST['stop_address']
        : array();

$stopLatitudes =
    isset($_POST['stop_latitude']) &&
    is_array($_POST['stop_latitude'])
        ? $_POST['stop_latitude']
        : array();

$stopLongitudes =
    isset($_POST['stop_longitude']) &&
    is_array($_POST['stop_longitude'])
        ? $_POST['stop_longitude']
        : array();

$stopArrivals =
    isset($_POST['stop_arrival']) &&
    is_array($_POST['stop_arrival'])
        ? $_POST['stop_arrival']
        : array();

$stopDepartures =
    isset($_POST['stop_departure']) &&
    is_array($_POST['stop_departure'])
        ? $_POST['stop_departure']
        : array();

$stopDistances =
    isset($_POST['stop_distance']) &&
    is_array($_POST['stop_distance'])
        ? $_POST['stop_distance']
        : array();

$stopDurations =
    isset($_POST['stop_duration']) &&
    is_array($_POST['stop_duration'])
        ? $_POST['stop_duration']
        : array();

$stopRowCount = max(
    count($stopVisitIds),
    count($stopPropertyIds),
    count($stopAddresses),
    count($stopLatitudes),
    count($stopLongitudes),
    count($stopArrivals),
    count($stopDepartures),
    count($stopDistances),
    count($stopDurations)
);

for ($index = 0; $index < $stopRowCount; $index++) {
    $submittedStops[] = array(
        'visit_id' =>
            routeAddArrayValue(
                $stopVisitIds,
                $index
            ),
        'property_id' =>
            routeAddArrayValue(
                $stopPropertyIds,
                $index
            ),
        'address' =>
            routeAddArrayValue(
                $stopAddresses,
                $index
            ),
        'latitude' =>
            routeAddArrayValue(
                $stopLatitudes,
                $index
            ),
        'longitude' =>
            routeAddArrayValue(
                $stopLongitudes,
                $index
            ),
        'arrival' =>
            routeAddArrayValue(
                $stopArrivals,
                $index
            ),
        'departure' =>
            routeAddArrayValue(
                $stopDepartures,
                $index
            ),
        'distance' =>
            routeAddArrayValue(
                $stopDistances,
                $index
            ),
        'duration' =>
            routeAddArrayValue(
                $stopDurations,
                $index
            )
    );
}

if (empty($submittedStops)) {
    $submittedStops[] = array(
        'visit_id' => '',
        'property_id' => '',
        'address' => '',
        'latitude' => '',
        'longitude' => '',
        'arrival' => '',
        'departure' => '',
        'distance' => '',
        'duration' => ''
    );
}

/*
|--------------------------------------------------------------------------
| Save route
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!routeAddVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $routeName =
        routeAddOld('name');

    $assignedUserId =
        isset($_POST['assigned_user_id']) &&
        (int) $_POST['assigned_user_id'] > 0
            ? (int) $_POST['assigned_user_id']
            : null;

    $routeDate =
        routeAddOld(
            'route_date',
            date('Y-m-d')
        );

    $status =
        routeAddOld(
            'status',
            'draft'
        );

    $optimizationProvider =
        routeAddOld(
            'optimization_provider'
        );

    $distanceInput =
        routeAddOld(
            'total_distance_km'
        );

    $durationInput =
        routeAddOld(
            'total_duration_minutes'
        );

    $allowedStatuses = array(
        'draft',
        'optimized',
        'dispatched'
    );

    if ($routeName === '') {
        $errors[] =
            'Route name is required.';
    } elseif (strlen($routeName) > 190) {
        $errors[] =
            'Route name cannot exceed 190 characters.';
    }

    if (!routeAddValidDate($routeDate)) {
        $errors[] =
            'Please enter a valid route date.';
    }

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {
        $errors[] =
            'Please select a valid route status.';
    }

    if (
        strlen($optimizationProvider) > 120
    ) {
        $errors[] =
            'Optimization provider cannot exceed 120 characters.';
    }

    if (
        $assignedUserId !== null &&
        !isset($workerMap[$assignedUserId])
    ) {
        $errors[] =
            'The selected worker is not available.';
    }

    $totalDistance = null;

    if ($distanceInput !== '') {
        if (
            !is_numeric($distanceInput) ||
            (float) $distanceInput < 0
        ) {
            $errors[] =
                'Total distance must be zero or greater.';
        } else {
            $totalDistance =
                round(
                    (float) $distanceInput,
                    2
                );
        }
    }

    $totalDuration = null;

    if ($durationInput !== '') {
        if (
            filter_var(
                $durationInput,
                FILTER_VALIDATE_INT
            ) === false ||
            (int) $durationInput < 0
        ) {
            $errors[] =
                'Total duration must be a whole number of minutes.';
        } else {
            $totalDuration =
                (int) $durationInput;
        }
    }

    $validatedStops = array();
    $usedVisitIds = array();

    foreach (
        $submittedStops as
        $rowIndex => $submittedStop
    ) {
        $displayNumber =
            $rowIndex + 1;

        $visitId =
            (int) $submittedStop['visit_id'];

        $propertyId =
            (int) $submittedStop['property_id'];

        $address =
            trim(
                (string) $submittedStop['address']
            );

        $latitudeInput =
            trim(
                (string) $submittedStop['latitude']
            );

        $longitudeInput =
            trim(
                (string) $submittedStop['longitude']
            );

        $arrivalInput =
            trim(
                (string) $submittedStop['arrival']
            );

        $departureInput =
            trim(
                (string) $submittedStop['departure']
            );

        $distanceFromPreviousInput =
            trim(
                (string) $submittedStop['distance']
            );

        $durationFromPreviousInput =
            trim(
                (string) $submittedStop['duration']
            );

        $rowIsEmpty =
            $visitId <= 0 &&
            $propertyId <= 0 &&
            $address === '' &&
            $arrivalInput === '' &&
            $departureInput === '';

        if ($rowIsEmpty) {
            continue;
        }

        if (
            $visitId > 0 &&
            !isset($visitMap[$visitId])
        ) {
            $errors[] =
                'Stop ' .
                $displayNumber .
                ': the selected visit is not available.';

            continue;
        }

        if (
            $visitId > 0 &&
            isset($usedVisitIds[$visitId])
        ) {
            $errors[] =
                'Stop ' .
                $displayNumber .
                ': the same visit cannot be added twice.';

            continue;
        }

        if ($visitId > 0) {
            $usedVisitIds[$visitId] = true;

            $visitRecord =
                $visitMap[$visitId];

            if (
                $propertyId <= 0 &&
                !empty($visitRecord['property_id'])
            ) {
                $propertyId =
                    (int) $visitRecord['property_id'];
            }

            if (
                $address === '' &&
                trim(
                    (string) $visitRecord['full_address']
                ) !== ''
            ) {
                $address =
                    (string) $visitRecord['full_address'];
            }

            if (
                $latitudeInput === '' &&
                $visitRecord['latitude'] !== null
            ) {
                $latitudeInput =
                    (string) $visitRecord['latitude'];
            }

            if (
                $longitudeInput === '' &&
                $visitRecord['longitude'] !== null
            ) {
                $longitudeInput =
                    (string) $visitRecord['longitude'];
            }

            if (
                $arrivalInput === '' &&
                !empty($visitRecord['scheduled_start'])
            ) {
                $arrivalInput =
                    date(
                        'Y-m-d\TH:i',
                        strtotime(
                            $visitRecord['scheduled_start']
                        )
                    );
            }

            if (
                $departureInput === '' &&
                !empty($visitRecord['scheduled_end'])
            ) {
                $departureInput =
                    date(
                        'Y-m-d\TH:i',
                        strtotime(
                            $visitRecord['scheduled_end']
                        )
                    );
            }
        }

        if (
            $propertyId > 0 &&
            !isset($propertyMap[$propertyId])
        ) {
            $errors[] =
                'Stop ' .
                $displayNumber .
                ': the selected property is not available.';

            continue;
        }

        if ($propertyId > 0) {
            $propertyRecord =
                $propertyMap[$propertyId];

            if (
                $address === '' &&
                trim(
                    (string) $propertyRecord['full_address']
                ) !== ''
            ) {
                $address =
                    (string) $propertyRecord['full_address'];
            }

            if (
                $latitudeInput === '' &&
                $propertyRecord['latitude'] !== null
            ) {
                $latitudeInput =
                    (string) $propertyRecord['latitude'];
            }

            if (
                $longitudeInput === '' &&
                $propertyRecord['longitude'] !== null
            ) {
                $longitudeInput =
                    (string) $propertyRecord['longitude'];
            }
        }

        if ($address === '') {
            $errors[] =
                'Stop ' .
                $displayNumber .
                ': an address is required.';

            continue;
        }

        if (strlen($address) > 500) {
            $errors[] =
                'Stop ' .
                $displayNumber .
                ': address cannot exceed 500 characters.';
        }

        $latitude = null;

        if ($latitudeInput !== '') {
            if (
                !is_numeric($latitudeInput) ||
                (float) $latitudeInput < -90 ||
                (float) $latitudeInput > 90
            ) {
                $errors[] =
                    'Stop ' .
                    $displayNumber .
                    ': latitude must be between -90 and 90.';
            } else {
                $latitude =
                    round(
                        (float) $latitudeInput,
                        7
                    );
            }
        }

        $longitude = null;

        if ($longitudeInput !== '') {
            if (
                !is_numeric($longitudeInput) ||
                (float) $longitudeInput < -180 ||
                (float) $longitudeInput > 180
            ) {
                $errors[] =
                    'Stop ' .
                    $displayNumber .
                    ': longitude must be between -180 and 180.';
            } else {
                $longitude =
                    round(
                        (float) $longitudeInput,
                        7
                    );
            }
        }

        $arrival =
            routeAddNormalizeDateTime(
                $arrivalInput
            );

        $departure =
            routeAddNormalizeDateTime(
                $departureInput
            );

        if (
            $arrivalInput !== '' &&
            $arrival === null
        ) {
            $errors[] =
                'Stop ' .
                $displayNumber .
                ': estimated arrival is invalid.';
        }

        if (
            $departureInput !== '' &&
            $departure === null
        ) {
            $errors[] =
                'Stop ' .
                $displayNumber .
                ': estimated departure is invalid.';
        }

        if (
            $arrival !== null &&
            $departure === null
        ) {
            $departure = date(
                'Y-m-d H:i:s',
                strtotime(
                    $arrival .
                    ' +30 minutes'
                )
            );
        }

        if (
            $arrival === null &&
            $departure !== null
        ) {
            $errors[] =
                'Stop ' .
                $displayNumber .
                ': estimated arrival is required when departure is entered.';
        }

        if (
            $arrival !== null &&
            $departure !== null &&
            strtotime($departure) <=
                strtotime($arrival)
        ) {
            $errors[] =
                'Stop ' .
                $displayNumber .
                ': departure must be after arrival.';
        }

        $distanceFromPrevious = null;

        if (
            $distanceFromPreviousInput !== ''
        ) {
            if (
                !is_numeric(
                    $distanceFromPreviousInput
                ) ||
                (float) $distanceFromPreviousInput < 0
            ) {
                $errors[] =
                    'Stop ' .
                    $displayNumber .
                    ': distance from previous must be zero or greater.';
            } else {
                $distanceFromPrevious =
                    round(
                        (float) $distanceFromPreviousInput,
                        2
                    );
            }
        }

        $durationFromPrevious = null;

        if (
            $durationFromPreviousInput !== ''
        ) {
            if (
                filter_var(
                    $durationFromPreviousInput,
                    FILTER_VALIDATE_INT
                ) === false ||
                (int) $durationFromPreviousInput < 0
            ) {
                $errors[] =
                    'Stop ' .
                    $displayNumber .
                    ': travel duration must be a whole number of minutes.';
            } else {
                $durationFromPrevious =
                    (int) $durationFromPreviousInput;
            }
        }

        $validatedStops[] = array(
            'visit_id' =>
                $visitId > 0
                    ? $visitId
                    : null,
            'property_id' =>
                $propertyId > 0
                    ? $propertyId
                    : null,
            'address' =>
                $address,
            'latitude' =>
                $latitude,
            'longitude' =>
                $longitude,
            'arrival' =>
                $arrival,
            'departure' =>
                $departure,
            'distance' =>
                $distanceFromPrevious,
            'duration' =>
                $durationFromPrevious
        );
    }

    if (empty($validatedStops)) {
        $errors[] =
            'Add at least one valid route stop.';
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            $optimizationProviderValue =
                routeAddNullable(
                    $optimizationProvider
                );

            $stmt = $conn->prepare("
                INSERT INTO route_plans (
                    tenant_id,
                    name,
                    assigned_user_id,
                    route_date,
                    status,
                    optimization_provider,
                    total_distance_km,
                    total_duration_minutes,
                    created_by,
                    created_at,
                    updated_at
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW(),
                    NOW()
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare route creation: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'isisssdii',
                $tenantId,
                $routeName,
                $assignedUserId,
                $routeDate,
                $status,
                $optimizationProviderValue,
                $totalDistance,
                $totalDuration,
                $currentUserId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Route could not be created: ' .
                    $stmt->error
                );
            }

            $routeId =
                (int) $stmt->insert_id;

            $stmt->close();

            $stopInsert = $conn->prepare("
                INSERT INTO route_plan_stops (
                    tenant_id,
                    route_plan_id,
                    visit_id,
                    property_id,
                    stop_order,
                    address,
                    latitude,
                    longitude,
                    estimated_arrival,
                    estimated_departure,
                    distance_from_previous_km,
                    duration_from_previous_minutes,
                    created_at
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW()
                )
            ");

            if (!$stopInsert) {
                throw new Exception(
                    'Unable to prepare route stop creation: ' .
                    $conn->error
                );
            }

            foreach (
                $validatedStops as
                $stopIndex => $stop
            ) {
                $stopOrder =
                    $stopIndex + 1;

                $visitIdValue =
                    $stop['visit_id'];

                $propertyIdValue =
                    $stop['property_id'];

                $addressValue =
                    $stop['address'];

                $latitudeValue =
                    $stop['latitude'];

                $longitudeValue =
                    $stop['longitude'];

                $arrivalValue =
                    $stop['arrival'];

                $departureValue =
                    $stop['departure'];

                $distanceValue =
                    $stop['distance'];

                $durationValue =
                    $stop['duration'];

                $stopInsert->bind_param(
                    'iiiiisddssdi',
                    $tenantId,
                    $routeId,
                    $visitIdValue,
                    $propertyIdValue,
                    $stopOrder,
                    $addressValue,
                    $latitudeValue,
                    $longitudeValue,
                    $arrivalValue,
                    $departureValue,
                    $distanceValue,
                    $durationValue
                );

                if (!$stopInsert->execute()) {
                    throw new Exception(
                        'Route stop ' .
                        $stopOrder .
                        ' could not be saved: ' .
                        $stopInsert->error
                    );
                }
            }

            $stopInsert->close();

            $conn->commit();

            routeAddLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $routeId,
                $routeName,
                $routeDate,
                $assignedUserId,
                count($validatedStops),
                $status
            );

            $_SESSION['flash_success'] =
                'Route created successfully.';

            header(
                'Location: route-view.php?id=' .
                $routeId
            );
            exit;
        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            $errors[] =
                $error->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Render values
|--------------------------------------------------------------------------
*/

$selectedWorkerId =
    (int) routeAddOld(
        'assigned_user_id'
    );

$selectedRouteDate =
    routeAddOld(
        'route_date',
        date('Y-m-d')
    );

$selectedStatus =
    routeAddOld(
        'status',
        'draft'
    );

$csrfToken =
    routeAddCsrfToken();

$visitJson = array();

foreach ($visitMap as $visitId => $visit) {
    $visitJson[(string) $visitId] = array(
        'id' =>
            (int) $visitId,
        'visit_no' =>
            (string) $visit['visit_no'],
        'job_no' =>
            (string) $visit['job_no'],
        'job_title' =>
            (string) $visit['job_title'],
        'client_name' =>
            (string) $visit['client_name'],
        'property_id' =>
            !empty($visit['property_id'])
                ? (int) $visit['property_id']
                : null,
        'address' =>
            (string) $visit['full_address'],
        'latitude' =>
            $visit['latitude'] !== null
                ? (string) $visit['latitude']
                : '',
        'longitude' =>
            $visit['longitude'] !== null
                ? (string) $visit['longitude']
                : '',
        'scheduled_start' =>
            !empty($visit['scheduled_start'])
                ? date(
                    'Y-m-d\TH:i',
                    strtotime(
                        $visit['scheduled_start']
                    )
                )
                : '',
        'scheduled_end' =>
            !empty($visit['scheduled_end'])
                ? date(
                    'Y-m-d\TH:i',
                    strtotime(
                        $visit['scheduled_end']
                    )
                )
                : '',
        'assigned_user_id' =>
            !empty($visit['assigned_user_id'])
                ? (int) $visit['assigned_user_id']
                : null
    );
}

$propertyJson = array();

foreach (
    $propertyMap as
    $propertyId => $property
) {
    $propertyJson[(string) $propertyId] = array(
        'id' =>
            (int) $propertyId,
        'client_name' =>
            (string) $property['client_name'],
        'name' =>
            (string) $property['name'],
        'address' =>
            (string) $property['full_address'],
        'latitude' =>
            $property['latitude'] !== null
                ? (string) $property['latitude']
                : '',
        'longitude' =>
            $property['longitude'] !== null
                ? (string) $property['longitude']
                : ''
    );
}

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.route-add-page {
    --ra-primary: #6d28d9;
    --ra-text: #111827;
    --ra-muted: #6b7280;
    --ra-border: #e5e7eb;
}

.ra-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.ra-header h1 {
    margin: 0;
    color: var(--ra-text);
    font-size: 21px;
    font-weight: 700;
}

.ra-header p {
    margin: 5px 0 0;
    color: var(--ra-muted);
    font-size: 11px;
}

.ra-back,
.ra-button {
    min-height: 36px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 9px;
    font-family: inherit;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}

.ra-back,
.ra-button.secondary {
    border: 1px solid var(--ra-border);
    background: #fff;
    color: #374151;
}

.ra-button.primary {
    border: 1px solid var(--ra-primary);
    background: var(--ra-primary);
    color: #fff;
}

.ra-button.danger {
    border: 1px solid #fecaca;
    background: #fff;
    color: #b91c1c;
}

.ra-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 10px;
    line-height: 1.6;
}

.ra-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.5fr)
        minmax(290px,.58fr);
    gap: 13px;
    align-items: start;
}

.ra-card {
    overflow: hidden;
    border: 1px solid var(--ra-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.ra-card + .ra-card {
    margin-top: 13px;
}

.ra-card-head {
    min-height: 46px;
    padding: 11px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #f1f5f9;
}

.ra-card-head h2 {
    margin: 0;
    color: var(--ra-text);
    font-size: 11px;
    font-weight: 700;
}

.ra-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.ra-card-body {
    padding: 14px;
}

.ra-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 11px;
}

.ra-field {
    min-width: 0;
}

.ra-field.full {
    grid-column: 1 / -1;
}

.ra-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.ra-required {
    color: #dc2626;
}

.ra-input,
.ra-select,
.ra-textarea {
    width: 100%;
    min-height: 38px;
    padding: 9px 10px;
    border: 1px solid #dfe3e8;
    border-radius: 9px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 9px;
    outline: none;
}

.ra-textarea {
    min-height: 86px;
    resize: vertical;
}

.ra-input:focus,
.ra-select:focus,
.ra-textarea:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.09);
}

.ra-help {
    margin-top: 4px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.45;
}

.ra-stop-list {
    display: grid;
    gap: 10px;
}

.ra-stop {
    overflow: hidden;
    border: 1px solid #e7e9ee;
    border-radius: 11px;
    background: #fff;
}

.ra-stop-head {
    padding: 9px 11px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    border-bottom: 1px solid #f1f5f9;
    background: #fafafa;
}

.ra-stop-title {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #111827;
    font-size: 9px;
    font-weight: 700;
}

.ra-stop-order {
    width: 23px;
    height: 23px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: var(--ra-primary);
    color: #fff;
    font-size: 8px;
}

.ra-stop-actions {
    display: flex;
    gap: 5px;
}

.ra-icon-button {
    width: 29px;
    height: 29px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--ra-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    cursor: pointer;
}

.ra-icon-button.remove {
    border-color: #fecaca;
    color: #b91c1c;
}

.ra-stop-body {
    padding: 11px;
}

.ra-stop-grid {
    display: grid;
    grid-template-columns:
        repeat(4,minmax(0,1fr));
    gap: 9px;
}

.ra-stop-field {
    min-width: 0;
}

.ra-stop-field.span-2 {
    grid-column: span 2;
}

.ra-stop-field.full {
    grid-column: 1 / -1;
}

.ra-summary {
    display: grid;
    gap: 9px;
}

.ra-summary-item {
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.ra-summary-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.ra-summary-value {
    margin-top: 4px;
    display: block;
    color: #111827;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.ra-actions {
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}

@media (max-width: 1120px) {
    .ra-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 920px) {
    .ra-stop-grid {
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 680px) {
    .ra-header {
        flex-direction: column;
    }

    .ra-grid,
    .ra-stop-grid {
        grid-template-columns: 1fr;
    }

    .ra-field.full,
    .ra-stop-field.span-2,
    .ra-stop-field.full {
        grid-column: auto;
    }

    .ra-actions {
        flex-direction: column-reverse;
    }

    .ra-button {
        width: 100%;
    }
}
</style>

<div class="route-add-page">
    <div class="ra-header">
        <div>
            <h1>Add Route</h1>
            <p>
                Create a worker route and arrange its visit, property, or manual stops.
            </p>
        </div>

        <a href="routes.php" class="ra-back">
            <i class="bi bi-arrow-left"></i>
            Back to Routes
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="ra-alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action=""
        id="routeAddForm"
        autocomplete="off"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken); ?>"
        >

        <div class="ra-layout">
            <main>
                <section class="ra-card">
                    <div class="ra-card-head">
                        <div>
                            <h2>Route Details</h2>
                            <p>
                                Set the route name, date, worker, status, and summary totals.
                            </p>
                        </div>
                    </div>

                    <div class="ra-card-body">
                        <div class="ra-grid">
                            <div class="ra-field full">
                                <label class="ra-label">
                                    Route Name
                                    <span class="ra-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="name"
                                    id="routeName"
                                    class="ra-input"
                                    maxlength="190"
                                    value="<?= e(
                                        routeAddOld('name')
                                    ); ?>"
                                    placeholder="Example: Dharmapuri Morning Route"
                                    required
                                >
                            </div>

                            <div class="ra-field">
                                <label class="ra-label">
                                    Route Date
                                    <span class="ra-required">*</span>
                                </label>

                                <input
                                    type="date"
                                    name="route_date"
                                    id="routeDate"
                                    class="ra-input"
                                    value="<?= e(
                                        $selectedRouteDate
                                    ); ?>"
                                    required
                                >
                            </div>

                            <div class="ra-field">
                                <label class="ra-label">
                                    Assigned Worker
                                </label>

                                <select
                                    name="assigned_user_id"
                                    id="routeWorker"
                                    class="ra-select"
                                >
                                    <option value="">
                                        Unassigned
                                    </option>

                                    <?php foreach ($workers as $worker): ?>
                                        <?php
                                        $workerName = trim(
                                            (string) $worker['first_name'] .
                                            ' ' .
                                            (string) $worker['last_name']
                                        );
                                        ?>
                                        <option
                                            value="<?= (int) $worker['id']; ?>"
                                            <?= $selectedWorkerId ===
                                                (int) $worker['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($workerName); ?>

                                            <?php if (
                                                trim(
                                                    (string) $worker['job_title']
                                                ) !== ''
                                            ): ?>
                                                · <?= e(
                                                    $worker['job_title']
                                                ); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ra-field">
                                <label class="ra-label">
                                    Route Status
                                </label>

                                <select
                                    name="status"
                                    id="routeStatus"
                                    class="ra-select"
                                >
                                    <option
                                        value="draft"
                                        <?= $selectedStatus === 'draft'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Draft
                                    </option>

                                    <option
                                        value="optimized"
                                        <?= $selectedStatus === 'optimized'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Optimized
                                    </option>

                                    <option
                                        value="dispatched"
                                        <?= $selectedStatus === 'dispatched'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Dispatched
                                    </option>
                                </select>
                            </div>

                            <div class="ra-field">
                                <label class="ra-label">
                                    Optimization Provider
                                </label>

                                <input
                                    type="text"
                                    name="optimization_provider"
                                    class="ra-input"
                                    maxlength="120"
                                    value="<?= e(
                                        routeAddOld(
                                            'optimization_provider'
                                        )
                                    ); ?>"
                                    placeholder="Example: Manual, Google Maps"
                                >
                            </div>

                            <div class="ra-field">
                                <label class="ra-label">
                                    Total Distance (KM)
                                </label>

                                <input
                                    type="number"
                                    name="total_distance_km"
                                    id="routeDistance"
                                    class="ra-input"
                                    min="0"
                                    step="0.01"
                                    value="<?= e(
                                        routeAddOld(
                                            'total_distance_km'
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ra-field">
                                <label class="ra-label">
                                    Total Duration (Minutes)
                                </label>

                                <input
                                    type="number"
                                    name="total_duration_minutes"
                                    id="routeDuration"
                                    class="ra-input"
                                    min="0"
                                    step="1"
                                    value="<?= e(
                                        routeAddOld(
                                            'total_duration_minutes'
                                        )
                                    ); ?>"
                                >
                            </div>
                        </div>
                    </div>
                </section>

                <section class="ra-card">
                    <div class="ra-card-head">
                        <div>
                            <h2>Route Stops</h2>
                            <p>
                                Add visits, properties, or manual addresses and arrange their order.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="ra-button primary"
                            id="addRouteStop"
                        >
                            <i class="bi bi-plus-lg"></i>
                            Add Stop
                        </button>
                    </div>

                    <div class="ra-card-body">
                        <div
                            class="ra-stop-list"
                            id="routeStopList"
                        >
                            <?php foreach (
                                $submittedStops as
                                $stopIndex => $stop
                            ): ?>
                                <article class="ra-stop">
                                    <div class="ra-stop-head">
                                        <div class="ra-stop-title">
                                            <span class="ra-stop-order">
                                                <?= e(
                                                    $stopIndex + 1
                                                ); ?>
                                            </span>
                                            Route Stop
                                        </div>

                                        <div class="ra-stop-actions">
                                            <button
                                                type="button"
                                                class="ra-icon-button move-up"
                                                title="Move Up"
                                            >
                                                <i class="bi bi-arrow-up"></i>
                                            </button>

                                            <button
                                                type="button"
                                                class="ra-icon-button move-down"
                                                title="Move Down"
                                            >
                                                <i class="bi bi-arrow-down"></i>
                                            </button>

                                            <button
                                                type="button"
                                                class="ra-icon-button remove remove-stop"
                                                title="Remove Stop"
                                            >
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="ra-stop-body">
                                        <div class="ra-stop-grid">
                                            <div class="ra-stop-field span-2">
                                                <label class="ra-label">
                                                    Visit
                                                </label>

                                                <select
                                                    name="stop_visit_id[]"
                                                    class="ra-select stop-visit"
                                                >
                                                    <option value="">
                                                        No Visit
                                                    </option>

                                                    <?php foreach (
                                                        $visits as $visit
                                                    ): ?>
                                                        <option
                                                            value="<?= (int) $visit['id']; ?>"
                                                            <?= (int) $stop['visit_id'] ===
                                                                (int) $visit['id']
                                                                    ? 'selected'
                                                                    : ''; ?>
                                                        >
                                                            <?= e(
                                                                trim(
                                                                    (string) $visit['visit_no']
                                                                ) !== ''
                                                                    ? $visit['visit_no']
                                                                    : 'Visit #' .
                                                                        $visit['id']
                                                            ); ?>
                                                            · <?= e(
                                                                $visit['job_no']
                                                            ); ?>
                                                            · <?= e(
                                                                $visit['client_name']
                                                            ); ?>

                                                            <?php if (
                                                                !empty(
                                                                    $visit['scheduled_start']
                                                                )
                                                            ): ?>
                                                                · <?= e(
                                                                    date(
                                                                        'd M Y h:i A',
                                                                        strtotime(
                                                                            $visit['scheduled_start']
                                                                        )
                                                                    )
                                                                ); ?>
                                                            <?php endif; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="ra-stop-field span-2">
                                                <label class="ra-label">
                                                    Property
                                                </label>

                                                <select
                                                    name="stop_property_id[]"
                                                    class="ra-select stop-property"
                                                >
                                                    <option value="">
                                                        No Property
                                                    </option>

                                                    <?php foreach (
                                                        $properties as $property
                                                    ): ?>
                                                        <?php
                                                        $propertyLabel =
                                                            trim(
                                                                (string) $property['name']
                                                            ) !== ''
                                                                ? (string) $property['name']
                                                                : (string) $property['address_line1'];
                                                        ?>
                                                        <option
                                                            value="<?= (int) $property['id']; ?>"
                                                            <?= (int) $stop['property_id'] ===
                                                                (int) $property['id']
                                                                    ? 'selected'
                                                                    : ''; ?>
                                                        >
                                                            <?= e(
                                                                $property['client_name']
                                                            ); ?>
                                                            · <?= e(
                                                                $propertyLabel
                                                            ); ?>
                                                            · <?= e(
                                                                $property['full_address']
                                                            ); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="ra-stop-field full">
                                                <label class="ra-label">
                                                    Stop Address
                                                    <span class="ra-required">*</span>
                                                </label>

                                                <input
                                                    type="text"
                                                    name="stop_address[]"
                                                    class="ra-input stop-address"
                                                    maxlength="500"
                                                    value="<?= e(
                                                        $stop['address']
                                                    ); ?>"
                                                    placeholder="Enter the complete stop address"
                                                >
                                            </div>

                                            <div class="ra-stop-field">
                                                <label class="ra-label">
                                                    Latitude
                                                </label>

                                                <input
                                                    type="number"
                                                    name="stop_latitude[]"
                                                    class="ra-input stop-latitude"
                                                    min="-90"
                                                    max="90"
                                                    step="0.0000001"
                                                    value="<?= e(
                                                        $stop['latitude']
                                                    ); ?>"
                                                >
                                            </div>

                                            <div class="ra-stop-field">
                                                <label class="ra-label">
                                                    Longitude
                                                </label>

                                                <input
                                                    type="number"
                                                    name="stop_longitude[]"
                                                    class="ra-input stop-longitude"
                                                    min="-180"
                                                    max="180"
                                                    step="0.0000001"
                                                    value="<?= e(
                                                        $stop['longitude']
                                                    ); ?>"
                                                >
                                            </div>

                                            <div class="ra-stop-field">
                                                <label class="ra-label">
                                                    Estimated Arrival
                                                </label>

                                                <input
                                                    type="datetime-local"
                                                    name="stop_arrival[]"
                                                    class="ra-input stop-arrival"
                                                    value="<?= e(
                                                        $stop['arrival']
                                                    ); ?>"
                                                >
                                            </div>

                                            <div class="ra-stop-field">
                                                <label class="ra-label">
                                                    Estimated Departure
                                                </label>

                                                <input
                                                    type="datetime-local"
                                                    name="stop_departure[]"
                                                    class="ra-input stop-departure"
                                                    value="<?= e(
                                                        $stop['departure']
                                                    ); ?>"
                                                >
                                            </div>

                                            <div class="ra-stop-field span-2">
                                                <label class="ra-label">
                                                    Distance From Previous Stop (KM)
                                                </label>

                                                <input
                                                    type="number"
                                                    name="stop_distance[]"
                                                    class="ra-input stop-distance"
                                                    min="0"
                                                    step="0.01"
                                                    value="<?= e(
                                                        $stop['distance']
                                                    ); ?>"
                                                >
                                            </div>

                                            <div class="ra-stop-field span-2">
                                                <label class="ra-label">
                                                    Travel Time From Previous Stop (Minutes)
                                                </label>

                                                <input
                                                    type="number"
                                                    name="stop_duration[]"
                                                    class="ra-input stop-duration"
                                                    min="0"
                                                    step="1"
                                                    value="<?= e(
                                                        $stop['duration']
                                                    ); ?>"
                                                >
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            </main>

            <aside>
                <section class="ra-card">
                    <div class="ra-card-head">
                        <div>
                            <h2>Route Summary</h2>
                            <p>
                                Review the route before saving.
                            </p>
                        </div>
                    </div>

                    <div class="ra-card-body">
                        <div class="ra-summary">
                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Route
                                </span>

                                <span
                                    class="ra-summary-value"
                                    id="summaryRouteName"
                                >
                                    Not entered
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Date
                                </span>

                                <span
                                    class="ra-summary-value"
                                    id="summaryRouteDate"
                                >
                                    <?= e(
                                        $selectedRouteDate
                                    ); ?>
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Worker
                                </span>

                                <span
                                    class="ra-summary-value"
                                    id="summaryRouteWorker"
                                >
                                    Unassigned
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Status
                                </span>

                                <span
                                    class="ra-summary-value"
                                    id="summaryRouteStatus"
                                >
                                    Draft
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Stops
                                </span>

                                <span
                                    class="ra-summary-value"
                                    id="summaryStopCount"
                                >
                                    <?= e(
                                        count($submittedStops)
                                    ); ?>
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Total Distance
                                </span>

                                <span
                                    class="ra-summary-value"
                                    id="summaryDistance"
                                >
                                    Not entered
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Total Duration
                                </span>

                                <span
                                    class="ra-summary-value"
                                    id="summaryDuration"
                                >
                                    Not entered
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="ra-actions">
                        <a
                            href="routes.php"
                            class="ra-button secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="ra-button primary"
                        >
                            <i class="bi bi-check2"></i>
                            Save Route
                        </button>
                    </div>
                </section>
            </aside>
        </div>
    </form>
</div>

<template id="routeStopTemplate">
    <article class="ra-stop">
        <div class="ra-stop-head">
            <div class="ra-stop-title">
                <span class="ra-stop-order">1</span>
                Route Stop
            </div>

            <div class="ra-stop-actions">
                <button
                    type="button"
                    class="ra-icon-button move-up"
                    title="Move Up"
                >
                    <i class="bi bi-arrow-up"></i>
                </button>

                <button
                    type="button"
                    class="ra-icon-button move-down"
                    title="Move Down"
                >
                    <i class="bi bi-arrow-down"></i>
                </button>

                <button
                    type="button"
                    class="ra-icon-button remove remove-stop"
                    title="Remove Stop"
                >
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>

        <div class="ra-stop-body">
            <div class="ra-stop-grid">
                <div class="ra-stop-field span-2">
                    <label class="ra-label">
                        Visit
                    </label>

                    <select
                        name="stop_visit_id[]"
                        class="ra-select stop-visit"
                    >
                        <option value="">No Visit</option>

                        <?php foreach ($visits as $visit): ?>
                            <option value="<?= (int) $visit['id']; ?>">
                                <?= e(
                                    trim(
                                        (string) $visit['visit_no']
                                    ) !== ''
                                        ? $visit['visit_no']
                                        : 'Visit #' .
                                            $visit['id']
                                ); ?>
                                · <?= e($visit['job_no']); ?>
                                · <?= e($visit['client_name']); ?>

                                <?php if (
                                    !empty(
                                        $visit['scheduled_start']
                                    )
                                ): ?>
                                    · <?= e(
                                        date(
                                            'd M Y h:i A',
                                            strtotime(
                                                $visit['scheduled_start']
                                            )
                                        )
                                    ); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ra-stop-field span-2">
                    <label class="ra-label">
                        Property
                    </label>

                    <select
                        name="stop_property_id[]"
                        class="ra-select stop-property"
                    >
                        <option value="">No Property</option>

                        <?php foreach (
                            $properties as $property
                        ): ?>
                            <?php
                            $propertyLabel =
                                trim(
                                    (string) $property['name']
                                ) !== ''
                                    ? (string) $property['name']
                                    : (string) $property['address_line1'];
                            ?>
                            <option value="<?= (int) $property['id']; ?>">
                                <?= e(
                                    $property['client_name']
                                ); ?>
                                · <?= e($propertyLabel); ?>
                                · <?= e(
                                    $property['full_address']
                                ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ra-stop-field full">
                    <label class="ra-label">
                        Stop Address
                        <span class="ra-required">*</span>
                    </label>

                    <input
                        type="text"
                        name="stop_address[]"
                        class="ra-input stop-address"
                        maxlength="500"
                        placeholder="Enter the complete stop address"
                    >
                </div>

                <div class="ra-stop-field">
                    <label class="ra-label">
                        Latitude
                    </label>

                    <input
                        type="number"
                        name="stop_latitude[]"
                        class="ra-input stop-latitude"
                        min="-90"
                        max="90"
                        step="0.0000001"
                    >
                </div>

                <div class="ra-stop-field">
                    <label class="ra-label">
                        Longitude
                    </label>

                    <input
                        type="number"
                        name="stop_longitude[]"
                        class="ra-input stop-longitude"
                        min="-180"
                        max="180"
                        step="0.0000001"
                    >
                </div>

                <div class="ra-stop-field">
                    <label class="ra-label">
                        Estimated Arrival
                    </label>

                    <input
                        type="datetime-local"
                        name="stop_arrival[]"
                        class="ra-input stop-arrival"
                    >
                </div>

                <div class="ra-stop-field">
                    <label class="ra-label">
                        Estimated Departure
                    </label>

                    <input
                        type="datetime-local"
                        name="stop_departure[]"
                        class="ra-input stop-departure"
                    >
                </div>

                <div class="ra-stop-field span-2">
                    <label class="ra-label">
                        Distance From Previous Stop (KM)
                    </label>

                    <input
                        type="number"
                        name="stop_distance[]"
                        class="ra-input stop-distance"
                        min="0"
                        step="0.01"
                    >
                </div>

                <div class="ra-stop-field span-2">
                    <label class="ra-label">
                        Travel Time From Previous Stop (Minutes)
                    </label>

                    <input
                        type="number"
                        name="stop_duration[]"
                        class="ra-input stop-duration"
                        min="0"
                        step="1"
                    >
                </div>
            </div>
        </div>
    </article>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var visitMap = <?= json_encode(
        $visitJson,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var propertyMap = <?= json_encode(
        $propertyJson,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var stopList =
        document.getElementById('routeStopList');

    var stopTemplate =
        document.getElementById('routeStopTemplate');

    var addStopButton =
        document.getElementById('addRouteStop');

    var routeName =
        document.getElementById('routeName');

    var routeDate =
        document.getElementById('routeDate');

    var routeWorker =
        document.getElementById('routeWorker');

    var routeStatus =
        document.getElementById('routeStatus');

    var routeDistance =
        document.getElementById('routeDistance');

    var routeDuration =
        document.getElementById('routeDuration');

    function selectedText(select, fallback) {
        var option =
            select.options[
                select.selectedIndex
            ];

        if (
            !option ||
            option.value === ''
        ) {
            return fallback;
        }

        return option.textContent
            .replace(/\s+/g, ' ')
            .trim();
    }

    function addMinutes(value, minutes) {
        if (!value) {
            return '';
        }

        var date =
            new Date(value);

        if (isNaN(date.getTime())) {
            return '';
        }

        date.setMinutes(
            date.getMinutes() + minutes
        );

        function pad(number) {
            return String(number).padStart(2, '0');
        }

        return date.getFullYear() +
            '-' +
            pad(date.getMonth() + 1) +
            '-' +
            pad(date.getDate()) +
            'T' +
            pad(date.getHours()) +
            ':' +
            pad(date.getMinutes());
    }

    function getRows() {
        return Array.prototype.slice.call(
            stopList.querySelectorAll(
                '.ra-stop'
            )
        );
    }

    function renumberStops() {
        var rows = getRows();

        rows.forEach(function (row, index) {
            var order =
                row.querySelector(
                    '.ra-stop-order'
                );

            if (order) {
                order.textContent =
                    String(index + 1);
            }

            var upButton =
                row.querySelector('.move-up');

            var downButton =
                row.querySelector('.move-down');

            if (upButton) {
                upButton.disabled =
                    index === 0;
            }

            if (downButton) {
                downButton.disabled =
                    index === rows.length - 1;
            }
        });

        document.getElementById(
            'summaryStopCount'
        ).textContent =
            String(rows.length);
    }

    function updateSummary() {
        document.getElementById(
            'summaryRouteName'
        ).textContent =
            routeName.value.trim() !== ''
                ? routeName.value.trim()
                : 'Not entered';

        document.getElementById(
            'summaryRouteDate'
        ).textContent =
            routeDate.value !== ''
                ? routeDate.value
                : 'Not entered';

        document.getElementById(
            'summaryRouteWorker'
        ).textContent =
            selectedText(
                routeWorker,
                'Unassigned'
            );

        document.getElementById(
            'summaryRouteStatus'
        ).textContent =
            selectedText(
                routeStatus,
                'Draft'
            );

        document.getElementById(
            'summaryDistance'
        ).textContent =
            routeDistance.value !== ''
                ? routeDistance.value + ' km'
                : 'Not entered';

        document.getElementById(
            'summaryDuration'
        ).textContent =
            routeDuration.value !== ''
                ? routeDuration.value +
                    ' minutes'
                : 'Not entered';

        renumberStops();
    }

    function applyVisit(row) {
        var visitSelect =
            row.querySelector('.stop-visit');

        var visit =
            visitMap[
                String(visitSelect.value || '')
            ];

        if (!visit) {
            return;
        }

        var propertySelect =
            row.querySelector('.stop-property');

        var address =
            row.querySelector('.stop-address');

        var latitude =
            row.querySelector('.stop-latitude');

        var longitude =
            row.querySelector('.stop-longitude');

        var arrival =
            row.querySelector('.stop-arrival');

        var departure =
            row.querySelector('.stop-departure');

        if (
            visit.property_id !== null &&
            propertySelect.value === ''
        ) {
            propertySelect.value =
                String(visit.property_id);
        }

        if (
            address.value.trim() === '' &&
            visit.address
        ) {
            address.value =
                visit.address;
        }

        if (
            latitude.value === '' &&
            visit.latitude !== ''
        ) {
            latitude.value =
                visit.latitude;
        }

        if (
            longitude.value === '' &&
            visit.longitude !== ''
        ) {
            longitude.value =
                visit.longitude;
        }

        if (
            arrival.value === '' &&
            visit.scheduled_start
        ) {
            arrival.value =
                visit.scheduled_start;
        }

        if (
            departure.value === '' &&
            visit.scheduled_end
        ) {
            departure.value =
                visit.scheduled_end;
        }

        if (
            routeWorker.value === '' &&
            visit.assigned_user_id !== null
        ) {
            routeWorker.value =
                String(
                    visit.assigned_user_id
                );
        }

        if (
            routeDate.value === '' &&
            visit.scheduled_start
        ) {
            routeDate.value =
                visit.scheduled_start.slice(
                    0,
                    10
                );
        }

        updateSummary();
    }

    function applyProperty(row) {
        var propertySelect =
            row.querySelector(
                '.stop-property'
            );

        var property =
            propertyMap[
                String(
                    propertySelect.value || ''
                )
            ];

        if (!property) {
            return;
        }

        var address =
            row.querySelector('.stop-address');

        var latitude =
            row.querySelector('.stop-latitude');

        var longitude =
            row.querySelector('.stop-longitude');

        if (address.value.trim() === '') {
            address.value =
                property.address || '';
        }

        if (
            latitude.value === '' &&
            property.latitude !== ''
        ) {
            latitude.value =
                property.latitude;
        }

        if (
            longitude.value === '' &&
            property.longitude !== ''
        ) {
            longitude.value =
                property.longitude;
        }
    }

    function bindRow(row) {
        var visitSelect =
            row.querySelector('.stop-visit');

        var propertySelect =
            row.querySelector('.stop-property');

        var arrival =
            row.querySelector('.stop-arrival');

        var departure =
            row.querySelector('.stop-departure');

        var removeButton =
            row.querySelector('.remove-stop');

        var upButton =
            row.querySelector('.move-up');

        var downButton =
            row.querySelector('.move-down');

        visitSelect.addEventListener(
            'change',
            function () {
                applyVisit(row);
            }
        );

        propertySelect.addEventListener(
            'change',
            function () {
                applyProperty(row);
            }
        );

        arrival.addEventListener(
            'change',
            function () {
                if (
                    arrival.value !== '' &&
                    departure.value === ''
                ) {
                    departure.value =
                        addMinutes(
                            arrival.value,
                            30
                        );
                }
            }
        );

        removeButton.addEventListener(
            'click',
            function () {
                var rows = getRows();

                if (rows.length === 1) {
                    row.querySelector(
                        '.stop-visit'
                    ).value = '';

                    row.querySelector(
                        '.stop-property'
                    ).value = '';

                    row.querySelector(
                        '.stop-address'
                    ).value = '';

                    row.querySelector(
                        '.stop-latitude'
                    ).value = '';

                    row.querySelector(
                        '.stop-longitude'
                    ).value = '';

                    row.querySelector(
                        '.stop-arrival'
                    ).value = '';

                    row.querySelector(
                        '.stop-departure'
                    ).value = '';

                    row.querySelector(
                        '.stop-distance'
                    ).value = '';

                    row.querySelector(
                        '.stop-duration'
                    ).value = '';

                    return;
                }

                row.remove();
                renumberStops();
            }
        );

        upButton.addEventListener(
            'click',
            function () {
                var previous =
                    row.previousElementSibling;

                if (previous) {
                    stopList.insertBefore(
                        row,
                        previous
                    );

                    renumberStops();
                }
            }
        );

        downButton.addEventListener(
            'click',
            function () {
                var next =
                    row.nextElementSibling;

                if (next) {
                    stopList.insertBefore(
                        next,
                        row
                    );

                    renumberStops();
                }
            }
        );
    }

    addStopButton.addEventListener(
        'click',
        function () {
            var fragment =
                stopTemplate.content.cloneNode(
                    true
                );

            var row =
                fragment.querySelector(
                    '.ra-stop'
                );

            stopList.appendChild(fragment);

            var addedRow =
                stopList.lastElementChild;

            bindRow(addedRow);
            renumberStops();

            addedRow.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }
    );

    getRows().forEach(function (row) {
        bindRow(row);
    });

    [
        routeName,
        routeDate,
        routeWorker,
        routeStatus,
        routeDistance,
        routeDuration
    ].forEach(function (element) {
        element.addEventListener(
            'input',
            updateSummary
        );

        element.addEventListener(
            'change',
            updateSummary
        );
    });

    document.getElementById(
        'routeAddForm'
    ).addEventListener(
        'submit',
        function (event) {
            var rows = getRows();
            var hasValidStop = false;

            rows.forEach(function (row) {
                var visitValue =
                    row.querySelector(
                        '.stop-visit'
                    ).value;

                var propertyValue =
                    row.querySelector(
                        '.stop-property'
                    ).value;

                var addressValue =
                    row.querySelector(
                        '.stop-address'
                    ).value.trim();

                if (
                    visitValue !== '' ||
                    propertyValue !== '' ||
                    addressValue !== ''
                ) {
                    hasValidStop = true;
                }
            });

            if (!hasValidStop) {
                event.preventDefault();

                window.alert(
                    'Add at least one route stop.'
                );
            }
        }
    );

    getRows().forEach(function (row) {
        var visitSelect =
            row.querySelector('.stop-visit');

        var propertySelect =
            row.querySelector('.stop-property');

        if (
            visitSelect.value !== '' &&
            row.querySelector(
                '.stop-address'
            ).value.trim() === ''
        ) {
            applyVisit(row);
        } else if (
            propertySelect.value !== '' &&
            row.querySelector(
                '.stop-address'
            ).value.trim() === ''
        ) {
            applyProperty(row);
        }
    });

    updateSummary();
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
