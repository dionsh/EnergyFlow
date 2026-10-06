<?php

declare(strict_types=1);

namespace EnergyFlow\Middleware;

use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;
use EnergyFlow\Services\Auth\SessionService;

/**
 * Resolves the `ef_session` cookie to a user. Attaches `user` (which carries
 * company_id) to the request. Tenancy everywhere else derives from this.
 */
final class Authenticate implements Middleware
{
    public function handle(Request $request): void
    {
        $token = $request->cookie(SessionService::COOKIE);
        $user = $token !== null ? SessionService::resolve($token) : null;
        if ($user === null) {
            throw HttpException::unauthorized();
        }
        $request->set('user', $user);
    }
}
