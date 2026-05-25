<?php
require 'vendor/autoload.php';
$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$router = $app->getServiceManager()->get('Router');
echo $router->assemble(['id' => 1], ['name' => 'library/announcements/delete']);
