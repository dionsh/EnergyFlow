<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Devices;

/**
 * Real hardware (EF-N3 firmware, tools/hw-bridge). Devices sit behind NAT, so
 * the server never connects to them: a queued command waits until the device's
 * next signed uplink (every 10 s), whose response says `pending_commands > 0`.
 * The device then calls GET /device/commands (→ sent), opens its remote-STOP
 * relay and calls POST /device/commands/{id}/ack (→ executed or failed).
 * Verification is independent of the device's word: the meter must show the drop.
 */
final class PullDriver implements DeviceDriver
{
    public function dispatch(array $command, int $now): void
    {
        // Nothing to push: the command waits in the queue for the device's next uplink.
    }

    public function advance(int $companyId, int $now): int
    {
        return 0;
    }

    public function describe(): array
    {
        return ['kind' => 'pull', 'protocol' => 'ef-device-v1', 'auth' => 'hmac-sha256', 'uplink_s' => 10];
    }
}
