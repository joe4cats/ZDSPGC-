-- =============================================================================
--  ZDSPGC Event QR Attendance System — MySQL / MariaDB schema
--  Use this when DB_DRIVER = 'mysql' in includes/config.php (XAMPP, cPanel).
--  XAMPP users: create the database "zdspgc_attendance" in phpMyAdmin, then
--  import this file (Import tab) — or simply open install.php, which creates
--  the tables for you.
-- =============================================================================

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(60)  NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  role          VARCHAR(20)  NOT NULL DEFAULT 'officer',
  password_hash VARCHAR(255) NOT NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'active',
  created_at    DATETIME     NOT NULL,
  last_login_at DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS students (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_no   VARCHAR(30)  NOT NULL,
  full_name    VARCHAR(120) NOT NULL,
  course       VARCHAR(60)  NOT NULL DEFAULT '',
  year_level   VARCHAR(20)  NOT NULL DEFAULT '',
  section      VARCHAR(40)  NOT NULL DEFAULT '',
  email        VARCHAR(120) NOT NULL DEFAULT '',
  contact      VARCHAR(30)  NOT NULL DEFAULT '',
  qr_nonce     VARCHAR(32)  NOT NULL,
  status       VARCHAR(20)  NOT NULL DEFAULT 'active',
  created_at   DATETIME     NOT NULL,
  qr_issued_at DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_students_no (student_no),
  KEY idx_students_course (course, year_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(40)  NOT NULL,
  title         VARCHAR(160) NOT NULL,
  description   TEXT         NULL,
  venue         VARCHAR(120) NOT NULL DEFAULT '',
  starts_at     DATETIME     NOT NULL,
  ends_at       DATETIME     NOT NULL,
  grace_minutes INT          NOT NULL DEFAULT 15,
  self_checkin  TINYINT(1)   NOT NULL DEFAULT 1,
  status        VARCHAR(20)  NOT NULL DEFAULT 'open',
  qr_nonce      VARCHAR(32)  NOT NULL,
  created_by    VARCHAR(120) NOT NULL DEFAULT '',
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_events_code (code),
  KEY idx_events_starts (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id      INT UNSIGNED NOT NULL,
  student_id    INT UNSIGNED NOT NULL,
  checked_in_at DATETIME     NOT NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'on_time',
  method        VARCHAR(20)  NOT NULL DEFAULT 'qr',
  source        VARCHAR(20)  NOT NULL DEFAULT 'station',
  station       VARCHAR(80)  NOT NULL DEFAULT '',
  scanned_by    VARCHAR(120) NOT NULL DEFAULT '',
  remark        VARCHAR(255) NOT NULL DEFAULT '',
  ip            VARCHAR(45)  NOT NULL DEFAULT '',
  user_agent    VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_event_student (event_id, student_id),
  KEY idx_attendance_student (student_id),
  KEY idx_attendance_time (checked_in_at),
  CONSTRAINT fk_attendance_event   FOREIGN KEY (event_id)   REFERENCES events (id)   ON DELETE CASCADE,
  CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME     NOT NULL,
  actor      VARCHAR(120) NOT NULL DEFAULT '',
  role       VARCHAR(20)  NOT NULL DEFAULT '',
  action     VARCHAR(60)  NOT NULL,
  detail     VARCHAR(500) NOT NULL DEFAULT '',
  scope      VARCHAR(120) NOT NULL DEFAULT '',
  ip         VARCHAR(45)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
