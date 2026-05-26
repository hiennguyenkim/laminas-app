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

// Fix Base URL detection for subdirectory hosting without '/public' in URL
if (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/public/index.php') !== false) {
    $_SERVER['SCRIPT_NAME'] = str_replace('/public/index.php', '/index.php', $_SERVER['SCRIPT_NAME']);
    $_SERVER['PHP_SELF'] = str_replace('/public/index.php', '/index.php', $_SERVER['PHP_SELF'] ?? '');
}

// Ensure the Request object doesn't include /public in the base path
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    // Tricking Laminas to think the script is in the root project dir
    $_SERVER['SCRIPT_FILENAME'] = str_replace('public/index.php', 'index.php', str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']));
}

$container = require __DIR__ . '/../config/container.php';
/** @var Application $app */

$app = $container->get('Application');
$app->run();
