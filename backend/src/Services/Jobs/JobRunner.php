<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Jobs;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Telemetry\RollupService;
use Throwable;

/**
 * Background work without background workers: cron-job.org calls
 * POST /api/v1/internal/jobs/run every 5 minutes (also keeping Render awake).
 * Each job is incremental and idempotent, so a missed tick only delays work.
 */
final class JobRunner
{
    private const RAW_RETENTION_S = 72 * 3600;

    /** @return array<string, mixed> summary of what ran */
    public static function run(int $budgetSeconds = 20): array
    {
        $deadline = microtime(true) + $budgetSeconds;
        $summary = ['demo_rows' => 0, 'rollup_buckets' => 0, 'retention_deleted' => 0, 'offline_devices' => 0, 'errors' => []];

        foreach (Database::all('SELECT company_id FROM sim_state') as $row) {
            if (microtime(true) > $deadline) {
                break;
            }
            $summary['demo_rows'] += self::attempt($summary, 'demo', static fn (): int => DemoClock::catchUp((int) $row['company_id']));
        }

        $companies = Database::all(
            'SELECT DISTINCT d.company_id FROM devices d WHERE d.is_simulated = 0 AND d.archived_at IS NULL',
        );
        foreach ($companies as $row) {
            if (microtime(true) > $deadline) {
                break;
            }
            $companyId = (int) $row['company_id'];
            $summary['rollup_buckets'] += self::attempt($summary, 'rollup', static fn (): int => RollupService::run($companyId, Clock::now($companyId)));
            if (!DemoClock::isDemo($companyId)) {
                // The demo company runs its pipeline inside the catch-up above.
                self::attempt($summary, 'analytics', static fn (): int => array_sum(AnalyticsPipeline::run($companyId, Clock::now($companyId))));
            }
        }

        foreach (Database::all('SELECT DISTINCT company_id FROM machine_live') as $row) {
            $companyId = (int) $row['company_id'];
            $cutoff = Clock::now($companyId) - self::RAW_RETENTION_S;
            $summary['retention_deleted'] += self::attempt($summary, 'retention', static fn (): int => Database::run(
                'DELETE r FROM readings_raw r JOIN machines m ON m.id = r.machine_id WHERE m.company_id = ? AND r.ts < FROM_UNIXTIME(?)',
                [$companyId, $cutoff],
            ));
            $summary['offline_devices'] += self::attempt($summary, 'devices', static fn (): int => Database::run(
                "UPDATE devices SET status = 'offline' WHERE company_id = ? AND is_simulated = 0 AND status = 'online' AND last_seen_at < FROM_UNIXTIME(?)",
                [$companyId, Clock::now($companyId) - 120],
            ));
        }
        return $summary;
    }

    private static function attempt(array &$summary, string $job, callable $work): int
    {
        try {
            return (int) $work();
        } catch (Throwable $e) {
            error_log("[EnergyFlow] job {$job} failed: " . $e->getMessage());
            $summary['errors'][] = $job;
            return 0;
        }
    }
}
