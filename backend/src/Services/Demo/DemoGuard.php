<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Demo;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;

/**
 * The demo company is shared by every visitor and rebuilt by the Demo Director, so its
 * accounts and setup are read-only: one visitor must never change what the next one sees.
 */
final class DemoGuard
{
    public static function refuse(int $companyId): void
    {
        if ((bool) Database::value('SELECT is_demo FROM companies WHERE id = ?', [$companyId]) || DemoClock::isDemo($companyId)) {
            throw HttpException::conflict('demo_account', 'Demo accounts are rebuilt by the Demo Director and cannot be changed here.');
        }
    }
}
