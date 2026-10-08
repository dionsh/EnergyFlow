<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Devices;

/** Picks the driver for a device. The only place that distinguishes simulated from real hardware. */
final class DeviceGateway
{
    public static function driverFor(array $device): DeviceDriver
    {
        return (bool) $device['is_simulated'] ? new SimulatedDriver() : new PullDriver();
    }

    /** Lets simulated devices act on their queue (real devices act on their own). */
    public static function advanceSimulated(int $companyId, int $now): int
    {
        return (new SimulatedDriver())->advance($companyId, $now);
    }
}
