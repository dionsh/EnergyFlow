<?php

declare(strict_types=1);

namespace EnergyFlow\Models;

use EnergyFlow\Core\Database;
use EnergyFlow\Utils\Time;

final class Company
{
    /** Columns a company admin may edit (VSME B1 basics). */
    public const EDITABLE = [
        'name', 'legal_form', 'business_number', 'nace_code', 'employees',
        'annual_turnover_eur', 'city', 'locale',
    ];

    private const PUBLIC_COLUMNS = 'id, name, legal_form, business_number, nace_code, employees,
        annual_turnover_eur, country, city, timezone, currency, locale, emission_factor_id, is_demo, created_at';

    public static function find(int $id): ?array
    {
        $row = Database::one('SELECT ' . self::PUBLIC_COLUMNS . ' FROM companies WHERE id = ?', [$id]);
        return $row === null ? null : self::present($row);
    }

    public static function create(string $name, ?string $city, string $locale): int
    {
        $factorId = Database::value(
            "SELECT id FROM emission_factors WHERE company_id IS NULL AND is_default = 1
               AND activity = 'grid_electricity' AND region = 'XK' ORDER BY valid_from DESC LIMIT 1",
        );
        return Database::insert(
            'INSERT INTO companies (name, city, locale, emission_factor_id) VALUES (?, ?, ?, ?)',
            [$name, $city, $locale, $factorId === null ? null : (int) $factorId],
        );
    }

    /** @param array<string, mixed> $fields already validated, keys ⊆ EDITABLE */
    public static function update(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::EDITABLE));
        if ($fields === []) {
            return;
        }
        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = :{$column}", array_keys($fields)));
        Database::run("UPDATE companies SET {$assignments} WHERE id = :id", $fields + ['id' => $id]);
    }

    private static function present(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['employees'] = $row['employees'] === null ? null : (int) $row['employees'];
        $row['annual_turnover_eur'] = $row['annual_turnover_eur'] === null ? null : (float) $row['annual_turnover_eur'];
        $row['emission_factor_id'] = $row['emission_factor_id'] === null ? null : (int) $row['emission_factor_id'];
        $row['is_demo'] = (bool) $row['is_demo'];
        return Time::isoColumns($row, ['created_at']);
    }
}
