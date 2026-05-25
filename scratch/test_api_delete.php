<?php
require 'vendor/autoload.php';
$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$session = new Laminas\Session\Container('library_auth');
$session->user = ['id' => 2, 'role' => 'student', 'username' => 'student_1'];

$request = new Laminas\Http\PhpEnvironment\Request();
$request->setUri('http://localhost/api/notifications');
$request->setMethod('POST');
$request->getHeaders()->addHeaderLine('Content-Type', 'application/json');
$request->setContent(json_encode(['id' => 'db_10', 'action' => 'delete']));

$app->getMvcEvent()->setRequest($request);
$app->run();
$response = $app->getMvcEvent()->getResponse();
echo 'Status: ' . $response->getStatusCode() . PHP_EOL;
echo 'Body: ' . $response->getContent() . PHP_EOL;
