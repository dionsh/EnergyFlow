<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Impact;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Calendar\ScheduleBook;
use EnergyFlow\Services\Carbon\EmissionFactors;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Notifications\NotificationService;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

/**
 * Before/after proof per intervention (docs/03-architecture.md §11),
 * "IPMVP-inspired, Option B (sub-metered)" — never claimed as certified M&V.
 *
 *   Intervention   an automation policy, or maintenance marked as done (T₀)
 *   Baseline       the 28 days before T₀
 *   Model          mean daily kWh per day type, where the day type is the machine's
 *                  scheduled hours that day (e.g. 14 h weekday, 6 h Saturday, closed)
 *   Adjusted       the model applied to the reporting days (T₀+1 → yesterday), so a
 *   baseline       week with a holiday is compared fairly
 *   Savings        adjusted baseline − actual, ± 1.645 · σ_residual · √n (90 %)
 *   Verified       at least 7 reporting days and the lower bound above zero
 *
 * € at the baseline's day/night mix of marginal rates, CO₂e at the grid factor.
 */
final class ImpactService
{
    private const JOB = 'impact';
    public const BASELINE_DAYS = 28;
    public const MIN_REPORTING_DAYS = 7;
    private const MIN_BASELINE_DAYS = 14;
    private const Z90 = 1.645;

    /** Recomputes every intervention (once per local day unless forced). */
    public static function run(int $companyId, int $now, bool $force = false): int
    {
        $time = LocalTime::forCompany($companyId);
        $today = $time->startOfDay($now);
        $watermark = Database::value('SELECT UNIX_TIMESTAMP(watermark) FROM job_runs WHERE job_key = ? AND company_id = ?', [self::JOB, $companyId]);
        if (!$force && $watermark !== null && (int) $watermark >= $today) {
            return 0;
        }
        $context = self::context($companyId, $time);
        $written = 0;
        foreach (self::interventions($companyId) as $iv) {
            $result = self::compute($companyId, $iv, $today, $context);
            if ($result !== null) {
                self::store($companyId, $iv, $result, $now);
                $written++;
            }
        }
        Database::run(
            "INSERT INTO job_runs (job_key, company_id, watermark, last_started_at, last_finished_at, last_status)
             VALUES (?, ?, FROM_UNIXTIME(?), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'ok')
             ON DUPLICATE KEY UPDATE watermark = VALUES(watermark), last_finished_at = VALUES(last_finished_at), last_status = 'ok'",
            [self::JOB, $companyId, $today],
        );
        return $written;
    }

    /** Company before/after plus every intervention with its proof. */
    public static function summary(int $companyId): array
    {
        $rows = self::rows($companyId);
        $totals = ['baseline_kwh' => 0.0, 'actual_kwh' => 0.0, 'savings_kwh' => 0.0, 'ci_sq' => 0.0, 'savings_eur' => 0.0, 'savings_co2_kg' => 0.0,
                   'raw_baseline_kwh' => 0.0, 'raw_baseline_eur' => 0.0, 'baseline_eur' => 0.0, 'actual_eur' => 0.0, 'verified_eur' => 0.0, 'verified_kwh' => 0.0, 'verified_co2_kg' => 0.0];
        foreach ($rows as $r) {
            if ($r['reporting_days'] === 0) {
                continue;
            }
            $rate = $r['details']['rate_eur_kwh'] ?? 0.0;
            $totals['baseline_kwh'] += $r['adjusted_baseline_kwh'];
            $totals['actual_kwh'] += $r['actual_kwh'];
            $totals['savings_kwh'] += $r['savings_kwh'];
            $totals['ci_sq'] += $r['savings_ci90_kwh'] ** 2;
            $totals['savings_eur'] += $r['savings_eur'];
            $totals['savings_co2_kg'] += $r['savings_co2_kg'];
            $rawBaseline = ($r['details']['raw']['baseline_kwh_per_day'] ?? 0) * $r['reporting_days'];
            $totals['raw_baseline_kwh'] += $rawBaseline;
            $totals['raw_baseline_eur'] += $rawBaseline * $rate;
            $totals['baseline_eur'] += $r['adjusted_baseline_kwh'] * $rate;
            $totals['actual_eur'] += $r['actual_kwh'] * $rate;
            if ($r['is_verified']) {
                $totals['verified_eur'] += $r['savings_eur'];
                $totals['verified_kwh'] += $r['savings_kwh'];
                $totals['verified_co2_kg'] += $r['savings_co2_kg'];
            }
        }
        $factor = EmissionFactors::gridFactor($companyId);
        $rawSavings = $totals['raw_baseline_kwh'] - $totals['actual_kwh'];
        return [
            'adjusted' => [
                'before' => ['kwh' => round($totals['baseline_kwh'], 1), 'eur' => round($totals['baseline_eur'], 2), 'co2_kg' => round($totals['baseline_kwh'] * $factor['value'], 1)],
                'after' => ['kwh' => round($totals['actual_kwh'], 1), 'eur' => round($totals['actual_eur'], 2), 'co2_kg' => round($totals['actual_kwh'] * $factor['value'], 1)],
                'savings' => ['kwh' => round($totals['savings_kwh'], 1), 'eur' => round($totals['savings_eur'], 2), 'co2_kg' => round($totals['savings_co2_kg'], 1),
                              'ci90_kwh' => round(sqrt($totals['ci_sq']), 1),
                              'share' => $totals['baseline_kwh'] > 0 ? round($totals['savings_kwh'] / $totals['baseline_kwh'], 4) : null],
            ],
            'raw' => [
                'before' => ['kwh' => round($totals['raw_baseline_kwh'], 1), 'eur' => round($totals['raw_baseline_eur'], 2), 'co2_kg' => round($totals['raw_baseline_kwh'] * $factor['value'], 1)],
                'after' => ['kwh' => round($totals['actual_kwh'], 1), 'eur' => round($totals['actual_eur'], 2), 'co2_kg' => round($totals['actual_kwh'] * $factor['value'], 1)],
                'savings' => ['kwh' => round($rawSavings, 1), 'eur' => round($totals['raw_baseline_eur'] - $totals['actual_eur'], 2), 'co2_kg' => round($rawSavings * $factor['value'], 1),
                              'share' => $totals['raw_baseline_kwh'] > 0 ? round($rawSavings / $totals['raw_baseline_kwh'], 4) : null],
            ],
            'verified' => ['kwh' => round($totals['verified_kwh'], 1), 'eur' => round($totals['verified_eur'], 2), 'co2_kg' => round($totals['verified_co2_kg'], 1)],
            'interventions' => array_map(static fn (array $r): array => self::presentRow($r, false), $rows),
            'method' => ['name' => 'ipmvp_b_daytype', 'baseline_days' => self::BASELINE_DAYS, 'min_reporting_days' => self::MIN_REPORTING_DAYS, 'confidence' => 0.9],
            'factor' => ['value' => $factor['value'], 'source' => $factor['source_name']],
        ];
    }

    public static function detail(int $companyId, int $id): array
    {
        foreach (self::rows($companyId) as $r) {
            if ($r['id'] === $id) {
                return self::presentRow($r, true);
            }
        }
        throw HttpException::notFound('intervention_not_found', 'Intervention not found.');
    }

    /** @return list<array{key: string, kind: string, policy_id: ?int, recommendation_id: ?int, machine_id: int, t0: int, label: string}> */
    public static function interventions(int $companyId): array
    {
        $out = [];
        foreach (Database::all(
            'SELECT p.id, p.machine_id, p.type, p.recommendation_id, UNIX_TIMESTAMP(p.effective_from) AS t0 FROM automation_policies p WHERE p.company_id = ? ORDER BY p.effective_from',
            [$companyId],
        ) as $p) {
            $out[] = ['key' => 'policy:' . $p['id'], 'kind' => 'policy', 'policy_id' => (int) $p['id'], 'recommendation_id' => $p['recommendation_id'] === null ? null : (int) $p['recommendation_id'],
                      'machine_id' => (int) $p['machine_id'], 't0' => (int) $p['t0'], 'label' => $p['type']];
        }
        foreach (Database::all(
            "SELECT r.id, r.machine_id, r.generator,
                    (SELECT UNIX_TIMESTAMP(MIN(a.created_at)) FROM audit_log a WHERE a.company_id = r.company_id AND a.action = 'recommendation.implemented' AND a.entity_id = r.id) AS t0
               FROM recommendations r
              WHERE r.company_id = ? AND r.status IN ('implemented', 'verified') AND r.machine_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM automation_policies p WHERE p.recommendation_id = r.id)",
            [$companyId],
        ) as $r) {
            if ($r['t0'] !== null) {
                $out[] = ['key' => 'rec:' . $r['id'], 'kind' => 'maintenance', 'policy_id' => null, 'recommendation_id' => (int) $r['id'],
                          'machine_id' => (int) $r['machine_id'], 't0' => (int) $r['t0'], 'label' => $r['generator']];
            }
        }
        return $out;
    }

    private static function context(int $companyId, LocalTime $time): array
    {
        $tariff = TariffBook::forCompany($companyId);
        return [
            'time' => $time,
            'schedule' => ScheduleBook::forCompany($companyId),
            'rates' => ['high' => $tariff?->energyRate('high', PHP_FLOAT_MAX) ?? 0.0, 'low' => $tariff?->energyRate('low', PHP_FLOAT_MAX) ?? 0.0],
            'factor' => EmissionFactors::gridFactor($companyId)['value'],
        ];
    }

    /** The proof for one intervention, from complete local days only. */
    private static function compute(int $companyId, array $iv, int $today, array $ctx): ?array
    {
        /** @var LocalTime $time */
        $time = $ctx['time'];
        $machine = Database::one('SELECT id, schedule_id FROM machines WHERE id = ? AND company_id = ?', [$iv['machine_id'], $companyId]);
        if ($machine === null) {
            return null;
        }
        $t0Day = $time->startOfDay($iv['t0']);
        $baseFrom = $time->startOfDay($t0Day - self::BASELINE_DAYS * 86400 + 43200);
        $reportFrom = $time->startOfDay($t0Day + 36 * 3600); // the first complete day after T₀

        $days = [];
        foreach (Database::all(
            "SELECT UNIX_TIMESTAMP(bucket_start) AS t, kwh, tariff_period FROM readings_15m
              WHERE machine_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?)",
            [$iv['machine_id'], $baseFrom, $today],
        ) as $b) {
            $date = $time->date((int) $b['t']);
            $days[$date] ??= ['kwh' => 0.0, 'high' => 0.0];
            $days[$date]['kwh'] += (float) $b['kwh'];
            $days[$date]['high'] += $b['tariff_period'] === 'high' ? (float) $b['kwh'] : 0.0;
        }
        ksort($days);

        $t0Date = $time->date($t0Day);
        $reportDate = $time->date($reportFrom);
        $scheduleId = $machine['schedule_id'] === null ? null : (int) $machine['schedule_id'];
        $baseline = [];
        $reporting = [];
        foreach ($days as $date => $d) {
            $type = self::dayType($ctx['schedule'], $time, $scheduleId, $iv['machine_id'], (string) $date);
            if ($date < $t0Date) {
                $baseline[] = ['date' => $date, 'kwh' => $d['kwh'], 'high' => $d['high'], 'type' => $type];
            } elseif ($date >= $reportDate) {
                $reporting[] = ['date' => $date, 'kwh' => $d['kwh'], 'type' => $type];
            }
        }
        if (count($baseline) < self::MIN_BASELINE_DAYS) {
            return null;
        }

        // Day-type means and the residual spread of the baseline around them.
        $byType = [];
        foreach ($baseline as $d) {
            $byType[$d['type']][] = $d['kwh'];
        }
        $means = array_map(static fn (array $v): float => array_sum($v) / count($v), $byType);
        $overall = array_sum(array_column($baseline, 'kwh')) / count($baseline);
        $ss = 0.0;
        foreach ($baseline as $d) {
            $ss += ($d['kwh'] - $means[$d['type']]) ** 2;
        }
        $sigma = sqrt($ss / max(1, count($baseline) - count($means)));

        $adjusted = 0.0;
        $actual = 0.0;
        $series = [];
        foreach ($baseline as $d) {
            $series[] = ['d' => $d['date'], 'actual' => round($d['kwh'], 2), 'model' => round($means[$d['type']], 2), 'phase' => 'baseline'];
        }
        foreach ($reporting as $d) {
            $model = $means[$d['type']] ?? $overall;
            $adjusted += $model;
            $actual += $d['kwh'];
            $series[] = ['d' => $d['date'], 'actual' => round($d['kwh'], 2), 'model' => round($model, 2), 'phase' => 'reporting'];
        }
        $n = count($reporting);
        $savings = $adjusted - $actual;
        $ci = self::Z90 * $sigma * sqrt(max(1, $n));
        $baselineKwh = array_sum(array_column($baseline, 'kwh'));
        $highShare = $baselineKwh > 0 ? array_sum(array_column($baseline, 'high')) / $baselineKwh : 1.0;
        $rate = $highShare * $ctx['rates']['high'] + (1 - $highShare) * $ctx['rates']['low'];

        return [
            'baseline_start' => $baseline[0]['date'],
            'baseline_end' => $baseline[count($baseline) - 1]['date'],
            'reporting_start' => $reportDate,
            'reporting_end' => $n > 0 ? $reporting[$n - 1]['date'] : $reportDate,
            'reporting_days' => $n,
            'adjusted' => $adjusted,
            'actual' => $actual,
            'savings' => $savings,
            'ci' => $n > 0 ? $ci : 0.0,
            'eur' => $savings * $rate,
            'co2' => $savings * $ctx['factor'],
            'verified' => $n >= self::MIN_REPORTING_DAYS && $savings - $ci > 0,
            'details' => [
                'method' => 'ipmvp_b_daytype',
                'day_types' => array_map(static fn (string $type, float $mean): array => ['type' => $type, 'mean_kwh' => round($mean, 2), 'days' => count($byType[$type])], array_keys($means), array_values($means)),
                'sigma_kwh' => round($sigma, 3),
                'rate_eur_kwh' => round($rate, 5),
                'high_share' => round($highShare, 3),
                'raw' => [
                    'baseline_kwh_per_day' => round($baselineKwh / count($baseline), 3),
                    'reporting_kwh_per_day' => $n > 0 ? round($actual / $n, 3) : null,
                ],
                'daily' => $series,
            ],
        ];
    }

    /** A day's type = the machine's scheduled hours that day ("h14", "h6", "h0"). */
    private static function dayType(ScheduleBook $schedule, LocalTime $time, ?int $scheduleId, int $machineId, string $date): string
    {
        $hours = 0;
        $start = $time->at($date);
        for ($h = 0; $h < 24; $h++) {
            $hours += $schedule->isScheduled($scheduleId, $start + $h * 3600 + 1800, $machineId) ? 1 : 0;
        }
        return 'h' . $hours;
    }

    private static function store(int $companyId, array $iv, array $r, int $now): void
    {
        $previous = Database::value('SELECT is_verified FROM impact_verifications WHERE company_id = ? AND intervention_key = ?', [$companyId, $iv['key']]);
        Database::run(
            'INSERT INTO impact_verifications (company_id, intervention_key, policy_id, recommendation_id, machine_id, method, baseline_start, baseline_end,
                                               reporting_start, reporting_end, reporting_days, adjusted_baseline_kwh, actual_kwh, savings_kwh, savings_ci90_kwh,
                                               savings_eur, savings_co2_kg, is_verified, details, computed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))
             ON DUPLICATE KEY UPDATE baseline_start = VALUES(baseline_start), baseline_end = VALUES(baseline_end), reporting_start = VALUES(reporting_start),
               reporting_end = VALUES(reporting_end), reporting_days = VALUES(reporting_days), adjusted_baseline_kwh = VALUES(adjusted_baseline_kwh),
               actual_kwh = VALUES(actual_kwh), savings_kwh = VALUES(savings_kwh), savings_ci90_kwh = VALUES(savings_ci90_kwh), savings_eur = VALUES(savings_eur),
               savings_co2_kg = VALUES(savings_co2_kg), is_verified = VALUES(is_verified), details = VALUES(details), computed_at = VALUES(computed_at)',
            [$companyId, $iv['key'], $iv['policy_id'], $iv['recommendation_id'], $iv['machine_id'], 'ipmvp_b_daytype', $r['baseline_start'], $r['baseline_end'],
             $r['reporting_start'], $r['reporting_end'], $r['reporting_days'], round($r['adjusted'], 2), round($r['actual'], 2), round($r['savings'], 2),
             round($r['ci'], 2), round($r['eur'], 2), round($r['co2'], 2), $r['verified'] ? 1 : 0, json_encode($r['details']), $now],
        );
        if ($r['verified'] && $iv['recommendation_id'] !== null) {
            Database::run("UPDATE recommendations SET status = 'verified' WHERE id = ? AND status = 'implemented'", [$iv['recommendation_id']]);
        }
        if ($r['verified'] && (int) ($previous ?? 0) === 0) {
            $code = Database::value('SELECT code FROM machines WHERE id = ?', [$iv['machine_id']]);
            NotificationService::push($companyId, 'achievement', 'impact.verified', [
                'code' => $code, 'kwh' => round($r['savings'], 1), 'eur' => round($r['eur'], 2), 'days' => $r['reporting_days'],
            ], '/impact', 'impact', null, $now);
        }
    }

    /** @return list<array> stored verifications with their machine */
    private static function rows(int $companyId): array
    {
        return array_map(static function (array $r): array {
            foreach (['adjusted_baseline_kwh', 'actual_kwh', 'savings_kwh', 'savings_ci90_kwh', 'savings_eur', 'savings_co2_kg'] as $k) {
                $r[$k] = (float) $r[$k];
            }
            $r['id'] = (int) $r['id'];
            $r['reporting_days'] = (int) $r['reporting_days'];
            $r['is_verified'] = (bool) $r['is_verified'];
            $r['details'] = json_decode((string) $r['details'], true) ?: [];
            return $r;
        }, Database::all(
            "SELECT i.*, m.code, m.name, m.type_code, p.type AS policy_type, p.params AS policy_params, UNIX_TIMESTAMP(p.effective_from) AS policy_from, p.is_active,
                    r.generator, r.title_key, r.params AS rec_params, r.status AS rec_status
               FROM impact_verifications i
               JOIN machines m ON m.id = i.machine_id
               LEFT JOIN automation_policies p ON p.id = i.policy_id
               LEFT JOIN recommendations r ON r.id = i.recommendation_id
              WHERE i.company_id = ? AND i.intervention_key IS NOT NULL
              ORDER BY i.is_verified DESC, i.savings_eur DESC",
            [$companyId],
        ));
    }

    private static function presentRow(array $r, bool $withSeries): array
    {
        $out = [
            'id' => $r['id'],
            'key' => $r['intervention_key'],
            'kind' => $r['policy_id'] !== null ? 'policy' : 'maintenance',
            'label' => $r['policy_type'] ?? $r['generator'],
            'policy_id' => $r['policy_id'] === null ? null : (int) $r['policy_id'],
            'policy_params' => $r['policy_params'] === null ? null : (json_decode((string) $r['policy_params'], true) ?: []),
            'recommendation' => $r['recommendation_id'] === null ? null : [
                'id' => (int) $r['recommendation_id'], 'title_key' => $r['title_key'], 'params' => json_decode((string) $r['rec_params'], true) ?: [], 'status' => $r['rec_status'],
            ],
            'machine' => ['id' => (int) $r['machine_id'], 'code' => $r['code'], 'name' => $r['name'], 'type' => $r['type_code']],
            'since' => $r['policy_from'] !== null ? Time::iso(gmdate('Y-m-d H:i:s', (int) $r['policy_from'])) : $r['reporting_start'],
            'baseline' => ['from' => $r['baseline_start'], 'to' => $r['baseline_end']],
            'reporting' => ['from' => $r['reporting_start'], 'to' => $r['reporting_end'], 'days' => $r['reporting_days']],
            'adjusted_baseline_kwh' => $r['adjusted_baseline_kwh'],
            'actual_kwh' => $r['actual_kwh'],
            'savings' => ['kwh' => $r['savings_kwh'], 'ci90_kwh' => $r['savings_ci90_kwh'], 'eur' => $r['savings_eur'], 'co2_kg' => $r['savings_co2_kg'],
                          'share' => $r['adjusted_baseline_kwh'] > 0 ? round($r['savings_kwh'] / $r['adjusted_baseline_kwh'], 4) : null],
            'verified' => $r['is_verified'],
            'collecting' => !$r['is_verified'] && $r['reporting_days'] < self::MIN_REPORTING_DAYS,
            'method' => $r['method'],
            'model' => ['day_types' => $r['details']['day_types'] ?? [], 'sigma_kwh' => $r['details']['sigma_kwh'] ?? null, 'raw' => $r['details']['raw'] ?? null],
            'computed_at' => Time::iso($r['computed_at']),
        ];
        if ($withSeries) {
            $out['daily'] = $r['details']['daily'] ?? [];
        }
        return $out;
    }
}
