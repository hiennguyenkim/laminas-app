<?php

declare(strict_types=1);

// Run with: php bin/create-fines-table.php

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

use Laminas\Db\Adapter\AdapterInterface;

try {
    $container = require 'config/container.php';
    echo "Connecting to database..." . PHP_EOL;
    
    /** @var AdapterInterface $db */
    $db = $container->get(AdapterInterface::class);

    echo "Creating fines table..." . PHP_EOL;
    $sql = "CREATE TABLE IF NOT EXISTS fines (
        fine_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        reason VARCHAR(255) NOT NULL,
        status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at DATETIME DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $db->query($sql)->execute();
    echo "Fines table created successfully!" . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
