<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Tariff;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Calendar\LocalTime;

/**
 * Kosovo electricity tariff engine.
 *
 * Supports what ERO Decision V_2703_2025 contains for commercial customers:
 * seasonal two-rate (high/low) energy prices, optional consumption blocks,
 * a fixed monthly fee, an engaged-power (demand) charge per kW and a reactive
 * energy charge above cos φ 0.95. Savings and waste are valued at the
 * marginal energy rate of the period in which the energy was (or would be) used.
 */
final class TariffBook
{
    /**
     * @param array<string, mixed> $plan
     * @param list<array{start_md: string, end_md: string, period: string, start: int, end: int}> $windows
     * @param array<string, list<array{from: int, to: ?int, rate: float}>> $rates period => blocks
     */
    public function __construct(
        public readonly array $plan,
        private readonly array $windows,
        private readonly array $rates,
        private readonly LocalTime $time,
    ) {
    }

    public static function forCompany(int $companyId): ?self
    {
        $row = Database::one('SELECT tariff_plan_id, timezone FROM companies WHERE id = ?', [$companyId]);
        if ($row === null || $row['tariff_plan_id'] === null) {
            return null;
        }
        return self::forPlan((int) $row['tariff_plan_id'], $row['timezone']);
    }

    public static function forPlan(int $planId, string $timezone = 'Europe/Belgrade'): self
    {
        $plan = Database::one('SELECT * FROM tariff_plans WHERE id = ?', [$planId]);
        $windows = array_map(
            static fn (array $w): array => [
                'start_md' => $w['season_start_mmdd'],
                'end_md' => $w['season_end_mmdd'],
                'period' => $w['period'],
                'start' => self::minutes($w['start_time']),
                'end' => self::minutes($w['end_time']),
            ],
            Database::all('SELECT * FROM tariff_windows WHERE tariff_plan_id = ?', [$planId]),
        );
        $rates = [];
        foreach (Database::all('SELECT * FROM tariff_rates WHERE tariff_plan_id = ? ORDER BY block_from_kwh', [$planId]) as $r) {
            $rates[$r['period']][] = [
                'from' => (int) $r['block_from_kwh'],
                'to' => $r['block_to_kwh'] === null ? null : (int) $r['block_to_kwh'],
                'rate' => (float) $r['rate_eur_kwh'],
            ];
        }
        return new self($plan, $windows, $rates, new LocalTime($timezone));
    }

    /** 'high' or 'low'. Flat plans are reported as 'high' (one price all day). */
    public function period(int $ts): string
    {
        if (!isset($this->rates['high'], $this->rates['low'])) {
            return 'high';
        }
        $md = gmdate('m-d', $ts + $this->time->offset($ts));
        $minute = $this->time->minuteOfDay($ts);
        foreach ($this->windows as $window) {
            if ($window['period'] !== 'high' || !self::inSeason($md, $window['start_md'], $window['end_md'])) {
                continue;
            }
            if ($minute >= $window['start'] && $minute < $window['end']) {
                return 'high';
            }
        }
        return 'low';
    }

    /** €/kWh for a period, given how many kWh were already used this month in that period. */
    public function energyRate(string $period, float $monthKwhSoFar = 0.0): float
    {
        $blocks = $this->rates[$period] ?? $this->rates['flat'] ?? $this->rates['high'] ?? [];
        foreach ($blocks as $block) {
            if ($block['to'] === null || $monthKwhSoFar < $block['to']) {
                return $block['rate'];
            }
        }
        return $blocks === [] ? 0.0 : $blocks[array_key_last($blocks)]['rate'];
    }

    /** Marginal energy price at $ts (€/kWh, excl. VAT). Used to value waste and savings. */
    public function marginalRate(int $ts): float
    {
        return $this->energyRate($this->period($ts), PHP_FLOAT_MAX);
    }

    /**
     * Monthly bill estimate, excl. and incl. VAT.
     *
     * @return array{lines: list<array{key: string, quantity: float, unit: string, rate: float, amount: float}>, subtotal: float, vat: float, total: float}
     */
    public function bill(float $kwhHigh, float $kwhLow, float $peakKw = 0.0, float $kvarh = 0.0, float $monthFraction = 1.0): array
    {
        $lines = [];
        $lines[] = $this->energyLine('energy_high', 'high', $kwhHigh);
        if (isset($this->rates['low'])) {
            $lines[] = $this->energyLine('energy_low', 'low', $kwhLow);
        }

        $demandRate = (float) $this->plan['demand_charge_eur_kw_month'];
        if ($demandRate > 0) {
            $lines[] = ['key' => 'engaged_power', 'quantity' => round($peakKw, 2), 'unit' => 'kW', 'rate' => $demandRate, 'amount' => round($peakKw * $demandRate, 2)];
        }

        $reactiveRate = (float) $this->plan['reactive_charge_eur_kvarh'];
        if ($reactiveRate > 0 && $this->plan['reactive_pf_threshold'] !== null) {
            $threshold = (float) $this->plan['reactive_pf_threshold'];
            $allowed = ($kwhHigh + $kwhLow) * tan(acos($threshold));
            $excess = max(0.0, $kvarh - $allowed);
            $lines[] = ['key' => 'reactive_energy', 'quantity' => round($excess, 1), 'unit' => 'kVArh', 'rate' => $reactiveRate, 'amount' => round($excess * $reactiveRate, 2)];
        }

        $fixed = (float) $this->plan['fixed_monthly_eur'];
        $lines[] = ['key' => 'fixed_fee', 'quantity' => 1, 'unit' => 'month', 'rate' => $fixed, 'amount' => round($fixed * $monthFraction, 2)];

        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $vat = round($subtotal * (float) $this->plan['vat_rate'], 2);
        return ['lines' => $lines, 'subtotal' => $subtotal, 'vat' => $vat, 'total' => round($subtotal + $vat, 2)];
    }

    /** Plain description for the UI and the assistant. */
    public function summary(): array
    {
        return [
            'id' => (int) $this->plan['id'],
            'name' => $this->plan['name'],
            'supplier' => $this->plan['supplier'],
            'category' => $this->plan['category'],
            'rate_high' => $this->energyRate('high', PHP_FLOAT_MAX),
            'rate_low' => isset($this->rates['low']) ? $this->energyRate('low', PHP_FLOAT_MAX) : null,
            'fixed_monthly_eur' => (float) $this->plan['fixed_monthly_eur'],
            'demand_charge_eur_kw_month' => (float) $this->plan['demand_charge_eur_kw_month'],
            'reactive_charge_eur_kvarh' => (float) $this->plan['reactive_charge_eur_kvarh'],
            'vat_rate' => (float) $this->plan['vat_rate'],
            'source_note' => $this->plan['source_note'],
        ];
    }

    private function energyLine(string $key, string $period, float $kwh): array
    {
        $rate = $this->energyRate($period, PHP_FLOAT_MAX);
        return ['key' => $key, 'quantity' => round($kwh, 1), 'unit' => 'kWh', 'rate' => $rate, 'amount' => round($kwh * $rate, 2)];
    }

    private static function inSeason(string $md, string $start, string $end): bool
    {
        return $start <= $end ? ($md >= $start && $md <= $end) : ($md >= $start || $md <= $end);
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    }
}
