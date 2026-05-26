<?php
require 'vendor/autoload.php';

$_SERVER = [
  'SCRIPT_FILENAME' => 'C:/xampp/htdocs/laminas-app/public/index.php',
  'REQUEST_METHOD' => 'GET',
  'QUERY_STRING' => '',
  'REQUEST_URI' => '/laminas-app/admin/dashboard',
  'SCRIPT_NAME' => '/laminas-app/public/index.php',
  'PHP_SELF' => '/laminas-app/public/index.php',
];

$request = new \Laminas\Http\PhpEnvironment\Request();

$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$router = $app->getServiceManager()->get('Router');

$urlHelper = $app->getServiceManager()->get('ViewHelperManager')->get('url');
echo "Generated URL for api/notifications: " . $urlHelper('api/notifications') . "\n";
