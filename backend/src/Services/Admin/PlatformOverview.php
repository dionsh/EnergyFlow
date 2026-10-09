<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Admin;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\Env;
use EnergyFlow\Core\Migrator;
use EnergyFlow\Utils\Time;

/**
 * Counts from EnergyFlow's own database for the platform admin panel. Customer
 * figures leave out the fictional demo company; demo use is counted on its own.
 */
final class PlatformOverview
{
    public static function build(): array
    {
        $companies = Database::one(
            'SELECT COALESCE(SUM(is_demo = 0), 0) AS real_count, COALESCE(SUM(is_demo = 0 AND created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY), 0) AS new_30d
               FROM companies',
        );
        $users = Database::one(
            'SELECT COUNT(*) AS total, COALESCE(SUM(u.disabled_at IS NOT NULL), 0) AS disabled,
                    COALESCE(SUM(u.created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY), 0) AS new_30d
               FROM users u JOIN companies c ON c.id = u.company_id WHERE c.is_demo = 0',
        );
        $active = Database::one(
            'SELECT COUNT(DISTINCT CASE WHEN s.last_used_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY THEN s.user_id END) AS day,
                    COUNT(DISTINCT s.user_id) AS week
               FROM sessions s JOIN users u ON u.id = s.user_id JOIN companies c ON c.id = u.company_id
              WHERE c.is_demo = 0 AND s.last_used_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY',
        );
        $demoSessions = (int) Database::value(
            'SELECT COUNT(*) FROM sessions s JOIN users u ON u.id = s.user_id JOIN companies c ON c.id = u.company_id
              WHERE c.is_demo = 1 AND s.created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY',
        );
        $devices = Database::one(
            'SELECT COALESCE(SUM(d.is_simulated = 0), 0) AS hardware,
                    COALESCE(SUM(d.is_simulated = 0 AND d.last_seen_at >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE), 0) AS online,
                    COALESCE(SUM(d.is_simulated = 1), 0) AS simulated
               FROM devices d WHERE d.archived_at IS NULL',
        );
        $machines = (int) Database::value(
            "SELECT COUNT(*) FROM machines m JOIN companies c ON c.id = m.company_id
              WHERE c.is_demo = 0 AND m.kind = 'machine' AND m.archived_at IS NULL",
        );
        $sendingData = (int) Database::value(
            'SELECT COUNT(*) FROM companies c
              WHERE c.is_demo = 0 AND (SELECT MAX(r.bucket_start) FROM readings_15m r WHERE r.company_id = c.id) >= UTC_TIMESTAMP() - INTERVAL 1 DAY',
        );
        $activity = Database::one(
            "SELECT
               (SELECT COUNT(*) FROM reports r JOIN companies c ON c.id = r.company_id
                 WHERE c.is_demo = 0 AND r.created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS reports_30d,
               (SELECT COUNT(*) FROM ai_messages m JOIN ai_conversations a ON a.id = m.conversation_id JOIN companies c ON c.id = a.company_id
                 WHERE c.is_demo = 0 AND m.role = 'user' AND m.created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY) AS questions_7d,
               (SELECT COUNT(*) FROM device_commands d JOIN companies c ON c.id = d.company_id
                 WHERE c.is_demo = 0 AND d.status = 'verified' AND d.requested_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS turn_offs_30d",
        );

        return [
            'companies' => ['total' => (int) $companies['real_count'], 'new_30d' => (int) $companies['new_30d'], 'sending_data_24h' => $sendingData],
            'users' => [
                'total' => (int) $users['total'], 'disabled' => (int) $users['disabled'], 'new_30d' => (int) $users['new_30d'],
                'active_24h' => (int) $active['day'], 'active_7d' => (int) $active['week'],
            ],
            'demo' => ['sessions_7d' => $demoSessions],
            'devices' => ['hardware' => (int) $devices['hardware'], 'online' => (int) $devices['online'], 'simulated' => (int) $devices['simulated']],
            'machines' => $machines,
            'activity' => [
                'reports_30d' => (int) $activity['reports_30d'],
                'assistant_questions_7d' => (int) $activity['questions_7d'],
                'verified_turn_offs_30d' => (int) $activity['turn_offs_30d'],
            ],
            'signups' => self::signupsByWeek(12),
            'recent_companies' => array_slice(array_values(array_filter(CompanyDirectory::list(), static fn (array $c): bool => !$c['demo'])), 0, 5),
            'jobs' => self::jobs(),
            'system' => [
                'environment' => Env::get('APP_ENV', 'local'),
                'migration' => Migrator::latestVersion(),
                'database_mb' => round((float) Database::value(
                    'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()',
                ) / 1048576, 1),
                'php' => PHP_VERSION,
                'ai_configured' => Env::get('GROQ_API_KEY') !== null,
                'mail_configured' => Env::get('RESEND_API_KEY') !== null && Env::get('MAIL_FROM') !== null,
            ],
            'admin_activity' => AdminLog::recent(10),
        ];
    }

    /** New real companies and users per ISO week (Monday start), oldest first, empty weeks included. */
    private static function signupsByWeek(int $weeks): array
    {
        $monday = new \DateTimeImmutable('monday this week', new \DateTimeZone('UTC'));
        $from = $monday->modify('-' . ($weeks - 1) . ' weeks');
        $series = [];
        for ($week = $from; $week <= $monday; $week = $week->modify('+1 week')) {
            $series[$week->format('Y-m-d')] = ['week' => $week->format('Y-m-d'), 'companies' => 0, 'users' => 0];
        }
        $bucket = "DATE_FORMAT(DATE_SUB(DATE(x.created_at), INTERVAL WEEKDAY(x.created_at) DAY), '%Y-%m-%d')";
        $count = static function (string $sql) use ($from): array {
            return Database::all($sql, [$from->format('Y-m-d H:i:s')]);
        };
        foreach ($count("SELECT {$bucket} AS week, COUNT(*) AS n FROM companies x WHERE x.is_demo = 0 AND x.created_at >= ? GROUP BY week") as $r) {
            if (isset($series[$r['week']])) {
                $series[$r['week']]['companies'] = (int) $r['n'];
            }
        }
        foreach ($count("SELECT {$bucket} AS week, COUNT(*) AS n FROM users x JOIN companies c ON c.id = x.company_id WHERE c.is_demo = 0 AND x.created_at >= ? GROUP BY week") as $r) {
            if (isset($series[$r['week']])) {
                $series[$r['week']]['users'] = (int) $r['n'];
            }
        }
        return array_values($series);
    }

    /** The background jobs (cron-job.org → /internal/jobs/run), one row per job across companies. */
    private static function jobs(): array
    {
        return array_map(static fn (array $r): array => [
            'key' => $r['job_key'],
            'companies' => (int) $r['companies'],
            'errors' => (int) $r['errors'],
            'last_finished_at' => Time::iso($r['last_finished_at']),
            'last_error' => $r['last_error'] === null ? null : mb_substr((string) $r['last_error'], 0, 300),
        ], Database::all(
            "SELECT j.job_key, COUNT(*) AS companies, COALESCE(SUM(j.last_status = 'error'), 0) AS errors, MAX(j.last_finished_at) AS last_finished_at,
                    (SELECT e.last_error FROM job_runs e WHERE e.job_key = j.job_key AND e.last_status = 'error' ORDER BY e.last_finished_at DESC LIMIT 1) AS last_error
               FROM job_runs j GROUP BY j.job_key ORDER BY j.job_key",
        ));
    }
}
