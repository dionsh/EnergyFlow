<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Simulation;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Tariff\TariffBook;

/**
 * Turns the machine model into data:
 *   - writeBuckets(): 15-minute aggregates straight into readings_15m (history, fast-forward)
 *   - readingsAt():   one 10-second reading per machine, fed through the same
 *                     IngestService a real EF-N3 node uses (live data)
 */
final class Simulator
{
    private const BUCKET = 900;
    private const SUBPOINTS = 5;

    private MachineModel $model;

    public function __construct(private readonly SimContext $ctx, private readonly ?TariffBook $tariff)
    {
        $this->model = new MachineModel($ctx);
    }

    public function model(): MachineModel
    {
        return $this->model;
    }

    /** Writes buckets for [from, to). Both bounds must be multiples of 900 s. Returns rows written. */
    public function writeBuckets(int $from, int $to): int
    {
        $rows = [];
        $written = 0;
        for ($start = $from; $start < $to; $start += self::BUCKET) {
            $site = ['kwh' => 0.0, 'kvarh' => 0.0, 'max' => 0.0, 'min' => 0.0, 'production' => false];
            $incomer = null;
            foreach ($this->ctx->machines as $m) {
                if ($m['kind'] === 'incomer') {
                    $incomer = $m;
                    continue;
                }
                $bucket = $this->bucket($m, $start);
                $rows[] = $bucket;
                $site['kwh'] += $bucket['kwh'];
                $site['kvarh'] += $bucket['kvarh'];
                $site['max'] += $bucket['max_kw'];
                $site['min'] += $bucket['min_kw'];
                $site['production'] = $site['production'] || $bucket['is_scheduled'];
            }
            if ($incomer !== null) {
                $rows[] = $this->incomerBucket($incomer, $start, $site);
            }
            if (count($rows) >= 800) {
                $written += $this->flush($rows);
                $rows = [];
            }
        }
        return $written + $this->flush($rows);
    }

    /**
     * One reading per machine (incomer = sum + unmonitored loads) at $ts.
     *
     * @return list<array{machine_id: int, ts: int, kw: float, v: float, i: float, pf: ?float, f: float, temp: ?float}>
     */
    public function readingsAt(int $ts): array
    {
        $readings = [];
        $total = 0.0;
        $totalKvar = 0.0;
        $production = false;
        $incomer = null;
        $volts = $this->voltage($ts);
        $hz = $this->frequency($ts);

        foreach ($this->ctx->machines as $m) {
            if ($m['kind'] === 'incomer') {
                $incomer = $m;
                continue;
            }
            $s = $this->model->sample($m, $ts);
            $total += $s['kw'];
            $totalKvar += $s['pf'] ? $s['kw'] * tan(acos($s['pf'])) : 0.0;
            $production = $production || $this->ctx->schedule->isScheduled($m['schedule_id'], $ts, $m['id']);
            $readings[] = $this->reading($m, $ts, $s['kw'], $s['pf'], $s['temp'], $volts, $hz);
        }

        if ($incomer !== null) {
            $p = $incomer['profile'];
            $extra = ($p['unmonitored_kw'] + ($production ? $p['unmonitored_production_kw'] : 0.0))
                * (1 + 0.15 * Noise::smooth($this->ctx->seed * 100000 + crc32((string) $incomer["code"]) % 99991, $ts, 600));
            $kw = $total + $extra;
            $kvar = $totalKvar + $extra * 0.4;
            $pf = $kw > 0 ? $kw / sqrt($kw * $kw + $kvar * $kvar) : null;
            $readings[] = $this->reading($incomer, $ts, $kw, $pf, null, $volts, $hz);
        }
        return $readings;
    }

    private function bucket(array $m, int $start): array
    {
        $kw = $run = $idle = $cycles = 0.0;
        $pfWeighted = 0.0;
        $temps = [];
        $kws = [];
        $previous = $this->model->expected($m, $start - 90);
        $starts = 0;

        for ($k = 0; $k < self::SUBPOINTS; $k++) {
            $e = $this->model->expected($m, $start + 90 + 180 * $k);
            $kws[] = $e['kw'];
            $kw += $e['kw'];
            $run += $e['run'];
            $idle += $e['idle'];
            $cycles += $e['cycles_h'] * 180 / 3600;
            $pfWeighted += $e['pf'] === null ? 0.0 : $e['kw'] * $e['pf'];
            if ($e['temp'] !== null) {
                $temps[] = $e['temp'];
            }
            if ($previous['kw'] <= 0.0 && $e['kw'] > 0.0) {
                $starts++;
            }
            $previous = $e;
        }

        $seed = $this->ctx->seed * 100000 + crc32((string) $m["code"]) % 99991; // stable across demo resets
        $avg = $kw / self::SUBPOINTS * (1 + 0.01 * Noise::gaussian($seed, intdiv($start, self::BUCKET)));
        $pf = $kw > 0 ? $pfWeighted / $kw : null;
        $kwh = $avg * 0.25;
        $p = $m['profile'];

        $max = match ($p['model']) {
            'compressor' => $run > 0 ? $p['loaded_kw'] * 1.02 : max($kws),
            'injection_moulding' => $run > 0 ? max($kws) * 1.45 / 0.9675 : max($kws) * 1.03,
            'pump' => $run > 0 ? $p['on_kw'] * 1.01 : 0.0,
            default => max($kws) * 1.03,
        };
        $min = match ($p['model']) {
            'compressor' => $idle > 0 ? $p['unloaded_kw'] * 0.98 : min($kws),
            'pump' => min($kws) > 0 ? $p['on_kw'] * 0.99 : 0.0,
            default => min($kws) * 0.97,
        };

        $runS = (int) round($run / self::SUBPOINTS * self::BUCKET);
        $idleS = (int) round($idle / self::SUBPOINTS * self::BUCKET);

        return [
            'machine_id' => $m['id'],
            'bucket_start' => $start,
            'kwh' => $kwh,
            'kvarh' => $pf ? $kwh * tan(acos(min(0.9999, $pf))) : 0.0,
            'avg_kw' => $avg,
            'max_kw' => max($max, $avg),
            'min_kw' => min($min, $avg),
            'avg_pf' => $pf,
            'avg_v' => $this->voltage($start + 450),
            'max_temp_c' => $temps === [] ? null : max($temps),
            'running_s' => $runS,
            'idle_s' => min($idleS, self::BUCKET - $runS),
            'off_s' => max(0, self::BUCKET - $runS - $idleS),
            'start_count' => $starts,
            'cycle_count' => (int) round($cycles),
            'tariff_period' => $this->tariff?->period($start + 450) ?? 'high',
            'is_scheduled' => $this->ctx->schedule->isScheduled($m['schedule_id'], $start + 450, $m['id']),
        ];
    }

    private function incomerBucket(array $m, int $start, array $site): array
    {
        $p = $m['profile'];
        $extraKw = $p['unmonitored_kw'] + ($site['production'] ? $p['unmonitored_production_kw'] : 0.0);
        $kwh = $site['kwh'] + $extraKw * 0.25;
        $kvarh = $site['kvarh'] + $extraKw * 0.25 * 0.4;
        return [
            'machine_id' => $m['id'],
            'bucket_start' => $start,
            'kwh' => $kwh,
            'kvarh' => $kvarh,
            'avg_kw' => $kwh * 4,
            'max_kw' => $site['max'] + $extraKw,
            'min_kw' => $site['min'] + $extraKw * 0.8,
            'avg_pf' => $kwh > 0 ? $kwh / sqrt($kwh * $kwh + $kvarh * $kvarh) : null,
            'avg_v' => $this->voltage($start + 450),
            'max_temp_c' => null,
            'running_s' => self::BUCKET,
            'idle_s' => 0,
            'off_s' => 0,
            'start_count' => 0,
            'cycle_count' => 0,
            'tariff_period' => $this->tariff?->period($start + 450) ?? 'high',
            'is_scheduled' => $site['production'],
        ];
    }

    private function reading(array $m, int $ts, float $kw, ?float $pf, ?float $temp, float $volts, float $hz): array
    {
        $phases = max(1, (int) $m['phases']);
        $current = ($kw > 0 && $pf) ? $kw * 1000 / ($phases * $volts * $pf) : 0.0;
        return [
            'machine_id' => $m['id'],
            'ts' => $ts,
            'kw' => round($kw, 3),
            'v' => round($volts, 2),
            'i' => round($current, 3),
            'pf' => $pf === null ? null : round($pf, 3),
            'f' => round($hz, 3),
            'temp' => $temp === null ? null : round($temp, 2),
        ];
    }

    private function voltage(int $ts): float
    {
        $seed = $this->ctx->seed * 7;
        return 230.0 + 2.5 * Noise::smooth($seed, $ts, 1800) + 0.8 * Noise::smooth($seed + 1, $ts, 120);
    }

    private function frequency(int $ts): float
    {
        return 50.0 + 0.025 * Noise::smooth($this->ctx->seed * 11, $ts, 300);
    }

    /** @param list<array<string, mixed>> $rows */
    private function flush(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $companyId = $this->ctx->companyId;
        $placeholders = [];
        $params = [];
        $rowPlaceholder = '(?, FROM_UNIXTIME(?)' . str_repeat(', ?', 17) . ')'; // 19 columns
        foreach ($rows as $r) {
            $placeholders[] = $rowPlaceholder;
            array_push(
                $params,
                $r['machine_id'], $r['bucket_start'], $companyId,
                round($r['kwh'], 4), round($r['kvarh'], 4),
                round($r['avg_kw'], 3), round($r['max_kw'], 3), round($r['min_kw'], 3),
                $r['avg_pf'] === null ? null : round($r['avg_pf'], 3),
                round($r['avg_v'], 2),
                $r['max_temp_c'] === null ? null : round($r['max_temp_c'], 2),
                $r['running_s'], $r['idle_s'], $r['off_s'], $r['start_count'], $r['cycle_count'],
                $r['tariff_period'], $r['is_scheduled'] ? 1 : 0, 'sim_direct',
            );
        }
        // INSERT IGNORE: buckets already built from real (raw) readings always win.
        Database::run(
            'INSERT IGNORE INTO readings_15m
               (machine_id, bucket_start, company_id, kwh, kvarh, avg_kw, max_kw, min_kw, avg_pf, avg_v, max_temp_c,
                running_s, idle_s, off_s, start_count, cycle_count, tariff_period, is_scheduled, source)
             VALUES ' . implode(',', $placeholders),
            $params,
        );
        return count($rows);
    }
}
