<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Control\CommandService;
use EnergyFlow\Services\Demo\DemoClock;

/**
 * Turn Off. Only `turn_off` exists: EnergyFlow nodes open a remote-STOP contact
 * and can never start a machine (hardware/README.md §4).
 */
final class CommandController
{
    public function create(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'command' => ['required', 'in:turn_off'],
            'confirm_scheduled' => ['nullable', 'bool'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $companyId = $request->companyId();
        $this->refresh($companyId);
        $command = CommandService::request(
            $companyId,
            (int) $params['id'],
            (int) $request->user()['id'],
            null,
            $input['reason'] ?? null,
            (bool) ($input['confirm_scheduled'] ?? false),
        );
        return Response::created($command);
    }

    public function forMachine(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $this->refresh($companyId);
        return Response::ok(CommandService::list($companyId, ['machine_id' => (int) $params['id']], 20));
    }

    public function index(Request $request, array $params): Response
    {
        $filters = Validator::validate($request->query, [
            'machine_id' => ['nullable', 'int', 'min:1'],
            'device_id' => ['nullable', 'int', 'min:1'],
            'policy_id' => ['nullable', 'int', 'min:1'],
            'limit' => ['nullable', 'int', 'min:1', 'max:500'],
        ]);
        $companyId = $request->companyId();
        $this->refresh($companyId);
        return Response::ok(CommandService::list($companyId, $filters, $filters['limit'] ?? 100));
    }

    /** Polled by the Turn Off dialog until the command is verified or failed. */
    public function show(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $this->refresh($companyId);
        return Response::ok(CommandService::find($companyId, (int) $params['id']));
    }

    /** Bring the command loop up to date: the demo simulation (incl. its nodes), or verification for real devices. */
    private function refresh(int $companyId): void
    {
        if (DemoClock::isDemo($companyId)) {
            DemoClock::catchUp($companyId);
        }
        CommandService::verify($companyId, Clock::now($companyId));
    }
}
