<?php
/**
 * api/stats.php — live counters for the scan station and dashboard.
 *
 * GET ?event_id=12  ->  { ok, counters:{...}, recent:[...] }
 */

declare(strict_types=1);

define('ZDSPGC_API_MODE', true);
require_once __DIR__ . '/../includes/bootstrap.php';

$eventId = Helpers::getInt('event_id');
$event   = $eventId > 0 ? Database::one('SELECT * FROM events WHERE id = :id', ['id' => $eventId]) : null;

if ($event === null) {
    Helpers::jsonOut(['ok' => false, 'code' => 'no_event', 'message' => 'Unknown event.'], 404);
}

/* Public counters are fine, but names are only sent to signed-in staff. */
$isStaff = Auth::check();
$recent  = [];
if ($isStaff) {
    foreach (Attendance::recent($eventId, 10) as $row) {
        $recent[] = [
            'student_name'  => (string) $row['student_name'],
            'student_no'    => (string) $row['student_no'],
            'course'        => (string) $row['course'],
            'status'        => (string) $row['status'],
            'source'        => (string) $row['source'],
            'checked_in_at' => (string) $row['checked_in_at'],
        ];
    }
}

Helpers::jsonOut([
    'ok'       => true,
    'event'    => [
        'id'      => (int) $event['id'],
        'code'    => (string) $event['code'],
        'title'   => (string) $event['title'],
        'state'   => Attendance::windowState($event)['state'],
        'status'  => (string) $event['status'],
    ],
    'counters' => Attendance::counters($eventId),
    'absent'   => Attendance::absenteeCount($eventId),
    'recent'   => $recent,
    'server'   => Helpers::now(),
]);
