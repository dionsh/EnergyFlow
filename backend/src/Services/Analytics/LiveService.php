<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Analytics;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Calendar\ScheduleBook;
use EnergyFlow\Services\Carbon\EmissionFactors;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

/**
 * What is happening right now: every machine's live state and power, whether it
 * should be running, and — if it is running outside its schedule — how much that
 * has cost so far tonight in kWh, € and CO₂e.
 */
final class LiveService
{
    public static function snapshot(int $companyId): array
    {
        $now = Clock::now($companyId);
        $schedule = ScheduleBook::forCompany($companyId);
        $tariff = TariffBook::forCompany($companyId);
        $factor = EmissionFactors::gridFactor($companyId);
        $time = $schedule->time;

        $rows = Database::all(
            "SELECT m.id, m.kind, m.code, m.name, m.type_code, m.schedule_id, m.criticality, m.control_mode, m.rated_power_kw,
                    m.off_threshold_kw, d.serial AS device_serial, d.is_simulated AS device_simulated,
                    l.state, UNIX_TIMESTAMP(l.state_since) AS state_since, UNIX_TIMESTAMP(l.ts) AS ts,
                    l.power_kw, l.voltage_v, l.current_a, l.power_factor, l.frequency_hz, l.temperature_c
               FROM machines m
               LEFT JOIN machine_live l ON l.machine_id = m.id
               LEFT JOIN devices d ON d.id = (
                   SELECT dc.device_id FROM device_channels dc
                    WHERE dc.machine_id = m.id AND dc.valid_to IS NULL
                    ORDER BY dc.valid_from DESC, dc.id DESC LIMIT 1)
              WHERE m.company_id = ? AND m.archived_at IS NULL
              ORDER BY m.kind DESC, m.code",
            [$companyId],
        );

        $today = EnergyQuery::byMachine($companyId, $time->startOfDay($now), $now, null, $tariff);
        $machines = [];
        $incomer = null;
        $siteKw = 0.0;
        $lastReading = null;
        $waste = ['kwh' => 0.0, 'eur' => 0.0, 'co2_kg' => 0.0, 'since' => null, 'machines' => 0];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $stale = $row['ts'] === null || $now - (int) $row['ts'] > 120;
            $kw = $stale ? null : (float) $row['power_kw'];
            $lastReading = max($lastReading ?? 0, (int) $row['ts']);
            $scheduleId = $row['schedule_id'] === null ? null : (int) $row['schedule_id'];
            $scheduled = $schedule->isScheduled($scheduleId, $now, $id);
            $on = $row['state'] !== null && $row['state'] !== 'off' && !$stale;

            $machine = [
                'id' => $id,
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $row['type_code'],
                'state' => $stale ? 'unknown' : ($row['state'] ?? 'unknown'),
                'state_since' => $row['state_since'] === null ? null : Time::iso(gmdate('Y-m-d H:i:s', (int) $row['state_since'])),
                'power_kw' => $kw,
                'voltage_v' => $stale || $row['voltage_v'] === null ? null : (float) $row['voltage_v'],
                'current_a' => $stale || $row['current_a'] === null ? null : (float) $row['current_a'],
                'power_factor' => $stale || $row['power_factor'] === null ? null : (float) $row['power_factor'],
                'temperature_c' => $stale || $row['temperature_c'] === null ? null : (float) $row['temperature_c'],
                'today_kwh' => round($today[$id]['kwh'] ?? 0.0, 2),
                'scheduled_now' => $scheduled,
                'criticality' => $row['criticality'],
                'control_mode' => $row['control_mode'],
                'device' => $row['device_serial'] === null ? null : ['serial' => $row['device_serial'], 'simulated' => (bool) $row['device_simulated']],
                'after_hours' => null,
            ];

            if ($row['kind'] === 'incomer') {
                $incomer = $machine;
                continue;
            }
            $siteKw += $kw ?? 0.0;

            // Running outside its schedule (critical always-on loads are never waste).
            if ($on && !$scheduled && $row['criticality'] !== 'critical') {
                $since = $schedule->lastScheduledEnd($scheduleId, $now, $id) ?? (int) $row['state_since'];
                $since = max($since, $now - 3 * 86400);
                $energy = EnergyQuery::byMachine($companyId, $since, $now, $id, $tariff)[$id] ?? null;
                $kwh = $energy['kwh'] ?? 0.0;
                $eur = $energy === null ? 0.0 : EnergyQuery::energyCost($energy, $tariff);
                $machine['after_hours'] = [
                    'since' => Time::iso(gmdate('Y-m-d H:i:s', $since)),
                    'duration_s' => $now - $since,
                    'kwh' => round($kwh, 2),
                    'eur' => round($eur, 2),
                    'co2_kg' => round($kwh * $factor['value'], 1),
                ];
                $waste['kwh'] += $kwh;
                $waste['eur'] += $eur;
                $waste['co2_kg'] += $kwh * $factor['value'];
                $waste['since'] = $waste['since'] === null ? $since : min($waste['since'], $since);
                $waste['machines']++;
            }
            $machines[] = $machine;
        }

        $devices = Database::one(
            "SELECT COUNT(*) AS total, SUM(status = 'online' AND last_seen_at >= FROM_UNIXTIME(?)) AS online
               FROM devices WHERE company_id = ? AND archived_at IS NULL",
            [$now - 120, $companyId],
        );

        return [
            'now' => Time::iso(gmdate('Y-m-d H:i:s', $now)),
            'local_time' => $time->format($now, 'H:i'),
            'site_kw' => round($incomer['power_kw'] ?? $siteKw, 2),
            'monitored_kw' => round($siteKw, 2),
            'incomer' => $incomer,
            'machines' => $machines,
            // "On" = running or idle (an unloaded compressor still draws power).
            'on' => count(array_filter($machines, static fn (array $m): bool => in_array($m['state'], ['running', 'idle'], true))),
            'devices' => ['online' => (int) ($devices['online'] ?? 0), 'total' => (int) ($devices['total'] ?? 0)],
            'last_reading_at' => $lastReading ? Time::iso(gmdate('Y-m-d H:i:s', $lastReading)) : null,
            'tariff_period' => $tariff?->period($now),
            'waste_now' => $waste['machines'] === 0 ? null : [
                'since' => Time::iso(gmdate('Y-m-d H:i:s', $waste['since'])),
                'kwh' => round($waste['kwh'], 2),
                'eur' => round($waste['eur'], 2),
                'co2_kg' => round($waste['co2_kg'], 1),
                'machines' => $waste['machines'],
            ],
        ];
    }
}
