<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Models\Company;
use EnergyFlow\Utils\Locales;

final class CompanyController
{
    public function show(Request $request, array $params): Response
    {
        return Response::ok(Company::find($request->companyId()));
    }

    public function update(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'name' => ['string', 'min:2', 'max:160'],
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
}
