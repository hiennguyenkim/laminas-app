<?php
require __DIR__ . '/../vendor/autoload.php';

// Simulate our index.php fix
if (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/public/index.php') !== false) {
    $_SERVER['SCRIPT_NAME'] = str_replace('/public/index.php', '/index.php', $_SERVER['SCRIPT_NAME']);
}

$request = new \Laminas\Http\PhpEnvironment\Request();
$app = Laminas\Mvc\Application::init(require __DIR__ . '/../config/application.config.php');
$basePathHelper = $app->getServiceManager()->get('ViewHelperManager')->get('basePath');

file_put_contents(__DIR__ . '/../scratch/img_debug_v2.txt', 
    "BasePath: '" . $request->getBasePath() . "'\n" .
    "Helper('/img/test.jpg'): '" . $basePathHelper('/img/test.jpg') . "'\n"
);
