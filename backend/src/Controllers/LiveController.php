<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Services\Analytics\LiveService;
use EnergyFlow\Services\Analytics\OverviewService;
use EnergyFlow\Services\Analytics\ScoreService;
use EnergyFlow\Services\Demo\DemoClock;

final class LiveController
{
    /** Polled every 5 s by the Live screen. For the demo company it also advances the simulation. */
    public function show(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        if (DemoClock::isDemo($companyId)) {
            DemoClock::catchUp($companyId);
        }
        return Response::ok(LiveService::snapshot($companyId), ['units' => ['power' => 'kW', 'energy' => 'kWh', 'money' => 'EUR', 'co2' => 'kg CO2e']]);
    }

    public function overview(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        if (DemoClock::isDemo($companyId)) {
            DemoClock::catchUp($companyId);
        }
        return Response::ok(OverviewService::summary($companyId), ['units' => ['energy' => 'kWh', 'money' => 'EUR', 'co2' => 'kg CO2e']]);
    }

    public function score(Request $request, array $params): Response
    {
        return Response::ok(ScoreService::summary($this->fresh($request)));
    }

    public function flow(Request $request, array $params): Response
    {
        return Response::ok(OverviewService::flow($this->fresh($request)), ['units' => ['energy' => 'kWh', 'money' => 'EUR', 'co2' => 'kg CO2e']]);
    }

    private function fresh(Request $request): int
    {
        $companyId = $request->companyId();
        if (DemoClock::isDemo($companyId)) {
            DemoClock::catchUp($companyId);
        }
        return $companyId;
    }
}
