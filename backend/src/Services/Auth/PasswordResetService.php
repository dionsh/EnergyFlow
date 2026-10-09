<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Auth;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\Env;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Services\RateLimiter;

final class PasswordResetService
{
    public static function request(string $email, string $ip): void
    {
        RateLimiter::hit('password-reset-ip:' . $ip, 5, 3600);
        Database::run('DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP() OR used_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
        $user = Database::one(
            "SELECT u.id, u.email, u.full_name, COALESCE(u.locale, 'sq') AS locale FROM users u WHERE u.email = ? AND u.disabled_at IS NULL LIMIT 1",
            [mb_strtolower($email)],
        );
        if ($user === null) {
            return;
        }

        Database::run('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [(int) $user['id']]);
        $token = bin2hex(random_bytes(32));
        Database::run(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
             VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 1 HOUR, UTC_TIMESTAMP())',
            [(int) $user['id'], hash('sha256', $token)],
        );

        $base = rtrim(Env::get('FRONTEND_URL', 'http://localhost:5173') ?? '', '/');
        $url = $base . '/reset-password?token=' . rawurlencode($token);
        self::deliver((string) $user['email'], (string) $user['full_name'], (string) $user['locale'], $url);
    }

    public static function reset(string $token, string $newPassword, string $ip): void
    {
        RateLimiter::hit('password-reset-complete-ip:' . $ip, 10, 3600);
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw HttpException::badRequest('invalid_reset_token', 'This password reset link is invalid or has expired.');
        }
        $tokenHash = hash('sha256', $token);
        Database::transaction(static function () use ($tokenHash, $newPassword): void {
            $reset = Database::one(
                'SELECT r.id, r.user_id FROM password_resets r JOIN users u ON u.id = r.user_id
                  WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > UTC_TIMESTAMP() AND u.disabled_at IS NULL FOR UPDATE',
                [$tokenHash],
            );
            if ($reset === null) {
                throw HttpException::badRequest('invalid_reset_token', 'This password reset link is invalid or has expired.');
            }

            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [AuthService::hash($newPassword), (int) $reset['user_id']]);
            Database::run('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [(int) $reset['user_id']]);
            Database::run('UPDATE sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL', [(int) $reset['user_id']]);
        });
    }

    private static function deliver(string $email, string $name, string $locale, string $url): void
    {
        $apiKey = Env::get('RESEND_API_KEY');
        $from = Env::get('MAIL_FROM');
        if ($apiKey === null || $from === null) {
            if (!Env::isProduction()) {
                error_log('[EnergyFlow] Local password reset link for ' . $email . ': ' . $url);
            } else {
                error_log('[EnergyFlow] Password reset email unavailable: configure RESEND_API_KEY and MAIL_FROM.');
            }
            return;
        }

        $escapedName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $albanian = $locale === 'sq';
        $subject = $albanian ? 'Rivendos fjalëkalimin e EnergyFlow' : 'Reset your EnergyFlow password';
        $htmlBody = $albanian
            ? '<p>Përshëndetje ' . $escapedName . ',</p><p>Përdore këtë lidhje për të zgjedhur një fjalëkalim të ri për EnergyFlow. Lidhja skadon pas një ore.</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Rivendos fjalëkalimin</a></p><p>Nëse nuk e kërkove këtë, mund ta shpërfillësh email-in.</p>'
            : '<p>Hello ' . $escapedName . ',</p><p>Use this link to choose a new EnergyFlow password. It expires in one hour.</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Reset password</a></p><p>If you did not request this, you can ignore this email.</p>';
        $textBody = $albanian
            ? "Përshëndetje {$name},\n\nPërdore këtë lidhje për të zgjedhur një fjalëkalim të ri për EnergyFlow. Lidhja skadon pas një ore:\n{$url}\n\nNëse nuk e kërkove këtë, mund ta shpërfillësh email-in."
            : "Hello {$name},\n\nUse this link to choose a new EnergyFlow password. It expires in one hour:\n{$url}\n\nIf you did not request this, you can ignore this email.";
        $payload = json_encode([
            'from' => $from,
            'to' => [$email],
            'subject' => $subject,
            'html' => $htmlBody,
            'text' => $textBody,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$apiKey}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents('https://api.resend.com/emails', false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($response === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
            error_log('[EnergyFlow] Password reset email delivery failed. HTTP response: ' . ($statusLine ?: 'unavailable'));
        }
    }
}
