<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Demo\DemoSeeder;
use EnergyFlow\Services\Detection\SpikeDetector;

// A real (non-demo) company with hand-made data: 28 days of 15-minute peaks
// around 10 kW, then 10-second readings for the minutes under test.
$spikeOwner = registeredOwner('spike-owner@example.com', 'Spike SME');
$spikeCompany = (int) Database::value("SELECT company_id FROM users WHERE email = 'spike-owner@example.com'");
$spikeSite = Database::insert("INSERT INTO sites (company_id, name) VALUES (?, 'Plant')", [$spikeCompany]);
$spikeMachine = static function (string $code, float $rated) use ($spikeCompany, $spikeSite): int {
    return Database::insert(
        "INSERT INTO machines (company_id, site_id, kind, code, name, type_code, rated_power_kw, criticality, off_threshold_kw, idle_threshold_kw)
         VALUES (?, ?, 'machine', ?, ?, 'other', ?, 'normal', 0.05, 2.0)",
        [$spikeCompany, $spikeSite, $code, "Machine {$code}", $rated],
    );
};
$spikeTime = new LocalTime('Europe/Belgrade');
// Wednesday 10:00 local: a working day, during the shift.
$spikeNow = $spikeTime->at('2026-09-16', 10 * 60);

$history = static function (int $machineId) use ($spikeCompany, $spikeNow): void {
    $rows = [];
    for ($t = $spikeNow - 28 * 86400; $t < $spikeNow - 3600; $t += 900) {
        $peak = 10.0 + 0.3 * sin($t / 7200); // peaks between 9.7 and 10.3 kW
        $rows[] = sprintf("(%d, FROM_UNIXTIME(%d), %d, 2.0, 8.0, %.3f, 6.0, 900, 0, 0, 'high', 1, 'rollup')", $machineId, $t, $spikeCompany, $peak);
        if (count($rows) === 500) {
            Database::run('INSERT INTO readings_15m (machine_id, bucket_start, company_id, kwh, avg_kw, max_kw, min_kw, running_s, idle_s, off_s, tariff_period, is_scheduled, source) VALUES ' . implode(',', $rows));
            $rows = [];
        }
    }
    if ($rows !== []) {
        Database::run('INSERT INTO readings_15m (machine_id, bucket_start, company_id, kwh, avg_kw, max_kw, min_kw, running_s, idle_s, off_s, tariff_period, is_scheduled, source) VALUES ' . implode(',', $rows));
    }
};
/** 10-second readings for the 40 minutes before now; $kw(minute index) gives the power. */
$raw = static function (int $machineId, callable $kw) use ($spikeNow): void {
    $rows = [];
    for ($t = $spikeNow - 2400; $t < $spikeNow; $t += 10) {
        $minute = intdiv($t - ($spikeNow - 2400), 60);
        $rows[] = sprintf("(%d, FROM_UNIXTIME(%d), 0, %.3f, 2, 'device')", $machineId, $t, $kw($minute) * (1 + 0.01 * sin($t)));
    }
    Database::run('INSERT INTO readings_raw (machine_id, ts, device_id, power_kw, state, source) VALUES ' . implode(',', $rows));
};

$steady = $spikeMachine('SPK-01', 11.0);
$blip = $spikeMachine('SPK-02', 11.0);
$mild = $spikeMachine('SPK-03', 15.0);
foreach ([$steady, $blip, $mild] as $id) {
    $history($id);
}
$raw($steady, static fn (int $m): float => $m >= 20 && $m < 24 ? 13.5 : 8.0);   // 4 minutes at 123 % of rating, then normal
$raw($blip, static fn (int $m): float => $m === 20 ? 14.0 : 8.0);               // one minute only
$raw($mild, static fn (int $m): float => $m >= 30 ? 12.5 : 8.0);                // still going on, below the rating

test('SPIKE: z > 6 for 2+ minutes opens an alert; overload of the nameplate makes it critical', function () use ($spikeCompany, $spikeNow, $steady, $blip, $mild): void {
    SpikeDetector::run($spikeCompany, $spikeNow);
    $alerts = Database::all("SELECT machine_id, severity, status, params, opened_at, resolved_at FROM alerts WHERE company_id = ? AND type = 'SPIKE'", [$spikeCompany]);
    $by = array_column($alerts, null, 'machine_id');
    assertSame(2, count($alerts), json_encode($alerts));

    $a = $by[$steady];
    $p = json_decode($a['params'], true);
    assertSame('critical', $a['severity'], '13.5 kW on an 11 kW motor is > 110 %');
    assertSame('resolved', $a['status'], 'five normal minutes after it ended');
    assertTrue($p['z'] > 6 && $p['peak_kw'] > 13.4 && $p['peak_kw'] < 13.8, json_encode($p));
    assertSame(240, $p['duration_s']);
    assertTrue($p['overload'] && $p['rated_pct'] >= 122, json_encode($p));

    assertTrue(!isset($by[$blip]), 'a single minute is not a spike');

    $m = $by[$mild];
    assertSame('warning', $m['severity'], '12.5 kW on a 15 kW motor is unusual but not an overload');
    assertSame('open', $m['status']);

    // Idempotent: running again over the same minutes changes nothing.
    SpikeDetector::run($spikeCompany, $spikeNow + 60);
    assertSame(2, (int) Database::value("SELECT COUNT(*) FROM alerts WHERE company_id = ? AND type = 'SPIKE'", [$spikeCompany]));
});

test('SPIKE evidence: the baseline, the minutes and the thresholds behind the alert', function () use ($spikeOwner, $steady): void {
    $list = $spikeOwner->get('/alerts?status=all')['body']['data'];
    $row = array_values(array_filter($list, static fn (array $a): bool => $a['type'] === 'SPIKE' && ($a['machine']['id'] ?? null) === $steady))[0];
    $one = $spikeOwner->get("/alerts/{$row['id']}");
    assertSame(200, $one['status'], json_encode($one['body']));
    $e = $one['body']['data']['evidence'];
    assertSame('robust_z', $e['rule']);
    assertTrue(abs($e['baseline']['median_kw'] - 10.0) < 0.35, json_encode($e['baseline']));
    assertSame(4, $e['minutes']);
    assertSame(4, count(array_filter(array_column($e['series'], 'spike'))));
    assertTrue(count($e['series']) >= 14, 'normal minutes around it, for the chart');
    assertTrue($e['limit_kw'] > $e['baseline']['median_kw'] && $e['limit_kw'] < $e['observed']['peak_kw']);
    assertSame(6.0, (float) $e['thresholds']['z']);
    assertSame(404, $spikeOwner->get('/alerts/999999')['status']);
});

test('the assistant reports an open spike from data', function () use ($spikeOwner): void {
    $conversation = $spikeOwner->post('/assistant/conversations')['body']['data']['id'];
    $answer = $spikeOwner->post("/assistant/conversations/{$conversation}/messages", ['content' => 'Any power spikes?', 'language' => 'en'])['body']['data']['assistant'];
    assertSame('alerts', $answer['intent']);
    assertTrue(str_contains($answer['content'], 'SPK-03: power spike to 12'), $answer['content']);
});

// The demo: inject an overload from the Demo Director and let the detector find it.
Clock::freeze(time());
$spikeAnchor = DemoClock::sceneAnchor('weekday_evening');
Clock::freeze($spikeAnchor);
$spikeDemo = DemoSeeder::reset($spikeAnchor, 30)['company_id'];
$director = new Client('10.9.5.1');
assertSame(200, $director->post('/auth/login', ['email' => DemoSeeder::OWNER_EMAIL, 'password' => 'test-owner-password'])['status']);

test('normal simulated data raises no spike', function () use ($spikeDemo): void {
    assertSame(0, (int) Database::value("SELECT COUNT(*) FROM alerts WHERE company_id = ? AND type = 'SPIKE'", [$spikeDemo]));
    assertTrue((int) Database::value('SELECT COUNT(*) FROM machine_baselines b JOIN machines m ON m.id = b.machine_id WHERE m.company_id = ?', [$spikeDemo]) > 50);
});

test('Demo Director: an injected overload is found by the detector', function () use ($director, $spikeDemo): void {
    $guest = new Client('10.9.5.2');
    $guest->post('/auth/demo');
    assertSame(403, $guest->post('/demo/spike')['status'], 'viewers cannot inject faults');

    $spike = $director->post('/demo/spike', ['minutes' => 5]);
    assertSame(200, $spike['status'], json_encode($spike['body']));
    $s = $spike['body']['data'];
    assertSame('CMP-01', $s['machine']['code'], 'the compressor left running tonight draws the most power');
    assertSame(135, $s['percent']);

    assertSame(200, $director->post('/demo/advance', ['minutes' => 15])['status']);
    $alert = Database::one("SELECT severity, status, params FROM alerts WHERE company_id = ? AND type = 'SPIKE'", [$spikeDemo]);
    assertTrue($alert !== null, 'the spike was detected');
    $p = json_decode($alert['params'], true);
    assertSame('critical', $alert['severity']);
    assertSame('resolved', $alert['status'], 'it lasted 5 minutes and the data has been normal since');
    assertSame('CMP-01', $p['code']);
    assertTrue($p['duration_s'] >= 240 && $p['duration_s'] <= 360, json_encode($p));
    assertSame(1, (int) Database::value("SELECT COUNT(*) FROM alerts WHERE company_id = ? AND type = 'SPIKE'", [$spikeDemo]));

    $off = Database::value("SELECT id FROM machines WHERE company_id = ? AND code = 'IMM-01'", [$spikeDemo]);
    assertSame(409, $director->post('/demo/spike', ['machine_id' => (int) $off])['status'], 'a machine that is off cannot be overloaded');
    assertSame(422, $director->post('/demo/advance', [])['status']);
    Clock::freeze(null);
});
