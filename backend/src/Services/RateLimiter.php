<?php

declare(strict_types=1);

namespace EnergyFlow\Services;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;

/**
 * Fixed-window rate limiting stored in MySQL (no Redis on the free tier).
 * Keys are hashed so no e-mail addresses or IPs are stored in clear text.
 */
final class RateLimiter
{
    public static function hit(string $key, int $max, int $windowSeconds): void
    {
        $bucket = hash('sha256', $key);
        // Assignment order matters: `hits` is computed from the OLD window_start.
        Database::run(
            'INSERT INTO rate_limits (bucket_key, window_start, hits) VALUES (?, UTC_TIMESTAMP(), 1)
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start < UTC_TIMESTAMP() - INTERVAL ? SECOND, 1, hits + 1),
               window_start = IF(window_start < UTC_TIMESTAMP() - INTERVAL ? SECOND, UTC_TIMESTAMP(), window_start)',
            [$bucket, $windowSeconds, $windowSeconds],
        );
        $hits = (int) Database::value('SELECT hits FROM rate_limits WHERE bucket_key = ?', [$bucket]);
        if ($hits > $max) {
            throw HttpException::tooManyRequests();
        }
    }

    public static function clear(string $key): void
    {
        Database::run('DELETE FROM rate_limits WHERE bucket_key = ?', [hash('sha256', $key)]);
    }
}
