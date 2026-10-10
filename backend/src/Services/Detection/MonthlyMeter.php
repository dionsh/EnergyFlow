<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Detection;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Analytics\OverviewService;
use EnergyFlow\Services\Calendar\LocalTime;

/**
 * What the utility meter sees in one billing month, from the closed 15-minute
 * buckets: the site (the main incomer, or the sum of the machines when there is
 * none) and each machine. The grid charges are monthly — ERO bills reactive
 * energy over the month's kWh and engaged power on the month's highest
 * 15-minute value — so the LOW_PF and PEAK_COINCIDENCE rules work per month.
 */
final class MonthlyMeter
{
    public const BUCKET_S = 900;
    /** How far back a first run looks (the demo story is 75 days). */
    private const BACKFILL_MONTHS = 3;

    /**
     * @param list<array{t: int, kwh: float, kvarh: ?float, kw: float}> $site ordered by time
     * @param array<int, array{code: string, name: string, criticality: string, rated_kw: ?float}> $machines
     * @param array<int, array<int, array{kwh: float, kvarh: ?float, kw: float}>> $byMachine machine id => bucket start => values
     */
    private function __construct(
        public readonly string $month,
        public readonly int $from,
        /** End of the closed data (≤ $end). */
        public readonly int $to,
        /** End of the month (exclusive), even while it is still running. */
        public readonly int $end,
        public readonly bool $closed,
        public readonly array $site,
        public readonly array $machines,
        public readonly array $byMachine,
    ) {
    }

    /**
     * The months to (re)evaluate since the job last ran, oldest first, and the new
     * watermark (the end of the last closed bucket). Empty when nothing is new.
     *
     * @return array{months: list<int>, watermark: ?int}
     */
    public static function due(int $companyId, string $job, int $now, LocalTime $time): array
    {
        $last = Database::value(
            'SELECT UNIX_TIMESTAMP(MAX(bucket_start)) FROM readings_15m WHERE company_id = ? AND bucket_start <= FROM_UNIXTIME(?)',
            [$companyId, $now - self::BUCKET_S],
        );
        if ($last === null) {
            return ['months' => [], 'watermark' => null];
        }
        $end = (int) $last + self::BUCKET_S;
        $watermark = Database::value('SELECT UNIX_TIMESTAMP(watermark) FROM job_runs WHERE job_key = ? AND company_id = ?', [$job, $companyId]);
        if ($watermark !== null && (int) $watermark >= $end) {
            return ['months' => [], 'watermark' => null];
        }
        if ($watermark === null) {
            $first = (int) Database::value('SELECT UNIX_TIMESTAMP(MIN(bucket_start)) FROM readings_15m WHERE company_id = ?', [$companyId]);
            $start = max($time->startOfMonth($first), self::monthsBefore($time->startOfMonth($end - 1), self::BACKFILL_MONTHS - 1, $time));
        } else {
            $start = $time->startOfMonth(max((int) $watermark - 1, 0));
        }
        $months = [];
        for ($month = $start; $month < $end; $month = $time->startOfMonth($month + 32 * 86400)) {
            $months[] = $month;
        }
        return ['months' => $months, 'watermark' => $end];
    }

    public static function done(int $companyId, string $job, int $watermark): void
    {
        Database::run(
            "INSERT INTO job_runs (job_key, company_id, watermark, last_started_at, last_finished_at, last_status)
             VALUES (?, ?, FROM_UNIXTIME(?), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'ok')
             ON DUPLICATE KEY UPDATE watermark = VALUES(watermark), last_finished_at = VALUES(last_finished_at), last_status = 'ok'",
            [$job, $companyId, $watermark],
        );
    }

    /** The closed buckets of the month that starts at $monthStart (local time), up to $now. */
    public static function load(int $companyId, int $monthStart, int $now, LocalTime $time): self
    {
        $monthEnd = $time->startOfMonth($monthStart + 32 * 86400);
        $to = min($monthEnd, $now - $now % self::BUCKET_S);

        $machines = [];
        foreach (Database::all(
            "SELECT id, code, name, criticality, rated_power_kw FROM machines WHERE company_id = ? AND kind = 'machine' AND archived_at IS NULL",
            [$companyId],
        ) as $m) {
            $machines[(int) $m['id']] = [
                'code' => $m['code'], 'name' => $m['name'], 'criticality' => $m['criticality'],
                'rated_kw' => $m['rated_power_kw'] === null ? null : (float) $m['rated_power_kw'],
            ];
        }

        $byMachine = [];
        $sum = [];
        foreach (Database::all(
            "SELECT r.machine_id, UNIX_TIMESTAMP(r.bucket_start) AS t, r.kwh, r.kvarh, r.avg_kw
               FROM readings_15m r JOIN machines m ON m.id = r.machine_id
              WHERE r.company_id = ? AND m.kind = 'machine' AND m.archived_at IS NULL
                AND r.bucket_start >= FROM_UNIXTIME(?) AND r.bucket_start < FROM_UNIXTIME(?)",
            [$companyId, $monthStart, $to],
        ) as $r) {
            $t = (int) $r['t'];
            $row = ['kwh' => (float) $r['kwh'], 'kvarh' => $r['kvarh'] === null ? null : (float) $r['kvarh'], 'kw' => (float) $r['avg_kw']];
            $byMachine[(int) $r['machine_id']][$t] = $row;
            $sum[$t] ??= ['t' => $t, 'kwh' => 0.0, 'kvarh' => null, 'kw' => 0.0];
            $sum[$t]['kwh'] += $row['kwh'];
            $sum[$t]['kw'] += $row['kw'];
            if ($row['kvarh'] !== null) {
                $sum[$t]['kvarh'] = ($sum[$t]['kvarh'] ?? 0.0) + $row['kvarh'];
            }
        }

        $incomerId = OverviewService::incomerId($companyId);
        if ($incomerId !== null) {
            $site = array_map(static fn (array $r): array => [
                't' => (int) $r['t'], 'kwh' => (float) $r['kwh'], 'kvarh' => $r['kvarh'] === null ? null : (float) $r['kvarh'], 'kw' => (float) $r['avg_kw'],
            ], Database::all(
                'SELECT UNIX_TIMESTAMP(bucket_start) AS t, kwh, kvarh, avg_kw FROM readings_15m
                  WHERE machine_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?) ORDER BY bucket_start',
                [$incomerId, $monthStart, $to],
            ));
        } else {
            ksort($sum);
            $site = array_values($sum);
        }

        return new self(
            gmdate('Y-m', $monthStart + 15 * 86400),
            $monthStart,
            $to,
            $monthEnd,
            $to >= $monthEnd,
            $site,
            $machines,
            $byMachine,
        );
    }

    private static function monthsBefore(int $monthStart, int $count, LocalTime $time): int
    {
        for ($i = 0; $i < $count; $i++) {
            $monthStart = $time->startOfMonth($monthStart - 86400);
        }
        return $monthStart;
    }
}
