<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Models\Company;
use EnergyFlow\Services\Demo\DemoGuard;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Services\Tariff\TariffPlans;
use EnergyFlow\Utils\Locales;

final class CompanyController
{
    public function editWorker(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $worker = \EnergyFlow\Models\User::find($id);
        if ($worker === null || $worker['company_id'] !== $request->companyId() || !in_array($worker['role'], ['viewer', 'worker'], true)) {
            throw \EnergyFlow\Core\HttpException::notFound('worker_not_found', 'Worker not found.');
        }
        $input = Validator::validate($request->json(), ['full_name' => ['required', 'string', 'min:2', 'max:120']]);
        \EnergyFlow\Models\User::updateProfile($id, $input);
        return Response::ok(\EnergyFlow\Models\User::find($id));
    }
    public function workers(Request $request, array $params): Response
    {
        return Response::ok(\EnergyFlow\Core\Database::all(
            'SELECT id, full_name, email, role FROM users WHERE company_id = ? AND disabled_at IS NULL ORDER BY full_name',
            [$request->companyId()],
        ));
    }

    public function addWorker(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:10', 'max:200'],
        ]);
        if (\EnergyFlow\Models\User::emailExists($input['email'])) {
            throw \EnergyFlow\Core\HttpException::validation(['email' => 'email_taken']);
        }
        $id = \EnergyFlow\Models\User::create(
            $request->companyId(), $input['email'],
            \EnergyFlow\Services\Auth\AuthService::hash($input['password']),
            $input['full_name'], 'worker', $request->user()['locale'],
        );
        return Response::ok(\EnergyFlow\Models\User::find($id));
    }

    public function show(Request $request, array $params): Response
    {
        return Response::ok(Company::find($request->companyId()));
    }

    public function update(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'name' => ['string', 'min:2', 'max:160'],
            'logo_data_url' => ['nullable', 'string', 'max:700000', 'regex:/^data:image\/(?:png|jpeg|webp);base64,[A-Za-z0-9+\/=]+$/'],
            'legal_form' => ['nullable', 'string', 'max:40'],
            'business_number' => ['nullable', 'string', 'max:20'],
            'nace_code' => ['nullable', 'string', 'max:10'],
            'employees' => ['nullable', 'int', 'min:0', 'max:65000'],
            'annual_turnover_eur' => ['nullable', 'number', 'min:0', 'max:999999999999'],
            'city' => ['nullable', 'string', 'max:80'],
            'locale' => [Locales::rule()],
        ]);

        Company::update($request->companyId(), $input);
        return Response::ok(Company::find($request->companyId()));
    }

    /** The company's tariff and the published tariff groups it can pick. */
    public function tariff(Request $request, array $params): Response
    {
        return Response::ok(self::tariffState($request->companyId()));
    }

    /** Picks a published tariff group: the company gets its own copy of that template. */
    public function chooseTariff(Request $request, array $params): Response
    {
        DemoGuard::refuse($request->companyId());
        $input = Validator::validate($request->json(), ['template_id' => ['required', 'int', 'min:1']]);
        TariffPlans::choose($request->companyId(), $input['template_id']);
        return Response::ok(self::tariffState($request->companyId()));
    }

    /** @return array{current: ?array<string, mixed>, templates: list<array<string, mixed>>} */
    private static function tariffState(int $companyId): array
    {
        return ['current' => TariffBook::forCompany($companyId)?->summary(), 'templates' => TariffPlans::templates()];
    }
}
