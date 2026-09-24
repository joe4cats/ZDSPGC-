<?php
/**
 * =============================================================================
 *  ZDSPGC Event QR Attendance System — Configuration
 * =============================================================================
 *  Copy this file, then change APP_SECRET and the database credentials before
 *  you deploy. Nothing else here needs to be touched for a normal install.
 * =============================================================================
 */

declare(strict_types=1);

/* -----------------------------------------------------------------------------
 * 1. Application identity
 * -------------------------------------------------------------------------- */
const APP_NAME    = 'ZDSPGC Event QR Attendance';
const APP_SHORT   = 'ZDSPGC QR Attendance';
const SCHOOL_NAME = 'Zamboanga del Sur Provincial Government College';
const SCHOOL_CAMPUS = 'Dimataling, Zamboanga del Sur';
const APP_VERSION = '1.0.0';

/**
 * Public address embedded in event QR links and phone check-in pages.
 * Keep the trailing slash out. Use HTTPS so mobile browsers can access cameras.
 */
const PUBLIC_BASE_URL = '';

/** 'local' shows PHP errors on screen. Use 'production' on a live server. */
const APP_ENV = 'local';

/** Asia/Manila — all event windows and check-in times use this zone. */
const APP_TIMEZONE = 'Asia/Manila';

/**
 * Secrets used to sign QR tokens (HMAC-SHA256).
 * CHANGE THIS to a long random string in production. Rotating this value
 * instantly invalidates every printed QR code.
 */
const APP_SECRET = 'zdspgc-change-me-8f2b41d9a7c65e03b1ad47c9';

/* -----------------------------------------------------------------------------
 * 2. Database
 *    DB_DRIVER = 'sqlite'  -> zero setup, file storage (good for demo / defence)
 *    DB_DRIVER = 'mysql'   -> XAMPP / cPanel / production
 * -------------------------------------------------------------------------- */
const DB_DRIVER = 'mysql';

/** SQLite (used when DB_DRIVER = 'sqlite') */
const DB_SQLITE_PATH = __DIR__ . '/../storage/attendance.sqlite';

/** MySQL (used when DB_DRIVER = 'mysql') — default XAMPP credentials */
const DB_HOST = '127.0.0.1';
const DB_PORT = '3307';
const DB_NAME = 'zdspgc_attendance';
const DB_USER = 'root';
const DB_PASS = '';

/* -----------------------------------------------------------------------------
 * 3. Security / sessions
 * -------------------------------------------------------------------------- */
const SESSION_NAME          = 'ZDSPGCQRATT';
const SESSION_IDLE_MINUTES  = 60;   // auto-logout after inactivity
const LOGIN_MAX_ATTEMPTS    = 5;    // failed logins before a short lockout
const LOGIN_LOCKOUT_MINUTES = 5;
const CHECKIN_RATE_PER_MIN  = 300;  // max check-in posts per minute per session

/* -----------------------------------------------------------------------------
 * 4. Attendance rules
 * -------------------------------------------------------------------------- */
/** Default grace period (minutes after the event start) that still counts as on-time. */
const DEFAULT_GRACE_MINUTES = 15;
/** How early a student may be scanned before the event starts. */
const EARLY_CHECKIN_MINUTES = 60;
/** Whether a student's mobile self-check-in is offered by default for new events. */
const DEFAULT_SELF_CHECKIN = true;

/* -----------------------------------------------------------------------------
 * 5. Demo / pilot mode
 *    true  -> install.php may reset data and seed demo data; demo logins are
 *             documented in README (they are not shown on the login page).
 *    false -> hide demo hints (still fine on a real deployment).
 * -------------------------------------------------------------------------- */
const DEMO_MODE = true;

/* -----------------------------------------------------------------------------
 * 6. Uploads / storage folders (kept outside the web root where possible)
 * -------------------------------------------------------------------------- */
const STORAGE_DIR = __DIR__ . '/../storage';
