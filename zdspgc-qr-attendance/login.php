<?php
/**
 * login.php — staff sign-in.
 *
 * Security: CSRF token, per-session rate limiting, bcrypt password check,
 * generic error message, and an audit-log entry for every attempt.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    // run-local.bat opens login.php?fresh=1 so every launcher start lands on the
    // sign-in form instead of the dashboard: sign the old session out first,
    // then come back on a clean request (new session + new CSRF token).
    $isLauncherStart = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && (string) ($_GET['fresh'] ?? '') === '1';
    if ($isLauncherStart) {
        Auth::logout('Launcher reopened the sign-in page');
        Helpers::redirect('login.php');
    }
    Helpers::redirect('index.php');
}

$error    = '';
$username = '';
$lockoutRemaining = Auth::lockoutRemaining();

if (Helpers::isPost()) {
    Security::requireCsrf();

    $username = Security::clean(Helpers::post('username'), 60);
    $password = (string) (Helpers::post('password') ?? '');

    $rate = Security::rateLimit('login', 12, 300);
    if (!$rate['allowed']) {
        $error = 'Too many sign-in attempts from this browser. Please wait ' . $rate['retry_after'] . ' seconds.';
        Security::audit('LOGIN_RATE_LIMITED', 'Rate limit hit for "' . $username . '"', 'auth');
    } elseif ($lockoutRemaining > 0) {
        $error = 'Too many failed password attempts. Sign-in is locked for this browser for 5 minutes.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter both your username and password.';
    } else {
        $attempt = Auth::attempt($username, $password);
        if ($attempt['ok']) {
            Helpers::flash('success', $attempt['message']);
            $intended = (string) ($_SESSION['intended'] ?? '');
            unset($_SESSION['intended']);

            // Only ever redirect to a same-site path (blocks open redirects).
            if ($intended !== '' && str_starts_with($intended, '/') && !str_starts_with($intended, '//') && !str_contains($intended, ':')) {
                header('Location: ' . $intended);
                exit;
            }
            Helpers::redirect('index.php');
        }
        $error = $attempt['message'];
        $lockoutRemaining = (int) ($attempt['retry_after'] ?? Auth::lockoutRemaining());
    }
}

$lockoutRemaining = max($lockoutRemaining, Auth::lockoutRemaining());
if ($lockoutRemaining > 0 && $error === '') {
    $error = 'Too many failed password attempts. Sign-in is locked for this browser for 5 minutes.';
}

$flashes = Helpers::takeFlashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign in · <?= Helpers::e(APP_SHORT) ?></title>
  <link rel="icon" type="image/png" href="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>">
  <link rel="stylesheet" href="<?= Helpers::e(Helpers::url('assets/css/style.css')) ?>">
</head>
<body class="login-page">
  <main class="login-shell">
    <header class="login-brand">
      <img class="login-logo" src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>" alt="ZDSPGC seal" width="84" height="84">
      <div>
        <span class="login-title"><?= Helpers::e(APP_SHORT) ?></span>
        <span class="login-sub">Event check-in system · <?= Helpers::e(SCHOOL_CAMPUS) ?></span>
      </div>
    </header>

    <section class="login-card" aria-labelledby="signin-title">
      <div class="login-card-head">
        <h1 id="signin-title">Sign in</h1>
        <p>Authorised staff only — administrator, officer and faculty accounts.</p>
      </div>

      <?php foreach ($flashes as $flash): ?>
        <div class="flash <?= Helpers::e((string) $flash['type']) ?>">
          <?= icon('bell') ?><span><?= Helpers::e((string) $flash['message']) ?></span>
        </div>
      <?php endforeach; ?>

      <?php if ($error !== ''): ?>
        <div class="flash <?= $lockoutRemaining > 0 ? 'error login-lockout' : 'error' ?>" role="alert"
          <?php if ($lockoutRemaining > 0): ?>data-lockout-seconds="<?= $lockoutRemaining ?>"<?php endif; ?>>
          <?= icon('alert') ?>
          <span>
            <?= Helpers::e($error) ?>
            <?php if ($lockoutRemaining > 0): ?>
              <strong class="lockout-countdown" aria-live="polite">Please wait <span data-lockout-timer>0:00</span> before trying again.</strong>
            <?php endif; ?>
          </span>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= Helpers::e(Helpers::url('login.php')) ?>" autocomplete="on">
        <?= Security::csrfField() ?>
        <label class="field">
          <span>Username</span>
          <input type="text" name="username" value="<?= Helpers::e($username) ?>" required autofocus autocomplete="username" placeholder="e.g. admin" <?= $lockoutRemaining > 0 ? 'disabled' : '' ?>>
        </label>
        <label class="field">
          <span>Password</span>
          <input type="password" name="password" required autocomplete="current-password" placeholder="Your password" <?= $lockoutRemaining > 0 ? 'disabled' : '' ?>>
        </label>
        <button class="block" type="submit" <?= $lockoutRemaining > 0 ? 'disabled' : '' ?>>
          <?= icon('lock') ?><span><?= $lockoutRemaining > 0 ? 'Sign-in locked' : 'Sign in' ?></span>
        </button>
      </form>
    </section>

    <p class="login-note">
      Passwords are stored as bcrypt hashes. Five wrong attempts locks sign-in for <?= (int) LOGIN_LOCKOUT_MINUTES ?> minutes.<br>
      Students do not sign in — they only need their QR ID at the scan station.
    </p>
  </main>
  <script src="<?= Helpers::e(Helpers::url('assets/js/app.js')) ?>"></script>
</body>
</html>
