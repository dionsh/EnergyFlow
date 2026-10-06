<?php

declare(strict_types=1);

/*
 * Router script for PHP's built-in development server:
 *   php -S localhost:8000 bin/serve.php
 * Every request goes through the same front controller as production.
 */

require dirname(__DIR__) . '/public/index.php';
