<?php

declare(strict_types=1);

namespace EnergyFlow\Services;

use EnergyFlow\Core\Database;

/**
 * "Now" for a company. Real companies use real time. The demo company runs on a
 * virtual clock (real time + offset) so the story can be set to a weekday evening
 * or fast-forwarded. Every service asks this class — never time() directly.
 */
final class Clock
{
    private static ?int $frozen = null;

    /** @var array<int, int> company_id => offset seconds (cached per request) */
    private static array $offsets = [];

    /** Tests can pin real time. */
    public static function freeze(?int $timestamp): void
    {
        self::$frozen = $timestamp;
    }

    public static function realNow(): int
    {
        return self::$frozen ?? time();
    }

    public static function now(int $companyId): int
    {
        return self::realNow() + self::offset($companyId);
    }

    public static function offset(int $companyId): int
    {
        if (!array_key_exists($companyId, self::$offsets)) {
            $offset = Database::value('SELECT clock_offset_s FROM sim_state WHERE company_id = ?', [$companyId]);
            self::$offsets[$companyId] = $offset === null ? 0 : (int) $offset;
        }
        return self::$offsets[$companyId];
    }

    public static function forget(?int $companyId = null): void
    {
        if ($companyId === null) {
            self::$offsets = [];
        } else {
            unset(self::$offsets[$companyId]);
        }
    }
}
