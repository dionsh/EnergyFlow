<?php

declare(strict_types=1);

namespace EnergyFlow\Middleware;

use EnergyFlow\Core\Request;

/** Runs before a controller. Blocks the request by throwing an HttpException. */
interface Middleware
{
    public function handle(Request $request): void;
}
