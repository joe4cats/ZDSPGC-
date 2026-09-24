<?php
/**
 * Helpers.php — small, boring, reusable functions used by every page.
 */

declare(strict_types=1);

final class Helpers
{
    /* ---------------------------------------------------------------------
     * Output / escaping
     * ------------------------------------------------------------------ */

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /* ---------------------------------------------------------------------
     * Request input
     * ------------------------------------------------------------------ */

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = $_GET[$key] ?? null;
        return is_string($v) ? trim($v) : $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $v = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
        return $v === false || $v === null ? $default : (int) $v;
    }

    public static function post(string $key, ?string $default = null): ?string
    {
        $v = $_POST[$key] ?? null;
        return is_string($v) ? trim($v) : $default;
    }

    public static function postInt(string $key, int $default = 0): int
    {
        $v = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
        return $v === false || $v === null ? $default : (int) $v;
    }

    public static function isPost(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }

    /** Reads a JSON body once and caches it (used by api/*.php). */
    public static function jsonInput(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $raw   = file_get_contents('php://input') ?: '';
        $data  = json_decode($raw, true);
        $cache = is_array($data) ? $data : [];
        return $cache;
    }

    /** Form field or JSON body — whichever the client sent. */
    public static function input(string $key, ?string $default = null): ?string
    {
        $v = $_POST[$key] ?? null;
        if (!is_string($v) || $v === '') {
            $json = self::jsonInput();
            if (isset($json[$key]) && is_scalar($json[$key])) {
                $v = (string) $json[$key];
            }
        }
        return is_string($v) && $v !== '' ? trim($v) : $default;
    }

    public static function jsonOut(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---------------------------------------------------------------------
     * URLs / redirects
     * ------------------------------------------------------------------ */

    public static function baseUrl(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $dir    = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
        // api/ and includes/ live one level deeper than the app root.
        if (preg_match('#/(api|includes)$#', $dir)) {
            $dir = dirname($dir);
        }
        $dir  = rtrim($dir, '/');
        $base = $scheme . '://' . $host . ($dir === '' ? '' : $dir);
        return $base;
    }

    public static function url(string $path, array $query = []): string
    {
        $url = self::baseUrl() . '/' . ltrim($path, '/');
        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . self::baseUrl() . '/' . ltrim($path, '/'));
        exit;
    }

    public static function currentPage(): string
    {
        return basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    }

    /* ---------------------------------------------------------------------
     * Flash messages
     * ------------------------------------------------------------------ */

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function takeFlashes(): array
    {
        $flashes = (array) ($_SESSION['flash'] ?? []);
        unset($_SESSION['flash']);
        return array_values(array_filter($flashes, static fn ($f) => is_array($f) && isset($f['type'], $f['message'])));
    }

    /* ---------------------------------------------------------------------
     * Dates (all app times are in APP_TIMEZONE, stored as 'Y-m-d H:i:s')
     * ------------------------------------------------------------------ */

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return date('Y-m-d');
    }

    public static function fmtDate(?string $value, string $format = 'M j, Y'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $ts = strtotime($value);
        return $ts === false ? '—' : date($format, $ts);
    }

    public static function fmtDateTime(?string $value): string
    {
        return self::fmtDate($value, 'M j, Y · g:i A');
    }

    public static function fmtTime(?string $value): string
    {
        return self::fmtDate($value, 'g:i A');
    }

    /** "8:00 AM – 12:00 PM, Mar 12, 2026" */
    public static function fmtWindow(?string $start, ?string $end): string
    {
        if (!$start || !$end) {
            return '—';
        }
        $sameDay = substr($start, 0, 10) === substr($end, 0, 10);
        return self::fmtTime($start) . ' – ' . self::fmtTime($end) . ', ' . self::fmtDate($start, 'M j, Y')
            . ($sameDay ? '' : ' → ' . self::fmtDate($end, 'M j, Y'));
    }

    public static function humanAgo(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return '—';
        }
        $sec = time() - $ts;
        if ($sec < 0) {
            $sec    = -$sec;
            $suffix = ' from now';
        } else {
            $suffix = ' ago';
        }
        if ($sec < 60) {
            return $sec . 's' . $suffix;
        }
        if ($sec < 3600) {
            return (string) floor($sec / 60) . 'm' . $suffix;
        }
        if ($sec < 86400) {
            return (string) floor($sec / 3600) . 'h' . $suffix;
        }
        return (string) floor($sec / 86400) . 'd' . $suffix;
    }

    /** Days between now and a datetime (negative = already past). */
    public static function daysUntil(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : (int) ceil(($ts - time()) / 86400);
    }

    public static function page(): int
    {
        $p = self::getInt('page', 1);
        return $p < 1 ? 1 : $p;
    }

    public static function percent(int $part, int $whole): float
    {
        return $whole <= 0 ? 0.0 : round(($part / $whole) * 100, 1);
    }
}
