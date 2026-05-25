<?php
require 'vendor/autoload.php';
$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$session = new Laminas\Session\Container('library_auth');
$session->user = ['id' => 1, 'role' => 'admin', 'username' => 'admin'];
$request = new Laminas\Http\PhpEnvironment\Request();
$request->setUri('http://localhost/admin/announcements/delete/9');
$request->setMethod('GET');
$app->getMvcEvent()->setRequest($request);
$app->run();
$response = $app->getMvcEvent()->getResponse();
echo 'Status: ' . $response->getStatusCode() . PHP_EOL;
$headers = $response->getHeaders();
if ($headers->has('Location')) {
    echo 'Location: ' . $headers->get('Location')->getFieldValue() . PHP_EOL;
}
