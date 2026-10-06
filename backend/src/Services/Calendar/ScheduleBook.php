<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Calendar;

use EnergyFlow\Core\Database;

/**
 * Answers "should this machine be running now?" from the company's working
 * schedules, national holidays, company closures and production overrides
 * (e.g. an operator pressed HOLD for an extra shift). All in local time.
 */
final class ScheduleBook
{
    /**
     * @param array<int, array<int, list<array{0: int, 1: int}>>> $windows schedule_id => dow => [[startMin, endMin]]
     * @param array<string, true> $closedDates local Y-m-d => true
     * @param list<array{machine_id: ?int, start: int, end: int}> $overrides
     */
    public function __construct(
        public readonly LocalTime $time,
        private readonly array $windows,
        private readonly array $closedDates,
        private array $overrides,
    ) {
    }

    public static function forCompany(int $companyId): self
    {
        $company = Database::one('SELECT timezone, country FROM companies WHERE id = ?', [$companyId]);
        $time = new LocalTime($company['timezone'] ?? 'Europe/Belgrade');

        $windows = [];
        $rules = Database::all(
            'SELECT r.schedule_id, r.day_of_week, r.start_time, r.end_time
               FROM schedule_rules r JOIN schedules s ON s.id = r.schedule_id
              WHERE s.company_id = ?',
            [$companyId],
        );
        foreach ($rules as $rule) {
            $windows[(int) $rule['schedule_id']][(int) $rule['day_of_week']][] = [
                self::minutes($rule['start_time']),
                self::minutes($rule['end_time']),
            ];
        }

        $closed = [];
        $days = Database::all(
            "SELECT date FROM calendar_days
              WHERE kind IN ('public_holiday','closed')
                AND ((company_id IS NULL AND country = ?) OR company_id = ?)",
            [$company['country'] ?? 'XK', $companyId],
        );
        foreach ($days as $day) {
            $closed[$day['date']] = true;
        }

        $overrides = array_map(
            static fn (array $row): array => [
                'machine_id' => $row['machine_id'] === null ? null : (int) $row['machine_id'],
                'start' => strtotime($row['starts_at'] . ' UTC'),
                'end' => strtotime($row['ends_at'] . ' UTC'),
            ],
            Database::all('SELECT machine_id, starts_at, ends_at FROM production_overrides WHERE company_id = ?', [$companyId]),
        );

        return new self($time, $windows, $closed, $overrides);
    }

    /** Is the machine (on this schedule) supposed to be working at $ts? */
    public function isScheduled(?int $scheduleId, int $ts, ?int $machineId = null): bool
    {
        if ($this->overridden($ts, $machineId)) {
            return true;
        }
        if ($scheduleId === null || !isset($this->windows[$scheduleId])) {
            return false;
        }

        $minute = $this->time->minuteOfDay($ts);
        $dow = $this->time->dayOfWeek($ts);
        $date = $this->time->date($ts);

        // Today's windows (unless today is a holiday/closure)…
        if (!isset($this->closedDates[$date])) {
            foreach ($this->windows[$scheduleId][$dow] ?? [] as [$start, $end]) {
                if ($start < $end ? ($minute >= $start && $minute < $end) : $minute >= $start) {
                    return true;
                }
            }
        }
        // …and yesterday's windows that run past midnight.
        $yesterday = $dow === 1 ? 7 : $dow - 1;
        $yesterdayDate = $this->time->date($ts - 86400);
        if (!isset($this->closedDates[$yesterdayDate])) {
            foreach ($this->windows[$scheduleId][$yesterday] ?? [] as [$start, $end]) {
                if ($end <= $start && $minute < $end) {
                    return true;
                }
            }
        }
        return false;
    }

    public function overridden(int $ts, ?int $machineId): bool
    {
        foreach ($this->overrides as $override) {
            if ($ts >= $override['start'] && $ts < $override['end']
                && ($override['machine_id'] === null || $override['machine_id'] === $machineId)) {
                return true;
            }
        }
        return false;
    }

    /** Adds an override in memory (used right after an operator presses HOLD). */
    public function addOverride(?int $machineId, int $start, int $end): void
    {
        $this->overrides[] = ['machine_id' => $machineId, 'start' => $start, 'end' => $end];
    }

    /**
     * The last moment the machine was scheduled before $ts (searching back up to
     * $maxLookback seconds in 5-minute steps), or null if none / still scheduled.
     */
    public function lastScheduledEnd(?int $scheduleId, int $ts, ?int $machineId = null, int $maxLookback = 172800): ?int
    {
        if ($this->isScheduled($scheduleId, $ts, $machineId)) {
            return null;
        }
        $step = 300;
        for ($t = $ts - ($ts % $step); $t >= $ts - $maxLookback; $t -= $step) {
            if ($this->isScheduled($scheduleId, $t, $machineId)) {
                return $t + $step;
            }
        }
        return null;
    }

    public function isClosedDay(int $ts): bool
    {
        return isset($this->closedDates[$this->time->date($ts)]);
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    }
}
