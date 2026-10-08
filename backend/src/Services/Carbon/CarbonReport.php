<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Carbon;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Analytics\EnergyQuery;
use EnergyFlow\Services\Analytics\OverviewService;
use EnergyFlow\Services\Analytics\Period;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Impact\ImpactService;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

/**
 * Carbon & ESG (docs/03-architecture.md §12). Everything is derived from metered
 * electricity and the company's own declarations; what isn't known is reported
 * as missing, never estimated silently.
 *
 *   Scope 2 (location-based)  grid kWh × Kosovo grid factor (Ember 2025, lifecycle)
 *   Scope 2 (market-based)    not available: no Kosovo residual mix / no certificates
 *   Scope 1                   on-site fuels: declared by the company (or declared none)
 *   VSME B3                   datapoints with a status: auto · manual · estimated · missing
 */
final class CarbonReport
{
    public const ANSWERS = ['energy_policy', 'reduction_target', 'maintenance_plan', 'no_fuel_combustion', 'no_onsite_generation'];

    public static function summary(int $companyId, Period $period): array
    {
        $factor = EmissionFactors::details($companyId);
        $tariff = TariffBook::forCompany($companyId);
        $incomerId = OverviewService::incomerId($companyId);
        $byMachine = EnergyQuery::byMachine($companyId, $period->from, $period->to, null, $tariff);
        $site = EnergyQuery::site($byMachine, $incomerId);
        $previous = EnergyQuery::site(EnergyQuery::byMachine($companyId, $period->previousFrom, $period->previousTo, null, $tariff), $incomerId);
        $monitored = array_sum(array_map(static fn (array $v): float => $v['kwh'], array_filter($byMachine, static fn (int $id): bool => $id !== $incomerId, ARRAY_FILTER_USE_KEY)));

        $scope2 = $site['kwh'] * $factor['value'];
        $scope1 = self::scope1($companyId, $period->from, $period->to);
        $company = Database::one('SELECT annual_turnover_eur, is_demo, created_at FROM companies WHERE id = ?', [$companyId]);
        $days = max(1, ($period->to - $period->from) / 86400);
        $turnover = $company['annual_turnover_eur'] === null ? null : (float) $company['annual_turnover_eur'];
        $total = $scope2 + ($scope1['kg'] ?? 0.0);
        $output = Database::one(
            'SELECT SUM(quantity) AS q, MAX(unit) AS unit FROM production_output WHERE company_id = ? AND date >= ? AND date < ?',
            [$companyId, gmdate('Y-m-d', $period->from), gmdate('Y-m-d', $period->to)],
        );
        $impact = ImpactService::summary($companyId);

        return [
            'period' => $period->meta(),
            'electricity_kwh' => round($site['kwh'], 1),
            'scope2_location_kg' => round($scope2, 1),
            'scope2_market' => ['available' => false, 'reason' => 'no_residual_mix'],
            'scope1' => $scope1,
            'total_kg' => round($total, 1),
            'previous' => [
                'scope2_location_kg' => round($previous['kwh'] * $factor['value'], 1),
                'change_ratio' => $previous['kwh'] > 0 ? round($site['kwh'] / $previous['kwh'] - 1, 4) : null,
            ],
            'intensity' => [
                // Annualised from the period, per €1,000 of the turnover the company declared (VSME B1).
                'kg_per_k_eur_turnover' => $turnover > 0 ? round(($total * 365 / $days) / ($turnover / 1000), 2) : null,
                'kwh_per_unit' => $output !== null && (float) $output['q'] > 0 ? round($site['kwh'] / (float) $output['q'], 4) : null,
                'unit' => $output['unit'] ?? null,
            ],
            'avoided_verified_kg' => $impact['verified']['co2_kg'],
            'coverage' => $incomerId !== null && $site['kwh'] > 0 ? round(min(1.0, $monitored / $site['kwh']), 4) : null,
            'monthly' => self::monthly($companyId, $incomerId, $period->to),
            'factor' => $factor,
            'simulated_data' => (bool) $company['is_demo'],
        ];
    }

    /** By machine (with the unmonitored remainder), by department, or by tariff period. */
    public static function breakdown(int $companyId, Period $period, string $groupBy): array
    {
        $factor = EmissionFactors::gridFactor($companyId)['value'];
        $tariff = TariffBook::forCompany($companyId);
        $incomerId = OverviewService::incomerId($companyId);
        $byMachine = EnergyQuery::byMachine($companyId, $period->from, $period->to, null, $tariff);
        $site = EnergyQuery::site($byMachine, $incomerId);
        $machines = [];
        foreach (Database::all(
            'SELECT m.id, m.code, m.name, m.type_code, d.name AS department FROM machines m LEFT JOIN departments d ON d.id = m.department_id
              WHERE m.company_id = ? AND m.kind = ? AND m.archived_at IS NULL',
            [$companyId, 'machine'],
        ) as $m) {
            $machines[(int) $m['id']] = $m;
        }
        $row = static fn (string $key, string $label, float $kwh, array $extra = []): array => $extra + [
            'key' => $key, 'label' => $label, 'kwh' => round($kwh, 1), 'co2_kg' => round($kwh * $factor, 1),
            'share' => $site['kwh'] > 0 ? round($kwh / $site['kwh'], 4) : 0.0,
        ];

        $rows = [];
        if ($groupBy === 'tariff_period') {
            $rows[] = $row('high', 'high', $site['kwh_high']);
            $rows[] = $row('low', 'low', $site['kwh_low']);
        } else {
            $grouped = [];
            $monitored = 0.0;
            foreach ($machines as $id => $m) {
                $kwh = $byMachine[$id]['kwh'] ?? 0.0;
                $monitored += $kwh;
                $key = $groupBy === 'department' ? ($m['department'] ?? '—') : $m['code'];
                $grouped[$key] ??= ['label' => $groupBy === 'department' ? ($m['department'] ?? '—') : $m['name'], 'kwh' => 0.0,
                                    'machine' => $groupBy === 'machine' ? ['id' => $id, 'code' => $m['code'], 'type' => $m['type_code']] : null];
                $grouped[$key]['kwh'] += $kwh;
            }
            foreach ($grouped as $key => $g) {
                $rows[] = $row((string) $key, $g['label'], $g['kwh'], $g['machine'] === null ? [] : ['machine' => $g['machine']]);
            }
            usort($rows, static fn (array $a, array $b): int => $b['kwh'] <=> $a['kwh']);
            if ($incomerId !== null && $site['kwh'] - $monitored > 0.5) {
                $rows[] = $row('unmonitored', 'unmonitored', $site['kwh'] - $monitored);
            }
        }
        return ['period' => $period->meta(), 'group_by' => $groupBy, 'total_kwh' => round($site['kwh'], 1), 'total_co2_kg' => round($site['kwh'] * $factor, 1), 'rows' => $rows];
    }

    /**
     * VSME Basic Module B3 (energy and GHG emissions) for a calendar year, each
     * datapoint with its status. Paragraph-level wording must be checked against
     * the EFRAG text before claiming alignment (docs/01-research.md §4.3).
     */
    public static function vsmeB3(int $companyId, int $year): array
    {
        $time = LocalTime::forCompany($companyId);
        $now = Clock::now($companyId);
        $from = $time->at("{$year}-01-01");
        $to = min($now, $time->at(($year + 1) . '-01-01'));
        $monitoringFrom = Database::value('SELECT UNIX_TIMESTAMP(MIN(bucket_start)) FROM readings_15m WHERE company_id = ?', [$companyId]);
        $period = Period::parse("year:{$year}", $now, $time);
        $summary = self::summary($companyId, $period);
        $answers = self::answers($companyId);
        $partial = $monitoringFrom !== null && (int) $monitoringFrom > $from;
        $mwh = $summary['electricity_kwh'] / 1000;
        $scope1 = $summary['scope1'];
        $fuelsKnown = $scope1['status'] !== 'missing';
        $selfKnown = ($answers['no_onsite_generation'] ?? false) === true;
        $company = Database::one('SELECT annual_turnover_eur FROM companies WHERE id = ?', [$companyId]);

        $point = static fn (string $key, ?float $value, string $unit, string $status, string $source, array $note = []): array =>
            ['key' => $key, 'value' => $value === null ? null : round($value, 3), 'unit' => $unit, 'status' => $status, 'source' => $source] + $note;

        $ghgTotal = $summary['scope2_location_kg'] / 1000 + ($scope1['kg'] ?? 0) / 1000;
        $rows = [
            $point('electricity_grid', $mwh, 'MWh', 'auto', 'metered', $partial ? ['note' => 'partial_year', 'since' => Time::iso(gmdate('Y-m-d H:i:s', (int) $monitoringFrom))] : []),
            $point('electricity_renewable_share', null, '%', 'missing', 'supplier', ['note' => 'supplier_mix']),
            $point('self_generated', $selfKnown ? 0.0 : null, 'MWh', $selfKnown ? 'manual' : 'missing', 'declaration'),
            $point('fuels', $fuelsKnown ? ($scope1['kwh'] ?? 0) / 1000 : null, 'MWh', $fuelsKnown ? 'manual' : 'missing', 'declaration'),
            $point('energy_total', $mwh + ($fuelsKnown ? ($scope1['kwh'] ?? 0) / 1000 : 0), 'MWh', $fuelsKnown && $selfKnown ? 'auto' : 'estimated', 'metered', $fuelsKnown && $selfKnown ? [] : ['note' => 'electricity_only']),
            $point('scope1', $fuelsKnown ? ($scope1['kg'] ?? 0) / 1000 : null, 't CO2e', $fuelsKnown ? 'manual' : 'missing', 'declaration'),
            $point('scope2_location', $summary['scope2_location_kg'] / 1000, 't CO2e', 'auto', 'metered_factor'),
            $point('scope2_market', null, 't CO2e', 'not_available', 'market', ['note' => 'no_residual_mix']),
            $point('ghg_total', $ghgTotal, 't CO2e', $fuelsKnown ? 'auto' : 'estimated', 'metered_factor', $fuelsKnown ? [] : ['note' => 'scope2_only']),
            $point('ghg_intensity', $summary['intensity']['kg_per_k_eur_turnover'], 'kg CO2e / €1,000', $company['annual_turnover_eur'] === null ? 'missing' : ($fuelsKnown ? 'auto' : 'estimated'), 'turnover'),
        ];
        return [
            'year' => $year,
            'from' => Time::iso(gmdate('Y-m-d H:i:s', $from)),
            'to' => Time::iso(gmdate('Y-m-d H:i:s', $to)),
            'partial_year' => $partial,
            'rows' => $rows,
            'factor' => $summary['factor'],
            'simulated_data' => $summary['simulated_data'],
        ];
    }

    /** ESG readiness: a weighted checklist with the next three steps (B1 basics, B2 practices, B3 data). */
    public static function readiness(int $companyId): array
    {
        $company = Database::one('SELECT legal_form, business_number, nace_code, employees, annual_turnover_eur, emission_factor_id FROM companies WHERE id = ?', [$companyId]);
        $answers = self::answers($companyId);
        $now = Clock::now($companyId);
        $has = static fn (string $sql, array $args): bool => Database::value($sql, $args) !== null;
        $coverage = self::summary($companyId, Period::parse('30d', $now, LocalTime::forCompany($companyId)))['coverage'];

        $items = [
            ['key' => 'legal_form', 'group' => 'B1', 'weight' => 1, 'done' => $company['legal_form'] !== null, 'source' => 'company'],
            ['key' => 'business_number', 'group' => 'B1', 'weight' => 1, 'done' => $company['business_number'] !== null, 'source' => 'company'],
            ['key' => 'nace_code', 'group' => 'B1', 'weight' => 1, 'done' => $company['nace_code'] !== null, 'source' => 'company'],
            ['key' => 'employees', 'group' => 'B1', 'weight' => 1, 'done' => $company['employees'] !== null, 'source' => 'company'],
            ['key' => 'turnover', 'group' => 'B1', 'weight' => 2, 'done' => $company['annual_turnover_eur'] !== null, 'source' => 'company'],
            ['key' => 'energy_policy', 'group' => 'B2', 'weight' => 2, 'done' => ($answers['energy_policy'] ?? false) === true, 'source' => 'answer'],
            ['key' => 'reduction_target', 'group' => 'B2', 'weight' => 2, 'done' => ($answers['reduction_target'] ?? false) === true, 'source' => 'answer'],
            ['key' => 'schedules', 'group' => 'B2', 'weight' => 1, 'done' => $has('SELECT 1 FROM schedules s JOIN schedule_rules r ON r.schedule_id = s.id WHERE s.company_id = ? LIMIT 1', [$companyId]), 'source' => 'auto'],
            ['key' => 'automation', 'group' => 'B2', 'weight' => 1, 'done' => $has('SELECT 1 FROM automation_policies WHERE company_id = ? AND is_active = 1 LIMIT 1', [$companyId]), 'source' => 'auto'],
            ['key' => 'maintenance_plan', 'group' => 'B2', 'weight' => 1, 'done' => ($answers['maintenance_plan'] ?? false) === true, 'source' => 'answer'],
            ['key' => 'electricity_metered', 'group' => 'B3', 'weight' => 3, 'done' => $has('SELECT 1 FROM readings_15m WHERE company_id = ? LIMIT 1', [$companyId]), 'source' => 'auto'],
            ['key' => 'coverage', 'group' => 'B3', 'weight' => 2, 'done' => $coverage !== null && $coverage >= 0.8, 'source' => 'auto', 'value' => $coverage],
            ['key' => 'factor', 'group' => 'B3', 'weight' => 1, 'done' => $company['emission_factor_id'] !== null, 'source' => 'auto'],
            ['key' => 'fuels', 'group' => 'B3', 'weight' => 2, 'done' => ($answers['no_fuel_combustion'] ?? false) === true || $has('SELECT 1 FROM activity_data WHERE company_id = ? LIMIT 1', [$companyId]), 'source' => 'answer'],
            ['key' => 'onsite_generation', 'group' => 'B3', 'weight' => 1, 'done' => ($answers['no_onsite_generation'] ?? false) === true, 'source' => 'answer'],
        ];
        $total = array_sum(array_column($items, 'weight'));
        $done = array_sum(array_map(static fn (array $i): int => $i['done'] ? $i['weight'] : 0, $items));
        $open = array_values(array_filter($items, static fn (array $i): bool => !$i['done']));
        usort($open, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return [
            'score' => round($done / $total, 3),
            'groups' => array_map(static function (string $group) use ($items): array {
                $g = array_values(array_filter($items, static fn (array $i): bool => $i['group'] === $group));
                return ['group' => $group, 'done' => count(array_filter($g, static fn (array $i): bool => $i['done'])), 'total' => count($g)];
            }, ['B1', 'B2', 'B3']),
            'items' => $items,
            'next_steps' => array_map(static fn (array $i): string => $i['key'], array_slice($open, 0, 3)),
            'answers' => $answers,
        ];
    }

    /** @return array<string, mixed> item_key => value */
    public static function answers(int $companyId): array
    {
        $out = [];
        foreach (Database::all('SELECT item_key, value FROM esg_answers WHERE company_id = ?', [$companyId]) as $a) {
            $out[$a['item_key']] = json_decode((string) $a['value'], true);
        }
        return $out;
    }

    public static function saveAnswer(int $companyId, int $userId, string $key, mixed $value): void
    {
        Database::run(
            'INSERT INTO esg_answers (company_id, item_key, value, updated_by, updated_at) VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$companyId, $key, json_encode($value), $userId, Clock::now($companyId)],
        );
    }

    /** Scope 1 from declared fuel use; "missing" until the company declares it (or declares none). */
    private static function scope1(int $companyId, int $from, int $to): array
    {
        $row = Database::one(
            'SELECT COUNT(*) AS n, SUM(co2e_kg) AS kg FROM activity_data WHERE company_id = ? AND period_month >= ? AND period_month < ?',
            [$companyId, gmdate('Y-m-01', $from), gmdate('Y-m-d', $to)],
        );
        if ((int) $row['n'] > 0) {
            return ['status' => 'manual', 'kg' => round((float) $row['kg'], 1), 'kwh' => null];
        }
        if ((self::answers($companyId)['no_fuel_combustion'] ?? false) === true) {
            return ['status' => 'declared_none', 'kg' => 0.0, 'kwh' => 0.0];
        }
        return ['status' => 'missing', 'kg' => null, 'kwh' => null];
    }

    /** Site electricity and CO₂e per month, from the ledger, last 12 months. */
    private static function monthly(int $companyId, ?int $incomerId, int $to): array
    {
        $filter = $incomerId === null
            ? " AND machine_id IN (SELECT id FROM machines WHERE company_id = ? AND kind = 'machine')"
            : ' AND machine_id = ?';
        return array_map(static fn (array $r): array => [
            'month' => $r['m'], 'kwh' => round((float) $r['kwh'], 1), 'co2_kg' => round((float) $r['co2'], 1), 'days' => (int) $r['days'],
        ], Database::all(
            "SELECT DATE_FORMAT(date, '%Y-%m') AS m, SUM(kwh) AS kwh, SUM(co2e_kg) AS co2, COUNT(DISTINCT date) AS days FROM carbon_records
              WHERE company_id = ? AND date >= ? {$filter} GROUP BY m ORDER BY m",
            [$companyId, gmdate('Y-m-01', $to - 335 * 86400), $incomerId ?? $companyId],
        ));
    }
}
