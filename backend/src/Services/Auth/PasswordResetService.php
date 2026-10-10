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
        if (!self::mailConfigured()) {
            throw new HttpException(503, 'email_not_configured', 'Password reset email delivery is not configured.');
        }
        RateLimiter::hit('password-reset-ip:' . $ip, 5, 3600);
        // Per address too, so nobody can flood one inbox from many IPs. Applied before the
        // lookup, so the limit behaves the same whether or not the account exists.
        RateLimiter::hit('password-reset-email:' . mb_strtolower($email), 3, 3600);
        Database::run('DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP() OR used_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
        $user = Database::one(
            "SELECT u.id, u.email, COALESCE(u.locale, 'sq') AS locale FROM users u WHERE u.email = ? AND u.disabled_at IS NULL LIMIT 1",
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
        self::deliver((string) $user['email'], (string) $user['locale'], $url);
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

    private static function deliver(string $email, string $locale, string $url): void
    {
        $apiKey = Env::get('RESEND_API_KEY');
        $fromEmail = Env::get('RESEND_FROM_EMAIL');
        if ($apiKey === null || $fromEmail === null) {
            throw new HttpException(503, 'email_not_configured', 'Password reset email delivery is not configured.');
        }

        $albanian = $locale === 'sq';
        $subject = $albanian ? 'Rivendos fjalëkalimin e EnergyFlow' : 'Reset your EnergyFlow password';
        $copy = $albanian ? [
            'eyebrow' => 'Siguria e llogarisë',
            'heading' => 'Rivendos fjalëkalimin',
            'intro' => 'Kemi marrë një kërkesë për të ndryshuar fjalëkalimin e llogarisë sate në EnergyFlow. Kliko butonin më poshtë për të vendosur një fjalëkalim të ri.',
            'button_text' => 'Ndrysho fjalëkalimin',
            'expiration_notice' => 'Ky link skadon pas 1 ore dhe mund të përdoret vetëm një herë.',
            'fallback_intro' => 'Nëse butoni nuk hapet, kopjoje këtë adresë në shfletues:',
            'ignore_notice' => 'Nëse nuk e ke kërkuar ti këtë ndryshim, mund ta injorosh këtë email. Fjalëkalimi yt nuk do të ndryshohet.',
            'footer' => 'Menaxhim më i mençur i energjisë',
        ] : [
            'eyebrow' => 'Account security',
            'heading' => 'Reset your password',
            'intro' => 'We received a request to change your EnergyFlow account password. Click the button below to choose a new password.',
            'button_text' => 'Reset password',
            'expiration_notice' => 'This link expires in 1 hour and can only be used once.',
            'fallback_intro' => 'If the button does not work, copy and paste this address into your browser:',
            'ignore_notice' => 'If you did not request this change, you can ignore this email. Your password will not be changed.',
            'footer' => 'Smarter energy management',
        ];
        $templatePath = dirname(__DIR__, 3) . '/templates/password-reset.html';
        $template = file_get_contents($templatePath);
        if ($template === false) {
            error_log('[EnergyFlow] Password reset email template could not be read.');
            throw new HttpException(503, 'email_not_configured', 'Password reset email delivery is not configured.');
        }
        $variables = ['subject' => $subject, 'link' => $url, ...$copy];
        $html = preg_replace_callback('/{{([a-z_]+)}}/', static function (array $match) use ($variables): string {
            return htmlspecialchars($variables[$match[1]] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $template) ?? '';
        $plainText = $copy['intro'] . "\n\n" . $copy['button_text'] . ': ' . $url . "\n\n"
            . $copy['expiration_notice'] . "\n" . $copy['ignore_notice'];
        $payload = json_encode([
            'from' => $fromEmail,
            'to' => [$email],
            'subject' => $subject,
            'html' => $html,
            'text' => $plainText,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents('https://api.resend.com/emails', false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($response === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
            $diagnostic = trim(strip_tags((string) $response));
            $diagnostic = str_replace($apiKey, '[credential hidden]', $diagnostic);
            $diagnostic = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email hidden]', $diagnostic) ?? '';
            $diagnostic = preg_replace('/https?:\/\/\S+/i', '[link hidden]', $diagnostic) ?? '';
            $diagnostic = preg_replace('/\b[a-f0-9]{64}\b/i', '[token hidden]', $diagnostic) ?? '';
            $diagnostic = mb_substr($diagnostic, 0, 300);
            error_log('[EnergyFlow] Password reset email delivery failed via Resend. HTTP response: '
                . ($statusLine ?: 'unavailable')
                . ($diagnostic !== '' ? '; Resend says: ' . $diagnostic : ''));
        }
    }

    private static function mailConfigured(): bool
    {
        return Env::get('RESEND_API_KEY') !== null
            && Env::get('RESEND_FROM_EMAIL') !== null;
    }
}
