<?php

declare(strict_types=1);

use EnergyFlow\Controllers\AdminController;
use EnergyFlow\Controllers\AlertController;
use EnergyFlow\Controllers\AssistantController;
use EnergyFlow\Controllers\AuthController;
use EnergyFlow\Controllers\BillCheckController;
use EnergyFlow\Controllers\CommandController;
use EnergyFlow\Controllers\CompanyController;
use EnergyFlow\Controllers\DemoController;
use EnergyFlow\Controllers\DeviceController;
use EnergyFlow\Controllers\HealthController;
use EnergyFlow\Controllers\IngestController;
use EnergyFlow\Controllers\InternalController;
use EnergyFlow\Controllers\LiveController;
use EnergyFlow\Controllers\MachineController;
use EnergyFlow\Controllers\NotificationController;
use EnergyFlow\Controllers\OnboardingController;
use EnergyFlow\Controllers\OpportunityController;
use EnergyFlow\Controllers\PolicyController;
use EnergyFlow\Controllers\ProveController;
use EnergyFlow\Controllers\ScanController;
use EnergyFlow\Controllers\WasteController;
use EnergyFlow\Core\Router;
use EnergyFlow\Middleware\Authenticate;
use EnergyFlow\Middleware\CronAuth;
use EnergyFlow\Middleware\DeviceAuth;
use EnergyFlow\Middleware\RequireClientHeader;
use EnergyFlow\Middleware\RequireDemoAdmin;
use EnergyFlow\Middleware\RequirePlatformAdmin;
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
        $r->post('/auth/password/forgot', [AuthController::class, 'forgotPassword']);
        $r->post('/auth/password/reset', [AuthController::class, 'resetPassword']);
        $r->post('/auth/demo', [AuthController::class, 'demo']);
        $r->post('/auth/logout', [AuthController::class, 'logout']);

        // The landing page's bill check: published template tariffs only, no sign-in.
        $r->get('/public/bill-check', [BillCheckController::class, 'check']);

        $r->group('', [Authenticate::class], static function (Router $r): void {
            $r->get('/auth/me', [AuthController::class, 'me']);
            $r->patch('/auth/me', [AuthController::class, 'updateMe']);

            $r->get('/company', [CompanyController::class, 'show']);
            $r->get('/company/workers', [CompanyController::class, 'workers'], [new RequireRole('admin')]);
            $r->post('/company/workers', [CompanyController::class, 'addWorker'], [new RequireRole('admin')]);
            $r->patch('/company/workers/{id}', [CompanyController::class, 'editWorker'], [new RequireRole('admin')]);
            $r->patch('/company', [CompanyController::class, 'update'], [new RequireRole('admin')]);
            $r->get('/company/tariff', [CompanyController::class, 'tariff']);
            $r->patch('/company/tariff', [CompanyController::class, 'chooseTariff'], [new RequireRole('admin')]);
            $r->get('/onboarding', [OnboardingController::class, 'show']);

            $r->get('/live', [LiveController::class, 'show']);
            $r->get('/overview', [LiveController::class, 'overview']);
            $r->get('/score', [LiveController::class, 'score']);
            $r->get('/energy-flow', [LiveController::class, 'flow']);
            $r->get('/machines', [MachineController::class, 'index']);
            $r->get('/machines/{id}', [MachineController::class, 'show']);
            $r->get('/machines/{id}/timeseries', [MachineController::class, 'timeseries']);
            $r->get('/machines/{id}/commands', [CommandController::class, 'forMachine']);
            $r->post('/machines/{id}/commands', [CommandController::class, 'create'], [new RequireRole('worker')]);
            $r->get('/commands', [CommandController::class, 'index']);
            $r->get('/commands/{id}', [CommandController::class, 'show']);
            $r->get('/recommendations', [OpportunityController::class, 'index']);
            $r->get('/recommendations/{id}', [OpportunityController::class, 'show']);
            $r->post('/recommendations/{id}/accept', [OpportunityController::class, 'accept'], [new RequireRole('worker')]);
            $r->post('/recommendations/{id}/implemented', [OpportunityController::class, 'implemented'], [new RequireRole('worker')]);
            $r->post('/recommendations/{id}/dismiss', [OpportunityController::class, 'dismiss'], [new RequireRole('worker')]);
            $r->get('/what-if/options', [OpportunityController::class, 'options']);
            $r->post('/what-if', [OpportunityController::class, 'whatIf']);
            $r->get('/impact/summary', [ProveController::class, 'impact']);
            $r->get('/impact/interventions/{id}', [ProveController::class, 'intervention']);
            $r->get('/carbon/summary', [ProveController::class, 'carbonSummary']);
            $r->get('/carbon/breakdown', [ProveController::class, 'carbonBreakdown']);
            $r->get('/esg/vsme-b3', [ProveController::class, 'vsme']);
            $r->get('/esg/readiness', [ProveController::class, 'readiness']);
            $r->put('/esg/answers/{key:slug}', [ProveController::class, 'answer'], [new RequireRole('admin')]);
            $r->get('/reports', [ProveController::class, 'reports']);
            $r->post('/reports', [ProveController::class, 'createReport'], [new RequireRole('worker')]);
            $r->get('/reports/{id}', [ProveController::class, 'report']);
            $r->delete('/reports/{id}', [ProveController::class, 'deleteReport'], [new RequireRole('worker')]);
            $r->patch('/reports/{id}/narrative', [ProveController::class, 'narrative'], [new RequireRole('worker')]);
            $r->post('/reports/{id}/finalize', [ProveController::class, 'finalize'], [new RequireRole('admin')]);
            $r->get('/policies', [PolicyController::class, 'index']);
            $r->patch('/policies/{id}', [PolicyController::class, 'update'], [new RequireRole('worker')]);
            $r->get('/devices', [DeviceController::class, 'index']);
            $r->get('/devices/{id}', [DeviceController::class, 'show']);

            $r->get('/waste/summary', [WasteController::class, 'summary']);
            $r->get('/waste-events', [WasteController::class, 'index']);
            $r->get('/waste-events/{id}', [WasteController::class, 'show']);
            $r->post('/waste-events/{id}/dismiss', [WasteController::class, 'dismiss'], [new RequireRole('worker')]);
            $r->get('/alerts', [AlertController::class, 'index']);
            $r->get('/alerts/{id}', [AlertController::class, 'show']);
            $r->post('/alerts/{id}/acknowledge', [AlertController::class, 'acknowledge'], [new RequireRole('worker')]);
            $r->post('/alerts/{id}/resolve', [AlertController::class, 'resolve'], [new RequireRole('worker')]);
            $r->post('/scan/read', [ScanController::class, 'read']);
            $r->post('/scan/check', [ScanController::class, 'check']);
            $r->post('/scan/save', [ScanController::class, 'save'], [new RequireRole('worker')]);
            $r->get('/bills', [ScanController::class, 'bills']);
            $r->delete('/bills/{id}', [ScanController::class, 'destroy'], [new RequireRole('worker')]);
            $r->get('/meter-readings', [ScanController::class, 'readings']);
            $r->delete('/meter-readings/{id}', [ScanController::class, 'destroy'], [new RequireRole('worker')]);
            $r->get('/fuel-records', [ScanController::class, 'fuel']);
            $r->delete('/fuel-records/{id}', [ScanController::class, 'destroy'], [new RequireRole('worker')]);
            $r->get('/assistant/conversations', [AssistantController::class, 'index']);
            $r->post('/assistant/conversations', [AssistantController::class, 'create']);
            $r->get('/assistant/conversations/{id}', [AssistantController::class, 'show']);
            $r->delete('/assistant/conversations/{id}', [AssistantController::class, 'destroy']);
            $r->post('/assistant/conversations/{id}/messages', [AssistantController::class, 'message']);
            $r->post('/assistant/messages/{id}/action', [AssistantController::class, 'action']);
            $r->get('/assistant/suggestions', [AssistantController::class, 'suggestions']);
            $r->get('/notifications', [NotificationController::class, 'index']);
            $r->post('/notifications/read-all', [NotificationController::class, 'readAll']);
            $r->post('/notifications/{id}/read', [NotificationController::class, 'read']);

            $r->group('/demo', [RequireDemoAdmin::class], static function (Router $r): void {
                $r->get('/state', [DemoController::class, 'state']);
                $r->post('/advance', [DemoController::class, 'advance']);
                $r->post('/spike', [DemoController::class, 'spike']);
                $r->post('/reset', [DemoController::class, 'reset']);
            });

            // Platform admin panel: EnergyFlow staff only (bin/platform-admin.php grants it).
            $r->group('/admin', [RequirePlatformAdmin::class], static function (Router $r): void {
                $r->get('/overview', [AdminController::class, 'overview']);
                $r->get('/requests', [AdminController::class, 'requests']);
                $r->post('/requests/{id}/approve', [AdminController::class, 'approve']);
                $r->get('/users', [AdminController::class, 'users']);
                $r->patch('/users/{id}', [AdminController::class, 'updateUser']);
                $r->delete('/users/{id}', [AdminController::class, 'deleteUser']);
                $r->post('/users/{id}/password-reset', [AdminController::class, 'sendPasswordReset']);
                $r->get('/companies', [AdminController::class, 'companies']);
            });
        });
    });
});

return $router;
