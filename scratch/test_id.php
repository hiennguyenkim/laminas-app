<?php
require 'vendor/autoload.php';
$pdo = new PDO('mysql:host=localhost;dbname=library_db;charset=utf8mb4', 'root', '');
$ann = $pdo->query('SELECT id FROM announcements ORDER BY id DESC LIMIT 1')->fetchColumn();
echo "Latest Announcement ID: " . $ann . "\n";
