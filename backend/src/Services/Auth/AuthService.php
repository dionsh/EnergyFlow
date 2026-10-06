<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Auth;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;
use EnergyFlow\Models\Company;
use EnergyFlow\Models\User;
use EnergyFlow\Services\RateLimiter;
use EnergyFlow\Utils\Locales;

final class AuthService
{
    /**
     * Creates a company and its owner, then signs the owner in.
     *
     * @param array{full_name: string, email: string, password: string, company_name: string, city?: ?string, locale?: ?string} $input
     * @return array{user: array, company: array, token: string}
     */
    public static function register(array $input, Request $request): array
    {
        RateLimiter::hit('register:' . $request->ip, 10, 3600);

        if (User::emailExists($input['email'])) {
            throw HttpException::validation(['email' => 'email_taken']);
        }

        $locale = $input['locale'] ?? Locales::DEFAULT;
        $userId = Database::transaction(static function () use ($input, $locale): int {
            $companyId = Company::create($input['company_name'], $input['city'] ?? null, $locale);
            return User::create($companyId, $input['email'], self::hash($input['password']), $input['full_name'], 'owner', $locale);
        });

        User::touchLogin($userId);
        $user = User::find($userId);
        return [
            'user' => $user,
            'company' => Company::find($user['company_id']),
            'token' => SessionService::start($userId, $request),
        ];
    }

    /** @return array{user: array, company: array, token: string} */
    public static function login(string $email, string $password, Request $request): array
    {
        $email = mb_strtolower($email);
        RateLimiter::hit("login:{$request->ip}:{$email}", 5, 60);
        RateLimiter::hit("login-ip:{$request->ip}", 30, 60);

        $account = User::findForLogin($email);
        // Verify against a dummy hash when the account doesn't exist so both
        // paths take the same time and don't reveal which e-mails are registered.
        $hash = $account['password_hash'] ?? self::dummyHash();
        $valid = password_verify($password, $hash);

        if ($account === null || !$valid || $account['disabled_at'] !== null) {
            throw new HttpException(401, 'invalid_credentials', 'The e-mail or password is incorrect.');
        }

        $userId = (int) $account['id'];
        if (password_needs_rehash($hash, self::algorithm())) {
            User::updatePasswordHash($userId, self::hash($password));
        }
        RateLimiter::clear("login:{$request->ip}:{$email}");
        User::touchLogin($userId);

        $user = User::find($userId);
        return [
            'user' => $user,
            'company' => Company::find($user['company_id']),
            'token' => SessionService::start($userId, $request),
        ];
    }

    public static function changePassword(int $userId, string $current, string $new, string $currentToken): void
    {
        if (!password_verify($current, (string) User::passwordHash($userId))) {
            throw HttpException::validation(['current_password' => 'incorrect_password']);
        }
        User::updatePasswordHash($userId, self::hash($new));
        SessionService::revokeOthers($userId, $currentToken);
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= password_hash(bin2hex(random_bytes(16)), self::algorithm());
    }
}
