<?php

declare(strict_types=1);

use EnergyFlow\Core\Kernel;
use EnergyFlow\Core\Request;

require dirname(__DIR__) . '/src/bootstrap.php';

Kernel::handle(Request::fromGlobals())->send();
