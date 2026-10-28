<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Tariff;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;

/**
 * The landing page's bill check: what engaged power and reactive energy cost on one
 * month's bill, priced with a published template tariff (company_id NULL) through
 * TariffBook::bill(), the same rules as the dashboard and the LOW_PF / PEAK detectors.
 * Amounts exclude VAT; a year is the month times twelve.
 */
final class BillCheck
{
    /** @return array<string, mixed> */
    public static function run(string $category, float $kwh, float $kvarh, float $peakKw): array
    {
        $planId = Database::value(
            'SELECT id FROM tariff_plans WHERE company_id IS NULL AND is_active = 1 AND category = ? ORDER BY valid_from DESC LIMIT 1',
            [$category],
        );
        if ($planId === null) {
            throw HttpException::notFound('tariff_not_found', 'There is no published tariff for this group.');
        }
        $book = TariffBook::forPlan((int) $planId);
        // Only these two lines are used, so all kWh can go in as high tariff.
        $lines = array_column($book->bill($kwh, 0.0, $peakKw, $kvarh)['lines'], null, 'key');
        $reactive = $lines['reactive_energy'] ?? null;
        $engaged = $lines['engaged_power'] ?? null;
        $threshold = (float) $book->plan['reactive_pf_threshold'];

        return [
            'tariff' => ['category' => $book->plan['category'], 'valid_from' => $book->plan['valid_from']],
            'power_factor' => $kwh > 0 ? round($kwh / hypot($kwh, $kvarh), 3) : null,
            'reactive' => $reactive === null ? null : [
                'threshold' => $threshold,
                'allowed_kvarh' => round($kwh * tan(acos($threshold)), 1),
                'billed_kvarh' => $reactive['quantity'],
                'rate_eur_kvarh' => $reactive['rate'],
                'month_eur' => $reactive['amount'],
                'year_eur' => round($reactive['amount'] * 12, 2),
            ],
            'engaged_power' => $engaged === null ? null : [
                'kw' => $engaged['quantity'],
                'rate_eur_kw_month' => $engaged['rate'],
                'month_eur' => $engaged['amount'],
                'year_eur' => round($engaged['amount'] * 12, 2),
                'per_kw_year_eur' => round($engaged['rate'] * 12, 2),
            ],
        ];
    }
}
