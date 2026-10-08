<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Demo;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\Env;
use EnergyFlow\Services\Auth\AuthService;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Control\PolicyEngine;
use EnergyFlow\Services\Jobs\AnalyticsPipeline;
use EnergyFlow\Services\Simulation\SimContext;
use EnergyFlow\Services\Simulation\Simulator;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Services\Telemetry\IngestService;
use EnergyFlow\Services\Telemetry\RollupService;
use EnergyFlow\Services\Telemetry\DeviceSecrets;
use EnergyFlow\Services\Weather\WeatherService;

/**
 * Builds the demo company "Ylli Plast Sh.p.k." (fictional) with a 75-day energy
 * story that ends at a chosen virtual "now" (the anchor):
 *
 *   day −75 … −46  baseline: hall lighting often left on at night, compressor left on ~45 % of nights
 *   day −45        lighting auto-off policy accepted → visible before/after
 *   day −40 … 0    injection moulder IMM-02 slowly loses efficiency (worn hydraulics)
 *   live           compressor still left on → the on-stage "Turn Off" moment
 *
 * Deterministic: the same seed and anchor always produce the same data.
 */
final class DemoSeeder
{
    public const COMPANY_NAME = 'Ylli Plast Sh.p.k.';
    public const OWNER_EMAIL = 'owner@ylli-plast.demo';
    public const GUEST_EMAIL = 'guest@ylli-plast.demo';
    private const SEED = 4242;
    private const TIMEZONE = 'Europe/Belgrade';

    /**
     * @param callable(string): void $log
     * @return array{company_id: int, owner_password: ?string}
     */
    public static function reset(int $anchor, int $historyDays = 75, ?callable $log = null): array
    {
        $log ??= static fn (string $line) => null;
        $anchor -= $anchor % 10;
        $time = new LocalTime(self::TIMEZONE);

        foreach (Database::all('SELECT id FROM companies WHERE is_demo = 1') as $existing) {
            CompanyPurger::purge((int) $existing['id']);
        }
        $log('Removed previous demo company.');

        $ids = Database::transaction(static fn (): array => self::createCompany($anchor, $historyDays, $time));
        $companyId = $ids['company_id'];
        $log("Created {$ids['machines']} machines and {$ids['devices']} devices.");

        $historyStart = $time->startOfDay($anchor - $historyDays * 86400);
        $site = Database::one('SELECT id, latitude, longitude FROM sites WHERE company_id = ?', [$companyId]);
        $source = WeatherService::ensure((int) $site['id'], (float) $site['latitude'], (float) $site['longitude'], $historyStart, $anchor + 2 * 86400);
        $log("Weather loaded ({$source}).");

        // The accepted lighting policy acted every night it found the lights left on:
        // those Turn Off commands are part of the history the simulator then follows.
        Clock::forget($companyId);
        $planned = PolicyEngine::planSimulated($companyId, $historyStart, $anchor + 1, $anchor);
        $log("Planned {$planned} automatic Turn Off commands from the accepted policy.");

        // History as 15-minute buckets, then the last hour as live 10-second readings.
        $bucketsEnd = intdiv($anchor - 3600, 900) * 900;
        $context = SimContext::load($companyId, $historyStart, $anchor + 3600);
        $tariff = TariffBook::forCompany($companyId);
        $simulator = new Simulator($context, $tariff);
        $rows = $simulator->writeBuckets($historyStart, $bucketsEnd);
        $log("Generated {$rows} fifteen-minute buckets.");

        $raw = [];
        for ($ts = $bucketsEnd; $ts <= $anchor; $ts += 10) {
            array_push($raw, ...$simulator->readingsAt($ts));
        }
        IngestService::store($companyId, $raw, 'simulator');
        Database::run(
            "INSERT INTO job_runs (job_key, company_id, watermark, last_status) VALUES ('rollup', ?, FROM_UNIXTIME(?), 'ok')",
            [$companyId, $bucketsEnd],
        );
        RollupService::run($companyId, $anchor);
        Database::run('UPDATE sim_state SET last_generated_at = FROM_UNIXTIME(?) WHERE company_id = ?', [$anchor, $companyId]);
        Database::run("UPDATE devices SET status = 'online', last_seen_at = FROM_UNIXTIME(?) WHERE company_id = ? AND is_simulated = 1", [$anchor, $companyId]);
        $log('Generated ' . count($raw) . ' live readings for the last hour.');

        $insights = AnalyticsPipeline::run($companyId, $anchor);
        $log("Detected {$insights['waste_episodes']} waste episodes and {$insights['drift']} efficiency drift(s).");

        return ['company_id' => $companyId, 'owner_password' => $ids['owner_password']];
    }

    private static function createCompany(int $anchor, int $historyDays, LocalTime $time): array
    {
        $factorId = Database::value("SELECT id FROM emission_factors WHERE company_id IS NULL AND is_default = 1 AND activity = 'grid_electricity' AND region = 'XK' ORDER BY valid_from DESC LIMIT 1");
        $companyId = Database::insert(
            "INSERT INTO companies (name, legal_form, nace_code, employees, annual_turnover_eur, country, city, timezone, locale, emission_factor_id, is_demo)
             VALUES (?, 'Sh.p.k.', '22.22', 28, 1450000, 'XK', 'Prishtinë', ?, 'sq', ?, 1)",
            [self::COMPANY_NAME, self::TIMEZONE, $factorId],
        );

        // Users: an owner for presenting, and a read-only guest for "Explore the demo".
        $ownerPassword = Env::get('DEMO_OWNER_PASSWORD');
        $generated = $ownerPassword === null ? bin2hex(random_bytes(8)) : null;
        $ownerId = Database::insert(
            "INSERT INTO users (company_id, email, password_hash, full_name, role, locale) VALUES (?, ?, ?, 'Arta Krasniqi', 'owner', 'sq')",
            [$companyId, self::OWNER_EMAIL, AuthService::hash($ownerPassword ?? $generated)],
        );
        Database::run(
            "INSERT INTO users (company_id, email, password_hash, full_name, role, locale) VALUES (?, ?, ?, 'Demo guest', 'viewer', 'sq')",
            [$companyId, self::GUEST_EMAIL, AuthService::hash(bin2hex(random_bytes(16)))],
        );

        $siteId = Database::insert(
            "INSERT INTO sites (company_id, name, address, city, latitude, longitude, floor_area_m2)
             VALUES (?, 'Fabrika · Zona Industriale', 'Zona Industriale', 'Prishtinë', 42.66290, 21.16550, 2400)",
            [$companyId],
        );
        $departments = [];
        foreach (['production' => 'Prodhimi', 'utilities' => 'Shërbimet teknike', 'office' => 'Zyra'] as $key => $name) {
            $departments[$key] = Database::insert('INSERT INTO departments (company_id, name) VALUES (?, ?)', [$companyId, $name]);
        }

        // Working schedules (local time).
        $production = Database::insert("INSERT INTO schedules (company_id, name) VALUES (?, 'Prodhimi · 2 ndërrime')", [$companyId]);
        foreach ([1, 2, 3, 4, 5] as $day) {
            Database::run("INSERT INTO schedule_rules (schedule_id, day_of_week, start_time, end_time) VALUES (?, ?, '07:00', '21:00')", [$production, $day]);
        }
        Database::run("INSERT INTO schedule_rules (schedule_id, day_of_week, start_time, end_time) VALUES (?, 6, '07:00', '13:00')", [$production]);
        $office = Database::insert("INSERT INTO schedules (company_id, name) VALUES (?, 'Zyra')", [$companyId]);
        foreach ([1, 2, 3, 4, 5] as $day) {
            Database::run("INSERT INTO schedule_rules (schedule_id, day_of_week, start_time, end_time) VALUES (?, ?, '08:00', '16:00')", [$office, $day]);
        }

        // Tariff: the company's own copy of the official KESCO Category I template.
        $template = Database::one("SELECT * FROM tariff_plans WHERE company_id IS NULL AND category = 'commercial_cat_1' ORDER BY id LIMIT 1");
        $planId = self::copyTariff((int) $template['id'], $companyId);
        Database::run('UPDATE companies SET tariff_plan_id = ? WHERE id = ?', [$planId, $companyId]);

        // Machines: [code, name, type, phases, rated kW, schedule, department, criticality, control,
        //            off threshold kW, idle threshold kW, profile overrides]
        // The heat pump's off threshold sits above its frost-protection setback (≤ 0.7 kW),
        // so the intended night setback is not reported as "running after hours".
        $machines = [
            ['IMM-01', 'Injection moulder 1 · 250 t', 'injection_moulding', 3, 22.0, $production, 'production', 'normal', 'approve', 0.05, 6.0, []],
            ['IMM-02', 'Injection moulder 2 · 180 t', 'injection_moulding', 3, 18.5, $production, 'production', 'normal', 'approve', 0.05, 5.5, ['run_kw' => 11.5, 'standby_kw' => 3.2, 'cycle_s' => 35]],
            ['CMP-01', 'Screw air compressor · 11 kW', 'compressor', 3, 11.0, $production, 'utilities', 'normal', 'approve', 0.05, 6.0, []],
            ['CHL-01', 'Mould chiller · 7.5 kW', 'chiller', 3, 7.5, $production, 'utilities', 'normal', 'notify', 0.05, 1.5, []],
            ['HVAC-01', 'Office & warehouse heat pump', 'hvac', 3, 6.0, $office, 'office', 'normal', 'notify', 0.8, 0.8, []],
            ['PMP-01', 'Process water pump', 'pump', 3, 3.0, $production, 'utilities', 'flexible', 'approve', 0.05, 1.0, []],
            ['LGT-01', 'Production hall lighting', 'lighting', 1, 3.2, $production, 'production', 'normal', 'auto', 0.05, 0.5, []],
            ['OFF-01', 'Office & IT', 'office', 1, 2.0, $office, 'office', 'critical', 'monitor', 0.05, 0.8, []],
        ];
        $machineIds = [];
        foreach ($machines as [$code, $name, $type, $phases, $rated, $schedule, $dept, $criticality, $control, $off, $idle, $profile]) {
            $machineIds[$code] = Database::insert(
                "INSERT INTO machines (company_id, site_id, department_id, kind, code, name, type_code, rated_power_kw, phases, schedule_id,
                                       criticality, control_mode, off_threshold_kw, idle_threshold_kw, sim_profile)
                 VALUES (?, ?, ?, 'machine', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$companyId, $siteId, $departments[$dept], $code, $name, $type, $rated, $phases, $schedule, $criticality, $control, $off, $idle,
                 json_encode(['model' => $type] + $profile)],
            );
        }
        $machineIds['INC-00'] = Database::insert(
            "INSERT INTO machines (company_id, site_id, kind, code, name, type_code, rated_power_kw, phases, criticality, control_mode, off_threshold_kw, idle_threshold_kw, sim_profile)
             VALUES (?, ?, 'incomer', 'INC-00', 'Main incomer', 'incomer', 80, 3, 'critical', 'monitor', 0.05, 1.0, ?)",
            [$companyId, $siteId, json_encode(['model' => 'incomer'])],
        );

        // Simulated EnergyFlow nodes and their CT channels.
        $installed = $anchor - $historyDays * 86400;
        $devices = [
            ['EF-000', 'EF-Node-3P', [[1, 'INC-00', 'three_ct', 0]]],
            ['EF-001', 'EF-Node-3P', [[1, 'IMM-01', 'three_ct', 1], [2, 'IMM-02', 'three_ct', 1], [3, 'CMP-01', 'three_ct', 1], [4, 'CHL-01', 'three_ct', 0]]],
            ['EF-002', 'EF-Node-3P', [[1, 'HVAC-01', 'three_ct', 0], [2, 'PMP-01', 'three_ct', 1]]],
            ['EF-003', 'EF-Node-1P', [[1, 'LGT-01', 'single_phase', 1], [2, 'OFF-01', 'single_phase', 0]]],
        ];
        foreach ($devices as [$serial, $model, $channels]) {
            $deviceId = Database::insert(
                "INSERT INTO devices (company_id, site_id, serial, model, firmware_version, key_hash, key_version, is_simulated, status, installed_at)
                 VALUES (?, ?, ?, ?, 'sim-1.0', ?, 1, 1, 'online', FROM_UNIXTIME(?))",
                [$companyId, $siteId, $serial, $model, hash('sha256', $serial), $installed],
            );
            foreach ($channels as [$channel, $code, $mode, $relay]) {
                Database::run(
                    'INSERT INTO device_channels (device_id, channel_no, machine_id, measurement_mode, ct_rating_a, has_relay, valid_from)
                     VALUES (?, ?, ?, ?, 100, ?, FROM_UNIXTIME(?))',
                    [$deviceId, $channel, $machineIds[$code], $mode, $relay, $installed],
                );
            }
        }

        // Optional real hardware on stage (tools/hw-bridge + a metering smart plug), only when the
        // team has one: DEMO_HARDWARE_SERIAL=EF-101. It starts as "not connected yet" and gets data
        // only from the device itself — the simulator never generates readings for it. The device
        // secret is derived from the serial, so it survives every reset unchanged.
        $hardwareSerial = Env::get('DEMO_HARDWARE_SERIAL');
        if ($hardwareSerial !== null && preg_match('/^[A-Z0-9\-]{3,30}$/', $hardwareSerial)) {
            $lampId = Database::insert(
                "INSERT INTO machines (company_id, site_id, department_id, kind, code, name, type_code, rated_power_kw, phases, schedule_id,
                                       criticality, control_mode, off_threshold_kw, idle_threshold_kw)
                 VALUES (?, ?, ?, 'machine', 'LIVE-01', 'Workshop lamp (live hardware)', 'lighting', 0.1, 1, ?, 'normal', 'approve', 0.005, 0.005)",
                [$companyId, $siteId, $departments['production'], $production],
            );
            $bridgeId = Database::insert(
                "INSERT INTO devices (company_id, site_id, serial, model, key_hash, key_version, is_simulated, status, installed_at)
                 VALUES (?, ?, ?, 'EF-Bridge', ?, 1, 0, 'provisioned', FROM_UNIXTIME(?))",
                [$companyId, $siteId, $hardwareSerial, hash('sha256', DeviceSecrets::secretFor($hardwareSerial, 1)), $anchor],
            );
            Database::run(
                "INSERT INTO device_channels (device_id, channel_no, machine_id, measurement_mode, has_relay, valid_from)
                 VALUES (?, 1, ?, 'single_phase', 1, FROM_UNIXTIME(?))",
                [$bridgeId, $lampId, $anchor],
            );
        }

        // The story.
        $start = $time->startOfDay($installed);
        $scenario = static function (string $kind, ?string $code, array $params, int $from, ?int $to = null) use ($companyId, $machineIds): void {
            Database::run(
                'INSERT INTO sim_scenarios (company_id, scenario, machine_id, params, starts_at, ends_at) VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), ?)',
                [$companyId, $kind, $code === null ? null : $machineIds[$code], json_encode($params), $from, $to === null ? null : gmdate('Y-m-d H:i:s', $to)],
            );
        };
        $scenario('left_on', 'CMP-01', ['weekday_p' => 0.45, 'weekend_p' => 0.15], $start);
        $scenario('left_on', 'LGT-01', ['weekday_p' => 0.7, 'weekend_p' => 0.35], $start);
        $scenario('degradation', 'IMM-02', ['rate_per_day' => 0.0035, 'max' => 0.2], $anchor - 40 * 86400);
        // The live moment: tonight the compressor is (certainly) left running after
        // the shift ends, until the morning shift — unless someone switches it off.
        $tonight = $time->startOfDay($anchor);
        $scenario('left_on', 'CMP-01', ['weekday_p' => 1.0, 'weekend_p' => 1.0], $tonight + 12 * 3600, $tonight + 31 * 3600);

        Database::run(
            "INSERT INTO automation_policies (company_id, machine_id, type, params, mode, is_active, effective_from, created_by, created_at)
             VALUES (?, ?, 'auto_off_after_schedule', ?, 'auto', 1, FROM_UNIXTIME(?), ?, FROM_UNIXTIME(?))",
            [$companyId, $machineIds['LGT-01'], json_encode(['grace_min' => 15]), $anchor - 45 * 86400, $ownerId, $anchor - 45 * 86400],
        );

        Database::run(
            'INSERT INTO sim_state (company_id, seed, clock_offset_s, speed, last_generated_at, story_anchor) VALUES (?, ?, ?, 1.00, FROM_UNIXTIME(?), FROM_UNIXTIME(?))',
            [$companyId, self::SEED, $anchor - Clock::realNow(), $anchor, $anchor],
        );

        return ['company_id' => $companyId, 'machines' => count($machineIds), 'devices' => count($devices), 'owner_password' => $generated];
    }

    private static function copyTariff(int $templateId, int $companyId): int
    {
        Database::run(
            'INSERT INTO tariff_plans (company_id, name, supplier, mode, voltage_level, category, fixed_monthly_eur, demand_charge_eur_kw_month,
                                       reactive_charge_eur_kvarh, reactive_pf_threshold, vat_rate, valid_from, valid_to, source_note, is_active)
             SELECT ?, name, supplier, mode, voltage_level, category, fixed_monthly_eur, demand_charge_eur_kw_month,
                    reactive_charge_eur_kvarh, reactive_pf_threshold, vat_rate, valid_from, valid_to, source_note, 1
               FROM tariff_plans WHERE id = ?',
            [$companyId, $templateId],
        );
        $planId = (int) Database::pdo()->lastInsertId();
        Database::run(
            'INSERT INTO tariff_windows (tariff_plan_id, season_start_mmdd, season_end_mmdd, period, start_time, end_time)
             SELECT ?, season_start_mmdd, season_end_mmdd, period, start_time, end_time FROM tariff_windows WHERE tariff_plan_id = ?',
            [$planId, $templateId],
        );
        Database::run(
            'INSERT INTO tariff_rates (tariff_plan_id, period, block_from_kwh, block_to_kwh, rate_eur_kwh)
             SELECT ?, period, block_from_kwh, block_to_kwh, rate_eur_kwh FROM tariff_rates WHERE tariff_plan_id = ?',
            [$planId, $templateId],
        );
        return $planId;
    }

    /** Device secret for the bridge/firmware of a demo or real device (shown once at provisioning). */
    public static function deviceSecret(string $serial, int $version = 1): string
    {
        return DeviceSecrets::secretFor($serial, $version);
    }
}
