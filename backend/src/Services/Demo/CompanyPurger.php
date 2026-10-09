<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Demo;

use EnergyFlow\Core\Database;

/**
 * Deletes a company and every row that belongs to it — including telemetry
 * tables that are keyed by machine/site rather than by a foreign key.
 * Used to rebuild the demo company, and by a platform admin deleting a
 * company's only user (UserAdmin::delete, which needs an explicit confirmation).
 */
final class CompanyPurger
{
    private const BY_COMPANY = [
        'audit_log', 'production_overrides', 'production_output', 'waste_events', 'alerts', 'forecasts',
        'notifications', 'score_snapshots', 'recommendations', 'automation_policies', 'device_commands',
        'impact_verifications', 'utility_bills', 'meter_readings', 'carbon_records', 'activity_data', 'esg_answers', 'reports',
        'ai_conversations', 'sim_state', 'sim_scenarios', 'job_runs', 'power_quality_events', 'readings_15m',
        'machine_live', 'calendar_days', 'emission_factors',
    ];

    public static function purge(int $companyId): void
    {
        $machineIds = array_map('intval', array_column(Database::all('SELECT id FROM machines WHERE company_id = ?', [$companyId]), 'id'));
        $siteIds = array_map('intval', array_column(Database::all('SELECT id FROM sites WHERE company_id = ?', [$companyId]), 'id'));

        foreach (array_chunk($machineIds, 200) as $chunk) {
            $in = implode(',', $chunk);
            Database::run("DELETE FROM readings_raw WHERE machine_id IN ({$in})");
            Database::run("DELETE FROM machine_state_events WHERE machine_id IN ({$in})");
            Database::run("DELETE FROM machine_baselines WHERE machine_id IN ({$in})");
            Database::run("DELETE FROM fingerprints WHERE machine_id IN ({$in})");
        }
        foreach (array_chunk($siteIds, 200) as $chunk) {
            Database::run('DELETE FROM weather_hourly WHERE site_id IN (' . implode(',', $chunk) . ')');
        }
        Database::run(
            'DELETE FROM notification_reads WHERE notification_id IN (SELECT id FROM notifications WHERE company_id = ?)',
            [$companyId],
        );
        foreach (self::BY_COMPANY as $table) {
            Database::run("DELETE FROM {$table} WHERE company_id = ?", [$companyId]);
        }
        Database::run(
            'DELETE FROM tariff_windows WHERE tariff_plan_id IN (SELECT id FROM tariff_plans WHERE company_id = ?)',
            [$companyId],
        );
        Database::run(
            'DELETE FROM tariff_rates WHERE tariff_plan_id IN (SELECT id FROM tariff_plans WHERE company_id = ?)',
            [$companyId],
        );
        Database::run('DELETE FROM tariff_plans WHERE company_id = ?', [$companyId]);
        // Cascades: users → sessions, sites → machines → channels/live, devices, schedules → rules, departments.
        Database::run('DELETE FROM devices WHERE company_id = ?', [$companyId]);
        Database::run('DELETE FROM machines WHERE company_id = ?', [$companyId]);
        Database::run('DELETE FROM companies WHERE id = ?', [$companyId]);
    }
}
