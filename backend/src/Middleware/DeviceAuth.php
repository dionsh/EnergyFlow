<?php

declare(strict_types=1);

namespace EnergyFlow\Middleware;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\RateLimiter;
use EnergyFlow\Services\Telemetry\DeviceSecrets;

/**
 * Authenticates an EnergyFlow node:
 *   Authorization: Device <serial>
 *   X-EF-Timestamp: <unix seconds>
 *   X-EF-Signature: hex(HMAC-SHA256(device secret, timestamp + "." + body))
 * The signature covers the exact body, so payloads cannot be altered or replayed
 * outside the ±5 minute window (and replayed readings are de-duplicated anyway).
 */
final class DeviceAuth implements Middleware
{
    public function handle(Request $request): void
    {
        $authorization = (string) $request->header('Authorization');
        if (!preg_match('/^Device\s+([A-Za-z0-9\-]{3,30})$/', $authorization, $match)) {
            throw new HttpException(401, 'device_unauthenticated', 'Missing device credentials.');
        }
        $serial = $match[1];
        RateLimiter::hit('ingest:' . $serial, 60, 60);

        $device = Database::one('SELECT * FROM devices WHERE serial = ? AND archived_at IS NULL', [$serial]);
        $timestamp = (int) $request->header('X-EF-Timestamp');
        $signature = (string) $request->header('X-EF-Signature');
        if ($device === null || $device['is_simulated'] || !DeviceSecrets::verify(
            $serial,
            (int) $device['key_version'],
            $timestamp,
            $request->rawBody(),
            $signature,
            Clock::realNow(),
        )) {
            throw new HttpException(401, 'device_unauthenticated', 'Invalid device signature.');
        }
        $request->set('device', $device);
    }
}
