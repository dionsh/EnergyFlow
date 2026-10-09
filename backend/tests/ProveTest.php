<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Assistant\Grounding;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Demo\DemoSeeder;

// The full 75-day story: lighting auto-off accepted on day −45, so the before/after
// has a 28-day baseline and weeks of reporting days.
Clock::freeze(time());
$proveAnchor = DemoClock::sceneAnchor('weekday_evening');
Clock::freeze($proveAnchor);
$proveDemo = DemoSeeder::reset($proveAnchor, 75)['company_id'];
$proveOwner = new Client('10.9.7.1');
assertSame(200, $proveOwner->post('/auth/login', ['email' => DemoSeeder::OWNER_EMAIL, 'password' => 'test-owner-password'])['status']);
$proveGuest = new Client('10.9.7.2');
$proveGuest->post('/auth/demo');

test('M&V: the lighting policy saves energy against an adjusted baseline, verified at 90 % confidence', function () use ($proveOwner): void {
    $summary = $proveOwner->get('/impact/summary');
    assertSame(200, $summary['status'], json_encode($summary['body']));
    $data = $summary['body']['data'];
    $lighting = array_values(array_filter($data['interventions'], static fn (array $i): bool => $i['machine']['code'] === 'LGT-01'));
    assertSame(1, count($lighting), 'one intervention for the lighting policy');
    $iv = $lighting[0];
    assertSame('policy', $iv['kind']);
    assertTrue($iv['reporting']['days'] >= 30, 'reporting since day −45');
    assertTrue($iv['savings']['kwh'] > 0 && $iv['savings']['kwh'] - $iv['savings']['ci90_kwh'] > 0, json_encode($iv['savings']));
    assertSame(true, $iv['verified']);
    assertTrue($data['verified']['kwh'] >= $iv['savings']['kwh'] - 0.1, 'verified totals include it');
    assertTrue($data['adjusted']['savings']['kwh'] > 0);

    $detail = $proveOwner->get("/impact/interventions/{$iv['id']}")['body']['data'];
    assertTrue(count($detail['daily']) >= 30, 'daily before/after series for the chart');
});

test('carbon: Scope 2 is metered kWh × the Kosovo grid factor; Scope 1 stays missing until declared', function () use ($proveOwner, $proveGuest): void {
    $carbon = $proveOwner->get('/carbon/summary?period=30d')['body']['data'];
    assertTrue($carbon['electricity_kwh'] > 0);
    assertTrue(abs($carbon['scope2_location_kg'] - $carbon['electricity_kwh'] * $carbon['factor']['value']) < 1.0, 'kWh × factor');
    assertSame('missing', $carbon['scope1']['status']);
    assertSame(false, $carbon['scope2_market']['available'], 'no residual mix published for Kosovo: never invented');
    assertSame(true, $carbon['simulated_data']);

    $statuses = array_column($proveOwner->get('/esg/vsme-b3')['body']['data']['rows'], 'status', 'key');
    assertSame('auto', $statuses['scope2_location']);
    assertSame('missing', $statuses['scope1']);
    assertSame('not_available', $statuses['scope2_market']);

    assertSame(403, $proveGuest->call('PUT', '/esg/answers/no_fuel_combustion', ['value' => true])['status'], 'viewers cannot declare');
    assertSame(404, $proveOwner->call('PUT', '/esg/answers/anything', ['value' => true])['status']);
    $answered = $proveOwner->call('PUT', '/esg/answers/no_fuel_combustion', ['value' => true]);
    assertSame(200, $answered['status'], json_encode($answered['body']));
    $fuels = array_values(array_filter($answered['body']['data']['items'], static fn (array $i): bool => $i['key'] === 'fuels'))[0];
    assertSame(true, $fuels['done']);
    assertSame('declared_none', $proveOwner->get('/carbon/summary?period=30d')['body']['data']['scope1']['status']);
    $statuses = array_column($proveOwner->get('/esg/vsme-b3')['body']['data']['rows'], 'status', 'key');
    assertSame('manual', $statuses['scope1']);
});

test('grounding: numbers must come from the data, in either language, allowing rounding and unit changes', function (): void {
    $facts = ['energy_kwh' => 4215.3, 'cost_eur' => 512.4, 'waste' => ['share' => 0.0731], 'machine' => 'Injection moulder · 250 t'];
    assertSame(true, Grounding::check('The plant used 4,215 kWh, costing €512.40; 7.3% of it was waste.', $facts)['grounded']);
    assertSame(true, Grounding::check('Fabrika konsumoi 4 215 kWh, me kosto 512,40 €; 7,3 % ishte humbje.', $facts)['grounded']);
    assertSame(true, Grounding::check('About 4.2 MWh in total; the 250 t moulder led.', $facts)['grounded']);
    $made = Grounding::check('Savings reached 9,999 kWh in 2026.', $facts);
    assertSame(false, $made['grounded']);
    assertSame(['9,999'], $made['unmatched'], 'years are not checked, invented figures are');
});

test('report: frozen snapshot, template narrative without AI, edit, finalise, then locked', function () use ($proveOwner, $proveGuest, $proveDemo): void {
    $month = LocalTime::forCompany($proveDemo)->format(Clock::now($proveDemo) - 31 * 86400, 'Y-m');
    assertSame(403, $proveGuest->post('/reports', ['type' => 'monthly', 'period' => $month, 'language' => 'en'])['status']);
    foreach (['2026-13', '2026-00', '2026-10-05'] as $bad) {
        assertSame(422, $proveOwner->post('/reports', ['type' => 'monthly', 'period' => $bad, 'language' => 'en'])['status'], "month {$bad}");
    }
    assertSame(422, $proveOwner->post('/reports', ['type' => 'yearly', 'period' => $month, 'language' => 'en'])['status']);

    $created = $proveOwner->post('/reports', ['type' => 'monthly', 'period' => $month, 'language' => 'sq']);
    assertSame(201, $created['status'], json_encode($created['body']));
    $report = $created['body']['data'];
    $s = $report['snapshot'];
    assertSame('draft', $report['status']);
    assertSame('template', $report['narrative_source'], 'GROQ_API_KEY is unset in tests');
    assertSame(false, $s['period']['partial'], 'a closed month');
    assertTrue(abs($s['carbon']['scope2_location_kg'] - $s['energy']['kwh'] * $s['factor']['value']) < 1.0);
    assertTrue(count($s['machines']) >= 8 && $s['machines'][0]['kwh'] >= $s['machines'][1]['kwh'], 'machines by consumption');
    assertTrue($s['company']['demo'] && $s['data_quality']['simulated_devices'] > 0, 'simulated data is labelled');
    assertTrue(str_contains($report['narrative']['summary'], 'Ylli Plast') && str_contains($report['narrative']['summary'], 'kWh'));
    $check = Grounding::check(implode(' ', $report['narrative']), $s);
    assertSame(true, $check['grounded'], 'the template passes the same check as the AI: ' . implode(', ', $check['unmatched']));

    $edited = $proveOwner->patch("/reports/{$report['id']}/narrative", ['summary' => 'Edited summary.']);
    assertSame('edited', $edited['body']['data']['narrative_source']);
    assertSame('Edited summary.', $edited['body']['data']['narrative']['summary']);
    assertSame($report['narrative']['outlook'], $edited['body']['data']['narrative']['outlook'], 'other sections untouched');

    $final = $proveOwner->post("/reports/{$report['id']}/finalize");
    assertSame(200, $final['status'], json_encode($final['body']));
    assertSame('final', $final['body']['data']['status']);
    assertSame(409, $proveOwner->patch("/reports/{$report['id']}/narrative", ['summary' => 'Late change'])['status']);
    assertSame(409, $proveOwner->post("/reports/{$report['id']}/finalize")['status']);

    // Later data never changes a frozen report.
    Database::run('UPDATE readings_15m SET kwh = kwh * 2 WHERE company_id = ?', [$proveDemo]);
    assertSame($s['energy']['kwh'], $proveOwner->get("/reports/{$report['id']}")['body']['data']['snapshot']['energy']['kwh']);
    $list = $proveOwner->get('/reports')['body']['data'];
    assertSame($s['energy']['kwh'], $list[0]['headline']['kwh']);
    Clock::freeze(null);
});

test('daily and weekly reports compare with the same local hours one day or one week earlier', function () use ($proveOwner, $proveDemo, $proveAnchor): void {
    Clock::freeze($proveAnchor); // a weekday, 21:40 local
    $time = LocalTime::forCompany($proveDemo);
    $now = Clock::now($proveDemo);
    $local = static fn (string $iso): string => $time->format((int) strtotime($iso), 'Y-m-d H:i');
    $day = static fn (string $date, string $shift): string => (new DateTimeImmutable($date, $time->zone()))->modify($shift)->format('Y-m-d');
    $today = $time->date($now);
    $clock = $time->format($now, 'H:i');

    assertSame(422, $proveOwner->post('/reports', ['type' => 'daily', 'period' => substr($today, 0, 7), 'language' => 'en'])['status'], 'a month is not a day');
    assertSame(422, $proveOwner->post('/reports', ['type' => 'weekly', 'period' => '2026-02-30', 'language' => 'en'])['status']);
    assertSame(400, $proveOwner->post('/reports', ['type' => 'daily', 'period' => $day($today, '+2 days'), 'language' => 'en'])['status'], 'not started yet');

    // Today, in progress: partial, and compared with yesterday up to the same time.
    $todayReport = $proveOwner->post('/reports', ['type' => 'daily', 'period' => $today, 'language' => 'en']);
    assertSame(201, $todayReport['status'], json_encode($todayReport['body']));
    assertSame('daily_sustainability', $todayReport['body']['data']['type']);
    $s = $todayReport['body']['data']['snapshot'];
    assertSame(true, $s['period']['partial'], 'today is still running');
    assertSame('previous_day', $s['previous']['basis']);
    assertSame($day($today, '-1 day') . ' 00:00', $local($s['previous']['from']));
    assertSame($day($today, '-1 day') . ' ' . $clock, $local($s['previous']['to']));
    if ($s['previous']['change_ratio'] !== null) {
        assertTrue(str_contains($todayReport['body']['data']['narrative']['changes'], 'the same hours of the previous day'), $todayReport['body']['data']['narrative']['changes']);
    }

    // Yesterday, complete: not partial, compared with the whole day before.
    $yesterday = $proveOwner->post('/reports', ['type' => 'daily', 'period' => $day($today, '-1 day'), 'language' => 'sq'])['body']['data']['snapshot'];
    assertSame(false, $yesterday['period']['partial']);
    assertSame($day($today, '-2 days') . ' 00:00', $local($yesterday['previous']['from']));
    assertSame($day($today, '-1 day') . ' 00:00', $local($yesterday['previous']['to']));

    // This week, in progress: Monday to now, against last Monday up to the same weekday and time.
    $week = $proveOwner->post('/reports', ['type' => 'weekly', 'period' => $today, 'language' => 'en'])['body']['data']['snapshot'];
    $monday = $day($today, '-' . ((int) $time->format($now, 'N') - 1) . ' days');
    assertSame(true, $week['period']['partial']);
    assertSame('previous_week', $week['previous']['basis']);
    assertSame($monday . ' 00:00', $local($week['period']['from']));
    assertSame($day($monday, '-7 days') . ' 00:00', $local($week['previous']['from']));
    assertSame($day($today, '-7 days') . ' ' . $clock, $local($week['previous']['to']));
    Clock::freeze(null);
});
