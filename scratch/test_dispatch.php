<?php
require 'vendor/autoload.php';
$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$request = new Laminas\Http\Request();
$request->setUri('http://localhost/admin/announcements/delete/10');
$request->setMethod('GET');
$app->getMvcEvent()->setRequest($request);
$app->run();
