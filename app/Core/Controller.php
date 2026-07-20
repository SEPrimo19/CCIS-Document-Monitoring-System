<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base controller: view rendering and config access.
 */
abstract class Controller
{
    /**
     * Render a PHP view from app/Views with the given data.
     *
     * @param array<string, mixed> $data
     */
    protected function view(string $view, array $data = []): void
    {
        $file = dirname(__DIR__) . '/Views/' . $view . '.php';

        if (!is_file($file)) {
            http_response_code(500);
            echo "View not found: {$view}";
            return;
        }

        extract($data, EXTR_SKIP);
        require $file;
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        static $config = null;
        if ($config === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
        }
        return $config;
    }
}
