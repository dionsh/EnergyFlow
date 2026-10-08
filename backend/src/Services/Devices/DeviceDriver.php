<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Devices;

/**
 * How a queued command reaches a device. This is the only seam between
 * EnergyFlow and hardware: everything above it (safety checks, the command
 * lifecycle, verification by the meter, policies, the UI) is identical for
 * simulated and real devices.
 *
 *   SimulatedDriver  server-side stand-in for a node (demo company)
 *   PullDriver       real hardware: EF-N3 firmware or tools/hw-bridge, which
 *                    fetch commands over the signed device API and ack them
 */
interface DeviceDriver
{
    /** Called right after a command was queued. */
    public function dispatch(array $command, int $now): void;

    /** What the device does on its own between requests (only simulated devices do anything here). */
    public function advance(int $companyId, int $now): int;

    /** Shown on the device page: how this device is connected. */
    public function describe(): array;
}
