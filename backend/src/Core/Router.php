<?php

declare(strict_types=1);

namespace EnergyFlow\Core;

use EnergyFlow\Middleware\Middleware;

/**
 * Minimal router: method + path pattern (`/machines/{id}`) → [Controller::class, 'method'].
 * Middleware may be class names or instances; each runs before the controller and
 * blocks the request by throwing an HttpException.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: array{0: class-string, 1: string}, middleware: list<Middleware|class-string<Middleware>>}> */
    private array $routes = [];

    /** @var list<Middleware|class-string<Middleware>> */
    private array $groupMiddleware = [];

    private string $groupPrefix = '';

    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, array $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /** Routes defined inside $define share the prefix and middleware. Groups nest. */
    public function group(string $prefix, array $middleware, callable $define): void
    {
        [$previousPrefix, $previousMiddleware] = [$this->groupPrefix, $this->groupMiddleware];
        $this->groupPrefix .= $prefix;
        $this->groupMiddleware = [...$this->groupMiddleware, ...$middleware];
        $define($this);
        [$this->groupPrefix, $this->groupMiddleware] = [$previousPrefix, $previousMiddleware];
    }

    public function dispatch(Request $request): Response
    {
        if ($request->method === 'OPTIONS') {
            return Response::noContent();
        }

        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            foreach ($route['middleware'] as $middleware) {
                (is_string($middleware) ? new $middleware() : $middleware)->handle($request);
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            [$controller, $method] = $route['handler'];
            return (new $controller())->{$method}($request, $params);
        }

        throw $pathMatched
            ? new HttpException(405, 'method_not_allowed', 'This method is not allowed here.')
            : HttpException::notFound('route_not_found', 'This endpoint does not exist.');
    }

    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        $full = rtrim($this->groupPrefix . $path, '/') ?: '/';
        $pattern = preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[0-9]+)', $full);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $pattern . '$#',
            'handler' => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
        ];
    }
}
