<?php
/**
 * Schema.php — creates the tables and seeds the demo data.
 *
 * The SQL itself lives in sql/{sqlite,mysql}-schema.sql so the database can
 * also be created by hand (phpMyAdmin import) if you prefer. This class just
 * picks the right file for the configured driver and runs it.
 */

declare(strict_types=1);

final class Schema
{
    /** Drop order matters (children first). */
    public const TABLES = ['attendance', 'audit_log', 'students', 'events', 'users'];

    public static function isInstalled(): bool
    {
        try {
            Database::scalar('SELECT COUNT(*) FROM users');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Lightweight forward migration for databases installed before a column
     * existed (CREATE TABLE IF NOT EXISTS never alters an existing table).
     * Runs at most once per request and only issues an ALTER when needed.
     */
    public static function ensureColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            if (Database::driver() === 'mysql') {
                $names = array_map(static fn ($c) => (string) ($c['Field'] ?? ''), Database::all('SHOW COLUMNS FROM users'));
                if (!in_array('avatar', $names, true)) {
                    Database::run("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NOT NULL DEFAULT '' AFTER status");
                }
                return;
            }
            $names = array_map(static fn ($c) => (string) ($c['name'] ?? ''), Database::all('PRAGMA table_info(users)'));
            if (!in_array('avatar', $names, true)) {
                Database::run("ALTER TABLE users ADD COLUMN avatar TEXT NOT NULL DEFAULT ''");
            }
        } catch (Throwable $e) {
            // Not installed yet (or concurrent install) — migrate() will add it.
        }
    }

    public static function schemaFile(): string
    {
        $file = Database::driver() === 'mysql' ? 'mysql-schema.sql' : 'sqlite-schema.sql';
        return dirname(__DIR__) . '/sql/' . $file;
    }

    /** @return array{statements:int,tables:array<int,string>} */
    public static function migrate(): array
    {
        $sql = @file_get_contents(self::schemaFile());
        if ($sql === false || $sql === '') {
            throw new RuntimeException('Schema file not found or empty: ' . self::schemaFile());
        }
        $statements = self::splitStatements($sql);
        $pdo        = Database::conn();
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        return ['statements' => count($statements), 'tables' => self::tableList()];
    }

    /** @return array<int,string> */
    public static function tableList(): array
    {
        if (Database::driver() === 'mysql') {
            $rows = Database::all('SHOW TABLES');
            return array_map(static fn ($row) => (string) reset($row), $rows);
        }
        $rows = Database::all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        return array_map(static fn ($row) => (string) $row['name'], $rows);
    }

    public static function dropAll(): void
    {
        $pdo = Database::conn();
        if (Database::driver() === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = OFF');
        }
        foreach (self::TABLES as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
        if (Database::driver() === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /** @return array<int,string> */
    private static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $kept  = [];
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            $kept[] = $line;
        }
        $parts = explode(';', implode("\n", $kept));
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }

    /**
     * Full install: create the tables, then fill them with pilot data.
     *
     * @return array{tables:array<int,string>,statements:int,seeded:array<string,int>,counts:array<string,int>}
     */
    public static function install(bool $reset = false): array
    {
        if ($reset) {
            self::dropAll();
        }
        $migrate = self::migrate();
        $seeded  = self::seedDemo($reset);
        return [
            'tables'     => $migrate['tables'],
            'statements' => $migrate['statements'],
            'seeded'     => $seeded,
            'counts'     => self::counts(),
        ];
    }

    /** @return array<string,int> */
    public static function counts(): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            try {
                $out[$table] = (int) Database::scalar('SELECT COUNT(*) FROM ' . $table);
            } catch (Throwable $e) {
                $out[$table] = 0;
            }
        }
        return $out;
    }

    /**
     * Demo / pilot data. A table is only seeded while it is empty, so calling
     * this repeatedly never duplicates rows.
     *
     * @return array<string,int>
     */
    public static function seedDemo(bool $force = false): array
    {
        $counts = ['users' => 0, 'students' => 0, 'events' => 0, 'attendance' => 0];
        $now    = Helpers::now();

        /* ---- Staff accounts (password_hash = bcrypt) --------------------- */
        if ($force || (int) Database::scalar('SELECT COUNT(*) FROM users') === 0) {
            $accounts = [
                ['admin',   'Ma. Teresa L. Yap',     'admin',   'Admin@2026'],
                ['officer', 'Jessa P. Lim',          'officer', 'Officer@2026'],
                ['faculty', 'Engr. Ronald B. Dacal', 'faculty', 'Faculty@2026'],
            ];
            foreach ($accounts as [$username, $fullName, $role, $plain]) {
                Database::insert('users', [
                    'username'      => $username,
                    'full_name'     => $fullName,
                    'role'          => $role,
                    'password_hash' => password_hash($plain, PASSWORD_DEFAULT),
                    'status'        => 'active',
                    'created_at'    => $now,
                    'last_login_at' => null,
                ]);
                $counts['users']++;
            }
        }

        /* ---- Students (fictitious sample records) ------------------------ */
        if ($force || (int) Database::scalar('SELECT COUNT(*) FROM students') === 0) {
            $students = [
                ['2022-00214', 'Dagohoy, Renz A.',   'BSED',   '4th Year', 'A'],
                ['2022-00215', 'Empasis, Liezl P.',  'BSED',   '4th Year', 'A'],
                ['2022-00361', 'Imson, Paolo G.',    'BSBA',   '4th Year', 'A'],
                ['2022-00455', 'Kintanar, Daryl V.', 'BSCrim', '4th Year', 'A'],
                ['2022-00733', 'Padilla, Sheena V.', 'BSA',    '4th Year', 'A'],
                ['2023-00101', 'Aguilar, Marione T.', 'BSIT',  '3rd Year', 'A'],
                ['2023-00102', 'Bautista, Kervin L.', 'BSIT',  '3rd Year', 'A'],
                ['2023-00103', 'Cabrera, Shaira M.',  'BSIT',  '2nd Year', 'B'],
                ['2023-00247', 'Hidalgo, Tessie R.',  'BEED',  '2nd Year', 'A'],
                ['2023-00402', 'Jalalon, Fatima S.',  'BSBA',  '3rd Year', 'B'],
                ['2023-00512', 'Lumanas, Cris A.',    'BSCrim', '3rd Year', 'A'],
                ['2023-00644', 'Naranjo, Kim E.',     'BSHM',  '2nd Year', 'A'],
                ['2024-00308', 'Fuentes, Joshue D.',  'BSIT',  '1st Year', 'C'],
                ['2024-00309', 'Gallano, Nicka B.',   'BEED',  '1st Year', 'A'],
                ['2024-00603', 'Malinao, Ayen C.',    'BSHM',  '1st Year', 'A'],
                ['2024-00701', 'Ocampos, Lorena J.',  'BSA',   '1st Year', 'A'],
            ];
            foreach ($students as [$no, $name, $course, $year, $section]) {
                $slug = strtolower(preg_replace('/[^a-z]/i', '', explode(',', $name)[0]) ?? 'student');
                Database::insert('students', [
                    'student_no'   => $no,
                    'full_name'    => $name,
                    'course'       => $course,
                    'year_level'   => $year,
                    'section'      => $section,
                    'email'        => $slug . '.' . $no . '@zdspgc.edu.ph',
                    'contact'      => '0917-000-' . substr($no, -4),
                    'qr_nonce'     => Security::nonce(),
                    'status'       => 'active',
                    'created_at'   => $now,
                    'qr_issued_at' => $now,
                ]);
                $counts['students']++;
            }
        }

        /* ---- Events ------------------------------------------------------ */
        if ($force || (int) Database::scalar('SELECT COUNT(*) FROM events') === 0) {
            $today  = Helpers::today();
            $events = [
                ['EVT-CONVO-2026', 'Semestral Convocation — Day 1',
                    'Attendance is monitored per college at the gymnasium entrance.',
                    'ZDSPGC Gymnasium', $today . ' 07:00:00', $today . ' 11:00:00',
                    15, 0, 'closed'],
                ['EVT-FOUND-2026', 'Foundation Day 2026 — Opening Program',
                    'Live check-in at the main gate station. Latecomers are flagged automatically.',
                    'ZDSPGC Grand Stand',
                    date('Y-m-d H:i:s', time() - (25 * 60)), date('Y-m-d H:i:s', time() + (4 * 3600)),
                    15, 1, 'open'],
                ['EVT-CAPSTONE-2026', 'BSIT Capstone Defense Orientation',
                    'Mandatory for all 3rd and 4th year BSIT students.',
                    'IT Building — Room 204',
                    date('Y-m-d', strtotime('+1 day')) . ' 13:00:00',
                    date('Y-m-d', strtotime('+1 day')) . ' 16:00:00',
                    20, 1, 'open'],
                ['EVT-INTRAM-2026', 'Intramurals 2026 Kick-off',
                    'Opening parade and torch lighting. Students may self check-in.',
                    'ZDSPGC Sports Complex',
                    date('Y-m-d', strtotime('+7 days')) . ' 08:00:00',
                    date('Y-m-d', strtotime('+7 days')) . ' 17:00:00',
                    30, 1, 'open'],
            ];
            foreach ($events as [$code, $title, $desc, $venue, $start, $end, $grace, $self, $status]) {
                Database::insert('events', [
                    'code'          => $code,
                    'title'         => $title,
                    'description'   => $desc,
                    'venue'         => $venue,
                    'starts_at'     => $start,
                    'ends_at'       => $end,
                    'grace_minutes' => $grace,
                    'self_checkin'  => $self,
                    'status'        => $status,
                    'qr_nonce'      => Security::nonce(),
                    'created_by'    => 'Ma. Teresa L. Yap',
                    'created_at'    => $now,
                ]);
                $counts['events']++;
            }
        }

        /* ---- Sample attendance (only while the log is empty) ------------- */
        if ($force || (int) Database::scalar('SELECT COUNT(*) FROM attendance') === 0) {
            $students = Database::all('SELECT id, student_no FROM students ORDER BY id');
            $convo    = Database::one('SELECT * FROM events WHERE code = :c', ['c' => 'EVT-CONVO-2026']);
            $found    = Database::one('SELECT * FROM events WHERE code = :c', ['c' => 'EVT-FOUND-2026']);

            // Each row is [student index, minutes after the event start time].
            $plan = [
                ['event' => $convo, 'rows' => [[0, -10], [1, -6], [2, -4], [3, -2], [4, 0], [5, 2],
                                               [6, 5], [7, 8], [8, 12], [9, 14], [10, 24], [11, 41]]],
                ['event' => $found, 'rows' => [[12, -20], [13, -16], [14, -12], [15, -8], [2, -5], [6, -3]]],
            ];

            foreach ($plan as $block) {
                $event = $block['event'];
                if ($event === null) {
                    continue;
                }
                $startTs = (int) strtotime((string) $event['starts_at']);
                if ($startTs + 60 > time()) {
                    continue; // never seed a check-in dated in the future
                }
                foreach ($block['rows'] as [$index, $offset]) {
                    if (!isset($students[$index])) {
                        continue;
                    }
                    Database::insert('attendance', [
                        'event_id'      => (int) $event['id'],
                        'student_id'    => (int) $students[$index]['id'],
                        'checked_in_at' => date('Y-m-d H:i:s', $startTs + ($offset * 60)),
                        'status'        => $offset <= (int) $event['grace_minutes'] ? Attendance::ON_TIME : Attendance::LATE,
                        'method'        => 'qr',
                        'source'        => 'station',
                        'station'       => $event['code'] === 'EVT-FOUND-2026' ? 'Main Gate' : 'Gymnasium Door',
                        'scanned_by'    => 'Jessa P. Lim',
                        'remark'        => '',
                        'ip'            => Security::ip(),
                        'user_agent'    => 'ZDSPGC demo seed',
                    ]);
                    $counts['attendance']++;
                }
            }
        }

        return $counts;
    }
}
