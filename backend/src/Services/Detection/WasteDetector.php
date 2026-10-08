<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Detection;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Analytics\EnergyQuery;
use EnergyFlow\Services\Calendar\ScheduleBook;
use EnergyFlow\Services\Carbon\EmissionFactors;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

/**
 * Finds waste episodes in the 15-minute buckets and stores them as quantified
 * waste_events (kWh, € at the marginal tariff of each bucket, CO₂e) with an
 * evidence object the UI shows verbatim under "Why am I seeing this?".
 *
 *   after_hours  Rule: power above the machine's off threshold, outside its
 *                schedule (production overrides count as schedule), for ≥ 30 min.
 *                For compressors, load/unload cycling with no production demand
 *                is reported as an air-leak signature.
 *   idle         Rule: standby (heaters on / unloaded, no cycles) for ≥ 30 min
 *                during production hours, excluding the start-of-shift warm-up.
 *
 * Episodes are re-derived from the buckets, so the same data always gives the
 * same episodes and keys: detection is an idempotent upsert, safe after a
 * fast-forward, a catch-up, or a late batch from a device that was offline.
 */
final class WasteDetector
{
    private const JOB = 'waste_detect';
    private const BUCKET = 900;
    private const LOOKBACK = 3 * 86400;
    private const MIN_BUCKETS = 2;
    private const WARMUP_S = 2700;
    /** Machine types with a genuine standby state (heaters on, or running unloaded). */
    private const STANDBY_TYPES = ['injection_moulding', 'compressor', 'cnc', 'oven'];

    /** @return array{episodes: int, closed_until: ?int} */
    public static function run(int $companyId, int $now): array
    {
        $bounds = Database::one(
            'SELECT UNIX_TIMESTAMP(MIN(bucket_start)) AS first, UNIX_TIMESTAMP(MAX(bucket_start)) AS last FROM readings_15m WHERE company_id = ?',
            [$companyId],
        );
        if ($bounds === null || $bounds['last'] === null) {
            return ['episodes' => 0, 'closed_until' => null];
        }
        $closedEnd = (int) $bounds['last'] + self::BUCKET;
        $watermark = Database::value('SELECT UNIX_TIMESTAMP(watermark) FROM job_runs WHERE job_key = ? AND company_id = ?', [self::JOB, $companyId]);
        if ($watermark !== null && (int) $watermark >= $closedEnd) {
            return ['episodes' => 0, 'closed_until' => $closedEnd];
        }

        $first = (int) $bounds['first'];
        $touchFrom = $watermark === null ? $first : (int) $watermark;
        $from = max($first, $touchFrom - self::LOOKBACK);
        $openSince = Database::value(
            "SELECT UNIX_TIMESTAMP(MIN(started_at)) FROM waste_events WHERE company_id = ? AND ended_at IS NULL AND type IN ('after_hours', 'idle')",
            [$companyId],
        );
        if ($openSince !== null) {
            $from = max($first, min($from, (int) $openSince));
        }

        $ctx = [
            'company_id' => $companyId,
            'now' => $now,
            'closed_end' => $closedEnd,
            'touch_from' => $touchFrom,
            'schedule' => ScheduleBook::forCompany($companyId),
            'tariff' => TariffBook::forCompany($companyId),
            'factor' => EmissionFactors::gridFactor($companyId),
        ];

        $machines = Database::all(
            "SELECT m.id, m.code, m.name, m.type_code, m.schedule_id, m.off_threshold_kw,
                    l.state AS live_state, UNIX_TIMESTAMP(l.ts) AS live_ts
               FROM machines m LEFT JOIN machine_live l ON l.machine_id = m.id
              WHERE m.company_id = ? AND m.kind = 'machine' AND m.criticality <> 'critical'
                AND m.schedule_id IS NOT NULL AND m.archived_at IS NULL",
            [$companyId],
        );

        $episodes = 0;
        foreach ($machines as $m) {
            $m['id'] = (int) $m['id'];
            $m['schedule_id'] = (int) $m['schedule_id'];
            $rows = self::buckets($m['id'], $from, $closedEnd, (float) $m['off_threshold_kw'], $ctx['schedule'], $m['schedule_id']);

            $offKw = (float) $m['off_threshold_kw'];
            $afterHours = static fn (array $b): bool => !$b['scheduled'] && $b['kw'] > $offKw;
            foreach (self::episodes($rows, $afterHours) as $run) {
                $episodes += self::save($ctx, $m, 'after_hours', $run);
            }

            if (in_array($m['type_code'], self::STANDBY_TYPES, true)) {
                $schedule = $ctx['schedule'];
                $idle = static fn (array $b): bool => $b['scheduled'] && $b['run_s'] <= 60 && $b['idle_s'] >= 780
                    && $schedule->isScheduled($m['schedule_id'], $b['t'] + 450 - self::WARMUP_S, $m['id']);
                foreach (self::episodes($rows, $idle) as $run) {
                    $episodes += self::save($ctx, $m, 'idle', $run);
                }
            }
        }

        Database::run(
            "INSERT INTO job_runs (job_key, company_id, watermark, last_started_at, last_finished_at, last_status)
             VALUES (?, ?, FROM_UNIXTIME(?), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'ok')
             ON DUPLICATE KEY UPDATE watermark = VALUES(watermark), last_finished_at = VALUES(last_finished_at), last_status = 'ok'",
            [self::JOB, $companyId, $closedEnd],
        );
        return ['episodes' => $episodes, 'closed_until' => $closedEnd];
    }

    /**
     * Buckets for one machine. If the first loaded bucket already belongs to an
     * after-hours run, earlier days are loaded too, so an episode is never cut
     * (its start is its identity).
     *
     * @return list<array{t: int, kwh: float, kw: float, max_kw: float, run_s: int, idle_s: int, cycles: int, period: string, scheduled: bool}>
     */
    private static function buckets(int $machineId, int $from, int $to, float $offKw, ScheduleBook $schedule, int $scheduleId): array
    {
        $rows = [];
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $chunk = array_map(static fn (array $r): array => [
                't' => (int) $r['t'],
                'kwh' => (float) $r['kwh'],
                'kw' => (float) $r['avg_kw'],
                'max_kw' => (float) $r['max_kw'],
                'run_s' => (int) $r['running_s'],
                'idle_s' => (int) $r['idle_s'],
                'cycles' => (int) $r['cycle_count'],
                'period' => $r['tariff_period'],
                'scheduled' => (bool) $r['is_scheduled'],
            ], Database::all(
                'SELECT UNIX_TIMESTAMP(bucket_start) AS t, kwh, avg_kw, max_kw, running_s, idle_s, cycle_count, tariff_period, is_scheduled
                   FROM readings_15m WHERE machine_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?)
                  ORDER BY bucket_start',
                [$machineId, $from, $to],
            ));
            $rows = [...$chunk, ...$rows];
            $head = $rows[0] ?? null;
            if ($head === null || $head['t'] !== $from || $head['scheduled'] || $head['kw'] <= $offKw) {
                break;
            }
            $to = $from;
            $from -= self::LOOKBACK;
        }
        return $rows;
    }

    /**
     * Maximal runs of consecutive (gap-free) buckets matching $flag, at least MIN_BUCKETS long.
     *
     * @return list<list<array>>
     */
    private static function episodes(array $rows, callable $flag): array
    {
        $runs = [];
        $current = [];
        foreach ($rows as $b) {
            $contiguous = $current !== [] && $b['t'] === $current[count($current) - 1]['t'] + self::BUCKET;
            if ($flag($b)) {
                if (!$contiguous && $current !== []) {
                    $runs[] = $current;
                    $current = [];
                }
                $current[] = $b;
            } elseif ($current !== []) {
                $runs[] = $current;
                $current = [];
            }
        }
        if ($current !== []) {
            $runs[] = $current;
        }
        return array_values(array_filter($runs, static fn (array $run): bool => count($run) >= self::MIN_BUCKETS));
    }

    /** Quantifies one episode and upserts the waste event + its alert. Returns 1 if written. */
    private static function save(array $ctx, array $m, string $type, array $run): int
    {
        $companyId = $ctx['company_id'];
        $now = $ctx['now'];
        $start = $run[0]['t'];
        $lastEnd = $run[count($run) - 1]['t'] + self::BUCKET;
        $live = $m['live_ts'] !== null && $now - (int) $m['live_ts'] <= 120 && in_array($m['live_state'], ['running', 'idle'], true);
        $ongoing = $lastEnd >= $ctx['closed_end'] && $live
            && ($type === 'after_hours'
                ? !$ctx['schedule']->isScheduled($m['schedule_id'], $now, $m['id'])
                : $m['live_state'] === 'idle');
        if ($lastEnd < $ctx['touch_from'] && !$ongoing) {
            return 0; // final before this run and unchanged
        }

        $tariff = $ctx['tariff'];
        $rates = ['high' => $tariff?->energyRate('high', PHP_FLOAT_MAX) ?? 0.0, 'low' => $tariff?->energyRate('low', PHP_FLOAT_MAX) ?? 0.0];
        $kwh = ['high' => 0.0, 'low' => 0.0];
        $runS = $idleS = $cycles = 0;
        $maxKw = 0.0;
        foreach ($run as $b) {
            $kwh[$b['period']] += $b['kwh'];
            $runS += $b['run_s'];
            $idleS += $b['idle_s'];
            $cycles += $b['cycles'];
            $maxKw = max($maxKw, $b['max_kw']);
        }
        $closedHours = count($run) * self::BUCKET / 3600;
        $avgKw = ($kwh['high'] + $kwh['low']) / $closedHours;

        $end = $lastEnd;
        if ($ongoing) {
            $tail = EnergyQuery::byMachine($companyId, $lastEnd, $now, $m['id'], $tariff)[$m['id']] ?? null;
            if ($tail !== null) {
                $kwh['high'] += $tail['kwh_high'];
                $kwh['low'] += $tail['kwh_low'];
            }
            $end = $now;
        }
        $totalKwh = $kwh['high'] + $kwh['low'];
        $eur = $kwh['high'] * $rates['high'] + $kwh['low'] * $rates['low'];
        $co2 = $totalKwh * $ctx['factor']['value'];
        $duration = $end - $start;

        $signals = [];
        if ($type === 'after_hours' && $m['type_code'] === 'compressor' && $runS + $idleS > 0) {
            $loadShare = $runS / ($runS + $idleS);
            $cyclesPerHour = $cycles / $closedHours;
            if ($loadShare >= 0.08 && $cyclesPerHour >= 4) {
                $signals[] = ['key' => 'leak_signature', 'load_share' => round($loadShare, 3), 'cycles_per_hour' => round($cyclesPerHour, 1)];
            }
        }

        $schedule = $ctx['schedule'];
        $scheduleEnd = $type === 'after_hours' ? $schedule->lastScheduledEnd($m['schedule_id'], $start + 1, $m['id']) : null;
        $evidence = [
            'method' => 'rule',
            'rule' => $type,
            'window' => ['from' => self::iso($start), 'to' => self::iso($end), 'closed_until' => self::iso($lastEnd)],
            'schedule_end' => $scheduleEnd === null ? null : self::iso($scheduleEnd),
            'observed' => [
                'avg_kw' => round($avgKw, 2),
                'max_kw' => round($maxKw, 2),
                'hours' => round($duration / 3600, 2),
                'kwh_high' => round($kwh['high'], 2),
                'kwh_low' => round($kwh['low'], 2),
                'load_share' => $runS + $idleS > 0 ? round($runS / ($runS + $idleS), 3) : null,
            ],
            'thresholds' => $type === 'after_hours'
                ? ['off_kw' => (float) $m['off_threshold_kw'], 'min_minutes' => self::MIN_BUCKETS * 15]
                : ['max_running_s' => 60, 'min_standby_s' => 780, 'min_minutes' => self::MIN_BUCKETS * 15, 'warmup_minutes' => self::WARMUP_S / 60],
            'rates' => $rates,
            'factor' => ['value' => $ctx['factor']['value'], 'source' => $ctx['factor']['source_name']],
            'signals' => $signals,
        ];
        if ($ongoing && $type === 'after_hours') {
            // The episode's average power, not one instantaneous reading (a compressor swings between loaded and unloaded).
            $evidence['projection'] = Projection::untilNextStart($schedule, $ctx['tariff'], $ctx['factor']['value'], $m['schedule_id'], $m['id'], $now, $avgKw);
        }

        $severity = $type === 'after_hours' ? SeverityPolicy::afterHours($eur, $duration) : SeverityPolicy::idle($eur);
        $dedupe = "{$type}:m{$m['id']}:{$start}";
        Database::run(
            "INSERT INTO waste_events (company_id, machine_id, dedupe_key, type, started_at, ended_at, energy_kwh, cost_eur, co2_kg,
                                       severity, evidence, action_status, updated_at)
             VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?, 'none', FROM_UNIXTIME(?))
             ON DUPLICATE KEY UPDATE ended_at = VALUES(ended_at), energy_kwh = VALUES(energy_kwh), cost_eur = VALUES(cost_eur),
               co2_kg = VALUES(co2_kg), severity = VALUES(severity), evidence = VALUES(evidence), updated_at = VALUES(updated_at)",
            [$companyId, $m['id'], $dedupe, $type, $start, $ongoing ? null : gmdate('Y-m-d H:i:s', $lastEnd),
             round($totalKwh, 3), round($eur, 2), round($co2, 2), $severity,
             json_encode($evidence, JSON_UNESCAPED_UNICODE), $now],
        );
        $eventId = (int) Database::value('SELECT id FROM waste_events WHERE company_id = ? AND dedupe_key = ?', [$companyId, $dedupe]);

        // Idle below €1 is waste but not worth an alert (docs/03 §7: waste events ≠ alerts).
        if ($type === 'after_hours' || $severity !== 'info') {
            AlertManager::upsert($companyId, [
                'type' => strtoupper($type === 'idle' ? 'idle_waste' : $type),
                'method' => $signals === [] ? 'rule' : 'pattern',
                'severity' => $severity,
                'dedupe_key' => $dedupe,
                'title_key' => $type,
                'params' => [
                    'machine' => $m['name'],
                    'code' => $m['code'],
                    'since' => self::iso($start),
                    'duration_s' => $duration,
                    'kwh' => round($totalKwh, 1),
                    'eur' => round($eur, 2),
                    'co2_kg' => round($co2, 1),
                    'leak' => $signals !== [],
                ],
                'evidence' => ['waste_event_id' => $eventId],
                'machine_id' => $m['id'],
                'waste_event_id' => $eventId,
                'opened_at' => $start + self::MIN_BUCKETS * self::BUCKET,
                'last_seen_at' => $ongoing ? $now : $lastEnd,
                'resolved_at' => $ongoing ? null : $lastEnd,
                'link' => '/waste?event=' . $eventId,
            ], $now);
        }
        return 1;
    }

    private static function iso(int $ts): string
    {
        return Time::iso(gmdate('Y-m-d H:i:s', $ts));
    }
}
