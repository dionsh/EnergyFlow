<?php

declare(strict_types=1);

use EnergyFlow\Controllers\AuthController;
use EnergyFlow\Controllers\CompanyController;
use EnergyFlow\Controllers\DemoController;
use EnergyFlow\Controllers\DeviceController;
use EnergyFlow\Controllers\HealthController;
use EnergyFlow\Controllers\IngestController;
use EnergyFlow\Controllers\InternalController;
use EnergyFlow\Controllers\LiveController;
use EnergyFlow\Controllers\MachineController;
use EnergyFlow\Controllers\OnboardingController;
use EnergyFlow\Core\Router;
use EnergyFlow\Middleware\Authenticate;
use EnergyFlow\Middleware\CronAuth;
use EnergyFlow\Middleware\DeviceAuth;
use EnergyFlow\Middleware\RequireClientHeader;
use EnergyFlow\Middleware\RequireDemoAdmin;
use EnergyFlow\Middleware\RequireRole;

$router = new Router();

$router->group('/api/v1', [], static function (Router $r): void {
    $r->get('/health', [HealthController::class, 'show']);

    // Device-facing (EF-N3 nodes, hw-bridge): HMAC-signed, no browser cookies.
    $r->group('', [DeviceAuth::class], static function (Router $r): void {
        $r->post('/ingest/readings', [IngestController::class, 'readings']);
        $r->get('/device/commands', [IngestController::class, 'commands']);
        $r->post('/device/commands/{id}/ack', [IngestController::class, 'ack']);
    });

    // cron-job.org
    $r->post('/internal/jobs/run', [InternalController::class, 'runJobs'], [CronAuth::class]);

    // Browser-facing routes: CSRF header required on every write.
    $r->group('', [RequireClientHeader::class], static function (Router $r): void {
        $r->post('/auth/register', [AuthController::class, 'register']);
        $r->post('/auth/login', [AuthController::class, 'login']);
        $r->post('/auth/demo', [AuthController::class, 'demo']);
        $r->post('/auth/logout', [AuthController::class, 'logout']);

        $r->group('', [Authenticate::class], static function (Router $r): void {
            $r->get('/auth/me', [AuthController::class, 'me']);
            $r->patch('/auth/me', [AuthController::class, 'updateMe']);

            $r->get('/company', [CompanyController::class, 'show']);
            $r->patch('/company', [CompanyController::class, 'update'], [new RequireRole('admin')]);
            $r->get('/onboarding', [OnboardingController::class, 'show']);

            $r->get('/live', [LiveController::class, 'show']);
            $r->get('/overview', [LiveController::class, 'overview']);
            $r->get('/machines', [MachineController::class, 'index']);
            $r->get('/machines/{id}', [MachineController::class, 'show']);
            $r->get('/machines/{id}/timeseries', [MachineController::class, 'timeseries']);
            $r->get('/devices', [DeviceController::class, 'index']);
            $r->get('/devices/{id}', [DeviceController::class, 'show']);

            $r->group('/demo', [RequireDemoAdmin::class], static function (Router $r): void {
                $r->get('/state', [DemoController::class, 'state']);
                $r->post('/advance', [DemoController::class, 'advance']);
                $r->post('/reset', [DemoController::class, 'reset']);
            });
        });
    });
});

return $router;
