<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Demo;

use EnergyFlow\Core\Database;

/**
 * Demo company only. In a real plant, marking maintenance "done" changes the
 * machine itself, and the meter sees it. The demo's machines are simulated, so
 * the simulated machine has to reflect the repair: this is the only place
 * where an action in the app changes the simulation, and it never runs for a
 * real company.
 */
final class StoryHooks
{
    public static function onImplemented(int $companyId, array $recommendation, int $now): void
    {
        if (!DemoClock::isDemo($companyId) || $recommendation['machine_id'] === null) {
            return;
        }
        $machineId = (int) $recommendation['machine_id'];
        $proposal = json_decode((string) $recommendation['proposed_policy'], true) ?: [];

        if ($recommendation['generator'] === 'EfficiencyDrift') {
            // The worn hydraulic pump was serviced: the degradation stops from now on.
            Database::run(
                "UPDATE sim_scenarios SET ends_at = FROM_UNIXTIME(?)
                  WHERE company_id = ? AND machine_id = ? AND scenario = 'degradation' AND (ends_at IS NULL OR ends_at > FROM_UNIXTIME(?))",
                [$now, $companyId, $machineId, $now],
            );
        } elseif ($recommendation['generator'] === 'CompressedAirLeak') {
            // The leak survey fixed the expected share of the leaks.
            $share = (float) ($proposal['what_if']['params']['repair_share'] ?? 0.5);
            Database::run(
                "INSERT INTO sim_scenarios (company_id, scenario, machine_id, params, starts_at) VALUES (?, 'leak_repair', ?, ?, FROM_UNIXTIME(?))",
                [$companyId, $machineId, json_encode(['repair_share' => $share]), $now],
            );
        }
    }
}
