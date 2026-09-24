<?php
/**
 * settings.php — signed-in staff settings.
 *
 * Any role can open this page: Profile Settings (display name + avatar upload),
 * password change (verified against the current one, CSRF-protected,
 * audit-logged) and the read-only system configuration.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireLogin();

/** Allowed avatar uploads: jpg / png / webp, at most 2 MB, real image data. */
function zdspgc_store_avatar(array $file): array
{
    /** @return array{0:bool,1:string} [ok, message] — message is the stored file name on success. */
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return [false, 'no_file'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return [false, 'The upload failed (error code ' . $err . '). Try a smaller image.'];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 2 * 1024 * 1024) {
        return [false, 'The profile picture must be a JPG, PNG or WEBP image under 2 MB.'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return [false, 'The upload could not be verified. Please try again.'];
    }
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo !== false ? (string) finfo_file($finfo, $tmp) : '';
        if ($finfo !== false) {
            finfo_close($finfo);
        }
    } else {
        $info = @getimagesize($tmp);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    }
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => '',
    };
    if ($ext === '') {
        return [false, 'The profile picture must be a JPG, PNG or WEBP image.'];
    }
    $info = @getimagesize($tmp);
    if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
        return [false, 'That file does not look like a valid image.'];
    }

    $dir = __DIR__ . '/assets/img/avatars';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return [false, 'The avatar folder could not be created on the server.'];
    }
    $name = 'avatar_' . (int) Auth::id() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $name)) {
        return [false, 'The profile picture could not be saved on the server.'];
    }
    @chmod($dir . DIRECTORY_SEPARATOR . $name, 0644);
    return [true, $name];
}

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) Helpers::post('action', 'password');

    if ($action === 'profile') {
        $name = Security::clean(Helpers::post('full_name'), 120);
        if ($name === '') {
            Helpers::flash('error', 'Your display name cannot be empty.');
            Helpers::redirect('settings.php');
        }

        $current = Auth::avatar();
        $stored  = $current;
        $remove  = Helpers::post('remove_avatar') === '1';
        $file    = $_FILES['avatar'] ?? null;

        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$ok, $result] = zdspgc_store_avatar($file);
            if (!$ok) {
                Helpers::flash('error', (string) $result);
                Helpers::redirect('settings.php');
            }
            $stored = (string) $result;
        } elseif ($remove && $current !== '') {
            $stored = '';
        }

        try {
            Database::run(
                'UPDATE users SET full_name = :n, avatar = :a WHERE id = :id',
                ['n' => $name, 'a' => $stored, 'id' => Auth::id()]
            );
        } catch (Throwable $e) {
            Helpers::flash('error', 'Your profile could not be saved. The avatar column may still be migrating — reload and try again.');
            Helpers::redirect('settings.php');
        }

        // Drop the replaced file (never delete an unrelated user's upload).
        if ($stored !== $current && $current !== '') {
            $old = basename($current);
            if (preg_match('/^avatar_' . (int) Auth::id() . '_[a-f0-9]+\.(jpg|png|webp)$/', $old) === 1) {
                $oldPath = __DIR__ . '/assets/img/avatars/' . $old;
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
        }

        $_SESSION['name']   = $name;
        $_SESSION['avatar'] = $stored;
        Security::audit('PROFILE_UPDATE', 'Profile updated (name' . ($stored !== '' ? ' + avatar' : '') . ')', 'auth');
        Helpers::flash('success', 'Your profile has been updated — the sidebar now shows your name and picture.');
        Helpers::redirect('settings.php');
    }

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

$account     = Auth::user() ?? [];
$avatarInfo  = Auth::avatarFile();
$avatarName  = (string) ($account['avatar'] ?? Auth::avatar());

$PAGE_TITLE  = 'Settings';
$PAGE_ACTIVE = 'settings';
$PAGE_SUB    = 'Your account and this installation.';

require __DIR__ . '/includes/layout/header.php';
?>

<div class="grid cols-2">
  <div class="stack">
    <div class="card">
      <h3><?= icon('user') ?> Profile settings</h3>
      <form method="post" action="<?= Helpers::e(Helpers::url('settings.php')) ?>" enctype="multipart/form-data">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="profile">

        <div class="avatar-preview" id="avatar-preview" aria-hidden="true">
          <?= Helpers::e(mb_strtoupper(mb_substr($account['full_name'] ?? '?', 0, 1))) ?>
          <?php if ($avatarInfo !== null): ?>
            <img src="<?= Helpers::e($avatarInfo[0]) ?>" alt="" data-avatar-existing onerror="this.remove()">
          <?php endif; ?>
        </div>

        <label class="field">
          <span>Display name</span>
          <input type="text" name="full_name" maxlength="120" required value="<?= Helpers::e((string) ($account['full_name'] ?? '')) ?>" autocomplete="name">
          <span class="hint">Shown in the sidebar, audit log and scanner operator field.</span>
        </label>

        <label class="field">
          <span>Profile picture</span>
          <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-avatar-preview="#avatar-preview img">
          <span class="hint">JPG, PNG or WEBP · up to 2 MB. The picture replaces the letter avatar.</span>
        </label>

        <?php if ($avatarName !== ''): ?>
          <label class="checkline mb">
            <input type="checkbox" name="remove_avatar" value="1">
            <span>Remove the current picture (revert to the letter avatar)</span>
          </label>
        <?php endif; ?>

        <div class="row">
          <button type="submit"><?= icon('check') ?><span>Save profile</span></button>
        </div>
      </form>
    </div>

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
  </div>

  <div class="stack">
    <div class="card">
      <h3><?= icon('shield') ?> Your account</h3>
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

    <div class="card">
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
