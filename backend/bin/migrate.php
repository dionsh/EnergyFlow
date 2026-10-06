<?php

declare(strict_types=1);

/*
 * Applies pending database migrations.
 *   php bin/migrate.php
 * Runs automatically on every Render container start (docker/entrypoint.sh).
 */

use EnergyFlow\Core\Migrator;

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    Migrator::run(static fn (string $line) => fwrite(STDOUT, "[migrate] {$line}" . PHP_EOL));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[migrate] FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
