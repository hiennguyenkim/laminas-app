<?php
$pdo = new PDO('mysql:host=localhost;dbname=library_db;charset=utf8mb4', 'root', '');
try {
    $pdo->query('DELETE FROM announcements LIMIT 1');
    echo "Deleted OK\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
