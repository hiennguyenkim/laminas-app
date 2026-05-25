<?php
require 'vendor/autoload.php';
$container = require 'config/container.php';
$bookTable = $container->get(Library\Model\Table\BookTable::class);
$trending = $bookTable->getTrendingBooks(1);
if (count($trending) > 0) {
    echo "Trending Books fetch OK: " . $trending[0]['title'] . " (" . $trending[0]['borrow_count'] . " borrows)\n";
} else {
    echo "No trending books found.\n";
}
