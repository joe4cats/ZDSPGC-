<?php
/**
 * install.php — creates the tables and loads the pilot data.
 *
 * Open this once after copying the folder to your server:
 *     http://localhost/zdspgc-qr-attendance/install.php
 * It is safe to re-run: existing tables are kept and data is only seeded into
 * empty tables. "Reset" (demo mode only) drops everything and starts over.
 */

declare(strict_types=1);

define('ZDSPGC_SKIP_INSTALL_CHECK', true);   // bootstrap must not redirect to itself
require_once __DIR__ . '/includes/bootstrap.php';

$error  = '';
$result = null;
$probe  = null;

$installed = Schema::isInstalled();
$counts    = $installed ? Schema::counts() : [];

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) Helpers::post('action', 'install');

    if ($action === 'reset' && !DEMO_MODE) {
        $error = 'Reset is disabled because DEMO_MODE is false in includes/config.php.';
    } else {
        try {
            $result = Schema::install($action === 'reset');
            Security::audit('INSTALL', 'Schema ' . ($action === 'reset' ? 'reset + seeded' : 'installed'), 'install');
            Helpers::flash(
                'success',
                'Database ready: ' . $result['counts']['students'] . ' students, '
                . $result['counts']['events'] . ' events, '
                . $result['counts']['attendance'] . ' check-in records.'
            );
            Helpers::redirect('install.php');
        } catch (Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
        }
    }
    $installed = Schema::isInstalled();
    $counts    = $installed ? Schema::counts() : [];
}

/* Quick self-test so a broken setup is obvious before opening the app. */
try {
    $probe = [
        'driver'    => Database::driver(),
        'php'       => PHP_VERSION,
        'sqlite'    => extension_loaded('pdo_sqlite'),
        'mbstring'  => extension_loaded('mbstring'),
        'secret_ok' => APP_SECRET !== 'zdspgc-change-me-8f2b41d9a7c65e03b1ad47c9',
        'storage'   => is_dir(STORAGE_DIR) && is_writable(STORAGE_DIR),
        'token'     => Security::readToken(Security::makeToken('S', 'SELFTEST', 'abcdef123456')) !== null,
    ];
} catch (Throwable $e) {
    $probe = null;
    $error = $error !== '' ? $error : $e->getMessage();
}

$flashes = Helpers::takeFlashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Install · <?= Helpers::e(APP_SHORT) ?></title>
  <link rel="icon" type="image/png" href="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>">
  <link rel="stylesheet" href="<?= Helpers::e(Helpers::url('assets/css/style.css')) ?>">
</head>
<body>
  <div class="govbar">
    <span class="flag"><?= flag_ph() ?><?= Helpers::e(SCHOOL_NAME) ?></span>
    <span class="govbar-right"><?= icon('settings') ?> Installation</span>
  </div>

  <main class="wrap" style="max-width:900px;">
    <div class="page-head">
      <div>
        <h1><?= icon('settings') ?> Set up the database</h1>
        <p class="lead">This page creates the tables of <strong><?= Helpers::e(APP_NAME) ?></strong> and loads sample
          students, events and check-in records so you can demo the system right away.</p>
      </div>
    </div>

    <?php foreach ($flashes as $flash): ?>
      <div class="flash <?= Helpers::e((string) $flash['type']) ?>">
        <?= icon('check') ?><span><?= Helpers::e((string) $flash['message']) ?></span>
      </div>
    <?php endforeach; ?>

    <?php if ($error !== ''): ?>
      <div class="flash error"><?= icon('alert') ?><span><?= Helpers::e($error) ?></span></div>
    <?php endif; ?>

    <div class="grid cols-2">
      <div class="card">
        <h3><?= icon('shield') ?> Environment</h3>
        <table class="tbl">
          <tbody>
            <tr><td>Database driver</td><td><strong><?= Helpers::e((string) ($probe['driver'] ?? DB_DRIVER)) ?></strong></td></tr>
            <tr><td>PHP version</td><td><?= Helpers::e((string) ($probe['php'] ?? PHP_VERSION)) ?></td></tr>
            <tr><td>SQLite support</td><td><?= !empty($probe['sqlite']) ? '<span class="badge">available</span>' : '<span class="badge red">missing</span>' ?></td></tr>
            <tr><td>mbstring</td><td><?= !empty($probe['mbstring']) ? '<span class="badge">available</span>' : '<span class="badge red">missing</span>' ?></td></tr>
            <tr><td>QR signing secret</td><td><?= !empty($probe['secret_ok']) ? '<span class="badge">changed</span>' : '<span class="badge red">still the default — change APP_SECRET</span>' ?></td></tr>
            <tr><td>Signed token self-test</td><td><?= !empty($probe['token']) ? '<span class="badge">passes</span>' : '<span class="badge red">fails</span>' ?></td></tr>
            <tr><td>Writable storage folder</td><td><?= !empty($probe['storage']) ? '<span class="badge">yes</span>' : '<span class="badge red">no (needed for SQLite)</span>' ?></td></tr>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h3><?= icon('dashboard') ?> Tables</h3>
        <?php if ($installed): ?>
          <table class="tbl">
            <thead><tr><th>Table</th><th class="num">Rows</th></tr></thead>
            <tbody>
              <?php foreach (Schema::TABLES as $table): ?>
                <tr><td class="mono"><?= Helpers::e($table) ?></td><td class="num"><?= (int) ($counts[$table] ?? 0) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p class="muted">Not installed yet — use the button below to create the tables.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mt">
      <h3><?= icon('plus') ?> Run the installer</h3>
      <div class="row">
        <form method="post">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="install">
          <button type="submit"><?= icon('check') ?><span><?= $installed ? 'Create missing tables / seed empty ones' : 'Install database' ?></span></button>
        </form>
        <form method="post" data-confirm="This DELETES every student, event and attendance record, then rebuilds the demo data. Continue?">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="reset">
          <button class="ghost red" type="submit" <?= DEMO_MODE ? '' : 'disabled' ?>>
            <?= icon('refresh') ?><span>Reset &amp; reseed demo data</span>
          </button>
        </form>
        <a class="btn ghost" href="<?= Helpers::e(Helpers::url('login.php')) ?>"><?= icon('logout') ?><span>Go to sign-in</span></a>
      </div>
      <p class="hint">For MySQL/XAMPP you can also import <span class="mono">sql/mysql-schema.sql</span> in phpMyAdmin
        instead of using this page — set <span class="mono">DB_DRIVER = 'mysql'</span> in
        <span class="mono">includes/config.php</span> first.</p>
    </div>

    <?php if (DEMO_MODE): ?>
      <div class="card mt">
        <h3><?= icon('users') ?> Demo accounts</h3>
        <table class="tbl">
          <thead><tr><th>Role</th><th>Username</th><th>Password</th></tr></thead>
          <tbody>
            <tr><td>System Administrator</td><td class="mono">admin</td><td class="mono">Admin@2026</td></tr>
            <tr><td>Student Affairs Officer</td><td class="mono">officer</td><td class="mono">Officer@2026</td></tr>
            <tr><td>Faculty / Door Marshal</td><td class="mono">faculty</td><td class="mono">Faculty@2026</td></tr>
          </tbody>
        </table>
        <p class="hint">Change these right after your first sign-in (Accounts page), and set your own
          <span class="mono">APP_SECRET</span> before printing any student QR ID.</p>
      </div>
    <?php endif; ?>
  </main>
  <script src="<?= Helpers::e(Helpers::url('assets/js/app.js')) ?>"></script>
</body>
</html>
