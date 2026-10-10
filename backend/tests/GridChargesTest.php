<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Detection\MonthlyMeter;
use EnergyFlow\Services\Detection\PeakDetector;
use EnergyFlow\Services\Detection\PowerFactorDetector;
use EnergyFlow\Services\Tariff\TariffBook;

// A real (non-demo) plant on the 0.4 kV Category I tariff, with hand-made 15-minute buckets on
// 10–12 August 2026: a motor at cos φ 0.78 running all the time, a pump marked as a flexible load
// (cos φ 0.85) running one quarter-hour in four, and 1 kW of unmonitored load at cos φ 1. On
// 11 August at 10:00 the motor surges to 13 kW while the pump runs: that quarter-hour is the
// month's peak. The clock stands in September, so August is a closed billing month.
$gridOwner = registeredOwner('grid-owner@example.com', 'Grid SME');
$gridCompany = (int) Database::value("SELECT company_id FROM users WHERE email = 'grid-owner@example.com'");
$gridTime = new LocalTime('Europe/Belgrade');
$catOne = (int) Database::value("SELECT id FROM tariff_plans WHERE company_id IS NULL AND category = 'commercial_cat_1'");
$catTwo = (int) Database::value("SELECT id FROM tariff_plans WHERE company_id IS NULL AND category = 'commercial_cat_2'");
Database::run('UPDATE companies SET tariff_plan_id = ? WHERE id = ?', [$catOne, $gridCompany]);
$gridSite = Database::insert("INSERT INTO sites (company_id, name) VALUES (?, 'Plant')", [$gridCompany]);
$gridMachine = static fn (string $code, string $kind, string $type, string $criticality): int => Database::insert(
    "INSERT INTO machines (company_id, site_id, kind, code, name, type_code, criticality, off_threshold_kw, idle_threshold_kw)
     VALUES (?, ?, ?, ?, ?, ?, ?, 0.05, 1.0)",
    [$gridCompany, $gridSite, $kind, $code, "Machine {$code}", $type, $criticality],
);
$motor = $gridMachine('MOT-1', 'machine', 'compressor', 'normal');
$pump = $gridMachine('PMP-1', 'machine', 'pump', 'flexible');
$incomer = $gridMachine('INC-1', 'incomer', 'incomer', 'critical');
$kvarPerKw = static fn (float $cos): float => tan(acos($cos));
$put = static function (int $machineId, int $t, float $kw, float $kvar) use ($gridCompany): void {
    Database::run(
        "INSERT INTO readings_15m (machine_id, bucket_start, company_id, kwh, kvarh, avg_kw, max_kw, min_kw, running_s, idle_s, off_s, cycle_count, tariff_period, is_scheduled, source)
         VALUES (?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, 'high', 1, 'rollup')",
        [$machineId, $t, $gridCompany, $kw * 0.25, $kvar * 0.25, $kw, $kw, $kw, $kw > 0 ? 900 : 0, $kw > 0 ? 0 : 900],
    );
};
$gridStart = $gridTime->at('2026-08-10');
$gridPeakAt = $gridTime->at('2026-08-11', 10 * 60);
for ($t = $gridStart; $t < $gridStart + 3 * 86400; $t += 900) {
    $motorKw = $t === $gridPeakAt ? 13.0 : 10.0;
    $pumpKw = intdiv($t - $gridStart, 900) % 4 === 1 || $t === $gridPeakAt ? 4.0 : 0.0;
    $put($motor, $t, $motorKw, $motorKw * $kvarPerKw(0.78));
    $put($pump, $t, $pumpKw, $pumpKw * $kvarPerKw(0.85));
    $put($incomer, $t, $motorKw + $pumpKw + 1.0, $motorKw * $kvarPerKw(0.78) + $pumpKw * $kvarPerKw(0.85));
}
Clock::freeze($gridTime->at('2026-09-10', 12 * 60));
$gridNow = Clock::now($gridCompany);
$gridIso = static fn (int $ts): string => gmdate('Y-m-d\TH:i:s\Z', $ts);
$gridAlert = static fn (string $type): ?array => Database::one('SELECT * FROM alerts WHERE company_id = ? AND type = ?', [$gridCompany, $type]);

test('LOW_PF: kVArh above cos φ 0.95 priced like the bill, with the machines behind it and the capacitor size', function () use ($gridCompany, $gridNow, $gridAlert, $incomer, $gridTime): void {
    assertSame(1, PowerFactorDetector::run($gridCompany, $gridNow));
    $site = Database::one('SELECT SUM(kwh) AS kwh, SUM(kvarh) AS kvarh FROM readings_15m WHERE machine_id = ?', [$incomer]);
    $kwh = (float) $site['kwh'];
    $kvarh = (float) $site['kvarh'];
    $excess = $kvarh - $kwh * tan(acos(0.95));
    assertTrue($excess > 0, 'the plant draws more than the allowance');

    $alert = $gridAlert('LOW_PF');
    assertSame('low_pf:2026-08', $alert['dedupe_key']);
    assertSame('resolved', $alert['status'], 'a closed billing month');
    assertSame($gridTime->at('2026-09-01'), strtotime($alert['resolved_at'] . ' UTC'));
    assertSame('warning', $alert['severity']);
    $p = json_decode($alert['params'], true);
    assertSame(round($kwh / sqrt($kwh ** 2 + $kvarh ** 2), 3), $p['cos_phi']);
    assertSame(0.95, $p['threshold']);
    assertSame(round($excess, 0), (float) $p['excess_kvarh']);
    assertSame(round($excess * 0.0087, 2), (float) $p['eur'], 'ERO V_2703_2025: 0.87 €c/kVArh');
    assertSame(null, $p['projected_eur'], 'no projection for a closed month');
    assertSame('MOT-1', $p['worst_code']);

    $e = json_decode($alert['evidence'], true);
    assertSame(['MOT-1', 'PMP-1'], array_column($e['machines'], 'code'), 'ranked by reactive energy');
    assertSame([true, false], array_column($e['machines'], 'poor'), 'cos φ 0.78 is poor, 0.85 is not');
    assertSame(0.0, (float) $e['unmonitored_kvarh'], 'the unmonitored load is resistive');
    $c = $e['compensation'];
    $expected = $c['working_kw'] * (tan(acos($c['working_cos_phi'])) - tan(acos(0.95)));
    assertTrue(abs($c['kvar'] - $expected) < 0.2 && $c['kvar'] > 3, "Qc = P · (tan φ − tan φ_target): {$c['kvar']} kvar");
    assertTrue(str_contains($e['source'], 'V_2703_2025'));

    assertSame(0, PowerFactorDetector::run($gridCompany, $gridNow), 'nothing new since the last run');
    assertSame(1, (int) Database::value("SELECT COUNT(*) FROM alerts WHERE company_id = ? AND type = 'LOW_PF'", [$gridCompany]));
});

test('LOW_PF needs a day of data, and without a reactive charge only a cos φ below 0.80 counts', function () use ($gridCompany, $gridNow, $gridTime, $catOne, $catTwo): void {
    $august = MonthlyMeter::load($gridCompany, $gridTime->at('2026-08-01'), $gridNow, $gridTime);
    assertTrue(PowerFactorDetector::assess($august, TariffBook::forPlan($catOne)) !== null);
    $categoryTwo = PowerFactorDetector::assess($august, TariffBook::forPlan($catTwo));
    assertSame(null, $categoryTwo, 'Category II has no reactive charge and this plant is above 0.80');
    $september = MonthlyMeter::load($gridCompany, $gridTime->at('2026-09-01'), $gridNow, $gridTime);
    assertSame(null, PowerFactorDetector::assess($september, TariffBook::forPlan($catOne)), 'no data this month');
});

test('PEAK_COINCIDENCE: the quarter-hour that sets engaged power, and what the flexible pump added to it', function () use ($gridCompany, $gridNow, $gridAlert, $gridPeakAt, $gridIso, $pump): void {
    assertSame(1, PeakDetector::run($gridCompany, $gridNow));
    $alert = $gridAlert('PEAK_COINCIDENCE');
    assertSame('peak:2026-08', $alert['dedupe_key']);
    assertSame('pattern', $alert['method']);
    assertSame('warning', $alert['severity']);
    assertSame($pump, (int) $alert['machine_id'], 'linked to the flexible load to move');
    $p = json_decode($alert['params'], true);
    assertSame(18.0, (float) $p['peak_kw'], '13 kW motor + 4 kW pump + 1 kW unmonitored');
    assertSame($gridIso($gridPeakAt), $p['peak_at']);
    assertSame(14.0, (float) $p['achievable_kw'], 'the same quarter-hour without the pump');
    assertSame(4.0, (float) $p['avoidable_kw']);
    assertSame(15.48, (float) $p['eur'], '4 kW × 3.87 €/kW (ERO V_2703_2025)');
    assertSame(69.66, (float) $p['billed_eur'], 'the whole peak: 18 kW × 3.87 €/kW');
    assertSame('PMP-1', $p['flexible']);

    $e = json_decode($alert['evidence'], true);
    $loads = array_column($e['loads'], null, 'code');
    assertSame([13.0, false, false], [(float) $loads['MOT-1']['kw'], $loads['MOT-1']['flexible'], $loads['MOT-1']['started']]);
    assertSame([4.0, true, true], [(float) $loads['PMP-1']['kw'], $loads['PMP-1']['flexible'], $loads['PMP-1']['started']], 'the pump had just started');
    assertSame(1.0, (float) $e['unmonitored_kw']);
    assertSame($gridIso($gridPeakAt), $e['top'][0]['t']);
    assertTrue(str_contains($e['source'], 'maximeter'));
    assertSame(0, PeakDetector::run($gridCompany, $gridNow), 'nothing new since the last run');
});

test('PEAK_COINCIDENCE needs a flexible load in the peak and an engaged-power charge', function () use ($gridCompany, $gridNow, $gridTime, $pump): void {
    $august = static fn (): MonthlyMeter => MonthlyMeter::load($gridCompany, $gridTime->at('2026-08-01'), $gridNow, $gridTime);
    assertSame(null, PeakDetector::assess($august(), 0.0), 'Category II bills no engaged power');
    Database::run("UPDATE machines SET criticality = 'normal' WHERE id = ?", [$pump]);
    try {
        assertSame(null, PeakDetector::assess($august(), 3.87), 'nothing can be moved');
    } finally {
        Database::run("UPDATE machines SET criticality = 'flexible' WHERE id = ?", [$pump]);
    }
});

test('both alerts reach the API with their evidence, and only for this company', function () use ($gridOwner): void {
    $alerts = $gridOwner->get('/alerts?status=all')['body']['data'];
    $byType = array_column($alerts, null, 'type');
    assertTrue(isset($byType['LOW_PF'], $byType['PEAK_COINCIDENCE']));
    assertSame(null, $byType['LOW_PF']['machine'], 'a site-level alert');
    $detail = $gridOwner->get('/alerts/' . $byType['PEAK_COINCIDENCE']['id'])['body']['data'];
    assertSame(3.87, (float) $detail['evidence']['charge']['rate_eur_kw_month']);
    assertSame('PMP-1', $detail['machine']['code']);
    $other = registeredOwner('grid-other@example.com', 'Other SME');
    assertSame(404, $other->get('/alerts/' . $byType['LOW_PF']['id'])['status']);
    Clock::freeze(null);
});

test('the demo plant (cos φ about 0.85 on Category I) gets a LOW_PF alert from its simulated data', function (): void {
    $demo = Database::one("SELECT a.params FROM alerts a JOIN companies c ON c.id = a.company_id WHERE c.is_demo = 1 AND a.type = 'LOW_PF' ORDER BY a.opened_at DESC LIMIT 1");
    assertTrue($demo !== null, 'the seeding pipeline ran the detector');
    $cos = json_decode($demo['params'], true)['cos_phi'];
    assertTrue($cos > 0.80 && $cos < 0.90, "simulated cos φ {$cos}");
});
