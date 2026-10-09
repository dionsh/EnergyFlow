<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Admin;

use EnergyFlow\Core\Database;
use EnergyFlow\Utils\Time;

/**
 * What platform admins did (admin_actions). Separate from the companies' own
 * audit_log, and it keeps the admin's e-mail and a description of the target,
 * so the record still reads correctly after the user or company is deleted.
 */
final class AdminLog
{
    /**
     * @param array{id: int, email: string}|null $admin null for the command line
     * @param array<string, mixed> $data
     */
    public static function record(?array $admin, string $action, string $targetType, ?int $targetId, array $data = []): void
    {
        Database::run(
            'INSERT INTO admin_actions (admin_user_id, admin_email, action, target_type, target_id, data, created_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$admin['id'] ?? null, $admin['email'] ?? 'command line', $action, $targetType, $targetId, json_encode($data, JSON_UNESCAPED_UNICODE)],
        );
    }

    /** @return list<array<string, mixed>> newest first */
    public static function recent(int $limit = 15): array
    {
        $limit = max(1, min(100, $limit));
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'admin_email' => $r['admin_email'],
            'action' => $r['action'],
            'target_type' => $r['target_type'],
            'target_id' => $r['target_id'] === null ? null : (int) $r['target_id'],
            'data' => json_decode((string) $r['data'], true) ?: [],
            'created_at' => Time::iso($r['created_at']),
        ], Database::all("SELECT * FROM admin_actions ORDER BY id DESC LIMIT {$limit}"));
    }
}
