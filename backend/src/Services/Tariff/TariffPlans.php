<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Tariff;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;

/**
 * Published tariff groups (template plans with company_id NULL, values from the ERO decision)
 * and a company's own copy of one. Picking a group copies its template, windows and rates;
 * TariffBook prices everything with the copy that companies.tariff_plan_id points to.
 */
final class TariffPlans
{
    /** @return list<array<string, mixed>> TariffBook summaries of the published templates */
    public static function templates(): array
    {
        return array_map(
            static fn (array $row): array => TariffBook::forPlan((int) $row['id'])->summary(),
            Database::all('SELECT id FROM tariff_plans WHERE company_id IS NULL AND is_active = 1 ORDER BY category, id'),
        );
    }

    /** Moves the company onto its own copy of a published template. Earlier copies stay, inactive. */
    public static function choose(int $companyId, int $templateId): void
    {
        if (Database::value('SELECT 1 FROM tariff_plans WHERE id = ? AND company_id IS NULL AND is_active = 1', [$templateId]) === null) {
            throw HttpException::validation(['template_id' => 'invalid_choice']);
        }
        Database::transaction(static function () use ($companyId, $templateId): void {
            Database::run('UPDATE tariff_plans SET is_active = 0 WHERE company_id = ?', [$companyId]);
            Database::run('UPDATE companies SET tariff_plan_id = ? WHERE id = ?', [self::copy($templateId, $companyId), $companyId]);
        });
    }

    /** The company's own copy of a template plan, with its time windows and rates. */
    public static function copy(int $templateId, int $companyId): int
    {
        Database::run(
            'INSERT INTO tariff_plans (company_id, name, supplier, mode, voltage_level, category, fixed_monthly_eur, demand_charge_eur_kw_month,
                                       reactive_charge_eur_kvarh, reactive_pf_threshold, vat_rate, valid_from, valid_to, source_note, is_active)
             SELECT ?, name, supplier, mode, voltage_level, category, fixed_monthly_eur, demand_charge_eur_kw_month,
                    reactive_charge_eur_kvarh, reactive_pf_threshold, vat_rate, valid_from, valid_to, source_note, 1
               FROM tariff_plans WHERE id = ?',
            [$companyId, $templateId],
        );
        $planId = (int) Database::pdo()->lastInsertId();
        Database::run(
            'INSERT INTO tariff_windows (tariff_plan_id, season_start_mmdd, season_end_mmdd, period, start_time, end_time)
             SELECT ?, season_start_mmdd, season_end_mmdd, period, start_time, end_time FROM tariff_windows WHERE tariff_plan_id = ?',
            [$planId, $templateId],
        );
        Database::run(
            'INSERT INTO tariff_rates (tariff_plan_id, period, block_from_kwh, block_to_kwh, rate_eur_kwh)
             SELECT ?, period, block_from_kwh, block_to_kwh, rate_eur_kwh FROM tariff_rates WHERE tariff_plan_id = ?',
            [$planId, $templateId],
        );
        return $planId;
    }
}
