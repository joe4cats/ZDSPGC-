<?php
/**
 * export.php — CSV downloads (Excel/Sheets friendly).
 *
 *   ?type=attendance  [filters…]   the filtered attendance log
 *   ?type=students                 the active student roster
 *   ?type=summary                  one row per event with turnout numbers
 *   ?type=absentees&event_id=N     students with no record for an event
 *
 * Cells that start with =, +, - or @ are prefixed with an apostrophe so a
 * spreadsheet cannot execute them as a formula.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireCapability('view_reports');

$type = Security::clean(Helpers::get('type'), 20);

/** @param array<int,string> $header @param array<int,array<int,string>> $rows */
function csv_out(string $filename, array $header, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        Helpers::jsonOut(['ok' => false, 'message' => 'Cannot write the export stream.'], 500);
    }
    fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel opens UTF-8 correctly
    fputcsv($out, $header);

    foreach ($rows as $row) {
        fputcsv($out, array_map(static fn ($value) => Security::csvSafe((string) $value), $row));
    }
    fclose($out);
    exit;
}

$stamp = date('Ymd-His');

switch ($type) {
    case 'students':
        $rows = Database::all(
            "SELECT * FROM students WHERE status = 'active' ORDER BY course, full_name"
        );
        Security::audit('EXPORT', 'Exported ' . count($rows) . ' student rows', 'export');

        csv_out('zdspgc-students-' . $stamp . '.csv', [
            'Student number', 'Full name', 'Course', 'Year level', 'Section', 'Email', 'Contact',
            'Status', 'QR issued at', 'Created at',
        ], array_map(static fn ($r) => [
            $r['student_no'], $r['full_name'], $r['course'], $r['year_level'], $r['section'],
            $r['email'], $r['contact'], $r['status'], $r['qr_issued_at'], $r['created_at'],
        ], $rows));

        // no break needed — csv_out() exits
    case 'summary':
        $onlyToday = Helpers::get('range') === 'today';
        $events    = Attendance::eventsSummary(500, $onlyToday);
        $active    = Attendance::activeStudentCount();
        Security::audit('EXPORT', 'Exported conference summary for ' . count($events) . ' events', 'export');

        csv_out('zdspgc-event-summary-' . $stamp . '.csv', [
            'Event code', 'Event title', 'Venue', 'Starts at', 'Ends at', 'Status', 'Grace (min)',
            'Present', 'On time', 'Late', 'Active students', 'No record', 'Turnout %',
        ], array_map(static function ($r) use ($active) {
            $present = (int) $r['total'];
            return [
                $r['code'], $r['title'], $r['venue'], $r['starts_at'], $r['ends_at'], $r['status'],
                $r['grace_minutes'], $present, $r['on_time'], $r['late'], $active,
                max(0, $active - $present), Helpers::percent($present, max(1, $active)),
            ];
        }, $events));

    case 'absentees':
        $eventId = Helpers::getInt('event_id');
        $course  = Security::clean(Helpers::get('course'), 60);
        $event   = $eventId > 0 ? Attendance::eventSummary($eventId) : null;

        if ($event === null) {
            Helpers::jsonOut(['ok' => false, 'message' => 'A valid event_id is required.'], 400);
        }
        $absent = Attendance::absentees($eventId, $course === '' ? null : $course, 2000);
        Security::audit('EXPORT', 'Exported ' . count($absent) . ' absentees for ' . $event['code'], 'export');

        csv_out('zdspgc-absentees-' . $event['code'] . '-' . $stamp . '.csv', [
            'Event code', 'Event title', 'Student number', 'Full name', 'Course', 'Year level',
            'Section', 'Contact', 'Email',
        ], array_map(static fn ($r) => [
            $event['code'], $event['title'], $r['student_no'], $r['full_name'], $r['course'],
            $r['year_level'], $r['section'], $r['contact'], $r['email'],
        ], $absent));

    case 'attendance':
    default:
        $filters = [
            'event_id'   => Helpers::getInt('event_id'),
            'student_id' => Helpers::getInt('student_id'),
            'status'     => Security::clean(Helpers::get('status'), 20),
            'method'     => Security::clean(Helpers::get('method'), 20),
            'course'     => Security::clean(Helpers::get('course'), 60),
            'date_from'  => Security::clean(Helpers::get('date_from'), 10),
            'date_to'    => Security::clean(Helpers::get('date_to'), 10),
            'q'          => Security::clean(Helpers::get('q'), 60),
        ];
        $records = Attendance::listRecords($filters, 5000, 0);
        Security::audit('EXPORT', 'Exported ' . count($records) . ' attendance rows', 'export');

        csv_out('zdspgc-attendance-' . $stamp . '.csv', [
            'Checked in at', 'Status', 'Student number', 'Full name', 'Course', 'Year level', 'Section',
            'Event code', 'Event title', 'Venue', 'Event starts', 'Method', 'Source', 'Station',
            'Recorded by', 'Remark', 'IP address',
        ], array_map(static fn ($r) => [
            $r['checked_in_at'],
            Attendance::statusLabel((string) $r['status']),
            $r['student_no'],
            $r['student_name'],
            $r['course'],
            $r['year_level'],
            $r['section'],
            $r['event_code'],
            $r['event_title'],
            $r['venue'],
            $r['starts_at'],
            Attendance::methodLabel((string) $r['method']),
            $r['source'] === 'self' ? 'self check-in' : 'station',
            $r['station'],
            $r['scanned_by'],
            $r['remark'],
            $r['ip'],
        ], $records));
}
