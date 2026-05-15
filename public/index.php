<?php

declare(strict_types=1);

use Laminas\Mvc\Application;

chdir(dirname(__DIR__));

/**
 * Laminas standard initialization.
 * Base URL detection works automatically when REQUEST_URI and SCRIPT_NAME are kept intact.
 */
if (php_sapi_name() === 'cli-server') {
    $parsedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $uriPath    = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';
    $file       = __DIR__ . $uriPath;
    if ($uriPath !== '/' && is_file($file)) {
        return false; // serve static files directly
    }
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

// Composer autoloading
include __DIR__ . '/../vendor/autoload.php';

if (! class_exists(Application::class)) {
    throw new RuntimeException("Unable to load application. Run `composer install` first.");
}

$container = require __DIR__ . '/../config/container.php';
/** @var Application $app */
$app = $container->get('Application');
$app->run();
