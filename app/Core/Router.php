<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal path-based router. Maps METHOD + path to a [Controller::class, 'action'] pair
 * or a closure. Enough for Phase 0; extend as routes are added.
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
        $handler = $this->routes[$method][$path] ?? null;

        if ($handler === null) {
            $this->notFound();
            return;
        }

        if (is_array($handler)) {
            [$class, $action] = $handler;
            (new $class())->{$action}();
            return;
        }

        $handler();
    }

    private function notFound(): void
    {
        http_response_code(404);

        $config = require dirname(__DIR__, 2) . '/config/config.php';

        $appName = $config['app']['name'];
        require dirname(__DIR__) . '/Views/errors/404.php';
    }
}
