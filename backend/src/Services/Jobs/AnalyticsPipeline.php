<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Jobs;

use EnergyFlow\Services\Carbon\CarbonLedger;
use EnergyFlow\Services\Control\CommandService;
use EnergyFlow\Services\Impact\ImpactService;
use EnergyFlow\Services\Control\PolicyEngine;
use EnergyFlow\Services\Detection\DeviceHealth;
use EnergyFlow\Services\Detection\DriftDetector;
use EnergyFlow\Services\Detection\WasteDetector;
use EnergyFlow\Services\Optimization\Recommendations;

/**
 * Everything that turns fresh measurements into insight and action, in
 * dependency order. Called after new data lands — by the demo catch-up, the
 * cron job runner and the demo seeder — so simulated and real companies follow
 * the same path. Each step is incremental and idempotent and returns quickly
 * when nothing is new.
 */
final class AnalyticsPipeline
{
    /** @return array<string, int> */
    public static function run(int $companyId, int $now): array
    {
        $waste = WasteDetector::run($companyId, $now);
        return [
            'waste_episodes' => $waste['episodes'],
            'drift' => DriftDetector::run($companyId, $now),
            'device_alerts' => DeviceHealth::run($companyId, $now),
            'commands_decided' => CommandService::verify($companyId, $now),
            'policy_commands' => PolicyEngine::tick($companyId, $now),
            'recommendations' => Recommendations::generate($companyId, $now),
            'carbon_days' => CarbonLedger::run($companyId, $now),
            'impact' => ImpactService::run($companyId, $now),
        ];
    }
}
