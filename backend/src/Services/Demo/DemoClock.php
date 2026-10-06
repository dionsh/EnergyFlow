<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Demo;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Simulation\SimContext;
use EnergyFlow\Services\Simulation\Simulator;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Services\Telemetry\IngestService;
use EnergyFlow\Services\Telemetry\RollupService;
use EnergyFlow\Services\Weather\WeatherService;

/**
 * Keeps the demo company's data up to date with its virtual clock.
 * Render's free tier has no background workers, so instead of a running
 * simulator we "catch up" on demand (live polling, cron tick): the missing
 * time range is generated in one go — identical to what a running simulator
 * would have produced, because the simulator is deterministic.
 */
final class DemoClock
{
    private const LIVE_WINDOW = 7200; // longer gaps are filled with buckets; only the last hour is 10-s raw

    public static function isDemo(int $companyId): bool
    {
        return Database::value('SELECT 1 FROM sim_state WHERE company_id = ?', [$companyId]) !== null;
    }

    /** Generates data up to the virtual now. Cheap when nothing is missing. */
    public static function catchUp(int $companyId): int
    {
        $lock = 'ef_sim_' . $companyId;
        if ((int) Database::value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return 0; // another request is already catching up
        }
        try {
            Clock::forget($companyId);
            $now = Clock::now($companyId);
            $now -= $now % 10;
            $last = Database::value('SELECT UNIX_TIMESTAMP(last_generated_at) FROM sim_state WHERE company_id = ?', [$companyId]);
            if ($last === null || $now - (int) $last < 10) {
                return 0;
            }
            $last = (int) $last;

            // Keep real weather ahead of the virtual clock (one API call per day at most).
            $site = Database::one('SELECT id, latitude, longitude FROM sites WHERE company_id = ? LIMIT 1', [$companyId]);
            if ($site !== null && Database::value('SELECT 1 FROM weather_hourly WHERE site_id = ? AND ts >= FROM_UNIXTIME(?)', [(int) $site['id'], $now + 3600]) === null) {
                WeatherService::ensure((int) $site['id'], (float) $site['latitude'], (float) $site['longitude'], $last - 86400, $now + 3 * 86400);
            }

            $context = SimContext::load($companyId, $last - 3600, $now + 3600);
            $simulator = new Simulator($context, TariffBook::forCompany($companyId));
            $rawFrom = $last + 10;
            $rows = 0;

            if ($now - $last > self::LIVE_WINDOW) {
                $bucketFrom = (int) ceil($rawFrom / 900) * 900;
                $rows += self::raw($companyId, $simulator, $rawFrom, $bucketFrom - 10);
                $bucketTo = intdiv($now - 3600, 900) * 900;
                if ($bucketTo > $bucketFrom) {
                    $rows += $simulator->writeBuckets($bucketFrom, $bucketTo);
                    // Buckets were written directly: move the rollup watermark past them.
                    Database::run(
                        "UPDATE job_runs SET watermark = GREATEST(watermark, FROM_UNIXTIME(?)) WHERE job_key = 'rollup' AND company_id = ?",
                        [$bucketTo, $companyId],
                    );
                    $rawFrom = $bucketTo;
                } else {
                    $rawFrom = $bucketFrom;
                }
            }
            $rows += self::raw($companyId, $simulator, $rawFrom, $now);

            Database::run('UPDATE sim_state SET last_generated_at = FROM_UNIXTIME(?) WHERE company_id = ?', [$now, $companyId]);
            Database::run("UPDATE devices SET status = 'online', last_seen_at = FROM_UNIXTIME(?) WHERE company_id = ? AND is_simulated = 1", [$now, $companyId]);
            RollupService::run($companyId, $now);
            return $rows;
        } finally {
            Database::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Fast-forward the virtual clock (e.g. "+7 days" after accepting a recommendation). */
    public static function advance(int $companyId, int $seconds): int
    {
        Database::run('UPDATE sim_state SET clock_offset_s = clock_offset_s + ? WHERE company_id = ?', [$seconds, $companyId]);
        Clock::forget($companyId);
        return self::catchUp($companyId);
    }

    /**
     * Anchor for a named scene, in real time terms.
     *   'now'             → the real current time
     *   'weekday_evening' → the most recent Monday–Thursday at 21:40 (after the 21:00 shift end)
     */
    public static function sceneAnchor(string $scene, string $timezone = 'Europe/Belgrade'): int
    {
        $real = Clock::realNow();
        if ($scene !== 'weekday_evening') {
            return $real;
        }
        $time = new LocalTime($timezone);
        for ($d = 0; $d < 8; $d++) {
            $day = $real - $d * 86400;
            $candidate = $time->at($time->date($day), 21 * 60 + 40);
            if ($candidate <= $real && $time->dayOfWeek($candidate) <= 4) {
                return $candidate;
            }
        }
        return $real;
    }

    private static function raw(int $companyId, Simulator $simulator, int $from, int $to): int
    {
        $count = 0;
        $batch = [];
        for ($ts = $from - ($from % 10); $ts <= $to; $ts += 10) {
            array_push($batch, ...$simulator->readingsAt($ts));
            if (count($batch) >= 3000) {
                $count += IngestService::store($companyId, $batch, 'simulator')['accepted'];
                $batch = [];
            }
        }
        if ($batch !== []) {
            $count += IngestService::store($companyId, $batch, 'simulator')['accepted'];
        }
        return $count;
    }
}
