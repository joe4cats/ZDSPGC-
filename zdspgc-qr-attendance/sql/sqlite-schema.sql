-- =============================================================================
--  ZDSPGC Event QR Attendance System — SQLite schema
--  Used automatically when DB_DRIVER = 'sqlite' in includes/config.php.
--  install.php runs this file for you; you normally never run it by hand.
-- =============================================================================

CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  full_name     TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'officer',
  password_hash TEXT NOT NULL,
  status        TEXT NOT NULL DEFAULT 'active',
  avatar        TEXT NOT NULL DEFAULT '',
  created_at    TEXT NOT NULL,
  last_login_at TEXT
);

CREATE TABLE IF NOT EXISTS students (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  student_no   TEXT NOT NULL UNIQUE,
  full_name    TEXT NOT NULL,
  course       TEXT NOT NULL DEFAULT '',
  year_level   TEXT NOT NULL DEFAULT '',
  section      TEXT NOT NULL DEFAULT '',
  email        TEXT NOT NULL DEFAULT '',
  contact      TEXT NOT NULL DEFAULT '',
  qr_nonce     TEXT NOT NULL,
  status       TEXT NOT NULL DEFAULT 'active',
  created_at   TEXT NOT NULL,
  qr_issued_at TEXT
);

CREATE TABLE IF NOT EXISTS events (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  code          TEXT NOT NULL UNIQUE,
  title         TEXT NOT NULL,
  description   TEXT NOT NULL DEFAULT '',
  venue         TEXT NOT NULL DEFAULT '',
  starts_at     TEXT NOT NULL,
  ends_at       TEXT NOT NULL,
  grace_minutes INTEGER NOT NULL DEFAULT 15,
  self_checkin  INTEGER NOT NULL DEFAULT 1,
  status        TEXT NOT NULL DEFAULT 'open',
  qr_nonce      TEXT NOT NULL,
  created_by    TEXT NOT NULL DEFAULT '',
  created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS attendance (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id      INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
  student_id    INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
  checked_in_at TEXT NOT NULL,
  status        TEXT NOT NULL DEFAULT 'on_time',
  method        TEXT NOT NULL DEFAULT 'qr',
  source        TEXT NOT NULL DEFAULT 'station',
  station       TEXT NOT NULL DEFAULT '',
  scanned_by    TEXT NOT NULL DEFAULT '',
  remark        TEXT NOT NULL DEFAULT '',
  ip            TEXT NOT NULL DEFAULT '',
  user_agent    TEXT NOT NULL DEFAULT '',
  CONSTRAINT uq_attendance_event_student UNIQUE (event_id, student_id)
);

CREATE TABLE IF NOT EXISTS audit_log (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at TEXT NOT NULL,
  actor      TEXT NOT NULL DEFAULT '',
  role       TEXT NOT NULL DEFAULT '',
  action     TEXT NOT NULL,
  detail     TEXT NOT NULL DEFAULT '',
  scope      TEXT NOT NULL DEFAULT '',
  ip         TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_attendance_event   ON attendance (event_id);
CREATE INDEX IF NOT EXISTS idx_attendance_student ON attendance (student_id);
CREATE INDEX IF NOT EXISTS idx_attendance_time    ON attendance (checked_in_at);
CREATE INDEX IF NOT EXISTS idx_students_course    ON students (course, year_level);
CREATE INDEX IF NOT EXISTS idx_events_starts      ON events (starts_at);
CREATE INDEX IF NOT EXISTS idx_audit_time         ON audit_log (created_at);
