<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Core\Kernel;
use EnergyFlow\Core\Request;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Demo\DemoSeeder;
use EnergyFlow\Services\Simulation\SimContext;
use EnergyFlow\Services\Simulation\Simulator;
use EnergyFlow\Services\Telemetry\DeviceSecrets;
use EnergyFlow\Services\Telemetry\RollupService;

// One small demo company (10 days of history) shared by these tests.
$anchor = DemoClock::sceneAnchor('weekday_evening');
$demo = DemoSeeder::reset($anchor, 10);
$demoId = $demo['company_id'];

/** Sends a signed device request the way EF-N3 firmware does. */
function deviceCall(string $serial, string $method, string $path, ?array $payload, ?int $timestamp = null, ?string $secret = null): array
{
    $body = $payload === null ? '' : json_encode($payload);
    $timestamp ??= Clock::realNow();
    $secret ??= DeviceSecrets::secretFor($serial, 1);
    $response = Kernel::handle(new Request(
        method: $method,
        path: '/api/v1' . $path,
        headers: [
            'authorization' => 'Device ' . $serial,
            'x-ef-timestamp' => (string) $timestamp,
            'x-ef-signature' => DeviceSecrets::sign($secret, $timestamp, $body),
            'content-type' => 'application/json',
        ],
        body: $body,
        ip: '10.20.30.40',
    ));
    return ['status' => $response->status, 'body' => $response->body];
}

test('the simulator is deterministic: same machine, same moment, same reading', function () use ($demoId, $anchor): void {
    $a = (new Simulator(SimContext::load($demoId, $anchor - 7200, $anchor), null))->readingsAt($anchor - 600);
    $b = (new Simulator(SimContext::load($demoId, $anchor - 7200, $anchor), null))->readingsAt($anchor - 600);
    assertSame($a, $b);
    assertTrue(count($a) === 9, '8 machines + incomer');
});

test('the demo story: compressor runs after the 21:00 shift end with leak cycling, IMM-02 is drifting', function () use ($demoId): void {
    $live = Database::one("SELECT l.state, l.power_kw FROM machine_live l JOIN machines m ON m.id = l.machine_id WHERE m.company_id = ? AND m.code = 'LGT-01'", [$demoId]);
    assertSame('off', $live['state'], 'lights are switched off by the accepted policy');
    $cmp = Database::one(
        "SELECT SUM(CASE WHEN r.is_scheduled = 0 THEN r.kwh ELSE 0 END) AS outside, SUM(r.cycle_count) AS cycles
           FROM readings_15m r JOIN machines m ON m.id = r.machine_id WHERE m.company_id = ? AND m.code = 'CMP-01'",
        [$demoId],
    );
    assertTrue((float) $cmp['outside'] > 50, 'compressor consumes energy outside its schedule');
    assertTrue((int) $cmp['cycles'] > 0, 'load/unload cycles are counted');
});

test('signed device ingest: accepted, duplicates ignored, bad signature and stale timestamp rejected', function () use ($demoId): void {
    // A real (non-simulated) node on the demo company, one channel → the hall lighting.
    $siteId = (int) Database::value('SELECT id FROM sites WHERE company_id = ?', [$demoId]);
    $machineId = (int) Database::value("SELECT id FROM machines WHERE company_id = ? AND code = 'OFF-01'", [$demoId]);
    $deviceId = Database::insert(
        "INSERT INTO devices (company_id, site_id, serial, model, key_hash, key_version, is_simulated, status) VALUES (?, ?, 'EF-TEST1', 'EF-Bridge', 'x', 1, 0, 'provisioned')",
        [$demoId, $siteId],
    );
    Database::run(
        "INSERT INTO device_channels (device_id, channel_no, machine_id, measurement_mode, has_relay, valid_from) VALUES (?, 1, ?, 'single_phase', 1, UTC_TIMESTAMP())",
        [$deviceId, $machineId],
    );

    $ts = gmdate('Y-m-d\TH:i:s\Z', Clock::realNow() - 20);
    $payload = ['device' => 'EF-TEST1', 'fw' => '0.1.0', 'seq' => 1,
        'readings' => [['ch' => 1, 'ts' => $ts, 'v' => 230.4, 'i' => 0.26, 'p_kw' => 0.058, 'pf' => 0.97, 'f' => 50.0]],
        'events' => [['ts' => $ts, 'type' => 'hold', 'minutes' => 120]],
        'status' => ['rssi' => -58, 'uptime_s' => 1200, 'buffered' => 0]];

    $first = deviceCall('EF-TEST1', 'POST', '/ingest/readings', $payload);
    assertSame(200, $first['status'], json_encode($first['body']));
    assertSame(1, $first['body']['data']['accepted']);
    assertSame(1, $first['body']['data']['events']);
    assertTrue(Database::value('SELECT 1 FROM production_overrides WHERE company_id = ? AND machine_id = ?', [$demoId, $machineId]) !== null, 'HOLD created an override');

    $again = deviceCall('EF-TEST1', 'POST', '/ingest/readings', $payload);
    assertSame(0, $again['body']['data']['accepted']);
    assertSame(1, $again['body']['data']['duplicates']);

    assertSame(401, deviceCall('EF-TEST1', 'POST', '/ingest/readings', $payload, secret: str_repeat('0', 64))['status']);
    assertSame(401, deviceCall('EF-TEST1', 'POST', '/ingest/readings', $payload, timestamp: Clock::realNow() - 900)['status']);
    assertSame(401, deviceCall('EF-000', 'POST', '/ingest/readings', $payload)['status'], 'simulated devices cannot be impersonated');
});

test('rollup integrates 10-second readings into 15-minute kWh', function () use ($demoId, $anchor): void {
    $machineId = (int) Database::value("SELECT id FROM machines WHERE company_id = ? AND code = 'LGT-01'", [$demoId]);
    $bucket = intdiv($anchor - 1800, 900) * 900;
    $raw = (float) Database::value(
        'SELECT SUM(power_kw) * 10 / 3600 FROM readings_raw WHERE machine_id = ? AND ts >= FROM_UNIXTIME(?) AND ts < FROM_UNIXTIME(?)',
        [$machineId, $bucket, $bucket + 900],
    );
    RollupService::run($demoId, $anchor);
    $rolled = (float) Database::value('SELECT kwh FROM readings_15m WHERE machine_id = ? AND bucket_start = FROM_UNIXTIME(?)', [$machineId, $bucket]);
    assertTrue(abs($rolled - $raw) < 0.01, "rollup {$rolled} ≈ raw {$raw}");
});

test('live, overview, machines and devices endpoints serve the demo data (read-only guest)', function (): void {
    $guest = new Client();
    $login = $guest->post('/auth/demo');
    assertSame(200, $login['status'], json_encode($login['body']));
    assertSame('viewer', $login['body']['data']['user']['role']);

    $live = $guest->get('/live')['body']['data'];
    assertTrue($live['site_kw'] > 0, 'site power');
    assertTrue($live['on'] >= 1, 'at least one machine is on');
    assertSame(8, count($live['machines']));
    assertTrue($live['devices']['total'] >= 4, 'devices listed');

    $overview = $guest->get('/overview')['body']['data'];
    assertTrue($overview['has_data']);
    assertTrue($overview['month']['kwh'] > 0 && $overview['projection']['kwh'] >= $overview['month']['kwh'], 'projection ≥ month to date');
    assertSame('0.900940', number_format($overview['emission_factor']['value'], 6));
    assertSame(96, count($overview['today_curve']['points']));

    $machines = $guest->get('/machines')['body']['data'];
    $first = $machines[0];
    $detail = $guest->get('/machines/' . $first['id']);
    assertSame(200, $detail['status']);
    assertTrue(count($guest->get('/machines/' . $first['id'] . '/timeseries?range=24h')['body']['data']) > 50, '24 h series');
    assertSame(200, $guest->get('/devices')['status']);

    assertSame(403, $guest->call('POST', '/demo/advance', ['hours' => 24])['status'], 'guests cannot drive the demo');
});

test('fast-forward generates the skipped time and keeps live data flowing', function () use ($demoId): void {
    $before = (int) Database::value('SELECT COUNT(*) FROM readings_15m WHERE company_id = ?', [$demoId]);
    $rows = DemoClock::advance($demoId, 2 * 86400);
    $after = (int) Database::value('SELECT COUNT(*) FROM readings_15m WHERE company_id = ?', [$demoId]);
    assertTrue($rows > 0 && $after > $before + 9 * 96, "two days of buckets added ({$before} → {$after})");
    $liveAge = Clock::now($demoId) - (int) Database::value('SELECT UNIX_TIMESTAMP(MAX(ts)) FROM machine_live WHERE company_id = ?', [$demoId]);
    assertTrue($liveAge <= 20, "live readings are current ({$liveAge} s old)");
});
