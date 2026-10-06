<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Calendar\ScheduleBook;
use EnergyFlow\Services\Simulation\Noise;
use EnergyFlow\Services\Tariff\TariffBook;

$local = static fn (string $datetime): int => (new DateTimeImmutable($datetime, new DateTimeZone('Europe/Belgrade')))->getTimestamp();

test('noise is deterministic, bounded and stays an integer hash (no float overflow)', function (): void {
    assertSame(Noise::uniform(1, 2, 3), Noise::uniform(1, 2, 3));
    assertTrue(Noise::uniform(1, 2, 3) !== Noise::uniform(1, 2, 4), 'different inputs differ');
    for ($i = 0; $i < 2000; $i++) {
        $u = Noise::uniform(4242, $i, PHP_INT_MAX & 0xFFFFFFFF);
        $s = Noise::smooth(7, $i * 37.5, 600);
        assertTrue($u >= 0 && $u < 1, "uniform in [0,1): {$u}");
        assertTrue($s >= -1 && $s <= 1, "smooth in [-1,1]: {$s}");
    }
});

test('local time handles Kosovo summer and winter time', function () use ($local): void {
    $time = new LocalTime('Europe/Belgrade');
    $summer = $local('2026-07-15 21:40');
    $winter = $local('2026-12-15 21:40');
    assertSame(21 * 60 + 40, $time->minuteOfDay($summer));
    assertSame(21 * 60 + 40, $time->minuteOfDay($winter));
    assertSame(3, $time->dayOfWeek($summer), '15 July 2026 is a Wednesday');
    assertSame('2026-12-15', $time->date($winter));
});

test('schedules: shifts, Saturday half-day, Kosovo public holidays, overnight windows and HOLD overrides', function () use ($local): void {
    $time = new LocalTime('Europe/Belgrade');
    $windows = [
        1 => [1 => [[420, 1260]], 2 => [[420, 1260]], 3 => [[420, 1260]], 4 => [[420, 1260]], 5 => [[420, 1260]], 6 => [[420, 780]]],
        2 => [5 => [[1320, 360]]], // a night shift Friday 22:00 → Saturday 06:00
    ];
    $book = new ScheduleBook($time, $windows, ['2026-05-01' => true], []);

    assertTrue($book->isScheduled(1, $local('2026-10-06 10:00')), 'Tuesday morning');
    assertTrue(!$book->isScheduled(1, $local('2026-10-06 21:40')), 'after the 21:00 shift end');
    assertTrue($book->isScheduled(1, $local('2026-10-10 12:00')), 'Saturday 12:00');
    assertTrue(!$book->isScheduled(1, $local('2026-10-10 14:00')), 'Saturday after 13:00');
    assertTrue(!$book->isScheduled(1, $local('2026-10-11 10:00')), 'Sunday');
    assertTrue(!$book->isScheduled(1, $local('2026-05-01 10:00')), 'Labour Day holiday');
    assertTrue($book->isScheduled(2, $local('2026-10-10 03:00')), 'overnight window continues after midnight');

    $book->addOverride(5, $local('2026-10-06 21:00'), $local('2026-10-06 23:00'));
    assertTrue($book->isScheduled(1, $local('2026-10-06 21:40'), 5), 'HOLD makes overtime legitimate for that machine');
    assertTrue(!$book->isScheduled(1, $local('2026-10-06 21:40'), 6), 'but not for other machines');
    assertSame($local('2026-10-06 21:00'), $book->lastScheduledEnd(1, $local('2026-10-06 21:40')));
});

test('KESCO Category I tariff (ERO V_2703_2025): seasonal day/night windows and rates', function () use ($local): void {
    $planId = (int) Database::value("SELECT id FROM tariff_plans WHERE company_id IS NULL AND category = 'commercial_cat_1'");
    $tariff = TariffBook::forPlan($planId);

    // Winter (1 Oct – 31 Mar): high 07:00–22:00.
    assertSame('high', $tariff->period($local('2026-10-06 07:00')));
    assertSame('high', $tariff->period($local('2026-10-06 21:59')));
    assertSame('low', $tariff->period($local('2026-10-06 22:00')));
    assertSame('low', $tariff->period($local('2026-10-06 06:59')));
    // Summer (1 Apr – 30 Sep): high 08:00–23:00.
    assertSame('low', $tariff->period($local('2026-07-15 07:30')));
    assertSame('high', $tariff->period($local('2026-07-15 22:30')));

    assertSame(0.087, $tariff->marginalRate($local('2026-10-06 12:00')));
    assertSame(0.0646, $tariff->marginalRate($local('2026-10-06 23:00')));
});

test('monthly bill includes energy, engaged power, reactive excess above cos φ 0.95, fixed fee and VAT', function (): void {
    $planId = (int) Database::value("SELECT id FROM tariff_plans WHERE company_id IS NULL AND category = 'commercial_cat_1'");
    $bill = TariffBook::forPlan($planId)->bill(10000, 2000, 50, 6000);
    $lines = array_column($bill['lines'], 'amount', 'key');
    assertSame(870.0, $lines['energy_high']);
    assertSame(129.2, $lines['energy_low']);
    assertSame(193.5, $lines['engaged_power']);
    // Allowed reactive = 12,000 kWh × tan(acos 0.95) ≈ 3,944 kVArh → excess ≈ 2,056 × €0.0087
    assertTrue(abs($lines['reactive_energy'] - 17.89) < 0.05, 'reactive charge ' . $lines['reactive_energy']);
    assertSame(3.34, $lines['fixed_fee']);
    assertSame(round($bill['subtotal'] * 0.08, 2), $bill['vat']);
});
