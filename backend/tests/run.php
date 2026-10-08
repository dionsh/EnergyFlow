<?php

declare(strict_types=1);

/*
 * Backend test suite.
 *   php tests/run.php
 * Uses a throwaway database (energyflow_test) that is dropped and rebuilt from
 * the migrations on every run, so the dev database is never touched.
 */

use EnergyFlow\Core\Database;
use EnergyFlow\Core\Env;
use EnergyFlow\Core\Migrator;

// Real environment variables beat .env, so these override the local config.
putenv('DB_NAME=energyflow_test');
putenv('APP_DEBUG=1');
putenv('SESSION_SECURE=0');
putenv('APP_KEY=' . str_repeat('ab', 32));
putenv('DEMO_OWNER_PASSWORD=test-owner-password');

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support.php';

// Tests never call Groq (removed after .env is loaded): AI features take their
// deterministic paths, so results don't depend on the network or a model.
putenv('GROQ_API_KEY');
unset($_ENV['GROQ_API_KEY']);

if (Env::isProduction()) {
    fwrite(STDERR, "Refusing to run tests with APP_ENV=production.\n");
    exit(1);
}

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', Env::get('DB_HOST', '127.0.0.1'), Env::int('DB_PORT', 3306)),
    Env::get('DB_USER', 'root'),
    Env::get('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$server->exec('DROP DATABASE IF EXISTS energyflow_test');
$server->exec('CREATE DATABASE energyflow_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
Database::reset();
Migrator::run(static fn (string $line) => null);

$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);
foreach ($files as $file) {
    echo PHP_EOL . basename($file, '.php') . PHP_EOL;
    require $file;
    $code = Tests::run();
    if ($code !== 0) {
        exit($code);
    }
}
exit(0);
