<?php
/**
 * FieldPlx Calendar Subscription Feed
 * File: business/calendar-feed.php
 * Returns a basic ICS feed for the current user's configured schedule items.
 * Compatible with PHP 7.2+ / MariaDB 11.x
 */

require_once __DIR__ . '/includes/db.php';

$token = isset($_GET['token']) && !is_array($_GET['token'])
    ? trim((string)$_GET['token'])
    : '';

if ($token === '') {
    http_response_code(404);
    exit('Calendar feed not found.');
}

$q = $pdo->prepare("
    SELECT *
    FROM calendar_sync_settings
    WHERE subscription_token = :token
    LIMIT 1
");
$q->execute(array(':token'=>$token));
$sync = $q->fetch(PDO::FETCH_ASSOC);

if (!$sync) {
    http_response_code(404);
    exit('Calendar feed not found.');
}

$tenantId = (int)$sync['tenant_id'];
$userId = (int)$sync['user_id'];
$events = array();

if (!empty($sync['sync_visits'])) {
    $q = $pdo->prepare("
        SELECT
            v.id,
            v.visit_no,
            v.scheduled_start,
            v.scheduled_end,
            j.title AS job_title
        FROM visits v
        LEFT JOIN jobs j
            ON j.id = v.job_id
           AND j.tenant_id = v.tenant_id
        LEFT JOIN visit_assignments va
            ON va.visit_id = v.id
           AND va.tenant_id = v.tenant_id
        WHERE v.tenant_id = :tenant_id
          AND (
                v.assigned_user_id = :user_id
                OR va.user_id = :user_id
              )
          AND v.scheduled_start IS NOT NULL
        ORDER BY v.scheduled_start ASC
        LIMIT 1000
    ");
    $q->execute(array(':tenant_id'=>$tenantId, ':user_id'=>$userId));

    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $events[] = array(
            'uid'=>'visit-' . (int)$row['id'],
            'summary'=>trim(($row['visit_no'] ? $row['visit_no'] . ' - ' : '') . ($row['job_title'] ?: 'Visit')),
            'start'=>$row['scheduled_start'],
            'end'=>$row['scheduled_end'] ?: $row['scheduled_start']
        );
    }
}

function ics_escape($value)
{
    $value = str_replace("\\", "\\\\", (string)$value);
    $value = str_replace(array("\r\n","\n","\r"), "\\n", $value);
    $value = str_replace(",", "\\,", $value);
    $value = str_replace(";", "\\;", $value);
    return $value;
}

function ics_time($value)
{
    $time = strtotime((string)$value);
    if (!$time) $time = time();
    return gmdate('Ymd\THis\Z', $time);
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="fieldplx-calendar.ics"');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//FieldPlx//Calendar Sync//EN\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";

foreach ($events as $event) {
    echo "BEGIN:VEVENT\r\n";
    echo "UID:" . ics_escape($event['uid'] . '@fieldplx') . "\r\n";
    echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    echo "DTSTART:" . ics_time($event['start']) . "\r\n";
    echo "DTEND:" . ics_time($event['end']) . "\r\n";
    echo "SUMMARY:" . ics_escape($event['summary']) . "\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";
