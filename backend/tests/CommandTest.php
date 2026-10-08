<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Core\Kernel;
use EnergyFlow\Core\Request;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Control\CommandService;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Demo\DemoSeeder;
use EnergyFlow\Services\Telemetry\DeviceSecrets;

// A short demo story with a frozen real clock, so the command loop can be
// stepped through second by second without sleeping.
$realStart = time();
Clock::freeze($realStart);
$anchor = DemoClock::sceneAnchor('weekday_evening');
Clock::freeze($anchor); // the story ends "now": 21:40 on a weekday, compressor still on
$cmdDemo = DemoSeeder::reset($anchor, 7)['company_id'];
$cmdOwner = new Client('10.9.9.9');
assertSame(200, $cmdOwner->post('/auth/login', ['email' => DemoSeeder::OWNER_EMAIL, 'password' => 'test-owner-password'])['status']);
$machineId = static fn (string $code): int => (int) Database::value('SELECT id FROM machines WHERE company_id = ? AND code = ?', [$cmdDemo, $code]);
$step = static function (int $seconds): void {
    Clock::freeze(Clock::realNow() + $seconds);
};

/** A signed request from a real device, at the frozen real time. */
function signedDevice(string $serial, string $method, string $path, ?array $payload = null): array
{
    $body = $payload === null ? '' : json_encode($payload);
    $timestamp = Clock::realNow();
    $response = Kernel::handle(new Request(
        method: $method,
        path: '/api/v1' . $path,
        headers: [
            'authorization' => 'Device ' . $serial,
            'x-ef-timestamp' => (string) $timestamp,
            'x-ef-signature' => DeviceSecrets::sign(DeviceSecrets::secretFor($serial, 1), $timestamp, $body),
            'content-type' => 'application/json',
        ],
        body: $body,
        ip: '10.20.30.41',
    ));
    return ['status' => $response->status, 'body' => $response->body];
}

test('safety rules: critical, monitor-only, no relay, already off, pending — and a scheduled machine needs confirmation', function (): void {
    $relay = ['device_id' => 1, 'serial' => 'EF-1', 'simulated' => true, 'channel' => 1];
    $machine = ['kind' => 'machine', 'criticality' => 'normal', 'control_mode' => 'approve'];
    assertSame('machine_critical', CommandService::evaluate(['criticality' => 'critical'] + $machine, $relay, null, 'running', false)['reason']);
    assertSame('control_monitor_only', CommandService::evaluate(['control_mode' => 'monitor'] + $machine, $relay, null, 'running', false)['reason']);
    assertSame('no_relay', CommandService::evaluate($machine, null, null, 'running', false)['reason']);
    assertSame('already_off', CommandService::evaluate($machine, $relay, null, 'off', false)['reason']);
    assertSame('command_pending', CommandService::evaluate($machine, $relay, ['status' => 'sent'], 'running', false)['reason']);
    $scheduled = CommandService::evaluate($machine, $relay, null, 'running', true);
    assertTrue($scheduled['can_turn_off'] && $scheduled['needs_confirm'] === 'machine_scheduled');
    assertTrue(CommandService::evaluate($machine, $relay, ['status' => 'verified'], 'idle', false)['can_turn_off']);
});

test('the API refuses critical machines, viewers and anything but turn_off', function () use ($cmdOwner, $machineId, $cmdDemo): void {
    $response = $cmdOwner->post('/machines/' . $machineId('OFF-01') . '/commands', ['command' => 'turn_off']);
    assertSame(409, $response['status']);
    assertSame('machine_critical', $response['body']['error']['code']);
    assertSame(422, $cmdOwner->post('/machines/' . $machineId('CMP-01') . '/commands', ['command' => 'turn_on'])['status'], 'EnergyFlow can never start a machine');

    $guest = new Client('10.9.9.10');
    $guest->post('/auth/demo');
    assertSame(403, $guest->post('/machines/' . $machineId('CMP-01') . '/commands', ['command' => 'turn_off'])['status']);
    assertSame(0, (int) Database::value('SELECT COUNT(*) FROM device_commands WHERE company_id = ? AND source = ?', [$cmdDemo, 'user']));
});

test('simulated Turn Off: queued → sent → executed → verified by the meter, then the machine stays off', function () use ($cmdOwner, $machineId, $cmdDemo, $step): void {
    $cmp = $machineId('CMP-01');
    $created = $cmdOwner->post("/machines/{$cmp}/commands", ['command' => 'turn_off', 'reason' => 'Shift ended']);
    assertSame(201, $created['status'], json_encode($created['body']));
    $id = $created['body']['data']['id'];
    assertSame('queued', $created['body']['data']['status']);
    assertTrue($created['body']['data']['verification']['power_before_kw'] > 3, 'compressor was running');
    assertSame('command_pending', $cmdOwner->post("/machines/{$cmp}/commands", ['command' => 'turn_off'])['body']['error']['code']);

    $status = null;
    for ($i = 0; $i < 6 && $status !== 'verified'; $i++) {
        $step(10);
        $status = $cmdOwner->get("/commands/{$id}")['body']['data']['status'];
    }
    $command = $cmdOwner->get("/commands/{$id}")['body']['data'];
    assertSame('verified', $command['status']);
    assertSame(['queued', 'sent', 'executed', 'verified'], array_column($command['timeline'], 'step'));
    assertSame(0.0, $command['verification']['power_after_kw']);
    assertTrue($command['verification']['verified_after_s'] <= 30, 'verified within 30 s');

    $live = $cmdOwner->get('/live')['body']['data'];
    $row = array_values(array_filter($live['machines'], static fn (array $m): bool => $m['code'] === 'CMP-01'))[0];
    assertSame('off', $row['state']);
    assertSame('already_off', $row['control']['reason']);
    assertSame('acted', Database::value("SELECT action_status FROM waste_events WHERE company_id = ? AND machine_id = ? AND type = 'after_hours' ORDER BY started_at DESC LIMIT 1", [$cmdDemo, $cmp]));
    assertSame(1, (int) Database::value("SELECT COUNT(*) FROM notifications WHERE company_id = ? AND title_key = 'command.verified'", [$cmdDemo]));
    assertTrue((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE company_id = ? AND action = 'command.turn_off'", [$cmdDemo]) >= 1);
});

test('real hardware: the device pulls the command, acks it, and its own readings verify it', function () use ($cmdOwner, $cmdDemo, $step): void {
    $siteId = (int) Database::value('SELECT id FROM sites WHERE company_id = ?', [$cmdDemo]);
    $schedule = (int) Database::value('SELECT id FROM schedules WHERE company_id = ? ORDER BY id LIMIT 1', [$cmdDemo]);
    $lamp = Database::insert(
        "INSERT INTO machines (company_id, site_id, kind, code, name, type_code, phases, schedule_id, criticality, control_mode, off_threshold_kw, idle_threshold_kw)
         VALUES (?, ?, 'machine', 'LIVE-T', 'Test lamp', 'lighting', 1, ?, 'normal', 'approve', 0.005, 0.005)",
        [$cmdDemo, $siteId, $schedule],
    );
    $device = Database::insert(
        "INSERT INTO devices (company_id, site_id, serial, model, key_hash, key_version, is_simulated, status) VALUES (?, ?, 'EF-T101', 'EF-Bridge', 'x', 1, 0, 'provisioned')",
        [$cmdDemo, $siteId],
    );
    Database::run("INSERT INTO device_channels (device_id, channel_no, machine_id, measurement_mode, has_relay, valid_from) VALUES (?, 1, ?, 'single_phase', 1, UTC_TIMESTAMP() - INTERVAL 1 DAY)", [$device, $lamp]);

    $reading = static fn (float $kw): array => ['device' => 'EF-T101', 'readings' => [['ch' => 1, 'ts' => gmdate('Y-m-d\TH:i:s\Z', Clock::realNow()), 'v' => 230.1, 'i' => $kw * 1000 / 230, 'p_kw' => $kw, 'pf' => 0.99]]];
    assertSame(200, signedDevice('EF-T101', 'POST', '/ingest/readings', $reading(0.06))['status']);

    $created = $cmdOwner->post("/machines/{$lamp}/commands", ['command' => 'turn_off']);
    assertSame(201, $created['status'], json_encode($created['body']));
    $id = $created['body']['data']['id'];

    $step(10);
    $uplink = signedDevice('EF-T101', 'POST', '/ingest/readings', $reading(0.06));
    assertSame(1, $uplink['body']['data']['pending_commands']);
    $pulled = signedDevice('EF-T101', 'GET', '/device/commands');
    assertSame([['id' => $id, 'ch' => 1, 'command' => 'turn_off']], $pulled['body']['data']);
    assertSame(true, signedDevice('EF-T101', 'POST', "/device/commands/{$id}/ack", ['status' => 'executed'])['body']['data']['updated']);
    assertSame('executed', $cmdOwner->get("/commands/{$id}")['body']['data']['status']);

    $step(10);
    signedDevice('EF-T101', 'POST', '/ingest/readings', $reading(0.0));
    $command = $cmdOwner->get("/commands/{$id}")['body']['data'];
    assertSame('verified', $command['status'], 'the meter confirms the stop');
    assertSame(false, $command['device']['simulated']);
});

test('a device that reports failure, or never answers, fails the command and raises an alert', function () use ($cmdOwner, $machineId, $cmdDemo, $step): void {
    $lamp = $machineId('LIVE-T');
    $reading = static fn (float $kw): array => ['device' => 'EF-T101', 'readings' => [['ch' => 1, 'ts' => gmdate('Y-m-d\TH:i:s\Z', Clock::realNow()), 'v' => 230.0, 'p_kw' => $kw]]];
    $step(10); // a new reading (one per channel and second; repeats are de-duplicated)
    signedDevice('EF-T101', 'POST', '/ingest/readings', $reading(0.05));
    $request = $cmdOwner->post("/machines/{$lamp}/commands", ['command' => 'turn_off']);
    assertSame(201, $request['status'], json_encode($request['body']));
    $id = $request['body']['data']['id'];
    signedDevice('EF-T101', 'GET', '/device/commands');
    signedDevice('EF-T101', 'POST', "/device/commands/{$id}/ack", ['status' => 'failed', 'detail' => 'relay stuck']);
    $failed = $cmdOwner->get("/commands/{$id}")['body']['data'];
    assertSame('failed', $failed['status']);
    assertTrue(str_starts_with($failed['failure_reason'], 'device_reported'));

    $step(5);
    signedDevice('EF-T101', 'POST', '/ingest/readings', $reading(0.05));
    $silent = $cmdOwner->post("/machines/{$lamp}/commands", ['command' => 'turn_off'])['body']['data']['id'];
    $step(CommandService::TIMEOUT_S + 5); // the device never picks it up
    $timedOut = $cmdOwner->get("/commands/{$silent}")['body']['data'];
    assertSame('failed', $timedOut['status']);
    assertSame(2, (int) Database::value("SELECT COUNT(*) FROM alerts WHERE company_id = ? AND type = 'COMMAND_FAILED'", [$cmdDemo]));
    Clock::freeze(null);
});
