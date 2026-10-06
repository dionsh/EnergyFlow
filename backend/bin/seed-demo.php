<?php

declare(strict_types=1);

/*
 * Builds (or rebuilds) the demo company "Ylli Plast Sh.p.k." with its 75-day story.
 *   php bin/seed-demo.php                       # story ends "now" at the latest weekday 21:40
 *   php bin/seed-demo.php --scene=now           # story ends at the real current time
 *   php bin/seed-demo.php --days=30             # shorter history
 * Run it locally, or against Aiven by pointing the DB_* variables at it.
 */

use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Demo\DemoSeeder;

require dirname(__DIR__) . '/src/bootstrap.php';

$options = getopt('', ['scene::', 'days::']);
$scene = $options['scene'] ?? 'weekday_evening';
$days = max(7, min(90, (int) ($options['days'] ?? 75)));

$started = microtime(true);
$anchor = DemoClock::sceneAnchor($scene);
fwrite(STDOUT, "[seed] Scene '{$scene}', story anchor " . gmdate('Y-m-d H:i', $anchor) . " UTC, {$days} days of history" . PHP_EOL);

$result = DemoSeeder::reset($anchor, $days, static fn (string $line) => fwrite(STDOUT, "[seed] {$line}" . PHP_EOL));

fwrite(STDOUT, sprintf('[seed] Done in %.1f s. Demo company id %d.', microtime(true) - $started, $result['company_id']) . PHP_EOL);
fwrite(STDOUT, '[seed] Owner login: ' . DemoSeeder::OWNER_EMAIL . PHP_EOL);
if ($result['owner_password'] !== null) {
    fwrite(STDOUT, "[seed] Generated owner password (set DEMO_OWNER_PASSWORD to choose one): {$result['owner_password']}" . PHP_EOL);
}
