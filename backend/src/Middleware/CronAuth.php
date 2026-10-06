<?php

declare(strict_types=1);

namespace EnergyFlow\Middleware;

use EnergyFlow\Core\Env;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;

/** Guards internal endpoints called by cron-job.org. Never relies on "is localhost" checks. */
final class CronAuth implements Middleware
{
    public function handle(Request $request): void
    {
        $expected = Env::get('CRON_SECRET');
        $given = (string) $request->header('X-Cron-Secret');
        if ($expected === null || strlen($expected) < 16 || !hash_equals($expected, $given)) {
            throw HttpException::forbidden('Invalid cron secret.');
        }
    }
}
