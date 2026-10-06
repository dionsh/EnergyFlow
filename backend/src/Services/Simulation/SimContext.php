<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Simulation;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Calendar\ScheduleBook;
use EnergyFlow\Services\Weather\WeatherService;

/**
 * Everything the simulator needs to know about one company, loaded once:
 * machines + profiles, schedules, scenarios, accepted policies, executed
 * commands and weather. The simulator itself is a pure function of this context.
 */
final class SimContext
{
    /**
     * @param array<int, array<string, mixed>> $machines id => row (+ 'profile')
     * @param list<array{scenario: string, machine_id: ?int, params: array, start: int, end: ?int}> $scenarios
     * @param array<int, list<array{type: string, params: array, from: int}>> $policies
     * @param array<int, list<array{0: int, 1: int}>> $forcedOff machine_id => [[start, end]]
     * @param array<int, float> $weather hour ts => °C
     */
    public function __construct(
        public readonly int $companyId,
        public readonly int $seed,
        public readonly ScheduleBook $schedule,
        public readonly array $machines,
        private readonly array $scenarios,
        private readonly array $policies,
        private readonly array $forcedOff,
        private readonly array $weather,
    ) {
    }

    public function time(): LocalTime
    {
        return $this->schedule->time;
    }

    public static function load(int $companyId, int $from, int $to): self
    {
        $seed = (int) (Database::value('SELECT seed FROM sim_state WHERE company_id = ?', [$companyId]) ?? 1);
        $schedule = ScheduleBook::forCompany($companyId);
        $defaults = require EF_ROOT . '/config/sim_profiles.php';

        $machines = [];
        foreach (Database::all(
            'SELECT id, kind, code, type_code, schedule_id, phases, rated_power_kw, idle_threshold_kw, off_threshold_kw, sim_profile
               FROM machines WHERE company_id = ? AND archived_at IS NULL ORDER BY kind, id',
            [$companyId],
        ) as $row) {
            $profile = json_decode((string) $row['sim_profile'], true) ?: [];
            $model = $profile['model'] ?? ($row['kind'] === 'incomer' ? 'incomer' : $row['type_code']);
            $row['id'] = (int) $row['id'];
            $row['schedule_id'] = $row['schedule_id'] === null ? null : (int) $row['schedule_id'];
            $row['phases'] = (int) $row['phases'];
            $row['profile'] = ['model' => $model] + $profile + ($defaults[$model] ?? []);
            $machines[$row['id']] = $row;
        }

        $scenarios = array_map(
            static fn (array $s): array => [
                'scenario' => $s['scenario'],
                'machine_id' => $s['machine_id'] === null ? null : (int) $s['machine_id'],
                'params' => json_decode((string) $s['params'], true) ?: [],
                'start' => (int) $s['start_ts'],
                'end' => $s['end_ts'] === null ? null : (int) $s['end_ts'],
            ],
            Database::all(
                'SELECT scenario, machine_id, params, UNIX_TIMESTAMP(starts_at) AS start_ts, UNIX_TIMESTAMP(ends_at) AS end_ts
                   FROM sim_scenarios WHERE company_id = ?',
                [$companyId],
            ),
        );

        $policies = [];
        foreach (Database::all(
            'SELECT machine_id, type, params, UNIX_TIMESTAMP(effective_from) AS from_ts
               FROM automation_policies WHERE company_id = ? AND is_active = 1',
            [$companyId],
        ) as $p) {
            $policies[(int) $p['machine_id']][] = [
                'type' => $p['type'],
                'params' => json_decode((string) $p['params'], true) ?: [],
                'from' => (int) $p['from_ts'],
            ];
        }

        // A machine switched off by EnergyFlow stays off until its next scheduled start
        // (the operator restarts it with its own START button).
        $forcedOff = [];
        foreach (Database::all(
            "SELECT m.id AS machine_id, m.schedule_id, UNIX_TIMESTAMP(c.executed_at) AS at_ts
               FROM device_commands c JOIN machines m ON m.id = c.machine_id
              WHERE c.company_id = ? AND c.command = 'turn_off' AND c.executed_at IS NOT NULL",
            [$companyId],
        ) as $c) {
            $start = (int) $c['at_ts'];
            $scheduleId = $c['schedule_id'] === null ? null : (int) $c['schedule_id'];
            $end = $start + 3 * 86400;
            for ($t = $start + 300; $t < $start + 3 * 86400; $t += 300) {
                if ($schedule->isScheduled($scheduleId, $t, (int) $c['machine_id'])) {
                    $end = $t;
                    break;
                }
            }
            $forcedOff[(int) $c['machine_id']][] = [$start, $end];
        }

        $siteId = Database::value('SELECT site_id FROM machines WHERE company_id = ? LIMIT 1', [$companyId]);
        $weather = $siteId === null ? [] : WeatherService::series((int) $siteId, $from, $to);

        return new self($companyId, $seed, $schedule, $machines, $scenarios, $policies, $forcedOff, $weather);
    }

    public function temperature(int $ts): float
    {
        $hour = $ts - ($ts % 3600);
        $a = $this->weather[$hour] ?? null;
        $b = $this->weather[$hour + 3600] ?? null;
        if ($a === null || $b === null) {
            return $a ?? $b ?? WeatherService::climatologyAt($ts);
        }
        return $a + ($b - $a) * (($ts - $hour) / 3600);
    }

    /**
     * The scenario of this kind that is active for the machine at $ts, if any.
     * When several overlap, the most recently started one wins — so a specific
     * "tonight" scenario overrides a long-running background one.
     */
    public function scenario(string $kind, int $machineId, int $ts): ?array
    {
        $match = null;
        foreach ($this->scenarios as $s) {
            if ($s['scenario'] === $kind
                && ($s['machine_id'] === null || $s['machine_id'] === $machineId)
                && $ts >= $s['start'] && ($s['end'] === null || $ts < $s['end'])
                && ($match === null || $s['start'] > $match['start'])) {
                $match = $s;
            }
        }
        return $match;
    }

    public function hasPolicy(int $machineId, string $type, int $ts): bool
    {
        foreach ($this->policies[$machineId] ?? [] as $policy) {
            if ($policy['type'] === $type && $ts >= $policy['from']) {
                return true;
            }
        }
        return false;
    }

    public function isForcedOff(int $machineId, int $ts): bool
    {
        foreach ($this->forcedOff[$machineId] ?? [] as [$start, $end]) {
            if ($ts >= $start && $ts < $end) {
                return true;
            }
        }
        return false;
    }
}
