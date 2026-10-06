<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Auth;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\Env;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Models\User;

/**
 * Database-backed sessions. The browser holds a random 256-bit token in an
 * HttpOnly cookie; the database only stores its SHA-256 hash, so a leaked
 * sessions table cannot be replayed. Sessions slide: each use (at most once per
 * hour) pushes the expiry forward.
 */
final class SessionService
{
    public const COOKIE = 'ef_session';
    private const REFRESH_AFTER_SECONDS = 3600;

    public static function start(int $userId, Request $request): string
    {
        $token = bin2hex(random_bytes(32));
        Database::run(
            'INSERT INTO sessions (user_id, token_hash, expires_at, last_used_at, ip, user_agent)
             VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY, UTC_TIMESTAMP(), ?, ?)',
            [$userId, self::hash($token), self::ttlDays(), $request->ip, mb_substr((string) $request->header('User-Agent'), 0, 255)],
        );
        return $token;
    }

    /** @return array<string, mixed>|null the user, or null when the token is unknown/expired/revoked */
    public static function resolve(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $session = Database::one(
            'SELECT id, user_id, TIMESTAMPDIFF(SECOND, last_used_at, UTC_TIMESTAMP()) AS idle_seconds
               FROM sessions
              WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [self::hash($token)],
        );
        if ($session === null) {
            return null;
        }

        $user = User::find((int) $session['user_id']);
        if ($user === null) {
            return null;
        }

        if ((int) $session['idle_seconds'] > self::REFRESH_AFTER_SECONDS) {
            Database::run(
                'UPDATE sessions SET last_used_at = UTC_TIMESTAMP(), expires_at = UTC_TIMESTAMP() + INTERVAL ? DAY WHERE id = ?',
                [self::ttlDays(), (int) $session['id']],
            );
        }
        return $user;
    }

    public static function revoke(string $token): void
    {
        Database::run('UPDATE sessions SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = ? AND revoked_at IS NULL', [self::hash($token)]);
    }

    /** After a password change: sign out every other device. */
    public static function revokeOthers(int $userId, string $keepToken): void
    {
        Database::run(
            'UPDATE sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND token_hash <> ? AND revoked_at IS NULL',
            [$userId, self::hash($keepToken)],
        );
    }

    public static function attachCookie(Response $response, string $token): Response
    {
        return $response->withCookie(self::COOKIE, $token, self::cookieOptions(time() + self::ttlDays() * 86400));
    }

    public static function clearCookie(Response $response): Response
    {
        return $response->withCookie(self::COOKIE, '', self::cookieOptions(time() - 3600));
    }

    private static function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            // Must be 0 for plain-http local development, or the browser drops the cookie.
            'secure' => Env::bool('SESSION_SECURE', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function ttlDays(): int
    {
        return max(1, Env::int('SESSION_TTL_DAYS', 7));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
