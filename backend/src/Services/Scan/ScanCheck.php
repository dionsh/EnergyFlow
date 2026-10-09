<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Scan;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Analytics\EnergyQuery;
use EnergyFlow\Services\Analytics\OverviewService;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Carbon\EmissionFactors;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Tariff\TariffBook;

/**
 * What EnergyFlow can say about a scanned document — plain, explainable rules,
 * never the model's opinion. Each check is a key + status (pass · warn · fail ·
 * info) + parameters; the interface words them in the user's language.
 *
 * A bill can't be proven genuine from a photo. What can be checked is whether it
 * is internally consistent (dates, kWh, VAT, totals), whether its prices match
 * the official tariff, and whether its kWh match what EnergyFlow measured. A bill
 * that fails several integrity rules is reported as likely not genuine.
 */
final class ScanCheck
{
    /**
     * Electricity VAT in Kosovo: reduced rate 8 % (Law No. 05/L-037 on VAT; PwC
     * Kosovo tax summary, reviewed 13 Jan 2026). The company's tariff may override it.
     */
    private const ELECTRICITY_VAT = 0.08;

    /**
     * Electricity suppliers known to ERO (ZRRE): KESCO (universal service), the
     * supply-licence register of 24 Jun 2022, and later Board decisions (Eurosol&Energy
     * 2023; Swis Solar Park and Solar Neo, 7 Nov 2025). Not a complete current list —
     * an unknown name is a warning to check at ero-ks.org, never proof of a fake.
     */
    private const SUPPLIERS = [
        'KESCO' => ['kesco', 'kosovo electricity supply'],
        'Elektrosever' => ['elektrosever', 'електросевер'],
        'KEK' => ['kosovo energy corporation', 'korporata energjetike', ' kek '],
        'EZ5 Kosovo' => ['ez5'],
        'MCM Commodities' => ['mcm commodities'],
        'HEP Energjia' => ['hep energj', 'hep energ'],
        'EDS International' => ['eds international'],
        'Enerco' => ['enerco'],
        'Sharrcem' => ['sharrcem'],
        'Jaha Company' => ['jaha company'],
        'Future Energy Trading' => ['future energy trading', 'feted'],
        'GSA Energji' => ['gsa energj', 'gsa sh.p.k'],
        'Eurosol&Energy' => ['eurosol'],
        'Swis Solar Park' => ['swis solar'],
        'Solar Neo' => ['solar neo'],
    ];

    /** Fuel → emission factor activity (seeded from DESNZ 2025, migration 0014). */
    public const FUELS = ['diesel', 'petrol', 'gas_oil', 'lpg', 'heating_oil'];

    // ---------------------------------------------------------------- bill

    public static function bill(int $companyId, array $f): array
    {
        $out = new CheckList();
        $time = LocalTime::forCompany($companyId);
        $today = $time->date(Clock::now($companyId));
        $tariff = TariffBook::forCompany($companyId);
        $kwh = $f['kwh_total'] ?? (($f['kwh_high'] !== null && $f['kwh_low'] !== null) ? $f['kwh_high'] + $f['kwh_low'] : null);

        $missing = array_keys(array_filter([
            'supplier' => $f['supplier'] === null,
            'period' => $f['period_start'] === null && $f['period_end'] === null && $f['issue_date'] === null,
            'kwh_total' => $kwh === null,
            'total_eur' => $f['total_eur'] === null,
        ]));
        $missing === [] ? $out->add('fields_complete', 'pass') : $out->add('fields_missing', 'warn', ['fields' => $missing]);

        // Who issued it.
        $supplier = self::supplier($f['supplier']);
        if ($f['supplier'] !== null) {
            if ($supplier === 'KESCO') {
                $out->add('supplier_universal', 'pass', ['name' => $supplier]);
            } elseif ($supplier !== null) {
                $out->add('supplier_licensed', 'pass', ['name' => $supplier]);
            } else {
                $out->add('supplier_unknown', 'warn', ['name' => $f['supplier']]);
            }
            $tariffSupplier = $tariff?->summary()['supplier'];
            if ($supplier !== null && $tariffSupplier !== null && stripos($tariffSupplier, $supplier) === false) {
                $out->add('supplier_differs', 'info', ['bill' => $supplier, 'tariff' => $tariffSupplier]);
            }
        }

        // Dates.
        if ($f['issue_date'] !== null && $f['issue_date'] > $today) {
            $out->add('date_in_future', 'fail', ['date' => $f['issue_date']], integrity: true);
        }
        $days = null;
        if ($f['period_start'] !== null && $f['period_end'] !== null) {
            $days = (int) ((strtotime($f['period_end']) - strtotime($f['period_start'])) / 86400) + 1;
            if ($days < 1) {
                $out->add('period_reversed', 'fail', [], integrity: true);
                $days = null;
            } elseif ($days < 25 || $days > 35) {
                $out->add('period_unusual', 'warn', ['days' => $days]);
            } else {
                $out->add('period_ok', 'pass', ['days' => $days]);
            }
            if ($f['period_end'] > $today) {
                $out->add('period_in_future', 'fail', ['date' => $f['period_end']], integrity: true);
            }
        }
        if ($f['issue_date'] !== null && $f['period_end'] !== null && $f['issue_date'] < $f['period_end']) {
            $out->add('issued_before_period_end', 'warn', ['issue' => $f['issue_date'], 'end' => $f['period_end']]);
        }
        if ($f['due_date'] !== null && $f['issue_date'] !== null && $f['due_date'] < $f['issue_date']) {
            $out->add('due_before_issue', 'fail', [], integrity: true);
        }

        // kWh: day + night = total; meter readings explain the total.
        if ($f['kwh_high'] !== null && $f['kwh_low'] !== null && $f['kwh_total'] !== null) {
            $sum = $f['kwh_high'] + $f['kwh_low'];
            abs($sum - $f['kwh_total']) <= max(1.0, $f['kwh_total'] * 0.001)
                ? $out->add('kwh_sum', 'pass', ['high' => $f['kwh_high'], 'low' => $f['kwh_low'], 'total' => $f['kwh_total']])
                : $out->add('kwh_sum_wrong', 'fail', ['sum' => round($sum, 1), 'total' => $f['kwh_total']], integrity: true);
        }
        if ($f['meter_start'] !== null && $f['meter_end'] !== null && $kwh !== null) {
            $diff = $f['meter_end'] - $f['meter_start'];
            if ($diff <= 0) {
                $out->add('meter_backwards', 'fail', ['start' => $f['meter_start'], 'end' => $f['meter_end']], integrity: true);
            } elseif (abs($diff - $kwh) <= 1.0) {
                $out->add('meter_diff_ok', 'pass', ['diff' => round($diff, 1)]);
            } else {
                // Meters on current transformers multiply the dial difference by a fixed factor.
                $factor = $kwh / $diff;
                $factor >= 2 && abs($factor - round($factor)) / $factor < 0.01
                    ? $out->add('meter_multiplier', 'pass', ['factor' => (int) round($factor)])
                    : $out->add('meter_diff_wrong', 'warn', ['diff' => round($diff, 1), 'kwh' => $kwh]);
            }
        }

        // Money: net + VAT = total, VAT at the electricity rate.
        $vatRate = $tariff !== null ? (float) ($tariff->summary()['vat_rate'] ?: self::ELECTRICITY_VAT) : self::ELECTRICITY_VAT;
        if ($f['net_eur'] !== null && $f['vat_eur'] !== null && $f['total_eur'] !== null) {
            $tolerance = max(0.05, $f['total_eur'] * 0.002);
            if (abs($f['net_eur'] + $f['vat_eur'] - $f['total_eur']) <= $tolerance) {
                $out->add('money_sum', 'pass');
            } elseif ($f['previous_debt_eur'] !== null && abs($f['net_eur'] + $f['vat_eur'] + $f['previous_debt_eur'] - $f['total_eur']) <= $tolerance) {
                $out->add('money_sum_with_debt', 'pass', ['debt' => $f['previous_debt_eur']]);
            } else {
                $out->add('money_sum_wrong', 'fail', ['net' => $f['net_eur'], 'vat' => $f['vat_eur'], 'total' => $f['total_eur']], integrity: true);
            }
        }
        if ($f['net_eur'] !== null && $f['vat_eur'] !== null && $f['net_eur'] > 0) {
            $rate = $f['vat_eur'] / $f['net_eur'];
            abs($rate - $vatRate) <= 0.003
                ? $out->add('vat_rate', 'pass', ['rate' => round($rate, 4)])
                : $out->add('vat_rate_wrong', 'fail', ['rate' => round($rate, 4), 'expected' => $vatRate], integrity: true);
        }
        $net = $f['net_eur'] ?? ($f['total_eur'] !== null ? round(($f['total_eur'] - ($f['previous_debt_eur'] ?? 0)) / (1 + $vatRate), 2) : null);

        // The period as EnergyFlow measured it.
        $from = $f['period_start'] !== null ? $time->at($f['period_start']) : null;
        $to = $f['period_end'] !== null ? $time->at($f['period_end']) + 86400 : null;
        $metered = null;
        if ($from !== null && $to !== null && $to > $from) {
            $since = Database::value('SELECT UNIX_TIMESTAMP(MIN(bucket_start)) FROM readings_15m WHERE company_id = ?', [$companyId]);
            if ($since === null || (int) $since > $from + 86400) {
                $out->add('metering_not_covering', 'info');
            } else {
                $incomer = OverviewService::incomerId($companyId);
                $metered = EnergyQuery::site(EnergyQuery::byMachine($companyId, $from, $to, null, $tariff), $incomer);
                $metered['peak_kw'] = EnergyQuery::peakKw($companyId, $from, $to, $incomer);
                if ($kwh !== null && $metered['kwh'] > 0) {
                    $ratio = $kwh / $metered['kwh'] - 1;
                    $params = ['billed' => $kwh, 'metered' => round($metered['kwh'], 1), 'diff' => round($ratio, 4)];
                    match (true) {
                        abs($ratio) <= 0.03 => $out->add('metered_match', 'pass', $params),
                        abs($ratio) <= 0.10 => $out->add('metered_diff', 'warn', $params),
                        default => $out->add('metered_diff_large', 'warn', $params),
                    };
                }
            }
        }

        // Price against the official tariff for these kWh.
        $expected = null;
        if ($tariff !== null && $kwh !== null && $net !== null && $kwh > 0) {
            [$high, $low, $split] = match (true) {
                $f['kwh_high'] !== null && $f['kwh_low'] !== null => [$f['kwh_high'], $f['kwh_low'], 'bill'],
                $metered !== null && $metered['kwh'] > 0 => [$kwh * $metered['kwh_high'] / $metered['kwh'], $kwh * $metered['kwh_low'] / $metered['kwh'], 'metered'],
                default => [$kwh, 0.0, 'all_high'],
            };
            $fraction = $days !== null ? min(1.0, $days / (int) $time->format($from, 't')) : 1.0;
            $expected = $tariff->bill($high, $low, $metered['peak_kw'] ?? 0.0, $metered === null ? 0.0 : ($metered['kvarh'] ?? 0.0) * $kwh / max(1.0, $metered['kwh']), $fraction);
            $ratio = $net / $expected['subtotal'] - 1;
            $params = ['billed' => $net, 'expected' => $expected['subtotal'], 'diff' => round($ratio, 4), 'tariff' => $tariff->summary()['name'], 'split' => $split,
                'demand' => isset($metered['peak_kw'])];
            match (true) {
                abs($ratio) <= 0.05 => $out->add('tariff_match', 'pass', $params),
                abs($ratio) <= 0.15 => $out->add('tariff_diff', 'warn', $params),
                default => $out->add('tariff_diff_large', 'fail', $params),
            };
        }

        $month = self::billMonth($f);
        if ($month !== null && Database::value('SELECT 1 FROM utility_bills WHERE company_id = ? AND period_month = ?', [$companyId, $month]) !== null) {
            $out->add('already_saved', 'info', ['month' => substr($month, 0, 7)]);
        }

        $integrity = $out->integrityFailures();
        $verdict = match (true) {
            $integrity >= 2, $integrity >= 1 && $supplier === null && $f['supplier'] !== null => 'likely_fake',
            $out->has('fail'), $out->has('warn') => 'check',
            default => 'consistent',
        };
        return $out->result('bill', $verdict, [
            'kwh' => $kwh, 'net_eur' => $net, 'price_eur_kwh' => $kwh && $net ? round($net / $kwh, 4) : null, 'period_month' => $month,
            'metered_kwh' => $metered === null ? null : round($metered['kwh'], 1),
            'expected_net_eur' => $expected['subtotal'] ?? null, 'expected_total_eur' => $expected['total'] ?? null,
            'supplier' => $supplier,
        ]);
    }

    /** The month a bill belongs to: the month of its period's midpoint (one bill per month). */
    public static function billMonth(array $f): ?string
    {
        if ($f['period_start'] !== null && $f['period_end'] !== null) {
            $mid = (strtotime($f['period_start']) + strtotime($f['period_end'])) / 2;
            return gmdate('Y-m-01', (int) $mid);
        }
        $date = $f['period_end'] ?? $f['period_start'] ?? $f['issue_date'];
        return $date === null ? null : substr($date, 0, 7) . '-01';
    }

    private static function supplier(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $haystack = ' ' . mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name) . ' ';
        foreach (self::SUPPLIERS as $canonical => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $canonical;
                }
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- meter

    public static function meter(int $companyId, array $f): array
    {
        $out = new CheckList();
        $register = self::register($f['register']);
        $reading = $f['reading_kwh'] ?? ($register === 'T1' ? $f['reading_t1'] : ($register === 'T2' ? $f['reading_t2'] : null));
        if ($reading === null && $f['reading_t1'] !== null && $f['reading_t2'] !== null) {
            $reading = $f['reading_t1'] + $f['reading_t2'];
        }
        $now = Clock::now($companyId);
        $derived = ['reading_kwh' => $reading, 'register' => $register];
        if ($reading === null) {
            $out->add('reading_missing', 'fail');
            return $out->result('meter', 'check', $derived);
        }

        $previous = Database::one(
            'SELECT reading_kwh, UNIX_TIMESTAMP(read_at) AS at FROM meter_readings WHERE company_id = ? AND register_code = ? AND (meter_serial <=> ? OR ? IS NULL)
              ORDER BY read_at DESC LIMIT 1',
            [$companyId, $register, $f['meter_serial'], $f['meter_serial']],
        );
        if ($previous === null) {
            $out->add('first_reading', 'info');
        } else {
            $delta = $reading - (float) $previous['reading_kwh'];
            $from = (int) $previous['at'];
            $derived += ['previous_kwh' => (float) $previous['reading_kwh'], 'since' => gmdate('Y-m-d\TH:i:s\Z', $from), 'delta_kwh' => round($delta, 2)];
            if ($delta < 0) {
                $out->add('reading_backwards', 'fail', ['previous' => (float) $previous['reading_kwh'], 'reading' => $reading]);
            } elseif ($now - $from < 3600) {
                $out->add('too_soon', 'info');
            } else {
                $metered = EnergyQuery::site(EnergyQuery::byMachine($companyId, $from, $now), OverviewService::incomerId($companyId))['kwh'];
                $derived['metered_kwh'] = round($metered, 1);
                if ($metered > 0) {
                    $ratio = $delta / $metered - 1;
                    $params = ['meter' => round($delta, 1), 'metered' => round($metered, 1), 'diff' => round($ratio, 4)];
                    match (true) {
                        abs($ratio) <= 0.03 => $out->add('meter_matches', 'pass', $params),
                        abs($ratio) <= 0.10 => $out->add('meter_differs', 'warn', $params),
                        default => $out->add('meter_differs_large', 'warn', $params),
                    };
                }
            }
        }
        return $out->result('meter', $out->has('fail') || $out->has('warn') ? 'check' : 'ok', $derived);
    }

    /** OBIS codes and labels → total, T1 (high tariff) or T2 (low tariff). */
    public static function register(?string $label): string
    {
        $label = mb_strtolower(trim((string) $label));
        return match (true) {
            (bool) preg_match('/1\.8\.1|\bt1\b|tarif[ae]? 1|e lartë|high/u', $label) => 'T1',
            (bool) preg_match('/1\.8\.2|\bt2\b|tarif[ae]? 2|e ulët|low/u', $label) => 'T2',
            default => 'total',
        };
    }

    // ---------------------------------------------------------------- nameplate

    public static function nameplate(int $companyId, array $f, ?int $machineId): array
    {
        $out = new CheckList();
        // 1 mechanical horsepower = 745.7 W.
        $kw = $f['rated_power_kw'] ?? ($f['rated_power_hp'] !== null ? round($f['rated_power_hp'] * 0.7457, 2) : null);
        if ($f['rated_power_kw'] !== null && $f['rated_power_hp'] !== null && abs($f['rated_power_kw'] - $f['rated_power_hp'] * 0.7457) / $f['rated_power_kw'] > 0.05) {
            $out->add('hp_kw_mismatch', 'warn', ['kw' => $f['rated_power_kw'], 'hp' => $f['rated_power_hp']]);
        }
        $kw === null ? $out->add('rated_power_missing', 'warn') : $out->add('rated_power', 'pass', ['kw' => $kw]);

        // Electrical input from V, I and cos φ; for a motor, rated (shaft) power ÷ input ≈ efficiency.
        $inputKw = null;
        if ($f['voltage_v'] !== null && $f['current_a'] !== null) {
            $phases = (int) ($f['phases'] ?? ($f['voltage_v'] >= 380 ? 3 : 1));
            $kva = ($phases === 3 ? sqrt(3) : 1.0) * $f['voltage_v'] * $f['current_a'] / 1000;
            $inputKw = $f['power_factor'] !== null ? round($kva * $f['power_factor'], 2) : null;
            if ($kw !== null && $inputKw !== null && $inputKw > 0) {
                $ratio = $kw / $inputKw;
                $ratio >= 0.6 && $ratio <= 1.02
                    ? $out->add('nameplate_consistent', 'pass', ['input_kw' => $inputKw, 'efficiency' => round($ratio, 3)])
                    : $out->add('nameplate_inconsistent', 'warn', ['input_kw' => $inputKw, 'kw' => $kw]);
            }
        }

        // Efficiency class (EU Ecodesign, Regulation (EU) 2019/1781: IE3 for most new 0.75–1000 kW motors since 1 July 2021).
        if ($f['efficiency_class'] !== null && preg_match('/IE\s*([0-5])/i', $f['efficiency_class'], $m)) {
            (int) $m[1] >= 3 ? $out->add('ie_class_ok', 'pass', ['class' => 'IE' . $m[1]]) : $out->add('ie_class_low', 'info', ['class' => 'IE' . $m[1]]);
        }

        // Against what EnergyFlow measured on the chosen machine (last 30 days, while running).
        $derived = ['rated_kw' => $kw, 'input_kw' => $inputKw];
        if ($machineId !== null && $kw !== null) {
            $machine = Database::one('SELECT id, code, rated_power_kw, off_threshold_kw FROM machines WHERE company_id = ? AND id = ?', [$companyId, $machineId]);
            if ($machine !== null) {
                $now = Clock::now($companyId);
                $values = array_map('floatval', array_column(Database::all(
                    'SELECT avg_kw FROM readings_15m WHERE machine_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND avg_kw > ? ORDER BY avg_kw',
                    [$machineId, $now - 30 * 86400, max(0.05, (float) $machine['off_threshold_kw'] * 4)],
                ), 'avg_kw'));
                if (count($values) >= 8) {
                    $p95 = $values[(int) floor(0.95 * (count($values) - 1))];
                    $load = $p95 / ($inputKw ?? $kw);
                    $params = ['code' => $machine['code'], 'p95_kw' => round($p95, 2), 'load' => round($load, 3)];
                    $derived += $params;
                    match (true) {
                        $load > 1.1 => $out->add('running_above_rating', 'warn', $params),
                        $load < 0.4 => $out->add('oversized', 'info', $params),
                        default => $out->add('load_ok', 'pass', $params),
                    };
                }
                if ($machine['rated_power_kw'] !== null && abs((float) $machine['rated_power_kw'] - $kw) > 0.01) {
                    $out->add('rated_power_changes', 'info', ['code' => $machine['code'], 'old' => (float) $machine['rated_power_kw'], 'new' => $kw]);
                }
            }
        }
        return $out->result('nameplate', $out->has('fail') || $out->has('warn') ? 'check' : 'ok', $derived);
    }

    // ---------------------------------------------------------------- fuel

    public static function fuel(int $companyId, array $f): array
    {
        $out = new CheckList();
        $fuel = self::fuelType($f['fuel']);
        $today = LocalTime::forCompany($companyId)->date(Clock::now($companyId));
        if ($f['litres'] === null || $f['litres'] <= 0) {
            $out->add('litres_missing', 'fail');
        }
        if ($f['date'] !== null && $f['date'] > $today) {
            $out->add('date_in_future', 'fail', ['date' => $f['date']]);
        }
        if ($f['litres'] !== null && $f['unit_price_eur'] !== null && $f['total_eur'] !== null) {
            abs($f['litres'] * $f['unit_price_eur'] - $f['total_eur']) <= max(0.05, $f['total_eur'] * 0.01)
                ? $out->add('receipt_sum', 'pass')
                : $out->add('receipt_sum_wrong', 'warn', ['litres' => $f['litres'], 'price' => $f['unit_price_eur'], 'total' => $f['total_eur']]);
        }
        $derived = ['fuel' => $fuel];
        $factor = $fuel === null ? null : self::fuelFactor($fuel);
        if ($factor === null) {
            $out->add('fuel_unknown', 'warn', ['fuel' => $f['fuel']]);
        } elseif ($f['litres'] !== null && $f['litres'] > 0) {
            $derived += [
                'co2_kg' => round($f['litres'] * (float) $factor['value'], 2),
                'energy_kwh' => $factor['energy_kwh_per_unit'] === null ? null : round($f['litres'] * (float) $factor['energy_kwh_per_unit'], 1),
                'factor' => ['value' => (float) $factor['value'], 'unit' => $factor['unit'], 'source' => $factor['source_name'], 'url' => $factor['source_url']],
            ];
            $out->add('scope1_factor', 'pass', ['value' => (float) $factor['value'], 'co2_kg' => $derived['co2_kg']]);
        }
        return $out->result('fuel', $out->has('fail') || $out->has('warn') ? 'check' : 'ok', $derived);
    }

    public static function fuelType(?string $label): ?string
    {
        $label = mb_strtolower((string) $label);
        return match (true) {
            $label === '' => null,
            in_array($label, self::FUELS, true) => $label,
            (bool) preg_match('/gas ?oil|gasoil|red diesel|off.?road/u', $label) => 'gas_oil',
            (bool) preg_match('/diesel|dizel|nafta|naftë|eurodiesel/u', $label) => 'diesel',
            (bool) preg_match('/petrol|benzin|gasoline|super|95|98/u', $label) => 'petrol',
            (bool) preg_match('/lpg|autogas|gaz i lëngshëm|gas/u', $label) => 'lpg',
            (bool) preg_match('/heating|kerosene|burning oil|mazut|vaj për ngrohje/u', $label) => 'heating_oil',
            default => null,
        };
    }

    public static function fuelFactor(string $fuel): ?array
    {
        return Database::one(
            "SELECT * FROM emission_factors WHERE scope = 'scope1' AND activity = ? AND company_id IS NULL AND is_default = 1 ORDER BY valid_from DESC LIMIT 1",
            [$fuel],
        );
    }

    // ---------------------------------------------------------------- label

    public static function label(int $companyId, array $f): array
    {
        $out = new CheckList();
        $class = $f['energy_class'] === null ? null : strtoupper(trim($f['energy_class']));
        if ($class !== null) {
            preg_match('/^[A-G](\+{1,3})?$/', $class)
                ? $out->add(str_contains($class, '+') ? 'label_old_scale' : 'label_class', str_contains($class, '+') ? 'info' : 'pass', ['class' => $class])
                : $out->add('label_class_unreadable', 'warn', ['class' => $f['energy_class']]);
        }
        $derived = ['class' => $class, 'kwh' => $f['kwh_per_year'], 'unit' => $f['consumption_unit'] ?? 'kWh/annum'];
        if ($f['kwh_per_year'] === null) {
            $out->add('label_kwh_missing', 'warn');
            return $out->result('label', 'check', $derived);
        }

        // What a kWh costs this company: its measured energy cost over the last 30 days (marginal tariff mix).
        $now = Clock::now($companyId);
        $tariff = TariffBook::forCompany($companyId);
        $site = EnergyQuery::site(EnergyQuery::byMachine($companyId, $now - 30 * 86400, $now, null, $tariff), OverviewService::incomerId($companyId));
        $price = $site['kwh'] > 0 ? EnergyQuery::energyCost($site, $tariff) / $site['kwh'] : ($tariff?->summary()['rate_high'] ?? null);
        $factor = EmissionFactors::gridFactor($companyId);
        if ($price !== null) {
            $derived += [
                'price_eur_kwh' => round($price, 4), 'price_basis' => $site['kwh'] > 0 ? 'measured_30d' : 'tariff_high',
                'eur' => round($f['kwh_per_year'] * $price, 2), 'co2_kg' => round($f['kwh_per_year'] * $factor['value'], 1),
            ];
            $out->add('label_cost', 'pass', ['eur' => $derived['eur'], 'co2_kg' => $derived['co2_kg'], 'unit' => $derived['unit']]);
        }
        return $out->result('label', $out->has('warn') ? 'check' : 'ok', $derived);
    }
}
