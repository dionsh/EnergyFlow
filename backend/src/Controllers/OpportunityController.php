<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Optimization\Recommendations;
use EnergyFlow\Services\Optimization\WhatIf;

/** Opportunities (ranked recommendations) and the what-if simulator. */
final class OpportunityController
{
    private const UNITS = ['units' => ['energy' => 'kWh', 'money' => 'EUR', 'co2' => 'kg CO2e']];

    public function index(Request $request, array $params): Response
    {
        $input = Validator::validate($request->query, ['status' => ['nullable', 'in:open,active,dismissed,all']]);
        $companyId = $request->companyId();
        if (DemoClock::isDemo($companyId)) {
            DemoClock::catchUp($companyId);
        }
        $list = Recommendations::list($companyId, $input['status'] ?? 'all');
        $open = array_filter($list, static fn (array $r): bool => $r['status'] === 'proposed');
        return Response::ok($list, self::UNITS + ['potential' => [
            'count' => count($open),
            'kwh' => round(array_sum(array_map(static fn (array $r): float => $r['per_month']['kwh'], $open)), 1),
            'eur' => round(array_sum(array_map(static fn (array $r): float => $r['per_month']['eur'], $open)), 2),
            'co2_kg' => round(array_sum(array_map(static fn (array $r): float => $r['per_month']['co2_kg'], $open)), 1),
        ]]);
    }

    public function show(Request $request, array $params): Response
    {
        return Response::ok(Recommendations::show($request->companyId(), (int) $params['id']), self::UNITS);
    }

    public function accept(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), ['mode' => ['nullable', 'in:auto,approve,notify']]);
        return Response::ok(Recommendations::accept($request->companyId(), (int) $params['id'], (int) $request->user()['id'], $input['mode'] ?? 'auto'));
    }

    public function implemented(Request $request, array $params): Response
    {
        return Response::ok(Recommendations::markImplemented($request->companyId(), (int) $params['id'], (int) $request->user()['id']));
    }

    public function dismiss(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), ['reason' => ['required', 'string', 'min:3', 'max:200']]);
        return Response::ok(Recommendations::dismiss($request->companyId(), (int) $params['id'], (int) $request->user()['id'], $input['reason']));
    }

    /** Replays the machine's own history under a change. Read-only, so viewers may use it too. */
    public function whatIf(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'machine_id' => ['required', 'int', 'min:1'],
            'action' => ['required', 'in:' . implode(',', WhatIf::ACTIONS)],
            'window_days' => ['nullable', 'int', 'min:7', 'max:90'],
        ]);
        $raw = $request->json()['params'] ?? [];
        $whatIfParams = Validator::validate(is_array($raw) ? $raw : [], [
            'grace_min' => ['nullable', 'int', 'min:0', 'max:120'],
            'repair_share' => ['nullable', 'number', 'min:0.1', 'max:0.9'],
            'improvement_pct' => ['nullable', 'number', 'min:1', 'max:40'],
            'shift_share' => ['nullable', 'number', 'min:0.1', 'max:1'],
        ]);
        return Response::ok(WhatIf::run($request->companyId(), $input + ['params' => array_filter($whatIfParams, static fn ($v): bool => $v !== null)]), self::UNITS);
    }

    /** Machines and the changes that apply to each (for the simulator's pickers). */
    public function options(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $machines = Database::all(
            "SELECT id, kind, code, name, type_code, schedule_id, criticality FROM machines
              WHERE company_id = ? AND kind = 'machine' AND archived_at IS NULL ORDER BY code",
            [$companyId],
        );
        $out = [];
        foreach ($machines as $m) {
            $actions = WhatIf::actionsFor($m);
            if ($actions !== []) {
                $out[] = ['id' => (int) $m['id'], 'code' => $m['code'], 'name' => $m['name'], 'type' => $m['type_code'], 'actions' => $actions];
            }
        }
        return Response::ok($out, ['now' => Clock::now($companyId)]);
    }
}
