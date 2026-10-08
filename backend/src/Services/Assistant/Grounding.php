<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Assistant;

/**
 * Grounding check (docs/03-architecture.md §9.3): every number an LLM writes
 * must exist in the data it was given — allowing for rounding and for kWh
 * written as MWh / kg as t / a ratio as %.
 *
 * Derived numbers (a % change, a difference, a sum) are accepted only between
 * numbers that belong together in the data: fields of the same object, or the
 * same field across a list (e.g. kWh of two months). Across hundreds of
 * unrelated numbers almost any value can be "derived", which would make the
 * check meaningless. Years, dates and small counts (≤ 31) are not checked.
 */
final class Grounding
{
    /**
     * @param array<mixed> $data the facts the model was given
     * @return array{grounded: bool, unmatched: list<string>}
     */
    public static function check(string $text, array $data): array
    {
        $known = [];
        array_walk_recursive($data, static function (mixed $v) use (&$known): void {
            if (is_int($v) || is_float($v)) {
                $known[] = (float) $v;
            } elseif (is_string($v)) {
                // Numbers inside text facts count too (a machine called "Injection moulder · 250 t").
                foreach (self::numbers($v) as [, $number]) {
                    $known[] = $number;
                }
            }
        });
        $known = array_values(array_unique(array_filter($known, static fn (float $v): bool => abs($v) > 0.0)));
        $groups = [];
        self::groups($data, $groups);

        $unmatched = [];
        // Percentages are always checked, however small: "saves 10–30%" is a statistic, not a count.
        preg_match_all('/(\d+(?:[.,]\d+)?)(?=\s*(?:[-–]\s*\d+(?:[.,]\d+)?\s*)?%)/u', $text, $percent);
        $percentages = array_flip($percent[1]);
        $exempt = static fn (float $v): bool => abs($v) <= 31 || ($v >= 1990 && $v <= 2100 && floor($v) === $v);
        foreach (self::numbers($text) as [$raw, $value]) {
            if (isset($percentages[$raw])) {
                if (!self::matches($value, $known) && !self::derived($value, $groups)) {
                    $unmatched[] = $raw . '%';
                }
                continue;
            }
            // "9,999" is 9999 in English and 9.999 in Albanian: either reading may be the
            // grounded one, but it's only exempt as a small number if both readings are.
            $readings = [$value];
            if (preg_match('/^\d{1,3}[.,]\d{3}$/', $raw)) {
                $readings[] = (float) str_replace([',', '.'], '', $raw);
            }
            if (count(array_filter($readings, $exempt)) === count($readings)) {
                continue;
            }
            $grounded = false;
            foreach ($readings as $reading) {
                $grounded = $grounded || self::matches($reading, $known) || self::derived($reading, $groups);
            }
            if (!$grounded) {
                $unmatched[] = $raw;
            }
        }
        return ['grounded' => $unmatched === [], 'unmatched' => $unmatched];
    }

    /** @return list<array{0: string, 1: float}> numbers as written (1,234.5 · 1 234,5 · 12,6 · 0.36) */
    public static function numbers(string $text): array
    {
        // Grouped numbers (1,234.5 · 1 234,5) must not stop in the middle of a longer number ("2026").
        preg_match_all('/(?<![\w.,])(?:\d{1,3}(?:[ \x{00A0}\x{202F},.]\d{3})*(?:[.,]\d+)?(?!\d)|\d+(?:[.,]\d+)?)/u', $text, $m);
        $out = [];
        foreach ($m[0] as $raw) {
            $clean = preg_replace('/[\x{00A0}\x{202F} ]/u', '', $raw) ?? $raw;
            // A separator followed by exactly 3 digits at the end is a thousands separator unless it's the only one and a comma-decimal is likely.
            if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $clean) && !preg_match('/^\d+[.,]\d{3}$/', $clean)) {
                $clean = str_replace([',', '.'], '', $clean);
            } elseif (str_contains($clean, ',') && str_contains($clean, '.')) {
                $clean = strrpos($clean, ',') > strrpos($clean, '.') ? str_replace(['.', ','], ['', '.'], $clean) : str_replace(',', '', $clean);
            } else {
                $clean = str_replace(',', '.', $clean);
            }
            if (is_numeric($clean)) {
                $out[] = [$raw, (float) $clean];
            }
        }
        return $out;
    }

    private static function matches(float $value, array $known): bool
    {
        foreach ($known as $k) {
            foreach ([$k, $k / 1000, $k * 1000, $k * 100] as $candidate) { // kWh↔MWh, kg↔t, ratio↔%
                $tolerance = max(0.051, abs($candidate) * 0.006);
                // A whole number may be the rounded value ("15 MWh" for 15.45); a decimal may not.
                $rounded = abs($candidate) >= 10 && floor($value) === $value && $value === round($candidate);
                if (abs($value - $candidate) <= $tolerance || $rounded) {
                    return true;
                }
            }
        }
        return false;
    }

    /** A % change, difference or sum of two numbers that belong together. */
    private static function derived(float $value, array $groups): bool
    {
        foreach ($groups as $group) {
            foreach ($group as $i => $a) {
                foreach ($group as $j => $b) {
                    if ($i === $j) {
                        continue;
                    }
                    // Components may have been rounded to whole units before subtracting.
                    $tolerance = max(0.051, abs($a) >= 100 || abs($b) >= 100 ? 1.01 : abs($value) * 0.01);
                    if (abs(abs($a - $b) - abs($value)) <= $tolerance || abs($a + $b - $value) <= $tolerance) {
                        return true;
                    }
                    if ($b != 0.0 && $value > 0 && $value < 1000 && abs(abs($a / $b - 1) * 100 - $value) < 0.6) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Sets of numbers that belong together: the scalar fields of each object, and
     * each field across the items of a list of objects.
     *
     * @param list<list<float>> $groups
     */
    private static function groups(mixed $node, array &$groups): void
    {
        if (!is_array($node)) {
            return;
        }
        $scalars = array_values(array_map('floatval', array_filter($node, static fn (mixed $v): bool => is_int($v) || is_float($v))));
        if (count($scalars) >= 2) {
            $groups[] = $scalars;
        }
        if (array_is_list($node) && $node !== [] && count(array_filter($node, 'is_array')) === count($node)) {
            $byKey = [];
            foreach ($node as $item) {
                foreach ($item as $key => $v) {
                    if (is_int($v) || is_float($v)) {
                        $byKey[$key][] = (float) $v;
                    } elseif (is_array($v) && !array_is_list($v)) {
                        // One level down too: CMP-01's kWh in August vs September.
                        foreach ($v as $sub => $w) {
                            if (is_int($w) || is_float($w)) {
                                $byKey["{$key}.{$sub}"][] = (float) $w;
                            }
                        }
                    }
                }
            }
            foreach ($byKey as $values) {
                if (count($values) >= 2) {
                    $groups[] = $values;
                }
            }
        }
        foreach ($node as $child) {
            self::groups($child, $groups);
        }
    }
}
