<?php
require 'vendor/autoload.php';

$_SERVER = [
  'SCRIPT_FILENAME' => 'C:/xampp/htdocs/laminas-app/public/index.php',
  'REQUEST_METHOD' => 'GET',
  'QUERY_STRING' => '',
  'REQUEST_URI' => '/laminas-app/auth/google-callback',
  'SCRIPT_NAME' => '/laminas-app/index.php', // TRICK LAMINAS
  'PHP_SELF' => '/laminas-app/index.php',
];

$request = new \Laminas\Http\PhpEnvironment\Request();
echo "BaseUrl: '" . $request->getBaseUrl() . "'\n";
echo "BasePath: '" . $request->getBasePath() . "'\n";
