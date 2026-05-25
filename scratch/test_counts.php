<?php
require 'vendor/autoload.php';
$container = require 'config/container.php';
$borrowTable = $container->get(Library\Model\Table\BorrowTable::class);

$methods = [
    'countBorrowed',
    'countOverdue',
    'countReturned',
    'countPending',
    'countTotalBorrowedHistory'
];

foreach ($methods as $method) {
    try {
        $borrowTable->$method(['search' => 'test']);
        echo "$method OK\n";
    } catch (\Exception $e) {
        echo "$method FAIL: " . $e->getMessage() . "\n";
    }
}
