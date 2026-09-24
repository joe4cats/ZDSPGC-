<?php
/**
 * settings.php — signed-in staff settings.
 *
 * Any role can open this page: it changes the signed-in user's own password
 * (verified against the current one, CSRF-protected, audit-logged) and shows
 * the read-only system configuration.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireLogin();

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) Helpers::post('action', 'password');

    if ($action === 'password') {
        $current  = (string) (Helpers::post('current_password') ?? '');
        $password = (string) (Helpers::post('password') ?? '');
        $confirm  = (string) (Helpers::post('password_confirm') ?? '');
        $user     = Database::one('SELECT * FROM users WHERE id = :id', ['id' => Auth::id()]);

        if ($user === null) {
            Helpers::flash('error', 'Your account could not be found.');
        } elseif (!password_verify($current, (string) $user['password_hash'])) {
            Security::audit('PASSWORD_CHANGE_FAILED', 'Wrong current password for ' . $user['username'], 'auth');
            Helpers::flash('error', 'Your current password is not correct.');
        } elseif (strlen($password) < 8) {
            Helpers::flash('error', 'The new password must be at least 8 characters.');
        } elseif ($password !== $confirm) {
            Helpers::flash('error', 'The two new passwords do not match.');
        } else {
            Database::run(
                'UPDATE users SET password_hash = :h WHERE id = :id',
                ['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => Auth::id()]
            );
            Security::audit('PASSWORD_CHANGE', 'Password changed for ' . $user['username'], 'auth');
            Helpers::flash('success', 'Your password has been updated.');
        }
        Helpers::redirect('settings.php');
    }
}

$account = Auth::user() ?? [];

$PAGE_TITLE  = 'Settings';
$PAGE_ACTIVE = 'settings';
$PAGE_SUB    = 'Your account and this installation.';

require __DIR__ . '/includes/layout/header.php';
?>

<div class="grid cols-2">
  <div class="card">
    <h3><?= icon('lock') ?> Change your password</h3>
    <form method="post" action="<?= Helpers::e(Helpers::url('settings.php')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="password">
      <label class="field">
        <span>Current password</span>
        <input type="password" name="current_password" required autocomplete="current-password">
      </label>
      <label class="field">
        <span>New password (at least 8 characters)</span>
        <input type="password" name="password" required minlength="8" autocomplete="new-password">
      </label>
      <label class="field">
        <span>Repeat new password</span>
        <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
      </label>
      <button type="submit"><?= icon('check') ?><span>Update password</span></button>
    </form>
    <p class="hint mt">Passwords are stored as bcrypt hashes. Changing your password here does not sign you out of this device.</p>
  </div>

  <div>
    <div class="card">
      <h3><?= icon('user') ?> Your account</h3>
      <table class="tbl">
        <tbody>
          <tr><td>Name</td><td><?= Helpers::e($account['full_name'] ?? '') ?></td></tr>
          <tr><td>Username</td><td class="mono"><?= Helpers::e($account['username'] ?? '') ?></td></tr>
          <tr><td>Role</td><td><?= Helpers::e(Auth::roleLabel()) ?></td></tr>
          <tr><td>Last sign-in</td><td><?= Helpers::e(($account['last_login_at'] ?? null) === null ? 'never' : Helpers::fmtDateTime((string) $account['last_login_at'])) ?></td></tr>
          <tr><td>Account created</td><td><?= Helpers::e(($account['created_at'] ?? null) === null ? '—' : Helpers::fmtDate((string) $account['created_at'])) ?></td></tr>
        </tbody>
      </table>
    </div>

    <div class="card mt">
      <h3><?= icon('settings') ?> System</h3>
      <table class="tbl">
        <tbody>
          <tr><td>Application</td><td><?= Helpers::e(APP_NAME) ?> v<?= Helpers::e(APP_VERSION) ?></td></tr>
          <tr><td>Campus</td><td><?= Helpers::e(SCHOOL_NAME) ?> · <?= Helpers::e(SCHOOL_CAMPUS) ?></td></tr>
          <tr><td>Storage driver</td><td class="mono"><?= Helpers::e(Database::driver()) ?></td></tr>
          <tr><td>Timezone</td><td class="mono"><?= Helpers::e(APP_TIMEZONE) ?></td></tr>
          <tr><td>Server time</td><td><?= Helpers::e(Helpers::fmtDateTime(Helpers::now())) ?></td></tr>
          <tr><td>Grace period (default)</td><td><?= (int) DEFAULT_GRACE_MINUTES ?> minutes</td></tr>
          <tr><td>Early check-in</td><td><?= (int) EARLY_CHECKIN_MINUTES ?> minutes before start</td></tr>
          <tr><td>Session idle timeout</td><td><?= (int) SESSION_IDLE_MINUTES ?> minutes</td></tr>
          <tr><td>Scan rate limit</td><td><?= (int) CHECKIN_RATE_PER_MIN ?> posts / minute / session</td></tr>
        </tbody>
      </table>
      <p class="hint mt">Configuration lives in <span class="mono">includes/config.php</span>.
        Changing <span class="mono">APP_SECRET</span> invalidates every printed QR code.</p>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
