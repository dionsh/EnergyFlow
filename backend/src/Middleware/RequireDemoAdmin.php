<?php

declare(strict_types=1);

namespace EnergyFlow\Middleware;

use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;
use EnergyFlow\Services\Demo\DemoClock;

/** Demo Director controls: only for an owner/admin of the demo company. */
final class RequireDemoAdmin implements Middleware
{
    public function handle(Request $request): void
    {
        $user = $request->user();
        if (!RequireRole::atLeast((string) $user['role'], 'admin') || !DemoClock::isDemo((int) $user['company_id'])) {
            throw HttpException::forbidden('Demo controls are only available to the demo company\'s admins.');
        }
    }
}
