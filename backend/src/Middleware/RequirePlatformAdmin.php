<?php

declare(strict_types=1);

namespace EnergyFlow\Middleware;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;
use EnergyFlow\Services\Demo\DemoClock;

/**
 * The platform admin panel: EnergyFlow's own staff, across every company.
 * The flag is granted only from the command line (bin/platform-admin.php),
 * and a demo account never qualifies, whatever its flag says.
 */
final class RequirePlatformAdmin implements Middleware
{
    public function handle(Request $request): void
    {
        $user = $request->user();
        $companyId = (int) $user['company_id'];
        $demo = (bool) Database::value('SELECT is_demo FROM companies WHERE id = ?', [$companyId]) || DemoClock::isDemo($companyId);
        if (($user['is_platform_admin'] ?? false) !== true || $demo) {
            throw HttpException::forbidden('Only EnergyFlow platform admins can open this.');
        }
    }
}
