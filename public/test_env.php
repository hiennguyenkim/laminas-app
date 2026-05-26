<?php
require __DIR__ . '/../vendor/autoload.php';
file_put_contents(__DIR__ . '/../scratch/server_dump.txt', print_r($_SERVER, true));
file_put_contents(__DIR__ . '/../scratch/request_dump.txt', print_r((new \Laminas\Http\PhpEnvironment\Request())->getBaseUrl(), true));
