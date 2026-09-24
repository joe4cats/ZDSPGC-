<?php
/**
 * Security.php — everything that protects the system.
 *
 *  1. CSRF tokens for every state-changing form / API call.
 *  2. Signed, revocable QR tokens (HMAC-SHA256 over a compact payload).
 *  3. Session-scoped rate limiting for logins and check-in posts.
 *  4. Input cleaning + audit logging.
 *
 * The QR token is *derived*, never stored as a secret:
 *     payload = 1|S|<student_no>|<nonce>      (or 1|E|<event_code>|<nonce>)
 *     token   = base64url(payload) . "." . base64url(hmac_sha256(payload)[0..15])
 * The nonce lives in the database, so re-issuing a QR code (new nonce)
 * immediately invalidates every old printout of that ID.
 */

declare(strict_types=1);

final class Security
{
    public const TOKEN_VERSION = '1';

    /* ---------------------------------------------------------------------
     * CSRF
     * ------------------------------------------------------------------ */

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . Helpers::e(self::csrfToken()) . '">';
    }

    /** Accepts a form field or the X-CSRF-Token header (used by fetch()). */
    public static function csrfSupplied(): string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (is_string($header) && $header !== '') {
            return $header;
        }
        return (string) ($_POST['csrf_token'] ?? '');
    }

    public static function csrfValid(?string $token = null): bool
    {
        $token ??= self::csrfSupplied();
        $known  = $_SESSION['csrf_token'] ?? '';
        return is_string($known) && $known !== '' && is_string($token)
            && hash_equals($known, $token);
    }

    public static function requireCsrf(): void
    {
        if (self::csrfValid()) {
            return;
        }
        if (defined('ZDSPGC_API_MODE')) {
            Helpers::jsonOut(['ok' => false, 'code' => 'csrf', 'message' => 'Session expired. Please reload the page.'], 419);
        }
        Helpers::flash('error', 'Security token expired. Please try again.');
        Helpers::redirect('login.php');
    }

    /* ---------------------------------------------------------------------
     * Signed QR tokens
     * ------------------------------------------------------------------ */

    public static function nonce(int $bytes = 6): string
    {
        return bin2hex(random_bytes($bytes)); // 6 bytes -> 12 hex chars
    }

    /** @param string $type 'S' for student, 'E' for event */
    public static function makeToken(string $type, string $key, string $nonce): string
    {
        $payload = implode('|', [self::TOKEN_VERSION, $type, $key, $nonce]);
        $sig     = substr(hash_hmac('sha256', $payload, APP_SECRET, true), 0, 16);
        return self::b64UrlEncode($payload) . '.' . self::b64UrlEncode($sig);
    }

    /**
     * Verifies a scanned token. Returns null when the signature is bad or the
     * payload is malformed.
     *
     * @return array{type:string,key:string,nonce:string}|null
     */
    public static function readToken(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '' || strlen($raw) > 200) {
            return null;
        }
        $parts = explode('.', $raw);
        if (count($parts) !== 2) {
            return null;
        }
        $payload = self::b64UrlDecode($parts[0]);
        $sig     = self::b64UrlDecode($parts[1]);
        if ($payload === null || $sig === null) {
            return null;
        }
        $expected = substr(hash_hmac('sha256', $payload, APP_SECRET, true), 0, 16);
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $fields = explode('|', $payload);
        if (count($fields) !== 4 || $fields[0] !== self::TOKEN_VERSION) {
            return null;
        }
        if (!in_array($fields[1], ['S', 'E'], true) || $fields[2] === '' || $fields[3] === '') {
            return null;
        }
        return ['type' => $fields[1], 'key' => $fields[2], 'nonce' => $fields[3]];
    }

    public static function constantTimeEquals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /* ---------------------------------------------------------------------
     * Rate limiting (per session, per bucket)
     * ------------------------------------------------------------------ */

    /** @return array{allowed:bool,hits:int,retry_after:int} */
    public static function rateLimit(string $bucket, int $max, int $windowSeconds): array
    {
        $now  = time();
        $key  = 'rl_' . preg_replace('/[^a-z0-9_]/i', '_', $bucket);
        $hits = array_values(array_filter(
            (array) ($_SESSION[$key] ?? []),
            static fn ($ts) => is_int($ts) && $ts > $now - $windowSeconds
        ));

        if (count($hits) >= $max) {
            $oldest = (int) min($hits);
            return [
                'allowed'     => false,
                'hits'        => count($hits),
                'retry_after' => max(1, ($oldest + $windowSeconds) - $now),
            ];
        }

        $hits[]         = $now;
        $_SESSION[$key] = $hits;
        return ['allowed' => true, 'hits' => count($hits), 'retry_after' => 0];
    }

    /* ---------------------------------------------------------------------
     * Input cleaning & audit
     * ------------------------------------------------------------------ */

    public static function clean(?string $value, int $maxLength = 255): string
    {
        $value = (string) $value;
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        return mb_substr(trim($value), 0, $maxLength);
    }

    /** CSV/Excel formula-injection guard, used by export.php */
    public static function csvSafe(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    public static function ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return 'cli';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }
        // Only honour forwarded headers when the direct peer is a proxy we
        // explicitly trust (see TRUSTED_PROXIES in config.php). Never trust
        // X-Forwarded-For from an untrusted client — it is trivially forged.
        $trusted = TRUSTED_PROXIES;
        if ($trusted !== [] && in_array($ip, $trusted, true)) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                foreach (explode(',', $forwarded) as $candidate) {
                    $candidate = trim($candidate);
                    if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                        return $candidate;
                    }
                }
            }
        }
        return $ip;
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 190);
    }

    public static function audit(string $action, string $detail, string $scope = ''): void
    {
        try {
            Database::insert('audit_log', [
                'created_at' => Helpers::now(),
                'actor'      => Auth::userName() ?? 'guest',
                'role'       => Auth::role() ?? '-',
                'action'     => $action,
                'detail'     => mb_substr($detail, 0, 480),
                'scope'      => mb_substr($scope, 0, 120),
                'ip'         => self::ip(),
            ]);
        } catch (Throwable $e) {
            // Never let logging break a check-in.
        }
    }

    /* ------------------------------------------------------------------ */

    public static function b64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64UrlDecode(string $data): ?string
    {
        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($data, true);
        return $decoded === false ? null : $decoded;
    }
}
