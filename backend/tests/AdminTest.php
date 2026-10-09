<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Models\User;
use EnergyFlow\Services\Auth\AuthService;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Demo\DemoSeeder;

// A customer with a team, a single-user customer, the demo company, and EnergyFlow's own staff account.
Clock::freeze(time());
$adminDemoAnchor = DemoClock::sceneAnchor('weekday_evening');
Clock::freeze($adminDemoAnchor);
$adminDemo = DemoSeeder::reset($adminDemoAnchor, 7)['company_id'];
Clock::freeze(null);

$platform = registeredOwner('staff@energyflow.test', 'EnergyFlow HQ');
Database::run('UPDATE users SET is_platform_admin = 1 WHERE email = ?', ['staff@energyflow.test']);
$teamOwner = registeredOwner('owner@kepi.test', 'Kepi Metal');
$teamCompany = (int) Database::value('SELECT company_id FROM users WHERE email = ?', ['owner@kepi.test']);
User::create($teamCompany, 'manager@kepi.test', AuthService::hash('correct-horse-battery'), 'Besa Manager', 'manager', 'sq');
User::create($teamCompany, 'viewer@kepi.test', AuthService::hash('correct-horse-battery'), 'Drin Viewer', 'viewer', 'sq');
registeredOwner('solo@buka.test', 'Furra Buka');
$userId = static fn (string $email): int => (int) Database::value('SELECT id FROM users WHERE email = ?', [$email]);

test('only platform admins open the admin API, and never a demo account', function () use ($platform, $teamOwner, $adminDemo): void {
    assertSame(401, (new Client())->get('/admin/overview')['status']);
    assertSame(403, $teamOwner->get('/admin/overview')['status'], 'a company owner is not a platform admin');
    assertSame(403, $teamOwner->get('/admin/users')['status']);
    assertSame(200, $platform->get('/admin/overview')['status']);
    assertSame(true, $platform->get('/auth/me')['body']['data']['user']['is_platform_admin']);
    assertSame(false, $teamOwner->get('/auth/me')['body']['data']['user']['is_platform_admin']);

    Database::run('UPDATE users SET is_platform_admin = 1 WHERE company_id = ?', [$adminDemo]);
    $demoOwner = new Client('10.8.1.1');
    assertSame(200, $demoOwner->post('/auth/login', ['email' => DemoSeeder::OWNER_EMAIL, 'password' => 'test-owner-password'])['status']);
    assertSame(403, $demoOwner->get('/admin/users')['status'], 'the flag never counts on a demo account');
    Database::run('UPDATE users SET is_platform_admin = 0 WHERE company_id = ?', [$adminDemo]);

    assertSame(403, $platform->call('PATCH', '/admin/users/1', ['full_name' => 'X'], [])['status'], 'writes still need the client header');
});

test('users: every company, searchable by name, e-mail or company, filtered by company and role', function () use ($platform, $teamCompany): void {
    $all = $platform->get('/admin/users');
    assertSame(200, $all['status'], json_encode($all['body']));
    assertTrue($all['body']['meta']['total'] >= 7, 'a team of 3, a solo owner, the staff account and the demo users');
    $emails = array_column($all['body']['data'], 'email');
    assertTrue(in_array('solo@buka.test', $emails, true) && in_array(DemoSeeder::GUEST_EMAIL, $emails, true));
    assertSame(false, $all['body']['data'][0]['company']['demo'], 'real customers first');

    $kepi = $platform->get('/admin/users?q=kepi')['body'];
    assertSame(3, $kepi['meta']['total']);
    assertSame(3, $kepi['data'][0]['company']['users']);
    assertSame(1, $platform->get("/admin/users?company_id={$teamCompany}&role=owner")['body']['meta']['total']);
    assertSame(0, $platform->get('/admin/users?q=' . rawurlencode('%'))['body']['meta']['total'], 'LIKE wildcards are literal');
});

test('edit: name, e-mail, role and language; disabling signs the user out until enabled again', function () use ($platform, $userId): void {
    $id = $userId('viewer@kepi.test');
    $viewer = new Client('10.8.2.1');
    assertSame(200, $viewer->post('/auth/login', ['email' => 'viewer@kepi.test', 'password' => 'correct-horse-battery'])['status']);

    $edited = $platform->patch("/admin/users/{$id}", ['full_name' => 'Arta Krasniqi', 'email' => 'Arta@Kepi.test', 'role' => 'manager', 'locale' => 'en']);
    assertSame(200, $edited['status'], json_encode($edited['body']));
    $u = $edited['body']['data'];
    assertSame(['Arta Krasniqi', 'arta@kepi.test', 'manager', 'en'], [$u['full_name'], $u['email'], $u['role'], $u['locale']]);
    assertSame('email_taken', $platform->patch("/admin/users/{$id}", ['email' => 'owner@kepi.test'])['body']['error']['fields']['email']);
    assertSame(422, $platform->patch("/admin/users/{$id}", ['role' => 'superuser'])['status']);
    assertSame(200, $viewer->get('/auth/me')['status'], 'an edit does not sign them out');

    $disabled = $platform->patch("/admin/users/{$id}", ['disabled' => true])['body']['data'];
    assertSame(true, $disabled['disabled']);
    assertSame(0, $disabled['active_sessions']);
    assertSame(401, $viewer->get('/auth/me')['status'], 'disabling signs them out');
    assertSame(401, (new Client('10.8.2.2'))->post('/auth/login', ['email' => 'arta@kepi.test', 'password' => 'correct-horse-battery'])['status']);
    assertSame(false, $platform->patch("/admin/users/{$id}", ['disabled' => false])['body']['data']['disabled']);
    assertSame(200, (new Client('10.8.2.3'))->post('/auth/login', ['email' => 'arta@kepi.test', 'password' => 'correct-horse-battery'])['status']);

    $log = Database::one("SELECT * FROM admin_actions WHERE action = 'user.update' AND target_id = ? ORDER BY id LIMIT 1", [$id]);
    assertSame('staff@energyflow.test', $log['admin_email']);
    assertSame(['from' => 'viewer', 'to' => 'manager'], json_decode((string) $log['data'], true)['changes']['role']);
});

test('a company always keeps an active owner, admins cannot lock themselves out, demo accounts are read-only', function () use ($platform, $userId): void {
    $owner = $userId('owner@kepi.test');
    assertSame('last_owner', $platform->patch("/admin/users/{$owner}", ['role' => 'admin'])['body']['error']['code']);
    assertSame('last_owner', $platform->patch("/admin/users/{$owner}", ['disabled' => true])['body']['error']['code']);
    assertSame('last_owner', $platform->call('DELETE', "/admin/users/{$owner}")['body']['error']['code']);

    $self = $userId('staff@energyflow.test');
    assertSame('cannot_disable_self', $platform->patch("/admin/users/{$self}", ['disabled' => true])['body']['error']['code']);
    assertSame('cannot_delete_self', $platform->call('DELETE', "/admin/users/{$self}")['body']['error']['code']);

    $guest = $userId(DemoSeeder::GUEST_EMAIL);
    assertSame('demo_account', $platform->patch("/admin/users/{$guest}", ['full_name' => 'Renamed'])['body']['error']['code']);
    assertSame('demo_account', $platform->call('DELETE', "/admin/users/{$guest}")['body']['error']['code']);
    assertSame(404, $platform->patch('/admin/users/999999', ['full_name' => 'Nobody'])['status']);

    // Promote someone else first; then the old owner can step down.
    $manager = $userId('manager@kepi.test');
    assertSame('owner', $platform->patch("/admin/users/{$manager}", ['role' => 'owner'])['body']['data']['role']);
    assertSame('admin', $platform->patch("/admin/users/{$owner}", ['role' => 'admin'])['body']['data']['role']);
});

test('delete: a team member goes with their own data; a company\'s only user takes the company along, only when confirmed', function () use ($platform, $teamCompany, $userId): void {
    $member = $userId('arta@kepi.test');
    Database::run("INSERT INTO ai_conversations (company_id, user_id, title, created_at, updated_at) VALUES (?, ?, 'Private chat', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$teamCompany, $member]);
    $deleted = $platform->call('DELETE', "/admin/users/{$member}");
    assertSame(200, $deleted['status'], json_encode($deleted['body']));
    assertSame(['deleted' => true, 'company_deleted' => false], $deleted['body']['data']);
    assertSame(null, Database::value('SELECT id FROM users WHERE id = ?', [$member]));
    assertSame(0, (int) Database::value('SELECT COUNT(*) FROM ai_conversations WHERE user_id = ?', [$member]));
    assertSame(2, (int) Database::value('SELECT COUNT(*) FROM users WHERE company_id = ?', [$teamCompany]), 'the company and its other users stay');

    $solo = $userId('solo@buka.test');
    $soloCompany = (int) Database::value('SELECT company_id FROM users WHERE id = ?', [$solo]);
    $refused = $platform->call('DELETE', "/admin/users/{$solo}");
    assertSame(409, $refused['status']);
    assertSame('company_would_be_empty', $refused['body']['error']['code']);
    assertTrue(Database::value('SELECT id FROM companies WHERE id = ?', [$soloCompany]) !== null, 'nothing is deleted without the confirmation');

    $gone = $platform->call('DELETE', "/admin/users/{$solo}?with_company=1");
    assertSame(['deleted' => true, 'company_deleted' => true], $gone['body']['data']);
    assertSame(null, Database::value('SELECT id FROM companies WHERE id = ?', [$soloCompany]));
    foreach (['sites', 'tariff_plans', 'calendar_days', 'emission_factors'] as $table) {
        assertSame(0, (int) Database::value("SELECT COUNT(*) FROM {$table} WHERE company_id = ?", [$soloCompany]), "{$table} purged");
    }
    $log = Database::one("SELECT * FROM admin_actions WHERE action = 'company.delete' ORDER BY id DESC LIMIT 1");
    assertSame('solo@buka.test', json_decode((string) $log['data'], true)['email']);
});

test('overview and companies: counts straight from the database, the demo company kept apart', function () use ($platform, $teamCompany): void {
    $o = $platform->get('/admin/overview')['body']['data'];
    assertSame((int) Database::value('SELECT COUNT(*) FROM companies WHERE is_demo = 0'), $o['companies']['total']);
    assertSame((int) Database::value('SELECT COUNT(*) FROM users u JOIN companies c ON c.id = u.company_id WHERE c.is_demo = 0'), $o['users']['total']);
    assertSame(12, count($o['signups']));
    assertTrue(array_sum(array_column($o['signups'], 'companies')) >= 2, 'this run\'s sign-ups fall in the last 12 weeks');
    assertTrue($o['demo']['sessions_7d'] >= 1, 'the demo sign-in above');
    assertTrue(str_starts_with((string) $o['system']['migration'], '0017'));
    assertTrue(count($o['admin_activity']) >= 3);

    $companies = $platform->get('/admin/companies')['body']['data'];
    $kepi = array_values(array_filter($companies, static fn (array $c): bool => $c['id'] === $teamCompany))[0];
    assertSame(2, $kepi['users']);
    assertSame('manager@kepi.test', $kepi['owner_email']);
    assertSame(false, $kepi['demo']);
    assertSame(true, end($companies)['demo'], 'the demo company is listed last');
    assertSame(1, count($platform->get('/admin/companies?q=Kepi')['body']['data']));
});

test('an admin can send a password reset link, but not to a disabled or demo account', function () use ($platform, $userId): void {
    $owner = $userId('manager@kepi.test');
    $log = (string) tempnam(sys_get_temp_dir(), 'ef-admin');
    $previous = ini_set('error_log', $log);
    try {
        $sent = $platform->post("/admin/users/{$owner}/password-reset");
    } finally {
        ini_set('error_log', (string) $previous);
    }
    $logged = (string) file_get_contents($log);
    unlink($log);
    assertSame(200, $sent['status'], json_encode($sent['body']));
    assertTrue(str_contains($logged, 'reset-password?token='), 'the link goes to the user, never to the admin');
    assertTrue(!isset($sent['body']['data']['token']) && !str_contains(json_encode($sent['body']), 'token='));
    assertSame('demo_account', $platform->post('/admin/users/' . $userId(DemoSeeder::OWNER_EMAIL) . '/password-reset')['body']['error']['code']);

    $former = $userId('owner@kepi.test'); // stepped down to admin above, so it can be disabled
    assertSame(true, $platform->patch("/admin/users/{$former}", ['disabled' => true])['body']['data']['disabled']);
    assertSame('user_disabled', $platform->post("/admin/users/{$former}/password-reset")['body']['error']['code']);
});
