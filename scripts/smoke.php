<?php

declare(strict_types=1);

/**
 * CLI smoke test: drive a GET / request through the real front controller and
 * print the rendered page. Proves autoloader -> router -> controller -> view -> DB.
 * Usage:  php scripts/smoke.php
 */

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['SCRIPT_NAME']    = '/index.php';

require dirname(__DIR__) . '/public/index.php';

echo "\n";
