<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Demo\DemoSeeder;

// The demo company is shared by every visitor: nobody can rename its accounts, change their
// language or password, or change its tariff. Real companies' users still can.
Clock::freeze(time());
$guardAnchor = DemoClock::sceneAnchor('weekday_evening');
Clock::freeze($guardAnchor);
$guardDemo = DemoSeeder::reset($guardAnchor, 7)['company_id'];
Clock::freeze(null);

test('the shared demo guest keeps its name, language and password, whoever signs in', function () use ($guardDemo): void {
    $visitor = new Client('10.9.1.1');
    assertSame(200, $visitor->post('/auth/demo')['status']);
    foreach ([['full_name' => 'Someone else'], ['locale' => 'en'], ['current_password' => 'x', 'new_password' => 'another-long-password']] as $change) {
        $result = $visitor->patch('/auth/me', $change);
        assertSame(409, $result['status']);
        assertSame('demo_account', $result['body']['error']['code']);
    }
    $guest = Database::one("SELECT full_name, locale FROM users WHERE company_id = ? AND role = 'viewer'", [$guardDemo]);
    assertSame(['full_name' => 'Demo guest', 'locale' => 'sq'], $guest);
});

test('the demo owner cannot change the profile or the tariff either; a real account can', function (): void {
    $demoOwner = new Client('10.9.1.2');
    assertSame(200, $demoOwner->post('/auth/login', ['email' => DemoSeeder::OWNER_EMAIL, 'password' => 'test-owner-password'])['status']);
    assertSame(409, $demoOwner->patch('/auth/me', ['full_name' => 'Renamed'])['status']);
    $categoryTwo = (int) Database::value("SELECT id FROM tariff_plans WHERE company_id IS NULL AND category = 'commercial_cat_2'");
    $refused = $demoOwner->patch('/company/tariff', ['template_id' => $categoryTwo]);
    assertSame(409, $refused['status']);
    assertSame('demo_account', $refused['body']['error']['code']);
    assertSame('commercial_cat_1', $demoOwner->get('/company/tariff')['body']['data']['current']['category']);

    $owner = registeredOwner('profile-owner@example.com', 'Real SME');
    $renamed = $owner->patch('/auth/me', ['full_name' => 'Arta Gashi', 'locale' => 'en']);
    assertSame(200, $renamed['status']);
    assertSame('Arta Gashi', $renamed['body']['data']['user']['full_name']);
});
