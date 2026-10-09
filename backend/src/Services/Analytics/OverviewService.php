<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Analytics;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Calendar\ScheduleBook;
use EnergyFlow\Services\Carbon\EmissionFactors;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

/**
 * Headline numbers for the Overview: today, month to date (with the same period
 * last month for comparison), a month-end projection, cost under the company's
 * real tariff, CO₂e and the "today vs typical" power curve.
 */
final class OverviewService
{
    public static function summary(int $companyId): array
    {
        $now = Clock::now($companyId);
        $schedule = ScheduleBook::forCompany($companyId);
        $time = $schedule->time;
        $tariff = TariffBook::forCompany($companyId);
        $factor = EmissionFactors::gridFactor($companyId);
        $incomerId = self::incomerId($companyId);

        $first = Database::value('SELECT UNIX_TIMESTAMP(MIN(bucket_start)) FROM readings_15m WHERE company_id = ?', [$companyId]);
        if ($first === null && Database::value('SELECT 1 FROM machine_live WHERE company_id = ? LIMIT 1', [$companyId]) === null) {
            return ['has_data' => false];
        }

        $dayStart = $time->startOfDay($now);
        $monthStart = $time->startOfMonth($now);
        $previousMonthStart = $time->startOfMonth($monthStart - 86400);
        $elapsed = $now - $monthStart;

        $today = EnergyQuery::site(EnergyQuery::byMachine($companyId, $dayStart, $now, null, $tariff), $incomerId);
        $month = EnergyQuery::site(EnergyQuery::byMachine($companyId, $monthStart, $now, null, $tariff), $incomerId);
        $lastMonthSamePoint = EnergyQuery::site(EnergyQuery::byMachine($companyId, $previousMonthStart, min($previousMonthStart + $elapsed, $monthStart), null, $tariff), $incomerId);
        $peakKw = EnergyQuery::peakKw($companyId, $monthStart, $now, $incomerId);

        $daysInMonth = (int) $time->format($now, 't');
        $monthFraction = $elapsed / ($daysInMonth * 86400);
        $billSoFar = $tariff?->bill($month['kwh_high'], $month['kwh_low'], $peakKw, $month['kvarh'], $monthFraction);

        $projection = self::projection($companyId, $now, $month, $incomerId, $schedule, $tariff, $peakKw);

        return [
            'has_data' => true,
            'now' => Time::iso(gmdate('Y-m-d H:i:s', $now)),
            'monitoring_since' => $first === null ? null : Time::iso(gmdate('Y-m-d H:i:s', (int) $first)),
            'today' => [
                'kwh' => round($today['kwh'], 1),
                'eur' => round(EnergyQuery::energyCost($today, $tariff), 2),
                'co2_kg' => round($today['kwh'] * $factor['value'], 1),
            ],
            'month' => [
                'kwh' => round($month['kwh'], 0),
                'kwh_high' => round($month['kwh_high'], 0),
                'kwh_low' => round($month['kwh_low'], 0),
                'co2_kg' => round($month['kwh'] * $factor['value'], 0),
                'peak_kw' => round($peakKw, 1),
                'bill_so_far' => $billSoFar,
                'previous_same_period_kwh' => round($lastMonthSamePoint['kwh'], 0),
                'change_ratio' => $lastMonthSamePoint['kwh'] > 0 ? round($month['kwh'] / $lastMonthSamePoint['kwh'] - 1, 4) : null,
            ],
            'projection' => $projection,
            'emission_factor' => $factor,
            'tariff' => $tariff?->summary(),
            'today_curve' => self::todayCurve($companyId, $dayStart, $now, $incomerId, $schedule),
        ];
    }

    /**
     * Month-end projection: actual month-to-date + for every remaining day the
     * average consumption of the same kind of day (working / Saturday / closed)
     * over the last 28 days. Simple, explainable, and labelled as such in the UI.
     *
     * The P10–P90 range comes from a backtest: each of the last 14 days is
     * "forecast" the same way from the 28 days before it, and the spread of those
     * daily errors is scaled to the days that remain (independent days, normal
     * approximation). The backtest's weighted absolute percentage error (Σ|error| ÷
     * Σ actual, so small closed days don't dominate it) is reported too.
     */
    private static function projection(int $companyId, int $now, array $month, ?int $incomerId, ScheduleBook $schedule, ?TariffBook $tariff, float $peakKw): array
    {
        $time = $schedule->time;
        $dayStart = $time->startOfDay($now);
        $rows = Database::all(
            'SELECT UNIX_TIMESTAMP(bucket_start) AS t, SUM(kwh) AS kwh FROM readings_15m
              WHERE company_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?)'
              . ($incomerId === null ? " AND machine_id IN (SELECT id FROM machines WHERE company_id = ? AND kind = 'machine')" : ' AND machine_id = ?')
              . ' GROUP BY bucket_start',
            [$companyId, $dayStart - 42 * 86400, $dayStart, $incomerId ?? $companyId],
        );
        $perDay = [];
        foreach ($rows as $row) {
            $day = $time->date((int) $row['t']);
            $perDay[$day] = ($perDay[$day] ?? 0.0) + (float) $row['kwh'];
        }
        $typeOf = static fn (string $day): string => self::dayType($time->at($day, 720), $schedule);
        /** Average of each day type over the 28 days before $before (Y-m-d). */
        $averages = static function (string $before) use ($perDay, $typeOf): array {
            $byType = [];
            foreach ($perDay as $day => $kwh) {
                if ($day < $before && $day >= date('Y-m-d', strtotime($before . ' -28 days'))) {
                    $byType[$typeOf($day)][] = $kwh;
                }
            }
            return array_map(static fn (array $v): float => array_sum($v) / count($v), $byType);
        };

        $current = $averages($time->date($dayStart));
        $average = static fn (string $type): float => $current[$type] ?? 0.0;
        $todayKwhSoFar = EnergyQuery::site(EnergyQuery::byMachine($companyId, $dayStart, $now, null, $tariff), $incomerId)['kwh'];
        $remaining = max(0.0, $average(self::dayType($now, $schedule)) - $todayKwhSoFar);
        $monthEnd = $time->startOfMonth($time->startOfMonth($now) + 32 * 86400);
        $remainingDays = max(0.0, ($dayStart + 86400 - $now) / 86400);
        for ($d = $dayStart + 86400; $d < $monthEnd; $d += 86400) {
            $remaining += $average(self::dayType($d + 43200, $schedule));
            $remainingDays += 1;
        }

        // Backtest over the last 14 complete days that have 28 days of history before them.
        $errors = [];
        $actuals = 0.0;
        for ($i = 14; $i >= 1; $i--) {
            $day = $time->date($dayStart - $i * 86400 + 43200);
            $history = $averages($day);
            if (!isset($perDay[$day], $history[$typeOf($day)]) || count(array_filter(array_keys($perDay), static fn (string $d): bool => $d < $day)) < 21) {
                continue;
            }
            $errors[] = $perDay[$day] - $history[$typeOf($day)];
            $actuals += $perDay[$day];
        }
        $kwh = $month['kwh'] + $remaining;
        $spread = null;
        if (count($errors) >= 7) {
            $mean = array_sum($errors) / count($errors);
            $sd = sqrt(array_sum(array_map(static fn (float $e): float => ($e - $mean) ** 2, $errors)) / (count($errors) - 1));
            $spread = 1.2816 * $sd * sqrt($remainingDays); // z(0.90) for P10/P90
        }

        $highShare = $month['kwh'] > 0 ? $month['kwh_high'] / $month['kwh'] : 0.7;
        $kvarRatio = $month['kwh'] > 0 ? $month['kvarh'] / $month['kwh'] : 0.0;
        $billFor = static fn (float $k): ?array => $tariff?->bill($k * $highShare, $k * (1 - $highShare), $peakKw, $k * $kvarRatio);
        $factor = EmissionFactors::gridFactor($companyId);
        $low = $spread === null ? null : max($month['kwh'], $kwh - $spread);
        $high = $spread === null ? null : $kwh + $spread;

        return [
            'method' => 'day_type_average_28d',
            'kwh' => round($kwh, 0),
            'bill' => $billFor($kwh),
            'co2_kg' => round($kwh * $factor['value'], 0),
            'range' => $spread === null ? null : [
                'kwh_p10' => round($low, 0), 'kwh_p90' => round($high, 0),
                'bill_p10' => $billFor($low)['subtotal'] ?? null, 'bill_p90' => $billFor($high)['subtotal'] ?? null,
                'co2_kg_p10' => round($low * $factor['value'], 0), 'co2_kg_p90' => round($high * $factor['value'], 0),
            ],
            'backtest' => ['days' => count($errors), 'wape' => $actuals > 0 ? round(array_sum(array_map('abs', $errors)) / $actuals, 4) : null],
            'remaining_days' => round($remainingDays, 2),
        ];
    }

    /** Site power today (15-min) next to the typical curve for this kind of day (median of the last 4 such days). */
    private static function todayCurve(int $companyId, int $dayStart, int $now, ?int $incomerId, ScheduleBook $schedule): array
    {
        $filter = $incomerId === null ? " AND machine_id IN (SELECT id FROM machines WHERE company_id = ? AND kind = 'machine')" : ' AND machine_id = ?';
        $param = $incomerId ?? $companyId;
        $time = $schedule->time;

        $today = [];
        foreach (Database::all(
            "SELECT UNIX_TIMESTAMP(bucket_start) AS t, SUM(avg_kw) AS kw FROM readings_15m
              WHERE company_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?){$filter} GROUP BY t ORDER BY t",
            [$companyId, $dayStart, $now, $param],
        ) as $row) {
            $today[intdiv((int) $row['t'] - $dayStart, 900)] = round((float) $row['kw'], 2);
        }

        // Up to 4 previous days of the same type.
        $type = self::dayType($now, $schedule);
        $days = [];
        for ($d = 1; $d <= 21 && count($days) < 4; $d++) {
            $start = $time->startOfDay($dayStart - $d * 86400 + 43200);
            if (self::dayType($start + 43200, $schedule) === $type) {
                $days[] = $start;
            }
        }
        $typical = [];
        foreach ($days as $start) {
            foreach (Database::all(
                "SELECT UNIX_TIMESTAMP(bucket_start) AS t, SUM(avg_kw) AS kw FROM readings_15m
                  WHERE company_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?){$filter} GROUP BY t",
                [$companyId, $start, $start + 86400, $param],
            ) as $row) {
                $typical[intdiv((int) $row['t'] - $start, 900)][] = (float) $row['kw'];
            }
        }

        $points = [];
        for ($slot = 0; $slot < 96; $slot++) {
            $values = $typical[$slot] ?? [];
            sort($values);
            $count = count($values);
            $points[] = [
                't' => Time::iso(gmdate('Y-m-d H:i:s', $dayStart + $slot * 900)),
                'kw' => $today[$slot] ?? null,
                'typical_kw' => $count === 0 ? null : round($count % 2 ? $values[intdiv($count, 2)] : ($values[$count / 2 - 1] + $values[$count / 2]) / 2, 2),
                'typical_min' => $count === 0 ? null : round($values[0], 2),
                'typical_max' => $count === 0 ? null : round($values[$count - 1], 2),
            ];
        }
        return ['day_type' => $type, 'points' => $points];
    }

    private static function dayType(int $ts, ScheduleBook $schedule): string
    {
        if ($schedule->isClosedDay($ts)) {
            return 'closed';
        }
        return match ($schedule->time->dayOfWeek($ts)) {
            6 => 'saturday',
            7 => 'closed',
            default => 'working',
        };
    }

    /**
     * Where this month's energy went: grid → machines → productive or waste
     * (the Sankey on the Overview). Waste is what the detectors quantified;
     * unmetered is the incomer minus the metered machines.
     */
    public static function flow(int $companyId): array
    {
        $now = Clock::now($companyId);
        $time = LocalTime::forCompany($companyId);
        $period = Period::parse('mtd', $now, $time);
        $tariff = TariffBook::forCompany($companyId);
        $factor = EmissionFactors::gridFactor($companyId)['value'];
        $incomer = self::incomerId($companyId);
        $by = EnergyQuery::byMachine($companyId, $period->from, $period->to, null, $tariff);
        $site = EnergyQuery::site($by, $incomer);
        $waste = [];
        foreach (\EnergyFlow\Services\Detection\WasteReport::summary($companyId, $period)['by_machine'] as $row) {
            $waste[(int) $row['machine']['id']] = ['kwh' => $row['kwh'], 'eur' => $row['eur']];
        }
        $machines = [];
        foreach (Database::all("SELECT id, code, name, type_code FROM machines WHERE company_id = ? AND kind = 'machine' AND archived_at IS NULL", [$companyId]) as $m) {
            $id = (int) $m['id'];
            $kwh = $by[$id]['kwh'] ?? 0.0;
            if ($kwh <= 0) {
                continue;
            }
            $machines[] = [
                'id' => $id, 'code' => $m['code'], 'name' => $m['name'], 'type' => $m['type_code'],
                'kwh' => round($kwh, 1), 'eur' => round(EnergyQuery::energyCost($by[$id], $tariff), 2),
                'waste_kwh' => round(min($kwh, $waste[$id]['kwh'] ?? 0.0), 1), 'waste_eur' => round($waste[$id]['eur'] ?? 0.0, 2),
            ];
        }
        usort($machines, static fn (array $a, array $b): int => $b['kwh'] <=> $a['kwh']);
        $metered = array_sum(array_column($machines, 'kwh'));
        $wasteKwh = array_sum(array_column($machines, 'waste_kwh'));
        return [
            'period' => $period->meta(),
            'site_kwh' => round($site['kwh'], 1),
            'site_eur' => round(EnergyQuery::energyCost($site, $tariff), 2),
            'site_co2_kg' => round($site['kwh'] * $factor, 1),
            'machines' => $machines,
            'unmetered_kwh' => $incomer === null ? 0.0 : round(max(0.0, $site['kwh'] - $metered), 1),
            'waste_kwh' => round($wasteKwh, 1),
            'waste_eur' => round(array_sum(array_column($machines, 'waste_eur')), 2),
            'productive_kwh' => round($metered - $wasteKwh, 1),
        ];
    }

    public static function incomerId(int $companyId): ?int
    {
        $id = Database::value("SELECT id FROM machines WHERE company_id = ? AND kind = 'incomer' AND archived_at IS NULL LIMIT 1", [$companyId]);
        return $id === null ? null : (int) $id;
    }
}
