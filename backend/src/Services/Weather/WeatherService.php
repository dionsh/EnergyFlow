<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Weather;

use EnergyFlow\Core\Database;

/**
 * Hourly outdoor temperature per site, cached in weather_hourly.
 * Source: Open-Meteo (free, no API key; up to 92 past days + 16 forecast days
 * in one call). If the API is unreachable, a Pristina climatology is used so the
 * simulator and analytics keep working offline — and the data is marked as such.
 */
final class WeatherService
{
    private const API = 'https://api.open-meteo.com/v1/forecast';

    /** Make sure hourly temperatures exist for [from, to]. Returns the source used. */
    public static function ensure(int $siteId, float $latitude, float $longitude, int $from, int $to): string
    {
        $from -= $from % 3600;
        $have = (int) Database::value(
            'SELECT COUNT(*) FROM weather_hourly WHERE site_id = ? AND ts BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?)',
            [$siteId, $from, $to],
        );
        $need = intdiv($to - $from, 3600) + 1;
        if ($have >= $need) {
            return 'cache';
        }

        $hours = self::fetch($latitude, $longitude, $from, $to);
        $source = 'open-meteo';
        if ($hours === []) {
            $hours = self::climatology($from, $to);
            $source = 'climatology';
        }

        foreach (array_chunk($hours, 500, true) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?, FROM_UNIXTIME(?), ?, ?)'));
            $params = [];
            foreach ($chunk as $ts => [$temp, $isForecast]) {
                array_push($params, $siteId, $ts, $temp, $isForecast ? 1 : 0);
            }
            Database::run(
                "INSERT INTO weather_hourly (site_id, ts, temp_c, is_forecast) VALUES {$placeholders}
                 ON DUPLICATE KEY UPDATE temp_c = VALUES(temp_c), is_forecast = VALUES(is_forecast)",
                $params,
            );
        }
        return $source;
    }

    /** @return array<int, float> hour timestamp => °C */
    public static function series(int $siteId, int $from, int $to): array
    {
        $rows = Database::all(
            'SELECT UNIX_TIMESTAMP(ts) AS t, temp_c FROM weather_hourly
              WHERE site_id = ? AND ts BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?) ORDER BY ts',
            [$siteId, $from - 3600, $to + 3600],
        );
        $series = [];
        foreach ($rows as $row) {
            $series[(int) $row['t']] = (float) $row['temp_c'];
        }
        return $series;
    }

    /**
     * Typical Pristina temperature for a timestamp: annual cycle (coldest mid-January)
     * plus a daily cycle (coolest ~05:00, warmest ~15:00). Used only as a fallback.
     */
    public static function climatologyAt(int $ts): float
    {
        $dayOfYear = (int) gmdate('z', $ts);
        $hour = (int) gmdate('G', $ts) + 1; // ≈ local time in Kosovo
        $annual = 10.0 - 11.0 * cos(2 * M_PI * ($dayOfYear - 15) / 365.0);
        $daily = -5.0 * cos(2 * M_PI * ($hour - 5) / 24.0);
        return round($annual + $daily, 1);
    }

    /** @return array<int, array{0: float, 1: bool}> */
    private static function fetch(float $latitude, float $longitude, int $from, int $to): array
    {
        $now = time();
        $pastDays = max(0, min(92, (int) ceil(($now - $from) / 86400) + 1));
        $forecastDays = max(1, min(16, (int) ceil(($to - $now) / 86400) + 1));
        $url = self::API . '?' . http_build_query([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'hourly' => 'temperature_2m',
            'past_days' => $pastDays,
            'forecast_days' => $forecastDays,
            'timezone' => 'GMT',
            'timeformat' => 'unixtime',
        ]);

        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5]);
        $raw = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($raw === false || $status !== 200) {
            error_log('[EnergyFlow] Open-Meteo unavailable (HTTP ' . $status . '), using climatology');
            return [];
        }

        $json = json_decode((string) $raw, true);
        $times = $json['hourly']['time'] ?? [];
        $temps = $json['hourly']['temperature_2m'] ?? [];
        $hours = [];
        foreach ($times as $i => $t) {
            if ($temps[$i] === null || $t < $from - 3600 || $t > $to + 3600) {
                continue;
            }
            $hours[(int) $t] = [(float) $temps[$i], $t > $now];
        }
        // Fill any gaps (e.g. range older than 92 days) with climatology.
        for ($t = $from; $t <= $to; $t += 3600) {
            $hours[$t] ??= [self::climatologyAt($t), false];
        }
        return $hours;
    }

    /** @return array<int, array{0: float, 1: bool}> */
    private static function climatology(int $from, int $to): array
    {
        $hours = [];
        for ($t = $from; $t <= $to; $t += 3600) {
            $hours[$t] = [self::climatologyAt($t), false];
        }
        return $hours;
    }
}
