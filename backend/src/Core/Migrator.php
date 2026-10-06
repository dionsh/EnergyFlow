<?php

declare(strict_types=1);

namespace EnergyFlow\Core;

use RuntimeException;

/**
 * Applies numbered `.sql` files from database/migrations once each, in order.
 * Safe to run on every container start: a MySQL named lock stops two instances
 * from migrating at the same time, and applied versions are recorded.
 */
final class Migrator
{
    private const LOCK = 'energyflow_migrations';

    /** @param callable(string): void $log */
    public static function run(callable $log, ?string $directory = null): int
    {
        $directory ??= EF_ROOT . '/database/migrations';
        $pdo = Database::pdo();

        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(80) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        if ((int) Database::value('SELECT GET_LOCK(?, 60)', [self::LOCK]) !== 1) {
            throw new RuntimeException('Could not acquire the migration lock.');
        }

        try {
            $applied = array_column(Database::all('SELECT version FROM schema_migrations'), 'version');
            $files = glob($directory . '/*.sql') ?: [];
            sort($files, SORT_STRING);

            $count = 0;
            foreach ($files as $file) {
                $version = basename($file, '.sql');
                if (in_array($version, $applied, true)) {
                    continue;
                }
                $log("Applying {$version}…");
                foreach (self::statements((string) file_get_contents($file)) as $sql) {
                    $pdo->exec($sql);
                }
                Database::run('INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())', [$version]);
                $count++;
            }
            $log($count === 0 ? 'Database is up to date.' : "Applied {$count} migration(s).");
            return $count;
        } finally {
            Database::value('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    public static function latestVersion(): ?string
    {
        return Database::value('SELECT MAX(version) FROM schema_migrations');
    }

    /**
     * Splits a migration file into statements. Migrations are written so that a
     * semicolon only ever appears at the end of a statement.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
        $parts = array_map('trim', explode(';', $withoutComments));
        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
