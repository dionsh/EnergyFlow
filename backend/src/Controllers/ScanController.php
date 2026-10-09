<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Env;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\RateLimiter;
use EnergyFlow\Services\Scan\ScanReader;
use EnergyFlow\Services\Scan\ScanService;

/**
 * Camera scan: read a photo into fields, check them, save them. Everyone can
 * read and check (the demo guest too); saving needs a manager.
 */
final class ScanController
{
    public function read(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'kind' => ['required', 'in:' . implode(',', ScanReader::KINDS)],
            'image' => ['required', 'string'],
        ]);
        $companyId = $request->companyId();
        RateLimiter::hit('scan:user:' . $request->user()['id'] . ':' . $request->ip, 20, 3600);
        RateLimiter::hit('scan:company:' . $companyId, max(1, (int) (Env::get('SCAN_DAILY_LIMIT') ?? 100)), 86400);
        return Response::ok(ScanReader::read($input['kind'], $input['image']));
    }

    public function check(Request $request, array $params): Response
    {
        $input = $this->input($request);
        $companyId = $this->fresh($request);
        return Response::ok(ScanService::check($companyId, $input['kind'], $input['fields'], $input['machine_id']));
    }

    public function save(Request $request, array $params): Response
    {
        $input = $this->input($request);
        $companyId = $this->fresh($request);
        return Response::created(ScanService::save(
            $companyId,
            (int) $request->user()['id'],
            $input['kind'],
            $input['fields'],
            $input['machine_id'],
            $input['source'],
        ));
    }

    public function bills(Request $request, array $params): Response
    {
        return Response::ok(ScanService::bills($this->fresh($request)));
    }

    public function readings(Request $request, array $params): Response
    {
        return Response::ok(ScanService::readings($request->companyId()));
    }

    public function fuel(Request $request, array $params): Response
    {
        return Response::ok(ScanService::fuelRecords($request->companyId()));
    }

    public function destroy(Request $request, array $params): Response
    {
        $what = match (true) {
            str_contains($request->path, '/bills/') => 'bill',
            str_contains($request->path, '/meter-readings/') => 'reading',
            default => 'fuel',
        };
        ScanService::delete($request->companyId(), (int) $request->user()['id'], $what, (int) $params['id']);
        return Response::noContent();
    }

    /** @return array{kind: string, fields: array, machine_id: ?int, source: string} */
    private function input(Request $request): array
    {
        $input = Validator::validate($request->json(), [
            'kind' => ['required', 'in:' . implode(',', ScanReader::KINDS)],
            'fields' => ['required'],
            'machine_id' => ['nullable', 'int'],
            'source' => ['nullable', 'in:manual,scan'],
        ]);
        return [
            'kind' => $input['kind'],
            'fields' => is_array($input['fields']) ? $input['fields'] : [],
            'machine_id' => isset($input['machine_id']) ? (int) $input['machine_id'] : null,
            'source' => $input['source'] ?? 'manual',
        ];
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
