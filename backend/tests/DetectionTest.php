<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Detection\DriftDetector;
use EnergyFlow\Services\Detection\WasteDetector;

// A small real (non-demo) company with hand-made 15-minute buckets, so every
// detection rule is tested on exactly known data.
$owner = registeredOwner('detect-owner@example.com', 'Detect SME');
$detectCompany = (int) Database::value("SELECT company_id FROM users WHERE email = 'detect-owner@example.com'");
$time = new LocalTime('Europe/Belgrade');

$site = Database::insert("INSERT INTO sites (company_id, name) VALUES (?, 'Plant')", [$detectCompany]);
$schedule = Database::insert("INSERT INTO schedules (company_id, name) VALUES (?, 'Two shifts')", [$detectCompany]);
foreach ([1, 2, 3, 4, 5] as $day) {
    Database::run("INSERT INTO schedule_rules (schedule_id, day_of_week, start_time, end_time) VALUES (?, ?, '07:00', '21:00')", [$schedule, $day]);
}
$template = (int) Database::value("SELECT id FROM tariff_plans WHERE company_id IS NULL AND category = 'commercial_cat_1'");
Database::run('UPDATE companies SET tariff_plan_id = ? WHERE id = ?', [$template, $detectCompany]);

$machine = static function (string $code, string $type, string $criticality = 'normal') use ($detectCompany, $site, $schedule): int {
    return Database::insert(
        "INSERT INTO machines (company_id, site_id, kind, code, name, type_code, schedule_id, criticality, off_threshold_kw, idle_threshold_kw)
         VALUES (?, ?, 'machine', ?, ?, ?, ?, ?, 0.05, 2.0)",
        [$detectCompany, $site, $code, "Machine {$code}", $type, $schedule, $criticality],
    );
};
/** One 15-minute bucket; is_scheduled follows the Mon–Fri 07–21 schedule. */
$bucket = static function (int $machineId, int $start, float $kw, int $runS = 900, int $idleS = 0, int $cycles = 0) use ($detectCompany, $time): void {
    $minute = $time->minuteOfDay($start + 450);
    $scheduled = $time->dayOfWeek($start) <= 5 && $minute >= 420 && $minute < 1260;
    Database::run(
        "INSERT INTO readings_15m (machine_id, bucket_start, company_id, kwh, avg_kw, max_kw, min_kw, running_s, idle_s, off_s, cycle_count, tariff_period, is_scheduled, source)
         VALUES (?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'high', ?, 'rollup')",
        [$machineId, $start, $detectCompany, $kw * 0.25, $kw, $kw * 1.05, $kw * 0.95, $kw > 0 ? $runS : 0, $kw > 0 ? $idleS : 0, $kw > 0 ? 900 - $runS - $idleS : 900, $cycles, $scheduled ? 1 : 0],
    );
};

$wednesday = $time->at('2026-09-16'); // a working day (local midnight)
$compressor = $machine('CMP-T', 'compressor');
$fridge = $machine('FRG-T', 'refrigeration', 'critical');
$lamp = $machine('LGT-T', 'lighting');
for ($t = $wednesday + 19 * 3600; $t < $wednesday + 23 * 3600; $t += 900) {
    $bucket($compressor, $t, 5.0, 200, 700, 7); // runs on two hours past the 21:00 shift end, cycling
    $bucket($fridge, $t, 2.0);                 // critical: always on, never waste
    $bucket($lamp, $t, $t < $wednesday + 21 * 3600 + 900 ? 3.0 : 0.0); // only 15 min past the end
}
Clock::freeze($wednesday + 24 * 3600);

test('after-hours running for 2 h becomes one quantified waste event with an alert', function () use ($detectCompany, $compressor, $wednesday): void {
    WasteDetector::run($detectCompany, Clock::realNow());
    $events = Database::all('SELECT * FROM waste_events WHERE company_id = ?', [$detectCompany]);
    assertSame(1, count($events), 'only the compressor episode');
    $e = $events[0];
    assertSame($compressor, (int) $e['machine_id']);
    assertSame('after_hours', $e['type']);
    assertSame(gmdate('Y-m-d H:i:s', $wednesday + 21 * 3600), $e['started_at']);
    assertSame(gmdate('Y-m-d H:i:s', $wednesday + 23 * 3600), $e['ended_at']);
    assertSame(10.0, round((float) $e['energy_kwh'], 1), '2 h × 5 kW');
    assertSame(0.87, (float) $e['cost_eur'], '10 kWh at the high rate of 0.087 €/kWh');
    $evidence = json_decode($e['evidence'], true);
    assertSame('leak_signature', $evidence['signals'][0]['key'] ?? null, 'loaded 22 % of the time with no production');
    $alert = Database::one('SELECT status, severity, type FROM alerts WHERE waste_event_id = ?', [(int) $e['id']]);
    assertSame('resolved', $alert['status'], 'the episode has ended');
    assertSame('info', $alert['severity'], 'under €1');
});

test('re-running detection over the same data never duplicates events', function () use ($detectCompany): void {
    Database::run("DELETE FROM job_runs WHERE job_key = 'waste_detect' AND company_id = ?", [$detectCompany]);
    WasteDetector::run($detectCompany, Clock::realNow());
    WasteDetector::run($detectCompany, Clock::realNow());
    assertSame(1, (int) Database::value('SELECT COUNT(*) FROM waste_events WHERE company_id = ?', [$detectCompany]));
    assertSame(1, (int) Database::value('SELECT COUNT(*) FROM alerts WHERE company_id = ?', [$detectCompany]));
});

test('drift: CUSUM flags a sustained +15 % in running power, a flat machine stays quiet', function () use ($detectCompany, $machine, $bucket, $wednesday): void {
    $worn = $machine('IMM-T', 'injection_moulding');
    $healthy = $machine('IMM-H', 'injection_moulding');
    $start = $wednesday - 50 * 86400;
    for ($day = 0; $day < 50; $day++) {
        $date = $start + $day * 86400;
        for ($h = 8; $h < 18; $h++) {
            $wobble = (($day * 7 + $h) % 5 - 2) * 0.02;
            $bucket($worn, $date + $h * 3600, ($day < 36 ? 10.0 : 11.5) + $wobble);
            $bucket($healthy, $date + $h * 3600, 10.0 + $wobble);
        }
    }
    DriftDetector::run($detectCompany, Clock::realNow(), force: true);
    $drift = Database::all("SELECT machine_id, severity, evidence FROM waste_events WHERE company_id = ? AND type = 'excess_vs_baseline'", [$detectCompany]);
    assertSame(1, count($drift), 'only the worn machine');
    assertSame($worn, (int) $drift[0]['machine_id']);
    assertSame('warning', $drift[0]['severity']);
    $evidence = json_decode($drift[0]['evidence'], true);
    assertTrue(abs($evidence['deviation_pct'] - 15.0) < 0.5, 'deviation ' . $evidence['deviation_pct']);
    assertSame('statistical', $evidence['method']);
});

test('waste API: summary, list and detail; only managers can dismiss', function () use ($owner, $detectCompany): void {
    $summary = $owner->get('/waste/summary?period=30d');
    assertSame(200, $summary['status'], json_encode($summary['body']));
    assertTrue($summary['body']['data']['totals']['kwh'] > 10, 'after-hours + drift');
    $types = array_column($summary['body']['data']['by_type'], 'type');
    assertTrue(in_array('after_hours', $types, true) && in_array('excess_vs_baseline', $types, true));

    $list = $owner->get('/waste-events?period=30d');
    assertSame(200, $list['status']);
    $id = $list['body']['data'][array_key_last($list['body']['data'])]['id'];
    $detail = $owner->get("/waste-events/{$id}");
    assertSame(200, $detail['status']);
    assertTrue(isset($detail['body']['data']['evidence']['method']));

    Database::run("UPDATE users SET role = 'viewer' WHERE email = 'detect-owner@example.com'");
    assertSame(403, $owner->post("/waste-events/{$id}/dismiss", ['reason' => 'Planned test run'])['status']);
    Database::run("UPDATE users SET role = 'owner' WHERE email = 'detect-owner@example.com'");
    $dismissed = $owner->post("/waste-events/{$id}/dismiss", ['reason' => 'Planned test run']);
    assertSame('dismissed', $dismissed['body']['data']['action_status']);

    $alerts = $owner->get('/alerts?status=all');
    assertSame(200, $alerts['status']);
    assertTrue(count($alerts['body']['data']) >= 1);
    assertSame(200, $owner->get('/notifications')['status']);
});

test('another company never sees these waste events', function () use ($detectCompany): void {
    $other = registeredOwner('detect-other@example.com', 'Other SME');
    assertSame([], $other->get('/waste-events?period=30d')['body']['data']);
    $someId = (int) Database::value('SELECT id FROM waste_events WHERE company_id = ? LIMIT 1', [$detectCompany]);
    assertSame(404, $other->get("/waste-events/{$someId}")['status']);
    Clock::freeze(null);
});
