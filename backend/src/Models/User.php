<?php

declare(strict_types=1);

namespace EnergyFlow\Models;

use EnergyFlow\Core\Database;
use EnergyFlow\Utils\Time;

final class User
{
    private const PUBLIC_COLUMNS = 'id, company_id, email, full_name, role, is_platform_admin, locale, last_login_at, created_at';

    public static function find(int $id): ?array
    {
        $row = Database::one('SELECT ' . self::PUBLIC_COLUMNS . ' FROM users WHERE id = ? AND disabled_at IS NULL', [$id]);
        return $row === null ? null : self::present($row);
    }

    /** Includes the password hash. Only for the login check. */
    public static function findForLogin(string $email): ?array
    {
        return Database::one(
            'SELECT id, company_id, password_hash, disabled_at FROM users WHERE email = ?',
            [mb_strtolower($email)],
        );
    }

    public static function emailExists(string $email): bool
    {
        return Database::value('SELECT 1 FROM users WHERE email = ?', [mb_strtolower($email)]) !== null;
    }

    public static function create(int $companyId, string $email, string $passwordHash, string $fullName, string $role, ?string $locale): int
    {
        return Database::insert(
            'INSERT INTO users (company_id, email, password_hash, full_name, role, locale) VALUES (?, ?, ?, ?, ?, ?)',
            [$companyId, mb_strtolower($email), $passwordHash, $fullName, $role, $locale],
        );
    }

    public static function passwordHash(int $id): ?string
    {
        return Database::value('SELECT password_hash FROM users WHERE id = ?', [$id]);
    }

    public static function updateProfile(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(['full_name', 'locale']));
        if ($fields === []) {
            return;
        }
        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = :{$column}", array_keys($fields)));
        Database::run("UPDATE users SET {$assignments} WHERE id = :id", $fields + ['id' => $id]);
    }

    public static function updatePasswordHash(int $id, string $hash): void
    {
        Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $id]);
    }

    public static function touchLogin(int $id): void
    {
        Database::run('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
    }

    private static function present(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['company_id'] = (int) $row['company_id'];
        $row['is_platform_admin'] = (bool) $row['is_platform_admin'];
        return Time::isoColumns($row, ['last_login_at', 'created_at']);
    }
}
