<?php

declare(strict_types=1);

namespace EnergyFlow\Utils;

use DateTimeImmutable;
use DateTimeZone;

final class Time
{
    /**
     * The database stores UTC DATETIME values without a zone. The API always
     * returns ISO-8601 with an explicit `Z` so clients never have to guess.
     */
    public static function iso(?string $utcDateTime): ?string
    {
        if ($utcDateTime === null || $utcDateTime === '') {
            return null;
        }
        return (new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    /** @param list<string> $columns */
    public static function isoColumns(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = self::iso($row[$column]);
            }
        }
        return $row;
    }
}
