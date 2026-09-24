<?php
/**
 * bootstrap.php — the single entry point every page includes first.
 *
 * It loads the configuration + core classes, starts the session with safe
 * cookie flags, enforces the idle timeout, and makes sure the database schema
 * exists (redirecting to install.php on a fresh checkout).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

date_default_timezone_set(APP_TIMEZONE);
mb_internal_encoding('UTF-8');
setlocale(LC_TIME, 'en_US.UTF-8');

if (APP_ENV === 'local') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Attendance.php';

/* -------------------------------------------------------------------------
 * Session (HttpOnly + SameSite cookies; Secure is added automatically on HTTPS)
 * ---------------------------------------------------------------------- */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

/* Baseline security headers (works with the plain built-in server too). */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

/* -------------------------------------------------------------------------
 * First-run check: is the database installed?
 * ---------------------------------------------------------------------- */
if (!Schema::isInstalled() && !defined('ZDSPGC_SKIP_INSTALL_CHECK')) {
    if (defined('ZDSPGC_API_MODE')) {
        Helpers::jsonOut([
            'ok'      => false,
            'code'    => 'not_installed',
            'message' => 'Database is not installed yet. Open install.php in your browser.',
        ], 503);
    }
    if (Helpers::currentPage() !== 'install.php') {
        Helpers::redirect('install.php');
    }
}

/* -------------------------------------------------------------------------
 * Session hygiene: idle timeout + last-seen date
 * ---------------------------------------------------------------------- */
Auth::touch();
