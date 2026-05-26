<?php
require __DIR__ . '/../vendor/autoload.php';
$request = new \Laminas\Http\PhpEnvironment\Request();
$app = Laminas\Mvc\Application::init(require __DIR__ . '/../config/application.config.php');
$basePathHelper = $app->getServiceManager()->get('ViewHelperManager')->get('basePath');

file_put_contents(__DIR__ . '/../scratch/img_debug.txt', 
    "BasePath: '" . $request->getBasePath() . "'\n" .
    "Helper('/img/test.jpg'): '" . $basePathHelper('/img/test.jpg') . "'\n" . 
    "SCRIPT_NAME: '" . $_SERVER['SCRIPT_NAME'] . "'\n"
);
