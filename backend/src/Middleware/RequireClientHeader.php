<?php

declare(strict_types=1);

namespace EnergyFlow\Middleware;

use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;

/**
 * CSRF defence for cookie-authenticated routes. Every state-changing browser
 * request must carry `X-EF-Client: web`. A cross-site form or script cannot set
 * a custom header without a CORS preflight, and this API never grants CORS.
 * Combined with SameSite=Lax cookies this closes the CSRF hole.
 */
final class RequireClientHeader implements Middleware
{
    public function handle(Request $request): void
    {
        if ($request->isUnsafeMethod() && $request->header('X-EF-Client') !== 'web') {
            throw new HttpException(403, 'missing_client_header', 'This request was blocked for security reasons.');
        }
    }
}
