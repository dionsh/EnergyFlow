<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Detection;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Services\Analytics\EnergyQuery;
use EnergyFlow\Services\Analytics\OverviewService;
use EnergyFlow\Services\Analytics\Period;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Carbon\EmissionFactors;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Optimization\Recommendations;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

/**
 * Read side of waste detection: period summaries, the event list and one
 * event's full story. Ongoing episodes are brought up to the second with the
 * still-open tail of raw readings, so the numbers tick while you watch.
 */
final class WasteReport
{
    private const BUCKET = 900;

    public static function summary(int $companyId, Period $period): array
    {
        $time = LocalTime::forCompany($companyId);
        $factor = EmissionFactors::gridFactor($companyId);
        $current = self::attribute($companyId, self::eventsOverlapping($companyId, $period->from, $period->to), $period->from, $period->to, $time);
        $previous = self::attribute($companyId, self::eventsOverlapping($companyId, $period->previousFrom, $period->previousTo), $period->previousFrom, $period->previousTo, $time);

        $tariff = TariffBook::forCompany($companyId);
        $site = EnergyQuery::site(EnergyQuery::byMachine($companyId, $period->from, $period->to, null, $tariff), OverviewService::incomerId($companyId));

        $byType = [];
        $byMachine = [];
        $daily = [];
        $largest = null;
        foreach ($current['items'] as $item) {
            if ($largest === null || $item['eur'] > $largest['eur']) {
                $largest = $item;
            }
            $byType[$item['type']] ??= ['type' => $item['type'], 'kwh' => 0.0, 'eur' => 0.0, 'events' => 0];
            $byType[$item['type']]['kwh'] += $item['kwh'];
            $byType[$item['type']]['eur'] += $item['eur'];
            $byType[$item['type']]['events']++;
            $m = $item['machine'];
            $byMachine[$m['id']] ??= ['machine' => $m, 'kwh' => 0.0, 'eur' => 0.0, 'events' => 0];
            $byMachine[$m['id']]['kwh'] += $item['kwh'];
            $byMachine[$m['id']]['eur'] += $item['eur'];
            $byMachine[$m['id']]['events']++;
            foreach ($item['days'] as $date => [$kwh, $eur]) {
                $daily[$date] ??= ['date' => $date, 'kwh' => 0.0, 'eur' => 0.0];
                $daily[$date]['kwh'] += $kwh;
                $daily[$date]['eur'] += $eur;
            }
        }
        $round = static fn (array $rows): array => array_values(array_map(static function (array $r) use ($factor): array {
            $r['kwh'] = round($r['kwh'], 1);
            $r['eur'] = round($r['eur'], 2);
            $r['co2_kg'] = round($r['kwh'] * $factor['value'], 1);
            return $r;
        }, $rows));
        usort($byType, static fn (array $a, array $b): int => $b['kwh'] <=> $a['kwh']);
        usort($byMachine, static fn (array $a, array $b): int => $b['kwh'] <=> $a['kwh']);
        // Every day of the period, so charts have a continuous axis (days without waste are 0).
        for ($day = $time->startOfDay($period->from); $day < $period->to; $day = $time->startOfDay($day + 26 * 3600)) {
            $daily[$time->date($day)] ??= ['date' => $time->date($day), 'kwh' => 0.0, 'eur' => 0.0];
        }
        ksort($daily);

        $total = $current['kwh'];
        return [
            'period' => $period->meta(),
            'totals' => [
                'kwh' => round($total, 1),
                'eur' => round($current['eur'], 2),
                'co2_kg' => round($total * $factor['value'], 1),
                'events' => count($current['items']),
                'share_of_consumption' => $site['kwh'] > 0 ? round($total / $site['kwh'], 4) : null,
                'consumption_kwh' => round($site['kwh'], 0),
            ],
            'previous' => [
                'kwh' => round($previous['kwh'], 1),
                'eur' => round($previous['eur'], 2),
                'change_ratio' => $previous['kwh'] > 0 ? round($total / $previous['kwh'] - 1, 4) : null,
            ],
            'by_type' => $round($byType),
            'by_machine' => $round($byMachine),
            // The costliest event, counted (like the totals) only for the part inside the period.
            'largest' => $largest === null ? null : [
                'id' => $largest['id'], 'type' => $largest['type'], 'code' => $largest['machine']['code'], 'name' => $largest['machine']['name'],
                'started_at' => $largest['started_at'], 'kwh' => round($largest['kwh'], 1), 'eur' => round($largest['eur'], 2),
                'co2_kg' => round($largest['kwh'] * $factor['value'], 1),
            ],
            'daily' => $round($daily),
            'open_alerts' => self::openAlertCounts($companyId),
            'factor' => ['value' => $factor['value'], 'source' => $factor['source_name']],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function list(int $companyId, Period $period, array $filters): array
    {
        $where = ['w.company_id = ?', 'w.started_at < FROM_UNIXTIME(?)', '(w.ended_at IS NULL OR w.ended_at >= FROM_UNIXTIME(?))'];
        $params = [$companyId, $period->to, $period->from];
        if (!empty($filters['type'])) {
            $where[] = 'w.type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['machine_id'])) {
            $where[] = 'w.machine_id = ?';
            $params[] = (int) $filters['machine_id'];
        }
        match ($filters['status'] ?? null) {
            'open' => $where[] = "w.ended_at IS NULL AND w.action_status <> 'dismissed'",
            'acted' => $where[] = "w.action_status = 'acted'",
            'dismissed' => $where[] = "w.action_status = 'dismissed'",
            default => $where[] = "w.action_status <> 'dismissed'",
        };
        $rows = Database::all(self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY w.started_at DESC LIMIT 300', $params);
        $live = self::liveContext($companyId);
        return array_map(static fn (array $row): array => self::present($row, $live), $rows);
    }

    public static function show(int $companyId, int $id): array
    {
        $row = Database::one(self::SELECT . ' WHERE w.company_id = ? AND w.id = ?', [$companyId, $id])
            ?? throw HttpException::notFound('waste_event_not_found', 'Waste event not found.');
        $event = self::present($row, self::liveContext($companyId));
        $event['evidence'] = json_decode((string) $row['evidence'], true) ?: [];

        if ($row['type'] !== 'excess_vs_baseline') {
            // The episode with context on both sides; 1-minute detail when it is recent and short.
            $start = strtotime($event['started_at']);
            $end = $event['ended_at'] === null ? Clock::now($companyId) : strtotime($event['ended_at']);
            $from = $start - 3 * 3600;
            $to = min(Clock::now($companyId), $end + 3600);
            $from = max($from, $to - 48 * 3600);
            $machineId = (int) $row['machine_id'];
            $resolution = ($to - $from) <= 8 * 3600 && self::hasRaw($machineId, $from) ? '1m' : '15m';
            $points = EnergyQuery::series($companyId, $machineId, $from, $to, $resolution, (string) $row['timezone']);
            if ($resolution === '15m') {
                // Closed buckets, then the still-open part minute by minute, so the chart reaches "now".
                $tailFrom = $points === [] ? $from : $points[count($points) - 1]['t'] + self::BUCKET;
                $points = [...$points, ...EnergyQuery::series($companyId, $machineId, $tailFrom, $to, '1m')];
            }
            $event['series'] = [
                'resolution' => $resolution,
                'points' => array_map(static fn (array $p): array => ['t' => Time::iso(gmdate('Y-m-d H:i:s', $p['t'])), 'kw' => $p['kw']], $points),
            ];
        }

        $alert = Database::one(
            'SELECT id, status, severity, UNIX_TIMESTAMP(acknowledged_at) AS acked FROM alerts WHERE company_id = ? AND waste_event_id = ? ORDER BY id DESC LIMIT 1',
            [$companyId, $id],
        );
        $event['alert'] = $alert === null ? null : ['id' => (int) $alert['id'], 'status' => $alert['status'], 'severity' => $alert['severity']];

        // The lasting fix for this kind of waste, if EnergyFlow has proposed one.
        $generators = match (true) {
            $row['type'] === 'excess_vs_baseline' => ['EfficiencyDrift'],
            $row['type'] === 'after_hours' && in_array('leak_signature', $event['signals'], true) => ['AfterHoursSchedule', 'CompressedAirLeak'],
            $row['type'] === 'after_hours' => ['AfterHoursSchedule'],
            default => [],
        };
        $event['opportunity'] = null;
        foreach (Recommendations::forMachine($companyId, (int) $row['machine_id']) as $rec) {
            if (in_array($rec['generator'], $generators, true)) {
                $event['opportunity'] = ['id' => $rec['id'], 'title_key' => $rec['title_key'], 'params' => $rec['params'], 'status' => $rec['status'], 'per_month' => $rec['per_month']];
                break;
            }
        }
        return $event;
    }

    public static function dismiss(int $companyId, int $id, string $reason): void
    {
        $row = Database::one('SELECT dedupe_key FROM waste_events WHERE company_id = ? AND id = ?', [$companyId, $id])
            ?? throw HttpException::notFound('waste_event_not_found', 'Waste event not found.');
        Database::run(
            "UPDATE waste_events SET action_status = 'dismissed', dismissed_reason = ? WHERE company_id = ? AND id = ?",
            [$reason, $companyId, $id],
        );
        AlertManager::resolve($companyId, (string) $row['dedupe_key'], Clock::now($companyId));
    }

    /** @return array{critical: int, warning: int, info: int, total: int} */
    public static function openAlertCounts(int $companyId): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach (Database::all(
            "SELECT severity, COUNT(*) AS n FROM alerts WHERE company_id = ? AND status <> 'resolved' GROUP BY severity",
            [$companyId],
        ) as $r) {
            $counts[$r['severity']] = (int) $r['n'];
        }
        return $counts + ['total' => array_sum($counts)];
    }

    private const SELECT = 'SELECT w.id, w.machine_id, w.type, w.severity, UNIX_TIMESTAMP(w.started_at) AS started, UNIX_TIMESTAMP(w.ended_at) AS ended,
               w.energy_kwh, w.cost_eur, w.co2_kg, w.action_status, w.dismissed_reason, w.command_id, w.evidence,
               m.code, m.name, m.type_code, m.criticality, m.control_mode, m.schedule_id, c.timezone
          FROM waste_events w JOIN machines m ON m.id = w.machine_id JOIN companies c ON c.id = w.company_id';

    /** Live state per machine, to bring ongoing episodes up to the second. */
    private static function liveContext(int $companyId): array
    {
        $now = Clock::now($companyId);
        $machines = [];
        foreach (Database::all(
            'SELECT machine_id, state, UNIX_TIMESTAMP(ts) AS ts, UNIX_TIMESTAMP(state_since) AS since FROM machine_live WHERE company_id = ?',
            [$companyId],
        ) as $l) {
            $machines[(int) $l['machine_id']] = $l;
        }
        return [
            'company_id' => $companyId,
            'now' => $now,
            'machines' => $machines,
            'tariff' => TariffBook::forCompany($companyId),
            'factor' => EmissionFactors::gridFactor($companyId),
        ];
    }

    private static function present(array $row, array $live): array
    {
        $evidence = json_decode((string) $row['evidence'], true) ?: [];
        $started = (int) $row['started'];
        $ended = $row['ended'] === null ? null : (int) $row['ended'];
        $kwh = (float) $row['energy_kwh'];
        $eur = (float) $row['cost_eur'];
        $ongoing = false;

        if ($ended === null && $row['type'] !== 'excess_vs_baseline') {
            $machineId = (int) $row['machine_id'];
            $l = $live['machines'][$machineId] ?? null;
            $on = $l !== null && $live['now'] - (int) $l['ts'] <= 120 && in_array($l['state'], ['running', 'idle'], true);
            $closedUntil = isset($evidence['window']['closed_until']) ? strtotime($evidence['window']['closed_until']) : null;
            if ($on) {
                $ongoing = true;
                $to = $live['now'];
            } else {
                // Switched off since the last detection run: it ended when the meter saw it stop.
                $to = $l === null ? $live['now'] : (int) $l['since'];
                $ended = $to;
            }
            if ($closedUntil !== null && $to > $closedUntil) {
                // Closed buckets + the raw-reading tail up to this second.
                $closed = self::bucketEnergy($machineId, $started, $closedUntil, $live['tariff']);
                $tail = EnergyQuery::byMachine($live['company_id'], $closedUntil, $to, $machineId, $live['tariff'])[$machineId] ?? null;
                $kwh = $closed['kwh'] + ($tail['kwh'] ?? 0.0);
                $eur = $closed['eur'] + ($tail === null ? 0.0 : EnergyQuery::energyCost($tail, $live['tariff']));
            }
        }

        $end = $ended ?? $live['now'];
        return [
            'id' => (int) $row['id'],
            'type' => $row['type'],
            'severity' => $row['severity'],
            'method' => $evidence['method'] ?? 'rule',
            'signals' => array_column($evidence['signals'] ?? [], 'key'),
            'machine' => [
                'id' => (int) $row['machine_id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $row['type_code'],
                'criticality' => $row['criticality'],
                'control_mode' => $row['control_mode'],
            ],
            'started_at' => Time::iso(gmdate('Y-m-d H:i:s', $started)),
            'ended_at' => $ended === null ? null : Time::iso(gmdate('Y-m-d H:i:s', $ended)),
            'ongoing' => $ongoing || ($row['type'] === 'excess_vs_baseline' && $row['ended'] === null),
            'duration_s' => max(0, $end - $started),
            'kwh' => round($kwh, 2),
            'eur' => round($eur, 2),
            'co2_kg' => round($kwh * $live['factor']['value'], 1),
            'deviation_pct' => $evidence['deviation_pct'] ?? null,
            'projection' => $ongoing ? ($evidence['projection'] ?? null) : null,
            'action_status' => $row['action_status'],
            'dismissed_reason' => $row['dismissed_reason'],
            'command_id' => $row['command_id'] === null ? null : (int) $row['command_id'],
        ];
    }

    /** kWh and € of the closed buckets of an episode. */
    private static function bucketEnergy(int $machineId, int $from, int $to, ?TariffBook $tariff): array
    {
        $r = Database::one(
            "SELECT SUM(kwh) AS kwh, SUM(CASE WHEN tariff_period = 'high' THEN kwh ELSE 0 END) AS kwh_high FROM readings_15m
              WHERE machine_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?)",
            [$machineId, $from, $to],
        );
        $kwh = (float) ($r['kwh'] ?? 0);
        $high = (float) ($r['kwh_high'] ?? 0);
        return ['kwh' => $kwh, 'eur' => EnergyQuery::energyCost(['kwh_high' => $high, 'kwh_low' => $kwh - $high], $tariff)];
    }

    /** Events (not dismissed) overlapping [from, to). */
    private static function eventsOverlapping(int $companyId, int $from, int $to): array
    {
        return Database::all(
            self::SELECT . " WHERE w.company_id = ? AND w.started_at < FROM_UNIXTIME(?)
               AND (w.ended_at IS NULL OR w.ended_at > FROM_UNIXTIME(?)) AND w.action_status <> 'dismissed'",
            [$companyId, $to, $from],
        );
    }

    /**
     * The part of each event that falls inside [from, to), per local day.
     * Drift events carry their excess per day; episodes are spread over their duration.
     *
     * @return array{kwh: float, eur: float, items: list<array>}
     */
    private static function attribute(int $companyId, array $rows, int $from, int $to, LocalTime $time): array
    {
        $live = self::liveContext($companyId);
        $items = [];
        $sumKwh = 0.0;
        $sumEur = 0.0;
        foreach ($rows as $row) {
            $event = self::present($row, $live);
            $evidence = json_decode((string) $row['evidence'], true) ?: [];
            $days = [];
            if (isset($evidence['excess_daily'])) {
                foreach ($evidence['excess_daily'] as $date => [$kwh, $eur]) {
                    $midday = $time->at((string) $date, 720);
                    if ($midday >= $from && $midday < $to) {
                        $days[$date] = [(float) $kwh, (float) $eur];
                    }
                }
            } else {
                $start = strtotime($event['started_at']);
                $end = $event['ended_at'] === null ? $live['now'] : strtotime($event['ended_at']);
                $span = max(1, $end - $start);
                $overlap = max(0, min($end, $to) - max($start, $from));
                if ($overlap > 0) {
                    $share = $overlap / $span;
                    $days[$time->date(max($start, $from))] = [$event['kwh'] * $share, $event['eur'] * $share];
                }
            }
            if ($days === []) {
                continue;
            }
            $kwh = array_sum(array_column($days, 0));
            $eur = array_sum(array_column($days, 1));
            $sumKwh += $kwh;
            $sumEur += $eur;
            $items[] = ['id' => $event['id'], 'type' => $event['type'], 'machine' => $event['machine'], 'started_at' => $event['started_at'], 'kwh' => $kwh, 'eur' => $eur, 'days' => $days];
        }
        return ['kwh' => $sumKwh, 'eur' => $sumEur, 'items' => $items];
    }

    private static function hasRaw(int $machineId, int $from): bool
    {
        return Database::value('SELECT 1 FROM readings_raw WHERE machine_id = ? AND ts <= FROM_UNIXTIME(?) LIMIT 1', [$machineId, $from + 600]) !== null;
    }
}
