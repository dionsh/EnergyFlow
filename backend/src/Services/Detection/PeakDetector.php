<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Detection;

use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

/**
 * Peak coincidence (PEAK_COINCIDENCE, method: pattern) — docs/03 §7.
 *
 * What is billed: ERO Decision V_2703_2025 (16 April 2025, unchanged for 2026)
 * charges engaged power per kW per month (3.87 €/kW for 0.4 kV Category I), and
 * ERO's tariff schedule measures it with a maximeter "which measures 15 min. and
 * stores the highest value recorded during the month" (footnote 5; the KEDS
 * Metering Code of December 2020 also defines maximum demand per 15-minute
 * period). So one quarter-hour sets the whole month's charge.
 *
 * The rule looks at that quarter-hour: which loads ran together in it, and how
 * much lower the month's peak would have been if the machines marked as
 * flexible loads (criticality "flexible": can run at another time) had not
 * overlapped with the rest — the highest quarter-hour of (site − flexible
 * loads). The difference, at the plan's €/kW, is what the coincidence costs per
 * month. Only flexible loads count, because only they can be moved; plants
 * without any, or whose peak has none, get no alert. Short start-up surges do
 * not matter here: the maximeter averages over 15 minutes.
 */
final class PeakDetector
{
    private const JOB = 'peak_detect';
    /** Judge a month only once it has a day of data. */
    private const MIN_BUCKETS = 96;
    public const MIN_AVOIDABLE_KW = 0.5;
    /** A machine "started" in the peak when the quarter-hour before it ran below this share of its peak power. */
    private const START_SHARE = 0.2;
    private const CONTEXT_S = 4 * 3600;

    public static function run(int $companyId, int $now): int
    {
        $time = LocalTime::forCompany($companyId);
        $due = MonthlyMeter::due($companyId, self::JOB, $now, $time);
        if ($due['watermark'] === null) {
            return 0;
        }
        $tariff = TariffBook::forCompany($companyId);
        $rate = $tariff === null ? 0.0 : (float) $tariff->plan['demand_charge_eur_kw_month'];
        $written = 0;
        foreach ($due['months'] as $monthStart) {
            $written += self::evaluate($companyId, MonthlyMeter::load($companyId, $monthStart, $now, $time), $rate, $now);
        }
        MonthlyMeter::done($companyId, self::JOB, $due['watermark']);
        return $written;
    }

    /** The month's peak and what the flexible loads added to it, or null when they added (almost) nothing. */
    public static function assess(MonthlyMeter $meter, float $rate): ?array
    {
        $flexible = array_keys(array_filter($meter->machines, static fn (array $m): bool => $m['criticality'] === 'flexible'));
        if ($rate <= 0 || $flexible === [] || count($meter->site) < self::MIN_BUCKETS) {
            return null;
        }
        $flexAt = static function (int $t) use ($meter, $flexible): float {
            $kw = 0.0;
            foreach ($flexible as $id) {
                $kw += $meter->byMachine[$id][$t]['kw'] ?? 0.0;
            }
            return $kw;
        };

        // Walk the month in order, so the alert opens at the quarter-hour the coincidence first cost something.
        $peak = null;
        $achievable = null;
        $since = null;
        foreach ($meter->site as $i => $b) {
            $without = $b['kw'] - $flexAt($b['t']);
            if ($peak === null || $b['kw'] > $peak['kw']) {
                $peak = $b;
            }
            if ($achievable === null || $without > $achievable['kw']) {
                $achievable = ['t' => $b['t'], 'kw' => $without];
            }
            if ($since === null && $i + 1 >= self::MIN_BUCKETS && $peak['kw'] - $achievable['kw'] >= self::MIN_AVOIDABLE_KW) {
                $since = $peak['t'] + MonthlyMeter::BUCKET_S;
            }
        }
        $avoidable = $peak['kw'] - $achievable['kw'];
        if ($avoidable < self::MIN_AVOIDABLE_KW) {
            return null;
        }

        // Who ran in the peak quarter-hour, and who had only just started.
        $loads = [];
        $machinesKw = 0.0;
        foreach ($meter->machines as $id => $m) {
            $kw = $meter->byMachine[$id][$peak['t']]['kw'] ?? 0.0;
            if ($kw <= 0.05) {
                continue;
            }
            $before = $meter->byMachine[$id][$peak['t'] - MonthlyMeter::BUCKET_S]['kw'] ?? 0.0;
            $machinesKw += $kw;
            $loads[] = [
                'id' => $id, 'code' => $m['code'], 'name' => $m['name'], 'kw' => round($kw, 2),
                'flexible' => $m['criticality'] === 'flexible', 'started' => $before < self::START_SHARE * $kw,
            ];
        }
        usort($loads, static fn (array $a, array $b): int => $b['kw'] <=> $a['kw']);
        $flexLoads = array_values(array_filter($loads, static fn (array $l): bool => $l['flexible']));

        $sorted = $meter->site;
        usort($sorted, static fn (array $a, array $b): int => $b['kw'] <=> $a['kw']);
        $kws = array_column($meter->site, 'kw');
        sort($kws);
        $p95 = $kws[(int) floor(0.95 * (count($kws) - 1))];

        return [
            'month' => $meter->month,
            'closed' => $meter->closed,
            'since' => $since ?? $peak['t'] + MonthlyMeter::BUCKET_S,
            'last' => $meter->site[array_key_last($meter->site)]['t'] + MonthlyMeter::BUCKET_S,
            'peak' => ['t' => $peak['t'], 'kw' => $peak['kw'], 'flexible_kw' => $flexAt($peak['t'])],
            'achievable' => $achievable,
            'avoidable_kw' => $avoidable,
            'rate' => $rate,
            'eur_month' => $avoidable * $rate,
            'billed_eur' => $peak['kw'] * $rate,
            'p95_kw' => $p95,
            'loads' => $loads,
            'flexible' => $flexLoads,
            'unmonitored_kw' => max(0.0, $peak['kw'] - $machinesKw),
            'top' => array_map(
                static fn (array $b): array => ['t' => $b['t'], 'kw' => $b['kw'], 'flexible_kw' => $flexAt($b['t'])],
                array_slice($sorted, 0, 5),
            ),
            'series' => array_map(
                static fn (array $b): array => ['t' => $b['t'], 'kw' => $b['kw'], 'flexible_kw' => $flexAt($b['t'])],
                array_values(array_filter($meter->site, static fn (array $b): bool => abs($b['t'] - $peak['t']) <= self::CONTEXT_S)),
            ),
        ];
    }

    private static function evaluate(int $companyId, MonthlyMeter $meter, float $rate, int $now): int
    {
        $key = "peak:{$meter->month}";
        $a = self::assess($meter, $rate);
        if ($a === null) {
            AlertManager::resolve($companyId, $key, min($now, $meter->to));
            return 0;
        }
        $iso = static fn (int $ts): string => (string) Time::iso(gmdate('Y-m-d H:i:s', $ts));
        $quarter = static fn (array $b): array => ['t' => $iso($b['t']), 'kw' => round($b['kw'], 2), 'flexible_kw' => round($b['flexible_kw'], 2)];
        $main = $a['flexible'][0] ?? null;

        AlertManager::upsert($companyId, [
            'type' => 'PEAK_COINCIDENCE',
            'method' => 'pattern',
            'severity' => SeverityPolicy::peakCoincidence($a['eur_month']),
            'dedupe_key' => $key,
            'title_key' => 'peak_coincidence',
            'params' => [
                'month' => $a['month'],
                'peak_kw' => round($a['peak']['kw'], 1),
                'peak_at' => $iso($a['peak']['t']),
                'achievable_kw' => round($a['achievable']['kw'], 1),
                'avoidable_kw' => round($a['avoidable_kw'], 2),
                'eur' => round($a['eur_month'], 2),
                'billed_eur' => round($a['billed_eur'], 2),
                'flexible' => implode(', ', array_column($a['flexible'], 'code')),
                'machine' => $main['name'] ?? null,
                'code' => $main['code'] ?? null,
                'since' => $iso($a['since']),
            ],
            'evidence' => [
                'method' => 'pattern',
                'rule' => 'flexible_loads_in_peak_quarter_hour',
                'metric' => 'site power, 15-minute average (what the maximeter bills)',
                'period' => ['month' => $a['month'], 'from' => $iso($meter->from), 'to' => $iso($a['last']), 'closed' => $a['closed']],
                'peak' => $quarter($a['peak']),
                'achievable' => ['t' => $iso($a['achievable']['t']), 'kw' => round($a['achievable']['kw'], 2)],
                'avoidable_kw' => round($a['avoidable_kw'], 2),
                'charge' => ['rate_eur_kw_month' => $a['rate'], 'billed_eur' => round($a['billed_eur'], 2), 'avoidable_eur' => round($a['eur_month'], 2)],
                'p95_kw' => round($a['p95_kw'], 2),
                'thresholds' => ['min_avoidable_kw' => self::MIN_AVOIDABLE_KW, 'start_share' => self::START_SHARE],
                'loads' => $a['loads'],
                'unmonitored_kw' => round($a['unmonitored_kw'], 2),
                'top' => array_map($quarter, $a['top']),
                'series' => array_map($quarter, $a['series']),
                'source' => 'ERO Decision V_2703_2025 (16 Apr 2025): engaged power per kW per month; ERO tariff schedule, footnote 5: the maximeter measures 15 minutes and keeps the month\'s highest value. Tariffs kept for 2026 (ERO Board, 7 May 2026).',
            ],
            'machine_id' => $main['id'] ?? null,
            'opened_at' => $a['since'],
            'last_seen_at' => $a['last'],
            'resolved_at' => $a['closed'] ? $meter->end : null,
            'link' => '/waste?tab=alerts&alert={id}',
        ], $now);
        return 1;
    }
}
