<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Services\Jobs\JobRunner;

final class InternalController
{
    /** Called by cron-job.org every 5 minutes (X-Cron-Secret). */
    public function runJobs(Request $request, array $params): Response
    {
        return Response::ok(JobRunner::run(20));
    }
}
