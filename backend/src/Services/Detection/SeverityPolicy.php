<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Detection;

/**
 * The one place that decides how loud a finding is (docs/03-architecture.md §7).
 * Calm by default: only sustained, costly waste is critical.
 */
final class SeverityPolicy
{
    /** Running outside the schedule: info under €1, critical when longer than 2 h AND over €5. */
    public static function afterHours(float $eur, int $durationS): string
    {
        if ($eur < 1.0) {
            return 'info';
        }
        return ($durationS > 2 * 3600 && $eur > 5.0) ? 'critical' : 'warning';
    }

    /** Standby during production hours (heaters on, no cycles). */
    public static function idle(float $eur): string
    {
        return $eur < 1.0 ? 'info' : 'warning';
    }

    /** Sustained increase of running power vs the machine's own reference. */
    public static function drift(float $deviation): string
    {
        return match (true) {
            $deviation >= 0.25 => 'critical',
            $deviation >= 0.10 => 'warning',
            default => 'info',
        };
    }

    /** Notification category for a severity (critical / warning / insight). */
    public static function category(string $severity): string
    {
        return match ($severity) {
            'critical' => 'critical',
            'warning' => 'warning',
            default => 'insight',
        };
    }

    public static function rank(string $severity): int
    {
        return ['info' => 1, 'warning' => 2, 'critical' => 3][$severity] ?? 0;
    }
}
