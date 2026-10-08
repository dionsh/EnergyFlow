<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Detection;

use EnergyFlow\Services\Calendar\ScheduleBook;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

/**
 * "If it keeps running until the next shift": average power × the hours left,
 * priced per tariff period at the marginal rate, with CO₂e at the grid factor.
 * Shown while a machine runs after hours (live screen, Turn Off dialog, waste drawer).
 */
final class Projection
{
    private const STEP = 900;

    public static function untilNextStart(ScheduleBook $schedule, ?TariffBook $tariff, float $factor, ?int $scheduleId, int $machineId, int $now, float $kw): ?array
    {
        $next = $schedule->nextScheduledStart($scheduleId, $now, $machineId);
        if ($next === null || $kw <= 0) {
            return null;
        }
        $kwh = 0.0;
        $eur = 0.0;
        for ($t = $now; $t < $next; $t += self::STEP) {
            $hours = min(self::STEP, $next - $t) / 3600;
            $kwh += $kw * $hours;
            $eur += $kw * $hours * ($tariff?->marginalRate($t) ?? 0.0);
        }
        return [
            'until' => Time::iso(gmdate('Y-m-d H:i:s', $next)),
            'kw' => round($kw, 2),
            'kwh' => round($kwh, 1),
            'eur' => round($eur, 2),
            'co2_kg' => round($kwh * $factor, 1),
        ];
    }
}
