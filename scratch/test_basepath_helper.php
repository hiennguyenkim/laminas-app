<?php
require 'vendor/autoload.php';

$_SERVER['REQUEST_URI'] = '/laminas-app/admin/dashboard';
$_SERVER['SCRIPT_NAME'] = '/laminas-app/public/index.php';
// Our fix in public/index.php
$_SERVER['SCRIPT_NAME'] = str_replace('/public/index.php', '/index.php', $_SERVER['SCRIPT_NAME']);
$_SERVER['PHP_SELF'] = str_replace('/public/index.php', '/index.php', $_SERVER['PHP_SELF'] ?? '');

$request = new \Laminas\Http\PhpEnvironment\Request();
echo "BasePath: '" . $request->getBasePath() . "'\n";

$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$basePathHelper = $app->getServiceManager()->get('ViewHelperManager')->get('basePath');
echo "basePath('/img/test.jpg'): " . $basePathHelper('/img/test.jpg') . "\n";
