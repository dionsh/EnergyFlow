<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Detection;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Calendar\ScheduleBook;
use EnergyFlow\Utils\Time;

/**
 * Power spikes (SPIKE, method: statistical) — docs/03 §7.
 *
 * Metric: each machine's peak 10-second power in every minute. Reference: the
 * machine's own 15-minute peaks over the last 28 days, per day type (working /
 * non-working) and hour of day, kept in machine_baselines (refreshed once a
 * local day). Only buckets where the machine was running count: a median of 0
 * for the hours a machine is normally off would turn every after-hours start
 * into a "spike", and that is AFTER_HOURS' job.
 *
 *   robust z = (peak − median) / (1.4826 · MAD)
 *
 * A spike is z > 6 in at least 2 consecutive minutes; it ends after 5 normal
 * minutes. σ has a floor of 2 % of the median, so a machine with a very steady
 * peak (a pump, lighting) is not flagged for noise. Severity: critical when a
 * reading exceeds 110 % of the nameplate rating (motor overload), else warning.
 * An alert, not waste: the energy is small, the risk is to the machine.
 */
final class SpikeDetector
{
    private const JOB = 'spike_detect';
    private const BASELINE_JOB = 'spike_baseline';
    private const REFERENCE_DAYS = 28;
    private const MIN_SAMPLES = 12;
    public const Z = 6.0;
    public const MIN_MINUTES = 2;
    private const CLEAR_MINUTES = 5;
    private const SIGMA_FLOOR = 0.02;
    private const SIGMA_FLOOR_KW = 0.05;
    public const OVERLOAD = 1.10;
    private const MIN_READINGS = 3;
    private const LOOKBACK_S = 600;
    private const MAX_WINDOW_S = 7200;
    /** hour_of_day used for the all-hours reference of a day type. */
    private const ALL_HOURS = 24;

    public static function run(int $companyId, int $now): int
    {
        $to = $now - $now % 60; // whole minutes only
        $watermark = Database::value('SELECT UNIX_TIMESTAMP(watermark) FROM job_runs WHERE job_key = ? AND company_id = ?', [self::JOB, $companyId]);
        if ($watermark !== null && (int) $watermark >= $to) {
            return 0;
        }
        $schedule = ScheduleBook::forCompany($companyId);
        self::refreshBaselines($companyId, $schedule, $now);

        // Re-read a recent episode from its start, so it keeps its identity (dedupe key) and full numbers.
        $from = $watermark === null ? $to - 3600 : (int) $watermark - self::LOOKBACK_S;
        $recent = Database::value(
            "SELECT UNIX_TIMESTAMP(MIN(opened_at)) FROM alerts WHERE company_id = ? AND type = 'SPIKE' AND last_seen_at >= FROM_UNIXTIME(?)",
            [$companyId, $from - (self::CLEAR_MINUTES + 1) * 60],
        );
        if ($recent !== null) {
            $from = min($from, (int) $recent - (self::MIN_MINUTES + 1) * 60);
        }
        $from = max($from - $from % 60, $to - self::MAX_WINDOW_S);

        $machines = [];
        foreach (Database::all(
            "SELECT id, code, name, rated_power_kw FROM machines WHERE company_id = ? AND kind = 'machine' AND archived_at IS NULL",
            [$companyId],
        ) as $m) {
            $machines[(int) $m['id']] = $m;
        }
        $minutes = [];
        foreach (Database::all(
            "SELECT r.machine_id, FLOOR(UNIX_TIMESTAMP(r.ts) / 60) * 60 AS minute, AVG(r.power_kw) AS avg_kw, MAX(r.power_kw) AS peak_kw, COUNT(*) AS n
               FROM readings_raw r JOIN machines m ON m.id = r.machine_id
              WHERE m.company_id = ? AND m.kind = 'machine' AND m.archived_at IS NULL
                AND r.ts >= FROM_UNIXTIME(?) AND r.ts < FROM_UNIXTIME(?)
              GROUP BY r.machine_id, minute ORDER BY r.machine_id, minute",
            [$companyId, $from, $to],
        ) as $r) {
            $minutes[(int) $r['machine_id']][] = ['t' => (int) $r['minute'], 'avg' => (float) $r['avg_kw'], 'peak' => (float) $r['peak_kw'], 'n' => (int) $r['n']];
        }

        $written = 0;
        foreach ($minutes as $machineId => $series) {
            if (!isset($machines[$machineId])) {
                continue;
            }
            $baselines = self::baselines($machineId);
            if ($baselines === []) {
                continue; // no running history yet: nothing to compare with
            }
            foreach (self::episodes($series, $baselines, $schedule) as $episode) {
                self::report($companyId, $machines[$machineId], $episode, $series, $to, $now);
                $written++;
            }
        }

        Database::run(
            "INSERT INTO job_runs (job_key, company_id, watermark, last_started_at, last_finished_at, last_status)
             VALUES (?, ?, FROM_UNIXTIME(?), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'ok')
             ON DUPLICATE KEY UPDATE watermark = VALUES(watermark), last_finished_at = VALUES(last_finished_at), last_status = 'ok'",
            [self::JOB, $companyId, $to],
        );
        return $written;
    }

    /**
     * Groups flagged minutes into episodes: ≥ MIN_MINUTES consecutive minutes with
     * z > Z start one; CLEAR_MINUTES normal minutes in a row end it.
     *
     * @param list<array{t: int, avg: float, peak: float, n: int}> $series
     * @return list<array{start: int, end: ?int, last: int, minutes: list<array>, reference: array}>
     */
    public static function episodes(array $series, array $baselines, ScheduleBook $schedule): array
    {
        $episodes = [];
        $current = null;
        $pending = [];
        $clear = 0;
        foreach ($series as $minute) {
            if ($minute['n'] < self::MIN_READINGS) {
                continue; // too few readings to judge this minute
            }
            $reference = self::referenceFor($baselines, $minute['t'], $schedule);
            if ($reference === null) {
                continue;
            }
            $sigma = self::sigma($reference['p50_kw'], $reference['mad_kw']);
            $z = ($minute['peak'] - $reference['p50_kw']) / $sigma;
            $row = $minute + ['z' => $z, 'ref' => $reference];

            if ($z > self::Z) {
                $clear = 0;
                if ($current !== null) {
                    $current['minutes'][] = $row;
                    $current['last'] = $minute['t'];
                    continue;
                }
                // Consecutive: the previous flagged minute must be the minute before.
                if ($pending !== [] && end($pending)['t'] !== $minute['t'] - 60) {
                    $pending = [];
                }
                $pending[] = $row;
                if (count($pending) >= self::MIN_MINUTES) {
                    $current = ['start' => $pending[0]['t'], 'end' => null, 'last' => $minute['t'], 'minutes' => $pending, 'reference' => $pending[0]['ref']];
                    $pending = [];
                }
                continue;
            }

            $pending = [];
            if ($current !== null && ++$clear >= self::CLEAR_MINUTES) {
                $current['end'] = $current['last'] + 60;
                $episodes[] = $current;
                $current = null;
                $clear = 0;
            }
        }
        if ($current !== null) {
            $episodes[] = $current; // still going on
        }
        return $episodes;
    }

    public static function sigma(float $median, float $mad): float
    {
        return max(1.4826 * $mad, self::SIGMA_FLOOR * $median, self::SIGMA_FLOOR_KW);
    }

    /** @param list<array{t: int, avg: float, peak: float, n: int}> $series the minutes around it, for the chart */
    private static function report(int $companyId, array $m, array $episode, array $series, int $to, int $now): void
    {
        $id = (int) $m['id'];
        $rated = $m['rated_power_kw'] === null ? null : (float) $m['rated_power_kw'];
        $peak = max(array_column($episode['minutes'], 'peak'));
        $zMax = max(array_column($episode['minutes'], 'z'));
        $overload = $rated !== null && $rated > 0 && $peak > self::OVERLOAD * $rated;
        $reference = $episode['reference'];
        $typicalKw = $reference['p50_kwh'] * 4; // the machine's usual average power in this hour
        $excessKwh = array_sum(array_map(static fn (array $x): float => max(0.0, $x['avg'] - $typicalKw) / 60, $episode['minutes']));
        $end = $episode['end'] ?? $episode['last'] + 60;
        $confirmedAt = $episode['start'] + self::MIN_MINUTES * 60;
        $flagged = array_flip(array_column($episode['minutes'], 't'));
        $context = array_values(array_filter($series, static fn (array $x): bool => $x['t'] >= $episode['start'] - 600 && $x['t'] < $end + 600));

        AlertManager::upsert($companyId, [
            'type' => 'SPIKE',
            'method' => 'statistical',
            'severity' => SeverityPolicy::spike($overload),
            'dedupe_key' => "spike:m{$id}:{$episode['start']}",
            'title_key' => 'spike',
            'params' => [
                'machine' => $m['name'],
                'code' => $m['code'],
                'peak_kw' => round($peak, 2),
                'normal_kw' => round($reference['p50_kw'], 2),
                'z' => round($zMax, 1),
                'rated_kw' => $rated,
                'rated_pct' => $rated ? round($peak / $rated * 100, 0) : null,
                'overload' => $overload,
                'since' => Time::iso(gmdate('Y-m-d H:i:s', $episode['start'])),
                'duration_s' => $end - $episode['start'],
            ],
            'evidence' => [
                'method' => 'statistical',
                'rule' => 'robust_z',
                'metric' => 'peak_power_1min_kw',
                'baseline' => [
                    'what' => '15-minute peak power while running',
                    'day_type' => $reference['day_type'],
                    'hour_of_day' => $reference['hour_of_day'] === self::ALL_HOURS ? null : $reference['hour_of_day'],
                    'median_kw' => round($reference['p50_kw'], 3),
                    'mad_kw' => round($reference['mad_kw'], 3),
                    'sigma_kw' => round(self::sigma($reference['p50_kw'], $reference['mad_kw']), 3),
                    'samples' => $reference['n_samples'],
                    'window' => [$reference['window_start'], $reference['window_end']],
                ],
                'observed' => ['peak_kw' => round($peak, 3), 'z_max' => round($zMax, 1)],
                'limit_kw' => round($reference['p50_kw'] + self::Z * self::sigma($reference['p50_kw'], $reference['mad_kw']), 3),
                'thresholds' => ['z' => self::Z, 'min_minutes' => self::MIN_MINUTES, 'clear_minutes' => self::CLEAR_MINUTES, 'overload_pct' => (int) round(self::OVERLOAD * 100)],
                'rated_kw' => $rated,
                'excess_kwh' => round($excessKwh, 3),
                'minutes' => count($episode['minutes']),
                'series' => array_map(
                    static fn (array $x): array => [
                        't' => Time::iso(gmdate('Y-m-d H:i:s', $x['t'])),
                        'avg_kw' => round($x['avg'], 2),
                        'peak_kw' => round($x['peak'], 2),
                        'spike' => isset($flagged[$x['t']]),
                    ],
                    array_slice($context, 0, 150),
                ),
            ],
            'machine_id' => $id,
            'opened_at' => min($confirmedAt, $to),
            'last_seen_at' => $episode['end'] === null ? $now : $end,
            'resolved_at' => $episode['end'],
            'link' => '/waste?tab=alerts&alert={id}',
        ], $now);
    }

    /** The reference for a minute: its day type and hour, else that day type's all-hours, else the other's. */
    private static function referenceFor(array $baselines, int $ts, ScheduleBook $schedule): ?array
    {
        $type = self::dayType($ts, $schedule);
        $other = $type === 'working' ? 'non_working' : 'working';
        $hour = intdiv($schedule->time->minuteOfDay($ts), 60);
        foreach ([[$type, $hour], [$type, self::ALL_HOURS], [$other, self::ALL_HOURS]] as [$t, $h]) {
            $row = $baselines["{$t}:{$h}"] ?? null;
            if ($row !== null && $row['n_samples'] >= self::MIN_SAMPLES) {
                return $row;
            }
        }
        return null;
    }

    private static function dayType(int $ts, ScheduleBook $schedule): string
    {
        return $schedule->isClosedDay($ts) || $schedule->time->dayOfWeek($ts) >= 6 ? 'non_working' : 'working';
    }

    /** @return array<string, array> "day_type:hour" => baseline row */
    private static function baselines(int $machineId): array
    {
        $out = [];
        foreach (Database::all('SELECT * FROM machine_baselines WHERE machine_id = ?', [$machineId]) as $b) {
            $out[$b['day_type'] . ':' . (int) $b['hour_of_day']] = [
                'day_type' => $b['day_type'],
                'hour_of_day' => (int) $b['hour_of_day'],
                'p50_kw' => (float) $b['p50_kw'],
                'mad_kw' => (float) $b['mad_kw'],
                'p50_kwh' => (float) $b['p50_kwh'],
                'n_samples' => (int) $b['n_samples'],
                'window_start' => $b['window_start'],
                'window_end' => $b['window_end'],
            ];
        }
        return $out;
    }

    /**
     * Robust baselines of the 15-minute peak power while running: median and MAD
     * per day type × hour of day (and per day type over all hours), last 28 days.
     * Once per local day.
     */
    public static function refreshBaselines(int $companyId, ScheduleBook $schedule, int $now, bool $force = false): int
    {
        $time = $schedule->time;
        $today = $time->startOfDay($now);
        $watermark = Database::value('SELECT UNIX_TIMESTAMP(watermark) FROM job_runs WHERE job_key = ? AND company_id = ?', [self::BASELINE_JOB, $companyId]);
        if (!$force && $watermark !== null && (int) $watermark >= $today) {
            return 0;
        }
        $from = $today - self::REFERENCE_DAYS * 86400;
        $groups = [];
        foreach (Database::all(
            "SELECT r.machine_id, UNIX_TIMESTAMP(r.bucket_start) AS t, r.max_kw, r.kwh
               FROM readings_15m r JOIN machines m ON m.id = r.machine_id
              WHERE r.company_id = ? AND m.kind = 'machine' AND r.bucket_start >= FROM_UNIXTIME(?) AND r.bucket_start < FROM_UNIXTIME(?)
                AND r.running_s > 0",
            [$companyId, $from, $today],
        ) as $r) {
            $t = (int) $r['t'];
            $type = self::dayType($t, $schedule);
            $hour = intdiv($time->minuteOfDay($t), 60);
            foreach ([$hour, self::ALL_HOURS] as $h) {
                $groups[(int) $r['machine_id']]["{$type}:{$h}"][] = [(float) $r['max_kw'], (float) $r['kwh']];
            }
        }

        $windowStart = $time->date($from);
        $windowEnd = $time->date($today - 1);
        $rows = 0;
        foreach (Database::all("SELECT id FROM machines WHERE company_id = ? AND kind = 'machine'", [$companyId]) as $m) {
            $machineId = (int) $m['id'];
            Database::run('DELETE FROM machine_baselines WHERE machine_id = ?', [$machineId]);
            foreach ($groups[$machineId] ?? [] as $key => $values) {
                [$type, $hour] = explode(':', $key);
                $peaks = array_column($values, 0);
                $median = self::median($peaks);
                $mad = self::median(array_map(static fn (float $v): float => abs($v - $median), $peaks));
                Database::run(
                    'INSERT INTO machine_baselines (machine_id, day_type, hour_of_day, p50_kw, mad_kw, p50_kwh, n_samples, window_start, window_end)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$machineId, $type, (int) $hour, round($median, 3), round($mad, 3), round(self::median(array_column($values, 1)), 4),
                     min(65535, count($values)), $windowStart, $windowEnd],
                );
                $rows++;
            }
        }

        Database::run(
            "INSERT INTO job_runs (job_key, company_id, watermark, last_started_at, last_finished_at, last_status)
             VALUES (?, ?, FROM_UNIXTIME(?), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'ok')
             ON DUPLICATE KEY UPDATE watermark = VALUES(watermark), last_finished_at = VALUES(last_finished_at), last_status = 'ok'",
            [self::BASELINE_JOB, $companyId, $today],
        );
        return $rows;
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
