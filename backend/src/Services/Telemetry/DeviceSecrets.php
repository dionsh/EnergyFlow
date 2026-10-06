<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Telemetry;

use EnergyFlow\Core\Env;

/**
 * Per-device secrets are DERIVED, never stored:
 *   secret = HMAC-SHA256(APP_KEY, "device:{serial}:v{key_version}")
 * Rotating a device key = bumping key_version. Changing APP_KEY re-keys every device.
 */
final class DeviceSecrets
{
    public const MAX_SKEW_SECONDS = 300;

    public static function secretFor(string $serial, int $keyVersion): string
    {
        return hash_hmac('sha256', "device:{$serial}:v{$keyVersion}", Env::require('APP_KEY'));
    }

    /** Signature a device sends in X-EF-Signature. */
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function verify(string $serial, int $keyVersion, int $timestamp, string $body, string $signature, int $now): bool
    {
        if (abs($now - $timestamp) > self::MAX_SKEW_SECONDS) {
            return false;
        }
        $expected = self::sign(self::secretFor($serial, $keyVersion), $timestamp, $body);
        return hash_equals($expected, strtolower($signature));
    }
}
