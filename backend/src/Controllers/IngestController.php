<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Telemetry\IngestService;

/** Device-facing endpoints (EF-N3 firmware, hw-bridge). Authenticated by DeviceAuth. */
final class IngestController
{
    public function readings(Request $request, array $params): Response
    {
        $device = $request->get('device');
        $result = IngestService::storeDevicePayload($device, $request->json(), Clock::realNow());
        $pending = (int) Database::value(
            "SELECT COUNT(*) FROM device_commands WHERE device_id = ? AND status = 'queued' AND expires_at > UTC_TIMESTAMP()",
            [(int) $device['id']],
        );
        return Response::ok($result + ['pending_commands' => $pending]);
    }

    /** Commands waiting for this device. Fetching marks them as sent. */
    public function commands(Request $request, array $params): Response
    {
        $device = $request->get('device');
        $commands = Database::all(
            "SELECT id, channel_no, command FROM device_commands
              WHERE device_id = ? AND status = 'queued' AND expires_at > UTC_TIMESTAMP() ORDER BY requested_at",
            [(int) $device['id']],
        );
        if ($commands !== []) {
            Database::run(
                "UPDATE device_commands SET status = 'sent', sent_at = UTC_TIMESTAMP() WHERE id IN (" . implode(',', array_map(static fn (array $c): int => (int) $c['id'], $commands)) . ')',
            );
        }
        return Response::ok(array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'ch' => (int) $c['channel_no'], 'command' => $c['command']], $commands));
    }

    public function ack(Request $request, array $params): Response
    {
        $device = $request->get('device');
        $status = $request->json()['status'] ?? '';
        if (!in_array($status, ['acknowledged', 'executed', 'failed'], true)) {
            return Response::error('validation_failed', 'Invalid status.', 422, ['status' => 'invalid_choice']);
        }
        $updated = Database::run(
            "UPDATE device_commands
                SET status = ?, executed_at = IF(? = 'executed', UTC_TIMESTAMP(), executed_at), failure_reason = ?
              WHERE id = ? AND device_id = ? AND status IN ('sent', 'acknowledged')",
            [$status, $status, $status === 'failed' ? mb_substr((string) ($request->json()['detail'] ?? ''), 0, 200) : null, (int) $params['id'], (int) $device['id']],
        );
        return Response::ok(['updated' => $updated === 1]);
    }
}
