<?php
require 'vendor/autoload.php';
$app = Laminas\Mvc\Application::init(require 'config/application.config.php');

$session = new Laminas\Session\Container('auth');
$session->user = ['id' => 1, 'role' => 'admin', 'username' => 'admin'];

$request = new Laminas\Http\PhpEnvironment\Request();
$request->setUri('http://localhost/admin/announcements/delete/9');
$request->setMethod('GET');
$app->getMvcEvent()->setRequest($request);
$app->run();
