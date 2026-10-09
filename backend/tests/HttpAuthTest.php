<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;

test('health reports the database and migration version', function (): void {
    $result = (new Client())->get('/health');
    assertSame(200, $result['status']);
    assertSame('ok', $result['body']['data']['database']);
    assertTrue(str_starts_with((string) $result['body']['data']['migration'], '00'), 'migration version present');
});

test('unknown route is 404, wrong method is 405', function (): void {
    $client = new Client();
    assertSame(404, $client->get('/nope')['status']);
    assertSame(405, $client->call('DELETE', '/health')['status']);
});

test('writes without the X-EF-Client header are rejected (CSRF)', function (): void {
    $result = (new Client())->post('/auth/login', ['email' => 'a@b.co', 'password' => 'x'], headers: []);
    assertSame(403, $result['status']);
    assertSame('missing_client_header', $result['body']['error']['code']);
});

test('register validates input with per-field codes', function (): void {
    $result = (new Client())->post('/auth/register', ['email' => 'not-an-email', 'password' => 'short']);
    assertSame(422, $result['status']);
    assertSame('invalid_email', $result['body']['error']['fields']['email']);
    assertSame('min_length:10', $result['body']['error']['fields']['password']);
    assertSame('required', $result['body']['error']['fields']['company_name']);
});

test('register creates company + owner, keeps UTF-8, assigns the Kosovo grid factor and signs in', function (): void {
    $client = registeredOwner('arta@example.com', 'Ylli Plast');
    $me = $client->get('/auth/me');
    assertSame(200, $me['status']);
    assertSame('owner', $me['body']['data']['user']['role']);
    assertSame('Prishtinë', $me['body']['data']['company']['city']);
    assertSame('Europe/Belgrade', $me['body']['data']['company']['timezone']);
    $factor = Database::value('SELECT value FROM emission_factors WHERE id = ?', [$me['body']['data']['company']['emission_factor_id']]);
    assertSame('0.900940', (string) $factor);
    assertTrue(str_ends_with((string) $me['body']['data']['user']['created_at'], 'Z'), 'timestamps are ISO UTC');
});

test('the same e-mail cannot register twice (case-insensitive)', function (): void {
    registeredOwner('dupe@example.com');
    $result = (new Client())->post('/auth/register', [
        'full_name' => 'Someone', 'email' => 'DUPE@example.com', 'password' => 'another-long-password', 'company_name' => 'Other',
    ]);
    assertSame(422, $result['status']);
    assertSame('email_taken', $result['body']['error']['fields']['email']);
});

test('the database stores only a hash of the session token', function (): void {
    $client = registeredOwner('hash@example.com');
    $token = $client->cookies['ef_session'];
    assertSame(null, Database::value('SELECT id FROM sessions WHERE token_hash = ?', [$token]));
    assertTrue(Database::value('SELECT id FROM sessions WHERE token_hash = ?', [hash('sha256', $token)]) !== null);
});

test('logout revokes the session server-side', function (): void {
    $client = registeredOwner('logout@example.com');
    $stolen = $client->cookies['ef_session'];
    assertSame(200, $client->post('/auth/logout')['status']);
    $attacker = new Client();
    $attacker->cookies['ef_session'] = $stolen;
    assertSame(401, $attacker->get('/auth/me')['status']);
});

test('login rejects a wrong password without revealing which part was wrong', function (): void {
    registeredOwner('login@example.com');
    $unknown = (new Client())->post('/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever-123']);
    $wrong = (new Client())->post('/auth/login', ['email' => 'login@example.com', 'password' => 'wrong-password']);
    assertSame(401, $unknown['status']);
    assertSame(401, $wrong['status']);
    assertSame($unknown['body']['error']['code'], $wrong['body']['error']['code']);

    $client = new Client();
    assertSame(200, $client->post('/auth/login', ['email' => 'LOGIN@example.com', 'password' => 'correct-horse-battery'])['status']);
    assertSame(200, $client->get('/auth/me')['status']);
});

test('login is rate-limited after repeated failures', function (): void {
    registeredOwner('brute@example.com');
    $attacker = new Client('10.9.9.9');
    for ($i = 0; $i < 5; $i++) {
        assertSame(401, $attacker->post('/auth/login', ['email' => 'brute@example.com', 'password' => "guess-{$i}-xxxx"])['status']);
    }
    assertSame(429, $attacker->post('/auth/login', ['email' => 'brute@example.com', 'password' => 'correct-horse-battery'])['status']);
});

test('changing the password signs out every other session', function (): void {
    $laptop = registeredOwner('pw@example.com');
    $phone = new Client();
    assertSame(200, $phone->post('/auth/login', ['email' => 'pw@example.com', 'password' => 'correct-horse-battery'])['status']);

    $bad = $laptop->patch('/auth/me', ['current_password' => 'nope', 'new_password' => 'a-brand-new-password']);
    assertSame('incorrect_password', $bad['body']['error']['fields']['current_password']);

    assertSame(200, $laptop->patch('/auth/me', ['current_password' => 'correct-horse-battery', 'new_password' => 'a-brand-new-password'])['status']);
    assertSame(200, $laptop->get('/auth/me')['status']);
    assertSame(401, $phone->get('/auth/me')['status']);
});

/** Requests a reset link and returns the token from the local delivery log (no mail key in tests). */
function requestResetToken(string $email, string $ip): ?string
{
    $log = (string) tempnam(sys_get_temp_dir(), 'ef-reset');
    $previous = ini_set('error_log', $log);
    try {
        $result = (new Client($ip))->post('/auth/password/forgot', ['email' => $email]);
    } finally {
        ini_set('error_log', (string) $previous);
    }
    assertSame(200, $result['status'], json_encode($result['body']));
    $logged = (string) file_get_contents($log);
    unlink($log);
    return preg_match('/reset-password\?token=([a-f0-9]{64})/', $logged, $m) ? $m[1] : null;
}

test('forgot password: the same answer for any e-mail, one hashed one-hour link, limited per address', function (): void {
    registeredOwner('reset@example.com');
    $unknown = (new Client('10.4.4.1'))->post('/auth/password/forgot', ['email' => 'nobody@example.com']);
    assertSame(200, $unknown['status']);
    assertSame(null, requestResetToken('nobody@example.com', '10.4.4.1'), 'nothing is sent for an unknown address');

    $token = requestResetToken('RESET@example.com', '10.4.4.2');
    assertTrue($token !== null, 'a known address gets a link');
    $rows = Database::all(
        'SELECT r.token_hash, TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), r.expires_at) AS minutes FROM password_resets r
           JOIN users u ON u.id = r.user_id WHERE u.email = ? AND r.used_at IS NULL',
        ['reset@example.com'],
    );
    assertSame(1, count($rows));
    assertSame(hash('sha256', $token), $rows[0]['token_hash'], 'only the hash is stored');
    assertTrue((int) $rows[0]['minutes'] >= 58 && (int) $rows[0]['minutes'] <= 60, 'expires in one hour');

    $newer = requestResetToken('reset@example.com', '10.4.4.3');
    assertSame(1, (int) Database::value('SELECT COUNT(*) FROM password_resets r JOIN users u ON u.id = r.user_id WHERE u.email = ? AND r.used_at IS NULL', ['reset@example.com']), 'a new link replaces the old one');
    assertSame(400, (new Client('10.4.4.4'))->post('/auth/password/reset', ['token' => $token, 'password' => 'a-brand-new-password'])['status'], 'the replaced link no longer works');
    assertTrue($newer !== null);

    assertSame(200, (new Client('10.4.4.5'))->post('/auth/password/forgot', ['email' => 'reset@example.com'])['status']);
    assertSame(429, (new Client('10.4.4.6'))->post('/auth/password/forgot', ['email' => 'reset@example.com'])['status'], 'a 4th request within the hour, from any IP');
});

test('a reset link sets the new password once and signs every session out', function (): void {
    $signedIn = registeredOwner('reset2@example.com');
    $token = requestResetToken('reset2@example.com', '10.4.5.1');
    $browser = new Client('10.4.5.2');

    assertSame(422, $browser->post('/auth/password/reset', ['token' => $token, 'password' => 'short'])['status']);
    assertSame(400, $browser->post('/auth/password/reset', ['token' => str_repeat('0', 64), 'password' => 'a-brand-new-password'])['status']);
    assertSame(200, $browser->post('/auth/password/reset', ['token' => $token, 'password' => 'a-brand-new-password'])['status']);
    assertSame(401, $signedIn->get('/auth/me')['status'], 'existing sessions are revoked');

    $again = $browser->post('/auth/password/reset', ['token' => $token, 'password' => 'another-new-password']);
    assertSame(400, $again['status'], 'a link works once');
    assertSame('invalid_reset_token', $again['body']['error']['code']);
    assertSame(401, (new Client('10.4.5.3'))->post('/auth/login', ['email' => 'reset2@example.com', 'password' => 'correct-horse-battery'])['status']);
    assertSame(200, (new Client('10.4.5.3'))->post('/auth/login', ['email' => 'reset2@example.com', 'password' => 'a-brand-new-password'])['status']);

    // An expired link is refused even if it was never used.
    $expired = bin2hex(random_bytes(32));
    Database::run(
        "INSERT INTO password_resets (user_id, token_hash, expires_at) SELECT id, ?, UTC_TIMESTAMP() - INTERVAL 1 MINUTE FROM users WHERE email = 'reset2@example.com'",
        [hash('sha256', $expired)],
    );
    assertSame(400, $browser->post('/auth/password/reset', ['token' => $expired, 'password' => 'a-third-new-password'])['status']);
});

test('each user only ever sees their own company', function (): void {
    $a = registeredOwner('a@tenant.test', 'Company A');
    $b = registeredOwner('b@tenant.test', 'Company B');
    assertSame('Company A', $a->get('/company')['body']['data']['name']);
    assertSame('Company B', $b->get('/company')['body']['data']['name']);

    $a->patch('/company', ['name' => 'Company A renamed']);
    assertSame('Company B', $b->get('/company')['body']['data']['name']);
});

test('onboarding steps are derived from real company data', function (): void {
    $owner = registeredOwner('onboard@example.com', 'Onboard SME');
    $steps = array_column($owner->get('/onboarding')['body']['data']['steps'], 'done', 'key');
    assertSame(['company' => false, 'schedule' => false, 'tariff' => false, 'device' => false], $steps);

    $owner->patch('/company', ['nace_code' => '22.22', 'employees' => 28, 'annual_turnover_eur' => 900000]);
    $steps = array_column($owner->get('/onboarding')['body']['data']['steps'], 'done', 'key');
    assertSame(true, $steps['company']);
    assertSame(false, $steps['device']);
});

test('a viewer cannot edit the company profile, an owner can', function (): void {
    $owner = registeredOwner('boss@example.com', 'Roles SME');
    $companyId = $owner->get('/company')['body']['data']['id'];
    Database::run(
        "INSERT INTO users (company_id, email, password_hash, full_name, role) VALUES (?, 'viewer@example.com', ?, 'Viewer', 'viewer')",
        [$companyId, password_hash('viewer-password-1', PASSWORD_DEFAULT)],
    );
    $viewer = new Client();
    assertSame(200, $viewer->post('/auth/login', ['email' => 'viewer@example.com', 'password' => 'viewer-password-1'])['status']);
    assertSame(403, $viewer->patch('/company', ['employees' => 5])['status']);

    $updated = $owner->patch('/company', ['employees' => 28, 'nace_code' => '22.22', 'annual_turnover_eur' => '1250000']);
    assertSame(200, $updated['status']);
    assertSame(28, $updated['body']['data']['employees']);
    assertSame(1250000.0, $updated['body']['data']['annual_turnover_eur']);
});
