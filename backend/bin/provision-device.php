<?php

declare(strict_types=1);

/*
 * Provisions a REAL EnergyFlow device (EF-N3 node or the tools/hw-bridge for a
 * metering smart plug) and maps one of its channels to a machine.
 *
 *   php bin/provision-device.php --company=demo --serial=EF-101 --model=EF-Bridge \
 *       --machine=LIVE-01 --name="Workshop lamp" --type=lighting --relay
 *
 * The device secret is printed ONCE. It is never stored: it is derived from
 * APP_KEY + serial + key version (see Services/Telemetry/DeviceSecrets.php), so
 * re-provisioning the same serial gives the same secret, and rotating a key
 * means bumping key_version.
 *
 * This creates no data. Readings appear only when the device (or the bridge)
 * actually starts sending them.
 */

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Telemetry\DeviceSecrets;

require dirname(__DIR__) . '/src/bootstrap.php';

$o = getopt('', ['company:', 'serial:', 'model::', 'machine:', 'name::', 'type::', 'channel::', 'relay', 'schedule::', 'criticality::']);
foreach (['company', 'serial', 'machine'] as $required) {
    if (empty($o[$required])) {
        fwrite(STDERR, "Missing --{$required}. See the header of this file for usage.\n");
        exit(1);
    }
}

$companyId = $o['company'] === 'demo'
    ? (int) Database::value('SELECT id FROM companies WHERE is_demo = 1 LIMIT 1')
    : (int) $o['company'];
$siteId = Database::value('SELECT id FROM sites WHERE company_id = ? ORDER BY id LIMIT 1', [$companyId]);
if ($companyId === 0 || $siteId === null) {
    fwrite(STDERR, "Company not found, or it has no site yet.\n");
    exit(1);
}

$serial = strtoupper((string) $o['serial']);
if (!preg_match('/^[A-Z0-9\-]{3,30}$/', $serial)) {
    fwrite(STDERR, "Invalid serial.\n");
    exit(1);
}
$model = $o['model'] ?? 'EF-Bridge';
if (!in_array($model, ['EF-Node-1P', 'EF-Node-3P', 'EF-Gateway', 'EF-Bridge'], true)) {
    fwrite(STDERR, "--model must be EF-Node-1P, EF-Node-3P, EF-Gateway or EF-Bridge.\n");
    exit(1);
}
$channel = max(1, (int) ($o['channel'] ?? 1));

$result = Database::transaction(static function () use ($companyId, $siteId, $serial, $model, $channel, $o): array {
    $existing = Database::one('SELECT id, company_id, key_version FROM devices WHERE serial = ?', [$serial]);
    if ($existing !== null && (int) $existing['company_id'] !== $companyId) {
        throw new RuntimeException("Serial {$serial} belongs to another company.");
    }

    $code = strtoupper((string) $o['machine']);
    $machineId = Database::value('SELECT id FROM machines WHERE company_id = ? AND code = ?', [$companyId, $code]);
    if ($machineId === null) {
        $scheduleId = isset($o['schedule'])
            ? (int) $o['schedule']
            : Database::value('SELECT id FROM schedules WHERE company_id = ? ORDER BY id LIMIT 1', [$companyId]);
        $machineId = Database::insert(
            "INSERT INTO machines (company_id, site_id, kind, code, name, type_code, phases, schedule_id, criticality, control_mode, off_threshold_kw, idle_threshold_kw)
             VALUES (?, ?, 'machine', ?, ?, ?, 1, ?, ?, 'approve', 0.005, 0.005)",
            [$companyId, (int) $siteId, $code, $o['name'] ?? $code, $o['type'] ?? 'other', $scheduleId, $o['criticality'] ?? 'normal'],
        );
    }

    $keyVersion = $existing === null ? 1 : (int) $existing['key_version'];
    $deviceId = $existing === null
        ? Database::insert(
            "INSERT INTO devices (company_id, site_id, serial, model, key_hash, key_version, is_simulated, status, installed_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, 'provisioned', UTC_TIMESTAMP())",
            [$companyId, (int) $siteId, $serial, $model, hash('sha256', DeviceSecrets::secretFor($serial, $keyVersion)), $keyVersion],
        )
        : (int) $existing['id'];

    Database::run('UPDATE device_channels SET valid_to = UTC_TIMESTAMP() WHERE device_id = ? AND channel_no = ? AND valid_to IS NULL', [$deviceId, $channel]);
    Database::run(
        "INSERT INTO device_channels (device_id, channel_no, machine_id, measurement_mode, has_relay, valid_from)
         VALUES (?, ?, ?, 'single_phase', ?, UTC_TIMESTAMP())",
        [$deviceId, $channel, (int) $machineId, isset($o['relay']) ? 1 : 0],
    );
    return ['device_id' => $deviceId, 'machine_id' => (int) $machineId, 'key_version' => $keyVersion];
});

fwrite(STDOUT, "Provisioned {$serial} ({$model}) → channel {$channel} → machine #{$result['machine_id']}" . (isset($o['relay']) ? ' with remote-STOP relay' : '') . PHP_EOL);
fwrite(STDOUT, 'Device secret (shown once, put it in the bridge/firmware config):' . PHP_EOL);
fwrite(STDOUT, '  ' . DeviceSecrets::secretFor($serial, $result['key_version']) . PHP_EOL);
