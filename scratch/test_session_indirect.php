<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Laminas\Session\Container;
use Laminas\Session\SessionManager;

// Initialize a session manager
$manager = new SessionManager();
$container = new Container('test_auth', $manager);

$container->user = [
    'id' => 1,
    'role' => 'admin',
    'full_name' => 'Admin'
];

try {
    // Attempt indirect modification of overloaded property
    $container->user['full_name'] = 'New Admin';
    echo "Value in container: " . print_r($container->user, true) . "\n";
} catch (\Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
