<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Simulation;

/**
 * Behaviour of each simulated machine type. Pure functions of (machine, time,
 * context): the same inputs always give the same output.
 *
 *   expected() — mean behaviour around a moment (used for 15-minute buckets)
 *   sample()   — one concrete 10-second reading (used for live data)
 */
final class MachineModel
{
    public const OFF = 0;
    public const IDLE = 1;
    public const RUNNING = 2;

    public function __construct(private readonly SimContext $ctx)
    {
    }

    /** 'scheduled' | 'left_on' | 'off' */
    public function mode(array $m, int $ts): string
    {
        $id = $m['id'];
        if ($this->ctx->isForcedOff($id, $ts)) {
            return 'off';
        }
        if ($this->ctx->schedule->isScheduled($m['schedule_id'], $ts, $id)) {
            return 'scheduled';
        }
        return $this->leftOn($m, $ts) ? 'left_on' : 'off';
    }

    /**
     * @return array{mode: string, kw: float, run: float, idle: float, pf: ?float, temp: ?float, cycles_h: float}
     */
    public function expected(array $m, int $ts): array
    {
        $e = $this->base($m, $ts);
        $e['kw'] = $this->overload($m, $ts, $e['kw'], 1.0);
        return $e;
    }

    /**
     * One concrete reading at $ts (10-second resolution).
     *
     * @return array{kw: float, pf: ?float, temp: ?float}
     */
    public function sample(array $m, int $ts): array
    {
        $s = $this->draw($m, $ts);
        $s['kw'] = $this->overload($m, $ts, $s['kw'], 1 + 0.015 * Noise::gaussian($this->seed($m) + 31, $ts));
        return $s;
    }

    /**
     * A fault injected from the Demo Director (scenario 'spike'): while it is on, the
     * machine draws `rated_share` × its nameplate power — a jammed screw, a seizing
     * bearing or a clogged impeller overloads the motor like this until the thermal
     * relay trips or someone intervenes.
     */
    private function overload(array $m, int $ts, float $kw, float $jitter): float
    {
        if ($kw <= 0.0) {
            return $kw;
        }
        $scenario = $this->ctx->scenario('spike', $m['id'], $ts);
        if ($scenario === null) {
            return $kw;
        }
        $rated = (float) ($m['rated_power_kw'] ?? 0.0);
        return max($kw, (float) ($scenario['params']['rated_share'] ?? 1.35) * $rated * $jitter);
    }

    /** @return array{mode: string, kw: float, run: float, idle: float, pf: ?float, temp: ?float, cycles_h: float} */
    private function base(array $m, int $ts): array
    {
        $p = $m['profile'];
        $seed = $this->seed($m);
        $mode = $this->mode($m, $ts);

        switch ($p['model']) {
            case 'compressor':
                if ($mode === 'off') {
                    return $this->off($mode, $this->indoor($ts) + 3.0);
                }
                // Leaks waste air whenever the network is pressurised; a repair (scenario) removes part of them.
                $repair = $this->ctx->scenario('leak_repair', $m['id'], $ts);
                $leak = $p['leak'] * (1 - (float) ($repair['params']['repair_share'] ?? 0.0));
                $load = $mode === 'scheduled'
                    ? self::clamp($p['prod_demand'] + $leak + 0.08 * Noise::smooth($seed, $ts, 1800) + 0.04 * Noise::smooth($seed + 7, $ts, 240), 0.15, 0.97)
                    : self::clamp($leak + 0.03 * Noise::smooth($seed + 3, $ts, 900), 0.04, 0.45);
                $loadedKw = $load * $p['loaded_kw'];
                $unloadedKw = (1 - $load) * $p['unloaded_kw'];
                $kw = $loadedKw + $unloadedKw;
                return [
                    'mode' => $mode,
                    'kw' => $kw,
                    'run' => $load,
                    'idle' => 1 - $load,
                    'pf' => ($loadedKw * $p['pf_loaded'] + $unloadedKw * $p['pf_unloaded']) / $kw,
                    'temp' => $p['temp_base'] + $p['temp_load'] * $load + 1.5 * Noise::smooth($seed + 9, $ts, 1200),
                    'cycles_h' => 4 * $load * (1 - $load) * $p['max_cycles_h'],
                ];

            case 'injection_moulding':
                if ($mode === 'off') {
                    return $this->off($mode, $this->indoor($ts));
                }
                $wear = $this->wear($m, $ts);
                $warmUp = $mode === 'scheduled' && !$this->ctx->schedule->isScheduled($m['schedule_id'], $ts - 1800, $m['id']);
                $microStop = Noise::uniform($seed, 55, intdiv($ts, 600)) < $p['micro_stop'];
                if ($mode === 'left_on' || $warmUp || $microStop) {
                    $kw = ($warmUp ? $p['warmup_kw'] : $p['standby_kw']) * (1 + 0.03 * Noise::smooth($seed + 2, $ts, 600));
                    return ['mode' => $mode, 'kw' => $kw, 'run' => 0.0, 'idle' => 1.0, 'pf' => $p['pf_standby'], 'temp' => $p['oil_temp'] - 6.0, 'cycles_h' => 0.0];
                }
                return [
                    'mode' => $mode,
                    'kw' => $p['run_kw'] * $wear * (1 + 0.05 * Noise::smooth($seed + 4, $ts, 1500)),
                    'run' => 1.0,
                    'idle' => 0.0,
                    'pf' => $p['pf_run'] - ($wear - 1) * 0.3,
                    'temp' => $p['oil_temp'] + 2.0 * Noise::smooth($seed + 5, $ts, 1800) + ($wear - 1) * 40,
                    'cycles_h' => 3600 / $p['cycle_s'],
                ];

            case 'chiller':
                if ($mode === 'off') {
                    return $this->off($mode);
                }
                $load = self::clamp(($this->ctx->temperature($ts) - 5) / 25, 0, 1);
                $kw = ($p['base_kw'] + $p['temp_kw'] * $load) * (0.88 + 0.12 * Noise::smooth($seed, $ts, 1800));
                return ['mode' => $mode, 'kw' => $kw, 'run' => 1.0, 'idle' => 0.0, 'pf' => $p['pf'], 'temp' => null, 'cycles_h' => 0.0];

            case 'hvac':
                $t = $this->ctx->temperature($ts);
                $demand = $p['heat_kw'] * self::clamp((18 - $t) / 20, 0, 1) + $p['cool_kw'] * self::clamp(($t - 24) / 10, 0, 1);
                $kw = $mode === 'off'
                    ? $p['setback'] * $p['heat_kw'] * self::clamp((18 - $t) / 20, 0, 1)
                    : max($p['fan_kw'], $demand) * (0.9 + 0.1 * Noise::smooth($seed, $ts, 900));
                $run = $kw > 0.6 ? 1.0 : 0.0;
                return ['mode' => $mode, 'kw' => $kw, 'run' => $run, 'idle' => $kw > 0.05 ? 1 - $run : 0.0, 'pf' => $kw > 0.05 ? $p['pf'] : null, 'temp' => null, 'cycles_h' => 0.0];

            case 'pump':
                if ($mode === 'off') {
                    return $this->off($mode);
                }
                $on = 0;
                foreach ([-60, 0, 60] as $delta) {
                    $on += $this->pumpOn($m, $ts + $delta) ? 1 : 0;
                }
                $fraction = $on / 3;
                return ['mode' => $mode, 'kw' => $p['on_kw'] * $fraction, 'run' => $fraction, 'idle' => 0.0, 'pf' => $fraction > 0 ? $p['pf'] : null, 'temp' => null, 'cycles_h' => 1.0];

            case 'lighting':
                if ($mode === 'off') {
                    return $this->off($mode);
                }
                return ['mode' => $mode, 'kw' => $p['on_kw'] * (1 + 0.005 * Noise::smooth($seed, $ts, 600)), 'run' => 1.0, 'idle' => 0.0, 'pf' => $p['pf'], 'temp' => null, 'cycles_h' => 0.0];

            case 'office':
                if ($mode === 'off') {
                    // Servers, router and fridge never switch off: base load is not waste.
                    return ['mode' => $mode, 'kw' => $p['base_kw'] * (0.95 + 0.05 * Noise::smooth($seed, $ts, 3600)), 'run' => 0.0, 'idle' => 1.0, 'pf' => $p['pf'], 'temp' => null, 'cycles_h' => 0.0];
                }
                return ['mode' => $mode, 'kw' => $p['office_kw'] * (0.9 + 0.1 * Noise::smooth($seed, $ts, 1200)), 'run' => 1.0, 'idle' => 0.0, 'pf' => $p['pf'], 'temp' => null, 'cycles_h' => 0.0];

            default:
                return $this->off($mode);
        }
    }

    /**
     * The normal reading at $ts, adding the dynamics that a mean hides: compressor
     * load/unload switching, moulding shot profile, pump on/off.
     *
     * @return array{kw: float, pf: ?float, temp: ?float}
     */
    private function draw(array $m, int $ts): array
    {
        $p = $m['profile'];
        $seed = $this->seed($m);
        $e = $this->base($m, $ts);
        $jitter = 1 + 0.012 * Noise::gaussian($seed, $ts);

        if ($e['kw'] <= 0.0) {
            return ['kw' => 0.0, 'pf' => null, 'temp' => $e['temp']];
        }

        switch ($p['model']) {
            case 'compressor':
                // Rhythm from the air demand at the start of the current 10-minute window, so the
                // load/unload cycle is continuous (re-deriving the period every sample turns the phase into noise).
                $window = $ts - ($ts % 600);
                $w = $this->base($m, $window);
                $period = 3600 / max(1.0, $w['cycles_h']);
                $phase = fmod($ts - $window + ($seed % 997) * 13, $period) / $period;
                $loaded = $phase < ($w['kw'] > 0.0 ? $w['run'] : $e['run']);
                return [
                    'kw' => ($loaded ? $p['loaded_kw'] : $p['unloaded_kw']) * $jitter,
                    'pf' => $loaded ? $p['pf_loaded'] : $p['pf_unloaded'],
                    'temp' => $e['temp'],
                ];
            case 'injection_moulding':
                if ($e['run'] < 1.0) {
                    return ['kw' => $e['kw'] * $jitter, 'pf' => $e['pf'], 'temp' => $e['temp']];
                }
                $phase = fmod($ts, $p['cycle_s']) / $p['cycle_s'];
                $shape = ($phase < 0.15 ? 1.45 : ($phase < 0.5 ? 1.0 : 0.8)) / 0.9675;
                return ['kw' => $e['kw'] * $shape * $jitter, 'pf' => $e['pf'], 'temp' => $e['temp']];
            case 'pump':
                $on = $this->pumpOn($m, $ts);
                return ['kw' => $on ? $p['on_kw'] * $jitter : 0.0, 'pf' => $on ? $p['pf'] : null, 'temp' => null];
            default:
                return ['kw' => $e['kw'] * $jitter, 'pf' => $e['pf'], 'temp' => $e['temp']];
        }
    }

    public function stateFor(array $m, float $kw): int
    {
        $off = (float) ($m['off_threshold_kw'] ?? 0.05);
        $idle = $m['idle_threshold_kw'] === null ? $off : (float) $m['idle_threshold_kw'];
        return $kw < $off ? self::OFF : ($kw < $idle ? self::IDLE : self::RUNNING);
    }

    /**
     * Machine forgotten after the shift (scenario). Auto-off policies don't change
     * this: like in a real plant, the machine is left on and the policy's Turn Off
     * command stops it (a forced-off interval in the context).
     */
    private function leftOn(array $m, int $ts): bool
    {
        $scenario = $this->ctx->scenario('left_on', $m['id'], $ts);
        if ($scenario === null) {
            return false;
        }
        // One decision per "night" (evening → next morning); one per weekend.
        $time = $this->ctx->time();
        $night = $ts - 7 * 3600;
        $day = $time->dayNumber($night);
        $dow = $time->dayOfWeek($night);
        $weekend = $dow >= 6;
        if ($weekend) {
            $day -= $dow - 6;
        }
        $probability = $weekend ? ($scenario['params']['weekend_p'] ?? 0.1) : ($scenario['params']['weekday_p'] ?? 0.4);
        return Noise::uniform($this->ctx->seed, $this->stableKey($m), 101, $day) < $probability;
    }

    /** Efficiency drift (e.g. worn hydraulic pump): multiplier on running power. */
    private function wear(array $m, int $ts): float
    {
        $scenario = $this->ctx->scenario('degradation', $m['id'], $ts);
        if ($scenario === null) {
            return 1.0;
        }
        $days = ($ts - $scenario['start']) / 86400;
        return 1.0 + min($scenario['params']['max'] ?? 0.25, ($scenario['params']['rate_per_day'] ?? 0.003) * $days);
    }

    private function pumpOn(array $m, int $ts): bool
    {
        $minute = $this->ctx->time()->minuteOfDay($ts);
        return (($minute + $this->stableKey($m) * 17) % 60) < $m['profile']['duty_min'];
    }

    private function indoor(int $ts): float
    {
        return 16.0 + 0.35 * ($this->ctx->temperature($ts) - 10);
    }

    private function off(string $mode, ?float $temp = null): array
    {
        return ['mode' => $mode, 'kw' => 0.0, 'run' => 0.0, 'idle' => 0.0, 'pf' => null, 'temp' => $temp, 'cycles_h' => 0.0];
    }

    private function seed(array $m): int
    {
        return $this->ctx->seed * 100000 + $this->stableKey($m);
    }

    /**
     * Randomness is keyed on the machine CODE, not its database id, so a demo
     * reset (which recreates every row) always tells exactly the same story.
     */
    private function stableKey(array $m): int
    {
        return crc32((string) $m['code']) % 99991;
    }

    private static function clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }
}
