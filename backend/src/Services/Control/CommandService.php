<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Control;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Services\AuditLog;
use EnergyFlow\Services\Calendar\ScheduleBook;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Detection\AlertManager;
use EnergyFlow\Services\Devices\DeviceGateway;
use EnergyFlow\Services\Notifications\NotificationService;
use EnergyFlow\Utils\Time;

/**
 * The Turn Off loop (docs/03-architecture.md §10.3):
 *
 *   queued → sent → executed → verified        (or failed / cancelled)
 *
 * Safety first: critical machines and monitor-only machines can never be
 * switched, a machine needs a remote-STOP relay channel, and one command at a
 * time. EnergyFlow can only STOP a machine; restarting is always done with the
 * machine's own START button (hardware/README.md §4).
 *
 * "Verified" is decided by the meter, never by the device's own report: the
 * readings after execution must show power below the machine's off threshold.
 * No drop within 60 s, or no response within 2 minutes, fails the command and
 * raises an alert.
 */
final class CommandService
{
    public const TIMEOUT_S = 120;
    public const VERIFY_WINDOW_S = 60;
    public const PENDING = ['queued', 'sent', 'acknowledged', 'executed'];

    /** @return array<int, array> machine_id => relay channel {device_id, serial, simulated, channel} */
    public static function relays(int $companyId): array
    {
        $out = [];
        foreach (Database::all(
            'SELECT dc.machine_id, dc.channel_no, d.id AS device_id, d.serial, d.is_simulated
               FROM device_channels dc JOIN devices d ON d.id = dc.device_id
              WHERE d.company_id = ? AND dc.valid_to IS NULL AND dc.has_relay = 1 AND d.archived_at IS NULL',
            [$companyId],
        ) as $r) {
            $out[(int) $r['machine_id']] = [
                'device_id' => (int) $r['device_id'],
                'serial' => $r['serial'],
                'simulated' => (bool) $r['is_simulated'],
                'channel' => (int) $r['channel_no'],
            ];
        }
        return $out;
    }

    /** @return array<int, array> machine_id => latest command, if it is pending or finished in the last 10 minutes */
    public static function recent(int $companyId, int $now): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT c.id, c.machine_id, c.status, c.source, UNIX_TIMESTAMP(c.requested_at) AS requested, UNIX_TIMESTAMP(c.verified_at) AS verified, c.verification
               FROM device_commands c
              WHERE c.company_id = ? AND (c.status IN ('queued','sent','acknowledged','executed') OR c.requested_at >= FROM_UNIXTIME(?))
              ORDER BY c.requested_at, c.id",
            [$companyId, $now - 600],
        ) as $c) {
            $verification = json_decode((string) $c['verification'], true) ?: [];
            $out[(int) $c['machine_id']] = [
                'id' => (int) $c['id'],
                'status' => $c['status'],
                'source' => $c['source'],
                'verified_after_s' => $verification['verified_after_s'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Can this machine be turned off right now, and if not, why? Shared by the API
     * (which enforces it) and the live view (which explains a disabled button).
     *
     * @return array{can_turn_off: bool, reason: ?string, needs_confirm: ?string}
     */
    public static function evaluate(array $machine, ?array $relay, ?array $command, ?string $state, bool $scheduledNow): array
    {
        $deny = static fn (string $reason): array => ['can_turn_off' => false, 'reason' => $reason, 'needs_confirm' => null];
        if (($machine['kind'] ?? 'machine') === 'incomer') {
            return $deny('not_controllable');
        }
        if ($machine['criticality'] === 'critical') {
            return $deny('machine_critical');
        }
        if ($machine['control_mode'] === 'monitor') {
            return $deny('control_monitor_only');
        }
        if ($relay === null) {
            return $deny('no_relay');
        }
        if ($command !== null && in_array($command['status'], self::PENDING, true)) {
            return $deny('command_pending');
        }
        if (!in_array($state, ['running', 'idle', 'abnormal'], true)) {
            return $deny($state === 'off' ? 'already_off' : 'no_live_data');
        }
        return ['can_turn_off' => true, 'reason' => null, 'needs_confirm' => $scheduledNow ? 'machine_scheduled' : null];
    }

    /**
     * Queues a Turn Off. $userId null = issued by a policy.
     *
     * @return array the presented command
     */
    public static function request(int $companyId, int $machineId, ?int $userId, ?int $policyId = null, ?string $reason = null, bool $confirmScheduled = false): array
    {
        $now = Clock::now($companyId);
        $machine = Database::one(
            "SELECT m.id, m.kind, m.code, m.name, m.criticality, m.control_mode, m.schedule_id, m.off_threshold_kw,
                    l.state, l.power_kw, UNIX_TIMESTAMP(l.ts) AS live_ts
               FROM machines m LEFT JOIN machine_live l ON l.machine_id = m.id
              WHERE m.id = ? AND m.company_id = ? AND m.archived_at IS NULL",
            [$machineId, $companyId],
        ) ?? throw HttpException::notFound('machine_not_found', 'Machine not found.');

        $relay = self::relays($companyId)[$machineId] ?? null;
        $command = self::recent($companyId, $now)[$machineId] ?? null;
        $state = $machine['live_ts'] === null || $now - (int) $machine['live_ts'] > 120 ? null : $machine['state'];
        $schedule = ScheduleBook::forCompany($companyId);
        $scheduledNow = $schedule->isScheduled($machine['schedule_id'] === null ? null : (int) $machine['schedule_id'], $now, $machineId);

        $check = self::evaluate($machine, $relay, $command, $state, $scheduledNow);
        if (!$check['can_turn_off']) {
            throw HttpException::conflict($check['reason'], 'This machine cannot be turned off right now.');
        }
        if ($check['needs_confirm'] !== null && !$confirmScheduled && $policyId === null) {
            throw HttpException::conflict('machine_scheduled', 'This machine is scheduled to run now. Confirm to turn it off anyway.');
        }

        $wasteEventId = Database::value(
            "SELECT id FROM waste_events WHERE company_id = ? AND machine_id = ? AND ended_at IS NULL AND type IN ('after_hours', 'idle')
              ORDER BY started_at DESC LIMIT 1",
            [$companyId, $machineId],
        );
        $id = Database::insert(
            "INSERT INTO device_commands (company_id, machine_id, device_id, channel_no, command, source, policy_id, waste_event_id, requested_by, reason,
                                          status, requested_at, expires_at, verification)
             VALUES (?, ?, ?, ?, 'turn_off', ?, ?, ?, ?, ?, 'queued', FROM_UNIXTIME(?), FROM_UNIXTIME(?), ?)",
            [$companyId, $machineId, $relay['device_id'], $relay['channel'], $policyId === null ? 'user' : 'policy', $policyId,
             $wasteEventId === null ? null : (int) $wasteEventId, $userId, $reason === null ? null : mb_substr($reason, 0, 200),
             $now, $now + self::TIMEOUT_S,
             json_encode(['power_before_kw' => $machine['power_kw'] === null ? null : round((float) $machine['power_kw'], 3)])],
        );
        AuditLog::record($companyId, $userId, 'command.turn_off', 'machine', $machineId, [
            'command_id' => $id, 'device' => $relay['serial'], 'channel' => $relay['channel'], 'policy_id' => $policyId,
            'scheduled_now' => $scheduledNow, 'reason' => $reason,
        ]);

        $device = Database::one('SELECT * FROM devices WHERE id = ?', [$relay['device_id']]);
        DeviceGateway::driverFor($device)->dispatch(['id' => $id, 'machine_id' => $machineId], $now);
        return self::find($companyId, $id);
    }

    /** Lets simulated nodes act, then verifies and expires. Cheap when nothing is pending. */
    public static function progress(int $companyId, int $now): void
    {
        if (Database::value("SELECT 1 FROM device_commands WHERE company_id = ? AND status IN ('queued','sent','acknowledged','executed') LIMIT 1", [$companyId]) === null) {
            return;
        }
        DeviceGateway::advanceSimulated($companyId, $now);
        self::verify($companyId, $now);
    }

    /** Decides executed commands by telemetry; fails silent or ineffective ones. */
    public static function verify(int $companyId, int $now): int
    {
        $decided = 0;
        foreach (Database::all(
            "SELECT c.*, UNIX_TIMESTAMP(c.requested_at) AS requested, UNIX_TIMESTAMP(c.executed_at) AS executed, UNIX_TIMESTAMP(c.expires_at) AS expires,
                    m.code, m.name, m.off_threshold_kw, d.serial, UNIX_TIMESTAMP(d.last_seen_at) AS device_seen
               FROM device_commands c JOIN machines m ON m.id = c.machine_id JOIN devices d ON d.id = c.device_id
              WHERE c.company_id = ? AND c.status IN ('queued','sent','acknowledged','executed')",
            [$companyId],
        ) as $c) {
            $id = (int) $c['id'];
            $verification = json_decode((string) $c['verification'], true) ?: [];
            if ($c['status'] === 'executed' && $c['executed'] !== null) {
                $executed = (int) $c['executed'];
                $readings = Database::all(
                    'SELECT UNIX_TIMESTAMP(ts) AS t, power_kw FROM readings_raw WHERE machine_id = ? AND ts > FROM_UNIXTIME(?) AND ts <= FROM_UNIXTIME(?) ORDER BY ts LIMIT 30',
                    [(int) $c['machine_id'], $executed, $executed + self::VERIFY_WINDOW_S],
                );
                foreach ($readings as $r) {
                    if ((float) $r['power_kw'] < (float) $c['off_threshold_kw']) {
                        $at = (int) $r['t'];
                        $verification['power_after_kw'] = round((float) $r['power_kw'], 3);
                        $verification['reading_at'] = self::iso($at);
                        $verification['verified_after_s'] = $at - (int) $c['requested'];
                        Database::run(
                            "UPDATE device_commands SET status = 'verified', verified_at = FROM_UNIXTIME(?), verification = ? WHERE id = ?",
                            [$at, json_encode($verification), $id],
                        );
                        self::onVerified($companyId, $c, $verification, $now);
                        $decided++;
                        continue 2;
                    }
                }
                if ($now > $executed + self::VERIFY_WINDOW_S) {
                    $last = $readings === [] ? null : $readings[count($readings) - 1];
                    $verification['power_after_kw'] = $last === null ? null : round((float) $last['power_kw'], 3);
                    self::fail($companyId, $c, $last === null ? 'no_readings' : 'power_did_not_drop', $verification, $now);
                    $decided++;
                }
            } elseif ($now > (int) $c['expires']) {
                $offline = $c['device_seen'] === null || $now - (int) $c['device_seen'] > 120;
                self::fail($companyId, $c, $offline ? 'device_offline' : 'no_response', $verification, $now);
                $decided++;
            }
        }
        return $decided;
    }

    /** A device reported that it could not execute the command. */
    public static function deviceFailed(int $companyId, int $commandId, string $detail, int $now): void
    {
        $c = Database::one(
            'SELECT c.*, m.code, m.name, d.serial FROM device_commands c JOIN machines m ON m.id = c.machine_id JOIN devices d ON d.id = c.device_id WHERE c.id = ? AND c.company_id = ?',
            [$commandId, $companyId],
        );
        if ($c !== null) {
            self::fail($companyId, $c, 'device_reported', json_decode((string) $c['verification'], true) ?: [], $now, $detail);
        }
    }

    public static function find(int $companyId, int $id): array
    {
        $row = Database::one(self::SELECT . ' WHERE c.company_id = ? AND c.id = ?', [$companyId, $id])
            ?? throw HttpException::notFound('command_not_found', 'Command not found.');
        return self::present($row);
    }

    /** @return list<array> */
    public static function list(int $companyId, array $filters = [], int $limit = 100): array
    {
        $where = ['c.company_id = ?'];
        $args = [$companyId];
        foreach (['machine_id' => 'c.machine_id', 'device_id' => 'c.device_id', 'policy_id' => 'c.policy_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = "{$column} = ?";
                $args[] = (int) $filters[$key];
            }
        }
        return array_map(
            static fn (array $row): array => self::present($row),
            Database::all(self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY c.requested_at DESC, c.id DESC LIMIT ' . max(1, min(500, $limit)), $args),
        );
    }

    private const SELECT = 'SELECT c.id, c.command, c.status, c.source, c.policy_id, c.waste_event_id, c.reason, c.channel_no, c.verification, c.failure_reason,
               c.requested_at, c.sent_at, c.executed_at, c.verified_at, c.expires_at,
               m.id AS machine_id, m.code, m.name, m.type_code, d.id AS device_id, d.serial, d.is_simulated, u.full_name AS requested_by
          FROM device_commands c
          JOIN machines m ON m.id = c.machine_id
          JOIN devices d ON d.id = c.device_id
          LEFT JOIN users u ON u.id = c.requested_by';

    private static function present(array $c): array
    {
        $verification = json_decode((string) $c['verification'], true) ?: [];
        $timeline = [['step' => 'queued', 'at' => Time::iso($c['requested_at'])]];
        if ($c['sent_at'] !== null) {
            $timeline[] = ['step' => 'sent', 'at' => Time::iso($c['sent_at'])];
        }
        if ($c['executed_at'] !== null) {
            $timeline[] = ['step' => 'executed', 'at' => Time::iso($c['executed_at'])];
        }
        if ($c['verified_at'] !== null) {
            $timeline[] = ['step' => 'verified', 'at' => Time::iso($c['verified_at'])];
        }
        return [
            'id' => (int) $c['id'],
            'command' => $c['command'],
            'status' => $c['status'],
            'source' => $c['source'],
            'policy_id' => $c['policy_id'] === null ? null : (int) $c['policy_id'],
            'waste_event_id' => $c['waste_event_id'] === null ? null : (int) $c['waste_event_id'],
            'reason' => $c['reason'],
            'requested_by' => $c['requested_by'],
            'machine' => ['id' => (int) $c['machine_id'], 'code' => $c['code'], 'name' => $c['name'], 'type' => $c['type_code']],
            'device' => ['id' => (int) $c['device_id'], 'serial' => $c['serial'], 'channel' => (int) $c['channel_no'], 'simulated' => (bool) $c['is_simulated']],
            'requested_at' => Time::iso($c['requested_at']),
            'expires_at' => Time::iso($c['expires_at']),
            'timeline' => $timeline,
            'verification' => [
                'power_before_kw' => isset($verification['power_before_kw']) ? (float) $verification['power_before_kw'] : null,
                'power_after_kw' => isset($verification['power_after_kw']) ? (float) $verification['power_after_kw'] : null,
                'verified_after_s' => isset($verification['verified_after_s']) ? (int) $verification['verified_after_s'] : null,
                'method' => $verification['method'] ?? 'telemetry',
            ],
            'failure_reason' => $c['failure_reason'],
        ];
    }

    private static function onVerified(int $companyId, array $c, array $verification, int $now): void
    {
        $projection = null;
        if ($c['waste_event_id'] !== null) {
            $event = Database::one('SELECT evidence FROM waste_events WHERE id = ?', [(int) $c['waste_event_id']]);
            $projection = (json_decode((string) ($event['evidence'] ?? ''), true) ?: [])['projection'] ?? null;
            Database::run(
                "UPDATE waste_events SET action_status = 'acted', command_id = ? WHERE id = ? AND company_id = ? AND action_status <> 'dismissed'",
                [(int) $c['id'], (int) $c['waste_event_id'], $companyId],
            );
            $key = Database::value('SELECT dedupe_key FROM waste_events WHERE id = ?', [(int) $c['waste_event_id']]);
            if ($key !== null) {
                AlertManager::resolve($companyId, (string) $key, (int) ($verification['verified_after_s'] ?? 0) + (int) $c['requested']);
            }
        }
        AuditLog::record($companyId, $c['requested_by'] === null ? null : (int) $c['requested_by'], 'command.verified', 'machine', (int) $c['machine_id'], [
            'command_id' => (int) $c['id'], 'verified_after_s' => $verification['verified_after_s'] ?? null,
        ]);
        NotificationService::push($companyId, 'achievement', 'command.verified', [
            'machine' => $c['name'],
            'code' => $c['code'],
            'seconds' => $verification['verified_after_s'] ?? null,
            'eur' => $projection['eur'] ?? null,
            'until' => $projection['until'] ?? null,
        ], $c['waste_event_id'] === null ? '/automations' : '/waste?event=' . $c['waste_event_id'], 'command', (int) $c['id'], $now);
    }

    private static function fail(int $companyId, array $c, string $reason, array $verification, int $now, ?string $detail = null): void
    {
        Database::run(
            "UPDATE device_commands SET status = 'failed', failure_reason = ?, verification = ? WHERE id = ?",
            [$detail === null ? $reason : mb_substr("{$reason}: {$detail}", 0, 200), json_encode($verification), (int) $c['id']],
        );
        AuditLog::record($companyId, null, 'command.failed', 'machine', (int) $c['machine_id'], ['command_id' => (int) $c['id'], 'reason' => $reason]);
        AlertManager::upsert($companyId, [
            'type' => 'COMMAND_FAILED',
            'method' => 'rule',
            'severity' => 'warning',
            'dedupe_key' => 'command_failed:' . $c['id'],
            'title_key' => 'command_failed',
            'params' => ['machine' => $c['name'], 'code' => $c['code'], 'serial' => $c['serial'], 'reason' => $reason],
            'evidence' => ['command_id' => (int) $c['id'], 'verification' => $verification],
            'machine_id' => (int) $c['machine_id'],
            'device_id' => (int) $c['device_id'],
            'opened_at' => $now,
            'last_seen_at' => $now,
            'link' => '/automations',
        ], $now);
    }

    private static function iso(int $ts): string
    {
        return Time::iso(gmdate('Y-m-d H:i:s', $ts));
    }
}
