<?php
declare(strict_types=1);

$uri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($uri, '/public') === false) {
    $basePath = rtrim(explode('?', $uri)[0], '/');
    header('Location: ' . $basePath . '/public/');
    exit;
}

require __DIR__ . '/public/index.php';
