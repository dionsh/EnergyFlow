<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Analytics;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Detection\WasteReport;
use EnergyFlow\Utils\Time;

/**
 * EnergyFlow Score, 0–100 (docs/03-architecture.md §13): one number for the
 * owner, every part of it explainable. Each sub-score is 0–100 over the last
 * 7 days, with the figures it came from; weights and scales are in
 * config/scoring.php. Compared with the 7 days before.
 */
final class ScoreService
{
    private const ISSUE_ALERTS = ['CONSUMPTION_DRIFT', 'COMMAND_FAILED'];

    public static function summary(int $companyId): array
    {
        $config = require EF_ROOT . '/config/scoring.php';
        $now = Clock::now($companyId);
        $span = $config['window_days'] * 86400;
        $current = self::compute($companyId, $now - $span, $now, $config);
        $previous = self::compute($companyId, $now - 2 * $span, $now - $span, $config);

        // What to fix next: the biggest weighted gap.
        $next = null;
        foreach ($current['parts'] as $key => $part) {
            $gap = $part['weight'] * (100 - ($part['value'] ?? 100));
            if ($gap > 0.5 && ($next === null || $gap > $next['gap'])) {
                $next = ['key' => $key, 'gap' => round($gap, 1)];
            }
        }
        return [
            'score' => $current['score'],
            'previous' => $previous['score'],
            'change' => $current['score'] === null || $previous['score'] === null ? null : round($current['score'] - $previous['score'], 1),
            'parts' => $current['parts'],
            'next' => $next === null ? null : ['key' => $next['key'], 'points' => $next['gap']],
            'window' => ['from' => Time::iso(gmdate('Y-m-d H:i:s', $now - $span)), 'to' => Time::iso(gmdate('Y-m-d H:i:s', $now)), 'days' => $config['window_days']],
        ];
    }

    /** Keeps an hourly history (score_snapshots) for trends and reports. Cheap when already done this hour. */
    public static function snapshot(int $companyId, int $now): void
    {
        $hour = $now - $now % 3600;
        if (Database::value("SELECT 1 FROM score_snapshots WHERE company_id = ? AND kind = 'energyflow' AND computed_at = FROM_UNIXTIME(?)", [$companyId, $hour]) !== null) {
            return;
        }
        $config = require EF_ROOT . '/config/scoring.php';
        $result = self::compute($companyId, $hour - $config['window_days'] * 86400, $hour, $config);
        if ($result['score'] !== null) {
            Database::run(
                "INSERT IGNORE INTO score_snapshots (company_id, kind, computed_at, score, breakdown) VALUES (?, 'energyflow', FROM_UNIXTIME(?), ?, ?)",
                [$companyId, $hour, $result['score'], json_encode(array_map(static fn (array $p): ?float => $p['value'], $result['parts']))],
            );
        }
    }

    /** @return array{score: ?float, parts: array<string, array{value: ?float, weight: float, detail: array}>} */
    public static function compute(int $companyId, int $from, int $to, array $config): array
    {
        $incomer = OverviewService::incomerId($companyId);
        $by = EnergyQuery::byMachine($companyId, $from, $to);
        $site = EnergyQuery::site($by, $incomer);
        if ($site['kwh'] <= 0) {
            return ['score' => null, 'parts' => []];
        }
        $machines = Database::all("SELECT id, criticality FROM machines WHERE company_id = ? AND kind = 'machine' AND archived_at IS NULL", [$companyId]);
        $machineIds = array_map(static fn (array $m): int => (int) $m['id'], $machines);
        $parts = [];

        // Waste: quantified waste as a share of consumption.
        $waste = WasteReport::summary($companyId, Period::between($from, $to))['totals']['kwh'];
        $wasteShare = $waste / $site['kwh'];
        $parts['waste'] = ['value' => 100 * (1 - min(1, $wasteShare / $config['waste_share_zero'])), 'detail' => ['share' => round($wasteShare, 4), 'kwh' => round($waste, 1)]];

        // Schedule discipline: energy used outside schedules by machines that should be off (critical loads excluded).
        $offSchedule = (float) Database::value(
            "SELECT COALESCE(SUM(r.kwh), 0) FROM readings_15m r JOIN machines m ON m.id = r.machine_id
              WHERE r.company_id = ? AND r.bucket_start >= FROM_UNIXTIME(?) AND r.bucket_start < FROM_UNIXTIME(?)
                AND r.is_scheduled = 0 AND m.kind = 'machine' AND m.criticality <> 'critical' AND m.schedule_id IS NOT NULL",
            [$companyId, $from, $to],
        );
        $offShare = $offSchedule / $site['kwh'];
        $parts['schedule'] = ['value' => 100 * (1 - min(1, $offShare / $config['off_schedule_share_zero'])), 'detail' => ['share' => round($offShare, 4), 'kwh' => round($offSchedule, 1)]];

        // Equipment health: kWh-weighted share of machines without an open drift or failed-command alert.
        $sick = array_map('intval', array_column(Database::all(
            'SELECT DISTINCT machine_id FROM alerts WHERE company_id = ? AND machine_id IS NOT NULL AND type IN (' . implode(',', array_fill(0, count(self::ISSUE_ALERTS), '?')) . ')
               AND opened_at < FROM_UNIXTIME(?) AND (resolved_at IS NULL OR resolved_at >= FROM_UNIXTIME(?))',
            [$companyId, ...self::ISSUE_ALERTS, $to, $to],
        ), 'machine_id'));
        $machineKwh = array_sum(array_map(static fn (int $id): float => $by[$id]['kwh'] ?? 0.0, $machineIds));
        $sickKwh = array_sum(array_map(static fn (int $id): float => $by[$id]['kwh'] ?? 0.0, $sick));
        $parts['health'] = ['value' => $machineKwh > 0 ? 100 * (1 - $sickKwh / $machineKwh) : 100.0, 'detail' => ['machines' => count($sick), 'share' => $machineKwh > 0 ? round($sickKwh / $machineKwh, 4) : 0.0]];

        // Peak management: load factor = average ÷ peak 15-minute power.
        $peak = EnergyQuery::peakKw($companyId, $from, $to, $incomer);
        $loadFactor = $peak > 0 ? ($site['kwh'] / (($to - $from) / 3600)) / $peak : null;
        $parts['peak'] = ['value' => $loadFactor === null ? null : 100 * min(1, $loadFactor / $config['load_factor_full']),
            'detail' => ['load_factor' => $loadFactor === null ? null : round($loadFactor, 3), 'peak_kw' => round($peak, 1)]];

        // Follow-through: opportunities acted on, and alerts resolved within 24 h (last 30 days). The
        // monthly billing alerts (reactive energy, engaged-power peak) run for the whole month by design.
        $recs = Database::one(
            "SELECT SUM(status IN ('accepted','implemented','verified')) AS acted, SUM(status <> 'dismissed') AS total FROM recommendations WHERE company_id = ? AND created_at < FROM_UNIXTIME(?)",
            [$companyId, $to],
        );
        $alerts = Database::one(
            "SELECT COUNT(*) AS total, SUM(resolved_at IS NOT NULL AND resolved_at <= opened_at + INTERVAL 1 DAY) AS quick FROM alerts
              WHERE company_id = ? AND opened_at >= FROM_UNIXTIME(?) AND opened_at < FROM_UNIXTIME(?)
                AND type NOT IN ('LOW_PF', 'PEAK_COINCIDENCE')",
            [$companyId, $to - 30 * 86400, $to - 86400],
        );
        $actedShare = (int) $recs['total'] > 0 ? (int) $recs['acted'] / (int) $recs['total'] : null;
        $quickShare = (int) $alerts['total'] > 0 ? (int) $alerts['quick'] / (int) $alerts['total'] : null;
        $shares = array_values(array_filter([$actedShare, $quickShare], static fn (?float $v): bool => $v !== null));
        $parts['follow_through'] = ['value' => $shares === [] ? null : 100 * array_sum($shares) / count($shares),
            'detail' => ['acted' => (int) $recs['acted'], 'opportunities' => (int) $recs['total'], 'resolved_24h' => (int) $alerts['quick'], 'alerts' => (int) $alerts['total']]];

        // Data coverage: metered share of the incomer × completeness of the 15-minute data.
        $coverage = $incomer !== null && $site['kwh'] > 0 ? min(1.0, $machineKwh / $site['kwh']) : 1.0;
        $expected = count($machineIds) * (int) (($to - $from) / 900);
        $present = $expected > 0 ? (int) Database::value(
            "SELECT COUNT(*) FROM readings_15m r JOIN machines m ON m.id = r.machine_id
              WHERE r.company_id = ? AND m.kind = 'machine' AND r.bucket_start >= FROM_UNIXTIME(?) AND r.bucket_start < FROM_UNIXTIME(?)",
            [$companyId, $from, $to],
        ) : 0;
        $completeness = $expected > 0 ? min(1.0, $present / $expected) : 0.0;
        $parts['coverage'] = ['value' => 100 * $coverage * $completeness, 'detail' => ['metered_share' => round($coverage, 4), 'completeness' => round($completeness, 4)]];

        // Weighted average over the parts that could be computed.
        $sum = 0.0;
        $weights = 0.0;
        foreach ($parts as $key => &$part) {
            $part['weight'] = $config['weights'][$key];
            $part['value'] = $part['value'] === null ? null : round($part['value'], 1);
            if ($part['value'] !== null) {
                $sum += $part['weight'] * $part['value'];
                $weights += $part['weight'];
            }
        }
        unset($part);
        return ['score' => $weights > 0 ? round($sum / $weights, 1) : null, 'parts' => $parts];
    }
}
