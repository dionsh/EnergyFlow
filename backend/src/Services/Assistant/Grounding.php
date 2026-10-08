<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Assistant;

/**
 * Grounding check (docs/03-architecture.md §9.3): every number an LLM writes
 * must exist in the data it was given — allowing for rounding, for kWh written
 * as MWh / kg as t, and for percentages derived from two given numbers.
 * Years, dates and small counts (≤ 31) are not checked.
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
        $unmatched = [];
        $exempt = static fn (float $v): bool => abs($v) <= 31 || ($v >= 1990 && $v <= 2100 && floor($v) === $v);
        foreach (self::numbers($text) as [$raw, $value]) {
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
                $grounded = $grounded || self::matches($reading, $known);
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
                if (abs($value - $candidate) <= $tolerance || abs($value - round($candidate)) < 0.51 && abs($candidate) >= 10) {
                    return true;
                }
            }
        }
        // A percentage derived from two known numbers (e.g. 13 % less).
        if ($value > 0 && $value < 100) {
            foreach ($known as $a) {
                foreach ($known as $b) {
                    if ($b != 0.0 && abs(abs($a / $b - 1) * 100 - $value) < 0.6) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
