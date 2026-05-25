<?php
require 'vendor/autoload.php';
$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$request = new Laminas\Http\PhpEnvironment\Request();
$request->setUri('http://localhost/admin/announcements/delete/9');
$request->setMethod('GET');
$router = $app->getServiceManager()->get('Router');
$routeMatch = $router->match($request);
if ($routeMatch) {
    echo 'Matched Route Name: ' . $routeMatch->getMatchedRouteName() . PHP_EOL;
    echo 'Controller: ' . $routeMatch->getParam('controller') . PHP_EOL;
    echo 'Action: ' . $routeMatch->getParam('action') . PHP_EOL;
    echo 'ID: ' . $routeMatch->getParam('id') . PHP_EOL;
} else {
    echo 'No route matched!' . PHP_EOL;
}
