<?php
/**
 * Database.php — thin PDO wrapper.
 *
 * One shared PDO connection for the whole request. Supports the two drivers
 * this project targets: SQLite (zero-setup demo) and MySQL (XAMPP/production).
 * Every query in the app uses prepared statements, so SQL injection needs an
 * explicit mistake to happen.
 */

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function driver(): string
    {
        return DB_DRIVER === 'mysql' ? 'mysql' : 'sqlite';
    }

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if (self::driver() === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } else {
            self::ensureStorageDir();
            $dsn = 'sqlite:' . DB_SQLITE_PATH;
            self::$pdo = new PDO($dsn, null, null, $options);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
        }

        return self::$pdo;
    }

    /** @param array<int|string,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @param array<int|string,mixed> $params */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<string,mixed> $row */
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql  = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES ('
              . implode(', ', array_map(static fn ($c) => ':' . $c, $cols)) . ')';
        self::run($sql, $row);
        return (int) self::conn()->lastInsertId();
    }

    /** @param array<string,mixed> $row */
    public static function update(string $table, array $row, string $where, array $params = []): void
    {
        $sets = [];
        foreach (array_keys($row) as $col) {
            $sets[] = $col . ' = :set_' . $col;
        }
        $bind = [];
        foreach ($row as $col => $value) {
            $bind['set_' . $col] = $value;
        }
        foreach ($params as $key => $value) {
            $bind[$key] = $value;
        }
        self::run('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where, $bind);
    }

    public static function ensureStorageDir(): void
    {
        if (!is_dir(STORAGE_DIR)) {
            @mkdir(STORAGE_DIR, 0775, true);
        }
    }

    /** True when the driver error means "duplicate key". */
    public static function isDuplicate(PDOException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $driver   = (string) ($e->errorInfo[1] ?? '');
        return $sqlState === '23000' || $sqlState === '23505' || $driver === '1062' || $driver === '19';
    }
}
