<?php
require 'vendor/autoload.php';

$_SERVER['REQUEST_URI'] = '/laminas-app/api/notifications';
$_SERVER['SCRIPT_NAME'] = '/laminas-app/index.php'; // Tricked for BasePath
$_SERVER['SCRIPT_FILENAME'] = 'C:/xampp/htdocs/laminas-app/index.php'; // Important for basepath
$_SERVER['REQUEST_METHOD'] = 'GET';

$request = new \Laminas\Http\PhpEnvironment\Request();
echo "BasePath: '" . $request->getBasePath() . "'\n";
echo "URI Path: '" . $request->getUri()->getPath() . "'\n";
echo "URI String: '" . $request->getUriString() . "'\n";

$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$router = $app->getServiceManager()->get('Router');

$match = $router->match($request);
if ($match) {
    echo "MATCH FOUND: " . $match->getMatchedRouteName() . "\n";
    $controllerName = $match->getParam('controller');
    $actionName = $match->getParam('action');
    echo "Controller: $controllerName, Action: $actionName\n";
} else {
    echo "NO MATCH\n";
}
