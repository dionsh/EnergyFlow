<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Services\RateLimiter;
use EnergyFlow\Services\Tariff\BillCheck;

final class BillCheckController
{
    /** Public, for the landing page: engaged power and reactive energy on one month's bill. No company data. */
    public function check(Request $request, array $params): Response
    {
        RateLimiter::hit('bill-check:' . $request->ip, 30, 60);
        $input = Validator::validate($request->query, [
            'category' => ['required', 'string', 'max:80'],
            'kwh' => ['required', 'number', 'min:0', 'max:10000000'],
            'kvarh' => ['required', 'number', 'min:0', 'max:10000000'],
            'kw' => ['required', 'number', 'min:0', 'max:100000'],
        ]);
        return Response::ok(BillCheck::run($input['category'], $input['kwh'], $input['kvarh'], $input['kw']));
    }
}
