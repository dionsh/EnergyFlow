<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Telemetry;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Simulation\MachineModel;

/**
 * The single entry point for measurements. Real EF-N3 nodes (via the ingest API)
 * and the simulator both end up in store(), so everything downstream treats
 * simulated and real data identically.
 */
final class IngestService
{
    private const STATES = [MachineModel::OFF => 'off', MachineModel::IDLE => 'idle', MachineModel::RUNNING => 'running'];
    private const STATE_CODES = ['off' => MachineModel::OFF, 'idle' => MachineModel::IDLE, 'running' => MachineModel::RUNNING, 'abnormal' => MachineModel::RUNNING];

    /**
     * @param list<array{machine_id: int, ts: int, kw: float, v?: ?float, i?: ?float, pf?: ?float, f?: ?float, e?: ?float, temp?: ?float}> $rows
     * @return array{accepted: int, duplicates: int}
     */
    public static function store(int $companyId, array $rows, string $source): array
    {
        if ($rows === []) {
            return ['accepted' => 0, 'duplicates' => 0];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['machine_id'], $a['ts']] <=> [$b['machine_id'], $b['ts']]);

        $machineIds = array_values(array_unique(array_column($rows, 'machine_id')));
        $in = implode(',', array_fill(0, count($machineIds), '?'));
        $machines = [];
        foreach (Database::all(
            "SELECT m.id, m.off_threshold_kw, m.idle_threshold_kw,
                    (SELECT dc.device_id FROM device_channels dc WHERE dc.machine_id = m.id AND dc.valid_to IS NULL ORDER BY dc.valid_from DESC LIMIT 1) AS device_id
               FROM machines m WHERE m.company_id = ? AND m.id IN ({$in})",
            [$companyId, ...$machineIds],
        ) as $m) {
            $machines[(int) $m['id']] = $m;
        }
        $live = [];
        foreach (Database::all(
            "SELECT machine_id, state, UNIX_TIMESTAMP(state_since) AS since, UNIX_TIMESTAMP(ts) AS ts FROM machine_live WHERE machine_id IN ({$in})",
            $machineIds,
        ) as $l) {
            $live[(int) $l['machine_id']] = $l;
        }

        $raw = [];
        $events = [];
        $latest = [];
        $state = [];
        $since = [];
        foreach ($rows as $row) {
            $id = $row['machine_id'];
            $m = $machines[$id] ?? null;
            if ($m === null) {
                continue;
            }
            $code = self::stateFor($m, (float) $row['kw']);
            if (!array_key_exists($id, $state)) {
                $state[$id] = isset($live[$id]) ? self::STATE_CODES[$live[$id]['state']] : null;
                $since[$id] = isset($live[$id]) ? (int) $live[$id]['since'] : $row['ts'];
                if (isset($live[$id]) && $row['ts'] <= (int) $live[$id]['ts']) {
                    // Older than what we already have (replayed buffer): store raw, don't touch live state.
                    $state[$id] = null;
                }
            }
            if ($state[$id] !== null && $code !== $state[$id]) {
                $events[] = [$id, $row['ts'], $state[$id], $code, $row['kw']];
                $since[$id] = $row['ts'];
            } elseif ($state[$id] === null && !isset($live[$id])) {
                $since[$id] = $row['ts'];
            }
            $state[$id] = $code;
            $raw[] = [$id, $row['ts'], (int) ($m['device_id'] ?? 0), $row, $code];
            $latest[$id] = [$row, $code, $since[$id]];
        }

        $accepted = 0;
        foreach (array_chunk($raw, 400) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as [$id, $ts, $deviceId, $row, $code]) {
                $placeholders[] = '(?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params, $id, $ts, $deviceId, round((float) $row['kw'], 3),
                    $row['v'] ?? null, $row['i'] ?? null, $row['pf'] ?? null, $row['f'] ?? null, $row['e'] ?? null,
                    $row['temp'] ?? null, $code, $source,
                );
            }
            $accepted += Database::run(
                'INSERT IGNORE INTO readings_raw
                   (machine_id, ts, device_id, power_kw, voltage_v, current_a, power_factor, frequency_hz, energy_kwh_counter, temperature_c, state, source)
                 VALUES ' . implode(',', $placeholders),
                $params,
            );
        }

        foreach (array_chunk($events, 300) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as [$id, $ts, $from, $to, $kw]) {
                $placeholders[] = '(?, FROM_UNIXTIME(?), ?, ?, ?)';
                array_push($params, $id, $ts, $from, $to, round((float) $kw, 3));
            }
            Database::run('INSERT INTO machine_state_events (machine_id, ts, from_state, to_state, power_kw) VALUES ' . implode(',', $placeholders), $params);
        }

        foreach ($latest as $id => [$row, $code, $stateSince]) {
            if (isset($live[$id]) && $row['ts'] <= (int) $live[$id]['ts']) {
                continue;
            }
            Database::run(
                'INSERT INTO machine_live (machine_id, company_id, ts, state, state_since, power_kw, voltage_v, current_a, power_factor, frequency_hz, temperature_c)
                 VALUES (?, ?, FROM_UNIXTIME(?), ?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE ts = VALUES(ts), state = VALUES(state), state_since = VALUES(state_since),
                   power_kw = VALUES(power_kw), voltage_v = VALUES(voltage_v), current_a = VALUES(current_a),
                   power_factor = VALUES(power_factor), frequency_hz = VALUES(frequency_hz), temperature_c = VALUES(temperature_c)',
                [$id, $companyId, $row['ts'], self::STATES[$code], $stateSince, round((float) $row['kw'], 3),
                 $row['v'] ?? null, $row['i'] ?? null, $row['pf'] ?? null, $row['f'] ?? null, $row['temp'] ?? null],
            );
        }

        return ['accepted' => $accepted, 'duplicates' => count($raw) - $accepted];
    }

    /**
     * Validates and stores a payload from a real device (protocol v1, see hardware/README.md §5).
     *
     * @return array{accepted: int, duplicates: int, rejected: int, events: int}
     */
    public static function storeDevicePayload(array $device, array $payload, int $now): array
    {
        if (($payload['device'] ?? null) !== $device['serial']) {
            throw HttpException::badRequest('device_mismatch', 'The payload device does not match the authenticated device.');
        }
        $companyId = (int) $device['company_id'];
        $channels = [];
        foreach (Database::all('SELECT channel_no, machine_id FROM device_channels WHERE device_id = ? AND valid_to IS NULL', [(int) $device['id']]) as $c) {
            $channels[(int) $c['channel_no']] = (int) $c['machine_id'];
        }
        // The demo company runs on a virtual clock; a real node on stage reports real
        // time, so its readings are shifted onto the company's timeline (offset is 0
        // for every real customer).
        $offset = Clock::offset($companyId);

        $rows = [];
        $rejected = 0;
        foreach (array_slice((array) ($payload['readings'] ?? []), 0, 2000) as $r) {
            $ts = is_string($r['ts'] ?? null) ? strtotime($r['ts']) : false;
            $machineId = $channels[(int) ($r['ch'] ?? 0)] ?? null;
            $kw = $r['p_kw'] ?? null;
            if ($ts === false || $machineId === null || !is_numeric($kw)
                || $ts > $now + 300 || $ts < $now - 7 * 86400
                || $kw < 0 || $kw > 10000
                || (isset($r['pf']) && ($r['pf'] < 0 || $r['pf'] > 1))
                || (isset($r['v']) && ($r['v'] < 0 || $r['v'] > 500))
                || (isset($r['f']) && ($r['f'] < 40 || $r['f'] > 70))) {
                $rejected++;
                continue;
            }
            $rows[] = [
                'machine_id' => $machineId,
                'ts' => $ts + $offset,
                'kw' => (float) $kw,
                'v' => isset($r['v']) ? (float) $r['v'] : null,
                'i' => isset($r['i']) ? (float) $r['i'] : null,
                'pf' => isset($r['pf']) ? (float) $r['pf'] : null,
                'f' => isset($r['f']) ? (float) $r['f'] : null,
                'e' => isset($r['e_kwh']) ? (float) $r['e_kwh'] : null,
                'temp' => isset($r['t_c']) ? (float) $r['t_c'] : null,
            ];
        }

        $result = self::store($companyId, $rows, 'device');
        $events = self::storeEvents($device, $channels, (array) ($payload['events'] ?? []), $now, $offset);

        $status = (array) ($payload['status'] ?? []);
        Database::run(
            "UPDATE devices SET status = 'online', last_seen_at = FROM_UNIXTIME(?), firmware_version = COALESCE(?, firmware_version),
                    last_rssi = ?, last_uptime_s = ?, buffered_count = ? WHERE id = ?",
            [$now + $offset, isset($payload['fw']) ? mb_substr((string) $payload['fw'], 0, 20) : null,
             isset($status['rssi']) ? (int) $status['rssi'] : null,
             isset($status['uptime_s']) ? (int) $status['uptime_s'] : null,
             isset($status['buffered']) ? (int) $status['buffered'] : null,
             (int) $device['id']],
        );

        return $result + ['rejected' => $rejected, 'events' => $events];
    }

    /** HOLD → production override; outage → power-quality log; start → start-up peak current. */
    private static function storeEvents(array $device, array $channels, array $events, int $now, int $offset): int
    {
        $companyId = (int) $device['company_id'];
        $stored = 0;
        foreach (array_slice($events, 0, 200) as $event) {
            $ts = is_string($event['ts'] ?? null) ? strtotime($event['ts']) : false;
            if ($ts === false || $ts > $now + 300) {
                continue;
            }
            $ts += $offset;
            switch ($event['type'] ?? '') {
                case 'hold':
                    $minutes = max(15, min(480, (int) ($event['minutes'] ?? 120)));
                    foreach (array_unique(array_values($channels)) as $machineId) {
                        Database::run(
                            'INSERT INTO production_overrides (company_id, machine_id, starts_at, ends_at, reason) VALUES (?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), ?)',
                            [$companyId, $machineId, $ts, $ts + $minutes * 60, 'HOLD pressed on ' . $device['serial']],
                        );
                    }
                    $stored++;
                    break;
                case 'outage':
                    Database::run(
                        "INSERT INTO power_quality_events (company_id, device_id, ts, kind, duration_s) VALUES (?, ?, FROM_UNIXTIME(?), 'outage', ?)",
                        [$companyId, (int) $device['id'], $ts, isset($event['duration_s']) ? (float) $event['duration_s'] : null],
                    );
                    $stored++;
                    break;
                case 'sag':
                case 'swell':
                    Database::run(
                        'INSERT INTO power_quality_events (company_id, device_id, ts, kind, duration_s, min_v, max_v) VALUES (?, ?, FROM_UNIXTIME(?), ?, ?, ?, ?)',
                        [$companyId, (int) $device['id'], $ts, $event['type'], $event['duration_s'] ?? null, $event['min_v'] ?? null, $event['max_v'] ?? null],
                    );
                    $stored++;
                    break;
                case 'start':
                    $machineId = $channels[(int) ($event['ch'] ?? 0)] ?? null;
                    if ($machineId !== null && isset($event['peak_a'])) {
                        Database::run(
                            'UPDATE machine_state_events SET peak_current_a = ? WHERE machine_id = ? AND ts BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?) ORDER BY ts LIMIT 1',
                            [(float) $event['peak_a'], $machineId, $ts - 30, $ts + 30],
                        );
                        $stored++;
                    }
                    break;
            }
        }
        return $stored;
    }

    private static function stateFor(array $m, float $kw): int
    {
        $off = (float) $m['off_threshold_kw'];
        $idle = $m['idle_threshold_kw'] === null ? $off : (float) $m['idle_threshold_kw'];
        return $kw < $off ? MachineModel::OFF : ($kw < $idle ? MachineModel::IDLE : MachineModel::RUNNING);
    }
}
