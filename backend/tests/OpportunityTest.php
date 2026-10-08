<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Demo\DemoSeeder;
use EnergyFlow\Services\Optimization\Recommendations;

// Three weeks of the demo story, ending at 21:40 with the compressor left on.
Clock::freeze(time());
$oppAnchor = DemoClock::sceneAnchor('weekday_evening');
Clock::freeze($oppAnchor);
$oppDemo = DemoSeeder::reset($oppAnchor, 21)['company_id'];
$oppOwner = new Client('10.9.8.7');
assertSame(200, $oppOwner->post('/auth/login', ['email' => DemoSeeder::OWNER_EMAIL, 'password' => 'test-owner-password'])['status']);
$oppMachine = static fn (string $code): int => (int) Database::value('SELECT id FROM machines WHERE company_id = ? AND code = ?', [$oppDemo, $code]);

test('what-if: night-tariff shift saves € but not kWh or CO₂, auto-off saves all three', function () use ($oppOwner, $oppMachine): void {
    $tou = $oppOwner->post('/what-if', ['machine_id' => $oppMachine('PMP-01'), 'action' => 'tou_shift', 'params' => ['shift_share' => 0.5], 'window_days' => 14]);
    assertSame(200, $tou['status'], json_encode($tou['body']));
    $d = $tou['body']['data'];
    assertSame(0.0, $d['delta']['kwh']);
    assertSame(0.0, $d['delta']['co2_kg']);
    assertTrue($d['delta']['eur'] > 0, 'moving day energy to the night tariff is cheaper');
    assertSame('eur', $d['impact_tag']);
    assertTrue(in_array('tou_no_co2', $d['caveats'], true));

    $off = $oppOwner->post('/what-if', ['machine_id' => $oppMachine('CMP-01'), 'action' => 'auto_off_after_schedule', 'params' => ['grace_min' => 15], 'window_days' => 14]);
    $o = $off['body']['data'];
    assertTrue($o['delta']['kwh'] > 20 && $o['delta']['eur'] > 0 && $o['delta']['co2_kg'] > 0, json_encode($o['delta']));
    assertTrue($o['scenario']['kwh'] < $o['baseline']['kwh']);
    $longer = $oppOwner->post('/what-if', ['machine_id' => $oppMachine('CMP-01'), 'action' => 'auto_off_after_schedule', 'params' => ['grace_min' => 60], 'window_days' => 14])['body']['data'];
    assertTrue($longer['delta']['kwh'] < $o['delta']['kwh'], 'a longer grace saves less');
});

test('what-if refuses changes that do not fit the machine', function () use ($oppOwner, $oppMachine): void {
    assertSame('action_not_applicable', $oppOwner->post('/what-if', ['machine_id' => $oppMachine('LGT-01'), 'action' => 'leak_repair'])['body']['error']['code']);
    assertSame(409, $oppOwner->post('/what-if', ['machine_id' => $oppMachine('OFF-01'), 'action' => 'auto_off_after_schedule'])['status'], 'critical machines are never switched');
    assertSame(422, $oppOwner->post('/what-if', ['machine_id' => $oppMachine('CMP-01'), 'action' => 'demolish'])['status']);
});

test('generators rank opportunities from detections, each quantified by a replay', function () use ($oppOwner, $oppDemo): void {
    Recommendations::generate($oppDemo, Clock::now($oppDemo), force: true);
    $list = $oppOwner->get('/recommendations')['body'];
    $generators = array_column($list['data'], 'generator');
    assertTrue(in_array('AfterHoursSchedule', $generators, true), 'compressor left on repeatedly');
    assertTrue(in_array('CompressedAirLeak', $generators, true), 'leak signature');
    assertTrue(in_array('TouShift', $generators, true), 'flexible pump');
    assertTrue(!in_array('LGT-01', array_map(static fn (array $r): string => $r['machine']['code'], array_filter($list['data'], static fn (array $r): bool => $r['generator'] === 'AfterHoursSchedule')), true), 'lighting already has its policy');
    $top = $list['data'][0];
    assertSame('AfterHoursSchedule', $top['generator'], 'highest priority first');
    assertTrue($top['per_month']['eur'] > 0 && $list['meta']['potential']['count'] >= 3);
});

test('accepting creates the policy, which switches the compressor off right away through the command loop', function () use ($oppOwner, $oppDemo, $oppMachine): void {
    $rec = array_values(array_filter($oppOwner->get('/recommendations')['body']['data'], static fn (array $r): bool => $r['generator'] === 'AfterHoursSchedule'))[0];
    $accepted = $oppOwner->post("/recommendations/{$rec['id']}/accept", ['mode' => 'auto']);
    assertSame(200, $accepted['status'], json_encode($accepted['body']));
    assertSame('implemented', $accepted['body']['data']['status']);
    assertSame(409, $oppOwner->post("/recommendations/{$rec['id']}/accept", ['mode' => 'auto'])['status'], 'decided once');
    assertSame(1, (int) Database::value("SELECT COUNT(*) FROM automation_policies WHERE company_id = ? AND recommendation_id = ? AND is_active = 1", [$oppDemo, $rec['id']]));

    // The compressor is still left on after hours: the auto policy acts, the meter verifies.
    $status = null;
    for ($i = 0; $i < 6 && $status !== 'verified'; $i++) {
        Clock::freeze(Clock::realNow() + 10);
        $commands = $oppOwner->get('/commands?machine_id=' . $oppMachine('CMP-01'))['body']['data'];
        $status = $commands[0]['status'] ?? null;
    }
    assertSame('verified', $status);
    assertSame('policy', $commands[0]['source']);
});

test('dismissing suppresses an opportunity, even when the generators run again', function () use ($oppOwner, $oppDemo): void {
    $rec = array_values(array_filter($oppOwner->get('/recommendations')['body']['data'], static fn (array $r): bool => $r['generator'] === 'TouShift'))[0];
    assertSame(422, $oppOwner->post("/recommendations/{$rec['id']}/dismiss", ['reason' => ''])['status']);
    assertSame('dismissed', $oppOwner->post("/recommendations/{$rec['id']}/dismiss", ['reason' => 'Pump runs on demand only'])['body']['data']['status']);
    Recommendations::generate($oppDemo, Clock::now($oppDemo), force: true);
    $again = $oppOwner->get("/recommendations/{$rec['id']}")['body']['data'];
    assertSame('dismissed', $again['status']);
    Clock::freeze(null);
});
