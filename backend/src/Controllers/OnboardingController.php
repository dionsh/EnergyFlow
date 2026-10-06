<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Services\OnboardingService;

final class OnboardingController
{
    public function show(Request $request, array $params): Response
    {
        return Response::ok(['steps' => OnboardingService::steps($request->companyId())]);
    }
}
