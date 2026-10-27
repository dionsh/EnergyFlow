<?php

declare(strict_types=1);

/*
 * Creates, grants or revokes access to the platform admin panel (/admin). This is the
 * only way to do it: the API never changes the flag.
 *   php bin/platform-admin.php list
 *   php bin/platform-admin.php create you@example.com "Full Name"
 *   php bin/platform-admin.php grant you@example.com
 *   php bin/platform-admin.php revoke you@example.com
 * Run it locally, or against Aiven by pointing the DB_* variables at it.
 * Sign-ups wait for a platform admin's approval, so on a fresh database `create` is how
 * the first admins get in: it asks for a password and makes a staff account in the
 * EnergyFlow company. An e-mail that already has an account is only granted and keeps
 * its password. Demo accounts never qualify.
 */

use EnergyFlow\Core\Database;
use EnergyFlow\Models\Company;
use EnergyFlow\Models\User;
use EnergyFlow\Services\Admin\AdminLog;
use EnergyFlow\Services\Auth\AuthService;
use EnergyFlow\Utils\Locales;

require dirname(__DIR__) . '/src/bootstrap.php';

const STAFF_COMPANY = 'EnergyFlow';

$command = $argv[1] ?? 'list';
$email = mb_strtolower(trim($argv[2] ?? ''));

if ($command === 'list') {
    $admins = Database::all(
        'SELECT u.email, u.full_name, c.name AS company, u.disabled_at FROM users u JOIN companies c ON c.id = u.company_id
          WHERE u.is_platform_admin = 1 ORDER BY u.email',
    );
    if ($admins === []) {
        fwrite(STDOUT, '[admin] No platform admins yet. Create one with: php bin/platform-admin.php create <email> "<full name>"' . PHP_EOL);
    }
    foreach ($admins as $a) {
        fwrite(STDOUT, "[admin] {$a['email']}  ({$a['full_name']}, {$a['company']})" . ($a['disabled_at'] !== null ? '  [disabled]' : '') . PHP_EOL);
    }
    exit(0);
}

if (!in_array($command, ['create', 'grant', 'revoke'], true) || $email === '') {
    fwrite(STDERR, 'Usage: php bin/platform-admin.php list | create <email> "<full name>" | grant <email> | revoke <email>' . PHP_EOL);
    exit(1);
}

$user = Database::one(
    'SELECT u.id, u.email, u.is_platform_admin, c.name AS company, c.is_demo FROM users u JOIN companies c ON c.id = u.company_id WHERE u.email = ?',
    [$email],
);

if ($command === 'create' && $user !== null) {
    fwrite(STDOUT, "[admin] {$email} already has an account ({$user['company']}); its password is unchanged." . PHP_EOL);
    $command = 'grant';
} elseif ($command === 'create') {
    $fullName = trim($argv[3] ?? '');
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $fullName === '') {
        fwrite(STDERR, 'Usage: php bin/platform-admin.php create <email> "<full name>"' . PHP_EOL);
        exit(1);
    }
    // Read from standard input rather than the command line, so it stays out of the shell history.
    fwrite(STDOUT, "Password for {$email}: ");
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if ($password === '' || mb_strlen($password) > 200) {
        fwrite(STDERR, '[admin] The password must be 1 to 200 characters.' . PHP_EOL);
        exit(1);
    }
    if (mb_strlen($password) < 10) {
        fwrite(STDERR, '[admin] Warning: shorter than the 10 characters the app requires for every other password.' . PHP_EOL);
    }
    Database::transaction(static function () use ($email, $fullName, $password): void {
        $companyId = Database::value('SELECT id FROM companies WHERE name = ? AND is_demo = 0 ORDER BY id LIMIT 1', [STAFF_COMPANY])
            ?? Company::create(STAFF_COMPANY, null, Locales::DEFAULT);
        $id = User::create((int) $companyId, $email, AuthService::hash($password), $fullName, 'admin', Locales::DEFAULT);
        Database::run('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [$id]);
        // A pending sign-up with this e-mail could never be approved now.
        Database::run('DELETE FROM company_requests WHERE email = ?', [$email]);
        AdminLog::record(null, 'admin.grant', 'user', $id, ['email' => $email, 'company' => STAFF_COMPANY]);
    });
    fwrite(STDOUT, "[admin] Created {$email} in " . STAFF_COMPANY . "; it can now open /admin." . PHP_EOL);
    exit(0);
}

if ($user === null) {
    fwrite(STDERR, "[admin] No account with the e-mail {$email}. Make one with: php bin/platform-admin.php create {$email} \"<full name>\"" . PHP_EOL);
    exit(1);
}
if ($command === 'grant' && (bool) $user['is_demo']) {
    fwrite(STDERR, '[admin] Demo accounts cannot be platform admins.' . PHP_EOL);
    exit(1);
}

$grant = $command === 'grant';
if ((bool) $user['is_platform_admin'] === $grant) {
    fwrite(STDOUT, "[admin] {$email} " . ($grant ? 'is already' : 'is not') . ' a platform admin.' . PHP_EOL);
    exit(0);
}
Database::run('UPDATE users SET is_platform_admin = ? WHERE id = ?', [$grant ? 1 : 0, (int) $user['id']]);
AdminLog::record(null, $grant ? 'admin.grant' : 'admin.revoke', 'user', (int) $user['id'], ['email' => $email, 'company' => $user['company']]);
fwrite(STDOUT, "[admin] {$email} " . ($grant ? 'can now open /admin.' : 'no longer has platform admin access.') . PHP_EOL);
