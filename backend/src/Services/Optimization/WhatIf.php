<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Optimization;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Calendar\ScheduleBook;
use EnergyFlow\Services\Carbon\EmissionFactors;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Detection\DriftDetector;
use EnergyFlow\Services\Tariff\TariffBook;

/**
 * What-if: replays this machine's own last N days of 15-minute buckets under
 * a proposed change and compares kWh, € (at the marginal tariff of each bucket)
 * and CO₂e (docs/03-architecture.md §10.2). Nothing is assumed that the data
 * can tell us; what can't be measured is an explicit, editable assumption.
 *
 *   auto_off_after_schedule  buckets after schedule end + grace become "off"
 *   leak_repair              compressors: the measured leak load (load share with
 *                            no production) is cut by the repaired fraction, using
 *                            P = unloaded + (loaded − unloaded) × load share
 *   efficiency_restore       running energy back at the reference running power
 *   tou_shift                part of the day-tariff energy moves to the night
 *                            tariff: saves € but not CO₂ (the grid factor is an
 *                            annual average, docs/01-research.md §4.1)
 */
final class WhatIf
{
    public const ACTIONS = ['auto_off_after_schedule', 'leak_repair', 'efficiency_restore', 'tou_shift'];
    private const BUCKET = 900;

    /** Which actions make sense for a machine (the UI offers only these). */
    public static function actionsFor(array $machine): array
    {
        if ($machine['kind'] === 'incomer' || $machine['criticality'] === 'critical') {
            return [];
        }
        $actions = [];
        if ($machine['schedule_id'] !== null) {
            $actions[] = 'auto_off_after_schedule';
        }
        if ($machine['type_code'] === 'compressor') {
            $actions[] = 'leak_repair';
        }
        if (in_array($machine['type_code'], DriftDetector::TYPES, true)) {
            $actions[] = 'efficiency_restore';
        }
        if ($machine['criticality'] === 'flexible') {
            $actions[] = 'tou_shift';
        }
        return $actions;
    }

    /**
     * @param array{action: string, machine_id: int, params?: array, window_days?: int} $input
     */
    public static function run(int $companyId, array $input): array
    {
        $machine = Database::one(
            "SELECT id, kind, code, name, type_code, schedule_id, criticality, off_threshold_kw FROM machines
              WHERE id = ? AND company_id = ? AND archived_at IS NULL",
            [(int) $input['machine_id'], $companyId],
        ) ?? throw HttpException::notFound('machine_not_found', 'Machine not found.');
        $action = $input['action'];
        if (!in_array($action, self::actionsFor($machine), true)) {
            throw HttpException::conflict('action_not_applicable', 'This change does not apply to this machine.');
        }

        $time = LocalTime::forCompany($companyId);
        $now = Clock::now($companyId);
        $days = max(7, min(90, (int) ($input['window_days'] ?? 30)));
        $to = $time->startOfDay($now);
        $from = $time->startOfDay($to - $days * 86400 + 43200);
        $buckets = Database::all(
            'SELECT UNIX_TIMESTAMP(bucket_start) AS t, kwh, avg_kw, max_kw, min_kw, running_s, idle_s, tariff_period, is_scheduled
               FROM readings_15m WHERE machine_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?)
              ORDER BY bucket_start',
            [(int) $machine['id'], $from, $to],
        );
        if (count($buckets) < 96) {
            throw HttpException::conflict('not_enough_data', 'There is not enough history for this machine yet.');
        }

        $tariff = TariffBook::forCompany($companyId);
        $rates = ['high' => $tariff?->energyRate('high', PHP_FLOAT_MAX) ?? 0.0, 'low' => $tariff?->energyRate('low', PHP_FLOAT_MAX) ?? 0.0];
        $factor = EmissionFactors::gridFactor($companyId);
        $params = $input['params'] ?? [];

        [$scenario, $effective, $assumptions] = match ($action) {
            'auto_off_after_schedule' => self::autoOff($machine, $buckets, $params, ScheduleBook::forCompany($companyId)),
            'leak_repair' => self::leakRepair($buckets, $params),
            'efficiency_restore' => self::efficiency($companyId, $machine, $buckets, $params),
            'tou_shift' => self::touShift($buckets, $params),
        };

        // Totals and the per-day replay.
        $base = ['kwh' => 0.0, 'eur' => 0.0];
        $after = ['kwh' => 0.0, 'eur' => 0.0];
        $daily = [];
        foreach ($buckets as $i => $b) {
            $s = $scenario[$i];
            $kwh = (float) $b['kwh'];
            $base['kwh'] += $kwh;
            $base['eur'] += $kwh * $rates[$b['tariff_period']];
            $after['kwh'] += $s['kwh'];
            $after['eur'] += $s['kwh_high'] * $rates['high'] + $s['kwh_low'] * $rates['low'];
            $date = $time->date((int) $b['t']);
            $daily[$date] ??= ['date' => $date, 'baseline_kwh' => 0.0, 'scenario_kwh' => 0.0];
            $daily[$date]['baseline_kwh'] += $kwh;
            $daily[$date]['scenario_kwh'] += $s['kwh'];
        }
        $co2Factor = $factor['value'];
        $totals = static fn (array $x): array => ['kwh' => round($x['kwh'], 1), 'eur' => round($x['eur'], 2), 'co2_kg' => round($x['kwh'] * $co2Factor, 1)];
        $delta = ['kwh' => $base['kwh'] - $after['kwh'], 'eur' => $base['eur'] - $after['eur']];
        $scale = static fn (float $k): array => [
            'kwh' => round($delta['kwh'] * $k, 1),
            'eur' => round($delta['eur'] * $k, 2),
            'co2_kg' => round($delta['kwh'] * $k * $co2Factor, 1),
        ];
        $caveats = [];
        if ($action === 'tou_shift') {
            $caveats[] = 'tou_no_co2';
        }
        if (in_array($machine['type_code'], ['hvac', 'chiller'], true)) {
            $caveats[] = 'seasonal';
        }

        return [
            'machine' => ['id' => (int) $machine['id'], 'code' => $machine['code'], 'name' => $machine['name'], 'type' => $machine['type_code']],
            'action' => $action,
            'params' => $effective,
            'window' => ['days' => $days, 'from' => $time->date($from), 'to' => $time->date($to - 1)],
            'baseline' => $totals($base),
            'scenario' => $totals($after),
            'delta' => $totals($delta) + ['share' => $base['kwh'] > 0 ? round($delta['kwh'] / $base['kwh'], 4) : 0.0],
            'per_month' => $scale(30 / $days),
            'annualized' => $scale(365 / $days),
            'impact_tag' => $action === 'tou_shift' ? 'eur' : 'eur_co2',
            'daily' => array_values(array_map(static fn (array $d): array => [
                'date' => $d['date'], 'baseline_kwh' => round($d['baseline_kwh'], 2), 'scenario_kwh' => round($d['scenario_kwh'], 2),
            ], $daily)),
            'assumptions' => $assumptions,
            'caveats' => $caveats,
            'rates' => $rates,
            'factor' => ['value' => $co2Factor, 'source' => $factor['source_name']],
        ];
    }

    /** Buckets that start `grace` after the schedule ended become off; the grace itself still runs. */
    private static function autoOff(array $m, array $buckets, array $params, ScheduleBook $schedule): array
    {
        $grace = 60 * max(0, min(120, (int) ($params['grace_min'] ?? 15)));
        $offKw = (float) $m['off_threshold_kw'];
        $scheduleId = (int) $m['schedule_id'];
        $lastEnd = $schedule->lastScheduledEnd($scheduleId, (int) $buckets[0]['t'], (int) $m['id']) ?? PHP_INT_MIN;
        $out = [];
        foreach ($buckets as $b) {
            $t = (int) $b['t'];
            $kwh = (float) $b['kwh'];
            if ($b['is_scheduled']) {
                $lastEnd = $t + self::BUCKET;
                $out[] = self::keep($b);
                continue;
            }
            if ((float) $b['avg_kw'] <= $offKw || $lastEnd === PHP_INT_MIN) {
                $out[] = self::keep($b);
                continue;
            }
            // Fraction of the bucket that still falls inside the grace period.
            $keep = max(0.0, min(1.0, ($lastEnd + $grace - $t) / self::BUCKET));
            $out[] = self::scaled($b, $kwh * $keep);
        }
        return [$out, ['grace_min' => $grace / 60], [
            ['key' => 'auto_off', 'params' => ['grace_min' => $grace / 60]],
            ['key' => 'overrides_respected', 'params' => []],
        ]];
    }

    /** Compressed-air leaks: measured from how much the compressor loads with no production demand. */
    private static function leakRepair(array $buckets, array $params): array
    {
        $repair = max(0.1, min(0.9, (float) ($params['repair_share'] ?? 0.5)));
        $loaded = [];
        $unloaded = [];
        $nightShares = [];
        foreach ($buckets as $b) {
            $run = (int) $b['running_s'];
            $idle = (int) $b['idle_s'];
            if ($run > 0 && $idle > 0) {
                $loaded[] = (float) $b['max_kw'];
                $unloaded[] = (float) $b['min_kw'];
                if (!$b['is_scheduled']) {
                    $nightShares[] = $run / ($run + $idle);
                }
            }
        }
        if (count($nightShares) < 8 || $loaded === []) {
            throw HttpException::conflict('no_leak_data', 'The compressor has not run without production long enough to measure leaks.');
        }
        $l = self::median($loaded);
        $u = self::median($unloaded);
        $leak = self::median($nightShares);
        $cut = $leak * $repair;

        $out = [];
        foreach ($buckets as $b) {
            $run = (int) $b['running_s'];
            $idle = (int) $b['idle_s'];
            if ($run + $idle === 0) {
                $out[] = self::keep($b);
                continue;
            }
            $share = $run / ($run + $idle);
            $onHours = ($run + $idle) / 3600;
            $saved = ($l - $u) * min($share, $cut) * $onHours;
            $out[] = self::scaled($b, max(0.0, (float) $b['kwh'] - $saved));
        }
        return [$out, ['repair_share' => $repair, 'leak_share' => round($leak, 3), 'loaded_kw' => round($l, 2), 'unloaded_kw' => round($u, 2)], [
            ['key' => 'leak_measured', 'params' => ['leak_share' => round($leak, 3), 'loaded_kw' => round($l, 2), 'unloaded_kw' => round($u, 2)]],
            ['key' => 'leak_repair', 'params' => ['repair_share' => $repair]],
        ]];
    }

    /** Running energy back at the machine's reference running power (e.g. after fixing a worn pump). */
    private static function efficiency(int $companyId, array $m, array $buckets, array $params): array
    {
        $measured = Database::value(
            "SELECT JSON_EXTRACT(evidence, '$.deviation_pct') FROM waste_events
              WHERE company_id = ? AND machine_id = ? AND type = 'excess_vs_baseline' AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1",
            [$companyId, (int) $m['id']],
        );
        $pct = isset($params['improvement_pct']) ? (float) $params['improvement_pct'] : (float) ($measured ?? 5.0);
        $pct = max(1.0, min(40.0, $pct));
        $ratio = ($pct / 100) / (1 + $pct / 100); // running power falls from (1 + d)·ref to ref
        $out = [];
        foreach ($buckets as $b) {
            $runningShare = min(1.0, (int) $b['running_s'] / self::BUCKET);
            $out[] = self::scaled($b, (float) $b['kwh'] * (1 - $runningShare * $ratio));
        }
        return [$out, ['improvement_pct' => round($pct, 1), 'measured' => $measured !== null], [
            ['key' => $measured !== null ? 'efficiency_measured' : 'efficiency_assumed', 'params' => ['pct' => round($pct, 1)]],
        ]];
    }

    /** Move part of the day-tariff energy to the night tariff: same kWh, lower €. */
    private static function touShift(array $buckets, array $params): array
    {
        $share = max(0.1, min(1.0, (float) ($params['shift_share'] ?? 0.5)));
        $out = [];
        foreach ($buckets as $b) {
            $kwh = (float) $b['kwh'];
            if ($b['tariff_period'] === 'high') {
                $out[] = ['kwh' => $kwh, 'kwh_high' => $kwh * (1 - $share), 'kwh_low' => $kwh * $share];
            } else {
                $out[] = self::keep($b);
            }
        }
        return [$out, ['shift_share' => $share], [['key' => 'tou_shift', 'params' => ['share' => $share]]]];
    }

    private static function keep(array $b): array
    {
        return self::scaled($b, (float) $b['kwh']);
    }

    private static function scaled(array $b, float $kwh): array
    {
        $high = $b['tariff_period'] === 'high';
        return ['kwh' => $kwh, 'kwh_high' => $high ? $kwh : 0.0, 'kwh_low' => $high ? 0.0 : $kwh];
    }

    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
