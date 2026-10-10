<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Detection;

use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

/**
 * Low power factor (LOW_PF, method: rule) — docs/03 §7.
 *
 * What is billed: ERO Decision V_2703_2025 (16 April 2025, retail tariffs of the
 * Universal Service Supplier, unchanged for 2026 by the ERO Board on 7 May 2026)
 * charges 0.4 kV Category I and the MV/HV groups for reactive energy: "The
 * customer is charged with reactive energy above the allowed one which
 * corresponds to cos(φ) < 0.95" (footnote of the tariff table), at the plan's
 * €/kVArh rate (0.87 €c/kVArh for Category I). So the rule works like the bill:
 * over the month, kVArh above kWh × tan(acos 0.95) is charged.
 *
 * The alert says what that costs so far and at this pace for the month, which
 * machines draw the reactive energy (cos φ below 0.80 is flagged as poor, the
 * docs/03 machine threshold), and how much power-factor correction would bring
 * the site to 0.95 at its usual working load: Qc = P · (tan φ − tan φ_target),
 * the standard sizing of a capacitor bank. Without a reactive charge in the
 * tariff, a site cos φ below 0.80 is still reported, as info and without €.
 */
final class PowerFactorDetector
{
    private const JOB = 'pf_detect';
    /** Judge a month only once it has a day of data, so the first hours do not decide. */
    private const MIN_BUCKETS = 96;
    public const POOR = 0.80;
    /** The share of the month's peak above which the site counts as working (for sizing). */
    private const WORKING_SHARE = 0.5;

    public static function run(int $companyId, int $now): int
    {
        $time = LocalTime::forCompany($companyId);
        $due = MonthlyMeter::due($companyId, self::JOB, $now, $time);
        if ($due['watermark'] === null) {
            return 0;
        }
        $tariff = TariffBook::forCompany($companyId);
        $written = 0;
        foreach ($due['months'] as $monthStart) {
            $written += self::evaluate($companyId, MonthlyMeter::load($companyId, $monthStart, $now, $time), $tariff, $now);
        }
        MonthlyMeter::done($companyId, self::JOB, $due['watermark']);
        return $written;
    }

    /** The month's figures as the bill would see them, or null without reactive data. */
    public static function assess(MonthlyMeter $meter, ?TariffBook $tariff): ?array
    {
        $buckets = array_values(array_filter($meter->site, static fn (array $b): bool => $b['kvarh'] !== null));
        if (count($buckets) < self::MIN_BUCKETS) {
            return null;
        }
        $threshold = $tariff?->plan['reactive_pf_threshold'] === null ? 0.95 : (float) $tariff->plan['reactive_pf_threshold'];
        $rate = $tariff === null ? 0.0 : (float) $tariff->plan['reactive_charge_eur_kvarh'];
        $allowedPerKwh = tan(acos($threshold));

        // Walk the month in order, so the alert opens at the quarter-hour the condition first held.
        $kwh = 0.0;
        $kvarh = 0.0;
        $since = null;
        foreach ($buckets as $i => $b) {
            $kwh += $b['kwh'];
            $kvarh += $b['kvarh'];
            if ($since === null && $i + 1 >= self::MIN_BUCKETS && self::condition($kwh, $kvarh, $allowedPerKwh, $rate)) {
                $since = $b['t'] + MonthlyMeter::BUCKET_S;
            }
        }
        if (!self::condition($kwh, $kvarh, $allowedPerKwh, $rate)) {
            return null;
        }
        $excess = max(0.0, $kvarh - $kwh * $allowedPerKwh);
        $eur = $excess * $rate;
        // At this pace to the end of the month (from the measured span, so a mid-month start is not over-projected).
        $first = $buckets[0]['t'];
        $last = end($buckets)['t'] + MonthlyMeter::BUCKET_S;
        $projected = $meter->closed || $last - $first < 86400 ? null : $eur + $eur / ($last - $first) * max(0, $meter->end - $last);

        // Sizing at the usual working load: the quarter-hours above half the month's peak.
        $peak = max(array_column($buckets, 'kw'));
        $working = array_values(array_filter($buckets, static fn (array $b): bool => $b['kw'] >= self::WORKING_SHARE * $peak));
        $workKwh = array_sum(array_column($working, 'kwh'));
        $workKvarh = array_sum(array_column($working, 'kvarh'));
        $workKw = $working === [] ? 0.0 : array_sum(array_column($working, 'kw')) / count($working);
        $tanNow = $workKwh > 0 ? $workKvarh / $workKwh : 0.0;
        $kvar = max(0.0, $workKw * ($tanNow - $allowedPerKwh));

        $machines = [];
        $machineKvarh = 0.0;
        foreach ($meter->byMachine as $id => $rows) {
            $mKwh = 0.0;
            $mKvarh = 0.0;
            foreach ($rows as $r) {
                if ($r['kvarh'] !== null) {
                    $mKwh += $r['kwh'];
                    $mKvarh += $r['kvarh'];
                }
            }
            if ($mKwh <= 0 || !isset($meter->machines[$id])) {
                continue;
            }
            $machineKvarh += $mKvarh;
            $cos = self::cosPhi($mKwh, $mKvarh);
            $machines[] = [
                'id' => $id, 'code' => $meter->machines[$id]['code'], 'name' => $meter->machines[$id]['name'],
                'kwh' => round($mKwh, 1), 'kvarh' => round($mKvarh, 1), 'cos_phi' => round($cos, 3),
                'share' => $kvarh > 0 ? round($mKvarh / $kvarh, 4) : 0.0, 'poor' => $cos < self::POOR,
            ];
        }
        usort($machines, static fn (array $a, array $b): int => $b['kvarh'] <=> $a['kvarh']);

        return [
            'month' => $meter->month,
            'closed' => $meter->closed,
            'since' => $since,
            'last' => $last,
            'kwh' => $kwh,
            'kvarh' => $kvarh,
            'cos_phi' => self::cosPhi($kwh, $kvarh),
            'threshold' => $threshold,
            'allowed_kvarh' => $kwh * $allowedPerKwh,
            'excess_kvarh' => $excess,
            'rate' => $rate,
            'eur' => $eur,
            'projected_eur' => $projected,
            'billed' => $rate > 0 && $excess > 0,
            'working_kw' => $workKw,
            'working_cos_phi' => self::cosPhi($workKwh, $workKvarh),
            'kvar_needed' => $kvar,
            'machines' => $machines,
            'unmonitored_kvarh' => max(0.0, $kvarh - $machineKvarh),
        ];
    }

    public static function cosPhi(float $kwh, float $kvarh): float
    {
        return $kwh > 0 ? $kwh / sqrt($kwh * $kwh + $kvarh * $kvarh) : 1.0;
    }

    /** Billed reactive energy, or (without a reactive charge) a poor power factor. */
    private static function condition(float $kwh, float $kvarh, float $allowedPerKwh, float $rate): bool
    {
        if ($kwh <= 0) {
            return false;
        }
        return $rate > 0 ? $kvarh > $kwh * $allowedPerKwh : self::cosPhi($kwh, $kvarh) < self::POOR;
    }

    private static function evaluate(int $companyId, MonthlyMeter $meter, ?TariffBook $tariff, int $now): int
    {
        $key = "low_pf:{$meter->month}";
        $a = self::assess($meter, $tariff);
        if ($a === null) {
            AlertManager::resolve($companyId, $key, min($now, $meter->to));
            return 0;
        }
        $iso = static fn (int $ts): string => (string) Time::iso(gmdate('Y-m-d H:i:s', $ts));
        $worst = null;
        foreach ($a['machines'] as $m) {
            if ($worst === null || $m['cos_phi'] < $worst['cos_phi']) {
                $worst = $m;
            }
        }
        AlertManager::upsert($companyId, [
            'type' => 'LOW_PF',
            'method' => 'rule',
            'severity' => SeverityPolicy::lowPowerFactor($a['billed'], $a['projected_eur'] ?? $a['eur']),
            'dedupe_key' => $key,
            'title_key' => 'low_pf',
            'params' => [
                'month' => $a['month'],
                'cos_phi' => round($a['cos_phi'], 3),
                'threshold' => $a['threshold'],
                'excess_kvarh' => round($a['excess_kvarh'], 0),
                'eur' => round($a['eur'], 2),
                'projected_eur' => $a['projected_eur'] === null ? null : round($a['projected_eur'], 2),
                'billed' => $a['billed'],
                'kvar_needed' => round($a['kvar_needed'], 1),
                'worst_code' => $worst['code'] ?? null,
                'worst_cos_phi' => $worst['cos_phi'] ?? null,
                'since' => $iso($a['since'] ?? $a['last']),
            ],
            'evidence' => [
                'method' => 'rule',
                'rule' => 'reactive_energy_above_cos_phi',
                'metric' => 'site cos φ over the billing month (kWh and kVArh of the main incomer)',
                'period' => ['month' => $a['month'], 'from' => $iso($meter->from), 'to' => $iso($a['last']), 'closed' => $a['closed']],
                'observed' => [
                    'kwh' => round($a['kwh'], 1), 'kvarh' => round($a['kvarh'], 1), 'cos_phi' => round($a['cos_phi'], 3),
                    'allowed_kvarh' => round($a['allowed_kvarh'], 1), 'excess_kvarh' => round($a['excess_kvarh'], 1),
                ],
                'charge' => ['rate_eur_kvarh' => $a['rate'], 'eur' => round($a['eur'], 2), 'projected_eur' => $a['projected_eur'] === null ? null : round($a['projected_eur'], 2)],
                'thresholds' => ['cos_phi' => $a['threshold'], 'poor_cos_phi' => self::POOR],
                'compensation' => [
                    'working_kw' => round($a['working_kw'], 1), 'working_cos_phi' => round($a['working_cos_phi'], 3),
                    'target_cos_phi' => $a['threshold'], 'kvar' => round($a['kvar_needed'], 1),
                    'formula' => 'Qc = P · (tan φ − tan φ_target)',
                ],
                'machines' => array_slice($a['machines'], 0, 8),
                'unmonitored_kvarh' => round($a['unmonitored_kvarh'], 1),
                'source' => 'ERO Decision V_2703_2025 (16 Apr 2025), tariff table: reactive energy charged above the allowance that corresponds to cos φ 0.95. Tariffs kept for 2026 (ERO Board, 7 May 2026).',
            ],
            'opened_at' => $a['since'] ?? $a['last'],
            'last_seen_at' => $a['last'],
            'resolved_at' => $a['closed'] ? $meter->end : null,
            'link' => '/waste?tab=alerts&alert={id}',
        ], $now);
        return 1;
    }
}
