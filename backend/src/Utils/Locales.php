<?php

declare(strict_types=1);

namespace EnergyFlow\Utils;

/**
 * Languages the product ships in. To add German or French: add 'de' / 'fr' here
 * and add frontend/src/i18n/locales/{de,fr}.json — nothing else changes.
 */
final class Locales
{
    public const SUPPORTED = ['en', 'sq'];
    public const DEFAULT = 'sq';

    public static function rule(): string
    {
        return 'in:' . implode(',', self::SUPPORTED);
    }
}
