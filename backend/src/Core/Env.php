<?php

declare(strict_types=1);

namespace EnergyFlow\Core;

use RuntimeException;

/**
 * Reads configuration from the process environment (Render) with an optional
 * local `.env` file for development. Real environment variables always win.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = self::unquote($value);
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }

    public static function require(string $key): string
    {
        return self::get($key) ?? throw new RuntimeException("Missing required environment variable {$key}");
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return ($value !== null && is_numeric($value)) ? (int) $value : $default;
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'local') === 'production';
    }

    private static function unquote(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$length - 1] === $value[0]) {
            return substr($value, 1, -1);
        }
        return $value;
    }
}
