<?php
require 'vendor/autoload.php';

$_SERVER['REQUEST_URI'] = '/laminas-app/api/notifications';
$_SERVER['SCRIPT_NAME'] = '/laminas-app/index.php'; 
$_SERVER['SCRIPT_FILENAME'] = 'C:/xampp/htdocs/laminas-app/index.php'; 
$_SERVER['REQUEST_METHOD'] = 'GET';

$app = Laminas\Mvc\Application::init(require 'config/application.config.php');

// Mock Session
$sessionContainer = $app->getServiceManager()->get(\Library\Session\AuthSessionContainer::class);
$sessionContainer->user = [
    'id' => 1,
    'username' => 'admin_1',
    'role' => 'admin'
];

$request = new \Laminas\Http\PhpEnvironment\Request();
$router = $app->getServiceManager()->get('Router');
$match = $router->match($request);

if ($match) {
    $controllerName = $match->getParam('controller');
    $actionName = $match->getParam('action') ?: 'index';
    
    $controller = $app->getServiceManager()->get('ControllerManager')->get($controllerName);
    
    $event = new \Laminas\Mvc\MvcEvent();
    $event->setRouteMatch($match);
    $event->setRequest($request);
    $event->setResponse(new \Laminas\Http\PhpEnvironment\Response());
    $event->setApplication($app);
    $controller->setEvent($event);
    
    try {
        $response = $controller->dispatch($request, $event->getResponse());
        echo "Response Content:\n";
        echo $response->getContent();
    } catch (\Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
} else {
    echo "NO MATCH\n";
}
