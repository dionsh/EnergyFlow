<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Admin;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Services\Auth\PasswordResetService;
use EnergyFlow\Services\Demo\CompanyPurger;
use EnergyFlow\Utils\Time;

/**
 * Every user of every company, for the platform admin panel.
 *
 * Rules that keep a company working:
 *  - demo accounts are rebuilt by the Demo Director, so they are read-only here;
 *  - an admin cannot disable or delete their own account;
 *  - a company always keeps an active owner (promote someone else first);
 *  - deleting a company's only user deletes the company and all its data, and
 *    only when the request says so explicitly (with_company).
 */
final class UserAdmin
{
    public const PAGE_SIZE = 50;
    private const ROLES = ['owner', 'admin', 'manager', 'viewer'];

    private const SELECT = "SELECT u.id, u.full_name, u.email, u.role, u.locale, u.is_platform_admin, u.last_login_at, u.created_at, u.disabled_at,
               c.id AS company_id, c.name AS company_name, c.is_demo,
               (SELECT COUNT(*) FROM users cu WHERE cu.company_id = u.company_id) AS company_users,
               (SELECT MAX(s.last_used_at) FROM sessions s WHERE s.user_id = u.id) AS last_seen_at,
               (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP()) AS active_sessions
          FROM users u JOIN companies c ON c.id = u.company_id";

    /**
     * @param array{q?: string, company_id?: int, role?: string, status?: string, page?: int} $filters
     * @return array{users: list<array<string, mixed>>, total: int, page: int, page_size: int}
     */
    public static function list(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(u.email LIKE ? OR u.full_name LIKE ? OR c.name LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if (!empty($filters['company_id'])) {
            $where[] = 'u.company_id = ?';
            $params[] = (int) $filters['company_id'];
        }
        if (in_array($filters['role'] ?? '', self::ROLES, true)) {
            $where[] = 'u.role = ?';
            $params[] = $filters['role'];
        }
        $status = $filters['status'] ?? '';
        if ($status === 'active') {
            $where[] = 'u.disabled_at IS NULL';
        } elseif ($status === 'disabled') {
            $where[] = 'u.disabled_at IS NOT NULL';
        }
        $condition = implode(' AND ', $where);
        $total = (int) Database::value("SELECT COUNT(*) FROM users u JOIN companies c ON c.id = u.company_id WHERE {$condition}", $params);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * self::PAGE_SIZE;
        $rows = Database::all(
            self::SELECT . " WHERE {$condition} ORDER BY c.is_demo, u.created_at DESC, u.id DESC LIMIT " . self::PAGE_SIZE . " OFFSET {$offset}",
            $params,
        );
        return ['users' => array_map([self::class, 'present'], $rows), 'total' => $total, 'page' => $page, 'page_size' => self::PAGE_SIZE];
    }

    public static function find(int $id): array
    {
        $row = Database::one(self::SELECT . ' WHERE u.id = ?', [$id]);
        if ($row === null) {
            throw HttpException::notFound('user_not_found', 'That user does not exist.');
        }
        return self::present($row);
    }

    /**
     * @param array{id: int, email: string} $admin
     * @param array{full_name?: string, email?: string, role?: string, locale?: string, disabled?: bool} $input
     */
    public static function update(array $admin, int $id, array $input): array
    {
        $user = self::find($id);
        self::guardDemo($user);
        $changes = [];

        if (isset($input['disabled']) && $input['disabled'] !== $user['disabled']) {
            if ($input['disabled'] && $id === $admin['id']) {
                throw HttpException::conflict('cannot_disable_self', 'You cannot disable your own account.');
            }
            $changes['disabled'] = [$user['disabled'], $input['disabled']];
        }
        if (isset($input['role']) && $input['role'] !== $user['role']) {
            $changes['role'] = [$user['role'], $input['role']];
        }
        $leavesOwnership = $user['role'] === 'owner' && !$user['disabled']
            && ((isset($changes['role']) && $input['role'] !== 'owner') || ($changes['disabled'][1] ?? false));
        if ($leavesOwnership && self::otherActiveOwners($user['company']['id'], $id) === 0) {
            throw HttpException::conflict('last_owner', 'Every company needs an active owner. Make another user owner first.');
        }
        if (isset($input['email']) && $input['email'] !== $user['email']) {
            if (Database::value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$input['email'], $id]) !== null) {
                throw HttpException::validation(['email' => 'email_taken']);
            }
            $changes['email'] = [$user['email'], $input['email']];
        }
        foreach (['full_name', 'locale'] as $field) {
            if (isset($input[$field]) && $input[$field] !== $user[$field]) {
                $changes[$field] = [$user[$field], $input[$field]];
            }
        }
        if ($changes === []) {
            return $user;
        }

        Database::transaction(static function () use ($id, $changes): void {
            $set = [];
            $params = [];
            foreach (['full_name', 'email', 'role', 'locale'] as $field) {
                if (isset($changes[$field])) {
                    $set[] = "{$field} = ?";
                    $params[] = $changes[$field][1];
                }
            }
            if (isset($changes['disabled'])) {
                $set[] = $changes['disabled'][1] ? 'disabled_at = UTC_TIMESTAMP()' : 'disabled_at = NULL';
            }
            $params[] = $id;
            Database::run('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
            if ($changes['disabled'][1] ?? false) {
                Database::run('UPDATE sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL', [$id]);
            }
            if (isset($changes['email'])) {
                // A reset link sent to the old address must not work any more.
                Database::run('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$id]);
            }
        });
        AdminLog::record($admin, 'user.update', 'user', $id, [
            'email' => $changes['email'][1] ?? $user['email'], 'company' => $user['company']['name'],
            'changes' => array_map(static fn (array $c): array => ['from' => $c[0], 'to' => $c[1]], $changes),
        ]);
        return self::find($id);
    }

    /**
     * @param array{id: int, email: string} $admin
     * @return array{deleted: true, company_deleted: bool}
     */
    public static function delete(array $admin, int $id, bool $withCompany): array
    {
        $user = self::find($id);
        self::guardDemo($user);
        if ($id === $admin['id']) {
            throw HttpException::conflict('cannot_delete_self', 'You cannot delete your own account.');
        }
        $companyId = $user['company']['id'];
        $soleUser = $user['company']['users'] === 1;
        if ($soleUser && !$withCompany) {
            throw HttpException::conflict('company_would_be_empty', 'This is the company\'s only user: deleting it deletes the company and all its data too.');
        }
        if (!$soleUser && $user['role'] === 'owner' && !$user['disabled'] && self::otherActiveOwners($companyId, $id) === 0) {
            throw HttpException::conflict('last_owner', 'Every company needs an active owner. Make another user owner first.');
        }

        Database::transaction(static function () use ($id, $companyId, $soleUser): void {
            if ($soleUser) {
                CompanyPurger::purge($companyId);
                return;
            }
            // The user's own data. Rows they created for the company (reports, policies,
            // commands) stay with the company; the name is simply no longer shown.
            Database::run('DELETE FROM ai_conversations WHERE user_id = ?', [$id]);
            Database::run('DELETE FROM notification_reads WHERE user_id = ?', [$id]);
            Database::run('DELETE FROM users WHERE id = ?', [$id]); // cascades: sessions, password_resets
        });
        AdminLog::record($admin, $soleUser ? 'company.delete' : 'user.delete', $soleUser ? 'company' : 'user', $soleUser ? $companyId : $id, [
            'email' => $user['email'], 'full_name' => $user['full_name'], 'role' => $user['role'], 'company' => $user['company']['name'],
        ]);
        return ['deleted' => true, 'company_deleted' => $soleUser];
    }

    /** @param array{id: int, email: string} $admin */
    public static function sendPasswordReset(array $admin, int $id, string $ip): void
    {
        $user = self::find($id);
        self::guardDemo($user);
        if ($user['disabled']) {
            throw HttpException::conflict('user_disabled', 'Enable the account before sending a reset link.');
        }
        PasswordResetService::request($user['email'], $ip);
        AdminLog::record($admin, 'user.password_reset', 'user', $id, ['email' => $user['email'], 'company' => $user['company']['name']]);
    }

    private static function otherActiveOwners(int $companyId, int $exceptUserId): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM users WHERE company_id = ? AND id <> ? AND role = 'owner' AND disabled_at IS NULL",
            [$companyId, $exceptUserId],
        );
    }

    private static function guardDemo(array $user): void
    {
        if ($user['company']['demo']) {
            throw HttpException::conflict('demo_account', 'Demo accounts are rebuilt by the Demo Director and cannot be changed here.');
        }
    }

    private static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'full_name' => $r['full_name'],
            'email' => $r['email'],
            'role' => $r['role'],
            'locale' => $r['locale'],
            'is_platform_admin' => (bool) $r['is_platform_admin'],
            'disabled' => $r['disabled_at'] !== null,
            'company' => ['id' => (int) $r['company_id'], 'name' => $r['company_name'], 'demo' => (bool) $r['is_demo'], 'users' => (int) $r['company_users']],
            'active_sessions' => (int) $r['active_sessions'],
            'last_login_at' => Time::iso($r['last_login_at']),
            'last_seen_at' => Time::iso($r['last_seen_at']),
            'created_at' => Time::iso($r['created_at']),
        ];
    }
}
