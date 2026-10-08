<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Services\AuditLog;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Detection\WasteReport;
use EnergyFlow\Utils\Time;

/** Alert lifecycle: open → acknowledged → resolved. Resolution is normally automatic. */
final class AlertController
{
    public function index(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $filters = Validator::validate($request->query, [
            'status' => ['nullable', 'in:open,acknowledged,resolved,active,all'],
            'severity' => ['nullable', 'in:info,warning,critical'],
        ]);
        $where = ['a.company_id = ?'];
        $args = [$companyId];
        $status = $filters['status'] ?? 'active';
        if ($status === 'active') {
            $where[] = "a.status <> 'resolved'";
        } elseif ($status !== 'all') {
            $where[] = 'a.status = ?';
            $args[] = $status;
        }
        if (isset($filters['severity'])) {
            $where[] = 'a.severity = ?';
            $args[] = $filters['severity'];
        }
        $rows = Database::all(
            "SELECT a.id, a.type, a.method, a.severity, a.status, a.title_key, a.params, a.waste_event_id, a.machine_id, a.device_id,
                    a.opened_at, a.last_seen_at, a.acknowledged_at, a.resolved_at, u.full_name AS acknowledged_by,
                    m.code AS machine_code, m.name AS machine_name, d.serial AS device_serial
               FROM alerts a
               LEFT JOIN users u ON u.id = a.acknowledged_by
               LEFT JOIN machines m ON m.id = a.machine_id
               LEFT JOIN devices d ON d.id = a.device_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY FIELD(a.status, 'open', 'acknowledged', 'resolved'), FIELD(a.severity, 'critical', 'warning', 'info'), a.opened_at DESC
              LIMIT 200",
            $args,
        );
        return Response::ok(
            array_map(static fn (array $a): array => [
                'id' => (int) $a['id'],
                'type' => $a['type'],
                'method' => $a['method'],
                'severity' => $a['severity'],
                'status' => $a['status'],
                'title_key' => $a['title_key'],
                'params' => json_decode((string) $a['params'], true) ?: [],
                'waste_event_id' => $a['waste_event_id'] === null ? null : (int) $a['waste_event_id'],
                'machine' => $a['machine_id'] === null ? null : ['id' => (int) $a['machine_id'], 'code' => $a['machine_code'], 'name' => $a['machine_name']],
                'device' => $a['device_id'] === null ? null : ['id' => (int) $a['device_id'], 'serial' => $a['device_serial']],
                'opened_at' => Time::iso($a['opened_at']),
                'last_seen_at' => Time::iso($a['last_seen_at']),
                'acknowledged_at' => Time::iso($a['acknowledged_at']),
                'acknowledged_by' => $a['acknowledged_by'],
                'resolved_at' => Time::iso($a['resolved_at']),
            ], $rows),
            ['counts' => WasteReport::openAlertCounts($companyId)],
        );
    }

    public function acknowledge(Request $request, array $params): Response
    {
        return $this->transition($request, (int) $params['id'], 'acknowledged');
    }

    public function resolve(Request $request, array $params): Response
    {
        return $this->transition($request, (int) $params['id'], 'resolved');
    }

    private function transition(Request $request, int $id, string $to): Response
    {
        $companyId = $request->companyId();
        $alert = Database::one('SELECT status FROM alerts WHERE id = ? AND company_id = ?', [$id, $companyId])
            ?? throw HttpException::notFound('alert_not_found', 'Alert not found.');
        if ($alert['status'] === 'resolved' || ($to === 'acknowledged' && $alert['status'] !== 'open')) {
            throw HttpException::conflict('alert_state', 'This alert has already moved on.');
        }
        $now = Clock::now($companyId);
        $userId = (int) $request->user()['id'];
        if ($to === 'acknowledged') {
            Database::run(
                "UPDATE alerts SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = FROM_UNIXTIME(?) WHERE id = ?",
                [$userId, $now, $id],
            );
        } else {
            Database::run("UPDATE alerts SET status = 'resolved', resolved_at = FROM_UNIXTIME(?) WHERE id = ?", [$now, $id]);
        }
        AuditLog::record($companyId, $userId, 'alert.' . ($to === 'acknowledged' ? 'acknowledge' : 'resolve'), 'alert', $id);
        return Response::ok(['id' => $id, 'status' => $to]);
    }
}
