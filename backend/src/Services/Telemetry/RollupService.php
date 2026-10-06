<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Telemetry;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Calendar\ScheduleBook;
use EnergyFlow\Services\Simulation\MachineModel;
use EnergyFlow\Services\Tariff\TariffBook;

/**
 * Turns 10-second readings into 15-minute buckets (the analytics source of truth).
 * Incremental: a watermark in job_runs remembers how far it got. Only closed
 * buckets are rolled up; buckets built from raw readings replace simulated ones.
 */
final class RollupService
{
    private const BUCKET = 900;
    private const MAX_GAP = 60; // a reading covers at most 60 s (gaps are not invented energy)

    public static function run(int $companyId, int $now): int
    {
        $closedEnd = intdiv($now - 30, self::BUCKET) * self::BUCKET;
        $watermark = Database::value("SELECT UNIX_TIMESTAMP(watermark) FROM job_runs WHERE job_key = 'rollup' AND company_id = ?", [$companyId]);
        if ($watermark === null) {
            $first = Database::value(
                'SELECT UNIX_TIMESTAMP(MIN(r.ts)) FROM readings_raw r JOIN machines m ON m.id = r.machine_id WHERE m.company_id = ?',
                [$companyId],
            );
            if ($first === null) {
                return 0;
            }
            $watermark = intdiv((int) $first, self::BUCKET) * self::BUCKET;
        }
        $watermark = (int) $watermark;
        if ($watermark >= $closedEnd) {
            return 0;
        }

        $schedule = ScheduleBook::forCompany($companyId);
        $tariff = TariffBook::forCompany($companyId);
        $machines = [];
        foreach (Database::all('SELECT id, kind, schedule_id FROM machines WHERE company_id = ?', [$companyId]) as $m) {
            $machines[(int) $m['id']] = $m;
        }

        $buckets = 0;
        // Work in 6-hour windows to bound memory.
        for ($from = $watermark; $from < $closedEnd; $from += 6 * 3600) {
            $to = min($closedEnd, $from + 6 * 3600);
            $rows = Database::all(
                'SELECT r.machine_id, UNIX_TIMESTAMP(r.ts) AS t, r.power_kw, r.voltage_v, r.power_factor, r.temperature_c, r.state
                   FROM readings_raw r JOIN machines m ON m.id = r.machine_id
                  WHERE m.company_id = ? AND r.ts >= FROM_UNIXTIME(?) AND r.ts < FROM_UNIXTIME(?)
                  ORDER BY r.machine_id, r.ts',
                [$companyId, $from, $to],
            );
            $buckets += self::write($companyId, self::aggregate($rows), $machines, $schedule, $tariff);
        }

        Database::run(
            "INSERT INTO job_runs (job_key, company_id, watermark, last_started_at, last_finished_at, last_status)
             VALUES ('rollup', ?, FROM_UNIXTIME(?), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'ok')
             ON DUPLICATE KEY UPDATE watermark = VALUES(watermark), last_finished_at = VALUES(last_finished_at), last_status = 'ok'",
            [$companyId, $closedEnd],
        );
        return $buckets;
    }

    /** @return array<string, array<string, mixed>> "machine:bucket" => aggregate */
    private static function aggregate(array $rows): array
    {
        $out = [];
        $count = count($rows);
        for ($k = 0; $k < $count; $k++) {
            $r = $rows[$k];
            $id = (int) $r['machine_id'];
            $t = (int) $r['t'];
            $bucket = intdiv($t, self::BUCKET) * self::BUCKET;
            $next = $rows[$k + 1] ?? null;
            $nextT = ($next !== null && (int) $next['machine_id'] === $id) ? (int) $next['t'] : $bucket + self::BUCKET;
            $dt = max(0, min(self::MAX_GAP, min($nextT, $bucket + self::BUCKET) - $t));
            $kw = (float) $r['power_kw'];
            $state = (int) $r['state'];
            $key = $id . ':' . $bucket;

            $a = &$out[$key];
            $a ??= ['machine_id' => $id, 'bucket' => $bucket, 'kwh' => 0.0, 'kvarh' => 0.0, 'sum_kw' => 0.0, 'n' => 0, 'max' => 0.0, 'min' => PHP_FLOAT_MAX,
                'pf_w' => 0.0, 'pf_kw' => 0.0, 'v' => 0.0, 'vn' => 0, 'temp' => null, 'run' => 0, 'idle' => 0, 'off' => 0, 'starts' => 0, 'cycles' => 0, 'prev' => null];
            $a['kwh'] += $kw * $dt / 3600;
            if ($r['power_factor'] !== null && (float) $r['power_factor'] > 0) {
                $pf = min(0.9999, (float) $r['power_factor']);
                $a['kvarh'] += $kw * tan(acos($pf)) * $dt / 3600;
                $a['pf_w'] += $kw * $pf;
                $a['pf_kw'] += $kw;
            }
            $a['sum_kw'] += $kw;
            $a['n']++;
            $a['max'] = max($a['max'], $kw);
            $a['min'] = min($a['min'], $kw);
            if ($r['voltage_v'] !== null) {
                $a['v'] += (float) $r['voltage_v'];
                $a['vn']++;
            }
            if ($r['temperature_c'] !== null) {
                $a['temp'] = max($a['temp'] ?? -INF, (float) $r['temperature_c']);
            }
            $a[$state === MachineModel::RUNNING ? 'run' : ($state === MachineModel::IDLE ? 'idle' : 'off')] += $dt;
            if ($a['prev'] !== null) {
                if ($a['prev'] === MachineModel::OFF && $state !== MachineModel::OFF) {
                    $a['starts']++;
                }
                if ($a['prev'] === MachineModel::IDLE && $state === MachineModel::RUNNING) {
                    $a['cycles']++;
                }
            }
            $a['prev'] = $state;
            unset($a);
        }
        return $out;
    }

    private static function write(int $companyId, array $aggregates, array $machines, ScheduleBook $schedule, ?TariffBook $tariff): int
    {
        if ($aggregates === []) {
            return 0;
        }
        $placeholders = [];
        $params = [];
        foreach ($aggregates as $a) {
            $m = $machines[$a['machine_id']] ?? null;
            if ($m === null) {
                continue;
            }
            $mid = $a['bucket'] + 450;
            $scheduled = $m['kind'] === 'incomer'
                ? false
                : $schedule->isScheduled($m['schedule_id'] === null ? null : (int) $m['schedule_id'], $mid, $a['machine_id']);
            $placeholders[] = '(?, FROM_UNIXTIME(?)' . str_repeat(', ?', 17) . ')';
            $covered = $a['run'] + $a['idle'] + $a['off'];
            array_push(
                $params,
                $a['machine_id'], $a['bucket'], $companyId,
                round($a['kwh'], 4), round($a['kvarh'], 4),
                round($a['sum_kw'] / max(1, $a['n']), 3), round($a['max'], 3), round($a['min'] === PHP_FLOAT_MAX ? 0 : $a['min'], 3),
                $a['pf_kw'] > 0 ? round($a['pf_w'] / $a['pf_kw'], 3) : null,
                $a['vn'] > 0 ? round($a['v'] / $a['vn'], 2) : null,
                $a['temp'] === null ? null : round($a['temp'], 2),
                $a['run'], $a['idle'], $a['off'] + max(0, 900 - $covered), $a['starts'], $a['cycles'],
                $tariff?->period($mid) ?? 'high', $scheduled ? 1 : 0, 'rollup',
            );
        }
        Database::run(
            'INSERT INTO readings_15m
               (machine_id, bucket_start, company_id, kwh, kvarh, avg_kw, max_kw, min_kw, avg_pf, avg_v, max_temp_c,
                running_s, idle_s, off_s, start_count, cycle_count, tariff_period, is_scheduled, source)
             VALUES ' . implode(',', $placeholders) . '
             ON DUPLICATE KEY UPDATE kwh = VALUES(kwh), kvarh = VALUES(kvarh), avg_kw = VALUES(avg_kw), max_kw = VALUES(max_kw),
               min_kw = VALUES(min_kw), avg_pf = VALUES(avg_pf), avg_v = VALUES(avg_v), max_temp_c = VALUES(max_temp_c),
               running_s = VALUES(running_s), idle_s = VALUES(idle_s), off_s = VALUES(off_s), start_count = VALUES(start_count),
               cycle_count = VALUES(cycle_count), tariff_period = VALUES(tariff_period), is_scheduled = VALUES(is_scheduled), source = VALUES(source)',
            $params,
        );
        return count($placeholders);
    }
}
