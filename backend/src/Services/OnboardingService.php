<?php

declare(strict_types=1);

namespace EnergyFlow\Services;

use EnergyFlow\Core\Database;

/** Which setup steps a company has completed — derived from real data, never stored flags. */
final class OnboardingService
{
    /** @return list<array{key: string, done: bool}> */
    public static function steps(int $companyId): array
    {
        $company = Database::one(
            'SELECT nace_code, employees, annual_turnover_eur FROM companies WHERE id = ?',
            [$companyId],
        ) ?? [];

        $done = [
            'company' => ($company['nace_code'] ?? null) !== null
                && ($company['employees'] ?? null) !== null
                && ($company['annual_turnover_eur'] ?? null) !== null,
            'schedule' => Database::value(
                'SELECT 1 FROM schedules s JOIN schedule_rules r ON r.schedule_id = s.id WHERE s.company_id = ? LIMIT 1',
                [$companyId],
            ) !== null,
            'tariff' => Database::value(
                'SELECT 1 FROM tariff_plans WHERE company_id = ? AND is_active = 1 LIMIT 1',
                [$companyId],
            ) !== null,
            'device' => Database::value(
                'SELECT 1 FROM devices WHERE company_id = ? AND archived_at IS NULL LIMIT 1',
                [$companyId],
            ) !== null,
        ];

        return array_map(
            static fn (string $key, bool $isDone): array => ['key' => $key, 'done' => $isDone],
            array_keys($done),
            array_values($done),
        );
    }
}
