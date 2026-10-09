<?php

declare(strict_types=1);

/*
 * Grants or revokes access to the platform admin panel (/admin). This is the only
 * way to do it: the API never changes the flag.
 *   php bin/platform-admin.php list
 *   php bin/platform-admin.php grant you@example.com
 *   php bin/platform-admin.php revoke you@example.com
 * Run it locally, or against Aiven by pointing the DB_* variables at it.
 * The account must exist first (sign up in the app), and demo accounts never qualify.
 */

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Admin\AdminLog;

require dirname(__DIR__) . '/src/bootstrap.php';

$command = $argv[1] ?? 'list';
$email = mb_strtolower(trim($argv[2] ?? ''));

if ($command === 'list') {
    $admins = Database::all(
        'SELECT u.email, u.full_name, c.name AS company, u.disabled_at FROM users u JOIN companies c ON c.id = u.company_id
          WHERE u.is_platform_admin = 1 ORDER BY u.email',
    );
    if ($admins === []) {
        fwrite(STDOUT, '[admin] No platform admins yet. Grant one with: php bin/platform-admin.php grant <email>' . PHP_EOL);
    }
    foreach ($admins as $a) {
        fwrite(STDOUT, "[admin] {$a['email']}  ({$a['full_name']}, {$a['company']})" . ($a['disabled_at'] !== null ? '  [disabled]' : '') . PHP_EOL);
    }
    exit(0);
}

if (!in_array($command, ['grant', 'revoke'], true) || $email === '') {
    fwrite(STDERR, 'Usage: php bin/platform-admin.php list | grant <email> | revoke <email>' . PHP_EOL);
    exit(1);
}

$user = Database::one(
    'SELECT u.id, u.email, u.is_platform_admin, c.name AS company, c.is_demo FROM users u JOIN companies c ON c.id = u.company_id WHERE u.email = ?',
    [$email],
);
if ($user === null) {
    fwrite(STDERR, "[admin] No account with the e-mail {$email}. Sign up in the app first." . PHP_EOL);
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
