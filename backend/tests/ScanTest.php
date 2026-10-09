<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Analytics\EnergyQuery;
use EnergyFlow\Services\Analytics\OverviewService;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Demo\DemoSeeder;
use EnergyFlow\Services\Scan\ScanCheck;
use EnergyFlow\Services\Scan\ScanReader;
use EnergyFlow\Services\Tariff\TariffBook;

// Enough history that last month is fully metered.
Clock::freeze(time());
$scanAnchor = DemoClock::sceneAnchor('weekday_evening');
Clock::freeze($scanAnchor);
$scanDemo = DemoSeeder::reset($scanAnchor, 45)['company_id'];
$scanOwner = new Client('10.9.5.1');
assertSame(200, $scanOwner->post('/auth/login', ['email' => DemoSeeder::OWNER_EMAIL, 'password' => 'test-owner-password'])['status']);
$scanGuest = new Client('10.9.5.2');
$scanGuest->post('/auth/demo');

/** Last month's bill exactly as the official tariff and the meters say it should be. */
$genuineBill = static function () use ($scanDemo): array {
    $time = LocalTime::forCompany($scanDemo);
    $from = $time->startOfMonth($time->startOfMonth(Clock::now($scanDemo)) - 86400);
    $to = $time->startOfMonth($from + 32 * 86400);
    $tariff = TariffBook::forCompany($scanDemo);
    $incomer = OverviewService::incomerId($scanDemo);
    $site = EnergyQuery::site(EnergyQuery::byMachine($scanDemo, $from, $to, null, $tariff), $incomer);
    $high = round($site['kwh_high']);
    $low = round($site['kwh_low']);
    $bill = $tariff->bill($high, $low, EnergyQuery::peakKw($scanDemo, $from, $to, $incomer), $site['kvarh'] * ($high + $low) / $site['kwh']);
    $vat = round($bill['subtotal'] * 0.08, 2);
    return [
        'supplier' => 'KESCO Sh.A.', 'customer_number' => 'TEST-1', 'issue_date' => $time->date($to + 4 * 86400 > Clock::now($scanDemo) ? Clock::now($scanDemo) : $to + 4 * 86400),
        'period_start' => $time->date($from), 'period_end' => $time->date($to - 1), 'due_date' => null,
        'kwh_high' => $high, 'kwh_low' => $low, 'kwh_total' => $high + $low, 'meter_start' => 100000.0, 'meter_end' => 100000.0 + $high + $low,
        'net_eur' => $bill['subtotal'], 'vat_eur' => $vat, 'total_eur' => round($bill['subtotal'] + $vat, 2), 'previous_debt_eur' => null,
    ];
};
$keys = static fn (array $result, string $status): array => array_column(array_filter($result['checks'], static fn (array $c): bool => $c['status'] === $status), 'key');

test('a bill that matches the tariff and the meters is consistent', function () use ($scanOwner, $genuineBill, $keys): void {
    $response = $scanOwner->post('/scan/check', ['kind' => 'bill', 'fields' => $genuineBill()]);
    assertSame(200, $response['status'], json_encode($response['body']));
    $r = $response['body']['data'];
    assertSame('consistent', $r['verdict'], json_encode($r['checks']));
    foreach (['supplier_universal', 'kwh_sum', 'meter_diff_ok', 'money_sum', 'vat_rate', 'metered_match', 'tariff_match'] as $key) {
        assertTrue(in_array($key, $keys($r, 'pass'), true), "{$key} passes");
    }
});

test('a bill whose own numbers don\'t add up is likely fake; tariff or meter gaps alone only ask for a check', function () use ($scanOwner, $genuineBill, $keys): void {
    $forged = ['supplier' => 'Energjia Plus Sh.p.k.', 'kwh_high' => 9000.0, 'net_eur' => 900.0, 'vat_eur' => 162.0, 'total_eur' => 1150.0] + $genuineBill();
    $r = $scanOwner->post('/scan/check', ['kind' => 'bill', 'fields' => $forged])['body']['data'];
    assertSame('likely_fake', $r['verdict']);
    foreach (['kwh_sum_wrong', 'money_sum_wrong', 'vat_rate_wrong'] as $key) {
        assertTrue(in_array($key, $keys($r, 'fail'), true), "{$key} fails");
    }
    assertTrue(in_array('supplier_unknown', $keys($r, 'warn'), true));

    // A genuine-looking bill from KESCO that charges 30 % more than the tariff: check, not fake.
    $bill = $genuineBill();
    $net = round($bill['net_eur'] * 1.3, 2);
    $overcharged = ['net_eur' => $net, 'vat_eur' => round($net * 0.08, 2), 'total_eur' => round($net * 1.08, 2)] + $bill;
    $r = $scanOwner->post('/scan/check', ['kind' => 'bill', 'fields' => $overcharged])['body']['data'];
    assertSame('check', $r['verdict']);
    assertTrue(in_array('tariff_diff_large', $keys($r, 'fail'), true));
});

test('bill details: CT-meter multipliers, earlier debt, licensed suppliers, impossible dates', function () use ($scanOwner, $genuineBill, $keys): void {
    $bill = $genuineBill();
    $ct = ['meter_start' => 5000.0, 'meter_end' => 5000.0 + $bill['kwh_total'] / 40] + $bill;
    assertTrue(in_array('meter_multiplier', $keys($scanOwner->post('/scan/check', ['kind' => 'bill', 'fields' => $ct])['body']['data'], 'pass'), true));

    $debt = ['previous_debt_eur' => 120.5, 'total_eur' => round($bill['total_eur'] + 120.5, 2)] + $bill;
    assertTrue(in_array('money_sum_with_debt', $keys($scanOwner->post('/scan/check', ['kind' => 'bill', 'fields' => $debt])['body']['data'], 'pass'), true));

    $north = ['supplier' => 'Elektrosever d.o.o.'] + $bill;
    $r = $scanOwner->post('/scan/check', ['kind' => 'bill', 'fields' => $north])['body']['data'];
    assertTrue(in_array('supplier_licensed', $keys($r, 'pass'), true));

    $future = ['issue_date' => '2099-01-05'] + $bill;
    assertTrue(in_array('date_in_future', $keys($scanOwner->post('/scan/check', ['kind' => 'bill', 'fields' => $future])['body']['data'], 'fail'), true));
});

test('saving a bill keeps the verdict and compares it with the meters', function () use ($scanOwner, $scanGuest, $genuineBill): void {
    assertSame(403, $scanGuest->post('/scan/save', ['kind' => 'bill', 'fields' => $genuineBill(), 'source' => 'scan'])['status'], 'viewers check but cannot save');
    $saved = $scanOwner->post('/scan/save', ['kind' => 'bill', 'fields' => $genuineBill(), 'source' => 'scan']);
    assertSame(201, $saved['status'], json_encode($saved['body']));
    $bill = $saved['body']['data']['saved'];
    assertSame('consistent', $bill['verdict']);
    assertTrue($bill['coverage'] !== null && abs($bill['coverage'] - 1.0) < 0.01, 'the incomer measured what was billed');
    assertSame(1, count($scanOwner->get('/bills')['body']['data']));
    $again = $scanOwner->post('/scan/check', ['kind' => 'bill', 'fields' => $genuineBill()])['body']['data'];
    assertTrue(in_array('already_saved', array_column($again['checks'], 'key'), true));
    assertSame(204, $scanOwner->call('DELETE', '/bills/' . $bill['id'])['status']);
});

test('a fuel receipt becomes Scope 1 with the DESNZ 2025 factor and fills the VSME fuels datapoint', function () use ($scanOwner, $scanDemo): void {
    $fields = ['vendor' => 'Test station', 'date' => LocalTime::forCompany($scanDemo)->date(Clock::now($scanDemo)), 'fuel' => 'Eurodiesel', 'litres' => '40,0',
        'unit_price_eur' => '1.35', 'total_eur' => '54.00', 'vat_eur' => null];
    $check = $scanOwner->post('/scan/check', ['kind' => 'fuel', 'fields' => $fields])['body']['data'];
    assertSame('ok', $check['verdict'], json_encode($check['checks']));
    assertSame('diesel', $check['derived']['fuel']);
    assertSame(106.46, $check['derived']['co2_kg'], '40 l × 2.66155');
    assertSame(397.1, $check['derived']['energy_kwh'], '40 l × 9.9282 kWh/l');

    $saved = $scanOwner->post('/scan/save', ['kind' => 'fuel', 'fields' => $fields, 'source' => 'scan']);
    assertSame(201, $saved['status'], json_encode($saved['body']));
    $carbon = $scanOwner->get('/carbon/summary?period=mtd')['body']['data'];
    assertSame('manual', $carbon['scope1']['status']);
    assertSame(106.46, $carbon['scope1']['kg']);
    $rows = array_column($scanOwner->get('/esg/vsme-b3')['body']['data']['rows'], null, 'key');
    assertSame('manual', $rows['fuels']['status']);
    assertTrue(abs($rows['fuels']['value'] - 0.397) < 0.001, 'fuels in MWh');
    $id = $saved['body']['data']['saved']['id'];
    assertSame(204, $scanOwner->call('DELETE', "/fuel-records/{$id}")['status']);
    assertSame('missing', $scanOwner->get('/carbon/summary?period=mtd')['body']['data']['scope1']['status']);
});

test('a nameplate updates the machine; meter readings must not run backwards; labels get a yearly cost', function () use ($scanOwner, $scanDemo, $keys): void {
    $cmp = (int) Database::value("SELECT id FROM machines WHERE company_id = ? AND code = 'CMP-01'", [$scanDemo]);
    $plate = ['manufacturer' => 'TestCo', 'model' => 'SC-11', 'rated_power_hp' => 15, 'voltage_v' => 400, 'current_a' => 20.5, 'phases' => 3, 'power_factor' => 0.86, 'efficiency_class' => 'IE2'];
    $check = $scanOwner->post('/scan/check', ['kind' => 'nameplate', 'fields' => $plate, 'machine_id' => $cmp])['body']['data'];
    assertSame(11.19, $check['derived']['rated_kw'], '15 HP × 0.7457');
    assertTrue(in_array('ie_class_low', $keys($check, 'info'), true));
    assertSame(422, $scanOwner->post('/scan/save', ['kind' => 'nameplate', 'fields' => $plate])['status'], 'a nameplate needs a machine');
    $saved = $scanOwner->post('/scan/save', ['kind' => 'nameplate', 'fields' => $plate, 'machine_id' => $cmp, 'source' => 'scan']);
    assertSame(201, $saved['status'], json_encode($saved['body']));
    assertSame('11.19', (string) Database::value('SELECT rated_power_kw FROM machines WHERE id = ?', [$cmp]));
    assertSame('IE2', json_decode((string) Database::value('SELECT nameplate FROM machines WHERE id = ?', [$cmp]), true)['efficiency_class']);

    $first = $scanOwner->post('/scan/save', ['kind' => 'meter', 'fields' => ['meter_serial' => 'M-1', 'reading_kwh' => 5000, 'register' => '1.8.0']]);
    assertSame(201, $first['status'], json_encode($first['body']));
    assertSame('total', $first['body']['data']['saved']['register']);
    assertSame(409, $scanOwner->post('/scan/save', ['kind' => 'meter', 'fields' => ['meter_serial' => 'M-1', 'reading_kwh' => 4000, 'register' => '1.8.0']])['status']);

    $label = $scanOwner->post('/scan/check', ['kind' => 'label', 'fields' => ['energy_class' => 'C', 'kwh_per_year' => 220, 'consumption_unit' => 'kWh/annum']])['body']['data'];
    assertTrue($label['derived']['eur'] > 0 && $label['derived']['co2_kg'] === round(220 * 0.90094, 1));
    assertSame(400, $scanOwner->post('/scan/save', ['kind' => 'label', 'fields' => ['energy_class' => 'C']])['status'], 'labels are information only');
});

test('reading photos: input is validated, and without the AI service the answer says so', function () use ($scanOwner): void {
    assertSame(422, $scanOwner->post('/scan/read', ['kind' => 'bill', 'image' => 'not-an-image'])['status']);
    assertSame(422, $scanOwner->post('/scan/read', ['kind' => 'passport', 'image' => 'data:image/jpeg;base64,AAAA'])['status']);
    $response = $scanOwner->post('/scan/read', ['kind' => 'bill', 'image' => 'data:image/jpeg;base64,' . base64_encode('x')]);
    assertSame(503, $response['status']);
    assertSame('scan_unavailable', $response['body']['error']['code']);

    // What the model returns is typed here, in either number style.
    $fields = ScanReader::normalise('bill', ['net_eur' => '1.561,93', 'vat_eur' => '124,95', 'total_eur' => '1,686.88 €', 'issue_date' => '05.10.2026', 'kwh_total' => '15 679 kWh']);
    assertSame(1561.93, $fields['net_eur']);
    assertSame(124.95, $fields['vat_eur']);
    assertSame(1686.88, $fields['total_eur']);
    assertSame('2026-10-05', $fields['issue_date']);
    assertSame(15679.0, $fields['kwh_total']);
    assertSame('T2', ScanCheck::register('1.8.2'));
    assertSame('gas_oil', ScanCheck::fuelType('Gas oil'));
    assertSame('petrol', ScanCheck::fuelType('Benzinë 95'));
    Clock::freeze(null);
});
