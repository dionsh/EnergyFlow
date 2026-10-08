<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Detection;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Notifications\NotificationService;

/**
 * Alert lifecycle: open → acknowledged → resolved (docs/03-architecture.md §7).
 *
 * Alerts are de-duplicated by `dedupe_key`. Episodic keys (one waste episode =
 * one alert) are updated in place, even after they were resolved, because
 * re-running detection over the same data must never create duplicates.
 * Recurring keys (a device that goes offline again) open a new alert once the
 * previous one was resolved.
 *
 * A notification is sent when an alert opens or escalates — but only for
 * recent events, so back-filling 75 days of history never floods the bell.
 */
final class AlertManager
{
    private const NOTIFY_WINDOW_S = 86400;

    /**
     * @param array{type: string, method: string, severity: string, dedupe_key: string, title_key: string,
     *              params: array, evidence: array, machine_id?: ?int, device_id?: ?int, waste_event_id?: ?int,
     *              opened_at: int, last_seen_at: int, resolved_at?: ?int, link?: ?string} $alert
     * @return int alert id
     */
    public static function upsert(int $companyId, array $alert, int $now, bool $episodic = true): int
    {
        $existing = Database::one(
            'SELECT id, status, severity FROM alerts WHERE company_id = ? AND dedupe_key = ? ORDER BY opened_at DESC, id DESC LIMIT 1',
            [$companyId, $alert['dedupe_key']],
        );
        $resolvedAt = $alert['resolved_at'] ?? null;
        $params = json_encode($alert['params'], JSON_UNESCAPED_UNICODE);
        $evidence = json_encode($alert['evidence'], JSON_UNESCAPED_UNICODE);

        if ($existing !== null && ($episodic || $existing['status'] !== 'resolved')) {
            $id = (int) $existing['id'];
            Database::run(
                "UPDATE alerts SET severity = ?, params = ?, evidence = ?, waste_event_id = COALESCE(?, waste_event_id),
                        last_seen_at = FROM_UNIXTIME(?),
                        status = CASE WHEN ? IS NOT NULL THEN 'resolved' ELSE status END,
                        resolved_at = CASE WHEN ? IS NOT NULL THEN COALESCE(resolved_at, FROM_UNIXTIME(?)) ELSE resolved_at END
                  WHERE id = ?",
                [$alert['severity'], $params, $evidence, $alert['waste_event_id'] ?? null, $alert['last_seen_at'],
                 $resolvedAt, $resolvedAt, $resolvedAt, $id],
            );
            $escalated = SeverityPolicy::rank($alert['severity']) > SeverityPolicy::rank((string) $existing['severity']);
            if ($escalated && $resolvedAt === null && $existing['status'] !== 'resolved' && $now - $alert['last_seen_at'] < self::NOTIFY_WINDOW_S) {
                self::notify($companyId, $id, $alert, 'escalated', $now);
            }
            return $id;
        }

        $id = Database::insert(
            "INSERT INTO alerts (company_id, machine_id, device_id, type, method, severity, status, dedupe_key, title_key, params, evidence,
                                 waste_event_id, opened_at, last_seen_at, resolved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), ?)",
            [$companyId, $alert['machine_id'] ?? null, $alert['device_id'] ?? null, $alert['type'], $alert['method'], $alert['severity'],
             $resolvedAt === null ? 'open' : 'resolved', $alert['dedupe_key'], $alert['title_key'], $params, $evidence,
             $alert['waste_event_id'] ?? null, $alert['opened_at'], $alert['last_seen_at'],
             $resolvedAt === null ? null : gmdate('Y-m-d H:i:s', $resolvedAt)],
        );
        if ($resolvedAt === null && $now - $alert['opened_at'] < self::NOTIFY_WINDOW_S) {
            self::notify($companyId, $id, $alert, 'opened', $now);
        }
        return $id;
    }

    /** Closes every unresolved alert with this key (the condition has cleared). */
    public static function resolve(int $companyId, string $dedupeKey, int $at): void
    {
        Database::run(
            "UPDATE alerts SET status = 'resolved', resolved_at = FROM_UNIXTIME(?) WHERE company_id = ? AND dedupe_key = ? AND status <> 'resolved'",
            [$at, $companyId, $dedupeKey],
        );
    }

    private static function notify(int $companyId, int $alertId, array $alert, string $event, int $now): void
    {
        NotificationService::push(
            $companyId,
            SeverityPolicy::category($alert['severity']),
            'alert.' . $alert['title_key'] . ($event === 'escalated' ? '.escalated' : ''),
            $alert['params'],
            $alert['link'] ?? '/waste?tab=alerts',
            'alert',
            $alertId,
            $now,
        );
    }
}
