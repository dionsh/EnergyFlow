<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Simulation;

/**
 * Deterministic, stateless randomness. The same inputs always give the same
 * value, so any time range of simulated data can be (re)generated on demand
 * and every demo reset tells exactly the same story.
 */
final class Noise
{
    /** Uniform value in [0, 1) derived from integer inputs. */
    public static function uniform(int ...$parts): float
    {
        $h = 0x9E3779B9;
        foreach ($parts as $part) {
            $h = self::mix(($h ^ ($part & 0xFFFFFFFF)) + 0x7F4A7C15);
        }
        return $h / 4294967296.0;
    }

    /** Uniform value in [-1, 1). */
    public static function signed(int ...$parts): float
    {
        return self::uniform(...$parts) * 2.0 - 1.0;
    }

    /** Approximately standard-normal value (sum of uniforms), deterministic. */
    public static function gaussian(int ...$parts): float
    {
        $sum = 0.0;
        for ($i = 0; $i < 4; $i++) {
            $sum += self::uniform(...[...$parts, $i]);
        }
        return ($sum - 2.0) * 1.7320508; // variance of the sum of 4 U(0,1) is 1/3
    }

    /**
     * Smooth noise in [-1, 1]: cosine interpolation between lattice values spaced
     * `$period` seconds apart. Gives slow, natural drift instead of jitter.
     */
    public static function smooth(int $seed, float $seconds, float $period): float
    {
        $x = $seconds / $period;
        $i = (int) floor($x);
        $fraction = $x - $i;
        $a = self::signed($seed, $i);
        $b = self::signed($seed, $i + 1);
        $weight = (1.0 - cos($fraction * M_PI)) / 2.0;
        return $a + ($b - $a) * $weight;
    }

    /** 32-bit integer finaliser (lowbias32). */
    private static function mix(int $h): int
    {
        $h &= 0xFFFFFFFF;
        $h ^= $h >> 16;
        $h = self::mul32($h, 0x7FEB352D);
        $h ^= $h >> 15;
        $h = self::mul32($h, 0x846CA68B);
        $h ^= $h >> 16;
        return $h;
    }

    /**
     * (a × b) mod 2³² without overflowing PHP's signed 64-bit integers:
     * multiply by the two 16-bit halves of b separately.
     */
    private static function mul32(int $a, int $b): int
    {
        return (($a * ($b & 0xFFFF)) + ((($a * ($b >> 16)) & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }
}
