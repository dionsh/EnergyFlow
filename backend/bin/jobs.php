<?php

declare(strict_types=1);

/* Runs the background jobs once (same as the cron endpoint). php bin/jobs.php */

use EnergyFlow\Services\Jobs\JobRunner;

require dirname(__DIR__) . '/src/bootstrap.php';

fwrite(STDOUT, json_encode(JobRunner::run(120), JSON_PRETTY_PRINT) . PHP_EOL);
