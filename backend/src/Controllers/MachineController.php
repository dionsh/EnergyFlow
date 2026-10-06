<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Services\Analytics\EnergyQuery;
use EnergyFlow\Services\Analytics\LiveService;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Carbon\EmissionFactors;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

final class MachineController
{
    /** All machines with live state and this month's energy, cost, CO₂e and share of the site. */
    public function index(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $live = LiveService::snapshot($companyId);
        $now = Clock::now($companyId);
        $time = new LocalTime($this->timezone($companyId));
        $tariff = TariffBook::forCompany($companyId);
        $factor = EmissionFactors::gridFactor($companyId);
        $month = EnergyQuery::byMachine($companyId, $time->startOfMonth($now), $now, null, $tariff);
        $total = array_sum(array_map(static fn (array $m): float => $month[$m['id']]['kwh'] ?? 0.0, $live['machines']));

        $machines = array_map(static function (array $m) use ($month, $tariff, $factor, $total): array {
            $energy = $month[$m['id']] ?? ['kwh' => 0.0, 'kwh_high' => 0.0, 'kwh_low' => 0.0, 'kvarh' => 0.0];
            return $m + [
                'month' => [
                    'kwh' => round($energy['kwh'], 1),
                    'eur' => round(EnergyQuery::energyCost($energy, $tariff), 2),
                    'co2_kg' => round($energy['kwh'] * $factor['value'], 1),
                    'share' => $total > 0 ? round($energy['kwh'] / $total, 4) : 0.0,
                ],
            ];
        }, $live['machines']);

        return Response::ok($machines);
    }

    public function show(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $id = (int) $params['id'];
        $machine = Database::one(
            'SELECT m.id, m.code, m.name, m.type_code, m.kind, m.manufacturer, m.model, m.year_installed, m.rated_power_kw, m.phases,
                    m.criticality, m.control_mode, m.idle_threshold_kw, s.name AS schedule_name, dp.name AS department
               FROM machines m
               LEFT JOIN schedules s ON s.id = m.schedule_id
               LEFT JOIN departments dp ON dp.id = m.department_id
              WHERE m.id = ? AND m.company_id = ? AND m.archived_at IS NULL',
            [$id, $companyId],
        ) ?? throw HttpException::notFound('machine_not_found', 'Machine not found.');

        $live = null;
        foreach (LiveService::snapshot($companyId)['machines'] as $m) {
            if ($m['id'] === $id) {
                $live = $m;
            }
        }

        $now = Clock::now($companyId);
        $time = new LocalTime($this->timezone($companyId));
        $tariff = TariffBook::forCompany($companyId);
        $factor = EmissionFactors::gridFactor($companyId);
        $summarise = static function (int $from) use ($companyId, $id, $now, $tariff, $factor): array {
            $e = EnergyQuery::byMachine($companyId, $from, $now, $id, $tariff)[$id] ?? ['kwh' => 0.0, 'kwh_high' => 0.0, 'kwh_low' => 0.0, 'kvarh' => 0.0];
            return ['kwh' => round($e['kwh'], 1), 'eur' => round(EnergyQuery::energyCost($e, $tariff), 2), 'co2_kg' => round($e['kwh'] * $factor['value'], 1)];
        };

        // Operating hours over the last 7 days, in vs. outside schedule.
        $hours = Database::one(
            'SELECT SUM(CASE WHEN is_scheduled = 1 THEN running_s + idle_s ELSE 0 END) / 3600 AS in_schedule,
                    SUM(CASE WHEN is_scheduled = 0 THEN running_s + idle_s ELSE 0 END) / 3600 AS outside,
                    SUM(running_s) / 3600 AS running, SUM(idle_s) / 3600 AS idle
               FROM readings_15m WHERE machine_id = ? AND bucket_start >= FROM_UNIXTIME(?)',
            [$id, $now - 7 * 86400],
        );

        return Response::ok([
            'machine' => [
                'id' => (int) $machine['id'],
                'code' => $machine['code'],
                'name' => $machine['name'],
                'type' => $machine['type_code'],
                'kind' => $machine['kind'],
                'rated_power_kw' => $machine['rated_power_kw'] === null ? null : (float) $machine['rated_power_kw'],
                'phases' => (int) $machine['phases'],
                'criticality' => $machine['criticality'],
                'control_mode' => $machine['control_mode'],
                'schedule' => $machine['schedule_name'],
                'department' => $machine['department'],
            ],
            'live' => $live,
            'today' => $summarise($time->startOfDay($now)),
            'month' => $summarise($time->startOfMonth($now)),
            'week_hours' => [
                'in_schedule' => round((float) ($hours['in_schedule'] ?? 0), 1),
                'outside_schedule' => round((float) ($hours['outside'] ?? 0), 1),
                'running' => round((float) ($hours['running'] ?? 0), 1),
                'idle' => round((float) ($hours['idle'] ?? 0), 1),
            ],
        ]);
    }

    /** Chart data. range = 24h | 7d | 30d. */
    public function timeseries(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $id = (int) $params['id'];
        if (Database::value('SELECT 1 FROM machines WHERE id = ? AND company_id = ?', [$id, $companyId]) === null) {
            throw HttpException::notFound('machine_not_found', 'Machine not found.');
        }
        $range = $request->query('range', '24h');
        $now = Clock::now($companyId);
        [$from, $resolution] = match ($range) {
            '7d' => [$now - 7 * 86400, '1h'],
            '30d' => [$now - 30 * 86400, '1d'],
            default => [$now - 86400, '15m'],
        };
        $points = EnergyQuery::series($companyId, $id, $from, $now, $resolution, $this->timezone($companyId));
        return Response::ok(
            array_map(static fn (array $p): array => ['t' => Time::iso(gmdate('Y-m-d H:i:s', $p['t'])), 'kw' => $p['kw'], 'kwh' => $p['kwh']], $points),
            ['range' => $range, 'resolution' => $resolution, 'units' => ['kw' => 'kW', 'kwh' => 'kWh']],
        );
    }

    private function timezone(int $companyId): string
    {
        return (string) (Database::value('SELECT timezone FROM companies WHERE id = ?', [$companyId]) ?? 'Europe/Belgrade');
    }
}
