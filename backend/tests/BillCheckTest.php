<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;

// The landing page's bill check: no sign-in, published template tariffs only, priced through
// TariffBook::bill() like the dashboard. 50 000 kWh with 30 000 kVArh is cos φ ≈ 0.857.

test('bill check: reactive energy above cos φ 0.95 and engaged power at the ERO Category I rates', function (): void {
    $result = (new Client('10.9.0.1'))->get('/public/bill-check?category=commercial_cat_1&kwh=50000&kvarh=30000&kw=120');
    assertSame(200, $result['status']);
    $data = $result['body']['data'];
    $allowed = 50000 * tan(acos(0.95));
    assertSame(round(50000 / hypot(50000, 30000), 3), $data['power_factor']);
    assertSame(0.95, $data['reactive']['threshold']);
    assertSame(round($allowed, 1), $data['reactive']['allowed_kvarh']);
    assertSame(round(30000 - $allowed, 1), $data['reactive']['billed_kvarh']);
    assertSame(0.0087, $data['reactive']['rate_eur_kvarh']);
    $month = round((30000 - $allowed) * 0.0087, 2);
    assertSame($month, $data['reactive']['month_eur']);
    assertSame(round($month * 12, 2), $data['reactive']['year_eur']);
    assertSame(3.87, $data['engaged_power']['rate_eur_kw_month']);
    assertSame(464.4, $data['engaged_power']['month_eur']);
    assertSame(5572.8, $data['engaged_power']['year_eur']);
    assertSame(46.44, $data['engaged_power']['per_kw_year_eur']);
    assertSame('commercial_cat_1', $data['tariff']['category']);
});

test('bill check: no reactive charge at cos φ 0.95 or better', function (): void {
    $data = (new Client('10.9.0.2'))->get('/public/bill-check?category=commercial_cat_1&kwh=10000&kvarh=3000&kw=0')['body']['data'];
    assertSame(0.0, $data['reactive']['billed_kvarh']);
    assertSame(0.0, $data['reactive']['month_eur']);
    assertSame(0.0, $data['engaged_power']['kw']);
});

test('bill check: Category II is billed for neither engaged power nor reactive energy', function (): void {
    $data = (new Client('10.9.0.3'))->get('/public/bill-check?category=commercial_cat_2&kwh=8000&kvarh=6000&kw=40')['body']['data'];
    assertSame(null, $data['reactive']);
    assertSame(null, $data['engaged_power']);
    assertSame(0.8, $data['power_factor']);
});

test('bill check: validates input and never prices a company\'s own tariff', function (): void {
    $client = new Client('10.9.0.4');
    assertSame(422, $client->get('/public/bill-check?category=commercial_cat_1&kwh=-5&kvarh=0&kw=0')['status']);
    $missing = $client->get('/public/bill-check?category=commercial_cat_1&kwh=100');
    assertSame(422, $missing['status']);
    assertSame(['kvarh' => 'required', 'kw' => 'required'], $missing['body']['error']['fields']);
    assertSame(422, $client->get('/public/bill-check?category=commercial_cat_1&kwh=100&kvarh=0&kw=abc')['status']);
    assertSame(404, $client->get('/public/bill-check?category=nope&kwh=100&kvarh=0&kw=0')['status']);
    Database::run(
        "INSERT INTO tariff_plans (company_id, name, mode, category, vat_rate, valid_from, source_note)
         VALUES (1, 'Private contract', 'market_flat', 'private_contract', 0.08, '2026-01-01', 'Test')",
    );
    assertSame(404, $client->get('/public/bill-check?category=private_contract&kwh=100&kvarh=0&kw=0')['status']);
});
