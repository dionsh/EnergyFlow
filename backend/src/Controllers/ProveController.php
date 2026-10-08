<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Services\Analytics\Period;
use EnergyFlow\Services\AuditLog;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Carbon\CarbonReport;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Impact\ImpactService;
use EnergyFlow\Services\Reports\ReportBuilder;
use EnergyFlow\Utils\Locales;

/** The "Prove" step: before/after impact, carbon & ESG, and reports. */
final class ProveController
{
    private const UNITS = ['units' => ['energy' => 'kWh', 'money' => 'EUR', 'co2' => 'kg CO2e']];

    public function impact(Request $request, array $params): Response
    {
        $companyId = $this->fresh($request);
        ImpactService::run($companyId, Clock::now($companyId));
        return Response::ok(ImpactService::summary($companyId), self::UNITS);
    }

    public function intervention(Request $request, array $params): Response
    {
        return Response::ok(ImpactService::detail($request->companyId(), (int) $params['id']), self::UNITS);
    }

    public function carbonSummary(Request $request, array $params): Response
    {
        $companyId = $this->fresh($request);
        return Response::ok(CarbonReport::summary($companyId, $this->period($request, $companyId, 'mtd')), self::UNITS);
    }

    public function carbonBreakdown(Request $request, array $params): Response
    {
        $companyId = $this->fresh($request);
        $input = Validator::validate($request->query, ['group_by' => ['nullable', 'in:machine,department,tariff_period']]);
        return Response::ok(CarbonReport::breakdown($companyId, $this->period($request, $companyId, 'mtd'), $input['group_by'] ?? 'machine'), self::UNITS);
    }

    public function vsme(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $year = (int) ($request->query('year') ?? LocalTime::forCompany($companyId)->format(Clock::now($companyId), 'Y'));
        if ($year < 2020 || $year > 2100) {
            throw HttpException::validation(['year' => 'invalid_int']);
        }
        return Response::ok(CarbonReport::vsmeB3($companyId, $year));
    }

    public function readiness(Request $request, array $params): Response
    {
        return Response::ok(CarbonReport::readiness($request->companyId()));
    }

    public function answer(Request $request, array $params): Response
    {
        $key = (string) ($params['key'] ?? '');
        if (!in_array($key, CarbonReport::ANSWERS, true)) {
            throw HttpException::notFound('answer_not_found', 'Unknown checklist item.');
        }
        $input = Validator::validate($request->json(), ['value' => ['required', 'bool']]);
        $companyId = $request->companyId();
        CarbonReport::saveAnswer($companyId, (int) $request->user()['id'], $key, (bool) $input['value']);
        AuditLog::record($companyId, (int) $request->user()['id'], 'esg.answer', 'esg_answer', null, ['key' => $key, 'value' => (bool) $input['value']]);
        return Response::ok(CarbonReport::readiness($companyId));
    }

    public function reports(Request $request, array $params): Response
    {
        return Response::ok(ReportBuilder::list($request->companyId()));
    }

    public function createReport(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'language' => ['required', Locales::rule()],
        ]);
        $companyId = $this->fresh($request);
        return Response::created(ReportBuilder::create($companyId, (int) $request->user()['id'], $input['month'], $input['language']));
    }

    public function report(Request $request, array $params): Response
    {
        return Response::ok(ReportBuilder::find($request->companyId(), (int) $params['id']));
    }

    public function narrative(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'summary' => ['nullable', 'string', 'max:2000'],
            'changes' => ['nullable', 'string', 'max:2000'],
            'outlook' => ['nullable', 'string', 'max:2000'],
        ]);
        return Response::ok(ReportBuilder::updateNarrative($request->companyId(), (int) $request->user()['id'], (int) $params['id'], array_filter($input, static fn ($v): bool => $v !== null)));
    }

    public function finalize(Request $request, array $params): Response
    {
        return Response::ok(ReportBuilder::finalize($request->companyId(), (int) $request->user()['id'], (int) $params['id']));
    }

    /** The demo company runs on catch-up: bring it to "now" first. */
    private function fresh(Request $request): int
    {
        $companyId = $request->companyId();
        if (DemoClock::isDemo($companyId)) {
            DemoClock::catchUp($companyId);
        }
        return $companyId;
    }

    private function period(Request $request, int $companyId, string $default): Period
    {
        return Period::parse($request->query('period'), Clock::now($companyId), LocalTime::forCompany($companyId), $default);
    }
}
