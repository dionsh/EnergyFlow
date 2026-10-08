<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Control\CommandService;
use EnergyFlow\Services\Control\PolicyEngine;
use EnergyFlow\Services\Telemetry\IngestService;

/**
 * Device-facing endpoints (EF-N3 firmware, tools/hw-bridge). Authenticated by DeviceAuth.
 *
 * Command times are on the company's clock — the same timeline as the readings
 * (the demo company runs on virtual time; for real customers it is real time).
 */
final class IngestController
{
    public function readings(Request $request, array $params): Response
    {
        $device = $request->get('device');
        $companyId = (int) $device['company_id'];
        $result = IngestService::storeDevicePayload($device, $request->json(), Clock::realNow());

        // Fresh readings can confirm a stop (verification by the meter) or trigger an auto-off policy.
        $now = Clock::now($companyId);
        CommandService::verify($companyId, $now);
        PolicyEngine::tick($companyId, $now);

        $pending = (int) Database::value(
            "SELECT COUNT(*) FROM device_commands WHERE device_id = ? AND status = 'queued' AND expires_at > FROM_UNIXTIME(?)",
            [(int) $device['id'], $now],
        );
        return Response::ok($result + ['pending_commands' => $pending]);
    }

    /** Commands waiting for this device. Fetching marks them as sent. */
    public function commands(Request $request, array $params): Response
    {
        $device = $request->get('device');
        $now = Clock::now((int) $device['company_id']);
        $commands = Database::all(
            "SELECT id, channel_no, command FROM device_commands
              WHERE device_id = ? AND status = 'queued' AND expires_at > FROM_UNIXTIME(?) ORDER BY requested_at",
            [(int) $device['id'], $now],
        );
        if ($commands !== []) {
            Database::run(
                "UPDATE device_commands SET status = 'sent', sent_at = FROM_UNIXTIME(?) WHERE id IN ("
                    . implode(',', array_map(static fn (array $c): int => (int) $c['id'], $commands)) . ')',
                [$now],
            );
        }
        return Response::ok(array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'ch' => (int) $c['channel_no'], 'command' => $c['command']], $commands));
    }

    /** The device reports what it did: acknowledged, executed (relay opened) or failed. */
    public function ack(Request $request, array $params): Response
    {
        $device = $request->get('device');
        $companyId = (int) $device['company_id'];
        $input = $request->json();
        $status = $input['status'] ?? '';
        if (!in_array($status, ['acknowledged', 'executed', 'failed'], true)) {
            return Response::error('validation_failed', 'Invalid status.', 422, ['status' => 'invalid_choice']);
        }
        $now = Clock::now($companyId);
        $id = (int) $params['id'];
        if ($status === 'failed') {
            $mine = Database::value(
                "SELECT 1 FROM device_commands WHERE id = ? AND device_id = ? AND status IN ('sent', 'acknowledged')",
                [$id, (int) $device['id']],
            );
            if ($mine !== null) {
                CommandService::deviceFailed($companyId, $id, mb_substr((string) ($input['detail'] ?? ''), 0, 150), $now);
            }
            return Response::ok(['updated' => $mine !== null]);
        }
        $updated = Database::run(
            "UPDATE device_commands
                SET status = ?, executed_at = IF(? = 'executed', FROM_UNIXTIME(?), executed_at)
              WHERE id = ? AND device_id = ? AND status IN ('sent', 'acknowledged')",
            [$status, $status, $now, $id, (int) $device['id']],
        );
        return Response::ok(['updated' => $updated === 1]);
    }
}
