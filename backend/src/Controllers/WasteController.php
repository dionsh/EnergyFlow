<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Services\Analytics\Period;
use EnergyFlow\Services\AuditLog;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Detection\WasteReport;

final class WasteController
{
    private const UNITS = ['units' => ['energy' => 'kWh', 'money' => 'EUR', 'co2' => 'kg CO2e']];

    public function summary(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $period = $this->period($request, $companyId, 'mtd');
        return Response::ok(WasteReport::summary($companyId, $period), self::UNITS);
    }

    public function index(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $filters = Validator::validate($request->query, [
            'type' => ['nullable', 'in:after_hours,idle,excess_vs_baseline'],
            'machine_id' => ['nullable', 'int', 'min:1'],
            'status' => ['nullable', 'in:open,acted,dismissed,all'],
        ]);
        $period = $this->period($request, $companyId, '30d');
        return Response::ok(WasteReport::list($companyId, $period, $filters), self::UNITS + ['period' => $period->meta()]);
    }

    public function show(Request $request, array $params): Response
    {
        return Response::ok(WasteReport::show($request->companyId(), (int) $params['id']), self::UNITS);
    }

    public function dismiss(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), ['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $companyId = $request->companyId();
        $id = (int) $params['id'];
        WasteReport::dismiss($companyId, $id, $input['reason']);
        AuditLog::record($companyId, (int) $request->user()['id'], 'waste.dismiss', 'waste_event', $id, ['reason' => $input['reason']]);
        return Response::ok(WasteReport::show($companyId, $id), self::UNITS);
    }

    private function period(Request $request, int $companyId, string $default): Period
    {
        if (DemoClock::isDemo($companyId)) {
            DemoClock::catchUp($companyId);
        }
        return Period::parse($request->query('period'), Clock::now($companyId), LocalTime::forCompany($companyId), $default);
    }
}
