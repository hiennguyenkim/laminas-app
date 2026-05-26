<?php
require 'vendor/autoload.php';

$_SERVER['REQUEST_URI'] = '/laminas-app/auth/google-callback';
$_SERVER['SCRIPT_NAME'] = '/laminas-app/public/index.php';
$_SERVER['SCRIPT_FILENAME'] = 'C:/xampp/htdocs/laminas-app/public/index.php';
$_SERVER['PHP_SELF'] = '/laminas-app/public/index.php';

$request = new \Laminas\Http\PhpEnvironment\Request();
echo "Base URL: " . $request->getBaseUrl() . "\n";
echo "URI Path: " . $request->getUri()->getPath() . "\n";

$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$router = $app->getServiceManager()->get('Router');
$router->setRequestUri($request->getUri());
$router->setBaseUrl($request->getBaseUrl()); // Force the router to use this base URL

$match = $router->match($request);
if ($match) {
    echo "MATCH FOUND: " . $match->getMatchedRouteName() . "\n";
} else {
    echo "NO MATCH\n";
}

$urlHelper = $app->getServiceManager()->get('ViewHelperManager')->get('url');
echo "Generated URL for library/auth: " . $urlHelper('library/auth', ['action' => 'login']) . "\n";
