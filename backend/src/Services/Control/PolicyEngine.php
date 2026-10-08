<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Control;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Services\Calendar\ScheduleBook;
use EnergyFlow\Services\Simulation\MachineModel;
use EnergyFlow\Services\Simulation\SimContext;

/**
 * Automation policies act through the same command loop as a person pressing
 * Turn Off — same safety checks, same verification by the meter, same log.
 *
 *   auto_off_after_schedule {grace_min}: if the machine is still on `grace_min`
 *   after its schedule ended (production overrides / HOLD count as schedule),
 *   send Turn Off. Mode `auto` acts; `approve` and `notify` leave the decision
 *   to a person (the after-hours alert carries the Turn Off button).
 *
 * Real hardware: tick() runs on every device uplink and cron call.
 * Simulated machines: planSimulated() makes the same decision for every night
 * in a generated range (demo seed, fast-forward), by asking the deterministic
 * machine model whether the machine would still be on — so a simulated week
 * contains the commands the policy really issued, each one stopping the
 * machine in the simulation from that moment.
 */
final class PolicyEngine
{
    private const STEP = 300;

    /** Real devices: act on machines that are still on after schedule end + grace. */
    public static function tick(int $companyId, int $now): int
    {
        $relays = CommandService::relays($companyId);
        $schedule = null;
        $issued = 0;
        foreach (self::policies($companyId) as $p) {
            $relay = $relays[$p['machine_id']] ?? null;
            if ($relay === null || $relay['simulated'] || !in_array($p['state'], ['running', 'idle'], true) || $now - (int) $p['live_ts'] > 120) {
                continue;
            }
            $schedule ??= ScheduleBook::forCompany($companyId);
            $end = $schedule->lastScheduledEnd($p['schedule_id'], $now, $p['machine_id']);
            if ($end === null || $now < max($end + $p['grace_s'], $p['from']) || self::handled($p['machine_id'], $end, $now + 1)) {
                continue;
            }
            try {
                CommandService::request($companyId, $p['machine_id'], null, $p['id'], 'auto_off');
                $issued++;
            } catch (HttpException) {
                // Not allowed right now (e.g. already off, or a command is pending): nothing to do.
            }
        }
        return $issued;
    }

    /**
     * Simulated machines: the auto-off commands for every schedule end whose
     * action time falls in [from, to). Past ones are written as executed and
     * verified history; one that is due now goes through the live command loop.
     */
    public static function planSimulated(int $companyId, int $from, int $to, int $now): int
    {
        $relays = CommandService::relays($companyId);
        $policies = array_values(array_filter(
            self::policies($companyId),
            static fn (array $p): bool => ($relays[$p['machine_id']]['simulated'] ?? false),
        ));
        if ($policies === []) {
            return 0;
        }

        // Cheap pass first: candidate action times, without loading the simulation.
        $schedule = ScheduleBook::forCompany($companyId);
        $candidates = [];
        foreach ($policies as $p) {
            // Look back two days for the schedule end: a policy that starts while a machine is
            // already running after hours acts at its start (max(end + grace, effective_from)).
            foreach (self::scheduleEnds($schedule, $p['schedule_id'], $p['machine_id'], $from - $p['grace_s'] - 2 * 86400, $to) as $end) {
                $at = max($end + $p['grace_s'], $p['from']);
                if ($at >= $to) {
                    continue;
                }
                // Due earlier but never acted on (e.g. accepted between two catch-ups): act now,
                // if that off-schedule period is still running — the model check below decides.
                $candidates[] = [$p, $end, max($at, $from)];
            }
        }
        if ($candidates === []) {
            return 0;
        }

        $context = SimContext::load($companyId, $from - 3600, $to + 3600);
        $model = new MachineModel($context);
        $planned = 0;
        foreach ($candidates as [$p, $end, $at]) {
            $m = $context->machines[$p['machine_id']] ?? null;
            if ($m === null || self::handled($p['machine_id'], $end, $at + 1)) {
                continue;
            }
            $expected = $model->expected($m, $at);
            if ($expected['mode'] !== 'left_on' || $expected['kw'] <= (float) $m['off_threshold_kw']) {
                continue; // switched off by the operator in time: nothing to do
            }
            if ($at > $now - 60) {
                try {
                    CommandService::request($companyId, $p['machine_id'], null, $p['id'], 'auto_off');
                    $planned++;
                } catch (HttpException) {
                }
                continue;
            }
            $relay = $relays[$p['machine_id']];
            $sent = (intdiv($at, 10) + 1) * 10;
            Database::run(
                "INSERT INTO device_commands (company_id, machine_id, device_id, channel_no, command, source, policy_id, reason, status,
                                              requested_at, sent_at, executed_at, verified_at, expires_at, verification)
                 VALUES (?, ?, ?, ?, 'turn_off', 'policy', ?, 'auto_off', 'verified',
                         FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?), ?)",
                [$companyId, $p['machine_id'], $relay['device_id'], $relay['channel'], $p['id'],
                 $at, $sent, $sent + 1, $sent + 11, $at + CommandService::TIMEOUT_S,
                 json_encode(['power_before_kw' => round($expected['kw'], 3), 'power_after_kw' => 0.0,
                              'verified_after_s' => $sent + 11 - $at, 'method' => 'simulated_history'])],
            );
            $planned++;
        }
        return $planned;
    }

    /**
     * Active auto-mode auto-off policies with their machine's live state.
     *
     * @return list<array{id: int, machine_id: int, schedule_id: int, grace_s: int, from: int, state: ?string, live_ts: ?int}>
     */
    private static function policies(int $companyId): array
    {
        return array_map(
            static function (array $r): array {
                $params = json_decode((string) $r['params'], true) ?: [];
                return [
                    'id' => (int) $r['id'],
                    'machine_id' => (int) $r['machine_id'],
                    'schedule_id' => (int) $r['schedule_id'],
                    'grace_s' => 60 * max(0, min(240, (int) ($params['grace_min'] ?? 15))),
                    'from' => (int) $r['from_ts'],
                    'state' => $r['state'],
                    'live_ts' => $r['live_ts'] === null ? null : (int) $r['live_ts'],
                ];
            },
            Database::all(
                "SELECT p.id, p.machine_id, p.params, UNIX_TIMESTAMP(p.effective_from) AS from_ts, m.schedule_id, l.state, UNIX_TIMESTAMP(l.ts) AS live_ts
                   FROM automation_policies p
                   JOIN machines m ON m.id = p.machine_id
                   LEFT JOIN machine_live l ON l.machine_id = m.id
                  WHERE p.company_id = ? AND p.is_active = 1 AND p.mode = 'auto' AND p.type = 'auto_off_after_schedule'
                    AND m.criticality <> 'critical' AND m.control_mode <> 'monitor' AND m.schedule_id IS NOT NULL AND m.archived_at IS NULL",
                [$companyId],
            ),
        );
    }

    /** Was this off-schedule period already acted on (by a person or the policy)? */
    private static function handled(int $machineId, int $end, int $until): bool
    {
        return Database::value(
            "SELECT 1 FROM device_commands WHERE machine_id = ? AND command = 'turn_off' AND status <> 'cancelled'
                AND requested_at >= FROM_UNIXTIME(?) AND requested_at < FROM_UNIXTIME(?) LIMIT 1",
            [$machineId, $end, $until],
        ) !== null;
    }

    /** @return list<int> moments the schedule switches from working to not working, in [from, to] */
    private static function scheduleEnds(ScheduleBook $schedule, int $scheduleId, int $machineId, int $from, int $to): array
    {
        $ends = [];
        $t = $from - ($from % self::STEP);
        $previous = $schedule->isScheduled($scheduleId, $t - self::STEP, $machineId);
        for (; $t <= $to; $t += self::STEP) {
            $current = $schedule->isScheduled($scheduleId, $t, $machineId);
            if ($previous && !$current) {
                $ends[] = $t;
            }
            $previous = $current;
        }
        return $ends;
    }
}
