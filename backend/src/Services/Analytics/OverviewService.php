<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Analytics;

use EnergyFlow\Core\Database;
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
     */
    private static function projection(int $companyId, int $now, array $month, ?int $incomerId, ScheduleBook $schedule, ?TariffBook $tariff, float $peakKw): array
    {
        $time = $schedule->time;
        $dayStart = $time->startOfDay($now);
        $rows = Database::all(
            'SELECT UNIX_TIMESTAMP(bucket_start) AS t, kwh FROM readings_15m
              WHERE company_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?)'
              . ($incomerId === null ? " AND machine_id IN (SELECT id FROM machines WHERE company_id = ? AND kind = 'machine')" : ' AND machine_id = ?'),
            [$companyId, $dayStart - 28 * 86400, $dayStart, $incomerId ?? $companyId],
        );
        $perDay = [];
        foreach ($rows as $row) {
            $day = $time->date((int) $row['t']);
            $perDay[$day] = ($perDay[$day] ?? 0.0) + (float) $row['kwh'];
        }
        $byType = [];
        foreach ($perDay as $day => $kwh) {
            $byType[self::dayType($time->at($day, 720), $schedule)][] = $kwh;
        }
        $average = static fn (string $type): float => isset($byType[$type]) ? array_sum($byType[$type]) / count($byType[$type]) : 0.0;

        $todayKwhSoFar = EnergyQuery::site(EnergyQuery::byMachine($companyId, $dayStart, $now, null, $tariff), $incomerId)['kwh'];
        $remaining = max(0.0, $average(self::dayType($now, $schedule)) - $todayKwhSoFar);
        $monthEnd = $time->startOfMonth($time->startOfMonth($now) + 32 * 86400);
        for ($d = $dayStart + 86400; $d < $monthEnd; $d += 86400) {
            $remaining += $average(self::dayType($d + 43200, $schedule));
        }

        $kwh = $month['kwh'] + $remaining;
        $highShare = $month['kwh'] > 0 ? $month['kwh_high'] / $month['kwh'] : 0.7;
        $kvarRatio = $month['kwh'] > 0 ? $month['kvarh'] / $month['kwh'] : 0.0;
        $bill = $tariff?->bill($kwh * $highShare, $kwh * (1 - $highShare), $peakKw, $kwh * $kvarRatio);
        $factor = EmissionFactors::gridFactor($companyId);

        return [
            'method' => 'day_type_average_28d',
            'kwh' => round($kwh, 0),
            'bill' => $bill,
            'co2_kg' => round($kwh * $factor['value'], 0),
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

    public static function incomerId(int $companyId): ?int
    {
        $id = Database::value("SELECT id FROM machines WHERE company_id = ? AND kind = 'incomer' AND archived_at IS NULL LIMIT 1", [$companyId]);
        return $id === null ? null : (int) $id;
    }
}
