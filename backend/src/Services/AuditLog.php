<?php

declare(strict_types=1);

namespace EnergyFlow\Services;

use EnergyFlow\Core\Database;

/** Who did what, when and why: commands, policies, dismissals, settings (docs/03 §15). */
final class AuditLog
{
    /** @param array<string, mixed> $data */
    public static function record(int $companyId, ?int $userId, string $action, string $entityType, ?int $entityId, array $data = []): void
    {
        Database::run(
            'INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, data, created_at) VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))',
            [$companyId, $userId, $action, $entityType, $entityId, json_encode($data, JSON_UNESCAPED_UNICODE), Clock::now($companyId)],
        );
    }
}
