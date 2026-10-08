<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Devices;

use EnergyFlow\Core\Database;

/**
 * Server-side stand-in for a node in the demo company. It behaves like the
 * firmware's command handling — and nothing more: it picks the command up on
 * its next 10-second uplink and opens the remote-STOP relay a second later.
 *
 * It does NOT report success. Once executed_at is set, the deterministic
 * simulator treats the machine as stopped (SimContext forced-off interval), and
 * the command is verified exactly like a real one: only when the following
 * meter readings show the power below the machine's off threshold.
 *
 * Runs inside the demo catch-up (under the simulation lock) before new readings
 * are generated, so the stop always applies to readings not yet produced.
 */
final class SimulatedDriver implements DeviceDriver
{
    private const UPLINK_S = 10;

    public function dispatch(array $command, int $now): void
    {
        // Like real hardware: the command waits for the node's next uplink.
    }

    public function advance(int $companyId, int $now): int
    {
        $generated = (int) (Database::value('SELECT UNIX_TIMESTAMP(last_generated_at) FROM sim_state WHERE company_id = ?', [$companyId]) ?? $now);
        $advanced = 0;
        foreach (Database::all(
            "SELECT c.id, UNIX_TIMESTAMP(c.requested_at) AS requested FROM device_commands c JOIN devices d ON d.id = c.device_id
              WHERE c.company_id = ? AND d.is_simulated = 1 AND c.status = 'queued' AND c.expires_at > FROM_UNIXTIME(?)",
            [$companyId, $now],
        ) as $c) {
            $requested = (int) $c['requested'];
            $sent = (intdiv($requested, self::UPLINK_S) + 1) * self::UPLINK_S; // next uplink
            if ($now < $sent) {
                continue;
            }
            $executed = max($sent + 1, $generated + 1);
            Database::run(
                "UPDATE device_commands SET status = 'executed', sent_at = FROM_UNIXTIME(?), executed_at = FROM_UNIXTIME(?) WHERE id = ? AND status = 'queued'",
                [$sent, $executed, (int) $c['id']],
            );
            $advanced++;
        }
        return $advanced;
    }

    public function describe(): array
    {
        return ['kind' => 'simulated', 'protocol' => 'server-side', 'auth' => null, 'uplink_s' => self::UPLINK_S];
    }
}
