<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal path-based router. Maps METHOD + path to a [Controller::class, 'action'] pair
 * or a closure. Enough for Phase 0; extend as routes are added.
 *
 * Supports `{name}` placeholder segments (e.g. '/admin/document-types/{id}/edit'). A
 * matching request passes the captured segment values to the handler as positional
 * arguments, in order (e.g. edit(string $id)). Routes with no placeholders keep using
 * the original exact-string match, so every previously verified route is unaffected.
 */
final class Router
{
    /** @var array<string, array<string, callable|array{0:class-string,1:string}>> */
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        $handlers = $this->routes[$method] ?? [];

        // Fast path: exact match — unchanged behavior for every route with no
        // placeholders (including all previously verified auth routes).
        if (isset($handlers[$path])) {
            $this->invoke($handlers[$path], []);
            return;
        }

        foreach ($handlers as $routePath => $handler) {
            if (!str_contains($routePath, '{')) {
                continue;
            }

            $params = $this->match($routePath, $path);
            if ($params !== null) {
                $this->invoke($handler, $params);
                return;
            }
        }

        $this->notFound();
    }

    /**
     * Compare a registered `{name}`-style route path against the requested path,
     * segment by segment. Returns the captured placeholder values in order, or
     * null if the request doesn't match this route.
     *
     * @return list<string>|null
     */
    private function match(string $routePath, string $path): ?array
    {
        $routeSegments = explode('/', trim($routePath, '/'));
        $pathSegments = explode('/', trim($path, '/'));

        if (count($routeSegments) !== count($pathSegments)) {
            return null;
        }

        $params = [];
        foreach ($routeSegments as $i => $segment) {
            if ($segment !== '' && $segment[0] === '{' && str_ends_with($segment, '}')) {
                $params[] = $pathSegments[$i];
                continue;
            }

            if ($segment !== $pathSegments[$i]) {
                return null;
            }
        }

        return $params;
    }

    /**
     * @param list<string> $params
     */
    private function invoke(callable|array $handler, array $params): void
    {
        if (is_array($handler)) {
            [$class, $action] = $handler;
            call_user_func_array([new $class(), $action], $params);
            return;
        }

        call_user_func_array($handler, $params);
    }

    private function notFound(): void
    {
        http_response_code(404);

        $config = require dirname(__DIR__, 2) . '/config/config.php';

        $appName = $config['app']['name'];
        require dirname(__DIR__) . '/Views/errors/404.php';
    }
}
