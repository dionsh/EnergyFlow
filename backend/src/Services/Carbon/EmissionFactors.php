<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Carbon;

use EnergyFlow\Core\Database;

/** Resolves the grid emission factor a company uses (its own choice, else the regional default). */
final class EmissionFactors
{
    /** @var array<int, array> */
    private static array $cache = [];

    /** @return array{id: int, value: float, unit: string, reference_year: int, methodology: string, source_name: string, source_url: ?string, region: ?string, notes: ?string} */
    public static function gridFactor(int $companyId): array
    {
        return self::$cache[$companyId] ??= self::resolve($companyId);
    }

    /** Same factor, for display: the Methodology panel shows its caveats in full. */
    public static function details(int $companyId): array
    {
        return self::gridFactor($companyId);
    }

    private static function resolve(int $companyId): array
    {
        $row = Database::one(
            'SELECT f.* FROM companies c JOIN emission_factors f ON f.id = c.emission_factor_id WHERE c.id = ?',
            [$companyId],
        ) ?? Database::one(
            "SELECT * FROM emission_factors WHERE company_id IS NULL AND is_default = 1 AND activity = 'grid_electricity'
              ORDER BY valid_from DESC LIMIT 1",
        );
        return [
            'id' => (int) $row['id'],
            'value' => (float) $row['value'],
            'unit' => $row['unit'],
            'reference_year' => (int) $row['reference_year'],
            'methodology' => $row['methodology'],
            'source_name' => $row['source_name'],
            'source_url' => $row['source_url'],
            'region' => $row['region'],
            'notes' => $row['notes'],
        ];
    }
}
