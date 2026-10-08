<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Control\CommandService;
use EnergyFlow\Services\Devices\DeviceGateway;
use EnergyFlow\Utils\Time;

final class DeviceController
{
    public function index(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $now = Clock::now($companyId);
        $devices = Database::all(
            'SELECT id, serial, model, firmware_version, is_simulated, status, UNIX_TIMESTAMP(last_seen_at) AS last_seen, last_rssi,
                    last_uptime_s, buffered_count, installed_at
               FROM devices WHERE company_id = ? AND archived_at IS NULL ORDER BY serial',
            [$companyId],
        );
        $channels = $this->channels(array_map(static fn (array $d): int => (int) $d['id'], $devices));
        return Response::ok(array_map(fn (array $d): array => $this->present($d, $channels[(int) $d['id']] ?? [], $now), $devices));
    }

    public function show(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $device = Database::one(
            'SELECT id, serial, model, firmware_version, is_simulated, status, UNIX_TIMESTAMP(last_seen_at) AS last_seen, last_rssi,
                    last_uptime_s, buffered_count, installed_at
               FROM devices WHERE id = ? AND company_id = ? AND archived_at IS NULL',
            [(int) $params['id'], $companyId],
        ) ?? throw HttpException::notFound('device_not_found', 'Device not found.');
        $channels = $this->channels([(int) $device['id']]);
        return Response::ok($this->present($device, $channels[(int) $device['id']] ?? [], Clock::now($companyId)) + [
            // How the node is connected (simulated vs. the signed pull protocol of real hardware) and its command queue.
            'connection' => DeviceGateway::driverFor($device)->describe(),
            'commands' => CommandService::list($companyId, ['device_id' => (int) $device['id']], 10),
        ]);
    }

    /** @return array<int, list<array>> device_id => channels with the live electrical readings */
    private function channels(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $deviceIds));
        $out = [];
        foreach (Database::all(
            "SELECT dc.device_id, dc.channel_no, dc.measurement_mode, dc.ct_rating_a, dc.has_relay,
                    m.id AS machine_id, m.code, m.name, l.state, l.power_kw, l.voltage_v, l.current_a, l.power_factor,
                    l.frequency_hz, l.temperature_c, UNIX_TIMESTAMP(l.ts) AS ts
               FROM device_channels dc
               JOIN machines m ON m.id = dc.machine_id
               LEFT JOIN machine_live l ON l.machine_id = m.id
              WHERE dc.device_id IN ({$in}) AND dc.valid_to IS NULL
              ORDER BY dc.channel_no",
        ) as $c) {
            $out[(int) $c['device_id']][] = [
                'channel' => (int) $c['channel_no'],
                'measurement_mode' => $c['measurement_mode'],
                'ct_rating_a' => $c['ct_rating_a'] === null ? null : (int) $c['ct_rating_a'],
                'has_relay' => (bool) $c['has_relay'],
                'machine' => ['id' => (int) $c['machine_id'], 'code' => $c['code'], 'name' => $c['name']],
                'reading' => $c['ts'] === null ? null : [
                    'at' => Time::iso(gmdate('Y-m-d H:i:s', (int) $c['ts'])),
                    'state' => $c['state'],
                    'power_kw' => (float) $c['power_kw'],
                    'voltage_v' => $c['voltage_v'] === null ? null : (float) $c['voltage_v'],
                    'current_a' => $c['current_a'] === null ? null : (float) $c['current_a'],
                    'power_factor' => $c['power_factor'] === null ? null : (float) $c['power_factor'],
                    'frequency_hz' => $c['frequency_hz'] === null ? null : (float) $c['frequency_hz'],
                    'temperature_c' => $c['temperature_c'] === null ? null : (float) $c['temperature_c'],
                ],
            ];
        }
        return $out;
    }

    private function present(array $d, array $channels, int $now): array
    {
        $lastSeen = $d['last_seen'] === null ? null : (int) $d['last_seen'];
        $online = $lastSeen !== null && $now - $lastSeen <= 120;
        return [
            'id' => (int) $d['id'],
            'serial' => $d['serial'],
            'model' => $d['model'],
            'firmware_version' => $d['firmware_version'],
            'simulated' => (bool) $d['is_simulated'],
            'status' => $lastSeen === null ? 'provisioned' : ($online ? 'online' : 'offline'),
            'last_seen_at' => $lastSeen === null ? null : Time::iso(gmdate('Y-m-d H:i:s', $lastSeen)),
            // Age on the company clock (the demo runs on virtual time, so clients can't compute it).
            'last_seen_ago_s' => $lastSeen === null ? null : max(0, $now - $lastSeen),
            'signal_dbm' => $d['last_rssi'] === null ? null : (int) $d['last_rssi'],
            'uptime_s' => $d['last_uptime_s'] === null ? null : (int) $d['last_uptime_s'],
            'buffered' => $d['buffered_count'] === null ? null : (int) $d['buffered_count'],
            'installed_at' => Time::iso($d['installed_at']),
            'channels' => $channels,
        ];
    }
}
