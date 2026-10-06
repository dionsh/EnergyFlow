<?php

declare(strict_types=1);

namespace EnergyFlow\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * The single PDO connection for the whole app, plus small query helpers.
 * Every query in EnergyFlow is a prepared statement with bound parameters.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    /** Used by tests and CLI tools that need a fresh connection. */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /** @return list<array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::statement($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::statement($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::statement($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Executes a write and returns the number of affected rows. */
    public static function run(string $sql, array $params = []): int
    {
        return self::statement($sql, $params)->rowCount();
    }

    /** Executes an INSERT and returns the new auto-increment id. */
    public static function insert(string $sql, array $params = []): int
    {
        self::statement($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function statement(string $sql, array $params): PDOStatement
    {
        $statement = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $statement->bindValue($name, $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }
        $statement->execute();
        return $statement;
    }

    private static function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST', '127.0.0.1'),
            Env::int('DB_PORT', 3306),
            Env::get('DB_NAME', 'energyflow'),
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ] + self::sslOptions();

        $pdo = new PDO($dsn, Env::get('DB_USER', 'root'), Env::get('DB_PASS', ''), $options);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    /**
     * Aiven refuses unencrypted connections. Render's filesystem is ephemeral, so
     * the CA certificate is passed as an env var (DB_SSL_CA_CONTENT) and written
     * to a temp file at runtime. DB_SSL_CA (a path) is for local use.
     */
    private static function sslOptions(): array
    {
        $caPath = Env::get('DB_SSL_CA');
        $caContent = Env::get('DB_SSL_CA_CONTENT');

        if ($caContent !== null) {
            $caPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'energyflow-db-ca.pem';
            $pem = str_replace('\n', "\n", $caContent);
            if (!is_file($caPath) || file_get_contents($caPath) !== $pem) {
                file_put_contents($caPath, $pem);
            }
        }

        if ($caPath !== null) {
            return [
                PDO::MYSQL_ATTR_SSL_CA => $caPath,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ];
        }

        if (Env::bool('DB_SSL')) {
            // Encrypted but without certificate verification. Prefer providing the CA.
            return [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false];
        }

        return [];
    }
}
