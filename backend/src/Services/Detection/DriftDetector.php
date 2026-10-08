<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Detection;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Carbon\EmissionFactors;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

/**
 * Efficiency drift (CONSUMPTION_DRIFT, method: statistical).
 *
 * Metric: the machine's average power while it is fully running (15-minute
 * buckets with ≥ 800 s running), one value per local day. A worn hydraulic
 * pump or a clogged filter makes the same work cost more power.
 *
 * Reference: the first 28 days with data after commissioning — or after the
 * last maintenance that fixed a drift — so a slow drift cannot hide inside a
 * rolling baseline. A one-sided CUSUM on the standardised daily values
 * (k = 0.5, h = 5) detects a sustained increase and dates its onset. The size
 * of the drift is the last 7 days vs the reference; severity follows
 * docs/03 §7 (warning ≥ 10 %, critical ≥ 25 %).
 *
 * Weather-driven loads (HVAC, chillers, refrigeration) are excluded: their
 * running power changes with the season, which needs degree-day
 * normalisation first.
 */
final class DriftDetector
{
    private const JOB = 'drift_detect';
    public const TYPES = ['injection_moulding', 'compressor', 'pump', 'cnc', 'oven', 'lighting', 'other'];
    private const REFERENCE_DAYS = 28;
    private const MIN_REFERENCE_DAYS = 14;
    private const RECENT_DAYS = 7;
    private const MIN_BUCKETS_PER_DAY = 8;
    private const K = 0.5;
    private const H = 5.0;
    private const MIN_DEVIATION = 0.05;

    public static function run(int $companyId, int $now, bool $force = false): int
    {
        $timezone = (string) (Database::value('SELECT timezone FROM companies WHERE id = ?', [$companyId]) ?? 'Europe/Belgrade');
        $time = new LocalTime($timezone);
        $today = $time->startOfDay($now);
        $watermark = Database::value('SELECT UNIX_TIMESTAMP(watermark) FROM job_runs WHERE job_key = ? AND company_id = ?', [self::JOB, $companyId]);
        if (!$force && $watermark !== null && (int) $watermark >= $today) {
            return 0; // once per local day
        }

        $tariff = TariffBook::forCompany($companyId);
        $factor = EmissionFactors::gridFactor($companyId);
        $written = 0;
        $types = implode(',', array_map(static fn (string $t): string => "'{$t}'", self::TYPES));
        foreach (Database::all(
            "SELECT id, code, name, type_code FROM machines
              WHERE company_id = ? AND kind = 'machine' AND archived_at IS NULL AND type_code IN ({$types})",
            [$companyId],
        ) as $m) {
            $written += self::machine($companyId, $m, $today, $time, $tariff, $factor, $now);
        }

        Database::run(
            "INSERT INTO job_runs (job_key, company_id, watermark, last_started_at, last_finished_at, last_status)
             VALUES (?, ?, FROM_UNIXTIME(?), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'ok')
             ON DUPLICATE KEY UPDATE watermark = VALUES(watermark), last_finished_at = VALUES(last_finished_at), last_status = 'ok'",
            [self::JOB, $companyId, $today],
        );
        return $written;
    }

    /**
     * Daily running-power series for a machine (also used by the machine page and the assistant).
     *
     * @return array<string, array{kw: float, run_h: float, high_share: float, n: int}> local date => values
     */
    public static function dailySeries(int $machineId, int $from, int $to, LocalTime $time): array
    {
        $days = [];
        foreach (Database::all(
            'SELECT UNIX_TIMESTAMP(bucket_start) AS t, avg_kw, running_s, kwh, tariff_period FROM readings_15m
              WHERE machine_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?) AND running_s > 0',
            [$machineId, $from, $to],
        ) as $r) {
            $date = $time->date((int) $r['t']);
            $d = &$days[$date];
            $d ??= ['kw_sum' => 0.0, 'n' => 0, 'run_s' => 0, 'kwh' => 0.0, 'kwh_high' => 0.0];
            $d['run_s'] += (int) $r['running_s'];
            $d['kwh'] += (float) $r['kwh'];
            $d['kwh_high'] += $r['tariff_period'] === 'high' ? (float) $r['kwh'] : 0.0;
            if ((int) $r['running_s'] >= 800) {
                $d['kw_sum'] += (float) $r['avg_kw'];
                $d['n']++;
            }
            unset($d);
        }
        ksort($days);
        $out = [];
        foreach ($days as $date => $d) {
            if ($d['n'] < self::MIN_BUCKETS_PER_DAY) {
                continue;
            }
            $out[$date] = [
                'kw' => $d['kw_sum'] / $d['n'],
                'run_h' => $d['run_s'] / 3600,
                'high_share' => $d['kwh'] > 0 ? $d['kwh_high'] / $d['kwh'] : 1.0,
                'n' => $d['n'],
            ];
        }
        return $out;
    }

    private static function machine(int $companyId, array $m, int $today, LocalTime $time, ?TariffBook $tariff, array $factor, int $now): int
    {
        $id = (int) $m['id'];
        // Re-baseline after maintenance that addressed a drift on this machine.
        $since = Database::value(
            "SELECT UNIX_TIMESTAMP(MAX(COALESCE(updated_at, accepted_at))) FROM recommendations
              WHERE company_id = ? AND machine_id = ? AND generator = 'EfficiencyDrift' AND status IN ('implemented', 'verified')",
            [$companyId, $id],
        );
        $first = Database::value('SELECT UNIX_TIMESTAMP(MIN(bucket_start)) FROM readings_15m WHERE machine_id = ?', [$id]);
        if ($first === null) {
            return 0;
        }
        $from = max((int) $first, (int) ($since ?? 0));
        $series = self::dailySeries($id, $from, $today, $time);
        $dates = array_keys($series);
        if (count($dates) < self::MIN_REFERENCE_DAYS + self::RECENT_DAYS) {
            return self::close($companyId, $id, $today);
        }

        $reference = array_slice($series, 0, min(self::REFERENCE_DAYS, count($dates) - self::RECENT_DAYS), true);
        $values = array_column($reference, 'kw');
        $mean = array_sum($values) / count($values);
        $sd = sqrt(array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $values)) / max(1, count($values) - 1));
        $sigma = max($sd, 0.01 * $mean);

        // One-sided CUSUM over the days after the reference.
        $s = 0.0;
        $onset = null;
        $alarm = null;
        $after = array_slice($series, count($reference), null, true);
        foreach ($after as $date => $d) {
            $s = max(0.0, $s + ($d['kw'] - $mean) / $sigma - self::K);
            if ($s === 0.0) {
                if ($alarm === null) {
                    $onset = null; // the rise was noise: restart
                }
            } elseif ($onset === null) {
                $onset = $date;
            }
            if ($alarm === null && $s > self::H) {
                $alarm = $date;
            }
        }
        $recent = array_slice(array_column($series, 'kw'), -self::RECENT_DAYS);
        $recentMean = array_sum($recent) / count($recent);
        $deviation = $recentMean / $mean - 1;

        if ($alarm === null || $s === 0.0 || $deviation < self::MIN_DEVIATION) {
            return self::close($companyId, $id, $today);
        }

        // Excess energy since the onset: (running power − reference) × running hours, priced per day's tariff mix.
        $rateHigh = $tariff?->energyRate('high', PHP_FLOAT_MAX) ?? 0.0;
        $rateLow = $tariff?->energyRate('low', PHP_FLOAT_MAX) ?? 0.0;
        $excessKwh = 0.0;
        $excessEur = 0.0;
        $excessDaily = [];
        foreach ($after as $date => $d) {
            if ($date < $onset) {
                continue;
            }
            $excess = max(0.0, $d['kw'] - $mean) * $d['run_h'];
            $eur = $excess * ($d['high_share'] * $rateHigh + (1 - $d['high_share']) * $rateLow);
            $excessKwh += $excess;
            $excessEur += $eur;
            $excessDaily[$date] = [round($excess, 3), round($eur, 3)];
        }

        $onsetTs = $time->at($onset);
        $severity = SeverityPolicy::drift($deviation);
        $referenceDates = array_keys($reference);
        $chartFrom = max(0, count($dates) - 60);
        $evidence = [
            'method' => 'statistical',
            'rule' => 'cusum',
            'metric' => 'running_power_kw',
            'reference' => [
                'from' => $referenceDates[0],
                'to' => $referenceDates[count($referenceDates) - 1],
                'days' => count($referenceDates),
                'mean_kw' => round($mean, 3),
                'sd_kw' => round($sd, 3),
            ],
            'recent' => ['days' => self::RECENT_DAYS, 'mean_kw' => round($recentMean, 3)],
            'deviation_pct' => round($deviation * 100, 1),
            'cusum' => ['k' => self::K, 'h' => self::H, 'onset' => $onset, 'alarm' => $alarm, 'value' => round($s, 1)],
            'thresholds' => ['warning_pct' => 10, 'critical_pct' => 25, 'min_pct' => self::MIN_DEVIATION * 100],
            'daily' => array_map(
                static fn (string $date, array $d): array => ['d' => $date, 'kw' => round($d['kw'], 3)],
                array_slice($dates, $chartFrom),
                array_slice(array_values($series), $chartFrom),
            ),
            'excess_daily' => $excessDaily,
            'rates' => ['high' => $rateHigh, 'low' => $rateLow],
            'factor' => ['value' => $factor['value'], 'source' => $factor['source_name']],
        ];

        $dedupe = "drift:m{$id}:{$onset}";
        Database::run(
            "INSERT INTO waste_events (company_id, machine_id, dedupe_key, type, started_at, ended_at, energy_kwh, cost_eur, co2_kg,
                                       severity, evidence, action_status, updated_at)
             VALUES (?, ?, ?, 'excess_vs_baseline', FROM_UNIXTIME(?), NULL, ?, ?, ?, ?, ?, 'none', FROM_UNIXTIME(?))
             ON DUPLICATE KEY UPDATE ended_at = NULL, energy_kwh = VALUES(energy_kwh), cost_eur = VALUES(cost_eur), co2_kg = VALUES(co2_kg),
               severity = VALUES(severity), evidence = VALUES(evidence), updated_at = VALUES(updated_at)",
            [$companyId, $id, $dedupe, $onsetTs, round($excessKwh, 3), round($excessEur, 2), round($excessKwh * $factor['value'], 2),
             $severity, json_encode($evidence, JSON_UNESCAPED_UNICODE), $now],
        );
        $eventId = (int) Database::value('SELECT id FROM waste_events WHERE company_id = ? AND dedupe_key = ?', [$companyId, $dedupe]);
        // An older drift episode on the same machine (different onset) is superseded.
        Database::run(
            "UPDATE waste_events SET ended_at = FROM_UNIXTIME(?) WHERE company_id = ? AND machine_id = ? AND type = 'excess_vs_baseline'
                AND ended_at IS NULL AND dedupe_key <> ?",
            [$onsetTs, $companyId, $id, $dedupe],
        );

        if ($severity !== 'info') {
            AlertManager::upsert($companyId, [
                'type' => 'CONSUMPTION_DRIFT',
                'method' => 'statistical',
                'severity' => $severity,
                'dedupe_key' => $dedupe,
                'title_key' => 'drift',
                'params' => [
                    'machine' => $m['name'],
                    'code' => $m['code'],
                    'deviation_pct' => round($deviation * 100, 1),
                    'since' => Time::iso(gmdate('Y-m-d H:i:s', $onsetTs)),
                    'kwh' => round($excessKwh, 1),
                    'eur' => round($excessEur, 2),
                ],
                'evidence' => ['waste_event_id' => $eventId],
                'machine_id' => $id,
                'waste_event_id' => $eventId,
                'opened_at' => $time->at($alarm),
                'last_seen_at' => $now,
                'link' => '/waste?event=' . $eventId,
            ], $now);
        }
        return 1;
    }

    /** No (more) drift: end the open episode and resolve its alert. */
    private static function close(int $companyId, int $machineId, int $at): int
    {
        foreach (Database::all(
            "SELECT dedupe_key FROM waste_events WHERE company_id = ? AND machine_id = ? AND type = 'excess_vs_baseline' AND ended_at IS NULL",
            [$companyId, $machineId],
        ) as $open) {
            Database::run('UPDATE waste_events SET ended_at = FROM_UNIXTIME(?) WHERE company_id = ? AND dedupe_key = ?', [$at, $companyId, $open['dedupe_key']]);
            AlertManager::resolve($companyId, $open['dedupe_key'], $at);
        }
        return 0;
    }
}
