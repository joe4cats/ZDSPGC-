<?php
/**
 * Attendance.php — the heart of the system: token handling, the check-in
 * business rules, and every query the pages need.
 *
 * Rules enforced on the server (never trust the browser):
 *   • the scanned QR must carry a valid HMAC signature AND match the nonce
 *     currently stored for that student (so re-issued IDs revoke old prints);
 *   • the event must be OPEN and "now" must be inside its check-in window;
 *   • a student can only be recorded once per event (unique DB constraint);
 *   • on time vs. late is decided from the event's grace period.
 */

declare(strict_types=1);

final class Attendance
{
    public const ON_TIME = 'on_time';
    public const LATE    = 'late';

    /* =====================================================================
     * Token helpers
     * ================================================================== */

    public static function studentToken(array $student): string
    {
        return Security::makeToken('S', (string) $student['student_no'], (string) $student['qr_nonce']);
    }

    public static function eventToken(array $event): string
    {
        return Security::makeToken('E', (string) $event['code'], (string) $event['qr_nonce']);
    }

    /** Public self-check-in link printed on the event poster. */
    public static function checkinUrl(array $event): string
    {
        $query = http_build_query([
            'event' => (string) $event['code'],
            'k'     => self::eventToken($event),
        ]);
        $base = PUBLIC_BASE_URL !== ''
            ? rtrim(PUBLIC_BASE_URL, '/')
            : rtrim(Helpers::url(''), '/');
        return $base . '/checkin.php?' . $query;
    }

    /** Student found by scanning a student ID QR — null when invalid/revoked. */
    public static function resolveStudentByToken(?string $token): ?array
    {
        $parsed = Security::readToken($token);
        if ($parsed === null || $parsed['type'] !== 'S') {
            return null;
        }
        $student = Database::one('SELECT * FROM students WHERE student_no = :no', ['no' => $parsed['key']]);
        if ($student === null || !Security::constantTimeEquals((string) $student['qr_nonce'], $parsed['nonce'])) {
            return null;
        }
        return $student;
    }

    /** Event found by scanning the event poster QR. */
    public static function resolveEventByToken(?string $token): ?array
    {
        $parsed = Security::readToken($token);
        if ($parsed === null || $parsed['type'] !== 'E') {
            return null;
        }
        $event = Database::one('SELECT * FROM events WHERE code = :code', ['code' => $parsed['key']]);
        if ($event === null || !Security::constantTimeEquals((string) $event['qr_nonce'], $parsed['nonce'])) {
            return null;
        }
        return $event;
    }

    public static function eventByCode(string $code): ?array
    {
        return Database::one('SELECT * FROM events WHERE code = :code', ['code' => $code]);
    }

    /* =====================================================================
     * Window / label helpers
     * ================================================================== */

    /** @return array{state:string,message:string} — upcoming | open | ended | closed */
    public static function windowState(array $event): array
    {
        $start = (int) strtotime((string) $event['starts_at']);
        $end   = (int) strtotime((string) $event['ends_at']);
        $now   = time();

        if (($event['status'] ?? 'open') !== 'open') {
            return ['state' => 'closed', 'message' => 'Check-in for this event is closed.'];
        }
        if ($now < $start - (EARLY_CHECKIN_MINUTES * 60)) {
            return ['state' => 'upcoming', 'message' => 'Check-in has not opened yet.'];
        }
        if ($now > $end) {
            return ['state' => 'ended', 'message' => 'The event has already ended.'];
        }
        return ['state' => 'open', 'message' => 'Check-in is open.'];
    }

    public static function statusLabel(string $status): string
    {
        return $status === self::LATE ? 'Late' : 'On time';
    }

    public static function methodLabel(string $method): string
    {
        return match ($method) {
            'manual' => 'Manual entry',
            'self'   => 'Self check-in',
            default  => 'QR scan',
        };
    }

    /* =====================================================================
     * Check-in
     * ================================================================== */

    /**
     * @return array{ok:bool,code:string,message:string,student:?array,record:?array,counters:array}
     */
    public static function checkIn(
        array $event,
        array $student,
        string $method = 'qr',
        string $source = 'station',
        string $station = '',
        ?array $operator = null,
        string $remark = ''
    ): array {
        $window = self::windowState($event);
        if ($window['state'] !== 'open') {
            $code = match ($window['state']) {
                'upcoming' => 'not_yet_open',
                'ended'    => 'event_ended',
                default    => 'event_closed',
            };
            return self::result(false, $code, $window['message'], $student, null, (int) $event['id']);
        }

        if (($student['status'] ?? 'active') !== 'active') {
            return self::result(false, 'inactive', 'This student record is inactive. Please refer to the registrar.', $student, null, (int) $event['id']);
        }

        // Self check-in only works when the organiser enabled it for the event.
        if ($source === 'self' && empty($event['self_checkin'])) {
            return self::result(false, 'self_disabled', 'Self check-in is not enabled for this event. Please see the officer at the station.', $student, null, (int) $event['id']);
        }

        $graceEnd = (int) strtotime((string) $event['starts_at']) + ((int) $event['grace_minutes'] * 60);
        $status   = time() <= $graceEnd ? self::ON_TIME : self::LATE;

        try {
            $id = Database::insert('attendance', [
                'event_id'      => (int) $event['id'],
                'student_id'    => (int) $student['id'],
                'checked_in_at' => Helpers::now(),
                'status'        => $status,
                'method'        => in_array($method, ['qr', 'manual'], true) ? $method : 'qr',
                'source'        => in_array($source, ['station', 'self'], true) ? $source : 'station',
                'station'       => $station,
                'scanned_by'    => (string) ($operator['full_name'] ?? ($source === 'self' ? 'self-service' : '')),
                'remark'        => $remark,
                'ip'            => Security::ip(),
                'user_agent'    => Security::userAgent(),
            ]);
        } catch (PDOException $e) {
            if (Database::isDuplicate($e)) {
                $existing = Database::one(
                    'SELECT * FROM attendance WHERE event_id = :e AND student_id = :s',
                    ['e' => (int) $event['id'], 's' => (int) $student['id']]
                );
                return self::result(
                    false,
                    'duplicate',
                    'Already checked in at ' . Helpers::fmtTime($existing['checked_in_at'] ?? null) . '.',
                    $student,
                    $existing,
                    (int) $event['id']
                );
            }
            throw $e;
        }

        $record = Database::one('SELECT * FROM attendance WHERE id = :id', ['id' => $id]);
        Security::audit(
            'CHECKIN',
            $student['full_name'] . ' (' . $student['student_no'] . ') at ' . $event['code']
                . ' [' . $status . '/' . $method . ']',
            'attendance'
        );

        return self::result(
            true,
            $status,
            $status === self::ON_TIME ? 'Check-in recorded — on time.' : 'Check-in recorded — marked LATE.',
            $student,
            $record,
            (int) $event['id']
        );
    }

    /** @return array{ok:bool,code:string,message:string,student:?array,record:?array,counters:array} */
    private static function result(bool $ok, string $code, string $message, ?array $student, ?array $record, int $eventId): array
    {
        return [
            'ok'       => $ok,
            'code'     => $code,
            'message'  => $message,
            'student'  => $student === null ? null : [
                'id'         => (int) $student['id'],
                'student_no' => (string) $student['student_no'],
                'full_name'  => (string) $student['full_name'],
                'course'     => (string) $student['course'],
                'year_level' => (string) $student['year_level'],
                'section'    => (string) $student['section'],
            ],
            'record'   => $record === null ? null : [
                'id'            => (int) $record['id'],
                'checked_in_at' => (string) $record['checked_in_at'],
                'status'        => (string) $record['status'],
                'method'        => (string) $record['method'],
                'source'        => (string) $record['source'],
            ],
            'counters' => self::counters($eventId),
        ];
    }

    /* =====================================================================
     * Queries
     * ================================================================== */

    /**
     * Live counters for one event.
     *
     * @return array{total:int,on_time:int,late:int,qr:int,manual:int,self:int,last_at:?string}
     */
    public static function counters(int $eventId): array
    {
        $row = Database::one(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN a.status = :s_on THEN 1 ELSE 0 END) AS on_time,
                    SUM(CASE WHEN a.status = :s_late THEN 1 ELSE 0 END) AS late,
                    SUM(CASE WHEN a.method = :m_qr THEN 1 ELSE 0 END) AS qr,
                    SUM(CASE WHEN a.method = :m_manual THEN 1 ELSE 0 END) AS manual,
                    SUM(CASE WHEN a.source = :src_self THEN 1 ELSE 0 END) AS self,
                    MAX(a.checked_in_at) AS last_at
             FROM attendance a
             WHERE a.event_id = :event_id',
            [
                's_on'     => self::ON_TIME,
                's_late'   => self::LATE,
                'm_qr'     => 'qr',
                'm_manual' => 'manual',
                'src_self' => 'self',
                'event_id' => $eventId,
            ]
        ) ?? [];

        return [
            'total'   => (int) ($row['total'] ?? 0),
            'on_time' => (int) ($row['on_time'] ?? 0),
            'late'    => (int) ($row['late'] ?? 0),
            'qr'      => (int) ($row['qr'] ?? 0),
            'manual'  => (int) ($row['manual'] ?? 0),
            'self'    => (int) ($row['self'] ?? 0),
            'last_at' => isset($row['last_at']) ? (string) $row['last_at'] : null,
        ];
    }

    /** @return array<int,array<string,mixed>> most recent check-ins of an event */
    public static function recent(int $eventId, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        return Database::all(
            'SELECT a.*, s.student_no, s.full_name AS student_name, s.course, s.year_level, s.section
             FROM attendance a
             JOIN students s ON s.id = a.student_id
             WHERE a.event_id = :event_id
             ORDER BY a.checked_in_at DESC, a.id DESC
             LIMIT ' . $limit,
            ['event_id' => $eventId]
        );
    }

    /* ---------------------------------------------------------------------
     * Filtered record list (shared by attendance.php and export.php)
     * ------------------------------------------------------------------ */

    /** @return array{0:string,1:array<string,mixed>} [whereSql, params] */
    private static function filterSql(array $f): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if (!empty($f['event_id'])) {
            $where[]           = 'a.event_id = :f_event';
            $params['f_event'] = (int) $f['event_id'];
        }
        if (!empty($f['student_id'])) {
            $where[]             = 'a.student_id = :f_student';
            $params['f_student'] = (int) $f['student_id'];
        }
        if (!empty($f['status']) && in_array($f['status'], [self::ON_TIME, self::LATE], true)) {
            $where[]            = 'a.status = :f_status';
            $params['f_status'] = (string) $f['status'];
        }
        if (!empty($f['method']) && in_array($f['method'], ['qr', 'manual'], true)) {
            $where[]            = 'a.method = :f_method';
            $params['f_method'] = (string) $f['method'];
        }
        if (!empty($f['course'])) {
            $where[]            = 's.course = :f_course';
            $params['f_course'] = (string) $f['course'];
        }
        if (!empty($f['date_from'])) {
            $where[]          = 'a.checked_in_at >= :f_from';
            $params['f_from'] = (string) $f['date_from'] . ' 00:00:00';
        }
        if (!empty($f['date_to'])) {
            $where[]        = 'a.checked_in_at <= :f_to';
            $params['f_to'] = (string) $f['date_to'] . ' 23:59:59';
        }
        if (!empty($f['q'])) {
            $where[]        = '(s.student_no LIKE :f_q1 OR s.full_name LIKE :f_q2 OR e.title LIKE :f_q3 OR e.code LIKE :f_q4)';
            $needle         = '%' . (string) $f['q'] . '%';
            $params['f_q1'] = $needle;
            $params['f_q2'] = $needle;
            $params['f_q3'] = $needle;
            $params['f_q4'] = $needle;
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return array<int,array<string,mixed>> */
    public static function listRecords(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::filterSql($filters);
        $limit  = max(1, min(5000, $limit));
        $offset = max(0, $offset);

        return Database::all(
            'SELECT a.*, s.student_no, s.full_name AS student_name, s.course, s.year_level, s.section,
                    e.title AS event_title, e.code AS event_code, e.starts_at, e.ends_at, e.venue
             FROM attendance a
             JOIN students s ON s.id = a.student_id
             JOIN events   e ON e.id = a.event_id
             WHERE ' . $where . '
             ORDER BY a.checked_in_at DESC, a.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
    }

    public static function countRecords(array $filters = []): int
    {
        [$where, $params] = self::filterSql($filters);
        return (int) Database::scalar(
            'SELECT COUNT(*)
             FROM attendance a
             JOIN students s ON s.id = a.student_id
             JOIN events   e ON e.id = a.event_id
             WHERE ' . $where,
            $params
        );
    }

    /* ---------------------------------------------------------------------
     * Student lookup (scanner manual-entry fallback)
     * ------------------------------------------------------------------ */

    public static function studentByNumber(string $studentNo): ?array
    {
        return Database::one('SELECT * FROM students WHERE student_no = :no', ['no' => $studentNo]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function searchStudents(string $q, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        return Database::all(
            'SELECT * FROM students
             WHERE status = :status AND (student_no LIKE :q1 OR full_name LIKE :q2)
             ORDER BY full_name
             LIMIT ' . $limit,
            ['status' => 'active', 'q1' => '%' . $q . '%', 'q2' => '%' . $q . '%']
        );
    }

    /* ---------------------------------------------------------------------
     * Event summaries
     * ------------------------------------------------------------------ */

    /** @return array<int,array<string,mixed>> events with their check-in totals */
    public static function eventsSummary(int $limit = 50, bool $onlyToday = false): array
    {
        $limit  = max(1, min(200, $limit));
        $where  = '';
        $params = ['on_time' => self::ON_TIME, 'late' => self::LATE];

        if ($onlyToday) {
            $where          = 'WHERE e.starts_at >= :from AND e.starts_at <= :to';
            $params['from'] = Helpers::today() . ' 00:00:00';
            $params['to']   = Helpers::today() . ' 23:59:59';
        }

        return Database::all(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id) AS total,
                    (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id AND a.status = :on_time) AS on_time,
                    (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id AND a.status = :late) AS late,
                    (SELECT MAX(a.checked_in_at) FROM attendance a WHERE a.event_id = e.id) AS last_at
             FROM events e ' . $where . '
             ORDER BY e.starts_at DESC
             LIMIT ' . $limit,
            $params
        );
    }

    /** @return array<string,mixed>|null event row + counters + registered students */
    public static function eventSummary(int $eventId): ?array
    {
        $event = Database::one('SELECT * FROM events WHERE id = :id', ['id' => $eventId]);
        if ($event === null) {
            return null;
        }
        $event['counters']   = self::counters($eventId);
        $event['registered'] = self::activeStudentCount();
        $event['rate']       = Helpers::percent($event['counters']['total'], (int) $event['registered']);
        return $event;
    }

    /* ---------------------------------------------------------------------
     * Reporting helpers
     * ------------------------------------------------------------------ */

    /** @return array<int,array<string,mixed>> per-course attendance for one event */
    public static function courseBreakdown(int $eventId): array
    {
        return Database::all(
            'SELECT s.course,
                    COUNT(s.id) AS registered,
                    SUM(CASE WHEN a.id IS NULL THEN 0 ELSE 1 END) AS present
             FROM students s
             LEFT JOIN attendance a ON a.student_id = s.id AND a.event_id = :event_id
             WHERE s.status = :status
             GROUP BY s.course
             ORDER BY s.course',
            ['event_id' => $eventId, 'status' => 'active']
        );
    }

    /** @return array<int,array<string,mixed>> active students with no check-in for the event */
    public static function absentees(int $eventId, ?string $course = null, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $where = self::absenteeWhere($course, $params);
        return Database::all(
            'SELECT s.* FROM students s WHERE ' . $where . '
             ORDER BY s.course, s.full_name
             LIMIT ' . $limit,
            $params + ['event_id' => $eventId]
        );
    }

    public static function absenteeCount(int $eventId, ?string $course = null): int
    {
        $where = self::absenteeWhere($course, $params);
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM students s WHERE ' . $where,
            $params + ['event_id' => $eventId]
        );
    }

    /** @param array<string,mixed>|null $params filled by reference */
    private static function absenteeWhere(?string $course, ?array &$params): string
    {
        $where = [
            's.status = :status',
            'NOT EXISTS (SELECT 1 FROM attendance a WHERE a.event_id = :event_id AND a.student_id = s.id)',
        ];
        $params = ['status' => 'active'];

        if ($course !== null && $course !== '') {
            $where[]          = 's.course = :course';
            $params['course'] = $course;
        }
        return implode(' AND ', $where);
    }

    /** @return array<int,array<string,mixed>> a student's attendance history */
    public static function studentHistory(int $studentId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return Database::all(
            'SELECT a.*, e.title AS event_title, e.code AS event_code, e.venue, e.starts_at
             FROM attendance a
             JOIN events e ON e.id = a.event_id
             WHERE a.student_id = :student_id
             ORDER BY a.checked_in_at DESC
             LIMIT ' . $limit,
            ['student_id' => $studentId]
        );
    }

    /** @return array<int,array<string,mixed>> latest check-ins across all events */
    public static function recentAll(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        return Database::all(
            'SELECT a.*, s.student_no, s.full_name AS student_name, s.course,
                    e.title AS event_title, e.code AS event_code
             FROM attendance a
             JOIN students s ON s.id = a.student_id
             JOIN events   e ON e.id = a.event_id
             ORDER BY a.checked_in_at DESC, a.id DESC
             LIMIT ' . $limit
        );
    }

    /** @return array<int,array<string,mixed>> open events that have not finished yet */
    public static function upcomingEvents(int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        return Database::all(
            'SELECT * FROM events
             WHERE status = :status AND ends_at >= :now
             ORDER BY starts_at
             LIMIT ' . $limit,
            ['status' => 'open', 'now' => Helpers::now()]
        );
    }

    public static function activeStudentCount(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM students WHERE status = :status', ['status' => 'active']);
    }

    /** @return array<int,string> distinct course codes, for filter dropdowns */
    public static function courses(): array
    {
        $rows = Database::all(
            "SELECT DISTINCT course FROM students WHERE status = :status AND course <> '' ORDER BY course",
            ['status' => 'active']
        );
        return array_map(static fn ($row) => (string) $row['course'], $rows);
    }

    /**
     * Headline numbers for the dashboard.
     *
     * @return array<string,int|float|string|null>
     */
    public static function dashboardStats(): array
    {
        $from   = Helpers::today() . ' 00:00:00';
        $to     = Helpers::today() . ' 23:59:59';
        $active = self::activeStudentCount();

        $today = Database::one(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN a.status = :s_on THEN 1 ELSE 0 END) AS on_time,
                    SUM(CASE WHEN a.status = :s_late THEN 1 ELSE 0 END) AS late
             FROM attendance a
             WHERE a.checked_in_at >= :from AND a.checked_in_at <= :to',
            ['s_on' => self::ON_TIME, 's_late' => self::LATE, 'from' => $from, 'to' => $to]
        ) ?? [];

        $todayTotal = (int) ($today['total'] ?? 0);

        return [
            'students'      => $active,
            'events'        => (int) Database::scalar('SELECT COUNT(*) FROM events'),
            'open_events'   => (int) Database::scalar('SELECT COUNT(*) FROM events WHERE status = :s', ['s' => 'open']),
            'live_events'   => (int) Database::scalar(
                'SELECT COUNT(*) FROM events WHERE status = :status AND starts_at <= :starts_before AND ends_at >= :ends_after',
                ['status' => 'open', 'starts_before' => Helpers::now(), 'ends_after' => Helpers::now()]
            ),
            'today_events'  => (int) Database::scalar(
                'SELECT COUNT(*) FROM events WHERE starts_at >= :from AND starts_at <= :to',
                ['from' => $from, 'to' => $to]
            ),
            'today_total'   => $todayTotal,
            'today_on_time' => (int) ($today['on_time'] ?? 0),
            'today_late'    => (int) ($today['late'] ?? 0),
            'today_rate'    => Helpers::percent($todayTotal, $active),
            'all_time'      => (int) Database::scalar('SELECT COUNT(*) FROM attendance'),
            'last_checkin'  => Database::scalar('SELECT MAX(checked_in_at) FROM attendance'),
        ];
    }
}
