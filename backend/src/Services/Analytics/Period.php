<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Analytics;

use EnergyFlow\Core\HttpException;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Utils\Time;

/**
 * Period parameters (docs/05-api.md): today | yesterday | 7d | 30d | mtd | month:YYYY-MM | ytd | year:YYYY,
 * resolved in the company's local time. Each period knows the equally long
 * period before it, for "vs last month" comparisons.
 */
final class Period
{
    private function __construct(
        public readonly string $key,
        public readonly int $from,
        public readonly int $to,
        public readonly int $previousFrom,
        public readonly int $previousTo,
    ) {
    }

    public static function parse(?string $value, int $now, LocalTime $time, string $default = 'mtd'): self
    {
        $value = $value ?? $default;
        $day = $time->startOfDay($now);
        $month = $time->startOfMonth($now);

        if (preg_match('/^month:(\d{4})-(\d{2})$/', $value, $m)) {
            $from = $time->at("{$m[1]}-{$m[2]}-01");
            $to = min($now, $time->startOfMonth($from + 32 * 86400));
            if ($from >= $now) {
                throw HttpException::badRequest('invalid_period', 'That month has not started yet.');
            }
            $previousFrom = $time->startOfMonth($from - 86400);
            return new self($value, $from, $to, $previousFrom, min($from, $previousFrom + ($to - $from)));
        }
        if (preg_match('/^year:(\d{4})$/', $value, $m)) {
            $from = $time->at("{$m[1]}-01-01");
            $to = min($now, $time->at(((int) $m[1] + 1) . '-01-01'));
            $previousFrom = $time->at(((int) $m[1] - 1) . '-01-01');
            return new self($value, $from, $to, $previousFrom, $previousFrom + ($to - $from));
        }

        return match ($value) {
            'today' => new self($value, $day, $now, $day - 86400, $now - 86400),
            'yesterday' => new self($value, $time->startOfDay($day - 43200), $day, $time->startOfDay($day - 129600), $time->startOfDay($day - 43200)),
            '7d' => new self($value, $now - 7 * 86400, $now, $now - 14 * 86400, $now - 7 * 86400),
            '30d' => new self($value, $now - 30 * 86400, $now, $now - 60 * 86400, $now - 30 * 86400),
            'ytd' => self::ytd($now, $time),
            'mtd' => new self(
                $value,
                $month,
                $now,
                $time->startOfMonth($month - 86400),
                min($month, $time->startOfMonth($month - 86400) + ($now - $month)),
            ),
            default => throw HttpException::validation(['period' => 'invalid_choice']),
        };
    }

    /** Any window [from, to), compared with the equally long window before it. */
    public static function between(int $from, int $to, string $key = 'custom'): self
    {
        return new self($key, $from, $to, $from - ($to - $from), $from);
    }

    public function meta(): array
    {
        return [
            'key' => $this->key,
            'from' => Time::iso(gmdate('Y-m-d H:i:s', $this->from)),
            'to' => Time::iso(gmdate('Y-m-d H:i:s', $this->to)),
            'previous' => [
                'from' => Time::iso(gmdate('Y-m-d H:i:s', $this->previousFrom)),
                'to' => Time::iso(gmdate('Y-m-d H:i:s', $this->previousTo)),
            ],
        ];
    }

    private static function ytd(int $now, LocalTime $time): self
    {
        $year = (int) $time->format($now, 'Y');
        $from = $time->at("{$year}-01-01");
        $previousFrom = $time->at(($year - 1) . '-01-01');
        return new self('ytd', $from, $now, $previousFrom, $previousFrom + ($now - $from));
    }
}
