<?php
/**
 * Auth.php — sessions, roles and capability checks.
 *
 * Roles
 *   admin   : everything (accounts, students, events, scanner, reports)
 *   officer : students, events, scanner, reports
 *   faculty : scanner + reports (e.g. a teacher manning the door)
 *
 * Passwords are stored with password_hash()/password_verify() (bcrypt) — no
 * plaintext, no reversible encoding, ever.
 */

declare(strict_types=1);

final class Auth
{
    public const ROLES = ['admin', 'officer', 'faculty'];

    /** Capabilities per role. */
    private const CAPS = [
        'admin'   => ['manage_users', 'manage_students', 'manage_events', 'run_scanner', 'view_reports'],
        'officer' => ['manage_students', 'manage_events', 'run_scanner', 'view_reports'],
        'faculty' => ['run_scanner', 'view_reports'],
    ];

    /* ---------------------------------------------------------------------
     * Sign-in / sign-out
     * ------------------------------------------------------------------ */

    /** @return array{ok:bool,message:string,user:?array,retry_after?:int,locked?:bool} */
    public static function attempt(string $username, string $password): array
    {
        $remaining = self::lockoutRemaining();
        if ($remaining > 0) {
            return self::lockedResult($remaining);
        }

        $username = strtolower(trim($username));
        $user     = Database::one('SELECT * FROM users WHERE username = :u', ['u' => $username]);

        // Always verify something, so a missing user and a wrong password take
        // about the same time (blocks user enumeration by timing).
        $hash   = $user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $passOk = password_verify($password, (string) $hash);

        if ($user === null || !$passOk) {
            $remaining = self::registerFailure();
            Security::audit('LOGIN_FAILED', 'Failed sign-in for "' . $username . '"', 'auth');
            if ($remaining > 0) {
                return self::lockedResult($remaining);
            }
            return ['ok' => false, 'message' => 'Invalid username or password.', 'user' => null];
        }

        if (($user['status'] ?? 'active') !== 'active') {
            Security::audit('LOGIN_BLOCKED', 'Inactive account "' . $username . '" tried to sign in', 'auth');
            return ['ok' => false, 'message' => 'This account is inactive. Contact the administrator.', 'user' => null];
        }

        unset($_SESSION['login_fails'], $_SESSION['login_lock_until']);
        self::startSession($user);
        Database::run('UPDATE users SET last_login_at = :t WHERE id = :id', ['t' => Helpers::now(), 'id' => $user['id']]);
        Security::audit('LOGIN_OK', 'Signed in as ' . $user['role'], 'auth');

        return ['ok' => true, 'message' => 'Welcome, ' . $user['full_name'] . '.', 'user' => $user];
    }

    /** Remaining lockout seconds, or 0. Expired values are cleared on every request. */
    public static function lockoutRemaining(): int
    {
        $lockUntil = (int) ($_SESSION['login_lock_until'] ?? 0);
        $remaining = max(0, $lockUntil - time());
        if ($remaining === 0 && isset($_SESSION['login_lock_until'])) {
            unset($_SESSION['login_lock_until'], $_SESSION['login_fails']);
        }
        return $remaining;
    }

    /** @return array{ok:bool,message:string,user:null,retry_after:int,locked:bool} */
    private static function lockedResult(int $remaining): array
    {
        return [
            'ok'          => false,
            'message'     => 'Too many failed password attempts. Sign-in is locked for this browser for 5 minutes.',
            'user'        => null,
            'retry_after' => $remaining,
            'locked'      => true,
        ];
    }

    private static function registerFailure(): int
    {
        $fails = (int) ($_SESSION['login_fails'] ?? 0) + 1;
        $_SESSION['login_fails'] = $fails;
        if ($fails >= LOGIN_MAX_ATTEMPTS) {
            $_SESSION['login_lock_until'] = time() + (LOGIN_LOCKOUT_MINUTES * 60);
            $_SESSION['login_fails']      = 0;
        }
        return self::lockoutRemaining();
    }

    private static function startSession(array $user): void
    {
        session_regenerate_id(true);            // stops session fixation
        $_SESSION['uid']        = (int) $user['id'];
        $_SESSION['role']       = (string) $user['role'];
        $_SESSION['name']       = (string) $user['full_name'];
        $_SESSION['username']   = (string) $user['username'];
        $_SESSION['login_at']   = time();
        $_SESSION['last_seen']  = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public static function logout(string $reason = 'User signed out'): void
    {
        if (self::check()) {
            Security::audit('LOGOUT', $reason, 'auth');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }
        session_destroy();
    }

    /* ---------------------------------------------------------------------
     * Session state
     * ------------------------------------------------------------------ */

    public static function check(): bool
    {
        return isset($_SESSION['uid']) && (int) $_SESSION['uid'] > 0;
    }

    public static function id(): ?int
    {
        return self::check() ? (int) $_SESSION['uid'] : null;
    }

    public static function role(): ?string
    {
        return self::check() ? (string) ($_SESSION['role'] ?? '') : null;
    }

    public static function userName(): ?string
    {
        return self::check() ? (string) ($_SESSION['name'] ?? '') : null;
    }

    /** Fresh copy of the signed-in user row (no password hash). */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        return Database::one(
            'SELECT id, username, full_name, role, status, created_at, last_login_at FROM users WHERE id = :id',
            ['id' => self::id()]
        );
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function can(string $capability): bool
    {
        $role = self::role();
        if ($role === null) {
            return false;
        }
        return in_array($capability, self::CAPS[$role] ?? [], true);
    }

    public static function roleLabel(?string $role = null): string
    {
        $role ??= (string) self::role();
        return match ($role) {
            'admin'   => 'System Administrator',
            'officer' => 'Student Affairs Officer',
            'faculty' => 'Faculty / Door Marshal',
            default   => ucfirst($role),
        };
    }

    /** Called on every request: enforces the idle timeout. */
    public static function touch(): void
    {
        if (!self::check()) {
            return;
        }
        $last = (int) ($_SESSION['last_seen'] ?? time());
        if (time() - $last > SESSION_IDLE_MINUTES * 60) {
            self::logout('Session expired (idle)');
            Helpers::flash('warning', 'You were signed out after ' . SESSION_IDLE_MINUTES . ' minutes of inactivity.');
            Helpers::redirect('login.php');
        }
        $_SESSION['last_seen'] = time();
    }

    /* ---------------------------------------------------------------------
     * Guards
     * ------------------------------------------------------------------ */

    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        $_SESSION['intended'] = (string) ($_SERVER['REQUEST_URI'] ?? '/index.php');
        Helpers::flash('warning', 'Please sign in to continue.');
        Helpers::redirect('login.php');
    }

    public static function requireCapability(string $capability): void
    {
        self::requireLogin();
        if (self::can($capability)) {
            return;
        }
        Security::audit('ACCESS_DENIED', 'Blocked from capability "' . $capability . '"', 'authz');
        Helpers::flash('error', 'Your role (' . self::roleLabel() . ') is not allowed to open that page.');
        Helpers::redirect('index.php');
    }
}
