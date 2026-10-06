<?php

declare(strict_types=1);

namespace EnergyFlow\Core;

use Throwable;

/**
 * Turns a Request into a Response. Shared by the web front controller and the
 * test suite, so tests exercise the exact same routing, middleware and errors.
 */
final class Kernel
{
    private static ?Router $router = null;

    public static function handle(Request $request): Response
    {
        try {
            self::$router ??= require EF_ROOT . '/config/routes.php';
            return self::$router->dispatch($request);
        } catch (HttpException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->status, $e->fields);
        } catch (Throwable $e) {
            error_log('[EnergyFlow] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $message = Env::bool('APP_DEBUG') ? $e->getMessage() : 'Something went wrong on our side.';
            return Response::error('server_error', $message, 500);
        }
    }
}
