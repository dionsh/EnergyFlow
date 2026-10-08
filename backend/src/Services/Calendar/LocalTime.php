<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use EnergyFlow\Core\Database;

/**
 * Fast UTC → company-local conversion for tight loops (simulation, rollups).
 * The UTC offset is cached per hour; Kosovo (Europe/Belgrade) only changes
 * offset on DST boundaries, which fall on whole hours.
 */
final class LocalTime
{
    private DateTimeZone $zone;

    /** @var array<int, int> hour index => offset seconds */
    private array $offsets = [];

    public function __construct(string $timezone)
    {
        $this->zone = new DateTimeZone($timezone);
    }

    public static function forCompany(int $companyId): self
    {
        return new self((string) (Database::value('SELECT timezone FROM companies WHERE id = ?', [$companyId]) ?? 'Europe/Belgrade'));
    }

    public function zone(): DateTimeZone
    {
        return $this->zone;
    }

    public function offset(int $ts): int
    {
        $hour = intdiv($ts, 3600);
        return $this->offsets[$hour] ??= $this->zone->getOffset(new DateTimeImmutable('@' . ($hour * 3600)));
    }

    /** Minutes since local midnight (0–1439). */
    public function minuteOfDay(int $ts): int
    {
        $local = $ts + $this->offset($ts);
        return intdiv(($local % 86400 + 86400) % 86400, 60);
    }

    /** ISO day of week, 1 = Monday … 7 = Sunday. */
    public function dayOfWeek(int $ts): int
    {
        $localDays = intdiv($ts + $this->offset($ts), 86400);
        return (($localDays + 3) % 7) + 1; // 1970-01-01 was a Thursday
    }

    public function date(int $ts): string
    {
        return gmdate('Y-m-d', $ts + $this->offset($ts));
    }

    /** Integer day number of the local date (days since 1970-01-01 local). */
    public function dayNumber(int $ts): int
    {
        return intdiv($ts + $this->offset($ts), 86400);
    }

    /** UTC timestamp of local midnight for the local date containing $ts. */
    public function startOfDay(int $ts): int
    {
        $date = new DateTimeImmutable($this->date($ts) . ' 00:00:00', $this->zone);
        return $date->getTimestamp();
    }

    /** UTC timestamp of the first moment of the local month containing $ts. */
    public function startOfMonth(int $ts): int
    {
        $date = new DateTimeImmutable(substr($this->date($ts), 0, 7) . '-01 00:00:00', $this->zone);
        return $date->getTimestamp();
    }

    /** UTC timestamp for a local date (Y-m-d) and minute of day. */
    public function at(string $date, int $minuteOfDay = 0): int
    {
        $base = new DateTimeImmutable($date . ' 00:00:00', $this->zone);
        return $base->modify("+{$minuteOfDay} minutes")->getTimestamp();
    }

    public function format(int $ts, string $format): string
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone($this->zone)->format($format);
    }
}
