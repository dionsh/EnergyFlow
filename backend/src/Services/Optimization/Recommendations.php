<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Optimization;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Services\AuditLog;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Calendar\ScheduleBook;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\StoryHooks;
use EnergyFlow\Utils\Time;

/**
 * Opportunities (docs/03-architecture.md §10.1). Deterministic generators look
 * at the company's own detections; every candidate is quantified by replaying
 * the machine's last 30 days (WhatIf), never by a rule of thumb.
 *
 *   AfterHoursSchedule  ≥ 3 after-hours episodes in 14 days → auto-off policy   € + CO₂
 *   CompressedAirLeak   leak signature on a compressor → leak survey + repair     € + CO₂
 *   EfficiencyDrift     running power ≥ 10 % above reference → maintenance        € + CO₂
 *   TouShift            flexible load in the day tariff → run it at night         € only
 *
 * Priority = (0.5·€ + 0.3·CO₂ + 0.2·peak, each normalised) × confidence ÷ effort
 * (low 1, medium 1.5, high 2.5). Lifecycle: proposed → accepted → implemented →
 * verified, or dismissed (suppressed for 30 days: a feedback loop).
 */
final class Recommendations
{
    private const JOB = 'recommendations';
    private const EFFORT = ['low' => 1.0, 'medium' => 1.5, 'high' => 2.5];
    private const SUPPRESS_S = 30 * 86400;

    /** Runs the generators (at most once per hour of company time unless forced). */
    public static function generate(int $companyId, int $now, bool $force = false): int
    {
        $last = Database::value('SELECT UNIX_TIMESTAMP(watermark) FROM job_runs WHERE job_key = ? AND company_id = ?', [self::JOB, $companyId]);
        if (!$force && $last !== null && $now - (int) $last < 3600) {
            return 0;
        }
        $candidates = [
            ...self::afterHours($companyId, $now),
            ...self::compressedAir($companyId, $now),
            ...self::drift($companyId),
            ...self::touShift($companyId),
        ];

        // Normalise impact across the candidates, then rank.
        $maxEur = 0.01;
        $maxCo2 = 0.01;
        foreach ($candidates as $c) {
            $maxEur = max($maxEur, $c['what_if']['per_month']['eur']);
            $maxCo2 = max($maxCo2, $c['what_if']['per_month']['co2_kg']);
        }
        $written = 0;
        foreach ($candidates as $c) {
            $month = $c['what_if']['per_month'];
            if ($month['eur'] < 0.5 && $month['kwh'] < 5) {
                continue; // not worth anyone's time
            }
            $score = (0.5 * $month['eur'] / $maxEur + 0.3 * $month['co2_kg'] / $maxCo2) * $c['confidence'] / self::EFFORT[$c['effort']];
            $band = $score >= 0.4 ? 'high' : ($score >= 0.15 ? 'medium' : 'optimization');
            $written += self::upsert($companyId, $c, round($score * 100, 2), $band, $now);
        }

        Database::run(
            "INSERT INTO job_runs (job_key, company_id, watermark, last_started_at, last_finished_at, last_status)
             VALUES (?, ?, FROM_UNIXTIME(?), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'ok')
             ON DUPLICATE KEY UPDATE watermark = VALUES(watermark), last_finished_at = VALUES(last_finished_at), last_status = 'ok'",
            [self::JOB, $companyId, $now],
        );
        return $written;
    }

    /** @return list<array> ranked, newest decisions last */
    public static function list(int $companyId, ?string $status = null): array
    {
        $where = 'r.company_id = ?';
        $args = [$companyId];
        if ($status === 'open') {
            $where .= " AND r.status = 'proposed'";
        } elseif ($status === 'active') {
            $where .= " AND r.status IN ('accepted', 'implemented', 'verified')";
        } elseif ($status === 'dismissed') {
            $where .= " AND r.status = 'dismissed'";
        }
        return array_map(
            static fn (array $r): array => self::present($r),
            Database::all(self::SELECT . " WHERE {$where} ORDER BY FIELD(r.status, 'proposed', 'accepted', 'implemented', 'verified', 'dismissed'), r.priority_score DESC", $args),
        );
    }

    public static function show(int $companyId, int $id): array
    {
        $row = Database::one(self::SELECT . ' WHERE r.company_id = ? AND r.id = ?', [$companyId, $id])
            ?? throw HttpException::notFound('recommendation_not_found', 'Opportunity not found.');
        $rec = self::present($row);
        $rec['evidence'] = json_decode((string) $row['evidence'], true) ?: [];
        $whatIf = json_decode((string) $row['proposed_policy'], true)['what_if'] ?? null;
        if ($whatIf !== null && $rec['machine'] !== null) {
            try {
                $rec['replay'] = WhatIf::run($companyId, ['action' => $whatIf['action'], 'machine_id' => $rec['machine']['id'], 'params' => $whatIf['params'], 'window_days' => 30]);
            } catch (HttpException) {
                $rec['replay'] = null;
            }
        }
        return $rec;
    }

    /**
     * Accept. An automation (auto-off) becomes a live policy right away — that is T₀ for
     * the before/after proof — so it is "implemented". A maintenance action is "accepted"
     * until someone marks the work done.
     */
    public static function accept(int $companyId, int $id, int $userId, string $mode): array
    {
        $row = self::row($companyId, $id);
        if ($row['status'] !== 'proposed') {
            throw HttpException::conflict('recommendation_state', 'This opportunity was already decided.');
        }
        $now = Clock::now($companyId);
        $proposal = json_decode((string) $row['proposed_policy'], true) ?: [];
        $policy = $proposal['policy'] ?? null;
        if ($policy !== null) {
            $relay = Database::value(
                'SELECT 1 FROM device_channels dc JOIN devices d ON d.id = dc.device_id WHERE dc.machine_id = ? AND dc.valid_to IS NULL AND dc.has_relay = 1 AND d.archived_at IS NULL',
                [(int) $row['machine_id']],
            );
            if ($relay === null) {
                throw HttpException::conflict('no_relay', 'No remote-STOP relay is wired to this machine.');
            }
            Database::run('UPDATE automation_policies SET is_active = 0 WHERE company_id = ? AND machine_id = ? AND type = ?', [$companyId, (int) $row['machine_id'], $policy['type']]);
            $policyId = Database::insert(
                'INSERT INTO automation_policies (company_id, machine_id, recommendation_id, type, params, mode, is_active, effective_from, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, FROM_UNIXTIME(?), ?, FROM_UNIXTIME(?))',
                [$companyId, (int) $row['machine_id'], $id, $policy['type'], json_encode($policy['params']), $mode, $now, $userId, $now],
            );
            AuditLog::record($companyId, $userId, 'policy.create', 'automation_policy', $policyId, ['recommendation_id' => $id, 'mode' => $mode] + $policy);
        }
        Database::run(
            'UPDATE recommendations SET status = ?, accepted_by = ?, accepted_at = FROM_UNIXTIME(?), updated_at = FROM_UNIXTIME(?) WHERE id = ?',
            [$policy !== null ? 'implemented' : 'accepted', $userId, $now, $now, $id],
        );
        AuditLog::record($companyId, $userId, 'recommendation.accept', 'recommendation', $id, ['mode' => $mode]);
        return self::show($companyId, $id);
    }

    /** The maintenance work was done: T₀ for the before/after proof. */
    public static function markImplemented(int $companyId, int $id, int $userId): array
    {
        $row = self::row($companyId, $id);
        if ($row['status'] !== 'accepted') {
            throw HttpException::conflict('recommendation_state', 'Only accepted opportunities can be marked as done.');
        }
        $now = Clock::now($companyId);
        Database::run("UPDATE recommendations SET status = 'implemented', updated_at = FROM_UNIXTIME(?) WHERE id = ?", [$now, $id]);
        AuditLog::record($companyId, $userId, 'recommendation.implemented', 'recommendation', $id);
        StoryHooks::onImplemented($companyId, $row, $now);
        return self::show($companyId, $id);
    }

    public static function dismiss(int $companyId, int $id, int $userId, string $reason): array
    {
        $row = self::row($companyId, $id);
        if (!in_array($row['status'], ['proposed', 'accepted'], true)) {
            throw HttpException::conflict('recommendation_state', 'This opportunity can no longer be dismissed.');
        }
        $now = Clock::now($companyId);
        Database::run(
            "UPDATE recommendations SET status = 'dismissed', dismissed_reason = ?, suppressed_until = FROM_UNIXTIME(?), updated_at = FROM_UNIXTIME(?) WHERE id = ?",
            [$reason, $now + self::SUPPRESS_S, $now, $id],
        );
        AuditLog::record($companyId, $userId, 'recommendation.dismiss', 'recommendation', $id, ['reason' => $reason]);
        return self::show($companyId, $id);
    }

    /** Open opportunities for a machine, for cross-links (waste drawer, machine page, assistant). */
    public static function forMachine(int $companyId, int $machineId): array
    {
        return array_map(
            static fn (array $r): array => self::present($r),
            Database::all(self::SELECT . " WHERE r.company_id = ? AND r.machine_id = ? AND r.status <> 'dismissed' ORDER BY r.priority_score DESC", [$companyId, $machineId]),
        );
    }

    // ---- generators -------------------------------------------------------------------

    private static function afterHours(int $companyId, int $now): array
    {
        $out = [];
        $schedule = ScheduleBook::forCompany($companyId);
        $time = LocalTime::forCompany($companyId);
        foreach (Database::all(
            "SELECT w.machine_id, COUNT(*) AS episodes, SUM(w.energy_kwh) AS kwh, MAX(w.started_at) AS last_at, m.code, m.name, m.schedule_id
               FROM waste_events w JOIN machines m ON m.id = w.machine_id
              WHERE w.company_id = ? AND w.type = 'after_hours' AND w.action_status <> 'dismissed' AND w.started_at >= FROM_UNIXTIME(?)
                AND m.criticality <> 'critical' AND m.control_mode <> 'monitor'
                AND NOT EXISTS (SELECT 1 FROM automation_policies p WHERE p.machine_id = w.machine_id AND p.is_active = 1 AND p.type = 'auto_off_after_schedule')
              GROUP BY w.machine_id HAVING episodes >= 3",
            [$companyId, $now - 14 * 86400],
        ) as $r) {
            $whatIf = self::whatIf($companyId, (int) $r['machine_id'], 'auto_off_after_schedule', ['grace_min' => 15]);
            if ($whatIf === null) {
                continue;
            }
            $end = $schedule->lastScheduledEnd((int) $r['schedule_id'], $now, (int) $r['machine_id']);
            $out[] = [
                'generator' => 'AfterHoursSchedule',
                'machine_id' => (int) $r['machine_id'],
                'title_key' => 'after_hours_schedule',
                'params' => ['machine' => $r['name'], 'code' => $r['code'], 'episodes' => (int) $r['episodes'], 'grace_min' => 15,
                             'schedule_end' => $end === null ? null : $time->format($end, 'H:i')],
                'evidence' => ['episodes_14d' => (int) $r['episodes'], 'kwh_14d' => round((float) $r['kwh'], 1), 'last_episode' => Time::iso($r['last_at']), 'method' => 'rule'],
                'policy' => ['type' => 'auto_off_after_schedule', 'params' => ['grace_min' => 15]],
                'what_if' => $whatIf,
                'effort' => 'low',
                'capex' => 'none',
                'confidence' => min(0.95, 0.55 + 0.05 * (int) $r['episodes']),
            ];
        }
        return $out;
    }

    private static function compressedAir(int $companyId, int $now): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT w.machine_id, COUNT(*) AS episodes, m.code, m.name FROM waste_events w JOIN machines m ON m.id = w.machine_id
              WHERE w.company_id = ? AND m.type_code = 'compressor' AND w.started_at >= FROM_UNIXTIME(?)
                AND JSON_SEARCH(w.evidence, 'one', 'leak_signature') IS NOT NULL
              GROUP BY w.machine_id",
            [$companyId, $now - 30 * 86400],
        ) as $r) {
            $whatIf = self::whatIf($companyId, (int) $r['machine_id'], 'leak_repair', ['repair_share' => 0.5]);
            if ($whatIf === null) {
                continue;
            }
            $out[] = [
                'generator' => 'CompressedAirLeak',
                'machine_id' => (int) $r['machine_id'],
                'title_key' => 'compressed_air_leak',
                'params' => ['machine' => $r['name'], 'code' => $r['code'], 'leak_pct' => round($whatIf['params']['leak_share'] * 100, 0)],
                'evidence' => ['nights_with_signature' => (int) $r['episodes'], 'leak_share' => $whatIf['params']['leak_share'], 'method' => 'pattern'],
                'policy' => null,
                'what_if' => $whatIf,
                'effort' => 'medium',
                'capex' => 'low',
                'confidence' => 0.6,
            ];
        }
        return $out;
    }

    private static function drift(int $companyId): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT w.machine_id, w.evidence, m.code, m.name FROM waste_events w JOIN machines m ON m.id = w.machine_id
              WHERE w.company_id = ? AND w.type = 'excess_vs_baseline' AND w.ended_at IS NULL AND w.action_status <> 'dismissed'",
            [$companyId],
        ) as $r) {
            $evidence = json_decode((string) $r['evidence'], true) ?: [];
            $pct = (float) ($evidence['deviation_pct'] ?? 0);
            if ($pct < 10) {
                continue;
            }
            $whatIf = self::whatIf($companyId, (int) $r['machine_id'], 'efficiency_restore', ['improvement_pct' => $pct]);
            if ($whatIf === null) {
                continue;
            }
            $out[] = [
                'generator' => 'EfficiencyDrift',
                'machine_id' => (int) $r['machine_id'],
                'title_key' => 'efficiency_drift',
                'params' => ['machine' => $r['name'], 'code' => $r['code'], 'pct' => $pct],
                'evidence' => ['deviation_pct' => $pct, 'since' => $evidence['cusum']['onset'] ?? null, 'reference_kw' => $evidence['reference']['mean_kw'] ?? null, 'method' => 'statistical'],
                'policy' => null,
                'what_if' => $whatIf,
                'effort' => 'medium',
                'capex' => 'low',
                'confidence' => 0.7,
            ];
        }
        return $out;
    }

    private static function touShift(int $companyId): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT id, code, name FROM machines WHERE company_id = ? AND criticality = 'flexible' AND kind = 'machine' AND archived_at IS NULL",
            [$companyId],
        ) as $m) {
            $whatIf = self::whatIf($companyId, (int) $m['id'], 'tou_shift', ['shift_share' => 0.5]);
            if ($whatIf === null) {
                continue;
            }
            $time = LocalTime::forCompany($companyId);
            $dayKwh = (float) Database::value(
                "SELECT SUM(kwh) FROM readings_15m WHERE machine_id = ? AND tariff_period = 'high' AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?)",
                [(int) $m['id'], $time->at($whatIf['window']['from']), $time->at($whatIf['window']['to']) + 86400],
            );
            $out[] = [
                'generator' => 'TouShift',
                'machine_id' => (int) $m['id'],
                'title_key' => 'tou_shift',
                'params' => ['machine' => $m['name'], 'code' => $m['code'], 'share_pct' => 50],
                'evidence' => ['day_tariff_kwh_30d' => round($dayKwh, 1), 'total_kwh_30d' => $whatIf['baseline']['kwh'], 'method' => 'rule'],
                'policy' => null,
                'what_if' => $whatIf,
                'effort' => 'medium',
                'capex' => 'none',
                'confidence' => 0.5,
            ];
        }
        return $out;
    }

    private static function whatIf(int $companyId, int $machineId, string $action, array $params): ?array
    {
        try {
            return WhatIf::run($companyId, ['action' => $action, 'machine_id' => $machineId, 'params' => $params, 'window_days' => 30]);
        } catch (HttpException) {
            return null; // not enough data yet
        }
    }

    // ---- storage ----------------------------------------------------------------------

    private static function upsert(int $companyId, array $c, float $score, string $band, int $now): int
    {
        $key = $c['generator'] . ':m' . $c['machine_id'];
        $existing = Database::one('SELECT id, status, UNIX_TIMESTAMP(suppressed_until) AS suppressed FROM recommendations WHERE company_id = ? AND dedupe_key = ?', [$companyId, $key]);
        if ($existing !== null && $existing['status'] !== 'proposed'
            && !($existing['status'] === 'dismissed' && (int) $existing['suppressed'] <= $now)) {
            return 0; // decided (or still suppressed): never overwrite a person's decision
        }
        $month = $c['what_if']['per_month'];
        $proposal = json_encode([
            'policy' => $c['policy'],
            'what_if' => ['action' => $c['what_if']['action'], 'params' => $c['what_if']['params']],
        ]);
        $values = [
            $c['title_key'], json_encode($c['params'], JSON_UNESCAPED_UNICODE), json_encode($c['evidence'], JSON_UNESCAPED_UNICODE), $proposal,
            $c['what_if']['impact_tag'], $month['kwh'], $month['eur'], $month['co2_kg'], $c['effort'], $c['capex'], $c['confidence'], $score, $band,
        ];
        if ($existing === null) {
            Database::run(
                "INSERT INTO recommendations (company_id, machine_id, generator, dedupe_key, title_key, params, evidence, proposed_policy, impact_tag,
                                              est_kwh_month, est_eur_month, est_co2_kg_month, effort, capex, confidence, priority_score, priority_band,
                                              status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'proposed', FROM_UNIXTIME(?), FROM_UNIXTIME(?))",
                [$companyId, $c['machine_id'], $c['generator'], $key, ...$values, $now, $now],
            );
        } else {
            Database::run(
                "UPDATE recommendations SET title_key = ?, params = ?, evidence = ?, proposed_policy = ?, impact_tag = ?, est_kwh_month = ?, est_eur_month = ?,
                        est_co2_kg_month = ?, effort = ?, capex = ?, confidence = ?, priority_score = ?, priority_band = ?, status = 'proposed',
                        dismissed_reason = NULL, suppressed_until = NULL, updated_at = FROM_UNIXTIME(?)
                  WHERE id = ?",
                [...$values, $now, (int) $existing['id']],
            );
        }
        return 1;
    }

    private const SELECT = 'SELECT r.*, m.code, m.name AS machine_name, m.type_code, u.full_name AS accepted_by_name,
               (SELECT p.id FROM automation_policies p WHERE p.recommendation_id = r.id ORDER BY p.id DESC LIMIT 1) AS policy_id
          FROM recommendations r LEFT JOIN machines m ON m.id = r.machine_id LEFT JOIN users u ON u.id = r.accepted_by';

    private static function row(int $companyId, int $id): array
    {
        return Database::one('SELECT * FROM recommendations WHERE company_id = ? AND id = ?', [$companyId, $id])
            ?? throw HttpException::notFound('recommendation_not_found', 'Opportunity not found.');
    }

    private static function present(array $r): array
    {
        $proposal = json_decode((string) $r['proposed_policy'], true) ?: [];
        return [
            'id' => (int) $r['id'],
            'generator' => $r['generator'],
            'title_key' => $r['title_key'],
            'params' => json_decode((string) $r['params'], true) ?: [],
            'machine' => $r['machine_id'] === null ? null : ['id' => (int) $r['machine_id'], 'code' => $r['code'], 'name' => $r['machine_name'], 'type' => $r['type_code']],
            'impact_tag' => $r['impact_tag'],
            'per_month' => ['kwh' => (float) $r['est_kwh_month'], 'eur' => (float) $r['est_eur_month'], 'co2_kg' => (float) $r['est_co2_kg_month']],
            'effort' => $r['effort'],
            'capex' => $r['capex'],
            'confidence' => (float) $r['confidence'],
            'priority_score' => (float) $r['priority_score'],
            'priority_band' => $r['priority_band'],
            'status' => $r['status'],
            'policy' => $proposal['policy'] ?? null,
            'what_if' => $proposal['what_if'] ?? null,
            'policy_id' => $r['policy_id'] === null ? null : (int) $r['policy_id'],
            'accepted_by' => $r['accepted_by_name'],
            'accepted_at' => Time::iso($r['accepted_at']),
            'dismissed_reason' => $r['dismissed_reason'],
            'updated_at' => Time::iso($r['updated_at']),
        ];
    }
}
