<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Detection;

use EnergyFlow\Core\Database;
use EnergyFlow\Utils\Time;

/**
 * DEVICE_OFFLINE (rule): a hardware node that has not reported for more than
 * 2 minutes. An alert, never waste. Resolved automatically when data returns.
 * Simulated nodes are skipped: they cannot lose connectivity.
 */
final class DeviceHealth
{
    public const OFFLINE_AFTER_S = 120;

    public static function run(int $companyId, int $now): int
    {
        $changed = 0;
        foreach (Database::all(
            'SELECT id, serial, UNIX_TIMESTAMP(last_seen_at) AS seen FROM devices
              WHERE company_id = ? AND is_simulated = 0 AND archived_at IS NULL AND last_seen_at IS NOT NULL',
            [$companyId],
        ) as $d) {
            $key = 'device_offline:' . $d['serial'];
            $seen = (int) $d['seen'];
            if ($now - $seen > self::OFFLINE_AFTER_S) {
                AlertManager::upsert($companyId, [
                    'type' => 'DEVICE_OFFLINE',
                    'method' => 'rule',
                    'severity' => 'warning',
                    'dedupe_key' => $key,
                    'title_key' => 'device_offline',
                    'params' => ['serial' => $d['serial'], 'since' => Time::iso(gmdate('Y-m-d H:i:s', $seen))],
                    'evidence' => ['last_seen_at' => Time::iso(gmdate('Y-m-d H:i:s', $seen)), 'threshold_s' => self::OFFLINE_AFTER_S],
                    'device_id' => (int) $d['id'],
                    'opened_at' => $seen + self::OFFLINE_AFTER_S,
                    'last_seen_at' => $now,
                    'link' => '/devices/' . $d['id'],
                ], $now, episodic: false);
                $changed++;
            } else {
                AlertManager::resolve($companyId, $key, $seen);
            }
        }
        return $changed;
    }
}
