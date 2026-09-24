<?php
/**
 * api/checkin.php — the only endpoint the scanner talks to.
 *
 * Expects JSON: { token, event_id, method, source, station, student_no? }
 * Returns JSON: { ok, code, message, student, record, counters }
 *
 * All the rules live in PHP (Attendance::checkIn): signed token check, event
 * window, duplicate prevention, grace period, rate limiting, audit logging.
 */

declare(strict_types=1);

define('ZDSPGC_API_MODE', true);
require_once __DIR__ . '/../includes/bootstrap.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    Helpers::jsonOut(['ok' => false, 'code' => 'method', 'message' => 'POST only.'], 405);
}

/* ---- CSRF + rate limiting ---- */
if (!Security::csrfValid()) {
    Helpers::jsonOut(['ok' => false, 'code' => 'csrf', 'message' => 'Session expired. Please reload the page and sign in again.'], 419);
}

$rate = Security::rateLimit('checkin', CHECKIN_RATE_PER_MIN, 60);
if (!$rate['allowed']) {
    Security::audit('CHECKIN_RATE_LIMITED', 'Check-in rate limit hit', 'attendance');
    Helpers::jsonOut([
        'ok'          => false,
        'code'        => 'rate_limited',
        'message'     => 'Too many scans in a minute. Please slow down (' . $rate['retry_after'] . 's).',
        'counters'    => null,
    ], 429);
}

/* ---- input ---- */
$token     = Helpers::input('token');
$studentNo = Security::clean(Helpers::input('student_no'), 30);
$eventId   = (int) (Helpers::input('event_id') ?? 0);
$method    = (string) (Helpers::input('method') ?? 'qr');
$source    = (string) (Helpers::input('source') ?? 'station');
$station   = Security::clean(Helpers::input('station'), 80);

$source = $source === 'self' ? 'self' : 'station';
if ($source === 'self') {
    $method = 'qr';        // self check-in is always a scan
}

/* ---- which event? ---- */
$event = null;
if ($eventId > 0) {
    $event = Database::one('SELECT * FROM events WHERE id = :id', ['id' => $eventId]);
} elseif ($token !== null) {
    // A scanned event poster carries the event token; use it when no id was sent.
    $event = Attendance::resolveEventByToken($token);
}

if ($event === null) {
    Helpers::jsonOut([
        'ok'       => false,
        'code'     => 'no_event',
        'message'  => 'Select an event first.',
        'student'  => null,
        'record'   => null,
        'counters' => null,
    ], 400);
}

/* ---- who is checking in? ---- */
if ($token !== null && $token !== '') {
    // The student scanned their own ID QR. (If an event token was scanned by
    // mistake, resolveStudentByToken returns null and we reject it.)
    $student = Attendance::resolveStudentByToken($token);
    if ($student === null) {
        Helpers::jsonOut([
            'ok'       => false,
            'code'     => 'invalid_token',
            'message'  => 'Unrecognised or revoked QR code. Ask the student to see the registrar for a new ID.',
            'student'  => null,
            'record'   => null,
            'counters' => Attendance::counters((int) $event['id']),
        ], 200);
    }
} elseif ($studentNo !== '') {
    // Manual entry is reserved for signed-in staff at the station.
    if ($source === 'self' || !Auth::can('run_scanner')) {
        Helpers::jsonOut([
            'ok'       => false,
            'code'     => 'manual_denied',
            'message'  => 'Manual entry needs a signed-in officer. Please scan the student ID instead.',
            'student'  => null,
            'record'   => null,
            'counters' => Attendance::counters((int) $event['id']),
        ], 403);
    }
    $student = Attendance::studentByNumber($studentNo);
    if ($student === null) {
        Helpers::jsonOut([
            'ok'       => false,
            'code'     => 'unknown_student',
            'message'  => 'No student found with number "' . $studentNo . '".',
            'student'  => null,
            'record'   => null,
            'counters' => Attendance::counters((int) $event['id']),
        ], 200);
    }
} else {
    Helpers::jsonOut([
        'ok'       => false,
        'code'     => 'empty',
        'message'  => 'Nothing scanned yet.',
        'student'  => null,
        'record'   => null,
        'counters' => Attendance::counters((int) $event['id']),
    ], 200);
}

/* ---- record it ---- */
$operator = $source === 'self' ? null : Auth::user();
$result   = Attendance::checkIn($event, $student, $method, $source, $station, $operator);

/* Enrich the payload the verdict panel renders — all of it server-decided. */
if ($result['record'] !== null) {
    $result['record']['status_label'] = Attendance::statusLabel((string) $result['record']['status']);
}

Helpers::jsonOut([
    'ok'       => $result['ok'],
    'code'     => $result['code'],
    'message'  => $result['message'],
    'student'  => $result['student'],
    'record'   => $result['record'],
    'event'    => [
        'id'        => (int) $event['id'],
        'code'      => (string) $event['code'],
        'title'     => (string) $event['title'],
        'venue'     => (string) $event['venue'],
        'starts_at' => (string) $event['starts_at'],
        'state'     => Attendance::windowState($event)['state'],
    ],
    'counters' => $result['counters'],
], 200);
