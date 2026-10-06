<?php

declare(strict_types=1);

use EnergyFlow\Core\Env;

define('EF_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'EnergyFlow\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = EF_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Env::load(EF_ROOT . '/.env');

// Everything is stored and computed in UTC. Company-local time (Kosovo uses the
// Europe/Belgrade zone: CET/CEST) is applied explicitly wherever schedules,
// tariff windows and day boundaries matter.
date_default_timezone_set('UTC');

error_reporting(E_ALL);
ini_set('display_errors', Env::bool('APP_DEBUG') ? '1' : '0');
