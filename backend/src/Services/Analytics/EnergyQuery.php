<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Analytics;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Tariff\TariffBook;

/**
 * Energy over any time range = closed 15-minute buckets + the still-open tail
 * integrated from 10-second readings. Costs use the tariff period stored on each
 * bucket (high/low), so night consumption is priced at the night rate.
 */
final class EnergyQuery
{
    private const RAW_STEP = 10;

    /**
     * @return array<int, array{kwh: float, kwh_high: float, kwh_low: float, kvarh: float}> machine_id => totals
     */
    public static function byMachine(int $companyId, int $from, int $to, ?int $machineId = null, ?TariffBook $tariff = null): array
    {
        $machineFilter = $machineId === null ? '' : ' AND machine_id = ' . (int) $machineId;
        $out = [];
        foreach (Database::all(
            "SELECT machine_id,
                    SUM(kwh) AS kwh, SUM(kvarh) AS kvarh,
                    SUM(CASE WHEN tariff_period = 'high' THEN kwh ELSE 0 END) AS kwh_high,
                    SUM(CASE WHEN tariff_period = 'low' THEN kwh ELSE 0 END) AS kwh_low
               FROM readings_15m
              WHERE company_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?){$machineFilter}
              GROUP BY machine_id",
            [$companyId, $from, $to],
        ) as $row) {
            $out[(int) $row['machine_id']] = [
                'kwh' => (float) $row['kwh'],
                'kwh_high' => (float) $row['kwh_high'],
                'kwh_low' => (float) $row['kwh_low'],
                'kvarh' => (float) ($row['kvarh'] ?? 0),
            ];
        }

        // Open tail: the part of the range after the last rolled-up bucket.
        $lastBucket = Database::value(
            'SELECT UNIX_TIMESTAMP(MAX(bucket_start)) FROM readings_15m WHERE company_id = ? AND bucket_start < FROM_UNIXTIME(?)',
            [$companyId, $to],
        );
        $tailFrom = max($from, $lastBucket === null ? $from : (int) $lastBucket + 900);
        if ($tailFrom < $to) {
            $period = $tariff?->period(intdiv($tailFrom + $to, 2)) ?? 'high';
            foreach (Database::all(
                "SELECT r.machine_id, SUM(r.power_kw) AS kw_sum,
                        SUM(CASE WHEN r.power_factor > 0 THEN r.power_kw * TAN(ACOS(LEAST(r.power_factor, 0.9999))) ELSE 0 END) AS kvar_sum
                   FROM readings_raw r JOIN machines m ON m.id = r.machine_id
                  WHERE m.company_id = ? AND r.ts >= FROM_UNIXTIME(?) AND r.ts < FROM_UNIXTIME(?)" . ($machineId === null ? '' : ' AND r.machine_id = ' . (int) $machineId) . '
                  GROUP BY r.machine_id',
                [$companyId, $tailFrom, $to],
            ) as $row) {
                $id = (int) $row['machine_id'];
                $kwh = (float) $row['kw_sum'] * self::RAW_STEP / 3600;
                $out[$id] ??= ['kwh' => 0.0, 'kwh_high' => 0.0, 'kwh_low' => 0.0, 'kvarh' => 0.0];
                $out[$id]['kwh'] += $kwh;
                $out[$id]['kwh_' . $period] += $kwh;
                $out[$id]['kvarh'] += (float) $row['kvar_sum'] * self::RAW_STEP / 3600;
            }
        }
        return $out;
    }

    /**
     * Site total: the main incomer when there is one (it also sees unmonitored
     * loads), otherwise the sum of all machines.
     *
     * @param array<int, array{kwh: float, kwh_high: float, kwh_low: float, kvarh: float}> $byMachine
     */
    public static function site(array $byMachine, ?int $incomerId): array
    {
        if ($incomerId !== null && isset($byMachine[$incomerId])) {
            return $byMachine[$incomerId];
        }
        $total = ['kwh' => 0.0, 'kwh_high' => 0.0, 'kwh_low' => 0.0, 'kvarh' => 0.0];
        foreach ($byMachine as $id => $values) {
            if ($id === $incomerId) {
                continue;
            }
            foreach ($total as $key => $_) {
                $total[$key] += $values[$key];
            }
        }
        return $total;
    }

    /** Energy cost (excl. VAT) of a kWh split at the plan's energy rates. */
    public static function energyCost(array $totals, ?TariffBook $tariff): float
    {
        if ($tariff === null) {
            return 0.0;
        }
        return $totals['kwh_high'] * $tariff->energyRate('high', PHP_FLOAT_MAX)
            + $totals['kwh_low'] * $tariff->energyRate('low', PHP_FLOAT_MAX);
    }

    /** Highest 15-minute average demand (kW) at the site in the range. */
    public static function peakKw(int $companyId, int $from, int $to, ?int $incomerId): float
    {
        if ($incomerId !== null) {
            return (float) Database::value(
                'SELECT MAX(avg_kw) FROM readings_15m WHERE machine_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?)',
                [$incomerId, $from, $to],
            );
        }
        return (float) Database::value(
            'SELECT MAX(total) FROM (SELECT SUM(avg_kw) AS total FROM readings_15m
               WHERE company_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?) GROUP BY bucket_start) t',
            [$companyId, $from, $to],
        );
    }

    /**
     * Time series for charts. Resolution picks the source: raw (≤ 1 min), 15 min, hour or day.
     *
     * @return list<array{t: int, kw: float, kwh: float}>
     */
    public static function series(int $companyId, int $machineId, int $from, int $to, string $resolution, string $timezone = 'Europe/Belgrade'): array
    {
        if ($resolution === 'raw' || $resolution === '1m') {
            $rows = Database::all(
                'SELECT FLOOR(UNIX_TIMESTAMP(r.ts) / 60) * 60 AS t, AVG(r.power_kw) AS kw
                   FROM readings_raw r JOIN machines m ON m.id = r.machine_id
                  WHERE m.company_id = ? AND r.machine_id = ? AND r.ts >= FROM_UNIXTIME(?) AND r.ts < FROM_UNIXTIME(?)
                  GROUP BY t ORDER BY t',
                [$companyId, $machineId, $from, $to],
            );
            return array_map(static fn (array $r): array => ['t' => (int) $r['t'], 'kw' => round((float) $r['kw'], 3), 'kwh' => round((float) $r['kw'] / 60, 4)], $rows);
        }

        $bucketExpr = match ($resolution) {
            '1h' => 'FLOOR(UNIX_TIMESTAMP(bucket_start) / 3600) * 3600',
            '1d' => 'UNIX_TIMESTAMP(DATE(CONVERT_TZ(bucket_start, \'+00:00\', ?)))',
            default => 'UNIX_TIMESTAMP(bucket_start)',
        };
        $params = $resolution === '1d' ? [self::offsetString($timezone, $from)] : [];
        $rows = Database::all(
            "SELECT {$bucketExpr} AS t, SUM(kwh) AS kwh, AVG(avg_kw) AS kw
               FROM readings_15m
              WHERE company_id = ? AND machine_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?)
              GROUP BY t ORDER BY t",
            [...$params, $companyId, $machineId, $from, $to],
        );
        return array_map(static fn (array $r): array => ['t' => (int) $r['t'], 'kw' => round((float) $r['kw'], 3), 'kwh' => round((float) $r['kwh'], 3)], $rows);
    }

    private static function offsetString(string $timezone, int $ts): string
    {
        $seconds = (new \DateTimeZone($timezone))->getOffset(new \DateTimeImmutable('@' . $ts));
        return sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
    }
}
