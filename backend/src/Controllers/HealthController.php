<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\Env;
use EnergyFlow\Core\Migrator;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use Throwable;

final class HealthController
{
    /** Render's health check. 503 when the database is unreachable. */
    public function show(Request $request, array $params): Response
    {
        $checks = ['database' => 'ok', 'migration' => null, 'ai_configured' => Env::get('GROQ_API_KEY') !== null];
        $status = 200;
        try {
            Database::value('SELECT 1');
            $checks['migration'] = Migrator::latestVersion();
        } catch (Throwable $e) {
            error_log('[EnergyFlow] health: ' . $e->getMessage());
            $checks['database'] = 'unreachable';
            $status = 503;
        }
        return Response::ok(['status' => $status === 200 ? 'ok' : 'degraded'] + $checks, status: $status);
    }
}
