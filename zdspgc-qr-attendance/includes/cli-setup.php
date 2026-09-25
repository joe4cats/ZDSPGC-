<?php
/**
 * cli-setup.php — database bootstrap used by run-local.bat (one-command start).
 *
 * Run it by hand whenever you need it:
 *     php includes/cli-setup.php
 *
 * Steps:
 *   1. MySQL: creates the database named in includes/config.php when missing.
 *   2. Runs the project installer when the tables are missing (creates the
 *      tables and seeds the demo rows). This never drops or overwrites data —
 *      a table is only seeded while it is empty.
 *
 * CLI only (it refuses to run over the web) and exits with a non-zero status
 * on failure so the launcher can stop with a useful message.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli-setup.php is a command-line helper. Open install.php in the browser instead.\n");
}

define('ZDSPGC_SKIP_INSTALL_CHECK', true);   // bootstrap must not redirect to install.php
require_once __DIR__ . '/bootstrap.php';     // include before printing: in CLI the session
                                             // warnings appear once output has been sent

/* ---- 1. MySQL: make sure the database itself exists ---------------------- */
if (DB_DRIVER === 'mysql') {
    if (!preg_match('/^[A-Za-z0-9_]+$/', DB_NAME)) {
        fwrite(STDERR, 'DB_NAME contains characters this helper will not send to MySQL.' . PHP_EOL);
        exit(1);
    }

    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        echo 'MySQL database "' . DB_NAME . '" is ready on ' . DB_HOST . ':' . DB_PORT . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, 'Cannot reach MySQL on ' . DB_HOST . ':' . DB_PORT . ' - ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

/* ---- 2. Tables + demo data (create what is missing, never destructive) --- */
if (Schema::isInstalled()) {
    $counts = Schema::counts();
    echo 'Schema already installed: ' . $counts['users'] . ' staff, ' . $counts['students'] . ' students, '
        . $counts['events'] . ' events, ' . $counts['attendance'] . ' check-ins' . PHP_EOL;
    exit(0);
}

try {
    $result = Schema::install(false);
    echo 'Schema installed: ' . $result['counts']['users'] . ' staff, ' . $result['counts']['students'] . ' students, '
        . $result['counts']['events'] . ' events, ' . $result['counts']['attendance'] . ' check-ins' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Schema install failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

exit(0);
