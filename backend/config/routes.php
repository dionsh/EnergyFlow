<?php

declare(strict_types=1);

use EnergyFlow\Controllers\AuthController;
use EnergyFlow\Controllers\CompanyController;
use EnergyFlow\Controllers\HealthController;
use EnergyFlow\Controllers\OnboardingController;
use EnergyFlow\Core\Router;
use EnergyFlow\Middleware\Authenticate;
use EnergyFlow\Middleware\RequireClientHeader;
use EnergyFlow\Middleware\RequireRole;

$router = new Router();

$router->group('/api/v1', [], static function (Router $r): void {
    $r->get('/health', [HealthController::class, 'show']);

    // Browser-facing routes: CSRF header required on every write.
    $r->group('', [RequireClientHeader::class], static function (Router $r): void {
        $r->post('/auth/register', [AuthController::class, 'register']);
        $r->post('/auth/login', [AuthController::class, 'login']);
        $r->post('/auth/logout', [AuthController::class, 'logout']);

        $r->group('', [Authenticate::class], static function (Router $r): void {
            $r->get('/auth/me', [AuthController::class, 'me']);
            $r->patch('/auth/me', [AuthController::class, 'updateMe']);

            $r->get('/company', [CompanyController::class, 'show']);
            $r->patch('/company', [CompanyController::class, 'update'], [new RequireRole('admin')]);
            $r->get('/onboarding', [OnboardingController::class, 'show']);
        });
    });
});

return $router;
